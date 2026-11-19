<?php
declare(strict_types=1);
namespace ASM\MCP;

final class Admin {
    private static array|\WP_Error|null $creation=null;
    public const CLIENTS=['codex-cli','codex-app','claude-code','claude-desktop','claude-cloud','gemini-cli','other-stdio','other-remote'];
    private const LABELS=['codex-cli'=>'Codex CLI','codex-app'=>'Aplikacja Codex','claude-code'=>'Claude Code (CLI)','claude-desktop'=>'Claude Desktop','claude-cloud'=>'Claude przez chmurę','gemini-cli'=>'Gemini CLI','other-stdio'=>'Inny klient stdio','other-remote'=>'Inny klient zdalny','oauth'=>'Logowanie w aplikacji'];
    public static function hooks(): void {
        add_action('admin_init',function () {
            if (($_GET['page']??'')!=='ai-site-manager') { return; }
            nocache_headers(); header('Cache-Control: private, no-store'); header('Referrer-Policy: no-referrer');
            $discovery=get_option('asm_mcp_discovery');
            // Raz na wersję: wybór adresów logowania zależnie od tego, czy hosting oddaje /.well-known WordPressowi.
            if (current_user_can('manage_options') && (!is_array($discovery) || ($discovery['version']??'')!==ASM_VERSION)) { OAuthDiscovery::detect(); }
            if (($_SERVER['REQUEST_METHOD']??'')!=='POST') { return; }
            self::guard('asm_mcp_create');
            $expiryMode=sanitize_key($_POST['expiry_mode']??'date');
            $expiryText=sanitize_text_field(wp_unslash($_POST['expiry']??''));
            $expiry=\DateTimeImmutable::createFromFormat('!Y-m-d',$expiryText,wp_timezone());
            self::$creation=Connections::issue(sanitize_text_field(wp_unslash($_POST['name']??'')),sanitize_key($_POST['client']??''),sanitize_key($_POST['scope']??''),$expiryMode==='never'?0:($expiry && $expiry->format('Y-m-d')===$expiryText?$expiry->setTime(23,59,59)->getTimestamp():-1),sanitize_text_field(wp_unslash($_POST['issue_key']??'')));
        });
        add_action('admin_post_asm_mcp_revoke',[self::class,'revoke']);
        add_action('admin_post_asm_mcp_delete',[self::class,'delete']);
        add_action('admin_post_asm_mcp_expiry',[self::class,'expiry']);
        add_action('admin_post_asm_mcp_help',[self::class,'download']);
        add_action('wp_ajax_asm_mcp_status',[self::class,'poll']);
    }
    private static function guard(string $nonce): void {
        if (!current_user_can('manage_options')) { wp_die('Brak dostępu.','',['response'=>403]); }
        check_admin_referer($nonce);
    }
    private static function url(string $tab='start'): string { return admin_url('admin.php?page=ai-site-manager&tab='.$tab); }
    private static function rows(string $sql): array {
        global $wpdb;
        $old=$wpdb->suppress_errors(true); $rows=$wpdb->get_results($sql,ARRAY_A); $error=$wpdb->last_error; $wpdb->suppress_errors($old);
        if ($error) { wp_die('Nie można odczytać stanu połączeń. Odśwież stronę po sprawdzeniu bazy danych.','',['response'=>503]); }
        return $rows;
    }
    private static function input(string $id,string $value,string $label,bool $secret=false): void {
        echo '<label for="'.esc_attr($id).'">'.esc_html($label).'</label><div class="asm-copy-row"><input readonly id="'.esc_attr($id).'" '.($secret?'data-secret type="password" autocomplete="off"':'type="text"').' value="'.esc_attr($value).'"><button type="button" class="button" data-copy="'.esc_attr($id).'">Kopiuj</button></div>';
    }
    public static function humanDate(int $timestamp): string {
        $months=[1=>'stycznia','lutego','marca','kwietnia','maja','czerwca','lipca','sierpnia','września','października','listopada','grudnia'];
        return wp_date('j',$timestamp).' '.$months[(int)wp_date('n',$timestamp)].' '.wp_date('Y H:i',$timestamp);
    }
    public static function help(string $name): string {
        if (!preg_match('/^[a-z-]+$/D',$name) || !is_file(ASM_PLUGIN_DIR.'/help/pl/'.$name.'.md')) { $name='start'; }
        return str_replace('{{MCP_URL}}',rest_url('asm-mcp/v1/mcp'),(string)file_get_contents(ASM_PLUGIN_DIR.'/help/pl/'.$name.'.md'));
    }
    private static function markdown(string $source): string {
        $lines=explode("\n",$source); $html=''; $code=false; $list='';
        $closeList=static function() use (&$html,&$list) { if ($list) { $html.='</'.$list.'>'; $list=''; } };
        foreach ($lines as $line) {
            if (str_starts_with($line,'```')) { $closeList(); $html.=$code?'</code></pre>':'<pre><code>'; $code=!$code; continue; }
            if ($code) { $html.=esc_html($line)."\n"; continue; }
            if (preg_match('/^(?:([0-9]+)\. |([-*]) )(.*)$/',$line,$m)) {
                $kind=$m[1]!==''?'ol':'ul'; if ($list!==$kind) { $closeList(); $list=$kind; $html.='<'.$kind.'>'; }
                $html.='<li>'.self::inline($m[3]).'</li>'; continue;
            }
            $closeList();
            if (preg_match('/^(#{1,3}) (.*)$/',$line,$m)) { $level=min(4,strlen($m[1])+1); $html.='<h'.$level.'>'.self::inline($m[2]).'</h'.$level.'>'; }
            elseif (trim($line)!=='') { $html.='<p>'.self::inline($line).'</p>'; }
        }
        $closeList();
        if ($code) { $html.='</code></pre>'; }
        return $html;
    }
    private static function inline(string $text): string {
        $html=''; $offset=0;
        preg_match_all('~`([^`]+)`|\[([^\]]+)\]\(([^\s)]+)\)|https?://[^\s<>]+~',$text,$tokens,PREG_OFFSET_CAPTURE);
        foreach ($tokens[0] as $i=>$token) {
            [$raw,$pos]=$token; $html.=esc_html(substr($text,$offset,$pos-$offset)); $offset=$pos+strlen($raw);
            if ($raw[0]==='`') { $html.='<code>'.esc_html($tokens[1][$i][0]).'</code>'; continue; }
            $url=$raw[0]==='['?$tokens[3][$i][0]:$raw; $label=$raw[0]==='['?$tokens[2][$i][0]:$raw;
            if (preg_match('/^([a-z-]+)\.md$/D',$url,$doc)) { $url=self::url('help').'&doc='.$doc[1]; }
            if (!preg_match('~^https?://~i',$url)) { $html.=esc_html($raw); continue; }
            $html.='<a href="'.esc_url($url,['http','https']).'">'.esc_html($label).'</a>';
        }
        return $html.esc_html(substr($text,$offset));
    }
    public static function page(): void {
        if (!current_user_can('manage_options')) { return; }
        $issued=self::$creation; $error=null;
        self::$creation=null;
        if (is_wp_error($issued)) { $error=$issued->get_error_message(); $issued=null; }
        $tabs=['start'=>'Start','connections'=>'Połączenia','permissions'=>'Uprawnienia','popup'=>'Popup','activity'=>'Historia','system'=>'System i aktualizacje','help'=>'Pomoc'];
        $tab=sanitize_key($_GET['tab']??'start'); if (!isset($tabs[$tab])) { $tab='start'; }
        echo '<div class="asm-mcp"><nav class="asm-tabs" aria-label="Sekcje wtyczki">';
        foreach ($tabs as $key=>$label) { echo '<a '.($key===$tab?'aria-current="page"':'').' href="'.esc_url(self::url($key)).'">'.esc_html($label).'</a>'; }
        echo '</nav><div id="asm-live" class="asm-live" role="status" aria-live="polite"></div>';
        if ($error) { echo '<div id="asm-error" class="notice notice-error" tabindex="-1" role="alert"><p>'.esc_html($error).'</p></div>'; }
        if ($issued) { self::oneTime($issued); }
        elseif ($tab==='start') { self::start(); }
        elseif ($tab==='connections') { self::connections(); }
        elseif ($tab==='permissions') { \ASM\Admin\AdminPage::section('operators'); self::permissions(); }
        elseif ($tab==='popup') { \ASM\Admin\AdminPage::section('popup'); }
        elseif ($tab==='activity') { self::activity(); \ASM\Admin\AdminPage::section('audit'); }
        elseif ($tab==='system') { \ASM\Admin\AdminPage::section('system'); }
        else { self::helpPage(); }
        echo '</div>';
    }
    private static function start(): void {
        $url=rest_url('asm-mcp/v1/mcp'); $name=sanitize_title((string)wp_parse_url(home_url(),PHP_URL_HOST));
        $manual=static fn(string $client): string=>self::url('connections').'&add=1&client='.$client;
        // Codex przerywa logowanie, gdy hosting przekierowuje /.well-known na inną domenę. W trybie path zostaje mu poświadczenie.
        $login=OAuthDiscovery::mode()==='root';
        echo '<div class="asm-grid asm-grid--apps"><section class="asm-surface"><h2>Claude</h2><p>Przeglądarka, Claude Desktop i telefon. Logujesz się w aplikacji, bez poświadczeń.</p>';
        self::input('asm-url',$url,'Adres połączenia');
        echo '<ol><li>Otwórz konektory w Claude i wybierz Add custom connector.</li><li>Wklej adres, nadaj nazwę i wybierz Sign in.</li><li>Zaloguj się do WordPressa, wybierz zakres i termin, zatwierdź dostęp.</li></ol><div class="asm-actions"><a class="button button-primary" target="_blank" rel="noopener" href="https://claude.ai/settings/connectors">Otwórz konektory w Claude</a></div></section>';
        echo '<section class="asm-surface"><h2>Claude Code (CLI)</h2><p>Jedno polecenie w terminalu. Konektor dodany w Claude jest widoczny także tutaj, wtedy ten krok pomiń.</p>';
        self::input('asm-claude-code','claude mcp add --transport http '.$name.' '.$url,'Polecenie');
        echo '<p>Potem wpisz <code>/mcp</code> i wybierz logowanie.</p></section>';
        echo '<section class="asm-surface"><h2>Codex</h2>';
        if ($login) {
            echo '<p>Dwa polecenia w terminalu: dodanie serwera i logowanie w przeglądarce.</p>';
            self::input('asm-codex-add','codex mcp add '.$name.' --url '.$url,'1. Dodaj serwer');
            self::input('asm-codex-login','codex mcp login '.$name,'2. Zaloguj się');
            echo '<p>Jeśli logowanie się nie powiedzie, użyj poświadczenia.</p>';
        } else {
            echo '<p>Na tym hostingu Codex nie zaloguje się w przeglądarce, więc łączy się poświadczeniem. Utwórz je, zapisz w zmiennej środowiskowej <code>ASM_MCP_TOKEN</code> i dodaj serwer poleceniem.</p>';
            self::input('asm-codex-add','codex mcp add '.$name.' --url '.$url.' --bearer-token-env-var ASM_MCP_TOKEN','Polecenie');
        }
        echo '<div class="asm-actions"><a class="button'.($login?'':' button-primary').'" href="'.esc_url($manual('codex-cli')).'">Utwórz poświadczenie dla Codex</a></div></section>';
        echo '<section class="asm-surface"><h2>Gemini CLI</h2><p>Łączy się poświadczeniem. Po jego utworzeniu dostaniesz gotową konfigurację do wklejenia.</p><div class="asm-actions"><a class="button" href="'.esc_url($manual('gemini-cli')).'">Utwórz poświadczenie dla Gemini CLI</a></div></section>';
        echo '<section class="asm-surface"><h2>Inna aplikacja</h2><p>Programy obsługujące MCP łączą się zdalnie albo przez most stdio.</p><div class="asm-actions"><a class="button" href="'.esc_url($manual('other-remote')).'">Utwórz ręczne poświadczenie</a><a class="button" href="'.esc_url(self::url('help').'&doc=advanced').'">Instrukcje czterech sposobów</a></div></section>';
        echo '<section class="asm-surface"><h2>Po połączeniu</h2><p>Zakres dostępu wybierasz przy logowaniu albo tworzeniu poświadczenia. Pełne zarządzanie wymaga konta z pełnym dostępem nadanym w zakładce <a href="'.esc_url(self::url('permissions')).'">Uprawnienia</a>. Wtyczka nie wymaga klucza dostawcy modelu.</p><p>'.(str_starts_with($url,'https://') && Endpoint::secure()?'Adres korzysta z HTTPS.':'Połączenia z aplikacją zdalną wymagają publicznego adresu HTTPS. Adres HTTP służy wyłącznie lokalnym testom.').'</p><div class="asm-actions"><a class="button" href="'.esc_url(self::url('connections')).'">Moje połączenia</a><a class="button" href="'.esc_url(self::url('activity')).'">Historia</a></div></section></div>';
    }
    private static function grantHelp(): void {
        echo '<section class="asm-surface"><h2>Brak pełnego dostępu ASM</h2><p>Jeśli chcesz pracować nad szkicami lub publikować, w zakładce Uprawnienia wybierz „Nadaj pełny dostęp” przy swoim koncie administratora. Połączenia AI nie nadają automatycznie pełnego dostępu.</p><a class="button" href="'.esc_url(self::url('permissions')).'">Otwórz operatorów</a></section>';
    }
    private static function connections(): void {
        global $wpdb;
        if (isset($_GET['add'])) { self::wizard(); return; }
        echo '<section class="asm-surface"><h2>Połączenia</h2><div class="asm-actions asm-actions--top"><a class="button button-primary" href="'.esc_url(self::url('start')).'">Dodaj połączenie</a><a class="button" href="'.esc_url(self::url('connections').'&add=1').'">Ręczne poświadczenie</a></div><p class="description">Nazwa i klient to Twój opis, nie potwierdzają tożsamości programu. Połączenie z Claude pojawi się tutaj po zalogowaniu w aplikacji.</p>';
        $rows=self::rows($wpdb->prepare('SELECT id,name,client,scope,revoked,last_used,last_status,expires_at FROM '.Connections::table('connections').' WHERE owner_id=%d AND deleted_at=0 ORDER BY revoked ASC,(expires_at=0 OR expires_at>UNIX_TIMESTAMP()) DESC,created_at DESC LIMIT 200',get_current_user_id()));
        echo '<table class="widefat asm-table"><thead><tr>';
        foreach (['Nazwa','Klient','Zakres','Stan','Ostatnie użycie','Ważne do','Działania'] as $label) { echo '<th scope="col">'.esc_html($label).'</th>'; }
        echo '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $state=$r['revoked']?'Dostęp cofnięty':(((int)$r['expires_at']!==0 && time()>=(int)$r['expires_at'])?'Wygasło':($r['last_status']?'Połączono':'Oczekuje na użycie'));
            $values=[$r['name'],self::LABELS[$r['client']]??$r['client'],Policy::SCOPES[$r['scope']]??'Nieznany',$state,$r['last_used']?self::humanDate((int)$r['last_used']):'Jeszcze nie użyto',(int)$r['expires_at']===0?'Bezterminowo':self::humanDate((int)$r['expires_at'])];
            echo '<tr>';
            foreach ($values as $i=>$value) { echo '<td data-label="'.esc_attr(['Nazwa','Klient','Zakres','Stan','Ostatnie użycie','Ważne do'][$i]).'">'.($i===3?'<span class="asm-chip '.($state==='Połączono'?'is-ok':($state==='Oczekuje na użycie'?'':'is-warn')).'">'.esc_html($value).'</span>':esc_html($value)).'</td>'; }
            $active=!$r['revoked'] && ((int)$r['expires_at']===0 || (int)$r['expires_at']>time());
            echo '<td data-label="Działania"><div class="asm-row-actions"><a class="button button-small" href="'.esc_url(self::url('help').'&doc='.$r['client']).'">Instrukcja</a>';
            if (!$r['revoked']) { echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" data-confirm="'.esc_attr('Połączenie „'.$r['name'].'” utraci dostęp do '.home_url().'. Cofnąć dostęp?').'"><input type="hidden" name="action" value="asm_mcp_revoke"><input type="hidden" name="connection_id" value="'.esc_attr($r['id']).'">'; wp_nonce_field('asm_mcp_revoke'); echo '<button class="button button-small" type="submit">Cofnij dostęp</button></form>'; }
            // Usunięcie potwierdza okno dialogowe z data-confirm. Przycisk odblokowuje skrypt, żeby bez JS nie dało się usunąć jednym kliknięciem.
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" data-confirm="'.esc_attr('Usunąć połączenie „'.$r['name'].'”? Dostęp zostanie cofnięty. Historia pozostanie zgodnie z retencją.').'"><input type="hidden" name="action" value="asm_mcp_delete"><input type="hidden" name="connection_id" value="'.esc_attr($r['id']).'"><input type="hidden" name="confirmation" value="yes">'; wp_nonce_field('asm_mcp_delete');
            echo '<button class="button button-small" type="submit" disabled data-needs-confirm>Usuń</button></form></div>';
            if ($active) {
                echo '<details><summary>Zmień termin</summary><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="asm_mcp_expiry"><input type="hidden" name="connection_id" value="'.esc_attr($r['id']).'">'; wp_nonce_field('asm_mcp_expiry');
                $field='asm-expiry-'.sanitize_html_class($r['id']);
                echo '<label for="'.esc_attr($field.'-mode').'">Termin dostępu</label><select id="'.esc_attr($field.'-mode').'" name="expiry_mode" data-expiry-mode><option value="never" '.selected((int)$r['expires_at'],0,false).'>Bezterminowo</option><option value="date" '.selected((int)$r['expires_at']!==0,true,false).'>Do dnia</option></select><label for="'.esc_attr($field).'">Dzień końca dostępu</label><input id="'.esc_attr($field).'" type="date" name="expiry" data-expiry-date value="'.esc_attr(wp_date('Y-m-d',(int)$r['expires_at']?:time()+90*86400)).'"><button class="button button-small">Zapisz termin</button></form></details>';
            }
            echo '</td></tr>';
        }
        if (!$rows) { echo '<tr><td colspan="7">Nie ma jeszcze połączeń.</td></tr>'; }
        echo '</tbody></table></section><section class="asm-surface"><h2>Cofnij wszystkie połączenia</h2><p>Ta czynność odcina wszystkie poświadczenia tej instalacji, również innych administratorów. Użyj jej po odtworzeniu kopii strony lub podejrzeniu ujawnienia sekretu.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" data-confirm="Cofnąć dostęp wszystkim połączeniom tej instalacji?"><input type="hidden" name="action" value="asm_mcp_revoke"><input type="hidden" name="all" value="1">'; wp_nonce_field('asm_mcp_revoke');
        echo '<label class="asm-check"><input type="checkbox" required name="confirmation" value="yes"> Potwierdzam cofnięcie dostępu wszystkim połączeniom.</label><button class="button" type="submit">Cofnij wszystkie połączenia</button></form></section>';
    }
    private static function wizard(): void {
        if (!current_user_can('asm_full_control')) { self::grantHelp(); }
        echo '<section class="asm-surface"><h2>Ręczne poświadczenie</h2><p>Ten kreator tworzy sekret do wklejenia w konfiguracji klienta. Claude w przeglądarce, Claude Desktop i Claude Code łączą się bez niego: <a href="'.esc_url(self::url('start')).'">wklej adres w aplikacji i zaloguj się</a>.</p><form method="post" action="'.esc_url(self::url('connections').'&add=1').'" id="asm-wizard">'; wp_nonce_field('asm_mcp_create');
        echo '<input type="hidden" name="issue_key" value="'.esc_attr(bin2hex(random_bytes(24))).'"><fieldset id="asm-step-one"><legend>1. Wybierz klienta i nazwę</legend><label for="asm-client">Klient</label><select id="asm-client" name="client">';
        $preset=sanitize_key($_GET['client']??'');
        foreach (self::LABELS as $value=>$label) { if (!in_array($value,self::CLIENTS,true)) { continue; } echo '<option value="'.esc_attr($value).'" '.selected($value,$preset,false).'>'.esc_html($label).'</option>'; }
        echo '</select><label for="asm-name">Nazwa połączenia</label><input id="asm-name" name="name" required maxlength="100" autocomplete="off"><p>Nazwa pomaga rozpoznać komputer lub zastosowanie. Nie potwierdza tożsamości klienta.</p></fieldset><fieldset id="asm-step-two"><legend>2. Ustal dostęp i termin</legend><label for="asm-scope">Zakres dostępu</label><select id="asm-scope" name="scope">';
        foreach (Policy::SCOPES as $scope=>$label) { if ($scope==='full' && !current_user_can('asm_full_control')) { continue; } echo '<option value="'.esc_attr($scope).'">'.esc_html($label).'</option>'; }
        echo '</select><p>Publiczny odczyt nie zmienia treści. Szkice pozwalają pracować nad szkicami i czytać niepubliczne treści zgodnie z uprawnieniami konta. Publikowanie obejmuje zmianę już opublikowanych treści.</p><label for="asm-expiry-mode">Termin dostępu</label><select id="asm-expiry-mode" name="expiry_mode" data-expiry-mode><option value="date">Do dnia</option><option value="never">Bezterminowo</option></select><label for="asm-expiry">Ważne do</label><input id="asm-expiry" type="date" name="expiry" data-expiry-date value="'.esc_attr(wp_date('Y-m-d',time()+90*86400)).'" min="'.esc_attr(wp_date('Y-m-d')).'" max="'.esc_attr(wp_date('Y-m-d',time()+365*86400)).'"><p>Opcja Bezterminowo działa do cofnięcia dostępu, usunięcia połączenia albo utraty uprawnień konta. Przy wybranej dacie dostęp wygasa po tym dniu. Zmiana zakresu wymaga nowego połączenia i cofnięcia starego.</p></fieldset><div class="asm-actions"><button type="button" class="button" id="asm-back" hidden>Wstecz</button><button type="button" class="button button-primary" id="asm-next" hidden>Dalej</button><button type="submit" class="button button-primary" id="asm-create">Utwórz połączenie</button><a href="'.esc_url(self::url('connections')).'">Anuluj</a></div></form></section>';
    }
    private static function oneTime(array $issued): void {
        $c=$issued['connection'];
        echo '<section class="asm-surface" id="asm-created"><h2 tabindex="-1">3. Zapisz dane połączenia</h2><p>To poświadczenie pokażemy tylko teraz. Przed opuszczeniem ekranu zapisz je w menedżerze haseł pod nazwą strony i połączenia. Potem wprowadź je do środowiska klienta zgodnie z instrukcją. Nie wklejaj go do rozmowy z AI. Po opuszczeniu lub odświeżeniu ekranu nie odzyskasz tego sekretu. Jeśli go utracisz, cofnij ten dostęp i utwórz nowy.</p>';
        self::input('asm-url',rest_url('asm-mcp/v1/mcp'),'Adres MCP');
        self::input('asm-secret',$issued['token'],'Jednorazowe poświadczenie',true);
        echo '<button type="button" class="button" id="asm-show" aria-controls="asm-secret" aria-pressed="false">Pokaż poświadczenie</button></section><section class="asm-surface"><h2>4. Skonfiguruj klienta</h2><p>Instrukcja oparta na dokumentacji producenta. Test SDK potwierdza transport; nie zastępuje testu Twojej aplikacji.</p><div class="asm-help">'.self::markdown(self::help($c['client'])).'</div><label for="asm-config">Konfiguracja wybranego klienta bez poświadczenia</label><textarea id="asm-config" readonly rows="10">'.esc_textarea(self::config($c['client'])).'</textarea><button type="button" class="button" data-copy="asm-config">Kopiuj konfigurację bez sekretu</button>';
        self::downloadLink($c['client']);
        echo '</section><section class="asm-surface"><h2>5. Sprawdź odczyt z klienta</h2><p>Poproś klienta: „Wywołaj wp_site_status i podaj adres oraz zakres dostępu. Niczego nie zmieniaj.”</p><p id="asm-poll" role="status" aria-live="polite" data-id="'.esc_attr($c['id']).'" data-nonce="'.esc_attr(wp_create_nonce('asm_mcp_poll')).'" data-url="'.esc_url(admin_url('admin-ajax.php')).'">Oczekuje na pierwszy rzeczywisty odczyt statusu. Możesz wrócić później do listy połączeń.</p><a class="button button-primary" href="'.esc_url(self::url('connections')).'">Przejdź do połączeń</a></section>';
    }
    public static function config(string $client): string {
        $url=rest_url('asm-mcp/v1/mcp');
        if (in_array($client,['codex-cli','codex-app'],true)) { return '[mcp_servers.asm-wordpress]' . "\nurl = ".wp_json_encode($url,JSON_UNESCAPED_SLASHES)."\nbearer_token_env_var = \"ASM_MCP_TOKEN\""; }
        if ($client==='claude-code') { $entry=['type'=>'http','url'=>$url,'headers'=>['Authorization'=>'Bearer ${ASM_MCP_TOKEN}']]; }
        elseif ($client==='gemini-cli') { $entry=['httpUrl'=>$url,'headers'=>['Authorization'=>'Bearer ${ASM_MCP_TOKEN}'],'trust'=>false]; }
        elseif (in_array($client,['claude-desktop','other-stdio'],true)) { $entry=['command'=>'node','args'=>['/pełna/ścieżka/ai-site-manager/bridge/bridge.mjs'],'env'=>['ASM_MCP_URL'=>$url]]; }
        else { return "Transport: Streamable HTTP\nAdres: $url\nNagłówek: Authorization\nSekret pobierz ze zmiennej ASM_MCP_TOKEN przez mechanizm klienta.\nNie wpisuj sekretu w URL ani w argumentach procesu."; }
        return wp_json_encode(['mcpServers'=>['asm-wordpress'=>$entry]],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    }
    private static function permissions(): void {
        echo '<section class="asm-surface"><h2>Uprawnienia każdego połączenia</h2><p>Zakres jest sprawdzany na serwerze przy każdym odczycie, podglądzie, wykonaniu, statusie i cofnięciu. Każde połączenie działa jako jego właściciel.</p>';
        global $wpdb;
        echo '<p>Zmiana odczytu na pełne zarządzanie wymaga ponownego połączenia i zgody w aplikacji. Dotychczasowe połączenia zachowują swój zakres.</p>';
        $rows=self::rows($wpdb->prepare('SELECT name,scope FROM '.Connections::table('connections').' WHERE owner_id=%d AND deleted_at=0 ORDER BY created_at DESC',get_current_user_id()));
        foreach ($rows as $row) { echo '<p><strong>'.esc_html($row['name']).'</strong>: '.esc_html(Policy::SCOPES[$row['scope']]??'Nieznany zakres').'</p>'; }
        echo self::markdown(self::help('permissions')).'</section>';
        if (!current_user_can('asm_full_control')) { self::grantHelp(); }
    }
    private static function activity(): void {
        global $wpdb;
        $connection=sanitize_text_field(wp_unslash($_GET['connection']??'')); $result=sanitize_key($_GET['result']??'');
        echo '<section class="asm-surface"><h2>Aktywność</h2><p>Historia obejmuje także usunięte połączenia i zawiera własne metadane, bez treści, tokenów i logów silnika ASM. Audyt i zakończone operacje przechowujemy przez 30 dni. Operacje w toku lub z nieustalonym wynikiem pozostają do wyjaśnienia.</p><form method="get" class="asm-filter"><input type="hidden" name="page" value="ai-site-manager"><input type="hidden" name="tab" value="activity"><label for="asm-filter-connection">Połączenie</label><select name="connection" id="asm-filter-connection"><option value="">Wszystkie moje połączenia</option>';
        $connections=self::rows($wpdb->prepare('SELECT id,name FROM '.Connections::table('connections').' WHERE owner_id=%d',get_current_user_id()));
        foreach ($connections as $c) { echo '<option '.selected($connection,$c['id'],false).' value="'.esc_attr($c['id']).'">'.esc_html($c['name']).'</option>'; }
        echo '</select><label for="asm-filter-result">Wynik</label><select name="result" id="asm-filter-result"><option value="">Wszystkie wyniki</option>';
        foreach (['requested','completed','denied','started','verified','rolled_back','unknown','failed','conflict','created','revoked','deleted','expiry_updated'] as $s) { echo '<option '.selected($result,$s,false).' value="'.esc_attr($s).'">'.esc_html(self::resultLabel($s)).'</option>'; }
        echo '</select><button class="button">Filtruj</button></form>';
        $a=Connections::table('audit'); $c=Connections::table('connections');
        $sql=$wpdb->prepare("SELECT a.event,a.result,a.operation_id,a.created_at,c.name FROM $a a JOIN $c c ON c.id=a.connection_id WHERE c.owner_id=%d",get_current_user_id());
        if ($connection!=='') { $sql.=$wpdb->prepare(' AND a.connection_id=%s',$connection); }
        if ($result!=='') { $sql.=$wpdb->prepare(' AND a.result=%s',$result); }
        $rows=self::rows($sql.' ORDER BY a.id DESC LIMIT 100');
        echo '<table class="widefat asm-table"><thead><tr><th>Kiedy</th><th>Połączenie</th><th>Czynność</th><th>Wynik</th><th>Operacja</th></tr></thead><tbody>';
        foreach ($rows as $row) { echo '<tr>'; foreach ([self::humanDate((int)$row['created_at']),$row['name'],self::eventLabel($row['event']),self::resultLabel($row['result']),$row['operation_id']?:'Nie dotyczy'] as $i=>$val) { echo '<td data-label="'.esc_attr(['Kiedy','Połączenie','Czynność','Wynik','Operacja'][$i]).'">'.esc_html($val).'</td>'; } echo '</tr>'; }
        if (!$rows) { echo '<tr><td colspan="5">Nie ma aktywności dla wybranego filtra.</td></tr>'; }
        echo '</tbody></table><p>Stan „executing” lub „rolling_back” po awarii wymaga sprawdzenia przez administratora. Nie ponawiaj zapisu.</p></section>';
    }
    private static function resultLabel(string $s): string { return ['requested'=>'Odebrano żądanie','completed'=>'Zakończono żądanie','denied'=>'Odmówiono','started'=>'Rozpoczęto','verified'=>'Sprawdzono zmianę','rolled_back'=>'Cofnięto zmianę','unknown'=>'Wynik wymaga sprawdzenia','created'=>'Utworzono dostęp','revoked'=>'Cofnięto dostęp','deleted'=>'Usunięto połączenie','expiry_updated'=>'Zmieniono termin','failed'=>'Nie udało się wykonać zmiany','conflict'=>'Konflikt z późniejszą zmianą','rollback_failed'=>'Nie potwierdzono cofnięcia','issued'=>'Wydano nowe tokeny'][$s]??$s; }
    private static function eventLabel(string $s): string { return ['wp_site_status'=>'Odczyt stanu strony','wp_resource_list'=>'Lista treści','wp_resource_get'=>'Odczyt zasobu','wp_search'=>'Szukanie w treściach','wp_logs'=>'Odczyt aktywności','wp_operation_preview'=>'Przygotowanie podglądu','wp_operation_execute'=>'Wykonanie operacji','wp_operation_status'=>'Odczyt stanu operacji','wp_operation_rollback'=>'Cofnięcie operacji','execute'=>'Wykonanie operacji','rollback'=>'Cofnięcie operacji','connection.create'=>'Utworzenie dostępu','connection.revoke'=>'Cofnięcie dostępu','connection.revoke_all'=>'Cofnięcie wszystkich połączeń','connection.delete'=>'Usunięcie połączenia','connection.expiry'=>'Zmiana terminu','oauth.refresh_retry'=>'Ponowione odświeżenie tokenu','oauth.refresh_replay'=>'Ponowne użycie starego tokenu','oauth.code_replay'=>'Ponowne użycie kodu logowania'][$s]??$s; }
    private static function downloadLink(string $doc): void {
        echo '<p><a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=asm_mcp_help&doc='.$doc),'asm_mcp_help')).'">Pobierz instrukcję Markdown</a></p>';
    }
    private static function helpPage(): void {
        $doc=sanitize_key($_GET['doc']??'start');
        echo '<section class="asm-surface"><h2>Pomoc lokalna</h2><form method="get"><input type="hidden" name="page" value="ai-site-manager"><input type="hidden" name="tab" value="help"><label for="asm-doc">Wybierz instrukcję</label><select name="doc" id="asm-doc">';
        $docs=['start'=>'Pierwsze kroki','advanced'=>'Cztery sposoby połączenia']+array_diff_key(self::LABELS,['oauth'=>true])+['permissions'=>'Uprawnienia','credentials'=>'Poświadczenia i bezpieczeństwo','activity'=>'Operacje i cofanie','troubleshooting'=>'Rozwiązywanie problemów','update'=>'Aktualizacja i wycofanie'];
        foreach ($docs as $key=>$label) { echo '<option '.selected($doc,$key,false).' value="'.esc_attr($key).'">'.esc_html($label).'</option>'; }
        echo '</select><button class="button">Otwórz instrukcję</button></form><div class="asm-help">'.self::markdown(self::help($doc)).'</div>';
        self::downloadLink($doc);
        echo '</section>';
        self::discoveryDiagnostics();
    }
    private static function discoveryDiagnostics(): void {
        echo '<details id="asm-discovery" class="asm-surface"><summary>Szczegóły techniczne: sprawdzenie logowania</summary><p>Sprawdzenie odczytuje pięć publicznych adresów tej strony. Nie loguje do aplikacji i nie udziela dostępu.</p><p>'.(OAuthDiscovery::mode()==='path'?'Hosting przejmuje katalog /.well-known, więc aplikacje dostają adresy logowania pod /wp-json/asm-mcp/v1.':'Aplikacje dostają adresy logowania pod /.well-known.').' Wtyczka wybiera to sama przy tym sprawdzeniu.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="asm_mcp_oauth_diagnostics">';
        wp_nonce_field('asm_mcp_oauth_diagnostics');
        echo '<button class="button">Sprawdź adresy logowania</button></form><p>Jeśli po przełączeniu na /wp-json aplikacja nadal nie pokazuje logowania, administrator serwera musi przekazać adresy /.well-known do WordPressa. Instrukcja jest w pomocy „Rozwiązywanie problemów”.</p>';
        $checks=get_transient('asm_mcp_discovery_'.get_current_user_id());
        if (is_array($checks)) {
            echo '<table class="widefat asm-table"><thead><tr><th>Adres</th><th>HTTP</th><th>Typ odpowiedzi</th><th>Wynik</th></tr></thead><tbody>';
            foreach ($checks as $check) { echo '<tr><td data-label="Adres">'.esc_html($check['url']).'</td><td data-label="HTTP">'.esc_html($check['status']?:'Brak odpowiedzi').'</td><td data-label="Typ odpowiedzi">'.esc_html($check['mime']?:'Brak').'</td><td data-label="Wynik">'.esc_html($check['valid']?'Prawidłowe metadane':'Sprawdź routing i konfigurację serwera').'</td></tr>'; }
            echo '</tbody></table><p>Wynik jest przechowywany przez dziesięć minut. Po zmianie konfiguracji wykonaj sprawdzenie ponownie.</p>';
        }
        echo '</details>';
    }
    public static function revoke(): void {
        self::guard('asm_mcp_revoke');
        if ($_SERVER['REQUEST_METHOD']!=='POST') { wp_die('Wymagany formularz POST.','',['response'=>405]); }
        if (isset($_POST['all'])) { $ok=($_POST['confirmation']??'')==='yes' && Connections::revokeAll(); }
        else { $ok=Connections::revoke(sanitize_text_field(wp_unslash($_POST['connection_id']??''))); }
        if (!$ok) { wp_die('Nie udało się cofnąć dostępu. Sprawdź stan połączeń.','',['response'=>503]); }
        wp_safe_redirect(self::url('connections')); exit;
    }
    public static function delete(): void {
        self::guard('asm_mcp_delete');
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST') { wp_die('Wymagany formularz POST.','',['response'=>405]); }
        if (($_POST['confirmation']??'')!=='yes' || !Connections::delete(sanitize_text_field(wp_unslash($_POST['connection_id']??'')))) { wp_die('Nie można usunąć tego połączenia.','',['response'=>403]); }
        wp_safe_redirect(self::url('connections')); exit;
    }
    public static function expiry(): void {
        self::guard('asm_mcp_expiry');
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST') { wp_die('Wymagany formularz POST.','',['response'=>405]); }
        $mode=sanitize_key($_POST['expiry_mode']??'');
        $text=sanitize_text_field(wp_unslash($_POST['expiry']??''));
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$text,wp_timezone());
        $expiry=$mode==='never'?0:($mode==='date' && $date && $date->format('Y-m-d')===$text?$date->setTime(23,59,59)->getTimestamp():-1);
        if ($expiry<0 || !Connections::updateExpiry(sanitize_text_field(wp_unslash($_POST['connection_id']??'')),$expiry)) { wp_die('Nie można zmienić terminu. Dostęp wygasły, cofnięty lub usunięty wymaga nowego połączenia.','',['response'=>403]); }
        wp_safe_redirect(self::url('connections')); exit;
    }
    public static function download(): void {
        self::guard('asm_mcp_help'); nocache_headers();
        header('Content-Type: text/markdown; charset=UTF-8'); header('Content-Disposition: attachment; filename="asm-mcp-instrukcja.md"');
        echo self::help(sanitize_key($_GET['doc']??'start')); exit;
    }
    public static function poll(): void {
        if (!current_user_can('manage_options') || !check_ajax_referer('asm_mcp_poll','nonce',false)) { wp_send_json_error(['message'=>'Brak dostępu.'],403); }
        $c=Connections::fresh(sanitize_text_field(wp_unslash($_POST['id']??'')));
        if (!$c || (int)$c['owner_id']!==get_current_user_id()) { wp_send_json_error(['message'=>'Brak dostępu.'],403); }
        wp_send_json_success(['confirmed'=>(bool)$c['last_status'],'time'=>$c['last_status']?self::humanDate((int)$c['last_status']):null,'revoked'=>(bool)$c['revoked'],'deleted'=>!empty($c['deleted_at']),'expired'=>(int)$c['expires_at']!==0 && time()>=(int)$c['expires_at']]);
    }
}
