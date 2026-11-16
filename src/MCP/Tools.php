<?php
declare(strict_types=1);
namespace ASM\MCP;

final class Tools {
    public static function schemas(array $c): array {
        if (($c['scope']??'')==='full') {
            $fresh=Connections::recheck($c);
            if (is_wp_error($fresh)) { return []; }
            $base=self::schemas(array_replace($c,['scope'=>'publicread']));
            $base=array_values(array_filter($base,static fn($s)=>in_array($s['name'],['wp_site_status','wp_logs'],true)));
            return array_merge($base,NativeBridge::schemas());
        }
        $string=['type'=>'string']; $id=['type'=>'integer','minimum'=>1];
        $object = static fn(array $props,array $required=[]):array=>['type'=>'object','properties'=>(object)$props,'required'=>$required,'additionalProperties'=>false];
        $resource=['type'=>'string','enum'=>['posts','media']];
        $schemas=[
            'wp_site_status'=>['Odczytuje adres, transport, zależność i zakres tego połączenia.',$object([])],
            'wp_resource_list'=>['Lista dozwolonych wpisów, stron lub publicznych obrazów.',$object(['type'=>$resource,'page'=>['type'=>'integer','minimum'=>1,'maximum'=>1000],'per_page'=>['type'=>'integer','minimum'=>1,'maximum'=>50]],['type'])],
            'wp_resource_get'=>['Odczytuje treść bez wykonywania kodu i bez metadanych.',$object(['type'=>$resource,'id'=>$id],['type','id'])],
            'wp_search'=>['Szuka wyłącznie w dozwolonych treściach lub obrazach.',$object(['area'=>['type'=>'string','enum'=>['content','media']],'needle'=>['type'=>'string','minLength'=>1,'maxLength'=>200]],['area','needle'])],
            'wp_logs'=>['Odczytuje bezsekretową aktywność wyłącznie tego połączenia.',$object(['limit'=>['type'=>'integer','minimum'=>1,'maximum'=>100]])]
        ];
        if ($c['scope']!=='publicread') {
            $fields=['post_type'=>['type'=>'string','enum'=>['post','page']],'post_status'=>['type'=>'string','enum'=>$c['scope']==='publish'?['draft','pending','publish','future','private']:['draft','pending']],'post_title'=>$string,'post_content'=>$string,'post_excerpt'=>$string,'post_name'=>$string,'field'=>['type'=>'string','enum'=>['post_content','post_title','post_excerpt']],'old'=>$string,'new'=>$string,'content_hash'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$'],'expected_occurrences'=>['type'=>'integer','minimum'=>1,'maximum'=>1000]];
            if ($c['scope']==='publish') { $fields['post_date']=$string; $fields['post_date_gmt']=$string; }
            $schemas += [
                'wp_operation_preview'=>['Przygotowuje niezmienny plan na 24 godziny. Nie zapisuje treści. Create wymaga typu i stanu; update nie zmienia typu; replace wymaga old/new/hash/occurrences.',$object(['action'=>['type'=>'string','enum'=>['content.create','content.update','content.replace']],'target'=>$object(['type'=>['type'=>'string','const'=>'posts'],'id'=>$id],['type']),'change'=>$object($fields)],['action','target','change'])],
                'wp_operation_execute'=>['Wykonuje własny podgląd raz. Wymaga jawnego potwierdzenia. Po utracie odpowiedzi odczytaj status.',$object(['operation_id'=>$string,'confirm'=>['type'=>'boolean','const'=>true],'idempotency_key'=>['type'=>'string','minLength'=>1,'maxLength'=>128]],['operation_id','confirm','idempotency_key'])],
                'wp_operation_status'=>['Odczytuje własny stan. executing i rolling_back po awarii wymagają ręcznego sprawdzenia.',$object(['operation_id'=>$string],['operation_id'])],
                'wp_operation_rollback'=>['Cofa własną sprawdzoną zmianę, jeśli nie było późniejszych edycji. Usuwa tylko własny nowy szkic bez powiązań.',$object(['operation_id'=>$string,'confirm'=>['type'=>'boolean','const'=>true]],['operation_id','confirm'])]
            ];
        }
        $result=[];
        foreach ($schemas as $name=>[$desc,$schema]) { $result[]=['name'=>$name,'description'=>$desc,'inputSchema'=>$schema]; }
        return $result;
    }
    public static function call(array $c,string $name,array $a) {
        $names=array_column(self::schemas($c),'name');
        if (!in_array($name,$names,true)) { return Policy::error(); }
        $fresh=Connections::recheck($c); if (is_wp_error($fresh)) { return $fresh; }
        if ($c['scope']==='full' && !in_array($name,['wp_site_status','wp_logs'],true)) { return NativeBridge::call($fresh,$name,$a); }
        if ($name==='wp_site_status') {
            if ($a) { return Policy::error('invalid',400); }
            return ['name'=>get_bloginfo('name'),'url'=>home_url(),'version'=>ASM_VERSION,'protocol'=>'2025-11-25','transport'=>'Streamable HTTP, JSON, stateless','scope'=>$c['scope'],'dependency'=>Operations::dependency(),'can_mutate'=>Operations::dependency() && $c['scope']!=='publicread' && user_can((int)$c['owner_id'],'asm_full_control')];
        }
        if ($name==='wp_logs') {
            if (!Policy::closed($a,['limit']) || (isset($a['limit']) && (!is_int($a['limit']) || $a['limit']<1 || $a['limit']>100))) { return Policy::error('invalid',400); }
            return ['items'=>Connections::logs($c,$a['limit']??30)];
        }
        if (str_starts_with($name,'wp_operation_')) {
            if ($name==='wp_operation_preview') { return Operations::preview($c,$a); }
            $allowed=['operation_id'];
            if ($name!=='wp_operation_status') { $allowed[]='confirm'; }
            if ($name==='wp_operation_execute') { $allowed[]='idempotency_key'; }
            if (!Policy::closed($a,$allowed,$allowed) || !is_string($a['operation_id']) || ($name!=='wp_operation_status' && $a['confirm']!==true)) { return Policy::error('invalid',400); }
            if ($name==='wp_operation_execute' && (!is_string($a['idempotency_key']) || !preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D',$a['idempotency_key']))) { return Policy::error('invalid',400); }
            if ($name==='wp_operation_status') { return Operations::status($c,$a['operation_id']); }
            return Operations::run($c,$a['operation_id'],$name==='wp_operation_rollback',$a['idempotency_key']??'');
        }
        if ($name==='wp_resource_get') {
            if (!Policy::closed($a,['type','id'],['type','id']) || !in_array($a['type'],['posts','media'],true) || !is_int($a['id']) || $a['id']<1) { return Policy::error('invalid',400); }
            clean_post_cache($a['id']); $post=get_post($a['id']);
            if (!$post || ($a['type']==='media')!==($post->post_type==='attachment') || !Policy::readable($c,$post)) { return Policy::error(); }
            return Policy::dto($post);
        }
        $search=$name==='wp_search';
        if ($search) {
            if (!Policy::closed($a,['area','needle'],['area','needle']) || !in_array($a['area'],['content','media'],true) || !is_string($a['needle']) || trim($a['needle'])==='' || mb_strlen($a['needle'])>200) { return Policy::error('invalid',400); }
            $media=$a['area']==='media';
        } else {
            if (!Policy::closed($a,['type','page','per_page'],['type']) || !in_array($a['type'],['posts','media'],true)) { return Policy::error('invalid',400); }
            foreach (['page'=>1000,'per_page'=>50] as $key=>$max) { if (isset($a[$key]) && (!is_int($a[$key]) || $a[$key]<1 || $a[$key]>$max)) { return Policy::error('invalid',400); } }
            $media=$a['type']==='media';
        }
        $q=['post_type'=>$media?'attachment':['post','page'],'post_status'=>$media?'inherit':($c['scope']==='publicread'?'publish':['publish','draft','pending','future','private']),'has_password'=>false,'posts_per_page'=>$a['per_page']??20,'paged'=>$a['page']??1,'orderby'=>'ID','order'=>'DESC','no_found_rows'=>true,'update_post_meta_cache'=>false,'update_post_term_cache'=>false];
        if ($media) { $q['post_mime_type']='image'; }
        if ($search) { $q['s']=$a['needle']; }
        global $wpdb;
        $query=new \WP_Query($q); $items=[];
        if ($wpdb->last_error) { return Policy::error('storage',503); }
        foreach ($query->posts as $post) { if (Policy::readable($c,$post)) { $items[]=Policy::dto($post); } }
        return ['items'=>$items,'page'=>$a['page']??1];
    }
}
