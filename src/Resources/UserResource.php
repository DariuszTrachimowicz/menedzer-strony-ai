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
            // Ochrona zapisana w chwili kopii: późniejsza zmiana roli nie może zdjąć ochrony przy cofaniu.
            'protected' => $user->has_cap('manage_options'),
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
        $this->guardPrivileged($userId, $change);
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

        // Zmiana hasła zmienia tylko hasło; zwykła edycja nie zmienia hasła.
        $allowed = $this->mode === 'password'
            ? array('user_pass')
            : array('user_login', 'user_email', 'user_url', 'display_name', 'nickname', 'first_name', 'last_name', 'description', 'role');
        if ($this->mode === 'create') {
            $allowed[] = 'user_pass';
        }
        $fields = array_intersect_key($change, array_flip($allowed));
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

    /** Konto chronione: administrator teraz albo w chwili kopii (zdegradowany później nie odzyska starych danych). */
    private function protectedAccount(int $userId, array $snapshot): bool
    {
        $user = get_userdata($userId);
        if (! empty($snapshot['protected']) || ($user !== false && $user->has_cap('manage_options'))) {
            return true;
        }
        // Kopie sprzed 2.8.0 nie mają pola „protected”: uprawnienia z kopii, role i uprawnienia nadane indywidualnie (np. manage_options dla subskrybenta).
        global $wpdb;
        $stored = $snapshot['meta'][$wpdb->prefix . 'capabilities'][0] ?? array();
        $caps = is_array($stored) ? $stored : (array) maybe_unserialize((string) $stored);
        foreach (array_merge((array) ($snapshot['roles'] ?? array()), array_keys(array_filter($caps))) as $name) {
            $name = (string) $name;
            $object = get_role($name);
            if ($name === 'administrator' || $name === 'manage_options' || ($object !== null && $object->has_cap('manage_options'))) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int,string> Metadane dostępu, których cofnięcie nie przywraca na koncie chronionym. */
    private function protectedMeta(): array
    {
        global $wpdb;
        return array($wpdb->prefix . 'capabilities', $wpdb->prefix . 'user_level', 'session_tokens', '_application_passwords');
    }

    /** Porównanie po cofnięciu z pominięciem danych dostępu konta chronionego (te celowo nie wracają). */
    public function verifyRollback(array $target, array $snapshot): bool
    {
        $userId = (int) ($target['id'] ?? ($snapshot['data']['ID'] ?? 0));
        if (empty($snapshot['exists'])) {
            return get_userdata($userId) === false;
        }
        clean_user_cache($userId);
        if (get_userdata($userId) === false) {
            return false;
        }
        $current = $this->snapshot(array('id' => $userId));
        $protected = $this->protectedAccount($userId, $snapshot);
        $normalize = function (array $state) use ($protected): array {
            $data = (array) ($state['data'] ?? array());
            $meta = (array) ($state['meta'] ?? array());
            if ($protected) {
                unset($data['user_pass'], $data['user_email'], $data['user_login'], $data['user_activation_key'], $state['roles']);
                $meta = array_diff_key($meta, array_flip($this->protectedMeta()));
            }
            ksort($data);
            ksort($meta);
            return array($data, $meta, $protected ? array() : (array) ($state['roles'] ?? array()), (array) ($state['authored_posts'] ?? array()), (array) ($state['authored_comments'] ?? array()));
        };
        return $normalize($current) == $normalize($snapshot);
    }

    /**
     * Konta z uprawnieniem manage_options zostają poza zasięgiem AI: przejęty token nie może założyć
     * administratora, zmienić hasła ani e-maila administratora, nadać roli administratora ani go usunąć.
     * Takie zmiany robi się w panelu WordPressa.
     */
    private function guardPrivileged(int $userId, array $change): void
    {
        $privilegedRole = static function (string $role): bool {
            $object = get_role($role);
            return $object !== null && $object->has_cap('manage_options');
        };
        // Bez jawnej roli WordPress nadaje default_role, którą da się wcześniej ustawić przez option.update.
        $role = $change['role'] ?? ($this->mode === 'create' ? (string) get_option('default_role', 'subscriber') : null);
        // Nieistniejąca „rola” (np. manage_options) trafiłaby do konta jako bezpośrednie uprawnienie.
        if ($role !== null && (string) $role !== '' && $this->mode !== 'password' && get_role((string) $role) === null) {
            throw new OperationFailure('asm_user_invalid_role', 'Nie ma takiej roli w WordPressie.');
        }
        if ($role !== null && $this->mode !== 'password' && $privilegedRole((string) $role)) {
            throw new OperationFailure('asm_user_privileged', 'Rolę administratora nadaje się w panelu WordPressa, nie przez połączenie AI.');
        }
        if ($this->mode === 'create' || $userId <= 0) {
            return;
        }
        $user = get_userdata($userId);
        if ($user === false || ! $user->has_cap('manage_options')) {
            return;
        }
        $touchesAccess = $this->mode === 'password' || $this->mode === 'delete'
            || array_key_exists('user_email', $change) || array_key_exists('user_login', $change) || array_key_exists('role', $change);
        if ($touchesAccess) {
            throw new OperationFailure('asm_user_privileged', 'Hasło, e-mail, login, rolę i usunięcie konta administratora zmienia się w panelu WordPressa.');
        }
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
            $created = $userId > 0 ? get_userdata($userId) : false;
            if ($created === false) {
                return;
            }
            // Konto założone przez AI mogło zostać awansowane albo dostać treści: wtedy usunięcie nie jest cofnięciem.
            if ($created->has_cap('manage_options')) {
                throw new OperationFailure('asm_user_privileged', 'Konto zostało w międzyczasie administratorem. Usuwa się je w panelu WordPressa.');
            }
            global $wpdb;
            $authored = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author = %d", $userId))
                + (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->comments} WHERE user_id = %d", $userId));
            if ($authored > 0) {
                throw new OperationFailure('asm_user_rollback_has_content', 'Konto ma już wpisy albo komentarze. Usuwa się je w panelu WordPressa z przypisaniem treści innej osobie.');
            }
            if (! function_exists('wp_delete_user')) {
                require_once ABSPATH . 'wp-admin/includes/user.php';
            }
            wp_delete_user($userId);
            return;
        }
        global $wpdb;
        $data = (array) $snapshot['data'];
        $userId = (int) ($data['ID'] ?? 0);
        $columns = array_map('strval', (array) $wpdb->get_col("DESCRIBE {$wpdb->users}", 0));
        $record = array_intersect_key($data, array_flip($columns));
        $current = get_userdata($userId);
        // Cofnięcie zmiany na koncie administratora nie przywraca danych dostępu: hasło, e-mail, login,
        // hasła aplikacji i uprawnienia mogły zostać zmienione później w panelu, a powrót do starych byłby przejęciem konta.
        $protected = $this->protectedAccount($userId, $snapshot);
        $protectedMeta = $this->protectedMeta();
        // Konto administratora usunięte po kopii nie wraca przez połączenie AI (ze starym hasłem i hasłami aplikacji).
        if ($current === false && $protected) {
            throw new OperationFailure('asm_user_privileged', 'Usunięte konto administratora odtwarza się ręcznie w panelu WordPressa.');
        }
        if ($current === false) {
            if ($wpdb->insert($wpdb->users, $record) === false) {
                throw new OperationFailure('asm_user_rollback_failed', 'Nie udało się odtworzyć użytkownika.');
            }
        } else {
            unset($record['ID']);
            if ($protected) {
                unset($record['user_pass'], $record['user_email'], $record['user_login'], $record['user_activation_key']);
            }
            if ($record !== array() && $wpdb->update($wpdb->users, $record, array('ID' => $userId)) === false) {
                throw new OperationFailure('asm_user_rollback_failed', 'Nie udało się przywrócić użytkownika.');
            }
        }
        foreach (array_keys((array) get_user_meta($userId)) as $key) {
            if (! $protected || ! in_array((string) $key, $protectedMeta, true)) {
                delete_user_meta($userId, (string) $key);
            }
        }
        foreach ((array) ($snapshot['meta'] ?? array()) as $key => $values) {
            if ($protected && in_array((string) $key, $protectedMeta, true)) {
                continue;
            }
            foreach ((array) $values as $value) {
                add_user_meta($userId, (string) $key, wp_slash(maybe_unserialize($value)));
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
