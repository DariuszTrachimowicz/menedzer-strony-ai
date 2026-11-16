# Operacje i cofanie

Najpierw przygotuj podgląd, który nie zmienia treści. Po zatwierdzeniu asystent wykonuje zmianę i odczytuje wynik. Cofnięcie jest możliwe tylko dla własnej sprawdzonej operacji, jeśli później nikt nie zmienił wpisu. Przy błędzie lub utracie odpowiedzi sprawdź bieżącą treść i stan; nie ponawiaj zapisu na ślepo. Cofnięcie dostępu do połączenia nie cofa wykonanych zmian.

## Szczegóły techniczne


Preview przygotowuje plan ważny 24 godziny. Nie zmienia treści. Execute wymaga confirm true i idempotency_key. Plan po podglądzie jest niezmienny. Klucz jest oddzielony dla połączenia i operacji.

Verified oznacza odczyt sprawdzonego skutku w WordPressie. Powtórzenie execute nie wykonuje zmiany ponownie, także z innym kluczem. Conflict oznacza późniejszą zmianę zasobu i brak nadpisania jej tym planem. Failed i unknown nie oznaczają sukcesu.

Po utracie odpowiedzi odczytaj wp_operation_status. Trwałe executing lub rolling_back po awarii są niejasne i nie można ich automatycznie ponowić. Administrator musi sprawdzić treść i stan silnika ASM; wtyczka nie ma przycisku do obchodzenia tej blokady.

Rollback wymaga własnej verified operacji i confirm true. Pełny fingerprint obejmuje wiersz wpisu, wszystkie meta oraz relacje terminów. Późniejsza edycja powoduje odmowę. Przywrócenie istniejącego wpisu korzysta z transakcji i blokad wierszy InnoDB. Niewspierane tabele lub błąd DB powodują odmowę zamiast obietnicy bezpieczeństwa.

Cofnięcie create usuwa tylko własny nowy draft/pending, bez późniejszych wpisów potomnych, załączników lub komentarzy i po zgodności fingerprintu. Nie usuwa nowej publikacji ani dowolnego identyfikatora. Globalnej izolacji hooków, zewnętrznych usług i niestandardowych zapisów DB nie obiecujemy. Cofnięcie nie zastępuje kopii zapasowej.

Cofnięcie tokenu blokuje nowy dispatch. Nie cofa zmiany już rozpoczętej. Granicą jest świeża kontrola DB bezpośrednio przed dispatch. Aktywność zawiera nazwy narzędzi, wynik, czas i identyfikator własnej operacji, bez treści lub sekretów.
