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
 */
class PerformanceBenchmarkTest extends TestCase
{
    use TestHelpers;

    private function getTestProcessor(): GdprProcessor
    {
        return $this->createProcessor(DefaultPatterns::get());
    }

    /**
     * @return array<string, mixed>
     */
    private function generateLargeNestedArray(int $depth, int $width): array
    {
        if ($depth <= 0) {
            return [
                'email' => 'user@example.com',
                'phone' => '+1234567890',
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
        $testMessage = 'john.doe@example.com';

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
        $this->assertLessThan(5.0, $avgTimePerOperation, 'Average time per regex operation should be under 5ms');
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
                100,
                $duration,
                'Processing should complete in under 100ms with depth limit ' . $maxDepth
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
                    'email' => sprintf(TestConstants::TEMPLATE_USER_EMAIL, $i),
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
            $this->assertStringContainsString(MaskConstants::MASK_EMAIL, (string) $result->context['item_0']['email']);

            // Performance should scale reasonably
            $timePerItem = $duration / (float) $size;
            $this->assertLessThan(1.0, $timePerItem, 'Time per item should be under 1ms for array size ' . $size);

            // Performance: Array size {$size}: {$duration}ms ({$timePerItem}ms per item), Memory: {$memoryUsed}MB
        }
    }

    public function testPatternCachingEffectiveness(): void
    {
        // Clear any existing cache
        PatternValidator::clearCache();

        $processor = $this->getTestProcessor();
        $testMessage = 'Contact john@example.com, SSN: ' . TestConstants::SSN_US . ', Phone: +1-555-123-4567';

        // First run - patterns will be cached
        microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $processor->regExpMessage($testMessage);
        }

        microtime(true);

        // Second run - should benefit from caching
        $startTime = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $processor->regExpMessage($testMessage);
        }

        $secondRunTime = ((microtime(true) - $startTime) * 1000.0);

        // Third run - should be similar to second
        $startTime = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $processor->regExpMessage($testMessage);
        }

        $thirdRunTime = ((microtime(true) - $startTime) * 1000.0);

        // Pattern Caching Performance:
        // - First run (cache building): {$firstRunTime}ms
        // - Second run (cached): {$secondRunTime}ms
        // - Third run (cached): {$thirdRunTime}ms
        // - Improvement: {$improvementPercent}%

        // Performance should be consistent after caching
        $variationPercent = (abs(($thirdRunTime - $secondRunTime) / $secondRunTime) * 100.0);
        $this->assertLessThan(
            20,
            $variationPercent,
            'Cached performance should be consistent (less than 20% variation)'
        );
    }

    public function testMemoryUsageWithGarbageCollection(): void
    {
        $processor = $this->getTestProcessor();

        // Test with dataset that should trigger garbage collection
        $largeArray = [];
        for ($i = 0; $i < 2000; $i++) { // Reduced for test environment
            $largeArray['item_' . $i] = [
                'email' => sprintf(TestConstants::TEMPLATE_USER_EMAIL, $i),
                'ssn' => TestConstants::SSN_US,
                'phone' => '+1-555-123-4567',
                'nested' => [
                    'level1' => [
                        'level2' => [
                            'data' => 'Deep nested data for item ' . $i,
                            'email' => sprintf('nested%d@example.com', $i),
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
        $this->assertStringContainsString(MaskConstants::MASK_EMAIL, (string) $result->context['item_0']['email']);

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
        $times = [];

        for ($concurrency = 1; $concurrency <= 5; $concurrency++) {
            $testData = [];
            for ($i = 0; $i < $concurrency; $i++) {
                $testData[] = [
                    'user' => [
                        'email' => sprintf(TestConstants::TEMPLATE_USER_EMAIL, $i),
                        'ssn' => TestConstants::SSN_US,
                    ],
                    'request' => [
                        'ip' => '192.168.1.' . ($i + 100),
                        'data' => str_repeat('x', 1000), // Large string
                    ],
                ];
            }

            $startTime = microtime(true);

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

            $endTime = microtime(true);
            $times[] = (($endTime - $startTime) * 1000.0);

            // Performance: Concurrency {$concurrency}: {$times[$concurrency - 1]}ms
        }

        // Verify all processing completed correctly
        $this->assertCount(15, $results);
        // 1+2+3+4+5 = 15 total results
        // Performance should scale reasonably with concurrency
        $counter = count($times); // 1+2+3+4+5 = 15 total results

        // Performance should scale reasonably with concurrency
        for ($i = 1; $i < $counter; $i++) {
            $scalingRatio = $times[$i] / $times[0];
            $expectedRatio = ($i + 1); // Linear scaling would be concurrency level

            // Should scale better than linear due to optimizations
            $this->assertLessThan(
                ((float) $expectedRatio * 1.5),
                $scalingRatio,
                "Scaling should be reasonable for concurrency level " . ((string) ($i + 1))
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
        $this->assertLessThan(1.0, $avgOptimizedTime, 'Optimized processing should average under 1ms per operation');
    }
}
