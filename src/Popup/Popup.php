<?php

declare(strict_types=1);

namespace ASM\Popup;

final class Popup
{
    private const OPTION = 'asm_popup';
    public const STYLES = array(
        'classic' => 'Klasyczny (dotychczasowy wygląd)',
        'auto' => 'Jak strona (czcionki, kolory i przycisk pobierane ze strony)',
        'custom' => 'Własny (kolory i zaokrąglenie poniżej)',
    );

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return array(
            'enabled' => false,
            'title' => '',
            'message' => '',
            'button_text' => '',
            'button_url' => '',
            'accent' => '#2271b1',
            'dismiss_days' => 7,
            // Istniejące instalacje zachowują dotychczasowy wygląd, dopóki administrator go nie zmieni.
            'style' => 'classic',
            'background' => '#ffffff',
            'text' => '#1d2327',
            'radius' => 12,
            'custom_css' => '',
        );
    }

    /** @return array<string,mixed> */
    public static function settings(): array
    {
        $saved = get_option(self::OPTION, array());
        return array_merge(self::defaults(), is_array($saved) ? $saved : array());
    }

    /** @return array<string,mixed> */
    public static function save(array $data): array
    {
        $defaults = self::defaults();
        $color = static fn(string $key): string => isset($data[$key]) && preg_match('/^#[0-9a-f]{6}$/i', (string) $data[$key])
            ? (string) $data[$key]
            : $defaults[$key];
        $style = (string) ($data['style'] ?? $defaults['style']);
        $clean = array(
            'enabled' => ! empty($data['enabled']),
            'title' => sanitize_text_field((string) ($data['title'] ?? $defaults['title'])),
            'message' => wp_kses_post((string) ($data['message'] ?? $defaults['message'])),
            'button_text' => sanitize_text_field((string) ($data['button_text'] ?? $defaults['button_text'])),
            'button_url' => esc_url_raw(trim((string) ($data['button_url'] ?? '')), array('http', 'https')),
            'accent' => $color('accent'),
            'dismiss_days' => max(0, min(365, (int) ($data['dismiss_days'] ?? 7))),
            'style' => isset(self::STYLES[$style]) ? $style : $defaults['style'],
            'background' => $color('background'),
            'text' => $color('text'),
            'radius' => max(0, min(40, (int) ($data['radius'] ?? $defaults['radius']))),
            'custom_css' => self::cleanCss((string) ($data['custom_css'] ?? '')),
        );
        update_option(self::OPTION, $clean, false);
        return $clean;
    }

    // CSS trafia do znacznika style, więc nie może go zamknąć. Rozsunięcie „</” nie zmienia poprawnego CSS.
    // To globalny arkusz strony, tak jak „Dodatkowy CSS” motywu: wpisuje go administrator albo połączenie z pełnym dostępem.
    private static function cleanCss(string $css): string
    {
        return str_replace('</', '< /', mb_substr($css, 0, 5000));
    }

    public function hooks(): void
    {
        add_action('wp_enqueue_scripts', array($this, 'enqueue'));
        add_action('wp_footer', array($this, 'render'));
    }

    // Podgląd pokazuje zapisany popup administratorowi także wtedy, gdy popup jest wyłączony.
    public static function preview(): bool
    {
        return isset($_GET['asm_popup_preview']) && current_user_can('manage_options'); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    }

    public static function previewUrl(): string
    {
        return add_query_arg('asm_popup_preview', '1', home_url('/'));
    }

    private static function visible(array $settings): bool
    {
        return self::preview() || (! empty($settings['enabled']) && trim((string) $settings['message']) !== '');
    }

    public function enqueue(): void
    {
        $settings = self::settings();
        if (! self::visible($settings)) {
            return;
        }
        $sheet = $settings['style'] === 'classic' ? 'assets/popup-v3.css' : 'assets/popup-theme.css';
        wp_enqueue_style('asm-popup', plugins_url($sheet, ASM_PLUGIN_FILE), array(), self::assetVersion($sheet));
        if ($settings['custom_css'] !== '') {
            wp_add_inline_style('asm-popup', $settings['custom_css']);
        }
        wp_enqueue_script(
            'asm-popup',
            plugins_url('assets/popup.js', ASM_PLUGIN_FILE),
            array(),
            self::assetVersion('assets/popup.js'),
            true
        );
        wp_localize_script('asm-popup', 'asmPopupConfig', array(
            'revision' => substr(hash('sha256', $settings['title'] . '|' . $settings['message']), 0, 12),
            'dismissDays' => (int) $settings['dismiss_days'],
            'style' => $settings['style'],
            'preview' => self::preview(),
        ));
    }

    public function render(): void
    {
        if (is_admin()) {
            return;
        }
        $settings = self::settings();
        if (! self::visible($settings)) {
            return;
        }
        if (trim((string) $settings['message']) === '') {
            $settings['title'] = $settings['title'] !== '' ? $settings['title'] : 'Przykładowy tytuł';
            $settings['message'] = '<p>To jest podgląd. Wpisz treść popupu w ustawieniach wtyczki.</p>';
            $settings['button_text'] = $settings['button_text'] !== '' ? $settings['button_text'] : 'Rozumiem';
        }
        echo self::renderMarkup($settings); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public static function renderMarkup(array $settings): string
    {
        $settings = array_merge(self::defaults(), $settings);
        $escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $hex = static fn(string $key, string $fallback): string => preg_match('/^#[0-9a-f]{6}$/i', (string) $settings[$key])
            ? (string) $settings[$key]
            : $fallback;
        $style = isset(self::STYLES[$settings['style']]) ? (string) $settings['style'] : 'classic';
        $vars = '--asm-popup-accent:' . $hex('accent', '#2271b1');
        if ($style === 'custom') {
            $vars .= ';--asm-popup-bg:' . $hex('background', '#ffffff') . ';--asm-popup-text:' . $hex('text', '#1d2327')
                . ';--asm-popup-radius:' . max(0, min(40, (int) $settings['radius'])) . 'px';
        }
        $url = (string) $settings['button_url'];
        $label = $escape($settings['button_text']);
        $button = $url !== '' && preg_match('~^https?://~i', $url)
            ? '<a class="asm-popup__btn" href="' . $escape($url) . '" data-asm-close>' . $label . '</a>'
            : '<button type="button" class="asm-popup__btn" data-asm-close>' . $label . '</button>';

        return '<div id="asm-popup" class="asm-popup asm-popup--' . $style . '" role="dialog" aria-modal="true" '
            . 'aria-labelledby="asm-popup-title" aria-describedby="asm-popup-description" tabindex="-1" hidden '
            . 'style="' . $escape($vars) . '">'
            . '<div class="asm-popup__backdrop" data-asm-close></div>'
            . '<div class="asm-popup__box" role="document">'
            . '<button type="button" class="asm-popup__x" aria-label="Zamknij okno" data-asm-close>&times;</button>'
            . '<h2 id="asm-popup-title" class="asm-popup__title">' . $escape($settings['title']) . '</h2>'
            . '<div id="asm-popup-description" class="asm-popup__msg">' . (string) $settings['message'] . '</div>'
            . $button
            . '</div></div>';
    }

    private static function assetVersion(string $relativePath): string
    {
        $path = plugin_dir_path(ASM_PLUGIN_FILE) . ltrim($relativePath, '/\\');
        return is_file($path) ? ASM_VERSION . '.' . filemtime($path) : ASM_VERSION;
    }
}
