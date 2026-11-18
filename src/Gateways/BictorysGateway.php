<?php

namespace SunuCode\AfriPay\Gateways;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SunuCode\AfriPay\Contracts\SettlesFromGateway;
use SunuCode\AfriPay\Enums\TransactionStatus;
use SunuCode\AfriPay\Models\Transaction;
use SunuCode\AfriPay\Support\SignatureVerifier;

/**
 * Bictorys (Sénégal, Côte d'Ivoire, et le reste de l'UEMOA selon le compte).
 *
 * Agrégateur à page hébergée : un POST ouvre une page de paiement où le client
 * choisit son opérateur, comme PayTech. Le mode « API directe »
 * (`?payment_type=`) n'est pas couvert : il rend un QR code pour Wave sur
 * ordinateur, un message USSD à afficher, et demande un code OTP pour Orange
 * Money Côte d'Ivoire, là où `charge()` ne sait rendre qu'une URL à ouvrir.
 * Le couvrir demanderait un contrat supplémentaire, comme `ChargesOffSession`
 * l'a été pour les prélèvements.
 *
 * Les retraits (payouts) ne sont pas couverts : le package n'a pas de notion
 * de retrait, et leur clé privée n'a donc pas sa place dans sa configuration.
 *
 * Doc : https://docs.bictorys.com/docs/integration
 */
class BictorysGateway extends AbstractGateway implements SettlesFromGateway
{
    public function name(): string
    {
        return 'bictorys';
    }

    /**
     * Ouvre un paiement sur la page hébergée Bictorys.
     *
     * Le montant part en entier : le XOF n'a pas de centimes, et leur API
     * refuse les décimales. Leur minimum est de 100 francs, qu'on ne recopie
     * pas ici pour ne pas figer leur règle commerciale dans notre code : un
     * montant plus bas revient en 400, journalisé comme tout autre refus.
     */
    public function charge(array $params): array
    {
        $amount = (int) $params['amount'];
        $currency = $params['currency'] ?? $this->defaultCurrency();
        $reference = $params['reference'] ?? $this->generateReference('BIC');

        $body = array_filter([
            'amount' => $amount,
            'currency' => $currency,
            'country' => $this->country($params),
            'paymentReference' => $reference,
            'successRedirectUrl' => $params['success_url'],
            // La majuscule est celle de l'API Bictorys, qui n'accorde pas sa
            // casse avec `successRedirectUrl`. La « corriger » fait perdre
            // l'URL d'échec en silence.
            'ErrorRedirectUrl' => $params['error_url'],
            'customerObject' => $this->customer($params),
        ], fn ($value) => $value !== null);

        $response = Http::withHeaders(['X-Api-Key' => $this->apiKey()])
            ->asJson()
            ->post($this->baseUrl().'/pay/v1/charges', $body);

        $data = is_array($response->json()) ? $response->json() : [];
        $redirectUrl = $data['redirectUrl'] ?? $data['link'] ?? null;

        if (! $response->successful() || ! is_string($redirectUrl) || $redirectUrl === '') {
            Log::error('AfriPay Bictorys: ouverture du paiement refusée', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            throw new \RuntimeException('Bictorys checkout failed: '.($data['message'] ?? mb_substr($response->body(), 0, 200)));
        }

        $transaction = $this->createTransaction(array_merge($params, [
            'reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
        ]));

        // L'identifiant qui ouvre leur endpoint de statut. Leur
        // documentation annonce `transactionId` ; leur API rend `chargeId`
        // (relevé sur le sandbox le 18/11/2026, réponse 202 :
        // type, link, chargeId, opToken). Les deux sont lus, dans cet ordre,
        // pour survivre au jour où ils aligneront l'un sur l'autre. Sans cet
        // identifiant, une transaction en attente ne peut plus être vérifiée.
        $transaction->update([
            'gateway_reference' => $this->identifiant($data),
            'gateway_response' => $this->withoutQrCode($data),
        ]);

        return [
            'redirect_url' => $redirectUrl,
            'transaction' => $transaction,
        ];
    }

    /**
     * Le webhook porte la référence de la commande, le statut, le montant et
     * la devise : de quoi régler la transaction et contrôler qu'on a bien été
     * payé ce qu'on attendait.
     */
    public function handleWebhook(array $data): ?Transaction
    {
        $reference = $data['paymentReference'] ?? $data['merchantReference'] ?? null;

        if (! is_string($reference) || $reference === '') {
            return null;
        }

        $status = $this->mapStatus($data['status'] ?? null);

        if ($status === TransactionStatus::Pending) {
            return null;
        }

        // Un « reversed » annonce un paiement revenu en arrière après avoir
        // réussi. `transition()` ne touche qu'une transaction encore en
        // attente : sur une transaction déjà réglée il ne fera rien, et
        // personne ne saurait que l'argent est reparti. Aucun remboursement
        // n'est prononcé tout seul, c'est une décision métier.
        if (is_string($data['status'] ?? null) && strtolower($data['status']) === 'reversed') {
            Log::warning('AfriPay Bictorys: paiement repris après coup (reversed)', [
                'reference' => $reference,
                'montant' => $data['amount'] ?? null,
            ]);
        }

        return $this->transition(
            $reference,
            $status,
            $data,
            isset($data['amount']) ? (float) $data['amount'] : null,
            is_string($data['currency'] ?? null) ? $data['currency'] : null,
            is_string($data['id'] ?? null) ? $data['id'] : null,
        );
    }

    /**
     * Statut lu à la demande, sans rien enregistrer.
     *
     * L'adresse est celle des transactions, pas celle des charges, et elle
     * attend le `chargeId` : vérifié sur leur sandbox, `/pay/v1/charges/{id}/status`
     * répond 404 et `/pay/v1/transactions/{id}` sans suffixe répond 403.
     *
     * Elle ne rend que deux champs, `id` et `status`, jamais le montant.
     */
    protected function fetchStatus(Transaction $transaction): ?array
    {
        if (! $transaction->gateway_reference) {
            return null;
        }

        $response = Http::withHeaders(['X-Api-Key' => $this->apiKey()])
            ->acceptJson()
            ->get($this->baseUrl().'/pay/v1/transactions/'.$transaction->gateway_reference.'/status');

        if (! $response->successful()) {
            return null;
        }

        $data = is_array($response->json()) ? $response->json() : [];

        return [
            'status' => $this->mapStatus($data['status'] ?? null),
            'amount' => isset($data['amount']) ? (float) $data['amount'] : null,
            'currency' => is_string($data['currency'] ?? null) ? $data['currency'] : null,
            'data' => $data,
        ];
    }

    /**
     * Régler depuis la page de retour demande le montant payé : sans lui, le
     * contrôle anti-fraude d'`AbstractGateway` refuse le règlement et fait
     * échouer une transaction pourtant payée.
     *
     * Or leur endpoint de statut ne rend pas le montant (relevé sur leur
     * sandbox le 18/11/2026 : `{id, status}`, rien d'autre). Un paiement
     * réussi est donc toujours laissé au webhook, qui porte le montant. Seuls
     * les échecs se règlent ici, et le contrôle ne s'applique pas à eux.
     */
    public function settle(Transaction $transaction): ?Transaction
    {
        $remote = $this->fetchStatus($transaction);

        if ($remote === null) {
            return null;
        }

        if ($remote['status'] === TransactionStatus::Completed && $remote['amount'] === null) {
            Log::info('AfriPay Bictorys: statut payé sans montant, règlement laissé au webhook', [
                'reference' => $transaction->reference,
            ]);

            return null;
        }

        return $this->transition(
            $transaction->reference,
            $remote['status'],
            $remote['data'],
            $remote['amount'],
            $remote['currency'],
        );
    }

    public function verifySignature(string $signature, string $rawBody): bool
    {
        return SignatureVerifier::verifyBictorysSignature(
            $signature,
            $rawBody,
            (string) ($this->config['webhook_secret'] ?? ''),
        );
    }

    /**
     * Bictorys envoie deux choses dans ses en-têtes : une clé statique,
     * toujours présente, et un HMAC optionnel accompagné de son horodatage.
     * On les ramène à une seule chaîne, au format que Wave et Stripe utilisent
     * déjà ici, pour ne pas changer la signature de
     * `GatewayInterface::verifySignature()` au profit d'une seule passerelle.
     */
    public static function signatureFrom(Request $request): string
    {
        $hmac = (string) $request->header('X-Webhook-Signature', '');
        $timestamp = (string) $request->header('X-Webhook-Timestamp', '');

        if ($hmac !== '' && $timestamp !== '') {
            return 't='.$timestamp.',v1='.$hmac;
        }

        return (string) $request->header('X-Secret-Key', '');
    }

    /**
     * Statuts Bictorys. « authorized » est une autorisation de carte avant
     * capture, que leur documentation demande de traiter comme un succès.
     * « pending » et « processing » restent en attente : le webhook tranchera.
     */
    private function mapStatus(mixed $status): TransactionStatus
    {
        return match (is_string($status) ? strtolower($status) : '') {
            'succeeded', 'authorized' => TransactionStatus::Completed,
            'failed', 'cancelled', 'canceled', 'reversed' => TransactionStatus::Failed,
            default => TransactionStatus::Pending,
        };
    }

    /**
     * Code pays attendu par Bictorys, qui n'est pas toujours le code ISO :
     * leur table des codes donne « BK » pour le Burkina là où leur exemple de
     * détection par indicatif donne « BF », et leur catalogue utilise les
     * deux. On n'en devine aucun : la valeur vient du réglage
     * (`BICTORYS_COUNTRY`), ou des métadonnées de l'appel pour un cas isolé.
     */
    private function country(array $params): string
    {
        $country = $params['metadata']['country'] ?? $this->config['country'] ?? null;

        return strtoupper((string) ($country ?: 'SN'));
    }

    /**
     * Informations client, facultatives chez Bictorys.
     *
     * Le téléphone ne part que s'il a leur forme exacte, « +221771234567 » :
     * les espaces sont refusés et un numéro sans indicatif fait échouer tout
     * l'appel. On ne lui ajoute pas d'indicatif, ce serait deviner le pays de
     * l'acheteur.
     *
     * @return array<string, string>|null
     */
    private function customer(array $params): ?array
    {
        $source = $params['metadata']['customer'] ?? null;

        if (! is_array($source)) {
            return null;
        }

        $customer = array_filter([
            'name' => $source['name'] ?? null,
            'phone' => $this->phone($source['phone'] ?? null),
            'email' => $source['email'] ?? null,
            'country' => isset($source['country']) ? strtoupper((string) $source['country']) : null,
        ], fn ($value) => is_string($value) && $value !== '');

        return $customer === [] ? null : $customer;
    }

    private function phone(mixed $phone): ?string
    {
        if (! is_string($phone) && ! is_int($phone)) {
            return null;
        }

        $compact = preg_replace('/[^\d+]/', '', (string) $phone);

        return is_string($compact) && preg_match('/^\+\d{8,15}$/', $compact) === 1 ? $compact : null;
    }

    /**
     * Le QR code de Wave est une image PNG en base64 : la garder gonflerait
     * `gateway_response` de plusieurs kilo-octets à chaque paiement, pour une
     * donnée d'affichage qui ne vaut plus rien une fois le paiement fait.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $data
     */
    private function identifiant(array $data): ?string
    {
        foreach (['transactionId', 'chargeId'] as $champ) {
            if (is_string($data[$champ] ?? null) && $data[$champ] !== '') {
                return $data[$champ];
            }
        }

        return null;
    }

    private function withoutQrCode(array $data): array
    {
        unset($data['qrCode']);

        return $data;
    }

    /**
     * La clé publique signe les créations de paiement et les lectures de
     * statut. Absente, Bictorys répond 401 sans rien dire d'utile.
     */
    private function apiKey(): string
    {
        $key = (string) ($this->config['api_key'] ?? '');

        if ($key === '') {
            throw new \RuntimeException('Bictorys: la clé publique BICTORYS_API_KEY n\'est pas configurée.');
        }

        return $key;
    }

    private function baseUrl(): string
    {
        return rtrim((string) ($this->config['base_url'] ?? 'https://api.bictorys.com'), '/');
    }
}
