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
     * Check if is locked and returns time until lock runs out or false
     *
     * @return bool|int
     */
    public function isLocked(): bool|int;

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
     * @return bool
     */
    public function break(): bool;
}
