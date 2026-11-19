<?php

declare(strict_types=1);

namespace ASM;

use ASM\Migration\Migration;

final class Installer
{
    public static function activate(): void
    {
        global $wpdb;
        $freshInstall = get_option('asm_schema_version', '') === '';

        if (! is_object($wpdb)) {
            throw new \RuntimeException('Brak połączenia z bazą WordPressa.');
        }

        $upgradeFile = ABSPATH . 'wp-admin/includes/upgrade.php';
        if (is_file($upgradeFile)) {
            require_once $upgradeFile;
        }
        if (! function_exists('dbDelta')) {
            throw new \RuntimeException('Funkcja dbDelta nie jest dostępna.');
        }

        $charset = method_exists($wpdb, 'get_charset_collate')
            ? (string) $wpdb->get_charset_collate()
            : '';
        $prefix = (string) $wpdb->prefix;

        $schemas = array(
            "CREATE TABLE {$prefix}asm_operations (
                id char(36) NOT NULL,
                status varchar(32) NOT NULL,
                actor_id bigint(20) unsigned NOT NULL,
                payload longtext NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY status (status),
                KEY actor_id (actor_id)
            ) {$charset};",
            "CREATE TABLE {$prefix}asm_idempotency (
                idempotency_key varchar(191) NOT NULL,
                operation_id char(36) NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (idempotency_key),
                KEY operation_id (operation_id)
            ) {$charset};",
            "CREATE TABLE {$prefix}asm_operation_steps (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                operation_id char(36) NOT NULL,
                step_name varchar(100) NOT NULL,
                status varchar(32) NOT NULL,
                metadata longtext NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY operation_id (operation_id)
            ) {$charset};",
            "CREATE TABLE {$prefix}asm_audit (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                operation_id char(36) NOT NULL,
                event_name varchar(100) NOT NULL,
                actor_id bigint(20) unsigned NOT NULL,
                metadata longtext NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY operation_id (operation_id),
                KEY created_at (created_at)
            ) {$charset};",
            "CREATE TABLE {$prefix}asm_locks (
                resource_key varchar(191) NOT NULL,
                operation_id char(36) NOT NULL,
                expires_at bigint(20) unsigned NOT NULL,
                PRIMARY KEY  (resource_key),
                KEY operation_id (operation_id),
                KEY expires_at (expires_at)
            ) {$charset};",
        );

        foreach ($schemas as $schema) {
            dbDelta($schema);
        }

        $role = function_exists('get_role') ? get_role('administrator') : null;
        if ($freshInstall && is_object($role) && method_exists($role, 'remove_cap')) {
            $role->remove_cap('asm_full_control');
        }

        // Do 2.7.3 popup był zapisywany bez autoload, co dawało zapytanie przy każdym wejściu na stronę.
        if (function_exists('wp_set_option_autoload') && get_option('asm_popup', null) !== null) {
            wp_set_option_autoload('asm_popup', true);
        }
        if (function_exists('update_option')) {
            update_option('asm_schema_version', ASM_VERSION, false);
        }
        Migration::run();
    }
}
