# Claude Desktop

## Windows: instalacja i weryfikacja

1. Otwórz [oficjalną instalację Claude Desktop](https://support.claude.com/en/articles/10065433-install-claude-desktop), przejdź do pobierania Windows, uruchom instalator i zaloguj się kontem Claude. Nie potrzebujesz klucza API WordPressa ani modelu.
2. Otwórz Settings ,  Developer ,  Edit Config. Sprawdź wersję w menu Help/About lub w Windows Ustawienia ,  Aplikacje ,  Zainstalowane aplikacje ,  szczegóły. Jeśli ta kompilacja nie udostępnia Developer/Edit Config, użyj [Claude Code](claude-code.md).

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

## Windows: otwórz lub utwórz JSON Desktop

Settings ,  Developer ,  Edit Config powinno otworzyć plik lub folder. Jeśli folder, otwórz `claude_desktop_config.json` w Notatniku. Alternatywnie Win+R ,  `%APPDATA%\Claude` ,  Enter. Poniższe tworzy wyłącznie brakujący folder/plik:

```powershell
$asmClaudeDir = Join-Path $env:APPDATA 'Claude'
if (!(Test-Path -LiteralPath $asmClaudeDir)) { New-Item -ItemType Directory -Path $asmClaudeDir | Out-Null }
$asmJsonFile = Join-Path $asmClaudeDir 'claude_desktop_config.json'
if (!(Test-Path -LiteralPath $asmJsonFile)) { New-Item -ItemType File -Path $asmJsonFile | Out-Null }
notepad.exe $asmJsonFile
```

## Zachowaj istniejący JSON

Pusty plik: wklej cały pokazany dokument. Istniejący plik: zrób kopię zapasową, zachowaj inne ustawienia i wpisy; dodaj tylko `asm-wordpress` wewnątrz istniejącego `mcpServers`. Nie dodawaj drugiego `mcpServers`. Między wpisami musi być przecinek; po ostatnim wpisie przecinka nie ma. Przykład łączenia z istniejącym wpisem:

```json
{
  "mcpServers": {
    "dotychczasowy-serwer": { "command": "dotychczasowy-program" },
    "asm-wordpress": { "TU_WSTAW": "pola asm-wordpress z przykładu poniżej" }
  }
}
```

Powyższy przykład wyjaśnia łączenie, nie jest gotową konfiguracją. Zachowaj własny dotychczasowy wpis i zastąp cały obiekt `TU_WSTAW` obiektem `asm-wordpress` poniżej. Zapisz plik bez `.txt`. Sprawdź składnię JSON w PowerShell (nie wypisuje treści):

```powershell
Get-Content -LiteralPath $asmJsonFile -Raw | ConvertFrom-Json | Out-Null
```

Brak błędu oznacza poprawną składnię, nie działające połączenie. Nie używaj walidatora internetowego do konfiguracji zawierającej prywatne dane.

```json
{
  "mcpServers": {
    "asm-wordpress": {
      "command": "C:\\Program Files\\nodejs\\node.exe",
      "args": ["C:\\Users\\Anna\\Documents\\ai-site-manager\\bridge\\bridge.mjs"],
      "env": { "ASM_MCP_URL": "{{MCP_URL}}" }
    }
  }
}
```

Ścieżki są przykładami: zastąp je wynikami sprawdzonych komend. Nie dodawaj tokena do `env` w JSON. Mostek pobiera `ASM_MCP_TOKEN` odziedziczony z procesu aplikacji; nie zakładamy rozwijania `${...}` przez Desktop.

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

## Windows: uruchom aplikację z tego samego procesu środowiska

1. Uruchom aplikację normalnie. Otwórz Menedżer zadań (Ctrl+Shift+Esc), znajdź jej proces, kliknij prawym przyciskiem ,  Otwórz lokalizację pliku. W Eksploratorze wybierz właściwy plik `.exe` ,  Kopiuj jako ścieżkę (w razie potrzeby Shift+prawy przycisk).
2. Całkowicie zakończ aplikację, także przez Zakończ/Quit w zasobniku. Sam przycisk X może pozostawić proces.
3. W PowerShell, w którym wprowadzono token ukrytym pytaniem, uruchom poniższe. Wklej znalezioną ścieżkę po pytaniu; nie wpisuj tokena w ścieżkę ani argumenty.

```powershell
$asmAppPath = (Read-Host 'Wklej pełną ścieżkę aplikacji .exe').Trim('"')
Test-Path -LiteralPath $asmAppPath
Start-Process -FilePath $asmAppPath
```

`Test-Path` musi pokazać `True`. Jest to interaktywna aplikacja, więc jej okno ma się pojawić. Uruchomienie z menu Start nie zapewnia tego samego środowiska. Jeśli instalacja pakietowa/sklepowa nie pozwala znaleźć lub uruchomić `.exe`, nie zmieniaj uprawnień folderów systemowych; użyj instrukcji odpowiedniego CLI. Jeśli aplikacja widzi serwer, ale dostaje 401, sprawdź zmienną w terminalu i zamknięcie wcześniejszego procesu. `True` w terminalu samo nie dowodzi dziedziczenia przez aplikację. Bez udanego odczytu nie ogłaszaj połączenia Desktop.

Po starcie w Settings ,  Developer sprawdź serwer i narzędzia MCP; rozpocznij nową rozmowę. Jeśli proces nie przekazuje tokena do mostka, ten wariant nie został skonfigurowany. Użyj [Claude Code](claude-code.md); nie przenoś tokena do `args` ani JSON.

[Oficjalna konfiguracja lokalnych serwerów](https://modelcontextprotocol.io/docs/develop/connect-local-servers)

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
