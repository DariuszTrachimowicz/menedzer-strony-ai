<?php

declare(strict_types=1);

namespace ASM\Resources;

use ASM\Database\QueryPolicy;
use ASM\Operations\OperationFailure;
use ASM\Storage\PrivateStorage;

final class DatabaseResource
{
    /** @var object */
    private $database;
    private QueryPolicy $policy;
    private PrivateStorage $storage;
    private ?int $lastAffected = null;
    private bool $lastRollbackSucceeded = false;

    public function __construct(object $database, QueryPolicy $policy, PrivateStorage $storage)
    {
        $this->database = $database;
        $this->policy = $policy;
        $this->storage = $storage;
    }

    public function list(array $params = array()): array
    {
        $databaseName = defined('DB_NAME') ? DB_NAME : '';
        $rows = $this->database->get_results(
            $this->database->prepare(
                'SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, TABLE_COLLATION '
                . 'FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s ORDER BY TABLE_NAME',
                $databaseName
            ),
            ARRAY_A
        );
        return array_values((array) $rows);
    }

    public function get(string $id)
    {
        if (preg_match('/^[A-Za-z0-9_$-]+$/', $id) !== 1) {
            return new \WP_Error('asm_invalid_table', 'Nieprawidłowa nazwa tabeli.', array('status' => 400));
        }
        $exists = $this->database->get_var(
            $this->database->prepare('SHOW TABLES LIKE %s', $id)
        );
        if ($exists !== $id) {
            return new \WP_Error('asm_resource_not_found', 'Nie znaleziono tabeli.', array('status' => 404));
        }
        $create = $this->database->get_row('SHOW CREATE TABLE `' . str_replace('`', '``', $id) . '`', ARRAY_A);
        $indexes = $this->database->get_results('SHOW INDEX FROM `' . str_replace('`', '``', $id) . '`', ARRAY_A);
        return array('table' => $id, 'create' => $create, 'indexes' => $indexes);
    }

    public function snapshot(array $target): array
    {
        return array('target' => $target);
    }

    public function snapshotWithChange(array $target, array $change): array
    {
        $sql = (string) ($change['sql'] ?? '');
        $allowed = $this->policy->validateMutation($sql);
        if ($allowed instanceof \WP_Error) {
            throw new OperationFailure($allowed->get_error_code(), $allowed->get_error_message());
        }

        $backupQuery = (string) ($change['backup_query'] ?? '');
        $rollbackSql = (array) ($change['rollback_sql'] ?? array());
        $nonData = preg_match('/^(OPTIMIZE|REPAIR)\s+TABLE\b/i', ltrim($sql)) === 1;
        if (! $nonData && ($backupQuery === '' || $rollbackSql === array())) {
            throw new OperationFailure(
                'asm_database_backup_required',
                'Zmiana danych wymaga backup_query oraz rollback_sql.'
            );
        }

        $rows = array();
        if ($backupQuery !== '') {
            $readAllowed = $this->policy->validateRead($backupQuery);
            if ($readAllowed instanceof \WP_Error) {
                throw new OperationFailure($readAllowed->get_error_code(), $readAllowed->get_error_message());
            }
            $rows = (array) $this->database->get_results($backupQuery, ARRAY_A);
            if (isset($change['expected_rows']) && count($rows) !== (int) $change['expected_rows']) {
                throw new OperationFailure('asm_database_row_conflict', 'Liczba rekordów nie zgadza się z oczekiwaniem.');
            }
            if (! empty($change['expected_rows_hash'])) {
                $actualHash = hash('sha256', (string) json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                if (! hash_equals((string) $change['expected_rows_hash'], $actualHash)) {
                    throw new OperationFailure('asm_database_row_conflict', 'Zbiór rekordów zmienił się od czasu podglądu.');
                }
            }
        }
        foreach ($rollbackSql as $statement) {
            $rollbackAllowed = $this->policy->validateMutation((string) $statement);
            if ($rollbackAllowed instanceof \WP_Error) {
                throw new OperationFailure($rollbackAllowed->get_error_code(), 'Niedozwolone polecenie rollback SQL.');
            }
        }

        $operationId = (string) ($target['_operation_id'] ?? 'snapshot-' . bin2hex(random_bytes(8)));
        $payload = json_encode(
            array(
                'rows' => $rows,
                'backup_query' => $backupQuery,
                'rollback_sql' => array_values($rollbackSql),
            ),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $backupPath = $this->storage->store($operationId, 'database/backup.json', $payload);
        return array(
            'backup_path' => $backupPath,
            'rows_count' => count($rows),
            'rows_hash' => hash('sha256', (string) json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'query_hash' => hash('sha256', $sql),
        );
    }

    public function apply(array $target, array $change): void
    {
        $sql = (string) ($change['sql'] ?? '');
        $allowed = $this->policy->validateMutation($sql);
        if ($allowed instanceof \WP_Error) {
            throw new OperationFailure($allowed->get_error_code(), $allowed->get_error_message());
        }
        $result = $this->database->query($sql);
        if ($result === false) {
            throw new OperationFailure('asm_database_execution_failed', 'Polecenie SQL nie zostało wykonane.');
        }
        $this->lastAffected = (int) $result;
    }

    public function verify(array $target, array $change): ?bool
    {
        if (! empty($change['verification_query'])) {
            $query = (string) $change['verification_query'];
            $allowed = $this->policy->validateRead($query);
            if ($allowed instanceof \WP_Error) {
                return false;
            }
            $rows = (array) $this->database->get_results($query, ARRAY_A);
            $hash = hash('sha256', (string) json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return ! empty($change['verification_hash'])
                ? hash_equals((string) $change['verification_hash'], $hash)
                : true;
        }
        if (isset($change['expected_affected_rows'])) {
            return $this->lastAffected === (int) $change['expected_affected_rows'];
        }
        return $this->lastAffected !== null;
    }

    public function rollback(array $target, array $snapshot): void
    {
        $this->lastRollbackSucceeded = false;
        $json = file_get_contents((string) ($snapshot['backup_path'] ?? ''));
        $payload = is_string($json) ? json_decode($json, true) : null;
        if (! is_array($payload)) {
            throw new OperationFailure('asm_database_backup_missing', 'Brakuje prywatnej kopii bazy.');
        }
        foreach ((array) ($payload['rollback_sql'] ?? array()) as $statement) {
            $allowed = $this->policy->validateMutation((string) $statement);
            if ($allowed instanceof \WP_Error || $this->database->query((string) $statement) === false) {
                throw new OperationFailure('asm_database_rollback_failed', 'Nie udało się wykonać rollbacku bazy.');
            }
        }
        $this->lastRollbackSucceeded = true;
    }

    public function verifyRollback(array $target, array $snapshot): bool
    {
        if (! $this->lastRollbackSucceeded) {
            return false;
        }
        $json = file_get_contents((string) ($snapshot['backup_path'] ?? ''));
        $payload = is_string($json) ? json_decode($json, true) : null;
        if (! is_array($payload)) {
            return false;
        }
        $query = (string) ($payload['backup_query'] ?? '');
        if ($query === '') {
            return true;
        }
        $allowed = $this->policy->validateRead($query);
        if ($allowed instanceof \WP_Error) {
            return false;
        }
        $rows = (array) $this->database->get_results($query, ARRAY_A);
        $hash = hash(
            'sha256',
            (string) json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        return hash_equals((string) ($snapshot['rows_hash'] ?? ''), $hash);
    }
}
