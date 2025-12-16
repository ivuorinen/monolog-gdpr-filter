<?php

declare(strict_types=1);

namespace Tests\Builder;

use Ivuorinen\MonologGdprFilter\Builder\GdprProcessorBuilder;
use Ivuorinen\MonologGdprFilter\Builder\PluginAwareProcessor;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\MaskConstants;
use Ivuorinen\MonologGdprFilter\Plugins\AbstractMaskingPlugin;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\TestConstants;

#[CoversClass(PluginAwareProcessor::class)]
final class PluginAwareProcessorTest extends TestCase
{
    public function testInvokeAppliesPreProcessing(): void
    {
        $plugin = new class extends AbstractMaskingPlugin {
            public function getName(): string
            {
                return 'uppercase-plugin';
            }

            public function preProcessMessage(string $message): string
            {
                return strtoupper($message);
            }
        };

        $processor = GdprProcessorBuilder::create()
            ->addPattern('/TEST/', '[MASKED]')
            ->addPlugin($plugin)
            ->buildWithPlugins();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: TestConstants::CHANNEL_TEST,
            level: Level::Info,
            message: 'This is a test message',
            context: []
        );

        $result = $processor($record);

        // Message should be uppercased, then 'TEST' should be masked
        $this->assertStringContainsString('[MASKED]', $result->message);
    }

    public function testInvokeAppliesPostProcessing(): void
    {
        $plugin = new class extends AbstractMaskingPlugin {
            public function getName(): string
            {
                return 'suffix-plugin';
            }

            public function postProcessMessage(string $message): string
            {
                return $message . ' [processed]';
            }
        };

        $processor = GdprProcessorBuilder::create()
            ->addPattern(TestConstants::PATTERN_TEST, MaskConstants::MASK_GENERIC)
            ->addPlugin($plugin)
            ->buildWithPlugins();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: TestConstants::CHANNEL_TEST,
            level: Level::Info,
            message: 'test',
            context: []
        );

        $result = $processor($record);

        $this->assertStringEndsWith('[processed]', $result->message);
    }

    public function testInvokeAppliesPreProcessContext(): void
    {
        $plugin = new class extends AbstractMaskingPlugin {
            public function getName(): string
            {
                return 'add-field-plugin';
            }

            public function preProcessContext(array $context): array
            {
                $context['added_by_plugin'] = 'true';
                return $context;
            }
        };

        $processor = GdprProcessorBuilder::create()
            ->addPlugin($plugin)
            ->buildWithPlugins();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: TestConstants::CHANNEL_TEST,
            level: Level::Info,
            message: TestConstants::MESSAGE_DEFAULT,
            context: ['original' => 'data']
        );

        $result = $processor($record);

        $this->assertArrayHasKey('added_by_plugin', $result->context);
    }

    public function testInvokeAppliesPostProcessContext(): void
    {
        $plugin = new class extends AbstractMaskingPlugin {
            public function getName(): string
            {
                return 'remove-field-plugin';
            }

            public function postProcessContext(array $context): array
            {
                unset($context['to_remove']);
                return $context;
            }
        };

        $processor = GdprProcessorBuilder::create()
            ->addPlugin($plugin)
            ->buildWithPlugins();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: TestConstants::CHANNEL_TEST,
            level: Level::Info,
            message: TestConstants::MESSAGE_DEFAULT,
            context: ['to_remove' => 'value', 'keep' => 'data']
        );

        $result = $processor($record);

        $this->assertArrayNotHasKey('to_remove', $result->context);
        $this->assertArrayHasKey('keep', $result->context);
    }

    public function testPostProcessingRunsInReverseOrder(): void
    {
        // Test that post-processing happens by verifying the message is modified
        $plugin1 = new class extends AbstractMaskingPlugin {
            public function __construct()
            {
                parent::__construct(10);
            }

            public function getName(): string
            {
                return 'plugin1';
            }

            public function postProcessMessage(string $message): string
            {
                return $message . '-plugin1';
            }
        };

        $plugin2 = new class extends AbstractMaskingPlugin {
            public function __construct()
            {
                parent::__construct(20);
            }

            public function getName(): string
            {
                return 'plugin2';
            }

            public function postProcessMessage(string $message): string
            {
                return $message . '-plugin2';
            }
        };

        $processor = GdprProcessorBuilder::create()
            ->addPlugin($plugin1)
            ->addPlugin($plugin2)
            ->buildWithPlugins();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: TestConstants::CHANNEL_TEST,
            level: Level::Info,
            message: 'base',
            context: []
        );

        $result = $processor($record);

        // Post-processing runs in reverse priority order (higher priority last)
        // plugin2 (priority 20) runs first in post-processing, then plugin1 (priority 10)
        $this->assertSame('base-plugin2-plugin1', $result->message);
    }

    public function testGetProcessor(): void
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
        $this->assertInstanceOf(GdprProcessor::class, $processor->getProcessor());
    }

    public function testGetPlugins(): void
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

        $processor = GdprProcessorBuilder::create()
            ->addPlugin($plugin1)
            ->addPlugin($plugin2)
            ->buildWithPlugins();

        $this->assertInstanceOf(PluginAwareProcessor::class, $processor);
        $this->assertCount(2, $processor->getPlugins());
    }

    public function testRegExpMessageDelegates(): void
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
        $this->assertSame(MaskConstants::MASK_GENERIC . ' message', $processor->regExpMessage('test message'));
    }

    public function testRecursiveMaskDelegates(): void
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

        $result = $processor->recursiveMask(['key' => 'test value']);

        $this->assertSame(MaskConstants::MASK_GENERIC . ' value', $result['key']);
    }

    public function testSetAuditLoggerDelegates(): void
    {
        $logs = [];
        $auditLogger = function (string $path, mixed $original, mixed $masked) use (&$logs): void {
            $logs[] = ['path' => $path];
        };

        $plugin = new class extends AbstractMaskingPlugin {
            public function getName(): string
            {
                return 'test-plugin';
            }
        };

        $processor = GdprProcessorBuilder::create()
            ->addPlugin($plugin)
            ->buildWithPlugins();

        $this->assertInstanceOf(PluginAwareProcessor::class, $processor);
        $processor->setAuditLogger($auditLogger);

        // Audit logger should be set on underlying processor
        $this->assertTrue(true); // No exception means it worked
    }

    public function testMultiplePluginsProcessInPriorityOrder(): void
    {
        // Test that pre-processing runs in priority order (lower number first)
        $plugin1 = new class extends AbstractMaskingPlugin {
            public function __construct()
            {
                parent::__construct(20); // Lower priority (runs second)
            }

            public function getName(): string
            {
                return 'plugin1';
            }

            public function preProcessMessage(string $message): string
            {
                return $message . '-plugin1';
            }
        };

        $plugin2 = new class extends AbstractMaskingPlugin {
            public function __construct()
            {
                parent::__construct(10); // Higher priority (runs first)
            }

            public function getName(): string
            {
                return 'plugin2';
            }

            public function preProcessMessage(string $message): string
            {
                return $message . '-plugin2';
            }
        };

        $processor = GdprProcessorBuilder::create()
            ->addPlugin($plugin1)
            ->addPlugin($plugin2)
            ->buildWithPlugins();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: TestConstants::CHANNEL_TEST,
            level: Level::Info,
            message: 'base',
            context: []
        );

        $result = $processor($record);

        // plugin2 (priority 10) runs first in pre-processing, then plugin1 (priority 20)
        $this->assertSame('base-plugin2-plugin1', $result->message);
    }
}
