# AfriPay - Unified Payment Gateway for Africa

**Accept payments from Wave, Orange Money, PayDunya, PayTech, Stripe & PayPal in your Laravel app with a single, clean API.**

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sunucode/afripay.svg)](https://packagist.org/packages/sunucode/afripay)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-8892BF.svg)](https://php.net)
[![Laravel](https://img.shields.io/badge/laravel-11%20%7C%2012%20%7C%2013-FF2D20.svg)](https://laravel.com)

---

[Francais](#francais) | [English](#english)

---

<a name="francais"></a>
## Francais

### Pourquoi AfriPay ?

Les developpeurs en Afrique de l'Ouest integrent manuellement chaque passerelle de paiement dans chaque projet. Wave, Orange Money, PayDunya, PayTech... chacun avec son API, ses webhooks, ses signatures.

**AfriPay unifie tout ca en une seule interface :**

```php
// Payer via Wave
$payment = AfriPay::via('wave')->charge([
    'amount'      => 15000,
    'currency'    => 'XOF',
    'description' => 'Abonnement Premium',
    'success_url' => route('payment.success'),
    'error_url'   => route('payment.error'),
]);

return redirect($payment['redirect_url']);
```

Changer de passerelle ? Une seule ligne :

```php
AfriPay::via('stripe')->charge([...]);
AfriPay::via('paydunya')->charge([...]);
AfriPay::via('orange_money')->charge([...]);
```

### Passerelles supportees

| Passerelle | Pays | Type | Statut |
|------------|------|------|--------|
| **Wave** | SN, CI, ML, BF | Mobile Money | Production |
| **Orange Money** | SN, CI, ML, BF, CM, GN | Mobile Money | Beta |
| **PayDunya** | SN, CI, BJ, TG, BF, ML | Multi-canal | Production |
| **PayTech** | SN | Multi-canal | Production |
| **Stripe** | Global | Carte bancaire | Production |
| **PayPal** | Global | International | Production |

### Installation

```bash
composer require sunucode/afripay
```

Publier la configuration :

```bash
php artisan vendor:publish --tag=afripay-config
```

Lancer les migrations :

```bash
php artisan migrate
```

### Configuration

Ajoutez vos cles dans `.env` :

```env
# Passerelle par defaut
AFRIPAY_DEFAULT_GATEWAY=wave
AFRIPAY_CURRENCY=XOF

# Securite : seuls les webhooks peuvent confirmer un paiement (recommande)
# Mettre a false en dev si les webhooks ne peuvent pas atteindre votre serveur
AFRIPAY_TRUST_WEBHOOK_ONLY=true

# Activer/desactiver les passerelles individuellement
AFRIPAY_WAVE_ENABLED=true
AFRIPAY_STRIPE_ENABLED=true
AFRIPAY_PAYDUNYA_ENABLED=true
AFRIPAY_PAYTECH_ENABLED=true
AFRIPAY_ORANGE_MONEY_ENABLED=false
AFRIPAY_PAYPAL_ENABLED=false

# Wave
WAVE_API_KEY=wave_sn_...
WAVE_API_SECRET=wave_sn_...
WAVE_WEBHOOK_SECRET=wave_sn_WHS_...

# Stripe
STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...

# PayDunya
PAYDUNYA_MASTER_KEY=...
PAYDUNYA_PRIVATE_KEY=...
PAYDUNYA_TOKEN=...
PAYDUNYA_MODE=test

# Orange Money
ORANGE_MONEY_CLIENT_ID=...
ORANGE_MONEY_CLIENT_SECRET=...
ORANGE_MONEY_MERCHANT_KEY=...

# PayPal
PAYPAL_CLIENT_ID=...
PAYPAL_CLIENT_SECRET=...
PAYPAL_MODE=sandbox

# PayTech
PAYTECH_API_KEY=...
PAYTECH_API_SECRET=...
PAYTECH_ENV=test
```

### Utilisation

#### Initier un paiement

```php
use SunuCode\AfriPay\Facades\AfriPay;

$payment = AfriPay::via('wave')->charge([
    'amount'      => 25000,
    'currency'    => 'XOF',
    'description' => 'Commande #1234',
    'success_url' => route('orders.payment.success'),
    'error_url'   => route('orders.payment.error'),
    'metadata'    => [
        'order_id' => 1234,
        'user_id'  => auth()->id(),
    ],
]);

// $payment['redirect_url']  -> URL de paiement (rediriger l'utilisateur)
// $payment['transaction']   -> Instance Transaction (sauvegardee en DB)

return redirect($payment['redirect_url']);
```

#### Lier a un modele (polymorphic)

```php
$payment = AfriPay::via('paydunya')->charge([
    'amount'        => 9900,
    'success_url'   => route('subscription.success'),
    'error_url'     => route('subscription.error'),
    'payable_type'  => Subscription::class,
    'payable_id'    => $subscription->id,
]);
```

#### Ecouter les evenements (le plus important)

```php
// app/Providers/EventServiceProvider.php
// ou dans un Listener dedie

use SunuCode\AfriPay\Events\PaymentCompleted;
use SunuCode\AfriPay\Events\PaymentFailed;
use SunuCode\AfriPay\Events\PaymentRefunded;

class HandlePaymentCompleted
{
    public function handle(PaymentCompleted $event): void
    {
        $transaction = $event->transaction;

        // Activer l'abonnement, envoyer un email, etc.
        $subscription = $transaction->payable;
        $subscription->activate();

        // Acceder aux metadonnees
        $orderId = $transaction->metadata['order_id'] ?? null;
    }
}
```

#### Verifier une transaction (fallback)

Si le webhook n'est pas encore arrive quand l'utilisateur revient :

```php
// Route de succes
public function paymentSuccess(Request $request)
{
    $transaction = Transaction::where('reference', $request->reference)->first();

    if ($transaction->status->isPending()) {
        // Verifier aupres de la passerelle ET dispatcher l'event si confirme
        $transaction = AfriPay::verifyAndProcess($transaction);
    }

    if ($transaction->status->isCompleted()) {
        return view('payment.success');
    }

    return view('payment.pending');
}
```

#### Rembourser

```php
$transaction = AfriPay::refund($transaction, 'Client insatisfait');
// Dispatche PaymentRefunded
```

#### Lister les passerelles actives

```php
// Toutes les passerelles activees via .env
$gateways = AfriPay::enabledGateways();
// ['wave', 'stripe', 'paydunya', 'paytech']

// Verifier si une passerelle est active
if (AfriPay::isEnabled('orange_money')) {
    // ...
}
```

#### Mode webhook-only vs fallback (trust_webhook_only)

```env
# PRODUCTION (recommande) — seul le webhook peut confirmer un paiement
AFRIPAY_TRUST_WEBHOOK_ONLY=true

# DEVELOPPEMENT — l'URL de retour peut aussi confirmer
AFRIPAY_TRUST_WEBHOOK_ONLY=false
```

Quand `trust_webhook_only=true`, `verifyAndProcess()` verifie le statut aupres de la passerelle mais ne dispatche **aucun evenement**. Seul le webhook declenche `PaymentCompleted`. C'est plus sur car ca empeche un utilisateur de forger une URL de succes.

Quand `trust_webhook_only=false`, les deux chemins (webhook ET URL de retour) peuvent declencher les evenements. Utile en dev local quand les webhooks ne peuvent pas atteindre votre machine.

#### Ajouter une passerelle personnalisee

```php
// Dans un ServiceProvider
use SunuCode\AfriPay\PaymentManager;

PaymentManager::extend('cinetpay', function (array $config) {
    return new CinetPayGateway($config);
});

// Utilisation
AfriPay::via('cinetpay')->charge([...]);
```

### Webhooks

Les webhooks sont automatiquement enregistres a :

```
POST /afripay/webhooks/wave
POST /afripay/webhooks/stripe
POST /afripay/webhooks/paydunya
POST /afripay/webhooks/orange-money
POST /afripay/webhooks/paytech
POST /afripay/webhooks/paypal
```

Le chemin est configurable via `AFRIPAY_WEBHOOK_PATH`.

**Chaque webhook :**
- Verifie la signature (HMAC-SHA256 pour Wave/Stripe/PayTech, master_key pour PayDunya)
- Verifie le montant (tolerance +/- 1 unite)
- Utilise `lockForUpdate()` pour eviter les doublons
- Dispatche `PaymentCompleted` ou `PaymentFailed`

### Securite

- **Idempotence** : Le champ `processed_at` empeche le double-traitement
- **Verrouillage DB** : `lockForUpdate()` sur chaque transaction pendant le webhook
- **Verification de montant** : Tolerance +/- 1 unite avant d'accepter
- **Anti-replay** : Timestamps verifies (Wave, Stripe) avec tolerance de 5 min
- **Zero-decimal** : XOF/XAF geres automatiquement (pas de x100 pour Stripe)
- **Orange Money** : Contre-verification API obligatoire (pas de signature webhook)

### Evenements disponibles

| Evenement | Quand | Donnees |
|-----------|-------|---------|
| `PaymentInitiated` | Apres `charge()` | `$transaction`, `$gateway` |
| `PaymentCompleted` | Webhook confirme | `$transaction` |
| `PaymentFailed` | Webhook echoue | `$transaction` |
| `PaymentRefunded` | Apres `refund()` | `$transaction`, `$reason` |

---

<a name="english"></a>
## English

### Why AfriPay?

West African developers manually integrate each payment gateway in every project. Wave, Orange Money, PayDunya, PayTech... each with its own API, webhooks, and signatures.

**AfriPay unifies everything into a single interface:**

```php
$payment = AfriPay::via('wave')->charge([
    'amount'      => 15000,
    'currency'    => 'XOF',
    'description' => 'Premium Subscription',
    'success_url' => route('payment.success'),
    'error_url'   => route('payment.error'),
]);

return redirect($payment['redirect_url']);
```

### Installation

```bash
composer require sunucode/afripay
php artisan vendor:publish --tag=afripay-config
php artisan migrate
```

### Listening to Events

This is the primary way to react to payment outcomes:

```php
use SunuCode\AfriPay\Events\PaymentCompleted;

class ActivateSubscription
{
    public function handle(PaymentCompleted $event): void
    {
        $transaction = $event->transaction;
        $subscription = $transaction->payable;
        $subscription->activate();
    }
}
```

### Custom Gateways

Extend AfriPay with your own gateways:

```php
use SunuCode\AfriPay\Contracts\GatewayInterface;
use SunuCode\AfriPay\PaymentManager;

class CinetPayGateway implements GatewayInterface
{
    // Implement the 4 methods: charge(), handleWebhook(), verify(), verifySignature()
}

PaymentManager::extend('cinetpay', fn($config) => new CinetPayGateway($config));
```

### Security

- **Idempotent processing** via atomic `processed_at` flag
- **Database locking** (`lockForUpdate`) prevents race conditions
- **Amount verification** with configurable tolerance
- **Replay protection** with timestamp validation (Wave, Stripe)
- **Zero-decimal currencies** (XOF, XAF) handled automatically
- **Orange Money**: Mandatory API counter-verification (no webhook signature)

---

## Requirements

- PHP >= 8.2
- Laravel 11, 12, or 13
- A database supporting `lockForUpdate()` (MySQL, PostgreSQL)

## Contributing

Contributions are welcome! Please submit pull requests to the `main` branch.

## Credits

- Built by [Sunu Code](https://sunucode.com) — Software agency based in Dakar, Senegal
- Extracted from [Semplio](https://semplio.com) — Business management SaaS for African SMEs

## License

MIT License. See [LICENSE](LICENSE) for details.
