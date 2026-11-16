# Cztery sposoby połączenia

## 1. Zdalne logowanie OAuth

Aplikacja łączy się przez HTTPS, otwiera logowanie WordPressa i pyta o zgodę. Nie kopiujesz sekretu. [Claude](claude-cloud.md) korzysta z tej ścieżki. Inny klient musi obsługiwać odkrywanie serwera, PKCE S256 i rejestrację publicznego klienta DCR. Oficjalne dokumenty tożsamości obsługujemy tylko dla Claude i Claude Code.

Claude Code może dodać zdalny serwer poleceniem klienta, a następnie rozpocząć logowanie z `/mcp`:

```text
claude mcp add --transport http asm-wordpress {{MCP_URL}}
```

Sprawdź składnię w dokumentacji swojej wersji. Nie dodawaj tokenu do argumentów. [Oficjalna dokumentacja Claude Code](https://code.claude.com/docs/en/mcp)

## 2. Zdalne ręczne poświadczenie Bearer

W sekcji zaawansowanej wybierz Utwórz ręczne poświadczenie. Ustal nazwę, klienta, zakres oraz Bezterminowo lub Do dnia. Sekret zobaczysz tylko raz. Zapisz go w menedżerze haseł. Klient musi odczytywać go ze zmiennej środowiska lub własnego sejfu i wysyłać nagłówek Authorization. Nie umieszczaj sekretu w adresie, rozmowie ani argumentach procesu.

[Inny klient zdalny](other-remote.md) opisuje wymagania. [Poświadczenia](credentials.md) opisują odłączenie i utratę sekretu.

## 3. Most stdio

Klient uruchamia lokalny program Node, który przesyła narzędzia do tej strony przez HTTPS. Most jest w paczce ai-site-manager/bridge/bridge.mjs i wymaga Node 20 lub nowszego. Poświadczenie i adres otrzymuje ze środowiska procesu. Nie wymaga instalowania pakietów npm.

[Claude Desktop](claude-desktop.md) i [inny klient stdio](other-stdio.md) pokazują pełne kroki dla Windows. To wariant zaawansowany, niezależny od prostego połączenia Claude przez logowanie.

## 4. Klient CLI

Klient terminalowy może użyć OAuth albo ręcznego poświadczenia, zależnie od jego obsługi. [Codex CLI](codex-cli.md), [Claude Code](claude-code.md) i [Gemini CLI](gemini-cli.md) zachowują osobne instrukcje konfiguracji. Nie zmieniaj globalnych ustawień zatwierdzania zapisów.

W każdym wariancie sprawdź wp_site_status. Lista narzędzi lub poprawna składnia konfiguracji nie potwierdza odczytu tej strony.


W wydaniu 2.2.0 pełna administracja wymaga nowego połączenia i jawnej zgody na Pełne zarządzanie stroną. Wybierz Bezterminowo, jeśli dostęp ma trwać do cofnięcia. Istniejący publiczny odczyt zachowuje zakres. Szczegóły narzędzi i cofania zmian: [Zakresy i uprawnienia](permissions.md).
