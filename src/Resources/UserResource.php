<?php

declare(strict_types=1);

namespace ASM\Resources;

use ASM\Operations\OperationFailure;

final class UserResource
{
    private string $mode;

    public function __construct(string $mode = 'update')
    {
        $this->mode = $mode;
    }

    /** @return array<int,array<string,mixed>> */
    public function list(array $params = array()): array
    {
        $users = get_users(array(
            'number' => max(1, min(100, (int) ($params['per_page'] ?? 20))),
            'paged' => max(1, (int) ($params['page'] ?? 1)),
            'orderby' => 'ID',
        ));
        return array_map(array($this, 'publicUser'), $users);
    }

    /** @return array<string,mixed>|\WP_Error */
    public function get(string $id)
    {
        $user = get_userdata((int) $id);
        return $user ? $this->publicUser($user) : new \WP_Error(
            'asm_resource_not_found',
            'Nie znaleziono użytkownika.',
            array('status' => 404)
        );
    }

    /** @return array<string,mixed> */
    public function snapshot(array $target): array
    {
        $user = get_userdata((int) ($target['id'] ?? 0));
        if (! $user) {
            if ($this->mode === 'create') {
                return array('exists' => false);
            }
            throw new OperationFailure('asm_resource_not_found', 'Nie znaleziono użytkownika.');
        }
        global $wpdb;
        return array(
            'exists' => true,
            'data' => $user->to_array(),
            'roles' => array_values((array) $user->roles),
            'meta' => get_user_meta((int) $user->ID),
            'authored_posts' => array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_author = %d ORDER BY ID",
                (int) $user->ID
            ))),
            'authored_comments' => array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
                "SELECT comment_ID FROM {$wpdb->comments} WHERE user_id = %d ORDER BY comment_ID",
                (int) $user->ID
            ))),
        );
    }

    public function apply(array $target, array $change)
    {
        $userId = (int) ($target['id'] ?? 0);
        if ($this->mode === 'delete') {
            $reassign = isset($change['reassign']) ? (int) $change['reassign'] : 0;
            if ($reassign <= 0 || $reassign === $userId) {
                throw new OperationFailure(
                    'asm_user_reassign_required',
                    'Odwracalne usunięcie użytkownika wymaga innego konta do tymczasowego przypisania treści.'
                );
            }
            if (! function_exists('wp_delete_user')) {
                require_once ABSPATH . 'wp-admin/includes/user.php';
            }
            if (! wp_delete_user($userId, $reassign)) {
                throw new OperationFailure('asm_user_delete_failed', 'Nie udało się usunąć użytkownika.');
            }
            return;
        }

        $fields = array_intersect_key($change, array_flip(array(
            'user_login', 'user_email', 'user_url', 'display_name', 'nickname',
            'first_name', 'last_name', 'description', 'user_pass', 'role',
        )));
        if ($this->mode === 'create') {
            $result = wp_insert_user(wp_slash($fields));
        } else {
            $fields['ID'] = $userId;
            $result = wp_update_user(wp_slash($fields));
        }
        if (is_wp_error($result)) {
            throw new OperationFailure($result->get_error_code(), $result->get_error_message());
        }
        return $this->mode === 'create' ? array('target' => array('id' => (int) $result)) : null;
    }

    public function verify(array $target, array $change): ?bool
    {
        if ($this->mode === 'create') {
            $user = get_userdata((int) ($target['id'] ?? 0));
            return $user !== false;
        }
        $user = get_userdata((int) ($target['id'] ?? 0));
        if ($this->mode === 'delete') {
            return $user === false;
        }
        if (! $user) {
            return false;
        }
        foreach (array('user_email', 'user_url', 'display_name') as $field) {
            if (array_key_exists($field, $change) && (string) $user->{$field} !== (string) $change[$field]) {
                return false;
            }
        }
        if (isset($change['role']) && ! in_array((string) $change['role'], (array) $user->roles, true)) {
            return false;
        }
        return true;
    }

    public function rollback(array $target, array $snapshot): void
    {
        if (empty($snapshot['exists'])) {
            $userId = (int) ($target['id'] ?? 0);
            if ($userId > 0) {
                if (! function_exists('wp_delete_user')) {
                    require_once ABSPATH . 'wp-admin/includes/user.php';
                }
                wp_delete_user($userId);
            }
            return;
        }
        global $wpdb;
        $data = (array) $snapshot['data'];
        $userId = (int) ($data['ID'] ?? 0);
        $columns = array_map('strval', (array) $wpdb->get_col("DESCRIBE {$wpdb->users}", 0));
        $record = array_intersect_key($data, array_flip($columns));
        if (get_userdata($userId) === false) {
            if ($wpdb->insert($wpdb->users, $record) === false) {
                throw new OperationFailure('asm_user_rollback_failed', 'Nie udało się odtworzyć użytkownika.');
            }
        } else {
            unset($record['ID']);
            if ($wpdb->update($wpdb->users, $record, array('ID' => $userId)) === false) {
                throw new OperationFailure('asm_user_rollback_failed', 'Nie udało się przywrócić użytkownika.');
            }
        }
        $wpdb->delete($wpdb->usermeta, array('user_id' => $userId), array('%d'));
        foreach ((array) ($snapshot['meta'] ?? array()) as $key => $values) {
            foreach ((array) $values as $value) {
                add_user_meta($userId, (string) $key, maybe_unserialize($value));
            }
        }
        foreach ((array) ($snapshot['authored_posts'] ?? array()) as $postId) {
            $wpdb->update($wpdb->posts, array('post_author' => $userId), array('ID' => (int) $postId), array('%d'), array('%d'));
            clean_post_cache((int) $postId);
        }
        foreach ((array) ($snapshot['authored_comments'] ?? array()) as $commentId) {
            $wpdb->update($wpdb->comments, array('user_id' => $userId), array('comment_ID' => (int) $commentId), array('%d'), array('%d'));
            clean_comment_cache((int) $commentId);
        }
        clean_user_cache($userId);
    }

    /** @param object $user
     *  @return array<string,mixed>
     */
    public function publicUser(object $user): array
    {
        return array(
            'id' => (int) $user->ID,
            'login' => (string) $user->user_login,
            'email' => (string) $user->user_email,
            'display_name' => (string) $user->display_name,
            'roles' => array_values((array) $user->roles),
            'registered' => (string) $user->user_registered,
        );
    }
}
