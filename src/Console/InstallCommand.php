<?php

namespace SunuCode\AfriPay\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class InstallCommand extends Command
{
    protected $signature = 'afripay:install
        {--controller-path=Http/Controllers : Controller directory relative to app/}';

    protected $description = 'Scaffold AfriPay controller, event listeners, routes, and views';

    public function handle(): int
    {
        $this->info('Installing AfriPay...');
        $this->newLine();

        $controllerPath = trim($this->option('controller-path'), '/');

        $this->publishConfig();
        $this->publishController($controllerPath);
        $this->publishViews();
        $this->registerRoutes($controllerPath);
        $this->registerListeners();

        $this->newLine();
        $this->info('AfriPay installed successfully!');
        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. Add your gateway credentials to <comment>.env</comment>');
        $this->line('  2. Run <comment>php artisan migrate</comment>');
        $this->line('  3. Fill in your business logic in <comment>app/Providers/AppServiceProvider.php</comment>');
        $this->line('  4. Customize the views in <comment>resources/views/payment/</comment> to match your layout');
        $this->newLine();

        return self::SUCCESS;
    }

    protected function publishConfig(): void
    {
        if (file_exists(config_path('afripay.php'))) {
            $this->components->info('Config [config/afripay.php] already exists — skipping.');
        } else {
            $this->callSilently('vendor:publish', ['--tag' => 'afripay-config']);
            $this->components->info('Config [config/afripay.php] published.');
        }
    }

    protected function publishController(string $controllerPath): void
    {
        $relativePath = $controllerPath.'/AfriPayController.php';
        $target = app_path($relativePath);

        if (file_exists($target)) {
            $this->components->info("Controller [app/{$relativePath}] already exists — skipping.");
            return;
        }

        $this->ensureDirectoryExists(dirname($target));

        // Read the stub and adjust the namespace
        $stub = file_get_contents(__DIR__.'/../../stubs/afripay-controller.stub');
        $namespace = 'App\\'.str_replace('/', '\\', $controllerPath);
        $stub = str_replace('namespace App\\Http\\Controllers;', "namespace {$namespace};", $stub);

        file_put_contents($target, $stub);
        $this->components->info("Controller [app/{$relativePath}] created.");
    }

    protected function publishViews(): void
    {
        $views = [
            'payment-success.stub' => 'payment/success.blade.php',
            'payment-pending.stub' => 'payment/pending.blade.php',
            'payment-error.stub'   => 'payment/error.blade.php',
        ];

        $viewPath = resource_path('views');

        foreach ($views as $stub => $destination) {
            $target = $viewPath.'/'.$destination;

            if (file_exists($target)) {
                $this->components->info("View [{$destination}] already exists — skipping.");
                continue;
            }

            $this->ensureDirectoryExists(dirname($target));
            copy(__DIR__.'/../../stubs/'.$stub, $target);
            $this->components->info("View [{$destination}] created.");
        }
    }

    protected function registerRoutes(string $controllerPath): void
    {
        $routesFile = base_path('routes/web.php');

        if (! file_exists($routesFile)) {
            $this->components->warn('routes/web.php not found — add routes manually.');
            return;
        }

        $contents = file_get_contents($routesFile);

        if (Str::contains($contents, 'AfriPayController')) {
            $this->components->info('Routes already registered in routes/web.php — skipping.');
            return;
        }

        $namespace = 'App\\'.str_replace('/', '\\', $controllerPath);

        $routes = <<<PHP


// AfriPay payment routes
use {$namespace}\\AfriPayController;

Route::get('/payment/success/{reference}', [AfriPayController::class, 'success'])->name('payment.success');
Route::get('/payment/error/{reference}', [AfriPayController::class, 'error'])->name('payment.error');
PHP;

        file_put_contents($routesFile, $contents.$routes.PHP_EOL);
        $this->components->info('Routes added to routes/web.php.');
    }

    protected function registerListeners(): void
    {
        $providerFile = app_path('Providers/AppServiceProvider.php');

        if (! file_exists($providerFile)) {
            $this->components->warn('AppServiceProvider.php not found — register listeners manually.');
            return;
        }

        $contents = file_get_contents($providerFile);

        if (Str::contains($contents, 'PaymentCompleted::class')) {
            $this->components->info('Event listeners already registered in AppServiceProvider — skipping.');
            return;
        }

        // Add use statements after the last existing use statement
        $useStatements = <<<'PHP'
use Illuminate\Support\Facades\Event;
use SunuCode\AfriPay\Events\PaymentCompleted;
use SunuCode\AfriPay\Events\PaymentFailed;
use SunuCode\AfriPay\Events\PaymentRefunded;
PHP;

        $listenerCode = <<<'PHP'

        // AfriPay event listeners
        // The payable_type set during charge() lets you route to the right logic.
        Event::listen(PaymentCompleted::class, function ($event) {
            $transaction = $event->transaction;
            $payable = $transaction->payable;

            // TODO: add your payable_type cases here
            match ($transaction->payable_type) {
                // \App\Models\Subscription::class => $payable->activate(),
                // \App\Models\Order::class        => $payable->markAsPaid(),
                default => null,
            };
        });

        Event::listen(PaymentFailed::class, function ($event) {
            $transaction = $event->transaction;
            // TODO: notify user, log failure...
        });

        Event::listen(PaymentRefunded::class, function ($event) {
            $transaction = $event->transaction;
            $reason = $event->reason;
            // TODO: cancel order, issue credit...
        });
PHP;

        // Insert use statements
        $contents = $this->insertUseStatements($contents, $useStatements);

        // Insert listener code into boot() method
        $contents = $this->insertIntoBootMethod($contents, $listenerCode);

        file_put_contents($providerFile, $contents);
        $this->components->info('Event listeners added to AppServiceProvider::boot().');
    }

    protected function insertUseStatements(string $contents, string $useStatements): string
    {
        // Find the last "use" import line before the class declaration
        if (preg_match('/^(use\s+.+;)\s*$/m', $contents, $matches, PREG_OFFSET_CAPTURE)) {
            preg_match_all('/^use\s+.+;\s*$/m', $contents, $allMatches, PREG_OFFSET_CAPTURE);
            $lastUse = end($allMatches[0]);
            $insertPos = $lastUse[1] + strlen($lastUse[0]);

            return substr($contents, 0, $insertPos).$useStatements."\n".substr($contents, $insertPos);
        }

        // Fallback: insert after namespace declaration
        if (preg_match('/^namespace\s+.+;\s*$/m', $contents, $matches, PREG_OFFSET_CAPTURE)) {
            $insertPos = $matches[0][1] + strlen($matches[0][0]);

            return substr($contents, 0, $insertPos)."\n".$useStatements."\n".substr($contents, $insertPos);
        }

        return $contents;
    }

    protected function insertIntoBootMethod(string $contents, string $code): string
    {
        // Find boot() method and insert code at the beginning of its body
        if (preg_match('/function\s+boot\s*\([^)]*\)(?:\s*:\s*void)?\s*\{/s', $contents, $matches, PREG_OFFSET_CAPTURE)) {
            $insertPos = $matches[0][1] + strlen($matches[0][0]);

            return substr($contents, 0, $insertPos).$code.substr($contents, $insertPos);
        }

        return $contents;
    }

    protected function ensureDirectoryExists(string $directory): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }
}
