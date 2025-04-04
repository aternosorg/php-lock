<?php

namespace Aternos\Lock;

use Aternos\Lock\Storage\EtcdStorage;
use Aternos\Lock\Storage\StorageException;
use Aternos\Lock\Storage\StorageInterface;
use Exception;

/**
 * LockInterface implementation using etcd-like storage
 *
 * @package Aternos\Lock
 */
class Lock extends AbstractLock
{
    /**
     * @see static::setStorage()
     * @var StorageInterface|null
     */
    protected static ?StorageInterface $storage = null;

    /**
     * see Lock::setPrefix()
     *
     * @var string
     */
    protected static string $prefix = "lock/";

    /**
     * see Lock::setWaitRetryInterval()
     *
     * @var int
     */
    protected static int $waitRetryInterval = 1;

    /**
     * see Lock::setMaxSaveRetries()
     *
     * @var int
     */
    protected static int $maxSaveRetries = 100;

    /**
     * see Lock::setMaxDelayPerSaveRetry()
     *
     * @var int
     */
    protected static int $maxDelayPerSaveRetry = 1000;

    /**
     * see Lock::setMaxUnavailableRetries()
     *
     * @var int
     */
    protected static int $maxUnavailableRetries = 3;

    /**
     * see Lock::setDelayPerUnavailableRetry()
     *
     * @var int
     */
    protected static int $delayPerUnavailableRetry = 1;

    /**
     * Set the storage interface used to store locks. If not set, {@link EtcdStorage} is used.
     * @param StorageInterface $storage
     * @return void
     */
    public static function setStorage(StorageInterface $storage): void
    {
        static::$storage = $storage;
    }

    /**
     * Get the storage interface used to store locks. If not set, {@link EtcdStorage} is used.
     * @return StorageInterface
     */
    protected static function getStorage(): StorageInterface
    {
        return static::$storage ??= new EtcdStorage();
    }

    /**
     * Set the prefix for all etcd keys (default "lock/")
     *
     * @param string $prefix
     */
    public static function setPrefix(string $prefix): void
    {
        static::$prefix = $prefix;
    }

    /**
     * Set the interval (in seconds) used to retry the locking if it's already locked
     *
     * @param int $interval
     */
    public static function setWaitRetryInterval(int $interval): void
    {
        static::$waitRetryInterval = $interval;
    }

    /**
     * Set the maximum save retries until a request should fail (throw TooManySaveRetriesException)
     *
     * Default is 100
     *
     * @param int $retries
     */
    public static function setMaxSaveRetries(int $retries): void
    {
        static::$maxSaveRetries = $retries;
    }

    /**
     * Set the maximum delay in microseconds (1,000,000 microseconds = 1 second) that should be used for the random
     * delay between retries.
     *
     * The delay is random and calculated like this: rand(0, $retries * $delayPerRetry)
     *
     * Lower value = faster retries (probably more retries necessary)
     * Higher value = slower retries (probably fewer retries necessary)
     *
     * @param int $delayPerRetry
     */
    public static function setMaxDelayPerSaveRetry(int $delayPerRetry): void
    {
        static::$maxDelayPerSaveRetry = $delayPerRetry;
    }

    /**
     * Set the maximum retries in case of an UnavailableException from etcd
     *
     * @param int $retries
     */
    public static function setMaxUnavailableRetries(int $retries): void
    {
        static::$maxUnavailableRetries = $retries;
    }

    /**
     * Delay in seconds between retries in case of an UnavailableException from etcd
     *
     * @param int $delayPerRetry
     */
    public static function setDelayPerUnavailableRetry(int $delayPerRetry): void
    {
        static::$delayPerUnavailableRetry = $delayPerRetry;
    }

    /**
     * Full name of the key in etcd (prefix + key)
     *
     * @var string|null
     */
    protected ?string $etcdKey = null;

    /**
     * Used to store the previous lock string
     *
     * Will be used in deleteIf and putIf requests to check
     * if there was no change in etcd while processing the lock
     *
     * @var string
     */
    protected ?string $previousLockString = null;

    /**
     * Current parsed locks
     *
     * @var LockEntry[]
     */
    protected array $locks = [];

    /**
     * Retry counter
     *
     * @var int
     */
    protected int $retries = 0;

    /**
     * Create a lock
     *
     * @param string $key Can be anything, should describe the resource in a unique way
     * @param string|null $identifier An identifier for this lock, falls back to the default identifier if null
     * @param int $time Timeout time of the lock in seconds. The lock will be released if this timeout is reached.
     * @param bool $exclusive Is this lock exclusive (true) or shared (false)
     * @param int $waitTime Time in seconds to wait for existing locks to be released.
     * @param int|null $refreshTime Duration in seconds the timeout should be set to when refreshing the lock.
     * If null the initial timeout will be used.
     * @param int $refreshThreshold Maximum duration in seconds the existing lock may be valid for to be refreshed.
     * If the lock is valid for longer than this time, the lock will not be refreshed.
     * @param bool $breakOnDestruct Automatically try to break the lock on destruct if possible
     */
    public function __construct(
        string $key,
        ?string $identifier = null,
        bool $exclusive = false,
        int $time = 120,
        int $waitTime = 300,
        ?int $refreshTime = null,
        int $refreshThreshold = 30,
        bool $breakOnDestruct = true,
    )
    {
        parent::__construct($key, $identifier, $exclusive, $time, $waitTime, $refreshTime, $refreshThreshold, $breakOnDestruct);
        $this->etcdKey = static::$prefix . $this->key;
    }

    /**
     * Try to acquire lock
     *
     * @return bool true if the lock was acquired, false if it was not
     * @throws StorageException
     * @throws TooManySaveRetriesException
     */
    public function lock(): bool
    {
        $this->retries = 0;

        do {
            $this->waitForOtherLocks();
            $retry = false;
            if ($this->canLock()) {
                $retry = !$this->addOrUpdateLock($this->time);
            }
        } while ($retry);

        return $this->isLocked();
    }

    /**
     * Wait for other locks to be released. This method will only wait if the timeout of the existing lock ends within
     * the specified wait time. If the timeout of the existing lock ends after the wait time, this method will return
     * immediately, even though the lock holder might voluntarily break the lock before the timeout.
     *
     * @param int|null $waitTime maximum time in seconds to wait for other locks
     * @return bool
     * @throws StorageException
     */
    public function waitForOtherLocks(?int $waitTime = null): bool
    {
        $waitTime ??= $this->waitTime;
        $startTime = time();
        $this->update();

        while (!$this->canLock() && $startTime + $waitTime > time()) {
            sleep(static::$waitRetryInterval);
            $this->update();
        }

        return $this->canLock();
    }

    /**
     * Get the time until the lock runs out. This method will return -1 if the lock is not valid or other negative values
     * if the lock has already run out.
     *
     * @return int
     */
    public function getRemainingLockDuration(): int
    {
        foreach ($this->locks as $lock) {
            if ($lock->isBy($this->identifier)) {
                return $lock->getRemainingTime();
            }
        }

        return -1;
    }

    /**
     * Refresh the lock
     *
     * @return boolean
     * @throws StorageException
     * @throws TooManySaveRetriesException
     */
    public function refresh(): bool
    {
        if ($this->refreshThreshold > 0 && $this->getRemainingLockDuration() > $this->refreshThreshold) {
            return true;
        }

        $this->update();
        $this->retries = 0;

        do {
            if (!$this->canLock()) {
                return false;
            }

            $retry = !$this->addOrUpdateLock($this->refreshTime ?? $this->time);
        } while ($retry);
        return true;
    }

    /**
     * Break the lock
     *
     * Should be only used if you have the lock
     *
     * @return void
     * @throws StorageException
     * @throws TooManySaveRetriesException
     */
    public function break(): void
    {
        if (!$this->isLocked()) {
            return;
        }

        $this->update();
        $this->retries = 0;
        $this->removeLock();
    }

    /**
     * Generate the lock object
     *
     * @return LockEntry
     */
    protected function generateLock(): LockEntry
    {
        return new LockEntry($this->identifier, time() + $this->time, $this->exclusive);
    }

    /**
     * Remove a lock from the locking array and save the locks
     *
     * @return void
     * @throws StorageException
     * @throws TooManySaveRetriesException
     */
    protected function removeLock(): void
    {
        do {
            foreach ($this->locks as $i => $lock) {
                if ($lock->isBy($this->identifier)) {
                    unset($this->locks[$i]);
                }
            }
            $success = $this->saveLocks();
        } while ($success === false);
    }

    /**
     * Add a lock to the locking array or update the current lock
     *
     * A 'false' return value can/should be retried, see Lock::saveLocks()
     *
     * @param int $time
     * @return bool
     * @throws StorageException
     * @throws TooManySaveRetriesException
     */
    protected function addOrUpdateLock(int $time): bool
    {
        foreach ($this->locks as $lock) {
            if ($lock->isBy($this->identifier)) {
                $lock->setRemaining($time);
                return $this->saveLocks();
            }
        }

        $this->locks[] = $this->generateLock();
        return $this->saveLocks();
    }

    /**
     * Save the locks array in etcd
     *
     * A 'false' return value can/should be retried by calling the function again
     * An infinite loop is (hopefully) prevented by the retries counter, an exception
     * is thrown when there are too many retries
     *
     * Before calling this function again the locks should be checked again, if the locks
     * changed since the last update, they will be updated by this function again.
     *
     * @return bool
     * @throws StorageException
     * @throws TooManySaveRetriesException
     * @throws Exception
     */
    protected function saveLocks(): bool
    {
        $previousLocks = $this->previousLockString;

        foreach ($this->locks as $i => $lock) {
            if ($lock->isExpired()) {
                unset($this->locks[$i]);
            }
        }

        $delayRetry = $this->retries >= 3;

        $result = false;
        if (count($this->locks) === 0) {
            for ($i = 1; $i <= static::$maxUnavailableRetries; $i++) {
                try {
                    $result = static::getStorage()->deleteIf($this->etcdKey, $previousLocks, !$delayRetry);
                    break;
                } catch (StorageException $e) {
                    if ($i === static::$maxUnavailableRetries) {
                        throw $e;
                    } else {
                        sleep(static::$delayPerUnavailableRetry);
                        continue;
                    }
                }
            }
        } else {
            $lockString = json_encode(array_values($this->locks));

            for ($i = 1; $i <= static::$maxUnavailableRetries; $i++) {
                try {
                    $result = static::getStorage()->putIf($this->etcdKey, $lockString, $previousLocks, !$delayRetry);
                    break;
                } catch (StorageException $e) {
                    if ($i === static::$maxUnavailableRetries) {
                        throw $e;
                    } else {
                        sleep(static::$delayPerUnavailableRetry);
                        continue;
                    }
                }
            }
        }

        if ($result !== true) {
            if ($this->retries >= static::$maxSaveRetries) {
                throw new TooManySaveRetriesException("Locking cancelled because of too many save retries (" . $this->retries . ").");
            }

            if ($delayRetry) {
                usleep(rand(0, static::$maxDelayPerSaveRetry * $this->retries));
                $this->update();
            } else {
                $this->updateFromString($result);
            }
            $this->retries++;

            return false;
        } else {
            return true;
        }
    }

    /**
     * Check if it is possible to lock
     *
     * @return bool
     */
    protected function canLock(): bool
    {
        foreach ($this->locks as $lock) {
            if (!$lock->isBy($this->identifier) && $lock->isExclusive() && !$lock->isExpired()) {
                return false;
            }

            if (!$lock->isBy($this->identifier) && $this->exclusive && !$lock->isExpired()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Update the locks array from etcd
     *
     * @return $this
     * @throws StorageException
     * @throws Exception
     */
    public function update(): static
    {
        $etcdLockString = false;
        for ($i = 1; $i <= static::$maxUnavailableRetries; $i++) {
            try {
                $etcdLockString = static::getStorage()->get($this->etcdKey);
                break;
            } catch (StorageException $e) {
                if ($i === static::$maxUnavailableRetries) {
                    throw $e;
                } else {
                    sleep(static::$delayPerUnavailableRetry);
                    continue;
                }
            }
        }

        return $this->updateFromString($etcdLockString);
    }

    /**
     * Update the locks array from a JSON string
     *
     * @param ?string $lockString
     * @return $this
     */
    protected function updateFromString(?string $lockString): static
    {
        $this->previousLockString = $lockString;

        if ($lockString) {
            $this->locks = LockEntry::fromJson($lockString);
        } else {
            $this->locks = [];
        }

        return $this;
    }

    /**
     * Break the lock on destruction of this object
     *
     * @throws StorageException
     * @throws TooManySaveRetriesException
     */
    public function __destruct()
    {
        if ($this->breakOnDestruct) {
            $this->break();
        }
    }

    /**
     * @return LockEntry[]
     */
    public function getLocks(): array
    {
        return $this->locks;
    }

    /**
     * Check if lock is acquired by a different process
     *
     * @return bool
     */
    public function isLockedByOther(): bool
    {
        foreach ($this->getLocks() as $lock) {
            if ($lock->isExpired() || $lock->isBy($this->getIdentifier())) {
                continue;
            }
            return true;
        }
        return false;
    }

    /**
     * Check if lock is acquired exclusively by a different process
     *
     * @return bool
     */
    public function isLockedByOtherExclusively(): bool
    {
        foreach ($this->getLocks() as $lock) {
            if ($lock->isExpired() || $lock->isBy($this->getIdentifier()) || !$lock->isExclusive()) {
                continue;
            }
            return true;
        }
        return false;
    }
}
