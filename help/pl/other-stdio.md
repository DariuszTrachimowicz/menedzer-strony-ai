# Inny klient stdio

Najpierw zainstaluj wybrany klient według jego oficjalnej instrukcji i sprawdź jego wersję w About lub udokumentowanym poleceniem. To opis wymagań integracji, nie deklaracja przetestowania dowolnej aplikacji. Jeśli klient nie potrafi uruchomić programu z odziedziczonym środowiskiem, wybierz [Claude Code](claude-code.md) lub [Codex CLI](codex-cli.md).

## Windows: Node.js

1. Otwórz [oficjalne pobieranie Node.js](https://nodejs.org/en/download) Wybierz aktualne LTS i instalator Windows `.msi` dla swojego komputera. Mostek wymaga co najmniej Node 20.
2. Uruchom pobrany instalator, zostaw dodanie Node do PATH, zakończ instalację. Zamknij stary terminal i otwórz nowy PowerShell.
3. Wykonaj poniższe polecenia. Pierwsze ma pokazać wersję `v20` lub wyższą; ostatnie pełną ścieżkę kończącą się `node.exe`.

```powershell
node.exe --version
npm.cmd --version
(Get-Command node.exe).Source
```

Jeśli polecenie nie jest rozpoznane, sprawdź instalację i ponownie otwórz terminal. Nie wyłączaj globalnie ExecutionPolicy. W przykładach npm używamy `npm.cmd`.

## Windows: rozpakuj i znajdź mostek

1. Pobierz ZIP tego wydania od administratora strony. W Eksploratorze kliknij ZIP prawym przyciskiem ,  Wyodrębnij wszystkie. Wybierz stały folder w Dokumentach. Nie wskazuj pliku wewnątrz ZIP ani pliku na serwerze WordPressa.
2. Otwórz rozpakowany `ai-site-manager`, potem `bridge`. Wybierz `bridge.mjs` ,  Kopiuj jako ścieżkę (ewentualnie Shift+prawy przycisk). Ścieżka musi kończyć się `bridge.mjs`.
3. W PowerShell sprawdź obie ścieżki. Mostek nie wymaga `npm ci` ani zależności npm.

```powershell
(Get-Command node.exe).Source
$asmBridgePath = (Read-Host 'Wklej pełną ścieżkę bridge.mjs').Trim('"')
Test-Path -LiteralPath $asmBridgePath
```

Ostatni wynik musi być `True`. W JSON użyj pełnej ścieżki Node z pierwszej komendy jako `command`, a znalezionej ścieżki mostka jako jedynego elementu `args`. W JSON każdy backslash zapisuje się podwójnie: `C:\\Users\\Anna\\Documents\\ai-site-manager\\bridge\\bridge.mjs`. Zastąp przykładowe ścieżki swoimi.

## Windows: zapisz sekret i przygotuj PowerShell

Najpierw zainstaluj i sprawdź klienta według kroków wyżej. Dopiero potem w WordPressie wybierz Dodaj połączenie; na pierwszy test wystarczy Publiczny odczyt. Przed opuszczeniem jednorazowego ekranu zapisz token w menedżerze haseł pod nazwą witryny i połączenia. Nie zapisuj go w Notatniku, konfiguracji, historii poleceń ani rozmowie. Jeśli ekran już zniknął i nie masz tokena, cofnij stary dostęp i utwórz nowy.

Pozostań w tym samym oknie PowerShell na komputerze klienta, w którym przygotowałeś konfigurację i folder projektu powyżej. Nie otwieraj nowego okna między `Set-Location`, wprowadzeniem tokena i startem klienta. Jeśli zamknąłeś terminal, ponownie przejdź przez sekcję folderu projektu, wybierając ten sam folder z zapisanym JSON; dla własnego projektu wróć do jego pełnej ścieżki przez `Set-Location -LiteralPath 'pełna ścieżka projektu'`. Dla aplikacji bez projektu, jeśli nie masz otwartego terminala, otwórz PowerShell z menu Start. Wklej poniższe komendy, a token wprowadź dopiero w ukrytym pytaniu. Zastąp `{{MCP_URL}}` dokładnym adresem z panelu (panel wstawia go automatycznie).

```powershell
$asmSecret = Read-Host 'Poświadczenie WordPressa' -AsSecureString
$env:ASM_MCP_TOKEN = [System.Net.NetworkCredential]::new('', $asmSecret).Password
Remove-Variable asmSecret
$env:ASM_MCP_URL = '{{MCP_URL}}'
Test-Path Env:ASM_MCP_TOKEN
```

Ostatnia komenda pokazuje tylko `True` lub `False`. Nie wyświetlaj wartości zmiennej. Uruchom klienta z tego samego PowerShell; wcześniej uruchomiony proces nie dziedziczy nowych zmiennych. Środowisko procesu nie jest szyfrowanym sejfem.

W konfiguracji klienta wybierz stdio, jako program podaj pełną ścieżkę `node.exe`, a jako jedyny argument pełną ścieżkę `bridge.mjs`. Proces musi otrzymać `ASM_MCP_URL` i `ASM_MCP_TOKEN` ze środowiska. Sposób zapisu JSON zależy od klienta; nie zgaduj jego opcji. Przykład i kompletna droga Desktop: [Claude Desktop](claude-desktop.md).

## Sprawdź odczyt i zakończ

Poproś klienta: „Wywołaj wp_site_status i podaj adres oraz zakres dostępu. Niczego nie zmieniaj.” W WordPressie otwórz Połączenia i sprawdź potwierdzony czas odczytu. Sama konfiguracja, lista narzędzi lub wypowiedź modelu nie potwierdzają odczytu tej strony.

Po pracy zamknij klienta i usuń zmienne z tego terminala:

```powershell
Remove-Item Env:ASM_MCP_TOKEN, Env:ASM_MCP_URL -ErrorAction SilentlyContinue
```

Aby odłączyć klienta, usuń tylko wpis `asm-wordpress` z jego konfiguracji i cofnij dostęp w WordPressie. Usunięcie lokalnej konfiguracji samo nie unieważnia tokena. Przy 401 sprawdź termin i cofnięcie na liście połączeń oraz `Test-Path Env:ASM_MCP_TOKEN` w terminalu, z którego uruchamiasz klienta. Przy 403 sprawdź zakres i uprawnienia właściciela.

## Poziom dowodu

Instrukcja opisuje konfigurację klienta. Test transportu lokalnego ani poprzedniego wydania nie potwierdza rzeczywistej sesji tej aplikacji z wydaniem 2.1.0. Odbiór konkretnego klienta wymaga odczytu wp_site_status i potwierdzenia w panelu. Nie deklarujemy gotowej ścieżki ChatGPT web ani Gemini web.

## macOS / Linux

Instalację wykonaj według oficjalnego odnośnika powyżej. Token w Bash wprowadź przez `read -r -s ASM_MCP_TOKEN`, następnie `export ASM_MCP_TOKEN`. URL ustaw przez `export ASM_MCP_URL='{{MCP_URL}}'`. Uruchom klienta z tego terminala; po zamknięciu użyj `unset ASM_MCP_TOKEN ASM_MCP_URL`. Konfiguracja ma tę samą strukturę, lecz lokalne ścieżki są inne. Warianty Desktop na tych systemach wymagają osobnego sprawdzenia środowiska aplikacji.
