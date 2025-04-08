<?php

namespace Aternos\Lock\Test\Storage;

use Aternos\Lock\Storage\SimpleInMemoryStorage;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class SimpleInMemoryStorageTest extends TestCase
{
    protected PublicSimpleInMemoryStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new PublicSimpleInMemoryStorage();
    }

    public function testGetNull(): void
    {
        $this->assertNull($this->storage->get("non-existing-key"));
    }

    public function testGet(): void
    {
        $this->storage->getStorage()["key"] = "value";
        $this->assertEquals("value", $this->storage->get("key"));
    }

    #[TestWith(["old"])]
    #[TestWith([null])]
    public function testPutIfSuccess(?string $previousValue): void
    {
        if ($previousValue !== null) {
            $this->storage->getStorage()["key"] = $previousValue;
        }
        $this->assertTrue($this->storage->putIf("key", "new", $previousValue));
        $this->assertEquals("new", $this->storage->get("key"));
    }

    #[TestWith(["old", null])]
    #[TestWith([null, "old"])]
    #[TestWith(["old", "real"])]
    public function testPutIfFail(?string $previousValue, ?string $realValue): void
    {
        if ($realValue !== null) {
            $this->storage->getStorage()["key"] = $realValue;
        }
        $this->assertFalse($this->storage->putIf("key", "new", $previousValue));
        $this->assertEquals($realValue, $this->storage->get("key"));
    }

    #[TestWith(["old", null])]
    #[TestWith([null, "old"])]
    public function testPutIfFailValue(?string $previousValue, ?string $realValue): void
    {
        if ($realValue !== null) {
            $this->storage->getStorage()["key"] = $realValue;
        }
        $this->assertEquals($realValue, $this->storage->putIf("key", "new", $previousValue, true));
        $this->assertEquals($realValue, $this->storage->get("key"));
    }

    #[TestWith(["old"])]
    #[TestWith([null])]
    public function testDeleteIfSuccess(?string $previousValue): void
    {
        if ($previousValue !== null) {
            $this->storage->getStorage()["key"] = $previousValue;
        }
        $this->assertTrue($this->storage->deleteIf("key", $previousValue));
        $this->assertEquals(null, $this->storage->get("key"));
    }

    #[TestWith(["old", null])]
    #[TestWith([null, "old"])]
    #[TestWith(["old", "real"])]
    public function testDeleteIfFail(?string $previousValue, ?string $realValue): void
    {
        if ($realValue !== null) {
            $this->storage->getStorage()["key"] = $realValue;
        }
        $this->assertFalse($this->storage->deleteIf("key", $previousValue));
        $this->assertEquals($realValue, $this->storage->get("key"));
    }

    #[TestWith(["old", null])]
    #[TestWith([null, "old"])]
    public function testDeleteIfFailValue(?string $previousValue, ?string $realValue): void
    {
        if ($realValue !== null) {
            $this->storage->getStorage()["key"] = $realValue;
        }
        $this->assertEquals($realValue, $this->storage->deleteIf("key", $previousValue, true));
        $this->assertEquals($realValue, $this->storage->get("key"));
    }

    public function testClear(): void
    {
        $this->assertTrue($this->storage->putIf("key", "value", null));
        $this->storage->clear();
        $this->assertNull($this->storage->get("key"));
    }
}

class PublicSimpleInMemoryStorage extends SimpleInMemoryStorage
{
    public function &getStorage(): array
    {
        return $this->storage;
    }
}
