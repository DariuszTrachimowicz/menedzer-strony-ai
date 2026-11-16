<?php

declare(strict_types=1);

namespace ASM\Resources;

use ASM\Operations\OperationFailure;

final class CoreResource
{
    public function list(array $params = array()): array
    {
        $updates = get_core_updates(array('dismissed' => false));
        return array(array(
            'id' => 'wordpress',
            'version' => get_bloginfo('version'),
            'locale' => get_locale(),
            'updates' => is_wp_error($updates) ? array() : $updates,
        ));
    }

    public function get(string $id)
    {
        return $id === 'wordpress'
            ? $this->list()[0]
            : new \WP_Error('asm_resource_not_found', 'Nie znaleziono rdzenia WordPressa.', array('status' => 404));
    }

    public function snapshot(array $target): array
    {
        return array('version' => get_bloginfo('version'), 'locale' => get_locale());
    }

    public function apply(array $target, array $change): void
    {
        require_once ABSPATH . 'wp-admin/includes/update.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        $version = (string) ($change['version'] ?? '');
        $locale = (string) ($change['locale'] ?? get_locale());
        $update = find_core_update($version, $locale);
        if (! is_object($update)) {
            throw new OperationFailure('asm_core_update_not_found', 'Nie znaleziono wskazanej aktualizacji WordPressa.');
        }
        $result = (new \Core_Upgrader(new \Automatic_Upgrader_Skin()))->upgrade($update, array('pre_check_md5' => true));
        if (is_wp_error($result) || $result !== true) {
            throw new OperationFailure('asm_core_update_failed', 'Aktualizacja WordPressa nie powiodła się.');
        }
    }

    public function verify(array $target, array $change): ?bool
    {
        return ! empty($change['version'])
            && version_compare(get_bloginfo('version'), (string) $change['version'], '>=');
    }

    public function rollback(array $target, array $snapshot): void
    {
        throw new OperationFailure(
            'asm_core_rollback_requires_recovery_package',
            'Rollback rdzenia wymaga przygotowanej paczki odzyskiwania serwera.'
        );
    }
}
