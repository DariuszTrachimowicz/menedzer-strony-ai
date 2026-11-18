<?php

/**
 * Plugin Name:       Menedżer Strony AI
 * Plugin URI:        https://d-x.pl/wtyczki/menedzer-strony-ai/
 * Description:       Połączenia AI przez MCP i OAuth oraz zarządzanie WordPressem z podglądem zmian i cofaniem z kopii.
 * Version:           2.7.1
 * Requires at least: 7.1
 * Requires PHP:      8.2
 * Author:            Dariusz Trachimowicz
 * Author URI:        https://d-x.pl/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://d-x.pl/wtyczki/menedzer-strony-ai/
 * Text Domain:       ai-site-manager
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('ASM_VERSION', '2.7.1');
define('ASM_PLUGIN_FILE', __FILE__);
define('ASM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ASM_REST_NAMESPACE', 'asm/v2');

require_once ASM_PLUGIN_DIR . 'src/Autoloader.php';

ASM\Autoloader::register(ASM_PLUGIN_DIR . 'src');

register_activation_hook(ASM_PLUGIN_FILE, array(ASM\Installer::class, 'activate'));
add_action('plugins_loaded', array(ASM\Plugin::class, 'boot'));

if (PHP_VERSION_ID >= 80200) {
    add_action('plugins_loaded', array(ASM\MCP\Module::class, 'boot'), 20);
} else {
    add_action('admin_notices', static function () {
        if (current_user_can('manage_options')) {
            echo '<div class="notice notice-warning"><p>Połączenia AI wymagają PHP 8.2 lub nowszego. Natywne funkcje Menedżera Strony AI pozostają dostępne.</p></div>';
        }
    });
}
