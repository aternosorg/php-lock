<?php

namespace Aternos\Lock;

use stdClass;

/**
 * Class LockEntry
 *
 * @package Aternos\Lock
 */
class LockEntry implements \JsonSerializable
{
    /**
     * @param stdClass $lockEntry
     * @return LockEntry
     */
    public static function fromObject(stdClass $lockEntry): LockEntry
    {
        return new LockEntry(
            $lockEntry->by ?? null,
            $lockEntry->until ?? null,
            $lockEntry->exclusive ?? null
        );
    }

    /**
     * @param string $json
     * @return LockEntry[]
     */
    public static function fromJson(string $json): array
    {
        $result = [];
        $lockEntries = json_decode($json);
        if (!$lockEntries || !is_array($lockEntries)) {
            return $result;
        }

        foreach ($lockEntries as $lockEntry) {
            if (!$lockEntry instanceof stdClass) {
                continue;
            }
            $result[] = static::fromObject($lockEntry);
        }
        return $result;
    }

    /**
     * @param string|null $by
     * @param int|null $until
     * @param bool|null $exclusive
     */
    public function __construct(
        protected ?string $by = null,
        protected ?int    $until = null,
        protected ?bool   $exclusive = null
    )
    {
    }

    /**
     * @return array
     */
    public function jsonSerialize()
    {
        return [
            "by" => $this->by,
            "until" => $this->until,
            "exclusive" => $this->exclusive
        ];
    }

    /**
     * @return string|null
     */
    public function getBy(): ?string
    {
        return $this->by;
    }

    /**
     * @param string $identifier
     * @return bool
     */
    public function isBy(string $identifier): bool
    {
        return $this->getBy() === $identifier;
    }

    /**
     * @return int|null
     */
    public function getUntil(): ?int
    {
        return $this->until;
    }

    /**
     * @return int
     */
    public function getRemainingTime(): int
    {
        return (int)$this->getUntil() - time();
    }

    /**
     * @return bool
     */
    public function isExpired(): bool
    {
        return $this->until < time();
    }

    /**
     * @return bool|null
     */
    public function isExclusive(): ?bool
    {
        return $this->exclusive;
    }

    /**
     * @param int|null $until
     * @return LockEntry
     */
    public function setUntil(?int $until): LockEntry
    {
        $this->until = $until;
        return $this;
    }

    /**
     * @param int $remaining
     * @return $this
     */
    public function setRemaining(int $remaining): LockEntry
    {
        return $this->setUntil(time() + $remaining);
    }
}