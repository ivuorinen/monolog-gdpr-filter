<?php

declare(strict_types=1);

namespace Tests\ArrayAccessor;

use Ivuorinen\MonologGdprFilter\ArrayAccessor\ArrayAccessorFactory;
use Ivuorinen\MonologGdprFilter\ArrayAccessor\DotArrayAccessor;
use Ivuorinen\MonologGdprFilter\Contracts\ArrayAccessorInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ArrayAccessorFactory.
 *
 * @api
 */
final class ArrayAccessorFactoryTest extends TestCase
{
    public function testDefaultFactoryCreatesDotAccessor(): void
    {
        $factory = ArrayAccessorFactory::default();
        $accessor = $factory->create(['test' => 'value']);

        $this->assertInstanceOf(ArrayAccessorInterface::class, $accessor);
        $this->assertInstanceOf(DotArrayAccessor::class, $accessor);
    }

    public function testCreateWithDataPassesDataToAccessor(): void
    {
        $factory = ArrayAccessorFactory::default();
        $accessor = $factory->create([
            'user' => ['email' => 'test@example.com'],
        ]);

        $this->assertTrue($accessor->has('user.email'));
        $this->assertSame('test@example.com', $accessor->get('user.email'));
    }

    public function testWithClassFactoryMethod(): void
    {
        $factory = ArrayAccessorFactory::withClass(DotArrayAccessor::class);
        $accessor = $factory->create(['foo' => 'bar']);

        $this->assertInstanceOf(DotArrayAccessor::class, $accessor);
        $this->assertSame('bar', $accessor->get('foo'));
    }

    public function testWithCallableFactoryMethod(): void
    {
        $customFactory = (fn(array $data): ArrayAccessorInterface => new DotArrayAccessor(array_merge($data, ['injected' => true])));

        $factory = ArrayAccessorFactory::withCallable($customFactory);
        $accessor = $factory->create(['original' => 'data']);

        $this->assertTrue($accessor->get('injected'));
        $this->assertSame('data', $accessor->get('original'));
    }

    public function testConstructorWithNullUsesDefault(): void
    {
        $factory = new ArrayAccessorFactory(null);
        $accessor = $factory->create(['test' => 'value']);

        $this->assertInstanceOf(DotArrayAccessor::class, $accessor);
    }

    public function testConstructorWithClassName(): void
    {
        $factory = new ArrayAccessorFactory(DotArrayAccessor::class);
        $accessor = $factory->create(['key' => 'value']);

        $this->assertInstanceOf(DotArrayAccessor::class, $accessor);
        $this->assertSame('value', $accessor->get('key'));
    }

    public function testConstructorWithCallable(): void
    {
        $callCount = 0;
        $customFactory = function (array $data) use (&$callCount): ArrayAccessorInterface {
            $callCount++;
            return new DotArrayAccessor($data);
        };

        $factory = new ArrayAccessorFactory($customFactory);
        $factory->create([]);
        $factory->create([]);

        $this->assertSame(2, $callCount);
    }

    public function testCreateMultipleAccessorsAreIndependent(): void
    {
        $factory = ArrayAccessorFactory::default();

        $accessor1 = $factory->create(['key' => 'value1']);
        $accessor2 = $factory->create(['key' => 'value2']);

        $accessor1->set('key', 'modified');

        $this->assertSame('modified', $accessor1->get('key'));
        $this->assertSame('value2', $accessor2->get('key'));
    }

    public function testFactoryWithEmptyArray(): void
    {
        $factory = ArrayAccessorFactory::default();
        $accessor = $factory->create([]);

        $this->assertSame([], $accessor->all());
        $this->assertFalse($accessor->has('anything'));
    }

    public function testFactoryPreservesComplexStructure(): void
    {
        $data = [
            'users' => [
                ['id' => 1, 'name' => 'Alice'],
                ['id' => 2, 'name' => 'Bob'],
            ],
            'metadata' => [
                'version' => '1.0',
                'nested' => ['deep' => ['value' => true]],
            ],
        ];

        $factory = ArrayAccessorFactory::default();
        $accessor = $factory->create($data);

        $this->assertSame($data, $accessor->all());
        $this->assertTrue($accessor->get('metadata.nested.deep.value'));
    }
}
