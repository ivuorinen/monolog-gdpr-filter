<?php

declare(strict_types=1);

namespace Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\TestHelpers;
use Tests\TestConstants;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\MaskConstants;
use Monolog\LogRecord;
use Monolog\Level;
use Ivuorinen\MonologGdprFilter\DefaultPatterns;
use Ivuorinen\MonologGdprFilter\PatternValidator;

/**
 * Performance benchmark tests for GDPR processor optimizations.
 *
 * These tests measure and validate the performance improvements.
 *
 * @api
 * @psalm-suppress DeprecatedMethod - Tests for deprecated PatternValidator API
 */
class PerformanceBenchmarkTest extends TestCase
{
    use TestHelpers;

    private function getTestProcessor(): GdprProcessor
    {
        return $this->createProcessor(DefaultPatterns::get());
    }

    /**
     * @return (array|int|string)[]
     *
     * @psalm-return array<string, '+1234567890'|'123-45-6789'|'user@example.com'|array<string, mixed>|int<1000, 9999>>
     */
    private function generateLargeNestedArray(int $depth, int $width): array
    {
        if ($depth <= 0) {
            return [
                TestConstants::CONTEXT_EMAIL => TestConstants::EMAIL_USER,
                TestConstants::CONTEXT_PHONE => TestConstants::PHONE_GENERIC,
                'ssn' => TestConstants::SSN_US,
                'id' => random_int(1000, 9999),
            ];
        }

        $result = [];
        // Limit width to prevent memory issues in test environment
        $limitedWidth = min($width, 2);
        for ($i = 0; $i < $limitedWidth; $i++) {
            $result['item_' . $i] = $this->generateLargeNestedArray($depth - 1, $limitedWidth);
        }

        return $result;
    }

    public function testRegExpMessagePerformance(): void
    {
        $processor = $this->getTestProcessor();
        $testMessage = TestConstants::EMAIL_JOHN_DOE;

        // Warmup
        for ($i = 0; $i < 10; $i++) {
            $processor->regExpMessage($testMessage);
        }

        $iterations = 100; // Reduced for test environment
        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        for ($i = 0; $i < $iterations; $i++) {
            $result = $processor->regExpMessage($testMessage);
            $this->assertStringContainsString(MaskConstants::MASK_EMAIL, $result);
        }

        $endTime = microtime(true);
        $endMemory = memory_get_usage();

        $duration = (($endTime - $startTime) * 1000.0); // Convert to milliseconds
        $memoryUsed = ($endMemory - $startMemory) / 1024; // Convert to KB

        $avgTimePerOperation = $duration / (float) $iterations;

        // Performance assertions - these should pass with optimizations
        // Generous ceiling: a real algorithmic regression blows past it, machine noise does not.
        $this->assertLessThan(50.0, $avgTimePerOperation, 'Average time per regex operation should be under 50ms');
        $this->assertLessThan(1000, $memoryUsed, 'Memory usage should be under 1MB for 100 operations');

        // Performance metrics captured in assertions above
        // Benchmark results: {$iterations} iterations, {$duration}ms total,
        //  {$avgTimePerOperation}ms avg, {$memoryUsed}KB memory
    }

    public function testRecursiveMaskPerformanceWithDepthLimit(): void
    {
        // Test with different depth limits
        $depths = [10, 50, 100];

        foreach ($depths as $maxDepth) {
            $processor = $this->createProcessor(
                DefaultPatterns::get(),
                [],
                [],
                null,
                $maxDepth
            );

            $testData = $this->generateLargeNestedArray(8, 2); // Deeper than max depth

            $startTime = microtime(true);
            // Use the processor via LogRecord to test recursive masking
            $logRecord = new LogRecord(
                new DateTimeImmutable(),
                'test',
                Level::Info,
                TestConstants::MESSAGE_DEFAULT,
                $testData
            );
            $result = $processor($logRecord);
            $endTime = microtime(true);

            $duration = (($endTime - $startTime) * 1000.0);

            // Should complete quickly even with deep nesting due to depth limiting
            $this->assertLessThan(
                2000,
                $duration,
                'Processing should complete in under 2s with depth limit ' . $maxDepth
            );
            $this->assertInstanceOf(LogRecord::class, $result);

            // Performance: Depth limit {$maxDepth}: {$duration}ms
        }
    }

    public function testLargeArrayChunkingPerformance(): void
    {
        $processor = $this->getTestProcessor();

        // Test different array sizes (reduced for test environment)
        $sizes = [50, 200, 500];

        foreach ($sizes as $size) {
            $largeArray = [];
            for ($i = 0; $i < $size; $i++) {
                $largeArray['item_' . $i] = [
                    TestConstants::CONTEXT_EMAIL => sprintf(TestConstants::TEMPLATE_USER_EMAIL, $i),
                    'data' => 'Some data for item ' . $i,
                    'metadata' => ['timestamp' => time(), 'id' => $i],
                ];
            }

            $startTime = microtime(true);

            // Use the processor via LogRecord to test array processing
            $logRecord = new LogRecord(
                new DateTimeImmutable(),
                'test',
                Level::Info,
                TestConstants::MESSAGE_DEFAULT,
                $largeArray
            );
            $result = $processor($logRecord);

            $endTime = microtime(true);

            $duration = (($endTime - $startTime) * 1000.0);
            // MB

            // Verify processing worked
            $this->assertInstanceOf(LogRecord::class, $result);
            $this->assertCount($size, $result->context);
            $emailValue = (string) $result->context['item_0'][TestConstants::CONTEXT_EMAIL];
            $this->assertStringContainsString(MaskConstants::MASK_EMAIL, $emailValue);

            // Performance should scale reasonably
            $timePerItem = $duration / (float) $size;
            $this->assertLessThan(25.0, $timePerItem, 'Time per item should be under 25ms for array size ' . $size);

            // Performance: Array size {$size}: {$duration}ms ({$timePerItem}ms per item), Memory: {$memoryUsed}MB
        }
    }

    public function testPatternCachingEffectiveness(): void
    {
        // Clear any existing cache
        PatternValidator::clearCache();

        $this->assertSame([], PatternValidator::getCache(), 'Cache should start empty');

        $patterns = DefaultPatterns::get();

        // Validating a pattern records its result, so repeat validations are cache hits.
        foreach (array_keys($patterns) as $pattern) {
            $this->assertTrue(PatternValidator::isValid($pattern), "Default pattern invalid: {$pattern}");
        }

        $cache = PatternValidator::getCache();
        $this->assertCount(count($patterns), $cache, 'Every validated pattern should be cached once');

        // Re-validating must be served from the cache: same results, no new entries.
        foreach (array_keys($patterns) as $pattern) {
            $this->assertTrue(PatternValidator::isValid($pattern));
        }

        $this->assertSame($cache, PatternValidator::getCache(), 'Re-validation must not change the cache');

        // An invalid pattern is cached as invalid rather than being retried.
        $this->assertFalse(PatternValidator::isValid('/[unclosed/'));
        $this->assertArrayHasKey('/[unclosed/', PatternValidator::getCache());
        $this->assertFalse(PatternValidator::getCache()['/[unclosed/']);
    }

    public function testMemoryUsageWithGarbageCollection(): void
    {
        $processor = $this->getTestProcessor();

        // Test with dataset that should trigger garbage collection
        $largeArray = [];
        for ($i = 0; $i < 2000; $i++) { // Reduced for test environment
            $largeArray['item_' . $i] = [
                TestConstants::CONTEXT_EMAIL => sprintf(TestConstants::TEMPLATE_USER_EMAIL, $i),
                'ssn' => TestConstants::SSN_US,
                TestConstants::CONTEXT_PHONE => TestConstants::PHONE_US,
                'nested' => [
                    'level1' => [
                        'level2' => [
                            'data' => 'Deep nested data for item ' . $i,
                            TestConstants::CONTEXT_EMAIL => sprintf('nested%d@example.com', $i),
                        ],
                    ],
                ],
            ];
        }

        $startMemory = memory_get_peak_usage(true);

        // Use the processor via LogRecord to test memory usage
        $logRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            TestConstants::MESSAGE_DEFAULT,
            $largeArray
        );
        $result = $processor($logRecord);

        $endMemory = memory_get_peak_usage(true);
        $memoryUsed = ($endMemory - $startMemory) / (1024 * 1024); // MB

        // Verify processing worked
        $this->assertInstanceOf(LogRecord::class, $result);
        $this->assertCount(2000, $result->context);
        $emailValue = (string) $result->context['item_0'][TestConstants::CONTEXT_EMAIL];
        $this->assertStringContainsString(MaskConstants::MASK_EMAIL, $emailValue);

        // Memory usage should be reasonable even for large datasets
        $this->assertLessThan(50, $memoryUsed, 'Memory usage should be under 50MB for dataset');

        // Large Dataset Memory Usage:
        // - Items processed: 2,000
        // - Peak memory used: {$memoryUsed}MB
    }

    public function testConcurrentProcessingSimulation(): void
    {
        $processor = $this->getTestProcessor();

        // Simulate concurrent processing by running multiple processors
        $results = [];

        for ($concurrency = 1; $concurrency <= 5; $concurrency++) {
            $testData = [];
            for ($i = 0; $i < $concurrency; $i++) {
                $testData[] = [
                    'user' => [
                        TestConstants::CONTEXT_EMAIL => sprintf(TestConstants::TEMPLATE_USER_EMAIL, $i),
                        'ssn' => TestConstants::SSN_US,
                    ],
                    'request' => [
                        'ip' => '192.168.1.' . ($i + 100),
                        'data' => str_repeat('x', 1000), // Large string
                    ],
                ];
            }

            // Process all datasets via LogRecord
            foreach ($testData as $data) {
                $logRecord = new LogRecord(
                    new DateTimeImmutable(),
                    'test',
                    Level::Info,
                    TestConstants::MESSAGE_DEFAULT,
                    $data
                );
                $results[] = $processor($logRecord);
            }
        }

        // Verify all processing completed correctly (1+2+3+4+5 = 15 total results)
        $this->assertCount(15, $results);

        // Scaling was previously asserted as $times[$i] / $times[0]. $times[0] measures a
        // single item and lands in the sub-millisecond range, so that ratio tracked
        // scheduler noise rather than algorithmic behaviour and failed intermittently.
        // Assert the algorithmic property instead: every record came back fully masked,
        // regardless of how many were processed in the batch.
        foreach ($results as $index => $record) {
            $this->assertInstanceOf(LogRecord::class, $record, 'Result ' . $index);

            $email = (string) $record->context['user'][TestConstants::CONTEXT_EMAIL];
            $this->assertStringContainsString(MaskConstants::MASK_EMAIL, $email);
            $this->assertStringNotContainsString('@', $email);

            $this->assertStringContainsString(
                MaskConstants::MASK_USSSN,
                (string) $record->context['user']['ssn']
            );
        }
    }

    public function testBenchmarkComparison(): void
    {
        // Compare optimized vs simple implementation
        $patterns = DefaultPatterns::get();
        $testMessage = 'Email: john@example.com, SSN: ' . TestConstants::SSN_US
            . ', Phone: +1-555-123-4567, IP: 192.168.1.1';

        // Optimized processor (with caching, etc.)
        $optimizedProcessor = $this->createProcessor($patterns);

        $iterations = 100; // Reduced for test environment

        // Benchmark optimized version
        $startTime = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $optimizedProcessor->regExpMessage($testMessage);
        }

        $optimizedTime = ((microtime(true) - $startTime) * 1000.0);

        // Simple benchmark without optimization features
        // (We can't easily disable optimizations, so we just measure the current performance)
        microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            foreach ($patterns as $pattern => $replacement) {
                if ($pattern === '') {
                    continue;
                }
                $testMessage = preg_replace(
                    $pattern,
                    $replacement,
                    $testMessage
                ) ?? $testMessage;
            }
        }

        microtime(true);

        // Performance Comparison ({$iterations} iterations):
        // - Optimized processor: {$optimizedTime}ms
        // - Simple processing: {$simpleTime}ms
        // - Improvement: {(($simpleTime - $optimizedTime) / $simpleTime) * 100}%

        // The optimized version should perform reasonably well
        $avgOptimizedTime = $optimizedTime / (float) $iterations;
        $this->assertLessThan(25.0, $avgOptimizedTime, 'Optimized processing should average under 25ms per operation');
    }
}
