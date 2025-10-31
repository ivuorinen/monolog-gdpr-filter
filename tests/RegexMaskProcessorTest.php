<?php

declare(strict_types=1);

namespace Tests;

use Tests\TestConstants;
use Ivuorinen\MonologGdprFilter\Exceptions\InvalidRegexPatternException;
use Ivuorinen\MonologGdprFilter\DefaultPatterns;
use Ivuorinen\MonologGdprFilter\FieldMaskConfig;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\MaskConstants as Mask;
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
#[CoversMethod(DefaultPatterns::class, 'get')]
#[CoversMethod(GdprProcessor::class, 'maskMessage')]
#[CoversMethod(GdprProcessor::class, 'recursiveMask')]
#[CoversMethod(GdprProcessor::class, 'regExpMessage')]
class RegexMaskProcessorTest extends TestCase
{
    use TestHelpers;

    private GdprProcessor $processor;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $patterns = [
            "/\b\d{6}[-+A]?\d{3}[A-Z]\b/u" => Mask::MASK_MASKED,
        ];
        $fieldPaths = [
            "user.ssn" => self::GDPR_REPLACEMENT,
            "order.total" => FieldMaskConfig::useProcessorPatterns(),
        ];
        $this->processor = new GdprProcessor($patterns, $fieldPaths);
    }

    public function testRemoveFieldRemovesKey(): void
    {
        $patterns = DefaultPatterns::get();
        $fieldPaths = ["user.ssn" => FieldMaskConfig::remove()];
        $processor = new GdprProcessor($patterns, $fieldPaths);
        $record = $this->logEntry()->with(
            message: "Remove SSN",
            context: ["user" => ["ssn" => self::TEST_HETU, "name" => TestConstants::NAME_FIRST]],
        );
        $result = ($processor)($record)->toArray();
        $this->assertArrayNotHasKey("ssn", $result["context"]["user"]);
        $this->assertSame(TestConstants::NAME_FIRST, $result["context"]["user"]["name"]);
    }

    public function testReplaceWithFieldReplacesValue(): void
    {
        $patterns = DefaultPatterns::get();
        $fieldPaths = ["user.card" => FieldMaskConfig::replace("MASKED")];
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
        $patterns = DefaultPatterns::get();
        $fieldPaths = [TestConstants::FIELD_USER_NAME => FieldMaskConfig::useProcessorPatterns()];
        $customCallbacks = [TestConstants::FIELD_USER_NAME => fn($value): string => strtoupper((string)$value)];
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
        $patterns = DefaultPatterns::get();
        $fieldPaths = [TestConstants::FIELD_USER_EMAIL => FieldMaskConfig::useProcessorPatterns()];
        $auditCalls = [];
        $auditLogger = function ($path, $original, $masked) use (&$auditCalls): void {
            $auditCalls[] = [$path, $original, $masked];
        };
        $processor = new GdprProcessor($patterns, $fieldPaths, [], $auditLogger);
        $record = $this->logEntry()->with(
            message: self::USER_REGISTERED,
            context: ["user" => [TestConstants::CONTEXT_EMAIL => self::TEST_EMAIL]],
        );
        $processor($record);
        $this->assertNotEmpty($auditCalls);
        $this->assertSame([TestConstants::FIELD_USER_EMAIL, TestConstants::EMAIL_JOHN_DOE, Mask::MASK_EMAIL], $auditCalls[0]);
    }

    public function testInvalidRegexPatternThrowsExceptionOnConstruction(): void
    {
        // Test that invalid regex patterns are caught during construction
        $this->expectException(InvalidRegexPatternException::class);
        $this->expectExceptionMessage("Invalid regex pattern '/[invalid/'");

        new GdprProcessor(['/[invalid/' => 'MASKED']);
    }

    public function testValidRegexPatternsWorkCorrectly(): void
    {
        // Test that valid regex patterns work correctly
        $validPatterns = [
            TestConstants::PATTERN_TEST => 'REPLACED',
            TestConstants::PATTERN_DIGITS => 'NUMBER',
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
        $patterns = DefaultPatterns::get();
        $fieldPaths = [TestConstants::FIELD_USER_EMAIL => Mask::MASK_BRACKETS];
        $processor = new GdprProcessor($patterns, $fieldPaths);
        $record = $this->logEntry()->with(
            message: self::USER_REGISTERED,
            context: ["user" => [TestConstants::CONTEXT_EMAIL => self::TEST_EMAIL]],
        );
        $result = ($processor)($record)->toArray();
        $this->assertSame(Mask::MASK_BRACKETS, $result["context"]["user"][TestConstants::CONTEXT_EMAIL]);
    }

    public function testNonStringValueInContextIsUnchanged(): void
    {
        $patterns = DefaultPatterns::get();
        $fieldPaths = ["user.id" => FieldMaskConfig::useProcessorPatterns()];
        $processor = new GdprProcessor($patterns, $fieldPaths);
        $record = $this->logEntry()->with(
            message: self::USER_REGISTERED,
            context: ["user" => ["id" => 12345]],
        );
        $result = ($processor)($record)->toArray();
        $this->assertSame(TestConstants::DATA_NUMBER_STRING, $result["context"]["user"]["id"]);
    }

    public function testMissingFieldInContextIsIgnored(): void
    {
        $patterns = DefaultPatterns::get();
        $fieldPaths = ["user.missing" => FieldMaskConfig::useProcessorPatterns()];
        $processor = new GdprProcessor($patterns, $fieldPaths);
        $record = $this->logEntry()->with(
            message: self::USER_REGISTERED,
            context: ["user" => [TestConstants::CONTEXT_EMAIL => self::TEST_EMAIL]],
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
            $this->assertSame("ID: " . Mask::MASK_MASKED, $result["message"]);
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
        $this->assertSame(Mask::MASK_MASKED . " €150", $result["context"]["order"]["total"]);
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
            context: ["user" => ["name" => TestConstants::NAME_FIRST]],
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
            TestConstants::PATTERN_SECRET => 'MASKED',
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
        $regex = FieldMaskConfig::useProcessorPatterns();
        $remove = FieldMaskConfig::remove();
        $replace = FieldMaskConfig::replace('MASKED');
        $this->assertSame('mask_regex', $regex->type);
        $this->assertSame('remove', $remove->type);
        $this->assertSame('replace', $replace->type);
        $this->assertSame('MASKED', $replace->replacement);
    }
}
