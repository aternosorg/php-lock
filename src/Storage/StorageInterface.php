<?php

namespace Aternos\Lock\Storage;

use Exception;

/**
 * Interface for a storage that can be used to perform etcd-like operations.
 */
interface StorageInterface
{
    /**
     * Put `$value` if `$key` value matches `$previousValue` otherwise `$returnNewValueOnFail`
     * @param string $key
     * @param string $value The new value to set
     * @param bool|string $previousValue The previous value to compare against
     * @param bool $returnNewValueOnFail
     * @return bool|string
     * @throws StorageException a known, retryable error occurred
     * @throws Exception an unknown error occurred
     */
    public function putIf(string $key, string $value, bool|string $previousValue, bool $returnNewValueOnFail): bool|string;

    /**
     * Delete if $key value matches $previous value otherwise $returnNewValueOnFail
     *
     * @param string $key
     * @param bool|string $previousValue The previous value to compare against
     * @param bool $returnNewValueOnFail
     * @return bool|string
     * @throws StorageException a known, retryable error occurred
     * @throws Exception an unknown error occurred
     */
    public function deleteIf(string $key, bool|string $previousValue, bool $returnNewValueOnFail = false): bool|string;

    /**
     * Get the value of a key
     * @param string $key
     * @return bool|string
     * @throws StorageException a known, retryable error occurred
     * @throws Exception an unknown error occurred
     */
    public function get(string $key): bool|string;
}
