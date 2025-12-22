<?php

declare(strict_types=1);

namespace Tests\Recovery;

use Ivuorinen\MonologGdprFilter\MaskConstants;
use Ivuorinen\MonologGdprFilter\Recovery\FailureMode;
use Ivuorinen\MonologGdprFilter\Recovery\FallbackMaskStrategy;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\TestConstants;

/**
 * Tests for FallbackMaskStrategy.
 *
 * @api
 */
final class FallbackMaskStrategyTest extends TestCase
{
    public function testDefaultFactory(): void
    {
        $strategy = FallbackMaskStrategy::default();

        $this->assertInstanceOf(FallbackMaskStrategy::class, $strategy);
    }

    public function testStrictFactory(): void
    {
        $strategy = FallbackMaskStrategy::strict();

        $this->assertSame(
            MaskConstants::MASK_REDACTED,
            $strategy->getFallback('test', FailureMode::FAIL_SAFE)
        );
    }

    public function testStrictFactoryWithCustomMask(): void
    {
        $strategy = FallbackMaskStrategy::strict('[REMOVED]');

        $this->assertSame(
            '[REMOVED]',
            $strategy->getFallback('test', FailureMode::FAIL_SAFE)
        );
    }

    public function testWithMappingsFactory(): void
    {
        $strategy = FallbackMaskStrategy::withMappings([
            'string' => '[CUSTOM_STRING]',
            'integer' => '[CUSTOM_INT]',
        ]);

        $this->assertSame(
            '[CUSTOM_STRING]',
            $strategy->getFallback('test', FailureMode::FAIL_SAFE)
        );
        $this->assertSame(
            '[CUSTOM_INT]',
            $strategy->getFallback(42, FailureMode::FAIL_SAFE)
        );
    }

    public function testFailOpenReturnsOriginal(): void
    {
        $strategy = FallbackMaskStrategy::default();

        $this->assertSame('original', $strategy->getFallback('original', FailureMode::FAIL_OPEN));
        $this->assertSame(42, $strategy->getFallback(42, FailureMode::FAIL_OPEN));
        $this->assertSame(['key' => 'value'], $strategy->getFallback(['key' => 'value'], FailureMode::FAIL_OPEN));
    }

    public function testFailClosedReturnsRedacted(): void
    {
        $strategy = FallbackMaskStrategy::default();

        $this->assertSame(MaskConstants::MASK_REDACTED, $strategy->getFallback('test', FailureMode::FAIL_CLOSED));
        $this->assertSame(MaskConstants::MASK_REDACTED, $strategy->getFallback(42, FailureMode::FAIL_CLOSED));
    }

    public function testFailSafeForString(): void
    {
        $strategy = FallbackMaskStrategy::default();

        $shortResult = $strategy->getFallback('short', FailureMode::FAIL_SAFE);
        $this->assertSame(MaskConstants::MASK_STRING, $shortResult);

        $longString = str_repeat('a', 50);
        $longResult = $strategy->getFallback($longString, FailureMode::FAIL_SAFE);
        $this->assertStringContainsString(MaskConstants::MASK_STRING, $longResult);
        $this->assertStringContainsString('50 chars', $longResult);
    }

    public function testFailSafeForInteger(): void
    {
        $strategy = FallbackMaskStrategy::default();

        $result = $strategy->getFallback(42, FailureMode::FAIL_SAFE);

        $this->assertSame(MaskConstants::MASK_INT, $result);
    }

    public function testFailSafeForFloat(): void
    {
        $strategy = FallbackMaskStrategy::default();

        $result = $strategy->getFallback(3.14, FailureMode::FAIL_SAFE);

        $this->assertSame(MaskConstants::MASK_FLOAT, $result);
    }

    public function testFailSafeForBoolean(): void
    {
        $strategy = FallbackMaskStrategy::default();

        $result = $strategy->getFallback(true, FailureMode::FAIL_SAFE);

        $this->assertSame(MaskConstants::MASK_BOOL, $result);
    }

    public function testFailSafeForNull(): void
    {
        $strategy = FallbackMaskStrategy::default();

        $result = $strategy->getFallback(null, FailureMode::FAIL_SAFE);

        $this->assertSame(MaskConstants::MASK_NULL, $result);
    }

    public function testFailSafeForEmptyArray(): void
    {
        $strategy = FallbackMaskStrategy::default();

        $result = $strategy->getFallback([], FailureMode::FAIL_SAFE);

        $this->assertSame(MaskConstants::MASK_ARRAY, $result);
    }

    public function testFailSafeForNonEmptyArray(): void
    {
        $strategy = FallbackMaskStrategy::default();

        $result = $strategy->getFallback(['a', 'b', 'c'], FailureMode::FAIL_SAFE);

        $this->assertStringContainsString(MaskConstants::MASK_ARRAY, $result);
        $this->assertStringContainsString('3 items', $result);
    }

    public function testFailSafeForObject(): void
    {
        $strategy = FallbackMaskStrategy::default();
        $obj = new stdClass();

        $result = $strategy->getFallback($obj, FailureMode::FAIL_SAFE);

        $this->assertStringContainsString(MaskConstants::MASK_OBJECT, $result);
        $this->assertStringContainsString('stdClass', $result);
    }

    public function testFailSafeForResource(): void
    {
        $strategy = FallbackMaskStrategy::default();
        $resource = fopen('php://memory', 'r');
        $this->assertNotFalse($resource, 'Failed to open memory stream');

        $result = $strategy->getFallback($resource, FailureMode::FAIL_SAFE);

        fclose($resource);

        $this->assertSame(MaskConstants::MASK_RESOURCE, $result);
    }

    public function testGetConfiguration(): void
    {
        $strategy = new FallbackMaskStrategy(
            customFallbacks: ['string' => '[CUSTOM]'],
            defaultFallback: '[DEFAULT]',
            preserveType: false
        );

        $config = $strategy->getConfiguration();

        $this->assertArrayHasKey('custom_fallbacks', $config);
        $this->assertArrayHasKey('default_fallback', $config);
        $this->assertArrayHasKey('preserve_type', $config);

        $this->assertSame(['string' => '[CUSTOM]'], $config['custom_fallbacks']);
        $this->assertSame('[DEFAULT]', $config['default_fallback']);
        $this->assertFalse($config['preserve_type']);
    }

    public function testPreserveTypeFalseUsesDefault(): void
    {
        $strategy = new FallbackMaskStrategy(
            defaultFallback: TestConstants::MASK_ALWAYS_THIS,
            preserveType: false
        );

        $this->assertSame(TestConstants::MASK_ALWAYS_THIS, $strategy->getFallback('string', FailureMode::FAIL_SAFE));
        $this->assertSame(TestConstants::MASK_ALWAYS_THIS, $strategy->getFallback(42, FailureMode::FAIL_SAFE));
        $this->assertSame(TestConstants::MASK_ALWAYS_THIS, $strategy->getFallback(['array'], FailureMode::FAIL_SAFE));
    }

    public function testCustomClosedFallback(): void
    {
        $strategy = new FallbackMaskStrategy(
            customFallbacks: ['closed' => '[CUSTOM_CLOSED]']
        );

        $result = $strategy->getFallback('test', FailureMode::FAIL_CLOSED);

        $this->assertSame('[CUSTOM_CLOSED]', $result);
    }
}
