<?php
// Uruchom: php -d zend.assertions=1 tests/oauth-discovery/test-discovery-mode.php
// Zakres: wybór trybu i treść dokumentów. Trasy REST i nagłówek 401 sprawdza się na działającym WordPressie.
declare(strict_types=1);
namespace ASM\MCP {
    final class Policy { public const SCOPES=['publicread'=>'Publiczny odczyt','full'=>'Pełne zarządzanie']; }
    final class OAuthClients { public static function transportAvailable(): bool { return true; } }
}
namespace {
    if (ini_get('zend.assertions')!=='1') { fwrite(STDERR,"Asercje są wyłączone. Uruchom z -d zend.assertions=1.\n"); exit(1); }
    const ASM_VERSION='2.5.1';
    $GLOBALS['options']=[]; $GLOBALS['root']='redirect'; $GLOBALS['rest']=true; $GLOBALS['plain']=false;
    function get_option($k,$d=false) { return $GLOBALS['options'][$k]??$d; }
    function update_option($k,$v,$a=null) { $GLOBALS['options'][$k]=$v; return true; }
    function home_url() { return 'https://example.test'; }
    function untrailingslashit($s) { return rtrim($s,'/'); }
    function rest_url($p='') { return $GLOBALS['plain']?'https://example.test/?rest_route=/'.$p:'https://example.test/wp-json/'.$p; }
    function admin_url($p='') { return 'https://example.test/wp-admin/'.$p; }
    function wp_parse_url($u,$c=-1) { return parse_url($u,$c); }
    function wp_get_environment_type() { return 'production'; }
    function is_wp_error($v) { return false; }
    // Atrapa hosta: dokumenty buduje ta sama klasa w bieżącym trybie, tak jak prawdziwy WordPress.
    function wp_safe_remote_get($url,$args) {
        $D=\ASM\MCP\OAuthDiscovery::class;
        $json=static fn(array $d)=>['status'=>200,'type'=>'application/json','body'=>json_encode($d)];
        $path=parse_url($url,PHP_URL_PATH);
        if (str_starts_with($path,'/.well-known/')) {
            if ($GLOBALS['root']!=='ok') { return ['status'=>['redirect'=>302,'missing'=>404,'error'=>500,'forbidden'=>403,'silent'=>0][$GLOBALS['root']],'type'=>'text/html','body'=>'']; }
            return $json(str_contains($path,'protected-resource')?$D::resourceMetadata():$D::server());
        }
        if (!$GLOBALS['rest']) { return ['status'=>404,'type'=>'application/json','body'=>'{}']; }
        return $json(str_ends_with($path,'/oauth/resource')?$D::resourceMetadata():$D::openid());
    }
    function wp_remote_retrieve_response_code($r) { return $r['status']; }
    function wp_remote_retrieve_header($r,$h) { return $r['type']; }
    function wp_remote_retrieve_body($r) { return $r['body']; }
    require __DIR__.'/../../src/MCP/OAuthDiscovery.php';
    $D=\ASM\MCP\OAuthDiscovery::class;
    $reset=static function(string $mode) { $GLOBALS['options']['asm_mcp_discovery']=['mode'=>$mode,'version'=>'2.5.1']; };

    // Bez zapisu stanu zachowanie jest takie jak w 2.2.0.
    assert($D::mode()==='root' && $D::issuer()==='https://example.test');
    assert($D::resourceMetadataUrl()==='https://example.test/.well-known/oauth-protected-resource');

    // Hosting oddaje /.well-known WordPressowi: zostaje tryb root.
    $GLOBALS['root']='ok'; $D::detect();
    assert($D::mode()==='root' && $GLOBALS['options']['asm_mcp_discovery']['version']==='2.5.1');

    // Błąd 5xx, 403 i brak odpowiedzi nie przełączają działającej strony.
    foreach (['error','forbidden','silent'] as $state) { $GLOBALS['root']=$state; $D::detect(); assert($D::mode()==='root',$state); }

    // Przekierowanie bez działających dokumentów REST też nie przełącza.
    $GLOBALS['root']='redirect'; $GLOBALS['rest']=false; $D::detect();
    assert($D::mode()==='root');

    // Hosting przekierowuje /.well-known albo zwraca 404, a dokumenty REST działają: tryb path.
    foreach (['redirect','missing'] as $state) {
        $reset('root'); $GLOBALS['root']=$state; $GLOBALS['rest']=true; $checks=$D::detect();
        assert($D::mode()==='path',$state);
        assert($checks[3]['valid'] && $checks[4]['valid'] && !$checks[0]['valid']);
    }
    assert($D::issuer()==='https://example.test/wp-json/asm-mcp/v1');
    assert($D::resourceMetadataUrl()==='https://example.test/wp-json/asm-mcp/v1/oauth/resource');
    assert($D::resourceMetadata()['authorization_servers']===[$D::issuer()] && $D::server()['issuer']===$D::issuer());
    foreach (['issuer','authorization_endpoint','token_endpoint','jwks_uri','response_types_supported','subject_types_supported','id_token_signing_alg_values_supported','registration_endpoint','code_challenge_methods_supported'] as $field) { assert(isset($D::openid()[$field]),$field); }

    // W trybie path brak odpowiedzi nie zmienia trybu.
    $GLOBALS['root']='silent'; $D::detect();
    assert($D::mode()==='path');

    // Adresy REST z rest_route nie nadają się na issuer ze ścieżką.
    $GLOBALS['plain']=true; assert($D::mode()==='root'); $GLOBALS['plain']=false;

    // Naprawiony hosting: samo wejście do panelu nie zmienia issuera, robi to ręczne sprawdzenie.
    $GLOBALS['root']='ok'; $D::detect();
    assert($D::mode()==='path');
    $D::detect(true);
    assert($D::mode()==='root' && $D::issuer()==='https://example.test');
    echo "OK\n";
}
