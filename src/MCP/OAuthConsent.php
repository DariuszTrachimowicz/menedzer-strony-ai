<?php
declare(strict_types=1);
namespace ASM\MCP;

/** Jednorazowa zgoda. W bazie wyłącznie hashe kontekstu, nigdy tokeny ani sekrety sesji. */
final class OAuthConsent {
    public const TTL=600;
    private const FIELDS=['client_id','redirect_uri','response_type','code_challenge','code_challenge_method','resource','scope','state'];
    public static function table(): string { return Connections::table('oauth_consents'); }
    public static function install(): void {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $table=self::table(); $collate=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (\nhash varchar(64) NOT NULL,\nowner_id bigint unsigned NOT NULL,\nsession_hash varchar(64) NOT NULL,\nrequest_hash varchar(64) NOT NULL,\nexpires_at bigint NOT NULL,\nconsumed_at bigint NOT NULL DEFAULT 0,\nPRIMARY KEY  (hash),\nKEY expires_at (expires_at)\n) ENGINE=InnoDB $collate;");
        $columns=$wpdb->get_col("SHOW COLUMNS FROM $table");
        if ($wpdb->last_error || array_diff(['hash','owner_id','session_hash','request_hash','expires_at','consumed_at'],$columns)) { throw new \RuntimeException('oauth_storage'); }
        $status=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s',$wpdb->esc_like($table)),ARRAY_A);
        if ($wpdb->last_error || !is_array($status) || strcasecmp($status['Engine']??'','InnoDB')!==0) { throw new \RuntimeException('oauth_storage'); }
    }
    private static function fingerprint(array $request): string {
        $context=[];
        foreach (self::FIELDS as $key) { $context[$key]=$request[$key]??($key==='scope'?null:''); }
        // Kodowanie do hasha zachowuje każdy bajt opaque state; nie zapisujemy ani nie odczytujemy serialize.
        return hash('sha256',serialize($context));
    }
    private static function session(): string {
        return hash_hmac('sha256',wp_get_session_token(),wp_salt('nonce'));
    }
    public static function create(array $request) {
        global $wpdb;
        if (!get_current_user_id() || wp_get_session_token()==='') { return OAuthClients::error('access_denied',403); }
        $id=bin2hex(random_bytes(32));
        $ok=$wpdb->insert(self::table(),['hash'=>hash('sha256',$id),'owner_id'=>get_current_user_id(),'session_hash'=>self::session(),'request_hash'=>self::fingerprint($request),'expires_at'=>time()+self::TTL]);
        return $ok===1?$id:OAuthClients::error('server_error',503);
    }
    public static function consume(string $id,array $request) {
        global $wpdb;
        if (!preg_match('/^[a-f0-9]{64}$/D',$id) || !get_current_user_id() || wp_get_session_token()==='') { return OAuthClients::error('invalid_request',409); }
        $table=self::table();
        $changed=$wpdb->query($wpdb->prepare("UPDATE $table SET consumed_at=UNIX_TIMESTAMP() WHERE hash=%s AND owner_id=%d AND session_hash=%s AND request_hash=%s AND consumed_at=0 AND expires_at>UNIX_TIMESTAMP()",hash('sha256',$id),get_current_user_id(),self::session(),self::fingerprint($request)));
        if ($changed===false) { return OAuthClients::error('server_error',503); }
        return $changed===1?true:OAuthClients::error('invalid_request',409);
    }
    public static function cleanup(): void {
        global $wpdb;
        if ($wpdb->query('DELETE FROM '.self::table().' WHERE expires_at<UNIX_TIMESTAMP()-600')===false) { throw new \RuntimeException('oauth_storage'); }
    }
}
