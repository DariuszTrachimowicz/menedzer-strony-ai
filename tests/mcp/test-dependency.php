<?php
// Uruchom: php -d zend.assertions=1 tests/mcp/test-dependency.php
// Pilnuje, żeby podbicie wersji nie wyłączyło zapisów przez MCP.
declare(strict_types=1);
if (ini_get('zend.assertions') !== '1') { fwrite(STDERR, "Uruchom z -d zend.assertions=1.\n"); exit(1); }
preg_match("/define\('ASM_VERSION', '([\d.]+)'\)/", (string) file_get_contents(__DIR__ . '/../../ai-site-manager.php'), $m);
define('ASM_VERSION', $m[1]);
require __DIR__ . '/../../src/MCP/Operations.php';
assert(ASM\MCP\Operations::dependency() === true, 'wersja ' . ASM_VERSION);
echo "OK\n";
