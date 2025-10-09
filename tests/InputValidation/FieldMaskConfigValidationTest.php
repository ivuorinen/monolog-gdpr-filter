<?php

declare(strict_types=1);

namespace Tests\InputValidation;

use Ivuorinen\MonologGdprFilter\Exceptions\InvalidConfigurationException;
use Ivuorinen\MonologGdprFilter\Exceptions\InvalidRegexPatternException;
use Ivuorinen\MonologGdprFilter\FieldMaskConfig;
use Ivuorinen\MonologGdprFilter\MaskConstants as Mask;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the FieldMaskConfig class.
 *
 * @api
 */
#[CoversClass(FieldMaskConfig::class)]
class FieldMaskConfigValidationTest extends TestCase
{
    #[Test]
    public function regexMaskThrowsExceptionForEmptyPattern(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Regex pattern cannot be empty');

        FieldMaskConfig::regexMask('');
    }

    #[Test]
    public function regexMaskThrowsExceptionForWhitespaceOnlyPattern(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Regex pattern cannot be empty');

        FieldMaskConfig::regexMask('   ');
    }

    #[Test]
    public function regexMaskThrowsExceptionForEmptyReplacement(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Replacement string cannot be empty');

        FieldMaskConfig::regexMask('/valid/', '');
    }

    #[Test]
    public function regexMaskThrowsExceptionForWhitespaceOnlyReplacement(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Replacement string cannot be empty');

        FieldMaskConfig::regexMask('/valid/', '   ');
    }

    #[Test]
    public function regexMaskThrowsExceptionForInvalidRegexPattern(): void
    {
        $this->expectException(InvalidRegexPatternException::class);
        $this->expectExceptionMessage("Invalid regex pattern 'invalid_regex'");

        FieldMaskConfig::regexMask('invalid_regex');
    }

    #[Test]
    public function regexMaskThrowsExceptionForIncompleteRegexPattern(): void
    {
        $this->expectException(InvalidRegexPatternException::class);
        $this->expectExceptionMessage("Invalid regex pattern '/unclosed'");

        FieldMaskConfig::regexMask('/unclosed');
    }

    #[Test]
    public function regexMaskThrowsExceptionForEmptyDelimitersPattern(): void
    {
        $this->expectException(InvalidRegexPatternException::class);
        $this->expectExceptionMessage("Invalid regex pattern '//'");

        FieldMaskConfig::regexMask('//');
    }

    #[Test]
    public function regexMaskAcceptsValidPattern(): void
    {
        $config = FieldMaskConfig::regexMask('/\d+/', '***NUMBER***');

        $this->assertSame(FieldMaskConfig::MASK_REGEX, $config->type);
        $this->assertSame('/\d+/::***NUMBER***', $config->replacement);
        $this->assertSame('/\d+/', $config->getRegexPattern());
        $this->assertSame('***NUMBER***', $config->getReplacement());
    }

    #[Test]
    public function regexMaskUsesDefaultReplacementWhenNotProvided(): void
    {
        $config = FieldMaskConfig::regexMask('/test/');

        $this->assertSame('***MASKED***', $config->getReplacement());
    }

    #[Test]
    public function regexMaskAcceptsComplexRegexPatterns(): void
    {
        $complexPattern = '/(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)/';
        $config = FieldMaskConfig::regexMask($complexPattern, '***IP***');

        $this->assertSame(FieldMaskConfig::MASK_REGEX, $config->type);
        $this->assertSame($complexPattern, $config->getRegexPattern());
        $this->assertSame('***IP***', $config->getReplacement());
    }

    #[Test]
    public function fromArrayThrowsExceptionForInvalidType(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage("Must be one of: mask_regex, remove, replace");

        FieldMaskConfig::fromArray(['type' => 'invalid_type']);
    }

    #[Test]
    public function fromArrayThrowsExceptionForEmptyReplacementWithReplaceType(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Cannot be null or empty for REPLACE type');

        FieldMaskConfig::fromArray([
            'type' => FieldMaskConfig::REPLACE,
            'replacement' => ''
        ]);
    }

    #[Test]
    public function fromArrayThrowsExceptionForNullReplacementWithReplaceType(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Cannot be null or empty for REPLACE type');

        FieldMaskConfig::fromArray([
            'type' => FieldMaskConfig::REPLACE,
            'replacement' => null
        ]);
    }

    #[Test]
    public function fromArrayThrowsExceptionForWhitespaceOnlyReplacementWithReplaceType(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Cannot be null or empty for REPLACE type');

        FieldMaskConfig::fromArray([
            'type' => FieldMaskConfig::REPLACE,
            'replacement' => '   '
        ]);
    }

    #[Test]
    public function fromArrayAcceptsValidRemoveType(): void
    {
        $config = FieldMaskConfig::fromArray(['type' => FieldMaskConfig::REMOVE]);

        $this->assertSame(FieldMaskConfig::REMOVE, $config->type);
        $this->assertNull($config->replacement);
        $this->assertTrue($config->shouldRemove());
    }

    #[Test]
    public function fromArrayAcceptsValidReplaceType(): void
    {
        $config = FieldMaskConfig::fromArray([
            'type' => FieldMaskConfig::REPLACE,
            'replacement' => Mask::MASK_BRACKETS
        ]);

        $this->assertSame(FieldMaskConfig::REPLACE, $config->type);
        $this->assertSame(Mask::MASK_BRACKETS, $config->replacement);
        $this->assertSame(Mask::MASK_BRACKETS, $config->getReplacement());
    }

    #[Test]
    public function fromArrayAcceptsValidMaskRegexType(): void
    {
        $config = FieldMaskConfig::fromArray([
            'type' => FieldMaskConfig::MASK_REGEX,
            'replacement' => '/\d+/::***DIGITS***'
        ]);

        $this->assertSame(FieldMaskConfig::MASK_REGEX, $config->type);
        $this->assertTrue($config->hasRegexPattern());
        $this->assertSame('/\d+/', $config->getRegexPattern());
        $this->assertSame('***DIGITS***', $config->getReplacement());
    }

    #[Test]
    public function fromArrayUsesDefaultValuesWhenMissing(): void
    {
        $config = FieldMaskConfig::fromArray([]);

        $this->assertSame(FieldMaskConfig::REPLACE, $config->type);
        $this->assertNull($config->replacement);
    }

    #[Test]
    public function fromArrayHandlesMissingReplacementForNonReplaceTypes(): void
    {
        $config = FieldMaskConfig::fromArray(['type' => FieldMaskConfig::REMOVE]);

        $this->assertSame(FieldMaskConfig::REMOVE, $config->type);
        $this->assertNull($config->replacement);
    }

    #[Test]
    public function toArrayAndFromArrayRoundTripWorksCorrectly(): void
    {
        $original = FieldMaskConfig::replace('[REDACTED]');
        $array = $original->toArray();
        $restored = FieldMaskConfig::fromArray($array);

        $this->assertSame($original->type, $restored->type);
        $this->assertSame($original->replacement, $restored->replacement);
    }

    #[Test]
    public function constructorAcceptsValidParameters(): void
    {
        $config = new FieldMaskConfig(FieldMaskConfig::REPLACE, '[TEST]');

        $this->assertSame(FieldMaskConfig::REPLACE, $config->type);
        $this->assertSame('[TEST]', $config->replacement);
    }

    #[Test]
    public function constructorAcceptsNullReplacement(): void
    {
        $config = new FieldMaskConfig(FieldMaskConfig::REMOVE, null);

        $this->assertSame(FieldMaskConfig::REMOVE, $config->type);
        $this->assertNull($config->replacement);
    }

    #[Test]
    public function staticMethodsCreateCorrectConfigurations(): void
    {
        $removeConfig = FieldMaskConfig::remove();
        $this->assertTrue($removeConfig->shouldRemove());
        $this->assertSame(FieldMaskConfig::REMOVE, $removeConfig->type);

        $replaceConfig = FieldMaskConfig::replace('[HIDDEN]');
        $this->assertSame(FieldMaskConfig::REPLACE, $replaceConfig->type);
        $this->assertSame('[HIDDEN]', $replaceConfig->getReplacement());

        $regexConfig = FieldMaskConfig::regexMask('/email/', '***EMAIL***');
        $this->assertTrue($regexConfig->hasRegexPattern());
        $this->assertSame('/email/', $regexConfig->getRegexPattern());
        $this->assertSame('***EMAIL***', $regexConfig->getReplacement());
    }

    #[Test]
    public function getRegexPatternReturnsNullForNonRegexTypes(): void
    {
        $removeConfig = FieldMaskConfig::remove();
        $this->assertNull($removeConfig->getRegexPattern());

        $replaceConfig = FieldMaskConfig::replace('[TEST]');
        $this->assertNull($replaceConfig->getRegexPattern());
    }

    #[Test]
    public function hasRegexPatternReturnsFalseForNonRegexTypes(): void
    {
        $removeConfig = FieldMaskConfig::remove();
        $this->assertFalse($removeConfig->hasRegexPattern());

        $replaceConfig = FieldMaskConfig::replace('[TEST]');
        $this->assertFalse($replaceConfig->hasRegexPattern());
    }
}
