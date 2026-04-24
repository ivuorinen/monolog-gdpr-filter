<?php

declare(strict_types=1);

namespace Tests\Plugins;

use Ivuorinen\MonologGdprFilter\Contracts\MaskingPluginInterface;
use Ivuorinen\MonologGdprFilter\Plugins\AbstractMaskingPlugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\TestConstants;

#[CoversClass(AbstractMaskingPlugin::class)]
final class AbstractMaskingPluginTest extends TestCase
{
    private AbstractMaskingPlugin $plugin;

    #[\Override]
    protected function setUp(): void
    {
        $this->plugin = new class extends AbstractMaskingPlugin {
            #[\Override]
            /**
             * @return string
             *
             * @psalm-return 'test-plugin'
             */
            public function getName(): string
            {
                return 'test-plugin';
            }
        };
    }

    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(MaskingPluginInterface::class, $this->plugin);
    }

    public function testDefaultPriority(): void
    {
        $this->assertSame(100, $this->plugin->getPriority());
    }

    public function testCustomPriority(): void
    {
        $plugin = new class extends AbstractMaskingPlugin {
            public function __construct()
            {
                parent::__construct(50);
            }

            #[\Override]
            /**
             * @return string
             *
             * @psalm-return 'test-plugin'
             */
            public function getName(): string
            {
                return 'test-plugin';
            }
        };

        $this->assertSame(50, $plugin->getPriority());
    }

    public function testPreProcessContextReturnsUnchanged(): void
    {
        $context = ['key' => 'value'];
        $result = $this->plugin->preProcessContext($context);

        $this->assertSame($context, $result);
    }

    public function testPostProcessContextReturnsUnchanged(): void
    {
        $context = ['key' => 'value'];
        $result = $this->plugin->postProcessContext($context);

        $this->assertSame($context, $result);
    }

    public function testPreProcessMessageReturnsUnchanged(): void
    {
        $message = TestConstants::MESSAGE_TEST_LOWERCASE;
        $result = $this->plugin->preProcessMessage($message);

        $this->assertSame($message, $result);
    }

    public function testPostProcessMessageReturnsUnchanged(): void
    {
        $message = TestConstants::MESSAGE_TEST_LOWERCASE;
        $result = $this->plugin->postProcessMessage($message);

        $this->assertSame($message, $result);
    }

    public function testGetPatternsReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->plugin->getPatterns());
    }

    public function testGetFieldPathsReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->plugin->getFieldPaths());
    }

    public function testCanOverridePreProcessContext(): void
    {
        $plugin = new class extends AbstractMaskingPlugin {
            #[\Override]
            /**
             * @return string
             *
             * @psalm-return 'test-plugin'
             */
            public function getName(): string
            {
                return 'test-plugin';
            }

            #[\Override]
            /**
             * @return (mixed|true)[]
             *
             * @psalm-return array{added: true,...}
             */
            public function preProcessContext(array $context): array
            {
                $context['added'] = true;
                return $context;
            }
        };

        $result = $plugin->preProcessContext(['original' => 'value']);

        $this->assertTrue($result['added']);
        $this->assertSame('value', $result['original']);
    }

    public function testCanOverridePreProcessMessage(): void
    {
        $plugin = new class extends AbstractMaskingPlugin {
            #[\Override]
            /**
             * @return string
             *
             * @psalm-return 'test-plugin'
             */
            public function getName(): string
            {
                return 'test-plugin';
            }

            #[\Override]
            public function preProcessMessage(string $message): string
            {
                return strtoupper($message);
            }
        };

        $this->assertSame('HELLO', $plugin->preProcessMessage('hello'));
    }

    public function testCanOverrideGetPatterns(): void
    {
        $plugin = new class extends AbstractMaskingPlugin {
            #[\Override]
            /**
             * @return string
             *
             * @psalm-return 'test-plugin'
             */
            public function getName(): string
            {
                return 'test-plugin';
            }

            #[\Override]
            /**
             * @return string[]
             *
             * @psalm-return array{'/secret/': '[REDACTED]'}
             */
            public function getPatterns(): array
            {
                return [TestConstants::PATTERN_SECRET => TestConstants::MASK_REDACTED_BRACKETS];
            }
        };

        $patterns = $plugin->getPatterns();

        $this->assertArrayHasKey(TestConstants::PATTERN_SECRET, $patterns);
        $this->assertSame(TestConstants::MASK_REDACTED_BRACKETS, $patterns[TestConstants::PATTERN_SECRET]);
    }
}
