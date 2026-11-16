<?php

declare(strict_types=1);
namespace ASM\MCP;

final class OAuthController {
    public static function boot(): void {
        add_action('init',[OAuthDiscovery::class,'route'],0);
        add_action('rest_api_init',[self::class,'register']);
        add_action('admin_post_asm_mcp_authorize',[self::class,'authorize']);
        add_action('admin_post_asm_mcp_oauth_diagnostics',[self::class,'diagnostics']);
        add_action('admin_post_nopriv_asm_mcp_authorize',[self::class,'authorize']);
    }
    public static function diagnostics(): void {
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST') { wp_die('Wymagany formularz POST.','',['response'=>405]); }
        if (!current_user_can('manage_options')) { wp_die('Brak uprawnień do sprawdzenia połączenia.','',['response'=>403]); }
        check_admin_referer('asm_mcp_oauth_diagnostics');
        if (!OAuthClients::rate('diagnostics',5)) { wp_die('Zbyt wiele prób. Spróbuj po upływie minuty.','',['response'=>429]); }
        set_transient('asm_mcp_discovery_'.get_current_user_id(),OAuthDiscovery::detect(true),600);
        wp_safe_redirect(admin_url('options-general.php?page=ai-site-manager&tab=help&doc=troubleshooting#asm-discovery')); exit;
    }
    public static function register(): void {
        foreach (['/oauth/resource'=>'resourceMetadata','/\\.well-known/oauth-authorization-server'=>'server','/\\.well-known/openid-configuration'=>'openid','/oauth/jwks'=>''] as $route=>$document) {
            register_rest_route('asm-mcp/v1',$route,['methods'=>'GET','callback'=>static function() use ($document) {
                // LiteSpeed zapisuje odpowiedzi GET z REST mimo no-store i po zmianie trybu podawałby starego issuera.
                do_action('litespeed_control_set_nocache','asm oauth metadata');
                return self::response($document?OAuthDiscovery::$document():['keys'=>[]]);
            },'permission_callback'=>'__return_true']);
        }
        foreach (['register','token','revoke'] as $endpoint) {
            register_rest_route('asm-mcp/v1','/oauth/'.$endpoint,['methods'=>'POST','callback'=>static fn(\WP_REST_Request $request)=>self::endpoint($endpoint,$request),'permission_callback'=>'__return_true']);
        }
    }
    private static function response($payload,int $status=200): \WP_REST_Response {
        if (is_wp_error($payload)) { $status=(int)($payload->get_error_data()['status']??400); $payload=['error'=>$payload->get_error_code()]; }
        return new \WP_REST_Response($payload,$status,['Cache-Control'=>'no-store','Pragma'=>'no-cache','X-LiteSpeed-Cache-Control'=>'no-cache','Referrer-Policy'=>'no-referrer','X-Content-Type-Options'=>'nosniff']);
    }
    private static function form(string $body): ?array {
        $parsed=[];
        foreach (explode('&',$body) as $pair) {
            if ($pair==='') { continue; }
            $pieces=explode('=',$pair,2); $key=urldecode($pieces[0]); $value=urldecode($pieces[1]??'');
            if ($key==='' || preg_match('/[^a-z_]/',$key) || isset($parsed[$key]) || strlen($value)>2048) { return null; }
            $parsed[$key]=$value;
        }
        return $parsed;
    }
    public static function endpoint(string $endpoint,\WP_REST_Request $request): \WP_REST_Response {
        if (!Endpoint::secure()) { return self::response(OAuthClients::error('access_denied',403)); }
        $body=$request->get_body();
        if (strlen($body)>OAuthClients::MAX_BODY) { return self::response(OAuthClients::error('invalid_request',413)); }
        $type=strtolower(trim(explode(';',$request->get_header('content-type'))[0]));
        if ($endpoint==='register') {
            if ($type!=='application/json') { return self::response(OAuthClients::error()); }
            $metadata=json_decode($body,true,16);
            if (!is_array($metadata) || array_is_list($metadata)) { return self::response(OAuthClients::error()); }
            return self::response(OAuthClients::register($metadata),201);
        }
        if ($type!=='application/x-www-form-urlencoded' || (string)$request->get_header('authorization')!=='') { return self::response(OAuthClients::error()); }
        if (!OAuthClients::rate($endpoint,60) || !OAuthClients::rate($endpoint,1000,60,true)) { return self::response(OAuthClients::error('temporarily_unavailable',429)); }
        $fields=self::form($body);
        if ($fields===null || !isset($fields['client_id'])) { return self::response(OAuthClients::error()); }
        $client=OAuthClients::resolve($fields['client_id']);
        if (is_wp_error($client)) { return self::response($client); }
        try {
            if ($endpoint==='revoke') {
                if (!isset($fields['token']) || !Policy::closed($fields,['client_id','token','token_type_hint'],['client_id','token'])) { return self::response(OAuthClients::error()); }
                OAuthGrant::revoke($fields);
                return self::response(null);
            }
            if (($fields['grant_type']??'')==='authorization_code') {
                if (!Policy::closed($fields,['grant_type','client_id','code','redirect_uri','code_verifier','resource'],['grant_type','client_id','code','redirect_uri','code_verifier','resource'])) { return self::response(OAuthClients::error()); }
                if ($fields['resource']!==OAuthDiscovery::resource()) { return self::response(OAuthClients::error('invalid_target')); }
                return self::response(OAuthGrant::exchangeCode($fields));
            }
            if (($fields['grant_type']??'')==='refresh_token') {
                if (!Policy::closed($fields,['grant_type','client_id','refresh_token','resource','scope'],['grant_type','client_id','refresh_token','resource'])) { return self::response(OAuthClients::error()); }
                if ($fields['resource']!==OAuthDiscovery::resource()) { return self::response(OAuthClients::error('invalid_target')); }
                return self::response(OAuthGrant::refresh($fields));
            }
            return self::response(OAuthClients::error('unsupported_grant_type'));
        } catch (\RuntimeException $failure) {
            error_log('ASM MCP: transakcja OAuth nie została zakończona ('.$failure->getMessage().').');
            return self::response(OAuthClients::error('server_error',503));
        }
    }
    public static function validateAuthorization(array $fields) {
        $allowed=['action','client_id','redirect_uri','response_type','code_challenge','code_challenge_method','resource','scope','state','_wpnonce','consent_context','selected_scope','expiry_mode','expiry_date','decision'];
        if (!Policy::closed($fields,$allowed,['client_id','redirect_uri','response_type','code_challenge','code_challenge_method','resource'])) { return OAuthClients::error(); }
        foreach ($fields as $value) { if (!is_string($value) || strlen($value)>2048) { return OAuthClients::error(); } }
        $fields['state']=$fields['state']??'';
        if ($fields['response_type']!=='code' || $fields['code_challenge_method']!=='S256' || preg_match('/^[A-Za-z0-9_-]{43}$/D',$fields['code_challenge'])!==1 || strlen($fields['state'])>1024 || preg_match('/[\x00-\x1f\x7f]/',$fields['state']) || $fields['resource']!==OAuthDiscovery::resource()) { return OAuthClients::error(); }
        $client=OAuthClients::resolve($fields['client_id']);
        if (is_wp_error($client)) { return $client; }
        $matches=false;
        foreach ($client['redirect_uris'] as $registered) { if (OAuthClients::redirectMatches($registered,$fields['redirect_uri'])) { $matches=true; break; } }
        if (!$matches) { return OAuthClients::error('invalid_redirect_uri'); }
        $registeredScopes=OAuthClients::scopes($client['scope']);
        $scopes=isset($fields['scope'])?OAuthClients::scopes($fields['scope']):$registeredScopes;
        if ($scopes===null || $registeredScopes===null || array_diff($scopes,$registeredScopes)) { return OAuthClients::error('invalid_scope'); }
        return ['request'=>$fields,'client'=>$client,'scopes'=>$scopes];
    }
    private static function callback(array $request,array $values): void {
        $values['iss']=OAuthDiscovery::issuer();
        if ($request['state']!=='') { $values['state']=$request['state']; }
        $separator=str_contains($request['redirect_uri'],'?')?'&':'?';
        wp_redirect($request['redirect_uri'].$separator.http_build_query($values,'','&',PHP_QUERY_RFC3986),302,'Menedżer Strony AI'); exit;
    }
    public static function authorize(): void {
        nocache_headers(); header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer'); header('X-Frame-Options: DENY'); header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'"); header('X-Content-Type-Options: nosniff');
        if (!Endpoint::secure()) { status_header(403); echo 'Logowanie do aplikacji wymaga połączenia HTTPS.'; exit; }
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>OAuthClients::MAX_BODY || strlen($_SERVER['REQUEST_URI']??'')>OAuthClients::MAX_BODY || strlen((string)file_get_contents('php://input',false,null,0,OAuthClients::MAX_BODY+1))>OAuthClients::MAX_BODY) { status_header(413); echo 'Żądanie logowania jest zbyt duże.'; exit; }
        $method=$_SERVER['REQUEST_METHOD']??'GET';
        if (!in_array($method,['GET','POST'],true)) { status_header(405); echo 'Ta metoda nie jest obsługiwana.'; exit; }
        if (!OAuthClients::rate('authorize',30) || !OAuthClients::rate('authorize',1000,60,true)) { status_header(429); echo 'Zbyt wiele prób. Spróbuj po upływie minuty.'; exit; }
        $fields=wp_unslash($method==='POST'?$_POST:$_GET);
        $validated=self::validateAuthorization($fields);
        if (is_wp_error($validated)) {
            status_header(400);
            if ($validated->get_error_code()==='invalid_client' && is_string($fields['client_id']??null) && in_array($fields['client_id'],OAuthClients::CIMD,true)) { echo 'Nie można potwierdzić dokumentu aplikacji Claude. Nie udzielono dostępu. Administrator serwera powinien sprawdzić DNS i połączenie HTTPS. Jeśli aplikacja obsługuje automatyczną rejestrację klienta (DCR), można skorzystać z tego sposobu połączenia.'; }
            else { echo 'Nieprawidłowe żądanie logowania. Wróć do aplikacji i dodaj połączenie ponownie.'; }
            exit;
        }
        $request=$validated['request'];
        $redirect=parse_url($request['redirect_uri']);
        $origin=$redirect['scheme'].'://'.$redirect['host'].(isset($redirect['port'])?':'.$redirect['port']:'');
        // Źródło CSP pochodzi tylko ze zweryfikowanego redirectu. Nietypowy host
        // nie może wprowadzić wildcard ani dodatkowej dyrektywy do nagłówka.
        if (preg_match('~\Ahttps?://(?:[a-z0-9._-]+|\[[a-f0-9:]+\])(?::[0-9]+)?\z~i',$origin)===1) {
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self' ".$origin."; frame-ancestors 'none'; base-uri 'none'");
        }
        if (!is_user_logged_in()) {
            $loginFields=array_intersect_key($request,array_flip(['client_id','redirect_uri','response_type','code_challenge','code_challenge_method','resource','scope','state']));
            wp_safe_redirect(wp_login_url(OAuthDiscovery::authorizationEndpoint().'&'.http_build_query($loginFields,'','&',PHP_QUERY_RFC3986))); exit;
        }
        if (!current_user_can('manage_options')) { status_header(403); echo 'Dostęp może zatwierdzić administrator tej strony.'; exit; }
        if ($method==='POST') {
            if (!isset($request['_wpnonce']) || !wp_verify_nonce($request['_wpnonce'],'asm_mcp_oauth_consent')) { status_header(403); echo 'Formularz wygasł. Otwórz połączenie ponownie.'; exit; }
            $consumed=OAuthConsent::consume($request['consent_context']??'',$request);
            if (is_wp_error($consumed)) { status_header((int)$consumed->get_error_data()['status']); echo 'Formularz został już wykorzystany, wygasł lub zmienił się jego kontekst. Wróć do aplikacji i ponów połączenie.'; exit; }
            if (($request['decision']??'')==='deny') { self::callback($request,['error'=>'access_denied']); }
            if (($request['decision']??'')!=='allow' || !isset($request['selected_scope']) || !in_array($request['selected_scope'],$validated['scopes'],true)) { status_header(400); echo 'Wybierz zakres dostępu.'; exit; }
            $expiry=0;
            if (($request['expiry_mode']??'')==='date') {
                $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$request['expiry_date']??'',wp_timezone());
                if (!$date || $date->format('Y-m-d')!==($request['expiry_date']??'')) { status_header(400); echo 'Podaj prawidłową datę końca dostępu.'; exit; }
                $expiry=$date->setTime(23,59,59)->getTimestamp();
                if ($expiry<=time() || $expiry>time()+366*86400) { status_header(400); echo 'Wybierz przyszłą datę, najwyżej za rok.'; exit; }
            } elseif (($request['expiry_mode']??'')!=='unlimited') { status_header(400); echo 'Wybierz termin dostępu.'; exit; }
            $code=OAuthGrant::issueCode($request,$request['selected_scope'],$expiry);
            if (is_wp_error($code)) { status_header(403); echo 'Nie można udzielić tego dostępu. Sprawdź uprawnienia i spróbuj ponownie.'; exit; }
            self::callback($request,['code'=>$code]);
        }
        self::consent($validated);
        exit;
    }
    private static function consent(array $validated): void {
        $client=$validated['client']; $request=$validated['request'];
        $context=OAuthConsent::create($request);
        if (is_wp_error($context)) { status_header(503); echo 'Nie można przygotować formularza zgody. Spróbuj ponownie.'; return; }
        status_header(200); header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Zgoda na dostęp | Menedżer Strony AI</title><style>body{font:18px/1.5 system-ui,sans-serif;color:#17212b;background:#f5f6f7;margin:0}main{max-width:640px;margin:24px auto;padding:24px;background:white;box-sizing:border-box}h1{font-size:28px}fieldset{margin:20px 0;padding:16px;border:1px solid #657180}label{display:block;margin:12px 0}input,button{font:inherit}button{padding:12px 16px;border:2px solid #153e66;background:#153e66;color:white;cursor:pointer;margin:8px 8px 8px 0}button[value=deny]{background:white;color:#153e66}:focus-visible{outline:3px solid #b54f00;outline-offset:4px}p{overflow-wrap:anywhere}@media(max-width:680px){main{margin:0;padding:20px}}</style></head><body><main><h1>Zgoda na dostęp do strony</h1><p>Aplikacja: <strong>'.esc_html($client['client_name']).'</strong></p>';
        echo $client['verified']?'<p>Tożsamość potwierdzona dokumentem Claude.</p>':'<p>Nazwa aplikacji jest deklaracją jej twórcy. Sprawdź adres powrotu przed udzieleniem dostępu.</p>';
        echo '<p>Adres powrotu: <strong>'.esc_html((string)wp_parse_url($request['redirect_uri'],PHP_URL_HOST)).'</strong></p><p>Strona: '.esc_html(untrailingslashit(home_url())).'</p><form method="post" action="'.esc_url(OAuthDiscovery::authorizationEndpoint()).'">';
        foreach (array_intersect_key($request,array_flip(['client_id','redirect_uri','response_type','code_challenge','code_challenge_method','resource','scope','state'])) as $key=>$value) { echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($value).'">'; }
        echo '<input type="hidden" name="consent_context" value="'.esc_attr($context).'"><input type="hidden" name="_wpnonce" value="'.esc_attr(wp_create_nonce('asm_mcp_oauth_consent')).'"><fieldset><legend>Zakres dostępu</legend>';
        if (!current_user_can('asm_full_control') && array_intersect($validated['scopes'],['drafts','publish','full'])) {
            echo '<p>Aplikacja prosi także o edycję treści, ale obecnie możliwy jest tylko odczyt. Administrator może jawnie nadać sobie pełny dostęp w ustawieniach operatorów Menedżera Strony AI, a następnie ponowić połączenie. Zatwierdzenie odczytu nie pozwala edytować wpisów.</p><p><a href="'.esc_url(admin_url('options-general.php?page=ai-site-manager&tab=permissions')).'" target="_blank" rel="noopener noreferrer">Otwórz ustawienia operatorów</a></p>';
        }
        $first=true;
        $scopeOrder=array_keys(Policy::SCOPES);
        foreach ($scopeOrder as $scope) {
            if (!in_array($scope,$validated['scopes'],true) || ($scope!=='publicread' && !current_user_can('asm_full_control'))) { continue; }
            echo '<label><input type="radio" name="selected_scope" value="'.esc_attr($scope).'"'.($first?' checked':'').'> '.esc_html(Policy::SCOPES[$scope]).'</label>'; $first=false;
        }
        echo '</fieldset><fieldset><legend>Termin dostępu</legend><label><input type="radio" name="expiry_mode" value="date" checked> Do dnia</label><label>Data końca <input type="date" name="expiry_date" value="'.esc_attr(wp_date('Y-m-d',time()+30*86400)).'" min="'.esc_attr(wp_date('Y-m-d',time()+86400)).'"></label><label><input type="radio" name="expiry_mode" value="unlimited"> Bezterminowo</label><p>Dostęp bezterminowy trwa do cofnięcia lub usunięcia połączenia w Menedżerze Strony AI. Aplikacja nadal musi mieć ważne poświadczenie.</p></fieldset><button type="submit" name="decision" value="allow">Zatwierdź dostęp</button><button type="submit" name="decision" value="deny">Odmów</button></form></main></body></html>';
    }
}
