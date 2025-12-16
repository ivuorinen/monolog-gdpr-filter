<?php

declare(strict_types=1);

namespace Tests\Retention;

use Ivuorinen\MonologGdprFilter\Retention\RetentionPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RetentionPolicy::class)]
final class RetentionPolicyTest extends TestCase
{
    public function testGetName(): void
    {
        $policy = new RetentionPolicy('test_policy', 30);

        $this->assertSame('test_policy', $policy->getName());
    }

    public function testGetRetentionDays(): void
    {
        $policy = new RetentionPolicy('test', 90);

        $this->assertSame(90, $policy->getRetentionDays());
    }

    public function testGetActionDefaultsToDelete(): void
    {
        $policy = new RetentionPolicy('test', 30);

        $this->assertSame(RetentionPolicy::ACTION_DELETE, $policy->getAction());
    }

    public function testGetActionCustom(): void
    {
        $policy = new RetentionPolicy('test', 30, RetentionPolicy::ACTION_ANONYMIZE);

        $this->assertSame(RetentionPolicy::ACTION_ANONYMIZE, $policy->getAction());
    }

    public function testGetFieldsDefaultsToEmpty(): void
    {
        $policy = new RetentionPolicy('test', 30);

        $this->assertSame([], $policy->getFields());
    }

    public function testGetFieldsCustom(): void
    {
        $policy = new RetentionPolicy('test', 30, RetentionPolicy::ACTION_DELETE, ['email', 'phone']);

        $this->assertSame(['email', 'phone'], $policy->getFields());
    }

    public function testIsWithinRetentionRecent(): void
    {
        $policy = new RetentionPolicy('test', 30);
        $recentDate = new \DateTimeImmutable('-10 days');

        $this->assertTrue($policy->isWithinRetention($recentDate));
    }

    public function testIsWithinRetentionExpired(): void
    {
        $policy = new RetentionPolicy('test', 30);
        $oldDate = new \DateTimeImmutable('-60 days');

        $this->assertFalse($policy->isWithinRetention($oldDate));
    }

    public function testIsWithinRetentionBoundary(): void
    {
        $policy = new RetentionPolicy('test', 30);
        $boundaryDate = new \DateTimeImmutable('-29 days');

        $this->assertTrue($policy->isWithinRetention($boundaryDate));
    }

    public function testGetCutoffDate(): void
    {
        $policy = new RetentionPolicy('test', 30);
        $cutoff = $policy->getCutoffDate();

        $expected = (new \DateTimeImmutable())->modify('-30 days');

        // Allow 1 second tolerance for test execution time
        $this->assertEqualsWithDelta(
            $expected->getTimestamp(),
            $cutoff->getTimestamp(),
            1
        );
    }

    public function testGdpr30DaysFactory(): void
    {
        $policy = RetentionPolicy::gdpr30Days();

        $this->assertSame('gdpr_standard', $policy->getName());
        $this->assertSame(30, $policy->getRetentionDays());
        $this->assertSame(RetentionPolicy::ACTION_DELETE, $policy->getAction());
    }

    public function testGdpr30DaysFactoryCustomName(): void
    {
        $policy = RetentionPolicy::gdpr30Days('custom_gdpr');

        $this->assertSame('custom_gdpr', $policy->getName());
    }

    public function testArchivalFactory(): void
    {
        $policy = RetentionPolicy::archival();

        $this->assertSame('archival', $policy->getName());
        $this->assertSame(2555, $policy->getRetentionDays()); // ~7 years
        $this->assertSame(RetentionPolicy::ACTION_ARCHIVE, $policy->getAction());
    }

    public function testAnonymizeFactory(): void
    {
        $policy = RetentionPolicy::anonymize('user_data', 90, ['email', 'name']);

        $this->assertSame('user_data', $policy->getName());
        $this->assertSame(90, $policy->getRetentionDays());
        $this->assertSame(RetentionPolicy::ACTION_ANONYMIZE, $policy->getAction());
        $this->assertSame(['email', 'name'], $policy->getFields());
    }

    public function testActionConstants(): void
    {
        $this->assertSame('delete', RetentionPolicy::ACTION_DELETE);
        $this->assertSame('anonymize', RetentionPolicy::ACTION_ANONYMIZE);
        $this->assertSame('archive', RetentionPolicy::ACTION_ARCHIVE);
    }
}
