<?php

namespace Aternos\Lock\Test;

use Aternos\Etcd\Exception\Status\InvalidResponseStatusCodeException;
use Aternos\Lock\Lock;
use Aternos\Lock\TooManySaveRetriesException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class LockTest extends TestCase
{
    protected function getRandomString($length = 16): string
    {
        return substr(str_shuffle(str_repeat($x = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil($length / strlen($x)))), 1, $length);
    }

    /**
     * @throws InvalidResponseStatusCodeException
     * @throws TooManySaveRetriesException
     */
    public function testCreateLock(): void
    {
        $key = $this->getRandomString();
        $identifier = $this->getRandomString();

        $this->assertTrue(($lock = new Lock($key, $identifier, false, 10, 0))->lock());
        $this->assertGreaterThanOrEqual(8, $lock->getRemainingLockDuration());

        $lock->break();
    }

    public function testConstructLockWithDefaultIdentifier(): void
    {
        $lock = new Lock("key");
        $this->assertIsString($lock->getIdentifier());
    }

    public function testConstructLockWithIdentifier(): void
    {
        $lock = new Lock("key", "identifier");
        $this->assertEquals("identifier", $lock->getIdentifier());
    }

    public function testSetIdentifier(): void
    {
        $lock = new Lock("key");
        $lock->setIdentifier("identifier");
        $this->assertEquals("identifier", $lock->getIdentifier());
    }

    public function testGetkey(): void
    {
        $lock = new Lock("key");
        $this->assertEquals("key", $lock->getKey());
    }

    /**
     * @throws TooManySaveRetriesException
     * @throws InvalidResponseStatusCodeException
     */
    public function testBreakLock(): void
    {
        $key = $this->getRandomString();
        $identifier = $this->getRandomString();

        $lock = new Lock($key, $identifier, false, 10, 0);
        $lock->lock();
        $this->assertTrue($lock->isLocked());
        $lock->break();
        $this->assertFalse($lock->isLocked());
    }

    public function testBreakLockTwice(): void
    {
        # Check that break() and isLocked() are called when breakOnDestruct is set to true
        $breakingLock = $this->getMockBuilder(Lock::class)
            ->setConstructorArgs([$this->getRandomString()])
            ->onlyMethods(['removeLock', 'isLocked'])
            ->getMock();
        $breakingLock->setBreakOnDestruct(true);
        $breakingLock
            ->expects($this->once())
            ->method('removeLock');
        $breakingLock
            ->expects($this->exactly(3))
            ->method('isLocked')
            ->willReturn(true, true, false);

        $breakingLock->lock();

        $breakingLock->break();
        $breakingLock->break();
    }

    /**
     * @throws InvalidResponseStatusCodeException
     * @throws TooManySaveRetriesException
     */
    public function testAutoReleaseLock(): void
    {
        $key = $this->getRandomString();
        $identifier = $this->getRandomString();

        $lock = new Lock($key, $identifier, false, 3, 0);
        $lock->lock();
        $this->assertTrue($lock->isLocked());
        sleep(3);
        $this->assertFalse($lock->isLocked());

        $lock->break();
    }

    /**
     * @throws TooManySaveRetriesException
     * @throws InvalidResponseStatusCodeException
     */
    public function testRefreshLock(): void
    {
        $key = $this->getRandomString();
        $identifier = $this->getRandomString();

        $lock = new Lock($key, $identifier, false, 3, 0, 5);
        $lock->lock();
        $this->assertTrue($lock->isLocked());
        sleep(1);
        $lock->refresh();
        $this->assertGreaterThan(3, $lock->getRemainingLockDuration());
        sleep(2);
        $this->assertTrue($lock->isLocked());
        $lock->break();
        $this->assertFalse($lock->isLocked());
    }

    /**
     * @throws TooManySaveRetriesException
     * @throws InvalidResponseStatusCodeException
     */
    public function testRefreshLockThreshold(): void
    {
        $key = $this->getRandomString();
        $identifier = $this->getRandomString();

        $lock = new Lock($key, $identifier, false, 10, 0, refreshThreshold: 5);
        $lock->lock();
        $this->assertTrue($lock->isLocked());
        sleep(3);
        $lock->refresh();
        $this->assertGreaterThan(3, $lock->getRemainingLockDuration());
        $this->assertLessThan(8, $lock->getRemainingLockDuration());
        sleep(3);
        $lock->refresh();
        $this->assertGreaterThan(8, $lock->getRemainingLockDuration());
        $lock->break();
        $this->assertFalse($lock->isLocked());
    }

    /**
     * @throws TooManySaveRetriesException
     * @throws InvalidResponseStatusCodeException
     */
    public function testMultipleSharedLocks(): void
    {
        $key = $this->getRandomString();
        $identifierA = $this->getRandomString();
        $identifierB = $this->getRandomString();
        $identifierC = $this->getRandomString();

        $lockA = new Lock($key, $identifierA, false, 3, 0, refreshTime: 5);
        $lockB = new Lock($key, $identifierB, false, 3, 0);
        $lockC = new Lock($key, $identifierC, false, 3, 0);

        $lockA->lock();
        $lockB->lock();
        $lockC->lock();

        $this->assertTrue($lockA->isLocked());
        $this->assertTrue($lockB->isLocked());
        $this->assertTrue($lockC->isLocked());

        sleep(1);
        $lockA->refresh();
        $this->assertGreaterThan(3, $lockA->getRemainingLockDuration());
        sleep(2);

        $this->assertTrue($lockA->isLocked());
        $this->assertFalse($lockB->isLocked());
        $this->assertFalse($lockC->isLocked());

        $lockA->break();
        $this->assertFalse($lockA->isLocked());

        $lockB->break();
        $lockC->break();
    }

    /**
     * @throws TooManySaveRetriesException
     * @throws InvalidResponseStatusCodeException
     */
    public function testExclusiveLock(): void
    {
        $key = $this->getRandomString();
        $identifier = $this->getRandomString();

        $lock = new Lock($key, $identifier, true, 10, 0);
        $lock->lock();
        $this->assertTrue($lock->isLocked());
        $lock->break();
        $this->assertFalse($lock->isLocked());

        $lock->break();
    }

    /**
     * @throws TooManySaveRetriesException
     * @throws InvalidResponseStatusCodeException
     */
    public function testWaitForExclusiveLockAfterExclusiveLock(): void
    {
        $key = $this->getRandomString();
        $identifierA = $this->getRandomString();
        $identifierB = $this->getRandomString();

        $lockA = new Lock($key, $identifierA, true, 3, 0);
        $lockA->lock();
        $this->assertTrue($lockA->isLocked());

        $lockB = new Lock($key, $identifierB, true, 3, 5);
        $lockB->lock();
        $this->assertTrue($lockB->isLocked());

        $lockA->break();
        $lockB->break();
    }

    /**
     * @throws TooManySaveRetriesException
     * @throws InvalidResponseStatusCodeException
     */
    public function testRejectSharedLockWhileExclusiveLock(): void
    {
        $key = $this->getRandomString();
        $identifierA = $this->getRandomString();
        $identifierB = $this->getRandomString();

        $lockA = new Lock($key, $identifierA, true, 3, 0);
        $lockA->lock();
        $this->assertTrue($lockA->isLocked());
        $lockB = new Lock($key, $identifierB, false, 3, 0);
        $lockB->lock();
        $this->assertFalse($lockB->isLocked());

        $lockA->break();
        $lockB->break();
    }

    /**
     * @throws TooManySaveRetriesException
     * @throws InvalidResponseStatusCodeException
     */
    public function testRejectExclusiveLockWhileSharedLock(): void
    {
        $key = $this->getRandomString();
        $identifierA = $this->getRandomString();
        $identifierB = $this->getRandomString();

        $lockA = new Lock($key, $identifierA, false, 3, 0);
        $lockA->lock();
        $this->assertTrue($lockA->isLocked());
        $lockB = new Lock($key, $identifierB, true, 3, 0);
        $lockB->lock();
        $this->assertFalse($lockB->isLocked());

        $lockA->break();
        $lockB->break();
    }

    /**
     * @throws TooManySaveRetriesException
     * @throws InvalidResponseStatusCodeException
     */
    public function testWaitForExclusiveLockAfterMultipleSharedLocks(): void
    {
        $key = $this->getRandomString();
        $identifierA = $this->getRandomString();
        $identifierB = $this->getRandomString();
        $identifierC = $this->getRandomString();
        $identifierD = $this->getRandomString();

        $lockA = new Lock($key, $identifierA, false, 3, 0);
        $lockB = new Lock($key, $identifierB, false, 5, 0);
        $lockC = new Lock($key, $identifierC, false, 8, 0);

        $lockA->lock();
        $lockB->lock();
        $lockC->lock();

        $this->assertTrue($lockA->isLocked());
        $this->assertTrue($lockB->isLocked());
        $this->assertTrue($lockC->isLocked());

        $time = time();
        $lockD = new Lock($key, $identifierD, true, 3, 10);
        $lockD->lock();
        $this->assertGreaterThan(7, time() - $time);

        $lockA->break();
        $lockB->break();
        $lockC->break();
        $lockD->break();
    }

    /**
     * @throws TooManySaveRetriesException
     * @throws InvalidResponseStatusCodeException
     */
    public function testLockWriteConflict(): void
    {
        $key = $this->getRandomString();
        $identifierA = $this->getRandomString();
        $identifierB = $this->getRandomString();

        $lockA = new PublicLock($key, $identifierA, false, 5, 0);
        $lockA->lock();
        $lockB = new PublicLock($key, $identifierB, false, 5, 0);
        $lockB->lock();

        $this->assertTrue($lockA->isLocked());
        $this->assertTrue($lockB->isLocked());

        $lockA->addOrUpdateLock($lockA->getTime());

        $this->assertTrue($lockA->isLocked());
        $this->assertTrue($lockB->isLocked());

        $lockB->update();

        $this->assertTrue($lockB->isLocked());

        $lockA->update();

        $this->assertTrue($lockA->isLocked());

        $lockA->break();
        $lockB->break();
    }

    /**
     * @throws TooManySaveRetriesException
     * @throws InvalidResponseStatusCodeException
     */
    public function testLockDeleteConflict(): void
    {
        $key = $this->getRandomString();
        $identifierA = $this->getRandomString();
        $identifierB = $this->getRandomString();

        $lockA = new PublicLock($key, $identifierA, false, 5, 0);
        $lockA->lock();
        $lockB = new PublicLock($key, $identifierB, false, 5, 0);
        $lockB->lock();

        $this->assertTrue($lockA->isLocked());
        $this->assertTrue($lockB->isLocked());

        $lockA->removeLock();
        $this->assertFalse($lockA->isLocked());

        $lockA->update();
        $this->assertFalse($lockA->isLocked());
        $this->assertTrue($lockB->isLocked());

        $lockB->update();
        $this->assertTrue($lockB->isLocked());

        $lockA->break();
        $lockB->break();
    }

    /**
     * @throws InvalidResponseStatusCodeException
     * @throws TooManySaveRetriesException
     */
    public function testLockFunctionUsesUniqueDefaultIdentifierIfNoIdentifierParameterIsProvided(): void
    {
        # Default identifier is not set explicitly and its default value is null.
        # (We have to set it to null manually because other tests might have set the default identifier before.)
        $reflection = new ReflectionProperty(Lock::class, 'defaultIdentifier');
        $reflection->setValue(null, null);
        $this->assertNull($reflection->getValue());

        $lock = new Lock($this->getRandomString());
        $lock->lock();
        $defaultIdentifier = $reflection->getValue();
        $this->assertIsString($defaultIdentifier);
        $this->assertNotEmpty($defaultIdentifier);

        # and uses the default identifier as identifier because no identifier parameter is provided
        $this->assertEquals($defaultIdentifier, $lock->getIdentifier());

        $lock->break();
    }

    /**
     * @throws InvalidResponseStatusCodeException
     * @throws TooManySaveRetriesException
     */
    public function testLockFunctionUsesThePresetDefaultIdentifierIfNoIdentifierParameterIsProvided(): void
    {
        # Default identifier is set
        Lock::setDefaultIdentifier('testDefaultIdentifier');
        $lock = new Lock($this->getRandomString());
        $lock->lock();
        $this->assertEquals('testDefaultIdentifier', $lock->getIdentifier());
        $lock->break();
    }

    /**
     * @throws InvalidResponseStatusCodeException
     * @throws TooManySaveRetriesException
     */
    public function testBreaksOnDestructWhenBreakOnDestructPropertyIsTrue(): void
    {
        # Check that break() and isLocked() are called when breakOnDestruct is set to true
        $breakingLock = $this->getMockBuilder(Lock::class)
            ->setConstructorArgs([$this->getRandomString()])
            ->onlyMethods(['break'])
            ->getMock();
        $breakingLock->setBreakOnDestruct(true);
        $breakingLock
            ->expects($this->once())
            ->method('break');

        $breakingLock->__destruct();
    }

    /**
     * @throws InvalidResponseStatusCodeException
     * @throws TooManySaveRetriesException
     */
    public function testDoesNotBreakOnDestructWhenBreakOnDestructPropertyIsFalse(): void
    {
        # Check that break() and isLocked() are not called when breakOnDestruct is set to false
        $notBreakingLock = $this->getMockBuilder(Lock::class)
            ->setConstructorArgs([$this->getRandomString()])
            ->onlyMethods(['isLocked', 'break'])
            ->getMock();
        $notBreakingLock->setBreakOnDestruct(false);
        $notBreakingLock
            ->expects($this->never())
            ->method('isLocked')
            ->willReturn(true);
        $notBreakingLock
            ->expects($this->never())
            ->method('break');

        $notBreakingLock->__destruct();
    }

    /**
     * @throws InvalidResponseStatusCodeException
     * @throws TooManySaveRetriesException
     */
    public function testCanLockWithSameKeyTwiceWhenIsNotExclusive(): void
    {
        $key = $this->getRandomString();
        $identifierA = $this->getRandomString();
        $identifierB = $this->getRandomString();

        $lockA = new PublicLock($key, $identifierA, false, 5, 0);
        $lockA->lock();
        $this->assertFalse($lockA->isLockedByOther());

        $lockB = new PublicLock($key, $identifierB, false, 5, 0);
        $lockB->lock();
        # is not locked by other because it was not updated
        $this->assertFalse($lockA->isLockedByOther());
        # is locked by other because it was updated
        $this->assertTrue($lockB->isLockedByOther());

        $lockA->update();
        $this->assertTrue($lockA->isLockedByOther());
        $lockB->update();
        $this->assertTrue($lockB->isLockedByOther());

        $lockA->break();
        $lockB->break();

        $lockA->update();
        $this->assertFalse($lockA->isLockedByOther());
        $lockB->update();
        $this->assertFalse($lockB->isLockedByOther());
    }

    /**
     * @throws InvalidResponseStatusCodeException
     * @throws TooManySaveRetriesException
     */
    public function testCannotLockWithSameKeyTwiceWhenIsExclusive(): void
    {
        $key = $this->getRandomString();
        $identifierA = $this->getRandomString();
        $identifierB = $this->getRandomString();

        $lockA = new PublicLock($key, $identifierA, true, 999, 0);
        $lockA->lock();
        $this->assertNotFalse($lockA->isLocked());
        $this->assertFalse($lockA->isLockedByOtherExclusively());

        $lockB = new PublicLock($key, $identifierB, true, 999, 0);
        $lockB->lock();
        # is not locked because lockA already got the lock
        $this->assertFalse($lockB->isLocked());
        # is locked by other because it was updated
        $this->assertTrue($lockB->isLockedByOtherExclusively());

        $lockA->update();
        $lockB->update();
        # is locked by other because the lock on lockA is active
        $this->assertTrue($lockB->isLockedByOtherExclusively());
        # is not locked by other because lockA has the only exclusive lock
        $this->assertFalse($lockA->isLockedByOtherExclusively());

        $lockA->break();
        $lockB->break();

        $lockA->update();
        $this->assertFalse($lockA->isLockedByOtherExclusively());
        $lockB->update();
        $this->assertFalse($lockB->isLockedByOtherExclusively());
    }

}

/**
 * Class PublicLock
 *
 * Make some functions public for testing purposes
 */
class PublicLock extends Lock
{
    public function addOrUpdateLock(int $time): bool
    {
        return parent::addOrUpdateLock($time);
    }

    public function removeLock(): void
    {
        parent::removeLock();
    }
}
