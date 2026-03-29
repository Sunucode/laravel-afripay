<?php

namespace SunuCode\AfriPay\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use SunuCode\AfriPay\PaymentManager;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    public function __construct(
        private readonly PaymentManager $manager,
    ) {}

    /**
     * Verify the webhook signature for the given gateway.
     * Gateway is determined from the route parameter or URL segment.
     */
    public function handle(Request $request, Closure $next, ?string $gateway = null): Response
    {
        $gateway = $gateway ?? $request->route('gateway') ?? $this->guessGateway($request);

        if (! $gateway) {
            return response()->json(['error' => 'Unknown gateway'], 400);
        }

        $signature = $this->extractSignature($request, $gateway);

        if (! $this->manager->verifyWebhookSignature($gateway, $signature, $request->getContent())) {
            return response()->json(['error' => 'Invalid webhook signature'], 403);
        }

        return $next($request);
    }

    private function extractSignature(Request $request, string $gateway): string
    {
        return match ($gateway) {
            'wave' => $request->header('Wave-Signature', ''),
            'stripe' => $request->header('Stripe-Signature', ''),
            'paydunya' => $request->input('data.hash') ?? $request->input('master_key') ?? '',
            default => '',
        };
    }

    private function guessGateway(Request $request): ?string
    {
        $segments = $request->segments();
        $segment = end($segments) ?: null;

        // Normalize: URL uses hyphens (orange-money) but gateway name uses underscores (orange_money)
        return $segment ? str_replace('-', '_', $segment) : null;
    }
}
