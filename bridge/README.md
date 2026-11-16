# Mostek stdio

Wymagany Node 20+. Bez zależności npm. Rozpakuj paczkę na komputerze klienta.
Użyj pełnej ścieżki Node i pełnej ścieżki bridge.mjs jako jedynego argumentu.

Proces pobiera ASM_MCP_URL i ASM_MCP_TOKEN ze środowiska. Nie wpisuj wartości
sekretu do poleceń, argv i plików JSON. Przygotowanie Windows, macOS i Linux
opisano w help/pl/credentials.md i instrukcji wybranego klienta.

Adres jest stały dla procesu i wskazuje dokładnie kanoniczny endpoint MCP.
HTTPS jest wymagany poza localhost. Przekierowania są odrzucane. Mostek
nie ponawia mutacji. Stdout zawiera tylko JSON-RPC. Błędy nie zawierają tokenu.

Test mostu z poprzedniego wydania nie potwierdza konkretnej aplikacji z wersją 2.1.0. Odbiór wymaga rzeczywistego odczytu statusu strony z klienta.
