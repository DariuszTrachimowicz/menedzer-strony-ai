<?php
// Uruchom: php -d zend.assertions=1 tests/content/test-meta-redaction.php
declare(strict_types=1);
if (ini_get('zend.assertions') !== '1') { fwrite(STDERR, "Uruchom z -d zend.assertions=1.\n"); exit(1); }
define('ASM_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
require ASM_PLUGIN_DIR . 'src/Operations/OperationFailure.php';
require ASM_PLUGIN_DIR . 'src/Resources/ContentResource.php';
use ASM\Resources\ContentResource as C;

// Fraza kluczowa SEO jest jawna.
foreach (array('_dxsm_seo_keyphrase', 'rank_math_focus_keyword', '_yoast_wpseo_focuskeywords') as $k) {
    assert(C::isRedactedMetaKey($k) === false, $k);
}
// Pozostałe pola SEO bez „key” jak dotąd jawne.
assert(C::isRedactedMetaKey('_dxsm_seo_title') === false);
assert(C::isRedactedMetaKey('_dxsm_seo_description') === false);
// Sekrety nadal ukryte, także podobne nazwy.
foreach (array('api_key', '_stripe_secret', 'user_token', 'auth_key', 'session_tokens', 'wp_password', '_dxsm_seo_keyphrase_secret', 'DXSM_SEO_KEYPHRASE', 'my_keyphrase', 'license_key') as $k) {
    assert(C::isRedactedMetaKey($k) === true, $k);
}
// meta_keys w list(): dokładne nazwy, bez śmieci, limit 20.
$m = new ReflectionMethod(C::class, 'listMetaKeys'); $m->setAccessible(true); $c = new C();
assert($m->invoke($c, array('_dxsm_seo_title', '_dxsm_seo_title', ' _dxsm_seo_keyphrase ')) === array('_dxsm_seo_title', '_dxsm_seo_keyphrase'));
assert($m->invoke($c, '_dxsm_seo_title,_cp_social_image') === array('_dxsm_seo_title', '_cp_social_image'));
assert($m->invoke($c, array('a b', "x'; DROP", '%', '')) === array());
assert($m->invoke($c, 5) === array());
assert(count($m->invoke($c, array_map(fn($i) => "k$i", range(1, 40)))) === 20);
echo "OK\n";
