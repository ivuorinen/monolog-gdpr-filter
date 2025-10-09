<?php

declare(strict_types=1);

namespace Tests\InputValidation;

use Ivuorinen\MonologGdprFilter\Exceptions\InvalidConfigurationException;
use Ivuorinen\MonologGdprFilter\Exceptions\InvalidRegexPatternException;
use Ivuorinen\MonologGdprFilter\FieldMaskConfig;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ivuorinen\MonologGdprFilter\PatternValidator;

/**
 * Tests for the GdprProcessor class.
 *
 * @api
 */
#[CoversClass(GdprProcessor::class)]
class GdprProcessorValidationTest extends TestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        // Clear pattern cache between tests
        PatternValidator::clearCache();
        parent::tearDown();
    }

    #[Test]
    public function constructorThrowsExceptionForNonStringPatternKey(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Pattern must be of type string, got integer');

        new GdprProcessor([123 => 'replacement']);
    }

    #[Test]
    public function constructorThrowsExceptionForEmptyPatternKey(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Pattern cannot be empty');

        new GdprProcessor(['' => 'replacement']);
    }

    #[Test]
    public function constructorThrowsExceptionForWhitespaceOnlyPatternKey(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Pattern cannot be empty');

        new GdprProcessor(['   ' => 'replacement']);
    }

    #[Test]
    public function constructorThrowsExceptionForNonStringPatternReplacement(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Pattern replacement must be of type string, got integer');

        new GdprProcessor(['/test/' => 123]);
    }

    #[Test]
    public function constructorThrowsExceptionForInvalidRegexPattern(): void
    {
        $this->expectException(InvalidRegexPatternException::class);
        $this->expectExceptionMessage("Invalid regex pattern 'invalid_pattern'");

        new GdprProcessor(['invalid_pattern' => 'replacement']);
    }

    #[Test]
    public function constructorAcceptsValidPatterns(): void
    {
        $processor = new GdprProcessor([
            '/\d+/' => '***NUMBER***',
            '/test/' => '***TEST***'
        ]);

        $this->assertInstanceOf(GdprProcessor::class, $processor);
    }

    #[Test]
    public function constructorThrowsExceptionForNonStringFieldPathKey(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Field path must be of type string, got integer');

        new GdprProcessor([], [123 => FieldMaskConfig::remove()]);
    }

    #[Test]
    public function constructorThrowsExceptionForEmptyFieldPathKey(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Field path cannot be empty');

        new GdprProcessor([], ['' => FieldMaskConfig::remove()]);
    }

    #[Test]
    public function constructorThrowsExceptionForWhitespaceOnlyFieldPathKey(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Field path cannot be empty');

        new GdprProcessor([], ['   ' => FieldMaskConfig::remove()]);
    }

    #[Test]
    public function constructorThrowsExceptionForInvalidFieldPathValue(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Field path value must be of type FieldMaskConfig or string, got integer');

        new GdprProcessor([], ['user.email' => 123]);
    }

    #[Test]
    public function constructorThrowsExceptionForEmptyStringFieldPathValue(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage("Cannot have empty string value");

        new GdprProcessor([], ['user.email' => '']);
    }

    #[Test]
    public function constructorAcceptsValidFieldPaths(): void
    {
        $processor = new GdprProcessor([], [
            'user.email' => FieldMaskConfig::remove(),
            'user.name' => 'masked_value',
            'payment.card' => FieldMaskConfig::replace('[CARD]')
        ]);

        $this->assertInstanceOf(GdprProcessor::class, $processor);
    }

    #[Test]
    public function constructorThrowsExceptionForNonStringCustomCallbackKey(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Custom callback path must be of type string, got integer');

        new GdprProcessor([], [], [123 => fn($value) => $value]);
    }

    #[Test]
    public function constructorThrowsExceptionForEmptyCustomCallbackKey(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Custom callback path cannot be empty');

        new GdprProcessor([], [], ['' => fn($value) => $value]);
    }

    #[Test]
    public function constructorThrowsExceptionForWhitespaceOnlyCustomCallbackKey(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Custom callback path cannot be empty');

        new GdprProcessor([], [], ['   ' => fn($value) => $value]);
    }

    #[Test]
    public function constructorThrowsExceptionForNonCallableCustomCallback(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage("Must be callable");

        new GdprProcessor([], [], ['user.id' => 'not_callable']);
    }

    #[Test]
    public function constructorAcceptsValidCustomCallbacks(): void
    {
        $processor = new GdprProcessor([], [], [
            'user.id' => fn($value): string => hash('sha256', (string) $value),
            'user.name' => fn($value) => strtoupper((string) $value)
        ]);

        $this->assertInstanceOf(GdprProcessor::class, $processor);
    }

    #[Test]
    public function constructorThrowsExceptionForNonCallableAuditLogger(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Audit logger must be of type callable or null, got string');

        new GdprProcessor([], [], [], 'not_callable');
    }

    #[Test]
    public function constructorAcceptsNullAuditLogger(): void
    {
        $processor = new GdprProcessor([], [], [], null);
        $this->assertInstanceOf(GdprProcessor::class, $processor);
    }

    #[Test]
    public function constructorAcceptsCallableAuditLogger(): void
    {
        $processor = new GdprProcessor([], [], [], fn($path, $original, $masked): null => null);
        $this->assertInstanceOf(GdprProcessor::class, $processor);
    }

    #[Test]
    public function constructorThrowsExceptionForZeroMaxDepth(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Must be a positive integer');

        new GdprProcessor([], [], [], null, 0);
    }

    #[Test]
    public function constructorThrowsExceptionForNegativeMaxDepth(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Must be a positive integer');

        new GdprProcessor([], [], [], null, -10);
    }

    #[Test]
    public function constructorThrowsExceptionForExcessiveMaxDepth(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Cannot exceed 1,000 for stack safety');

        new GdprProcessor([], [], [], null, 1001);
    }

    #[Test]
    public function constructorAcceptsValidMaxDepth(): void
    {
        $processor1 = new GdprProcessor([], [], [], null, 1);
        $this->assertInstanceOf(GdprProcessor::class, $processor1);

        $processor2 = new GdprProcessor([], [], [], null, 1000);
        $this->assertInstanceOf(GdprProcessor::class, $processor2);

        $processor3 = new GdprProcessor([], [], [], null, 100);
        $this->assertInstanceOf(GdprProcessor::class, $processor3);
    }

    #[Test]
    public function constructorThrowsExceptionForNonStringDataTypeMaskKey(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Data type mask key must be of type string, got integer');

        new GdprProcessor([], [], [], null, 100, [123 => '***MASK***']);
    }

    #[Test]
    public function constructorThrowsExceptionForInvalidDataTypeMaskKey(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage("Must be one of: integer, double, string, boolean, NULL, array, object, resource");

        new GdprProcessor([], [], [], null, 100, ['invalid_type' => '***MASK***']);
    }

    #[Test]
    public function constructorThrowsExceptionForNonStringDataTypeMaskValue(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Data type mask value must be of type string, got integer');

        new GdprProcessor([], [], [], null, 100, ['string' => 123]);
    }

    #[Test]
    public function constructorThrowsExceptionForEmptyDataTypeMaskValue(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage("Cannot be empty");

        new GdprProcessor([], [], [], null, 100, ['string' => '']);
    }

    #[Test]
    public function constructorThrowsExceptionForWhitespaceOnlyDataTypeMaskValue(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage("Cannot be empty");

        new GdprProcessor([], [], [], null, 100, ['string' => '   ']);
    }

    #[Test]
    public function constructorAcceptsValidDataTypeMasks(): void
    {
        $processor = new GdprProcessor([], [], [], null, 100, [
            'string' => '***STRING***',
            'integer' => '***INT***',
            'double' => '***FLOAT***',
            'boolean' => '***BOOL***',
            'NULL' => '***NULL***',
            'array' => '***ARRAY***',
            'object' => '***OBJECT***',
            'resource' => '***RESOURCE***'
        ]);

        $this->assertInstanceOf(GdprProcessor::class, $processor);
    }

    #[Test]
    public function constructorThrowsExceptionForNonStringConditionalRuleKey(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Conditional rule name must be of type string, got integer');

        new GdprProcessor([], [], [], null, 100, [], [123 => fn(): true => true]);
    }

    #[Test]
    public function constructorThrowsExceptionForEmptyConditionalRuleKey(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Conditional rule name cannot be empty');

        new GdprProcessor([], [], [], null, 100, [], ['' => fn(): true => true]);
    }

    #[Test]
    public function constructorThrowsExceptionForWhitespaceOnlyConditionalRuleKey(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Conditional rule name cannot be empty');

        new GdprProcessor([], [], [], null, 100, [], ['   ' => fn(): true => true]);
    }

    #[Test]
    public function constructorThrowsExceptionForNonCallableConditionalRule(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage("Must have a callable callback");

        new GdprProcessor([], [], [], null, 100, [], ['level_rule' => 'not_callable']);
    }

    #[Test]
    public function constructorAcceptsValidConditionalRules(): void
    {
        $processor = new GdprProcessor([], [], [], null, 100, [], [
            'level_rule' => fn(LogRecord $record): bool => $record->level === Level::Error,
            'channel_rule' => fn(LogRecord $record): bool => $record->channel === 'app'
        ]);

        $this->assertInstanceOf(GdprProcessor::class, $processor);
    }

    #[Test]
    public function constructorAcceptsEmptyArraysForOptionalParameters(): void
    {
        $processor = new GdprProcessor([]);
        $this->assertInstanceOf(GdprProcessor::class, $processor);
    }

    #[Test]
    public function constructorAcceptsAllParametersWithValidValues(): void
    {
        $processor = new GdprProcessor(
            patterns: ['/\d+/' => '***NUMBER***'],
            fieldPaths: ['user.email' => FieldMaskConfig::remove()],
            customCallbacks: ['user.id' => fn($value): string => hash('sha256', (string) $value)],
            auditLogger: fn($path, $original, $masked): null => null,
            maxDepth: 50,
            dataTypeMasks: ['string' => '***STRING***'],
            conditionalRules: ['level_rule' => fn(LogRecord $record): true => true]
        );

        $this->assertInstanceOf(GdprProcessor::class, $processor);
    }

    #[Test]
    public function constructorValidatesMultipleInvalidParametersAndThrowsFirstError(): void
    {
        // Should throw for the first validation error (patterns)
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Pattern must be of type string, got integer');

        new GdprProcessor(
            patterns: [123 => 'replacement'], // First error
            fieldPaths: [456 => 'value'], // Second error (won't be reached)
            maxDepth: -1 // Third error (won't be reached)
        );
    }

    #[Test]
    public function constructorHandlesComplexValidRegexPatterns(): void
    {
        $complexPatterns = [
            '/(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)/' => '***IP***',
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '***EMAIL***',
            '/\b\d{4}[-\s]?\d{4}[-\s]?\d{4}[-\s]?\d{4}\b/' => '***CARD***'
        ];

        $processor = new GdprProcessor($complexPatterns);
        $this->assertInstanceOf(GdprProcessor::class, $processor);
    }

    #[Test]
    public function constructorHandlesMixedFieldPathConfigTypes(): void
    {
        $processor = new GdprProcessor([], [
            'user.email' => FieldMaskConfig::remove(),
            'user.name' => FieldMaskConfig::replace('[REDACTED]'),
            'user.phone' => FieldMaskConfig::regexMask('/\d/', '*'),
            'metadata.ip' => 'simple_string_replacement'
        ]);

        $this->assertInstanceOf(GdprProcessor::class, $processor);
    }
}
