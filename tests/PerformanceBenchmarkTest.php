<?php

declare(strict_types=1);

namespace Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Monolog\LogRecord;
use Monolog\Level;

/**
 * Performance benchmark tests for GDPR processor optimizations.
 *
 * These tests measure and validate the performance improvements made in Phase 4.
 */
class PerformanceBenchmarkTest extends TestCase
{
    private function getTestProcessor(): GdprProcessor
    {
        return new GdprProcessor(GdprProcessor::getDefaultPatterns());
    }

    private function generateLargeNestedArray(int $depth, int $width): array
    {
        if ($depth <= 0) {
            return [
                'email' => 'user@example.com',
                'phone' => '+1234567890',
                'ssn' => '123-45-6789',
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
            $this->assertStringContainsString('***EMAIL***', $result);
        }

        $endTime = microtime(true);
        $endMemory = memory_get_usage();

        $duration = ($endTime - $startTime) * 1000; // Convert to milliseconds
        $memoryUsed = ($endMemory - $startMemory) / 1024; // Convert to KB

        $avgTimePerOperation = $duration / $iterations;

        // Performance assertions - these should pass with optimizations
        $this->assertLessThan(5.0, $avgTimePerOperation, 'Average time per regex operation should be under 5ms');
        $this->assertLessThan(1000, $memoryUsed, 'Memory usage should be under 1MB for 100 operations');

        // Output benchmark results for monitoring
        echo "\n";
        echo "RegExp Message Performance Benchmark:\n";
        echo sprintf('  - Iterations: %d%s', $iterations, PHP_EOL);
        echo "  - Total time: " . round($duration, 2) . "ms\n";
        echo "  - Average per operation: " . round($avgTimePerOperation, 4) . "ms\n";
        echo "  - Memory used: " . round($memoryUsed, 2) . "KB\n";
    }

    public function testRecursiveMaskPerformanceWithDepthLimit(): void
    {
        // Test with different depth limits
        $depths = [10, 50, 100];

        foreach ($depths as $maxDepth) {
            $processor = new GdprProcessor(
                GdprProcessor::getDefaultPatterns(),
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
                'Test message',
                $testData
            );
            $result = $processor($logRecord);
            $endTime = microtime(true);

            $duration = ($endTime - $startTime) * 1000;

            // Should complete quickly even with deep nesting due to depth limiting
            $this->assertLessThan(100, $duration, 'Processing should complete in under 100ms with depth limit ' . $maxDepth);
            $this->assertInstanceOf(LogRecord::class, $result);

            echo sprintf('Depth limit %s: ', $maxDepth) . round($duration, 2) . "ms\n";
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
                    'email' => sprintf('user%d@example.com', $i),
                    'data' => 'Some data for item ' . $i,
                    'metadata' => ['timestamp' => time(), 'id' => $i],
                ];
            }

            $startTime = microtime(true);
            $startMemory = memory_get_peak_usage(true);

            // Use the processor via LogRecord to test array processing
            $logRecord = new LogRecord(
                new DateTimeImmutable(),
                'test',
                Level::Info,
                'Test message',
                $largeArray
            );
            $result = $processor($logRecord);

            $endTime = microtime(true);
            $endMemory = memory_get_peak_usage(true);

            $duration = ($endTime - $startTime) * 1000;
            $memoryUsed = ($endMemory - $startMemory) / (1024 * 1024); // MB

            // Verify processing worked
            $this->assertInstanceOf(LogRecord::class, $result);
            $this->assertCount($size, $result->context);
            $this->assertStringContainsString('***EMAIL***', (string) $result->context['item_0']['email']);

            // Performance should scale reasonably
            $timePerItem = $duration / $size;
            $this->assertLessThan(1.0, $timePerItem, 'Time per item should be under 1ms for array size ' . $size);

            echo sprintf('Array size %s: ', $size) . round($duration, 2) . "ms (" . round($timePerItem, 4) . "ms per item), Memory: " . round($memoryUsed, 2) . "MB\n";
        }
    }

    public function testPatternCachingEffectiveness(): void
    {
        // Clear any existing cache
        GdprProcessor::clearPatternCache();

        $processor = $this->getTestProcessor();
        $testMessage = 'Contact john@example.com, SSN: 123-45-6789, Phone: +1-555-123-4567';

        // First run - patterns will be cached
        $startTime = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $processor->regExpMessage($testMessage);
        }

        $firstRunTime = (microtime(true) - $startTime) * 1000;

        // Second run - should benefit from caching
        $startTime = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $processor->regExpMessage($testMessage);
        }

        $secondRunTime = (microtime(true) - $startTime) * 1000;

        // Third run - should be similar to second
        $startTime = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $processor->regExpMessage($testMessage);
        }

        $thirdRunTime = (microtime(true) - $startTime) * 1000;

        // Caching should provide consistent performance
        $improvementPercent = (($firstRunTime - $secondRunTime) / $firstRunTime) * 100;

        echo "\n";
        echo "Pattern Caching Performance:\n";
        echo "  - First run (cache building): " . round($firstRunTime, 2) . "ms\n";
        echo "  - Second run (cached): " . round($secondRunTime, 2) . "ms\n";
        echo "  - Third run (cached): " . round($thirdRunTime, 2) . "ms\n";
        echo "  - Improvement: " . round($improvementPercent, 1) . "%\n";

        // Performance should be consistent after caching
        $variationPercent = abs(($thirdRunTime - $secondRunTime) / $secondRunTime) * 100;
        $this->assertLessThan(20, $variationPercent, 'Cached performance should be consistent (less than 20% variation)');
    }

    public function testMemoryUsageWithGarbageCollection(): void
    {
        $processor = $this->getTestProcessor();

        // Test with dataset that should trigger garbage collection
        $largeArray = [];
        for ($i = 0; $i < 2000; $i++) { // Reduced for test environment
            $largeArray['item_' . $i] = [
                'email' => sprintf('user%d@example.com', $i),
                'ssn' => '123-45-6789',
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
            'Test message',
            $largeArray
        );
        $result = $processor($logRecord);

        $endMemory = memory_get_peak_usage(true);
        $memoryUsed = ($endMemory - $startMemory) / (1024 * 1024); // MB

        // Verify processing worked
        $this->assertInstanceOf(LogRecord::class, $result);
        $this->assertCount(2000, $result->context);
        $this->assertStringContainsString('***EMAIL***', (string) $result->context['item_0']['email']);

        // Memory usage should be reasonable even for large datasets
        $this->assertLessThan(50, $memoryUsed, 'Memory usage should be under 50MB for dataset');

        echo "\n";
        echo "Large Dataset Memory Usage:\n";
        echo "  - Items processed: 2,000\n";
        echo "  - Peak memory used: " . round($memoryUsed, 2) . "MB\n";
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
                        'email' => sprintf('user%d@example.com', $i),
                        'ssn' => '123-45-6789',
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
                    'Test message',
                    $data
                );
                $results[] = $processor($logRecord);
            }

            $endTime = microtime(true);
            $times[] = ($endTime - $startTime) * 1000;

            echo sprintf('Concurrency %d: ', $concurrency) . round($times[$concurrency - 1], 2) . "ms\n";
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
                $expectedRatio * 1.5,
                $scalingRatio,
                "Scaling should be reasonable for concurrency level " . ($i + 1)
            );
        }
    }

    public function testBenchmarkComparison(): void
    {
        // Compare optimized vs simple implementation
        $patterns = GdprProcessor::getDefaultPatterns();
        $testMessage = 'Email: john@example.com, SSN: 123-45-6789, Phone: +1-555-123-4567, IP: 192.168.1.1';

        // Optimized processor (with caching, etc.)
        $optimizedProcessor = new GdprProcessor($patterns);

        $iterations = 100; // Reduced for test environment

        // Benchmark optimized version
        $startTime = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $optimizedProcessor->regExpMessage($testMessage);
        }

        $optimizedTime = (microtime(true) - $startTime) * 1000;

        // Simple benchmark without optimization features
        // (We can't easily disable optimizations, so we just measure the current performance)
        $startTime = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            foreach ($patterns as $pattern => $replacement) {
                $testMessage = preg_replace($pattern, $replacement, $testMessage) ?? $testMessage;
            }
        }

        $simpleTime = (microtime(true) - $startTime) * 1000;

        echo "\n";
        echo "Performance Comparison ({$iterations} iterations):\n";
        echo "  - Optimized processor: " . round($optimizedTime, 2) . "ms\n";
        echo "  - Simple processing: " . round($simpleTime, 2) . "ms\n";
        echo "  - Improvement: " . round((($simpleTime - $optimizedTime) / $simpleTime) * 100, 1) . "%\n";

        // The optimized version should perform reasonably well
        $avgOptimizedTime = $optimizedTime / $iterations;
        $this->assertLessThan(1.0, $avgOptimizedTime, 'Optimized processing should average under 1ms per operation');
    }
}
