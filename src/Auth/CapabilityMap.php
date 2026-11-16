<?php

declare(strict_types=1);

namespace ASM\Auth;

final class CapabilityMap
{
    private const ACTIONS = array(
        'site.read' => array('read'),
        'inventory.read' => array('manage_options', 'asm_full_control'),
        'search.read' => array('manage_options', 'asm_full_control'),
        'content.read' => array('read'),
        'content.write' => array('edit_posts'),
        'content.publish' => array('publish_posts'),
        'content.delete' => array('delete_posts'),
        'media.read' => array('upload_files'),
        'media.write' => array('upload_files'),
        'media.delete' => array('delete_posts'),
        'menu.read' => array('edit_theme_options'),
        'menu.write' => array('edit_theme_options'),
        'options.read' => array('manage_options'),
        'options.write' => array('manage_options'),
        'users.read' => array('list_users'),
        'users.write' => array('edit_users'),
        'users.delete' => array('delete_users'),
        'comments.read' => array('moderate_comments'),
        'comments.write' => array('moderate_comments'),
        'cron.read' => array('manage_options'),
        'cron.write' => array('manage_options', 'asm_full_control'),
        'plugins.read' => array('activate_plugins'),
        'plugins.install' => array('install_plugins'),
        'plugins.update' => array('update_plugins'),
        'plugins.activate' => array('activate_plugins'),
        'plugins.delete' => array('delete_plugins'),
        'themes.read' => array('switch_themes'),
        'themes.install' => array('install_themes'),
        'themes.update' => array('update_themes'),
        'themes.activate' => array('switch_themes'),
        'themes.delete' => array('delete_themes'),
        'core.update' => array('update_core'),
        'cache.read' => array('manage_options'),
        'cache.purge' => array('manage_options'),
        'file.read' => array('manage_options', 'asm_full_control'),
        'file.write' => array('manage_options', 'asm_full_control'),
        'file.delete' => array('manage_options', 'asm_full_control'),
        'database.read' => array('manage_options', 'asm_full_control'),
        'database.execute' => array('manage_options', 'asm_full_control'),
        'operation.read' => array('manage_options', 'asm_full_control'),
        'operation.execute' => array('manage_options', 'asm_full_control'),
        'operation.rollback' => array('manage_options', 'asm_full_control'),
        'logs.read' => array('manage_options', 'asm_full_control'),
        'popup.read' => array('manage_options'),
        'popup.write' => array('manage_options'),
    );

    public static function forAction(string $action): array
    {
        return self::ACTIONS[$action] ?? array();
    }
}
