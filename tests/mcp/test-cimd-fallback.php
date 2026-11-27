<?php
// Uruchom: php -d zend.assertions=1 tests/mcp/test-cimd-fallback.php
// Chwilowy brak dostępu do claude.ai nie może odrzucać klienta Claude przy wydanym dostępie: kopia dokumentu albo 503.
// Nowa zgoda kopii nie używa, a dokument odrzucony albo wycofany usuwa kopię.
declare(strict_types=1);
namespace ASM\MCP {
    final class Policy { public const SCOPES=['publicread'=>'Publiczny odczyt','full'=>'Pełne zarządzanie']; }
    // Atrapy sieci: funkcje z przestrzeni nazw przesłaniają wbudowane w OAuthClients.
    function dns_get_record($host,$type) { return $GLOBALS['dns']; }
    function curl_init($url) { return new \stdClass(); }
    function curl_setopt_array($h,array $o) { $GLOBALS['write']=$o[CURLOPT_WRITEFUNCTION]; return true; }
    function curl_exec($h) { if ($GLOBALS['http']['fail']) { return false; } return ($GLOBALS['write'])($h,$GLOBALS['http']['body'])===strlen($GLOBALS['http']['body']); }
    function curl_getinfo($h,$what) { return [CURLINFO_RESPONSE_CODE=>$GLOBALS['http']['status'],CURLINFO_CONTENT_TYPE=>$GLOBALS['http']['type']??'application/json',CURLINFO_PRIMARY_IP=>$GLOBALS['http']['peer']??'160.79.104.10'][$what]; }
    function curl_close($h) {}
}
namespace {
    if (ini_get('zend.assertions')!=='1') { fwrite(STDERR,"Asercje są wyłączone. Uruchom z -d zend.assertions=1.\n"); exit(1); }
    final class WP_Error {
        public function __construct(private string $code,private string $message='',private array $data=[]) {}
        public function get_error_code() { return $this->code; }
        public function get_error_data() { return $this->data; }
    }
    $GLOBALS['options']=[]; $GLOBALS['transients']=[];
    function is_wp_error($v) { return $v instanceof WP_Error; }
    function get_option($k,$d=false) { return $GLOBALS['options'][$k]??$d; }
    function update_option($k,$v,$a=null) { $GLOBALS['options'][$k]=$v; return true; }
    function delete_option($k) { unset($GLOBALS['options'][$k]); return true; }
    function get_transient($k) { return ($GLOBALS['ukryj']??false)?false:($GLOBALS['transients'][$k]??false); }
    function set_transient($k,$v,$t=0) { $GLOBALS['transients'][$k]=$v; return true; }
    function delete_transient($k) { unset($GLOBALS['transients'][$k]); return true; }
    require __DIR__.'/../../src/MCP/OAuthClients.php';
    $C=\ASM\MCP\OAuthClients::class; $id=$C::CIMD[0];
    $doc=json_encode(['client_id'=>$id,'client_name'=>'Claude','redirect_uris'=>['https://claude.ai/api/mcp/auth_callback'],'grant_types'=>['authorization_code','refresh_token'],'response_types'=>['code'],'token_endpoint_auth_method'=>'none']);
    $ok=['fail'=>false,'status'=>200,'body'=>$doc];
    $public=[['ip'=>'160.79.104.10']];
    $code=static fn($r)=>is_wp_error($r)?$r->get_error_code().'/'.$r->get_error_data()['status']:'doc';
    // Stan przed próbą: pamięć godzinna pusta, sieć według argumentów.
    $net=static function($dns,array $http) { $GLOBALS['transients']=[]; $GLOBALS['dns']=$dns; $GLOBALS['http']=$http; };
    $copies=static fn(): int => count($GLOBALS['options']);
    $down=['fail'=>true,'status'=>0,'body'=>''];

    // 1. DNS nie odpowiada, kopii nie ma: 503, nie invalid_client (także dla wydanego dostępu).
    $net(false,$ok);
    assert($code($C::resolve($id))==='temporarily_unavailable/503');
    assert($code($C::resolve($id,true))==='temporarily_unavailable/503');
    // 2. Udane pobranie zapisuje kopię.
    $net($public,$ok);
    $first=$C::resolve($id);
    assert(is_array($first) && $first['redirect_uris']===['https://claude.ai/api/mcp/auth_callback'] && $copies()===1);
    // 3. Chwilowe awarie: wydany dostęp dostaje kopię, nowa zgoda dostaje 503.
    foreach ([$down,['fail'=>true,'status'=>200,'body'=>''],['fail'=>false,'status'=>503,'body'=>''],['fail'=>false,'status'=>429,'body'=>''],['fail'=>false,'status'=>403,'body'=>'<html>','type'=>'text/html']] as $awaria) {
        $net($public,$awaria);
        assert($C::resolve($id,true)===$first, 'kopia przy awarii '.json_encode($awaria));
        $net($public,$awaria);
        assert($code($C::resolve($id))==='temporarily_unavailable/503', 'nowa zgoda bez kopii przy '.json_encode($awaria));
    }
    $net(false,$ok);
    assert($C::resolve($id,true)===$first, 'kopia przy braku DNS');
    // 4. Prywatny adres w DNS odrzuca klienta.
    $net([['ip'=>'10.0.0.5']],$ok);
    assert($code($C::resolve($id,true))==='invalid_client/400');
    // 5. Wynik definitywny odrzuca klienta i usuwa kopię: następna awaria nie ma do czego sięgnąć.
    $definitywne=[
        'błędny client_id'=>['fail'=>false,'status'=>200,'body'=>json_encode(['client_id'=>'https://example.test/inny'])],
        '404'=>['fail'=>false,'status'=>404,'body'=>''],
        '410'=>['fail'=>false,'status'=>410,'body'=>''],
        'przekierowanie'=>['fail'=>false,'status'=>302,'body'=>''],
        'zły typ treści'=>$ok+['type'=>'text/html'],
        'adres spoza listy'=>$ok+['peer'=>'203.0.113.9'],
        'za duża odpowiedź'=>['fail'=>false,'status'=>200,'body'=>str_repeat('a',16385)],
    ];
    foreach ($definitywne as $nazwa=>$wynik) {
        $net($public,$ok);
        assert($C::resolve($id)===$first && $copies()===1, 'kopia odtworzona przed: '.$nazwa);
        $net($public,$wynik);
        assert($code($C::resolve($id,true))==='invalid_client/400', $nazwa.': odrzucenie');
        assert($copies()===0, $nazwa.': kopia usunięta');
        // Równoległe żądanie zapisało pamięć godzinną tuż przed odrzuceniem (atrapa ukrywa ją przed odczytem): odrzucenie usuwa także ją.
        $net($public,$ok);
        assert($C::resolve($id)===$first && count($GLOBALS['transients'])===1);
        $GLOBALS['ukryj']=true; $GLOBALS['http']=$wynik;
        assert($code($C::resolve($id,true))==='invalid_client/400' && $GLOBALS['transients']===[] && $copies()===0, $nazwa.': pamięć godzinna usunięta');
        $GLOBALS['ukryj']=false;
        $net(false,$ok);
        assert($code($C::resolve($id,true))==='temporarily_unavailable/503', $nazwa.': awaria po odrzuceniu nie wskrzesza kopii');
    }
    // 6. Kopia starsza niż 30 dni, z datą z przyszłości albo o błędnej treści nie służy.
    $net($public,$ok);
    assert($C::resolve($id)===$first);
    $klucz=array_key_first($GLOBALS['options']);
    assert(preg_match('/^asm_mcp_cimd_ok_[a-f0-9]{32}$/D',$klucz)===1);
    foreach ([time()-31*86400,time()+30,time()+3600] as $kiedy) {
        $GLOBALS['options'][$klucz]['at']=$kiedy;
        $net(false,$ok);
        assert($code($C::resolve($id,true))==='temporarily_unavailable/503');
    }
    // Udane pobranie tej samej treści naprawia datę z przyszłości.
    $net($public,$ok);
    assert($C::resolve($id)===$first && $GLOBALS['options'][$klucz]['at']<=time());
    $GLOBALS['options'][$klucz]=['at'=>time(),'doc'=>['redirect_uris'=>['http://zly.example/odbior']]+$first];
    $net(false,$ok);
    assert($code($C::resolve($id,true))==='temporarily_unavailable/503', 'podmieniona kopia z adresem http nie przechodzi walidacji');
    // 7. Ponowne udane pobranie odświeża kopię.
    $net($public,$ok);
    assert($C::resolve($id)===$first && $GLOBALS['options'][$klucz]['at']>time()-5 && $GLOBALS['options'][$klucz]['doc']===$first);
    // 8. Dokumenty mają osobne kopie: odrzucenie jednego nie rusza drugiego, a zapis drugiego nie wskrzesza pierwszego.
    $drugi=$C::CIMD[1]; $docDrugi=['fail'=>false,'status'=>200,'body'=>str_replace(json_encode($id),json_encode($drugi),$doc)];
    $net($public,$docDrugi);
    assert(is_array($C::resolve($drugi)) && $copies()===2);
    $net($public,['fail'=>false,'status'=>410,'body'=>'']);
    assert($code($C::resolve($id,true))==='invalid_client/400' && $copies()===1);
    $GLOBALS['options'][array_key_first($GLOBALS['options'])]['at']=time()-7200;
    $net($public,$docDrugi);
    assert(is_array($C::resolve($drugi)) && $copies()===1, 'zapis kopii drugiego dokumentu nie przywraca pierwszej');
    $net(false,$ok);
    assert($code($C::resolve($id,true))==='temporarily_unavailable/503' && is_array($C::resolve($drugi,true)));
    echo "OK\n";
}
