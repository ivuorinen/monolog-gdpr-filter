<?php

declare(strict_types=1);

namespace Tests\RegressionTests;

use DateTimeImmutable;
use Ivuorinen\MonologGdprFilter\DefaultPatterns;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\MaskConstants as Mask;
use Ivuorinen\MonologGdprFilter\Strategies\RegexMaskingStrategy;
use Ivuorinen\MonologGdprFilter\Strategies\StrategyManager;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The library ships two masking implementations: the pipeline GdprProcessor actually
 * uses, and the public Strategy layer, which nothing in src/ calls. They have already
 * drifted once — FieldPathMaskingStrategy honoured a custom FieldMaskConfig regex while
 * the wired ContextProcessor silently ignored it.
 *
 * Until the two are consolidated onto one path, these tests pin them to the same
 * observable behaviour so the next divergence fails here instead of in production.
 */
#[CoversClass(GdprProcessor::class)]
#[CoversClass(StrategyManager::class)]
#[CoversClass(RegexMaskingStrategy::class)]
final class MaskingPathParityTest extends TestCase
{
    /**
     * @param array<string, string> $patterns
     */
    private function strategyMask(array $patterns, string $value): mixed
    {
        $manager = new StrategyManager([new RegexMaskingStrategy(patterns: $patterns)]);

        $record = new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'parity',
            level: Level::Info,
            message: $value,
            context: []
        );

        return $manager->maskValue($value, 'message', $record);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function sensitiveValueProvider(): array
    {
        return [
            'email embedded' => ['user john.doe@example.com logged in'],
            'email bare' => ['john.doe@example.com'],
            'us ssn embedded' => ['ssn is 123-45-6789 here'],
            'credit card embedded' => ['card 4111111111111111 charged'],
            'hetu embedded' => ['hetu 010190-123A on file'],
            'mac embedded' => ['nic 00:1A:2B:3C:4D:5E up'],
            'ipv4 embedded' => ['from 10.1.2.3 ok'],
            'nothing sensitive' => ['deployment finished cleanly'],
            'multiple values' => ['john@example.com paid from FI21 1234 5600 0007 85'],
        ];
    }

    /**
     * Both masking paths must produce the same output for the same default patterns.
     */
    #[DataProvider('sensitiveValueProvider')]
    #[Test]
    public function testWiredAndStrategyPathsAgreeOnDefaultPatterns(string $value): void
    {
        $patterns = DefaultPatterns::get();

        $wired = (new GdprProcessor(patterns: $patterns))->maskMessage($value);
        $strategy = $this->strategyMask($patterns, $value);

        $this->assertSame(
            $wired,
            $strategy,
            'GdprProcessor and StrategyManager disagree — the two masking paths have drifted'
        );
    }

    /**
     * A custom pattern must behave identically on both paths too.
     */
    #[Test]
    public function testWiredAndStrategyPathsAgreeOnCustomPatterns(): void
    {
        $patterns = ['/\bsecret-\w+\b/' => Mask::MASK_MASKED];
        $value = 'value secret-alpha and secret-beta end';

        $wired = (new GdprProcessor(patterns: $patterns))->maskMessage($value);

        $this->assertSame($wired, $this->strategyMask($patterns, $value));
        $this->assertStringNotContainsString('secret-alpha', $wired);
    }
}
