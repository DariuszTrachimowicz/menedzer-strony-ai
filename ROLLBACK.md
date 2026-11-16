# Rollback

## Pojedyncza operacja

1. Pobierz stan przez `GET /asm/v2/operations/{id}`.
2. Jeżeli operacja jest `verified`, `failed` albo `unknown`, wywołaj `POST /asm/v2/operations/{id}/rollback`.
3. Oczekuj stanu `rolled_back`.
4. Sprawdź zasób przez API i niezależnie na publicznej stronie.
5. Jeżeli pojawi się `rollback_failed`, nie powtarzaj mutacji. Zachowaj prywatną kopię i przejdź do ręcznego odtworzenia.

## Cała wtyczka

Przy wycofaniu wydania 2.1.0 przywracaj wyłącznie pliki z kontrolą hash i kopii. Nie cofaj bazy danych, schematu, odwołań ani oznaczeń usunięcia. Zachowaj tabele połączeń, OAuth, operacji i audytu oraz install_id. Nie ponawiaj operacji z wynikiem unknown. Starsza wersja może nie obsługiwać OAuth i terminów bez końca; utratę tych funkcji oznacz jawnie, bez wydłużania grantów lub przywracania cofniętego dostępu. Powrót do osobnego modułu wymaga osobnej kontroli operatora i nie usuwa jego zachowanej kopii.

1. Unieważnij dedykowane hasło aplikacji ASM.
2. Włącz tryb serwisowy, jeżeli błąd wpływa na publiczną stronę.
3. Przywróć poprzedni katalog `wp-content/plugins/ai-site-manager` z kopii.
4. Nie usuwaj tabel audytu ani prywatnego magazynu przed wyjaśnieniem operacji w toku.
5. Jeżeli poprzednia wersja używała API v1, pozostaw je wyłączone, dopóki nie zapadnie jawna decyzja o krótkim oknie zgodności.
6. Sprawdź stronę główną, REST, popup, log PHP i ostatnio zmieniany zasób.

## Media i pliki

Prywatne kopie zawierają plik główny i warianty mediów albo pełną treść pliku. Nie usuwaj katalogu wskazanego przez `ASM_PRIVATE_STORAGE`, dopóki wszystkie operacje nie mają potwierdzonego stanu końcowego.

Trwale usunięte wpisy, powiązane komentarze, media i pozycje menu są odtwarzane z oryginalnymi ID oraz przechwyconymi metadanymi. Usunięcie użytkownika jest odwracalne tylko z wymaganym parametrem `reassign`, który podczas operacji chroni jego treści przed skasowaniem.

## Baza danych

Rollback SQL wykonuje wyłącznie polecenia zatwierdzone podczas preview. Jeżeli nie można go zweryfikować, odtwórz wskazane tabele lub rekordy z backupu stagingowego albo hostingowego. Nie uruchamiaj ponownie tego samego SQL z nowym kluczem idempotencji.

## Rdzeń WordPressa

Automatyczny rollback rdzenia nie jest deklarowany jako bezpieczny. Przywróć pliki rdzenia i zgodny snapshot bazy z narzędzia hostingu, a następnie sprawdź wersję bazy przez `wp core update-db` tylko wtedy, gdy wymaga tego przywrócona wersja.
