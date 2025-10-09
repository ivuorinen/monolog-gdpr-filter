<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

/**
 * Test regex mask processor functionality.
 *
 * @api
 */
#[CoversClass(GdprProcessor::class)]
#[CoversMethod(GdprProcessor::class, '__construct')]
#[CoversMethod(GdprProcessor::class, '__invoke')]
#[CoversMethod(GdprProcessor::class, 'getDefaultPatterns')]
#[CoversMethod(GdprProcessor::class, 'maskMessage')]
#[CoversMethod(GdprProcessor::class, 'maskWithRegex')]
#[CoversMethod(GdprProcessor::class, 'recursiveMask')]
#[CoversMethod(GdprProcessor::class, 'regExpMessage')]
#[CoversMethod(GdprProcessor::class, 'removeField')]
#[CoversMethod(GdprProcessor::class, 'replaceWith')]
class RegexMaskProcessorTest extends TestCase
{
    use TestHelpers;

    private GdprProcessor $processor;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $patterns = [
            "/\b\d{6}[-+A]?\d{3}[A-Z]\b/u" => "***MASKED***",
        ];
        $fieldPaths = [
            "user.ssn" => self::GDPR_REPLACEMENT,
            "order.total" => GdprProcessor::maskWithRegex(),
        ];
        $this->processor = new GdprProcessor($patterns, $fieldPaths);
    }

    public function testRemoveFieldRemovesKey(): void
    {
        $patterns = $this->processor::getDefaultPatterns();
        $fieldPaths = ["user.ssn" => GdprProcessor::removeField()];
        $processor = new GdprProcessor($patterns, $fieldPaths);
        $record = $this->logEntry()->with(
            message: "Remove SSN",
            context: ["user" => ["ssn" => self::TEST_HETU, "name" => "John"]],
        );
        $result = ($processor)($record)->toArray();
        $this->assertArrayNotHasKey("ssn", $result["context"]["user"]);
        $this->assertSame("John", $result["context"]["user"]["name"]);
    }

    public function testReplaceWithFieldReplacesValue(): void
    {
        $patterns = $this->processor::getDefaultPatterns();
        $fieldPaths = ["user.card" => GdprProcessor::replaceWith("MASKED")];
        $processor = new GdprProcessor($patterns, $fieldPaths);
        $record = $this->logEntry()->with(
            message: "Payment processed",
            context: ["user" => ["card" => "1234123412341234"]],
        );
        $result = ($processor)($record)->toArray();
        $this->assertSame("MASKED", $result["context"]["user"]["card"]);
    }

    public function testCustomCallbackIsUsed(): void
    {
        $patterns = $this->processor::getDefaultPatterns();
        $fieldPaths = ["user.name" => GdprProcessor::maskWithRegex()];
        $customCallbacks = ["user.name" => fn($value): string => strtoupper((string)$value)];
        $processor = new GdprProcessor($patterns, $fieldPaths, $customCallbacks);
        $record = $this->logEntry()->with(
            message: "Name logged",
            context: ["user" => ["name" => "john"]],
        );
        $result = ($processor)($record)->toArray();
        $this->assertSame("JOHN", $result["context"]["user"]["name"]);
    }

    public function testAuditLoggerIsCalled(): void
    {
        $patterns = $this->processor::getDefaultPatterns();
        $fieldPaths = ["user.email" => GdprProcessor::maskWithRegex()];
        $auditCalls = [];
        $auditLogger = function ($path, $original, $masked) use (&$auditCalls): void {
            $auditCalls[] = [$path, $original, $masked];
        };
        $processor = new GdprProcessor($patterns, $fieldPaths, [], $auditLogger);
        $record = $this->logEntry()->with(
            message: self::USER_REGISTERED,
            context: ["user" => ["email" => self::TEST_EMAIL]],
        );
        $processor($record);
        $this->assertNotEmpty($auditCalls);
        $this->assertSame(["user.email", "john.doe@example.com", "***EMAIL***"], $auditCalls[0]);
    }

    public function testInvalidRegexPatternThrowsExceptionOnConstruction(): void
    {
        // Test that invalid regex patterns are caught during construction
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid regex pattern: '/[invalid/'");

        new GdprProcessor(['/[invalid/' => 'MASKED']);
    }

    public function testValidRegexPatternsWorkCorrectly(): void
    {
        // Test that valid regex patterns work correctly
        $validPatterns = [
            '/test/' => 'REPLACED',
            '/\d+/' => 'NUMBER',
        ];

        $processor = new GdprProcessor($validPatterns);
        $this->assertInstanceOf(GdprProcessor::class, $processor);

        // Test that the patterns actually work
        $result = $processor->regExpMessage('test 123');
        $this->assertStringContainsString('REPLACED', $result);
        $this->assertStringContainsString('NUMBER', $result);
    }

    public function testStringReplacementBackwardCompatibility(): void
    {
        $patterns = $this->processor::getDefaultPatterns();
        $fieldPaths = ["user.email" => '[MASKED]'];
        $processor = new GdprProcessor($patterns, $fieldPaths);
        $record = $this->logEntry()->with(
            message: self::USER_REGISTERED,
            context: ["user" => ["email" => self::TEST_EMAIL]],
        );
        $result = ($processor)($record)->toArray();
        $this->assertSame('[MASKED]', $result["context"]["user"]["email"]);
    }

    public function testNonStringValueInContextIsUnchanged(): void
    {
        $patterns = $this->processor::getDefaultPatterns();
        $fieldPaths = ["user.id" => GdprProcessor::maskWithRegex()];
        $processor = new GdprProcessor($patterns, $fieldPaths);
        $record = $this->logEntry()->with(
            message: self::USER_REGISTERED,
            context: ["user" => ["id" => 12345]],
        );
        $result = ($processor)($record)->toArray();
        $this->assertSame('12345', $result["context"]["user"]["id"]);
    }

    public function testMissingFieldInContextIsIgnored(): void
    {
        $patterns = $this->processor::getDefaultPatterns();
        $fieldPaths = ["user.missing" => GdprProcessor::maskWithRegex()];
        $processor = new GdprProcessor($patterns, $fieldPaths);
        $record = $this->logEntry()->with(
            message: self::USER_REGISTERED,
            context: ["user" => ["email" => self::TEST_EMAIL]],
        );
        $result = ($processor)($record)->toArray();
        $this->assertArrayNotHasKey('missing', $result["context"]["user"]);
    }

    public function testHetuMasking(): void
    {
        $testHetu = [self::TEST_HETU, "131052+308T", "131052A308T"];
        foreach ($testHetu as $hetu) {
            $record = $this->logEntry()->with(message: 'ID: ' . $hetu);
            $result = ($this->processor)($record)->toArray();
            $this->assertSame("ID: ***MASKED***", $result["message"]);
        }
    }

    public function testReplacesContextUserSsnWithCustomReplacement(): void
    {
        $record = $this->logEntry()->with(
            message: "Login",
            context: ["user" => ["ssn" => self::TEST_HETU]],
        );
        $result = ($this->processor)($record)->toArray();
        $this->assertSame(self::GDPR_REPLACEMENT, $result["context"]["user"]["ssn"]);
    }

    public function testMasksOrderTotalUsingRegexWhenReplacementIsEmpty(): void
    {
        $record = $this->logEntry()->with(
            message: "Order created",
            context: ["order" => ["total" => self::TEST_HETU . " €150"]],
        );
        $result = ($this->processor)($record)->toArray();
        $this->assertSame("***MASKED*** €150", $result["context"]["order"]["total"]);
    }

    public function testNoMaskingWhenPatternDoesNotMatch(): void
    {
        $record = $this->logEntry()->with(
            message: "No sensitive data here",
            context: ["user" => ["ssn" => "not-a-hetu"]],
        );
        $result = ($this->processor)($record)->toArray();
        $this->assertSame("No sensitive data here", $result["message"]);
        $this->assertSame(self::GDPR_REPLACEMENT, $result["context"]["user"]["ssn"]);
    }

    public function testMissingFieldPathIsIgnored(): void
    {
        $record = $this->logEntry()->with(
            message: "Missing field",
            context: ["user" => ["name" => "John"]],
        );
        $result = ($this->processor)($record)->toArray();
        $this->assertArrayNotHasKey("ssn", $result["context"]["user"]);
    }

    public function testMaskMessageDirect(): void
    {
        $patterns = [
            '/foo/' => 'bar',
            '/baz/' => 'qux',
        ];
        $processor = new GdprProcessor($patterns);
        $masked = $processor->maskMessage('foo and baz');
        $this->assertSame('bar and qux', $masked);
    }

    public function testRecursiveMaskDirect(): void
    {
        $patterns = [
            '/secret/' => 'MASKED',
        ];
        $processor = new class ($patterns) extends GdprProcessor {
            public function callRecursiveMask(mixed $data): array|string
            {
                return $this->recursiveMask($data);
            }
        };
        $data = [
            'a' => 'secret',
            'b' => ['c' => 'secret'],
            'd' => 123,
        ];
        $masked = $processor->callRecursiveMask($data);
        $this->assertSame([
            'a' => 'MASKED',
            'b' => ['c' => 'MASKED'],
            'd' => 123,
        ], $masked);
    }

    public function testStaticHelpers(): void
    {
        $regex = GdprProcessor::maskWithRegex();
        $remove = GdprProcessor::removeField();
        $replace = GdprProcessor::replaceWith('MASKED');
        $this->assertSame('mask_regex', $regex->type);
        $this->assertSame('remove', $remove->type);
        $this->assertSame('replace', $replace->type);
        $this->assertSame('MASKED', $replace->replacement);
    }
}
