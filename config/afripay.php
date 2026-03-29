<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Gateway
    |--------------------------------------------------------------------------
    |
    | The default payment gateway to use when none is specified.
    |
    */

    'default' => env('AFRIPAY_DEFAULT_GATEWAY', 'wave'),

    /*
    |--------------------------------------------------------------------------
    | Default Currency
    |--------------------------------------------------------------------------
    |
    | The default currency for transactions (ISO 4217).
    | XOF = Franc CFA BCEAO (West Africa)
    | XAF = Franc CFA BEAC (Central Africa)
    |
    */

    'currency' => env('AFRIPAY_CURRENCY', 'XOF'),

    /*
    |--------------------------------------------------------------------------
    | Callback Base URL
    |--------------------------------------------------------------------------
    |
    | The base URL for webhook callbacks. Useful when your app URL differs
    | from the publicly reachable URL (e.g. behind a tunnel in development).
    | Falls back to APP_URL if not set.
    |
    */

    'callback_base_url' => env('AFRIPAY_CALLBACK_URL'),

    /*
    |--------------------------------------------------------------------------
    | Webhook Path
    |--------------------------------------------------------------------------
    |
    | The URL path prefix for webhook endpoints.
    | Webhooks will be available at: {app_url}/{webhook_path}/{gateway}
    |
    */

    'webhook_path' => env('AFRIPAY_WEBHOOK_PATH', '/afripay/webhooks'),

    /*
    |--------------------------------------------------------------------------
    | Transaction Table
    |--------------------------------------------------------------------------
    |
    | The database table name for storing payment transactions.
    |
    */

    'table' => env('AFRIPAY_TABLE', 'afripay_transactions'),

    /*
    |--------------------------------------------------------------------------
    | Trust Webhook Only
    |--------------------------------------------------------------------------
    |
    | When true, ONLY webhook callbacks can trigger PaymentCompleted events.
    | The success URL fallback (verifyAndProcess) will verify the transaction
    | status but will NOT dispatch events — the webhook is the sole source
    | of truth. This is the recommended setting for production.
    |
    | When false, both webhooks AND the success URL fallback can dispatch
    | PaymentCompleted events. Useful in development or when webhooks are
    | unreliable (e.g. behind a firewall without a tunnel).
    |
    */

    'trust_webhook_only' => env('AFRIPAY_TRUST_WEBHOOK_ONLY', true),

    /*
    |--------------------------------------------------------------------------
    | Payment Gateways
    |--------------------------------------------------------------------------
    |
    | Configuration for each payment gateway.
    | Only configure the gateways you need.
    |
    */

    'gateways' => [

        /*
        |----------------------------------------------------------------------
        | Wave (Senegal, Ivory Coast, Mali, Burkina Faso)
        |----------------------------------------------------------------------
        | Mobile money — dominant in West Africa.
        | No sandbox available — uses 5 XOF in local/testing mode.
        | Docs: https://docs.wave.com
        */
        'wave' => [
            'enabled' => env('AFRIPAY_WAVE_ENABLED', true),
            'api_key' => env('WAVE_API_KEY'),
            'api_secret' => env('WAVE_API_SECRET'),
            'webhook_secret' => env('WAVE_WEBHOOK_SECRET'),
            'base_url' => env('WAVE_BASE_URL', 'https://api.wave.com/v1'),
        ],

        /*
        |----------------------------------------------------------------------
        | Stripe (Global)
        |----------------------------------------------------------------------
        | Card payments — international support.
        | Handles zero-decimal currencies (XOF, XAF) automatically.
        | Docs: https://stripe.com/docs
        */
        'stripe' => [
            'enabled' => env('AFRIPAY_STRIPE_ENABLED', true),
            'key' => env('STRIPE_KEY'),
            'secret' => env('STRIPE_SECRET'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        ],

        /*
        |----------------------------------------------------------------------
        | PayDunya (Senegal, Ivory Coast, Benin, Togo, Burkina, Mali)
        |----------------------------------------------------------------------
        | Multi-channel payment gateway for West Africa.
        | Supports: mobile money, card, bank transfer.
        | Docs: https://paydunya.com/developers
        */
        'paydunya' => [
            'enabled' => env('AFRIPAY_PAYDUNYA_ENABLED', true),
            'master_key' => env('PAYDUNYA_MASTER_KEY'),
            'private_key' => env('PAYDUNYA_PRIVATE_KEY'),
            'token' => env('PAYDUNYA_TOKEN'),
            'mode' => env('PAYDUNYA_MODE', 'test'), // test or live
        ],

        /*
        |----------------------------------------------------------------------
        | Orange Money (Senegal, Ivory Coast, Mali, Burkina, Cameroon, Guinea)
        |----------------------------------------------------------------------
        | Mobile money via Orange telecom.
        | Uses OAuth2 for authentication.
        | WARNING: No webhook signature — counter-verification mandatory.
        | Docs: https://developer.orange.com
        */
        'orange_money' => [
            'enabled' => env('AFRIPAY_ORANGE_MONEY_ENABLED', false),
            'client_id' => env('ORANGE_MONEY_CLIENT_ID'),
            'client_secret' => env('ORANGE_MONEY_CLIENT_SECRET'),
            'merchant_key' => env('ORANGE_MONEY_MERCHANT_KEY'),
            'base_url' => env('ORANGE_MONEY_BASE_URL', 'https://api.orange.com'),
            'auth_header' => env('ORANGE_MONEY_AUTH_HEADER'),
        ],

        /*
        |----------------------------------------------------------------------
        | PayPal (Global)
        |----------------------------------------------------------------------
        | International payments — useful for diaspora.
        | Supports Orders v2 API with capture flow.
        | Docs: https://developer.paypal.com
        */
        'paypal' => [
            'enabled' => env('AFRIPAY_PAYPAL_ENABLED', false),
            'client_id' => env('PAYPAL_CLIENT_ID'),
            'client_secret' => env('PAYPAL_CLIENT_SECRET'),
            'mode' => env('PAYPAL_MODE', 'sandbox'), // sandbox or live
        ],

        /*
        |----------------------------------------------------------------------
        | PayTech (Senegal)
        |----------------------------------------------------------------------
        | Local Senegalese payment gateway.
        | Supports: Wave, Orange Money, card via single interface.
        | IPN callback with HMAC-SHA256 signature.
        | Docs: https://paytech.sn/documentation
        */
        'paytech' => [
            'enabled' => env('AFRIPAY_PAYTECH_ENABLED', true),
            'api_key' => env('PAYTECH_API_KEY'),
            'api_secret' => env('PAYTECH_API_SECRET'),
            'base_url' => env('PAYTECH_BASE_URL', 'https://paytech.sn/api'),
            'env' => env('PAYTECH_ENV', 'test'), // test or prod
        ],

    ],

];
