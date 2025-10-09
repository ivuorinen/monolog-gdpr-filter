<?php

declare(strict_types=1);

namespace Tests;

use Ivuorinen\MonologGdprFilter\Exceptions\InvalidConfigurationException;
use Ivuorinen\MonologGdprFilter\Exceptions\InvalidRegexPatternException;
use Ivuorinen\MonologGdprFilter\FieldMaskConfig;
use Ivuorinen\MonologGdprFilter\InputValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InputValidator::class)]
final class InputValidatorTest extends TestCase
{
    #[Test]
    public function validateAllPassesWithValidInputs(): void
    {
        $patterns = ['/\d{3}-\d{2}-\d{4}/' => '***'];
        $fieldPaths = ['user.email' => '***'];
        $customCallbacks = ['user.id' => fn($value): string => (string) $value];
        $auditLogger = fn($field, $old, $new): null => null;
        $maxDepth = 10;
        $dataTypeMasks = ['string' => '***'];
        $conditionalRules = ['rule1' => fn($value): true => true];

        InputValidator::validateAll(
            $patterns,
            $fieldPaths,
            $customCallbacks,
            $auditLogger,
            $maxDepth,
            $dataTypeMasks,
            $conditionalRules
        );

        $this->assertTrue(true); // If we get here, validation passed
    }

    #[Test]
    public function validatePatternsThrowsForNonStringPattern(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('pattern');
        $this->expectExceptionMessage('string');

        InputValidator::validatePatterns([123 => '***']);
    }

    #[Test]
    public function validatePatternsThrowsForEmptyPattern(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('pattern');
        $this->expectExceptionMessage('empty');

        InputValidator::validatePatterns(['' => '***']);
    }

    #[Test]
    public function validatePatternsThrowsForNonStringReplacement(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('replacement');
        $this->expectExceptionMessage('string');

        InputValidator::validatePatterns(['/test/' => 123]);
    }

    #[Test]
    public function validatePatternsThrowsForInvalidRegex(): void
    {
        $this->expectException(InvalidRegexPatternException::class);

        InputValidator::validatePatterns(['/[invalid/' => '***']);
    }

    #[Test]
    public function validatePatternsPassesForValidPatterns(): void
    {
        InputValidator::validatePatterns([
            '/\d{3}-\d{2}-\d{4}/' => '***-**-****',
            '/[a-z]+/' => 'REDACTED',
        ]);

        $this->assertTrue(true);
    }

    #[Test]
    public function validateFieldPathsThrowsForNonStringPath(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('field path');
        $this->expectExceptionMessage('string');

        InputValidator::validateFieldPaths([123 => '***']);
    }

    #[Test]
    public function validateFieldPathsThrowsForEmptyPath(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('field path');
        $this->expectExceptionMessage('empty');

        InputValidator::validateFieldPaths(['' => '***']);
    }

    #[Test]
    public function validateFieldPathsThrowsForInvalidConfigType(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('field path value');

        InputValidator::validateFieldPaths(['user.email' => 123]);
    }

    #[Test]
    public function validateFieldPathsThrowsForEmptyStringValue(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('user.email');
        $this->expectExceptionMessage('empty string');

        InputValidator::validateFieldPaths(['user.email' => '']);
    }

    #[Test]
    public function validateFieldPathsPassesForValidPaths(): void
    {
        InputValidator::validateFieldPaths([
            'user.email' => '***@***.***',
            'user.password' => FieldMaskConfig::remove(),
            'user.ssn' => FieldMaskConfig::regexMask('/\d{3}-\d{2}-\d{4}/', '***-**-****'),
        ]);

        $this->assertTrue(true);
    }

    #[Test]
    public function validateCustomCallbacksThrowsForNonStringPath(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('custom callback path');
        $this->expectExceptionMessage('string');

        InputValidator::validateCustomCallbacks([123 => fn($v): string => (string) $v]);
    }

    #[Test]
    public function validateCustomCallbacksThrowsForEmptyPath(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('custom callback path');
        $this->expectExceptionMessage('empty');

        InputValidator::validateCustomCallbacks(['' => fn($v): string => (string) $v]);
    }

    #[Test]
    public function validateCustomCallbacksThrowsForNonCallable(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('custom callback');
        $this->expectExceptionMessage('callable');

        InputValidator::validateCustomCallbacks(['user.id' => 'not-a-callback']);
    }

    #[Test]
    public function validateCustomCallbacksPassesForValidCallbacks(): void
    {
        InputValidator::validateCustomCallbacks([
            'user.id' => fn($value): string => (string) $value,
            'user.name' => fn($value) => strtoupper((string) $value),
        ]);

        $this->assertTrue(true);
    }

    #[Test]
    public function validateAuditLoggerThrowsForNonCallable(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('audit logger');
        $this->expectExceptionMessage('callable');

        InputValidator::validateAuditLogger('not-a-callback');
    }

    #[Test]
    public function validateAuditLoggerPassesForNull(): void
    {
        InputValidator::validateAuditLogger(null);
        $this->assertTrue(true);
    }

    #[Test]
    public function validateAuditLoggerPassesForCallable(): void
    {
        InputValidator::validateAuditLogger(fn($field, $old, $new): null => null);
        $this->assertTrue(true);
    }

    #[Test]
    public function validateMaxDepthThrowsForZero(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('max_depth');
        $this->expectExceptionMessage('positive integer');

        InputValidator::validateMaxDepth(0);
    }

    #[Test]
    public function validateMaxDepthThrowsForNegative(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('max_depth');
        $this->expectExceptionMessage('positive integer');

        InputValidator::validateMaxDepth(-1);
    }

    #[Test]
    public function validateMaxDepthThrowsForTooLarge(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('max_depth');
        $this->expectExceptionMessage('1,000');

        InputValidator::validateMaxDepth(1001);
    }

    #[Test]
    public function validateMaxDepthPassesForValidValue(): void
    {
        InputValidator::validateMaxDepth(10);
        InputValidator::validateMaxDepth(1);
        InputValidator::validateMaxDepth(1000);

        $this->assertTrue(true);
    }

    #[Test]
    public function validateDataTypeMasksThrowsForNonStringType(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('data type mask key');
        $this->expectExceptionMessage('string');

        InputValidator::validateDataTypeMasks([123 => '***']);
    }

    #[Test]
    public function validateDataTypeMasksThrowsForInvalidType(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('invalid_type');
        $this->expectExceptionMessage('integer, double, string, boolean');

        InputValidator::validateDataTypeMasks(['invalid_type' => '***']);
    }

    #[Test]
    public function validateDataTypeMasksThrowsForNonStringMask(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('data type mask value');
        $this->expectExceptionMessage('string');

        InputValidator::validateDataTypeMasks(['string' => 123]);
    }

    #[Test]
    public function validateDataTypeMasksThrowsForEmptyMask(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('string');
        $this->expectExceptionMessage('empty');

        InputValidator::validateDataTypeMasks(['string' => '']);
    }

    #[Test]
    public function validateDataTypeMasksPassesForValidTypes(): void
    {
        InputValidator::validateDataTypeMasks([
            'integer' => '***',
            'double' => '***',
            'string' => 'REDACTED',
            'boolean' => '***',
            'NULL' => 'null',
            'array' => '[]',
            'object' => '{}',
            'resource' => 'RESOURCE',
        ]);

        $this->assertTrue(true);
    }

    #[Test]
    public function validateConditionalRulesThrowsForNonStringRuleName(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('conditional rule name');
        $this->expectExceptionMessage('string');

        InputValidator::validateConditionalRules([123 => fn($v): true => true]);
    }

    #[Test]
    public function validateConditionalRulesThrowsForEmptyRuleName(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('conditional rule name');
        $this->expectExceptionMessage('empty');

        InputValidator::validateConditionalRules(['' => fn($v): true => true]);
    }

    #[Test]
    public function validateConditionalRulesThrowsForNonCallable(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('rule1');
        $this->expectExceptionMessage('callable');

        InputValidator::validateConditionalRules(['rule1' => 'not-a-callback']);
    }

    #[Test]
    public function validateConditionalRulesPassesForValidRules(): void
    {
        InputValidator::validateConditionalRules([
            'rule1' => fn($value): bool => $value > 100,
            'rule2' => fn($value): bool => is_string($value),
        ]);

        $this->assertTrue(true);
    }
}
