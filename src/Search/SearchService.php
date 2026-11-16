<?php

declare(strict_types=1);

namespace ASM\Search;

final class SearchService
{
    /** @var array<string,object> */
    private array $sources;

    /** @param array<string,object> $sources */
    public function __construct(array $sources)
    {
        $this->sources = $sources;
    }

    /**
     * @param array<int,string> $areas
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function search(array $areas, string $needle, array $filters): array
    {
        if ($needle === '') {
            return array();
        }

        $results = array();
        foreach ($areas as $area) {
            if (! isset($this->sources[$area])) {
                continue;
            }
            $records = $this->sources[$area]->records($filters);
            foreach ($records as $record) {
                $content = isset($record['content']) ? (string) $record['content'] : '';
                $occurrences = $this->occurrences($content, $needle);
                if ($occurrences === 0) {
                    continue;
                }

                $secret = ! empty($record['secret']);
                $results[] = array(
                    'resource_type' => (string) ($record['resource_type'] ?? $area),
                    'resource_id' => (string) ($record['resource_id'] ?? ''),
                    'path' => (string) ($record['path'] ?? ''),
                    'context' => $secret ? '[REDACTED]' : $this->context($content, $needle),
                    'occurrences' => $occurrences,
                    'content_hash' => hash('sha256', $content),
                    'redacted' => $secret,
                );
            }
        }

        return $results;
    }

    private function occurrences(string $content, string $needle): int
    {
        $matched = preg_match_all('/' . preg_quote($needle, '/') . '/iu', $content, $matches);
        return is_int($matched) ? $matched : 0;
    }

    private function context(string $content, string $needle): string
    {
        $position = stripos($content, $needle);
        if ($position === false) {
            return '';
        }

        $start = max(0, $position - 80);
        $length = strlen($needle) + 160;
        $context = substr($content, $start, $length);
        return ($start > 0 ? '…' : '') . $context
            . (($start + $length) < strlen($content) ? '…' : '');
    }
}
