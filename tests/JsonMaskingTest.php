<?php

declare(strict_types=1);

namespace Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Monolog\LogRecord;
use Monolog\Level;

/**
 * Test JSON string masking functionality within log messages.
 *
 * @api
 */
class JsonMaskingTest extends TestCase
{
    public function testSimpleJsonObjectMasking(): void
    {
        $processor = new GdprProcessor([
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '***EMAIL***'
        ]);

        $message = 'User data: {"email": "user@example.com", "name": "John Doe"}';
        $result = $processor->regExpMessage($message);

        $this->assertStringContainsString('***EMAIL***', $result);
        $this->assertStringNotContainsString('user@example.com', $result);

        // Verify it's still valid JSON
        $extractedJson = $this->extractJsonFromMessage($result);
        $this->assertNotNull($extractedJson);
        $this->assertEquals('***EMAIL***', $extractedJson['email']);
        $this->assertEquals('John Doe', $extractedJson['name']);
    }

    public function testJsonArrayMasking(): void
    {
        $processor = new GdprProcessor([
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '***EMAIL***'
        ]);

        $message = 'Users: [{"email": "admin@example.com"}, {"email": "user@test.com"}]';
        $result = $processor->regExpMessage($message);

        $this->assertStringContainsString('***EMAIL***', $result);
        $this->assertStringNotContainsString('admin@example.com', $result);
        $this->assertStringNotContainsString('user@test.com', $result);

        // Verify it's still valid JSON
        $extractedJson = $this->extractJsonArrayFromMessage($result);
        $this->assertNotNull($extractedJson);
        $this->assertEquals('***EMAIL***', $extractedJson[0]['email']);
        $this->assertEquals('***EMAIL***', $extractedJson[1]['email']);
    }

    public function testNestedJsonMasking(): void
    {
        $processor = new GdprProcessor([
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '***EMAIL***',
            '/\b\d{3}-\d{2}-\d{4}\b/' => '***SSN***'
        ]);

        $message = 'Complex data: {"user": {"contact": {"email": "nested@example.com", "ssn": "123-45-6789"}, "id": 42}}';
        $result = $processor->regExpMessage($message);

        $this->assertStringContainsString('***EMAIL***', $result);
        $this->assertStringContainsString('***SSN***', $result);
        $this->assertStringNotContainsString('nested@example.com', $result);
        $this->assertStringNotContainsString('123-45-6789', $result);

        // Verify nested structure is maintained
        $extractedJson = $this->extractJsonFromMessage($result);
        $this->assertNotNull($extractedJson);
        $this->assertEquals('***EMAIL***', $extractedJson['user']['contact']['email']);
        $this->assertEquals('***SSN***', $extractedJson['user']['contact']['ssn']);
        $this->assertEquals(42, $extractedJson['user']['id']);
    }

    public function testMultipleJsonStringsInMessage(): void
    {
        $processor = new GdprProcessor([
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '***EMAIL***'
        ]);

        $message = 'Request: {"email": "req@example.com"} Response: {"email": "resp@test.com", "status": "ok"}';
        $result = $processor->regExpMessage($message);

        $this->assertStringContainsString('***EMAIL***', $result);
        $this->assertStringNotContainsString('req@example.com', $result);
        $this->assertStringNotContainsString('resp@test.com', $result);

        // Both JSON objects should be masked
        preg_match_all('/\{[^}]+\}/', $result, $matches);
        $this->assertCount(2, $matches[0]);

        foreach ($matches[0] as $jsonStr) {
            $decoded = json_decode($jsonStr, true);
            $this->assertNotNull($decoded);
            if (isset($decoded['email'])) {
                $this->assertEquals('***EMAIL***', $decoded['email']);
            }
        }
    }

    public function testInvalidJsonStillGetsMasked(): void
    {
        $processor = new GdprProcessor([
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '***EMAIL***'
        ]);

        $message = 'Invalid JSON: {email: "invalid@example.com", missing quotes} and email@test.com';
        $result = $processor->regExpMessage($message);

        // Since it's not valid JSON, regular patterns should apply to everything
        $this->assertStringContainsString('***EMAIL***', $result);
        $this->assertStringNotContainsString('invalid@example.com', $result);
        $this->assertStringNotContainsString('email@test.com', $result);

        // The structure should still be there, just with masked emails
        $this->assertStringContainsString('{email: "***EMAIL***", missing quotes}', $result);
    }

    public function testJsonWithSpecialCharacters(): void
    {
        $processor = new GdprProcessor([
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '***EMAIL***'
        ]);

        $message = 'Data: {"email": "user@example.com", "message": "Hello \"world\"", "unicode": "café ñoño"}';
        $result = $processor->regExpMessage($message);

        $this->assertStringContainsString('***EMAIL***', $result);
        $this->assertStringNotContainsString('user@example.com', $result);

        $extractedJson = $this->extractJsonFromMessage($result);
        $this->assertNotNull($extractedJson);
        $this->assertEquals('***EMAIL***', $extractedJson['email']);
        $this->assertEquals('Hello "world"', $extractedJson['message']);
        $this->assertEquals('café ñoño', $extractedJson['unicode']);
    }

    public function testJsonMaskingWithDataTypeMasks(): void
    {
        $processor = new GdprProcessor(
            ['/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '***EMAIL***'],
            [],
            [],
            null,
            100,
            ['integer' => '***INT***', 'string' => '***STRING***']
        );

        $message = 'Data: {"email": "user@example.com", "id": 12345, "active": true}';
        $result = $processor->regExpMessage($message);

        $extractedJson = $this->extractJsonFromMessage($result);
        $this->assertNotNull($extractedJson);

        // Email should be masked by regex pattern (takes precedence over data type masking)
        $this->assertEquals('***EMAIL***', $extractedJson['email']);
        // Integer should be masked by data type rule
        $this->assertEquals('***INT***', $extractedJson['id']);
        // Boolean should remain unchanged (no data type mask configured)
        $this->assertTrue($extractedJson['active']);
    }

    public function testJsonMaskingWithAuditLogger(): void
    {
        $auditLogs = [];
        $auditLogger = function (string $path, mixed $original, mixed $masked) use (&$auditLogs): void {
            $auditLogs[] = ['path' => $path, 'original' => $original, 'masked' => $masked];
        };

        $processor = new GdprProcessor(
            ['/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '***EMAIL***'],
            [],
            [],
            $auditLogger
        );

        $message = 'User: {"email": "test@example.com", "name": "Test User"}';
        $result = $processor->regExpMessage($message);

        $this->assertStringContainsString('***EMAIL***', $result);

        // Should have logged the JSON masking operation
        $jsonMaskingLogs = array_filter($auditLogs, fn($log) => $log['path'] === 'json_masked');
        $this->assertNotEmpty($jsonMaskingLogs);

        $jsonLog = reset($jsonMaskingLogs);
        $this->assertStringContainsString('test@example.com', (string) $jsonLog['original']);
        $this->assertStringContainsString('***EMAIL***', (string) $jsonLog['masked']);
    }

    public function testJsonMaskingInLogRecord(): void
    {
        $processor = new GdprProcessor([
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '***EMAIL***'
        ]);

        $logRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            'API Response: {"user": {"email": "api@example.com"}, "status": "success"}',
            []
        );

        $result = $processor($logRecord);

        $this->assertStringContainsString('***EMAIL***', $result->message);
        $this->assertStringNotContainsString('api@example.com', $result->message);

        // Verify JSON structure is maintained
        $extractedJson = $this->extractJsonFromMessage($result->message);
        $this->assertNotNull($extractedJson);
        $this->assertEquals('***EMAIL***', $extractedJson['user']['email']);
        $this->assertEquals('success', $extractedJson['status']);
    }

    public function testJsonMaskingWithConditionalRules(): void
    {
        $processor = new GdprProcessor(
            ['/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '***EMAIL***'],
            [],
            [],
            null,
            100,
            [],
            [
                'error_level' => GdprProcessor::createLevelBasedRule(['Error'])
            ]
        );

        // ERROR level - should mask JSON
        $errorRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Error,
            'Error data: {"email": "error@example.com"}',
            []
        );

        $result = $processor($errorRecord);
        $this->assertStringContainsString('***EMAIL***', $result->message);
        $this->assertStringNotContainsString('error@example.com', $result->message);

        // INFO level - should NOT mask JSON
        $infoRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            'Info data: {"email": "info@example.com"}',
            []
        );

        $result = $processor($infoRecord);
        $this->assertStringNotContainsString('***EMAIL***', $result->message);
        $this->assertStringContainsString('info@example.com', $result->message);
    }

    public function testComplexJsonWithArraysAndObjects(): void
    {
        $processor = new GdprProcessor([
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '***EMAIL***',
            '/\+1-\d{3}-\d{3}-\d{4}/' => '***PHONE***'
        ]);

        $complexJson = '{
            "users": [
                {
                    "id": 1,
                    "email": "john@example.com",
                    "contacts": {
                        "phone": "+1-555-123-4567",
                        "emergency": {
                            "email": "emergency@example.com",
                            "phone": "+1-555-987-6543"
                        }
                    }
                },
                {
                    "id": 2,
                    "email": "jane@test.com",
                    "contacts": {
                        "phone": "+1-555-456-7890"
                    }
                }
            ]
        }';

        $message = 'Complex data: ' . $complexJson;
        $result = $processor->regExpMessage($message);

        $this->assertStringContainsString('***EMAIL***', $result);
        $this->assertStringContainsString('***PHONE***', $result);
        $this->assertStringNotContainsString('john@example.com', $result);
        $this->assertStringNotContainsString('jane@test.com', $result);
        $this->assertStringNotContainsString('emergency@example.com', $result);
        $this->assertStringNotContainsString('+1-555-123-4567', $result);

        // Verify complex structure is maintained
        $extractedJson = $this->extractJsonFromMessage($result);
        $this->assertNotNull($extractedJson);
        $this->assertCount(2, $extractedJson['users']);
        $this->assertEquals('***EMAIL***', $extractedJson['users'][0]['email']);
        $this->assertEquals('***PHONE***', $extractedJson['users'][0]['contacts']['phone']);
        $this->assertEquals('***EMAIL***', $extractedJson['users'][0]['contacts']['emergency']['email']);
    }

    public function testJsonMaskingErrorHandling(): void
    {
        $auditLogs = [];
        $auditLogger = function (string $path, mixed $original, mixed $masked) use (&$auditLogs): void {
            $auditLogs[] = ['path' => $path, 'original' => $original, 'masked' => $masked];
        };

        $processor = new GdprProcessor(
            ['/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '***EMAIL***'],
            [],
            [],
            $auditLogger
        );

        // Test with JSON that becomes invalid after processing (edge case)
        $message = 'Malformed after processing: {"valid": true}';
        $result = $processor->regExpMessage($message);

        // Should process normally
        $this->assertStringContainsString('{"valid":true}', $result);

        // No error logs should be generated for valid JSON
        $errorLogs = array_filter($auditLogs, fn($log) => str_contains($log['path'], 'error'));
        $this->assertEmpty($errorLogs);
    }

    /**
     * Helper method to extract JSON object from a message string.
     */
    private function extractJsonFromMessage(string $message): ?array
    {
        // Find the first opening brace
        $startPos = strpos($message, '{');
        if ($startPos === false) {
            return null;
        }

        // Count braces to find the matching closing brace
        $braceCount = 0;
        $length = strlen($message);
        $endPos = -1;

        for ($i = $startPos; $i < $length; $i++) {
            if ($message[$i] === '{') {
                $braceCount++;
            } elseif ($message[$i] === '}') {
                $braceCount--;
                if ($braceCount === 0) {
                    $endPos = $i;
                    break;
                }
            }
        }

        if ($endPos === -1) {
            return null;
        }

        $jsonString = substr($message, $startPos, $endPos - $startPos + 1);
        return json_decode($jsonString, true);
    }

    /**
     * Helper method to extract JSON array from a message string.
     */
    private function extractJsonArrayFromMessage(string $message): ?array
    {
        if (preg_match('/\[[^\]]+\]/', $message, $matches)) {
            return json_decode($matches[0], true);
        }

        return null;
    }

    public function testEmptyJsonHandling(): void
    {
        $processor = new GdprProcessor([
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '***EMAIL***'
        ]);

        $message = 'Empty objects: {} [] {"empty": {}}';
        $result = $processor->regExpMessage($message);

        // Empty JSON structures should remain as-is
        $this->assertStringContainsString('{}', $result);
        $this->assertStringContainsString('[]', $result);
        $this->assertStringContainsString('{"empty":{}}', $result);
    }

    public function testJsonWithNullValues(): void
    {
        $processor = new GdprProcessor([
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '***EMAIL***'
        ]);

        $message = 'Data: {"email": "user@example.com", "optional": null, "empty": ""}';
        $result = $processor->regExpMessage($message);

        $extractedJson = $this->extractJsonFromMessage($result);
        $this->assertNotNull($extractedJson);
        $this->assertEquals('***EMAIL***', $extractedJson['email']);
        $this->assertNull($extractedJson['optional']);
        $this->assertEquals('', $extractedJson['empty']);
    }
}
