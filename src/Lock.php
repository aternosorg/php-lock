<?php

namespace Aternos\Lock;

use Aternos\Etcd\Client;
use Aternos\Etcd\ClientInterface;
use Aternos\Etcd\Exception\Status\DeadlineExceededException;
use Aternos\Etcd\Exception\Status\InvalidResponseStatusCodeException;
use Aternos\Etcd\Exception\Status\UnavailableException;
use Aternos\Etcd\Exception\Status\UnknownException;

/**
 * Class Lock
 *
 * @package Aternos\Lock
 */
class Lock implements LockInterface
{
    /**
     * see Lock::setClient()
     *
     * @var ClientInterface|null
     */
    protected static ?ClientInterface $client = null;

    /**
     * see Lock::setPrefix()
     *
     * @var string
     */
    protected static string $prefix = "lock/";

    /**
     * see Lock::setDefaultIdentifier()
     *
     * @var string|null
     */
    protected static ?string $defaultIdentifier = null;

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
     * Set the etcd client (Aternos\Etcd\Client)
     *
     * Uses a localhost client if not set
     *
     * @param ClientInterface $client
     */
    public static function setClient(ClientInterface $client): void
    {
        static::$client = $client;
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
     * Set the default identifier
     *
     * Should be the same for the same synchronous process/request, but should be random
     * enough to never be the same. Can be created with uniqid(). Will fallback to uniqid().
     * If there is already a lock with the same identifier, that lock is used for this lock.
     *
     * Can be set individually on every lock if necessary (see Lock::__construct).
     *
     * @param string|null $defaultIdentifier
     */
    public static function setDefaultIdentifier(?string $defaultIdentifier = null): void
    {
        if ($defaultIdentifier === null) {
            $defaultIdentifier = uniqid();
        }
        static::$defaultIdentifier = $defaultIdentifier;
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
     * Set the maximum delay in microseconds (1,000,000 microseconds = 1 second) that should used for the random delay between retries
     *
     * The delay is random and calculated like this: rand(0, $retries * $delayPerRetry)
     *
     * Lower value = faster retries (probably more retries necessary)
     * Higher value = slower retries (probably less retries necessary)
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
     * Identifier of the current lock
     *
     * Probably the same as Lock::$defaultIdentifier if not overwritten in Lock::__construct()
     *
     * @var string|null
     */
    protected ?string $identifier = null;

    /**
     * Unique key for the resource
     *
     * @var string
     */
    protected string $key;

    /**
     * Timeout time of the lock
     *
     * The lock will be released if this timeout is reached
     *
     * @var int|null
     */
    protected ?int $time = null;

    /**
     * Is this an exclusive lock (true) or shared (false)
     *
     * @var bool
     */
    protected bool $exclusive = false;

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
     * @var string|bool
     */
    protected string|bool $previousLockString = false;

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
     * Automatically try to break the lock on destruct if possible
     *
     * @var bool
     */
    protected bool $breakOnDestruct = true;

    /**
     * Create a lock
     *
     * @param string $key Can be anything, should describe the resource in a unique way
     * @param string|null $identifier An identifier for this lock, falls back to the default identifier if null
     */
    public function __construct(string $key, ?string $identifier = null)
    {
        $this->key = $key;
        $this->etcdKey = static::$prefix . $this->key;

        if (static::$defaultIdentifier === null) {
            static::setDefaultIdentifier();
        }

        if ($identifier === null) {
            $this->identifier = static::$defaultIdentifier;
        } else {
            $this->identifier = $identifier;
        }
    }

    /**
     * Try to acquire lock
     *
     * @param bool $exclusive
     * @param int $time
     * @param int $wait
     * @return bool
     * @throws InvalidResponseStatusCodeException
     * @throws TooManySaveRetriesException
     */
    public function lock(bool $exclusive = false, int $time = 120, int $wait = 300): bool
    {
        $this->exclusive = $exclusive;
        $this->time = $time;

        $this->retries = 0;

        do {
            $this->waitForOtherLocks($wait, $exclusive);
            $retry = false;
            if ($this->canLock()) {
                $retry = !$this->addOrUpdateLock();
            }
        } while ($retry);

        return !!$this->isLocked();
    }

    /**
     * @param int $wait
     * @param bool $exclusive
     * @return bool
     * @throws InvalidResponseStatusCodeException
     */
    public function waitForOtherLocks(int $wait = 300, bool $exclusive = false): bool
    {
        $startTime = time();
        $this->exclusive = $exclusive;
        $this->update();

        while (!$this->canLock() && $startTime + $wait > time()) {
            sleep(static::$waitRetryInterval);
            $this->update();
        }

        return $this->canLock();
    }

    /**
     * Check if is locked and returns time until lock runs out or false
     *
     * @return bool|int
     */
    public function isLocked(): bool|int
    {
        foreach ($this->locks as $lock) {
            if ($lock->isBy($this->identifier)) {
                $remaining = $lock->getRemainingTime();
                return ($remaining > 0) ? $remaining : false;
            }
        }

        return false;
    }

    /**
     * Set the identifier for this lock, falls back to the default identifier if null
     *
     * @param string|null $identifier
     * @return $this
     */
    public function setIdentifier(?string $identifier): static
    {
        if ($identifier === null) {
            $this->identifier = static::$defaultIdentifier;
        } else {
            $this->identifier = $identifier;
        }
        return $this;
    }

    /**
     * Get the used identifier for this lock
     *
     * @return string|null
     */
    public function getIdentifier(): ?string
    {
        return $this->identifier;
    }

    /**
     * Dis/enable automatic lock break on object destruct
     *
     * @param bool $breakOnDestruct
     * @return $this
     */
    public function setBreakOnDestruct(bool $breakOnDestruct): static
    {
        $this->breakOnDestruct = $breakOnDestruct;
        return $this;
    }

    /**
     * Refresh the lock
     *
     * @param int $time Time until the lock should be released automatically
     * @param int $remainingThreshold The lock will only be refreshed if the remaining time is below this threshold (0 to disable)
     * @return boolean
     * @throws InvalidResponseStatusCodeException
     * @throws TooManySaveRetriesException
     */
    public function refresh(int $time = 60, int $remainingThreshold = 30): bool
    {
        if ($remainingThreshold > 0 && $this->isLocked() > $remainingThreshold) {
            return true;
        }

        $this->update();
        $this->time = $time;
        $this->retries = 0;

        do {
            if (!$this->canLock()) {
                return false;
            }

            $retry = !$this->addOrUpdateLock();
        } while ($retry);
        return true;
    }

    /**
     * Break the lock
     *
     * Should be only used if you have the lock
     *
     * @return boolean
     * @throws InvalidResponseStatusCodeException
     * @throws TooManySaveRetriesException
     */
    public function break(): bool
    {
        $this->update();
        $this->retries = 0;
        $this->removeLock();

        return true;
    }

    /**
     * Get the unique key for the resource
     * @return string
     */
    public function getKey(): string
    {
        return $this->key;
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
     * @return bool
     * @throws InvalidResponseStatusCodeException
     * @throws TooManySaveRetriesException
     */
    protected function removeLock(): bool
    {
        do {
            foreach ($this->locks as $i => $lock) {
                if ($lock->isBy($this->identifier)) {
                    unset($this->locks[$i]);
                }
            }
            $success = $this->saveLocks();
        } while ($success === false);
        return true;
    }

    /**
     * Add a lock to the locking array or update the current lock
     *
     * A 'false' return value can/should be retried, see Lock::saveLocks()
     *
     * @return bool
     * @throws InvalidResponseStatusCodeException
     * @throws TooManySaveRetriesException
     */
    protected function addOrUpdateLock(): bool
    {
        foreach ($this->locks as $lock) {
            if ($lock->isBy($this->identifier)) {
                $lock->setRemaining($this->time);
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
     * @throws InvalidResponseStatusCodeException
     * @throws TooManySaveRetriesException
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
                    $result = static::getClient()->deleteIf($this->etcdKey, $previousLocks, !$delayRetry);
                    break;
                } catch (UnavailableException | DeadlineExceededException | UnknownException $e) {
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
                    $result = static::getClient()->putIf($this->etcdKey, $lockString, $previousLocks, !$delayRetry);
                    break;
                } catch (UnavailableException | DeadlineExceededException | UnknownException $e) {
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
     * Get an Aternos\Etcd\Client instance
     *
     * @return ClientInterface
     */
    protected function getClient(): ClientInterface
    {
        if (static::$client === null) {
            static::$client = new Client();
        }

        return static::$client;
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
     * @throws InvalidResponseStatusCodeException
     * @return $this
     */
    public function update(): static
    {
        $etcdLockString = false;
        for ($i = 1; $i <= static::$maxUnavailableRetries; $i++) {
            try {
                $etcdLockString = static::getClient()->get($this->etcdKey);
                break;
            } catch (UnavailableException | DeadlineExceededException | UnknownException $e) {
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
     * @param string|bool $lockString
     * @return $this
     */
    protected function updateFromString(string|bool $lockString): static
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
     * @throws InvalidResponseStatusCodeException
     * @throws TooManySaveRetriesException
     */
    public function __destruct()
    {
        if ($this->breakOnDestruct && $this->isLocked()) {
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
