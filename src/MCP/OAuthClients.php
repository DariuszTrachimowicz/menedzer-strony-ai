<?php

declare(strict_types=1);
namespace ASM\MCP;

final class OAuthClients {
    public const CIMD = ['https://claude.ai/oauth/mcp-oauth-client-metadata','https://claude.ai/oauth/claude-code-client-metadata'];
    public const MAX_BODY=8192;
    public const MAX_CLIENTS=1000;
    public const MAX_REDIRECTS=8;
    public const MAX_NAME=100;
    public static function table(): string { return Connections::table('oauth_clients'); }
    public static function install(): void {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $collate=$wpdb->get_charset_collate(); $table=self::table();
        dbDelta("CREATE TABLE $table (\nclient_id varchar(80) NOT NULL,\nmetadata longtext NOT NULL,\ncreated_at bigint NOT NULL,\nlast_used bigint NOT NULL DEFAULT 0,\nPRIMARY KEY  (client_id)\n) ENGINE=InnoDB $collate;");
        $table=Connections::table('oauth_limits');
        dbDelta("CREATE TABLE $table (\nbucket varchar(64) NOT NULL,\n`window` bigint NOT NULL,\ncount int NOT NULL DEFAULT 0,\nPRIMARY KEY  (bucket,`window`)\n) ENGINE=InnoDB $collate;");
        foreach ([self::table()=>['client_id','metadata','created_at','last_used'],Connections::table('oauth_limits')=>['bucket','window','count']] as $name=>$required) {
            $columns=$wpdb->get_col("SHOW COLUMNS FROM $name");
            if ($wpdb->last_error || array_diff($required,$columns)) { throw new \RuntimeException('oauth_storage'); }
            $status=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s',$wpdb->esc_like($name)),ARRAY_A);
            if ($wpdb->last_error || !is_array($status) || strcasecmp($status['Engine']??'','InnoDB')!==0) { throw new \RuntimeException('oauth_storage'); }
        }
    }
    public static function error(string $code='invalid_request',int $status=400): \WP_Error { return new \WP_Error($code,'Żądanie logowania nie zostało przyjęte.',['status'=>$status]); }
    public static function rate(string $endpoint,int $limit,int $seconds=60,bool $global=false): bool {
        global $wpdb;
        $address=$global?'global':($_SERVER['REMOTE_ADDR']??'unknown');
        $bucket=hash('sha256',$endpoint.'|'.$address); $window=intdiv(time(),$seconds)*$seconds; $table=Connections::table('oauth_limits');
        $ok=$wpdb->query($wpdb->prepare("INSERT INTO $table (bucket,`window`,count) VALUES (%s,%d,1) ON DUPLICATE KEY UPDATE count=IF(count<%d,count+1,count)",$bucket,$window,$limit+1));
        if ($ok===false) { return false; }
        $count=$wpdb->get_var($wpdb->prepare("SELECT count FROM $table WHERE bucket=%s AND `window`=%d",$bucket,$window));
        return !$wpdb->last_error && $count!==null && (int)$count<=$limit;
    }
    public static function loopback(array $parts): bool { return isset($parts['host']) && in_array(strtolower($parts['host']),['localhost','127.0.0.1','[::1]'],true); }
    public static function validRedirect(string $uri): bool {
        if (strlen($uri)>2048 || preg_match('/[\x00-\x20\x7f\\\\]/',$uri)) { return false; }
        $parts=parse_url($uri);
        if (!$parts || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || empty($parts['host']) || !in_array($parts['scheme']??'',['http','https'],true)) { return false; }
        if (isset($parts['port']) && ($parts['port']<1 || $parts['port']>65535)) { return false; }
        return $parts['scheme']==='https' || self::loopback($parts);
    }
    public static function redirectMatches(string $registered,string $requested): bool {
        if (!self::validRedirect($registered) || !self::validRedirect($requested)) { return false; }
        if (hash_equals($registered,$requested)) { return true; }
        $a=parse_url($registered); $b=parse_url($requested);
        if (!self::loopback($a) || !self::loopback($b) || $a['host']!==$b['host'] || $a['scheme']!==$b['scheme']) { return false; }
        unset($a['port'],$b['port']);
        return $a===$b;
    }
    public static function scopes(string $scope): ?array {
        $names=explode(' ',trim($scope));
        return $names && !in_array('', $names,true) && count($names)===count(array_unique($names)) && !array_diff($names,array_keys(Policy::SCOPES)) ? $names : null;
    }
    public static function validate(array $metadata,bool $cimd=false) {
        if (!isset($metadata['redirect_uris']) || !is_array($metadata['redirect_uris']) || !$metadata['redirect_uris'] || count($metadata['redirect_uris'])>self::MAX_REDIRECTS || !array_is_list($metadata['redirect_uris'])) { return self::error('invalid_redirect_uri'); }
        foreach ($metadata['redirect_uris'] as $uri) { if (!is_string($uri) || !self::validRedirect($uri)) { return self::error('invalid_redirect_uri'); } }
        if (isset($metadata['client_name']) && (!is_string($metadata['client_name']) || mb_strlen($metadata['client_name'])>self::MAX_NAME || trim($metadata['client_name'])==='' || preg_match('/[\x00-\x1f\x7f<>]/',$metadata['client_name']))) { return self::error('invalid_client_metadata'); }
        if (($metadata['token_endpoint_auth_method']??'none')!=='none') { return self::error('invalid_client_metadata'); }
        foreach (['grant_types'=>['authorization_code','refresh_token'],'response_types'=>['code']] as $field=>$allowed) {
            $values=$metadata[$field]??$allowed;
            if (!is_array($values) || !array_is_list($values) || !$values || count(array_filter($values,'is_string'))!==count($values) || (!$cimd && array_diff($values,$allowed)) || !in_array($allowed[0],$values,true)) { return self::error('invalid_client_metadata'); }
        }
        if (!$cimd && isset($metadata['scope']) && (!is_string($metadata['scope']) || self::scopes($metadata['scope'])===null)) { return self::error('invalid_scope'); }
        return ['redirect_uris'=>array_values(array_unique($metadata['redirect_uris'])),'client_name'=>$metadata['client_name']??'Aplikacja MCP','token_endpoint_auth_method'=>'none','grant_types'=>['authorization_code','refresh_token'],'response_types'=>['code'],'scope'=>$cimd?implode(' ',array_keys(Policy::SCOPES)):($metadata['scope']??implode(' ',array_keys(Policy::SCOPES))),'verified'=>$cimd];
    }
    public static function register(array $metadata) {
        global $wpdb;
        if (!self::rate('register',10,3600) || !self::rate('register',100,3600,true)) { return self::error('temporarily_unavailable',429); }
        $validated=self::validate($metadata);
        if (is_wp_error($validated)) { return $validated; }
        $lock='asm-mcp:dcr:'.substr(hash('sha256',$wpdb->prefix),0,36);
        if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1') { return self::error('temporarily_unavailable',503); }
        try {
            $count=$wpdb->get_var('SELECT COUNT(*) FROM '.self::table());
            if ($wpdb->last_error || $count===null) { return self::error('server_error',503); }
            if ((int)$count>=self::MAX_CLIENTS) { return self::error('temporarily_unavailable',429); }
            $id='asm_'.bin2hex(random_bytes(24));
            if ($wpdb->insert(self::table(),['client_id'=>$id,'metadata'=>wp_json_encode($validated),'created_at'=>time()])!==1) { return self::error('server_error',503); }
            unset($validated['verified']);
            return array_merge($validated,['client_id'=>$id,'client_id_issued_at'=>time()]);
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); if ($wpdb->last_error) { error_log('ASM MCP: nie można zwolnić blokady rejestracji OAuth.'); } }
    }
    public static function resolve(string $id) {
        global $wpdb;
        if (in_array($id,self::CIMD,true)) { return self::fetchCIMD($id); }
        if (!preg_match('/^asm_[a-f0-9]{48}$/D',$id)) { return self::error('invalid_client'); }
        $row=$wpdb->get_row($wpdb->prepare('SELECT metadata FROM '.self::table().' WHERE client_id=%s',$id),ARRAY_A);
        if ($wpdb->last_error) { return self::error('server_error',503); }
        if (!$row) { return self::error('invalid_client'); }
        $metadata=json_decode($row['metadata'],true);
        return is_array($metadata)?$metadata:self::error('invalid_client');
    }
    public static function touch(string $id): bool {
        global $wpdb;
        return in_array($id,self::CIMD,true) || $wpdb->update(self::table(),['last_used'=>time()],['client_id'=>$id])!==false;
    }
    public static function transportAvailable(): bool {
        if (!function_exists('curl_init') || !function_exists('curl_version') || !function_exists('dns_get_record') || !defined('CURLOPT_RESOLVE') || !defined('CURLPROTO_HTTPS') || !defined('CURL_VERSION_SSL')) { return false; }
        $version=curl_version();
        // Lista wielu przypiętych A/AAAA wymaga libcurl 7.59 lub nowszego.
        return is_array($version) && in_array('https',$version['protocols']??[],true) && (($version['features']??0)&CURL_VERSION_SSL)!==0 && ($version['version_number']??0)>=0x073b00;
    }
    public static function publicIP(string $ip): bool {
        return filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_GLOBAL_RANGE)!==false && !str_starts_with(strtolower($ip),'::ffff:');
    }
    private static function fetchCIMD(string $id) {
        if (!self::transportAvailable()) { return self::error('invalid_client'); }
        // Metadata grants a choice from the current server catalog, not old tokens.
        $cacheKey='asm_mcp_cimd_'.hash('sha256',$id.'|'.implode(' ',array_keys(Policy::SCOPES)));
        $cached=get_transient($cacheKey);
        if (is_array($cached)) { return $cached; }
        $records=@dns_get_record('claude.ai',DNS_A|DNS_AAAA);
        if (!$records) { error_log('ASM MCP: nie można odczytać publicznych adresów DNS dokumentu Claude.'); return self::error('invalid_client'); }
        $addresses=[];
        foreach ($records as $record) {
            $ip=$record['ip']??$record['ipv6']??null;
            if ($ip===null) { continue; }
            if (!self::publicIP($ip)) { return self::error('invalid_client'); }
            $addresses[]=$ip;
        }
        if (!$addresses) { return self::error('invalid_client'); }
        $body=''; $oversized=false; $curl=curl_init($id);
        if ($curl===false) { return self::error('invalid_client'); }
        $pinned=array_map(static fn($ip)=>str_contains($ip,':')?'['.$ip.']':$ip,array_unique($addresses));
        $configured=curl_setopt_array($curl,[CURLOPT_RESOLVE=>['claude.ai:443:'.implode(',',$pinned)],CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>5,CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$body,&$oversized): int { if (strlen($body)+strlen($chunk)>16384) { $oversized=true; return 0; } $body.=$chunk; return strlen($chunk); }]);
        if (!$configured) { curl_close($curl); return self::error('invalid_client'); }
        $ok=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE); $type=curl_getinfo($curl,CURLINFO_CONTENT_TYPE); $peer=curl_getinfo($curl,CURLINFO_PRIMARY_IP); curl_close($curl);
        if ($ok===false || $oversized || $status!==200 || !is_string($type) || !preg_match('~^application/(?:[a-z0-9.+-]+\+)?json(?:\s*;|$)~i',$type) || !in_array($peer,$addresses,true)) { return self::error('invalid_client'); }
        $document=json_decode($body,true,16);
        if (!is_array($document) || ($document['client_id']??null)!==$id || !isset($document['grant_types'],$document['response_types'],$document['token_endpoint_auth_method']) || !is_array($document['grant_types']) || !is_array($document['response_types']) || !in_array('refresh_token',$document['grant_types'],true)) { return self::error('invalid_client'); }
        $validated=self::validate($document,true);
        if (is_wp_error($validated)) { return $validated; }
        // Zakresy serwera nie wynikają z deklaracji klienta.
        $validated['scope']=implode(' ',array_keys(Policy::SCOPES));
        set_transient($cacheKey,$validated,3600);
        return $validated;
    }
    public static function cleanup(): void {
        global $wpdb;
        $clients=self::table(); $credentials=Connections::table('oauth_credentials'); $connections=Connections::table('connections');
        $ok=$wpdb->query($wpdb->prepare("DELETE c FROM $clients c WHERE GREATEST(c.last_used,c.created_at)<%d AND NOT EXISTS (SELECT 1 FROM $credentials t JOIN $connections g ON g.id=t.connection_id WHERE t.client_id=c.client_id AND g.revoked=0 AND g.deleted_at=0 AND (g.expires_at=0 OR g.expires_at>%d))",time()-30*86400,time()));
        if ($ok===false) { error_log('ASM MCP: czyszczenie rejestracji OAuth nie powiodło się.'); }
        if ($wpdb->query($wpdb->prepare('DELETE FROM '.Connections::table('oauth_limits').' WHERE `window`<%d',time()-2*86400))===false) { error_log('ASM MCP: czyszczenie limitów OAuth nie powiodło się.'); }
    }
}
