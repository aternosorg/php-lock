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
     * @param bool $exclusive
     * @param int $time
     * @param int $wait
     * @param string|null $identifier
     */
    public function __construct(string $key, bool $exclusive = false, int $time = 60, int $wait = 300, ?string $identifier = null);

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