<?php

declare(strict_types=1);

namespace Tests\Strategies;

use Ivuorinen\MonologGdprFilter\FieldMaskConfig;
use Ivuorinen\MonologGdprFilter\MaskConstants as Mask;
use Ivuorinen\MonologGdprFilter\Exceptions\MaskingOperationFailedException;
use Ivuorinen\MonologGdprFilter\Strategies\FieldPathMaskingStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\TestConstants;
use Tests\TestHelpers;

#[CoversClass(FieldPathMaskingStrategy::class)]
final class FieldPathMaskingStrategyEnhancedTest extends TestCase
{
    use TestHelpers;

    public function testMaskWithNullPatternThrowsException(): void
    {
        // Create a config with useProcessorPatterns which has null pattern
        $strategy = new FieldPathMaskingStrategy([
            'field' => FieldMaskConfig::useProcessorPatterns(),
        ]);

        $record = $this->createLogRecord('Test');

        $this->expectException(MaskingOperationFailedException::class);
        $this->expectExceptionMessage('Regex pattern is null');

        $strategy->mask('test value', 'field', $record);
    }

    public function testApplyStaticReplacementWithNullReplacement(): void
    {
        // Create a FieldMaskConfig with null replacement
        $config = new FieldMaskConfig(FieldMaskConfig::REPLACE, null);

        $strategy = new FieldPathMaskingStrategy([
            'field' => $config,
        ]);

        $record = $this->createLogRecord('Test');

        // Should return original value when replacement is null
        $result = $strategy->mask('original', 'field', $record);

        $this->assertSame('original', $result);
    }

    public function testApplyStaticReplacementPreservesStringTypeWhenNotNumeric(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            'field' => FieldMaskConfig::replace(Mask::MASK_REDACTED),
        ]);

        $record = $this->createLogRecord('Test');

        // For non-numeric replacement with numeric value, should return string
        $result = $strategy->mask(123, 'field', $record);

        $this->assertSame(Mask::MASK_REDACTED, $result);
        $this->assertIsString($result);
    }

    public function testApplyStaticReplacementWithFloatValue(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            'field' => FieldMaskConfig::replace('3.14'),
        ]);

        $record = $this->createLogRecord('Test');

        $result = $strategy->mask(2.71, 'field', $record);

        $this->assertSame(3.14, $result);
        $this->assertIsFloat($result);
    }

    public function testValidateReturnsFalseForZeroStringPath(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            '0' => Mask::MASK_GENERIC,
        ]);

        $this->assertFalse($strategy->validate());
    }

    public function testValidateWithFieldMaskConfigWithoutRegex(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            'field' => FieldMaskConfig::remove(),
        ]);

        $this->assertTrue($strategy->validate());
    }

    public function testValidateWithFieldMaskConfigWithValidRegex(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            'field' => FieldMaskConfig::regexMask(TestConstants::PATTERN_DIGITS, 'NUM'),
        ]);

        $this->assertTrue($strategy->validate());
    }

    public function testRegexMaskingWithNullReplacement(): void
    {
        // Create a regex mask config and test default replacement
        $strategy = new FieldPathMaskingStrategy([
            'field' => FieldMaskConfig::regexMask(TestConstants::PATTERN_DIGITS),
        ]);

        $record = $this->createLogRecord('Test');

        $result = $strategy->mask('test 123 value', 'field', $record);

        // Should use default MASK_MASKED when replacement is null
        $this->assertStringContainsString(Mask::MASK_MASKED, $result);
    }

    public function testRegexMaskingPreservesValueType(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            'field' => FieldMaskConfig::regexMask(TestConstants::PATTERN_DIGITS, '999'),
        ]);

        $record = $this->createLogRecord('Test');

        // When original value is string, result should be string
        $result = $strategy->mask('number: 123', 'field', $record);

        $this->assertIsString($result);
        $this->assertStringContainsString('999', $result);
    }

    public function testRegexMaskingHandlesNumericValue(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            'field' => FieldMaskConfig::regexMask(TestConstants::PATTERN_DIGITS, 'NUM'),
        ]);

        $record = $this->createLogRecord('Test');

        // Test with a numeric value that gets converted to string
        $result = $strategy->mask(12345, 'field', $record);

        // Should convert number to string, apply regex, and preserve type
        $this->assertSame('NUM', $result);
    }

    public function testMaskCatchesAndRethrowsMaskingOperationFailedException(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            'field' => FieldMaskConfig::useProcessorPatterns(),
        ]);

        $record = $this->createLogRecord('Test');

        try {
            $strategy->mask('test', 'field', $record);
            $this->fail('Expected MaskingOperationFailedException to be thrown');
        } catch (MaskingOperationFailedException $e) {
            // Exception should contain the field path
            $this->assertStringContainsString('field', $e->getMessage());
            // Exception should be the same type
            $this->assertInstanceOf(MaskingOperationFailedException::class, $e);
        }
    }

    public function testGetConfigForPathWithPatternMatch(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            TestConstants::PATH_USER_WILDCARD => Mask::MASK_GENERIC,
        ]);

        $record = $this->createLogRecord('Test');

        // Pattern should match
        $this->assertTrue($strategy->shouldApply('value', TestConstants::FIELD_USER_EMAIL, $record));
        $this->assertTrue($strategy->shouldApply('value', TestConstants::FIELD_USER_PASSWORD, $record));
        $this->assertTrue($strategy->shouldApply('value', TestConstants::FIELD_USER_NAME, $record));
    }

    public function testGetConfigForPathExactMatchTakesPrecedenceOverPattern(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            TestConstants::PATH_USER_WILDCARD => 'PATTERN',
            TestConstants::FIELD_USER_EMAIL => 'EXACT',
        ]);

        $record = $this->createLogRecord('Test');

        // Exact match should take precedence
        $result = $strategy->mask(TestConstants::EMAIL_TEST, TestConstants::FIELD_USER_EMAIL, $record);
        $this->assertSame('EXACT', $result);

        // Pattern should still match other paths
        $result = $strategy->mask(TestConstants::CONTEXT_PASSWORD, TestConstants::FIELD_USER_PASSWORD, $record);
        $this->assertSame('PATTERN', $result);
    }

    public function testApplyFieldConfigWithStringConfig(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            'field' => 'SIMPLE_REPLACEMENT',
        ]);

        $record = $this->createLogRecord('Test');

        $result = $strategy->mask('original value', 'field', $record);

        $this->assertSame('SIMPLE_REPLACEMENT', $result);
    }

    public function testGetNameWithEmptyConfigs(): void
    {
        $strategy = new FieldPathMaskingStrategy([]);

        $this->assertSame('Field Path Masking (0 fields)', $strategy->getName());
    }

    public function testGetNameWithSingleConfig(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            'field' => 'VALUE',
        ]);

        $this->assertSame('Field Path Masking (1 fields)', $strategy->getName());
    }

    public function testBooleanReplacementWithTruthyString(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            'active' => FieldMaskConfig::replace('1'),
        ]);

        $record = $this->createLogRecord('Test');

        $result = $strategy->mask(false, 'active', $record);

        // filter_var with FILTER_VALIDATE_BOOLEAN treats '1' as true
        $this->assertTrue($result);
        $this->assertIsBool($result);
    }

    public function testIntegerReplacementWithNonNumericString(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            'count' => FieldMaskConfig::replace('NOT_A_NUMBER'),
        ]);

        $record = $this->createLogRecord('Test');

        $result = $strategy->mask(42, 'count', $record);

        // Should return string when replacement is not numeric
        $this->assertSame('NOT_A_NUMBER', $result);
        $this->assertIsString($result);
    }
}
