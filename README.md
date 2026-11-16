# Menedżer Strony AI 2.6.0

Wtyczka udostępnia kontrolowane API do odczytu i zarządzania instalacją WordPressa. Każda zmiana przechodzi przez cztery etapy: preview, execute, verify i opcjonalny rollback.

## Najważniejsze możliwości

- treści wszystkich typów wpisów, metadane, terminy i dokładna podmiana jednego fragmentu,
- dane Elementora zmieniane przez jednoznaczną ścieżkę JSON Pointer,
- media, upload z pliku albo publicznego HTTPS oraz podmiana z zachowaniem ID i URL,
- menu, opcje, użytkownicy, hasła użytkowników, komentarze i cron,
- pliki WordPressa, kontrolowane SQL, cache i diagnostyka,
- instalacja, aktualizacja, aktywacja, wyłączenie i usuwanie wtyczek oraz motywów,
- aktualizacja rdzenia WordPressa,
- pełny spis strony, wyszukiwanie w treści, metadanych, opcjach, plikach i wskazanym zapytaniu bazy,
- append-only audit, blokady zasobów, idempotencja, szyfrowanie danych operacji i prywatne kopie.

Wtyczka nie odczytuje jawnych haseł WordPressa. Uwierzytelnianie wykorzystuje natywne hasła aplikacji. Nowe hasło użytkownika lub sekret może zostać ustawiony, ale odpowiedź i log go nie zwracają, a trwały payload operacji jest szyfrowany AES-256-GCM kluczem pochodnym od saltów WordPressa.

## Wymagania

- WordPress 7.1 lub nowszy,
- PHP 8.2 lub nowszy z OpenSSL, Fileinfo i JSON,
- HTTPS poza lokalnym środowiskiem testowym,
- prywatny katalog zapisu poza katalogiem publicznym strony.

W `wp-config.php` ustaw bezpieczną ścieżkę, na przykład:

```php
define('ASM_PRIVATE_STORAGE', '/home/konto/.private/dx-asm');
```

Katalog nie może znajdować się wewnątrz `public_html`, `htdocs` ani innego document root. Jeżeli stała nie jest ustawiona, wtyczka próbuje użyć prywatnego katalogu obok document root. Brak bezpiecznej ścieżki blokuje operacje wymagające kopii, ale nie blokuje diagnostyki.

## Połączenia AI

W Ustawieniach otwórz Menedżer Strony AI. Główna ścieżka Claude wymaga skopiowania adresu i dodania go w Customize, Connectors, Add custom connector. Continue i Sign in now otwierają logowanie WordPressa oraz jawną zgodę. Wybierz zakres i termin. Nie kopiujesz tokenu ani klucza modelu. Połączono pojawia się po rzeczywistym użyciu.

MCP jest wewnętrznym modułem tej samej wtyczki, wymaga PHP 8.2 i HTTPS. Najniższy przetestowany zakres wydania to WordPress 7.1 / PHP 8.2. Zgodność natywnego fallbacku z WordPress 5.6 / PHP 7.4 nie została potwierdzona; zachowany warunek ładowania nie uruchamia modułu MCP na starszym PHP. Zakresy MCP obejmują publiczny odczyt, szkice, publikowanie i pełne zarządzanie stroną przez istniejący kontrakt asm/v2. Pełny zakres wymaga manage_options i asm_full_control oraz bieżących uprawnień konkretnej akcji. Uprawnienia sprawdzane są przy każdym żądaniu. Aktywacja nie nadaje pełnego dostępu automatycznie.

Połączenie można cofnąć, usunąć lub zmienić termin na Bezterminowo albo Do dnia. Usunięcie odcina kolejne żądania i ukrywa wpis, zachowując historię zgodnie z retencją. Audyt i zakończone operacje MCP są przechowywane przez 30 dni. Operacje w toku lub z nieustalonym wynikiem pozostają do wyjaśnienia. Zmiana terminu nie przywraca zakończonego dostępu.

[Pomoc Claude](help/pl/claude-cloud.md), [cztery sposoby połączenia](help/pl/advanced.md), [aktualizacja i routing OAuth](help/pl/update.md). Manual Bearer, most stdio i konfiguracje CLI pozostają w pomocy zaawansowanej. Most Node jest w bridge/bridge.mjs.

Discovery wymaga przekazania dokładnych ścieżek /.well-known do WordPressa. Nginx ze statycznym blokiem /.well-known wymaga dokładnych reguł opisanych w pomocy aktualizacji. Nie zmieniaj routingu ACME i ARD. Oficjalne dokumenty tożsamości są pobierane wyłącznie z dwóch przypiętych adresów Claude; pozostałe klienty mogą zarejestrować się przez DCR bez pobierania dowolnych adresów.

## Uwierzytelnianie natywnego API

1. Aktywuj wtyczkę.
2. Otwórz Menedżer Strony AI w menu kokpitu.
3. Nadaj `asm_full_control` wybranemu administratorowi.
4. W profilu tego użytkownika utwórz osobne hasło aplikacji.
5. Używaj Basic Auth przez HTTPS: login WordPressa oraz hasło aplikacji.

Nie przekazuj głównego hasła do konta. Hasło aplikacji można niezależnie unieważnić.

## Domyślny popup

Nowa instalacja ma neutralny popup wyłączony (`enabled=false`) i nie zawiera treści Inpuris. Włączenie popupu oraz jego treść wymagają osobnej konfiguracji. Aktualizacja istniejącej instalacji zachowuje opcję `asm_popup` bez zmian.

## Schemat operacji

Preview dokładnej zmiany słowa:

```http
POST /wp-json/asm/v2/operations/preview
Content-Type: application/json
Authorization: Basic ...

{
  "action": "content.replace",
  "target": {"type": "post", "id": 123},
  "expected": {"hash": ""},
  "change": {
    "field": "post_content",
    "old": "stary wyraz",
    "new": "nowy wyraz",
    "expected_occurrences": 1,
    "content_hash": "sha256 aktualnej treści"
  }
}
```

Wykonanie:

```http
POST /wp-json/asm/v2/operations/{operation_id}/execute
Idempotency-Key: własny-unikalny-klucz
Authorization: Basic ...
```

Cofnięcie:

```http
POST /wp-json/asm/v2/operations/{operation_id}/rollback
Authorization: Basic ...
```

## Trasy v2

| Metoda | Trasa | Znaczenie |
| --- | --- | --- |
| GET | `/asm/v2/ping` | Stan i wersja API |
| GET | `/asm/v2/capabilities` | Efektywne uprawnienia |
| GET | `/asm/v2/inventory` | Stan WordPressa, PHP, komponentów, bazy, cron i cache |
| POST | `/asm/v2/search` | Wyszukiwanie w wybranych obszarach |
| POST | `/asm/v2/operations/preview` | Migawka i plan zmiany |
| POST | `/asm/v2/operations/{id}/execute` | Jednorazowe wykonanie |
| GET | `/asm/v2/operations/{id}` | Stan i uzgodnienie wyniku |
| POST | `/asm/v2/operations/{id}/rollback` | Cofnięcie z kopii |
| GET | `/asm/v2/resources/{type}` | Lista zasobów |
| GET | `/asm/v2/resources/{type}/{id}` | Jeden zasób |
| POST | `/asm/v2/uploads` | Upload roboczy z krótkim tokenem |
| GET | `/asm/v2/logs` | Zredagowany audyt |

Typy zasobów obejmują `posts`, `media`, `menus`, `options`, `users`, `comments`, `cron`, `plugins`, `themes`, `core`, `cache`, `files`, `database` i `popup`.

## Ważne zabezpieczenia

- Nie ma endpointu wykonującego PHP przesłane w treści żądania.
- Edycja PHP przechodzi parser składni i atomowy zapis.
- Ścieżki nie mogą wyjść poza katalog WordPressa ani przez symlink.
- Zdalne pliki muszą używać HTTPS i publicznych adresów IP.
- SQL blokuje wiele poleceń, obce bazy, `LOAD_FILE`, `OUTFILE`, `INFILE`, funkcje opóźniające i blokady serwera.
- Zmiana SQL wymaga kopii zakresu oraz poleceń rollbacku.
- Podmiana media zachowująca URL wymaga tego samego formatu pliku.
- Trwałe usunięcie użytkownika wymaga wskazania innego konta w `reassign`; dzięki temu treści nie są kasowane i mogą wrócić do pierwotnego autora przy rollbacku.
- Rollback trwałego usunięcia odtwarza oryginalne ID wpisu, powiązane komentarze, media, pozycję menu albo użytkownika oraz ich przechwycone metadane.
- Starsze API `asm/v1` jest domyślnie wyłączone. Hash dawnego klucza pozostaje tylko do kontrolowanego usunięcia w panelu.

## Ograniczenia wymagające infrastruktury

Rollback rdzenia WordPressa po nieudanej aktualizacji wymaga paczki odzyskiwania lub kopii hostingu. Wtyczka celowo nie udaje automatycznego sukcesu w tej sytuacji i zwraca `asm_core_rollback_requires_recovery_package`. Aktualizację rdzenia należy wykonywać dopiero po potwierdzeniu pełnego backupu serwera.

Pełne testy produkcyjnego Elementora, konkretnej wtyczki cache i aktualizacji komponentów należy wykonać na klonie staging, ponieważ zależą od zainstalowanych wersji i konfiguracji hostingu.

Zobacz też [DEPLOYMENT.md](DEPLOYMENT.md) i [ROLLBACK.md](ROLLBACK.md).
