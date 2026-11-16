# Changelog

## 2.6.0, 17 listopada 2026

- Popup::PRESETS: 10 stylów i 4 okazje jako zestawy zmiennych CSS; Popup::styles(), swatches(), templates().
- Biblioteka popupów w opcji asm_popup_library (limit 50): remember, recall, forget; akcja admin-post asm2_popup_library.
- Styl auto: przycisk strony wybierany po najczęstszym kolorze tła, bez banera cookies i białych przycisków; kolor nagłówka ze strony.

## 2.5.1, 16 listopada 2026

- Popup: usunięte pole wait_for_cookies i czekanie na baner Complianz. Konfiguracja w atrybutach data-* okna zamiast wp_localize_script, skrypt z data-no-optimize, data-no-defer i data-cfasync (LiteSpeed opóźniał go do pierwszej interakcji, przez co popup wyskakiwał dopiero po kliknięciu banera cookies).

## 2.5.0, 16 listopada 2026

- Panel jako strona najwyższego poziomu (admin.php?page=ai-site-manager, pozycja 3); stary adres options-general.php przekierowuje.
- Widżet kokpitu asm_dashboard (wp_dashboard_setup) z alertami i stanem połączeń.
- Start: karty aplikacji z poleceniami dla Claude Code, Codex (logowanie albo --bearer-token-env-var zależnie od trybu adresów) i skrótami do kreatora poświadczeń (&client=).
- Połączenia: działania w jednym wierszu, usunięcie potwierdzane oknem dialogowym.
- Popup: wpautop przy wyświetlaniu, pole wait_for_cookies (domyślnie true), akapity w stylu klasycznym mają odstęp.

## 2.4.2, 16 listopada 2026

- Filtr plugins_api: okno szczegółów wtyczki z sekcjami z readme.txt i banerem (assets/banner-772x250.jpg, banner-1544x500.jpg).
- readme.txt: nowy opis, instalacja od zera, prywatność w sekcji Other Notes.

## 2.4.1, 16 listopada 2026

- Operations::dependency() porównuje wersję zamiast listy 2.0.1, 2.1.0, 2.2.0. Od 2.2.1 do 2.4.0 narzędzia zapisu MCP zwracały błąd zależności, a wp_site_status pokazywał can_mutate false.

## 2.4.0, 16 listopada 2026

- Panel: nagłówek ze stanem, zakładki Popup oraz System i aktualizacje, operatorzy w Uprawnieniach, zdarzenia natywnego API w Historii, układ na całą szerokość okna.
- Popup: pole style (classic, auto, custom), button_url, background, text, radius, custom_css; arkusz assets/popup-theme.css; podgląd ?asm_popup_preview=1 dla administratora. Istniejące instalacje zostają przy stylu classic.
- Updater: filtr update_plugins_d-x.pl czyta najnowsze wydanie z GitHuba (stała ASM_UPDATE_REPO, tag vX.Y.Z, załącznik ai-site-manager-*.zip). Tylko publiczne repozytorium.
- Numer 2.3.0 pominięty: nosi go osobna paczka z pinem serwera licencji.

## 2.2.4, 16 listopada 2026

- Ekran po utworzeniu ręcznego poświadczenia: instrukcja klienta bezpośrednio pod danymi połączenia, konfiguracja pod nią.

## 2.2.3, 16 listopada 2026

- Zakładka Połączenia: główny przycisk prowadzi do logowania w aplikacji, kreator sekretu jest opcją zaawansowaną z ostrzeżeniem.
- Start: przycisk do konektorów Claude i gotowe polecenie dla Claude Code.

## 2.2.2, 16 listopada 2026

- Dokumenty OAuth pod /wp-json wyłączone z pamięci podręcznej LiteSpeed (po zmianie trybu serwer podawał zapisany dokument ze starym issuerem).

## 2.2.1, 16 listopada 2026

- Metadane OAuth są dostępne także pod /wp-json/asm-mcp/v1 (oauth/resource, .well-known/openid-configuration, .well-known/oauth-authorization-server).
- Gdy hosting nie oddaje /.well-known WordPressowi, wtyczka przełącza issuer i resource_metadata na adresy pod /wp-json (opcja asm_mcp_discovery, wybór przy wejściu do panelu po aktualizacji i przy „Sprawdź adresy logowania”). Na pozostałych stronach adresy zostają bez zmian.
- Przywrócone akcje term.create, post.category.move, post.tags.append i post.tags.remove.

## 2.2.0, 12 października 2026

- Zakres MCP full deleguje odczyty do asm/v2 i operacje do istniejącego silnika ASM.
- Własność operacji i stagingu przypisana do połączenia oraz konta; mapowanie bez planów i sekretów.
- Uprawnienia konkretnej akcji sprawdzane przy podglądzie, wykonaniu, statusie i cofnięciu.
- Opcjonalny guarded rollback zachowuje późniejsze zmiany treści, opcji i plików; pozostałe efekty mają jawną odmowę automatycznego cofnięcia.
- Pominięty scope OAuth pozwala wybrać zakresy rejestracji; jawny publicread oraz istniejące tokeny zachowują ograniczenia.
- Zachowano FIX3 MySQL, natywne API i dotychczasowe zakresy treści.

## 2.1.0 - 11 października 2026

- Schemat i zapytania limitów OAuth cytują kolumnę `window`, zachowując zgodność z MySQL 8 i istniejącą bazą MariaDB.
- Błąd inicjalizacji MCP pozostawia natywny panel i API dostępne, bez nowych endpointów i flagi gotowości; administrator otrzymuje krótki komunikat.
- Limit prób logowania działa przed odczytem dokumentu klienta, także przy nieudanym pobraniu i błędzie magazynu limitów.
- Formularz zgody pozwala przeglądarce wrócić wyłącznie do originu przekierowania zweryfikowanego dla bieżącego klienta.
- Usunięte połączenia są ukryte w Uprawnieniach; pozostają w Historii.
- Połączenia MCP są wewnątrz Menedżera Strony AI, w jednym panelu i pakiecie.
- Dodano logowanie OAuth z jawną zgodą, PKCE S256, odnawianiem i odwołaniem dostępu.
- Dodano usuwanie połączeń oraz edycję terminu Bezterminowo lub Do dnia.
- Zachowano stare połączenia, powiązanie instalacji, operacje i audyt podczas migracji.
- Dodano prostą ścieżkę Claude i pomoc dla czterech sposobów połączenia.
- Najniższy przetestowany zakres wydania: WordPress 7.1 / PHP 8.2. Starszy fallback zachowuje warunek niewczytywania MCP, lecz nie ma potwierdzonego wsparcia.
- Revoke odwołuje własne tokeny także po utracie uprawnień, binding lub terminu; błędy transakcji nie potwierdzają sukcesu.
- Edycja terminu sprawdza ważność po blokadach i atomowo w SQL. Zgoda OAuth ma jednorazowy, związany z sesją kontekst ważny 10 minut.
- Panel czeka na gotowość modułu podczas aktualizacji plików; Start pokazuje instrukcję Claude bez pozornego wyboru. Poprawiono polskie komunikaty i wyjaśnienie dostępu na zgodzie.
- OAuth public client akceptuje brak nagłówka Authorization także na serwerach przekazujących go do WordPressa jako null; niepusty nagłówek nadal jest odrzucany.
- Hash kontekstu zgody zachowuje dokładne bajty opaque state, bez normalizacji UTF-8 i bez zapisu jawnych parametrów.

## 2.0.1 - 3 września 2026

- Aktywacja nie nadaje już `asm_full_control` całej roli administratora. Uprawnienie nadaje się wyłącznie wybranemu użytkownikowi w panelu wtyczki.
- Nowe instalacje otrzymują wyłączony, neutralny popup bez treści właściwej dla Inpuris.
- Aktualizacja zachowuje istniejącą opcję `asm_popup` bez nadpisywania konfiguracji.
- Zaktualizowano metadane pakietu oraz adresy produktu i aktualizacji do `https://d-x.pl/wtyczki/menedzer-strony-ai/`.

## 2.0.0 - 2 września 2026

- Dodano API `asm/v2` oparte na natywnych hasłach aplikacji WordPressa.
- Dodano capability `asm_full_control` i uprawnienia zależne od zasobu.
- Dodano trwały silnik preview, execute, verify, reconcile i rollback.
- Dodano blokady, idempotencję, append-only audit i szyfrowanie payloadów AES-256-GCM.
- Dodano prywatne kopie treści plików, mediów, komponentów i zakresów bazy.
- Dodano treści, dokładną podmianę, Elementor, menu, opcje, użytkowników, komentarze i cron.
- Dodano media z kontrolą MIME oraz zdalny upload odporny na SSRF.
- Dodano pliki, kontrolowane SQL, pluginy, motywy, rdzeń i cache.
- Dodano uniwersalny inventory i wyszukiwanie.
- Przeniesiono popup do wersjonowanych assetów i dodano obsługę fokusu, Escape, reduced motion i Complianz.
- Zachowano ustawienia popupu z v1. Starsze API pozostaje wyłączone.
- Dodano weryfikację rollbacku niezależną od zmiennych ścieżek prywatnych kopii.
- Dodano odtwarzanie trwale usuniętych wpisów z komentarzami, mediów, pozycji menu, komentarzy i użytkowników.
- Ograniczono SQL do aktualnej bazy WordPressa i dodano weryfikację danych po rollbacku.
# Uzupełnienie odbioru 2.2.0 (FIX1)

- Cache metadanych CIMD zależy od katalogu zakresów; aktualizacja nie rozszerza istniejących zgód ani tokenów.
- Snapshot treści zachowuje format 2.1.0, daty pozostają w odczycie publicznym i wewnątrz `snapshot.post`.
- Full używa osobnego typowanego fingerprintu oraz wiernego zapisu ułamków; nieodtwarzalne snapshoty mają jawną odmowę automatycznego rollbacku.
- Tworzenie wpisu, medium, użytkownika lub menu przyjmuje wyłącznie typ celu; gate sprawdza strict UTF-8 oraz brak U+FFFD.
