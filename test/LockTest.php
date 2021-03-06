<?php

use PHPUnit\Framework\TestCase;
use Aternos\Lock\EtcdLock as Lock;

class LockTest extends TestCase
{
    protected function getRandomString($length = 16)
    {
        return substr(str_shuffle(str_repeat($x = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil($length / strlen($x)))), 1, $length);
    }

    public function testCreateLock()
    {
        $key = $this->getRandomString();
        $identifier = $this->getRandomString();

        $lock = new Lock($key, false, 10, 0, $identifier);
        $this->assertTrue($lock->isLocked() >= 8);

        $lock->break();
    }

    public function testBreakLock()
    {
        $key = $this->getRandomString();
        $identifier = $this->getRandomString();

        $lock = new Lock($key, false, 10, 0, $identifier);
        $this->assertTrue($lock->isLocked() > 0);
        $lock->break();
        $this->assertFalse($lock->isLocked());
    }

    public function testAutoReleaseLock()
    {
        $key = $this->getRandomString();
        $identifier = $this->getRandomString();

        $lock = new Lock($key, false, 3, 0, $identifier);
        $this->assertTrue($lock->isLocked() > 0);
        sleep(3);
        $this->assertFalse($lock->isLocked());

        $lock->break();
    }

    public function testRefreshLock()
    {
        $key = $this->getRandomString();
        $identifier = $this->getRandomString();

        $lock = new Lock($key, false, 3, 0, $identifier);
        $this->assertTrue($lock->isLocked() > 0);
        sleep(1);
        $lock->refresh(5);
        $this->assertTrue($lock->isLocked() > 3);
        sleep(2);
        $this->assertTrue($lock->isLocked() > 0);
        $lock->break();
        $this->assertFalse($lock->isLocked());
    }

    public function testRefreshLockThreshold()
    {
        $key = $this->getRandomString();
        $identifier = $this->getRandomString();

        $lock = new Lock($key, false, 10, 0, $identifier);
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

    public function testMultipleSharedLocks()
    {
        $key = $this->getRandomString();
        $identifierA = $this->getRandomString();
        $identifierB = $this->getRandomString();
        $identifierC = $this->getRandomString();

        $lockA = new Lock($key, false, 3, 0, $identifierA);
        $lockB = new Lock($key, false, 3, 0, $identifierB);
        $lockC = new Lock($key, false, 3, 0, $identifierC);

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

    public function testExclusiveLock()
    {
        $key = $this->getRandomString();
        $identifier = $this->getRandomString();

        $lock = new Lock($key, true, 10, 0, $identifier);
        $this->assertTrue($lock->isLocked() > 0);
        $lock->break();
        $this->assertFalse($lock->isLocked());

        $lock->break();
    }

    public function testWaitForExclusiveLockAfterExclusiveLock()
    {
        $key = $this->getRandomString();
        $identifierA = $this->getRandomString();
        $identifierB = $this->getRandomString();

        $lockA = new Lock($key, true, 3, 0, $identifierA);
        $this->assertTrue($lockA->isLocked() > 0);
        $lockB = new Lock($key, true, 3, 5, $identifierB);
        $this->assertTrue($lockB->isLocked() > 0);

        $lockA->break();
        $lockB->break();
    }

    public function testRejectSharedLockWhileExclusiveLock()
    {
        $key = $this->getRandomString();
        $identifierA = $this->getRandomString();
        $identifierB = $this->getRandomString();

        $lockA = new Lock($key, true, 3, 0, $identifierA);
        $this->assertTrue($lockA->isLocked() > 0);
        $lockB = new Lock($key, false, 3, 0, $identifierB);
        $this->assertFalse($lockB->isLocked());

        $lockA->break();
        $lockB->break();
    }

    public function testRejectExclusiveLockWhileSharedLock()
    {
        $key = $this->getRandomString();
        $identifierA = $this->getRandomString();
        $identifierB = $this->getRandomString();

        $lockA = new Lock($key, false, 3, 0, $identifierA);
        $this->assertTrue($lockA->isLocked() > 0);
        $lockB = new Lock($key, true, 3, 0, $identifierB);
        $this->assertFalse($lockB->isLocked());

        $lockA->break();
        $lockB->break();
    }

    public function testWaitForExclusiveLockAfterMultipleSharedLocks()
    {
        $key = $this->getRandomString();
        $identifierA = $this->getRandomString();
        $identifierB = $this->getRandomString();
        $identifierC = $this->getRandomString();
        $identifierD = $this->getRandomString();

        $lockA = new Lock($key, false, 3, 0, $identifierA);
        $lockB = new Lock($key, false, 5, 0, $identifierB);
        $lockC = new Lock($key, false, 8, 0, $identifierC);

        $this->assertTrue($lockA->isLocked() > 0);
        $this->assertTrue($lockB->isLocked() > 0);
        $this->assertTrue($lockC->isLocked() > 0);

        $time = time();
        $lockD = new Lock($key, true, 3, 10, $identifierD);
        $this->assertTrue(time() - $time >= 7);

        $lockA->break();
        $lockB->break();
        $lockC->break();
        $lockD->break();
    }

    public function testLockWriteConflict()
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

    public function testLockDeleteConflict()
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
}

/**
 * Class PublicLock
 *
 * Make some functions public for testing purposes
 */
class PublicLock extends Lock
{
    public function addOrUpdateLock()
    {
        return parent::addOrUpdateLock();
    }

    public function removeLock()
    {
        return parent::removeLock();
    }

    public function update()
    {
        parent::update();
    }
}