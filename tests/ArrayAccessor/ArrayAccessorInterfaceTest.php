<?php

declare(strict_types=1);

namespace Tests\ArrayAccessor;

use Adbar\Dot;
use Ivuorinen\MonologGdprFilter\ArrayAccessor\DotArrayAccessor;
use Ivuorinen\MonologGdprFilter\Contracts\ArrayAccessorInterface;
use PHPUnit\Framework\TestCase;
use Tests\TestConstants;

/**
 * Tests for ArrayAccessorInterface implementations.
 *
 * @api
 */
final class ArrayAccessorInterfaceTest extends TestCase
{
    public function testDotArrayAccessorImplementsInterface(): void
    {
        $accessor = new DotArrayAccessor([]);

        $this->assertInstanceOf(ArrayAccessorInterface::class, $accessor);
    }

    public function testHasReturnsTrueForExistingPath(): void
    {
        $accessor = new DotArrayAccessor([
            'user' => [
                'email' => TestConstants::EMAIL_TEST,
            ],
        ]);

        $this->assertTrue($accessor->has('user.email'));
    }

    public function testHasReturnsFalseForMissingPath(): void
    {
        $accessor = new DotArrayAccessor([
            'user' => [
                'email' => TestConstants::EMAIL_TEST,
            ],
        ]);

        $this->assertFalse($accessor->has('user.name'));
        $this->assertFalse($accessor->has('nonexistent'));
    }

    public function testGetReturnsValueForExistingPath(): void
    {
        $accessor = new DotArrayAccessor([
            'user' => [
                'email' => TestConstants::EMAIL_TEST,
                'age' => 25,
            ],
        ]);

        $this->assertSame(TestConstants::EMAIL_TEST, $accessor->get('user.email'));
        $this->assertSame(25, $accessor->get('user.age'));
    }

    public function testGetReturnsDefaultForMissingPath(): void
    {
        $accessor = new DotArrayAccessor(['key' => 'value']);

        $this->assertNull($accessor->get('missing'));
        $this->assertSame('default', $accessor->get('missing', 'default'));
    }

    public function testSetCreatesNewPath(): void
    {
        $accessor = new DotArrayAccessor([]);

        $accessor->set('user.email', TestConstants::EMAIL_NEW);

        $this->assertTrue($accessor->has('user.email'));
        $this->assertSame(TestConstants::EMAIL_NEW, $accessor->get('user.email'));
    }

    public function testSetOverwritesExistingPath(): void
    {
        $accessor = new DotArrayAccessor([
            'user' => ['email' => 'old@example.com'],
        ]);

        $accessor->set('user.email', TestConstants::EMAIL_NEW);

        $this->assertSame(TestConstants::EMAIL_NEW, $accessor->get('user.email'));
    }

    public function testDeleteRemovesPath(): void
    {
        $accessor = new DotArrayAccessor([
            'user' => [
                'email' => TestConstants::EMAIL_TEST,
                'name' => 'Test User',
            ],
        ]);

        $accessor->delete('user.email');

        $this->assertFalse($accessor->has('user.email'));
        $this->assertTrue($accessor->has('user.name'));
    }

    public function testAllReturnsCompleteArray(): void
    {
        $data = [
            'user' => [
                'email' => TestConstants::EMAIL_TEST,
                'profile' => [
                    'bio' => 'Hello world',
                ],
            ],
            'settings' => ['theme' => 'dark'],
        ];

        $accessor = new DotArrayAccessor($data);

        $this->assertSame($data, $accessor->all());
    }

    public function testAllReflectsModifications(): void
    {
        $accessor = new DotArrayAccessor(['key' => 'original']);

        $accessor->set('key', 'modified');
        $accessor->set('new', 'value');

        $result = $accessor->all();

        $this->assertSame('modified', $result['key']);
        $this->assertSame('value', $result['new']);
    }

    public function testFromArrayFactoryMethod(): void
    {
        $data = ['foo' => 'bar'];
        $accessor = DotArrayAccessor::fromArray($data);

        $this->assertInstanceOf(DotArrayAccessor::class, $accessor);
        $this->assertSame('bar', $accessor->get('foo'));
    }

    public function testGetDotReturnsUnderlyingInstance(): void
    {
        $accessor = new DotArrayAccessor(['test' => 'value']);
        $dot = $accessor->getDot();

        $this->assertInstanceOf(Dot::class, $dot);
        $this->assertSame('value', $dot->get('test'));
    }

    public function testDeepNestedAccess(): void
    {
        $accessor = new DotArrayAccessor([
            'level1' => [
                'level2' => [
                    'level3' => [
                        'level4' => 'deep value',
                    ],
                ],
            ],
        ]);

        $this->assertTrue($accessor->has('level1.level2.level3.level4'));
        $this->assertSame('deep value', $accessor->get('level1.level2.level3.level4'));

        $accessor->set('level1.level2.level3.level4', 'modified');
        $this->assertSame('modified', $accessor->get('level1.level2.level3.level4'));
    }

    public function testNumericKeys(): void
    {
        $accessor = new DotArrayAccessor([
            'items' => [
                0 => 'first',
                1 => 'second',
                2 => 'third',
            ],
        ]);

        $this->assertTrue($accessor->has('items.0'));
        $this->assertSame('first', $accessor->get('items.0'));
        $this->assertSame('second', $accessor->get('items.1'));
    }
}
