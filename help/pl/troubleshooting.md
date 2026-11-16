# Rozwiązywanie problemów

401: sprawdź poświadczenie, termin i cofnięcie. Zmienna musi być widoczna w procesie klienta. Cookies administratora nie zastępują Bearer. Nie wysyłaj sekretu do pomocy.

403: sprawdź zakres połączenia, uprawnienia właściciela i Origin. Do zapisu wymagana jest istniejąca capability asm_full_control. Nie wyłączaj autoryzacji.

404: skopiuj dokładny adres z panelu. Sprawdź aktywność wtyczki i permalinki. Nie zgaduj `/sse`. Dla plain użyj dokładnego adresu `rest_route` wygenerowanego przez panel, bez dodatkowych parametrów.

405: GET jest prawidłowo odrzucany, ponieważ ten transport nie udostępnia SSE. Testuj initialize klientem MCP przez POST. POST 405 może oznaczać błędny adres lub proxy.

400, 406, 415: sprawdź wersję MCP, pojedynczy JSON-RPC, Content-Type i Accept. Nie przesyłaj dodatkowych pól ani magicznych parametrów WordPressa.

429: odczekaj minutę zgodnie z Retry-After. Ogranicz równoległość. Nie uruchamiaj pętli ponawiania zapisów. Limit modelu dostawcy jest odrębnym limitem konta.

503: sprawdź wersję ASM 2.1.0, stan DB, tabele InnoDB i prywatny magazyn ASM poza webrootem. Błąd po zapisie wymaga sprawdzenia skutku, a nie nowej próby execute.

ENOENT w stdio: sprawdź pełne ścieżki Node i bridge.mjs. Mostek nie wymaga npm ani innych pakietów. Node musi mieć wersję co najmniej 20.

CLI działa, Desktop nie: sprawdź środowisko procesu i konfigurację konkretnej aplikacji. Wynik CLI lub SDK nie potwierdza działania Desktop.

Konflikt cofnięcia: zachowano późniejszą edycję. Sprawdź bieżącą treść w WordPressie. Nie wymuszaj cofnięcia przez zmianę statusu operacji.

## Windows: bezsekretowa diagnostyka

- 401: w tym samym PowerShell sprawdź `Test-Path Env:ASM_MCP_TOKEN` (True/False), potem uruchom klienta z tego terminala.
- ENOENT: znajdź Node przez `(Get-Command node.exe).Source`; znajdź mostek przez Eksplorator, rozpakowany ZIP, bridge, bridge.mjs, Kopiuj jako ścieżkę. Sprawdź `Test-Path -LiteralPath 'pełna znaleziona ścieżka'`.
- Desktop: znajdź `.exe` w Menedżerze zadań, Otwórz lokalizację pliku, zamknij także proces w zasobniku i użyj `Start-Process -FilePath` z tego terminala. Pełne kroki są w instrukcji wybranej aplikacji.

## Logowanie Claude

Brak ekranu logowania: w Pomocy otwórz „Szczegóły techniczne: sprawdzenie logowania” i wybierz „Sprawdź adresy logowania”. Gdy hosting przejmuje /.well-known, wtyczka przełączy aplikacje na adresy pod /wp-json/asm-mcp/v1. Potem usuń połączenie w aplikacji i dodaj je ponownie. Jeśli to nie pomaga, sprawdź odpowiedź 401 z nagłówkiem WWW-Authenticate oraz dostępność JSON metadanych pod /.well-known. Statyczna reguła serwera może blokować przekazanie ścieżek do WordPressa. Instrukcja operatora jest w [Aktualizacji](update.md).

Błąd dokumentu tożsamości Claude: sprawdź dostępność bezpiecznego transportu cURL i dwóch oficjalnych adresów. Wtyczka nie zastępuje pobranego dokumentu zgadywanymi danymi. Gdy bezpieczny transport jest niedostępny, metadane nie reklamują CIMD; klient może zarejestrować się przez DCR.

Zgoda udzielona bez Połączono: aplikacja jeszcze nie wykonała potwierdzonego odczytu. Sprawdź wp_site_status z klienta. Nie rozszerzaj uprawnień tylko po to, by ominąć błąd logowania.
