<?php

declare(strict_types=1);

namespace Tests\Strategies;

use Ivuorinen\MonologGdprFilter\Exceptions\InvalidRegexPatternException;
use Ivuorinen\MonologGdprFilter\MaskConstants;
use PHPUnit\Framework\TestCase;
use Tests\TestHelpers;
use Ivuorinen\MonologGdprFilter\Strategies\RegexMaskingStrategy;

/**
 * Enhanced tests for RegexMaskingStrategy to improve coverage.
 */
final class RegexMaskingStrategyEnhancedTest extends TestCase
{
    use TestHelpers;

    public function testAllReDoSPatternsAreDetected(): void
    {
        // Test pattern 1: (x*)+
        try {
            new RegexMaskingStrategy(['/(a*)+/' => 'masked']);
            $this->fail('Expected InvalidRegexPatternException for (x*)+');
        } catch (InvalidRegexPatternException $e) {
            $this->assertStringContainsString('ReDoS', $e->getMessage());
        }

        // Test pattern 2: (x+)+
        try {
            new RegexMaskingStrategy(['/(b+)+/' => 'masked']);
            $this->fail('Expected InvalidRegexPatternException for (x+)+');
        } catch (InvalidRegexPatternException $e) {
            $this->assertStringContainsString('ReDoS', $e->getMessage());
        }

        // Test pattern 3: (x*)*
        try {
            new RegexMaskingStrategy(['/(c*)*/' => 'masked']);
            $this->fail('Expected InvalidRegexPatternException for (x*)*');
        } catch (InvalidRegexPatternException $e) {
            $this->assertStringContainsString('ReDoS', $e->getMessage());
        }

        // Test pattern 4: (x+)*
        try {
            new RegexMaskingStrategy(['/(d+)*/' => 'masked']);
            $this->fail('Expected InvalidRegexPatternException for (x+)*');
        } catch (InvalidRegexPatternException $e) {
            $this->assertStringContainsString('ReDoS', $e->getMessage());
        }
    }

    public function testReDoSDetectionWithOverlappingAlternations(): void
    {
        // Test (.*|.*)
        try {
            new RegexMaskingStrategy(['/^(.*|.*)$/' => 'masked']);
            $this->fail('Expected InvalidRegexPatternException for overlapping alternations');
        } catch (InvalidRegexPatternException $e) {
            $this->assertStringContainsString('ReDoS', $e->getMessage());
        }

        // Test (a|ab|abc|abcd)*
        try {
            new RegexMaskingStrategy(['/^(a|ab|abc|abcd)*$/' => 'masked']);
            $this->fail('Expected InvalidRegexPatternException for expanding alternations');
        } catch (InvalidRegexPatternException $e) {
            $this->assertStringContainsString('ReDoS', $e->getMessage());
        }
    }

    public function testMultiplePatternsWithOneFailure(): void
    {
        $patterns = [
            '/\b\d{3}-\d{2}-\d{4}\b/' => '***SSN***',
            '/email\w+@\w+\.com/' => '***EMAIL***',
        ];

        $strategy = new RegexMaskingStrategy($patterns);
        $logRecord = $this->createLogRecord();

        // Should successfully apply all valid patterns
        $result = $strategy->mask('SSN: 123-45-6789, Email: emailtest@example.com', 'message', $logRecord);
        $this->assertStringContainsString('***SSN***', $result);
        $this->assertStringContainsString('***EMAIL***', $result);
    }

    public function testEmptyPatternIsRejected(): void
    {
        $this->expectException(InvalidRegexPatternException::class);
        new RegexMaskingStrategy(['' => 'masked']);
    }

    public function testPatternWithInvalidDelimiter(): void
    {
        $this->expectException(InvalidRegexPatternException::class);
        new RegexMaskingStrategy(['invalid_pattern' => 'masked']);
    }

    public function testPatternWithMismatchedBrackets(): void
    {
        $this->expectException(InvalidRegexPatternException::class);
        new RegexMaskingStrategy(['/[abc/' => 'masked']);
    }

    public function testPatternWithInvalidEscape(): void
    {
        $this->expectException(InvalidRegexPatternException::class);
        new RegexMaskingStrategy(['/\k/' => 'masked']);
    }

    public function testMaskingValueThatDoesNotMatch(): void
    {
        $patterns = ['/secret/' => MaskConstants::MASK_MASKED];
        $strategy = new RegexMaskingStrategy($patterns);
        $logRecord = $this->createLogRecord();

        // Value that doesn't match should be returned unchanged
        $result = $strategy->mask('public information', 'message', $logRecord);
        $this->assertEquals('public information', $result);
    }

    public function testShouldApplyWithIncludePathsOnly(): void
    {
        $patterns = ['/test/' => MaskConstants::MASK_MASKED];
        $strategy = new RegexMaskingStrategy($patterns, ['user.*', 'admin.log']);
        $logRecord = $this->createLogRecord();

        // Should apply to matching content in included paths
        $this->assertTrue($strategy->shouldApply('test data', 'user.email', $logRecord));
        $this->assertTrue($strategy->shouldApply('test data', 'admin.log', $logRecord));

        // Should not apply to non-included paths even if content matches
        $this->assertFalse($strategy->shouldApply('test data', 'system.info', $logRecord));
    }

    public function testShouldApplyWithExcludePathsPrecedence(): void
    {
        $patterns = ['/test/' => MaskConstants::MASK_MASKED];
        $strategy = new RegexMaskingStrategy($patterns, ['user.*'], ['user.id', 'user.created_at']);
        $logRecord = $this->createLogRecord();

        // Should apply to included but not excluded
        $this->assertTrue($strategy->shouldApply('test data', 'user.email', $logRecord));

        // Should not apply to excluded paths
        $this->assertFalse($strategy->shouldApply('test data', 'user.id', $logRecord));
        $this->assertFalse($strategy->shouldApply('test data', 'user.created_at', $logRecord));
    }

    public function testShouldNotApplyWhenContentDoesNotMatch(): void
    {
        $patterns = ['/secret/' => MaskConstants::MASK_MASKED];
        $strategy = new RegexMaskingStrategy($patterns);
        $logRecord = $this->createLogRecord();

        // Should return false when content doesn't match patterns
        $this->assertFalse($strategy->shouldApply('public data', 'message', $logRecord));
        $this->assertFalse($strategy->shouldApply('no sensitive info', 'context.field', $logRecord));
    }

    public function testShouldApplyForNonStringValuesWhenPatternMatches(): void
    {
        $patterns = ['/123/' => MaskConstants::MASK_MASKED];
        $strategy = new RegexMaskingStrategy($patterns);
        $logRecord = $this->createLogRecord();

        // Non-string values are converted to strings, so pattern matching still works
        $this->assertTrue($strategy->shouldApply(123, 'number', $logRecord));

        // Arrays/objects that don't match pattern
        $patterns2 = ['/email/' => MaskConstants::MASK_MASKED];
        $strategy2 = new RegexMaskingStrategy($patterns2);
        $this->assertFalse($strategy2->shouldApply(['array'], 'data', $logRecord));
        $this->assertFalse($strategy2->shouldApply(true, 'boolean', $logRecord));
    }

    public function testGetNameWithMultiplePatterns(): void
    {
        $patterns = [
            '/email/' => '***EMAIL***',
            '/phone/' => '***PHONE***',
            '/ssn/' => '***SSN***',
        ];
        $strategy = new RegexMaskingStrategy($patterns);

        $name = $strategy->getName();
        $this->assertStringContainsString('Regex Pattern Masking', $name);
        $this->assertStringContainsString('3 patterns', $name);
    }

    public function testGetNameWithSinglePattern(): void
    {
        $patterns = ['/test/' => MaskConstants::MASK_MASKED];
        $strategy = new RegexMaskingStrategy($patterns);

        $name = $strategy->getName();
        $this->assertStringContainsString('1 pattern', $name);
    }

    public function testGetPriorityDefaultValue(): void
    {
        $patterns = ['/test/' => MaskConstants::MASK_MASKED];
        $strategy = new RegexMaskingStrategy($patterns);

        $this->assertEquals(60, $strategy->getPriority());
    }

    public function testGetPriorityCustomValue(): void
    {
        $patterns = ['/test/' => MaskConstants::MASK_MASKED];
        $strategy = new RegexMaskingStrategy($patterns, [], [], 75);

        $this->assertEquals(75, $strategy->getPriority());
    }

    public function testGetConfiguration(): void
    {
        $patterns = ['/test/' => MaskConstants::MASK_MASKED];
        $includePaths = ['user.*'];
        $excludePaths = ['user.id'];

        $strategy = new RegexMaskingStrategy($patterns, $includePaths, $excludePaths);

        $config = $strategy->getConfiguration();
        $this->assertArrayHasKey('patterns', $config);
        $this->assertArrayHasKey('include_paths', $config);
        $this->assertArrayHasKey('exclude_paths', $config);
        $this->assertEquals($patterns, $config['patterns']);
        $this->assertEquals($includePaths, $config['include_paths']);
        $this->assertEquals($excludePaths, $config['exclude_paths']);
    }

    public function testValidateReturnsTrue(): void
    {
        $patterns = ['/test/' => MaskConstants::MASK_MASKED];
        $strategy = new RegexMaskingStrategy($patterns);

        $this->assertTrue($strategy->validate());
    }

    public function testMaskingWithSpecialCharactersInReplacement(): void
    {
        $patterns = ['/secret/' => '$1 ***MASKED*** $2'];
        $strategy = new RegexMaskingStrategy($patterns);
        $logRecord = $this->createLogRecord();

        $result = $strategy->mask('This is secret data', 'message', $logRecord);
        $this->assertStringContainsString(MaskConstants::MASK_MASKED, $result);
    }

    public function testMaskingWithCaptureGroupsInPattern(): void
    {
        $patterns = ['/(\w+)@(\w+)\.com/' => '$1@***DOMAIN***.com'];
        $strategy = new RegexMaskingStrategy($patterns);
        $logRecord = $this->createLogRecord();

        $result = $strategy->mask('Email: john@example.com', 'message', $logRecord);
        $this->assertEquals('Email: john@***DOMAIN***.com', $result);
    }

    public function testMaskingWithUtf8Characters(): void
    {
        $patterns = ['/café/' => MaskConstants::MASK_MASKED];
        $strategy = new RegexMaskingStrategy($patterns);
        $logRecord = $this->createLogRecord();

        $result = $strategy->mask('I went to the café yesterday', 'message', $logRecord);
        $this->assertStringContainsString(MaskConstants::MASK_MASKED, $result);
    }

    public function testMaskingWithCaseInsensitiveFlag(): void
    {
        $patterns = ['/secret/i' => MaskConstants::MASK_MASKED];
        $strategy = new RegexMaskingStrategy($patterns);
        $logRecord = $this->createLogRecord();

        $result1 = $strategy->mask('This is SECRET data', 'message', $logRecord);
        $result2 = $strategy->mask('This is secret data', 'message', $logRecord);
        $result3 = $strategy->mask('This is SeCrEt data', 'message', $logRecord);

        $this->assertStringContainsString(MaskConstants::MASK_MASKED, $result1);
        $this->assertStringContainsString(MaskConstants::MASK_MASKED, $result2);
        $this->assertStringContainsString(MaskConstants::MASK_MASKED, $result3);
    }
}
