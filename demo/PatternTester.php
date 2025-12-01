<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Demo;

use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\Strategies\RegexMaskingStrategy;
use Ivuorinen\MonologGdprFilter\Strategies\FieldPathMaskingStrategy;
use Ivuorinen\MonologGdprFilter\Strategies\StrategyManager;
use Ivuorinen\MonologGdprFilter\FieldMaskConfig;
use Ivuorinen\MonologGdprFilter\DefaultPatterns;
use Monolog\Level;
use Monolog\LogRecord;
use DateTimeImmutable;
use Throwable;

/**
 * Pattern testing utility for the demo playground.
 */
final class PatternTester
{
    /** @var array<array{path: string, original: mixed, masked: mixed}> */
    private array $auditLog = [];

    /**
     * Test regex patterns against sample text.
     *
     * @param string $text Sample text to test
     * @param array<string, string> $patterns Regex patterns to apply
     * @return array{masked: string, matches: array<string, array<string>>, errors: array<string>}
     */
    public function testPatterns(string $text, array $patterns): array
    {
        $errors = [];
        $matches = [];
        $masked = $text;

        foreach ($patterns as $pattern => $replacement) {
            // Validate pattern
            if (@preg_match($pattern, '') === false) {
                $errors[] = "Invalid pattern: {$pattern}";
                continue;
            }

            // Find matches
            if (preg_match_all($pattern, $text, $found)) {
                $matches[$pattern] = $found[0];
            }

            // Apply replacement
            $result = @preg_replace($pattern, $replacement, $masked);
            if ($result !== null) {
                $masked = $result;
            }
        }

        return [
            'masked' => $masked,
            'matches' => $matches,
            'errors' => $errors,
        ];
    }

    /**
     * Test with the full GdprProcessor.
     *
     * @param string $message Log message to test
     * @param array<string, mixed> $context Log context to test
     * @param array<string, string> $patterns Custom patterns (or empty for defaults)
     * @param array<string, string|FieldMaskConfig> $fieldPaths Field path configurations
     * @return array{
     *     original_message: string,
     *     masked_message: string,
     *     original_context: array<string, mixed>,
     *     masked_context: array<string, mixed>,
     *     audit_log: array<array{path: string, original: mixed, masked: mixed}>,
     *     errors: array<string>
     * }
     */
    public function testProcessor(
        string $message,
        array $context = [],
        array $patterns = [],
        array $fieldPaths = []
    ): array {
        $this->auditLog = [];
        $errors = [];

        try {
            // Use default patterns if none provided
            if (empty($patterns)) {
                $patterns = DefaultPatterns::getPatterns();
            }

            // Create audit logger
            $auditLogger = function (string $path, mixed $original, mixed $masked): void {
                $this->auditLog[] = [
                    'path' => $path,
                    'original' => $original,
                    'masked' => $masked,
                ];
            };

            // Convert field paths to FieldMaskConfig
            $configuredPaths = [];
            foreach ($fieldPaths as $path => $config) {
                if ($config instanceof FieldMaskConfig) {
                    $configuredPaths[$path] = $config;
                } elseif (is_string($config)) {
                    $configuredPaths[$path] = $config;
                }
            }

            // Create processor
            $processor = new GdprProcessor(
                patterns: $patterns,
                fieldPaths: $configuredPaths,
                auditLogger: $auditLogger
            );

            // Create log record
            $record = new LogRecord(
                datetime: new DateTimeImmutable(),
                channel: 'demo',
                level: Level::Info,
                message: $message,
                context: $context
            );

            // Process
            $result = $processor($record);

            return [
                'original_message' => $message,
                'masked_message' => $result->message,
                'original_context' => $context,
                'masked_context' => $result->context,
                'audit_log' => $this->auditLog,
                'errors' => $errors,
            ];
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();

            return [
                'original_message' => $message,
                'masked_message' => $message,
                'original_context' => $context,
                'masked_context' => $context,
                'audit_log' => [],
                'errors' => $errors,
            ];
        }
    }

    /**
     * Test with the Strategy pattern.
     *
     * @param string $message Log message
     * @param array<string, mixed> $context Log context
     * @param array<string, string> $patterns Regex patterns
     * @param array<string> $includePaths Paths to include
     * @param array<string> $excludePaths Paths to exclude
     * @return array<string, mixed>
     */
    public function testStrategies(
        string $message,
        array $context = [],
        array $patterns = [],
        array $includePaths = [],
        array $excludePaths = []
    ): array {
        $errors = [];

        try {
            if (empty($patterns)) {
                $patterns = DefaultPatterns::getPatterns();
            }

            // Create strategies
            $regexStrategy = new RegexMaskingStrategy(
                patterns: $patterns,
                includePaths: $includePaths,
                excludePaths: $excludePaths
            );

            // Create strategy manager
            $manager = new StrategyManager([$regexStrategy]);

            // Create log record
            $record = new LogRecord(
                datetime: new DateTimeImmutable(),
                channel: 'demo',
                level: Level::Info,
                message: $message,
                context: $context
            );

            // Mask message
            $maskedMessage = $manager->maskValue($message, 'message', $record);

            // Mask context recursively
            $maskedContext = $this->maskContextWithStrategies($context, $manager, $record);

            return [
                'original_message' => $message,
                'masked_message' => $maskedMessage,
                'original_context' => $context,
                'masked_context' => $maskedContext,
                'strategy_stats' => $manager->getStatistics(),
                'errors' => $errors,
            ];
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();

            return [
                'original_message' => $message,
                'masked_message' => $message,
                'original_context' => $context,
                'masked_context' => $context,
                'strategy_stats' => [],
                'errors' => $errors,
            ];
        }
    }

    /**
     * Get default patterns for display.
     *
     * @return array<string, string>
     */
    public function getDefaultPatterns(): array
    {
        return DefaultPatterns::getPatterns();
    }

    /**
     * Validate a single regex pattern.
     *
     * @return array{valid: bool, error: string|null}
     */
    public function validatePattern(string $pattern): array
    {
        if ($pattern === '') {
            return ['valid' => false, 'error' => 'Pattern cannot be empty'];
        }

        if (@preg_match($pattern, '') === false) {
            $error = preg_last_error_msg();
            return ['valid' => false, 'error' => $error];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Recursively mask context values using strategy manager.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function maskContextWithStrategies(
        array $context,
        StrategyManager $manager,
        LogRecord $record,
        string $prefix = ''
    ): array {
        $result = [];

        foreach ($context as $key => $value) {
            $path = $prefix === '' ? $key : $prefix . '.' . $key;

            if (is_array($value)) {
                $result[$key] = $this->maskContextWithStrategies($value, $manager, $record, $path);
            } elseif (is_string($value)) {
                $result[$key] = $manager->maskValue($value, $path, $record);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
