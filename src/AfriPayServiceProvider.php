<?php

namespace SunuCode\AfriPay;

use Illuminate\Support\ServiceProvider;
use SunuCode\AfriPay\Console\InstallCommand;
use SunuCode\AfriPay\Http\Middleware\VerifyWebhookSignature;

class AfriPayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/afripay.php', 'afripay');

        $this->app->singleton(PaymentManager::class, function ($app) {
            return new PaymentManager($app);
        });

        $this->app->alias(PaymentManager::class, 'afripay');
    }

    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__.'/../config/afripay.php' => config_path('afripay.php'),
        ], 'afripay-config');

        // Publish migrations
        $this->publishes([
            __DIR__.'/../database/migrations/' => database_path('migrations'),
        ], 'afripay-migrations');

        // Load migrations
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Load routes
        $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');

        // Register middleware alias
        $this->app['router']->aliasMiddleware('afripay.signature', VerifyWebhookSignature::class);

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
            ]);
        }
    }
}
