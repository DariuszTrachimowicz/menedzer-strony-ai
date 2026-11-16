# Pierwsze kroki

Menedżer Strony AI łączy tę stronę z wybraną aplikacją AI. Konto modelu pozostaje w aplikacji. Wtyczka nie wymaga kluczy OpenAI, Anthropic ani Google. Wyniki narzędzi trafiają do klienta i mogą być przetwarzane przez dostawcę jego modelu.

## Claude przez logowanie

1. W panelu Połączenia AI otwórz Start i skopiuj adres z instrukcji Claude.
2. W Claude otwórz Customize, Connectors, Add custom connector. Podaj nazwę strony i wklej adres.
3. Wybierz Continue, następnie Sign in now.
4. Zaloguj się do WordPressa tej strony. Na ekranie zgody przeczytaj nazwę aplikacji, adres powrotu i zakres.
5. Wybierz Publiczny odczyt oraz Bezterminowo lub Do dnia. Zatwierdź dostęp.
6. Włącz połączenie w rozmowie i poproś o wp_site_status. Stan Połączono pojawia się po rzeczywistym użyciu.

Adres tej instalacji:

`{{MCP_URL}}`

Claude musi osiągnąć publiczny adres HTTPS. Localhost służy testom i jest niedostępny z chmury. Dodanie połączenia zależy od planu konta i ustawień organizacji Claude. Nie oznacza wpisu w oficjalnym katalogu aplikacji.

Nie trzeba kopiować poświadczenia, instalować Node ani tworzyć pliku JSON dla tej ścieżki. [Instrukcja Claude](claude-cloud.md) opisuje zgodę i odłączenie. [Cztery sposoby połączenia](advanced.md) opisują inne aplikacje i terminal.

## Zarządzanie dostępem

Połączenia pokazują tylko dostęp Twojego konta. Cofnij dostęp blokuje kolejne żądania. Usuń połączenie dodatkowo ukrywa wpis z listy. Historia pozostaje zgodnie z retencją: audyt i zakończone operacje przez 30 dni, operacje w toku i z nieustalonym wynikiem do wyjaśnienia.

Bezterminowo oznacza brak daty końca zgody. Uprawnienia konta, właściciel, powiązanie instalacji i cofnięcie są nadal sprawdzane. Token dostępu OAuth wygasa po godzinie; aplikacja odnawia go w ramach ważnej zgody. Zmiana terminu nie przywraca wygasłego, cofniętego lub usuniętego połączenia.

Pomoc znajduje się w help/pl. Panel pokazuje te same pliki z adresem tej instalacji i pozwala pobrać instrukcję Markdown.
