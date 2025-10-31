<?php

declare(strict_types=1);

namespace Tests;

use Ivuorinen\MonologGdprFilter\FieldMaskConfig;
use Ivuorinen\MonologGdprFilter\MaskConstants;
use Ivuorinen\MonologGdprFilter\Exceptions\InvalidConfigurationException;
use Ivuorinen\MonologGdprFilter\Exceptions\InvalidRegexPatternException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\TestConstants;

#[CoversClass(FieldMaskConfig::class)]
final class FieldMaskConfigEnhancedTest extends TestCase
{
    public function testRemoveFactory(): void
    {
        $config = FieldMaskConfig::remove();

        $this->assertSame(FieldMaskConfig::REMOVE, $config->type);
        $this->assertNull($config->replacement);
        $this->assertTrue($config->shouldRemove());
        $this->assertFalse($config->hasRegexPattern());
    }

    public function testReplaceFactory(): void
    {
        $config = FieldMaskConfig::replace(MaskConstants::MASK_REDACTED);

        $this->assertSame(FieldMaskConfig::REPLACE, $config->type);
        $this->assertSame(MaskConstants::MASK_REDACTED, $config->replacement);
        $this->assertSame(MaskConstants::MASK_REDACTED, $config->getReplacement());
        $this->assertFalse($config->shouldRemove());
        $this->assertFalse($config->hasRegexPattern());
    }

    public function testUseProcessorPatternsFactory(): void
    {
        $config = FieldMaskConfig::useProcessorPatterns();

        $this->assertSame(FieldMaskConfig::MASK_REGEX, $config->type);
        $this->assertNull($config->replacement);
        $this->assertTrue($config->hasRegexPattern());
        $this->assertFalse($config->shouldRemove());
    }

    public function testRegexMaskFactory(): void
    {
        $config = FieldMaskConfig::regexMask(TestConstants::PATTERN_DIGITS, 'NUM');

        $this->assertSame(FieldMaskConfig::MASK_REGEX, $config->type);
        $this->assertTrue($config->hasRegexPattern());
        $this->assertSame(TestConstants::PATTERN_DIGITS, $config->getRegexPattern());
        $this->assertSame('NUM', $config->getReplacement());
    }

    public function testRegexMaskFactoryWithDefaultReplacement(): void
    {
        $config = FieldMaskConfig::regexMask(TestConstants::PATTERN_TEST);

        $this->assertSame(TestConstants::PATTERN_TEST, $config->getRegexPattern());
        $this->assertSame(MaskConstants::MASK_MASKED, $config->getReplacement());
    }

    public function testRegexMaskThrowsOnEmptyPattern(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('regex pattern');

        FieldMaskConfig::regexMask('   ', 'MASKED');
    }

    public function testRegexMaskThrowsOnEmptyReplacement(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('replacement string');

        FieldMaskConfig::regexMask(TestConstants::PATTERN_TEST, '   ');
    }

    public function testRegexMaskThrowsOnInvalidPattern(): void
    {
        $this->expectException(InvalidRegexPatternException::class);

        // Invalid regex pattern (no delimiters)
        FieldMaskConfig::regexMask('invalid', 'MASKED');
    }

    public function testRegexMaskThrowsOnEmptyRegexPattern(): void
    {
        $this->expectException(InvalidRegexPatternException::class);

        // Effectively empty pattern
        FieldMaskConfig::regexMask('//', 'MASKED');
    }

    public function testRegexMaskThrowsOnWhitespaceOnlyPattern(): void
    {
        $this->expectException(InvalidRegexPatternException::class);

        // Whitespace-only pattern
        FieldMaskConfig::regexMask('/\s*/', 'MASKED');
    }

    public function testGetRegexPatternReturnsNullForNonRegex(): void
    {
        $config = FieldMaskConfig::remove();

        $this->assertNull($config->getRegexPattern());
    }

    public function testGetRegexPatternReturnsNullWhenReplacementNull(): void
    {
        $config = FieldMaskConfig::useProcessorPatterns();

        $this->assertNull($config->getRegexPattern());
    }

    public function testGetReplacementForReplace(): void
    {
        $config = FieldMaskConfig::replace('CUSTOM');

        $this->assertSame('CUSTOM', $config->getReplacement());
    }

    public function testGetReplacementForRemove(): void
    {
        $config = FieldMaskConfig::remove();

        $this->assertNull($config->getReplacement());
    }

    public function testToArray(): void
    {
        $config = FieldMaskConfig::replace('TEST');

        $array = $config->toArray();

        $this->assertIsArray($array);
        $this->assertArrayHasKey('type', $array);
        $this->assertArrayHasKey('replacement', $array);
        $this->assertSame(FieldMaskConfig::REPLACE, $array['type']);
        $this->assertSame('TEST', $array['replacement']);
    }

    public function testToArrayWithRemove(): void
    {
        $config = FieldMaskConfig::remove();

        $array = $config->toArray();

        $this->assertSame(FieldMaskConfig::REMOVE, $array['type']);
        $this->assertNull($array['replacement']);
    }

    public function testFromArrayWithValidData(): void
    {
        $data = [
            'type' => FieldMaskConfig::REPLACE,
            'replacement' => 'VALUE',
        ];

        $config = FieldMaskConfig::fromArray($data);

        $this->assertSame(FieldMaskConfig::REPLACE, $config->type);
        $this->assertSame('VALUE', $config->replacement);
    }

    public function testFromArrayWithDefaultType(): void
    {
        $data = ['replacement' => 'VALUE'];

        $config = FieldMaskConfig::fromArray($data);

        // Default type should be REPLACE
        $this->assertSame(FieldMaskConfig::REPLACE, $config->type);
        $this->assertSame('VALUE', $config->replacement);
    }

    public function testFromArrayWithRemoveType(): void
    {
        $data = ['type' => FieldMaskConfig::REMOVE];

        $config = FieldMaskConfig::fromArray($data);

        $this->assertSame(FieldMaskConfig::REMOVE, $config->type);
        $this->assertNull($config->replacement);
    }

    public function testFromArrayThrowsOnInvalidType(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Must be one of');

        FieldMaskConfig::fromArray(['type' => 'invalid_type']);
    }

    public function testFromArrayThrowsOnNullReplacementForReplaceType(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(TestConstants::ERROR_REPLACE_TYPE_EMPTY);

        FieldMaskConfig::fromArray([
            'type' => FieldMaskConfig::REPLACE,
            'replacement' => null,
        ]);
    }

    public function testFromArrayThrowsOnEmptyReplacementForReplaceType(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(TestConstants::ERROR_REPLACE_TYPE_EMPTY);

        FieldMaskConfig::fromArray([
            'type' => FieldMaskConfig::REPLACE,
            'replacement' => '   ',
        ]);
    }

    public function testFromArrayAllowsNullReplacementWhenNotExplicitlyProvided(): void
    {
        // When replacement key is not in the array at all, it should be allowed
        $data = ['type' => FieldMaskConfig::REPLACE];

        $config = FieldMaskConfig::fromArray($data);

        $this->assertSame(FieldMaskConfig::REPLACE, $config->type);
        $this->assertNull($config->replacement);
    }

    public function testRoundTripToArrayFromArray(): void
    {
        $original = FieldMaskConfig::replace('ROUNDTRIP');

        $array = $original->toArray();
        $restored = FieldMaskConfig::fromArray($array);

        $this->assertSame($original->type, $restored->type);
        $this->assertSame($original->replacement, $restored->replacement);
    }

    public function testShouldRemoveReturnsTrueOnlyForRemove(): void
    {
        $this->assertTrue(FieldMaskConfig::remove()->shouldRemove());
        $this->assertFalse(FieldMaskConfig::replace('X')->shouldRemove());
        $this->assertFalse(FieldMaskConfig::useProcessorPatterns()->shouldRemove());
    }

    public function testHasRegexPatternReturnsTrueOnlyForMaskRegex(): void
    {
        $this->assertTrue(FieldMaskConfig::useProcessorPatterns()->hasRegexPattern());
        $this->assertTrue(FieldMaskConfig::regexMask(TestConstants::PATTERN_TEST, 'X')->hasRegexPattern());
        $this->assertFalse(FieldMaskConfig::remove()->hasRegexPattern());
        $this->assertFalse(FieldMaskConfig::replace('X')->hasRegexPattern());
    }
}
