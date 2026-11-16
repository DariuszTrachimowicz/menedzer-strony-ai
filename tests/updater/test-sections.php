<?php
// Uruchom: php -d zend.assertions=1 tests/updater/test-sections.php
declare(strict_types=1);
if (ini_get('zend.assertions') !== '1') { fwrite(STDERR, "Uruchom z -d zend.assertions=1.\n"); exit(1); }
define('ASM_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
require ASM_PLUGIN_DIR . 'src/Updater.php';
$s = ASM\Updater::sections();
foreach (array('description', 'installation', 'faq', 'other_notes', 'changelog') as $key) { assert(isset($s[$key]) && $s[$key] !== '', $key); }
assert(str_contains($s['description'], '<h4>Co zyskujesz</h4>') && str_contains($s['description'], '<ul><li>'));
assert(str_contains($s['installation'], '<ol><li>') && str_contains($s['installation'], '<code>wp-config.php</code>'));
assert(str_contains($s['description'], '<a href="https://d-x.pl/wtyczki/menedzer-strony-ai/">'));
assert(substr_count($s['description'], '<ul>') === substr_count($s['description'], '</ul>'));
assert(! str_contains(implode('', $s), '<script'));
echo "OK\n";
