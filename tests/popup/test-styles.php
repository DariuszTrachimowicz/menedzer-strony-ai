<?php
// Uruchom: php -d zend.assertions=1 tests/popup/test-styles.php
declare(strict_types=1);
if (ini_get('zend.assertions') !== '1') { fwrite(STDERR, "Uruchom z -d zend.assertions=1.\n"); exit(1); }
$GLOBALS['options'] = array();
function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['options'][$k] = $v; return true; }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function wp_kses_post($v) { return (string) $v; }
function esc_url_raw($v, $p = null) { return (string) $v; }
function current_user_can($c) { return false; }
require __DIR__ . '/../../src/Popup/Popup.php';
use ASM\Popup\Popup;

// Trzy style podstawowe, 10 gotowych i 7 okazji.
$styles = Popup::styles();
assert(count($styles) === 20 && isset($styles['black-friday'], $styles['walentynki'], $styles['mikolajki']) && isset($styles['auto'], $styles['classic'], $styles['custom'], $styles['glass'], $styles['niepodleglosc']));
assert(count(array_filter(Popup::swatches(), static fn($s) => $s['group'] === 'styl')) === 10);
assert(count(array_filter(Popup::swatches(), static fn($s) => $s['group'] === 'okazja')) === 7);
assert(count(Popup::templates()) === 7);

// Zapisane ustawienia bez pola style zostają przy wyglądzie klasycznym, nieznany styl też.
$GLOBALS['options']['asm_popup'] = array('enabled' => true, 'title' => 'T', 'message' => 'M');
assert(Popup::settings()['style'] === 'classic');
assert(Popup::save(array('style' => 'nie-ma-takiego', 'message' => 'M'))['style'] === 'classic');

// Gotowy styl wypisuje swoje zmienne CSS, a znaki specjalne są zakodowane w atrybucie.
$markup = Popup::renderMarkup(array('style' => 'elegant', 'title' => 'A', 'message' => 'B', 'button_text' => 'C'));
assert(str_contains($markup, 'asm-popup--elegant') && str_contains($markup, '--asm-popup-accent:#c8a45c') && str_contains($markup, '--asm-popup-heading-font:Georgia,&quot;Times New Roman&quot;,serif'));
assert(str_contains(Popup::renderMarkup(array('style' => 'boze-narodzenie', 'message' => 'x')), '--asm-popup-deco:&quot;'));

// Szablon okazji ma treść i styl o tej samej nazwie.
foreach (Popup::templates() as $key => $template) {
    assert(isset($styles[$template['settings']['style']]) && $template['settings']['title'] !== '' && str_contains($template['settings']['message'], "\n\n"), $key);
    assert(Popup::recall('tpl:' . $key) === $template['settings']);
}

// Biblioteka: zapis bez pola enabled, odczyt, usunięcie, pusta nazwa odrzucona.
assert(Popup::remember('', array('message' => 'x')) === false);
assert(Popup::remember('Zima <b>2026</b>', array('enabled' => true, 'title' => 'Zima', 'message' => 'Tekst', 'style' => 'dark')) === true);
$library = Popup::library();
$id = array_key_first($library);
assert(count($library) === 1 && $library[$id]['name'] === 'Zima 2026' && ! array_key_exists('enabled', $library[$id]['settings']));
assert(Popup::recall($id)['style'] === 'dark' && Popup::recall('brak') === null);
assert(Popup::forget($id) === true && Popup::library() === array() && Popup::forget($id) === false);
echo "OK\n";
