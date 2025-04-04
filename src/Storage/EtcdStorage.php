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

    public function putIf(string $key, string $value, bool|string $previousValue, bool $returnNewValueOnFail): bool|string
    {
        try {
            return $this->client->putIf($key, $value, $previousValue, $returnNewValueOnFail);
        } catch (UnavailableException | DeadlineExceededException | UnknownException $e) {
            throw new StorageException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function deleteIf(string $key, $previousValue, bool $returnNewValueOnFail = false): bool|string
    {
        try {
            return $this->client->deleteIf($key, $previousValue, $returnNewValueOnFail);
        } catch (UnavailableException | DeadlineExceededException | UnknownException $e) {
            throw new StorageException($e->getMessage(), $e->getCode(), $e);
        }
    }


    public function get(string $key): bool|string
    {
        try {
            return $this->client->get($key);
        } catch (UnavailableException | DeadlineExceededException | UnknownException $e) {
            throw new StorageException($e->getMessage(), $e->getCode(), $e);
        }
    }
}
