<?php
// Uruchom w lokalnym WordPressie z zainstalowanym Menedżerem: wp eval-file plugin-update-keeps-active.php <wtyczka/plik.php>
// Oczekiwane: wtyczka zaktualizowana i nadal włączona; bez nowszej wersji błąd asm_plugin_no_update. Kod wyjścia 1 przy porażce.
// Pusty transient update_plugins odtwarza stan po cache.purge (18.11.2026, rs2.pl).
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$id = $args[0];
delete_site_transient('update_plugins');
$storage = (new ReflectionClass('ASM\\Storage\\PrivateStorage'))->newInstanceWithoutConstructor();
$resource = new ASM\Resources\ComponentResource('plugin', 'update', $storage);
$before = get_plugins()[$id]['Version'];
$wasActive = is_plugin_active($id);
$error = '';
try {
    $resource->apply(array('id' => $id), array());
} catch (Throwable $e) {
    $error = $e->getMessage();
}
wp_clean_plugins_cache();
wp_cache_flush();
$after = get_plugins()[$id]['Version'] ?? '';
$isActive = in_array($id, (array) get_option('active_plugins'), true);
echo "przed: $before (" . ($wasActive ? 'włączona' : 'wyłączona') . "), po: $after (" . ($isActive ? 'włączona' : 'wyłączona') . ")" . ($error !== '' ? ", błąd: $error" : '') . "\n";
$failed = ($wasActive && ! $isActive)
    || ($error === '' && version_compare($after, $before, '<='))
    || ($error !== '' && strpos($error, 'nie widzi nowszej wersji') === false);
echo $failed ? "FAIL\n" : "OK\n";
exit($failed ? 1 : 0);
