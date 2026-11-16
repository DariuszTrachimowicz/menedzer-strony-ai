<?php

declare(strict_types=1);

namespace ASM\Storage;

final class DatabaseLockBackend
{
    /** @var object */
    private $database;
    private string $table;

    public function __construct(object $database)
    {
        $this->database = $database;
        $prefix = isset($database->prefix) ? (string) $database->prefix : '';
        $this->table = $prefix . 'asm_locks';
    }

    public function acquire(string $key, string $operationId, int $expiresAt): bool
    {
        $now = time();
        $this->database->query(
            $this->database->prepare(
                "DELETE FROM {$this->table} WHERE resource_key = %s AND expires_at < %d",
                $key,
                $now
            )
        );

        $result = $this->database->query(
            $this->database->prepare(
                "INSERT IGNORE INTO {$this->table} (resource_key, operation_id, expires_at) VALUES (%s, %s, %d)",
                $key,
                $operationId,
                $expiresAt
            )
        );

        return $result === 1;
    }

    public function release(string $key, string $operationId): bool
    {
        $result = $this->database->query(
            $this->database->prepare(
                "DELETE FROM {$this->table} WHERE resource_key = %s AND operation_id = %s",
                $key,
                $operationId
            )
        );

        return $result === 1;
    }
}
