# Aktualizacja i wycofanie

Menedżer Strony AI 2.1.0 jest jedną aktualizacją istniejącej wtyczki ai-site-manager. Połączenia MCP, panel, pomoc i most są w tej samej paczce. Nie instaluj drugiej wtyczki MCP.

Przed aktualizacją wykonaj kopię bazy, plików i prywatnego magazynu ASM. Sprawdź brak operacji w toku lub z nieustalonym wynikiem. Pakiet ai-site-manager-2.2.0.zip przetestuj na kopii strony. Wgraj go jako aktualizację Menedżera Strony AI.

Migracja dodaje pola i tabele potrzebne logowaniu. Zachowuje identyfikator instalacji, powiązanie, połączenia, tokeny, operacje i audyt. Stare daty ważności nie są zmieniane. Aktywna stara wtyczka ASM MCP Connect zostaje przejęta przez nowy moduł, ale operator powinien wyłączyć ją dopiero po sprawdzeniu działania nowego wydania. Zachowaj stare pliki jako kopię. Nie usuwaj ręcznie tabel.

Najniższe przetestowane wersje tego wydania to WordPress 7.1 i PHP 8.2. Zgodność natywnego fallbacku z WordPress 5.6 / PHP 7.4 nie została potwierdzona. Warunek ładowania nie uruchamia modułu Połączenia AI na starszym PHP. Obsługa multisite wymaga osobnego odbioru i nie jest deklarowana przez ten pakiet.

Po odtworzeniu kopii cofnij wszystkie połączenia. Wycofanie plików nie cofa zmian bazy, treści ani decyzji o odwołaniu dostępu. Operacji niejasnych nie wykonuj ponownie.

Dezaktywacja zachowuje dane. Audyt i zakończone operacje podlegają retencji 30 dni. Operacje w toku i z nieustalonym wynikiem pozostają do wyjaśnienia. Usunięte albo zakończone połączenie może być usunięte po 90 dniach tylko bez powiązanych operacji. Aktywne bezterminowe połączenia pozostają.

Pakiet zawiera kod PHP, zasoby panelu, pomoc i most Node. Nie zawiera testów, logów, danych QA, zależności deweloperskich ani poświadczeń.

## Dostępność logowania przez HTTPS

Serwer musi przekazywać dokładne ścieżki metadanych OAuth do WordPressa. Dla Nginx z osobnym statycznym location /.well-known operator dodaje trzy dokładne reguły dla /.well-known/oauth-protected-resource, /.well-known/oauth-protected-resource/wp-json/asm-mcp/v1/mcp oraz /.well-known/oauth-authorization-server. Nie zastępuje całego /.well-known ani reguł ACME i ARD.

Jeżeli WordPress jest w podkatalogu, użyj ścieżki resource pokazanej przez metadane tej instalacji. Przy Apache zwykły fallback do WordPressa obsługuje te ścieżki, chyba że hosting wyłącza /.well-known. Sprawdź faktyczne odpowiedzi JSON bez cache i poprawny adres resource oraz issuer; samo wgranie plików nie potwierdza dostępności discovery.


W wydaniu 2.2.0 pełna administracja wymaga nowego połączenia i jawnej zgody na Pełne zarządzanie stroną. Wybierz Bezterminowo, jeśli dostęp ma trwać do cofnięcia. Istniejący publiczny odczyt zachowuje zakres. Szczegóły narzędzi i cofania zmian: [Zakresy i uprawnienia](permissions.md).
