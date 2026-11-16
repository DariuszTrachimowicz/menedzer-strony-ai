# Claude przez logowanie

1. W WordPressie otwórz Menedżer Strony AI w menu kokpitu, zakładka Start. Skopiuj adres z instrukcji Claude.
2. W Claude otwórz Customize, Connectors, Add custom connector. Wpisz nazwę strony i adres `{{MCP_URL}}`.
3. Wybierz Continue, następnie Sign in now. Zaloguj się do WordPressa tej strony.
4. Ekran zgody pokaże aplikację, adres powrotu i zakres. Domyślny Publiczny odczyt nie zmienia treści. Zakres Szkice lub Publikowanie wymaga istniejących uprawnień konta ASM.
5. Wybierz Bezterminowo lub Do dnia i Zatwierdź dostęp. Odmowa nie tworzy dostępu.
6. Włącz połączenie w rozmowie. Poproś: „Wywołaj wp_site_status i podaj adres oraz zakres dostępu. Niczego nie zmieniaj.” Sprawdź rzeczywiste użycie w WordPressie.

Krok dodania odbywa się poza WordPressem. Dostępność własnych połączeń zależy od konta i organizacji Claude. Wymagany jest publiczny adres HTTPS. Adres localhost nie jest dostępny z Claude w chmurze.

W podstawowej ścieżce nie wpisujesz tokenu, nagłówka Authorization ani klucza dostawcy modelu. Logowanie i jawna zgoda wiążą dostęp z kontem WordPressa. Samo zatwierdzenie zgody daje stan Zgoda udzielona, a stan Połączono wymaga rzeczywistego użycia.

Cofnij dostęp lub Usuń połączenie w WordPressie, aby natychmiast odciąć kolejne żądania i odnowienie dostępu. Usunięcie samego połączenia w Claude nie zastępuje cofnięcia po stronie WordPressa. Wykonane zmiany nie są cofane przez odłączenie.

Wyniki narzędzi trafiają do infrastruktury dostawcy klienta. Właściciel wykonuje własne logowanie i zgodę; test lokalnego klienta QA nie potwierdza działania konta właściciela w Claude.

[Oficjalna instrukcja uwierzytelniania Claude](https://claude.com/docs/connectors/building/authentication)


W wydaniu 2.2.0 pełna administracja wymaga nowego połączenia i jawnej zgody na Pełne zarządzanie stroną. Wybierz Bezterminowo, jeśli dostęp ma trwać do cofnięcia. Istniejący publiczny odczyt zachowuje zakres. Szczegóły narzędzi i cofania zmian: [Zakresy i uprawnienia](permissions.md).
