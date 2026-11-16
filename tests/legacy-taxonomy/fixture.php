<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Autoloader.php';
ASM\Autoloader::register(dirname(__DIR__, 2) . '/src');

function taxonomyRegistry(object $database): ASM\Operations\OperationRegistry
{
    return (new ReflectionMethod(ASM\Plugin::class, 'operationRegistry'))->invoke(
        null,
        null,
        null,
        new ASM\Filesystem\PathPolicy([__DIR__]),
        new ASM\Database\QueryPolicy(),
        $database
    );
}

$terms = $assigned = [];
$cap = true;
$term_race = false;
$id = 100;

class WP_Term
{
    public function __construct(
        public int $term_id, public string $name, public string $slug, public string $description, public int $parent = 0
    ) {}
}
function check(bool $condition, string $message): void { if (!$condition) { throw new \RuntimeException($message); } }
function fails(callable $fn, string $expected): void
{
    try {
        $fn();
    } catch (ASM\Operations\OperationFailure $e) {
        check($e->operationCode() === $expected, $e->operationCode());
        return;
    }
    throw new \RuntimeException('Expected ' . $expected);
}
function current_user_can(string $capability, int ...$objectIds): bool
{
    if ($capability === 'asm_full_control') {
        return $GLOBALS['fullControl'] ?? false;
    }
    return $GLOBALS['cap'] && in_array($capability, ['read', 'manage_options', 'manage_categories', 'edit_posts', 'edit_post'], true);
}
function sanitize_text_field(string $s): string { return strip_tags($s); }
function sanitize_textarea_field(string $s): string { return strip_tags($s); }
function wp_slash(mixed $v): mixed { return $v; }
function is_wp_error(mixed $v): bool { return false; }
function add_action(string $name, callable $fn, int $priority, int $args): void { $GLOBALS['creation_hook'] = $fn; }
function remove_action(string $name, callable $fn, int $priority): void { unset($GLOBALS['creation_hook']); }
function get_term_by(string $field, string $value, string $taxonomy): WP_Term|false
{
    foreach ($GLOBALS['terms'][$taxonomy] ?? [] as $term) {
        if ($term->$field === $value) { return $term; }
    }
    return false;
}
function wp_insert_term(string $name, string $taxonomy, array $fields): array
{
    if ($GLOBALS['term_race']) { return ['term_id' => get_term_by('slug', $fields['slug'], $taxonomy)->term_id]; }
    $id = ++$GLOBALS['id'];
    $GLOBALS['terms'][$taxonomy][$id] = new WP_Term($id, $name, $fields['slug'], $fields['description']);
    ($GLOBALS['creation_hook'])($id, $id, $taxonomy);
    return ['term_id' => $id];
}
function get_term(int $id, string $taxonomy): ?WP_Term { return $GLOBALS['terms'][$taxonomy][$id] ?? null; }
function get_terms(array $args): array { return []; }
function get_option(string $option): int { return 1; }
function get_objects_in_term(int $id, string $taxonomy): array { return $GLOBALS['assigned']; }
function get_term_meta(int $id): array { return []; }
function wp_get_associated_nav_menu_items(int $id, string $type, string $taxonomy): array { return []; }
function wp_delete_term(int $id, string $taxonomy): bool { unset($GLOBALS['terms'][$taxonomy][$id]); return true; }
