<?php

declare(strict_types=1);

namespace Tests\Recovery;

use Ivuorinen\MonologGdprFilter\Recovery\FailureMode;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FailureMode enum.
 *
 * @api
 */
final class FailureModeTest extends TestCase
{
    public function testEnumValues(): void
    {
        $this->assertSame('fail_open', FailureMode::FAIL_OPEN->value);
        $this->assertSame('fail_closed', FailureMode::FAIL_CLOSED->value);
        $this->assertSame('fail_safe', FailureMode::FAIL_SAFE->value);
    }

    public function testGetDescription(): void
    {
        $openDesc = FailureMode::FAIL_OPEN->getDescription();
        $closedDesc = FailureMode::FAIL_CLOSED->getDescription();
        $safeDesc = FailureMode::FAIL_SAFE->getDescription();

        $this->assertStringContainsString('original', $openDesc);
        $this->assertStringContainsString('risky', $openDesc);

        $this->assertStringContainsString('redacted', $closedDesc);
        $this->assertStringContainsString('strict', $closedDesc);

        $this->assertStringContainsString('fallback', $safeDesc);
        $this->assertStringContainsString('balanced', $safeDesc);
    }

    public function testRecommended(): void
    {
        $recommended = FailureMode::recommended();

        $this->assertSame(FailureMode::FAIL_SAFE, $recommended);
    }

    public function testAllCasesExist(): void
    {
        $cases = FailureMode::cases();

        $this->assertCount(3, $cases);
        $this->assertContains(FailureMode::FAIL_OPEN, $cases);
        $this->assertContains(FailureMode::FAIL_CLOSED, $cases);
        $this->assertContains(FailureMode::FAIL_SAFE, $cases);
    }
}
