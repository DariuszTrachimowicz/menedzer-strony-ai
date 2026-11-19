# Changelog

## 2.8.0, 19 listopada 2026

Poprawki z audytu kodu (ponytail-audit, 19.11.2026):
- CapabilityMap: content.read wymaga manage_options + asm_full_control (było read: subskrybent/klient Woo mógł pobrać przez /asm/v2/resources/posts szkice, prywatne i chronione hasłem).
- wp_slash() przy add_*_meta/update_post_meta w rollback i content.meta, media alt (Content, Media, Menu, Comment, User): add_metadata robi wp_unslash i niszczył _elementor_data.
- FileResource::verify(delete): resolve(..., true), więc brak pliku daje asm_path_not_found (wcześniej każdy file.delete kończył się asm_verification_failed).
- uninstall.php: usuwa asm_mcp_install_id (nowe wiązanie unieważnia tokeny), odwołuje połączenia, kasuje poświadczenia OAuth, zdejmuje asm_full_control z każdego konta.
- OAuthGrant: ponowienie refresh w ciągu 30 s bez zrywania połączenia; drugie użycie kodu autoryzacji zrywa połączenie (RFC 6749 4.1.2).
- OAuthClients: nieużyte rejestracje DCR usuwane po dobie; przy pełnym limicie usuwana najstarsza nieużyta (starsza niż godzina).
- OperationEngine: po założeniu blokady ponowny odczyt operacji (drugie wykonanie nie nadpisuje wyniku); przy nieudanej blokadzie zapis tylko na nietkniętym podglądzie; rollback niechronionych operacji pod blokadą i zakaz cofania operacji zatrzymanych przed zmianą (asm_resource_locked, asm_idempotency_conflict); blokada 900 s dla plugin./theme./core.
- OptionResource: rollback przywraca surową kolumnę autoload (WP 6.6+ on/off/auto); option.update odrzuca wartość z [REDACTED] (asm_option_redacted_value).
- QueryPolicy: analiza zapytania w jednym przejściu od lewej (teksty zasłonięte, `identyfikatory` odsłonięte, tryby NO_BACKSLASH_ESCAPES i ANSI_QUOTES), komentarz poza tekstem i identyfikator z nietypowymi znakami odrzucane; inna baza wykrywana po FROM, JOIN (z grupami w nawiasach), UPDATE z modyfikatorami i w listach tabel. Świadomy skrót: kontrola składniowa, nie parser; granicą jest pełny dostęp.
- Retention (nowy): w asm_mcp_cleanup usuwa zakończone operacje starsze niż 30 dni z kluczami, krokami i kopiami (także „-after”) oraz osierocone katalogi magazynu starsze niż doba. PrivateStorage::directories(), remove().
- UserResource: odmowa roli z manage_options (także przez default_role) i nieistniejącej roli (asm_user_invalid_role) oraz zmiany hasła, e-maila, loginu, roli i usunięcia konta administratora (asm_user_privileged); tryb password przyjmuje tylko user_pass, update nie przyjmuje user_pass. Rollback konta chronionego (administrator teraz albo w kopii, także przez indywidualne manage_options) nie przywraca hasła, e-maila, loginu, uprawnień, sesji ani haseł aplikacji, nie odtwarza usuniętego administratora; ochrona zapisana w kopii (pole protected); cofnięcie user.create nie usuwa konta awansowanego na administratora ani konta z treścią; własne verifyRollback.
- SecretDetector::publicValue rekurencyjnie po kluczach; podgląd option.* w REST ukrywa snapshot.value.
- Popup: autoload true (migracja w Installer), czyszczenie cache stron (LiteSpeed, W3TC, WP Super Cache) przy każdym zapisie, wp_kses_post przy wyświetlaniu, limity tytułu 200 i treści 20000 znaków; popup.js: dismiss_days 0 zapamiętuje zamknięcie w sessionStorage.
- FileResource: ukrywanie każdej stałej define z PASS/SECRET/KEY/SALT/TOKEN; usuwanie niepełnego pliku tymczasowego. MediaResource: błąd wp_update_post przy media.replace zgłaszany. DirectorySnapshot::restore przez katalog tymczasowy i zamianę nazw. RemoteFetcher: przypięcie sprawdzonych IP (CURLOPT_RESOLVE).
- build.py pakuje tylko pliki z git ls-files. Usunięte nieużywane assets/popup.css i popup-v2.css, powielone AdminPage::humanDate.
- tests/integration/data-safety.php: 44 sprawdzeń na prawdziwym WordPressie.

## 2.7.3, 19 listopada 2026

- ComponentResource::applyPlugin(update): Plugin_Upgrader::bulk_upgrade() zamiast upgrade() (upgrade() wyłączał aktywną wtyczkę i jej nie włączał; 18.11 Social Meta na rs2.pl była wyłączona ok. 6 minut). Zapamiętanie stanu aktywnego (także sieciowego) i ponowne activate_plugin() w razie potrzeby, błąd asm_plugin_reactivation_failed.
- Gdy w update_plugins brakuje wtyczki (np. po cache.purge), wp_update_plugins() przed aktualizacją; bez nowszej wersji błąd asm_plugin_no_update zamiast fałszywego sukcesu. Wcześniej samoaktualizacja ASM kończyła się asm_plugin_upgrade_failed.
- Sukces bulk_upgrade to tablica z danymi instalacji: traktowana jako powodzenie.
- tests/integration/plugin-update-keeps-active.php: test w prawdziwym WordPressie.

## 2.7.2, 19 listopada 2026

- Updater::info() i readme: tested 7.2 zamiast 7.1.4 (testy na 7.1.5 i 7.2-RC2). install_plugin_information() w 7.1.5 porównuje pełną wersję: version_compare(get_bloginfo('version'), tested, '<='), więc 7.1.4 dawało ostrzeżenie o braku testów.

## 2.7.1, 18 listopada 2026

- Updater::hooks(): filtr plugins_api na priorytecie PHP_INT_MAX. CMS Portal 2.1.3 (motyw Wastia na rs2.pl) na priorytecie 20 zwracał false dla obcych slugów i okno „Zobacz szczegóły” było puste.

## 2.7.0, 17 listopada 2026

- Popup::PRESETS i templates(): okazje black-friday, walentynki, mikolajki (razem 7 okazji). Szablony sprzedażowe mają pola w nawiasach kwadratowych zamiast wymyślonych rabatów i terminów.

## 2.6.2, 17 listopada 2026

- Updater::release() czyta wersję z przekierowania github.com/<repo>/releases/latest zamiast z api.github.com (limit 60 zapytań na godzinę na IP). Adres paczki wynika z wersji: releases/download/vX.Y.Z/ai-site-manager-X.Y.Z.zip.
- Panel popupu: kolorowy komunikat stanu (klasa asm-state: on, warn, off, error) pod przełącznikiem, także po odświeżeniu strony.

## 2.6.1, 17 listopada 2026

- Akcja wp_ajax_asm2_popup_toggle: natychmiastowy zapis pola enabled popupu z panelu, z komunikatem stanu; pozostałe pola nadal przez „Zapisz popup”.

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
