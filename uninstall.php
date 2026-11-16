<?php

/**
 * Menedżer Strony AI zachowuje audyt i kopie po odinstalowaniu.
 * Usunięcie tych danych wymaga osobnej, świadomej procedury retencji.
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$role = get_role('administrator');
if (is_object($role) && method_exists($role, 'remove_cap')) {
    $role->remove_cap('asm_full_control');
}

update_option('asm_uninstalled_at', gmdate('c'), false);
