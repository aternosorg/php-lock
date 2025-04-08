<?php

namespace Aternos\Lock\Storage;

use Aternos\Etcd\Client;
use Aternos\Etcd\ClientInterface;
use Aternos\Etcd\Exception\Status\DeadlineExceededException;
use Aternos\Etcd\Exception\Status\UnavailableException;
use Aternos\Etcd\Exception\Status\UnknownException;

class EtcdStorage implements StorageInterface
{
    protected ClientInterface $client;

    public function __construct(?ClientInterface $client = null)
    {
        $this->client = $client ?? new Client();
    }

    public function putIf(string $key, string $value, ?string $previousValue, bool $returnNewValueOnFail): bool|string
    {
        try {
            return $this->client->putIf($key, $value, $previousValue ?? false, $returnNewValueOnFail);
        } catch (UnavailableException | DeadlineExceededException | UnknownException $e) {
            throw new StorageException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function deleteIf(string $key, ?string $previousValue, bool $returnNewValueOnFail = false): bool|string
    {
        try {
            return $this->client->deleteIf($key, $previousValue ?? false, $returnNewValueOnFail);
        } catch (UnavailableException | DeadlineExceededException | UnknownException $e) {
            throw new StorageException($e->getMessage(), $e->getCode(), $e);
        }
    }


    public function get(string $key): ?string
    {
        try {
            $value = $this->client->get($key);
        } catch (UnavailableException | DeadlineExceededException | UnknownException $e) {
            throw new StorageException($e->getMessage(), $e->getCode(), $e);
        }

        if ($value === false) {
            return null;
        }

        return $value;
    }
}
