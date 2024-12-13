<?php

namespace Aternos\Lock;

use Aternos\Etcd\Exception\Status\InvalidResponseStatusCodeException;

/**
 * Class EtcdLock
 *
 * @package Aternos\Lock
 */
class EtcdLock extends Lock implements LockInterface
{
    /**
     * Create a lock and also try to acquire lock
     *
     * This is a shorthand version of (new Lock($key))->lock($exclusive, $time, $wait, $identifier)
     * It mainly exists for backwards compatibility reasons.
     *
     * @param string $key Can be anything, should describe the resource in a unique way
     * @param bool $exclusive Should the lock be exclusive (true) or shared (false)
     * @param int $time Time until the lock should be released automatically
     * @param int $wait Time to wait for an existing lock to get released
     * @param string|null $identifier An identifier (if different from Lock::$defaultIdentifier, see Lock::setDefaultIdentifier())
     * @throws InvalidResponseStatusCodeException
     * @throws TooManySaveRetriesException
     */
    public function __construct(string $key, bool $exclusive = false, int $time = 120, int $wait = 300, ?string $identifier = null)
    {
        parent::__construct($key);
        $this->lock($exclusive, $time, $wait, $identifier);
    }
}