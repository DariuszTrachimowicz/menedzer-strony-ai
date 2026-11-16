<?php

declare(strict_types=1);
namespace ASM\MCP;

final class OAuthDiscovery {
    public static function mode(): string {
        $state=get_option('asm_mcp_discovery');
        // Tryb path omija hosting przejmujący /.well-known. Wymaga adresów REST bez parametru rest_route.
        return is_array($state) && ($state['mode']??'')==='path' && !str_contains(rest_url('asm-mcp/v1'),'?') ? 'path' : 'root';
    }
    public static function issuer(): string { return untrailingslashit(self::mode()==='path'?rest_url('asm-mcp/v1'):home_url()); }
    public static function resource(): string { return rest_url('asm-mcp/v1/mcp'); }
    public static function resourceMetadataUrl(): string { return self::mode()==='path'?rest_url('asm-mcp/v1/oauth/resource'):untrailingslashit(home_url()).'/.well-known/oauth-protected-resource'; }
    public static function authorizationEndpoint(): string { return admin_url('admin-post.php?action=asm_mcp_authorize'); }
    public static function server(): array {
        return ['issuer'=>self::issuer(),'authorization_endpoint'=>self::authorizationEndpoint(),'token_endpoint'=>rest_url('asm-mcp/v1/oauth/token'),'registration_endpoint'=>rest_url('asm-mcp/v1/oauth/register'),'revocation_endpoint'=>rest_url('asm-mcp/v1/oauth/revoke'),'response_types_supported'=>['code'],'grant_types_supported'=>['authorization_code','refresh_token'],'token_endpoint_auth_methods_supported'=>['none'],'revocation_endpoint_auth_methods_supported'=>['none'],'code_challenge_methods_supported'=>['S256'],'scopes_supported'=>array_keys(Policy::SCOPES),'authorization_response_iss_parameter_supported'=>true,'client_id_metadata_document_supported'=>OAuthClients::transportAvailable()];
    }
    // Klienci MCP czytają ten adres schematem OIDC i odrzucają dokument bez trzech pól poniżej.
    // Wtyczka nie wydaje tokenów ID i nie oferuje zakresu openid, dlatego zbiór kluczy jest pusty.
    public static function openid(): array { return self::server()+['jwks_uri'=>rest_url('asm-mcp/v1/oauth/jwks'),'subject_types_supported'=>['public'],'id_token_signing_alg_values_supported'=>['RS256']]; }
    // Zmiana trybu zmienia issuer, więc logowanie rozpoczęte tuż przed nią trzeba powtórzyć.
    // Dlatego powrót do root następuje tylko po ręcznym sprawdzeniu przez administratora.
    public static function detect(bool $manual=false): array {
        $checks=self::diagnostics(); $before=self::mode(); $mode=$before;
        $taken=static fn(array $check): bool=>$check['status']===404 || ($check['status']>=300 && $check['status']<400);
        if ($checks[0]['valid'] && $checks[2]['valid']) { if ($manual) { $mode='root'; } }
        // Tylko przekierowanie albo 404 pod /.well-known przy działających dokumentach REST. Błąd 5xx, 403 i brak odpowiedzi nie zmieniają trybu.
        elseif ($taken($checks[0]) && $taken($checks[2]) && $checks[3]['valid'] && $checks[4]['valid']) { $mode='path'; }
        update_option('asm_mcp_discovery',['mode'=>$mode,'version'=>ASM_VERSION],false);
        return $mode===$before?$checks:self::diagnostics();
    }
    public static function resourceMetadata(): array { return ['resource'=>self::resource(),'authorization_servers'=>[self::issuer()],'bearer_methods_supported'=>['header'],'scopes_supported'=>array_keys(Policy::SCOPES)]; }
    public static function diagnostics(): array {
        $home=untrailingslashit(home_url());
        $urls=[$home.'/.well-known/oauth-protected-resource',$home.'/.well-known/oauth-protected-resource/wp-json/asm-mcp/v1/mcp',$home.'/.well-known/oauth-authorization-server',rest_url('asm-mcp/v1/oauth/resource'),rest_url('asm-mcp/v1/.well-known/openid-configuration')];
        $checks=[];
        foreach ($urls as $index=>$url) {
            $arguments=['timeout'=>3,'redirection'=>0,'limit_response_size'=>16384,'cookies'=>[],'headers'=>['Accept'=>'application/json'],'sslverify'=>true];
            $host=wp_parse_url($url,PHP_URL_HOST);
            $local=wp_get_environment_type()==='local' && in_array($host,['127.0.0.1','localhost','::1','[::1]'],true);
            $reply=$local?wp_remote_get($url,$arguments):wp_safe_remote_get($url,$arguments);
            $status=is_wp_error($reply)?0:(int)wp_remote_retrieve_response_code($reply);
            $mime=is_wp_error($reply)?'':strtolower((string)wp_remote_retrieve_header($reply,'content-type'));
            $document=is_wp_error($reply)?null:json_decode(wp_remote_retrieve_body($reply),true,16);
            $json=preg_match('~^application/(?:[a-z0-9.+-]+\+)?json(?:\s*;|$)~i',$mime)===1;
            $valid=false;
            if ($status===200 && $json && is_array($document)) {
                $valid=in_array($index,[0,1,3],true) ? ($document['resource']??null)===self::resource() && ($document['authorization_servers']??null)===[self::issuer()] : ($document['issuer']??null)===self::issuer() && ($document['authorization_endpoint']??null)===self::authorizationEndpoint() && ($document['token_endpoint']??null)===rest_url('asm-mcp/v1/oauth/token') && ($document['registration_endpoint']??null)===rest_url('asm-mcp/v1/oauth/register');
            }
            $checks[]=['url'=>$url,'status'=>$status,'mime'=>substr($mime,0,100),'valid'=>$valid];
        }
        return $checks;
    }
    public static function route(): void {
        $homePath=rtrim((string)wp_parse_url(home_url(),PHP_URL_PATH),'/');
        $requestPath=(string)wp_parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
        $paths=array_unique([$homePath.'/.well-known/oauth-protected-resource',$homePath.'/.well-known/oauth-protected-resource/wp-json/asm-mcp/v1/mcp','/.well-known/oauth-protected-resource','/.well-known/oauth-protected-resource'.$homePath.'/wp-json/asm-mcp/v1/mcp']);
        // RFC 8414 wstawia well-known przed ścieżką issuera.
        $serverPaths=['/.well-known/oauth-authorization-server'.$homePath,$homePath.'/.well-known/oauth-authorization-server','/.well-known/oauth-authorization-server'.$homePath.'/wp-json/asm-mcp/v1'];
        $openidPaths=['/.well-known/openid-configuration'.$homePath.'/wp-json/asm-mcp/v1'];
        if (!in_array($requestPath,array_merge($paths,$serverPaths,$openidPaths),true)) { return; }
        if (!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)) { status_header(405); header('Allow: GET, HEAD'); exit; }
        nocache_headers(); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
        status_header(200);
        if (($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD') { echo wp_json_encode(in_array($requestPath,$paths,true)?self::resourceMetadata():(in_array($requestPath,$openidPaths,true)?self::openid():self::server()),JSON_UNESCAPED_SLASHES); }
        exit;
    }
}

