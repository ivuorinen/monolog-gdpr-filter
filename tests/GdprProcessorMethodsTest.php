<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\FieldMaskConfig;
use PHPUnit\Framework\TestCase;
use Adbar\Dot;

#[CoversClass(className: GdprProcessor::class)]
#[CoversMethod(className: GdprProcessor::class, methodName: '__invoke')]
#[CoversMethod(className: GdprProcessor::class, methodName: 'maskFieldPaths')]
#[CoversMethod(className: GdprProcessor::class, methodName: 'maskValue')]
#[CoversMethod(className: GdprProcessor::class, methodName: 'logAudit')]
class GdprProcessorMethodsTest extends TestCase
{
    use TestHelpers;

    public function testMaskFieldPathsSetsMaskedValueAndRemovesField(): void
    {
        $patterns = [
            '/john.doe/' => 'bar',
        ];
        $fieldPaths = [
            'user.email' => GdprProcessor::maskWithRegex(),
            'user.ssn' => GdprProcessor::removeField(),
            'user.card' => GdprProcessor::replaceWith('MASKED'),
        ];
        $context = [
            'user' => [
                'email' => self::TEST_EMAIL,
                'ssn' => self::TEST_HETU,
                'card' => self::TEST_CC,
            ],
        ];
        $accessor = new Dot($context);
        $processor = new GdprProcessor($patterns, $fieldPaths);
        $method = $this->getReflection($processor, 'maskFieldPaths');
        $method->invoke($processor, $accessor);

        $result = $accessor->all();
        $this->assertSame('bar@example.com', $result['user']['email']);
        $this->assertSame('MASKED', $result['user']['card']);
        $this->assertArrayNotHasKey('ssn', $result['user']);
    }

    public function testMaskValueWithCustomCallback(): void
    {
        $patterns = [];
        $fieldPaths = [
            'user.name' => GdprProcessor::maskWithRegex(),
        ];
        $customCallbacks = [
            'user.name' => fn($value) => strtoupper((string) $value),
        ];
        $processor = new GdprProcessor($patterns, $fieldPaths, $customCallbacks);
        $method = $this->getReflection($processor, 'maskValue');
        $result = $method->invoke($processor, 'user.name', 'john', $fieldPaths['user.name']);
        $this->assertSame(['masked' => 'JOHN', 'remove' => false], $result);
    }

    public function testMaskValueWithRemove(): void
    {
        $patterns = [];
        $fieldPaths = [
            'user.ssn' => GdprProcessor::removeField(),
        ];
        $processor = new GdprProcessor($patterns, $fieldPaths);
        $method = $this->getReflection($processor, 'maskValue');
        $result = $method->invoke($processor, 'user.ssn', self::TEST_HETU, $fieldPaths['user.ssn']);
        $this->assertSame(['masked' => null, 'remove' => true], $result);
    }

    public function testMaskValueWithReplace(): void
    {
        $patterns = [];
        $fieldPaths = [
            'user.card' => GdprProcessor::replaceWith('MASKED'),
        ];
        $processor = new GdprProcessor($patterns, $fieldPaths);
        $method = $this->getReflection($processor, 'maskValue');
        $result = $method->invoke($processor, 'user.card', self::TEST_CC, $fieldPaths['user.card']);
        $this->assertSame(['masked' => 'MASKED', 'remove' => false], $result);
    }

    public function testLogAuditIsCalled(): void
    {
        $patterns = [];
        $fieldPaths = [];
        $calls = [];
        $auditLogger = function ($path, $original, $masked) use (&$calls): void {
            $calls[] = [$path, $original, $masked];
        };
        $processor = new GdprProcessor($patterns, $fieldPaths, [], $auditLogger);
        $method = $this->getReflection($processor, 'logAudit');
        $method->invoke($processor, 'user.email', self::TEST_EMAIL, 'MASKED');
        $this->assertNotEmpty($calls);
        $this->assertSame(['user.email', self::TEST_EMAIL, 'MASKED'], $calls[0]);
    }

    public function testMaskValueWithDefaultCase(): void
    {
        $patterns = [];
        $fieldPaths = [
            'user.unknown' => new FieldMaskConfig('999'), // unknown type
        ];
        $processor = new GdprProcessor($patterns, $fieldPaths);
        $method = $this->getReflection($processor, 'maskValue');
        $result = $method->invoke($processor, 'user.unknown', 'foo', $fieldPaths['user.unknown']);
        $this->assertSame(['masked' => '999', 'remove' => false], $result);
    }

    public function testMaskValueWithStringConfigBackwardCompatibility(): void
    {
        $patterns = [];
        $fieldPaths = [
            'user.simple' => 'MASKED',
        ];
        $processor = new GdprProcessor($patterns, $fieldPaths);
        $method = $this->getReflection($processor, 'maskValue');
        $result = $method->invoke($processor, 'user.simple', 'foo', $fieldPaths['user.simple']);
        $this->assertSame(['masked' => 'MASKED', 'remove' => false], $result);
    }
}
