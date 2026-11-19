<?php

declare(strict_types=1);

namespace ASM\Storage;

use ASM\Operations\Operation;

final class OperationStore
{
    /** @var object */
    private $database;
    private string $operationsTable;
    private string $keysTable;
    private ?PayloadCipher $cipher;

    public function __construct(object $database, ?PayloadCipher $cipher = null)
    {
        $this->database = $database;
        $prefix = isset($database->prefix) ? (string) $database->prefix : '';
        $this->operationsTable = $prefix . 'asm_operations';
        $this->keysTable = $prefix . 'asm_idempotency';
        $this->cipher = $cipher;
    }

    public function save(Operation $operation): void
    {
        $payload = json_encode(
            $operation->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | ($operation->guardedRollback() ? JSON_PRESERVE_ZERO_FRACTION : 0)
        );
        if ($this->cipher !== null) {
            $payload = $this->cipher->encrypt($payload);
        }
        $result = $this->database->replace(
            $this->operationsTable,
            array(
                'id' => $operation->id(),
                'status' => $operation->status(),
                'actor_id' => $operation->actorId(),
                'payload' => $payload,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ),
            array('%s', '%s', '%d', '%s', '%s')
        );
        if ($result === false) {
            throw new \RuntimeException('Nie udało się zapisać operacji ASM.');
        }
    }

    /** Zapis tylko wtedy, gdy w bazie operacja ma nadal status $expected (porównanie i zapis w jednym zapytaniu). */
    public function saveIfStatus(Operation $operation, string $expected): bool
    {
        $payload = json_encode(
            $operation->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | ($operation->guardedRollback() ? JSON_PRESERVE_ZERO_FRACTION : 0)
        );
        if ($this->cipher !== null) {
            $payload = $this->cipher->encrypt($payload);
        }
        $result = $this->database->update(
            $this->operationsTable,
            array('status' => $operation->status(), 'payload' => $payload, 'updated_at' => gmdate('Y-m-d H:i:s')),
            array('id' => $operation->id(), 'status' => $expected),
            array('%s', '%s', '%s'),
            array('%s', '%s')
        );
        if ($result === false) {
            throw new \RuntimeException('Nie udało się zapisać operacji ASM.');
        }
        return $result === 1;
    }

    public function get(string $operationId): ?Operation
    {
        $payload = $this->database->get_var(
            $this->database->prepare(
                "SELECT payload FROM {$this->operationsTable} WHERE id = %s",
                $operationId
            )
        );
        if (! is_string($payload) || $payload === '') {
            return null;
        }

        if ($this->cipher !== null) {
            $payload = $this->cipher->decrypt($payload);
        }
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            return null;
        }

        return Operation::fromArray($data);
    }

    public function operationIdForKey(string $key): ?string
    {
        $operationId = $this->database->get_var(
            $this->database->prepare(
                "SELECT operation_id FROM {$this->keysTable} WHERE idempotency_key = %s",
                $key
            )
        );

        return is_string($operationId) && $operationId !== '' ? $operationId : null;
    }

    public function bindKey(string $key, string $operationId): bool
    {
        if ($key === '' || strlen($key) > 191) {
            return false;
        }

        $inserted = $this->database->query(
            $this->database->prepare(
                "INSERT IGNORE INTO {$this->keysTable} (idempotency_key, operation_id, created_at) VALUES (%s, %s, %s)",
                $key,
                $operationId,
                gmdate('Y-m-d H:i:s')
            )
        );
        if ($inserted === 1) {
            return true;
        }

        return $this->operationIdForKey($key) === $operationId;
    }
}
