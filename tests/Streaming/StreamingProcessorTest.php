<?php

declare(strict_types=1);

namespace Tests\Streaming;

use Ivuorinen\MonologGdprFilter\MaskConstants;
use Ivuorinen\MonologGdprFilter\MaskingOrchestrator;
use Ivuorinen\MonologGdprFilter\Streaming\StreamingProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\TestConstants;

#[CoversClass(StreamingProcessor::class)]
final class StreamingProcessorTest extends TestCase
{
    private function createOrchestrator(): MaskingOrchestrator
    {
        return new MaskingOrchestrator([TestConstants::PATTERN_TEST => MaskConstants::MASK_GENERIC]);
    }

    public function testProcessStreamSingleRecord(): void
    {
        $processor = new StreamingProcessor($this->createOrchestrator(), 10);

        $records = [
            ['message' => 'test message', 'context' => []],
        ];

        $results = iterator_to_array($processor->processStream($records));

        $this->assertCount(1, $results);
        $this->assertSame(MaskConstants::MASK_GENERIC . ' message', $results[0]['message']);
    }

    public function testProcessStreamMultipleRecords(): void
    {
        $processor = new StreamingProcessor($this->createOrchestrator(), 10);

        $records = [
            ['message' => 'test one', 'context' => []],
            ['message' => 'test two', 'context' => []],
            ['message' => 'test three', 'context' => []],
        ];

        $results = iterator_to_array($processor->processStream($records));

        $this->assertCount(3, $results);
    }

    public function testProcessStreamChunking(): void
    {
        $processor = new StreamingProcessor($this->createOrchestrator(), 2);

        $records = [
            ['message' => 'test 1', 'context' => []],
            ['message' => 'test 2', 'context' => []],
            ['message' => 'test 3', 'context' => []],
            ['message' => 'test 4', 'context' => []],
            ['message' => 'test 5', 'context' => []],
        ];

        $results = iterator_to_array($processor->processStream($records));

        $this->assertCount(5, $results);
    }

    public function testProcessStreamWithContext(): void
    {
        $processor = new StreamingProcessor($this->createOrchestrator(), 10);

        $records = [
            ['message' => 'message', 'context' => ['key' => 'test value']],
        ];

        $results = iterator_to_array($processor->processStream($records));

        $this->assertSame(MaskConstants::MASK_GENERIC . ' value', $results[0]['context']['key']);
    }

    public function testProcessStreamWithGenerator(): void
    {
        $processor = new StreamingProcessor($this->createOrchestrator(), 2);

        $generator = (function () {
            yield ['message' => 'test a', 'context' => []];
            yield ['message' => 'test b', 'context' => []];
            yield ['message' => 'test c', 'context' => []];
        })();

        $results = iterator_to_array($processor->processStream($generator));

        $this->assertCount(3, $results);
    }

    public function testProcessFileWithTempFile(): void
    {
        $processor = new StreamingProcessor($this->createOrchestrator(), 2);

        // Create temp file with test data containing 'test' to be masked
        $tempFile = tempnam(sys_get_temp_dir(), 'gdpr_test_');
        file_put_contents($tempFile, "test line 1\ntest line 2\ntest line 3\n");

        try {
            $lineParser = fn(string $line): array => ['message' => $line, 'context' => []];

            $results = [];
            foreach ($processor->processFile($tempFile, $lineParser) as $result) {
                $results[] = $result;
            }

            $this->assertCount(3, $results);
            $this->assertStringContainsString(MaskConstants::MASK_GENERIC, $results[0]['message']);
        } finally {
            unlink($tempFile);
        }
    }

    public function testProcessFileSkipsEmptyLines(): void
    {
        $processor = new StreamingProcessor($this->createOrchestrator(), 10);

        $tempFile = tempnam(sys_get_temp_dir(), 'gdpr_test_');
        file_put_contents($tempFile, "test line 1\n\n\ntest line 2\n");

        try {
            $lineParser = fn(string $line): array => ['message' => $line, 'context' => []];

            $results = iterator_to_array($processor->processFile($tempFile, $lineParser));

            $this->assertCount(2, $results);
        } finally {
            unlink($tempFile);
        }
    }

    public function testProcessFileThrowsOnInvalidPath(): void
    {
        $processor = new StreamingProcessor($this->createOrchestrator(), 10);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot open file:');

        iterator_to_array($processor->processFile('/nonexistent/path/file.log', fn(string $l): array => ['message' => $l, 'context' => []]));
    }

    public function testProcessToFile(): void
    {
        $processor = new StreamingProcessor($this->createOrchestrator(), 10);

        $records = [
            ['message' => 'test line 1', 'context' => []],
            ['message' => 'test line 2', 'context' => []],
        ];

        $outputFile = tempnam(sys_get_temp_dir(), 'gdpr_output_');

        try {
            $formatter = fn(array $record): string => $record['message'];
            $count = $processor->processToFile($records, $outputFile, $formatter);

            $this->assertSame(2, $count);

            $output = file_get_contents($outputFile);
            $this->assertNotFalse($output);
            $this->assertStringContainsString(MaskConstants::MASK_GENERIC, $output);
        } finally {
            unlink($outputFile);
        }
    }

    public function testProcessToFileThrowsOnInvalidPath(): void
    {
        $processor = new StreamingProcessor($this->createOrchestrator(), 10);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot open output file:');

        $processor->processToFile([], '/nonexistent/path/output.log', fn(array $r): string => '');
    }

    public function testGetStatistics(): void
    {
        $processor = new StreamingProcessor($this->createOrchestrator(), 10);

        $records = [
            ['message' => 'test masked', 'context' => []],
            ['message' => 'no sensitive data', 'context' => []],
            ['message' => 'another test here', 'context' => []],
        ];

        $stats = $processor->getStatistics($records);

        $this->assertSame(3, $stats['processed']);
        $this->assertGreaterThan(0, $stats['masked']); // At least some should be masked
    }

    public function testSetAuditLogger(): void
    {
        $logs = [];
        $auditLogger = function (string $path, mixed $original, mixed $masked) use (&$logs): void {
            $logs[] = ['path' => $path];
        };

        $processor = new StreamingProcessor($this->createOrchestrator(), 1);
        $processor->setAuditLogger($auditLogger);

        $records = [['message' => 'test', 'context' => []]];
        iterator_to_array($processor->processStream($records));

        $this->assertNotEmpty($logs);
    }

    public function testGetChunkSize(): void
    {
        $processor = new StreamingProcessor($this->createOrchestrator(), 500);

        $this->assertSame(500, $processor->getChunkSize());
    }

    public function testDefaultChunkSize(): void
    {
        $processor = new StreamingProcessor($this->createOrchestrator());

        $this->assertSame(1000, $processor->getChunkSize());
    }

    public function testLargeDataSet(): void
    {
        $processor = new StreamingProcessor($this->createOrchestrator(), 100);

        $records = [];
        for ($i = 1; $i <= 500; $i++) {
            $records[] = ['message' => "test record {$i}", 'context' => []];
        }

        $count = 0;
        foreach ($processor->processStream($records) as $_) {
            $count++;
        }

        $this->assertSame(500, $count);
    }
}
