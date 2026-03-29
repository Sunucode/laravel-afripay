<?php

namespace SunuCode\AfriPay;

use Illuminate\Contracts\Foundation\Application;
use SunuCode\AfriPay\Contracts\GatewayInterface;
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
     * Dispatches PaymentCompleted or PaymentFailed events automatically.
     * This is the PRIMARY path — always dispatches events.
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
     * Read-only — NEVER dispatches events.
     */
    public function verify(Transaction $transaction): Transaction
    {
        $driver = $this->gateway($transaction->gateway);

        return $driver->verify($transaction);
    }

    /**
     * Verify AND process: verify with gateway, then dispatch events if completed.
     *
     * This is the FALLBACK path (success URL redirect).
     * Behavior depends on `trust_webhook_only` config:
     *
     * - true (default, recommended): verifies status but does NOT dispatch events.
     *   The webhook is the sole source of truth. Returns the updated transaction
     *   so your app can show a "payment received, processing..." message.
     *
     * - false: verifies AND dispatches events, same as a webhook.
     *   Useful in development or when webhooks can't reach your server.
     */
    public function verifyAndProcess(Transaction $transaction): Transaction
    {
        $transaction = $this->verify($transaction);

        if ($transaction->status === TransactionStatus::Completed) {
            $trustWebhookOnly = config('afripay.trust_webhook_only', true);

            if (! $trustWebhookOnly) {
                $this->dispatchEvent($transaction);
            }
        }

        return $transaction;
    }

    /**
     * Verify a webhook signature for a given gateway.
     */
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
