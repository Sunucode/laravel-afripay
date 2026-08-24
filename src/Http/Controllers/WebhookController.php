<?php

namespace SunuCode\AfriPay\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use SunuCode\AfriPay\PaymentManager;

class WebhookController extends Controller
{
    public function __construct(
        private readonly PaymentManager $manager,
    ) {}

    public function wave(Request $request): JsonResponse
    {
        Log::info('AfriPay: Wave webhook received');

        $signature = $request->header('Wave-Signature', '');

        if (! $this->manager->verifyWebhookSignature('wave', $signature, $request->getContent())) {
            Log::warning('AfriPay: Wave signature verification failed');

            return response()->json(['error' => 'Invalid signature'], 403);
        }

        $transaction = $this->manager->handleWebhook('wave', $request->all());

        return response()->json([
            'status' => $transaction ? 'confirmed' : 'ignored',
        ]);
    }

    public function stripe(Request $request): JsonResponse
    {
        Log::info('AfriPay: Stripe webhook received');

        $signature = $request->header('Stripe-Signature', '');

        if (! $this->manager->verifyWebhookSignature('stripe', $signature, $request->getContent())) {
            Log::warning('AfriPay: Stripe signature verification failed');

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $eventType = $request->input('type');
        $acceptedEvents = [
            'checkout.session.completed',
            'checkout.session.expired',
            'payment_intent.succeeded',
            'payment_intent.payment_failed',
        ];

        if (! in_array($eventType, $acceptedEvents)) {
            return response()->json(['status' => 'ignored']);
        }

        $transaction = $this->manager->handleWebhook('stripe', $request->all());

        return response()->json([
            'status' => $transaction ? 'ok' : 'ignored',
        ]);
    }

    public function paydunya(Request $request): JsonResponse
    {
        Log::info('AfriPay: PayDunya webhook received');

        $receivedKey = (string) ($request->input('data.hash') ?? $request->input('master_key') ?? '');
        if (! $this->manager->verifyWebhookSignature('paydunya', $receivedKey, '')) {
            // Length is logged, never the value: it tells a SHA-512 hash (128)
            // from a raw master key or an empty payload, which is enough to
            // diagnose a rejection without exposing anything.
            Log::warning('AfriPay: PayDunya master_key invalid', [
                'received_length' => strlen($receivedKey),
            ]);

            return response()->json(['error' => 'Invalid master_key'], 401);
        }

        $transaction = $this->manager->handleWebhook('paydunya', $request->all());

        return response()->json([
            'status' => $transaction ? 'ok' : 'ignored',
        ]);
    }

    public function orangeMoney(Request $request): JsonResponse
    {
        Log::info('AfriPay: Orange Money webhook received');

        // Orange Money has no signature — counter-verification done in gateway
        $transaction = $this->manager->handleWebhook('orange_money', $request->all());

        return response()->json([
            'status' => $transaction ? 'ok' : 'ignored',
        ]);
    }

    public function paytech(Request $request): JsonResponse
    {
        Log::info('AfriPay: PayTech webhook received');

        // PayTech signature is verified inside the gateway handler
        $transaction = $this->manager->handleWebhook('paytech', $request->all());

        return response()->json([
            'status' => $transaction ? 'ok' : 'ignored',
        ]);
    }

    public function paypal(Request $request): JsonResponse
    {
        Log::info('AfriPay: PayPal webhook received');

        $transaction = $this->manager->handleWebhook('paypal', $request->all());

        return response()->json([
            'status' => $transaction ? 'ok' : 'ignored',
        ]);
    }
}
