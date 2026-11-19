# Menedżer Strony AI: instrukcja dla agentów (Codex, Claude)

Stan na 16 listopada 2026. To jest kanoniczne źródło wtyczki od wersji 2.4.0. Starsze kopie robocze w `d-x.pl/operations/asm-220-20261116/worktree`, `Jest Moda Na Makalu/outputs/...` i w bibliotece Dropbox są historyczne. Nie rozwijaj ich.

## Gdzie co jest

- Repozytorium: https://github.com/DariuszTrachimowicz/menedzer-strony-ai (publiczne, gałąź main). Nie wpisuj tu sekretów, haseł, tokenów ani danych klientów.
- Kod PHP: `src/`. Połączenia MCP i OAuth: `src/MCP/`. Popup i pasek od 2.9.0 są we wtyczce Digital Xperts Popup (`C:/Users/CP24/Documents/ChatGPT/digital-xperts-popup`); tu został tylko zasób `src/Resources/PopupResource.php`. Panel: `src/Admin/AdminPage.php`, `src/MCP/Admin.php`, `assets/mcp-admin.css`. Aktualizacje: `src/Updater.php`.
- Testy: `tests/`, uruchamiane w PHP 8.3 z `-d zend.assertions=1` (na komputerze Darka przez Docker, lokalnego PHP nie ma).

## Wydanie nowej wersji

1. Podnieś wersję w `ai-site-manager.php` (nagłówek i `ASM_VERSION`), `readme.txt`, `README.md`, dopisz `CHANGELOG.md`.
2. `python build.py` tworzy `dist/ai-site-manager-<wersja>.zip` (bez testów).
3. Commit i push, potem `gh release create v<wersja> dist/ai-site-manager-<wersja>.zip --title <wersja> --notes "..."`.
4. Strony z wersją 2.4.0 lub nowszą widzą wydanie na zwykłym ekranie aktualizacji WordPressa (odczyt co kilka godzin albo „Sprawdź teraz” w zakładce System i aktualizacje). Tag musi mieć postać `vX.Y.Z`, a załącznik dokładnie nazwę `ai-site-manager-X.Y.Z.zip` (tak nazywa go build.py) z katalogiem `ai-site-manager/`; wtyczka od 2.6.2 składa adres paczki z numeru wersji i nie pyta API GitHuba.

Opublikowane wydanie trafia na strony klientów. Nie publikuj wydania bez testów i bez zgody Darka.

## Ustalenia, których nie wolno zgubić

- Numer 2.3.0 jest zajęty przez osobną paczkę z pinem serwera licencji (`Menedzer Strony AI Licencje`). Ta linia nie jest scalona z tym repozytorium.
- Logowanie OAuth: gdy hosting przejmuje `/.well-known` (dhosting na d-x.pl), wtyczka przełącza się na adresy pod `/wp-json/asm-mcp/v1` (opcja `asm_mcp_discovery`, `OAuthDiscovery::detect()`). Dokumenty OAuth muszą zostać poza LiteSpeed Cache. Zmiana trybu zmienia issuer.
- Popup: ustalenia (styl `classic`, pokazywanie przed banerem cookies, poza opóźnianiem JS) są w AGENTS.md wtyczki Digital Xperts Popup. Nie przywracaj popupu do Menedżera: wtyczka Popup pomija wyświetlanie, gdy istnieje klasa `ASM\Popup\Popup`.
- Aktualizator obsługuje tylko publiczne repozytorium. Nazwa filtra `update_plugins_d-x.pl` wynika z hosta w nagłówku `Update URI`.
- Po każdym podbiciu wersji sprawdź `wp_site_status` połączenia z pełnym dostępem: `dependency` i `can_mutate` mają zostać true (regresja 2.2.1 do 2.4.0, test `tests/mcp/test-dependency.php`).
- Commity bez dopisków o narzędziach AI, autor z adresem noreply GitHuba.

Wspólny stan projektów: `C:/Users/CP24/AiXperts - projekty/DOKUMENTACJA-PROJEKTU.md`. Odbiór wdrożenia na d-x.pl: `C:/Users/CP24/Documents/ChatGPT/d-x.pl/operations/asm-220-20261116/ODBIOR-221.md`.
