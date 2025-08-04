<?php

namespace Ivuorinen\MonologGdprFilter\Laravel\Commands;

use InvalidArgumentException;
use Exception;
use Illuminate\Console\Command;
use Ivuorinen\MonologGdprFilter\GdprProcessor;

/**
 * Artisan cfinal ommand for testing GDPR regex patterns.
 *
 * This command allows developers to test regex patterns against sample data
 * to ensure they work correctly before deploying to production.
 *
 * @api
 */
class GdprTestPatternCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gdpr:test-pattern
                           {pattern : The regex pattern to test}
                           {replacement : The replacement text}
                           {test-string : The string to test against}
                           {--validate : Validate the pattern for security}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test GDPR regex patterns against sample data';

    /**
     * Execute the console command.
     *
     * @psalm-return 0|1
     */
    public function handle(): int
    {
        $pattern = $this->argument('pattern');
        $replacement = $this->argument('replacement');
        $testString = $this->argument('test-string');
        $validate = $this->option('validate');

        $this->info('Testing GDPR Pattern');
        $this->line('====================');
        $this->line('Pattern: ' . $pattern);
        $this->line('Replacement: ' . $replacement);
        $this->line('Test String: ' . $testString);
        $this->line('');

        // Validate pattern if requested
        if ($validate) {
            $this->info('Validating pattern...');
            try {
                GdprProcessor::validatePatterns([$pattern => $replacement]);
                $this->line('<info>✓</info> Pattern is valid and secure');
            } catch (InvalidArgumentException $e) {
                $this->error('✗ Pattern validation failed: ' . $e->getMessage());
                return 1;
            }

            $this->line('');
        }

        // Test the pattern
        $this->info('Testing pattern match...');

        try {
            $processor = new GdprProcessor([$pattern => $replacement]);
            $result = $processor->regExpMessage($testString);

            if ($result === $testString) {
                $this->line('<comment>-</comment> No match found - string unchanged');
            } else {
                $this->line('<info>✓</info> Pattern matched!');
                $this->line('Result: ' . $result);
            }

            // Show detailed matching info
            $matches = [];
            if (preg_match($pattern, $testString, $matches)) {
                $this->line('');
                $this->info('Match details:');
                foreach ($matches as $index => $match) {
                    $this->line(sprintf('  [%s]: %s', $index, $match));
                }
            }
        } catch (Exception $exception) {
            $this->error('✗ Pattern test failed: ' . $exception->getMessage());
            return 1;
        }

        return 0;
    }
}
