<?php

declare(strict_types=1);

namespace Tests\Strategies;

use Tests\TestConstants;
use Ivuorinen\MonologGdprFilter\Exceptions\InvalidRegexPatternException;
use Ivuorinen\MonologGdprFilter\MaskConstants;
use Ivuorinen\MonologGdprFilter\Exceptions\MaskingOperationFailedException;
use Ivuorinen\MonologGdprFilter\FieldMaskConfig;
use Ivuorinen\MonologGdprFilter\Strategies\FieldPathMaskingStrategy;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\TestHelpers;

#[CoversClass(FieldPathMaskingStrategy::class)]
final class FieldPathMaskingStrategyTest extends TestCase
{
    use TestHelpers;

    private LogRecord $logRecord;

    #[\Override]
    protected function setUp(): void
    {
        $this->logRecord = $this->createLogRecord(
            TestConstants::MESSAGE_DEFAULT,
            ['user' => [TestConstants::CONTEXT_EMAIL => self::TEST_EMAIL]]
        );
    }

    #[Test]
    public function constructorAcceptsFieldConfigsArray(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            TestConstants::FIELD_USER_EMAIL => MaskConstants::MASK_EMAIL_PATTERN,
            TestConstants::FIELD_USER_PASSWORD => FieldMaskConfig::remove(),
        ]);

        $this->assertSame(80, $strategy->getPriority());
    }

    #[Test]
    public function constructorAcceptsCustomPriority(): void
    {
        $strategy = new FieldPathMaskingStrategy(
            [TestConstants::FIELD_USER_EMAIL => MaskConstants::MASK_GENERIC],
            priority: 90
        );

        $this->assertSame(90, $strategy->getPriority());
    }

    #[Test]
    public function getNameReturnsDescriptiveName(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            'field1' => MaskConstants::MASK_GENERIC,
            'field2' => MaskConstants::MASK_GENERIC,
            'field3' => MaskConstants::MASK_GENERIC,
        ]);

        $this->assertSame('Field Path Masking (3 fields)', $strategy->getName());
    }

    #[Test]
    public function shouldApplyReturnsTrueForExactPathMatch(): void
    {
        $config = [TestConstants::FIELD_USER_EMAIL => MaskConstants::MASK_GENERIC];
        $strategy = new FieldPathMaskingStrategy($config);

        $this->assertTrue($strategy->shouldApply(
            TestConstants::EMAIL_TEST,
            TestConstants::FIELD_USER_EMAIL,
            $this->logRecord
        ));
    }

    #[Test]
    public function shouldApplyReturnsFalseForNonMatchingPath(): void
    {
        $config = [TestConstants::FIELD_USER_EMAIL => MaskConstants::MASK_GENERIC];
        $strategy = new FieldPathMaskingStrategy($config);

        $this->assertFalse($strategy->shouldApply(
            TestConstants::CONTEXT_PASSWORD,
            TestConstants::FIELD_USER_PASSWORD,
            $this->logRecord
        ));
    }

    #[Test]
    public function shouldApplySupportsWildcardPatterns(): void
    {
        $config = [TestConstants::PATH_USER_WILDCARD => MaskConstants::MASK_GENERIC];
        $strategy = new FieldPathMaskingStrategy($config);

        $this->assertTrue($strategy->shouldApply(
            TestConstants::EMAIL_TEST,
            TestConstants::FIELD_USER_EMAIL,
            $this->logRecord
        ));
        $this->assertTrue($strategy->shouldApply(
            TestConstants::CONTEXT_PASSWORD,
            TestConstants::FIELD_USER_PASSWORD,
            $this->logRecord
        ));
    }

    #[Test]
    public function maskAppliesStringReplacement(): void
    {
        $config = [TestConstants::FIELD_USER_EMAIL => MaskConstants::MASK_EMAIL_PATTERN];
        $strategy = new FieldPathMaskingStrategy($config);

        $result = $strategy->mask(TestConstants::EMAIL_TEST, TestConstants::FIELD_USER_EMAIL, $this->logRecord);

        $this->assertSame(MaskConstants::MASK_EMAIL_PATTERN, $result);
    }

    #[Test]
    public function maskAppliesRemovalConfig(): void
    {
        $strategy = new FieldPathMaskingStrategy([TestConstants::FIELD_USER_PASSWORD => FieldMaskConfig::remove()]);

        $result = $strategy->mask('secretpass', TestConstants::FIELD_USER_PASSWORD, $this->logRecord);

        $this->assertNull($result);
    }

    #[Test]
    public function maskAppliesRegexReplacement(): void
    {
        $ssnConfig = FieldMaskConfig::regexMask(
            TestConstants::PATTERN_SSN_FORMAT,
            MaskConstants::MASK_SSN_PATTERN
        );
        $strategy = new FieldPathMaskingStrategy(['user.ssn' => $ssnConfig]);

        $result = $strategy->mask(TestConstants::SSN_US, 'user.ssn', $this->logRecord);

        $this->assertSame(MaskConstants::MASK_SSN_PATTERN, $result);
    }

    #[Test]
    public function maskAppliesStaticReplacementFromConfig(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            TestConstants::FIELD_USER_NAME => FieldMaskConfig::replace('[REDACTED]'),
        ]);

        $result = $strategy->mask(TestConstants::NAME_FULL, TestConstants::FIELD_USER_NAME, $this->logRecord);

        $this->assertSame('[REDACTED]', $result);
    }

    #[Test]
    public function maskPreservesIntegerTypeWhenPossible(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            'user.age' => FieldMaskConfig::replace('0'),
        ]);

        $result = $strategy->mask(25, 'user.age', $this->logRecord);

        $this->assertSame(0, $result);
        $this->assertIsInt($result);
    }

    #[Test]
    public function maskPreservesFloatTypeWhenPossible(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            'user.salary' => FieldMaskConfig::replace('0.0'),
        ]);

        $result = $strategy->mask(50000.50, 'user.salary', $this->logRecord);

        $this->assertSame(0.0, $result);
        $this->assertIsFloat($result);
    }

    #[Test]
    public function maskPreservesBooleanType(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            'user.active' => FieldMaskConfig::replace('false'),
        ]);

        $result = $strategy->mask(true, 'user.active', $this->logRecord);

        $this->assertFalse($result);
        $this->assertIsBool($result);
    }

    #[Test]
    public function maskReturnsOriginalValueWhenNoMatchingPath(): void
    {
        $strategy = new FieldPathMaskingStrategy(['other.field' => MaskConstants::MASK_GENERIC]);

        $result = $strategy->mask('original', TestConstants::FIELD_USER_EMAIL, $this->logRecord);

        $this->assertSame('original', $result);
    }

    #[Test]
    public function maskThrowsExceptionOnRegexError(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            'user.field' => FieldMaskConfig::regexMask('/valid/', MaskConstants::MASK_BRACKETS),
        ]);

        // Create a resource which cannot be converted to string
        $resource = fopen('php://memory', 'r');
        $this->assertIsResource($resource, 'Failed to open php://memory');

        $this->expectException(MaskingOperationFailedException::class);
        $this->expectExceptionMessage('user.field');

        try {
            $strategy->mask($resource, 'user.field', $this->logRecord);
        } finally {
            fclose($resource);
        }
    }

    #[Test]
    public function validateReturnsTrueForValidConfiguration(): void
    {
        $ssnConfig = FieldMaskConfig::regexMask(
            TestConstants::PATTERN_SSN_FORMAT,
            MaskConstants::MASK_SSN_PATTERN
        );

        $strategy = new FieldPathMaskingStrategy([
            TestConstants::FIELD_USER_EMAIL => MaskConstants::MASK_EMAIL_PATTERN,
            TestConstants::FIELD_USER_PASSWORD => FieldMaskConfig::remove(),
            'user.ssn' => $ssnConfig,
        ]);

        $this->assertTrue($strategy->validate());
    }

    #[Test]
    public function validateReturnsFalseForEmptyConfigs(): void
    {
        $strategy = new FieldPathMaskingStrategy([]);

        $this->assertFalse($strategy->validate());
    }

    #[Test]
    public function validateReturnsFalseForNonStringPath(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            123 => MaskConstants::MASK_GENERIC,
        ]);

        $this->assertFalse($strategy->validate());
    }

    #[Test]
    public function validateReturnsFalseForEmptyStringPath(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            '' => MaskConstants::MASK_GENERIC,
        ]);

        $this->assertFalse($strategy->validate());
    }

    #[Test]
    public function validateReturnsFalseForInvalidConfigType(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            'user.field' => 123,
        ]);

        $this->assertFalse($strategy->validate());
    }

    #[Test]
    public function validateReturnsFalseForInvalidRegexPattern(): void
    {
        $this->expectException(InvalidRegexPatternException::class);

        $strategy = new FieldPathMaskingStrategy([
            'user.field' => FieldMaskConfig::regexMask('/[invalid/', MaskConstants::MASK_GENERIC),
        ]);
        unset($strategy); // Satisfy SonarQube - this line won't be reached if exception is thrown
        $this->fail(TestConstants::ERROR_EXCEPTION_NOT_THROWN);
    }

    #[Test]
    public function getConfigurationReturnsFieldConfigsArray(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            TestConstants::FIELD_USER_EMAIL => MaskConstants::MASK_EMAIL_PATTERN,
        ]);

        $config = $strategy->getConfiguration();

        $this->assertArrayHasKey('field_configs', $config);
        $this->assertArrayHasKey(TestConstants::FIELD_USER_EMAIL, $config['field_configs']);
    }

    #[Test]
    public function maskHandlesComplexRegexPatterns(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            'data' => FieldMaskConfig::regexMask(
                TestConstants::PATTERN_EMAIL_FULL,
                MaskConstants::MASK_EMAIL_PATTERN
            ),
        ]);

        $input = 'Contact us at support@example.com for help';
        $result = $strategy->mask($input, 'data', $this->logRecord);

        $this->assertStringContainsString(MaskConstants::MASK_EMAIL_PATTERN, $result);
        $this->assertStringNotContainsString('support@example.com', $result);
    }

    #[Test]
    public function maskHandlesMultipleReplacementsInSameValue(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            'message' => FieldMaskConfig::regexMask(TestConstants::PATTERN_SSN_FORMAT, MaskConstants::MASK_SSN_PATTERN),
        ]);

        $input = 'SSNs: 123-45-6789 and 987-65-4321';
        $result = $strategy->mask($input, TestConstants::FIELD_MESSAGE, $this->logRecord);

        $this->assertSame('SSNs: ***-**-**** and ' . MaskConstants::MASK_SSN_PATTERN, $result);
    }
}
