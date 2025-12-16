<?php

declare(strict_types=1);

namespace Tests\Builder;

use DateTimeImmutable;
use Ivuorinen\MonologGdprFilter\ArrayAccessor\ArrayAccessorFactory;
use Ivuorinen\MonologGdprFilter\Builder\GdprProcessorBuilder;
use Ivuorinen\MonologGdprFilter\Builder\PluginAwareProcessor;
use Ivuorinen\MonologGdprFilter\FieldMaskConfig;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\MaskConstants;
use Ivuorinen\MonologGdprFilter\Plugins\AbstractMaskingPlugin;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Edge case tests for GdprProcessorBuilder.
 */
#[CoversClass(GdprProcessorBuilder::class)]
#[CoversClass(PluginAwareProcessor::class)]
final class GdprProcessorBuilderEdgeCasesTest extends TestCase
{
    /**
     * @param array<string, mixed> $context
     */
    private function createLogRecord(string $message = 'Test', array $context = []): LogRecord
    {
        return new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: $message,
            context: $context,
        );
    }

    #[Test]
    public function setPatternsReplacesExisting(): void
    {
        $builder = GdprProcessorBuilder::create()
            ->addPattern('/old/', 'OLD_MASK')
            ->setPatterns(['/new/' => 'NEW_MASK']);

        $patterns = $builder->getPatterns();

        $this->assertArrayNotHasKey('/old/', $patterns);
        $this->assertArrayHasKey('/new/', $patterns);
        $this->assertSame('NEW_MASK', $patterns['/new/']);
    }

    #[Test]
    public function setFieldPathsReplacesExisting(): void
    {
        $builder = GdprProcessorBuilder::create()
            ->addFieldPath('old.path', '[OLD]')
            ->setFieldPaths(['new.path' => '[NEW]']);

        $paths = $builder->getFieldPaths();

        $this->assertArrayNotHasKey('old.path', $paths);
        $this->assertArrayHasKey('new.path', $paths);
        $this->assertSame('[NEW]', $paths['new.path']);
    }

    #[Test]
    public function addCallbacksAddsMultipleCallbacks(): void
    {
        $callback1 = fn(mixed $value): string => 'CALLBACK1';
        $callback2 = fn(mixed $value): string => 'CALLBACK2';

        $builder = GdprProcessorBuilder::create()
            ->addCallbacks([
                'path.one' => $callback1,
                'path.two' => $callback2,
            ]);

        $processor = $builder->build();

        $record = $this->createLogRecord('Test', [
            'path' => [
                'one' => 'value1',
                'two' => 'value2',
            ],
        ]);

        $processed = $processor($record);

        $this->assertSame('CALLBACK1', $processed->context['path']['one']);
        $this->assertSame('CALLBACK2', $processed->context['path']['two']);
    }

    #[Test]
    public function addDataTypeMasksAddsMultipleMasks(): void
    {
        $builder = GdprProcessorBuilder::create()
            ->addDataTypeMasks([
                'integer' => '999',
                'boolean' => 'false',
            ]);

        $processor = $builder->build();

        $record = $this->createLogRecord('Test', [
            'count' => 42,
            'active' => true,
        ]);

        $processed = $processor($record);

        $this->assertSame(999, $processed->context['count']);
        $this->assertFalse($processed->context['active']);
    }

    #[Test]
    public function addConditionalRulesAddsMultipleRules(): void
    {
        $rule1Called = false;
        $rule2Called = false;

        $builder = GdprProcessorBuilder::create()
            ->addPattern('/sensitive/', '[MASKED]')
            ->addConditionalRules([
                'rule1' => function (LogRecord $record) use (&$rule1Called): bool {
                    $rule1Called = true;
                    return $record->channel === 'test';
                },
                'rule2' => function (LogRecord $record) use (&$rule2Called): bool {
                    $rule2Called = true;
                    return true;
                },
            ]);

        $processor = $builder->build();

        $record = $this->createLogRecord('Contains sensitive data');
        $processed = $processor($record);

        $this->assertTrue($rule1Called);
        $this->assertTrue($rule2Called);
        $this->assertStringContainsString('[MASKED]', $processed->message);
    }

    #[Test]
    public function getPluginsReturnsRegisteredPlugins(): void
    {
        $plugin1 = new class extends AbstractMaskingPlugin {
            public function getName(): string
            {
                return 'plugin-1';
            }
        };

        $plugin2 = new class extends AbstractMaskingPlugin {
            public function getName(): string
            {
                return 'plugin-2';
            }
        };

        $builder = GdprProcessorBuilder::create()
            ->addPlugin($plugin1)
            ->addPlugin($plugin2);

        $plugins = $builder->getPlugins();

        $this->assertCount(2, $plugins);
        $this->assertSame('plugin-1', $plugins[0]->getName());
        $this->assertSame('plugin-2', $plugins[1]->getName());
    }

    #[Test]
    public function addPluginsAddsMultiplePlugins(): void
    {
        $plugin1 = new class extends AbstractMaskingPlugin {
            public function getName(): string
            {
                return 'plugin-1';
            }
        };

        $plugin2 = new class extends AbstractMaskingPlugin {
            public function getName(): string
            {
                return 'plugin-2';
            }
        };

        $builder = GdprProcessorBuilder::create()
            ->addPlugins([$plugin1, $plugin2]);

        $plugins = $builder->getPlugins();

        $this->assertCount(2, $plugins);
    }

    #[Test]
    public function buildWithPluginsReturnsGdprProcessorWhenNoPlugins(): void
    {
        $processor = GdprProcessorBuilder::create()
            ->withDefaultPatterns()
            ->buildWithPlugins();

        // buildWithPlugins returns GdprProcessor when no plugins are registered
        // We can't use assertNotInstanceOf due to PHPStan's static analysis
        // Instead we verify the actual return type
        $this->assertSame(GdprProcessor::class, $processor::class);
    }

    #[Test]
    public function buildWithPluginsReturnsPluginAwareProcessorWithPlugins(): void
    {
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
    }

    #[Test]
    public function buildWithPluginsSortsPluginsByPriority(): void
    {
        $lowPriority = new class (200) extends AbstractMaskingPlugin {
            public function getName(): string
            {
                return 'low-priority';
            }
        };

        $highPriority = new class (10) extends AbstractMaskingPlugin {
            public function getName(): string
            {
                return 'high-priority';
            }
        };

        $processor = GdprProcessorBuilder::create()
            ->addPlugin($lowPriority)
            ->addPlugin($highPriority)
            ->buildWithPlugins();

        $this->assertInstanceOf(PluginAwareProcessor::class, $processor);
    }

    #[Test]
    public function pluginContributesPatterns(): void
    {
        $plugin = new class extends AbstractMaskingPlugin {
            public function getName(): string
            {
                return 'pattern-plugin';
            }

            #[\Override]
            public function getPatterns(): array
            {
                return ['/PLUGIN-\d+/' => '[PLUGIN-ID]'];
            }
        };

        $processor = GdprProcessorBuilder::create()
            ->addPlugin($plugin)
            ->buildWithPlugins();

        $record = $this->createLogRecord('Reference: PLUGIN-12345');
        $processed = $processor($record);

        $this->assertSame('Reference: [PLUGIN-ID]', $processed->message);
    }

    #[Test]
    public function pluginContributesFieldPaths(): void
    {
        $plugin = new class extends AbstractMaskingPlugin {
            public function getName(): string
            {
                return 'field-plugin';
            }

            #[\Override]
            public function getFieldPaths(): array
            {
                return ['secret.key' => FieldMaskConfig::replace('[REDACTED]')];
            }
        };

        $processor = GdprProcessorBuilder::create()
            ->addPlugin($plugin)
            ->buildWithPlugins();

        $record = $this->createLogRecord('Test', ['secret' => ['key' => 'sensitive-value']]);
        $processed = $processor($record);

        $this->assertSame('[REDACTED]', $processed->context['secret']['key']);
    }

    #[Test]
    public function withArrayAccessorFactoryConfiguresProcessor(): void
    {
        $factory = ArrayAccessorFactory::default();

        $processor = GdprProcessorBuilder::create()
            ->withArrayAccessorFactory($factory)
            ->addFieldPath('user.email', MaskConstants::MASK_EMAIL)
            ->build();

        $record = $this->createLogRecord('Test', [
            'user' => ['email' => 'test@example.com'],
        ]);

        $processed = $processor($record);

        $this->assertSame(MaskConstants::MASK_EMAIL, $processed->context['user']['email']);
    }

    #[Test]
    public function withMaxDepthLimitsRecursion(): void
    {
        $processor = GdprProcessorBuilder::create()
            ->withMaxDepth(2)
            ->addPattern('/secret/', '[MASKED]')
            ->build();

        $record = $this->createLogRecord('Test', [
            'level1' => [
                'level2' => [
                    'level3' => 'secret data',
                ],
            ],
        ]);

        $processed = $processor($record);

        // Processor should handle the record without throwing
        $this->assertIsArray($processed->context);
    }

    #[Test]
    public function withAuditLoggerConfiguresLogging(): void
    {
        $auditLogs = [];

        $processor = GdprProcessorBuilder::create()
            ->addFieldPath('password', MaskConstants::MASK_REDACTED)
            ->withAuditLogger(function ($path, $original, $masked) use (&$auditLogs): void {
                $auditLogs[] = ['path' => $path, 'original' => $original, 'masked' => $masked];
            })
            ->build();

        $record = $this->createLogRecord('Test', ['password' => 'secret123']);
        $processor($record);

        $this->assertNotEmpty($auditLogs);
    }

    #[Test]
    public function addConditionalRuleConfiguresProcessor(): void
    {
        $ruleExecuted = false;

        $processor = GdprProcessorBuilder::create()
            ->addPattern('/data/', '[MASKED]')
            ->addConditionalRule('track-execution', function (LogRecord $r) use (&$ruleExecuted): bool {
                $ruleExecuted = true;
                return true;
            })
            ->build();

        $record = $this->createLogRecord('Contains data value');
        $processor($record);

        // Verify the conditional rule was executed
        $this->assertTrue($ruleExecuted);
    }

    #[Test]
    public function addFieldPathsAddsMultiplePaths(): void
    {
        $processor = GdprProcessorBuilder::create()
            ->addFieldPaths([
                'user.email' => MaskConstants::MASK_EMAIL,
                'user.phone' => MaskConstants::MASK_PHONE,
            ])
            ->build();

        $record = $this->createLogRecord('Test', [
            'user' => [
                'email' => 'test@example.com',
                'phone' => '555-1234',
            ],
        ]);

        $processed = $processor($record);

        $this->assertSame(MaskConstants::MASK_EMAIL, $processed->context['user']['email']);
        $this->assertSame(MaskConstants::MASK_PHONE, $processed->context['user']['phone']);
    }

    #[Test]
    public function addPatternsAddsMultiplePatterns(): void
    {
        $processor = GdprProcessorBuilder::create()
            ->addPatterns([
                '/\d{3}-\d{2}-\d{4}/' => '[SSN]',
                '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '[EMAIL]',
            ])
            ->build();

        $record = $this->createLogRecord('SSN: 123-45-6789, Email: user@example.com');
        $processed = $processor($record);

        $this->assertStringContainsString('[SSN]', $processed->message);
        $this->assertStringContainsString('[EMAIL]', $processed->message);
    }
}
