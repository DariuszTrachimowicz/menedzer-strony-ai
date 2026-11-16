<?php
declare(strict_types=1);
namespace ASM\MCP;

final class Policy {
    public const SCOPES = ['publicread'=>'Publiczny odczyt','drafts'=>'Praca nad szkicami','publish'=>'Publikowanie','full'=>'Pełne zarządzanie stroną'];
    public static function error(string $code = 'forbidden', int $status = 403): \WP_Error {
        $messages = ['invalid'=>'Nieprawidłowe pola lub wartości żądania.', 'forbidden'=>'Brak dostępu do tej czynności lub zasobu.', 'conflict'=>'Zasób zmienił się. Zachowano późniejszą zmianę.', 'busy'=>'Operacja trwa lub jej wynik wymaga sprawdzenia. Nie ponawiaj zapisu.', 'storage'=>'Nie można bezpiecznie zapisać stanu. Sprawdź wynik operacji.', 'dependency'=>'Operacje wymagają aktywnego Menedżera Strony AI z obsługą natywnego API.', 'auth'=>'Poświadczenie nie zostało przyjęte.', 'rate'=>'Zbyt wiele żądań. Spróbuj po upływie minuty.'];
        return new \WP_Error('asm_mcp_'.$code, $messages[$code] ?? 'Nie można wykonać żądania.', ['status'=>$status]);
    }
    public static function closed(array $data, array $allowed, array $required = []): bool {
        return !array_diff(array_keys($data), $allowed) && !array_diff($required, array_keys($data));
    }
    public static function readable(array $c, $p): bool {
        if (!$p || $p->post_password !== '') { return false; }
        if ($p->post_type === 'attachment') {
            if ($p->post_status !== 'inherit' || !str_starts_with($p->post_mime_type, 'image/')) { return false; }
            if (!$p->post_parent) { return true; }
            $parent = get_post($p->post_parent);
            return $parent && in_array($parent->post_type, ['post','page'], true) && $parent->post_status === 'publish' && $parent->post_password === '';
        }
        if (!in_array($p->post_type, ['post','page'], true) || !in_array($p->post_status, ['publish','future','private','draft','pending'], true)) { return false; }
        if ($p->post_status === 'publish') { return true; }
        return $c['scope'] !== 'publicread' && user_can((int)$c['owner_id'], 'read_post', $p->ID);
    }
    public static function plan(array $c, array $p, bool $precondition = true) {
        if (!self::closed($p, ['action','target','change'], ['action','target','change']) || !is_string($p['action']) || !is_array($p['target']) || !is_array($p['change'])) { return self::error('invalid',400); }
        if ($c['scope'] === 'publicread' || !user_can((int)$c['owner_id'], 'manage_options') || !user_can((int)$c['owner_id'], 'asm_full_control')) { return self::error(); }
        if (!in_array($p['action'], ['content.create','content.update','content.replace'], true)) { return self::error('invalid',400); }
        $create = $p['action'] === 'content.create';
        $target = $p['target']; $change = $p['change'];
        if (!self::closed($target, $create ? ['type'] : ['type','id'], $create ? ['type'] : ['type','id']) || $target['type'] !== 'posts') { return self::error('invalid',400); }
        $type = $change['post_type'] ?? 'post'; $post = null;
        if (!$create) {
            if (!is_int($target['id']) || $target['id'] < 1) { return self::error('invalid',400); }
            $post = get_post($target['id']);
            if (!$post || !in_array($post->post_type, ['post','page'], true) || $post->post_password !== '' || !in_array($post->post_status,['draft','pending','publish','future','private'],true) || !user_can((int)$c['owner_id'], 'edit_post', $post->ID)) { return self::error(); }
            $type = $post->post_type;
            if ($c['scope'] !== 'publish' && !in_array($post->post_status,['draft','pending'],true)) { return self::error(); }
        }
        if (!in_array($type, ['post','page'],true)) { return self::error('invalid',400); }
        $object = get_post_type_object($type);
        if (!user_can((int)$c['owner_id'], $object->cap->create_posts)) { return self::error(); }
        if ($c['scope'] === 'publish' && !user_can((int)$c['owner_id'], $object->cap->publish_posts)) { return self::error(); }
        if ($p['action'] === 'content.replace') {
            if (!self::closed($change,['field','old','new','content_hash','expected_occurrences'],['field','old','new','content_hash','expected_occurrences']) || !in_array($change['field'],['post_title','post_content','post_excerpt'],true) || !is_string($change['old']) || $change['old']==='' || !is_string($change['new']) || !is_string($change['content_hash']) || !preg_match('/^[a-f0-9]{64}$/D',$change['content_hash']) || !is_int($change['expected_occurrences']) || $change['expected_occurrences']<1 || $change['expected_occurrences']>1000) { return self::error('invalid',400); }
            // ASM 2.0.1 nie może bezpiecznie zweryfikować pustego zamiennika (PHP substr_count).
            if ($change['new']==='') { return self::error('invalid',400); }
            if ($precondition && (!hash_equals($change['content_hash'],hash('sha256',$post->{$change['field']})) || substr_count($post->{$change['field']},$change['old']) !== $change['expected_occurrences'])) { return self::error('conflict',409); }
            if (wp_kses_post($change['new']) !== $change['new']) { return self::error('invalid',400); }
            return $p;
        }
        $allowed = ['post_status','post_title','post_content','post_excerpt','post_name'];
        if ($create) { $allowed[] = 'post_type'; }
        if ($c['scope'] === 'publish') { $allowed = array_merge($allowed,['post_date','post_date_gmt']); }
        if (!$change || !self::closed($change,$allowed)) { return self::error('invalid',400); }
        if ($create && (!isset($change['post_type'],$change['post_status']) || !in_array($change['post_type'],['post','page'],true))) { return self::error('invalid',400); }
        foreach ($change as $key=>$value) {
            if (!is_string($value) || strlen($value)>131072) { return self::error('invalid',400); }
            if (in_array($key,['post_content','post_excerpt','post_title'],true) && wp_kses_post($value)!==$value) { return self::error('invalid',400); }
        }
        if (isset($change['post_status']) && !in_array($change['post_status'], $c['scope']==='publish' ? ['draft','pending','publish','future','private'] : ['draft','pending'], true)) { return self::error(); }
        foreach (['post_date','post_date_gmt'] as $key) {
            if (isset($change[$key])) {
                $d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$change[$key],new \DateTimeZone($key==='post_date_gmt'?'UTC':wp_timezone_string()));
                if (!$d || $d->format('Y-m-d H:i:s')!==$change[$key]) { return self::error('invalid',400); }
            }
        }
        if (($change['post_status']??'')==='future' && (!isset($change['post_date'],$change['post_date_gmt']) || ($precondition && strtotime($change['post_date_gmt'].' UTC')<=time()))) { return self::error('invalid',400); }
        if (isset($change['post_date'],$change['post_date_gmt'])) {
            $local = new \DateTimeImmutable($change['post_date'],wp_timezone());
            if ($local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')!==$change['post_date_gmt']) { return self::error('invalid',400); }
        }
        return $p;
    }
    public static function dto($p): array {
        if ($p->post_type==='attachment') {
            return ['id'=>(int)$p->ID,'type'=>'image','title'=>$p->post_title,'url'=>wp_get_attachment_url($p->ID),'mime'=>$p->post_mime_type];
        }
        return ['id'=>(int)$p->ID,'type'=>$p->post_type,'status'=>$p->post_status,'title'=>$p->post_title,'content'=>$p->post_content,'excerpt'=>$p->post_excerpt,'slug'=>$p->post_name,'url'=>get_permalink($p->ID),'modified_gmt'=>$p->post_modified_gmt];
    }
}
