<?php

namespace Aternos\Lock\Storage;

use Exception;

/**
 * Interface for a storage that can be used to perform etcd-like operations.
 */
interface StorageInterface
{
    /**
     * Put `$value` if `$key` value matches `$previousValue` and return true. If the new value does not match and
     * `$returnNewValueOnFail` is true return the new value, otherwise return false.
     * @param string $key
     * @param string $value The new value to set
     * @param string|null $previousValue The previous value to compare against. If null is provided, the comparison
     * should check that the key does not exist yet.
     * @param bool $returnNewValueOnFail if true the new value of the key should be returned if the operation fails
     * @return bool|string true if the operation succeeded, false if it failed and `$returnNewValueOnFail` is false,
     * otherwise the new value of the key
     * @throws StorageException a known, retryable error occurred
     * @throws Exception an unknown error occurred
     */
    public function putIf(string $key, string $value, ?string $previousValue, bool $returnNewValueOnFail): bool|string;

    /**
     * Delete if $key value matches $previous value otherwise $returnNewValueOnFail
     *
     * @param string $key
     * @param string|null $previousValue The previous value to compare against. If null is provided, the comparison
     *  should check that the key does not exist yet.
     * @param bool $returnNewValueOnFail
     * @return bool|string true if the operation succeeded, false if it failed and `$returnNewValueOnFail` is false,
     * otherwise the new value of the key
     * @throws StorageException a known, retryable error occurred
     * @throws Exception an unknown error occurred
     */
    public function deleteIf(string $key, ?string $previousValue, bool $returnNewValueOnFail = false): bool|string;

    /**
     * Get the value of a key
     * @param string $key
     * @return string|null the value of the key or null if the key does not exist
     * @throws StorageException a known, retryable error occurred
     * @throws Exception an unknown error occurred
     */
    public function get(string $key): ?string;
}
