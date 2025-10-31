<?php

declare(strict_types=1);

namespace Tests;

use Tests\TestConstants;
use Ivuorinen\MonologGdprFilter\JsonMasker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonMasker::class)]
final class JsonMaskerEnhancedTest extends TestCase
{
    public function testEncodePreservingEmptyObjectsWithEmptyString(): void
    {
        $recursiveCallback = fn($val) => $val;
        $masker = new JsonMasker($recursiveCallback);

        $result = $masker->encodePreservingEmptyObjects('', '{}');

        $this->assertSame('{}', $result);
    }

    public function testEncodePreservingEmptyObjectsWithZeroString(): void
    {
        $recursiveCallback = fn($val) => $val;
        $masker = new JsonMasker($recursiveCallback);

        $result = $masker->encodePreservingEmptyObjects('0', '{}');

        $this->assertSame('{}', $result);
    }

    public function testEncodePreservingEmptyObjectsWithEmptyArray(): void
    {
        $recursiveCallback = fn($val) => $val;
        $masker = new JsonMasker($recursiveCallback);

        $result = $masker->encodePreservingEmptyObjects([], '[]');

        $this->assertSame('[]', $result);
    }

    public function testEncodePreservingEmptyObjectsWithEmptyArrayButObjectOriginal(): void
    {
        $recursiveCallback = fn($val) => $val;
        $masker = new JsonMasker($recursiveCallback);

        $result = $masker->encodePreservingEmptyObjects([], '{}');

        $this->assertSame('{}', $result);
    }

    public function testEncodePreservingEmptyObjectsReturnsFalseOnEncodingFailure(): void
    {
        $recursiveCallback = fn($val) => $val;
        $masker = new JsonMasker($recursiveCallback);

        // Create a resource which cannot be JSON encoded
        $resource = fopen('php://memory', 'r');
        $this->assertIsResource($resource);

        $result = $masker->encodePreservingEmptyObjects(['resource' => $resource], '{}');
        fclose($resource);

        $this->assertFalse($result);
    }

    public function testProcessCandidateWithNullDecoded(): void
    {
        $recursiveCallback = fn($val) => $val;
        $masker = new JsonMasker($recursiveCallback);

        // JSON string "null" decodes to null
        $result = $masker->processCandidate('null');

        // Should return original since decoded is null
        $this->assertSame('null', $result);
    }

    public function testProcessCandidateWithInvalidJson(): void
    {
        $recursiveCallback = fn($val) => $val;
        $masker = new JsonMasker($recursiveCallback);

        $result = $masker->processCandidate('{invalid json}');

        $this->assertSame('{invalid json}', $result);
    }

    public function testProcessCandidateWithAuditLoggerWhenUnchanged(): void
    {
        $auditLog = [];
        $auditLogger = function (string $path, mixed $original, mixed $masked) use (&$auditLog): void {
            $auditLog[] = ['path' => $path, 'original' => $original, TestConstants::DATA_MASKED => $masked];
        };

        $recursiveCallback = fn($val) => $val;
        $masker = new JsonMasker($recursiveCallback, $auditLogger);

        $json = '{"key":"value"}';
        $result = $masker->processCandidate($json);

        // Should not log when unchanged
        $this->assertCount(0, $auditLog);
    }

    public function testProcessCandidateWithEncodingFailure(): void
    {
        $recursiveCallback = function ($val) {
            // Return something that can't be re-encoded
            if (is_array($val)) {
                $resource = fopen('php://memory', 'r');
                return ['resource' => $resource];
            }
            return $val;
        };

        $masker = new JsonMasker($recursiveCallback);

        $json = '{"key":"value"}';
        $result = $masker->processCandidate($json);

        // Should return original when encoding fails
        $this->assertSame($json, $result);
    }

    public function testFixEmptyObjectsWithNoEmptyObjects(): void
    {
        $recursiveCallback = fn($val) => $val;
        $masker = new JsonMasker($recursiveCallback);

        $encoded = '{"key":"value"}';
        $original = '{"key":"value"}';

        $result = $masker->fixEmptyObjects($encoded, $original);

        $this->assertSame($encoded, $result);
    }

    public function testFixEmptyObjectsReplacesEmptyArrays(): void
    {
        $recursiveCallback = fn($val) => $val;
        $masker = new JsonMasker($recursiveCallback);

        $encoded = '{"a":[],"b":[]}';
        $original = '{"a":{},"b":{}}';

        $result = $masker->fixEmptyObjects($encoded, $original);

        $this->assertSame('{"a":{},"b":{}}', $result);
    }

    public function testExtractBalancedStructureWithUnbalancedJson(): void
    {
        $recursiveCallback = fn($val) => $val;
        $masker = new JsonMasker($recursiveCallback);

        $message = '{"unclosed":';
        $result = $masker->extractBalancedStructure($message, 0);

        $this->assertNull($result);
    }

    public function testExtractBalancedStructureWithEscapedQuotes(): void
    {
        $recursiveCallback = fn($val) => $val;
        $masker = new JsonMasker($recursiveCallback);

        $message = '{"key":"value with \\" quote"}';
        $result = $masker->extractBalancedStructure($message, 0);

        $this->assertSame('{"key":"value with \\" quote"}', $result);
    }

    public function testExtractBalancedStructureWithNestedArrays(): void
    {
        $recursiveCallback = fn($val) => $val;
        $masker = new JsonMasker($recursiveCallback);

        $message = '[[1,2,[3,4]]]';
        $result = $masker->extractBalancedStructure($message, 0);

        $this->assertSame('[[1,2,[3,4]]]', $result);
    }

    public function testProcessMessageWithNoJson(): void
    {
        $recursiveCallback = fn($val) => $val;
        $masker = new JsonMasker($recursiveCallback);

        $message = 'Plain text message';
        $result = $masker->processMessage($message);

        $this->assertSame($message, $result);
    }

    public function testProcessMessageWithInvalidJsonLike(): void
    {
        $recursiveCallback = fn($val) => $val;
        $masker = new JsonMasker($recursiveCallback);

        $message = 'Text {not json} more text';
        $result = $masker->processMessage($message);

        $this->assertSame($message, $result);
    }
}
