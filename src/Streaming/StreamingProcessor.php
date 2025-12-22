<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Streaming;

use Ivuorinen\MonologGdprFilter\Exceptions\StreamingOperationFailedException;
use Ivuorinen\MonologGdprFilter\MaskingOrchestrator;

/**
 * Streaming processor for handling large log files.
 *
 * Processes logs in chunks to minimize memory usage when
 * dealing with large log files or data streams.
 *
 * @api
 */
final class StreamingProcessor
{
    private const DEFAULT_CHUNK_SIZE = 1000;

    /**
     * @var callable(string,mixed,mixed):void|null
     */
    private $auditLogger;

    /**
     * @param MaskingOrchestrator $orchestrator The masking orchestrator
     * @param int $chunkSize Number of records to process at once
     */
    public function __construct(
        private readonly MaskingOrchestrator $orchestrator,
        private readonly int $chunkSize = self::DEFAULT_CHUNK_SIZE,
        ?callable $auditLogger = null
    ) {
        $this->auditLogger = $auditLogger;
    }

    /**
     * Process a generator of records.
     *
     * @param iterable<array{message: string, context: array<string,mixed>}> $records
     * @return \Generator<array{message: string, context: array<string,mixed>}>
     */
    public function processStream(iterable $records): \Generator
    {
        $buffer = [];
        $count = 0;

        foreach ($records as $record) {
            $buffer[] = $record;
            $count++;

            if ($count >= $this->chunkSize) {
                foreach ($this->processChunk($buffer) as $item) {
                    yield $item;
                }
                $buffer = [];
                $count = 0;
            }
        }

        // Process remaining records
        if ($buffer !== []) {
            foreach ($this->processChunk($buffer) as $item) {
                yield $item;
            }
        }
    }

    /**
     * Process a file line by line.
     *
     * @param string $filePath Path to the log file
     * @param callable(string):array{message: string, context: array<string,mixed>} $lineParser
     * @return \Generator<array{message: string, context: array<string,mixed>}>
     */
    public function processFile(string $filePath, callable $lineParser): \Generator
    {
        $handle = @fopen($filePath, 'r');
        if ($handle === false) {
            throw StreamingOperationFailedException::cannotOpenInputFile($filePath);
        }

        try {
            $buffer = [];
            $count = 0;

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $record = $lineParser($line);
                $buffer[] = $record;
                $count++;

                if ($count >= $this->chunkSize) {
                    foreach ($this->processChunk($buffer) as $item) {
                        yield $item;
                    }
                    $buffer = [];
                    $count = 0;
                }
            }

            // Process remaining records
            if ($buffer !== []) {
                foreach ($this->processChunk($buffer) as $item) {
                    yield $item;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Process and write to an output file.
     *
     * @param iterable<array{message: string, context: array<string,mixed>}> $records
     * @param string $outputPath Path to output file
     * @param callable(array{message: string, context: array<string,mixed>}):string $formatter
     * @return int Number of records processed
     */
    public function processToFile(
        iterable $records,
        string $outputPath,
        callable $formatter
    ): int {
        $handle = @fopen($outputPath, 'w');
        if ($handle === false) {
            throw StreamingOperationFailedException::cannotOpenOutputFile($outputPath);
        }

        try {
            $count = 0;
            foreach ($this->processStream($records) as $record) {
                fwrite($handle, $formatter($record) . "\n");
                $count++;
            }

            return $count;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Process a chunk of records.
     *
     * @param list<array{message: string, context: array<string,mixed>}> $chunk
     * @return \Generator<array{message: string, context: array<string,mixed>}>
     */
    private function processChunk(array $chunk): \Generator
    {
        foreach ($chunk as $record) {
            $processed = $this->orchestrator->process($record['message'], $record['context']);

            if ($this->auditLogger !== null) {
                ($this->auditLogger)('streaming.processed', count($chunk), 1);
            }

            yield $processed;
        }
    }

    /**
     * Get statistics about a streaming operation.
     *
     * @param iterable<array{message: string, context: array<string,mixed>}> $records
     * @return array{processed: int, masked: int, errors: int}
     */
    public function getStatistics(iterable $records): array
    {
        $stats = ['processed' => 0, 'masked' => 0, 'errors' => 0];

        foreach ($this->processStream($records) as $record) {
            $stats['processed']++;
            // Count if any masking occurred (simple heuristic)
            if (str_contains($record['message'], '***') || str_contains($record['message'], '[')) {
                $stats['masked']++;
            }
        }

        return $stats;
    }

    /**
     * Set the audit logger.
     *
     * @param callable(string,mixed,mixed):void|null $auditLogger
     */
    public function setAuditLogger(?callable $auditLogger): void
    {
        $this->auditLogger = $auditLogger;
    }

    /**
     * Get the chunk size.
     */
    public function getChunkSize(): int
    {
        return $this->chunkSize;
    }
}
