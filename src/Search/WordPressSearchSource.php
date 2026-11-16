<?php

declare(strict_types=1);

namespace ASM\Search;

use ASM\Resources\SecretDetector;

final class WordPressSearchSource
{
    private string $area;

    public function __construct(string $area)
    {
        $this->area = $area;
    }

    public function records(array $filters): array
    {
        global $wpdb;
        $limit = max(1, min(1000, (int) ($filters['limit'] ?? 500)));

        if ($this->area === 'content' || $this->area === 'media') {
            $where = $this->area === 'media' ? "WHERE post_type = 'attachment'" : '';
            $rows = $wpdb->get_results(
                "SELECT ID, post_title, post_content, post_excerpt, post_name, guid FROM {$wpdb->posts} {$where} ORDER BY ID DESC LIMIT {$limit}",
                ARRAY_A
            );
            $records = array();
            foreach ((array) $rows as $row) {
                foreach (array('post_title', 'post_content', 'post_excerpt', 'post_name', 'guid') as $field) {
                    if ((string) $row[$field] !== '') {
                        $records[] = $this->record(
                            $this->area === 'media' ? 'media' : 'post',
                            (string) $row['ID'],
                            $field,
                            (string) $row[$field]
                        );
                    }
                }
            }
            return $records;
        }

        if ($this->area === 'meta') {
            $rows = $wpdb->get_results(
                "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} ORDER BY meta_id DESC LIMIT {$limit}",
                ARRAY_A
            );
            return array_map(function (array $row): array {
                return $this->record(
                    'post_meta',
                    (string) $row['post_id'],
                    (string) $row['meta_key'],
                    (string) $row['meta_value'],
                    SecretDetector::isSecretName((string) $row['meta_key'])
                );
            }, (array) $rows);
        }

        if ($this->area === 'options') {
            $rows = $wpdb->get_results(
                "SELECT option_name, option_value FROM {$wpdb->options} ORDER BY option_id ASC LIMIT {$limit}",
                ARRAY_A
            );
            return array_map(function (array $row): array {
                return $this->record(
                    'option',
                    (string) $row['option_name'],
                    'value',
                    (string) $row['option_value'],
                    SecretDetector::isSecretName((string) $row['option_name'])
                );
            }, (array) $rows);
        }

        if ($this->area === 'comments') {
            $rows = $wpdb->get_results(
                "SELECT comment_ID, comment_content, comment_author FROM {$wpdb->comments} ORDER BY comment_ID DESC LIMIT {$limit}",
                ARRAY_A
            );
            $records = array();
            foreach ((array) $rows as $row) {
                $records[] = $this->record('comment', (string) $row['comment_ID'], 'comment_content', (string) $row['comment_content']);
                $records[] = $this->record('comment', (string) $row['comment_ID'], 'comment_author', (string) $row['comment_author']);
            }
            return $records;
        }

        if ($this->area === 'terms') {
            $rows = $wpdb->get_results(
                "SELECT term_id, name, slug FROM {$wpdb->terms} ORDER BY term_id DESC LIMIT {$limit}",
                ARRAY_A
            );
            $records = array();
            foreach ((array) $rows as $row) {
                $records[] = $this->record('term', (string) $row['term_id'], 'name', (string) $row['name']);
                $records[] = $this->record('term', (string) $row['term_id'], 'slug', (string) $row['slug']);
            }
            return $records;
        }

        return array();
    }

    private function record(string $type, string $id, string $path, string $content, bool $secret = false): array
    {
        return array(
            'resource_type' => $type,
            'resource_id' => $id,
            'path' => $path,
            'content' => $content,
            'secret' => $secret,
        );
    }
}
