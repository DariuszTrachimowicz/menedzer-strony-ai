<?php

declare(strict_types=1);

namespace ASM;

/**
 * Aktualizacje z wydań GitHuba przez natywny mechanizm WordPressa (nagłówek Update URI).
 * Wydanie musi mieć tag w postaci v1.2.3 i załącznik ai-site-manager-*.zip z katalogiem ai-site-manager/.
 * shortcut: tylko publiczne repozytorium, prywatne wymaga tokenu w żądaniach i pobieraniu.
 */
final class Updater
{
    private const CACHE = 'asm_update_release';

    public static function repo(): string
    {
        $repo = defined('ASM_UPDATE_REPO') ? (string) ASM_UPDATE_REPO : 'DariuszTrachimowicz/menedzer-strony-ai';
        return preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D', $repo) === 1 ? $repo : '';
    }

    public static function hooks(): void
    {
        // Nazwa filtra pochodzi z hosta w nagłówku Update URI pliku wtyczki.
        add_filter('update_plugins_d-x.pl', array(self::class, 'check'), 10, 3);
    }

    /** @return array{version:string,package:string,url:string}|null */
    public static function release(bool $fresh = false): ?array
    {
        $cached = get_site_transient(self::CACHE);
        if (! $fresh && is_array($cached)) {
            return $cached ?: null;
        }
        $release = array();
        $repo = self::repo();
        $reply = $repo === '' ? null : wp_remote_get(
            'https://api.github.com/repos/' . $repo . '/releases/latest',
            array('timeout' => 8, 'headers' => array('Accept' => 'application/vnd.github+json'))
        );
        $data = $reply && ! is_wp_error($reply) && wp_remote_retrieve_response_code($reply) === 200
            ? json_decode(wp_remote_retrieve_body($reply), true)
            : null;
        $version = is_array($data) ? ltrim((string) ($data['tag_name'] ?? ''), 'v') : '';
        if (preg_match('/^\d+\.\d+\.\d+$/D', $version) === 1) {
            foreach ((array) ($data['assets'] ?? array()) as $asset) {
                $package = (string) ($asset['browser_download_url'] ?? '');
                // Paczka tylko z wydań tego repozytorium.
                if (preg_match('/^ai-site-manager[\w.-]*\.zip$/D', (string) ($asset['name'] ?? '')) === 1
                    && str_starts_with($package, 'https://github.com/' . $repo . '/releases/download/')) {
                    $release = array('version' => $version, 'package' => $package, 'url' => (string) ($data['html_url'] ?? ''));
                    break;
                }
            }
        }
        // Nieudany odczyt pamiętamy krócej, żeby nie odpytywać GitHuba przy każdym wejściu do panelu.
        set_site_transient(self::CACHE, $release, $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS);
        return $release ?: null;
    }

    /** @param mixed $update */
    public static function check($update, array $pluginData, string $pluginFile)
    {
        if ($pluginFile !== plugin_basename(ASM_PLUGIN_FILE)) {
            return $update;
        }
        $release = self::release();
        if (! $release) {
            return $update;
        }
        // WordPress sam porównuje wersję z zainstalowaną.
        return array(
            'slug' => 'ai-site-manager',
            'version' => $release['version'],
            'url' => $release['url'],
            'package' => $release['package'],
            'requires_php' => '8.2',
        );
    }

    public static function refresh(): ?array
    {
        $release = self::release(true);
        delete_site_transient('update_plugins');
        wp_update_plugins();
        return $release;
    }
}
