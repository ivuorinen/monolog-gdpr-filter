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
 * @psalm-suppress PropertyNotSetInConstructor
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
        /** @psalm-param string $pattern */
        $pattern = $this->argument('pattern');
        /** @psalm-param string $replacement */
        $replacement = $this->argument('replacement');
        /** @psalm-param string $testString */
        $testString = $this->argument('test-string');
        /** @psalm-param bool $validate */
        $validate = $this->option('validate');

        $pattern = is_array($pattern) ? $pattern[0] : $pattern;
        $replacement = is_array($replacement) ? $replacement[0] : $replacement;
        $testString = is_array($testString) ? $testString[0] : $testString;
        $validate = is_bool($validate) ? $validate : (bool)$validate;

        $pattern = (string)($pattern ?? '');
        $replacement = (string)($replacement ?? '');
        $testString = (string)($testString ?? '');

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
                GdprProcessor::validatePatternsArray([$pattern => $replacement]);
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

            if ($pattern === '' || $pattern === '0') {
                $this->error('✗ Pattern is empty');
                return 1;
            }

            if ($testString === '' || $testString === '0') {
                $this->error('✗ Test string is empty');
                return 1;
            }

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
