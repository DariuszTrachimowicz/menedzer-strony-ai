<?php
// Uruchom w lokalnym, jednorazowym WordPressie z włączonym Menedżerem: wp eval-file data-safety.php
// Sprawdza poprawki audytu 2.8.0 na prawdziwym WordPressie. Kod wyjścia 1 przy porażce.
// Zmienia dane testowej instalacji (wpisy, opcje, użytkownicy, uprawnienia): nie uruchamiać na produkcji.
if (wp_get_environment_type() !== 'local') {
    fwrite(STDERR, "Tylko lokalny WordPress testowy.\n");
    exit(1);
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$failures = 0;
$check = static function (bool $ok, string $name) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    $failures += $ok ? 0 : 1;
};
$fails = static function (callable $call, string $code): bool {
    try {
        $call();
    } catch (ASM\Operations\OperationFailure $error) {
        return $error->operationCode() === $code;
    }
    return false;
};
global $wpdb;
$storageRoot = sys_get_temp_dir() . '/asm-test-storage-' . bin2hex(random_bytes(4));
$storage = new ASM\Storage\PrivateStorage($storageRoot, ABSPATH);

// 1. Odczyt treści przez REST wymaga pełnego dostępu, nie samej roli „read”.
$check(ASM\Auth\CapabilityMap::forAction('content.read') === array('manage_options', 'asm_full_control'), 'content.read wymaga manage_options i asm_full_control');

// 2. Metadane z ukośnikami (Elementor, polskie znaki jako ą) przetrwają zapis i cofnięcie.
$elementor = '[{"text":"\"cytat\" ą","url":"https:\/\/example.test\/"}]';
$postId = wp_insert_post(array('post_title' => 'Test metadanych', 'post_status' => 'draft'));
update_post_meta($postId, '_elementor_data', wp_slash($elementor));
$meta = new ASM\Resources\ContentResource('meta');
$target = array('type' => 'posts', 'id' => $postId);
$snapshot = $meta->snapshot($target);
$value = '{"q":"\"nowy\" ę"}';
$meta->apply($target, array('meta_key' => '_asm_test', 'value' => $value));
$check(get_post_meta($postId, '_asm_test', true) === $value, 'content.meta zapisuje wartość z ukośnikami bez zmian');
$meta->rollback($target, $snapshot);
$check(get_post_meta($postId, '_elementor_data', true) === $elementor, 'cofnięcie przywraca _elementor_data bajt w bajt');
$check(get_post_meta($postId, '_asm_test', true) === '', 'cofnięcie usuwa dodane metadane');

// 3. Usunięcie pliku przechodzi weryfikację.
$file = ABSPATH . 'wp-content/asm-test-delete.txt';
file_put_contents($file, 'x');
$delete = new ASM\Resources\FileResource('delete', new ASM\Filesystem\PathPolicy(array(ABSPATH)), $storage);
$fileTarget = array('type' => 'files', 'path' => 'wp-content/asm-test-delete.txt', '_operation_id' => 'test-delete');
$delete->snapshot($fileTarget);
$delete->apply($fileTarget, array());
$check(! file_exists($file) && $delete->verify($fileTarget, array()) === true, 'file.delete: plik usunięty i weryfikacja true');

// 4. Cofnięcie opcji zachowuje autoload (WordPress 6.6+: on/off/auto).
add_option('asm_test_autoload', 'stara', '', true);
$autoloadBefore = (string) $wpdb->get_var("SELECT autoload FROM {$wpdb->options} WHERE option_name = 'asm_test_autoload'");
$option = new ASM\Resources\OptionResource('update');
$optionSnapshot = $option->snapshot(array('id' => 'asm_test_autoload'));
$option->apply(array('id' => 'asm_test_autoload'), array('value' => 'nowa'));
$option->rollback(array('id' => 'asm_test_autoload'), $optionSnapshot);
$autoloadAfter = (string) $wpdb->get_var("SELECT autoload FROM {$wpdb->options} WHERE option_name = 'asm_test_autoload'");
wp_cache_delete('asm_test_autoload', 'options');
$check(get_option('asm_test_autoload') === 'stara' && $autoloadAfter === $autoloadBefore, 'cofnięcie opcji: wartość i autoload „' . $autoloadBefore . '” bez zmian');

// 5. Zapis opcji z [REDACTED] (wartość z odczytu) nie kasuje hasła.
$check($fails(static fn() => $option->apply(array('id' => 'asm_test_smtp'), array('value' => array('smtp' => array('pass' => '[REDACTED]')))), 'asm_option_redacted_value'), 'option.update odrzuca wartość z [REDACTED]');

// 6. Ukrywanie haseł działa też w opcjach złożonych.
$public = ASM\Resources\SecretDetector::publicValue('wp_mail_smtp', array('smtp' => array('pass' => 'tajne', 'host' => 'smtp.example.test')));
$check($public['smtp']['pass'] === '[REDACTED]' && $public['smtp']['host'] === 'smtp.example.test', 'zagnieżdżone hasło ukryte, reszta widoczna');

// 7. Konta administratorów poza zasięgiem AI.
$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
$adminId = (int) $admins[0];
$check($fails(static fn() => (new ASM\Resources\UserResource('create'))->apply(array(), array('user_login' => 'nowyadmin', 'user_email' => 'nowy@example.test', 'user_pass' => 'Haslo-Testowe-1', 'role' => 'administrator')), 'asm_user_privileged'), 'user.create z rolą administratora odrzucone');
$check($fails(static fn() => (new ASM\Resources\UserResource('password'))->apply(array('id' => $adminId), array('user_pass' => 'Inne-Haslo-1')), 'asm_user_privileged'), 'zmiana hasła administratora odrzucona');
(new ASM\Resources\UserResource('update'))->apply(array('id' => $adminId), array('nickname' => 'Admin testowy'));
$check(get_user_meta($adminId, 'nickname', true) === 'Admin testowy', 'zwykła edycja administratora (pseudonim) działa');
$check($fails(static fn() => (new ASM\Resources\UserResource('update'))->apply(array('id' => $adminId), array('user_email' => 'obcy@example.test')), 'asm_user_privileged'), 'zmiana e-maila administratora odrzucona');
$check($fails(static fn() => (new ASM\Resources\UserResource('create'))->apply(array(), array('user_login' => 'zrola', 'user_email' => 'zrola@example.test', 'user_pass' => 'Haslo-Testowe-3', 'role' => 'manage_options')), 'asm_user_invalid_role'), 'user.create z nieistniejącą rolą „manage_options” odrzucone');
update_option('default_role', 'administrator');
$check($fails(static fn() => (new ASM\Resources\UserResource('create'))->apply(array(), array('user_login' => 'bezroli', 'user_email' => 'bezroli@example.test', 'user_pass' => 'Haslo-Testowe-2')), 'asm_user_privileged'), 'user.create bez roli przy default_role=administrator odrzucone');
update_option('default_role', 'subscriber');
// Cofnięcie zmiany pseudonimu administratora nie przywraca starego hasła ustawionego później w panelu.
$userOp = new ASM\Resources\UserResource('update');
$userSnapshot = $userOp->snapshot(array('id' => $adminId));
$userOp->apply(array('id' => $adminId), array('nickname' => 'Po zmianie'));
wp_set_password('Nowe-Haslo-Z-Panelu-1', $adminId);
update_user_meta($adminId, '_application_passwords', array(array('uuid' => 'nowe-z-panelu', 'name' => 'Nowe', 'password' => 'hash')));
$passAfterPanel = (string) get_userdata($adminId)->user_pass;
$userOp->rollback(array('id' => $adminId), $userSnapshot);
clean_user_cache($adminId);
$appPasswords = (array) get_user_meta($adminId, '_application_passwords', true);
$check((string) get_userdata($adminId)->user_pass === $passAfterPanel && get_user_meta($adminId, 'nickname', true) === 'Admin testowy' && get_userdata($adminId)->has_cap('manage_options') && ($appPasswords[0]['uuid'] ?? '') === 'nowe-z-panelu', 'cofnięcie zmiany administratora: pseudonim wraca, hasło, hasła aplikacji i uprawnienia z panelu zostają');
$check($userOp->verifyRollback(array('id' => $adminId), $userSnapshot) === true, 'weryfikacja cofnięcia konta administratora: true mimo celowo zachowanych danych dostępu');
// Subskrybent z indywidualnym manage_options, odebranym później: chroniony według kopii.
$powerId = (int) wp_insert_user(array('user_login' => 'zaufany', 'user_email' => 'zaufany@example.test', 'user_pass' => 'Stare-Haslo-1', 'role' => 'subscriber'));
get_userdata($powerId)->add_cap('manage_options');
$powerOp = new ASM\Resources\UserResource('update');
$powerSnapshot = $powerOp->snapshot(array('id' => $powerId));
get_userdata($powerId)->remove_cap('manage_options');
wp_set_password('Nowe-Haslo-2', $powerId);
$powerPass = (string) get_userdata($powerId)->user_pass;
$powerOp->rollback(array('id' => $powerId), $powerSnapshot);
clean_user_cache($powerId);
$check((string) get_userdata($powerId)->user_pass === $powerPass && ! get_userdata($powerId)->has_cap('manage_options'), 'konto z indywidualnym manage_options w kopii: hasło i uprawnienia z panelu zostają');
// Własna rola z manage_options odebranym po kopii: ochrona zapisana w kopii.
add_role('kierownik', 'Kierownik', array('read' => true, 'manage_options' => true));
$roleId = (int) wp_insert_user(array('user_login' => 'kierownik1', 'user_email' => 'kier@example.test', 'user_pass' => 'Stare-Haslo-4', 'role' => 'kierownik'));
$roleOp = new ASM\Resources\UserResource('update');
$roleSnapshot = $roleOp->snapshot(array('id' => $roleId));
get_role('kierownik')->remove_cap('manage_options');
wp_set_password('Nowe-Haslo-4', $roleId);
$rolePass = (string) get_userdata($roleId)->user_pass;
$roleOp->rollback(array('id' => $roleId), $roleSnapshot);
clean_user_cache($roleId);
$check((string) get_userdata($roleId)->user_pass === $rolePass, 'rola z manage_options odebranym po kopii: hasło z panelu zostaje');
// Cofnięcie user.create nie usuwa konta awansowanego później na administratora ani konta z treścią.
$created = new ASM\Resources\UserResource('create');
$promotedId = (int) wp_insert_user(array('user_login' => 'awansowany', 'user_email' => 'awans@example.test', 'user_pass' => 'Haslo-5', 'role' => 'subscriber'));
(new WP_User($promotedId))->set_role('administrator');
$check($fails(static fn() => $created->rollback(array('id' => $promotedId), array('exists' => false)), 'asm_user_privileged') && get_userdata($promotedId) !== false, 'cofnięcie user.create nie usuwa awansowanego administratora');
$authorId = (int) wp_insert_user(array('user_login' => 'autor1', 'user_email' => 'autor1@example.test', 'user_pass' => 'Haslo-6', 'role' => 'author'));
wp_insert_post(array('post_title' => 'Wpis autora', 'post_status' => 'publish', 'post_author' => $authorId));
$check($fails(static fn() => $created->rollback(array('id' => $authorId), array('exists' => false)), 'asm_user_rollback_has_content') && get_userdata($authorId) !== false, 'cofnięcie user.create nie usuwa konta z wpisami');
$plainId = (int) wp_insert_user(array('user_login' => 'pusty1', 'user_email' => 'pusty1@example.test', 'user_pass' => 'Haslo-7', 'role' => 'subscriber'));
$created->rollback(array('id' => $plainId), array('exists' => false));
$check(get_userdata($plainId) === false, 'cofnięcie user.create usuwa świeże, puste konto');
// Administrator usunięty w panelu po kopii nie wraca przez cofnięcie.
$goneId = (int) wp_insert_user(array('user_login' => 'drugiadmin', 'user_email' => 'drugi@example.test', 'user_pass' => 'Haslo-Admina-3', 'role' => 'administrator'));
$goneSnapshot = (new ASM\Resources\UserResource('update'))->snapshot(array('id' => $goneId));
wp_delete_user($goneId);
$check($fails(static fn() => (new ASM\Resources\UserResource('update'))->rollback(array('id' => $goneId), $goneSnapshot), 'asm_user_privileged') && get_userdata($goneId) === false, 'usunięty administrator nie wraca przez cofnięcie');

// 8. Filtr SQL analizuje to, co wykona baza.
$policy = new ASM\Database\QueryPolicy(DB_NAME);
$check(is_wp_error($policy->validateRead("SELECT 1, '#', SLEEP(60) LIMIT 1")), "SQL: '#', SLEEP() odrzucone");
$check(is_wp_error($policy->validateRead('SELECT 1 LIMIT 1 -- komentarz')), 'SQL: komentarz poza tekstem odrzucony');
$check($policy->validateRead("SELECT option_name FROM {$wpdb->options} WHERE option_value LIKE '%#%' LIMIT 1") === true, "SQL: '#' wewnątrz tekstu dozwolone");
$check($policy->validateRead("SELECT option_name FROM {$wpdb->options} WHERE option_value = 'a\\'b -- c' LIMIT 1") === true, 'SQL: cudzysłów z ukośnikiem w tekście dozwolony');
$check(is_wp_error($policy->validateRead("SELECT * FROM {$wpdb->posts}, information_schema.tables LIMIT 1")), 'SQL: druga tabela z innej bazy po przecinku odrzucona');
$check(is_wp_error($policy->validateRead("SELECT * FROM {$wpdb->posts}, (information_schema.tables) LIMIT 1")), 'SQL: tabela z innej bazy w nawiasie odrzucona');
$check(is_wp_error($policy->validateRead("SELECT p.ID FROM {$wpdb->posts} p JOIN `mysql`.`user` u ON 1=1 LIMIT 1")), 'SQL: JOIN z inną bazą odrzucony');
$check($policy->validateRead("SELECT p.ID, p.post_title FROM {$wpdb->posts} p WHERE p.ID > 0 LIMIT 1") === true, 'SQL: alias tabeli z kropką dozwolony');
$check(is_wp_error($policy->validateRead("SELECT 1 AS `x'`, SLEEP(60), 2 AS `'y` LIMIT 1")), 'SQL: apostrof w `identyfikatorze` nie zasłania SLEEP()');
$check(is_wp_error($policy->validateRead('SELECT * FROM (`inna baza`.`t`) LIMIT 1')), 'SQL: identyfikator ze spacją odrzucony');
$check($policy->validateRead("SELECT `ID` FROM `{$wpdb->posts}` WHERE `post_status` = 'publish' LIMIT 1") === true, 'SQL: zwykłe `identyfikatory` dozwolone');
$check($policy->validateRead("SELECT p.ID, (SELECT COUNT(*) FROM {$wpdb->comments} c WHERE c.comment_post_ID = p.ID) AS n FROM {$wpdb->posts} p LIMIT 1") === true, 'SQL: podzapytanie z aliasami dozwolone');
$check(is_wp_error($policy->validateRead("SELECT * FROM {$wpdb->posts} p JOIN ({$wpdb->comments} c, information_schema.tables t) ON 1=1 LIMIT 1")), 'SQL: inna baza w grupie JOIN w nawiasie odrzucona');
$check(is_wp_error($policy->validateMutation("UPDATE IGNORE innabaza.wp_options SET option_value = 'x' WHERE option_name = 'blogname'")), 'SQL: UPDATE IGNORE innej bazy odrzucony');
$check($policy->validateRead("SELECT {$wpdb->posts}.ID FROM {$wpdb->posts} JOIN {$wpdb->postmeta} ON {$wpdb->postmeta}.post_id = {$wpdb->posts}.ID LIMIT 1") === true, 'SQL: kolumny z nazwą tabeli dozwolone');
$check($policy->validateMutation("UPDATE {$wpdb->options} SET option_value = 'x' WHERE option_name = 'asm_test_autoload'") === true, 'SQL: zwykły UPDATE dozwolony');

// 9. Popup i pasek są we wtyczce Digital Xperts Popup; konektor zmienia je tym samym zasobem (main i bar).
$popupResource = new ASM\Resources\PopupResource();
$unknownPart = $popupResource->get('okno');
$check(is_wp_error($unknownPart) && $unknownPart->get_error_code() === 'asm_resource_not_found', 'zasób popupu: nieznana część odrzucona');
if (class_exists('DigitalXperts\Popup\Bar')) {
    $barBefore = $popupResource->snapshot(array('id' => 'bar'));
    $popupResource->apply(array('id' => 'bar'), array('enabled' => true, 'message' => 'Darmowa dostawa'));
    $check($popupResource->verify(array('id' => 'bar'), array('enabled' => true, 'message' => 'Darmowa dostawa')) === true, 'konektor zmienia pasek');
    $popupResource->rollback(array('id' => 'bar'), $barBefore);
    $check(DigitalXperts\Popup\Bar::settings() == $barBefore, 'cofnięcie przywraca pasek');
    $popupResource->apply(array('id' => 'main'), array('title' => str_repeat('t', 500)));
    $check(mb_strlen((string) $popupResource->get('main')['title']) === 200, 'konektor zmienia popup, tytuł przycięty do 200 znaków');
    $check(count($popupResource->list()) === 2, 'lista zasobów popupu: popup i pasek');
} else {
    $missing = $popupResource->get('bar');
    $check(is_wp_error($missing) && $missing->get_error_code() === 'asm_popup_plugin_missing', 'bez wtyczki Popup: jasny błąd');
    try {
        $popupResource->apply(array('id' => 'main'), array('enabled' => true));
        $check(false, 'bez wtyczki Popup: zmiana odrzucona');
    } catch (ASM\Operations\OperationFailure $failure) {
        $check($failure->operationCode() === 'asm_popup_plugin_missing', 'bez wtyczki Popup: zmiana odrzucona');
    }
}
$download = new ReflectionMethod(ASM\Resources\ComponentResource::class, 'repositoryDownload');
$download->setAccessible(true);
$check($download->invoke(new ASM\Resources\ComponentResource('plugin', 'install', $storage), 'plugin', 'digital-xperts-popup') === 'https://github.com/DariuszTrachimowicz/digital-xperts-popup/releases/latest/download/digital-xperts-popup.zip', 'instalacja wtyczki Popup z wydań GitHuba');

// 10. Sprzątanie: stara operacja i jej kopia znikają, świeża zostaje, osierocony katalog po dobie znika.
$oldId = wp_generate_uuid4();
$liveId = wp_generate_uuid4();
foreach (array($oldId => gmdate('Y-m-d H:i:s', time() - 40 * 86400), $liveId => gmdate('Y-m-d H:i:s')) as $id => $updated) {
    $wpdb->insert($wpdb->prefix . 'asm_operations', array('id' => $id, 'status' => 'verified', 'actor_id' => 1, 'payload' => '{}', 'updated_at' => $updated));
    $storage->store($id, 'files/kopia.txt', 'kopia');
}
$storage->store($oldId . '-after', 'files/kopia.txt', 'po');
$storage->store('osierocony', 'uploads/plik.txt', 'x');
touch($storageRoot . '/osierocony', time() - 2 * 86400);
ASM\Storage\Retention::run($wpdb, $storage);
$dirs = $storage->directories();
$oldRow = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}asm_operations WHERE id = %s", $oldId));
$check($oldRow === null && ! isset($dirs[$oldId]) && ! isset($dirs[$oldId . '-after']), 'stara operacja: wiersz, kopia i kopia „-after” usunięte');
$check(isset($dirs[$liveId]) && ! isset($dirs['osierocony']), 'świeża operacja zostaje, osierocony katalog usunięty');

// 11. Przywracanie katalogu: pełna podmiana bez resztek.
$component = WP_CONTENT_DIR . '/asm-test-component';
wp_mkdir_p($component . '/sub');
file_put_contents($component . '/a.php', '<?php // a');
file_put_contents($component . '/sub/b.txt', 'b');
$captured = ASM\Components\DirectorySnapshot::capture($component);
file_put_contents($component . '/a.php', '<?php // zmienione');
file_put_contents($component . '/nowy.txt', 'nowy');
ASM\Components\DirectorySnapshot::restore($component, $captured);
$leftovers = glob(WP_CONTENT_DIR . '/asm-test-component.asm-*') ?: array();
$check(file_get_contents($component . '/a.php') === '<?php // a' && ! file_exists($component . '/nowy.txt') && file_get_contents($component . '/sub/b.txt') === 'b' && $leftovers === array(), 'przywrócenie katalogu: stan z kopii, bez katalogów tymczasowych');

// 12. Odinstalowanie odcina dostęp: uprawnienie kont i identyfikator instalacji znikają.
get_userdata($adminId)->add_cap('asm_full_control');
update_option('asm_mcp_install_id', 'test-install-id', false);
define('WP_UNINSTALL_PLUGIN', 'ai-site-manager/ai-site-manager.php');
include ASM_PLUGIN_DIR . 'uninstall.php';
clean_user_cache($adminId);
wp_cache_delete('asm_mcp_install_id', 'options');
$newInstallId = (string) get_option('asm_mcp_install_id');
$check(! get_userdata($adminId)->has_cap('asm_full_control') && $newInstallId !== '' && $newInstallId !== 'test-install-id', 'uninstall: uprawnienie konta zdjęte, nowy identyfikator instalacji (stare tokeny nieważne)');

echo $failures === 0 ? "WSZYSTKO OK\n" : "BLEDY: {$failures}\n";
exit($failures === 0 ? 0 : 1);
