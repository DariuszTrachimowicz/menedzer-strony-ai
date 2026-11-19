<?php

declare(strict_types=1);

namespace ASM\Resources;

use ASM\Operations\OperationFailure;

final class CommentResource
{
    private string $mode;

    public function __construct(string $mode = 'update')
    {
        $this->mode = $mode;
    }

    public function list(array $params = array()): array
    {
        return array_map('get_object_vars', get_comments(array(
            'number' => max(1, min(100, (int) ($params['per_page'] ?? 20))),
            'offset' => (max(1, (int) ($params['page'] ?? 1)) - 1) * max(1, (int) ($params['per_page'] ?? 20)),
            'status' => $params['status'] ?? 'all',
        )));
    }

    /** @return array<string,mixed>|\WP_Error */
    public function get(string $id)
    {
        $comment = get_comment((int) $id);
        return $comment ? get_object_vars($comment) : new \WP_Error(
            'asm_resource_not_found',
            'Nie znaleziono komentarza.',
            array('status' => 404)
        );
    }

    public function snapshot(array $target): array
    {
        $comment = get_comment((int) ($target['id'] ?? 0));
        if (! $comment) {
            throw new OperationFailure('asm_resource_not_found', 'Nie znaleziono komentarza.');
        }
        return array('comment' => get_object_vars($comment), 'meta' => get_comment_meta((int) $comment->comment_ID));
    }

    public function apply(array $target, array $change): void
    {
        $commentId = (int) ($target['id'] ?? 0);
        if ($this->mode === 'delete') {
            if (! wp_delete_comment($commentId, ! empty($change['force']) && rest_sanitize_boolean($change['force']))) {
                throw new OperationFailure('asm_comment_delete_failed', 'Nie udało się usunąć komentarza.');
            }
            return;
        }
        $fields = array_intersect_key($change, array_flip(array(
            'comment_content', 'comment_approved', 'comment_author', 'comment_author_email',
            'comment_author_url', 'comment_parent', 'comment_post_ID',
        )));
        $fields['comment_ID'] = $commentId;
        $result = wp_update_comment(wp_slash($fields), true);
        if (is_wp_error($result) || $result === 0) {
            throw new OperationFailure('asm_comment_update_failed', 'Nie udało się zmienić komentarza.');
        }
    }

    public function verify(array $target, array $change): ?bool
    {
        $comment = get_comment((int) ($target['id'] ?? 0));
        if ($this->mode === 'delete') {
            return $comment === null;
        }
        if (! $comment) {
            return false;
        }
        foreach ($change as $key => $value) {
            if (property_exists($comment, (string) $key) && (string) $comment->{$key} !== (string) $value) {
                return false;
            }
        }
        return true;
    }

    public function rollback(array $target, array $snapshot): void
    {
        $data = (array) $snapshot['comment'];
        $data['comment_ID'] = (int) $data['comment_ID'];
        $commentId = (int) $data['comment_ID'];
        if (get_comment($commentId) === null) {
            global $wpdb;
            $columns = array_map('strval', (array) $wpdb->get_col("DESCRIBE {$wpdb->comments}", 0));
            $record = array_intersect_key($data, array_flip($columns));
            if ($wpdb->insert($wpdb->comments, $record) === false) {
                throw new OperationFailure('asm_comment_rollback_failed', 'Nie udało się odtworzyć komentarza.');
            }
        } else {
            $result = wp_update_comment(wp_slash($data), true);
            if (is_wp_error($result)) {
                throw new OperationFailure('asm_comment_rollback_failed', $result->get_error_message());
            }
        }
        foreach (array_keys(get_comment_meta($commentId)) as $key) {
            delete_comment_meta($commentId, (string) $key);
        }
        foreach ((array) ($snapshot['meta'] ?? array()) as $key => $values) {
            foreach ((array) $values as $value) {
                add_comment_meta($commentId, (string) $key, wp_slash(maybe_unserialize($value)));
            }
        }
        clean_comment_cache($commentId);
        wp_update_comment_count_now((int) ($data['comment_post_ID'] ?? 0));
    }
}
