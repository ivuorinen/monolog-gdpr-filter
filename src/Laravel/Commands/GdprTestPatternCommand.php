<?php

namespace Ivuorinen\MonologGdprFilter\Laravel\Commands;

use Illuminate\Console\Command;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\Exceptions\PatternValidationException;
use Ivuorinen\MonologGdprFilter\Exceptions\CommandExecutionException;

/**
 * Artisan command for testing GDPR regex patterns.
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
        $args = $this->extractAndNormalizeArguments();
        $pattern = $args[0];
        $replacement = $args[1];
        $testString = $args[2];
        $validate = $args[3];
        
        $this->displayTestHeader($pattern, $replacement, $testString);

        if ($validate && !$this->validatePattern($pattern, $replacement)) {
            return 1;
        }

        return $this->executePatternTest($pattern, $replacement, $testString);
    }

    /**
     * Extract and normalize command arguments.
     *
     * @return array{string, string, string, bool}
     */
    private function extractAndNormalizeArguments(): array
    {
        $pattern = $this->argument('pattern');
        $replacement = $this->argument('replacement');
        $testString = $this->argument('test-string');
        $validate = $this->option('validate');

        $pattern = is_array($pattern) ? $pattern[0] : $pattern;
        $replacement = is_array($replacement) ? $replacement[0] : $replacement;
        $testString = is_array($testString) ? $testString[0] : $testString;
        $validate = is_bool($validate) ? $validate : (bool) $validate;

        return [
            (string) ($pattern ?? ''),
            (string) ($replacement ?? ''),
            (string) ($testString ?? ''),
            $validate,
        ];
    }

    /**
     * Display the test header with pattern information.
     */
    private function displayTestHeader(string $pattern, string $replacement, string $testString): void
    {
        $this->info('Testing GDPR Pattern');
        $this->line('====================');
        $this->line('Pattern: ' . $pattern);
        $this->line('Replacement: ' . $replacement);
        $this->line('Test String: ' . $testString);
        $this->line('');
    }

    /**
     * Validate the pattern if requested.
     */
    private function validatePattern(string $pattern, string $replacement): bool
    {
        $this->info('Validating pattern...');
        try {
            GdprProcessor::validatePatternsArray([$pattern => $replacement]);
            $this->line('<info>✓</info> Pattern is valid and secure');
        } catch (PatternValidationException $e) {
            $this->error('✗ Pattern validation failed: ' . $e->getMessage());
            return false;
        }

        $this->line('');
        return true;
    }

    /**
     * Execute the pattern test.
     */
    private function executePatternTest(string $pattern, string $replacement, string $testString): int
    {
        $this->info('Testing pattern match...');

        try {
            $this->validateInputs($pattern, $testString);
            
            $processor = new GdprProcessor([$pattern => $replacement]);
            $result = $processor->regExpMessage($testString);

            $this->displayTestResult($result, $testString);
            $this->showMatchDetails($pattern, $testString);

        } catch (CommandExecutionException $exception) {
            $this->error('✗ Pattern test failed: ' . $exception->getMessage());
            return 1;
        }

        return 0;
    }

    /**
     * Validate inputs are not empty.
     */
    private function validateInputs(string $pattern, string $testString): void
    {
        if ($pattern === '' || $pattern === '0') {
            throw CommandExecutionException::forInvalidInput(
                'gdpr:test-pattern',
                'pattern',
                $pattern,
                'Pattern cannot be empty'
            );
        }

        if ($testString === '' || $testString === '0') {
            throw CommandExecutionException::forInvalidInput(
                'gdpr:test-pattern',
                'test-string',
                $testString,
                'Test string cannot be empty'
            );
        }
    }

    /**
     * Display the test result.
     */
    private function displayTestResult(string $result, string $testString): void
    {
        if ($result === $testString) {
            $this->line('<comment>-</comment> No match found - string unchanged');
        } else {
            $this->line('<info>✓</info> Pattern matched!');
            $this->line('Result: ' . $result);
        }
    }

    /**
     * Show detailed matching information.
     */
    private function showMatchDetails(string $pattern, string $testString): void
    {
        $matches = [];
        if (preg_match($pattern, $testString, $matches)) {
            $this->line('');
            $this->info('Match details:');
            foreach ($matches as $index => $match) {
                $this->line(sprintf('  [%s]: %s', $index, $match));
            }
        }
    }
}
