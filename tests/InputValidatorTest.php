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
        $auditLogger = fn($field, $old, $new): null => null;
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
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('pattern');
        $this->expectExceptionMessageIsOrContains('string');

        InputValidator::validatePatterns([123 => MaskConstants::MASK_GENERIC]);
    }

    #[Test]
    public function validatePatternsThrowsForEmptyPattern(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('pattern');
        $this->expectExceptionMessageIsOrContains('empty');

        InputValidator::validatePatterns(['' => MaskConstants::MASK_GENERIC]);
    }

    #[Test]
    public function validatePatternsThrowsForNonStringReplacement(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('replacement');
        $this->expectExceptionMessageIsOrContains('string');

        InputValidator::validatePatterns([TestConstants::PATTERN_TEST => 123]);
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
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('field path');
        $this->expectExceptionMessageIsOrContains('string');

        InputValidator::validateFieldPaths([123 => MaskConstants::MASK_GENERIC]);
    }

    #[Test]
    public function validateFieldPathsThrowsForEmptyPath(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('field path');
        $this->expectExceptionMessageIsOrContains('empty');

        InputValidator::validateFieldPaths(['' => MaskConstants::MASK_GENERIC]);
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
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains(TestConstants::FIELD_USER_EMAIL);
        $this->expectExceptionMessageIsOrContains('empty string');

        InputValidator::validateFieldPaths([TestConstants::FIELD_USER_EMAIL => '']);
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
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('custom callback path');
        $this->expectExceptionMessageIsOrContains('string');

        InputValidator::validateCustomCallbacks([123 => fn($v): string => (string) $v]);
    }

    #[Test]
    public function validateCustomCallbacksThrowsForEmptyPath(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('custom callback path');
        $this->expectExceptionMessageIsOrContains('empty');

        InputValidator::validateCustomCallbacks(['' => fn($v): string => (string) $v]);
    }

    #[Test]
    public function validateCustomCallbacksThrowsForNonCallable(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('custom callback');
        $this->expectExceptionMessageIsOrContains('callable');

        InputValidator::validateCustomCallbacks(['user.id' => 'not-a-callback']);
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
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('audit logger');
        $this->expectExceptionMessageIsOrContains('callable');

        InputValidator::validateAuditLogger('not-a-callback');
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

        InputValidator::validateAuditLogger(fn($field, $old, $new): null => null);
    }

    #[Test]
    public function validateMaxDepthThrowsForZero(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('max_depth');
        $this->expectExceptionMessageIsOrContains('positive integer');

        InputValidator::validateMaxDepth(0);
    }

    #[Test]
    public function validateMaxDepthThrowsForNegative(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('max_depth');
        $this->expectExceptionMessageIsOrContains('positive integer');

        InputValidator::validateMaxDepth(-1);
    }

    #[Test]
    public function validateMaxDepthThrowsForTooLarge(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('max_depth');
        $this->expectExceptionMessageIsOrContains('1,000');

        InputValidator::validateMaxDepth(1001);
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
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('data type mask key');
        $this->expectExceptionMessageIsOrContains('string');

        InputValidator::validateDataTypeMasks([123 => MaskConstants::MASK_GENERIC]);
    }

    #[Test]
    public function validateDataTypeMasksThrowsForInvalidType(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('invalid_type');
        $this->expectExceptionMessageIsOrContains('integer, double, string, boolean');

        InputValidator::validateDataTypeMasks(['invalid_type' => MaskConstants::MASK_GENERIC]);
    }

    #[Test]
    public function validateDataTypeMasksThrowsForNonStringMask(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('data type mask value');
        $this->expectExceptionMessageIsOrContains('string');

        InputValidator::validateDataTypeMasks(['string' => 123]);
    }

    #[Test]
    public function validateDataTypeMasksThrowsForEmptyMask(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('string');
        $this->expectExceptionMessageIsOrContains('empty');

        InputValidator::validateDataTypeMasks(['string' => '']);
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
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('conditional rule name');
        $this->expectExceptionMessageIsOrContains('string');

        InputValidator::validateConditionalRules([123 => fn($v): true => true]);
    }

    #[Test]
    public function validateConditionalRulesThrowsForEmptyRuleName(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('conditional rule name');
        $this->expectExceptionMessageIsOrContains('empty');

        InputValidator::validateConditionalRules(['' => fn($v): true => true]);
    }

    #[Test]
    public function validateConditionalRulesThrowsForNonCallable(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('rule1');
        $this->expectExceptionMessageIsOrContains('callable');

        InputValidator::validateConditionalRules(['rule1' => 'not-a-callback']);
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
