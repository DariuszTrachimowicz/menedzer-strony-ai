<?php

declare(strict_types=1);

namespace ASM\Resources;

final class CacheResource
{
    public function list(array $params = array()): array
    {
        return array(
            array('id' => 'object', 'active' => wp_using_ext_object_cache()),
            array('id' => 'opcache', 'active' => function_exists('opcache_get_status') && opcache_get_status(false) !== false),
            array('id' => 'litespeed', 'active' => defined('LSCWP_V')),
            array('id' => 'w3_total_cache', 'active' => function_exists('w3tc_flush_all')),
            array('id' => 'wp_super_cache', 'active' => function_exists('wp_cache_clear_cache')),
        );
    }

    public function get(string $id)
    {
        foreach ($this->list() as $cache) {
            if ($cache['id'] === $id) {
                return $cache;
            }
        }
        return new \WP_Error('asm_resource_not_found', 'Nie znaleziono mechanizmu cache.', array('status' => 404));
    }

    public function snapshot(array $target): array
    {
        return array('cache' => $this->list());
    }

    public function apply(array $target, array $change): void
    {
        $scope = (string) ($target['id'] ?? $change['scope'] ?? 'all');
        if ($scope === 'object' || $scope === 'all') {
            wp_cache_flush();
        }
        if (($scope === 'litespeed' || $scope === 'all') && has_action('litespeed_purge_all')) {
            do_action('litespeed_purge_all');
        }
        if (($scope === 'w3_total_cache' || $scope === 'all') && function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
        }
        if (($scope === 'wp_super_cache' || $scope === 'all') && function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }
        if (($scope === 'opcache' || $scope === 'all') && function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    public function verify(array $target, array $change): ?bool
    {
        return true;
    }

    public function rollback(array $target, array $snapshot): void
    {
        // Czyszczenie cache jest bezpieczne i nie ma stanu do odtworzenia.
    }
}
