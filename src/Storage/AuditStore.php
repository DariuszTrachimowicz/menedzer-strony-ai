<?php

declare(strict_types=1);

namespace ASM\Storage;

final class AuditStore
{
    /** @var object */
    private $database;
    private string $table;

    public function __construct(object $database)
    {
        $this->database = $database;
        $prefix = isset($database->prefix) ? (string) $database->prefix : '';
        $this->table = $prefix . 'asm_audit';
    }

    /** @param array<string,mixed> $metadata */
    public function append(string $operationId, string $event, int $actorId, array $metadata = array()): void
    {
        $payload = json_encode(
            self::redact($metadata),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $result = $this->database->insert(
            $this->table,
            array(
                'operation_id' => $operationId,
                'event_name' => $event,
                'actor_id' => $actorId,
                'metadata' => $payload,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ),
            array('%s', '%s', '%d', '%s', '%s')
        );
        if ($result === false) {
            throw new \RuntimeException('Nie udało się zapisać zdarzenia audytu ASM.');
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function recent(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $rows = $this->database->get_results(
            "SELECT id, operation_id, event_name, actor_id, metadata, created_at FROM {$this->table} ORDER BY id DESC LIMIT {$limit}",
            defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A'
        );
        $events = array();
        foreach ((array) $rows as $row) {
            $metadata = json_decode((string) ($row['metadata'] ?? ''), true);
            $row['metadata'] = is_array($metadata) ? $metadata : array();
            $events[] = $row;
        }
        return $events;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    public static function redact($value, string $key = '')
    {
        $normalizedKey = strtolower($key);
        $secretKeys = array(
            'password', 'pass', 'token', 'secret', 'authorization', 'cookie',
            'api_key', 'apikey', 'application_password', 'nonce',
        );
        if (
            in_array($normalizedKey, $secretKeys, true)
            || preg_match('/(?:pass(?:word)?|secret|token|api[_-]?key|auth[_-]?key|salt|cookie|session)/i', $normalizedKey) === 1
        ) {
            return '[REDACTED]';
        }

        if (is_array($value)) {
            $redacted = array();
            foreach ($value as $childKey => $childValue) {
                $redacted[$childKey] = self::redact($childValue, (string) $childKey);
            }
            return $redacted;
        }

        if (is_string($value) && strlen($value) > 512) {
            return array(
                'length' => strlen($value),
                'sha256' => hash('sha256', $value),
            );
        }

        return $value;
    }
}
