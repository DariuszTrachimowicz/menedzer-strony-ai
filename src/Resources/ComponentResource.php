<?php

declare(strict_types=1);

namespace ASM\Resources;

use ASM\Components\DirectorySnapshot;
use ASM\Operations\OperationFailure;
use ASM\Storage\PrivateStorage;

final class ComponentResource
{
    private const OWN_PLUGINS = array('digital-xperts-popup' => 'DariuszTrachimowicz/digital-xperts-popup');
    private string $type;
    private string $action;
    private PrivateStorage $storage;

    public function __construct(string $type, string $action, PrivateStorage $storage)
    {
        $this->type = $type;
        $this->action = $action;
        $this->storage = $storage;
    }

    public function list(array $params = array()): array
    {
        if ($this->type === 'plugin') {
            if (! function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $items = array();
            foreach (get_plugins() as $file => $data) {
                $items[] = array(
                    'id' => $file,
                    'name' => (string) ($data['Name'] ?? ''),
                    'version' => (string) ($data['Version'] ?? ''),
                    'active' => is_plugin_active($file),
                    'network_active' => is_plugin_active_for_network($file),
                    'update' => $this->pluginUpdate($file),
                );
            }
            return $items;
        }

        $items = array();
        $active = get_stylesheet();
        foreach (wp_get_themes() as $slug => $theme) {
            $items[] = array(
                'id' => $slug,
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'active' => $slug === $active,
                'parent' => $theme->get_template() !== $slug ? $theme->get_template() : null,
            );
        }
        return $items;
    }

    public function get(string $id)
    {
        foreach ($this->list() as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }
        return new \WP_Error('asm_resource_not_found', 'Nie znaleziono komponentu.', array('status' => 404));
    }

    public function snapshot(array $target): array
    {
        $id = (string) ($target['id'] ?? '');
        if ($this->action === 'install') {
            return array('exists' => false, 'id' => $id);
        }
        $path = $this->componentPath($id);
        if (! file_exists($path)) {
            throw new OperationFailure('asm_resource_not_found', 'Nie znaleziono plików komponentu.');
        }
        $singleFile = ! is_dir($path);
        $snapshot = ! $singleFile
            ? DirectorySnapshot::capture($path)
            : array('files' => array(basename($path) => $this->singleFile($path)), 'total_bytes' => filesize($path));
        $operationId = (string) ($target['_operation_id'] ?? 'snapshot-' . bin2hex(random_bytes(8)));
        $backup = $this->storage->store(
            $operationId,
            'components/' . $this->type . '-' . hash('sha256', $id) . '.json',
            (string) json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
        $current = $this->get($id);
        return array(
            'exists' => true,
            'id' => $id,
            'path' => $path,
            'backup_path' => $backup,
            'single_file' => $singleFile,
            'state' => $current instanceof \WP_Error ? array() : $current,
        );
    }

    public function apply(array $target, array $change): void
    {
        $id = (string) ($target['id'] ?? $change['slug'] ?? '');
        $this->loadAdministrationApi();
        if ($this->type === 'plugin') {
            $this->applyPlugin($id, $change);
            return;
        }
        $this->applyTheme($id, $change);
    }

    public function verify(array $target, array $change): ?bool
    {
        $id = (string) ($target['id'] ?? $change['slug'] ?? '');
        if ($this->action === 'install') {
            return $this->get($id) instanceof \WP_Error ? null : true;
        }
        $item = $this->get($id);
        if ($this->action === 'delete') {
            return $item instanceof \WP_Error;
        }
        if ($item instanceof \WP_Error) {
            return false;
        }
        if ($this->action === 'activate') {
            return ! empty($item['active']);
        }
        if ($this->action === 'deactivate') {
            return empty($item['active']);
        }
        if ($this->action === 'update' && ! empty($change['version'])) {
            return version_compare((string) $item['version'], (string) $change['version'], '>=');
        }
        return true;
    }

    public function rollback(array $target, array $snapshot): void
    {
        if (empty($snapshot['exists'])) {
            $id = (string) ($target['id'] ?? '');
            $this->loadAdministrationApi();
            if ($id !== '' && ! ($this->get($id) instanceof \WP_Error)) {
                $result = $this->type === 'plugin' ? delete_plugins(array($id)) : delete_theme($id);
                if (is_wp_error($result) || ! $result) {
                    throw new OperationFailure('asm_component_rollback_failed', 'Nie udało się usunąć zainstalowanego komponentu.');
                }
            }
            return;
        }
        $json = file_get_contents((string) $snapshot['backup_path']);
        $data = is_string($json) ? json_decode($json, true) : null;
        if (! is_array($data)) {
            throw new OperationFailure('asm_component_backup_missing', 'Brakuje kopii komponentu.');
        }
        $path = (string) $snapshot['path'];
        if (! empty($snapshot['single_file'])) {
            $file = reset($data['files']);
            $bytes = is_array($file) ? base64_decode((string) ($file['bytes'] ?? ''), true) : false;
            if (! is_string($bytes) || ! hash_equals((string) ($file['sha256'] ?? ''), hash('sha256', $bytes))) {
                throw new OperationFailure('asm_component_backup_invalid', 'Kopia pojedynczego pliku jest uszkodzona.');
            }
            file_put_contents($path, $bytes, LOCK_EX);
            chmod($path, (int) ($file['mode'] ?? 0644));
        } else {
            DirectorySnapshot::restore($path, $data);
        }
        if ($this->type === 'plugin') {
            $wasActive = ! empty($snapshot['state']['active']);
            $isActive = is_plugin_active((string) $snapshot['id']);
            if ($wasActive && ! $isActive) {
                activate_plugin((string) $snapshot['id']);
            } elseif (! $wasActive && $isActive) {
                deactivate_plugins((string) $snapshot['id'], true);
            }
        } elseif (! empty($snapshot['state']['active'])) {
            switch_theme((string) $snapshot['id']);
        }
    }

    public function verifyRollback(array $target, array $snapshot): bool
    {
        $id = (string) ($target['id'] ?? $snapshot['id'] ?? '');
        if (empty($snapshot['exists'])) {
            return $this->get($id) instanceof \WP_Error;
        }
        $json = file_get_contents((string) ($snapshot['backup_path'] ?? ''));
        $expected = is_string($json) ? json_decode($json, true) : null;
        $path = (string) ($snapshot['path'] ?? '');
        if (! is_array($expected) || ! file_exists($path)) {
            return false;
        }
        try {
            $actual = ! empty($snapshot['single_file'])
                ? array('files' => array(basename($path) => $this->singleFile($path)), 'total_bytes' => filesize($path))
                : DirectorySnapshot::capture($path);
        } catch (\Throwable $error) {
            return false;
        }
        if (! hash_equals($this->snapshotDigest($expected), $this->snapshotDigest($actual))) {
            return false;
        }
        $current = $this->get($id);
        return ! ($current instanceof \WP_Error)
            && (bool) ($current['active'] ?? false) === (bool) ($snapshot['state']['active'] ?? false);
    }

    private function applyPlugin(string $id, array $change): void
    {
        if ($this->action === 'activate') {
            $result = activate_plugin($id, '', ! empty($change['network']));
            if (is_wp_error($result)) {
                throw new OperationFailure($result->get_error_code(), $result->get_error_message());
            }
            return;
        }
        if ($this->action === 'deactivate') {
            deactivate_plugins($id, false, ! empty($change['network']));
            return;
        }
        if ($this->action === 'delete') {
            $result = delete_plugins(array($id));
            if (is_wp_error($result) || ! $result) {
                throw new OperationFailure('asm_plugin_delete_failed', 'Nie udało się usunąć wtyczki.');
            }
            return;
        }
        $upgrader = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
        if ($this->action !== 'update') {
            $result = $upgrader->install($this->repositoryDownload('plugin', (string) ($change['slug'] ?? $id)));
            $this->assertUpgrade($result, 'asm_plugin_upgrade_failed');
            return;
        }
        $network = is_multisite() && is_plugin_active_for_network($id);
        $wasActive = $network || is_plugin_active($id);
        // Pusta lista aktualizacji (np. po cache.purge) daje „brak aktualizacji”: odśwież ją przed aktualizacją.
        if ($this->pluginUpdate($id) === null) {
            wp_update_plugins();
        }
        if ($this->pluginUpdate($id) === null) {
            throw new OperationFailure('asm_plugin_no_update', 'WordPress nie widzi nowszej wersji tej wtyczki.');
        }
        // bulk_upgrade, jak WP-CLI i ekran Aktualizacje: upgrade() wyłącza aktywną wtyczkę i jej nie włącza.
        $results = $upgrader->bulk_upgrade(array($id));
        $result = is_array($results) ? ($results[$id] ?? false) : $results;
        // Sukces bulk_upgrade to tablica z danymi instalacji, nie true.
        $this->assertUpgrade(is_array($result) ? true : $result, 'asm_plugin_upgrade_failed');
        if ($wasActive && ! is_plugin_active($id)) {
            $activated = activate_plugin($id, '', $network, true);
            if (is_wp_error($activated)) {
                throw new OperationFailure('asm_plugin_reactivation_failed', 'Wtyczka zaktualizowana, ale nie udało się jej ponownie włączyć: ' . $activated->get_error_message());
            }
        }
    }

    private function applyTheme(string $id, array $change): void
    {
        if ($this->action === 'activate') {
            switch_theme($id);
            return;
        }
        if ($this->action === 'delete') {
            $result = delete_theme($id);
            if (is_wp_error($result) || ! $result) {
                throw new OperationFailure('asm_theme_delete_failed', 'Nie udało się usunąć motywu.');
            }
            return;
        }
        $upgrader = new \Theme_Upgrader(new \Automatic_Upgrader_Skin());
        $result = $this->action === 'update'
            ? $upgrader->upgrade($id)
            : $upgrader->install($this->repositoryDownload('theme', (string) ($change['slug'] ?? $id)));
        $this->assertUpgrade($result, 'asm_theme_upgrade_failed');
    }

    private function repositoryDownload(string $type, string $slug): string
    {
        $slug = preg_replace('/[^a-z0-9-]/', '', strtolower($slug));
        if (! is_string($slug) || $slug === '') {
            throw new OperationFailure('asm_invalid_component_slug', 'Nieprawidłowy identyfikator repozytorium.');
        }
        // Nasze wtyczki spoza wordpress.org: stała paczka najnowszego wydania na GitHubie (robi ją build.py wtyczki).
        if ($type === 'plugin' && isset(self::OWN_PLUGINS[$slug])) {
            return 'https://github.com/' . self::OWN_PLUGINS[$slug] . '/releases/latest/download/' . $slug . '.zip';
        }
        $api = $type === 'plugin'
            ? plugins_api('plugin_information', array('slug' => $slug, 'fields' => array('sections' => false)))
            : themes_api('theme_information', array('slug' => $slug, 'fields' => array('sections' => false)));
        if (is_wp_error($api) || empty($api->download_link)) {
            throw new OperationFailure('asm_component_download_missing', 'Repozytorium nie zwróciło paczki komponentu.');
        }
        return (string) $api->download_link;
    }

    private function componentPath(string $id): string
    {
        if (validate_file($id) !== 0 || strpos(str_replace('\\', '/', $id), '../') !== false) {
            throw new OperationFailure('asm_invalid_component_path', 'Nieprawidłowa ścieżka komponentu.');
        }
        if ($this->type === 'theme') {
            return get_theme_root($id) . DIRECTORY_SEPARATOR . $id;
        }
        $pluginFile = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $id);
        $directory = dirname($pluginFile);
        return $directory === WP_PLUGIN_DIR ? $pluginFile : $directory;
    }

    private function pluginUpdate(string $file): ?array
    {
        $updates = get_site_transient('update_plugins');
        if (! is_object($updates) || empty($updates->response[$file])) {
            return null;
        }
        $update = $updates->response[$file];
        return array('version' => (string) ($update->new_version ?? ''), 'package' => ! empty($update->package));
    }

    private function singleFile(string $path): array
    {
        $bytes = file_get_contents($path);
        if (! is_string($bytes)) {
            throw new OperationFailure('asm_component_unreadable', 'Nie można odczytać pliku komponentu.');
        }
        return array(
            'bytes' => base64_encode($bytes),
            'sha256' => hash('sha256', $bytes),
            'mode' => fileperms($path) & 0777,
        );
    }

    private function snapshotDigest(array $snapshot): string
    {
        $files = array();
        foreach ((array) ($snapshot['files'] ?? array()) as $relative => $file) {
            $files[(string) $relative] = array(
                'sha256' => (string) ($file['sha256'] ?? ''),
                'mode' => (int) ($file['mode'] ?? 0),
            );
        }
        ksort($files);
        return hash('sha256', (string) json_encode($files, JSON_UNESCAPED_SLASHES));
    }

    /** @param mixed $result */
    private function assertUpgrade($result, string $code): void
    {
        if (is_wp_error($result)) {
            throw new OperationFailure($result->get_error_code(), $result->get_error_message());
        }
        if ($result !== true) {
            throw new OperationFailure($code, 'Aktualizacja komponentu nie powiodła się.');
        }
    }

    private function loadAdministrationApi(): void
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/theme.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/theme-install.php';
    }
}
