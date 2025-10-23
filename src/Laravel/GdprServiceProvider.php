<?php

namespace Ivuorinen\MonologGdprFilter\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Carbon;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\Laravel\Commands\GdprTestPatternCommand;
use Ivuorinen\MonologGdprFilter\Laravel\Commands\GdprDebugCommand;
use Ivuorinen\MonologGdprFilter\Exceptions\ServiceRegistrationException;

/**
 * Laravel Service Provider for Monolog GDPR Filter.
 *
 * This service provider automatically registers the GDPR processor with Laravel's logging system
 * and provides configuration management and artisan commands.
 *
 * @api
 */
class GdprServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/gdpr.php', 'gdpr');

        $this->app->singleton('gdpr.processor', function (Application $app): GdprProcessor {
            $config = $app->make('config')->get('gdpr', []);

            $patterns = $config['patterns'] ?? GdprProcessor::getDefaultPatterns();
            $fieldPaths = $config['field_paths'] ?? [];
            $customCallbacks = $config['custom_callbacks'] ?? [];
            $maxDepth = $config['max_depth'] ?? 100;

            $auditLogger = null;
            if ($config['audit_logging']['enabled'] ?? false) {
                $auditLogger = function (string $path, mixed $original, mixed $masked): void {
                    Log::channel('gdpr-audit')->info('GDPR Processing', [
                        'path' => $path,
                        'original_type' => gettype($original),
                        'was_masked' => $original !== $masked,
                        'timestamp' => Carbon::now()->toISOString(),
                    ]);
                };
            }

            return new GdprProcessor(
                $patterns,
                $fieldPaths,
                $customCallbacks,
                $auditLogger,
                $maxDepth
            );
        });

        $this->app->alias('gdpr.processor', GdprProcessor::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish configuration file
        $this->publishes([
            __DIR__ . '/../../config/gdpr.php' => $this->app->configPath('gdpr.php'),
        ], 'gdpr-config');

        // Register artisan commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                GdprTestPatternCommand::class,
                GdprDebugCommand::class,
            ]);
        }

        // Auto-register with Laravel's logging system if enabled
        if (\config('gdpr.auto_register', true)) {
            $this->registerWithLogging();
        }
    }

    /**
     * Automatically register GDPR processor with Laravel's logging channels.
     */
    protected function registerWithLogging(): void
    {
        $logger = $this->app->make('log');
        $processor = $this->app->make('gdpr.processor');

        // Get channels to apply GDPR processing to
        $channels = \config('gdpr.channels', ['single', 'daily', 'stack']);

        foreach ($channels as $channelName) {
            try {
                $channelLogger = $logger->channel($channelName);
                if (method_exists($channelLogger, 'pushProcessor')) {
                    $channelLogger->pushProcessor($processor);
                }
            } catch (\Throwable $e) {
                // Log proper service registration failure but continue with other channels
                $exception = ServiceRegistrationException::forChannel(
                    $channelName,
                    $e->getMessage(),
                    $e
                );
                Log::debug('GDPR service registration warning: ' . $exception->getMessage());
            }
        }
    }
}
