<?php

declare(strict_types=1);

namespace Tests\Strategies;

use Ivuorinen\MonologGdprFilter\Exceptions\InvalidRegexPatternException;
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
            'Test message',
            ['user' => ['email' => self::TEST_EMAIL]]
        );
    }

    #[Test]
    public function constructorAcceptsFieldConfigsArray(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            'user.email' => '***@***.***',
            'user.password' => FieldMaskConfig::remove(),
        ]);

        $this->assertSame(80, $strategy->getPriority());
    }

    #[Test]
    public function constructorAcceptsCustomPriority(): void
    {
        $strategy = new FieldPathMaskingStrategy(
            ['user.email' => '***'],
            priority: 90
        );

        $this->assertSame(90, $strategy->getPriority());
    }

    #[Test]
    public function getNameReturnsDescriptiveName(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            'field1' => '***',
            'field2' => '***',
            'field3' => '***',
        ]);

        $this->assertSame('Field Path Masking (3 fields)', $strategy->getName());
    }

    #[Test]
    public function shouldApplyReturnsTrueForExactPathMatch(): void
    {
        $strategy = new FieldPathMaskingStrategy(['user.email' => '***']);

        $this->assertTrue($strategy->shouldApply('test@example.com', 'user.email', $this->logRecord));
    }

    #[Test]
    public function shouldApplyReturnsFalseForNonMatchingPath(): void
    {
        $strategy = new FieldPathMaskingStrategy(['user.email' => '***']);

        $this->assertFalse($strategy->shouldApply('password', 'user.password', $this->logRecord));
    }

    #[Test]
    public function shouldApplySupportsWildcardPatterns(): void
    {
        $strategy = new FieldPathMaskingStrategy(['user.*' => '***']);

        $this->assertTrue($strategy->shouldApply('test@example.com', 'user.email', $this->logRecord));
        $this->assertTrue($strategy->shouldApply('password', 'user.password', $this->logRecord));
    }

    #[Test]
    public function maskAppliesStringReplacement(): void
    {
        $strategy = new FieldPathMaskingStrategy(['user.email' => '***@***.***']);

        $result = $strategy->mask('test@example.com', 'user.email', $this->logRecord);

        $this->assertSame('***@***.***', $result);
    }

    #[Test]
    public function maskAppliesRemovalConfig(): void
    {
        $strategy = new FieldPathMaskingStrategy(['user.password' => FieldMaskConfig::remove()]);

        $result = $strategy->mask('secretpass', 'user.password', $this->logRecord);

        $this->assertNull($result);
    }

    #[Test]
    public function maskAppliesRegexReplacement(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            'user.ssn' => FieldMaskConfig::regexMask('/\d{3}-\d{2}-\d{4}/', '***-**-****'),
        ]);

        $result = $strategy->mask('123-45-6789', 'user.ssn', $this->logRecord);

        $this->assertSame('***-**-****', $result);
    }

    #[Test]
    public function maskAppliesStaticReplacementFromConfig(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            'user.name' => FieldMaskConfig::replace('[REDACTED]'),
        ]);

        $result = $strategy->mask('John Doe', 'user.name', $this->logRecord);

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
        $strategy = new FieldPathMaskingStrategy(['other.field' => '***']);

        $result = $strategy->mask('original', 'user.email', $this->logRecord);

        $this->assertSame('original', $result);
    }

    #[Test]
    public function maskThrowsExceptionOnRegexError(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            'user.field' => FieldMaskConfig::regexMask('/valid/', '[MASKED]'),
        ]);

        // Create a resource which cannot be converted to string
        $resource = fopen('php://memory', 'r');

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
        $strategy = new FieldPathMaskingStrategy([
            'user.email' => '***@***.***',
            'user.password' => FieldMaskConfig::remove(),
            'user.ssn' => FieldMaskConfig::regexMask('/\d{3}-\d{2}-\d{4}/', '***-**-****'),
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
            123 => '***',
        ]);

        $this->assertFalse($strategy->validate());
    }

    #[Test]
    public function validateReturnsFalseForEmptyStringPath(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            '' => '***',
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

        new FieldPathMaskingStrategy([
            'user.field' => FieldMaskConfig::regexMask('/[invalid/', '***'),
        ]);
    }

    #[Test]
    public function getConfigurationReturnsFieldConfigsArray(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            'user.email' => '***@***.***',
        ]);

        $config = $strategy->getConfiguration();

        $this->assertArrayHasKey('field_configs', $config);
        $this->assertArrayHasKey('user.email', $config['field_configs']);
    }

    #[Test]
    public function maskHandlesComplexRegexPatterns(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            'data' => FieldMaskConfig::regexMask(
                '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/',
                '***@***.***'
            ),
        ]);

        $input = 'Contact us at support@example.com for help';
        $result = $strategy->mask($input, 'data', $this->logRecord);

        $this->assertStringContainsString('***@***.***', $result);
        $this->assertStringNotContainsString('support@example.com', $result);
    }

    #[Test]
    public function maskHandlesMultipleReplacementsInSameValue(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            'message' => FieldMaskConfig::regexMask('/\d{3}-\d{2}-\d{4}/', '***-**-****'),
        ]);

        $input = 'SSNs: 123-45-6789 and 987-65-4321';
        $result = $strategy->mask($input, 'message', $this->logRecord);

        $this->assertSame('SSNs: ***-**-**** and ***-**-****', $result);
    }
}
