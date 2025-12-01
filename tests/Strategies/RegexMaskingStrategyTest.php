<?php

declare(strict_types=1);

namespace Tests\Strategies;

use Tests\TestConstants;
use Ivuorinen\MonologGdprFilter\Exceptions\InvalidRegexPatternException;
use Ivuorinen\MonologGdprFilter\MaskConstants;
use Ivuorinen\MonologGdprFilter\Exceptions\MaskingOperationFailedException;
use Ivuorinen\MonologGdprFilter\Strategies\RegexMaskingStrategy;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\TestHelpers;

#[CoversClass(RegexMaskingStrategy::class)]
final class RegexMaskingStrategyTest extends TestCase
{
    use TestHelpers;

    private LogRecord $logRecord;

    #[\Override]
    protected function setUp(): void
    {
        $this->logRecord = $this->createLogRecord();
    }

    #[Test]
    public function constructorAcceptsPatternsArray(): void
    {
        $strategy = new RegexMaskingStrategy([
            TestConstants::PATTERN_SSN_FORMAT => MaskConstants::MASK_SSN_PATTERN,
            TestConstants::PATTERN_EMAIL_FULL => MaskConstants::MASK_EMAIL_PATTERN,
        ]);

        $this->assertSame(60, $strategy->getPriority());
    }

    #[Test]
    public function constructorAcceptsCustomPriority(): void
    {
        $strategy = new RegexMaskingStrategy(
            [TestConstants::PATTERN_TEST => MaskConstants::MASK_GENERIC],
            priority: 70
        );

        $this->assertSame(70, $strategy->getPriority());
    }

    #[Test]
    public function constructorThrowsForInvalidPattern(): void
    {
        $this->expectException(InvalidRegexPatternException::class);

        new RegexMaskingStrategy(['/[invalid/' => MaskConstants::MASK_GENERIC]);
    }

    #[Test]
    public function constructorThrowsForReDoSVulnerablePattern(): void
    {
        $this->expectException(InvalidRegexPatternException::class);
        $this->expectExceptionMessage('catastrophic backtracking');

        new RegexMaskingStrategy(['/^(a+)+$/' => MaskConstants::MASK_GENERIC]);
    }

    #[Test]
    public function getNameReturnsDescriptiveName(): void
    {
        $strategy = new RegexMaskingStrategy([
            '/pattern1/' => 'replacement1',
            '/pattern2/' => 'replacement2',
            '/pattern3/' => 'replacement3',
        ]);

        $this->assertSame('Regex Pattern Masking (3 patterns)', $strategy->getName());
    }

    #[Test]
    public function maskAppliesSinglePattern(): void
    {
        $strategy = new RegexMaskingStrategy([
            TestConstants::PATTERN_SSN_FORMAT => MaskConstants::MASK_SSN_PATTERN,
        ]);

        $result = $strategy->mask('SSN: 123-45-6789', 'field', $this->logRecord);

        $this->assertSame('SSN: ' . MaskConstants::MASK_SSN_PATTERN, $result);
    }

    #[Test]
    public function maskAppliesMultiplePatterns(): void
    {
        $strategy = new RegexMaskingStrategy([
            TestConstants::PATTERN_SSN_FORMAT => MaskConstants::MASK_SSN_PATTERN,
            TestConstants::PATTERN_EMAIL_FULL => MaskConstants::MASK_EMAIL_PATTERN,
        ]);

        $result = $strategy->mask('SSN: 123-45-6789, Email: test@example.com', 'field', $this->logRecord);

        $this->assertStringContainsString(MaskConstants::MASK_SSN_PATTERN, $result);
        $this->assertStringContainsString(MaskConstants::MASK_EMAIL_PATTERN, $result);
        $this->assertStringNotContainsString(TestConstants::SSN_US, $result);
        $this->assertStringNotContainsString(TestConstants::EMAIL_TEST, $result);
    }

    #[Test]
    public function maskPreservesValueType(): void
    {
        $strategy = new RegexMaskingStrategy([
            TestConstants::PATTERN_DIGITS => '0',
        ]);

        $result = $strategy->mask(123, 'field', $this->logRecord);

        $this->assertSame(0, $result);
        $this->assertIsInt($result);
    }

    #[Test]
    public function maskHandlesArrayValues(): void
    {
        $strategy = new RegexMaskingStrategy([
            '/"email":"[^"]+"/' => '"email":"' . MaskConstants::MASK_EMAIL_PATTERN . '"',
        ]);

        $input = [TestConstants::CONTEXT_EMAIL => TestConstants::EMAIL_TEST];
        $result = $strategy->mask($input, 'field', $this->logRecord);

        $this->assertIsArray($result);
        $this->assertSame(MaskConstants::MASK_EMAIL_PATTERN, $result[TestConstants::CONTEXT_EMAIL]);
    }

    #[Test]
    public function maskThrowsForUnconvertibleValue(): void
    {
        $strategy = new RegexMaskingStrategy([
            TestConstants::PATTERN_TEST => MaskConstants::MASK_GENERIC,
        ]);

        $resource = fopen('php://memory', 'r');
        $this->assertIsResource($resource, 'Failed to open php://memory');

        $this->expectException(MaskingOperationFailedException::class);

        try {
            $strategy->mask($resource, 'field', $this->logRecord);
        } finally {
            fclose($resource);
        }
    }

    #[Test]
    public function shouldApplyReturnsTrueWhenPatternMatches(): void
    {
        $strategy = new RegexMaskingStrategy([
            TestConstants::PATTERN_SSN_FORMAT => MaskConstants::MASK_SSN_PATTERN,
        ]);

        $this->assertTrue($strategy->shouldApply(TestConstants::SSN_US, 'field', $this->logRecord));
    }

    #[Test]
    public function shouldApplyReturnsFalseWhenNoPatternMatches(): void
    {
        $strategy = new RegexMaskingStrategy([
            TestConstants::PATTERN_SSN_FORMAT => MaskConstants::MASK_SSN_PATTERN,
        ]);

        $this->assertFalse($strategy->shouldApply('no ssn here', 'field', $this->logRecord));
    }

    #[Test]
    public function shouldApplyReturnsFalseForExcludedPath(): void
    {
        $strategy = new RegexMaskingStrategy(
            patterns: [TestConstants::PATTERN_DIGITS => MaskConstants::MASK_GENERIC],
            excludePaths: ['excluded.field']
        );

        $this->assertFalse($strategy->shouldApply(
            TestConstants::DATA_NUMBER_STRING,
            'excluded.field',
            $this->logRecord
        ));
    }

    #[Test]
    public function shouldApplyReturnsTrueForNonExcludedPath(): void
    {
        $strategy = new RegexMaskingStrategy(
            patterns: [TestConstants::PATTERN_DIGITS => MaskConstants::MASK_GENERIC],
            excludePaths: ['excluded.field']
        );

        $this->assertTrue($strategy->shouldApply(
            TestConstants::DATA_NUMBER_STRING,
            'included.field',
            $this->logRecord
        ));
    }

    #[Test]
    public function shouldApplyRespectsIncludePaths(): void
    {
        $strategy = new RegexMaskingStrategy(
            patterns: [TestConstants::PATTERN_DIGITS => MaskConstants::MASK_GENERIC],
            includePaths: ['user.ssn', 'user.phone']
        );

        $this->assertTrue($strategy->shouldApply(
            TestConstants::DATA_NUMBER_STRING,
            'user.ssn',
            $this->logRecord
        ));
        $this->assertTrue($strategy->shouldApply(
            TestConstants::DATA_NUMBER_STRING,
            'user.phone',
            $this->logRecord
        ));
        $this->assertFalse($strategy->shouldApply(
            TestConstants::DATA_NUMBER_STRING,
            TestConstants::FIELD_USER_EMAIL,
            $this->logRecord
        ));
    }

    #[Test]
    public function shouldApplySupportsWildcardsInIncludePaths(): void
    {
        $strategy = new RegexMaskingStrategy(
            patterns: [TestConstants::PATTERN_DIGITS => MaskConstants::MASK_GENERIC],
            includePaths: [TestConstants::PATH_USER_WILDCARD]
        );

        $this->assertTrue($strategy->shouldApply(TestConstants::DATA_NUMBER_STRING, 'user.ssn', $this->logRecord));
        $this->assertTrue($strategy->shouldApply(TestConstants::DATA_NUMBER_STRING, 'user.phone', $this->logRecord));
        $this->assertFalse($strategy->shouldApply(TestConstants::DATA_NUMBER_STRING, 'admin.id', $this->logRecord));
    }

    #[Test]
    public function shouldApplySupportsWildcardsInExcludePaths(): void
    {
        $strategy = new RegexMaskingStrategy(
            patterns: [TestConstants::PATTERN_DIGITS => MaskConstants::MASK_GENERIC],
            excludePaths: ['debug.*']
        );

        $this->assertFalse($strategy->shouldApply(TestConstants::DATA_NUMBER_STRING, 'debug.info', $this->logRecord));
        $this->assertFalse($strategy->shouldApply(TestConstants::DATA_NUMBER_STRING, 'debug.data', $this->logRecord));
        $this->assertTrue($strategy->shouldApply(TestConstants::DATA_NUMBER_STRING, 'user.id', $this->logRecord));
    }

    #[Test]
    public function shouldApplyReturnsFalseForUnconvertibleValue(): void
    {
        $strategy = new RegexMaskingStrategy([
            TestConstants::PATTERN_TEST => MaskConstants::MASK_GENERIC,
        ]);

        $resource = fopen('php://memory', 'r');
        $this->assertIsResource($resource, 'Failed to open php://memory');

        try {
            $result = $strategy->shouldApply($resource, 'field', $this->logRecord);
            $this->assertFalse($result);
        } finally {
            fclose($resource);
        }
    }

    #[Test]
    public function validateReturnsTrueForValidConfiguration(): void
    {
        $strategy = new RegexMaskingStrategy([
            TestConstants::PATTERN_SSN_FORMAT => MaskConstants::MASK_SSN_PATTERN,
            '/[a-z]+/' => 'REDACTED',
        ]);

        $this->assertTrue($strategy->validate());
    }

    #[Test]
    public function validateReturnsFalseForEmptyPatterns(): void
    {
        $strategy = new RegexMaskingStrategy([]);

        $this->assertFalse($strategy->validate());
    }



    #[Test]
    public function getConfigurationReturnsFullConfiguration(): void
    {
        $patterns = [TestConstants::PATTERN_DIGITS => MaskConstants::MASK_GENERIC];
        $includePaths = ['user.ssn'];
        $excludePaths = ['debug.*'];

        $strategy = new RegexMaskingStrategy(
            patterns: $patterns,
            includePaths: $includePaths,
            excludePaths: $excludePaths
        );

        $config = $strategy->getConfiguration();

        $this->assertSame($patterns, $config['patterns']);
        $this->assertSame($includePaths, $config['include_paths']);
        $this->assertSame($excludePaths, $config['exclude_paths']);
    }

    #[Test]
    public function maskHandlesMultipleMatchesInSameString(): void
    {
        $strategy = new RegexMaskingStrategy([
            TestConstants::PATTERN_SSN_FORMAT => MaskConstants::MASK_SSN_PATTERN,
        ]);

        $input = 'First: 123-45-6789, Second: 987-65-4321';
        $result = $strategy->mask($input, 'field', $this->logRecord);

        $this->assertSame('First: ***-**-****, Second: ' . MaskConstants::MASK_SSN_PATTERN, $result);
    }

    #[Test]
    public function maskAppliesPatternsInOrder(): void
    {
        $strategy = new RegexMaskingStrategy([
            TestConstants::PATTERN_TEST => 'REPLACED',
            '/REPLACED/' => 'FINAL',
        ]);

        $result = $strategy->mask('test value', 'field', $this->logRecord);

        $this->assertSame('FINAL value', $result);
    }

    #[Test]
    public function maskHandlesEmptyStringReplacement(): void
    {
        $strategy = new RegexMaskingStrategy([
            TestConstants::PATTERN_DIGITS => '',
        ]);

        $result = $strategy->mask(TestConstants::MESSAGE_USER_ID, 'field', $this->logRecord);

        $this->assertSame('User ID: ', $result);
    }

    #[Test]
    public function maskHandlesCaseInsensitivePatterns(): void
    {
        $strategy = new RegexMaskingStrategy([
            '/password/i' => MaskConstants::MASK_GENERIC,
        ]);

        $expected = MaskConstants::MASK_GENERIC . ' ' . MaskConstants::MASK_GENERIC;
        $result = $strategy->mask('password PASSWORD', 'field', $this->logRecord);
        $this->assertSame($expected, $result);
    }

    #[Test]
    public function maskHandlesMultilinePatterns(): void
    {
        $strategy = new RegexMaskingStrategy([
            '/^line\d+$/m' => 'REDACTED',
        ]);

        $input = "line1\nother\nline2";
        $result = $strategy->mask($input, 'field', $this->logRecord);

        $this->assertStringContainsString('REDACTED', $result);
        $this->assertStringContainsString('other', $result);
    }
}
