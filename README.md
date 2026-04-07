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

#### Installation rapide (recommande)

Une seule commande genere tout le necessaire :

```bash
php artisan afripay:install
php artisan migrate
```

La commande cree :
- `config/afripay.php` — configuration des passerelles
- `app/Http/Controllers/AfriPayController.php` — controller avec `success()` et `error()`
- `resources/views/payment/{success,pending,error}.blade.php` — vues de retour (HTML simple, a integrer dans votre layout)
- Les routes `/payment/success/{reference}` et `/payment/error/{reference}` dans `routes/web.php`
- Les event listeners dans `AppServiceProvider::boot()`

Par defaut, le controller est cree dans `app/Http/Controllers/`. Pour le placer ailleurs :

```bash
# Exemple : app/Http/Controllers/Payment/AfriPayController.php
php artisan afripay:install --controller-path=Http/Controllers/Payment
```

#### Installation manuelle

```bash
php artisan vendor:publish --tag=afripay-config
php artisan migrate
```

Puis suivez les sections [Ecouter les evenements](#ecouter-les-evenements-le-plus-important) et [Gestion du retour](#gestion-du-retour-success-url) ci-dessous.

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

> **Important :** Laravel auto-decouvre uniquement les listeners pour les events dans `App\Events\*`.
> Les events d'un package vendor comme AfriPay (`SunuCode\AfriPay\Events\*`) ne sont **jamais auto-decouverts**.
> Vous devez les enregistrer manuellement.

Si vous avez utilise `php artisan afripay:install`, les listeners sont deja enregistres avec des `// TODO` a completer. Sinon, ajoutez-les dans `AppServiceProvider::boot()` :

```php
// app/Providers/AppServiceProvider.php

use Illuminate\Support\Facades\Event;
use SunuCode\AfriPay\Events\PaymentCompleted;
use SunuCode\AfriPay\Events\PaymentFailed;
use SunuCode\AfriPay\Events\PaymentRefunded;

public function boot(): void
{
    Event::listen(PaymentCompleted::class, function ($event) {
        $transaction = $event->transaction;

        // Votre logique metier ici
        // Exemples : crediter un wallet, activer un abonnement, envoyer un email...
    });

    Event::listen(PaymentFailed::class, function ($event) {
        // Notifier l'utilisateur, logger l'echec, etc.
    });

    Event::listen(PaymentRefunded::class, function ($event) {
        // Annuler la commande, re-crediter, etc.
    });
}
```

Vous pouvez aussi utiliser des classes Listener dediees :

```php
Event::listen(PaymentCompleted::class, ActivateSubscription::class);
Event::listen(PaymentFailed::class, NotifyPaymentFailure::class);
```

#### Gestion du retour (success URL)

Quand l'utilisateur est redirige vers votre `success_url` apres le paiement, le webhook n'est pas forcement encore arrive. Vous **devez** appeler `verifyAndProcess()` dans votre controller de retour pour confirmer le paiement :

Si vous avez utilise `php artisan afripay:install`, le controller est deja genere avec cette logique. Sinon, voici le code a ajouter dans votre controller de retour :

```php
use SunuCode\AfriPay\Facades\AfriPay;
use SunuCode\AfriPay\Models\Transaction as AfriPayTransaction;

public function success(string $reference)
{
    $transaction = AfriPayTransaction::where('reference', $reference)->firstOrFail();

    // Verifie aupres de la passerelle ET dispatche PaymentCompleted si confirme
    $transaction = AfriPay::verifyAndProcess($transaction);

    if ($transaction->status->isCompleted()) {
        return view('payment.success', compact('transaction'));
    }

    // Le paiement n'est pas encore confirme (webhook en attente)
    return view('payment.pending', compact('transaction'));
}
```

> **Sans cet appel**, si le webhook arrive en retard (ou jamais en dev local),
> l'utilisateur verra une page de succes mais votre logique metier ne sera jamais executee.

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

> **Piege courant en developpement :** Si vous developpez en local sans tunnel (ngrok, Expose...),
> les webhooks ne peuvent pas atteindre votre machine. Avec `AFRIPAY_TRUST_WEBHOOK_ONLY=true` (defaut),
> `verifyAndProcess()` ne declenchera **aucun event** et vos paiements resteront en `pending`.
>
> **Solution :** Mettez `AFRIPAY_TRUST_WEBHOOK_ONLY=false` dans votre `.env` local.
> N'oubliez pas de remettre `true` en production.

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

# Quick setup (recommended) — scaffolds controller, views, routes, and listeners
php artisan afripay:install
php artisan migrate
```

To place the controller in a custom directory:

```bash
php artisan afripay:install --controller-path=Http/Controllers/Payment
```

Or set up manually: `php artisan vendor:publish --tag=afripay-config` and follow the sections below.

### Listening to Events

> **Important:** Laravel only auto-discovers listeners for events in `App\Events\*`.
> Events from a vendor package like AfriPay (`SunuCode\AfriPay\Events\*`) are **never auto-discovered**.
> You must register them manually.

Register your listeners in `AppServiceProvider::boot()`:

```php
// app/Providers/AppServiceProvider.php

use Illuminate\Support\Facades\Event;
use SunuCode\AfriPay\Events\PaymentCompleted;
use SunuCode\AfriPay\Events\PaymentFailed;

public function boot(): void
{
    Event::listen(PaymentCompleted::class, function ($event) {
        $transaction = $event->transaction;
        $subscription = $transaction->payable;
        $subscription->activate();
    });

    Event::listen(PaymentFailed::class, function ($event) {
        // Notify user, log failure, etc.
    });
}
```

You can also use dedicated Listener classes:

```php
Event::listen(PaymentCompleted::class, ActivateSubscription::class);
```

### Handling the Success URL

When the user is redirected to your `success_url`, the webhook may not have arrived yet. You **must** call `verifyAndProcess()` in your return controller:

```php
use SunuCode\AfriPay\Facades\AfriPay;
use SunuCode\AfriPay\Models\Transaction as AfriPayTransaction;

public function success(string $reference)
{
    $transaction = AfriPayTransaction::where('reference', $reference)->firstOrFail();
    $transaction = AfriPay::verifyAndProcess($transaction);

    if ($transaction->status->isCompleted()) {
        return view('payment.success', compact('transaction'));
    }

    return view('payment.pending', compact('transaction'));
}
```

### AFRIPAY_TRUST_WEBHOOK_ONLY

> **Common pitfall in development:** Without a tunnel (ngrok, Expose...), webhooks can't reach
> your local machine. With `AFRIPAY_TRUST_WEBHOOK_ONLY=true` (default), `verifyAndProcess()` will
> **not dispatch any events** and your payments will stay `pending`.
>
> **Fix:** Set `AFRIPAY_TRUST_WEBHOOK_ONLY=false` in your local `.env`.
> Remember to set it back to `true` in production.

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
