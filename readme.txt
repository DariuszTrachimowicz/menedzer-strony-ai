=== Menedżer Strony AI ===
Contributors: dariusztrachimowicz
Tags: ai, mcp, oauth, administration
Requires at least: 7.1
Requires PHP: 8.2
Tested up to: 7.1
Stable tag: 2.6.2
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Połącz WordPress z Claude i innymi asystentami AI. Decydujesz, co mogą czytać i zmieniać, a każdą zmianę możesz sprawdzić i cofnąć.

== Description ==

Menedżer Strony AI łączy Twoją stronę z asystentem AI, na przykład z Claude. Asystent czyta treści i przygotowuje szkice, a za Twoją zgodą publikuje wpisy i porządkuje stronę. Ustalasz, ile mu wolno, i w każdej chwili możesz ten dostęp odebrać.

Nie musisz już przeklejać tekstów między czatem a panelem WordPressa. Piszesz asystentowi, co ma zrobić, a każdą zmianę widzisz w podglądzie i w historii.

= Co zyskujesz =

* Łączysz stronę z Claude w kilka kliknięć: wklejasz adres, logujesz się do WordPressa i zatwierdzasz dostęp. Nie kopiujesz haseł ani kluczy.
* Sam wybierasz zakres: odczyt publicznych treści, praca na szkicach, publikowanie albo pełne zarządzanie stroną. Ustalasz też, do kiedy zgoda obowiązuje.
* Widzisz zmianę przed zapisem, bo asystent najpierw pokazuje, co zamierza zrobić. Po wykonaniu wtyczka sprawdza, czy wynik zgadza się z planem.
* Możesz się wycofać. Przed zmianą powstaje kopia w prywatnym magazynie poza katalogiem publicznym, a zmiany treści, ustawień i plików da się z niej cofnąć.
* W zakładce Historia sprawdzasz, które połączenie co zrobiło i z jakim wynikiem. Wpisy są przechowywane przez 30 dni.
* Każde połączenie odcinasz jednym przyciskiem. Możesz też cofnąć wszystkie naraz, na przykład po odtworzeniu strony z kopii.
* Nie płacisz za dodatkowy klucz API. Asystent pracuje na Twoim koncie w aplikacji AI, a wtyczka nie wysyła telemetrii do autora.
* Pokazujesz odwiedzającym popup, który bierze czcionki, kolory i wygląd przycisku z Twojej strony. Treść piszesz w edytorze, a wynik oglądasz w podglądzie przed włączeniem.
* Nowe wersje instalujesz ze zwykłego ekranu aktualizacji WordPressa.

= Do czego się przydaje =

* Pisanie i poprawianie wpisów oraz stron bez kopiowania tekstu z czatu.
* Przegląd treści: asystent wyszukuje wpisy, czyta je i wskazuje, co wymaga poprawy.
* Porządki w kategoriach, tagach i menu.
* Prace administracyjne przy pełnym dostępie: ustawienia, media, wtyczki i motywy.

= Jak to działa =

1. W Claude otwierasz Customize, Connectors i Add custom connector, a potem wklejasz adres ze strony Start wtyczki.
2. Logujesz się do WordPressa i na ekranie zgody wybierasz zakres oraz termin dostępu.
3. Piszesz asystentowi, co ma zrobić. Przy zmianach dostajesz podgląd przed wykonaniem.
4. W panelu wtyczki sprawdzasz historię i w razie potrzeby cofasz zmianę albo dostęp.

= Z czym działa =

* Claude w przeglądarce, Claude Desktop i Claude Code: logowanie w aplikacji, bez poświadczeń.
* Codex, Gemini CLI i inne programy obsługujące MCP: ręczne poświadczenie albo most stdio. Instrukcje są w zakładce Pomoc.

Wtyczka działa także na hostingu, który przejmuje katalog `/.well-known`. Sama wybiera wtedy adresy logowania, które docierają do WordPressa.

= Wymagania =

* WordPress 7.1 lub nowszy i PHP 8.2 lub nowszy.
* Strona pod adresem HTTPS.
* Prywatny katalog na kopie poza katalogiem publicznym strony (stała `ASM_PRIVATE_STORAGE`).

Autor: Dariusz Trachimowicz, https://d-x.pl/
Opis produktu: https://d-x.pl/wtyczki/menedzer-strony-ai/

== Installation ==

1. Zrób kopię bazy danych i plików strony.
2. W panelu WordPressa wybierz Wtyczki, Dodaj wtyczkę, Wyślij wtyczkę na serwer i wskaż plik `ai-site-manager-2.6.2.zip`. Przy aktualizacji wybierz zastąpienie obecnej wersji.
3. W pliku `wp-config.php` ustaw stałą `ASM_PRIVATE_STORAGE` na katalog poza katalogiem publicznym. Przykład jest w pliku README.md.
4. Otwórz Menedżer Strony AI w menu kokpitu, pod pozycją Kokpit. Nagłówek pokaże, czy HTTPS i magazyn kopii są gotowe.
5. Skopiuj adres ze strony Start i dodaj połączenie w Claude. Zaloguj się do strony i zatwierdź wybrany zakres.
6. Poproś asystenta o odczyt stanu strony. Połączenie pojawi się na liście w zakładce Połączenia.

Pełne zarządzanie wymaga dodatkowego kroku: w zakładce Uprawnienia nadaj pełny dostęp swojemu kontu administratora. Aktywacja wtyczki nie nadaje go sama.

Kolejne wersje instalujesz z ekranu aktualizacji WordPressa.

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

== Other Notes ==

= Prywatność =

Wtyczka przechowuje właściciela, opis połączenia, zakres, termin, hashe poświadczeń i metadane operacji. Jawne tokeny i hasła nie są zapisywane w audycie. Natywne payloady operacji są szyfrowane, a kopie znajdują się w prywatnym magazynie.
Jednorazowy kontekst zgody zapisuje wyłącznie hashe identyfikatora, sesji i parametrów żądania oraz właściciela i czas. Jest ważny 10 minut; wygasłe rekordy usuwa codzienny cleanup po dodatkowym 10-minutowym oknie.

Audyt i zakończone operacje MCP są przechowywane przez 30 dni. Operacje w toku i z nieustalonym wynikiem pozostają do wyjaśnienia. Usunięte albo zakończone połączenie można wyczyścić po 90 dniach tylko bez powiązanych operacji. Aktywne bezterminowe połączenie nie podlega temu czyszczeniu. Odinstalowanie nie jest poleceniem usunięcia danych.

Wyniki narzędzi trafiają do aplikacji klienta i mogą być przetwarzane przez dostawcę modelu. Pobieranie dokumentów tożsamości jest ograniczone do dwóch oficjalnych adresów HTTPS Claude. Wtyczka nie wymaga kluczy dostawcy modelu i nie wysyła telemetrii do autora.

== Changelog ==

= 2.6.2, 17 listopada 2026 =

* Sprawdzanie nowych wersji działa także na współdzielonym hostingu, gdzie limit zapytań do GitHuba wyczerpują inne strony.
* Stan popupu pod przełącznikiem ma kolor: zielony, gdy popup jest widoczny, szary, gdy jest wyłączony, żółty, gdy brakuje treści.

= 2.6.1, 17 listopada 2026 =

* Przełącznik „Pokazuj popup odwiedzającym” zapisuje się od razu po zaznaczeniu albo odznaczeniu.

= 2.6.0, 17 listopada 2026 =

* Popup: 10 gotowych stylów (m.in. Minimalistyczny, Ciemny, Gradient, Szkło, Elegancki) i 4 style okazjonalne.
* Popupy okazjonalne z gotową treścią: Nowy Rok, Boże Narodzenie, Wielkanoc, Święto Niepodległości.
* Biblioteka popupów: zapisz popup pod nazwą i wczytaj go później.
* Styl „Jak strona” trafniej wybiera kolor przycisku i nagłówka strony.

= 2.5.1, 16 listopada 2026 =

* Popup pokazuje się od razu po wejściu na stronę, bez czekania na baner cookies i na pierwszą interakcję. Przełącznik czekania na baner usunięty.

= 2.5.0, 16 listopada 2026 =

* Menedżer Strony AI ma własną pozycję w menu kokpitu, zaraz pod Kokpitem.
* Widżet w kokpicie pokazuje sprawy do załatwienia: nową wersję, brak magazynu kopii, wygasające połączenia.
* Start: osobna karta dla Claude, Claude Code, Codex, Gemini CLI i innych aplikacji, z gotowymi poleceniami.
* Lista połączeń zajmuje mniej miejsca, działania są w jednym wierszu.
* Popup zachowuje akapity i nowe wiersze z edytora. Nowa opcja: pokaż od razu, bez czekania na baner cookies.

= 2.4.2, 16 listopada 2026 =

* Okno „Zobacz szczegóły” na liście wtyczek pokazuje opis, instalację, pytania i rejestr zmian z ilustracją wtyczki.

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
