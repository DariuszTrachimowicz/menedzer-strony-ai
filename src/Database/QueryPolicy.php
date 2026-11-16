<?php

declare(strict_types=1);

namespace ASM\Database;

final class QueryPolicy
{
    private string $databaseName;

    public function __construct(?string $databaseName = null)
    {
        $this->databaseName = $databaseName ?? (defined('DB_NAME') ? (string) DB_NAME : '');
    }

    /** @return true|\WP_Error */
    public function validateRead(string $query)
    {
        $normalized = $this->normalize($query);
        if ($normalized instanceof \WP_Error) {
            return $normalized;
        }
        if (preg_match('/^(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $normalized) !== 1) {
            return $this->forbidden();
        }
        if ($this->referencesExternalDatabase($normalized)) {
            return $this->forbidden();
        }
        if (
            preg_match('/^SELECT\b/i', $normalized) === 1
            && preg_match('/\bLIMIT\s+\d+/i', $normalized) !== 1
            && preg_match('/\b(COUNT|SUM|AVG|MIN|MAX)\s*\(/i', $normalized) !== 1
        ) {
            return new \WP_Error(
                'asm_database_limit_required',
                'Zapytanie SELECT musi zawierać liczbowy LIMIT.',
                array('status' => 400)
            );
        }
        return true;
    }

    /** @return true|\WP_Error */
    public function validateMutation(string $query)
    {
        $normalized = $this->normalize($query);
        if ($normalized instanceof \WP_Error) {
            return $normalized;
        }
        if (preg_match('/^(INSERT|UPDATE|DELETE|REPLACE|CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE\s+TABLE|OPTIMIZE\s+TABLE|REPAIR\s+TABLE)\b/i', $normalized) !== 1) {
            return $this->forbidden();
        }
        if ($this->referencesExternalDatabase($normalized)) {
            return $this->forbidden();
        }
        return true;
    }

    private function referencesExternalDatabase(string $query): bool
    {
        if ($this->databaseName === '') {
            return false;
        }
        preg_match_all(
            '/\b(?:FROM|JOIN|UPDATE|INTO|TABLE|DESCRIBE|DESC)\s+((?:`?[A-Za-z0-9_$-]+`?\s*\.\s*)?`?[A-Za-z0-9_$-]+`?)/i',
            $query,
            $matches
        );
        foreach ((array) ($matches[1] ?? array()) as $reference) {
            $reference = str_replace(array('`', ' '), '', (string) $reference);
            if (strpos($reference, '.') === false) {
                continue;
            }
            [$database] = explode('.', $reference, 2);
            if (strcasecmp($database, $this->databaseName) !== 0) {
                return true;
            }
        }
        return false;
    }

    /** @return string|\WP_Error */
    private function normalize(string $query)
    {
        $query = preg_replace('#/\*.*?\*/#s', ' ', $query);
        $query = preg_replace('/(?:--|#)[^\r\n]*/', ' ', (string) $query);
        $query = trim((string) $query);
        $query = preg_replace('/;\s*$/', '', $query, 1);
        if ($query === '' || strpos((string) $query, ';') !== false) {
            return $this->forbidden();
        }
        $dangerous = '/\b(?:LOAD_FILE|INTO\s+(?:OUTFILE|DUMPFILE)|LOAD\s+DATA|LOCAL\s+INFILE|SLEEP|BENCHMARK|GET_LOCK|RELEASE_LOCK|USE|CREATE\s+DATABASE|DROP\s+DATABASE|ALTER\s+DATABASE)\b/i';
        if (preg_match($dangerous, (string) $query) === 1) {
            return $this->forbidden();
        }
        return (string) preg_replace('/\s+/', ' ', (string) $query);
    }

    private function forbidden(): \WP_Error
    {
        return new \WP_Error(
            'asm_database_query_forbidden',
            'Zapytanie SQL nie jest dozwolone przez politykę bezpieczeństwa ASM.',
            array('status' => 400)
        );
    }
}
