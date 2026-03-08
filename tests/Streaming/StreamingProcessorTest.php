<?php

declare(strict_types=1);

namespace Tests\Streaming;

use Ivuorinen\MonologGdprFilter\Exceptions\StreamingOperationFailedException;
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
            [TestConstants::FIELD_MESSAGE => TestConstants::MESSAGE_TEST_LOWERCASE, 'context' => []],
        ];

        $results = iterator_to_array($processor->processStream($records));

        $this->assertCount(1, $results);
        $this->assertSame(MaskConstants::MASK_GENERIC . ' message', $results[0][TestConstants::FIELD_MESSAGE]);
    }

    public function testProcessStreamMultipleRecords(): void
    {
        $processor = new StreamingProcessor($this->createOrchestrator(), 10);

        $records = [
            [TestConstants::FIELD_MESSAGE => 'test one', 'context' => []],
            [TestConstants::FIELD_MESSAGE => 'test two', 'context' => []],
            [TestConstants::FIELD_MESSAGE => 'test three', 'context' => []],
        ];

        $results = iterator_to_array($processor->processStream($records));

        $this->assertCount(3, $results);
    }

    public function testProcessStreamChunking(): void
    {
        $processor = new StreamingProcessor($this->createOrchestrator(), 2);

        $records = [
            [TestConstants::FIELD_MESSAGE => 'test 1', 'context' => []],
            [TestConstants::FIELD_MESSAGE => 'test 2', 'context' => []],
            [TestConstants::FIELD_MESSAGE => 'test 3', 'context' => []],
            [TestConstants::FIELD_MESSAGE => 'test 4', 'context' => []],
            [TestConstants::FIELD_MESSAGE => 'test 5', 'context' => []],
        ];

        $results = iterator_to_array($processor->processStream($records));

        $this->assertCount(5, $results);
    }

    public function testProcessStreamWithContext(): void
    {
        $processor = new StreamingProcessor($this->createOrchestrator(), 10);

        $records = [
            [TestConstants::FIELD_MESSAGE => TestConstants::FIELD_MESSAGE, 'context' => ['key' => TestConstants::VALUE_TEST]],
        ];

        $results = iterator_to_array($processor->processStream($records));

        $this->assertSame(MaskConstants::MASK_GENERIC . TestConstants::VALUE_SUFFIX, $results[0]['context']['key']);
    }

    public function testProcessStreamWithGenerator(): void
    {
        $processor = new StreamingProcessor($this->createOrchestrator(), 2);

        $generator = (function () {
            yield [TestConstants::FIELD_MESSAGE => 'test a', 'context' => []];
            yield [TestConstants::FIELD_MESSAGE => 'test b', 'context' => []];
            yield [TestConstants::FIELD_MESSAGE => 'test c', 'context' => []];
        })();

        $results = iterator_to_array($processor->processStream($generator));

        $this->assertCount(3, $results);
    }

    public function testProcessFileWithTempFile(): void
    {
        $processor = new StreamingProcessor($this->createOrchestrator(), 2);

        // Create temp file with test data containing 'test' to be masked
        $tempFile = tempnam(sys_get_temp_dir(), 'gdpr_test_');
        $this->assertIsString($tempFile, 'Failed to create temp file');
        file_put_contents($tempFile, "test line 1\ntest line 2\ntest line 3\n");

        try {
            $lineParser = fn(string $line): array => [TestConstants::FIELD_MESSAGE => $line, 'context' => []];

            $results = [];
            foreach ($processor->processFile($tempFile, $lineParser) as $result) {
                $results[] = $result;
            }

            $this->assertCount(3, $results);
            $this->assertStringContainsString(MaskConstants::MASK_GENERIC, $results[0][TestConstants::FIELD_MESSAGE]);
        } finally {
            unlink($tempFile);
        }
    }

    public function testProcessFileSkipsEmptyLines(): void
    {
        $processor = new StreamingProcessor($this->createOrchestrator(), 10);

        $tempFile = tempnam(sys_get_temp_dir(), 'gdpr_test_');
        $this->assertIsString($tempFile, 'Failed to create temp file');
        file_put_contents($tempFile, "test line 1\n\n\ntest line 2\n");

        try {
            $lineParser = fn(string $line): array => [TestConstants::FIELD_MESSAGE => $line, 'context' => []];

            $results = iterator_to_array($processor->processFile($tempFile, $lineParser));

            $this->assertCount(2, $results);
        } finally {
            unlink($tempFile);
        }
    }

    public function testProcessFileThrowsOnInvalidPath(): void
    {
        $processor = new StreamingProcessor($this->createOrchestrator(), 10);

        $this->expectException(StreamingOperationFailedException::class);
        $this->expectExceptionMessage('Cannot open input file for streaming:');

        iterator_to_array($processor->processFile('/nonexistent/path/file.log', fn(string $l): array => [TestConstants::FIELD_MESSAGE => $l, 'context' => []]));
    }

    public function testProcessToFile(): void
    {
        $processor = new StreamingProcessor($this->createOrchestrator(), 10);

        $records = [
            [TestConstants::FIELD_MESSAGE => 'test line 1', 'context' => []],
            [TestConstants::FIELD_MESSAGE => 'test line 2', 'context' => []],
        ];

        $outputFile = tempnam(sys_get_temp_dir(), 'gdpr_output_');
        $this->assertIsString($outputFile, 'Failed to create temp file');

        try {
            $formatter = fn(array $record): string => $record[TestConstants::FIELD_MESSAGE];
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

        $this->expectException(StreamingOperationFailedException::class);
        $this->expectExceptionMessage('Cannot open output file for streaming:');

        $processor->processToFile([], '/nonexistent/path/output.log', fn(array $r): string => '');
    }

    public function testGetStatistics(): void
    {
        $processor = new StreamingProcessor($this->createOrchestrator(), 10);

        $records = [
            [TestConstants::FIELD_MESSAGE => 'test masked', 'context' => []],
            [TestConstants::FIELD_MESSAGE => 'no sensitive data', 'context' => []],
            [TestConstants::FIELD_MESSAGE => 'another test here', 'context' => []],
        ];

        $stats = $processor->getStatistics($records);

        $this->assertSame(3, $stats['processed']);
        $this->assertSame(2, $stats[TestConstants::DATA_MASKED]);
    }

    public function testSetAuditLogger(): void
    {
        $logs = [];
        $auditLogger = function (string $path) use (&$logs): void {
            $logs[] = ['path' => $path];
        };

        $processor = new StreamingProcessor($this->createOrchestrator(), 1);
        $processor->setAuditLogger($auditLogger);

        $records = [[TestConstants::FIELD_MESSAGE => 'test', 'context' => []]];
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
            $records[] = [TestConstants::FIELD_MESSAGE => "test record {$i}", 'context' => []];
        }

        $count = 0;
        foreach ($processor->processStream($records) as $record) {
            $count++;
            $this->assertIsArray($record);
        }

        $this->assertSame(500, $count);
    }
}
