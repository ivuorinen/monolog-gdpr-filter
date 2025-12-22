<?php

declare(strict_types=1);

namespace Tests;

use DateTimeImmutable;
use Ivuorinen\MonologGdprFilter\ArrayAccessor\ArrayAccessorFactory;
use Ivuorinen\MonologGdprFilter\ConditionalRuleFactory;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ConditionalRuleFactory instance methods.
 */
#[CoversClass(ConditionalRuleFactory::class)]
final class ConditionalRuleFactoryInstanceTest extends TestCase
{
    /**
     * @param array<string, mixed> $context
     */
    private function createLogRecord(string $message, array $context = []): LogRecord
    {
        return new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: $message,
            context: $context,
        );
    }

    public function testConstructorWithDefaultFactory(): void
    {
        $factory = new ConditionalRuleFactory();
        $this->assertInstanceOf(ConditionalRuleFactory::class, $factory);
    }

    public function testConstructorWithCustomFactory(): void
    {
        $accessorFactory = ArrayAccessorFactory::default();
        $factory = new ConditionalRuleFactory($accessorFactory);
        $this->assertInstanceOf(ConditionalRuleFactory::class, $factory);
    }

    public function testContextFieldRuleWithPresentField(): void
    {
        $factory = new ConditionalRuleFactory();
        $rule = $factory->contextFieldRule('user_id');

        $record = $this->createLogRecord('Test message', ['user_id' => 123]);
        $this->assertTrue($rule($record));
    }

    public function testContextFieldRuleWithMissingField(): void
    {
        $factory = new ConditionalRuleFactory();
        $rule = $factory->contextFieldRule('user_id');

        $record = $this->createLogRecord('Test message', []);
        $this->assertFalse($rule($record));
    }

    public function testContextFieldRuleWithNestedField(): void
    {
        $factory = new ConditionalRuleFactory();
        $rule = $factory->contextFieldRule('user.profile.id');

        $recordWithField = $this->createLogRecord('Test', [
            'user' => ['profile' => ['id' => 456]],
        ]);
        $this->assertTrue($rule($recordWithField));

        $recordWithoutField = $this->createLogRecord('Test', [
            'user' => ['profile' => []],
        ]);
        $this->assertFalse($rule($recordWithoutField));
    }

    public function testContextValueRuleWithMatchingValue(): void
    {
        $factory = new ConditionalRuleFactory();
        $rule = $factory->contextValueRule('env', 'production');

        $record = $this->createLogRecord('Test', ['env' => 'production']);
        $this->assertTrue($rule($record));
    }

    public function testContextValueRuleWithNonMatchingValue(): void
    {
        $factory = new ConditionalRuleFactory();
        $rule = $factory->contextValueRule('env', 'production');

        $record = $this->createLogRecord('Test', ['env' => 'development']);
        $this->assertFalse($rule($record));
    }

    public function testContextValueRuleWithMissingField(): void
    {
        $factory = new ConditionalRuleFactory();
        $rule = $factory->contextValueRule('env', 'production');

        $record = $this->createLogRecord('Test', []);
        $this->assertFalse($rule($record));
    }

    public function testContextValueRuleWithNestedField(): void
    {
        $factory = new ConditionalRuleFactory();
        $rule = $factory->contextValueRule('config.debug', true);

        $recordMatching = $this->createLogRecord('Test', [
            'config' => ['debug' => true],
        ]);
        $this->assertTrue($rule($recordMatching));

        $recordNonMatching = $this->createLogRecord('Test', [
            'config' => ['debug' => false],
        ]);
        $this->assertFalse($rule($recordNonMatching));
    }

    public function testContextValueRuleWithNullValue(): void
    {
        $factory = new ConditionalRuleFactory();
        $rule = $factory->contextValueRule('nullable', null);

        $recordWithNull = $this->createLogRecord('Test', ['nullable' => null]);
        $this->assertTrue($rule($recordWithNull));

        $recordWithValue = $this->createLogRecord('Test', ['nullable' => 'value']);
        $this->assertFalse($rule($recordWithValue));
    }

    public function testContextValueRuleWithArrayValue(): void
    {
        $factory = new ConditionalRuleFactory();
        $expectedArray = ['a', 'b', 'c'];
        $rule = $factory->contextValueRule('tags', $expectedArray);

        $recordMatching = $this->createLogRecord('Test', ['tags' => ['a', 'b', 'c']]);
        $this->assertTrue($rule($recordMatching));

        $recordNonMatching = $this->createLogRecord('Test', ['tags' => ['a', 'b']]);
        $this->assertFalse($rule($recordNonMatching));
    }

    public function testContextValueRuleWithIntegerValue(): void
    {
        $factory = new ConditionalRuleFactory();
        $rule = $factory->contextValueRule('count', 42);

        $recordMatching = $this->createLogRecord('Test', ['count' => 42]);
        $this->assertTrue($rule($recordMatching));

        // Different type (string vs int) should not match
        $recordDifferentType = $this->createLogRecord('Test', ['count' => '42']);
        $this->assertFalse($rule($recordDifferentType));
    }

    public function testCustomAccessorFactoryIsUsed(): void
    {
        // Create a custom accessor factory
        $customFactory = ArrayAccessorFactory::default();
        $ruleFactory = new ConditionalRuleFactory($customFactory);

        $fieldRule = $ruleFactory->contextFieldRule('test.field');
        $valueRule = $ruleFactory->contextValueRule('test.value', 'expected');

        $record = $this->createLogRecord('Test', [
            'test' => [
                'field' => 'present',
                'value' => 'expected',
            ],
        ]);

        $this->assertTrue($fieldRule($record));
        $this->assertTrue($valueRule($record));
    }

    public function testInstanceMethodsVsStaticMethods(): void
    {
        $instanceFactory = new ConditionalRuleFactory();

        // Create rules using both methods
        $instanceFieldRule = $instanceFactory->contextFieldRule('user.email');
        $staticFieldRule = ConditionalRuleFactory::createContextFieldRule('user.email');

        $instanceValueRule = $instanceFactory->contextValueRule('type', 'admin');
        $staticValueRule = ConditionalRuleFactory::createContextValueRule('type', 'admin');

        $record = $this->createLogRecord('Test', [
            'user' => ['email' => 'test@example.com'],
            'type' => 'admin',
        ]);

        // Both should produce the same results
        $this->assertSame($staticFieldRule($record), $instanceFieldRule($record));
        $this->assertSame($staticValueRule($record), $instanceValueRule($record));
    }

    public function testContextFieldRuleWithEmptyPath(): void
    {
        $factory = new ConditionalRuleFactory();
        $rule = $factory->contextFieldRule('');

        $record = $this->createLogRecord('Test', ['key' => 'value']);
        $this->assertFalse($rule($record));
    }

    public function testContextValueRuleWithEmptyPath(): void
    {
        $factory = new ConditionalRuleFactory();
        $rule = $factory->contextValueRule('', 'value');

        $record = $this->createLogRecord('Test', ['key' => 'value']);
        $this->assertFalse($rule($record));
    }

    public function testContextFieldRuleWithDeeplyNestedField(): void
    {
        $factory = new ConditionalRuleFactory();
        $rule = $factory->contextFieldRule('a.b.c.d.e.f');

        $deepRecord = $this->createLogRecord('Test', [
            'a' => ['b' => ['c' => ['d' => ['e' => ['f' => 'deep']]]]],
        ]);
        $this->assertTrue($rule($deepRecord));

        $shallowRecord = $this->createLogRecord('Test', [
            'a' => ['b' => ['c' => 'shallow']],
        ]);
        $this->assertFalse($rule($shallowRecord));
    }
}
