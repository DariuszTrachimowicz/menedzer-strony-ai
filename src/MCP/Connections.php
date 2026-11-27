<?php
declare(strict_types=1);
namespace ASM\MCP;

final class Connections {
    public static bool $authTransaction=false;
    public static function table(string $name): string { global $wpdb; return $wpdb->prefix.'asm_mcp_'.$name; }
    public static function install(): void {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $collate = $wpdb->get_charset_collate();
        $sql = [
            'connections'=>"id varchar(32) NOT NULL, owner_id bigint unsigned NOT NULL, name varchar(100) NOT NULL, client varchar(32) NOT NULL, scope varchar(16) NOT NULL, token_hash varchar(64) NOT NULL, binding varchar(64) NOT NULL, issue_key varchar(64) NOT NULL, created_at bigint NOT NULL, expires_at bigint NOT NULL, revoked tinyint NOT NULL DEFAULT 0, deleted_at bigint NOT NULL DEFAULT 0, revoked_at bigint NOT NULL DEFAULT 0, last_used bigint DEFAULT NULL, last_status bigint DEFAULT NULL, rate_window bigint NOT NULL DEFAULT 0, rate_count int NOT NULL DEFAULT 0, PRIMARY KEY  (id), UNIQUE KEY issue_key (issue_key)",
            'operations'=>"id varchar(36) NOT NULL, connection_id varchar(32) NOT NULL, owner_id bigint unsigned NOT NULL, plan longtext NOT NULL, plan_hash varchar(64) NOT NULL, action varchar(32) NOT NULL, target_id bigint unsigned NOT NULL DEFAULT 0, status varchar(32) NOT NULL, expires_at bigint NOT NULL, created_at bigint NOT NULL, pre_hash varchar(64) NOT NULL, before_fp varchar(64) NOT NULL, before_date varchar(19) NOT NULL DEFAULT '', before_date_gmt varchar(19) NOT NULL DEFAULT '', after_fp varchar(64) NOT NULL DEFAULT '', new_id bigint unsigned NOT NULL DEFAULT 0, execution_key varchar(64) NOT NULL DEFAULT '', PRIMARY KEY  (id), KEY connection_id (connection_id)",
            'audit'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, connection_id varchar(32) NOT NULL, event varchar(64) NOT NULL, result varchar(32) NOT NULL, operation_id varchar(36) NOT NULL DEFAULT '', created_at bigint NOT NULL, PRIMARY KEY  (id), KEY connection_time (connection_id,created_at)"
        ];
        foreach ($sql as $name=>$fields) {
            // dbDelta parsuje jedną kolumnę lub indeks na wiersz. Nigdy nie dziel po przecinkach wewnątrz indeksów.
            $definitions=explode(', ',$fields);
            $table=self::table($name);
            dbDelta("CREATE TABLE $table (\n".implode(",\n",$definitions)."\n) ENGINE=InnoDB $collate;");
            $columns=$wpdb->get_col("SHOW COLUMNS FROM $table");
            if ($wpdb->last_error) { throw new \RuntimeException('storage'); }
            $indexes=$wpdb->get_results("SHOW INDEX FROM $table",ARRAY_A);
            if ($wpdb->last_error) { throw new \RuntimeException('storage'); }
            foreach ($definitions as $definition) {
                if (preg_match('/^(?:PRIMARY KEY|(?:UNIQUE )?KEY)/',$definition)) {
                    preg_match('/^(?:PRIMARY KEY|(?:UNIQUE )?KEY ([a-z_]+))\s+\(([^)]+)\)/',$definition,$match);
                    $key=$match[1]?:'PRIMARY';
                    $found=array_values(array_filter($indexes,static fn($index)=>$index['Key_name']===$key));
                    if (array_column($found,'Column_name')!==explode(',',$match[2]) || (($key==='PRIMARY' || str_starts_with($definition,'UNIQUE')) && (int)($found[0]['Non_unique']??1)!==0)) { throw new \RuntimeException('storage'); }
                } elseif (!in_array(strtok($definition,' '),$columns,true)) { throw new \RuntimeException('storage'); }
            }
        }
        if (!get_option('asm_mcp_install_id')) { add_option('asm_mcp_install_id',wp_generate_uuid4(),'',false); }
    }
    public static function binding(): string {
        global $wpdb;
        // Kanoniczny URL, tożsamość instalacji i definicje ról nie mogą pochodzić z
        // nieaktualnej trwałej pamięci podręcznej opcji po odtworzeniu kopii lub zmianie uprawnień.
        wp_cache_delete('alloptions','options');
        foreach (['home','asm_mcp_install_id',$wpdb->prefix.'user_roles'] as $key) { wp_cache_delete($key,'options'); }
        return hash('sha256', get_option('asm_mcp_install_id','').'|'.untrailingslashit(home_url()).'|'.wp_salt('auth').'|'.wp_salt('secure_auth'));
    }
    public static function issue(string $name, string $client, string $scope, int $expiry, string $issueKey) {
        global $wpdb;
        if (!current_user_can('manage_options') || !isset(Policy::SCOPES[$scope]) || !in_array($client,Admin::CLIENTS,true) || trim($name)==='' || mb_strlen($name)>100 || strlen($issueKey)<24 || ($expiry!==0 && ($expiry<=time() || $expiry>time()+366*86400))) { return Policy::error('invalid',400); }
        if ($scope==='full') {
            clean_user_cache(get_current_user_id());
            if (!user_can(get_current_user_id(),'asm_full_control')) { return Policy::error(); }
        }
        $lock='asm-mcp:issue:'.substr(hash('sha256',$wpdb->prefix.'|'.get_current_user_id()),0,36);
        $got=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock));
        if ($wpdb->last_error) { return Policy::error('storage',503); }
        if ((string)$got!=='1') { return Policy::error('busy',409); }
        try {
        $used=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::table('connections').' WHERE issue_key=%s',hash('sha256',$issueKey)));
        if ($wpdb->last_error) { return Policy::error('storage',503); }
        if ($used) { return new \WP_Error('asm_mcp_already_created','Ten formularz został już wykorzystany. Poświadczenie nie zostanie pokazane ponownie. Otwórz listę połączeń.', ['status'=>409]); }
        $count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::table('connections').' WHERE owner_id=%d AND revoked=0 AND deleted_at=0 AND (expires_at=0 OR expires_at>%d)',get_current_user_id(),time()));
        if ($wpdb->last_error || $count===null) { return Policy::error('storage',503); }
        if ((int)$count>=50) { return Policy::error('rate',429); }
        $id = bin2hex(random_bytes(16)); $secret = bin2hex(random_bytes(32));
        $row = ['id'=>$id,'owner_id'=>get_current_user_id(),'name'=>sanitize_text_field($name),'client'=>$client,'scope'=>$scope,'token_hash'=>hash('sha256',$secret),'binding'=>self::binding(),'issue_key'=>hash('sha256',$issueKey),'created_at'=>time(),'expires_at'=>$expiry];
        $old = $wpdb->suppress_errors(true);
        $ok = $wpdb->insert(self::table('connections'),$row);
        $wpdb->suppress_errors($old);
        if ($ok!==1) { return Policy::error('storage',409); }
        if (!self::audit($row,'connection.create','created')) { $wpdb->update(self::table('connections'),['revoked'=>1,'revoked_at'=>time()],['id'=>$id]); return Policy::error('storage',503); }
        unset($row['token_hash'],$row['binding'],$row['issue_key']);
        return ['connection'=>$row,'token'=>$id.'.'.$secret];
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); if ($wpdb->last_error) { error_log('ASM MCP: issue lock release failed.'); } }
    }
    public static function authenticate(string $header, bool $rate = false) {
        global $wpdb;
        if (!preg_match('/^Bearer ([a-f0-9]{32})\.([a-f0-9]{64})$/D',$header,$m)) { return Policy::error('auth',401); }
        $c = self::fresh($m[1]);
        if (!$c || !hash_equals($c['token_hash'],hash('sha256',$m[2])) || !self::valid($c)) { return Policy::error('auth',401); }
        if ($rate) { $limited=self::rate($c); if (is_wp_error($limited)) { return $limited; } }
        return $c;
    }
    public static function fresh(string $id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('connections').' WHERE id=%s'.(self::$authTransaction?' FOR UPDATE':''),$id),ARRAY_A);
        return $wpdb->last_error ? null : $row;
    }
    public static function valid(array $c): bool {
        if (self::$authTransaction) { return self::currentAuthorization($c); }
        // Pamięci podręczne użytkownika WP są jawnie czyszczone, aby cofnięcie roli sprawdzano w DB.
        clean_user_cache((int)$c['owner_id']);
        if ((int)$c['revoked'] || (int)($c['deleted_at']??0) || ((int)$c['expires_at']!==0 && time()>=(int)$c['expires_at']) || !hash_equals($c['binding'],self::binding()) || !isset(Policy::SCOPES[$c['scope']])) { return false; }
        wp_roles()->for_site();
        $allowed=user_can((int)$c['owner_id'],'manage_options') && ($c['scope']!=='full' || user_can((int)$c['owner_id'],'asm_full_control'));
        return $allowed && ((int)$c['expires_at']===0 || time()<(int)$c['expires_at']);
    }
    /** Powód do dziennika, dla którego połączenie nie przechodzi kontroli z valid() i OAuthGrant::authorized(); pusty tekst, gdy przechodzi. */
    public static function denial(array $c): string {
        // Usunięcie ustawia też revoked, więc sprawdzamy je pierwsze.
        if ((int)($c['deleted_at']??0)) { return 'denied_deleted'; }
        if ((int)$c['revoked']) { return 'denied_revoked'; }
        if ((int)$c['expires_at']!==0 && time()>=(int)$c['expires_at']) { return 'denied_expired'; }
        if (!isset(Policy::SCOPES[$c['scope']])) { return 'denied_scope'; }
        if (!hash_equals($c['binding'],self::binding())) { return 'denied_binding'; }
        clean_user_cache((int)$c['owner_id']);
        wp_roles()->for_site();
        if (!user_can((int)$c['owner_id'],'manage_options') || ($c['scope']!=='publicread' && !user_can((int)$c['owner_id'],'asm_full_control'))) { return 'denied_role'; }
        return '';
    }
    private static function currentAuthorization(array $c): bool {
        global $wpdb;
        if ((int)$c['revoked'] || (int)($c['deleted_at']??0) || ((int)$c['expires_at']!==0 && time()>=(int)$c['expires_at']) || !isset(Policy::SCOPES[$c['scope']])) { return false; }
        // Bieżące odczyty po utworzeniu snapshotu fingerprintu wpisu. Deterministyczna kolejność blokad:
        // connection -> user -> wszystkie metadane właściciela -> opcje home/install/ról.
        // Blokady są utrzymywane do COMMIT/ROLLBACK: późniejsze cofnięcia uprawnień są szeregowane po dispatch.
        $owner=(int)$c['owner_id'];
        $user=$wpdb->get_row($wpdb->prepare("SELECT * FROM $wpdb->users WHERE ID=%d FOR UPDATE",$owner));
        if ($wpdb->last_error || !$user) { return false; }
        $rows=$wpdb->get_results($wpdb->prepare("SELECT meta_key,meta_value FROM $wpdb->usermeta WHERE user_id=%d ORDER BY umeta_id FOR UPDATE",$owner),ARRAY_A);
        if ($wpdb->last_error) { return false; }
        $rolesKey=$wpdb->prefix.'user_roles';
        $options=$wpdb->get_results($wpdb->prepare("SELECT option_name,option_value FROM $wpdb->options WHERE option_name IN (%s,%s,%s) ORDER BY option_name FOR UPDATE",'home','asm_mcp_install_id',$rolesKey),OBJECT_K);
        if ($wpdb->last_error || !isset($options['home'],$options['asm_mcp_install_id'],$options[$rolesKey])) { return false; }
        $binding=hash('sha256',$options['asm_mcp_install_id']->option_value.'|'.untrailingslashit($options['home']->option_value).'|'.wp_salt('auth').'|'.wp_salt('secure_auth'));
        if (!hash_equals($c['binding'],$binding)) { return false; }
        // Zasil obliczanie uprawnień WP danymi z odczytów blokujących, nigdy ze snapshotu RR.
        clean_user_cache($owner); wp_cache_set($owner,$user,'users');
        $meta=[]; foreach ($rows as $row) { $meta[$row['meta_key']][]=$row['meta_value']; }
        wp_cache_set($owner,$meta,'user_meta');
        $roles=maybe_unserialize($options[$rolesKey]->option_value);
        if (!is_array($roles)) { return false; }
        $registry=wp_roles(); $registry->roles=$roles; $registry->role_objects=[]; $registry->role_names=[];
        foreach ($roles as $name=>$role) { $registry->role_objects[$name]=new \WP_Role($name,$role['capabilities']); $registry->role_names[$name]=$role['name']; }
        if (get_current_user_id()===$owner) { wp_set_current_user(0); wp_set_current_user($owner); }
        $allowed=user_can($owner,'manage_options') && ($c['scope']!=='full' || user_can($owner,'asm_full_control'));
        // Odczyty blokujące mogły czekać dłużej niż pozostała ważność grantu.
        return $allowed && ((int)$c['expires_at']===0 || time()<(int)$c['expires_at']);
    }
    public static function recheck(array $c) {
        $fresh = self::fresh($c['id']);
        return $fresh && self::valid($fresh) && (int)$fresh['owner_id']===(int)$c['owner_id'] && $fresh['scope']===$c['scope'] ? $fresh : Policy::error('auth',401);
    }
    public static function used(array $c, bool $status): bool {
        global $wpdb;
        $data = ['last_used'=>time()]; if ($status) { $data['last_status']=time(); }
        return $wpdb->update(self::table('connections'),$data,['id'=>$c['id']])!==false;
    }
    public static function rate(array $c) {
        global $wpdb;
        $window=intdiv(time(),60); $t=self::table('connections');
        $n=$wpdb->query($wpdb->prepare("UPDATE $t SET rate_count=IF(rate_window=%d,rate_count+1,1),rate_window=%d WHERE id=%s AND revoked=0 AND deleted_at=0 AND (expires_at=0 OR expires_at>%d) AND (rate_window<>%d OR rate_count<120)",$window,$window,$c['id'],time(),$window));
        if ($n===false) { return Policy::error('storage',503); }
        return $n===1 ? $c : Policy::error('rate',429);
    }
    public static function lockName(string $id): string {
        global $wpdb;
        return 'asm-mcp:connection:'.substr(hash('sha256',$wpdb->prefix.'|'.$id),0,36);
    }
    public static function revoke(string $id): bool { return self::mutate($id,'revoke'); }
    public static function delete(string $id): bool { return self::mutate($id,'delete'); }
    public static function updateExpiry(string $id,int $expiry): bool {
        if ($expiry!==0 && ($expiry<=time() || $expiry>time()+366*86400)) { return false; }
        return self::mutate($id,'expiry',$expiry);
    }
    private static function mutate(string $id,string $action,int $expiry=0): bool {
        global $wpdb;
        if (!current_user_can('manage_options') || !preg_match('/^[a-f0-9]{32}$/D',$id)) { return false; }
        $lock=self::lockName($id);
        $got=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock));
        if ($wpdb->last_error || (string)$got!=='1') { return false; }
        $transaction=false;
        try {
            if ($wpdb->query('START TRANSACTION')===false) { return false; }
            $transaction=true; self::$authTransaction=true;
            $c=self::fresh($id);
            if (!$c || (int)$c['owner_id']!==get_current_user_id()) { return false; }
            // Delete obejmuje również cofnięte i wygasłe wpisy. Termin wolno zmienić tylko żywemu grantowi.
            if ($action==='expiry' && !self::valid($c)) { return false; }
            $fields=$action==='expiry' ? ['expires_at'=>$expiry] : ['revoked'=>1,'revoked_at'=>(int)($c['revoked_at']??0)?:time()];
            if ($action==='delete') { $fields['deleted_at']=(int)($c['deleted_at']??0)?:time(); }
            if ($action==='expiry') {
                $table=self::table('connections');
                // Czas bazy jest oceniany przy samym zapisie, również dla nowej daty.
                $changed=$wpdb->query($wpdb->prepare("UPDATE $table SET expires_at=%d WHERE id=%s AND owner_id=%d AND revoked=0 AND deleted_at=0 AND (expires_at=0 OR expires_at>UNIX_TIMESTAMP()) AND (%d=0 OR (%d>UNIX_TIMESTAMP() AND %d<=UNIX_TIMESTAMP()+31622400))",$expiry,$id,get_current_user_id(),$expiry,$expiry,$expiry));
                if ($changed!==1) { return false; }
            } else {
                $changed=$wpdb->update(self::table('connections'),$fields,['id'=>$id,'owner_id'=>get_current_user_id()]);
            }
            if ($changed===false || !self::audit($c,'connection.'.$action,$action==='expiry'?'updated':($action==='delete'?'deleted':'revoked'))) { return false; }
            if ($wpdb->query('COMMIT')===false) { return false; }
            $transaction=false;
            return true;
        } finally {
            if ($transaction && $wpdb->query('ROLLBACK')===false) { error_log('ASM MCP: connection rollback failed.'); }
            self::$authTransaction=false;
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
            if ($wpdb->last_error) { error_log('ASM MCP: connection lock release failed.'); }
        }
    }
    public static function revokeAll(): bool {
        global $wpdb;
        if (!current_user_can('manage_options')) { return false; }
        $rows=$wpdb->get_results('SELECT id FROM '.self::table('connections').' WHERE revoked=0',ARRAY_A);
        if ($wpdb->last_error) { return false; }
        foreach ($rows as $c) { if (!self::audit($c,'connection.revoke_all','started')) { return false; } }
        if ($wpdb->query('UPDATE '.self::table('connections').' SET revoked=1,revoked_at='.time())===false) { return false; }
        foreach ($rows as $c) { if (!self::audit($c,'connection.revoke_all','revoked')) { return false; } }
        return true;
    }
    public static function audit(array $c,string $event,string $result,string $op=''): bool {
        global $wpdb;
        return $wpdb->insert(self::table('audit'),['connection_id'=>$c['id'],'event'=>$event,'result'=>$result,'operation_id'=>$op,'created_at'=>time()])===1;
    }
    public static function logs(array $c, int $limit=30): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT event,result,operation_id,created_at FROM '.self::table('audit').' WHERE connection_id=%s ORDER BY id DESC LIMIT %d',$c['id'],$limit),ARRAY_A);
        if ($wpdb->last_error) { throw new \RuntimeException('storage'); }
        return $rows;
    }
    public static function cleanup(): void {
        global $wpdb;
        $cutoff=time()-30*86400;
        $queries=[
            $wpdb->prepare('DELETE FROM '.self::table('audit').' WHERE created_at<%d',$cutoff),
            $wpdb->prepare("DELETE FROM ".self::table('operations')." WHERE created_at<%d AND status NOT IN ('executing','rolling_back','unknown')",$cutoff),
            $wpdb->prepare('DELETE c FROM '.self::table('connections').' c WHERE ((deleted_at>0 AND deleted_at<%d) OR (revoked=1 AND revoked_at>0 AND revoked_at<%d) OR (expires_at>0 AND expires_at<%d)) AND NOT EXISTS (SELECT 1 FROM '.self::table('operations').' o WHERE o.connection_id=c.id)',time()-90*86400,time()-90*86400,time()-90*86400)
        ];
        foreach ($queries as $sql) { if ($wpdb->query($sql)===false) { throw new \RuntimeException('storage'); } }
        OAuthGrant::cleanup();
        OAuthClients::cleanup();
        OAuthConsent::cleanup();
    }
}
