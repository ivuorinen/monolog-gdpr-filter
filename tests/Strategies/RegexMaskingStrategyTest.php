<?php

declare(strict_types=1);

namespace Tests\Strategies;

use Ivuorinen\MonologGdprFilter\Exceptions\InvalidRegexPatternException;
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
            '/\d{3}-\d{2}-\d{4}/' => '***-**-****',
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '***@***.***',
        ]);

        $this->assertSame(60, $strategy->getPriority());
    }

    #[Test]
    public function constructorAcceptsCustomPriority(): void
    {
        $strategy = new RegexMaskingStrategy(
            ['/test/' => '***'],
            priority: 70
        );

        $this->assertSame(70, $strategy->getPriority());
    }

    #[Test]
    public function constructorThrowsForInvalidPattern(): void
    {
        $this->expectException(InvalidRegexPatternException::class);

        new RegexMaskingStrategy(['/[invalid/' => '***']);
    }

    #[Test]
    public function constructorThrowsForReDoSVulnerablePattern(): void
    {
        $this->expectException(InvalidRegexPatternException::class);
        $this->expectExceptionMessage('catastrophic backtracking');

        new RegexMaskingStrategy(['/^(a+)+$/' => '***']);
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
            '/\d{3}-\d{2}-\d{4}/' => '***-**-****',
        ]);

        $result = $strategy->mask('SSN: 123-45-6789', 'field', $this->logRecord);

        $this->assertSame('SSN: ***-**-****', $result);
    }

    #[Test]
    public function maskAppliesMultiplePatterns(): void
    {
        $strategy = new RegexMaskingStrategy([
            '/\d{3}-\d{2}-\d{4}/' => '***-**-****',
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '***@***.***',
        ]);

        $result = $strategy->mask('SSN: 123-45-6789, Email: test@example.com', 'field', $this->logRecord);

        $this->assertStringContainsString('***-**-****', $result);
        $this->assertStringContainsString('***@***.***', $result);
        $this->assertStringNotContainsString('123-45-6789', $result);
        $this->assertStringNotContainsString('test@example.com', $result);
    }

    #[Test]
    public function maskPreservesValueType(): void
    {
        $strategy = new RegexMaskingStrategy([
            '/\d+/' => '0',
        ]);

        $result = $strategy->mask(123, 'field', $this->logRecord);

        $this->assertSame(0, $result);
        $this->assertIsInt($result);
    }

    #[Test]
    public function maskHandlesArrayValues(): void
    {
        $strategy = new RegexMaskingStrategy([
            '/"email":"[^"]+"/' => '"email":"***@***.***"',
        ]);

        $result = $strategy->mask(['email' => 'test@example.com'], 'field', $this->logRecord);

        $this->assertIsArray($result);
        $this->assertSame('***@***.***', $result['email']);
    }

    #[Test]
    public function maskThrowsForUnconvertibleValue(): void
    {
        $strategy = new RegexMaskingStrategy([
            '/test/' => '***',
        ]);

        $resource = fopen('php://memory', 'r');

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
            '/\d{3}-\d{2}-\d{4}/' => '***-**-****',
        ]);

        $this->assertTrue($strategy->shouldApply('123-45-6789', 'field', $this->logRecord));
    }

    #[Test]
    public function shouldApplyReturnsFalseWhenNoPatternMatches(): void
    {
        $strategy = new RegexMaskingStrategy([
            '/\d{3}-\d{2}-\d{4}/' => '***-**-****',
        ]);

        $this->assertFalse($strategy->shouldApply('no ssn here', 'field', $this->logRecord));
    }

    #[Test]
    public function shouldApplyReturnsFalseForExcludedPath(): void
    {
        $strategy = new RegexMaskingStrategy(
            patterns: ['/\d+/' => '***'],
            excludePaths: ['excluded.field']
        );

        $this->assertFalse($strategy->shouldApply('12345', 'excluded.field', $this->logRecord));
    }

    #[Test]
    public function shouldApplyReturnsTrueForNonExcludedPath(): void
    {
        $strategy = new RegexMaskingStrategy(
            patterns: ['/\d+/' => '***'],
            excludePaths: ['excluded.field']
        );

        $this->assertTrue($strategy->shouldApply('12345', 'included.field', $this->logRecord));
    }

    #[Test]
    public function shouldApplyRespectsIncludePaths(): void
    {
        $strategy = new RegexMaskingStrategy(
            patterns: ['/\d+/' => '***'],
            includePaths: ['user.ssn', 'user.phone']
        );

        $this->assertTrue($strategy->shouldApply('12345', 'user.ssn', $this->logRecord));
        $this->assertTrue($strategy->shouldApply('12345', 'user.phone', $this->logRecord));
        $this->assertFalse($strategy->shouldApply('12345', 'user.email', $this->logRecord));
    }

    #[Test]
    public function shouldApplySupportsWildcardsInIncludePaths(): void
    {
        $strategy = new RegexMaskingStrategy(
            patterns: ['/\d+/' => '***'],
            includePaths: ['user.*']
        );

        $this->assertTrue($strategy->shouldApply('12345', 'user.ssn', $this->logRecord));
        $this->assertTrue($strategy->shouldApply('12345', 'user.phone', $this->logRecord));
        $this->assertFalse($strategy->shouldApply('12345', 'admin.id', $this->logRecord));
    }

    #[Test]
    public function shouldApplySupportsWildcardsInExcludePaths(): void
    {
        $strategy = new RegexMaskingStrategy(
            patterns: ['/\d+/' => '***'],
            excludePaths: ['debug.*']
        );

        $this->assertFalse($strategy->shouldApply('12345', 'debug.info', $this->logRecord));
        $this->assertFalse($strategy->shouldApply('12345', 'debug.data', $this->logRecord));
        $this->assertTrue($strategy->shouldApply('12345', 'user.id', $this->logRecord));
    }

    #[Test]
    public function shouldApplyReturnsFalseForUnconvertibleValue(): void
    {
        $strategy = new RegexMaskingStrategy([
            '/test/' => '***',
        ]);

        $resource = fopen('php://memory', 'r');

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
            '/\d{3}-\d{2}-\d{4}/' => '***-**-****',
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
        $patterns = ['/\d+/' => '***'];
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
            '/\d{3}-\d{2}-\d{4}/' => '***-**-****',
        ]);

        $input = 'First: 123-45-6789, Second: 987-65-4321';
        $result = $strategy->mask($input, 'field', $this->logRecord);

        $this->assertSame('First: ***-**-****, Second: ***-**-****', $result);
    }

    #[Test]
    public function maskAppliesPatternsInOrder(): void
    {
        $strategy = new RegexMaskingStrategy([
            '/test/' => 'REPLACED',
            '/REPLACED/' => 'FINAL',
        ]);

        $result = $strategy->mask('test value', 'field', $this->logRecord);

        $this->assertSame('FINAL value', $result);
    }

    #[Test]
    public function maskHandlesEmptyStringReplacement(): void
    {
        $strategy = new RegexMaskingStrategy([
            '/\d+/' => '',
        ]);

        $result = $strategy->mask('User ID: 12345', 'field', $this->logRecord);

        $this->assertSame('User ID: ', $result);
    }

    #[Test]
    public function maskHandlesCaseInsensitivePatterns(): void
    {
        $strategy = new RegexMaskingStrategy([
            '/password/i' => '***',
        ]);

        $this->assertSame('*** ***', $strategy->mask('password PASSWORD', 'field', $this->logRecord));
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
