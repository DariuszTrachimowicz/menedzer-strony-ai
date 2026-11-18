<?php
// Uruchom: php -d zend.assertions=1 tests/updater/test-priority.php
declare(strict_types=1);
if (ini_get('zend.assertions') !== '1') { fwrite(STDERR, "Uruchom z -d zend.assertions=1.\n"); exit(1); }
define('ASM_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
$GLOBALS['hooks'] = array();
function add_filter($hook, $cb, $prio = 10, $args = 1) { $GLOBALS['hooks'][$hook] = $prio; }
require ASM_PLUGIN_DIR . 'src/Updater.php';
ASM\Updater::hooks();
// CMS Portal na priorytecie 20 zwraca false dla obcych slugów: nasz filtr musi działać po nim.
assert(($GLOBALS['hooks']['plugins_api'] ?? 0) > 20, 'plugins_api priority');
// Okno szczegółów: WordPress 7.1.5 ostrzega, gdy version_compare(wersja, tested, '<=') jest fałszywe.
$src = (string) file_get_contents(ASM_PLUGIN_DIR . 'src/Updater.php');
assert(preg_match("~'tested' => '([0-9.]+)'~", $src, $m) === 1 && version_compare('7.1.5', $m[1], '<='), 'tested >= 7.1.5');
echo "OK\n";
