<?php

namespace Ivuorinen\MonologGdprFilter\Laravel\Commands;

use Monolog\LogRecord;
use DateTimeImmutable;
use Monolog\Level;
use JsonException;
use Illuminate\Console\Command;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\Exceptions\CommandExecutionException;

/**
 * Artisan command for debugging GDPR configuration and testing.
 *
 * This command provides information about the current GDPR configuration
 * and allows testing with sample log data.
 *
 * @api
 * @psalm-suppress PropertyNotSetInConstructor
 */
class GdprDebugCommand extends Command
{
    private const COMMAND_NAME = 'gdpr:debug';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gdpr:debug
                           {--test-data= : JSON string of sample data to test}
                           {--show-patterns : Show all configured patterns}
                           {--show-config : Show current configuration}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Debug GDPR configuration and test with sample data';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('GDPR Filter Debug Information');
        $this->line('=============================');

        // Show configuration if requested
        if ((bool)$this->option('show-config')) {
            $this->showConfiguration();
        }

        // Show patterns if requested
        if ((bool)$this->option('show-patterns')) {
            $this->showPatterns();
        }

        // Test with sample data if provided
        $testData = (string)$this->option('test-data');
        if ($testData !== '' && $testData !== '0') {
            $this->testWithSampleData($testData);
        }

        if (!$this->option('show-config') && !$this->option('show-patterns') && !$testData) {
            $this->showSummary();
        }

        return 0;
    }

    /**
     * Show current GDPR configuration.
     */
    protected function showConfiguration(): void
    {
        $this->line('');
        $this->info('Current Configuration:');
        $this->line('----------------------');

        $config = \config('gdpr', []);

        $this->line('Auto Register: ' . ($config['auto_register'] ?? true ? 'Yes' : 'No'));
        $this->line('Max Depth: ' . ($config['max_depth'] ?? 100));
        $this->line('Audit Logging: ' . (($config['audit_logging']['enabled'] ?? false) ? 'Enabled' : 'Disabled'));

        $channels = $config['channels'] ?? [];
        $this->line('Channels: ' . (empty($channels) ? 'None' : implode(', ', $channels)));

        $fieldPaths = $config['field_paths'] ?? [];
        $this->line('Field Paths: ' . count($fieldPaths) . ' configured');

        $customCallbacks = $config['custom_callbacks'] ?? [];
        $this->line('Custom Callbacks: ' . count($customCallbacks) . ' configured');
    }

    /**
     * Show all configured patterns.
     */
    protected function showPatterns(): void
    {
        $this->line('');
        $this->info('Configured Patterns:');
        $this->line('--------------------');

        $config = \config('gdpr', []);
        /**
         * @var array<string, mixed>|null $patterns
         */
        $patterns = $config['patterns'] ?? null;

        if (count($patterns) === 0 && empty($patterns)) {
            $this->line('No patterns configured - using defaults');
            $patterns = GdprProcessor::getDefaultPatterns();
        }

        foreach ($patterns as $pattern => $replacement) {
            $this->line(sprintf('%s => %s', $pattern, $replacement));
        }

        $this->line('');
        $this->line('Total patterns: ' . count($patterns));
    }

    /**
     * Test GDPR processing with sample data.
     */
    protected function testWithSampleData(string $testData): void
    {
        $this->line('');
        $this->info('Testing with sample data:');
        $this->line('-------------------------');

        try {
            $data = json_decode($testData, true, 512, JSON_THROW_ON_ERROR);

            $processor = \app('gdpr.processor');

            // Test with a sample log record
            $logRecord = new LogRecord(
                datetime: new DateTimeImmutable(),
                channel: 'test',
                level: Level::Info,
                message: $data['message'] ?? 'Test message',
                context: $data['context'] ?? []
            );

            $result = $processor($logRecord);

            $this->line('Original Message: ' . $logRecord->message);
            $this->line('Processed Message: ' . $result->message);

            if ($logRecord->context !== []) {
                $this->line('');
                $this->line('Original Context:');
                $this->line((string)json_encode($logRecord->context, JSON_PRETTY_PRINT));

                $this->line('Processed Context:');
                $this->line((string)json_encode($result->context, JSON_PRETTY_PRINT));
            }
        } catch (JsonException $e) {
            throw CommandExecutionException::forJsonProcessing(
                self::COMMAND_NAME,
                $testData,
                $e->getMessage(),
                $e
            );
        } catch (\Throwable $e) {
            throw CommandExecutionException::forOperation(
                self::COMMAND_NAME,
                'data processing',
                $e->getMessage(),
                $e
            );
        }
    }

    /**
     * Show summary information.
     */
    protected function showSummary(): void
    {
        $this->line('');
        $this->info('Quick Summary:');
        $this->line('--------------');

        try {
            \app('gdpr.processor');
            $this->line('<info>✓</info> GDPR processor is registered and ready');

            $config = \config('gdpr', []);
            $patterns = $config['patterns'] ?? GdprProcessor::getDefaultPatterns();
            $this->line('Patterns configured: ' . count($patterns));
        } catch (\Throwable $exception) {
            throw CommandExecutionException::forOperation(
                self::COMMAND_NAME,
                'configuration check',
                'GDPR processor is not properly configured: ' . $exception->getMessage(),
                $exception
            );
        }

        $this->line('');
        $this->info('Available options:');
        $this->line('  --show-config    Show current configuration');
        $this->line('  --show-patterns  Show all regex patterns');
        $this->line('  --test-data      Test with JSON sample data');

        $this->line('');
        $this->info('Example usage:');
        $this->line('  php artisan gdpr:debug --show-config');
        $this->line('  php artisan gdpr:debug --test-data=\'{"message":"Email: test@example.com"}\'');
    }
}
