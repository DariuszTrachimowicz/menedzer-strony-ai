# Gemini CLI

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

## Windows: instalacja, wersja i konto

Według [oficjalnej instalacji Gemini CLI](https://geminicli.com/docs/get-started/installation/), w PowerShell:

```powershell
npm.cmd install -g @google/gemini-cli
gemini.cmd --version
gemini.cmd
```

Wersja ma pojawić się bez błędu. Wybierz `Sign in with Google`. Nie twórz klucza API dla WordPressa. Zakończ pierwszą sesję przed konfiguracją. Przy nierozpoznanym poleceniu otwórz nowy PowerShell po instalacji.

## Windows: folder projektu i settings.json

```powershell
$asmProject = Join-Path ([Environment]::GetFolderPath('MyDocuments')) 'WordPress-MCP'
if (!(Test-Path -LiteralPath $asmProject)) { New-Item -ItemType Directory -Path $asmProject | Out-Null }
Set-Location -LiteralPath $asmProject
$asmGeminiDir = Join-Path $asmProject '.gemini'
if (!(Test-Path -LiteralPath $asmGeminiDir)) { New-Item -ItemType Directory -Path $asmGeminiDir | Out-Null }
$asmJsonFile = Join-Path $asmGeminiDir 'settings.json'
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
      "httpUrl": "{{MCP_URL}}",
      "headers": { "Authorization": "Bearer ${ASM_MCP_TOKEN}" },
      "trust": false
    }
  }
}
```

`httpUrl` oznacza Streamable HTTP; `url` oznacza inny transport SSE. Zostaw odwołanie do zmiennej, bez wartości tokena.

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

W tym samym PowerShell i folderze projektu uruchom `gemini.cmd`, zaufaj tylko własnemu projektowi, wpisz `/mcp`. Nie ustawiaj globalnego automatycznego zatwierdzania zapisów.

[Oficjalne serwery MCP](https://geminicli.com/docs/tools/mcp-server/) [Konfiguracja i zmienne](https://geminicli.com/docs/reference/configuration/)

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
