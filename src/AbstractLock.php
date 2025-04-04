<?php

namespace Aternos\Lock;

abstract class AbstractLock implements LockInterface
{
    /**
     * @see AbstractLock::setDefaultIdentifier()
     * @var string|null
     */
    protected static ?string $defaultIdentifier = null;

    /**
     * Get the default identifier
     *
     * This is the default identifier for all new locks and therefore the same within a synchronous process/request.
     * It should be random enough to never be the same in two different processes. If the default identifier has not
     * been accessed yet, one will be generated using {@link uniqid()}.
     *
     * @return string $defaultIdentifier
     */
    public static function getDefaultIdentifier(): string
    {
        return self::$defaultIdentifier ??= uniqid();
    }

    /**
     * Set the default identifier
     *
     * This is the default identifier for all new locks and therefore the same within a synchronous process/request.
     * It should be random enough to never be the same in two different processes. Can be created with {@link uniqid()}.
     * Will fall back to {@link uniqid()}.
     *
     * Can be set individually on every lock if necessary (see {@link AbstractLock::__construct}).
     *
     * @param string|null $defaultIdentifier
     */
    public static function setDefaultIdentifier(?string $defaultIdentifier = null): void
    {
        static::$defaultIdentifier = $defaultIdentifier;
    }

    /**
     * Identifier of the current lock
     *
     * Probably the same as {@link static::$defaultIdentifier} if not overwritten in {@link static::__construct()}
     *
     * @var string
     */
    protected string $identifier;

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
        protected string $key,
        ?string $identifier = null,
        protected bool $exclusive = false,
        protected int $time = 120,
        protected int $waitTime = 300,
        protected ?int $refreshTime = null,
        protected int $refreshThreshold = 30,
        protected bool $breakOnDestruct = true,
    )
    {
        $this->identifier = $identifier ?? static::getDefaultIdentifier();
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
     * Get the used identifier for this lock
     *
     * @return string|null
     */
    public function getIdentifier(): ?string
    {
        return $this->identifier;
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
     * If true the lock will be broken automatically on destruct
     * @return bool
     */
    public function shouldBreakOnDestruct(): bool
    {
        return $this->breakOnDestruct;
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
     * Get the timeout time of the lock in seconds. The lock will be released if this timeout is reached.
     * @return int
     */
    public function getTime(): int
    {
        return $this->time;
    }

    /**
     * Set the timeout time of the lock. The lock will be released if this timeout is reached.
     * @param int $time time in seconds
     * @return $this
     */
    public function setTime(int $time): static
    {
        $this->time = $time;
        return $this;
    }

    /**
     * Is this lock exclusive (true) or shared (false)
     * @return bool
     */
    public function isExclusive(): bool
    {
        return $this->exclusive;
    }

    /**
     * Make this lock exclusive (true) or shared (false)
     * @param bool $exclusive
     * @return $this
     */
    public function setExclusive(bool $exclusive): static
    {
        $this->exclusive = $exclusive;
        return $this;
    }

    /**
     * Get the wait time in seconds to wait for existing locks to be released.
     * @return int
     */
    public function getWaitTime(): int
    {
        return $this->waitTime;
    }

    /**
     * Set the wait time in seconds to wait for existing locks to be released.
     * @param int $waitTime
     * @return $this
     */
    public function setWaitTime(int $waitTime): static
    {
        $this->waitTime = $waitTime;
        return $this;
    }

    /**
     * Duration in seconds the timeout should be set to when refreshing the lock.
     * If null the initial timeout will be used.
     * @return int|null
     */
    public function getRefreshTime(): ?int
    {
        return $this->refreshTime;
    }

    /**
     * Duration in seconds the timeout should be set to when refreshing the lock.
     * If null the initial timeout will be used.
     * @param int|null $refreshTime
     * @return $this
     */
    public function setRefreshTime(?int $refreshTime): static
    {
        $this->refreshTime = $refreshTime;
        return $this;
    }

    /**
     * Maximum duration in seconds the existing lock may be valid for to be refreshed. If the lock is valid for longer
     * than this time, the lock will not be refreshed.
     * @return int
     */
    public function getRefreshThreshold(): int
    {
        return $this->refreshThreshold;
    }

    /**
     * Maximum duration in seconds the existing lock may be valid for to be refreshed. If the lock is valid for longer
     * than this time, the lock will not be refreshed.
     * @param int $refreshThreshold
     * @return $this
     */
    public function setRefreshThreshold(int $refreshThreshold): static
    {
        $this->refreshThreshold = $refreshThreshold;
        return $this;
    }

    /**
     * Check if is locked and returns time until lock runs out or false
     *
     * @return bool
     */
    public function isLocked(): bool
    {
        return $this->getRemainingLockDuration() > 0;
    }
}
