<?php

use Illuminate\Support\Facades\Route;
use SunuCode\AfriPay\Http\Controllers\WebhookController;

$path = config('afripay.webhook_path', '/afripay/webhooks');

// Only the gateways enabled in config/afripay.php get a webhook route: a disabled
// gateway cannot be reached from outside.
$routes = [
    'wave' => ['wave', 'wave'],
    'stripe' => ['stripe', 'stripe'],
    'paydunya' => ['paydunya', 'paydunya'],
    'orange_money' => ['orange-money', 'orangeMoney'],
    'paytech' => ['paytech', 'paytech'],
    'paypal' => ['paypal', 'paypal'],
];

Route::middleware('throttle:60,1')
    ->prefix(ltrim($path, '/'))
    ->name('afripay.webhooks.')
    ->group(function () use ($routes) {
        foreach ($routes as $gateway => [$uri, $action]) {
            if (filter_var(config("afripay.gateways.{$gateway}.enabled", true), FILTER_VALIDATE_BOOLEAN)) {
                Route::post('/'.$uri, [WebhookController::class, $action])->name($uri);
            }
        }
    });
