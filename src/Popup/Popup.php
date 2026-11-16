<?php

declare(strict_types=1);

namespace ASM\Popup;

final class Popup
{
    private const OPTION = 'asm_popup';
    private const LIBRARY = 'asm_popup_library';
    private const LIBRARY_LIMIT = 50;
    private const BASE_STYLES = array(
        'auto' => 'Jak strona',
        'custom' => 'Własny',
        'classic' => 'Klasyczny',
    );
    // Gotowe style: etykieta, grupa i wartości zmiennych CSS okna. Wartości są stałe, nie pochodzą od użytkownika.
    private const PRESETS = array(
        'minimal' => array('Minimalistyczny', 'styl', array('bg' => '#ffffff', 'text' => '#111827', 'accent' => '#111827', 'accent-text' => '#ffffff', 'radius' => '4px')),
        'dark' => array('Ciemny', 'styl', array('bg' => '#111827', 'text' => '#f9fafb', 'accent' => '#f59e0b', 'accent-text' => '#111827', 'radius' => '12px')),
        'gradient' => array('Gradient', 'styl', array('bg' => 'linear-gradient(135deg,#6d28d9,#2563eb)', 'text' => '#ffffff', 'accent' => '#ffffff', 'accent-text' => '#4c1d95', 'radius' => '16px')),
        'glass' => array('Szkło', 'styl', array('bg' => 'rgba(255,255,255,.8)', 'text' => '#0f172a', 'accent' => '#2563eb', 'accent-text' => '#ffffff', 'radius' => '20px')),
        'contrast' => array('Mocny kontrast', 'styl', array('bg' => '#facc15', 'text' => '#111111', 'accent' => '#111111', 'accent-text' => '#facc15', 'radius' => '0px')),
        'pastel' => array('Pastelowy', 'styl', array('bg' => '#fdf2f8', 'text' => '#4a044e', 'accent' => '#db2777', 'accent-text' => '#ffffff', 'radius' => '24px')),
        'business' => array('Biznesowy', 'styl', array('bg' => '#ffffff', 'text' => '#0f2a4a', 'accent' => '#0b5cad', 'accent-text' => '#ffffff', 'radius' => '6px')),
        'elegant' => array('Elegancki', 'styl', array('bg' => '#1c1917', 'text' => '#f5f0e6', 'accent' => '#c8a45c', 'accent-text' => '#1c1917', 'radius' => '2px', 'heading-font' => 'Georgia,"Times New Roman",serif')),
        'nature' => array('Naturalny', 'styl', array('bg' => '#f3f7f0', 'text' => '#1f3d2b', 'accent' => '#2f7d4f', 'accent-text' => '#ffffff', 'radius' => '14px')),
        'sunset' => array('Ciepły', 'styl', array('bg' => 'linear-gradient(135deg,#f97316,#db2777)', 'text' => '#ffffff', 'accent' => '#ffffff', 'accent-text' => '#9a3412', 'radius' => '18px')),
        'nowy-rok' => array('Nowy Rok', 'okazja', array('bg' => 'linear-gradient(160deg,#0b1026,#1e2a78)', 'text' => '#ffffff', 'accent' => '#f5c542', 'accent-text' => '#0b1026', 'radius' => '16px', 'deco' => '"🎆"')),
        'boze-narodzenie' => array('Boże Narodzenie', 'okazja', array('bg' => 'linear-gradient(160deg,#7f1d1d,#b91c1c)', 'text' => '#ffffff', 'accent' => '#fde68a', 'accent-text' => '#7f1d1d', 'radius' => '16px', 'deco' => '"🎄"')),
        'wielkanoc' => array('Wielkanoc', 'okazja', array('bg' => '#fefce8', 'text' => '#3f6212', 'accent' => '#65a30d', 'accent-text' => '#ffffff', 'radius' => '22px', 'deco' => '"🐣"')),
        'niepodleglosc' => array('Święto Niepodległości', 'okazja', array('bg' => '#ffffff', 'text' => '#1f2937', 'accent' => '#dc143c', 'accent-text' => '#ffffff', 'radius' => '8px')),
    );

    /** @return array<string,string> Wszystkie style: klucz i etykieta. */
    public static function styles(): array
    {
        return self::BASE_STYLES + array_map(static fn(array $preset): string => $preset[0], self::PRESETS);
    }

    /** @return array<string,array{label:string,group:string,bg:string,text:string,accent:string}> Dane do podglądu stylów w panelu. */
    public static function swatches(): array
    {
        $swatches = array();
        foreach (self::PRESETS as $key => $preset) {
            $swatches[$key] = array('label' => $preset[0], 'group' => $preset[1], 'bg' => $preset[2]['bg'], 'text' => $preset[2]['text'], 'accent' => $preset[2]['accent']);
        }
        return $swatches;
    }

    /** @return array<string,array{name:string,settings:array<string,mixed>}> Gotowe popupy okazjonalne. */
    public static function templates(): array
    {
        return array(
            'nowy-rok' => array('name' => 'Nowy Rok', 'settings' => array('style' => 'nowy-rok', 'title' => 'Szczęśliwego Nowego Roku!', 'message' => "Dziękujemy, że jesteście z nami.\n\nW nowym roku życzymy zdrowia, spokoju i udanych planów.", 'button_text' => 'Dziękuję', 'button_url' => '')),
            'boze-narodzenie' => array('name' => 'Boże Narodzenie', 'settings' => array('style' => 'boze-narodzenie', 'title' => 'Wesołych Świąt!', 'message' => "Życzymy spokojnych i radosnych Świąt Bożego Narodzenia.\n\nW czasie świąt odpowiadamy na wiadomości z opóźnieniem.", 'button_text' => 'Wesołych Świąt', 'button_url' => '')),
            'wielkanoc' => array('name' => 'Wielkanoc', 'settings' => array('style' => 'wielkanoc', 'title' => 'Wesołego Alleluja!', 'message' => "Życzymy zdrowych i pogodnych Świąt Wielkanocnych.\n\nW czasie świąt odpowiadamy na wiadomości z opóźnieniem.", 'button_text' => 'Dziękuję', 'button_url' => '')),
            'niepodleglosc' => array('name' => 'Święto Niepodległości', 'settings' => array('style' => 'niepodleglosc', 'title' => '11 listopada, Narodowe Święto Niepodległości', 'message' => "W tym dniu nie pracujemy.\n\nNa wiadomości odpowiemy w najbliższy dzień roboczy.", 'button_text' => 'Rozumiem', 'button_url' => '')),
        );
    }

    /** @return array<string,array{name:string,saved:int,settings:array<string,mixed>}> Popupy zapisane przez administratora. */
    public static function library(): array
    {
        $library = get_option(self::LIBRARY, array());
        return is_array($library) ? $library : array();
    }

    // Zapis do biblioteki nie obejmuje pola enabled: wczytany popup nie włącza się sam.
    public static function remember(string $name, array $settings): bool
    {
        $name = sanitize_text_field($name);
        $library = self::library();
        if ($name === '' || count($library) >= self::LIBRARY_LIMIT) {
            return false;
        }
        $clean = self::clean($settings);
        unset($clean['enabled']);
        $library[bin2hex(random_bytes(6))] = array('name' => mb_substr($name, 0, 80), 'saved' => time(), 'settings' => $clean);
        return update_option(self::LIBRARY, $library, false);
    }

    /** @return array<string,mixed>|null Ustawienia z biblioteki albo z szablonu (identyfikator „tpl:klucz”). */
    public static function recall(string $id): ?array
    {
        if (str_starts_with($id, 'tpl:')) {
            return self::templates()[substr($id, 4)]['settings'] ?? null;
        }
        $entry = self::library()[$id] ?? null;
        return is_array($entry) && is_array($entry['settings'] ?? null) ? $entry['settings'] : null;
    }

    public static function forget(string $id): bool
    {
        $library = self::library();
        if (! isset($library[$id])) {
            return false;
        }
        unset($library[$id]);
        return update_option(self::LIBRARY, $library, false);
    }

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
        // Tylko znane pola: klucze usunięte w nowszych wersjach nie wracają w odczycie ani w kopii przed zmianą.
        $defaults = self::defaults();
        return array_merge($defaults, array_intersect_key(is_array($saved) ? $saved : array(), $defaults));
    }

    /** @return array<string,mixed> */
    public static function save(array $data): array
    {
        $clean = self::clean($data);
        update_option(self::OPTION, $clean, false);
        return $clean;
    }

    /** @return array<string,mixed> */
    private static function clean(array $data): array
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
            'style' => isset(self::styles()[$style]) ? $style : $defaults['style'],
            'background' => $color('background'),
            'text' => $color('text'),
            'radius' => max(0, min(40, (int) ($data['radius'] ?? $defaults['radius']))),
            'custom_css' => self::cleanCss((string) ($data['custom_css'] ?? '')),
        );
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
        add_filter('script_loader_tag', array($this, 'immediate'), 10, 2);
    }

    // LiteSpeed i podobne optymalizatory opóźniają skrypty do pierwszej interakcji. Popup ma się pokazać od razu.
    public function immediate(string $tag, string $handle): string
    {
        return $handle === 'asm-popup'
            ? str_replace('<script ', '<script data-no-optimize="1" data-no-defer="1" data-cfasync="false" ', $tag)
            : $tag;
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
        $style = isset(self::styles()[$settings['style']]) ? (string) $settings['style'] : 'classic';
        $vars = '--asm-popup-accent:' . $hex('accent', '#2271b1');
        if ($style === 'custom') {
            $vars .= ';--asm-popup-bg:' . $hex('background', '#ffffff') . ';--asm-popup-text:' . $hex('text', '#1d2327')
                . ';--asm-popup-radius:' . max(0, min(40, (int) $settings['radius'])) . 'px';
        }
        foreach (self::PRESETS[$style][2] ?? array() as $name => $value) {
            $vars .= ';--asm-popup-' . $name . ':' . $value;
        }
        $url = (string) $settings['button_url'];
        $label = $escape($settings['button_text']);
        $button = $url !== '' && preg_match('~^https?://~i', $url)
            ? '<a class="asm-popup__btn" href="' . $escape($url) . '" data-asm-close>' . $label . '</a>'
            : '<button type="button" class="asm-popup__btn" data-asm-close>' . $label . '</button>';

        return '<div id="asm-popup" class="asm-popup asm-popup--' . $style . '" role="dialog" aria-modal="true" '
            . 'aria-labelledby="asm-popup-title" aria-describedby="asm-popup-description" tabindex="-1" hidden '
            . 'data-revision="' . substr(hash('sha256', $settings['title'] . '|' . $settings['message']), 0, 12) . '" '
            . 'data-dismiss-days="' . (int) $settings['dismiss_days'] . '" data-preview="' . (self::preview() ? '1' : '0') . '" '
            . 'style="' . $escape($vars) . '">'
            . '<div class="asm-popup__backdrop" data-asm-close></div>'
            . '<div class="asm-popup__box" role="document">'
            . '<button type="button" class="asm-popup__x" aria-label="Zamknij okno" data-asm-close>&times;</button>'
            . '<h2 id="asm-popup-title" class="asm-popup__title">' . $escape($settings['title']) . '</h2>'
            // Edytor zapisuje akapity jako puste wiersze. Bez wpautop treść zlewała się w jeden blok.
            . '<div id="asm-popup-description" class="asm-popup__msg">' . (function_exists('wpautop') ? wpautop((string) $settings['message']) : (string) $settings['message']) . '</div>'
            . $button
            . '</div></div>';
    }

    private static function assetVersion(string $relativePath): string
    {
        $path = plugin_dir_path(ASM_PLUGIN_FILE) . ltrim($relativePath, '/\\');
        return is_file($path) ? ASM_VERSION . '.' . filemtime($path) : ASM_VERSION;
    }
}
