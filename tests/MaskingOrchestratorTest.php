<?php

declare(strict_types=1);

namespace Tests;

use Ivuorinen\MonologGdprFilter\Exceptions\InvalidConfigurationException;
use Ivuorinen\MonologGdprFilter\ArrayAccessor\ArrayAccessorFactory;
use Ivuorinen\MonologGdprFilter\ContextProcessor;
use Ivuorinen\MonologGdprFilter\FieldMaskConfig;
use Ivuorinen\MonologGdprFilter\MaskConstants;
use Ivuorinen\MonologGdprFilter\MaskingOrchestrator;
use Ivuorinen\MonologGdprFilter\RecursiveProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MaskingOrchestrator::class)]
final class MaskingOrchestratorTest extends TestCase
{
    public function testProcessMasksMessage(): void
    {
        $orchestrator = new MaskingOrchestrator(
            [TestConstants::PATTERN_TEST => MaskConstants::MASK_GENERIC]
        );

        $result = $orchestrator->process('This is a test message', []);

        $this->assertSame('This is a ' . MaskConstants::MASK_GENERIC . ' message', $result['message']);
        $this->assertSame([], $result['context']);
    }

    public function testProcessMasksContext(): void
    {
        $orchestrator = new MaskingOrchestrator(
            [TestConstants::PATTERN_TEST => MaskConstants::MASK_GENERIC]
        );

        $result = $orchestrator->process('message', ['key' => 'test value']);

        $this->assertSame('message', $result['message']);
        $this->assertSame(MaskConstants::MASK_GENERIC . ' value', $result['context']['key']);
    }

    public function testProcessMasksFieldPaths(): void
    {
        $orchestrator = new MaskingOrchestrator(
            [],
            [TestConstants::CONTEXT_EMAIL => FieldMaskConfig::replace('[EMAIL]')]
        );

        $result = $orchestrator->process('message', [TestConstants::CONTEXT_EMAIL => TestConstants::EMAIL_TEST]);

        $this->assertSame('[EMAIL]', $result['context'][TestConstants::CONTEXT_EMAIL]);
    }

    public function testProcessExecutesCustomCallbacks(): void
    {
        $orchestrator = new MaskingOrchestrator(
            [],
            [],
            ['name' => fn(mixed $val): string => strtoupper((string) $val)]
        );

        $result = $orchestrator->process('message', ['name' => 'john']);

        $this->assertSame('JOHN', $result['context']['name']);
    }

    public function testProcessContextDirectly(): void
    {
        $orchestrator = new MaskingOrchestrator(
            [TestConstants::PATTERN_TEST => MaskConstants::MASK_GENERIC]
        );

        $result = $orchestrator->processContext(['key' => 'test value']);

        $this->assertSame(MaskConstants::MASK_GENERIC . ' value', $result['key']);
    }

    public function testRegExpMessageMasksPatterns(): void
    {
        $orchestrator = new MaskingOrchestrator(
            [TestConstants::PATTERN_SSN_FORMAT => '[SSN]']
        );

        $result = $orchestrator->regExpMessage('SSN: 123-45-6789');

        $this->assertSame('SSN: [SSN]', $result);
    }

    public function testRegExpMessagePreservesEmptyString(): void
    {
        $orchestrator = new MaskingOrchestrator([TestConstants::PATTERN_TEST => MaskConstants::MASK_GENERIC]);

        $result = $orchestrator->regExpMessage('');

        $this->assertSame('', $result);
    }

    public function testRecursiveMaskMasksNestedArrays(): void
    {
        $orchestrator = new MaskingOrchestrator(
            [TestConstants::PATTERN_TEST => MaskConstants::MASK_GENERIC]
        );

        $result = $orchestrator->recursiveMask(['level1' => ['level2' => 'test value']]);

        $this->assertIsArray($result);
        $this->assertSame(MaskConstants::MASK_GENERIC . ' value', $result['level1']['level2']);
    }

    public function testRecursiveMaskMasksString(): void
    {
        $orchestrator = new MaskingOrchestrator(
            [TestConstants::PATTERN_TEST => MaskConstants::MASK_GENERIC]
        );

        $result = $orchestrator->recursiveMask('test string');

        $this->assertSame(MaskConstants::MASK_GENERIC . ' string', $result);
    }

    public function testCreateValidatesParameters(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        MaskingOrchestrator::create(
            [],
            [],
            [],
            null,
            -1 // Invalid depth
        );
    }

    public function testCreateWithValidParameters(): void
    {
        $orchestrator = MaskingOrchestrator::create(
            [TestConstants::PATTERN_DIGITS => '[DIGITS]'],
            [],
            [],
            null,
            50
        );

        $result = $orchestrator->regExpMessage('Number: 12345');

        $this->assertSame('Number: [DIGITS]', $result);
    }

    public function testGetContextProcessor(): void
    {
        $orchestrator = new MaskingOrchestrator([]);

        $this->assertInstanceOf(ContextProcessor::class, $orchestrator->getContextProcessor());
    }

    public function testGetRecursiveProcessor(): void
    {
        $orchestrator = new MaskingOrchestrator([]);

        $this->assertInstanceOf(RecursiveProcessor::class, $orchestrator->getRecursiveProcessor());
    }

    public function testGetArrayAccessorFactory(): void
    {
        $orchestrator = new MaskingOrchestrator([]);

        $this->assertInstanceOf(ArrayAccessorFactory::class, $orchestrator->getArrayAccessorFactory());
    }

    public function testSetAuditLogger(): void
    {
        $logs = [];
        $auditLogger = function (string $path, mixed $original, mixed $masked) use (&$logs): void {
            $logs[] = ['path' => $path, 'original' => $original, TestConstants::DATA_MASKED => $masked];
        };

        $orchestrator = new MaskingOrchestrator(
            [],
            ['field' => FieldMaskConfig::replace('[MASKED]')]
        );

        $orchestrator->setAuditLogger($auditLogger);
        $orchestrator->processContext(['field' => 'value']);

        $this->assertCount(1, $logs);
        $this->assertSame('field', $logs[0]['path']);
    }

    public function testWithCustomArrayAccessorFactory(): void
    {
        $customFactory = ArrayAccessorFactory::default();

        $orchestrator = new MaskingOrchestrator(
            [],
            [],
            [],
            null,
            100,
            [],
            $customFactory
        );

        $this->assertSame($customFactory, $orchestrator->getArrayAccessorFactory());
    }

    public function testProcessWithFieldPathsAndCustomCallbacksCombined(): void
    {
        $orchestrator = new MaskingOrchestrator(
            [TestConstants::PATTERN_TEST => MaskConstants::MASK_GENERIC],
            [TestConstants::CONTEXT_EMAIL => FieldMaskConfig::replace('[EMAIL]')],
            ['name' => fn(mixed $val): string => strtoupper((string) $val)]
        );

        $result = $orchestrator->process(
            'Hello test',
            [
                TestConstants::CONTEXT_EMAIL => TestConstants::EMAIL_TEST,
                'name' => 'john',
                'message' => 'test'
            ]
        );

        $this->assertSame('Hello ' . MaskConstants::MASK_GENERIC, $result['message']);
        $this->assertSame('[EMAIL]', $result['context'][TestConstants::CONTEXT_EMAIL]);
        $this->assertSame('JOHN', $result['context']['name']);
    }

    public function testProcessWithDataTypeMasks(): void
    {
        $orchestrator = new MaskingOrchestrator(
            [],
            [],
            [],
            null,
            100,
            ['integer' => '[INT]']
        );

        $result = $orchestrator->processContext(['count' => 42]);

        $this->assertSame('[INT]', $result['count']);
    }

    public function testProcessContextWithRemoveConfig(): void
    {
        $orchestrator = new MaskingOrchestrator(
            [],
            ['secret' => FieldMaskConfig::remove()]
        );

        $result = $orchestrator->processContext(['secret' => 'value', 'public' => 'data']);

        $this->assertArrayNotHasKey('secret', $result);
        $this->assertSame('data', $result['public']);
    }
}
