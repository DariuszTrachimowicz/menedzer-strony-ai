<?php

declare(strict_types=1);

namespace ASM;

/**
 * Aktualizacje z wydań GitHuba przez natywny mechanizm WordPressa (nagłówek Update URI).
 * Wydanie musi mieć tag w postaci v1.2.3 i załącznik ai-site-manager-1.2.3.zip z katalogiem ai-site-manager/.
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
        // Ostatni priorytet: część wtyczek (np. CMS Portal motywów CMSSuperHeroes, priorytet 20) zwraca false
        // dla każdego obcego sluga i kasuje dane okna "Zobacz szczegóły" podane wcześniej.
        add_filter('plugins_api', array(self::class, 'info'), PHP_INT_MAX, 3);
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
        // Adres releases/latest przekierowuje na tag najnowszego wydania. API GitHuba ma limit 60 zapytań
        // na godzinę na adres IP, który na współdzielonym hostingu wyczerpują inne strony.
        $reply = $repo === '' ? null : wp_remote_head(
            'https://github.com/' . $repo . '/releases/latest',
            array('timeout' => 8, 'redirection' => 0)
        );
        $location = $reply && ! is_wp_error($reply) ? (string) wp_remote_retrieve_header($reply, 'location') : '';
        if (preg_match('~^https://github\.com/' . preg_quote($repo, '~') . '/releases/tag/v(\d+\.\d+\.\d+)$~D', $location, $match) === 1) {
            // Paczka ma stałą nazwę nadawaną przez build.py i leży w wydaniach tego repozytorium.
            $release = array(
                'version' => $match[1],
                'package' => 'https://github.com/' . $repo . '/releases/download/v' . $match[1] . '/ai-site-manager-' . $match[1] . '.zip',
                'url' => $location,
            );
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

    // Okno „Zobacz szczegóły” na liście wtyczek. Treść pochodzi z readme.txt, żeby opis miał jedno źródło.
    /** @param mixed $result @param mixed $args */
    public static function info($result, $action, $args)
    {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'ai-site-manager') {
            return $result;
        }
        $release = self::release();
        return (object) array(
            'name' => 'Menedżer Strony AI',
            'slug' => 'ai-site-manager',
            'version' => $release['version'] ?? ASM_VERSION,
            'author' => '<a href="https://d-x.pl/">Dariusz Trachimowicz</a>',
            'homepage' => 'https://d-x.pl/wtyczki/menedzer-strony-ai/',
            'requires' => '7.1',
            // Najwyższa wersja WordPressa, na której wydanie było uruchamiane. Starsza wartość pokazuje w oknie ostrzeżenie.
            'tested' => '7.1.4',
            'requires_php' => '8.2',
            'download_link' => $release['package'] ?? '',
            'sections' => self::sections(),
            'banners' => array(
                'low' => plugins_url('assets/banner-772x250.jpg', ASM_PLUGIN_FILE),
                'high' => plugins_url('assets/banner-1544x500.jpg', ASM_PLUGIN_FILE),
            ),
        );
    }

    /** @return array<string,string> */
    public static function sections(): array
    {
        $text = str_replace("\r\n", "\n", (string) file_get_contents(ASM_PLUGIN_DIR . 'readme.txt'));
        $map = array('Description' => 'description', 'Installation' => 'installation', 'Frequently Asked Questions' => 'faq', 'Other Notes' => 'other_notes', 'Changelog' => 'changelog');
        $parts = preg_split('/^== (.+?) ==$/m', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: array();
        $sections = array();
        for ($i = 1; $i + 1 < count($parts); $i += 2) {
            $key = $map[trim($parts[$i])] ?? null;
            if ($key) {
                $sections[$key] = self::html($parts[$i + 1]);
            }
        }
        return $sections;
    }

    // Podzbiór składni readme WordPressa: nagłówki = x =, listy, pogrubienie, kod i adresy.
    private static function html(string $text): string
    {
        $html = '';
        $list = '';
        $close = static function () use (&$html, &$list): void {
            if ($list !== '') {
                $html .= '</' . $list . '>';
                $list = '';
            }
        };
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if (preg_match('/^(?:(\d+)\. |[*-] )(.*)$/', $line, $m)) {
                $kind = $m[1] !== '' ? 'ol' : 'ul';
                if ($list !== $kind) {
                    $close();
                    $list = $kind;
                    $html .= '<' . $kind . '>';
                }
                $html .= '<li>' . self::inline($m[2]) . '</li>';
                continue;
            }
            $close();
            if (preg_match('/^= (.+) =$/', $line, $m)) {
                $html .= '<h4>' . esc_html($m[1]) . '</h4>';
            } elseif ($line !== '') {
                $html .= '<p>' . self::inline($line) . '</p>';
            }
        }
        $close();
        return $html;
    }

    private static function inline(string $text): string
    {
        $text = esc_html($text);
        $text = (string) preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
        return (string) preg_replace('~https?://[^\s<]+[^\s<.,)]~', '<a href="$0">$0</a>', $text);
    }

    public static function refresh(): ?array
    {
        $release = self::release(true);
        delete_site_transient('update_plugins');
        wp_update_plugins();
        return $release;
    }
}
