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
     * @param bool $exclusive true for exclusive lock, false for shared lock
     * @param int $time duration in seconds for which the lock should be held
     * @param int $wait duration in seconds to wait for existing locks to be released
     * @return bool true if lock was acquired, false otherwise
     */
    public function lock(bool $exclusive = false, int $time = 120, int $wait = 300): bool;

    /**
     * Check if is locked and returns time until lock runs out or false
     *
     * @return bool|int
     */
    public function isLocked(): bool|int;

    /**
     * Refresh the lock
     *
     * @param int $time
     * @param int $remainingThreshold
     * @return bool
     */
    public function refresh(int $time = 60, int $remainingThreshold = 30): bool;

    /**
     * Break the lock
     *
     * Should be only used if you have the lock
     *
     * @return bool
     */
    public function break(): bool;
}
