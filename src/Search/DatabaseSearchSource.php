<?php

declare(strict_types=1);

namespace ASM\Search;

use ASM\Database\QueryPolicy;

final class DatabaseSearchSource
{
    /** @var object */
    private $database;
    private QueryPolicy $policy;

    public function __construct(object $database, QueryPolicy $policy)
    {
        $this->database = $database;
        $this->policy = $policy;
    }

    public function records(array $filters): array
    {
        $query = (string) ($filters['query'] ?? '');
        $allowed = $this->policy->validateRead($query);
        if ($allowed instanceof \WP_Error) {
            return array();
        }
        $rows = (array) $this->database->get_results($query, ARRAY_A);
        $records = array();
        foreach ($rows as $index => $row) {
            foreach ((array) $row as $column => $value) {
                $records[] = array(
                    'resource_type' => 'database_row',
                    'resource_id' => (string) $index,
                    'path' => (string) $column,
                    'content' => is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value),
                    'secret' => \ASM\Resources\SecretDetector::isSecretName((string) $column),
                );
            }
        }
        return $records;
    }
}
