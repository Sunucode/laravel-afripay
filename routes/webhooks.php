<?php

use Illuminate\Support\Facades\Route;
use SunuCode\AfriPay\Http\Controllers\WebhookController;

$path = config('afripay.webhook_path', '/afripay/webhooks');

Route::middleware('throttle:60,1')
    ->prefix(ltrim($path, '/'))
    ->name('afripay.webhooks.')
    ->group(function () {
        Route::post('/wave', [WebhookController::class, 'wave'])->name('wave');
        Route::post('/stripe', [WebhookController::class, 'stripe'])->name('stripe');
        Route::post('/paydunya', [WebhookController::class, 'paydunya'])->name('paydunya');
        Route::post('/orange-money', [WebhookController::class, 'orangeMoney'])->name('orange-money');
        Route::post('/paytech', [WebhookController::class, 'paytech'])->name('paytech');
        Route::post('/paypal', [WebhookController::class, 'paypal'])->name('paypal');
    });
