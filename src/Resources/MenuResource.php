<?php

declare(strict_types=1);

namespace ASM\Resources;

use ASM\Operations\OperationFailure;

final class MenuResource
{
    private string $mode;

    public function __construct(string $mode = 'update')
    {
        $this->mode = $mode;
    }

    public function list(array $params = array()): array
    {
        $menus = array();
        foreach (wp_get_nav_menus() as $menu) {
            $menus[] = array(
                'id' => (int) $menu->term_id,
                'name' => (string) $menu->name,
                'slug' => (string) $menu->slug,
                'count' => (int) $menu->count,
                'items' => $this->items((int) $menu->term_id),
            );
        }
        return $menus;
    }

    public function get(string $id)
    {
        $menu = wp_get_nav_menu_object((int) $id);
        if (! $menu) {
            return new \WP_Error('asm_resource_not_found', 'Nie znaleziono menu.', array('status' => 404));
        }
        return array(
            'id' => (int) $menu->term_id,
            'name' => (string) $menu->name,
            'slug' => (string) $menu->slug,
            'items' => $this->items((int) $menu->term_id),
        );
    }

    public function snapshot(array $target): array
    {
        $itemId = (int) ($target['id'] ?? 0);
        $post = get_post($itemId);
        if ($this->mode === 'create' && (! $post || $post->post_type !== 'nav_menu_item')) {
            return array('exists' => false);
        }
        if (! $post || $post->post_type !== 'nav_menu_item') {
            throw new OperationFailure('asm_resource_not_found', 'Nie znaleziono pozycji menu.');
        }
        return array(
            'exists' => true,
            'post' => get_object_vars($post),
            'meta' => get_post_meta($itemId),
            'terms' => wp_get_object_terms($itemId, 'nav_menu', array('fields' => 'ids')),
        );
    }

    public function apply(array $target, array $change)
    {
        $itemId = (int) ($target['id'] ?? 0);
        if ($this->mode === 'delete') {
            if (! wp_delete_post($itemId, true)) {
                throw new OperationFailure('asm_menu_delete_failed', 'Nie udało się usunąć pozycji menu.');
            }
            return;
        }
        $menuId = (int) ($target['menu_id'] ?? $change['menu_id'] ?? 0);
        $args = array_intersect_key($change, array_flip(array(
            'menu-item-db-id', 'menu-item-object-id', 'menu-item-object', 'menu-item-parent-id',
            'menu-item-position', 'menu-item-type', 'menu-item-title', 'menu-item-url',
            'menu-item-description', 'menu-item-attr-title', 'menu-item-target',
            'menu-item-classes', 'menu-item-xfn', 'menu-item-status',
        )));
        $result = wp_update_nav_menu_item($menuId, $this->mode === 'create' ? 0 : $itemId, wp_slash($args));
        if (is_wp_error($result)) {
            throw new OperationFailure($result->get_error_code(), $result->get_error_message());
        }
        return $this->mode === 'create' ? array('target' => array('id' => (int) $result)) : null;
    }

    public function verify(array $target, array $change): ?bool
    {
        $post = get_post((int) ($target['id'] ?? 0));
        if ($this->mode === 'create') {
            return $post !== null && $post->post_type === 'nav_menu_item';
        }
        if ($this->mode === 'delete') {
            return $post === null;
        }
        if (! $post) {
            return false;
        }
        if (isset($change['menu-item-title']) && (string) $post->post_title !== (string) $change['menu-item-title']) {
            return false;
        }
        return true;
    }

    public function rollback(array $target, array $snapshot): void
    {
        if (empty($snapshot['exists'])) {
            $itemId = (int) ($target['id'] ?? 0);
            if ($itemId > 0) {
                wp_delete_post($itemId, true);
            }
            return;
        }
        $post = (array) $snapshot['post'];
        $post['ID'] = (int) $post['ID'];
        $itemId = (int) $post['ID'];
        global $wpdb;
        if (get_post($itemId) === null) {
            $columns = array_map('strval', (array) $wpdb->get_col("DESCRIBE {$wpdb->posts}", 0));
            $record = array_intersect_key($post, array_flip($columns));
            if ($wpdb->insert($wpdb->posts, $record) === false) {
                throw new OperationFailure('asm_menu_rollback_failed', 'Nie udało się odtworzyć pozycji menu.');
            }
            clean_post_cache($itemId);
        } else {
            $result = wp_update_post(wp_slash($post), true);
            if (is_wp_error($result)) {
                throw new OperationFailure('asm_menu_rollback_failed', $result->get_error_message());
            }
        }
        $wpdb->update(
            $wpdb->posts,
            array(
                'post_modified' => (string) $post['post_modified'],
                'post_modified_gmt' => (string) $post['post_modified_gmt'],
            ),
            array('ID' => $itemId),
            array('%s', '%s'),
            array('%d')
        );
        foreach (array_keys(get_post_meta($itemId)) as $key) {
            delete_post_meta($itemId, (string) $key);
        }
        foreach ((array) $snapshot['meta'] as $key => $values) {
            foreach ((array) $values as $value) {
                add_post_meta($itemId, (string) $key, wp_slash(maybe_unserialize($value)));
            }
        }
        wp_set_object_terms($itemId, array_map('intval', (array) $snapshot['terms']), 'nav_menu', false);
        clean_post_cache($itemId);
    }

    private function items(int $menuId): array
    {
        $items = array();
        foreach ((array) wp_get_nav_menu_items($menuId) as $item) {
            $items[] = array(
                'id' => (int) $item->ID,
                'title' => (string) $item->title,
                'url' => (string) $item->url,
                'parent' => (int) $item->menu_item_parent,
                'order' => (int) $item->menu_order,
                'type' => (string) $item->type,
                'object' => (string) $item->object,
                'object_id' => (int) $item->object_id,
            );
        }
        return $items;
    }
}
