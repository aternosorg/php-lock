<?php

namespace Aternos\Lock\Storage;

/**
 * A simple in-memory storage implementation for testing purposes.
 * This is not thread-safe and should not be used in production, but can be very useful for unit testing.
 */
class SimpleInMemoryStorage implements StorageInterface
{
    protected array $storage = [];

    public function putIf(string $key, string $value, ?string $previousValue, bool $returnNewValueOnFail = false): bool|string|null
    {
        $realValue = $this->get($key);
        if ($realValue === $previousValue) {
            $this->storage[$key] = $value;
            return true;
        } elseif ($returnNewValueOnFail) {
            return $realValue;
        } else {
            return false;
        }
    }

    public function deleteIf(string $key, ?string $previousValue, bool $returnNewValueOnFail = false): bool|string|null
    {
        $realValue = $this->get($key);
        if ($realValue === $previousValue) {
            unset($this->storage[$key]);
            return true;
        } elseif ($returnNewValueOnFail) {
            return $realValue;
        } else {
            return false;
        }
    }

    public function get(string $key): ?string
    {
        return $this->storage[$key] ?? null;
    }
}
