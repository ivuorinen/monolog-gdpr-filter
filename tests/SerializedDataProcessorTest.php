<?php

declare(strict_types=1);

namespace Tests;

use Ivuorinen\MonologGdprFilter\MaskConstants;
use Ivuorinen\MonologGdprFilter\SerializedDataProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SerializedDataProcessor::class)]
final class SerializedDataProcessorTest extends TestCase
{
    private function createProcessor(?callable $auditLogger = null): SerializedDataProcessor
    {
        $stringMasker = fn(string $value): string => preg_replace(
            '/\b[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}\b/',
            MaskConstants::MASK_EMAIL,
            $value
        ) ?? $value;

        return new SerializedDataProcessor($stringMasker, $auditLogger);
    }

    public function testProcessEmptyMessage(): void
    {
        $processor = $this->createProcessor();

        $this->assertSame('', $processor->process(''));
    }

    public function testProcessPlainTextUnchanged(): void
    {
        $processor = $this->createProcessor();

        $message = 'This is a plain text message without serialized data';
        $this->assertSame($message, $processor->process($message));
    }

    public function testProcessEmbeddedJsonMasksEmail(): void
    {
        $processor = $this->createProcessor();

        $message = 'User data: {"email":"john@example.com","name":"John"}';
        $result = $processor->process($message);

        $this->assertStringContainsString(MaskConstants::MASK_EMAIL, $result);
        $this->assertStringNotContainsString('john@example.com', $result);
    }

    public function testProcessEmbeddedJsonPreservesStructure(): void
    {
        $processor = $this->createProcessor();

        $message = 'Data: {"id":123,"email":"test@example.com"}';
        $result = $processor->process($message);

        // Should still be valid JSON in the message
        preg_match('/\{[^}]+\}/', $result, $matches);
        $this->assertNotEmpty($matches);

        $decoded = json_decode($matches[0], true);
        $this->assertNotNull($decoded);
        $this->assertSame(123, $decoded['id']);
    }

    public function testProcessNestedJson(): void
    {
        $processor = $this->createProcessor();

        $message = 'User: {"user":{"contact":{"email":"test@example.com"}}}';
        $result = $processor->process($message);

        $this->assertStringContainsString(MaskConstants::MASK_EMAIL, $result);
        $this->assertStringNotContainsString('test@example.com', $result);
    }

    public function testProcessPrintROutput(): void
    {
        $processor = $this->createProcessor();

        $printROutput = <<<'PRINT_R'
Array
(
    [name] => John Doe
    [email] => john@example.com
    [age] => 30
)
PRINT_R;

        $result = $processor->process($printROutput);

        $this->assertStringContainsString(MaskConstants::MASK_EMAIL, $result);
        $this->assertStringNotContainsString('john@example.com', $result);
        $this->assertStringContainsString('John Doe', $result); // Name not masked
    }

    public function testProcessPrintROutputWithNestedArrays(): void
    {
        $processor = $this->createProcessor();

        $printROutput = <<<'PRINT_R'
Array
(
    [user] => Array
        (
            [email] => user@example.com
        )
)
PRINT_R;

        $result = $processor->process($printROutput);

        $this->assertStringContainsString(MaskConstants::MASK_EMAIL, $result);
    }

    public function testProcessVarExportOutput(): void
    {
        $processor = $this->createProcessor();

        $varExportOutput = <<<'VAR_EXPORT'
array (
  'name' => 'John Doe',
  'email' => 'john@example.com',
  'active' => true,
)
VAR_EXPORT;

        $result = $processor->process($varExportOutput);

        $this->assertStringContainsString(MaskConstants::MASK_EMAIL, $result);
        $this->assertStringNotContainsString('john@example.com', $result);
    }

    public function testProcessSerializeOutput(): void
    {
        $processor = $this->createProcessor();

        $data = ['email' => 'test@example.com', 'name' => 'Test'];
        $serialized = serialize($data);

        $result = $processor->process($serialized);

        $this->assertStringContainsString(MaskConstants::MASK_EMAIL, $result);
        $this->assertStringNotContainsString('test@example.com', $result);
    }

    public function testProcessSerializeOutputUpdatesLength(): void
    {
        $processor = $this->createProcessor();

        // test@example.com is 16 characters, so s:16:"test@example.com";
        $email = 'test@example.com';
        $serialized = 's:' . strlen($email) . ':"' . $email . '";';
        $result = $processor->process($serialized);

        // Should update the length prefix to match the mask
        $maskLength = strlen(MaskConstants::MASK_EMAIL);
        $this->assertStringContainsString("s:{$maskLength}:", $result);
        $this->assertStringContainsString(MaskConstants::MASK_EMAIL, $result);
    }

    public function testProcessMixedContent(): void
    {
        $processor = $this->createProcessor();

        $message = 'Log entry: User {"email":"test@example.com"} performed action';
        $result = $processor->process($message);

        $this->assertStringContainsString('Log entry: User', $result);
        $this->assertStringContainsString('performed action', $result);
        $this->assertStringContainsString(MaskConstants::MASK_EMAIL, $result);
    }

    public function testProcessWithAuditLogger(): void
    {
        $logs = [];
        $auditLogger = function (string $path, mixed $original, mixed $masked) use (&$logs): void {
            $logs[] = ['path' => $path, 'original' => $original, TestConstants::DATA_MASKED => $masked];
        };

        $processor = $this->createProcessor($auditLogger);

        $processor->process('{"email":"test@example.com"}');

        $this->assertNotEmpty($logs);
        $this->assertStringContainsString('json', $logs[0]['path']);
    }

    public function testSetAuditLogger(): void
    {
        $logs = [];
        $auditLogger = function (string $path, mixed $original, mixed $masked) use (&$logs): void {
            $logs[] = ['path' => $path];
        };

        $processor = $this->createProcessor();
        $processor->setAuditLogger($auditLogger);

        $processor->process('{"email":"test@example.com"}');

        $this->assertNotEmpty($logs);
    }

    public function testProcessInvalidJsonNotModified(): void
    {
        $processor = $this->createProcessor();

        $message = '{invalid json here}';
        $result = $processor->process($message);

        $this->assertSame($message, $result);
    }

    public function testProcessJsonArray(): void
    {
        $processor = $this->createProcessor();

        $message = 'Users: [{"email":"a@example.com"},{"email":"b@example.com"}]';
        $result = $processor->process($message);

        $this->assertStringNotContainsString('a@example.com', $result);
        $this->assertStringNotContainsString('b@example.com', $result);
    }

    public function testProcessDoesNotMaskNonSensitiveData(): void
    {
        $processor = $this->createProcessor();

        $message = '{"status":"ok","count":42}';
        $result = $processor->process($message);

        // Should remain unchanged since no sensitive data
        $this->assertSame($message, $result);
    }

    public function testProcessWithDoubleQuotesInVarExport(): void
    {
        $processor = $this->createProcessor();

        $varExportOutput = <<<'VAR_EXPORT'
array (
  "email" => "john@example.com",
)
VAR_EXPORT;

        $result = $processor->process($varExportOutput);

        $this->assertStringContainsString(MaskConstants::MASK_EMAIL, $result);
    }

    public function testProcessMultipleFormatsInSameMessage(): void
    {
        $processor = $this->createProcessor();

        $message = 'JSON: {"email":"a@example.com"} and serialized: s:16:"b@example.com";';
        $result = $processor->process($message);

        $this->assertStringNotContainsString('a@example.com', $result);
        // Note: b@example.com length is 13, not 16, so serialize won't match
    }

    public function testProcessWithCustomMasker(): void
    {
        $customMasker = fn(string $value): string => str_replace('secret', '[REDACTED]', $value);
        $processor = new SerializedDataProcessor($customMasker);

        $message = '{"data":"this is secret information"}';
        $result = $processor->process($message);

        $this->assertStringContainsString('[REDACTED]', $result);
        $this->assertStringNotContainsString('secret', $result);
    }
}
