<?php

namespace Aternos\Lock;

use Aternos\Etcd\Client;
use Aternos\Etcd\ClientInterface;
use Aternos\Etcd\Exception\Status\DeadlineExceededException;
use Aternos\Etcd\Exception\Status\InvalidResponseStatusCodeException;
use Aternos\Etcd\Exception\Status\UnavailableException;
use Aternos\Etcd\Exception\Status\UnknownException;
use stdClass;

/**
 * Class EtcdLock
 *
 * @package Aternos\Lock
 */
class Lock
{
    /**
     * see EtcdLock::setClient()
     *
     * @var ClientInterface|null
     */
    protected static ?ClientInterface $client = null;

    /**
     * see EtcdLock::setPrefix()
     *
     * @var string
     */
    protected static string $prefix = "lock/";

    /**
     * see EtcdLock::setDefaultIdentifier()
     *
     * @var string|null
     */
    protected static ?string $defaultIdentifier = null;

    /**
     * see EtcdLock::setWaitRetryInterval()
     *
     * @var int
     */
    protected static int $waitRetryInterval = 1;

    /**
     * see EtcdLock::setMaxSaveRetries()
     *
     * @var int
     */
    protected static int $maxSaveRetries = 100;

    /**
     * see EtcdLock::setMaxDelayPerSaveRetry()
     *
     * @var int
     */
    protected static int $maxDelayPerSaveRetry = 1000;

    /**
     * see EtcdLock::setMaxUnavailableRetries()
     *
     * @var int
     */
    protected static int $maxUnavailableRetries = 3;

    /**
     * see EtcdLock::setDelayPerUnavailableRetry()
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
     * Can be set individually on every lock if necessary (see EtcdLock::__construct).
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
     * Probably the same as EtcdLock::$defaultIdentifier if not overwritten in EtcdLock::__construct()
     *
     * @var string|null
     */
    protected ?string $identifier = null;

    /**
     * Unique key for the resource
     *
     * @var string|null
     */
    protected ?string $key = null;

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
     * @var array
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
     */
    public function __construct(string $key)
    {
        $this->key = $key;
        $this->etcdKey = static::$prefix . $this->key;
    }

    /**
     * Try to acquire lock
     *
     * @param bool $exclusive
     * @param int $time
     * @param int $wait
     * @param string|null $identifier
     * @return bool
     * @throws InvalidResponseStatusCodeException
     * @throws TooManySaveRetriesException
     */
    public function lock(bool $exclusive = false, int $time = 120, int $wait = 300, string $identifier = null): bool
    {
        $startTime = time();
        $this->exclusive = $exclusive;
        $this->time = $time;

        if (static::$defaultIdentifier === null) {
            static::setDefaultIdentifier();
        }
        if ($identifier === null) {
            $this->identifier = static::$defaultIdentifier;
        } else {
            $this->identifier = $identifier;
        }

        $this->update();
        $this->retries = 0;

        do {
            while (!$this->canLock() && $startTime + $wait > time()) {
                sleep(static::$waitRetryInterval);
                $this->update();
            }

            $retry = false;
            if ($this->canLock()) {
                $retry = !$this->addOrUpdateLock();
            }
        } while ($retry);

        return !!$this->isLocked();
    }

    /**
     * Check if is locked and returns time until lock runs out or false
     *
     * @return bool|int
     */
    public function isLocked(): bool|int
    {
        foreach ($this->locks as $i => $lock) {
            if ($lock->by === $this->identifier) {
                $remaining = $this->locks[$i]->until - time();
                return ($remaining > 0) ? $remaining : false;
            }
        }

        return false;
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
     */
    public function setBreakOnDestruct(bool $breakOnDestruct): void
    {
        $this->breakOnDestruct = $breakOnDestruct;
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
     * Generate the lock object
     *
     * @return stdClass
     */
    protected function generateLock(): stdClass
    {
        $lock = new stdClass();
        $lock->by = $this->identifier;
        $lock->until = time() + $this->time;
        $lock->exclusive = $this->exclusive;

        return $lock;
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
                if ($lock->by === $this->identifier) {
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
     * A 'false' return value can/should be retried, see EtcdLock::saveLocks()
     *
     * @return bool
     * @throws InvalidResponseStatusCodeException
     * @throws TooManySaveRetriesException
     */
    protected function addOrUpdateLock(): bool
    {
        foreach ($this->locks as $i => $lock) {
            if ($lock->by === $this->identifier) {
                $this->locks[$i]->until = time() + $this->time;
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
            if ($lock->until < time()) {
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
            if ($lock->by !== $this->identifier && $lock->exclusive && $lock->until >= time()) {
                return false;
            }

            if ($lock->by !== $this->identifier && $this->exclusive && $lock->until >= time()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Update the locks array from etcd
     *
     * @throws InvalidResponseStatusCodeException
     */
    public function update(): void
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

        $this->updateFromString($etcdLockString);
    }

    /**
     * Update the locks array from a JSON string
     *
     * @param string|bool $lockString
     */
    protected function updateFromString(string|bool $lockString): void
    {
        $this->previousLockString = $lockString;

        if ($lockString) {
            $this->locks = json_decode($lockString);
        } else {
            $this->locks = [];
        }
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
}