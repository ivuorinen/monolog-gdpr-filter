<?php

declare(strict_types=1);

namespace Tests\Plugins;

use Ivuorinen\MonologGdprFilter\Contracts\MaskingPluginInterface;
use Ivuorinen\MonologGdprFilter\Plugins\AbstractMaskingPlugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractMaskingPlugin::class)]
final class AbstractMaskingPluginTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $plugin = new class extends AbstractMaskingPlugin {
            public function getName(): string
            {
                return 'test-plugin';
            }
        };

        $this->assertInstanceOf(MaskingPluginInterface::class, $plugin);
    }

    public function testDefaultPriority(): void
    {
        $plugin = new class extends AbstractMaskingPlugin {
            public function getName(): string
            {
                return 'test-plugin';
            }
        };

        $this->assertSame(100, $plugin->getPriority());
    }

    public function testCustomPriority(): void
    {
        $plugin = new class extends AbstractMaskingPlugin {
            public function __construct()
            {
                parent::__construct(50);
            }

            public function getName(): string
            {
                return 'test-plugin';
            }
        };

        $this->assertSame(50, $plugin->getPriority());
    }

    public function testPreProcessContextReturnsUnchanged(): void
    {
        $plugin = new class extends AbstractMaskingPlugin {
            public function getName(): string
            {
                return 'test-plugin';
            }
        };

        $context = ['key' => 'value'];
        $result = $plugin->preProcessContext($context);

        $this->assertSame($context, $result);
    }

    public function testPostProcessContextReturnsUnchanged(): void
    {
        $plugin = new class extends AbstractMaskingPlugin {
            public function getName(): string
            {
                return 'test-plugin';
            }
        };

        $context = ['key' => 'value'];
        $result = $plugin->postProcessContext($context);

        $this->assertSame($context, $result);
    }

    public function testPreProcessMessageReturnsUnchanged(): void
    {
        $plugin = new class extends AbstractMaskingPlugin {
            public function getName(): string
            {
                return 'test-plugin';
            }
        };

        $message = 'test message';
        $result = $plugin->preProcessMessage($message);

        $this->assertSame($message, $result);
    }

    public function testPostProcessMessageReturnsUnchanged(): void
    {
        $plugin = new class extends AbstractMaskingPlugin {
            public function getName(): string
            {
                return 'test-plugin';
            }
        };

        $message = 'test message';
        $result = $plugin->postProcessMessage($message);

        $this->assertSame($message, $result);
    }

    public function testGetPatternsReturnsEmptyArray(): void
    {
        $plugin = new class extends AbstractMaskingPlugin {
            public function getName(): string
            {
                return 'test-plugin';
            }
        };

        $this->assertSame([], $plugin->getPatterns());
    }

    public function testGetFieldPathsReturnsEmptyArray(): void
    {
        $plugin = new class extends AbstractMaskingPlugin {
            public function getName(): string
            {
                return 'test-plugin';
            }
        };

        $this->assertSame([], $plugin->getFieldPaths());
    }

    public function testCanOverridePreProcessContext(): void
    {
        $plugin = new class extends AbstractMaskingPlugin {
            public function getName(): string
            {
                return 'test-plugin';
            }

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
            public function getName(): string
            {
                return 'test-plugin';
            }

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
            public function getName(): string
            {
                return 'test-plugin';
            }

            public function getPatterns(): array
            {
                return ['/secret/' => '[REDACTED]'];
            }
        };

        $patterns = $plugin->getPatterns();

        $this->assertArrayHasKey('/secret/', $patterns);
        $this->assertSame('[REDACTED]', $patterns['/secret/']);
    }
}
