<?php

namespace Aternos\Lock\Test;

use Aternos\Etcd\Exception\Status\InvalidResponseStatusCodeException;
use Aternos\Lock\EtcdLock;
use Aternos\Lock\Lock;
use Aternos\Lock\TooManySaveRetriesException;
use PHPUnit\Framework\TestCase;

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

        $this->assertTrue(($lock = new Lock($key))->lock(false, 10, 0, $identifier));
        $this->assertTrue($lock->isLocked() >= 8);

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

    /**
     * @throws InvalidResponseStatusCodeException
     * @throws TooManySaveRetriesException
     */
    public function testCreateEtcdLock(): void
    {
        $key = $this->getRandomString();
        $identifier = $this->getRandomString();

        $lock = new EtcdLock($key, false, 10, 0, $identifier);
        $this->assertTrue($lock->isLocked() >= 8);

        $lock->break();
    }

    /**
     * @throws TooManySaveRetriesException
     * @throws InvalidResponseStatusCodeException
     */
    public function testBreakLock(): void
    {
        $key = $this->getRandomString();
        $identifier = $this->getRandomString();

        $lock = new EtcdLock($key, false, 10, 0, $identifier);
        $this->assertTrue($lock->isLocked() > 0);
        $lock->break();
        $this->assertFalse($lock->isLocked());
    }

    /**
     * @throws InvalidResponseStatusCodeException
     * @throws TooManySaveRetriesException
     */
    public function testAutoReleaseLock(): void
    {
        $key = $this->getRandomString();
        $identifier = $this->getRandomString();

        $lock = new EtcdLock($key, false, 3, 0, $identifier);
        $this->assertTrue($lock->isLocked() > 0);
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

        $lock = new EtcdLock($key, false, 3, 0, $identifier);
        $this->assertTrue($lock->isLocked() > 0);
        sleep(1);
        $lock->refresh(5);
        $this->assertTrue($lock->isLocked() > 3);
        sleep(2);
        $this->assertTrue($lock->isLocked() > 0);
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

        $lock = new EtcdLock($key, false, 10, 0, $identifier);
        $this->assertTrue($lock->isLocked() > 0);
        sleep(3);
        $lock->refresh(10, 5);
        $this->assertTrue($lock->isLocked() > 3);
        $this->assertTrue($lock->isLocked() < 8);
        sleep(3);
        $lock->refresh(10, 5);
        $this->assertTrue($lock->isLocked() > 8);
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

        $lockA = new EtcdLock($key, false, 3, 0, $identifierA);
        $lockB = new EtcdLock($key, false, 3, 0, $identifierB);
        $lockC = new EtcdLock($key, false, 3, 0, $identifierC);

        $this->assertTrue($lockA->isLocked() > 0);
        $this->assertTrue($lockB->isLocked() > 0);
        $this->assertTrue($lockC->isLocked() > 0);

        sleep(1);
        $lockA->refresh(5);
        $this->assertTrue($lockA->isLocked() > 3);
        sleep(2);

        $this->assertTrue($lockA->isLocked() > 0);
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

        $lock = new EtcdLock($key, true, 10, 0, $identifier);
        $this->assertTrue($lock->isLocked() > 0);
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

        $lockA = new EtcdLock($key, true, 3, 0, $identifierA);
        $this->assertTrue($lockA->isLocked() > 0);
        $lockB = new EtcdLock($key, true, 3, 5, $identifierB);
        $this->assertTrue($lockB->isLocked() > 0);

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

        $lockA = new EtcdLock($key, true, 3, 0, $identifierA);
        $this->assertTrue($lockA->isLocked() > 0);
        $lockB = new EtcdLock($key, false, 3, 0, $identifierB);
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

        $lockA = new EtcdLock($key, false, 3, 0, $identifierA);
        $this->assertTrue($lockA->isLocked() > 0);
        $lockB = new EtcdLock($key, true, 3, 0, $identifierB);
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

        $lockA = new EtcdLock($key, false, 3, 0, $identifierA);
        $lockB = new EtcdLock($key, false, 5, 0, $identifierB);
        $lockC = new EtcdLock($key, false, 8, 0, $identifierC);

        $this->assertTrue($lockA->isLocked() > 0);
        $this->assertTrue($lockB->isLocked() > 0);
        $this->assertTrue($lockC->isLocked() > 0);

        $time = time();
        $lockD = new EtcdLock($key, true, 3, 10, $identifierD);
        $this->assertTrue(time() - $time >= 7);

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

        $lockA = new PublicLock($key, false, 5, 0, $identifierA);
        $lockB = new PublicLock($key, false, 5, 0, $identifierB);

        $this->assertTrue($lockA->isLocked() > 0);
        $this->assertTrue($lockB->isLocked() > 0);

        $lockA->addOrUpdateLock();

        $this->assertTrue($lockA->isLocked() > 0);
        $this->assertTrue($lockB->isLocked() > 0);

        $lockB->update();

        $this->assertTrue($lockB->isLocked() > 0);

        $lockA->update();

        $this->assertTrue($lockA->isLocked() > 0);

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

        $lockA = new PublicLock($key, false, 5, 0, $identifierA);
        $lockB = new PublicLock($key, false, 5, 0, $identifierB);

        $this->assertTrue($lockA->isLocked() > 0);
        $this->assertTrue($lockB->isLocked() > 0);

        $lockA->removeLock();
        $this->assertFalse($lockA->isLocked());

        $lockA->update();
        $this->assertFalse($lockA->isLocked());
        $this->assertTrue($lockB->isLocked() > 0);

        $lockB->update();
        $this->assertTrue($lockB->isLocked() > 0);

        $lockA->break();
        $lockB->break();
    }

    /**
     * @throws InvalidResponseStatusCodeException
     * @throws TooManySaveRetriesException
     */
    public function testLockFunctionUsesUniqueDefaultIdentifierIfNoIdentifierParameterIsProvided(): void
    {
        $lock = new Lock($this->getRandomString());

        # Default identifier is not set explicitly and its default value is null.
        # (We have to set it to null manually because other tests might have set the default identifier before.)
        $reflection = new \ReflectionProperty(Lock::class, 'defaultIdentifier');
        $reflection->setAccessible(true);
        $reflection->setValue(null);
        $this->assertNull($reflection->getValue());

        # lock() sets the default identifier to an uniqid()
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
            ->onlyMethods(['isLocked', 'break'])
            ->getMock();
        $breakingLock->setBreakOnDestruct(true);
        $breakingLock
            ->expects($this->once())
            ->method('isLocked')
            ->willReturn(true);
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

        $lockA = new PublicLock($key, false, 5, 0, $identifierA);
        $this->assertFalse($lockA->isLockedByOther());

        $lockB = new PublicLock($key, false, 5, 0, $identifierB);
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

        $lockA = new PublicLock($key, true, 999, 0, $identifierA);
        $this->assertNotFalse($lockA->isLocked());
        $this->assertFalse($lockA->isLockedByOtherExclusively());

        $lockB = new PublicLock($key, true, 999, 0, $identifierB);
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
class PublicLock extends EtcdLock
{
    public function addOrUpdateLock(): bool
    {
        return parent::addOrUpdateLock();
    }

    public function removeLock(): bool
    {
        return parent::removeLock();
    }
}