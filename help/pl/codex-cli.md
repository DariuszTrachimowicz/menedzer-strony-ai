# Codex CLI

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

## Windows: zainstaluj i sprawdź Codex CLI

W [oficjalnej instrukcji Codex CLI](https://learn.chatgpt.com/docs/codex/cli) sprawdź wymagania. W PowerShell:

```powershell
npm.cmd install -g @openai/codex
codex.cmd --version
codex.cmd
```

Wersja powinna zostać wypisana bez błędu. Przy pierwszym uruchomieniu zaloguj się kontem ChatGPT zgodnie z ekranem klienta; nie potrzebujesz klucza API do połączenia WordPressa. Zakończ pierwszą sesję przed konfiguracją MCP. Jeśli komenda nie jest rozpoznana, otwórz nowy PowerShell po instalacji.

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

## Dodaj serwer z tego PowerShell

```powershell
codex.cmd mcp add asm-wordpress --url $env:ASM_MCP_URL --bearer-token-env-var ASM_MCP_TOKEN
codex.cmd
```

Jeśli serwer o tej nazwie już istnieje, popraw jego tabelę według instrukcji poniżej. W nowej sesji wpisz `/mcp` i sprawdź obecność `asm-wordpress`. Usunięcie lokalnego wpisu: `codex.cmd mcp remove asm-wordpress`.

## Windows: znajdź konfigurację na właściwym hoście Codex

Desktop, CLI i rozszerzenie IDE współdzielą konfigurację MCP na tym samym hoście Codex. Jeśli aplikacja jest połączona ze zdalnym hostem, edytuj konfigurację na nim, nie przypadkowy plik na Windows. W aplikacji otwórz Settings ,  MCP servers; tam sprawdź wybrany host i obecność serwera po restarcie.

Na lokalnym Windows uruchom poniższe w PowerShell. Odczytują wyłącznie ścieżkę `CODEX_HOME`. Folder i plik są tworzone tylko, jeśli ich brakuje; zawartość istniejącego pliku pozostaje.

```powershell
$asmConfigDir = if ($env:CODEX_HOME) { $env:CODEX_HOME } else { Join-Path $env:USERPROFILE '.codex' }
Write-Output $asmConfigDir
if (!(Test-Path -LiteralPath $asmConfigDir)) { New-Item -ItemType Directory -Path $asmConfigDir | Out-Null }
$asmConfigFile = Join-Path $asmConfigDir 'config.toml'
if (!(Test-Path -LiteralPath $asmConfigFile)) { New-Item -ItemType File -Path $asmConfigFile | Out-Null }
notepad.exe $asmConfigFile
```

Alternatywnie Win+R ,  `%USERPROFILE%\.codex` ,  Enter, jeśli nie ustawiono `CODEX_HOME`. W Notatniku dodaj tylko poniższą tabelę na końcu, zachowując resztę. Jeśli `[mcp_servers.asm-wordpress]` już istnieje, popraw ją zamiast tworzyć drugą. Zapisz `config.toml`, bez `.txt`.

```toml
[mcp_servers.asm-wordpress]
url = "{{MCP_URL}}"
bearer_token_env_var = "ASM_MCP_TOKEN"
```

Nazwa zmiennej Bearer nie zawiera `Bearer` ani `${...}`. Konfiguracja nie zawiera tokena.

[Oficjalna konfiguracja MCP](https://learn.chatgpt.com/docs/extend/mcp?surface=cli)

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
