<?php
declare(strict_types=1);
namespace ASM\MCP;

final class Module {
    private static bool $booted=false;
    private const SCHEMA_VERSION='2.2.0-full';
    public static function ready(): bool { return self::$booted; }
    public static function boot(): void {
        if (self::$booted) { return; }
        // Wszystkie aktywne wtyczki są już załadowane, niezależnie od ich kolejności.
        self::removeLegacyHooks();
        global $wpdb,$wp_filter;
        // Zachowujemy natywne hooki, ale nie przywracamy wyłączonych dispatcherów legacy.
        $hooks=[];
        foreach ($wp_filter as $name=>$hook) { $hooks[$name]=clone $hook; }
        $suppressed=$wpdb->suppress_errors(true);
        try {
            $install=get_option('asm_mcp_schema_version','')!==self::SCHEMA_VERSION;
            if ($install) {
                Connections::install();
                OAuthClients::install();
                OAuthGrant::install();
                OAuthConsent::install();
            }
            add_action('rest_api_init',[Endpoint::class,'register']);
            add_action('init',[self::class,'schedule']);
            add_action('asm_mcp_cleanup',[Connections::class,'cleanup']);
            add_action('admin_init',[self::class,'redirectLegacy'],0);
            Admin::hooks();
            OAuthController::boot();
            if ($install && !update_option('asm_mcp_schema_version',self::SCHEMA_VERSION,false)) {
                throw new \RuntimeException('schema_state');
            }
            self::$booted=true;
        } catch (\Throwable $error) {
            $wp_filter=$hooks;
            add_action('admin_notices',[self::class,'initializationNotice']);
            return;
        } finally {
            $wpdb->suppress_errors($suppressed);
        }
        // WordPress sprawdza prawo do nieistniejącej strony menu przed admin_init.
        // Przekierowanie zalogowanego administratora musi nastąpić przed tą bramką.
        if (is_admin() && current_user_can('manage_options')) { self::redirectLegacy(); }
    }
    public static function initializationNotice(): void {
        if (current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>Połączenia AI są niedostępne: inicjalizacja modułu nie powiodła się. Natywne funkcje Menedżera Strony AI pozostają dostępne. Skontaktuj się z administratorem utrzymującym wtyczkę.</p></div>';
        }
    }
    private static function removeLegacyHooks(): void {
        global $wp_filter;
        $legacyRoot=defined('ASMMCP_DIR') ? realpath(ASMMCP_DIR) : false;
        foreach (['rest_api_init','init','asm_mcp_cleanup','admin_init','admin_menu','admin_enqueue_scripts','admin_post_asm_mcp_revoke','admin_post_asm_mcp_help','wp_ajax_asm_mcp_status'] as $hook) {
            if (!isset($wp_filter[$hook])) { continue; }
            foreach ($wp_filter[$hook]->callbacks as $priority=>$callbacks) {
                foreach ($callbacks as $entry) {
                    $callback=$entry['function'];
                    $legacy=is_array($callback) && is_string($callback[0]) && str_starts_with($callback[0],'ASMMCP\\');
                    if ($callback instanceof \Closure && $legacyRoot) {
                        $source=(new \ReflectionFunction($callback))->getFileName();
                        $legacy=$source && str_starts_with((string)realpath($source),$legacyRoot.DIRECTORY_SEPARATOR);
                    }
                    if ($legacy) { remove_action($hook,$callback,$priority); }
                }
            }
        }
    }
    public static function schedule(): void {
        // Stara dezaktywacja kasuje wspólne zdarzenie. Następne żądanie je odtwarza.
        if (!wp_next_scheduled('asm_mcp_cleanup')) { wp_schedule_event(time()+3600,'daily','asm_mcp_cleanup'); }
    }
    public static function redirectLegacy(): void {
        if (($_GET['page']??'')!=='asm-mcp-connect') { return; }
        wp_safe_redirect(admin_url('admin.php?page=ai-site-manager&tab=connections'),302);
        exit;
    }
}
