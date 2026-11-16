<?php

declare(strict_types=1);

namespace ASM\Resources;

use ASM\Operations\OperationFailure;

final class OptionResource
{
    private string $mode;

    public function __construct(string $mode = 'update')
    {
        $this->mode = $mode;
    }

    /** @return array<int,array<string,mixed>> */
    public function list(array $params = array()): array
    {
        global $wpdb;
        $limit = max(1, min(200, (int) ($params['per_page'] ?? 50)));
        $offset = (max(1, (int) ($params['page'] ?? 1)) - 1) * $limit;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value, autoload FROM {$wpdb->options} ORDER BY option_id ASC LIMIT %d OFFSET %d",
                $limit,
                $offset
            ),
            ARRAY_A
        );

        $items = array();
        foreach ((array) $rows as $row) {
            $name = (string) $row['option_name'];
            $value = maybe_unserialize($row['option_value']);
            $items[] = array(
                'id' => $name,
                'value' => SecretDetector::publicValue($name, $value),
                'redacted' => SecretDetector::isSecretName($name),
                'autoload' => (string) $row['autoload'],
            );
        }
        return $items;
    }

    /** @return array<string,mixed>|\WP_Error */
    public function get(string $id)
    {
        $snapshot = $this->snapshot(array('id' => $id));
        if (! $snapshot['exists']) {
            return new \WP_Error('asm_resource_not_found', 'Nie znaleziono opcji.', array('status' => 404));
        }
        return array(
            'id' => $id,
            'value' => SecretDetector::publicValue($id, $snapshot['value']),
            'redacted' => SecretDetector::isSecretName($id),
            'autoload' => $snapshot['autoload'],
        );
    }

    /** @return array<string,mixed> */
    public function snapshot(array $target): array
    {
        global $wpdb;
        $name = (string) ($target['id'] ?? '');
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s",
                $name
            ),
            ARRAY_A
        );
        if (! is_array($row)) {
            return array('exists' => false, 'value' => null, 'autoload' => 'no');
        }
        return array(
            'exists' => true,
            'value' => maybe_unserialize($row['option_value']),
            'autoload' => (string) $row['autoload'],
        );
    }

    public function apply(array $target, array $change): void
    {
        $name = (string) ($target['id'] ?? '');
        if ($name === '') {
            throw new OperationFailure('asm_invalid_option', 'Brak nazwy opcji.');
        }
        if ($this->mode === 'delete') {
            delete_option($name);
            return;
        }
        $autoload = isset($change['autoload']) ? rest_sanitize_boolean($change['autoload']) : null;
        $result = $autoload === null
            ? update_option($name, $change['value'] ?? null)
            : update_option($name, $change['value'] ?? null, $autoload);
        if (! $result && get_option($name, new \stdClass()) != ($change['value'] ?? null)) {
            throw new OperationFailure('asm_option_update_failed', 'Nie udało się zmienić opcji.');
        }
    }

    public function verify(array $target, array $change): ?bool
    {
        $snapshot = $this->snapshot($target);
        if ($this->mode === 'delete') {
            return ! $snapshot['exists'];
        }
        return $snapshot['exists'] && $snapshot['value'] == ($change['value'] ?? null);
    }

    public function rollback(array $target, array $snapshot): void
    {
        $name = (string) ($target['id'] ?? '');
        if (empty($snapshot['exists'])) {
            delete_option($name);
            return;
        }
        update_option($name, $snapshot['value'], $snapshot['autoload'] === 'yes');
    }
}
