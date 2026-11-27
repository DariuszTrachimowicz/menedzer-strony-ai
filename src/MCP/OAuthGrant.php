<?php

declare(strict_types=1);
namespace ASM\MCP;

final class OAuthGrant {
    public const CODE_TTL=120;
    public const ACCESS_TTL=3600;
    private const REFRESH_RETRY_GRACE=30;
    // Powód odmowy odświeżenia ustalany w gałęzi, która odrzuca żądanie; do audytu trafia w locked().
    private static ?string $denial=null;
    public static function table(): string { return Connections::table('oauth_credentials'); }
    public static function install(): void {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $table=self::table(); $collate=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (\nhash varchar(64) NOT NULL,\nkind varchar(8) NOT NULL,\nconnection_id varchar(32) NOT NULL,\nclient_id varchar(120) NOT NULL,\nfamily varchar(32) NOT NULL,\nredirect_uri text NOT NULL,\nchallenge varchar(43) NOT NULL DEFAULT '',\nresource text NOT NULL,\nscope varchar(16) NOT NULL,\ncreated_at bigint NOT NULL,\nexpires_at bigint NOT NULL,\nconsumed_at bigint NOT NULL DEFAULT 0,\nPRIMARY KEY  (hash),\nKEY connection_id (connection_id),\nKEY family (family),\nKEY client_id (client_id)\n) ENGINE=InnoDB $collate;");
        $columns=$wpdb->get_col("SHOW COLUMNS FROM $table");
        if ($wpdb->last_error || array_diff(['hash','kind','connection_id','client_id','family','redirect_uri','challenge','resource','scope','created_at','expires_at','consumed_at'],$columns)) { throw new \RuntimeException('oauth_storage'); }
        $status=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s',$wpdb->esc_like($table)),ARRAY_A);
        if ($wpdb->last_error || !is_array($status) || strcasecmp($status['Engine']??'','InnoDB')!==0) { throw new \RuntimeException('oauth_storage'); }
    }
    public static function verifyPKCE(string $verifier,string $challenge): bool {
        return preg_match('/^[A-Za-z0-9._~-]{43,128}$/D',$verifier)===1 && preg_match('/^[A-Za-z0-9_-]{43}$/D',$challenge)===1 && hash_equals($challenge,rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='));
    }
    public static function authorized(array $connection): bool {
        return Connections::valid($connection) && ($connection['scope']==='publicread' || user_can((int)$connection['owner_id'],'asm_full_control'));
    }
    private static function insert(string $kind,array $binding,int $expiry) {
        global $wpdb;
        $secret=($kind==='code'?'oc.':($kind==='access'?'oa.':'or.')).bin2hex(random_bytes(32));
        $row=['hash'=>hash('sha256',$secret),'kind'=>$kind,'connection_id'=>$binding['connection_id'],'client_id'=>$binding['client_id'],'family'=>$binding['family'],'redirect_uri'=>$binding['redirect_uri']??'','challenge'=>$binding['challenge']??'','resource'=>$binding['resource'],'scope'=>$binding['scope'],'created_at'=>time(),'expires_at'=>$expiry];
        return $wpdb->insert(self::table(),$row)===1 ? $secret : OAuthClients::error('server_error',503);
    }
    public static function issueCode(array $request,string $scope,int $expiry) {
        $client=OAuthClients::resolve($request['client_id']);
        if (is_wp_error($client)) { return $client; }
        if (!in_array($scope,OAuthClients::scopes($client['scope'])??[],true)) { return OAuthClients::error('access_denied',403); }
        if (!current_user_can('manage_options') || !in_array($scope,isset($request['scope'])?(OAuthClients::scopes($request['scope'])??[]):(OAuthClients::scopes($client['scope'])??[]),true) || ($scope!=='publicread' && !current_user_can('asm_full_control'))) { return OAuthClients::error('access_denied',403); }
        $type=$request['client_id']===OAuthClients::CIMD[0]?'claude-cloud':($request['client_id']===OAuthClients::CIMD[1]?'claude-code':'other-remote');
        $issued=Connections::issue($client['client_name'],$type,$scope,$expiry,bin2hex(random_bytes(32)));
        if (is_wp_error($issued)) { return $issued; }
        $connection=$issued['connection'];
        $binding=['connection_id'=>$connection['id'],'client_id'=>$request['client_id'],'family'=>bin2hex(random_bytes(16)),'redirect_uri'=>$request['redirect_uri'],'challenge'=>$request['code_challenge'],'resource'=>$request['resource'],'scope'=>$scope];
        $code=self::insert('code',$binding,time()+self::CODE_TTL);
        if (is_wp_error($code) || !OAuthClients::touch($request['client_id'])) {
            if (!Connections::revoke($connection['id'])) { error_log('ASM MCP: nie można cofnąć niepełnej zgody OAuth.'); }
            return OAuthClients::error('server_error',503);
        }
        return $code;
    }
    private static function lookup(string $secret,string $kind) {
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE hash=%s AND kind=%s',hash('sha256',$secret),$kind),ARRAY_A);
        return $wpdb->last_error?OAuthClients::error('server_error',503):$row;
    }
    private static function locked(string $secret,string $kind,callable $exchange,bool $requireAuthorization=true) {
        global $wpdb;
        $initial=self::lookup($secret,$kind);
        if (is_wp_error($initial)) { return $initial; }
        if (!$initial) { return OAuthClients::error('invalid_grant'); }
        $lock=Connections::lockName($initial['connection_id']);
        if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!=='1') { return OAuthClients::error('temporarily_unavailable',503); }
        $committed=false; $connection=null; $record=null; self::$denial=null;
        try {
            if ($wpdb->query('START TRANSACTION')===false) { return OAuthClients::error('server_error',503); }
            Connections::$authTransaction=true;
            // Porządek blokad odpowiada operacjom i usunięciu połączenia.
            $connection=Connections::fresh($initial['connection_id']);
            if ($wpdb->last_error) { return OAuthClients::error('server_error',503); }
            if (!$connection || ($requireAuthorization && !self::authorized($connection))) { if ($connection) { $record='authorization'; } return OAuthClients::error('invalid_grant'); }
            $credential=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE hash=%s AND kind=%s FOR UPDATE',hash('sha256',$secret),$kind),ARRAY_A);
            if ($wpdb->last_error) { return OAuthClients::error('server_error',503); }
            if (!$credential) { return OAuthClients::error('invalid_grant'); }
            $reply=$exchange($credential,$connection);
            // Replay odwołuje rodzinę także wtedy, gdy odpowiedź zawiera invalid_grant.
            if ($wpdb->query('COMMIT')===false) { return OAuthClients::error('server_error',503); }
            $committed=true;
            // Do audytu trafia tylko odmowa, którą klient faktycznie dostał (nie błąd bazy ani nieudany COMMIT).
            if (is_wp_error($reply) && $reply->get_error_code()==='invalid_grant') { $record=self::$denial; }
            return $reply;
        } finally {
            if (!$committed && $wpdb->query('ROLLBACK')===false) { error_log('ASM MCP: cofnięcie transakcji OAuth nie powiodło się.'); }
            Connections::$authTransaction=false;
            // Po transakcji (odmowa ją wycofuje), ale jeszcze pod blokadą połączenia: równoległe odmowy nie dublują wpisu.
            try {
                if ($kind==='refresh' && $record!==null && $connection) { self::recordDenial($connection,$record==='authorization'?(Connections::denial($connection)?:'denied_unknown'):$record); }
            } catch (\Throwable $failure) {
                error_log('ASM MCP: zapis powodu odmowy odświeżenia nie powiódł się.');
            } finally {
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
            }
            if ($wpdb->last_error) { error_log('ASM MCP: zwolnienie blokady OAuth nie powiodło się.'); }
        }
    }
    private static function tokens(array $credential,array $connection) {
        $access=self::insert('access',$credential,time()+self::ACCESS_TTL);
        if (is_wp_error($access)) { return $access; }
        $refresh=self::insert('refresh',$credential,0);
        if (is_wp_error($refresh)) { return $refresh; }
        return ['access_token'=>$access,'token_type'=>'Bearer','expires_in'=>self::ACCESS_TTL,'refresh_token'=>$refresh,'scope'=>$credential['scope']];
    }
    public static function exchangeCode(array $request) {
        return self::locked($request['code'],'code',static function(array $credential,array $connection) use ($request) {
            global $wpdb;
            if ((int)$credential['consumed_at']) {
                // RFC 6749 4.1.2: drugie użycie kodu unieważnia tokeny już wydane na jego podstawie.
                if ($wpdb->update(Connections::table('connections'),['revoked'=>1,'revoked_at'=>time()],['id'=>$connection['id']])===false || !Connections::audit($connection,'oauth.code_replay','revoked')) { throw new \RuntimeException('oauth_storage'); }
                return OAuthClients::error('invalid_grant');
            }
            if ((int)$credential['expires_at']<=time() || !hash_equals($credential['client_id'],$request['client_id']) || !hash_equals($credential['redirect_uri'],$request['redirect_uri']) || !hash_equals($credential['resource'],$request['resource']) || !self::verifyPKCE($request['code_verifier'],$credential['challenge']) || $credential['scope']!==$connection['scope']) { return OAuthClients::error('invalid_grant'); }
            $used=$wpdb->update(self::table(),['consumed_at'=>time()],['hash'=>$credential['hash'],'consumed_at'=>0]);
            // Błąd bazy to nie ponowne użycie: klient dostaje 503 i ponawia, zamiast tracić połączenie.
            if ($used===false) { throw new \RuntimeException('oauth_storage'); }
            if ($used!==1) { self::$denial='denied_replay'; return OAuthClients::error('invalid_grant'); }
            $reply=self::tokens($credential,$connection);
            if (is_wp_error($reply)) { throw new \RuntimeException('oauth_storage'); }
            return $reply;
        });
    }
    public static function refresh(array $request) {
        return self::locked($request['refresh_token'],'refresh',static function(array $credential,array $connection) use ($request) {
            global $wpdb;
            if (!hash_equals($credential['client_id'],$request['client_id']) || !hash_equals($credential['resource'],$request['resource']) || $credential['scope']!==$connection['scope'] || (isset($request['scope']) && $request['scope']!==$credential['scope'])) { self::$denial='denied_request'; return OAuthClients::error('invalid_grant'); }
            if ((int)$credential['consumed_at']) {
                // Ponowienie tuż po wymianie (zgubiona odpowiedź, dwa procesy naraz) nie jest kradzieżą: klient dostaje
                // nową parę tokenów, bo poprzedniej mógł nie otrzymać. Po oknie ponowienia zużyty token zrywa połączenie.
                if ((int)$credential['consumed_at']>time()-self::REFRESH_RETRY_GRACE) {
                    if ((int)$credential['expires_at']!==0 && (int)$credential['expires_at']<=time()) { self::$denial='denied_expired'; return OAuthClients::error('invalid_grant'); }
                    if (!Connections::audit($connection,'oauth.refresh_retry','issued')) { throw new \RuntimeException('oauth_storage'); }
                    $reply=self::tokens($credential,$connection);
                    if (is_wp_error($reply)) { throw new \RuntimeException('oauth_storage'); }
                    return $reply;
                }
                if ($wpdb->update(Connections::table('connections'),['revoked'=>1,'revoked_at'=>time()],['id'=>$connection['id']])===false || !Connections::audit($connection,'oauth.refresh_replay','revoked')) { throw new \RuntimeException('oauth_storage'); }
                self::$denial='denied_replay';
                return OAuthClients::error('invalid_grant');
            }
            if ((int)$credential['expires_at']!==0 && (int)$credential['expires_at']<=time()) { self::$denial='denied_expired'; return OAuthClients::error('invalid_grant'); }
            $used=$wpdb->update(self::table(),['consumed_at'=>time()],['hash'=>$credential['hash'],'consumed_at'=>0]);
            // Błąd bazy to nie ponowne użycie: klient dostaje 503 i ponawia, zamiast tracić połączenie.
            if ($used===false) { throw new \RuntimeException('oauth_storage'); }
            if ($used!==1) { self::$denial='denied_replay'; return OAuthClients::error('invalid_grant'); }
            $reply=self::tokens($credential,$connection);
            if (is_wp_error($reply)) { throw new \RuntimeException('oauth_storage'); }
            return $reply;
        });
    }
    // Najwyżej jeden wpis odmowy na godzinę na połączenie; nieznany token nie zostawia śladu.
    private static function recordDenial(array $connection,string $reason): void {
        global $wpdb;
        $recent=$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Connections::table('audit').' WHERE connection_id=%s AND event=%s AND created_at>%d',$connection['id'],'oauth.refresh_denied',time()-3600));
        if ($wpdb->last_error || (int)$recent>0) { return; }
        if (!Connections::audit($connection,'oauth.refresh_denied',$reason)) { error_log('ASM MCP: nie zapisano powodu odmowy odświeżenia ('.$reason.').'); }
    }
    public static function authenticate(string $header,bool $rate=false) {
        if (!preg_match('/^Bearer (oa\.[a-f0-9]{64})$/D',$header,$match)) { return Policy::error('auth',401); }
        $credential=self::lookup($match[1],'access');
        if (is_wp_error($credential) || !$credential || (int)$credential['expires_at']<=time() || (int)$credential['consumed_at'] || !hash_equals($credential['resource'],OAuthDiscovery::resource())) { return Policy::error('auth',401); }
        $connection=Connections::fresh($credential['connection_id']);
        if (!$connection || !self::authorized($connection) || $connection['scope']!==$credential['scope']) { return Policy::error('auth',401); }
        return $rate?Connections::rate($connection):$connection;
    }
    public static function revoke(array $request): void {
        global $wpdb;
        $kind=str_starts_with($request['token'],'or.')?'refresh':'access';
        $initial=self::lookup($request['token'],$kind);
        if (is_wp_error($initial)) { throw new \RuntimeException('oauth_storage'); }
        if (!$initial || !hash_equals($initial['client_id'],$request['client_id'])) { return; }
        $reply=self::locked($request['token'],$kind,static function(array $credential,array $connection) use ($request) {
            global $wpdb;
            if (!hash_equals($credential['client_id'],$request['client_id'])) { return OAuthClients::error('invalid_grant'); }
            if ($wpdb->update(Connections::table('connections'),['revoked'=>1,'revoked_at'=>(int)$connection['revoked_at']?:time()],['id'=>$connection['id']])===false || !Connections::audit($connection,'oauth.revoke','revoked')) { throw new \RuntimeException('oauth_storage'); }
            return true;
        },false);
        // Tożsamość tokenu i klienta wystarcza do revoke, nawet po utracie roli, binding lub terminu.
        // Znany token z niepotwierdzonym zapisem nigdy nie otrzymuje potwierdzenia sukcesu.
        if (is_wp_error($reply)) { throw new \RuntimeException('oauth_storage'); }
    }
    public static function cleanup(): void {
        global $wpdb;
        $table=self::table(); $connections=Connections::table('connections');
        // Zużyte refresh zachowujemy dla replay przez cały czas ważnego grantu.
        $sql=$wpdb->prepare("DELETE t FROM $table t LEFT JOIN $connections c ON c.id=t.connection_id WHERE (t.kind='code' AND t.expires_at<%d) OR (t.kind='access' AND t.expires_at<%d) OR (t.kind='refresh' AND ((c.id IS NULL AND t.created_at<%d) OR (c.revoked<>0 AND c.revoked_at>0 AND c.revoked_at<%d) OR (c.deleted_at>0 AND c.deleted_at<%d) OR (c.expires_at<>0 AND c.expires_at<%d)))",time()-600,time()-86400,time()-90*86400,time()-90*86400,time()-90*86400,time()-90*86400);
        if ($wpdb->query($sql)===false) { error_log('ASM MCP: czyszczenie poświadczeń OAuth nie powiodło się.'); }
    }
}
