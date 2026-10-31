# Changelog

## 2.0.0

Correctifs de fiabilité des paiements. Version majeure parce que `verify()` ne modifie plus la transaction : un projet en `^1.0` ne la reçoit pas sans le vouloir. Lire « À vérifier » avant de passer en `^2.0`.

### Changement incompatible

- **`verify()` est en lecture seule** pour Wave, PayDunya, Stripe et Orange Money. Le code qui appelait `AfriPay::verify($transaction)` puis lisait `$transaction->status` pour savoir si le paiement est reçu doit lire `$transaction->isConfirmedByGateway()` (ou `$transaction->remoteStatus`). Pour régler la transaction depuis la page de retour, appeler `verifyAndProcess()` avec `AFRIPAY_TRUST_WEBHOOK_ONLY=false`.

### Corrigé

- **Course entre la page de retour et le webhook.** `verify()` ne modifie plus la transaction pour Wave, PayDunya, Stripe et Orange Money : il lit le statut auprès de la passerelle et le place dans `$transaction->remoteStatus`, sans rien enregistrer. Avant, la page de retour passait la transaction en « payée » sans événement, et le webhook arrivé ensuite l'ignorait : `PaymentCompleted` ne partait jamais.
- **Idempotence.** Une transaction ne quitte « en attente » qu'une fois, sous verrou, et l'événement part à ce moment-là seulement. Un webhook rejoué, ou arrivé après la page de retour, ne déclenche plus rien (toutes passerelles).
- **PayDunya.** Le statut et le montant ne sont plus lus dans l'IPN, qui n'est pas signé : ils sont relus sur l'API de confirmation. La casse du statut (« Completed » ou « completed ») est indifférente.
- **Montant et devise.** Un paiement confirmé sans montant, ou d'un montant ou d'une devise différents de ceux de la transaction, échoue (Wave, PayDunya, Stripe).
- **Wave.** Signature lue avec plusieurs `v1=` (rotation de clé) ; l'événement `checkout.session.payment_failed` fait échouer la transaction ; un webhook sans montant est relu sur l'API.
- **Page de retour.** Une passerelle injoignable laisse la page sur « en attente » au lieu de lever une erreur.

### Ajouté

- `Transaction::isConfirmedByGateway()` : vrai si la transaction est payée en base, ou confirmée par la passerelle en attendant le webhook. C'est le test à utiliser sur la page de retour.
- Contrat `SettlesFromGateway` (`settle()`), implémenté par Wave, PayDunya, Stripe et Orange Money.
- `WAVE_TEST_AMOUNT` : montant facturé à la place du vrai, pour un essai en production. Vide par défaut.
- `ORANGE_MONEY_API_PATH` (défaut `orange-money-webpay/dev/v1`) et `ORANGE_MONEY_CURRENCY` (défaut `OUV`) : le passage en production d'Orange Money ne demande plus de modifier le code.
- Index unique sur (passerelle, référence). La migration ne touche jamais aux données : si des doublons existent, elle n'ajoute pas l'index et l'écrit dans le journal.

### À vérifier avant de monter

- **Wave ne facture plus 5 F en local ni en test.** Le montant de test dépendait de `APP_ENV` (`local` ou `testing`) ; il dépend désormais de `WAVE_TEST_AMOUNT`. Un poste qui comptait sur les 5 F doit renseigner `WAVE_TEST_AMOUNT=5`. Le paiement de ce montant vaut paiement attendu.
- **Routes des passerelles désactivées.** Seules les passerelles dont `AFRIPAY_*_ENABLED` est vrai ont leur route de webhook. Attention aux valeurs par défaut : Orange Money et PayPal sont désactivés par défaut, PayTech activé.
- **Page de retour avec `AFRIPAY_TRUST_WEBHOOK_ONLY=true`.** `$transaction->status` reste `pending` tant que le webhook n'est pas arrivé : tester `isConfirmedByGateway()` plutôt que `status->isCompleted()` pour afficher « paiement reçu ».
- **`php artisan migrate`** ajoute l'index unique ; lancer `route:cache` après avoir changé `AFRIPAY_*_ENABLED`.

PayPal et PayTech gardent leur fonctionnement, avec l'idempotence en plus.
