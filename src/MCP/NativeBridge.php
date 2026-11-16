<?php
declare(strict_types=1);
namespace ASM\MCP;

use ASM\Auth\CapabilityMap;
use ASM\Operations\Operation;
use ASM\Operations\OperationEngine;
use ASM\Operations\OperationFailure;

/** Zamknięty most do natywnego kontraktu ASM. Mapowanie nie zawiera planów ani snapshotów. */
final class NativeBridge {
    private static ?OperationEngine $engine = null;
    private const TYPES = ['posts','options','users','comments','cron','menus','cache','core','popup','media','files','database','plugins','themes'];
    private const ACTIONS = [
        'content.create'=>'content.write','content.update'=>'content.write','content.replace'=>'content.write','content.meta'=>'content.write','content.delete'=>'content.delete','elementor.update'=>'content.write',
        'menu.create'=>'menu.write','menu.update'=>'menu.write','menu.delete'=>'menu.write',
        'option.update'=>'options.write','option.replace_secret'=>'options.write','option.delete'=>'options.write',
        'user.create'=>'users.write','user.update'=>'users.write','user.change_password'=>'users.write','user.delete'=>'users.delete',
        'comment.update'=>'comments.write','comment.delete'=>'comments.write',
        'cron.schedule'=>'cron.write','cron.run'=>'cron.write','cron.delete'=>'cron.write','cache.purge'=>'cache.purge','core.update'=>'core.update','popup.update'=>'popup.write',
        'media.create'=>'media.write','media.update'=>'media.write','media.replace'=>'media.write','media.delete'=>'media.delete',
        'file.create'=>'file.write','file.write'=>'file.write','file.patch'=>'file.write','file.move'=>'file.write','file.delete'=>'file.delete','database.execute'=>'database.execute',
        'plugin.install'=>'plugins.install','plugin.update'=>'plugins.update','plugin.activate'=>'plugins.activate','plugin.deactivate'=>'plugins.activate','plugin.delete'=>'plugins.delete',
        'theme.install'=>'themes.install','theme.update'=>'themes.update','theme.activate'=>'themes.activate','theme.delete'=>'themes.delete'
    ];
    public static function configure(OperationEngine $engine): void { self::$engine=$engine; }
    public static function schemas(): array {
        $s=['type'=>'string']; $o=['type'=>'object','additionalProperties'=>true];
        $type=['type'=>'string','enum'=>self::TYPES];
        $schemas=[
            'wp_inventory'=>['Odczytuje natywny katalog zasobów i dostępnych akcji ASM.',[]],
            'wp_capabilities'=>['Sprawdza bieżące uprawnienia operatora.',[]],
            'wp_resource_list'=>['Lista natywnych zasobów. params przyjmuje filtry właściwe dla typu zasobu.',['type'=>$type,'params'=>$o]],
            'wp_resource_get'=>['Odczytuje natywny zasób z redakcją sekretów, metadanymi i taksonomiami.',['type'=>$type,'id'=>['type'=>['string','integer']]]],
            'wp_search'=>['Szuka w natywnych obszarach ASM z filters.',['areas'=>['type'=>'array','items'=>$s],'needle'=>$s,'filters'=>$o]],
            'wp_media_stage'=>['Pobiera obraz przez HTTPS do prywatnego stagingu. Token jest przypisany do tego połączenia.',['url'=>$s]],
            'wp_operation_preview'=>['Najpierw odczytaj zasób. Podgląd tworzy niezmienny plan ważny 24 godziny; potem execute, po niejasnej odpowiedzi status.',['action'=>['type'=>'string','enum'=>array_keys(self::ACTIONS)],'target'=>$o,'change'=>$o,'expected'=>$o,'verification'=>$o]],
            'wp_operation_execute'=>['Wykonuje własny podgląd raz. Po niejasnej odpowiedzi użyj status, bez nowego zapisu.',['operation_id'=>$s,'confirm'=>['type'=>'boolean','const'=>true],'idempotency_key'=>['type'=>'string','minLength'=>1,'maxLength'=>128]]],
            'wp_operation_status'=>['Odczytuje własny stan bez ponawiania mutacji. unknown nie potwierdza sukcesu.',['operation_id'=>$s]],
            'wp_operation_rollback'=>['Cofa własną zmianę tylko przy mierzalnym, niezmienionym stanie. Nieodwracalne efekty wymagają ręcznej naprawy.',['operation_id'=>$s,'confirm'=>['type'=>'boolean','const'=>true]]]
        ];
        $required=['wp_resource_list'=>['type'],'wp_resource_get'=>['type','id'],'wp_search'=>['areas','needle'],'wp_media_stage'=>['url'],'wp_operation_preview'=>['action','target','change','expected'],'wp_operation_execute'=>['operation_id','confirm','idempotency_key'],'wp_operation_status'=>['operation_id'],'wp_operation_rollback'=>['operation_id','confirm']];
        $out=[]; foreach($schemas as $name=>[$description,$properties]) { $out[]=['name'=>$name,'description'=>$description,'inputSchema'=>['type'=>'object','properties'=>(object)$properties,'required'=>$required[$name]??[],'additionalProperties'=>false]]; }
        return $out;
    }
    public static function call(array $c,string $name,array $a) {
        global $wpdb;
        $fresh=Connections::recheck($c); if(is_wp_error($fresh)) { return $fresh; }
        if($fresh['scope']!=='full' || !self::$engine) { return Policy::error(); }
        $schema=null; foreach(self::schemas() as $candidate) { if($candidate['name']===$name) { $schema=$candidate['inputSchema']; break; } }
        if(!$schema || !Policy::closed($a,array_keys((array)$schema['properties']),$schema['required'])) { return Policy::error('invalid',400); }
        $lock=Connections::lockName($c['id']);
        $got=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock));
        if($wpdb->last_error || (string)$got!=='1') { return Policy::error('busy',409); }
        $prior=get_current_user_id();
        try {
            $fresh=Connections::recheck($c); if(is_wp_error($fresh)) { return $fresh; }
            wp_set_current_user(0); wp_set_current_user((int)$fresh['owner_id']);
            if($name==='wp_inventory' || $name==='wp_capabilities') { return self::request('GET',$name==='wp_inventory'?'/inventory':'/capabilities'); }
            if($name==='wp_resource_list' || $name==='wp_resource_get') {
                if(!is_string($a['type']) || !in_array($a['type'],self::TYPES,true)) { return Policy::error('invalid',400); }
                $path='/resources/'.$a['type']; $params=$a['params']??[];
                if(is_array($params) && array_key_exists('id',$params)) { return Policy::error('invalid',400); }
                if($name==='wp_resource_get') {
                    if((!is_string($a['id']) && !is_int($a['id'])) || (string)$a['id']==='' || strlen((string)$a['id'])>190) { return Policy::error('invalid',400); }
                    $path.='/'.rawurlencode((string)$a['id']);
                    $params['id']=(string)$a['id'];
                }
                if(!is_array($params) || array_intersect(array_keys($params),['type','route','_method','rest_route','action','context'])) { return Policy::error('invalid',400); }
                return self::request('GET',$path,$params);
            }
            if($name==='wp_search') {
                if(!is_array($a['areas']) || !is_string($a['needle']) || (isset($a['filters']) && (!is_array($a['filters']) || array_intersect(array_keys($a['filters']),['route','type','id','_method','rest_route'])))) { return Policy::error('invalid',400); }
                return self::request('POST','/search',$a);
            }
            if($name==='wp_media_stage') {
                if(!is_string($a['url']) || strlen($a['url'])>2048 || strtolower((string)parse_url($a['url'],PHP_URL_SCHEME))!=='https') { return Policy::error('invalid',400); }
                $staged=self::request('POST','/uploads',$a); if(is_wp_error($staged)) { return $staged; }
                if(!is_string($staged['token']??null) || !is_int($staged['expires']??null)) { return Policy::error('storage',503); }
                if(!self::map($fresh,wp_generate_uuid4(),'mcp.stage','staged',$staged['expires'],hash('sha256',$staged['token']))) { return Policy::error('storage',503); }
                return $staged;
            }
            if($name==='wp_operation_preview') {
                if(!is_string($a['action']) || !is_array($a['target']) || !is_array($a['change']) || !is_array($a['expected']) || (isset($a['verification']) && !is_array($a['verification'])) || !Policy::closed($a['target'],['type','id','path','menu_id','hook','timestamp','args']) || !is_string($a['target']['type']??null) || !in_array($a['target']['type'],self::TYPES,true)) { return Policy::error('invalid',400); }
                if(in_array($a['action'],['content.create','media.create','user.create','menu.create'],true) && array_keys($a['target'])!==['type']) { return Policy::error('invalid',400); }
                foreach(['id','path','hook'] as $field) { if(isset($a['target'][$field]) && !is_string($a['target'][$field]) && !is_int($a['target'][$field])) { return Policy::error('invalid',400); } }
                $prefix=strtok($a['action'],'.');
                $types=['content'=>'posts','elementor'=>'posts','option'=>'options','user'=>'users','comment'=>'comments','menu'=>'menus','file'=>'files','plugin'=>'plugins','theme'=>'themes','media'=>'media','cron'=>'cron','core'=>'core','database'=>'database','cache'=>'cache','popup'=>'popup'];
                if(($types[$prefix]??null)!==$a['target']['type']) { return Policy::error('invalid',400); }
                if($prefix==='file') {
                    $resolved=(new \ASM\Filesystem\PathPolicy([ABSPATH]))->resolve((string)($a['target']['path']??$a['target']['id']??''),false);
                    if(is_wp_error($resolved)) { return $resolved; }
                    $a['target']['id']=$resolved; $a['target']['path']=$resolved;
                }
                $allowed=self::authorize($a['action'],$a['target'],$a['change']); if(is_wp_error($allowed)) { return $allowed; }
                $stage=self::checkStage($fresh,$a['action'],$a['change']); if(is_wp_error($stage)) { return $stage; }
                $op=self::$engine->preview($a,(int)$fresh['owner_id'],true);
                if(!self::map($fresh,$op->id(),$op->action(),$op->status(),time()+86400,hash('sha256','native:'.$op->id()))) { return Policy::error('storage',503); }
                return self::dto($op,time()+86400);
            }
            if(!is_string($a['operation_id']) || !preg_match('/^[a-f0-9-]{36}$/D',$a['operation_id']) || ($name!=='wp_operation_status' && $a['confirm']!==true)) { return Policy::error('invalid',400); }
            $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Connections::table('operations').' WHERE id=%s AND connection_id=%s AND owner_id=%d',$a['operation_id'],$fresh['id'],$fresh['owner_id']),ARRAY_A);
            if($wpdb->last_error) { return Policy::error('storage',503); }
            if(!$row || $row['plan']!=='{}' || !hash_equals(hash('sha256','native:'.$row['id']),$row['plan_hash'])) { return Policy::error(); }
            $op=self::$engine->get($row['id']);
            if($op->id()!==$row['id'] || $op->actorId()!==(int)$fresh['owner_id'] || $op->action()!==$row['action'] || !$op->guardedRollback()) { return Policy::error(); }
            $allowed=self::authorize($op->action(),$op->target(),$op->change(),$op->snapshot()); if(is_wp_error($allowed)) { return $allowed; }
            if($name==='wp_operation_execute') {
                if(!is_string($a['idempotency_key']) || !preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D',$a['idempotency_key'])) { return Policy::error('invalid',400); }
                if($op->status()==='previewed' && (int)$row['expires_at']<=time()) { return Policy::error('conflict',409); }
                if($op->status()==='previewed') { $stage=self::checkStage($fresh,$op->action(),$op->change()); if(is_wp_error($stage)) { return $stage; } }
                $key=hash('sha256',$fresh['id'].'|'.$op->id().'|'.$a['idempotency_key']);
                if($row['execution_key']!=='' && !hash_equals($row['execution_key'],$key)) { return Policy::error('conflict',409); }
                if($wpdb->update(Connections::table('operations'),['execution_key'=>$key],['id'=>$row['id']])===false) { return Policy::error('storage',503); }
                $op=self::$engine->execute($row['id'],'mcp-full:'.$key);
            } elseif($name==='wp_operation_rollback') {
                if($op->status()==='verified' && $op->afterHash()!==null) { $allowed=self::authorizeRollback($op); if(is_wp_error($allowed)) { return $allowed; } }
                $op=self::$engine->rollback($row['id']);
            }
            if($op->id()!==$row['id']) { return Policy::error('storage',503); }
            if($wpdb->update(Connections::table('operations'),['status'=>$op->status()],['id'=>$row['id']])===false) { return Policy::error('storage',503); }
            return self::dto($op,(int)$row['expires_at']);
        } catch(OperationFailure $e) { return new \WP_Error($e->operationCode(),'Operacja została zatrzymana: '.$e->operationCode().'.',['status'=>409]); }
        catch(\Throwable $e) { error_log('ASM MCP: native bridge failure '.get_class($e)); return Policy::error('storage',503); }
        finally { wp_set_current_user($prior); $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); if($wpdb->last_error) { error_log('ASM MCP: native lock release failed.'); } }
    }
    private static function request(string $method,string $path,array $params=[]) {
        $r=new \WP_REST_Request($method,'/asm/v2'.$path);
        if($method==='GET') { $r->set_query_params($params); } else { $r->set_header('content-type','application/json'); $r->set_body(wp_json_encode($params)); }
        $response=rest_do_request($r);
        if($response->is_error() || $response->get_status()>=400) { return new \WP_Error('asm_mcp_native_denied','Natywne API odmówiło żądania.',['status'=>$response->get_status()]); }
        $body=$response->get_data(); return $body['data']??$body;
    }
    private static function authorize(string $action,array $target,array $change,array $snapshot=[]) {
        $key=self::ACTIONS[$action]??''; $caps=CapabilityMap::forAction($key);
        if(!$caps) { return Policy::error('invalid',400); }
        foreach($caps as $cap) { if(!current_user_can($cap)) { return Policy::error(); } }
        if(str_starts_with($action,'content.') || $action==='elementor.update') {
            $post=isset($target['id'])?get_post((int)$target['id']):null;
            if($action!=='content.create' && !$post) {
                if($action!=='content.delete' || !isset($snapshot['post'])) { return Policy::error(); }
                $saved=(object)$snapshot['post']; $object=get_post_type_object($saved->post_type);
                if(!$object || !current_user_can($object->cap->delete_posts) || ((int)$saved->post_author!==get_current_user_id() && !current_user_can($object->cap->delete_others_posts)) || ($saved->post_status==='publish' && !current_user_can($object->cap->delete_published_posts)) || ($saved->post_status==='private' && !current_user_can($object->cap->delete_private_posts))) { return Policy::error(); }
                return true;
            }
            $type=$post?$post->post_type:($change['post_type']??'post'); $object=is_string($type)?get_post_type_object($type):null;
            if(!$object || !current_user_can($object->cap->edit_posts) || ($action==='content.create' && !current_user_can($object->cap->create_posts)) || ($post && !current_user_can($action==='content.delete'?'delete_post':'edit_post',$post->ID))) { return Policy::error(); }
            $destinationType=$change['post_type']??$type;
            $destination=is_string($destinationType)?get_post_type_object($destinationType):null;
            if(!$destination || !current_user_can($destination->cap->edit_posts)) { return Policy::error(); }
            $author=(int)($change['post_author']??($post?$post->post_author:get_current_user_id()));
            $status=(string)($change['post_status']??($post?$post->post_status:'draft'));
            if($author!==get_current_user_id() && !current_user_can($destination->cap->edit_others_posts)) { return Policy::error(); }
            if($post) {
                foreach(array_unique([$post->post_status,$status]) as $effectiveStatus) {
                    if(in_array($effectiveStatus,['publish','future'],true) && !current_user_can($destination->cap->edit_published_posts)) { return Policy::error(); }
                    if($effectiveStatus==='private' && $author!==get_current_user_id() && !current_user_can($destination->cap->edit_private_posts)) { return Policy::error(); }
                }
            }
            if(!$destination->map_meta_cap && !current_user_can($destination->cap->edit_post)) { return Policy::error(); }
            if(in_array($status,['publish','future','private'],true) && !current_user_can($destination->cap->publish_posts)) { return Policy::error(); }
            if($action==='content.delete' && !current_user_can($object->cap->delete_posts)) { return Policy::error(); }
            if(in_array($change['post_status']??($post?$post->post_status:''),['publish','future','private'],true) && !current_user_can($object->cap->publish_posts)) { return Policy::error(); }
        }
        if(str_starts_with($action,'user.') && $action!=='user.create' && !current_user_can($action==='user.delete'?'delete_user':'edit_user',(int)($target['id']??0))) { return Policy::error(); }
        if(str_starts_with($action,'user.') && isset($change['role']) && !current_user_can('promote_users')) { return Policy::error(); }
        if($action==='user.create' && !current_user_can('create_users')) { return Policy::error(); }
        if(str_starts_with($action,'media.') && isset($target['id']) && !current_user_can($action==='media.delete'?'delete_post':'edit_post',(int)$target['id'])) { return Policy::error(); }
        return true;
    }
    private static function authorizeRollback(Operation $op) {
        if(!str_starts_with($op->action(),'content.')) { return true; }
        if($op->action()==='content.create') {
            $post=get_post((int)($op->target()['id']??0));
            $type=$post?get_post_type_object($post->post_type):null;
            foreach(CapabilityMap::forAction('content.delete') as $cap) { if(!current_user_can($cap)) { return Policy::error(); } }
            return $type && current_user_can($type->cap->delete_posts) && current_user_can('delete_post',$post->ID)?true:Policy::error();
        }
        $saved=$op->snapshot()['post']??null;
        if(!is_array($saved)) { return Policy::error(); }
        return self::authorize('content.update',$op->target(),['post_type'=>$saved['post_type'],'post_status'=>$saved['post_status'],'post_author'=>$saved['post_author']],$op->snapshot());
    }
    private static function checkStage(array $c,string $action,array $change) {
        global $wpdb;
        if(!in_array($action,['media.create','media.replace'],true)) { return true; }
        $token=$change['staging_token']??null; if(!is_string($token)) { return Policy::error('invalid',400); }
        $id=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.Connections::table('operations')." WHERE connection_id=%s AND owner_id=%d AND action='mcp.stage' AND plan_hash=%s AND expires_at>%d",$c['id'],$c['owner_id'],hash('sha256',$token),time()));
        return !$wpdb->last_error && $id?true:Policy::error();
    }
    private static function map(array $c,string $id,string $action,string $status,int $expires,string $hash): bool {
        global $wpdb;
        return $wpdb->insert(Connections::table('operations'),['id'=>$id,'connection_id'=>$c['id'],'owner_id'=>$c['owner_id'],'plan'=>'{}','plan_hash'=>$hash,'action'=>$action,'status'=>$status,'expires_at'=>$expires,'created_at'=>time(),'pre_hash'=>'','before_fp'=>''])===1;
    }
    private static function dto(Operation $op,int $expires): array {
        return ['id'=>$op->id(),'action'=>$op->action(),'target'=>array_intersect_key($op->target(),array_flip(['type','id','path','hook','timestamp','menu_id'])),'status'=>$op->status(),'error_code'=>$op->errorCode(),'precondition_hash'=>$op->preconditionHash(),'expires_at'=>$expires,'changed_fields'=>array_keys($op->change()),'rollback_available'=>$op->status()==='verified' && $op->afterHash()!==null];
    }
}
