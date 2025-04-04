<?php

namespace Aternos\Lock;

/**
 * Interface LockInterface
 *
 * @package Aternos\Lock
 */
interface LockInterface
{
    /**
     * LockInterface constructor.
     *
     * @param string $key
     * @param string|null $identifier
     */
    public function __construct(string $key, ?string $identifier = null);

    /**
     * Try to acquire lock
     *
     * @return bool true if lock was acquired, false otherwise
     */
    public function lock(): bool;

    /**
     * Check if is locked
     *
     * @return bool
     */
    public function isLocked(): bool;

    /**
     * * Get the time until the lock runs out. This method will return -1 if the lock is not valid or other negative values
     * * if the lock has already run out.
     *
     * @return int
     */
    public function getRemainingLockDuration(): int;

    /**
     * Refresh the lock
     *
     * @return bool
     */
    public function refresh(): bool;

    /**
     * Break the lock
     *
     * Should be only used if you have the lock
     *
     * @return void
     */
    public function break(): void;
}
