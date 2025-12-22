<?php

declare(strict_types=1);

namespace Tests\Strategies;

use Ivuorinen\MonologGdprFilter\Exceptions\InvalidRegexPatternException;
use Ivuorinen\MonologGdprFilter\MaskConstants;
use PHPUnit\Framework\TestCase;
use Tests\TestHelpers;
use Tests\TestConstants;
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
            new RegexMaskingStrategy(['/(a*)+/' => TestConstants::DATA_MASKED]);
            $this->fail('Expected InvalidRegexPatternException for (x*)+');
        } catch (InvalidRegexPatternException $e) {
            $this->assertStringContainsString('ReDoS', $e->getMessage());
        }

        // Test pattern 2: (x+)+
        try {
            new RegexMaskingStrategy(['/(b+)+/' => TestConstants::DATA_MASKED]);
            $this->fail('Expected InvalidRegexPatternException for (x+)+');
        } catch (InvalidRegexPatternException $e) {
            $this->assertStringContainsString('ReDoS', $e->getMessage());
        }

        // Test pattern 3: (x*)*
        try {
            new RegexMaskingStrategy(['/(c*)*/' => TestConstants::DATA_MASKED]);
            $this->fail('Expected InvalidRegexPatternException for (x*)*');
        } catch (InvalidRegexPatternException $e) {
            $this->assertStringContainsString('ReDoS', $e->getMessage());
        }

        // Test pattern 4: (x+)*
        try {
            new RegexMaskingStrategy(['/(d+)*/' => TestConstants::DATA_MASKED]);
            $this->fail('Expected InvalidRegexPatternException for (x+)*');
        } catch (InvalidRegexPatternException $e) {
            $this->assertStringContainsString('ReDoS', $e->getMessage());
        }
    }

    public function testReDoSDetectionWithOverlappingAlternations(): void
    {
        // Test (.*|.*)
        try {
            new RegexMaskingStrategy(['/^(.*|.*)$/' => TestConstants::DATA_MASKED]);
            $this->fail('Expected InvalidRegexPatternException for overlapping alternations');
        } catch (InvalidRegexPatternException $e) {
            $this->assertStringContainsString('ReDoS', $e->getMessage());
        }

        // Test (a|ab|abc|abcd)*
        try {
            new RegexMaskingStrategy(['/^(a|ab|abc|abcd)*$/' => TestConstants::DATA_MASKED]);
            $this->fail('Expected InvalidRegexPatternException for expanding alternations');
        } catch (InvalidRegexPatternException $e) {
            $this->assertStringContainsString('ReDoS', $e->getMessage());
        }
    }

    public function testMultiplePatternsWithOneFailure(): void
    {
        $patterns = [
            '/\b\d{3}-\d{2}-\d{4}\b/' => MaskConstants::MASK_SSN,
            '/email\w+@\w+\.com/' => MaskConstants::MASK_EMAIL,
        ];

        $strategy = new RegexMaskingStrategy($patterns);
        $logRecord = $this->createLogRecord();

        // Should successfully apply all valid patterns
        $input = 'SSN: 123-45-6789, Email: emailtest@example.com';
        $result = $strategy->mask($input, TestConstants::FIELD_MESSAGE, $logRecord);
        $this->assertStringContainsString(MaskConstants::MASK_SSN, $result);
        $this->assertStringContainsString(MaskConstants::MASK_EMAIL, $result);
    }

    public function testEmptyPatternIsRejected(): void
    {
        $this->expectException(InvalidRegexPatternException::class);
        new RegexMaskingStrategy(['' => TestConstants::DATA_MASKED]);
    }

    public function testPatternWithInvalidDelimiter(): void
    {
        $this->expectException(InvalidRegexPatternException::class);
        new RegexMaskingStrategy(['invalid_pattern' => TestConstants::DATA_MASKED]);
    }

    public function testPatternWithMismatchedBrackets(): void
    {
        $this->expectException(InvalidRegexPatternException::class);
        new RegexMaskingStrategy(['/[abc/' => TestConstants::DATA_MASKED]);
    }

    public function testPatternWithInvalidEscape(): void
    {
        $this->expectException(InvalidRegexPatternException::class);
        new RegexMaskingStrategy(['/\k/' => TestConstants::DATA_MASKED]);
    }

    public function testMaskingValueThatDoesNotMatch(): void
    {
        $patterns = [TestConstants::PATTERN_SECRET => MaskConstants::MASK_MASKED];
        $strategy = new RegexMaskingStrategy($patterns);
        $logRecord = $this->createLogRecord();

        // Value that doesn't match should be returned unchanged
        $result = $strategy->mask('public information', TestConstants::FIELD_MESSAGE, $logRecord);
        $this->assertEquals('public information', $result);
    }

    public function testShouldApplyWithIncludePathsOnly(): void
    {
        $patterns = [TestConstants::PATTERN_TEST => MaskConstants::MASK_MASKED];
        $includePaths = [TestConstants::PATH_USER_WILDCARD, 'admin.log'];
        $strategy = new RegexMaskingStrategy($patterns, $includePaths);
        $logRecord = $this->createLogRecord();

        // Should apply to matching content in included paths
        $this->assertTrue($strategy->shouldApply(
            TestConstants::DATA_TEST_DATA,
            TestConstants::FIELD_USER_EMAIL,
            $logRecord
        ));
        $this->assertTrue($strategy->shouldApply(
            TestConstants::DATA_TEST_DATA,
            'admin.log',
            $logRecord
        ));

        // Should not apply to non-included paths even if content matches
        $this->assertFalse($strategy->shouldApply(
            TestConstants::DATA_TEST_DATA,
            'system.info',
            $logRecord
        ));
    }

    public function testShouldApplyWithExcludePathsPrecedence(): void
    {
        $patterns = [TestConstants::PATTERN_TEST => MaskConstants::MASK_MASKED];
        $includePaths = [TestConstants::PATH_USER_WILDCARD];
        $excludePaths = ['user.id', 'user.created_at'];
        $strategy = new RegexMaskingStrategy($patterns, $includePaths, $excludePaths);
        $logRecord = $this->createLogRecord();

        // Should apply to included but not excluded
        $this->assertTrue($strategy->shouldApply(
            TestConstants::DATA_TEST_DATA,
            TestConstants::FIELD_USER_EMAIL,
            $logRecord
        ));

        // Should not apply to excluded paths
        $this->assertFalse($strategy->shouldApply(TestConstants::DATA_TEST_DATA, 'user.id', $logRecord));
        $this->assertFalse($strategy->shouldApply(TestConstants::DATA_TEST_DATA, 'user.created_at', $logRecord));
    }

    public function testShouldNotApplyWhenContentDoesNotMatch(): void
    {
        $patterns = [TestConstants::PATTERN_SECRET => MaskConstants::MASK_MASKED];
        $strategy = new RegexMaskingStrategy($patterns);
        $logRecord = $this->createLogRecord();

        // Should return false when content doesn't match patterns
        $this->assertFalse($strategy->shouldApply(
            TestConstants::DATA_PUBLIC,
            TestConstants::FIELD_MESSAGE,
            $logRecord
        ));
        $this->assertFalse($strategy->shouldApply(
            'no sensitive info',
            'context.field',
            $logRecord
        ));
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
            '/email/' => MaskConstants::MASK_EMAIL,
            '/phone/' => MaskConstants::MASK_PHONE,
            '/ssn/' => MaskConstants::MASK_SSN,
        ];
        $strategy = new RegexMaskingStrategy($patterns);

        $name = $strategy->getName();
        $this->assertStringContainsString('Regex Pattern Masking', $name);
        $this->assertStringContainsString('3 patterns', $name);
    }

    public function testGetNameWithSinglePattern(): void
    {
        $patterns = [TestConstants::PATTERN_TEST => MaskConstants::MASK_MASKED];
        $strategy = new RegexMaskingStrategy($patterns);

        $name = $strategy->getName();
        $this->assertStringContainsString('1 pattern', $name);
    }

    public function testGetPriorityDefaultValue(): void
    {
        $patterns = [TestConstants::PATTERN_TEST => MaskConstants::MASK_MASKED];
        $strategy = new RegexMaskingStrategy($patterns);

        $this->assertEquals(60, $strategy->getPriority());
    }

    public function testGetPriorityCustomValue(): void
    {
        $patterns = [TestConstants::PATTERN_TEST => MaskConstants::MASK_MASKED];
        $strategy = new RegexMaskingStrategy($patterns, [], [], 75);

        $this->assertEquals(75, $strategy->getPriority());
    }

    public function testGetConfiguration(): void
    {
        $patterns = [TestConstants::PATTERN_TEST => MaskConstants::MASK_MASKED];
        $includePaths = [TestConstants::PATH_USER_WILDCARD];
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
        $patterns = [TestConstants::PATTERN_TEST => MaskConstants::MASK_MASKED];
        $strategy = new RegexMaskingStrategy($patterns);

        $this->assertTrue($strategy->validate());
    }

    public function testMaskingWithSpecialCharactersInReplacement(): void
    {
        $patterns = [TestConstants::PATTERN_SECRET => '$1 ***MASKED*** $2'];
        $strategy = new RegexMaskingStrategy($patterns);
        $logRecord = $this->createLogRecord();

        $result = $strategy->mask('This is secret data', TestConstants::FIELD_MESSAGE, $logRecord);
        $this->assertStringContainsString(MaskConstants::MASK_MASKED, $result);
    }

    public function testMaskingWithCaptureGroupsInPattern(): void
    {
        $patterns = ['/(\w+)@(\w+)\.com/' => '$1@***DOMAIN***.com'];
        $strategy = new RegexMaskingStrategy($patterns);
        $logRecord = $this->createLogRecord();

        $result = $strategy->mask('Email: john@example.com', TestConstants::FIELD_MESSAGE, $logRecord);
        $this->assertEquals('Email: john@***DOMAIN***.com', $result);
    }

    public function testMaskingWithUtf8Characters(): void
    {
        $patterns = ['/café/' => MaskConstants::MASK_MASKED];
        $strategy = new RegexMaskingStrategy($patterns);
        $logRecord = $this->createLogRecord();

        $result = $strategy->mask('I went to the café yesterday', TestConstants::FIELD_MESSAGE, $logRecord);
        $this->assertStringContainsString(MaskConstants::MASK_MASKED, $result);
    }

    public function testMaskingWithCaseInsensitiveFlag(): void
    {
        $patterns = ['/secret/i' => MaskConstants::MASK_MASKED];
        $strategy = new RegexMaskingStrategy($patterns);
        $logRecord = $this->createLogRecord();

        $result1 = $strategy->mask('This is SECRET data', TestConstants::FIELD_MESSAGE, $logRecord);
        $result2 = $strategy->mask('This is secret data', TestConstants::FIELD_MESSAGE, $logRecord);
        $result3 = $strategy->mask('This is SeCrEt data', TestConstants::FIELD_MESSAGE, $logRecord);

        $this->assertStringContainsString(MaskConstants::MASK_MASKED, $result1);
        $this->assertStringContainsString(MaskConstants::MASK_MASKED, $result2);
        $this->assertStringContainsString(MaskConstants::MASK_MASKED, $result3);
    }
}
