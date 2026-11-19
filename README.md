# Michelin Restaurant — etap 1

Samodzielna aplikacja PHP/MySQL. Wszystkie polecenia poniżej wykonuj z katalogu
projektu, zawierającego `public`, `private` i `database`.

## Wymagania

- PHP 8.2 lub nowsze, rozszerzenia `PDO`, `pdo_mysql`, `mbstring` oraz obsługa sesji.
- MySQL 8.0.16 lub nowszy; środowisko weryfikacji: MySQL 8.4.13 i PHP 8.4.27.
  Starszy MySQL nie zapewnia wymaganej kontroli ograniczeń `CHECK`.
- Serwer Apache/Nginx z PHP albo wbudowany serwer PHP do pracy lokalnej.
- W `php.ini` ustaw `date.timezone = Europe/Warsaw` i zapisywalny `session.save_path`.
  Włącz `log_errors`; poza lokalnym debugowaniem wyłącz `display_errors`.

Sprawdź środowisko: `php -v`, `php --ini`, `php -m`. Konfiguracja PHP dla CLI
i serwera WWW może być różna. Aplikacja nie wymaga Composera ani Node.js.
Interfejs pobiera Bootstrap z CDN, więc jego wygląd wymaga dostępu do internetu.

## Nowa baza i import

`database/database.sql` służy wyłącznie do pierwszego importu do **pustej** bazy.
Zawiera schemat i dane demonstracyjne; nie jest migracją istniejącej instalacji.
Nie resetuje bazy, nie usuwa tabel ani nie wyłącza kluczy obcych. Nie uruchamiaj
go ponownie na istniejących danych i nie stosuj opcji klienta `--force`.
MySQL zatwierdza DDL niezależnie od transakcji: błąd importu może pozostawić
częściowy schemat. W takim przypadku diagnozuj błąd na osobnej bazie testowej.

Połącz się z MySQL kontem uprawnionym do tworzenia bazy (hasło wprowadzaj
w interaktywnym monicie):

```text
mysql --default-character-set=utf8mb4 -h 127.0.0.1 -P 3306 -u root -p
```

W kliencie MySQL, uruchomionym z katalogu projektu:

```sql
CREATE DATABASE michelin_restaurant CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE michelin_restaurant;
SOURCE database/database.sql;

CREATE USER 'michelin_app'@'127.0.0.1' IDENTIFIED BY 'WSTAW_WLASNE_LOSOWE_HASLO';
GRANT SELECT, INSERT, UPDATE, DELETE ON michelin_restaurant.* TO 'michelin_app'@'127.0.0.1';
```

Podane hasło jest znacznikiem do zastąpienia, nie hasłem aplikacji. Jeśli bazę
lub użytkownika utworzono wcześniej, pomiń ich tworzenie. Nie zmieniaj uprawnień
innych użytkowników. Konto aplikacji nie potrzebuje uprawnień `DROP`, `ALTER`
ani `CREATE`. Host w koncie MySQL dopasuj do miejsca, z którego łączy się PHP.

W phpMyAdmin: utwórz pustą bazę z `utf8mb4_unicode_ci`, wybierz ją, przejdź
do **Import**, wybierz `database/database.sql` i kodowanie UTF-8.

Wszystkie 11 tabel jawnie korzysta z InnoDB i `utf8mb4_unicode_ci`. Mają klucze
główne; 15 kluczy obcych ma zgodne typy i indeksy. Relacja pracownicy–usługi
ma złożony PK `(employee_id, service_id)`. Indeks rezerwacji
`(employee_id, reservation_date)` wspiera wyszukiwanie kolizji. Pozostawiono
istniejące reguły `RESTRICT`, `CASCADE` i `SET NULL`, zgodne z zachowaniem aplikacji.
Powiązanie wybranej usługi z pracownikiem sprawdza kod rezerwacji; nie dodano FK
do tabeli przypisań, bo administrator może zmieniać bieżące przypisania usług
bez usuwania historii rezerwacji.

Tabele urlopów, dni wolnych i dziennika były już częścią projektu; pozostają
w schemacie, ponieważ korzysta z nich aktualny kod. Nie rozszerzano tych funkcji.

## Konfiguracja PHP i uruchomienie

Skopiuj przykład w PowerShell:

```powershell
Copy-Item private/db_credentials.example.php private/db_credentials.php
```

Uzupełnij `host`, `port`, `dbname`, `username`, `password`. `port` domyślnie wynosi
3306, jeśli został pominięty. Plik zwraca tablicę PHP i nie powinien mieć BOM,
białych znaków przed `<?php` ani zamykającego znacznika PHP.

```text
php -S 127.0.0.1:8000 -t public
```

Otwórz `http://127.0.0.1:8000/`. W Apache/Nginx ustaw DocumentRoot na **public**,
nie na katalog całego projektu. Dzięki temu konfiguracja, SQL i skrypty CLI
pozostają poza dostępem WWW. Wbudowany serwer PHP służy do pracy lokalnej.

`public/db_connect.php` używa PDO, natywnych zapytań przygotowanych,
`utf8mb4` i wyjątków. Brak/błędna konfiguracja lub niedostępna baza daje
HTTP 503 z ogólnym komunikatem; szczegóły trafiają do logu serwera.
W CLI błąd połączenia kończy proces kodem 1.

`.gitignore` wyklucza prywatną konfigurację, pliki `.env`, logi i `.tools`.
Przykład konfiguracji pozostaje wersjonowany. Jeśli prawdziwe hasło było już
wcześniej w Git, samo dodanie `.gitignore` nie usuwa go z historii ani ze śledzenia.

## Konta demonstracyjne

SQL zawiera 10 kont z nieaktywnymi zastępczymi hasłami `TEST_HASH_*`.
Te ciągi nie pozwalają zalogować się przez `password_verify`.
Na lokalnej bazie demonstracyjnej:

1. Ustaw `allow_demo_accounts` na `true` w `private/db_credentials.php`.
2. Uruchom:

   ```text
   php scripts/init_demo_accounts.php --initialize-demo-accounts
   ```

3. Zapisz wygenerowane hasła z terminala w menedżerze haseł.
4. Ustaw `allow_demo_accounts` z powrotem na `false`.

Każde konto otrzymuje osobne losowe hasło z `random_bytes(16)`, zapisane
przez `password_hash(PASSWORD_DEFAULT)`. Skrypt działa tylko w CLI, sprawdza
ID, e-mail, rolę, aktywność oraz dokładną wartość `TEST_HASH_<ID>`. Wymaga
InnoDB, blokuje wiersze i zatwierdza zmiany jedną transakcją. Pomija istniejące
prawidłowe hasła oraz konta o zmienionych danych; kolejne uruchomienie nie resetuje
haseł. Nie tworzy nowych użytkowników, tylko inicjalizuje konta zaimportowane z SQL.

| Rola | Przykładowy login | Hasło |
| --- | --- | --- |
| Klient | `jan.kowalski@example.com` | Wygenerowane w terminalu |
| Klient | `anna.nowak@example.com` | Wygenerowane w terminalu |
| Pracownik | `piotr.wisniewski@example.com` | Wygenerowane w terminalu |
| Administrator | `adam.zielinski@example.com` | Wygenerowane w terminalu |

Pozostałe konta pracowników: `maria.wojcik@example.com`,
`tomasz.kaczmarek@example.com`, `michal.szymanski@example.com`,
`katarzyna.wozniak@example.com`, `piotr.kozlowski@example.com`,
`magdalena.jankowska@example.com`.

Trzy rezerwacje demonstracyjne mają stałe daty w październiku 2026 i służą też
do prezentowania historii. Nową rezerwację składaj na przyszły dzień pracy
pracownika. Pracownicy o ID 6 i 7 nie mają jeszcze przypisanych usług ani grafiku.

## Sprawdzanie projektu

Składnia wszystkich plików aplikacji, konfiguracji przykładowej i narzędzi:

```powershell
$failed = 0
Get-ChildItem public,private,scripts,tests -Recurse -Filter *.php | ForEach-Object {
    php -l $_.FullName
    if ($LASTEXITCODE -ne 0) { $failed++ }
}
if ($failed -gt 0) { throw "Błędy składni PHP: $failed" }
```

Test bazy wymaga **osobnej**, świeżo zaimportowanej bazy z nazwą zaczynającą się
od `michelin_test_`, np. `michelin_test_local`. Skonfiguruj do niej połączenie
w lokalnym `private/db_credentials.php`, następnie uruchom:

```text
php tests/stage1_database.php
```

Test nie tworzy, nie importuje i nie usuwa baz. Sprawdza schemat, indeksy,
relacje, dane demonstracyjne, ograniczenia, rollback i blokady między dwoma
połączeniami. Próby modyfikacji danych wycofuje przez `ROLLBACK`. Wymaga MySQL;
kody błędów `CHECK` dla MariaDB są inne. Po testach przywróć konfigurację
właściwego środowiska. Nigdy nie kieruj testów na bazę zawierającą prawdziwe dane.

Adres `dashboard_client%20copy.php` zwraca HTTP 410 zarówno dla GET, jak i POST.
Aktywny formularz jest dostępny pod `dashboard_client.php`.

Dokumentacja silnika: [ograniczenia CHECK](https://dev.mysql.com/doc/refman/8.4/en/create-table-check-constraints.html).

## Wyniki weryfikacji — 18.11.2026

- Import całego `database.sql` przez PDO do nowej, izolowanej bazy MySQL 8.4.13:
  poprawny, bez wyłączania FK i bez zmian w istniejących bazach.
- `tests/stage1_database.php`: 83 kontrole zakończone powodzeniem, w tym
  11 tabel, 15 FK, indeksy, polskie znaki, dane demo, odrzucanie nieprawidłowych
  danych, `ROLLBACK` i blokowanie drugiego połączenia przez `FOR UPDATE`.
- Generator kont: 10 losowych haseł poprawnie zweryfikowanych przez
  `password_verify`; ponowne uruchomienie nie zmieniło żadnego hasła;
  `allow_demo_accounts=false` zablokowało inicjalizację.
- Testy HTTP: logowanie i sesje trzech ról, wszystkie sześć podstron
  administracyjnych, kontrola dostępu, AJAX pracowników, zapis rezerwacji
  i odrzucenie kolizji. Wycofany formularz: HTTP 410 dla GET/POST, brak zapisu.
  Wnioski urlopowe: HTTP 403 bez CSRF, poprawny zapis i przekierowanie z tokenem.
- Pięć wariantów brakującej/błędnej konfiguracji lub niedostępnej bazy:
  ogólny komunikat HTTP 503 oraz kod wyjścia CLI 1.
- Końcowe `php -l`: 21 plików projektu, bez błędów. Brak BOM, treści przed
  otwierającym znacznikiem PHP i znaczników konfliktu Git.

Nie sprawdzano importu przez interfejs phpMyAdmin, wdrożenia Apache/Nginx,
MariaDB ani wyglądu w przeglądarce. Nie wykonywano testów obciążeniowych
ani pełnego audytu funkcji z kolejnych etapów. Testy korzystały z przenośnych
narzędzi w ignorowanym `.tools`; nie instalowano usług systemowych.
Tymczasowe połączenie testowe nie jest konfiguracją docelowej aplikacji.

Nie dostarczono `WYMAGANIA_MICHELIN.md` ani `AUDYT_STARTOWY.md`; zakres napraw
oparto na wymaganiach etapu 1 przekazanych w zadaniu i analizie aktualnego kodu.
