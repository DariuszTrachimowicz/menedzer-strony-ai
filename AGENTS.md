# Menedżer Strony AI: instrukcja dla agentów (Codex, Claude)

Stan na 16 listopada 2026. To jest kanoniczne źródło wtyczki od wersji 2.4.0. Starsze kopie robocze w `d-x.pl/operations/asm-220-20261116/worktree`, `Jest Moda Na Makalu/outputs/...` i w bibliotece Dropbox są historyczne. Nie rozwijaj ich.

## Gdzie co jest

- Repozytorium: https://github.com/DariuszTrachimowicz/menedzer-strony-ai (publiczne, gałąź main). Nie wpisuj tu sekretów, haseł, tokenów ani danych klientów.
- Kod PHP: `src/`. Połączenia MCP i OAuth: `src/MCP/`. Popup: `src/Popup/Popup.php`, `assets/popup.js`, `assets/popup-v3.css` (styl klasyczny), `assets/popup-theme.css` (style „Jak strona” i Własny). Panel: `src/Admin/AdminPage.php`, `src/MCP/Admin.php`, `assets/mcp-admin.css`. Aktualizacje: `src/Updater.php`.
- Testy: `tests/`, uruchamiane w PHP 8.3 z `-d zend.assertions=1` (na komputerze Darka przez Docker, lokalnego PHP nie ma).

## Wydanie nowej wersji

1. Podnieś wersję w `ai-site-manager.php` (nagłówek i `ASM_VERSION`), `readme.txt`, `README.md`, dopisz `CHANGELOG.md`.
2. `python build.py` tworzy `dist/ai-site-manager-<wersja>.zip` (bez testów).
3. Commit i push, potem `gh release create v<wersja> dist/ai-site-manager-<wersja>.zip --title <wersja> --notes "..."`.
4. Strony z wersją 2.4.0 lub nowszą widzą wydanie na zwykłym ekranie aktualizacji WordPressa (odczyt co kilka godzin albo „Sprawdź teraz” w zakładce System i aktualizacje). Tag musi mieć postać `vX.Y.Z`, a załącznik nazwę `ai-site-manager-*.zip` z katalogiem `ai-site-manager/`.

Opublikowane wydanie trafia na strony klientów. Nie publikuj wydania bez testów i bez zgody Darka.

## Ustalenia, których nie wolno zgubić

- Numer 2.3.0 jest zajęty przez osobną paczkę z pinem serwera licencji (`Menedzer Strony AI Licencje`). Ta linia nie jest scalona z tym repozytorium.
- Logowanie OAuth: gdy hosting przejmuje `/.well-known` (dhosting na d-x.pl), wtyczka przełącza się na adresy pod `/wp-json/asm-mcp/v1` (opcja `asm_mcp_discovery`, `OAuthDiscovery::detect()`). Dokumenty OAuth muszą zostać poza LiteSpeed Cache. Zmiana trybu zmienia issuer.
- Popup: zapisane ustawienia bez pola `style` zostają przy wyglądzie klasycznym. Nie zmieniaj domyślnego `classic`, bo zmieni wygląd działających popupów.
- Popup pokazuje się od razu, przed banerem cookies (decyzja Darka 16 XI 2026). Nie przywracaj czekania na baner ani przełącznika. Skrypt popupu musi zostać poza opóźnianiem JS w LiteSpeed (atrybuty data-no-optimize i data-no-defer).
- Aktualizator obsługuje tylko publiczne repozytorium. Nazwa filtra `update_plugins_d-x.pl` wynika z hosta w nagłówku `Update URI`.
- Po każdym podbiciu wersji sprawdź `wp_site_status` połączenia z pełnym dostępem: `dependency` i `can_mutate` mają zostać true (regresja 2.2.1 do 2.4.0, test `tests/mcp/test-dependency.php`).
- Commity bez dopisków o narzędziach AI, autor z adresem noreply GitHuba.

Wspólny stan projektów: `C:/Users/CP24/AiXperts - projekty/DOKUMENTACJA-PROJEKTU.md`. Odbiór wdrożenia na d-x.pl: `C:/Users/CP24/Documents/ChatGPT/d-x.pl/operations/asm-220-20261116/ODBIOR-221.md`.
