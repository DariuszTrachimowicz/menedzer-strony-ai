<?php

declare(strict_types=1);

namespace ASM\Admin;

use ASM\Migration\Migration;
use ASM\Popup\Popup;
use ASM\Storage\AuditStore;
use ASM\Updater;

final class AdminPage
{
    private const SLUG = 'ai-site-manager';
    private const NONCE = 'asm2_admin';
    private const MESSAGES = array(
        'popup_saved' => 'Zapisano ustawienia popupu.',
        'popup_remembered' => 'Zapisano popup w bibliotece.',
        'popup_loaded' => 'Wczytano popup. Sprawdź treść i zapisz, a potem obejrzyj podgląd.',
        'popup_forgotten' => 'Usunięto popup z biblioteki.',
        'popup_library_failed' => 'Nie udało się wykonać operacji na bibliotece. Podaj nazwę albo usuń starsze wpisy (limit 50).',
        'operator_saved' => 'Zapisano uprawnienia operatora.',
        'legacy_revoked' => 'Usunięto stary hash klucza.',
        'update_checked' => 'Sprawdzono wydania w repozytorium.',
    );

    private static ?self $instance = null;
    private AuditStore $audit;
    private bool $storageAvailable;

    public function __construct(AuditStore $audit, bool $storageAvailable)
    {
        $this->audit = $audit;
        $this->storageAvailable = $storageAvailable;
    }

    public function hooks(): void
    {
        self::$instance = $this;
        // Stary adres z menu Ustawienia. WordPress odmawia dostępu do nieistniejącej strony przed admin_init, stąd przekierowanie już tutaj.
        if (($GLOBALS['pagenow'] ?? '') === 'options-general.php' && ($_GET['page'] ?? '') === self::SLUG) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $query = array('page' => self::SLUG);
            foreach (array('tab', 'doc', 'add', 'client', 'connection', 'result', 'asm_message') as $key) {
                if (isset($_GET[$key]) && is_string($_GET[$key])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                    $query[$key] = sanitize_text_field(wp_unslash($_GET[$key])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                }
            }
            wp_safe_redirect(add_query_arg($query, admin_url('admin.php')));
            exit;
        }
        add_action('admin_menu', array($this, 'menu'));
        add_action('wp_dashboard_setup', array($this, 'dashboard'));
        add_action('admin_enqueue_scripts', array($this, 'assets'));
        add_action('admin_post_asm2_save_popup', array($this, 'savePopup'));
        add_action('admin_post_asm2_popup_library', array($this, 'popupLibrary'));
        add_action('admin_post_asm2_operator', array($this, 'changeOperator'));
        add_action('admin_post_asm2_revoke_legacy', array($this, 'revokeLegacy'));
        add_action('admin_post_asm2_check_update', array($this, 'checkUpdate'));
        add_filter('plugin_action_links_' . plugin_basename(ASM_PLUGIN_FILE), array($this, 'actionLinks'));
    }

    public function menu(): void
    {
        // Pozycja 3: zaraz pod Kokpitem.
        add_menu_page(
            'Menedżer Strony AI',
            'Menedżer Strony AI',
            'manage_options',
            self::SLUG,
            array($this, 'render'),
            'dashicons-superhero-alt',
            3
        );
    }

    public function assets(string $hook): void
    {
        if ($hook !== 'toplevel_page_' . self::SLUG) {
            return;
        }
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_style('asm-mcp-admin', plugins_url('assets/mcp-admin.css', ASM_PLUGIN_FILE), array(), ASM_VERSION);
        wp_enqueue_script('asm-mcp-admin', plugins_url('assets/mcp-admin.js', ASM_PLUGIN_FILE), array('wp-color-picker'), ASM_VERSION, true);
    }

    public function actionLinks(array $links): array
    {
        array_unshift(
            $links,
            '<a href="' . esc_url(self::url('start')) . '">Ustawienia</a>'
        );
        return $links;
    }

    public static function url(string $tab): string
    {
        return admin_url('admin.php?page=' . self::SLUG . '&tab=' . $tab);
    }

    // Sekcje natywne wyświetlane w zakładkach panelu połączeń.
    public static function section(string $name): void
    {
        if (self::$instance && in_array($name, array('popup', 'operators', 'system', 'audit'), true)) {
            self::$instance->{$name}();
        }
    }

    public function savePopup(): void
    {
        $this->guard();
        Popup::save(array(
            'enabled' => ! empty($_POST['popup_enabled']),
            'title' => wp_unslash((string) ($_POST['popup_title'] ?? '')),
            'message' => wp_unslash((string) ($_POST['popup_message'] ?? '')),
            'button_text' => wp_unslash((string) ($_POST['popup_button'] ?? '')),
            'button_url' => wp_unslash((string) ($_POST['popup_button_url'] ?? '')),
            'accent' => wp_unslash((string) ($_POST['popup_accent'] ?? '')),
            'dismiss_days' => (int) ($_POST['popup_days'] ?? 7),
            'style' => sanitize_key(wp_unslash((string) ($_POST['popup_style'] ?? ''))),
            'background' => wp_unslash((string) ($_POST['popup_background'] ?? '')),
            'text' => wp_unslash((string) ($_POST['popup_text'] ?? '')),
            'radius' => (int) ($_POST['popup_radius'] ?? 12),
            'custom_css' => wp_unslash((string) ($_POST['popup_css'] ?? '')),
        ));
        $this->redirect('popup_saved', 'popup');
    }

    public function popupLibrary(): void
    {
        $this->guard();
        $operation = sanitize_key(wp_unslash((string) ($_POST['library_action'] ?? '')));
        $id = sanitize_text_field(wp_unslash((string) ($_POST['library_id'] ?? '')));
        $current = Popup::settings();
        if ($operation === 'save') {
            $ok = Popup::remember(wp_unslash((string) ($_POST['library_name'] ?? '')), $current);
            $this->redirect($ok ? 'popup_remembered' : 'popup_library_failed', 'popup');
        }
        if ($operation === 'load') {
            $settings = Popup::recall($id);
            if ($settings !== null) {
                // Wczytanie nie zmienia tego, czy popup jest włączony.
                Popup::save(array_merge($current, $settings, array('enabled' => ! empty($current['enabled']))));
            }
            $this->redirect($settings !== null ? 'popup_loaded' : 'popup_library_failed', 'popup');
        }
        $this->redirect($operation === 'delete' && Popup::forget($id) ? 'popup_forgotten' : 'popup_library_failed', 'popup');
    }

    public function changeOperator(): void
    {
        $this->guard();
        if (! current_user_can('promote_users')) {
            wp_die('Brak uprawnienia do zarządzania operatorami.');
        }
        $user = get_userdata((int) ($_POST['user_id'] ?? 0));
        $action = sanitize_key(wp_unslash((string) ($_POST['operator_action'] ?? '')));
        if (! $user || ! in_array('administrator', (array) $user->roles, true)) {
            wp_die('Pełny dostęp ASM można nadać wyłącznie administratorowi.');
        }
        if ($action === 'grant') {
            $user->add_cap('asm_full_control');
        } elseif ($action === 'revoke') {
            $user->remove_cap('asm_full_control');
        }
        $this->redirect('operator_saved', 'permissions');
    }

    public function revokeLegacy(): void
    {
        $this->guard();
        Migration::revokeLegacyKey();
        $this->redirect('legacy_revoked', 'system');
    }

    public function checkUpdate(): void
    {
        $this->guard();
        if (! current_user_can('update_plugins')) {
            wp_die('Brak uprawnienia do aktualizacji wtyczek.');
        }
        Updater::refresh();
        $this->redirect('update_checked', 'system');
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $mcp = PHP_VERSION_ID >= 80200 && class_exists('ASM\MCP\Module') && \ASM\MCP\Module::ready();
        $message = self::MESSAGES[sanitize_key((string) ($_GET['asm_message'] ?? ''))] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $release = get_site_transient('asm_update_release');
        $known = is_array($release) && isset($release['version']);
        $newer = $known && version_compare($release['version'], ASM_VERSION, '>');
        ?>
        <div class="wrap asm-admin">
            <hr class="wp-header-end">
            <header class="asm-header">
                <div>
                    <h1>Menedżer Strony AI <span class="asm-version"><?php echo esc_html(ASM_VERSION); ?></span></h1>
                    <p>Połącz tę stronę z asystentem AI, ustaw popup i pilnuj, kto ma dostęp.</p>
                </div>
                <ul class="asm-chips" aria-label="Stan wtyczki">
                    <?php
                    self::chip(is_ssl(), is_ssl() ? 'HTTPS działa' : 'Brak HTTPS');
                    if ($mcp) {
                        self::chip(true, \ASM\MCP\OAuthDiscovery::mode() === 'path' ? 'Logowanie: adresy /wp-json' : 'Logowanie: adresy /.well-known');
                    } else {
                        self::chip(false, 'Połączenia AI niedostępne');
                    }
                    self::chip($this->storageAvailable, $this->storageAvailable ? 'Magazyn kopii gotowy' : 'Magazyn kopii niedostępny');
                    if ($known) {
                        self::chip(! $newer, $newer ? 'Dostępna wersja ' . $release['version'] : 'Wersja aktualna');
                    }
                    ?>
                </ul>
            </header>
            <?php
            if ($message !== '') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
            }
            if ($mcp) {
                \ASM\MCP\Admin::page();
            } else {
                echo '<div class="notice notice-warning"><p>Połączenia AI wymagają PHP 8.2 lub nowszego i poprawnej inicjalizacji modułu. Pozostałe ustawienia są dostępne poniżej.</p></div>';
                foreach (array('popup', 'operators', 'system', 'audit') as $section) {
                    $this->{$section}();
                }
            }
            ?>
        </div>
        <?php
    }

    public function dashboard(): void
    {
        if (current_user_can('manage_options')) {
            add_meta_box('asm_dashboard', 'Menedżer Strony AI', array($this, 'dashboardWidget'), 'dashboard', 'normal', 'high');
        }
    }

    // Widżet kokpitu: sprawy wymagające uwagi i skrót stanu połączeń.
    public function dashboardWidget(): void
    {
        global $wpdb;
        $alerts = array();
        $release = get_site_transient('asm_update_release');
        if (is_array($release) && isset($release['version']) && version_compare($release['version'], ASM_VERSION, '>')) {
            $alerts[] = array('Dostępna jest wersja ' . $release['version'] . '.', admin_url('plugins.php?plugin_status=upgrade'), 'Aktualizuj');
        }
        if (! $this->storageAvailable) {
            $alerts[] = array('Magazyn kopii jest niedostępny, zmian nie da się cofnąć.', self::url('system'), 'Sprawdź');
        }
        if (! is_ssl()) {
            $alerts[] = array('Strona nie działa pod HTTPS, aplikacje zdalne się nie połączą.', self::url('system'), 'Sprawdź');
        }
        $mcp = PHP_VERSION_ID >= 80200 && class_exists('ASM\MCP\Module') && \ASM\MCP\Module::ready();
        $stats = null;
        if ($mcp) {
            $table = \ASM\MCP\Connections::table('connections');
            $now = time();
            $old = $wpdb->suppress_errors(true);
            $stats = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS active, MAX(last_used) AS last_used, SUM(expires_at>0 AND expires_at<%d) AS expiring FROM $table WHERE owner_id=%d AND revoked=0 AND deleted_at=0 AND (expires_at=0 OR expires_at>%d)", $now + 7 * DAY_IN_SECONDS, get_current_user_id(), $now), ARRAY_A);
            $wpdb->suppress_errors($old);
            if ($stats && (int) $stats['expiring'] > 0) {
                $alerts[] = array('Połączenia wygasające w ciągu 7 dni: ' . (int) $stats['expiring'] . '.', self::url('connections'), 'Przedłuż');
            }
        } else {
            $alerts[] = array('Połączenia AI są niedostępne.', self::url('system'), 'Sprawdź');
        }
        foreach ($alerts as $alert) {
            echo '<p class="asm-dash-alert"><span class="dashicons dashicons-warning" aria-hidden="true"></span> ' . esc_html($alert[0]) . ' <a href="' . esc_url($alert[1]) . '">' . esc_html($alert[2]) . '</a></p>';
        }
        if (! $alerts) {
            echo '<p><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> Nic nie wymaga uwagi.</p>';
        }
        $popup = Popup::settings();
        echo '<ul>';
        if ($stats) {
            echo '<li>Twoje aktywne połączenia: <strong>' . (int) $stats['active'] . '</strong></li>';
            echo '<li>Ostatnie użycie: <strong>' . esc_html((int) $stats['last_used'] ? self::humanDate((int) $stats['last_used']) : 'jeszcze nie użyto') . '</strong></li>';
        }
        echo '<li>Popup: <strong>' . (! empty($popup['enabled']) && trim((string) $popup['message']) !== '' ? 'włączony' : 'wyłączony') . '</strong></li>';
        echo '<li>Wersja wtyczki: <strong>' . esc_html(ASM_VERSION) . '</strong></li></ul>';
        echo '<p><a class="button button-primary" href="' . esc_url(self::url('start')) . '">Dodaj połączenie</a> <a class="button" href="' . esc_url(self::url('activity')) . '">Historia</a> <a class="button" href="' . esc_url(self::url('popup')) . '">Popup</a></p>';
    }

    private static function chip(bool $ok, string $label): void
    {
        echo '<li class="asm-chip ' . ($ok ? 'is-ok' : 'is-warn') . '">' . esc_html($label) . '</li>';
    }

    private function popup(): void
    {
        $popup = Popup::settings();
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="asm-grid asm-grid--wide">
            <?php wp_nonce_field(self::NONCE); ?>
            <input type="hidden" name="action" value="asm2_save_popup">
            <section class="asm-surface">
                <h2>Treść popupu</h2>
                <label class="asm-check"><input type="checkbox" name="popup_enabled" value="1" <?php checked(! empty($popup['enabled'])); ?>> Pokazuj popup odwiedzającym</label>
                <label for="asm-popup-title-field">Tytuł</label>
                <input id="asm-popup-title-field" name="popup_title" value="<?php echo esc_attr($popup['title']); ?>">
                <label for="asm_popup_message">Treść</label>
                <?php wp_editor((string) $popup['message'], 'asm_popup_message', array('textarea_name' => 'popup_message', 'teeny' => true, 'media_buttons' => false, 'textarea_rows' => 8)); ?>
                <label for="asm-popup-button-field">Tekst przycisku</label>
                <input id="asm-popup-button-field" name="popup_button" value="<?php echo esc_attr($popup['button_text']); ?>">
                <label for="asm-popup-url-field">Adres po kliknięciu przycisku (opcjonalnie)</label>
                <input id="asm-popup-url-field" type="url" name="popup_button_url" placeholder="https://" value="<?php echo esc_attr($popup['button_url']); ?>">
                <p class="description">Bez adresu przycisk tylko zamyka okno.</p>
                <label for="asm-popup-days-field">Po zamknięciu nie pokazuj przez (dni)</label>
                <input id="asm-popup-days-field" class="asm-short" type="number" min="0" max="365" name="popup_days" value="<?php echo (int) $popup['dismiss_days']; ?>">
                <p class="description">0 oznacza, że popup pokaże się przy każdej wizycie.</p>
                <p class="description">Popup pojawia się od razu po wejściu na stronę, także przed zamknięciem banera cookies.</p>
            </section>
            <section class="asm-surface">
                <h2>Wygląd</h2>
                <fieldset class="asm-styles">
                    <legend>Styl</legend>
                    <?php
                    $swatches = Popup::swatches();
                    $groups = array('Podstawowe' => array('auto', 'custom', 'classic'), 'Gotowe style' => array(), 'Okazje' => array());
                    foreach ($swatches as $key => $swatch) {
                        $groups[$swatch['group'] === 'okazja' ? 'Okazje' : 'Gotowe style'][] = $key;
                    }
                    $labels = Popup::styles();
                    foreach ($groups as $group => $keys) :
                        ?>
                        <p class="asm-styles__group"><?php echo esc_html($group); ?></p>
                        <div class="asm-styles__grid">
                        <?php foreach ($keys as $key) :
                            $swatch = $swatches[$key] ?? null; ?>
                            <label class="asm-style">
                                <input type="radio" name="popup_style" value="<?php echo esc_attr($key); ?>" <?php checked($popup['style'], $key); ?>>
                                <span class="asm-style__swatch"<?php echo $swatch ? ' style="background:' . esc_attr($swatch['bg']) . ';color:' . esc_attr($swatch['text']) . '"' : ''; ?>>Aa<i<?php echo $swatch ? ' style="background:' . esc_attr($swatch['accent']) . '"' : ''; ?>></i></span>
                                <span class="asm-style__name"><?php echo esc_html($labels[$key]); ?></span>
                            </label>
                        <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </fieldset>
                <p class="description">„Jak strona” bierze czcionki, kolory i wygląd przycisku z Twojej strony. „Własny” używa kolorów i zaokrąglenia poniżej. „Klasyczny” to wygląd sprzed wersji 2.4. Gotowe style i okazje mają własne kolory.</p>
                <div class="asm-colors">
                    <div><label for="asm-popup-accent-field">Kolor akcentu i przycisku</label><input id="asm-popup-accent-field" class="asm-color" name="popup_accent" value="<?php echo esc_attr($popup['accent']); ?>"></div>
                    <div><label for="asm-popup-bg-field">Tło okna (styl Własny)</label><input id="asm-popup-bg-field" class="asm-color" name="popup_background" value="<?php echo esc_attr($popup['background']); ?>"></div>
                    <div><label for="asm-popup-text-field">Tekst (styl Własny)</label><input id="asm-popup-text-field" class="asm-color" name="popup_text" value="<?php echo esc_attr($popup['text']); ?>"></div>
                </div>
                <label for="asm-popup-radius-field">Zaokrąglenie rogów w pikselach (styl Własny)</label>
                <input id="asm-popup-radius-field" class="asm-short" type="number" min="0" max="40" name="popup_radius" value="<?php echo (int) $popup['radius']; ?>">
                <label for="asm-popup-css-field">Własny CSS (opcjonalnie)</label>
                <textarea id="asm-popup-css-field" name="popup_css" rows="6" spellcheck="false" placeholder=".asm-popup__title { text-transform: uppercase; }"><?php echo esc_textarea($popup['custom_css']); ?></textarea>
                <p class="description">Klasy: .asm-popup__box, .asm-popup__title, .asm-popup__msg, .asm-popup__btn, .asm-popup__x.</p>
                <div class="asm-actions">
                    <?php submit_button('Zapisz popup', 'primary', 'submit', false); ?>
                    <a class="button" target="_blank" rel="noopener" href="<?php echo esc_url(Popup::previewUrl()); ?>">Podgląd zapisanego popupu na stronie</a>
                </div>
                <p class="description">Podgląd widzi tylko zalogowany administrator, także gdy popup jest wyłączony. Najpierw zapisz zmiany.</p>
            </section>
        </form>
        <div class="asm-grid asm-grid--wide">
            <section class="asm-surface">
                <h2>Biblioteka popupów</h2>
                <p>Zapisz obecny popup pod nazwą, żeby wrócić do niego później, na przykład co roku przed świętami.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="asm-copy-row">
                    <?php wp_nonce_field(self::NONCE); ?>
                    <input type="hidden" name="action" value="asm2_popup_library">
                    <input type="hidden" name="library_action" value="save">
                    <input name="library_name" required maxlength="80" aria-label="Nazwa popupu w bibliotece" placeholder="Nazwa, np. Promocja zimowa">
                    <button class="button" type="submit">Zapisz obecny popup</button>
                </form>
                <p class="description">Do biblioteki trafia ostatnio zapisana wersja popupu: treść, przycisk i wygląd.</p>
                <?php $this->libraryTable(Popup::library(), true); ?>
            </section>
            <section class="asm-surface">
                <h2>Popupy okazjonalne</h2>
                <p>Gotowa treść i wygląd na święta. Po wczytaniu możesz zmienić tekst i zapisać.</p>
                <?php
                $templates = array();
                foreach (Popup::templates() as $key => $template) {
                    $templates['tpl:' . $key] = $template;
                }
                $this->libraryTable($templates, false);
                ?>
            </section>
        </div>
        <?php
    }

    private function libraryTable(array $entries, bool $deletable): void
    {
        if (! $entries) {
            echo '<p class="description">Biblioteka jest pusta.</p>';
            return;
        }
        $labels = Popup::styles();
        echo '<table class="widefat asm-table"><thead><tr><th scope="col">Nazwa</th><th scope="col">Tytuł</th><th scope="col">Styl</th><th scope="col">Działania</th></tr></thead><tbody>';
        foreach ($entries as $id => $entry) {
            $settings = (array) ($entry['settings'] ?? array());
            $name = (string) ($entry['name'] ?? '');
            echo '<tr><td data-label="Nazwa">' . esc_html($name) . '</td><td data-label="Tytuł">' . esc_html((string) ($settings['title'] ?? '')) . '</td><td data-label="Styl">' . esc_html($labels[$settings['style'] ?? ''] ?? '') . '</td><td data-label="Działania"><div class="asm-row-actions">';
            foreach (array_filter(array('load' => 'Wczytaj', 'delete' => $deletable ? 'Usuń' : null)) as $operation => $label) {
                $question = $operation === 'load'
                    ? 'Wczytać „' . $name . '”? Zastąpi to treść i wygląd obecnego popupu.'
                    : 'Usunąć „' . $name . '” z biblioteki?';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-confirm="' . esc_attr($question) . '">';
                wp_nonce_field(self::NONCE);
                echo '<input type="hidden" name="action" value="asm2_popup_library"><input type="hidden" name="library_action" value="' . esc_attr($operation) . '"><input type="hidden" name="library_id" value="' . esc_attr((string) $id) . '">';
                echo '<button class="button button-small" type="submit" disabled data-needs-confirm>' . esc_html($label) . '</button></form>';
            }
            echo '</div></td></tr>';
        }
        echo '</tbody></table>';
    }

    private function operators(): void
    {
        $admins = get_users(array('role' => 'administrator'));
        ?>
        <section class="asm-surface" id="asm-operators">
            <h2>Operatorzy pełnego dostępu</h2>
            <p>Pełne zarządzanie stroną przez AI może zatwierdzić tylko administrator z nadanym pełnym dostępem. Dla starszego połączenia REST utwórz osobne hasło aplikacji w profilu tego administratora. Wtyczka nie odczytuje ani nie przechowuje jawnego hasła.</p>
            <table class="widefat asm-table">
                <thead><tr><th scope="col">Administrator</th><th scope="col">Pełny dostęp</th><th scope="col">Działania</th></tr></thead>
                <tbody>
                <?php foreach ($admins as $admin) :
                    $enabled = $admin->has_cap('asm_full_control'); ?>
                    <tr>
                        <td data-label="Administrator"><?php echo esc_html($admin->user_login); ?> (ID <?php echo (int) $admin->ID; ?>)</td>
                        <td data-label="Pełny dostęp"><span class="asm-chip <?php echo $enabled ? 'is-ok' : ''; ?>"><?php echo $enabled ? 'nadany' : 'brak'; ?></span></td>
                        <td data-label="Działania">
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php wp_nonce_field(self::NONCE); ?>
                                <input type="hidden" name="action" value="asm2_operator">
                                <input type="hidden" name="user_id" value="<?php echo (int) $admin->ID; ?>">
                                <input type="hidden" name="operator_action" value="<?php echo $enabled ? 'revoke' : 'grant'; ?>">
                                <?php submit_button($enabled ? 'Odbierz pełny dostęp' : 'Nadaj pełny dostęp', $enabled ? 'secondary' : 'primary', 'submit', false); ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
        <?php
    }

    private function system(): void
    {
        $release = Updater::release();
        $newer = $release && version_compare($release['version'], ASM_VERSION, '>');
        ?>
        <div class="asm-grid">
            <section class="asm-surface">
                <h2>Aktualizacje</h2>
                <dl>
                    <dt>Zainstalowana wersja</dt><dd><?php echo esc_html(ASM_VERSION); ?></dd>
                    <dt>Najnowsze wydanie</dt><dd><?php echo $release ? esc_html($release['version']) : 'Nie udało się odczytać wydań.'; ?></dd>
                    <dt>Źródło</dt><dd><code>github.com/<?php echo esc_html(Updater::repo()); ?></code></dd>
                </dl>
                <?php if ($newer) : ?>
                    <p><strong>Jest nowsza wersja.</strong> Zainstalujesz ją tak jak każdą aktualizację wtyczki.</p>
                <?php endif; ?>
                <div class="asm-actions">
                    <?php if ($newer) : ?>
                        <a class="button button-primary" href="<?php echo esc_url(admin_url('update-core.php')); ?>">Przejdź do aktualizacji</a>
                    <?php endif; ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field(self::NONCE); ?>
                        <input type="hidden" name="action" value="asm2_check_update">
                        <button class="button" type="submit">Sprawdź teraz</button>
                    </form>
                </div>
                <p class="description">WordPress sprawdza wydania sam, co kilka godzin. Aktualizacje automatyczne włączysz na <a href="<?php echo esc_url(admin_url('plugins.php')); ?>">liście wtyczek</a>.</p>
            </section>
            <section class="asm-surface">
                <h2>Stan systemu</h2>
                <dl>
                    <dt>Adres REST</dt><dd><code><?php echo esc_html(rest_url(ASM_REST_NAMESPACE)); ?></code></dd>
                    <dt>Prywatny magazyn kopii</dt><dd><?php echo $this->storageAvailable ? 'gotowy' : 'niedostępny, ustaw ASM_PRIVATE_STORAGE poza katalogiem publicznym'; ?></dd>
                    <dt>Starsze API v1</dt><dd>wyłączone<?php echo get_option('asm_key_hash', false) ? ', zachowano stary hash klucza' : ', brak starego klucza'; ?></dd>
                    <dt>PHP i WordPress</dt><dd><?php echo esc_html(PHP_VERSION . ', ' . get_bloginfo('version')); ?></dd>
                </dl>
                <?php if (get_option('asm_key_hash', false)) : ?>
                    <h3>Klucz starszej wersji</h3>
                    <p>Hash pozostawiono wyłącznie dla bezpiecznej migracji. API v1 nie jest rejestrowane.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field(self::NONCE); ?>
                        <input type="hidden" name="action" value="asm2_revoke_legacy">
                        <?php submit_button('Usuń stary hash klucza', 'delete', 'submit', false); ?>
                    </form>
                <?php endif; ?>
            </section>
        </div>
        <?php
    }

    private function audit(): void
    {
        $events = $this->audit->recent(30);
        ?>
        <section class="asm-surface">
            <h2>Zdarzenia natywnego API</h2>
            <p>Ostatnie 30 zdarzeń połączeń przez hasła aplikacji i silnik operacji.</p>
            <table class="widefat asm-table">
                <thead><tr><th scope="col">Kiedy</th><th scope="col">Operacja</th><th scope="col">Zdarzenie</th><th scope="col">Operator</th><th scope="col">Metadane</th></tr></thead>
                <tbody>
                <?php if ($events === array()) :
                    ?><tr><td colspan="5">Brak zdarzeń.</td></tr><?php
                endif; ?>
                <?php foreach ($events as $event) : ?>
                    <tr><td data-label="Kiedy"><?php echo esc_html(self::humanDate(strtotime($event['created_at'] . ' UTC'))); ?></td><td data-label="Operacja"><code><?php echo esc_html($event['operation_id']); ?></code></td><td data-label="Zdarzenie"><?php echo esc_html($event['event_name']); ?></td><td data-label="Operator"><?php echo (int) $event['actor_id']; ?></td><td data-label="Metadane"><code><?php echo esc_html(wp_json_encode($event['metadata'])); ?></code></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
        <?php
    }

    private static function humanDate(int $timestamp): string
    {
        $months = array(1 => 'stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia');
        return wp_date('j', $timestamp) . ' ' . $months[(int) wp_date('n', $timestamp)] . ' ' . wp_date('Y H:i', $timestamp);
    }

    private function guard(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Brak uprawnień.');
        }
        check_admin_referer(self::NONCE);
    }

    private function redirect(string $message, string $tab): void
    {
        wp_safe_redirect(add_query_arg(
            array('page' => self::SLUG, 'tab' => $tab, 'asm_message' => $message),
            admin_url('admin.php')
        ));
        exit;
    }
}
