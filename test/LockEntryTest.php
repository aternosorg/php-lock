<?php

namespace Aternos\Lock\Test;

use Aternos\Lock\LockEntry;
use PHPUnit\Framework\TestCase;

class LockEntryTest extends TestCase
{
    protected function getRandomString($length = 16): string
    {
        return substr(str_shuffle(str_repeat($x = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil($length / strlen($x)))), 1, $length);
    }

    public function testFromObjectHasPropertiesFromObject(): void
    {
        $identifier = $this->getRandomString();

        $obj = new \stdClass();
        $obj->by = $identifier;
        $obj->until = 10;
        $obj->exclusive = true;

        $lockEntry = LockEntry::fromObject($obj);

        $this->assertEquals($identifier, $lockEntry->getBy());
        $this->assertEquals(10, $lockEntry->getUntil());
        $this->assertTrue($lockEntry->isExclusive());
    }

    public function testFromObjectWithEmptyPropertiesHasNullProperties(): void
    {
        $lockEntry = LockEntry::fromObject(new \stdClass());

        $this->assertNull($lockEntry->getBy());
        $this->assertNull($lockEntry->getUntil());
        $this->assertNull($lockEntry->isExclusive());
    }

    public function testFromJsonWithInvalidJsonReturnsEmptyArray(): void
    {
        $lockEntry = LockEntry::fromJson('[invalid]');

        $this->assertIsArray($lockEntry);
        $this->assertEmpty($lockEntry);
    }

    public function testFromJsonWithNonArrayJsonStringReturnsEmptyArray(): void
    {
        $lockEntry = LockEntry::fromJson('{"key": "value"}');

        $this->assertIsArray($lockEntry);
        $this->assertEmpty($lockEntry);
    }

    public function testFromJson(): void
    {
        $identifier = $this->getRandomString();

        $jsonInput = <<<JSON
[
    {
        "by": "$identifier",
        "until": 10,
        "exclusive": true
    }
]
JSON;

        $lockEntries = LockEntry::fromJson($jsonInput);
        $this->assertIsArray($lockEntries);
        $this->assertCount(1, $lockEntries);

        $this->assertEquals($identifier, $lockEntries[0]->getBy());
        $this->assertEquals(10, $lockEntries[0]->getUntil());
        $this->assertTrue($lockEntries[0]->isExclusive());
    }

    public function testToJson(): void
    {
        $identifier = $this->getRandomString();
        $lockEntry = new LockEntry($identifier, 10, true);
        $expectedJsonOutput = <<<JSON
{
    "by": "$identifier",
    "until": 10,
    "exclusive": true
}
JSON;

        $this->assertJsonStringEqualsJsonString($expectedJsonOutput, json_encode($lockEntry, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    public function testIsByReturnsFalseIfIdentifierIsNull(): void
    {
        $lockEntry = new LockEntry();
        $this->assertFalse($lockEntry->isBy(null));
    }

    public function testGetRemainingTimeIsCorrect(): void
    {
        $lockEntry = new LockEntry($this->getRandomString(), time() + 10);
        $this->assertEquals(10, $lockEntry->getRemainingTime());
        sleep(1);
        $this->assertEquals(9, $lockEntry->getRemainingTime());
    }

    public function testIsExpiredIsCorrect(): void
    {
        $lockEntry = new LockEntry($this->getRandomString(), time() + 1);
        $this->assertFalse($lockEntry->isExpired());
        sleep(2);
        $this->assertTrue($lockEntry->isExpired());
    }

    public function testSetRemainingTimeIsCorrect(): void
    {
        $lockEntry = new LockEntry($this->getRandomString(), time() + 10);
        $this->assertEquals(10, $lockEntry->getRemainingTime());

        $lockEntry->setRemaining(5);
        $this->assertEquals(5, $lockEntry->getRemainingTime());
    }

    public function testSetUntilIsCorrect(): void
    {
        $lockEntry = new LockEntry($this->getRandomString(), time() + 10);
        $this->assertEquals(10, $lockEntry->getRemainingTime());
        $this->assertEquals(time() + 10, $lockEntry->getUntil());

        $lockEntry->setUntil(time() + 5);
        $this->assertEquals(5, $lockEntry->getRemainingTime());
        $this->assertEquals(time() + 5, $lockEntry->getUntil());
    }


}
