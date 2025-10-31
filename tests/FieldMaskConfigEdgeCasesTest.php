<?php

declare(strict_types=1);

namespace Tests;

use Ivuorinen\MonologGdprFilter\FieldMaskConfig;
use Ivuorinen\MonologGdprFilter\Exceptions\InvalidRegexPatternException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FieldMaskConfig::class)]
final class FieldMaskConfigEdgeCasesTest extends TestCase
{
    public function testRegexMaskThrowsOnWhitespaceOnlyPattern(): void
    {
        $this->expectException(InvalidRegexPatternException::class);

        // Pattern with only whitespace matcher
        FieldMaskConfig::regexMask('/\s*/', 'MASKED');
    }

    public function testRegexMaskThrowsOnEffectivelyEmptyPattern(): void
    {
        $this->expectException(InvalidRegexPatternException::class);

        // Pattern that is effectively empty (just delimiters)
        FieldMaskConfig::regexMask('//', 'MASKED');
    }

    public function testRegexMaskWithComplexPattern(): void
    {
        // Test a valid complex pattern to ensure the validation doesn't reject valid patterns
        $config = FieldMaskConfig::regexMask('/[a-zA-Z0-9]+@[a-z]+\.[a-z]{2,}/', 'EMAIL');

        $this->assertTrue($config->hasRegexPattern());
        $pattern = $config->getRegexPattern();
        $this->assertNotNull($pattern);
        $this->assertStringContainsString('@', $pattern);
    }
}
