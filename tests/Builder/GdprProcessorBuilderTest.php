<?php

declare(strict_types=1);

namespace Tests\Builder;

use Ivuorinen\MonologGdprFilter\ArrayAccessor\ArrayAccessorFactory;
use Ivuorinen\MonologGdprFilter\Builder\GdprProcessorBuilder;
use Ivuorinen\MonologGdprFilter\Builder\PluginAwareProcessor;
use Ivuorinen\MonologGdprFilter\DefaultPatterns;
use Ivuorinen\MonologGdprFilter\FieldMaskConfig;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\MaskConstants;
use Ivuorinen\MonologGdprFilter\Plugins\AbstractMaskingPlugin;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\TestConstants;

#[CoversClass(GdprProcessorBuilder::class)]
final class GdprProcessorBuilderTest extends TestCase
{
    public function testCreateReturnsBuilder(): void
    {
        $builder = GdprProcessorBuilder::create();

        $this->assertInstanceOf(GdprProcessorBuilder::class, $builder);
    }

    public function testBuildReturnsGdprProcessor(): void
    {
        $processor = GdprProcessorBuilder::create()
            ->addPattern(TestConstants::PATTERN_TEST, MaskConstants::MASK_GENERIC)
            ->build();

        $this->assertInstanceOf(GdprProcessor::class, $processor);
    }

    public function testWithDefaultPatterns(): void
    {
        $builder = GdprProcessorBuilder::create()->withDefaultPatterns();
        $patterns = $builder->getPatterns();

        $this->assertNotEmpty($patterns);
        $this->assertSame(DefaultPatterns::get(), $patterns);
    }

    public function testAddPattern(): void
    {
        $builder = GdprProcessorBuilder::create()
            ->addPattern(TestConstants::PATTERN_DIGITS, TestConstants::MASK_DIGITS_BRACKETS);

        $this->assertArrayHasKey(TestConstants::PATTERN_DIGITS, $builder->getPatterns());
    }

    public function testAddPatterns(): void
    {
        $patterns = [
            TestConstants::PATTERN_DIGITS => TestConstants::MASK_DIGITS_BRACKETS,
            TestConstants::PATTERN_TEST => '[TEST]',
        ];

        $builder = GdprProcessorBuilder::create()->addPatterns($patterns);

        $this->assertSame($patterns, $builder->getPatterns());
    }

    public function testSetPatternsReplacesExisting(): void
    {
        $builder = GdprProcessorBuilder::create()
            ->addPattern(TestConstants::PATTERN_DIGITS, TestConstants::MASK_DIGITS_BRACKETS)
            ->setPatterns([TestConstants::PATTERN_TEST => '[TEST]']);

        $patterns = $builder->getPatterns();

        $this->assertCount(1, $patterns);
        $this->assertArrayHasKey(TestConstants::PATTERN_TEST, $patterns);
        $this->assertArrayNotHasKey(TestConstants::PATTERN_DIGITS, $patterns);
    }

    public function testAddFieldPath(): void
    {
        $builder = GdprProcessorBuilder::create()
            ->addFieldPath(TestConstants::CONTEXT_EMAIL, FieldMaskConfig::replace('[EMAIL]'));

        $this->assertArrayHasKey(TestConstants::CONTEXT_EMAIL, $builder->getFieldPaths());
    }

    public function testAddFieldPaths(): void
    {
        $fieldPaths = [
            TestConstants::CONTEXT_EMAIL => FieldMaskConfig::replace('[EMAIL]'),
            TestConstants::CONTEXT_PASSWORD => FieldMaskConfig::remove(),
        ];

        $builder = GdprProcessorBuilder::create()->addFieldPaths($fieldPaths);

        $this->assertCount(2, $builder->getFieldPaths());
    }

    public function testAddCallback(): void
    {
        $callback = fn(mixed $val): string => strtoupper((string) $val);

        $processor = GdprProcessorBuilder::create()
            ->addCallback('name', $callback)
            ->build();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: TestConstants::CHANNEL_TEST,
            level: Level::Info,
            message: TestConstants::MESSAGE_DEFAULT,
            context: ['name' => 'john']
        );

        $result = $processor($record);

        $this->assertSame('JOHN', $result->context['name']);
    }

    public function testWithAuditLogger(): void
    {
        $logs = [];
        $auditLogger = function (string $path, mixed $original, mixed $masked) use (&$logs): void {
            $logs[] = ['path' => $path, 'original' => $original, TestConstants::DATA_MASKED => $masked];
        };

        $processor = GdprProcessorBuilder::create()
            ->addFieldPath('field', FieldMaskConfig::replace('[MASKED]'))
            ->withAuditLogger($auditLogger)
            ->build();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: TestConstants::CHANNEL_TEST,
            level: Level::Info,
            message: TestConstants::MESSAGE_DEFAULT,
            context: ['field' => 'value']
        );

        $processor($record);

        $this->assertCount(1, $logs);
    }

    public function testWithMaxDepth(): void
    {
        $processor = GdprProcessorBuilder::create()
            ->addPattern(TestConstants::PATTERN_TEST, MaskConstants::MASK_GENERIC)
            ->withMaxDepth(50)
            ->build();

        // The processor should still work
        $result = $processor->regExpMessage(TestConstants::MESSAGE_TEST_LOWERCASE);

        $this->assertSame(MaskConstants::MASK_GENERIC . ' message', $result);
    }

    public function testAddDataTypeMask(): void
    {
        $processor = GdprProcessorBuilder::create()
            ->addDataTypeMask('integer', TestConstants::MASK_INT_BRACKETS)
            ->build();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: TestConstants::CHANNEL_TEST,
            level: Level::Info,
            message: TestConstants::MESSAGE_DEFAULT,
            context: ['count' => 42]
        );

        $result = $processor($record);

        $this->assertSame(TestConstants::MASK_INT_BRACKETS, $result->context['count']);
    }

    public function testAddConditionalRule(): void
    {
        $processor = GdprProcessorBuilder::create()
            ->addPattern(TestConstants::PATTERN_TEST, MaskConstants::MASK_GENERIC)
            ->addConditionalRule('skip_debug', fn(LogRecord $r): bool => $r->level !== Level::Debug)
            ->build();

        $debugRecord = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: TestConstants::CHANNEL_TEST,
            level: Level::Debug,
            message: TestConstants::MESSAGE_TEST_LOWERCASE,
            context: []
        );

        $infoRecord = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: TestConstants::CHANNEL_TEST,
            level: Level::Info,
            message: TestConstants::MESSAGE_TEST_LOWERCASE,
            context: []
        );

        // Debug should not be masked
        $this->assertSame(TestConstants::MESSAGE_TEST_LOWERCASE, $processor($debugRecord)->message);

        // Info should be masked
        $this->assertSame(MaskConstants::MASK_GENERIC . ' message', $processor($infoRecord)->message);
    }

    public function testWithArrayAccessorFactory(): void
    {
        $factory = ArrayAccessorFactory::default();

        $processor = GdprProcessorBuilder::create()
            ->addPattern(TestConstants::PATTERN_TEST, MaskConstants::MASK_GENERIC)
            ->withArrayAccessorFactory($factory)
            ->build();

        $this->assertInstanceOf(GdprProcessor::class, $processor);
    }

    public function testAddPlugin(): void
    {
        $plugin = new class extends AbstractMaskingPlugin {
            public function getName(): string
            {
                return 'test-plugin';
            }

            public function getPatterns(): array
            {
                return ['/secret/' => '[SECRET]'];
            }
        };

        $builder = GdprProcessorBuilder::create()->addPlugin($plugin);

        $this->assertCount(1, $builder->getPlugins());
    }

    public function testAddPlugins(): void
    {
        $plugin1 = new class extends AbstractMaskingPlugin {
            public function getName(): string
            {
                return 'plugin1';
            }
        };

        $plugin2 = new class extends AbstractMaskingPlugin {
            public function getName(): string
            {
                return 'plugin2';
            }
        };

        $builder = GdprProcessorBuilder::create()->addPlugins([$plugin1, $plugin2]);

        $this->assertCount(2, $builder->getPlugins());
    }

    public function testBuildWithPluginsReturnsGdprProcessorWhenNoPlugins(): void
    {
        $processor = GdprProcessorBuilder::create()
            ->addPattern(TestConstants::PATTERN_TEST, MaskConstants::MASK_GENERIC)
            ->buildWithPlugins();

        $this->assertInstanceOf(GdprProcessor::class, $processor);
        // When no plugins, it returns GdprProcessor directly, not PluginAwareProcessor
        $this->assertSame(GdprProcessor::class, $processor::class);
    }

    public function testBuildWithPluginsReturnsPluginAwareProcessor(): void
    {
        $plugin = new class extends AbstractMaskingPlugin {
            public function getName(): string
            {
                return 'test-plugin';
            }
        };

        $processor = GdprProcessorBuilder::create()
            ->addPattern(TestConstants::PATTERN_TEST, MaskConstants::MASK_GENERIC)
            ->addPlugin($plugin)
            ->buildWithPlugins();

        $this->assertInstanceOf(PluginAwareProcessor::class, $processor);
    }

    public function testPluginPatternsAreApplied(): void
    {
        $plugin = new class extends AbstractMaskingPlugin {
            public function getName(): string
            {
                return 'secret-plugin';
            }

            public function getPatterns(): array
            {
                return ['/secret/' => '[SECRET]'];
            }
        };

        $processor = GdprProcessorBuilder::create()
            ->addPlugin($plugin)
            ->build();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: TestConstants::CHANNEL_TEST,
            level: Level::Info,
            message: 'This is secret data',
            context: []
        );

        $result = $processor($record);

        $this->assertSame('This is [SECRET] data', $result->message);
    }

    public function testPluginFieldPathsAreApplied(): void
    {
        $plugin = new class extends AbstractMaskingPlugin {
            public function getName(): string
            {
                return 'field-plugin';
            }

            public function getFieldPaths(): array
            {
                return ['api_key' => FieldMaskConfig::replace('[API_KEY]')];
            }
        };

        $processor = GdprProcessorBuilder::create()
            ->addPlugin($plugin)
            ->build();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: TestConstants::CHANNEL_TEST,
            level: Level::Info,
            message: TestConstants::MESSAGE_DEFAULT,
            context: ['api_key' => 'abc123']
        );

        $result = $processor($record);

        $this->assertSame('[API_KEY]', $result->context['api_key']);
    }

    public function testFluentChaining(): void
    {
        $processor = GdprProcessorBuilder::create()
            ->withDefaultPatterns()
            ->addPattern('/custom/', '[CUSTOM]')
            ->addFieldPath('secret', FieldMaskConfig::remove())
            ->addCallback('name', fn(mixed $v): string => strtoupper((string) $v))
            ->withMaxDepth(50)
            ->addDataTypeMask('integer', TestConstants::MASK_INT_BRACKETS)
            ->build();

        $this->assertInstanceOf(GdprProcessor::class, $processor);
    }
}
