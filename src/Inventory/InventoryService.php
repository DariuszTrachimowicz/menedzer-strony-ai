<?php

declare(strict_types=1);

namespace ASM\Inventory;

final class InventoryService
{
    /** @return array<string,mixed> */
    public function collect(): array
    {
        global $wpdb;

        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $theme = wp_get_theme();
        $plugins = array();
        foreach (get_plugins() as $file => $data) {
            $plugins[] = array(
                'file' => $file,
                'name' => (string) ($data['Name'] ?? ''),
                'version' => (string) ($data['Version'] ?? ''),
                'active' => is_plugin_active($file),
            );
        }

        $cron = _get_cron_array();
        $cronCount = 0;
        foreach ((array) $cron as $hooks) {
            foreach ((array) $hooks as $instances) {
                $cronCount += count((array) $instances);
            }
        }

        return array(
            'wordpress' => array(
                'version' => get_bloginfo('version'),
                'url' => home_url('/'),
                'multisite' => is_multisite(),
                'debug' => defined('WP_DEBUG') && WP_DEBUG,
            ),
            'runtime' => array(
                'php' => PHP_VERSION,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => (int) ini_get('max_execution_time'),
                'opcache' => function_exists('opcache_get_status') && opcache_get_status(false) !== false,
            ),
            'theme' => array(
                'stylesheet' => $theme->get_stylesheet(),
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'parent' => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
            ),
            'plugins' => $plugins,
            'updates' => array(
                'core' => get_site_transient('update_core'),
                'plugins' => get_site_transient('update_plugins'),
                'themes' => get_site_transient('update_themes'),
            ),
            'database' => array(
                'name' => defined('DB_NAME') ? DB_NAME : null,
                'version' => method_exists($wpdb, 'db_version') ? $wpdb->db_version() : null,
                'prefix' => $wpdb->prefix,
            ),
            'cron' => array('events' => $cronCount, 'disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON),
            'cache' => array(
                'object_cache_dropin' => file_exists(WP_CONTENT_DIR . '/object-cache.php'),
                'advanced_cache_dropin' => file_exists(WP_CONTENT_DIR . '/advanced-cache.php'),
                'wp_cache' => defined('WP_CACHE') && WP_CACHE,
            ),
            'disk' => array(
                'free_bytes' => @disk_free_space(ABSPATH),
                'total_bytes' => @disk_total_space(ABSPATH),
            ),
        );
    }
}
