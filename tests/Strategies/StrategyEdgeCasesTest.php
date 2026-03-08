<?php

declare(strict_types=1);

namespace Tests\Strategies;

use DateTimeImmutable;
use Ivuorinen\MonologGdprFilter\Exceptions\InvalidRegexPatternException;
use Ivuorinen\MonologGdprFilter\FieldMaskConfig;
use Ivuorinen\MonologGdprFilter\MaskConstants;
use Ivuorinen\MonologGdprFilter\Strategies\DataTypeMaskingStrategy;
use Ivuorinen\MonologGdprFilter\Strategies\FieldPathMaskingStrategy;
use Ivuorinen\MonologGdprFilter\Strategies\RegexMaskingStrategy;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\TestConstants;

/**
 * Edge case tests for masking strategies to improve coverage.
 */
#[CoversClass(RegexMaskingStrategy::class)]
#[CoversClass(DataTypeMaskingStrategy::class)]
#[CoversClass(FieldPathMaskingStrategy::class)]
final class StrategyEdgeCasesTest extends TestCase
{
    private LogRecord $logRecord;

    #[\Override]
    protected function setUp(): void
    {
        $this->logRecord = new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: TestConstants::MESSAGE_DEFAULT,
            context: [],
        );
    }

    // ========================================
    // RegexMaskingStrategy ReDoS Detection
    // ========================================

    #[Test]
    #[DataProvider('redosPatternProvider')]
    public function regexStrategyDetectsReDoSPatterns(string $pattern): void
    {
        $this->expectException(InvalidRegexPatternException::class);
        $this->expectExceptionMessage('catastrophic backtracking');

        $strategy = new RegexMaskingStrategy([$pattern => MaskConstants::MASK_GENERIC]);
        $this->assertInstanceOf(RegexMaskingStrategy::class, $strategy);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function redosPatternProvider(): array
    {
        return [
            'nested plus quantifier' => [TestConstants::PATTERN_REDOS_VULNERABLE],
            'nested star quantifier' => [TestConstants::PATTERN_REDOS_NESTED_STAR],
            'plus with repetition' => ['/^(a+){1,10}$/'],
            'star with repetition' => ['/^(a*){1,10}$/'],
            'identical alternation with star' => ['/(.*|.*)x/'],
            'identical alternation with plus' => ['/(.+|.+)x/'],
            'multiple overlapping alternations with star' => ['/(ab|bc|cd)*y/'],
            'multiple overlapping alternations with plus' => ['/(ab|bc|cd)+y/'],
        ];
    }

    #[Test]
    public function regexStrategySafePatternsPasses(): void
    {
        $strategy = new RegexMaskingStrategy([
            TestConstants::PATTERN_SSN_FORMAT => TestConstants::MASK_SSN_BRACKETS,
            TestConstants::PATTERN_EMAIL_SIMPLE => TestConstants::MASK_EMAIL_BRACKETS,
            TestConstants::PATTERN_CREDIT_CARD => TestConstants::MASK_CARD_BRACKETS,
        ]);

        $this->assertInstanceOf(RegexMaskingStrategy::class, $strategy);
        $this->assertTrue($strategy->validate());
    }

    #[Test]
    public function regexStrategyHandlesErrorInHasPatternMatches(): void
    {
        $strategy = new RegexMaskingStrategy([
            '/simple/' => MaskConstants::MASK_GENERIC,
        ]);

        $result = $strategy->shouldApply('no match here', 'field', $this->logRecord);
        $this->assertFalse($result);
    }

    // ========================================
    // DataTypeMaskingStrategy Edge Cases
    // ========================================

    #[Test]
    public function dataTypeStrategyParseArrayMaskWithEmptyString(): void
    {
        $strategy = new DataTypeMaskingStrategy(['array' => '']);

        $result = $strategy->mask(['original'], 'field', $this->logRecord);

        $this->assertSame([], $result);
    }

    #[Test]
    public function dataTypeStrategyParseArrayMaskWithInvalidJson(): void
    {
        $strategy = new DataTypeMaskingStrategy(['array' => '[invalid json']);

        $result = $strategy->mask(['original'], 'field', $this->logRecord);

        $this->assertIsArray($result);
        $this->assertSame(['invalid json'], $result);
    }

    #[Test]
    public function dataTypeStrategyParseArrayMaskWithNonArrayJson(): void
    {
        $strategy = new DataTypeMaskingStrategy(['array' => '["test"]']);

        $result = $strategy->mask(['original'], 'field', $this->logRecord);

        $this->assertIsArray($result);
        $this->assertSame(['test'], $result);
    }

    #[Test]
    public function dataTypeStrategyParseObjectMaskWithEmptyString(): void
    {
        $strategy = new DataTypeMaskingStrategy(['object' => '']);

        $obj = (object) ['key' => 'value'];
        $result = $strategy->mask($obj, 'field', $this->logRecord);

        $this->assertIsObject($result);
        $this->assertEquals((object) [], $result);
    }

    #[Test]
    public function dataTypeStrategyParseObjectMaskWithInvalidJson(): void
    {
        $strategy = new DataTypeMaskingStrategy(['object' => '{invalid json']);

        $obj = (object) ['key' => 'value'];
        $result = $strategy->mask($obj, 'field', $this->logRecord);

        $this->assertIsObject($result);
        $this->assertEquals((object) [TestConstants::DATA_MASKED => '{invalid json'], $result);
    }

    #[Test]
    public function dataTypeStrategyParseObjectMaskWithNonObjectJson(): void
    {
        $strategy = new DataTypeMaskingStrategy(['object' => '["array"]']);

        $obj = (object) ['key' => 'value'];
        $result = $strategy->mask($obj, 'field', $this->logRecord);

        $this->assertIsObject($result);
        $this->assertEquals((object) [TestConstants::DATA_MASKED => '["array"]'], $result);
    }

    #[Test]
    public function dataTypeStrategyHandlesResourceTypeUnmapped(): void
    {
        $strategy = new DataTypeMaskingStrategy(['string' => 'MASKED']);

        $resource = fopen('php://memory', 'r');
        $this->assertIsResource($resource);

        $result = $strategy->shouldApply($resource, 'field', $this->logRecord);
        $this->assertFalse($result);

        fclose($resource);
    }

    #[Test]
    public function dataTypeStrategyHandlesResourceTypeMapped(): void
    {
        $strategy = new DataTypeMaskingStrategy(['resource' => 'RESOURCE_MASKED']);

        $resource = fopen('php://memory', 'r');
        $this->assertIsResource($resource);

        $result = $strategy->shouldApply($resource, 'field', $this->logRecord);
        $this->assertTrue($result);

        fclose($resource);
    }

    #[Test]
    public function dataTypeStrategyValidateWithResourceType(): void
    {
        $strategy = new DataTypeMaskingStrategy(['resource' => 'MASKED']);

        $this->assertTrue($strategy->validate());
    }

    #[Test]
    public function dataTypeStrategyHandlesDoubleNonNumericMask(): void
    {
        $strategy = new DataTypeMaskingStrategy(['double' => 'NOT_A_NUMBER']);

        $result = $strategy->mask(123.45, 'field', $this->logRecord);

        $this->assertSame('NOT_A_NUMBER', $result);
    }

    #[Test]
    public function dataTypeStrategyHandlesIntegerNonNumericMask(): void
    {
        $strategy = new DataTypeMaskingStrategy(['integer' => 'NOT_A_NUMBER']);

        $result = $strategy->mask(123, 'field', $this->logRecord);

        $this->assertSame('NOT_A_NUMBER', $result);
    }

    // ========================================
    // FieldPathMaskingStrategy Edge Cases
    // ========================================

    #[Test]
    public function fieldPathStrategyValidateWithEmptyConfigs(): void
    {
        $strategy = new FieldPathMaskingStrategy([]);

        $this->assertFalse($strategy->validate());
    }

    #[Test]
    public function fieldPathStrategyValidateWithEmptyPath(): void
    {
        $strategy = new FieldPathMaskingStrategy(['' => MaskConstants::MASK_GENERIC]);

        $this->assertFalse($strategy->validate());
    }

    #[Test]
    public function fieldPathStrategyValidateWithZeroPath(): void
    {
        $strategy = new FieldPathMaskingStrategy(['0' => MaskConstants::MASK_GENERIC]);

        $this->assertFalse($strategy->validate());
    }

    #[Test]
    public function fieldPathStrategyValidateWithValidStringConfig(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            TestConstants::FIELD_USER_EMAIL => MaskConstants::MASK_EMAIL_PATTERN,
        ]);

        $this->assertTrue($strategy->validate());
    }

    #[Test]
    public function fieldPathStrategyValidateWithFieldMaskConfig(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            TestConstants::FIELD_USER_EMAIL => FieldMaskConfig::replace(MaskConstants::MASK_EMAIL_PATTERN),
            TestConstants::FIELD_USER_SSN => FieldMaskConfig::remove(),
        ]);

        $this->assertTrue($strategy->validate());
    }

    #[Test]
    public function fieldPathStrategyValidateWithValidRegexConfig(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            TestConstants::FIELD_USER_DATA => FieldMaskConfig::regexMask(TestConstants::PATTERN_DIGITS, MaskConstants::MASK_BRACKETS),
        ]);

        $this->assertTrue($strategy->validate());
    }

    #[Test]
    public function fieldPathStrategyApplyStaticReplacementPreservesIntType(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            'user.age' => FieldMaskConfig::replace('999'),
        ]);

        $result = $strategy->mask(25, 'user.age', $this->logRecord);

        $this->assertSame(999, $result);
        $this->assertIsInt($result);
    }

    #[Test]
    public function fieldPathStrategyApplyStaticReplacementPreservesFloatType(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            'price' => FieldMaskConfig::replace('99.99'),
        ]);

        $result = $strategy->mask(123.45, 'price', $this->logRecord);

        $this->assertSame(99.99, $result);
        $this->assertIsFloat($result);
    }

    #[Test]
    public function fieldPathStrategyApplyStaticReplacementPreservesBoolType(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            'active' => FieldMaskConfig::replace('false'),
        ]);

        $result = $strategy->mask(true, 'active', $this->logRecord);

        $this->assertFalse($result);
        $this->assertIsBool($result);
    }

    #[Test]
    public function fieldPathStrategyApplyStaticReplacementWithNonNumericForInt(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            'user.count' => FieldMaskConfig::replace('NOT_NUMERIC'),
        ]);

        $result = $strategy->mask(42, 'user.count', $this->logRecord);

        $this->assertSame('NOT_NUMERIC', $result);
    }

    #[Test]
    public function fieldPathStrategyShouldApplyReturnsFalseForMissingPath(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            TestConstants::FIELD_USER_EMAIL => MaskConstants::MASK_EMAIL_PATTERN,
        ]);

        $this->assertFalse($strategy->shouldApply('value', 'other.path', $this->logRecord));
    }

    #[Test]
    public function fieldPathStrategyShouldApplyReturnsTrueForWildcardMatch(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            TestConstants::PATH_USER_WILDCARD => MaskConstants::MASK_GENERIC,
        ]);

        $this->assertTrue($strategy->shouldApply('value', TestConstants::FIELD_USER_EMAIL, $this->logRecord));
        $this->assertTrue($strategy->shouldApply('value', TestConstants::FIELD_USER_NAME, $this->logRecord));
    }

    #[Test]
    public function fieldPathStrategyMaskAppliesRemoveConfig(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            'secret.key' => FieldMaskConfig::remove(),
        ]);

        $result = $strategy->mask('sensitive', 'secret.key', $this->logRecord);

        $this->assertNull($result);
    }

    #[Test]
    public function fieldPathStrategyMaskAppliesRegexConfig(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            TestConstants::FIELD_USER_SSN => FieldMaskConfig::regexMask(TestConstants::PATTERN_SSN_FORMAT, TestConstants::MASK_SSN_BRACKETS),
        ]);

        $result = $strategy->mask('SSN: 123-45-6789', TestConstants::FIELD_USER_SSN, $this->logRecord);

        $this->assertSame(TestConstants::EXPECTED_SSN_MASKED, $result);
    }

    #[Test]
    public function fieldPathStrategyMaskHandlesArrayValue(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            'data' => FieldMaskConfig::regexMask(TestConstants::PATTERN_DIGITS, '[NUM]'),
        ]);

        $result = $strategy->mask(['count' => '123 items'], 'data', $this->logRecord);

        $this->assertIsArray($result);
        $this->assertSame('[NUM] items', $result['count']);
    }

    #[Test]
    public function fieldPathStrategyMaskReturnsValueWhenNoConfigMatch(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            TestConstants::FIELD_USER_EMAIL => MaskConstants::MASK_EMAIL_PATTERN,
        ]);

        $result = $strategy->mask('original', 'other.field', $this->logRecord);

        $this->assertSame('original', $result);
    }

    #[Test]
    public function fieldPathStrategyGetNameReturnsCorrectFormat(): void
    {
        $strategy = new FieldPathMaskingStrategy([
            TestConstants::FIELD_USER_EMAIL => MaskConstants::MASK_EMAIL_PATTERN,
            'user.phone' => MaskConstants::MASK_PHONE,
        ]);

        $name = $strategy->getName();

        $this->assertSame('Field Path Masking (2 fields)', $name);
    }

    #[Test]
    public function fieldPathStrategyGetConfigurationReturnsAllSettings(): void
    {
        $config = [
            TestConstants::FIELD_USER_EMAIL => FieldMaskConfig::replace(TestConstants::MASK_EMAIL_BRACKETS),
        ];
        $strategy = new FieldPathMaskingStrategy($config);

        $result = $strategy->getConfiguration();

        $this->assertArrayHasKey('field_configs', $result);
    }

    // ========================================
    // Integration Edge Cases
    // ========================================

    #[Test]
    public function regexStrategyMaskHandlesBooleanValue(): void
    {
        $strategy = new RegexMaskingStrategy([
            '/true/' => 'MASKED',
        ]);

        $result = $strategy->mask(true, 'field', $this->logRecord);

        $this->assertTrue($result);
    }

    #[Test]
    public function regexStrategyMaskHandlesNullValue(): void
    {
        $strategy = new RegexMaskingStrategy([
            '/.*/' => 'MASKED',
        ]);

        $result = $strategy->mask(null, 'field', $this->logRecord);

        // Null converts to empty string, which matches .* and gets masked
        // preserveValueType doesn't specifically handle null, so returns masked string
        $this->assertSame('MASKED', $result);
    }

    #[Test]
    public function regexStrategyMaskHandlesEmptyString(): void
    {
        $strategy = new RegexMaskingStrategy([
            '/.+/' => 'MASKED',
        ]);

        $result = $strategy->mask('', 'field', $this->logRecord);

        $this->assertSame('', $result);
    }

    #[Test]
    public function dataTypeStrategyMaskHandlesDefaultCase(): void
    {
        $strategy = new DataTypeMaskingStrategy(['string' => 'MASKED']);

        $result = $strategy->mask('test', 'field', $this->logRecord);

        $this->assertSame('MASKED', $result);
    }
}
