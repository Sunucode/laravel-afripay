<?php

namespace SunuCode\AfriPay\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use SunuCode\AfriPay\AfriPayServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [AfriPayServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('afripay.gateways.wave', [
            'api_key' => 'test_api_key',
            'api_secret' => 'test_api_secret',
            'webhook_secret' => 'test_webhook_secret',
            'base_url' => 'https://api.wave.com/v1',
        ]);

        $app['config']->set('afripay.gateways.stripe', [
            'key' => 'pk_test_xxx',
            'secret' => 'sk_test_xxx',
            'webhook_secret' => 'whsec_test_xxx',
        ]);

        $app['config']->set('afripay.gateways.paydunya', [
            'master_key' => 'test_master_key',
            'private_key' => 'test_private_key',
            'token' => 'test_token',
            'mode' => 'test',
        ]);

        $app['config']->set('afripay.gateways.paytech', [
            'api_key' => 'test_api_key',
            'api_secret' => 'test_api_secret',
            'base_url' => 'https://paytech.sn/api',
            'env' => 'test',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
