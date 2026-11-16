# Poświadczenia i bezpieczeństwo

Poświadczenie jest losowe i związane z właścicielem, instalacją, adresem home oraz solami WordPressa. Serwer zapisuje tylko hash sekretu. Jednorazowy ekran nie zapisuje sekretu w transient, localStorage lub sessionStorage. Konfiguracje i instrukcje nie zawierają jego wartości.

Używaj osobnego połączenia dla aplikacji i komputera. Wybierz Bezterminowo lub Do dnia. Formularz ręcznego poświadczenia podpowiada 90 dni; decyzję możesz zmienić. Brak zapisanego sekretu wymaga nowego połączenia, aktualizacji klienta i cofnięcia starego. To poświadczenie działa tylko na endpoint MCP, nie na ASM REST, WP REST ani w panelu. Nie twórz hasła aplikacyjnego WordPressa dla tego transportu.

Origin, jeśli klient go przesyła, musi być dokładnym originem strony. Nie rozszerzamy CORS. Publiczny serwer wymaga HTTPS. HTTP jest dopuszczony tylko dla localhost w środowisku WordPress local. Używaj dokładnego adresu z panelu. Obsługujemy pretty i plain permalinks; dla plain dozwolony jest tylko pojedynczy canonical `rest_route=/asm-mcp/v1/mcp`. Inne query, `_method`, `_wpnonce` i nagłówek override są odrzucane.

Po odtworzeniu kopii strony cofnij wszystkie połączenia w panelu. Zmiana domeny lub soli unieważnia stare tokeny. Bitowo identycznej kopii z tym samym adresem i solami nie można automatycznie rozpoznać. Przed udostępnieniem takiej kopii odetnij jej sieć i cofnij połączenia. Nie jest to automatyczna detekcja wszystkich klonów.

Limit: 120 uwierzytelnionych żądań na minutę dla połączenia, atomowo w DB; body do 256 KiB. Aktywność: 30 dni. Zakończone plany: 30 dni, niejasne executing/rolling_back/unknown pozostają do sprawdzenia. Usunięte lub zakończone połączenia: po 90 dniach tylko bez powiązanych operacji. Aktywne bezterminowe połączenia pozostają. Sprzątanie zależy od WP-Cron.

## Przygotuj środowisko klienta

Wykonuj te kroki na komputerze klienta, nie na serwerze WordPressa. Nie wpisuj tokenu w polecenie, argumenty procesu, plik konfiguracyjny ani rozmowę z modelem.

Windows, PowerShell: wpisz poświadczenie w ukrytym pytaniu. Polecenia nie zawierają jego wartości.

```powershell
$asmSecret = Read-Host 'Poświadczenie WordPressa' -AsSecureString
$env:ASM_MCP_TOKEN = [System.Net.NetworkCredential]::new('', $asmSecret).Password
Remove-Variable asmSecret
$env:ASM_MCP_URL = '{{MCP_URL}}'
```

macOS lub Linux, Bash: wklej token po wyświetleniu pytania. Nie zapisuj go w pliku profilu.

```bash
read -r -s -p 'Poświadczenie WordPressa: ' ASM_MCP_TOKEN
printf '\n'
export ASM_MCP_TOKEN
export ASM_MCP_URL='{{MCP_URL}}'
```

Uruchom klienta z tego samego terminala. Już działająca aplikacja nie odziedziczy nowych zmiennych. Zmienne środowiska nie są szyfrowanym sejfem. Inne procesy konta mogą uzyskać do nich dostęp.

Po zakończeniu zamknij klienta. PowerShell: Remove-Item Env:ASM_MCP_TOKEN, Env:ASM_MCP_URL -ErrorAction SilentlyContinue. Bash: unset ASM_MCP_TOKEN ASM_MCP_URL.

Przed opuszczeniem jednorazowego ekranu zapisz token w menedżerze haseł pod nazwą witryny i połączenia. Jeśli go utracisz, cofnij stary dostęp i utwórz nowy.

Logowanie OAuth nie wymaga ręcznego sekretu. Token dostępu ma ważność jednej godziny, a odnowienie jest rotowane. Cofnięcie i usunięcie blokują zarówno odczyt, jak i odnowienie. Zmiana terminu jest dostępna tylko dla nadal aktywnego połączenia.
