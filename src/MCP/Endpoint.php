<?php
declare(strict_types=1);
namespace ASM\MCP;

final class Endpoint {
    public const VERSIONS=['2026-07-28','2025-11-25','2025-06-18','2025-03-26'];
    public static function register(): void {
        add_filter('rest_pre_dispatch',function ($result,$server,$request) {
            if ($request->get_route()==='/asm-mcp/v1/mcp' && strlen($request->get_body())>262144) {
                return self::response(['message'=>'Żądanie jest zbyt duże.'],413);
            }
            return $result;
        },5,3);
        register_rest_route('asm-mcp/v1','/mcp',['methods'=>'GET,POST,PUT,PATCH,DELETE,OPTIONS','permission_callback'=>'__return_true','callback'=>[self::class,'handle']]);
        add_filter('rest_pre_serve_request',function ($served,$result,$request) {
            if ($request->get_route()!=='/asm-mcp/v1/mcp') { return $served; }
            header_remove('Access-Control-Allow-Origin'); header_remove('Access-Control-Allow-Credentials'); header_remove('Access-Control-Allow-Methods'); header_remove('Access-Control-Allow-Headers');
            header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
            if ($result->get_status()===202) { return true; }
            return $served;
        },100,3);
    }
    public static function secure(): bool {
        $url=wp_parse_url(home_url());
        return ($url['scheme']??'')==='https' && is_ssl() || wp_get_environment_type()==='local' && in_array($url['host']??'', ['127.0.0.1','localhost','::1','[::1]'],true);
    }
    private static function response($data,int $status=200): \WP_REST_Response {
        $r=new \WP_REST_Response($data,$status); $r->header('Cache-Control','private, no-store'); $r->header('X-Content-Type-Options','nosniff');
        if ($status===401) { $r->header('WWW-Authenticate','Bearer resource_metadata="'.OAuthDiscovery::resourceMetadataUrl().'"'); }
        if ($status===405) { $r->header('Allow','POST'); }
        if ($status===429) { $r->header('Retry-After','60'); }
        return $r;
    }
    // Tytuł, adres i ikona witryny pozwalają odróżnić strony na liście serwerów w kliencie.
    private static function serverInfo(): array {
        $info=['name'=>'ai-site-manager','title'=>trim(wp_specialchars_decode(get_bloginfo('name'),ENT_QUOTES).' ('.wp_parse_url(home_url(),PHP_URL_HOST).')'),'version'=>ASM_VERSION,'websiteUrl'=>home_url('/')];
        $icon=get_site_icon_url(192);
        if ($icon) { $info['icons']=[['src'=>$icon,'sizes'=>['192x192']]]; }
        return $info;
    }
    private static function failure(\WP_Error $e): \WP_REST_Response {
        return self::response(['error'=>$e->get_error_code(),'message'=>$e->get_error_message()],(int)($e->get_error_data()['status']??500));
    }
    private static function rpcError($id,int $code,string $message,int $status=200): \WP_REST_Response { return self::response(['jsonrpc'=>'2.0','id'=>$id,'error'=>['code'=>$code,'message'=>$message]],$status); }
    public static function handle(\WP_REST_Request $r): \WP_REST_Response {
        global $wpdb;
        $old=$wpdb->suppress_errors(true);
        try {
            if (!self::secure()) { return self::failure(Policy::error('forbidden',403)); }
            $home=wp_parse_url(home_url());
            $host=strtolower((string)($_SERVER['HTTP_HOST']??''));
            $expected=strtolower($home['host'].(isset($home['port'])?':'.$home['port']:''));
            if ($host!==$expected) { return self::failure(Policy::error()); }
            $origin=(string)$r->get_header('origin');
            $canonical=$home['scheme'].'://'.$expected;
            if ($origin!=='' && $origin!==$canonical) { return self::failure(Policy::error()); }
            if ($r->get_header('x-http-method-override')!==null) { return self::failure(Policy::error('invalid',400)); }
            $query=$r->get_query_params();
            $rawQuery=(string)($_SERVER['QUERY_STRING']??'');
            if ($query || $rawQuery!=='') {
                $parts=explode('&',$rawQuery); $pair=explode('=',$parts[0],2);
                $path=wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
                $homePath=trailingslashit($home['path']??'/');
                if (count($parts)!==1 || count($pair)!==2 || urldecode($pair[0])!=='rest_route' || urldecode($pair[1])!=='/asm-mcp/v1/mcp' || !in_array($path,[$homePath,$homePath.'index.php'],true) || array_diff(array_keys($query),['rest_route']) || (isset($query['rest_route']) && $query['rest_route']!=='/asm-mcp/v1/mcp')) { return self::failure(Policy::error('invalid',400)); }
            }
            $authorization=(string)$r->get_header('authorization');
            $c=str_starts_with($authorization,'Bearer oa.') ? OAuthGrant::authenticate($authorization,true) : Connections::authenticate($authorization,true);
            if (is_wp_error($c)) { return self::failure($c); }
            if ($r->get_method()!=='POST') { return self::response(['message'=>'Ten transport przyjmuje żądania POST klienta MCP.'],405); }
            if (strlen($r->get_body())>262144) { return self::response(['message'=>'Żądanie jest zbyt duże.'],413); }
            if (!preg_match('~^application/json(?:\s*;.*)?$~i',(string)$r->get_header('content-type'))) { return self::response(['message'=>'Wymagany jest Content-Type application/json.'],415); }
            $accept=strtolower((string)$r->get_header('accept'));
            if (!str_contains($accept,'application/json') || !str_contains($accept,'text/event-stream')) { return self::response(['message'=>'Accept musi obejmować application/json i text/event-stream.'],406); }
            $version=(string)$r->get_header('mcp-protocol-version');
            if ($version!=='' && !in_array($version,self::VERSIONS,true)) { return self::response(['message'=>'Nieobsługiwana wersja MCP.'],400); }
            try { $raw=json_decode($r->get_body(),false,32,JSON_THROW_ON_ERROR); } catch (\Throwable $e) { return self::rpcError(null,-32700,'Nieprawidłowy JSON.',400); }
            if (!is_object($raw)) { return self::rpcError(null,-32600,'Wymagane pojedyncze żądanie JSON-RPC.',400); }
            $d=(array)$raw; $id=$d['id']??null;
            if (!Policy::closed($d,['jsonrpc','id','method','params'],['jsonrpc','method']) || $d['jsonrpc']!=='2.0' || !is_string($d['method']) || (array_key_exists('id',$d) && !is_int($id) && !is_string($id)) || (array_key_exists('params',$d) && !is_object($d['params']))) { return self::rpcError(null,-32600,'Nieprawidłowe żądanie.',400); }
            $params=(array)($d['params']??(object)[]); $method=$d['method'];
            if (array_key_exists('_meta',$params)) {
                if (!is_object($params['_meta'])) { return self::rpcError($id,-32602,'Nieprawidłowe metadane.'); }
                unset($params['_meta']); // Metadane protokołu nigdy nie trafiają do treści żądania narzędzia/ASM ani do audytu.
            }
            if (!array_key_exists('id',$d)) {
                if ($method==='notifications/initialized' && !$params) { if (!Connections::used($c,false)) { return self::failure(Policy::error('storage',503)); } return self::response(null,202); }
                if ($method==='notifications/cancelled' && Policy::closed($params,['requestId','reason'],['requestId']) && (is_string($params['requestId']) || is_int($params['requestId'])) && (!array_key_exists('reason',$params) || is_string($params['reason']))) { if (!Connections::used($c,false)) { return self::failure(Policy::error('storage',503)); } return self::response(null,202); }
                return self::rpcError(null,-32600,'Nieobsługiwane powiadomienie.',400);
            }
            if ($method==='initialize') {
                if (!Policy::closed($params,['protocolVersion','capabilities','clientInfo'],['protocolVersion','capabilities','clientInfo']) || !is_string($params['protocolVersion']) || !is_object($params['capabilities']) || !is_object($params['clientInfo'])) { return self::rpcError($id,-32602,'Nieprawidłowa wersja lub parametry.',400); }
                if (!is_string($params['clientInfo']->name??null) || !is_string($params['clientInfo']->version??null)) { return self::rpcError($id,-32602,'Nieprawidłowe dane klienta.',400); }
                $negotiated=in_array($params['protocolVersion'],self::VERSIONS,true)?$params['protocolVersion']:self::VERSIONS[0];
                $result=['protocolVersion'=>$negotiated,'capabilities'=>['tools'=>['listChanged'=>false]],'serverInfo'=>self::serverInfo(),'instructions'=>'Zakres dostępu jest przypisany do połączenia. Po niejasnym zapisie odczytaj status.'];
            } elseif ($method==='ping' && !$params) { $result=(object)[];
            } elseif ($method==='tools/list' && !$params) { $result=['tools'=>Tools::schemas($c)];
            } elseif ($method==='tools/call') {
                if (!Policy::closed($params,['name','arguments'],['name']) || !is_string($params['name']) || (array_key_exists('arguments',$params) && !is_object($params['arguments']))) { return self::rpcError($id,-32602,'Nieprawidłowe argumenty.'); }
                $params['arguments']=$params['arguments']??(object)[];
                $tool=$params['name'];
                if (!in_array($tool,array_column(Tools::schemas($c),'name'),true)) {
                    return self::response(['jsonrpc'=>'2.0','id'=>$id,'result'=>['content'=>[['type'=>'text','text'=>'Narzędzie nie jest dostępne dla tego połączenia.']],'isError'=>true]]);
                }
                if (!Connections::audit($c,$tool,'requested')) { return self::failure(Policy::error('storage',503)); }
                $prior=get_current_user_id();
                try { wp_set_current_user((int)$c['owner_id']); $out=Tools::call($c,$tool,self::arrays($params['arguments'])); } finally { wp_set_current_user($prior); }
                $error=is_wp_error($out);
                if ($error) { $out=['error'=>$out->get_error_code(),'message'=>$out->get_error_message(),'status'=>$out->get_error_data()['status']??500]; }
                elseif (in_array($tool,['wp_operation_execute','wp_operation_rollback'],true) && !in_array($out['status']??'',['verified','rolled_back'],true)) { $error=true; $out['message']='Wynik operacji nie potwierdza wykonanej lub cofniętej zmiany. Sprawdź jej status.'; }
                elseif (in_array($out['status']??null,['unknown','failed','rollback_failed'],true)) { $error=true; $out['message']='Stan operacji wymaga sprawdzenia; nie potwierdza sukcesu.'; }
                if (!Connections::audit($c,$tool,$error?'denied':'completed',isset($out['action'])?($out['id']??''):'')) { return self::failure(Policy::error('storage',503)); }
                if (!$error && !Connections::used($c,$tool==='wp_site_status')) { return self::failure(Policy::error('storage',503)); }
                $result=['content'=>[['type'=>'text','text'=>wp_json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]],'isError'=>$error];
            } else { return self::rpcError($id,-32601,'Nieobsługiwana metoda.'); }
            if ($method!=='tools/call' && !Connections::used($c,false)) { return self::failure(Policy::error('storage',503)); }
            return self::response(['jsonrpc'=>'2.0','id'=>$id,'result'=>$result]);
        } catch (\Throwable $e) { error_log('ASM MCP: endpoint failure '.get_class($e)); return self::failure(Policy::error('storage',503)); }
        finally { $wpdb->suppress_errors($old); }
    }
    private static function arrays($value) {
        if (is_object($value)) { return array_map([self::class,'arrays'],(array)$value); }
        if (is_array($value)) { return array_map([self::class,'arrays'],$value); }
        return $value;
    }
}
