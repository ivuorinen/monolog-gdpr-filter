<?php

declare(strict_types=1);

namespace Tests;

use Ivuorinen\MonologGdprFilter\Exceptions\InvalidConfigurationException;
use Ivuorinen\MonologGdprFilter\Exceptions\InvalidRegexPatternException;
use Ivuorinen\MonologGdprFilter\FieldMaskConfig;
use Ivuorinen\MonologGdprFilter\InputValidator;
use Ivuorinen\MonologGdprFilter\MaskConstants;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\TestConstants;

#[CoversClass(InputValidator::class)]
final class InputValidatorTest extends TestCase
{
    #[Test]
    public function validateAllPassesWithValidInputs(): void
    {
        // Void validator: passing means not throwing. Declared explicitly rather than
        // faked with a vacuous assertion; rejection is covered by the matching negative tests.
        $this->expectNotToPerformAssertions();

        $patterns = [TestConstants::PATTERN_SSN_FORMAT => MaskConstants::MASK_GENERIC];
        $fieldPaths = [TestConstants::FIELD_USER_EMAIL => MaskConstants::MASK_GENERIC];
        $customCallbacks = ['user.id' => fn($value): string => (string) $value];
        $auditLogger = static fn (string $field, mixed $old, mixed $new): null => null;
        $maxDepth = 10;
        $dataTypeMasks = ['string' => MaskConstants::MASK_GENERIC];
        $conditionalRules = ['rule1' => fn($value): true => true];

        InputValidator::validateAll(
            $patterns,
            $fieldPaths,
            $customCallbacks,
            $auditLogger,
            $maxDepth,
            $dataTypeMasks,
            $conditionalRules
        );
    }

    #[Test]
    public function validatePatternsThrowsForNonStringPattern(): void
    {
        // expectExceptionMessageIsOrContains() sets a single predicate, so a second call
        // would silently replace the first and only 'string' would be enforced. Catch and
        // assert both fragments instead.
        try {
            InputValidator::validatePatterns([123 => MaskConstants::MASK_GENERIC]);
            $this->fail('Expected InvalidConfigurationException for a non-string pattern');
        } catch (InvalidConfigurationException $e) {
            $this->assertStringContainsString('pattern', $e->getMessage());
            $this->assertStringContainsString('string', $e->getMessage());
        }
    }

    #[Test]
    public function validatePatternsThrowsForEmptyPattern(): void
    {
        // expectExceptionMessageIsOrContains() sets a single predicate, so chaining
        // two would enforce only the second. Assert both fragments explicitly.
        try {
            InputValidator::validatePatterns(['' => MaskConstants::MASK_GENERIC]);
            $this->fail('Expected InvalidConfigurationException');
        } catch (InvalidConfigurationException $e) {
            $this->assertStringContainsString('pattern', $e->getMessage());
            $this->assertStringContainsString('empty', $e->getMessage());
        }
    }

    #[Test]
    public function validatePatternsThrowsForNonStringReplacement(): void
    {
        // expectExceptionMessageIsOrContains() sets a single predicate, so chaining
        // two would enforce only the second. Assert both fragments explicitly.
        try {
            InputValidator::validatePatterns([TestConstants::PATTERN_TEST => 123]);
            $this->fail('Expected InvalidConfigurationException');
        } catch (InvalidConfigurationException $e) {
            $this->assertStringContainsString('replacement', $e->getMessage());
            $this->assertStringContainsString('string', $e->getMessage());
        }
    }

    #[Test]
    public function validatePatternsThrowsForInvalidRegex(): void
    {
        $this->expectException(InvalidRegexPatternException::class);

        InputValidator::validatePatterns(['/[invalid/' => MaskConstants::MASK_GENERIC]);
    }

    #[Test]
    public function validatePatternsPassesForValidPatterns(): void
    {
        // Void validator: passing means not throwing. Declared explicitly rather than
        // faked with a vacuous assertion; rejection is covered by the matching negative tests.
        $this->expectNotToPerformAssertions();

        InputValidator::validatePatterns([
            TestConstants::PATTERN_SSN_FORMAT => MaskConstants::MASK_SSN_PATTERN,
            TestConstants::PATTERN_SAFE => TestConstants::MASK_REDACTED_PLAIN,
        ]);
    }

    #[Test]
    public function validateFieldPathsThrowsForNonStringPath(): void
    {
        // expectExceptionMessageIsOrContains() sets a single predicate, so chaining
        // two would enforce only the second. Assert both fragments explicitly.
        try {
            InputValidator::validateFieldPaths([123 => MaskConstants::MASK_GENERIC]);
            $this->fail('Expected InvalidConfigurationException');
        } catch (InvalidConfigurationException $e) {
            $this->assertStringContainsString('field path', $e->getMessage());
            $this->assertStringContainsString('string', $e->getMessage());
        }
    }

    #[Test]
    public function validateFieldPathsThrowsForEmptyPath(): void
    {
        // expectExceptionMessageIsOrContains() sets a single predicate, so chaining
        // two would enforce only the second. Assert both fragments explicitly.
        try {
            InputValidator::validateFieldPaths(['' => MaskConstants::MASK_GENERIC]);
            $this->fail('Expected InvalidConfigurationException');
        } catch (InvalidConfigurationException $e) {
            $this->assertStringContainsString('field path', $e->getMessage());
            $this->assertStringContainsString('empty', $e->getMessage());
        }
    }

    #[Test]
    public function validateFieldPathsThrowsForInvalidConfigType(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('field path value');

        InputValidator::validateFieldPaths([TestConstants::FIELD_USER_EMAIL => 123]);
    }

    #[Test]
    public function validateFieldPathsThrowsForEmptyStringValue(): void
    {
        // expectExceptionMessageIsOrContains() sets a single predicate, so chaining
        // two would enforce only the second. Assert both fragments explicitly.
        try {
            InputValidator::validateFieldPaths([TestConstants::FIELD_USER_EMAIL => '']);
            $this->fail('Expected InvalidConfigurationException');
        } catch (InvalidConfigurationException $e) {
            $this->assertStringContainsString(TestConstants::FIELD_USER_EMAIL, $e->getMessage());
            $this->assertStringContainsString('empty string', $e->getMessage());
        }
    }

    #[Test]
    public function validateFieldPathsPassesForValidPaths(): void
    {
        // Void validator: passing means not throwing. Declared explicitly rather than
        // faked with a vacuous assertion; rejection is covered by the matching negative tests.
        $this->expectNotToPerformAssertions();

        $ssnConfig = FieldMaskConfig::regexMask(
            TestConstants::PATTERN_SSN_FORMAT,
            MaskConstants::MASK_SSN_PATTERN
        );

        InputValidator::validateFieldPaths([
            TestConstants::FIELD_USER_EMAIL => MaskConstants::MASK_EMAIL_PATTERN,
            TestConstants::FIELD_USER_PASSWORD => FieldMaskConfig::remove(),
            TestConstants::FIELD_USER_SSN => $ssnConfig,
        ]);
    }

    #[Test]
    public function validateCustomCallbacksThrowsForNonStringPath(): void
    {
        // expectExceptionMessageIsOrContains() sets a single predicate, so chaining
        // two would enforce only the second. Assert both fragments explicitly.
        try {
            InputValidator::validateCustomCallbacks([123 => fn($v): string => (string) $v]);
            $this->fail('Expected InvalidConfigurationException');
        } catch (InvalidConfigurationException $e) {
            $this->assertStringContainsString('custom callback path', $e->getMessage());
            $this->assertStringContainsString('string', $e->getMessage());
        }
    }

    #[Test]
    public function validateCustomCallbacksThrowsForEmptyPath(): void
    {
        // expectExceptionMessageIsOrContains() sets a single predicate, so chaining
        // two would enforce only the second. Assert both fragments explicitly.
        try {
            InputValidator::validateCustomCallbacks(['' => fn($v): string => (string) $v]);
            $this->fail('Expected InvalidConfigurationException');
        } catch (InvalidConfigurationException $e) {
            $this->assertStringContainsString('custom callback path', $e->getMessage());
            $this->assertStringContainsString('empty', $e->getMessage());
        }
    }

    #[Test]
    public function validateCustomCallbacksThrowsForNonCallable(): void
    {
        // expectExceptionMessageIsOrContains() sets a single predicate, so chaining
        // two would enforce only the second. Assert both fragments explicitly.
        try {
            InputValidator::validateCustomCallbacks(['user.id' => 'not-a-callback']);
            $this->fail('Expected InvalidConfigurationException');
        } catch (InvalidConfigurationException $e) {
            $this->assertStringContainsString('custom callback', $e->getMessage());
            $this->assertStringContainsString('callable', $e->getMessage());
        }
    }

    #[Test]
    public function validateCustomCallbacksPassesForValidCallbacks(): void
    {
        // Void validator: passing means not throwing. Declared explicitly rather than
        // faked with a vacuous assertion; rejection is covered by the matching negative tests.
        $this->expectNotToPerformAssertions();

        InputValidator::validateCustomCallbacks([
            'user.id' => fn($value): string => (string) $value,
            TestConstants::FIELD_USER_NAME => fn($value) => strtoupper((string) $value),
        ]);
    }

    #[Test]
    public function validateAuditLoggerThrowsForNonCallable(): void
    {
        // expectExceptionMessageIsOrContains() sets a single predicate, so chaining
        // two would enforce only the second. Assert both fragments explicitly.
        try {
            InputValidator::validateAuditLogger('not-a-callback');
            $this->fail('Expected InvalidConfigurationException');
        } catch (InvalidConfigurationException $e) {
            $this->assertStringContainsString('audit logger', $e->getMessage());
            $this->assertStringContainsString('callable', $e->getMessage());
        }
    }

    #[Test]
    public function validateAuditLoggerPassesForNull(): void
    {
        // Void validator: passing means not throwing. Declared explicitly rather than
        // faked with a vacuous assertion; rejection is covered by the matching negative tests.
        $this->expectNotToPerformAssertions();

        InputValidator::validateAuditLogger(null);
    }

    #[Test]
    public function validateAuditLoggerPassesForCallable(): void
    {
        // Void validator: passing means not throwing. Declared explicitly rather than
        // faked with a vacuous assertion; rejection is covered by the matching negative tests.
        $this->expectNotToPerformAssertions();

        InputValidator::validateAuditLogger(
            static fn (string $field, mixed $old, mixed $new): null => null
        );
    }

    #[Test]
    public function validateMaxDepthThrowsForZero(): void
    {
        // expectExceptionMessageIsOrContains() sets a single predicate, so chaining
        // two would enforce only the second. Assert both fragments explicitly.
        try {
            InputValidator::validateMaxDepth(0);
            $this->fail('Expected InvalidConfigurationException');
        } catch (InvalidConfigurationException $e) {
            $this->assertStringContainsString('max_depth', $e->getMessage());
            $this->assertStringContainsString('positive integer', $e->getMessage());
        }
    }

    #[Test]
    public function validateMaxDepthThrowsForNegative(): void
    {
        // expectExceptionMessageIsOrContains() sets a single predicate, so chaining
        // two would enforce only the second. Assert both fragments explicitly.
        try {
            InputValidator::validateMaxDepth(-1);
            $this->fail('Expected InvalidConfigurationException');
        } catch (InvalidConfigurationException $e) {
            $this->assertStringContainsString('max_depth', $e->getMessage());
            $this->assertStringContainsString('positive integer', $e->getMessage());
        }
    }

    #[Test]
    public function validateMaxDepthThrowsForTooLarge(): void
    {
        // expectExceptionMessageIsOrContains() sets a single predicate, so chaining
        // two would enforce only the second. Assert both fragments explicitly.
        try {
            InputValidator::validateMaxDepth(1001);
            $this->fail('Expected InvalidConfigurationException');
        } catch (InvalidConfigurationException $e) {
            $this->assertStringContainsString('max_depth', $e->getMessage());
            $this->assertStringContainsString('1,000', $e->getMessage());
        }
    }

    #[Test]
    public function validateMaxDepthPassesForValidValue(): void
    {
        // Void validator: passing means not throwing. Declared explicitly rather than
        // faked with a vacuous assertion; rejection is covered by the matching negative tests.
        $this->expectNotToPerformAssertions();

        InputValidator::validateMaxDepth(10);
        InputValidator::validateMaxDepth(1);
        InputValidator::validateMaxDepth(1000);
    }

    #[Test]
    public function validateDataTypeMasksThrowsForNonStringType(): void
    {
        // expectExceptionMessageIsOrContains() sets a single predicate, so chaining
        // two would enforce only the second. Assert both fragments explicitly.
        try {
            InputValidator::validateDataTypeMasks([123 => MaskConstants::MASK_GENERIC]);
            $this->fail('Expected InvalidConfigurationException');
        } catch (InvalidConfigurationException $e) {
            $this->assertStringContainsString('data type mask key', $e->getMessage());
            $this->assertStringContainsString('string', $e->getMessage());
        }
    }

    #[Test]
    public function validateDataTypeMasksThrowsForInvalidType(): void
    {
        // expectExceptionMessageIsOrContains() sets a single predicate, so chaining
        // two would enforce only the second. Assert both fragments explicitly.
        try {
            InputValidator::validateDataTypeMasks(['invalid_type' => MaskConstants::MASK_GENERIC]);
            $this->fail('Expected InvalidConfigurationException');
        } catch (InvalidConfigurationException $e) {
            $this->assertStringContainsString('invalid_type', $e->getMessage());
            $this->assertStringContainsString('integer, double, string, boolean', $e->getMessage());
        }
    }

    #[Test]
    public function validateDataTypeMasksThrowsForNonStringMask(): void
    {
        // expectExceptionMessageIsOrContains() sets a single predicate, so chaining
        // two would enforce only the second. Assert both fragments explicitly.
        try {
            InputValidator::validateDataTypeMasks(['string' => 123]);
            $this->fail('Expected InvalidConfigurationException');
        } catch (InvalidConfigurationException $e) {
            $this->assertStringContainsString('data type mask value', $e->getMessage());
            $this->assertStringContainsString('string', $e->getMessage());
        }
    }

    #[Test]
    public function validateDataTypeMasksThrowsForEmptyMask(): void
    {
        // expectExceptionMessageIsOrContains() sets a single predicate, so chaining
        // two would enforce only the second. Assert both fragments explicitly.
        try {
            InputValidator::validateDataTypeMasks(['string' => '']);
            $this->fail('Expected InvalidConfigurationException');
        } catch (InvalidConfigurationException $e) {
            $this->assertStringContainsString('string', $e->getMessage());
            $this->assertStringContainsString('empty', $e->getMessage());
        }
    }

    #[Test]
    public function validateDataTypeMasksPassesForValidTypes(): void
    {
        // Void validator: passing means not throwing. Declared explicitly rather than
        // faked with a vacuous assertion; rejection is covered by the matching negative tests.
        $this->expectNotToPerformAssertions();

        InputValidator::validateDataTypeMasks([
            'integer' => MaskConstants::MASK_GENERIC,
            'double' => MaskConstants::MASK_GENERIC,
            'string' => TestConstants::MASK_REDACTED_PLAIN,
            'boolean' => MaskConstants::MASK_GENERIC,
            'NULL' => 'null',
            'array' => '[]',
            'object' => '{}',
            'resource' => 'RESOURCE',
        ]);
    }

    #[Test]
    public function validateConditionalRulesThrowsForNonStringRuleName(): void
    {
        // expectExceptionMessageIsOrContains() sets a single predicate, so chaining
        // two would enforce only the second. Assert both fragments explicitly.
        try {
            InputValidator::validateConditionalRules([123 => fn($v): true => true]);
            $this->fail('Expected InvalidConfigurationException');
        } catch (InvalidConfigurationException $e) {
            $this->assertStringContainsString('conditional rule name', $e->getMessage());
            $this->assertStringContainsString('string', $e->getMessage());
        }
    }

    #[Test]
    public function validateConditionalRulesThrowsForEmptyRuleName(): void
    {
        // expectExceptionMessageIsOrContains() sets a single predicate, so chaining
        // two would enforce only the second. Assert both fragments explicitly.
        try {
            InputValidator::validateConditionalRules(['' => fn($v): true => true]);
            $this->fail('Expected InvalidConfigurationException');
        } catch (InvalidConfigurationException $e) {
            $this->assertStringContainsString('conditional rule name', $e->getMessage());
            $this->assertStringContainsString('empty', $e->getMessage());
        }
    }

    #[Test]
    public function validateConditionalRulesThrowsForNonCallable(): void
    {
        // expectExceptionMessageIsOrContains() sets a single predicate, so chaining
        // two would enforce only the second. Assert both fragments explicitly.
        try {
            InputValidator::validateConditionalRules(['rule1' => 'not-a-callback']);
            $this->fail('Expected InvalidConfigurationException');
        } catch (InvalidConfigurationException $e) {
            $this->assertStringContainsString('rule1', $e->getMessage());
            $this->assertStringContainsString('callable', $e->getMessage());
        }
    }

    #[Test]
    public function validateConditionalRulesPassesForValidRules(): void
    {
        // Void validator: passing means not throwing. Declared explicitly rather than
        // faked with a vacuous assertion; rejection is covered by the matching negative tests.
        $this->expectNotToPerformAssertions();

        InputValidator::validateConditionalRules([
            'rule1' => fn($value): bool => $value > 100,
            'rule2' => is_string(...),
        ]);
    }
}
