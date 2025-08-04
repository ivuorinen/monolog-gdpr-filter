<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Ivuorinen\MonologGdprFilter\FieldMaskConfig;

/**
 * Test field mask configuration.
 *
 * @api
 */
#[CoversClass(className: FieldMaskConfig::class)]
#[CoversMethod(className: FieldMaskConfig::class, methodName: '__construct')]
class FieldMaskConfigTest extends TestCase
{
    public function testMaskRegexConfig(): void
    {
        $config = new FieldMaskConfig(FieldMaskConfig::MASK_REGEX);
        $this->assertSame(FieldMaskConfig::MASK_REGEX, $config->type);
        $this->assertNull($config->replacement);
    }

    public function testRemoveConfig(): void
    {
        $config = new FieldMaskConfig(FieldMaskConfig::REMOVE);
        $this->assertSame(FieldMaskConfig::REMOVE, $config->type);
        $this->assertNull($config->replacement);
    }

    public function testReplaceConfig(): void
    {
        $config = new FieldMaskConfig(FieldMaskConfig::REPLACE, 'MASKED');
        $this->assertSame(FieldMaskConfig::REPLACE, $config->type);
        $this->assertSame('MASKED', $config->replacement);
    }
}
