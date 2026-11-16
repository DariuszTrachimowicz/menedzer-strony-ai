# Wdrożenie

Ta procedura dotyczy Menedżera Strony AI 2.1.0 na wybranej stronie. Samo zbudowanie paczki nie oznacza wdrożenia.

## 1. Staging

Najniższy przetestowany zakres wydania to WordPress 7.1 / PHP 8.2. Starszy natywny fallback zachowuje warunek niewczytywania MCP poniżej PHP 8.2, ale zgodność WordPress 5.6 / PHP 7.4 nie została potwierdzona. Wyniki pełnych testów i runtime PHP 8.5 podaje raport FIX1.md przekazywany osobno operatorowi.

1. Utwórz aktualny klon plików i bazy strony docelowej.
2. Potwierdź, że staging nie wysyła prawdziwych maili i nie jest indeksowany.
3. Utwórz prywatny katalog poza document root i nadaj zapis wyłącznie użytkownikowi PHP.
4. Dodaj `ASM_PRIVATE_STORAGE` do `wp-config.php` stagingu.
5. Zapisz obecną paczkę wtyczki i opcje `asm_popup`, `asm_key_hash` oraz `asm_settings`.
6. Zaktualizuj istniejącą wtyczkę przez `ai-site-manager-2.1.0.zip`. Nie instaluj osobnej wtyczki MCP.
7. W panelu nadaj pełny dostęp wybranemu administratorowi technicznemu.
8. Utwórz osobne hasło aplikacji nazwane na przykład `Codex staging`.

## 2. Bramka akceptacyjna stagingu

Wykonaj kolejno i zachowaj `operation_id`:

- anonimowy ping zwraca 401,
- uwierzytelniony ping zwraca wersję 2.1.0,
- inventory i listy zasobów nie zawierają sekretów,
- podmiana dokładnie jednego słowa, kontrola publicznego HTML i rollback,
- upload PNG lub WebP, podmiana testowej grafiki z zachowaniem ID oraz rollback,
- patch nieszkodliwego pliku testowego i rollback,
- kontrolowana zmiana opcji testowej i rollback,
- czyszczenie używanego cache oraz świeże sprawdzenie publicznej strony,
- popup pozostaje wyłączony, chyba że jego osobne włączenie zostało zaakceptowane; po takim włączeniu sprawdź go na 320 px i desktopie, Tab, Shift+Tab, Escape oraz współpracę z Complianz,
- log audytu, idempotencja i brak jawnego payloadu w bazie,
- strona główna, `/wp-json/asm/v2/ping`, log PHP i Site Health po każdej ryzykownej zmianie.

Po testach usuń hasło aplikacji stagingu.

## Połączenia MCP i OAuth

1. Przed aktualizacją zapisz stan aktywnych wtyczek, identyfikator instalacji, binding, liczbę połączeń, operacji i audytu. Nie zapisuj jawnych danych dostępu.
2. Sprawdź, że nie ma operacji w toku ani z nieustalonym wynikiem. Migracja jest addytywna i zachowuje stare terminy oraz tokeny.
   Przy dostarczaniu plików przez kontrolowane natywne API najpierw twórz nowe pliki, później zmieniaj AdminPage i Installer, a bootstrap ai-site-manager.php dostarcz na końcu. Nowy panel uruchamia się dopiero po zakończonym boot modułu; stary bootstrap nadal pokazuje natywne ustawienia bez zapytań do nowych tabel/kolumn. Marker migracji MCP 2.1.0-fix1 dodaje tabelę jednorazowych kontekstów zgody i nie zmienia uprawnień.
3. Przy aktywnym ASM MCP Connect nowy moduł przejmuje jego wejścia. Dopiero po potwierdzeniu nowego odczytu, podglądu, wykonania i rollbacku dezaktywuj osobną wtyczkę. Zachowaj jej pliki jako kopię. Następne żądanie odtwarza wspólne zdarzenie cleanup.
4. Potwierdź jeden ekran Menedżera Strony AI i działanie starego Bearer. Sprawdź terminy, usuwanie z nonce i właścicielem oraz zachowanie historii.
5. Sprawdź trzy dokładne adresy: `/.well-known/oauth-protected-resource`, `/.well-known/oauth-protected-resource/wp-json/asm-mcp/v1/mcp` i `/.well-known/oauth-authorization-server`. Oczekuj HTTP 200, JSON oraz resource i issuer z panelu. Anonimowe MCP ma zwracać 401 z resource_metadata.
6. Jeżeli Nginx serwuje /.well-known statycznie, operator musi dodać trzy dokładne location = przekazujące te adresy do istniejącego backendu WordPressa z kanonicznym Host i bez cache. Nie zastępuj szerokiej reguły /.well-known i nie zmieniaj ACME ani ARD. Wykonaj kopię konfiguracji, kontrolę hash, nginx -t, reload i odczyt publiczny. Szczegóły oraz Apache są w `help/pl/update.md`.
7. Własny testowy klient powinien przejść logowanie, zgodę, wymianę kodu, odczyt MCP, refresh, revoke i delete. Zgoda właściciela w Claude wymaga osobnego działania właściciela.
8. Diagnostyka w pomocy sprawdza dostępność metadanych z serwera. Dla CIMD potrzebny jest cURL z pinowaniem publicznych adresów DNS i działającym TLS. Błąd pobrania oficjalnego dokumentu nie jest zastępowany kopią wbudowaną.

## 3. Produkcja

Produkcję można zmienić dopiero po jawnej akceptacji wyniku stagingu.

1. Wykonaj pełny backup plików i bazy oraz sprawdź możliwość odtworzenia.
2. Zaplanuj krótkie okno serwisowe.
3. Potwierdź ścieżkę i uprawnienia `ASM_PRIVATE_STORAGE` na właściwym koncie hostingowym.
4. Zapisz hash paczki wydania i obecną wersję wtyczki.
5. Wgraj ZIP przez WordPress lub atomowo podmień katalog wtyczki.
6. Aktywuj i sprawdź, czy tabele `asm_operations`, `asm_idempotency`, `asm_operation_steps`, `asm_audit` i `asm_locks` istnieją z właściwym prefiksem.
7. Utwórz dedykowane hasło aplikacji produkcji i przechowuj je poza repozytorium oraz logami.
8. Wykonaj tylko ping, inventory i odczyt zasobów.
9. Dopiero potem wykonaj małą, odwracalną operację treści wraz z rollbackiem.

Nie testuj aktualizacji rdzenia, masowego SQL ani usuwania komponentów po raz pierwszy na produkcji.
