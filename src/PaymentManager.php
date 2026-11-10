<?php

namespace SunuCode\AfriPay;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use SunuCode\AfriPay\Contracts\ChargesOffSession;
use SunuCode\AfriPay\Contracts\GatewayInterface;
use SunuCode\AfriPay\Contracts\SettlesFromGateway;
use SunuCode\AfriPay\Enums\TransactionStatus;
use SunuCode\AfriPay\Events\PaymentCompleted;
use SunuCode\AfriPay\Events\PaymentFailed;
use SunuCode\AfriPay\Events\PaymentInitiated;
use SunuCode\AfriPay\Events\PaymentRefunded;
use SunuCode\AfriPay\Gateways\OrangeMoneyGateway;
use SunuCode\AfriPay\Gateways\PayDunyaGateway;
use SunuCode\AfriPay\Gateways\PayPalGateway;
use SunuCode\AfriPay\Gateways\PayTechGateway;
use SunuCode\AfriPay\Gateways\StripeGateway;
use SunuCode\AfriPay\Gateways\WaveGateway;
use SunuCode\AfriPay\Models\PaymentMethod;
use SunuCode\AfriPay\Models\Transaction;

class PaymentManager
{
    protected Application $app;

    protected ?string $currentGateway = null;

    /**
     * Resolved gateway instances.
     *
     * @var array<string, GatewayInterface>
     */
    protected array $gateways = [];

    /**
     * Custom gateway resolvers registered by the user.
     *
     * @var array<string, callable>
     */
    protected static array $customGateways = [];

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Select a gateway for the next operation.
     *
     * Usage: AfriPay::via('wave')->charge([...])
     */
    public function via(string $gateway): static
    {
        $this->currentGateway = $gateway;

        return $this;
    }

    /**
     * Initiate a payment charge.
     *
     * @param  array{
     *     amount: float,
     *     currency?: string,
     *     description?: string,
     *     reference?: string,
     *     success_url: string,
     *     error_url: string,
     *     metadata?: array,
     *     payable_type?: string,
     *     payable_id?: int|string,
     * }  $params
     * @return array{redirect_url: string, transaction: Transaction}
     */
    public function charge(array $params): array
    {
        $name = $this->currentGateway ?? config('afripay.default', 'wave');

        if (! $this->isEnabled($name)) {
            $this->currentGateway = null;
            throw new \RuntimeException("Gateway [{$name}] is disabled. Enable it with AFRIPAY_".strtoupper($name).'_ENABLED=true in your .env file.');
        }

        $gateway = $this->resolveGateway();
        $result = $gateway->charge($params);

        event(new PaymentInitiated($result['transaction'], $gateway->name()));

        $this->currentGateway = null;

        return $result;
    }

    /**
     * Handle an incoming webhook from a gateway.
     * The PRIMARY path: dispatches PaymentCompleted or PaymentFailed when the
     * transaction leaves pending, and only then: a replayed or late
     * notification returns null and dispatches nothing.
     */
    public function handleWebhook(string $gateway, array $data): ?Transaction
    {
        $driver = $this->gateway($gateway);
        $transaction = $driver->handleWebhook($data);

        if ($transaction) {
            $this->dispatchEvent($transaction);
        }

        return $transaction;
    }

    /**
     * Verify a transaction status directly with the gateway.
     * Read-only for Wave, PayDunya and Stripe: the reported status is kept on
     * $transaction->remoteStatus, never saved, and no event is dispatched.
     */
    public function verify(Transaction $transaction): Transaction
    {
        $driver = $this->gateway($transaction->gateway);

        return $driver->verify($transaction);
    }

    /**
     * The FALLBACK path (success URL redirect).
     *
     * For Wave, PayDunya, Stripe and Orange Money:
     *
     * - trust_webhook_only = true (default, recommended): reads the status from
     *   the gateway without settling anything. The webhook stays the only path
     *   that settles the transaction and dispatches PaymentCompleted; use
     *   $transaction->isConfirmedByGateway() to show « payment received ».
     *
     * - trust_webhook_only = false: settles the transaction the same way a
     *   webhook does (once, under a lock) and dispatches the event. Useful in
     *   development or when webhooks cannot reach the server.
     */
    public function verifyAndProcess(Transaction $transaction): Transaction
    {
        $driver = $this->gateway($transaction->gateway);

        if ($driver instanceof SettlesFromGateway) {
            if (config('afripay.trust_webhook_only', true)) {
                return $driver->verify($transaction);
            }

            // Unreachable gateway: the page shows « pending », the webhook settles later.
            try {
                $settled = $driver->settle($transaction);
            } catch (\Throwable $e) {
                Log::warning('AfriPay: settlement from the return page failed', [
                    'gateway' => $transaction->gateway,
                    'reference' => $transaction->reference,
                    'error' => $e->getMessage(),
                ]);
                $settled = null;
            }

            if ($settled) {
                $this->dispatchEvent($settled);

                return $settled;
            }

            return $transaction->fresh() ?? $transaction;
        }

        // PayPal captures on return and PayTech has no status check: they keep
        // their former behaviour, and the event goes out when the return page is
        // the one that settled the payment (the late webhook then dispatches nothing).
        $wasPending = $transaction->status === TransactionStatus::Pending;
        $transaction = $driver->verify($transaction);

        if ($wasPending && $transaction->status !== TransactionStatus::Pending) {
            $this->dispatchEvent($transaction);
        }

        return $transaction;
    }

    /**
     * Verify a webhook signature for a given gateway.
     */
    /**
     * Whether this gateway can charge a customer who is not there.
     *
     * Mobile Money cannot: every payment is authorised one by one. Ask before
     * offering a subscription or usage billing, rather than discovering it on
     * the first billing run.
     */
    public function canChargeOffSession(?string $gateway = null): bool
    {
        $name = $gateway ?? $this->currentGateway ?? config('afripay.default', 'wave');

        return $this->gateway($name) instanceof ChargesOffSession;
    }

    /**
     * Open the page where the customer authorises a payment method. Nothing
     * is charged.
     *
     * @param  array{success_url: string, error_url: string, reference?: string, description?: string, metadata?: array}  $params
     * @return array{redirect_url: string, reference: string}
     */
    public function startSavingMethod(array $params): array
    {
        $driver = $this->offSessionDriver();
        $this->currentGateway = null;

        return $driver->startSavingMethod($params);
    }

    /**
     * Keep the handles from a finished authorisation. Null while it is not
     * complete.
     *
     * `$expectedReference` is the reference `startSavingMethod()` returned. A
     * session id travels in a URL, so it reaches browser history, server logs
     * and anyone the customer forwards the link to. Without this check, that
     * id is the only thing needed to claim the card it authorised: replaying
     * it from another account attaches someone else's card to that account.
     * Pass the reference you stored when you opened the session, and a
     * replayed id is refused.
     */
    public function confirmSavedMethod(string $gatewayReference, ?Model $payable = null, ?string $gateway = null, ?string $expectedReference = null): ?PaymentMethod
    {
        return $this->offSessionDriver($gateway)->confirmSavedMethod($gatewayReference, $payable, $expectedReference);
    }

    /**
     * Charge a stored payment method, without the customer.
     *
     * @param  array{amount: float, currency?: string, description?: string, reference?: string, metadata?: array}  $params
     *
     * @throws \SunuCode\AfriPay\Exceptions\RequiresCustomerAuthentication
     */
    public function chargeSaved(PaymentMethod $method, array $params): Transaction
    {
        return $this->offSessionDriver($method->gateway)->chargeSaved($method, $params);
    }

    /**
     * The selected gateway, refused outright when it cannot charge off
     * session: a clear error here beats a failed billing run later.
     */
    protected function offSessionDriver(?string $gateway = null): ChargesOffSession
    {
        $name = $gateway ?? $this->currentGateway ?? config('afripay.default', 'wave');
        $driver = $this->gateway($name);

        if (! $driver instanceof ChargesOffSession) {
            throw new \InvalidArgumentException("Gateway [{$name}] cannot charge off session.");
        }

        return $driver;
    }

    public function verifyWebhookSignature(string $gateway, string $signature, string $rawBody): bool
    {
        return $this->gateway($gateway)->verifySignature($signature, $rawBody);
    }

    /**
     * Mark a transaction as refunded and dispatch event.
     */
    public function refund(Transaction $transaction, ?string $reason = null): Transaction
    {
        $transaction->update([
            'status' => TransactionStatus::Refunded,
            'metadata' => array_merge($transaction->metadata ?? [], [
                'refund_reason' => $reason,
                'refunded_at' => now()->toIso8601String(),
            ]),
        ]);

        $transaction = $transaction->fresh();

        event(new PaymentRefunded($transaction, $reason));

        return $transaction;
    }

    /**
     * Get a specific gateway driver instance.
     */
    public function gateway(string $name): GatewayInterface
    {
        if (! isset($this->gateways[$name])) {
            $this->gateways[$name] = $this->createGateway($name);
        }

        return $this->gateways[$name];
    }

    /**
     * Register a custom gateway driver.
     *
     * Usage: AfriPay::extend('cinetpay', fn($config) => new CinetPayGateway($config))
     */
    public static function extend(string $name, callable $resolver): void
    {
        static::$customGateways[$name] = $resolver;
    }

    /**
     * Get all configured gateway names (enabled or not).
     *
     * @return array<string>
     */
    public function availableGateways(): array
    {
        return array_keys(config('afripay.gateways', []));
    }

    /**
     * Get only enabled gateway names.
     *
     * @return array<string>
     */
    public function enabledGateways(): array
    {
        return collect(config('afripay.gateways', []))
            ->filter(fn (array $config) => ($config['enabled'] ?? true) === true)
            ->keys()
            ->all();
    }

    /**
     * Check if a gateway is enabled.
     */
    public function isEnabled(string $gateway): bool
    {
        return config("afripay.gateways.{$gateway}.enabled", true) === true;
    }

    /**
     * Resolve the current gateway (from via() or default).
     */
    protected function resolveGateway(): GatewayInterface
    {
        $name = $this->currentGateway ?? config('afripay.default', 'wave');

        return $this->gateway($name);
    }

    /**
     * Create a gateway driver instance.
     */
    protected function createGateway(string $name): GatewayInterface
    {
        $config = config("afripay.gateways.{$name}", []);

        // Check for custom gateway first
        if (isset(static::$customGateways[$name])) {
            return call_user_func(static::$customGateways[$name], $config);
        }

        return match ($name) {
            'wave' => new WaveGateway($config),
            'stripe' => new StripeGateway($config),
            'paydunya' => new PayDunyaGateway($config),
            'orange_money' => new OrangeMoneyGateway($config),
            'paypal' => new PayPalGateway($config),
            'paytech' => new PayTechGateway($config),
            default => throw new \InvalidArgumentException("Gateway [{$name}] is not supported. Use AfriPay::extend() to register custom gateways."),
        };
    }

    /**
     * Dispatch the appropriate event based on transaction status.
     */
    protected function dispatchEvent(Transaction $transaction): void
    {
        match ($transaction->status) {
            TransactionStatus::Completed => event(new PaymentCompleted($transaction)),
            TransactionStatus::Failed => event(new PaymentFailed($transaction)),
            TransactionStatus::Refunded => event(new PaymentRefunded($transaction)),
            default => null,
        };
    }
}
