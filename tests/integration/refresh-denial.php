<?php
// Uruchom w lokalnym, jednorazowym WordPressie z włączonym Menedżerem: wp eval-file refresh-denial.php
// Sprawdza, że odmowa odświeżenia tokenu zostawia w audycie powód, a chwilowa przyczyna nie zrywa połączenia.
// Zmienia dane testowej instalacji (opcje, uprawnienia, połączenia): nie uruchamiać na produkcji.
if (wp_get_environment_type() !== 'local') {
    fwrite(STDERR, "Tylko lokalny WordPress testowy.\n");
    exit(1);
}
use ASM\MCP\Connections;
use ASM\MCP\OAuthClients;
use ASM\MCP\OAuthDiscovery;
use ASM\MCP\OAuthGrant;

$failures = 0;
$check = static function (bool $ok, string $name) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    $failures += $ok ? 0 : 1;
};
global $wpdb;
$admin = get_users(array('role' => 'administrator', 'number' => 1))[0];
$admin->add_cap('asm_full_control');
wp_set_current_user($admin->ID);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

$redirect = 'https://klient.example/callback';
$client = OAuthClients::register(array('redirect_uris' => array($redirect), 'client_name' => 'Test odmowy'));
$verifier = str_repeat('a', 43);
$base = array('client_id' => $client['client_id'], 'redirect_uri' => $redirect, 'resource' => OAuthDiscovery::resource());
$code = OAuthGrant::issueCode($base + array('code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'scope' => 'full'), 'full', 0);
if (is_wp_error($code)) {
    fwrite(STDERR, 'Nie wydano kodu: ' . $code->get_error_code() . "\n");
    exit(1);
}
$tokens = OAuthGrant::exchangeCode($base + array('code' => $code, 'code_verifier' => $verifier));
$check(is_array($tokens) && isset($tokens['refresh_token']), 'logowanie wydaje tokeny');

$refresh = static fn(string $token) => OAuthGrant::refresh(array('client_id' => $client['client_id'], 'resource' => $base['resource'], 'refresh_token' => $token));
$denied = static fn($reply): bool => is_wp_error($reply) && $reply->get_error_code() === 'invalid_grant';
$audit = Connections::table('audit');
$connections = Connections::table('connections');
$id = $wpdb->get_var($wpdb->prepare('SELECT connection_id FROM ' . OAuthGrant::table() . ' WHERE hash=%s', hash('sha256', $tokens['refresh_token'])));
$reasons = static fn(): array => $wpdb->get_col($wpdb->prepare("SELECT result FROM $audit WHERE connection_id=%s AND event='oauth.refresh_denied' ORDER BY id", $id));
$forget = static fn() => $wpdb->query($wpdb->prepare("DELETE FROM $audit WHERE connection_id=%s AND event='oauth.refresh_denied'", $id));
$revoked = static fn(): int => (int) $wpdb->get_var($wpdb->prepare("SELECT revoked FROM $connections WHERE id=%s", $id));

$first = $refresh($tokens['refresh_token']);
$check(is_array($first) && $reasons() === array(), 'udane odświeżenie nie zostawia odmowy');
$current = $first['refresh_token'];

// Zmiana identyfikatora instalacji (tak samo działa zmiana kluczy WordPressa albo adresu strony).
$install = get_option('asm_mcp_install_id');
update_option('asm_mcp_install_id', wp_generate_uuid4(), false);
$check($denied($refresh($current)) && $reasons() === array('denied_binding'), 'zmiana wiązania: odmowa z powodem denied_binding');
update_option('asm_mcp_install_id', $install, false);
$check($revoked() === 0, 'odmowa z powodu wiązania nie cofa połączenia');

// Druga odmowa w tej samej godzinie nie dopisuje wiersza.
$wpdb->update($connections, array('expires_at' => time() - 10), array('id' => $id));
$check($denied($refresh($current)) && $reasons() === array('denied_binding'), 'najwyżej jeden wpis odmowy na godzinę');
$wpdb->query($wpdb->prepare("UPDATE $audit SET created_at=%d WHERE connection_id=%s AND event='oauth.refresh_denied'", time() - 3700, $id));
$check($denied($refresh($current)) && $reasons() === array('denied_binding', 'denied_expired'), 'po godzinie kolejny wpis: denied_expired');
$wpdb->update($connections, array('expires_at' => 0), array('id' => $id));

$forget();
$admin->remove_cap('asm_full_control');
$check($denied($refresh($current)) && $reasons() === array('denied_role'), 'utrata uprawnienia: denied_role');
$admin->add_cap('asm_full_control');

$forget();
$check($denied($refresh('or.' . bin2hex(random_bytes(32)))) && $reasons() === array(), 'nieznany token: odmowa bez wpisu');

// Po ustaniu przyczyny ten sam token działa dalej: odmowa niczego nie zużyła.
$second = $refresh($current);
$check(is_array($second) && isset($second['access_token']), 'po ustaniu przyczyny odświeżenie działa tym samym tokenem');

// Stary, zużyty token po oknie ponowienia zrywa połączenie (zabezpieczenie bez zmian) i zostawia oba wpisy.
$wpdb->query($wpdb->prepare('UPDATE ' . OAuthGrant::table() . ' SET consumed_at=%d WHERE hash=%s', time() - 120, hash('sha256', $current)));
$check($denied($refresh($current)) && $revoked() === 1, 'ponowne użycie starego tokenu cofa połączenie');
$replay = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $audit WHERE connection_id=%s AND event='oauth.refresh_replay'", $id));
$check($replay === 1 && $reasons() === array('denied_replay'), 'ponowne użycie: wpis refresh_replay i odmowa denied_replay');
$forget();
$check($denied($refresh($second['refresh_token'])) && $reasons() === array('denied_revoked'), 'cofnięte połączenie: denied_revoked');
$forget();
$wpdb->update($connections, array('deleted_at' => time()), array('id' => $id));
$check($denied($refresh($second['refresh_token'])) && $reasons() === array('denied_deleted'), 'usunięte połączenie: denied_deleted, nie denied_revoked');

echo $failures ? "PORAŻKI: $failures\n" : "OK\n";
exit($failures ? 1 : 0);
