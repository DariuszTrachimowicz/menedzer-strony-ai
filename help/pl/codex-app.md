# Aplikacja desktop z hostem Codex

Dotyczy aplikacji desktop korzystającej z hosta Codex, nie zwykłej karty ChatGPT web. Oficjalna dokumentacja potwierdza wspólny MCP desktop/CLI/IDE na tym samym hoście oraz `bearer_token_env_var`. Nie zakładamy braku MCP w typowej aplikacji.

## Windows: zainstaluj i sprawdź aplikację

1. Otwórz [oficjalny Quickstart](https://learn.chatgpt.com/docs/quickstart) i wybierz instalację desktop Windows wskazaną przez producenta. Uruchom instalator i zaloguj się kontem ChatGPT. Nie twórz klucza API dla WordPressa.
2. W aplikacji otwórz Settings ,  MCP servers. W menu aplikacji/Settings ,  About odczytaj wersję, jeśli dana kompilacja udostępnia tę pozycję; przy instalacji ze sklepu wersję odczytasz też w Windows Ustawienia ,  Aplikacje ,  Zainstalowane aplikacje ,  szczegóły programu.
3. Ustal, czy pracujesz na lokalnym czy zdalnym hoście Codex. Plik konfiguracyjny i zmienna muszą być na tym samym hoście. Poniższa droga dotyczy lokalnego Windows.

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

Po ponownym uruchomieniu w Settings ,  MCP servers sprawdź `asm-wordpress`. Jeśli korzystasz z formularza Add server i ma pole nazwy zmiennej tokena, wpisz `ASM_MCP_TOKEN`; jeśli pola nie ma, użyj opisanej tabeli TOML. Nie zastępuj manual Bearer logowaniem OAuth. Gdy mimo poprawnego pliku hosta serwer nie pojawia się lub dziedziczenie środowiska jest niedostępne w danym launcherze, użyj [Codex CLI](codex-cli.md). To alternatywa dla tego wariantu uruchomienia, nie twierdzenie, że aplikacja nie obsługuje MCP.

[Wspólna konfiguracja MCP](https://learn.chatgpt.com/docs/extend/mcp?surface=cli) [Nazwa zmiennej Bearer](https://learn.chatgpt.com/docs/config-file/config-reference)

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
