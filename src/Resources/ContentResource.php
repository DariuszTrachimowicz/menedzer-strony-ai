<?php

declare(strict_types=1);

namespace ASM\Resources;

use ASM\Operations\OperationFailure;

final class ContentResource
{
    private string $mode;

    public function __construct(string $mode = 'update')
    {
        $this->mode = $mode;
    }

    /** @return array<int,array<string,mixed>> */
    public function list(array $params = array()): array
    {
        $perPage = max(1, min(100, (int) ($params['per_page'] ?? 20)));
        $page = max(1, (int) ($params['page'] ?? 1));
        $query = new \WP_Query(array(
            'post_type' => $params['post_type'] ?? 'any',
            'post_status' => $params['post_status'] ?? 'any',
            'posts_per_page' => $perPage,
            'paged' => $page,
            'orderby' => 'ID',
            'order' => 'DESC',
        ));

        $items = array();
        foreach ($query->posts as $post) {
            $items[] = $this->publicPost($post);
        }
        return $items;
    }

    /** @return array<string,mixed>|\WP_Error */
    public function get(string $id)
    {
        $post = get_post((int) $id);
        if (! $post) {
            return new \WP_Error('asm_resource_not_found', 'Nie znaleziono wpisu.', array('status' => 404));
        }

        $data = $this->publicPost($post);
        $data['meta'] = $this->redactedMeta(get_post_meta((int) $post->ID));
        $data['terms'] = $this->terms((int) $post->ID, (string) $post->post_type);
        return $data;
    }

    /** @return array<string,mixed> */
    public function snapshot(array $target): array
    {
        $post = get_post((int) ($target['id'] ?? 0));
        if (! $post) {
            if ($this->mode === 'create') {
                return array('exists' => false);
            }
            throw new OperationFailure('asm_resource_not_found', 'Nie znaleziono wpisu.');
        }

        $snapshot = array(
            'exists' => true,
            'post' => get_object_vars($post),
            'meta' => get_post_meta((int) $post->ID),
            'terms' => $this->terms((int) $post->ID, (string) $post->post_type),
            'modified_gmt' => (string) $post->post_modified_gmt,
        );
        if ($this->mode === 'delete') {
            $snapshot['comments'] = $this->comments((int) $post->ID);
        }
        return $snapshot;
    }

    public function apply(array $target, array $change)
    {
        if ($this->mode === 'create') {
            $result = wp_insert_post(wp_slash($this->allowedPostFields($change)), true);
            $this->assertWordPressResult($result, 'asm_content_create_failed');
            return array('target' => array('id' => (int) $result));
        }

        $postId = (int) ($target['id'] ?? 0);
        if ($this->mode === 'delete') {
            $force = isset($change['force']) ? rest_sanitize_boolean($change['force']) : false;
            if (! wp_delete_post($postId, $force)) {
                throw new OperationFailure('asm_content_delete_failed', 'Nie udało się usunąć wpisu.');
            }
            return;
        }

        if ($this->mode === 'replace') {
            $this->applyExactReplacement($postId, $change);
            return;
        }

        if ($this->mode === 'meta') {
            $key = (string) ($change['meta_key'] ?? '');
            if ($key === '') {
                throw new OperationFailure('asm_invalid_meta_key', 'Brak klucza metadanych.');
            }
            if (! empty($change['delete'])) {
                delete_post_meta($postId, $key);
            } else {
                update_post_meta($postId, $key, $change['value'] ?? null);
            }
            return;
        }

        $fields = $this->allowedPostFields($change);
        $fields['ID'] = $postId;
        $result = wp_update_post(wp_slash($fields), true);
        $this->assertWordPressResult($result, 'asm_content_update_failed');
        return null;
    }

    public function verify(array $target, array $change): ?bool
    {
        $postId = (int) ($target['id'] ?? 0);
        $post = get_post($postId);
        if ($this->mode === 'delete') {
            return $post === null;
        }
        if ($this->mode === 'create') {
            if (! $post) {
                return false;
            }
            foreach ($this->allowedPostFields($change) as $key => $value) {
                if ((string) $post->{$key} !== (string) $value) {
                    return false;
                }
            }
            return true;
        }
        if (! $post) {
            return false;
        }
        if ($this->mode === 'meta') {
            $key = (string) ($change['meta_key'] ?? '');
            if (! empty($change['delete'])) {
                return ! metadata_exists('post', $postId, $key);
            }
            return get_post_meta($postId, $key, true) == ($change['value'] ?? null);
        }
        if ($this->mode === 'replace') {
            $field = $this->fieldName((string) ($change['field'] ?? 'post_content'));
            $value = (string) $post->{$field};
            $expected = (int) ($change['expected_occurrences'] ?? 1);
            return substr_count($value, (string) ($change['new'] ?? '')) >= $expected;
        }

        foreach ($this->allowedPostFields($change) as $key => $value) {
            if ((string) $post->{$key} !== (string) $value) {
                return false;
            }
        }
        return true;
    }

    public function rollback(array $target, array $snapshot): void
    {
        $postId = (int) ($target['id'] ?? 0);
        if (empty($snapshot['exists'])) {
            if ($postId > 0) {
                wp_delete_post($postId, true);
            }
            return;
        }

        $post = (array) $snapshot['post'];
        $post['ID'] = (int) $post['ID'];
        global $wpdb;
        if (get_post($postId) === null) {
            $columns = array_map('strval', (array) $wpdb->get_col("DESCRIBE {$wpdb->posts}", 0));
            $record = array_intersect_key($post, array_flip($columns));
            if ($wpdb->insert($wpdb->posts, $record) === false) {
                throw new OperationFailure('asm_content_rollback_failed', 'Nie udało się odtworzyć wpisu.');
            }
        } else {
            $result = wp_update_post(wp_slash($post), true);
            $this->assertWordPressResult($result, 'asm_content_rollback_failed');
        }
        $wpdb->update(
            $wpdb->posts,
            array(
                'post_modified' => (string) $post['post_modified'],
                'post_modified_gmt' => (string) $post['post_modified_gmt'],
            ),
            array('ID' => $postId),
            array('%s', '%s'),
            array('%d')
        );

        $currentMeta = get_post_meta($postId);
        foreach (array_keys($currentMeta) as $key) {
            delete_post_meta($postId, (string) $key);
        }
        foreach ((array) $snapshot['meta'] as $key => $values) {
            foreach ((array) $values as $value) {
                add_post_meta($postId, (string) $key, maybe_unserialize($value));
            }
        }
        foreach ((array) $snapshot['terms'] as $taxonomy => $termIds) {
            wp_set_object_terms($postId, array_map('intval', (array) $termIds), (string) $taxonomy, false);
        }
        foreach ((array) ($snapshot['comments'] ?? array()) as $commentSnapshot) {
            $comment = (array) ($commentSnapshot['data'] ?? array());
            $commentId = (int) ($comment['comment_ID'] ?? 0);
            if ($commentId <= 0) {
                continue;
            }
            $restoredComment = get_comment($commentId) === null;
            if ($restoredComment) {
                $columns = array_map('strval', (array) $wpdb->get_col("DESCRIBE {$wpdb->comments}", 0));
                $record = array_intersect_key($comment, array_flip($columns));
                if ($wpdb->insert($wpdb->comments, $record) === false) {
                    throw new OperationFailure('asm_content_rollback_failed', 'Nie udało się odtworzyć komentarza wpisu.');
                }
            }
            if ($restoredComment) {
                foreach ((array) ($commentSnapshot['meta'] ?? array()) as $key => $values) {
                    foreach ((array) $values as $value) {
                        add_comment_meta($commentId, (string) $key, maybe_unserialize($value));
                    }
                }
            }
            clean_comment_cache($commentId);
        }
        wp_update_comment_count_now($postId);
        clean_post_cache($postId);
    }

    /** @return array<int,array<string,mixed>> */
    private function comments(int $postId): array
    {
        $snapshots = array();
        $comments = get_comments(array(
            'post_id' => $postId,
            'status' => 'all',
            'orderby' => 'comment_ID',
            'order' => 'ASC',
        ));
        foreach ($comments as $comment) {
            $snapshots[] = array(
                'data' => get_object_vars($comment),
                'meta' => get_comment_meta((int) $comment->comment_ID),
            );
        }
        return $snapshots;
    }

    /** @param object $post
     *  @return array<string,mixed>
     */
    private function publicPost(object $post): array
    {
        return array(
            'id' => (int) $post->ID,
            'type' => (string) $post->post_type,
            'status' => (string) $post->post_status,
            'title' => (string) $post->post_title,
            'content' => (string) $post->post_content,
            'excerpt' => (string) $post->post_excerpt,
            'slug' => (string) $post->post_name,
            'author' => (int) $post->post_author,
            'date' => (string) $post->post_date,
            'date_gmt' => (string) $post->post_date_gmt,
            'modified_gmt' => (string) $post->post_modified_gmt,
        );
    }

    /** @return array<string,mixed> */
    private function allowedPostFields(array $change): array
    {
        $allowed = array(
            'post_type', 'post_status', 'post_title', 'post_content', 'post_excerpt',
            'post_name', 'post_author', 'post_parent', 'menu_order', 'comment_status',
            'ping_status', 'post_date', 'post_date_gmt',
        );
        return array_intersect_key($change, array_flip($allowed));
    }

    private function applyExactReplacement(int $postId, array $change): void
    {
        $post = get_post($postId);
        if (! $post) {
            throw new OperationFailure('asm_resource_not_found', 'Nie znaleziono wpisu.');
        }
        $field = $this->fieldName((string) ($change['field'] ?? 'post_content'));
        $content = (string) $post->{$field};
        if (! empty($change['content_hash']) && ! hash_equals((string) $change['content_hash'], hash('sha256', $content))) {
            throw new OperationFailure('asm_conflict', 'Treść zmieniła się od czasu podglądu.');
        }
        $result = ExactReplacement::apply(
            $content,
            (string) ($change['old'] ?? ''),
            (string) ($change['new'] ?? ''),
            (int) ($change['expected_occurrences'] ?? 1)
        );
        if ($result instanceof \WP_Error) {
            throw new OperationFailure($result->get_error_code(), $result->get_error_message());
        }
        $update = array('ID' => $postId, $field => $result);
        $this->assertWordPressResult(wp_update_post(wp_slash($update), true), 'asm_content_update_failed');
    }

    private function fieldName(string $field): string
    {
        $map = array(
            'title' => 'post_title',
            'content' => 'post_content',
            'excerpt' => 'post_excerpt',
            'post_title' => 'post_title',
            'post_content' => 'post_content',
            'post_excerpt' => 'post_excerpt',
        );
        if (! isset($map[$field])) {
            throw new OperationFailure('asm_invalid_content_field', 'Pole nie obsługuje dokładnej podmiany.');
        }
        return $map[$field];
    }

    /** @return array<string,array<int,int>> */
    private function terms(int $postId, string $postType): array
    {
        $result = array();
        foreach (get_object_taxonomies($postType) as $taxonomy) {
            $ids = wp_get_object_terms($postId, $taxonomy, array('fields' => 'ids'));
            if (! is_wp_error($ids)) {
                $result[(string) $taxonomy] = array_map('intval', (array) $ids);
            }
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function redactedMeta(array $meta): array
    {
        foreach ($meta as $key => $value) {
            if (preg_match('/pass|password|secret|token|key|salt|session/i', (string) $key)) {
                $meta[$key] = '[REDACTED]';
            }
        }
        return $meta;
    }

    /** @param mixed $result */
    private function assertWordPressResult($result, string $fallbackCode): void
    {
        if (is_wp_error($result)) {
            throw new OperationFailure($result->get_error_code() ?: $fallbackCode, $result->get_error_message());
        }
        if (! $result) {
            throw new OperationFailure($fallbackCode, 'Operacja WordPressa nie powiodła się.');
        }
    }
}
