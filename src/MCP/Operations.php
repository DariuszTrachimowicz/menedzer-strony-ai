<?php
declare(strict_types=1);
namespace ASM\MCP;

final class Operations {
    public static function dependency(): bool { return defined('ASM_VERSION') && in_array(ASM_VERSION,['2.0.1','2.1.0','2.2.0'],true); }
    public static function upstream(array $c,string $path,array $body=[],string $key='') {
        if (!self::dependency()) { return Policy::error('dependency',503); }
        $fresh = Connections::recheck($c); if (is_wp_error($fresh)) { return $fresh; }
        if ($path==='preview') {
            $check=Policy::plan($fresh,array_intersect_key($body,array_flip(['action','target','change'])));
            if (is_wp_error($check)) { return $check; }
        } elseif (preg_match('~^([a-f0-9-]{36})/(execute|rollback)$~D',$path,$parts)) {
            $check=self::own($fresh,$parts[1]);
            if (is_wp_error($check)) { return $check; }
        } else { return Policy::error('invalid',400); }
        $prior = get_current_user_id();
        // W przeciwnym razie WordPress przesuwa datę szkicu, gdy ASM przywraca zerową datę GMT.
        // Zachowaj daty z niezmiennego snapshotu ASM, tylko w tym wywołaniu rollback dispatch.
        $dateGuard = null;
        if (preg_match('~^([a-f0-9-]{36})/rollback$~D',$path,$match)) {
            $owned = self::own($c,$match[1]);
            if (is_wp_error($owned)) { return $owned; }
            $targetId = (int)$owned['target_id'];
            if ($targetId>0) {
                $dates=['post_date'=>$owned['before_date'],'post_date_gmt'=>$owned['before_date_gmt']];
                $dateGuard = static function ($data,$postarr,$raw) use ($targetId,$dates) {
                    if ((int)($raw['ID']??0)===$targetId) {
                        foreach ($dates as $field=>$value) { $data[$field]=$value; }
                    }
                    return $data;
                };
                add_filter('wp_insert_post_data',$dateGuard,PHP_INT_MAX,3);
            }
        }
        try {
            wp_set_current_user((int)$c['owner_id']);
            if (!user_can((int)$c['owner_id'],'manage_options') || !user_can((int)$c['owner_id'],'asm_full_control')) { return Policy::error(); }
            $r = new \WP_REST_Request('POST','/asm/v2/operations/'.$path);
            $r->set_header('Content-Type','application/json');
            if ($key!=='') { $r->set_header('Idempotency-Key',$key); }
            $r->set_body(wp_json_encode($body));
            $response = rest_do_request($r);
            if ($response->is_error() || $response->get_status()>=400) { return Policy::error('conflict',409); }
            $data = $response->get_data();
            return is_array($data['data']??null) ? $data['data'] : Policy::error('storage',503);
        } finally { if ($dateGuard) { remove_filter('wp_insert_post_data',$dateGuard,PHP_INT_MAX); } wp_set_current_user($prior); }
    }
    public static function fingerprint(int $id): string {
        global $wpdb;
        // Czytaj bezpośrednio z DB. Pamięć podręczna obiektów i filtry WP_Post nie są źródłem warunków wstępnych.
        $post = $wpdb->get_row($wpdb->prepare("SELECT * FROM $wpdb->posts WHERE ID=%d",$id),ARRAY_A);
        if ($wpdb->last_error) { throw new \RuntimeException('storage'); }
        $meta = $wpdb->get_results($wpdb->prepare("SELECT meta_key,meta_value FROM $wpdb->postmeta WHERE post_id=%d ORDER BY meta_key,meta_value,meta_id",$id),ARRAY_A);
        if ($wpdb->last_error) { throw new \RuntimeException('storage'); }
        $terms = $wpdb->get_results($wpdb->prepare("SELECT term_taxonomy_id,term_order FROM $wpdb->term_relationships WHERE object_id=%d ORDER BY term_taxonomy_id",$id),ARRAY_A);
        if ($wpdb->last_error) { throw new \RuntimeException('storage'); }
        return hash('sha256',wp_json_encode([$post,$meta,$terms]));
    }
    public static function preview(array $c,array $plan) {
        global $wpdb;
        $p = Policy::plan($c,$plan); if (is_wp_error($p)) { return $p; }
        $id = (int)($p['target']['id']??0);
        $before = self::fingerprint($id);
        $dates=$id?$wpdb->get_row($wpdb->prepare("SELECT post_date,post_date_gmt FROM $wpdb->posts WHERE ID=%d",$id),ARRAY_A):['post_date'=>'','post_date_gmt'=>''];
        if ($wpdb->last_error || !$dates) { return Policy::error('storage',503); }
        if (!Connections::audit($c,'wp_operation_preview','started')) { return Policy::error('storage',503); }
        // ASM Schema wymaga niepustego obiektu expected. Ten nieszkodliwy hash pustej wartości tworzy serwer.
        $result = self::upstream($c,'preview',$p+['expected'=>['hash'=>'']]);
        if (is_wp_error($result)) { return $result; }
        if (!self::matches($result,$p) || ($result['status']??'')!=='previewed' || !preg_match('/^[a-f0-9-]{36}$/D',$result['id']??'')) { return Policy::error('storage',503); }
        if (!hash_equals($before,self::fingerprint($id))) { return Policy::error('conflict',409); }
        $json = wp_json_encode($p);
        $row = ['id'=>$result['id'],'connection_id'=>$c['id'],'owner_id'=>$c['owner_id'],'plan'=>$json,'plan_hash'=>hash('sha256',$json.'|'.$dates['post_date'].'|'.$dates['post_date_gmt']),'action'=>$p['action'],'target_id'=>$id,'status'=>'previewed','expires_at'=>time()+86400,'created_at'=>time(),'pre_hash'=>$result['precondition_hash'],'before_fp'=>$before,'before_date'=>$dates['post_date'],'before_date_gmt'=>$dates['post_date_gmt']];
        if ($wpdb->insert(Connections::table('operations'),$row)!==1) { return Policy::error('storage',503); }
        return self::dto($row);
    }
    private static function matches(array $result,array $plan): bool {
        return ($result['action']??'')===$plan['action'] && ($result['target']['type']??'')==='posts' && (!isset($plan['target']['id']) || (int)($result['target']['id']??0)===$plan['target']['id']);
    }
    public static function own(array $c,string $id) {
        global $wpdb;
        if (!preg_match('/^[a-f0-9-]{36}$/D',$id)) { return Policy::error('invalid',400); }
        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.Connections::table('operations').' WHERE id=%s AND connection_id=%s AND owner_id=%d',$id,$c['id'],$c['owner_id']),ARRAY_A);
        if ($wpdb->last_error) { return Policy::error('storage',503); }
        if (!$r) { return Policy::error(); }
        if (!hash_equals($r['plan_hash'],hash('sha256',$r['plan'].'|'.$r['before_date'].'|'.$r['before_date_gmt']))) { return Policy::error('storage',503); }
        $plan = json_decode($r['plan'],true);
        if (!is_array($plan)) { return Policy::error('storage',503); }
        if (($plan['action']??null)!==$r['action'] || (int)($plan['target']['id']??0)!==(int)$r['target_id']) { return Policy::error('storage',503); }
        $fresh = Connections::recheck($c); if (is_wp_error($fresh)) { return $fresh; }
        clean_post_cache((int)$r['target_id']);
        $policy = Policy::plan($fresh,$plan,false); if (is_wp_error($policy)) { return $policy; }
        if ((int)$r['new_id']>0) {
            $new = get_post((int)$r['new_id']);
            if ($new && (!user_can((int)$c['owner_id'],'edit_post',$new->ID) || ($c['scope']!=='publish' && !in_array($new->post_status,['draft','pending'],true)))) { return Policy::error(); }
        }
        return $r;
    }
    public static function dto(array $r): array {
        $plan = json_decode($r['plan'],true);
        return ['id'=>$r['id'],'action'=>$r['action'],'target'=>['type'=>'posts','id'=>(int)(($r['new_id']??0)?:$r['target_id'])],'status'=>$r['status'],'precondition_hash'=>$r['pre_hash'],'change_fields'=>array_keys($plan['change']),'expires_at'=>gmdate('c',(int)$r['expires_at'])];
    }
    public static function status(array $c,string $id) { $r=self::own($c,$id); return is_wp_error($r)?$r:self::dto($r); }
    private static function save(string $id,array $fields): void {
        global $wpdb;
        if ($wpdb->update(Connections::table('operations'),$fields,['id'=>$id])!==1) { throw new \RuntimeException('storage'); }
    }
    private static function rowLocks(int $id): void {
        global $wpdb;
        // Odmów rollbacku na magazynie bez obsługi transakcji. Blokady szeregują też zapisy natywnych edytorów WP.
        foreach ([$wpdb->posts,$wpdb->postmeta,$wpdb->term_relationships,$wpdb->comments,$wpdb->users,$wpdb->usermeta,$wpdb->options,Connections::table('connections'),Connections::table('operations'),$wpdb->prefix.'asm_operations',$wpdb->prefix.'asm_idempotency',$wpdb->prefix.'asm_audit'] as $table) {
            $engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));
            if ($wpdb->last_error) { throw new \RuntimeException('storage'); }
            if (strtoupper((string)$engine)!=='INNODB') { throw new \RuntimeException('transaction_unsupported'); }
        }
        if ($wpdb->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')===false || $wpdb->query('START TRANSACTION')===false) { throw new \RuntimeException('storage'); }
        Connections::$authTransaction=true;
        foreach (["SELECT ID FROM $wpdb->posts WHERE ID=%d FOR UPDATE","SELECT meta_id FROM $wpdb->postmeta WHERE post_id=%d FOR UPDATE","SELECT term_taxonomy_id FROM $wpdb->term_relationships WHERE object_id=%d FOR UPDATE","SELECT ID FROM $wpdb->posts WHERE post_parent=%d FOR UPDATE","SELECT comment_ID FROM $wpdb->comments WHERE comment_post_ID=%d FOR UPDATE"] as $sql) {
            $wpdb->get_results($wpdb->prepare($sql,$id));
            if ($wpdb->last_error) { throw new \RuntimeException('storage'); }
        }
        clean_post_cache($id);
    }
    public static function run(array $c,string $id,bool $rollback,string $key='') {
        global $wpdb;
        $r = self::own($c,$id); if (is_wp_error($r)) { return $r; }
        $target = (int)($r['new_id']?:$r['target_id']);
        $lock = 'asm-mcp:'.substr(hash('sha256',$wpdb->prefix.'|posts|'.($target?:'create')),0,48);
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock));
        if ($wpdb->last_error) { return Policy::error('storage',503); }
        if ((string)$got!=='1') { return Policy::error('busy',409); }
        $transaction = false;
        try {
            $r = self::own($c,$id); if (is_wp_error($r)) { return $r; }
            $plan = json_decode($r['plan'],true);
            if (!$rollback && $r['status']==='verified') { return self::dto($r); }
            if ($rollback && $r['status']==='rolled_back') { return self::dto($r); }
            if (in_array($r['status'],['executing','rolling_back','unknown'],true)) { return Policy::error('busy',409); }
            $expected = $rollback?'verified':'previewed'; $next=$rollback?'rolling_back':'executing';
            if ($r['status']!==$expected || (!$rollback && time()>=(int)$r['expires_at'])) { return Policy::error('conflict',409); }
            $p = Policy::plan($c,$plan,!$rollback); if (is_wp_error($p)) { return $p; }
            if (!Connections::audit($c,$rollback?'rollback':'execute','started',$id)) { return Policy::error('storage',503); }
            $namespace = hash('sha256',$c['id'].'|'.$id.'|'.$key);
            $cas = $wpdb->query($wpdb->prepare('UPDATE '.Connections::table('operations').' SET status=%s,execution_key=%s WHERE id=%s AND connection_id=%s AND status=%s',$next,$rollback?$r['execution_key']:$namespace,$id,$c['id'],$expected));
            if ($cas===false) { return Policy::error('storage',503); }
            if ($cas!==1) { return Policy::error('busy',409); }
            // Efekt zmiany wpisu i jego after-fingerprint są zatwierdzane razem. W przeciwnym razie natywna
            // edycja między ASM apply a pomiarem fingerprintu mogłaby zostać uznana za nasz efekt.
            $transaction=true; self::rowLocks($target);
            if ($rollback) {
                if (!hash_equals($r['after_fp'],self::fingerprint($target))) {
                    $wpdb->query('ROLLBACK'); $transaction=false;
                    self::save($id,['status'=>'verified']);
                    return Policy::error('conflict',409);
                }
                if ($r['action']==='content.create') {
                    $related = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->posts WHERE post_parent=%d",$target));
                    if ($wpdb->last_error) { throw new \RuntimeException('storage'); }
                    $related += (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->comments WHERE comment_post_ID=%d",$target));
                    if ($wpdb->last_error) { throw new \RuntimeException('storage'); }
                    $new = get_post($target);
                    if ($related || !$new || !in_array($new->post_status,['draft','pending'],true)) {
                        $wpdb->query('ROLLBACK'); $transaction=false; self::save($id,['status'=>'verified']); return Policy::error('conflict',409);
                    }
                }
            } elseif (!hash_equals($r['before_fp'],self::fingerprint((int)$r['target_id']))) {
                $wpdb->query('ROLLBACK'); $transaction=false;
                self::save($id,['status'=>'conflict']); return Policy::error('conflict',409);
            }
            // Świeża autoryzacja i polityka obiektu bezpośrednio przed jedyną dozwoloną mutacją upstream.
            $fresh = Connections::recheck($c); if (is_wp_error($fresh)) { return $fresh; }
            $p = Policy::plan($fresh,$plan,!$rollback); if (is_wp_error($p)) { return $p; }
            if (!$rollback && time()>=(int)$r['expires_at']) {
                $wpdb->query('ROLLBACK'); $transaction=false; self::save($id,['status'=>'conflict']); return Policy::error('conflict',409);
            }
            $expectedContent = null;
            if (!$rollback && $r['action']==='content.replace') {
                $ch=$plan['change']; $expectedContent = str_replace($ch['old'],$ch['new'],get_post($target)->{$ch['field']});
            }
            $result = self::upstream($fresh,$id.'/'.($rollback?'rollback':'execute'),[],$rollback?'':$namespace);
            if (is_wp_error($result)) { return $result; }
            if (($result['id']??'')!==$id || !self::matches($result,$plan)) { throw new \RuntimeException('storage'); }
            $newId = $r['action']==='content.create'?(int)($result['target']['id']??0):0;
            $actual = $newId?:$target;
            clean_post_cache($actual);
            $status = $result['status']??'unknown';
            if (!in_array($status,['verified','rolled_back','failed','conflict','unknown','rollback_failed'],true)) { $status='unknown'; }
            if (!$rollback && $status==='verified') {
                $post=get_post($actual);
                if (!$post || ($expectedContent!==null && $post->{$plan['change']['field']}!==$expectedContent)) { $status='unknown'; }
                if ($r['action']!=='content.replace') {
                    foreach ($plan['change'] as $field=>$value) { if (!$post || (string)$post->{$field}!==$value) { $status='unknown'; } }
                }
            }
            if ($rollback && $status==='rolled_back' && !hash_equals($r['before_fp'],self::fingerprint((int)$r['target_id']))) { $status='rollback_failed'; }
            if ($status!==($rollback?'rolled_back':'verified')) {
                if ($wpdb->query('ROLLBACK')===false) { throw new \RuntimeException('storage'); }
                $transaction=false; Connections::$authTransaction=false; clean_post_cache($actual);
                // Utrwalaj dopiero PO rollbacku; SQL rollback cofa też stan upstream.
                // Trwały CAS pozostaje i zapobiega ponowieniu, jeśli ten zapis zawiedzie lub proces ulegnie awarii.
                self::save($id,['status'=>$status]);
                if (!Connections::audit($c,$rollback?'rollback':'execute',$status,$id)) { throw new \RuntimeException('storage'); }
                return Policy::error('storage',503);
            }
            $fields=['status'=>$status,'new_id'=>$newId?:$r['new_id']];
            if (!$rollback && $status==='verified') { $fields['after_fp']=self::fingerprint($actual); }
            self::save($id,$fields);
            if (!Connections::audit($c,$rollback?'rollback':'execute',$status,$id)) { throw new \RuntimeException('storage'); }
            if ($transaction) { if ($wpdb->query('COMMIT')===false) { throw new \RuntimeException('storage'); } $transaction=false; }
            $r=array_merge($r,$fields);
            return self::dto($r);
        } catch (\Throwable $e) {
            error_log('ASM MCP: operation failure '.get_class($e));
            return Policy::error('storage',503);
        } finally {
            if ($transaction) { $wpdb->query('ROLLBACK'); clean_post_cache($target); if (isset($actual) && $actual!==$target) { clean_post_cache($actual); } }
            Connections::$authTransaction=false;
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
            if ($wpdb->last_error) { error_log('ASM MCP: advisory lock release failed.'); }
        }
    }
}
