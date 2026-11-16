# Zakresy i uprawnienia

Publiczny odczyt: opublikowane wpisy i strony bez hasła oraz istniejące publiczne obrazy. Nie odczytuje szkiców, metadanych, EXIF ani ścieżek serwera. Obraz jest publiczny, gdy nie ma rodzica lub rodzic jest publicznym wpisem albo stroną bez hasła.

Praca nad szkicami: dodatkowo odczyt niepublicznych post/page według uprawnień konta; tworzenie i poprawianie draft/pending. Nie zmienia już opublikowanej, zaplanowanej ani prywatnej treści. Nie publikuje.

Publikowanie: dodatkowo tworzenie, zmiana i publikacja post/page, także harmonogram future. Zmiana istniejącej publikacji wymaga tego zakresu.

Każdy zapis wymaga istniejących manage_options i asm_full_control właściciela oraz uprawnienia konkretnego obiektu. Otwórz Menedżer Strony AI, Operatorzy pełnego dostępu, Nadaj pełny dostęp przy swoim koncie. Moduł MCP nie nadaje automatycznie uprawnień. Do odczytu wystarcza właściciel z manage_options.

Trzy zakresy treści nie udostępniają CPT, użytkowników, ustawień, dowolnego meta, plików, administracji, uploadu mediów ani usuwania istniejących treści. Nie uruchamia shortcode lub kodu. Odczyt zwraca surową treść, nie renderuje jej.

Operacje trzech zakresów treści: content.create, content.update, content.replace. Target ma type posts i dodatnie całkowite id; create nie ma id. Update nie zmienia typu, autora ani rodzica. Replace obejmuje tylko post_title/content/excerpt i wymaga field, old, new, content_hash SHA256 oraz dokładnej liczby expected_occurrences. Nieznane pola są odrzucane.

Zmiana odczytu na pełne zarządzanie wymaga ponownego połączenia i zgody w aplikacji. Istniejące połączenia i tokeny zachowują swoje zakresy. Status, wykonanie i cofnięcie ponownie sprawdzają zakres oraz własność połączenia. Wygaśnięcie, cofnięcie lub odebranie roli blokuje kolejne żądanie.


Pełne zarządzanie stroną (full): istniejący katalog natywnego ASM, w tym treści, metadane, media, opcje, pliki, menu, użytkownicy, komentarze, cron, cache, wtyczki, motywy, baza i core. Dostępny wyłącznie dla konta z manage_options i asm_full_control. Każda akcja sprawdza dodatkowo CapabilityMap i uprawnienia obiektu. To zakres istniejących funkcji ASM, bez dowolnego proxy HTTP lub poleceń systemowych.

Kolejność: wp_inventory / wp_capabilities, wp_resource_list / wp_resource_get lub wp_search, wp_operation_preview z action/target/change/expected i opcjonalnym verification, następnie wp_operation_execute z confirm=true i idempotency_key. Po niejasnej odpowiedzi odczytaj wp_operation_status tej samej operacji. Nie twórz nowego zapisu. Pliki i opcje używają identyfikatorów tekstowych; params i filters odpowiadają natywnemu kontraktowi.

wp_media_stage przyjmuje publiczny adres HTTPS. Natywny RemoteFetcher odrzuca sieci prywatne. Zwrócony token przekaż jako change.staging_token do media.create lub media.replace w tym samym połączeniu. Token w mapowaniu jest przechowywany wyłącznie jako hash. Plan i snapshot są w szyfrowanym magazynie natywnego ASM; odpowiedzi operacji zawierają tylko metadane.

Automatyczne cofnięcie full jest dostępne dla content.create/update/replace/meta, option.update/replace_secret/delete i file.create/write/patch po stanie verified. Serwer porównuje hash rzeczywistego stanu po wykonaniu pod blokadą ASM. Późniejsza zmiana oznacza asm_conflict. Pozostałe akcje zwracają asm_rollback_unavailable; ich wykonanie nadal jest dostępne. Nie cofamy automatycznie efektów cron.run, core ani cache. Blokada ASM nie szereguje ręcznych zmian filesystem, SQL ani edytora WP; nie zapewnia globalnej atomowości.

Gdy klient OAuth pomija scope, ekran zgody pokazuje zakresy dozwolone rejestracją klienta i uprawnieniami konta, także full. Jawny publicread nie może być rozszerzony. Pełny zakres i Bezterminowo wybiera się jawnie na ekranie zgody. Moduł nie nadaje globalnie asm_full_control i nie usuwa starych połączeń.
# Granice zapisu i automatycznego cofania full

Podgląd, wykonanie i cofanie pełnego zakresu porównują także typy danych: tablica i obiekt oraz liczba całkowita i ułamek to różne stany. Pole danych `backup_path` w opcji lub metadanych nie jest pomijane. Pomijana jest jedynie techniczna ścieżka kopii w snapshotach plików. Starsze operacje zachowują dotychczasowy format i reguły.

Magazyn operacji używa szyfrowanego JSON. Zmiana, której typu lub bajtów nie można wiernie zapisać i odczytać, jest odrzucana w podglądzie z `asm_unpersistable_change`. Ułamki są zachowywane. Jeżeli istniejący snapshot zawiera obiekt PHP lub bajty niepoprawne w UTF-8, wykonanie obsługiwanej zmiany pozostaje dostępne, ale automatyczne cofnięcie jest niedostępne: `rollback_available=false` i `asm_rollback_unavailable`, bez przywracania zniekształconych danych. Nie deserializujemy klas z payloadu. Cykliczne, zbyt głębokie dane lub zasoby PHP bez obsługiwanej reprezentacji zatrzymują operację z `asm_unhashable_state`.
