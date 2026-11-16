=== Menedżer Strony AI ===
Contributors: dariusztrachimowicz
Tags: ai, mcp, oauth, administration
Requires at least: 7.1
Requires PHP: 8.2
Tested up to: 7.1
Stable tag: 2.4.1
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Połączenia AI i kontrolowane zarządzanie WordPressem przez jawne uprawnienia, podgląd zmian i cofanie z kopii.

== Description ==

Autor: Dariusz Trachimowicz, https://d-x.pl/
Produkt i aktualizacje: https://d-x.pl/wtyczki/menedzer-strony-ai/

Jedna wtyczka udostępnia Połączenia AI oraz natywne API asm/v2. Prosta ścieżka Claude obejmuje skopiowanie adresu, logowanie WordPressa i jawną zgodę. Wydanie wymaga WordPress 7.1 i PHP 8.2 lub nowszych oraz HTTPS. To najniższe przetestowane wersje; zgodność WordPress 5.6 i PHP 7.4 nie została potwierdzona. Zachowany warunek ładowania chroni starsze PHP przed uruchomieniem modułu MCP, ale nie oznacza wsparcia tej konfiguracji.

Zakresy MCP: publiczny odczyt, szkice, publikowanie i pełne zarządzanie stroną. Pełny zakres udostępnia istniejący kontrakt asm/v2, z uprawnieniami akcji i własnością operacji oraz stagingu. Dostęp działa jako właściciel połączenia i sprawdza jego bieżące uprawnienia. Pełny dostęp natywnego API administrator nadaje świadomie wybranemu kontu. Aktywacja nie nadaje go automatycznie.

== Installation ==

1. Wykonaj kopię bazy, plików i prywatnego magazynu ASM.
2. Wgraj ai-site-manager-2.4.1.zip jako aktualizację istniejącego Menedżera Strony AI.
3. Ustaw prywatny magazyn poza katalogiem publicznym według README.md.
4. Otwórz Ustawienia, Menedżer Strony AI.
5. Dla Claude skopiuj adres i dodaj własne połączenie w aplikacji. Zaloguj się do tej strony i zatwierdź wybrany zakres.
6. Sprawdź rzeczywisty odczyt wp_site_status.

Migracja zachowuje stare dane MCP i identyfikator instalacji. Starą osobną wtyczkę ASM MCP Connect wyłącz dopiero po odbiorze nowego modułu. Nie kasuj jej plików ani tabel. Routing /.well-known musi przekazywać metadane OAuth do WordPressa; instrukcja Nginx i Apache jest w help/pl/update.md.

== Frequently Asked Questions ==

= Czy potrzebny jest klucz API modelu? =
Nie. Klient korzysta ze swojego konta modelu. Podstawowa ścieżka Claude nie wymaga kopiowania poświadczenia WordPressa.

= Co oznacza Bezterminowo? =
Zgoda nie ma daty końca. Cofnięcie, usunięcie, utrata uprawnień konta lub zmiana powiązania instalacji nadal odcina dostęp. Token dostępu OAuth ma ważność jednej godziny i jest odnawiany tylko w ramach ważnej zgody.

= Czy usunięcie połączenia cofa zmiany? =
Nie. Odcina kolejne żądania i ukrywa połączenie z listy. Cofanie sprawdzonych operacji jest osobną czynnością. Historia podlega retencji.

= Czy działają inne aplikacje? =
Pomoc opisuje zdalne OAuth, ręczny Bearer, most stdio i CLI. Odbiór konkretnego klienta wymaga rzeczywistego odczytu z tej instalacji. Nie deklarujemy obsługi każdego konta ChatGPT web lub Gemini web.

= Gdzie uzyskać wsparcie? =
Dokumentacja produktu: https://d-x.pl/wtyczki/menedzer-strony-ai/
Pomoc lokalna: help/pl i ekran Pomoc. Nie przekazuj haseł, tokenów ani kodów logowania przy zgłaszaniu problemu.

== Privacy ==

Wtyczka przechowuje właściciela, opis połączenia, zakres, termin, hashe poświadczeń i metadane operacji. Jawne tokeny i hasła nie są zapisywane w audycie. Natywne payloady operacji są szyfrowane, a kopie znajdują się w prywatnym magazynie.
Jednorazowy kontekst zgody zapisuje wyłącznie hashe identyfikatora, sesji i parametrów żądania oraz właściciela i czas. Jest ważny 10 minut; wygasłe rekordy usuwa codzienny cleanup po dodatkowym 10-minutowym oknie.

Audyt i zakończone operacje MCP są przechowywane przez 30 dni. Operacje w toku i z nieustalonym wynikiem pozostają do wyjaśnienia. Usunięte albo zakończone połączenie można wyczyścić po 90 dniach tylko bez powiązanych operacji. Aktywne bezterminowe połączenie nie podlega temu czyszczeniu. Odinstalowanie nie jest poleceniem usunięcia danych.

Wyniki narzędzi trafiają do aplikacji klienta i mogą być przetwarzane przez dostawcę modelu. Pobieranie dokumentów tożsamości jest ograniczone do dwóch oficjalnych adresów HTTPS Claude. Wtyczka nie wymaga kluczy dostawcy modelu i nie wysyła telemetrii do autora.

== Changelog ==

= 2.4.1, 16 listopada 2026 =

* Naprawa: połączenia z pełnym dostępem znów mogą zapisywać. Wersje 2.2.1 do 2.4.0 blokowały zapisy przez MCP.

= 2.4.0, 16 listopada 2026 =

* Nowy układ panelu: jedna strona z zakładkami Start, Połączenia, Uprawnienia, Popup, Historia, System i aktualizacje, Pomoc.
* Popup: styl „Jak strona” pobiera czcionki, kolory i wygląd przycisku ze strony; styl Własny, edytor treści, adres przycisku, własny CSS i podgląd.
* Aktualizacje z wydań GitHuba przez zwykły ekran aktualizacji WordPressa.

= 2.2.4, 16 listopada 2026 =

* Po utworzeniu ręcznego poświadczenia instrukcja klienta jest przed blokiem konfiguracji.

= 2.2.3, 16 listopada 2026 =

* Panel prowadzi najpierw do logowania w aplikacji. Ręczne poświadczenie jest opcją zaawansowaną.

= 2.2.2, 16 listopada 2026 =

* Adresy logowania pod /wp-json nie trafiają do pamięci podręcznej LiteSpeed.

= 2.2.1, 16 listopada 2026 =

* Logowanie w aplikacji działa także na hostingu, który przejmuje katalog /.well-known: adresy logowania są dodatkowo pod /wp-json/asm-mcp/v1, a wtyczka sama wybiera właściwe.

= 2.2.0, 12 października 2026 =
Pełne zarządzanie przez MCP w istniejącym kontrakcie asm/v2, dynamiczne uprawnienia, własność operacji i stagingu, metadane bez sekretów i kontrola późniejszych zmian przed rollbackiem. Pominięty scope OAuth pokazuje zakresy rejestracji. Stare tokeny zachowują zakres.


= 2.1.0, 11 października 2026 =
* Wewnętrzny moduł MCP w jednej wtyczce i jednym panelu.
* OAuth z jawną zgodą, PKCE S256, odnawianiem i odwołaniem.
* Usuń połączenie oraz zmiana terminu Bezterminowo lub Do dnia.
* Migracja zachowująca połączenia, powiązanie, operacje i audyt.
* Prosta ścieżka Claude oraz cztery sposoby połączenia w pomocy.
* Jednorazowa zgoda związana z sesją; trwałe revoke mimo utraty uprawnień oraz atomowa edycja terminu.
* Gotowość panelu przy aktualizacji plików, poprawne polskie komunikaty i wskazanie ustawień operatorów.
* Deklarowane minimum zgodne z testami: WordPress 7.1 / PHP 8.2.

= 2.0.1, 3 września 2026 =
* Pełny dostęp nadawany wybranemu administratorowi.
* Neutralny popup i zachowanie istniejących ustawień przy aktualizacji.

== Upgrade Notice ==

= 2.2.0 =
Istniejące tokeny zachowują zakres. Pełne zarządzanie wymaga nowego połączenia i jawnej zgody w aplikacji oraz uprawnienia ASM konta. Wykonaj kopię przed aktualizacją.

= 2.1.0 =
Wykonaj kopię. Moduł Połączenia AI wymaga PHP 8.2 i dostępnych metadanych OAuth przez HTTPS. Zachowaj stare tabele MCP oraz pliki osobnego konnektora do odbioru migracji.
