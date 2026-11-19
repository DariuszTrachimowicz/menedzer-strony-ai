<?php

/**
 * Menedżer Strony AI zachowuje audyt i kopie po odinstalowaniu.
 * Usunięcie tych danych wymaga osobnej, świadomej procedury retencji.
 * Dostęp AI kończy się jednak od razu: ponowna instalacja nie wskrzesza starych połączeń ani uprawnień.
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Nowy identyfikator instalacji zmienia wiązanie, więc stare tokeny przestają działać. Nie usuwamy go:
// po ponownej instalacji Connections::install() może się nie uruchomić (zostaje asm_mcp_schema_version),
// a bez identyfikatora nowe logowanie OAuth kończyłoby się invalid_grant.
update_option('asm_mcp_install_id', wp_generate_uuid4(), false);
$connections = $wpdb->prefix . 'asm_mcp_connections';
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $connections)) === $connections) {
    $wpdb->query($wpdb->prepare("UPDATE {$connections} SET revoked = 1, revoked_at = %d WHERE revoked = 0", time()));
}
$credentials = $wpdb->prefix . 'asm_mcp_oauth_credentials';
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $credentials)) === $credentials) {
    $wpdb->query("DELETE FROM {$credentials}");
}

// Uprawnienie jest nadawane pojedynczym kontom, więc zdejmujemy je każdemu, nie tylko roli.
foreach (get_users(array('capability' => 'asm_full_control', 'fields' => 'all')) as $user) {
    $user->remove_cap('asm_full_control');
}
$role = get_role('administrator');
if (is_object($role) && method_exists($role, 'remove_cap')) {
    $role->remove_cap('asm_full_control');
}

update_option('asm_uninstalled_at', gmdate('c'), false);
