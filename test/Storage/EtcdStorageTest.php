<?php

namespace Aternos\Lock\Test\Storage;

use Aternos\Etcd\ClientInterface;
use Aternos\Etcd\Exception\Status\UnavailableException;
use Aternos\Lock\Storage\EtcdStorage;
use Aternos\Lock\Storage\StorageException;
use Exception;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Constraint\IsEqual;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class EtcdStorageTest extends TestCase
{
    protected EtcdStorage $storage;
    protected MockObject&ClientInterface $client;

    protected function setUp(): void
    {
        $this->client = $this->createMock(ClientInterface::class);
        $this->storage = new EtcdStorage($this->client);
    }

    protected function equalToEtcdValue(?string $x): IsEqual
    {
        return $this->equalTo($x ?? false);
    }

    #[TestWith(['previous_value', false])]
    #[TestWith(['previous_value', true])]
    #[TestWith([null, false])]
    #[TestWith([null, true])]
    public function testPutIf(?string $previousValue, bool $result): void
    {
        $key = 'test_key';
        $value = 'test_value';


        $this->client->method('putIf')
            ->with($this->equalTo($key), $this->equalTo($value), $this->equalToEtcdValue($previousValue))
            ->willReturn($result);

        $this->assertSame($result, $this->storage->putIf($key, $value, $previousValue));
    }

    #[TestWith(['previous_value', false, null])]
    #[TestWith(['previous_value', true, true])]
    #[TestWith([null, 'real_value', 'real_value'])]
    #[TestWith([null, true, true])]
    public function testPutIfReturnValue(
        ?string $previousValue,
        bool|string $etcdResponse,
        bool|string|null $expected,
    ): void
    {
        $key = 'test_key';
        $value = 'test_value';


        $this->client->method('putIf')
            ->with($this->equalTo($key), $this->equalTo($value), $this->equalToEtcdValue($previousValue))
            ->willReturn($etcdResponse);

        $this->assertSame($expected, $this->storage->putIf($key, $value, $previousValue, true));
    }

    public function testPutIfStorageException(): void
    {
        $key = 'test_key';
        $value = 'test_value';
        $previousValue = 'previous_value';

        $this->client->method('putIf')
            ->with($this->equalTo($key), $this->equalTo($value), $this->equalToEtcdValue($previousValue))
            ->willThrowException(new UnavailableException("Test exception"));

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage("Test exception");
        $this->storage->putIf($key, $value, $previousValue);
    }

    public function testPutIfOtherException(): void
    {
        $key = 'test_key';
        $value = 'test_value';
        $previousValue = 'previous_value';

        $this->client->method('putIf')
            ->with($this->equalTo($key), $this->equalTo($value), $this->equalToEtcdValue($previousValue))
            ->willThrowException(new InvalidArgumentException("Test exception"));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Test exception");
        $this->storage->putIf($key, $value, $previousValue);
    }

    #[TestWith(['previous_value', false])]
    #[TestWith(['previous_value', true])]
    #[TestWith([null, false])]
    #[TestWith([null, true])]
    public function testDeleteIf(?string $previousValue, bool|string $result): void
    {
        $key = 'test_key';

        $this->client->method('deleteIf')
            ->with($this->equalTo($key), $this->equalToEtcdValue($previousValue))
            ->willReturn($result);

        $this->assertSame($result, $this->storage->deleteIf($key, $previousValue));
    }

    #[TestWith(['previous_value', false, null])]
    #[TestWith(['previous_value', true, true])]
    #[TestWith([null, 'real_value', 'real_value'])]
    #[TestWith([null, true, true])]
    public function testDeleteIfReturnValue(
        ?string $previousValue,
        bool|string $etcdResponse,
        bool|string|null $expected,
    ): void
    {
        $key = 'test_key';

        $this->client->method('deleteIf')
            ->with($this->equalTo($key), $this->equalToEtcdValue($previousValue))
            ->willReturn($etcdResponse);

        $this->assertSame($expected, $this->storage->deleteIf($key, $previousValue, true));
    }

    public function testDeleteIfStorageException(): void
    {
        $key = 'test_key';
        $previousValue = 'previous_value';

        $this->client->method('deleteIf')
            ->with($this->equalTo($key), $this->equalToEtcdValue($previousValue))
            ->willThrowException(new UnavailableException("Test exception"));

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage("Test exception");
        $this->storage->deleteIf($key, $previousValue);
    }

    public function testDeleteIfOtherException(): void
    {
        $key = 'test_key';
        $previousValue = 'previous_value';

        $this->client->method('deleteIf')
            ->with($this->equalTo($key), $this->equalToEtcdValue($previousValue))
            ->willThrowException(new InvalidArgumentException("Test exception"));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Test exception");
        $this->storage->deleteIf($key, $previousValue);
    }

    #[TestWith([null])]
    #[TestWith(["test value"])]
    public function testGet(?string $value)
    {
        $key = 'test_key';

        $this->client->method('get')
            ->with($this->equalTo($key))
            ->willReturn($value ?? false);

        $this->assertSame($value, $this->storage->get($key));
    }

    public function testGStorageException(): void
    {
        $key = 'test_key';

        $this->client->method('get')
            ->with($this->equalTo($key))
            ->willThrowException(new UnavailableException("Test exception"));

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage("Test exception");
        $this->storage->get($key);
    }

    public function testGetOtherException(): void
    {
        $key = 'test_key';

        $this->client->method('get')
            ->with($this->equalTo($key))
            ->willThrowException(new InvalidArgumentException("Test exception"));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Test exception");
        $this->storage->get($key);
    }
}
