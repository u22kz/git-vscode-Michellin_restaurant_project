# Michelin Restaurant — etapy 1–3 (kategorie i usługi)

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

## Wyniki weryfikacji etapu 1 — 18.11.2026

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

## Konta, sesje i uprawnienia — etap 2

Rejestracja publiczna tworzy wyłącznie klienta. Pola `role`, `user_id` i
`is_active` przesłane przez użytkownika nie wpływają na tworzone konto.
Imię i nazwisko wymagają liter (również polskich), z dopuszczalnymi spacjami,
apostrofami i łącznikami, przy limitach 50/80 znaków. Telefon musi zawierać
9–15 cyfr, maksymalnie 20 znaków ze spacjami, łącznikami i opcjonalnym `+`
na początku. E-mail jest walidowany i chroniony indeksem UNIQUE, także przy
równoczesnej rejestracji. Pola tekstowe przesłane jako tablice są odrzucane.

Nowe hasło wymaga co najmniej 8 znaków i najwyżej 72 bajtów UTF-8; znak NUL
jest zabroniony. Limit bajtów zapobiega obcinaniu hasła przez bcrypt używany
w obecnym `PASSWORD_DEFAULT`. Zmiana hasła wymaga dotychczasowego hasła
i zgodnego potwierdzenia. Hasła przechowujemy wyłącznie jako hashe,
weryfikowane przez `password_verify`. Przy logowaniu przestarzały hash
jest aktualizowany przez `password_needs_rehash`/`password_hash`.

Po 5 błędnych logowaniach konto jest blokowane na 15 minut, również dla
poprawnego hasła. Licznik działa między różnymi sesjami przeglądarki.
Transakcja z `SELECT ... FOR UPDATE` chroni jego aktualizację przed
nadpisywaniem równoczesnych prób. Czas blokady pochodzi z MySQL.
Jest to ograniczenie prób na konto; nie zastępuje ograniczania ruchu
na poziomie serwera dla ataków rozproszonych.

Sesje korzystają wyłącznie z ciasteczek, trybu ścisłego, `HttpOnly` i
`SameSite=Lax`; przy HTTPS otrzymują również `Secure`. Serwer produkcyjny
powinien obsługiwać HTTPS i przekazywać PHP poprawną wartość `HTTPS`.
Nie ufamy nagłówkom `X-Forwarded-*` nadsyłanym bezpośrednio przez klienta.
ID sesji zmienia się po logowaniu, zmianie hasła i zmianie roli. Odpowiedzi
korzystające z sesji mają `Cache-Control: no-store`.

Przy każdym żądaniu zalogowanego użytkownika sprawdzamy w bazie jego rolę,
aktywność oraz zgodność hasła z sesją. Dezaktywowane konto traci dostęp
przy następnym żądaniu, a zmiana hasła unieważnia inne sesje. W sesji jest
odcisk hasha hasła, nie hasło jawne. Błąd odczytu uprawnień blokuje dostęp
(HTTP 503). Sesje sprzed tej aktualizacji wymagają ponownego logowania.

Wspólna kontrola w `auth_check.php` odrzuca każdy POST z brakującym,
niepoprawnym lub nietekstowym tokenem CSRF (HTTP 403), przed obsługą
formularza. Dotyczy też logowania, rejestracji i wylogowania. Przyciski
wylogowania zachowują klasy Bootstrap, ale wysyłają POST z tokenem.
Bezpośredni GET do `logout.php` zwraca 405 i nie niszczy sesji.

Profil zawsze używa ID zalogowanej osoby; formularz nie może zmienić
e-maila, roli ani danych innego konta. Zapytania z danymi wejściowymi
używają parametrów PDO; dane użytkowników wyświetlane w HTML są escapowane.
Nie było potrzeby zmieniania schematu SQL ani danych istniejących użytkowników.

## Uruchomienie testów etapu 2

Utwórz osobną, **pustą** lokalną bazę o unikalnej nazwie `michelin_test_*`
i plik konfiguracji poza projektem, np. w katalogu tymczasowym. Format tablicy
jest taki jak w `private/db_credentials.example.php`. Konto testowe potrzebuje
uprawnień SELECT, INSERT, UPDATE, DELETE, CREATE i REFERENCES tylko do tej bazy.
Nie importuj SQL ręcznie — zrobi to test. Nie wskazuj istniejącej bazy aplikacji.

```text
php tests/stage2_auth.php /sciezka/do/test_credentials.php
```

W PowerShell podaj ścieżkę Windows w cudzysłowie. Wymagane są `pdo_mysql`,
`mbstring`, `allow_url_fopen=On`, dostępne `proc_open` i wolny lokalny port.
Test odmawia pracy na niepustej bazie oraz na nazwie bez prefiksu
`michelin_test_`. Kopiuje pliki PHP do losowego katalogu tymczasowego,
uruchamia własny serwer HTTP tylko na `127.0.0.1` i sprawdza jego tożsamość.
Nie zmienia konfiguracji ani plików aplikacji w projekcie.

Po zakończeniu test zatrzymuje swój serwer i usuwa kopię aplikacji, sesje
i konfigurację tymczasową. Dane testowe pozostają w wskazanej osobnej bazie
do ewentualnej inspekcji; kolejny przebieg wymaga nowej pustej bazy.
Pobrane zewnętrznie narzędzia PHP/MySQL przechowuj poza projektem i usuń po pracy.

Dokumentacja PHP: [ustawienia bezpieczeństwa sesji](https://www.php.net/manual/en/session.security.ini.php),
[hashowanie i limit bcrypt](https://www.php.net/manual/en/function.password-hash.php).

## Weryfikacja etapu 2 — 19.11.2026

Ponownie sprawdzono aktualne pliki względem WYMAGANIA_MICHELIN.md.
Wcześniejsze poprawki logowania, sesji, ról, profilu i CSRF były już obecne.
W tym przebiegu uzupełniono wspólną walidację kształtu danych GET/POST:
tablice w polach tekstowych/ID, NUL i niepoprawny UTF-8 zwracają HTTP 400
przed wykonaniem operacji. Wyjątkiem jest prawidłowa płaska lista `service_ids`
w formularzu pracowników. Błędny token CSRF nadal ma pierwszeństwo i daje 403.
Walidacja biznesowa poprawnie zbudowanych formularzy pozostaje bez zmian.

Poprawiono kolejność obsługi wyjątków w panelu klienta: PDOException jest
obsługiwany przed RuntimeException. Szczegóły błędu bazy są zapisywane w logu,
a użytkownik widzi ogólny komunikat. Test wymusił błąd SQL i potwierdził rollback.

Rzeczywiście wykonane sprawdzenia:

- PHP 8.4.28: `php -l` dla wszystkich 23 plików PHP — bez błędów.
- MySQL 8.4.13: import aktualnego SQL do pustej osobnej bazy testowej.
- `tests/stage2_auth.php`: **169/169 kontroli OK**. Rejestracja poprawna,
  niepoprawny/duplikowany email, niepoprawne hasła i potwierdzenia, wymuszenie
  roli admin przez POST, logowanie trzech ról, regeneracja i niszczenie sesji,
  profil i zmiana hasła, blokada po 5 próbach, odmowa dostępu klientowi do
  paneli, brak/błędny/tablicowy/obcy CSRF, deaktywacja w trakcie sesji,
  zmiana roli w trakcie sesji, SQL Injection, XSS, podmiana ID, walidacja
  kształtu danych oraz ukrywanie błędu PDO.
- Poprawne operacje administratora/pracownika nadal działają z prawidłowym
  CSRF, w tym zapis listy przypisanych usług.

Nie zmieniano schematu SQL, prawdziwych danych ani wyglądu formularzy.
Testy korzystały z kopii aplikacji i narzędzi poza projektem, w katalogu
tymczasowym; po pracy środowisko jest usuwane. W folderze projektu nie ma `.git`.
Nie wykonano commit/push.

Ograniczenia: bez testów wdrożenia Apache/Nginx/HTTPS i wyglądu w przeglądarce,
bez pełnej macierzy etapu 4 i równoległych żądań rezerwacji. Pozostałe problemy
biznesowe etapów 3–4 opisane w audycie wymagają osobnego zadania.

## Etap 3 — kategorie i usługi (19.11.2026)

W panelu administratora jest nawigacja do kategorii, usług, pracowników,
grafiku, rezerwacji i statystyk. Istniejące formularze zachowują Bootstrap.
Kategorie można dodawać, edytować i usuwać, o ile nie mają usług ani
podkategorii. Usługi można dodawać, edytować, przełączać ich dostępność
i usuwać, o ile nie mają rezerwacji ani przypisań do pracowników.
Powiązaną usługę można wyłączyć zamiast usuwać historię.

Walidacja odpowiada istniejącym kolumnom SQL:

- nazwa kategorii: 1–100 znaków; `parent_id` wskazuje istniejącą kategorię
  lub jest NULL; zakazane jest wskazanie siebie lub własnego potomka;
- `slug` jest generowany z nazwy i unikalny, także przy powtórzonej nazwie
  długości 100 znaków; sufiks mieści się w limicie 100 znaków;
- `sort_order`: liczba całkowita 0–2147483647;
- nazwa usługi: 1–150 znaków, istniejąca kategoria, niepusta jednostka
  do 20 znaków, cena 0–99999999,99 z maksymalnie dwoma miejscami dziesiętnymi;
- czas usługi: liczba całkowita 1–65535 minut, zgodnie z SMALLINT UNSIGNED;
- dostępność: wyłącznie `0` lub `1`; brak niezaznaczonego checkboxa oznacza `0`;
- opisy: maksymalnie 65535 bajtów UTF-8, zgodnie z kolumną TEXT.

Sprawdzenie relacji i zapis/usunięcie są wykonywane w transakcji.
Panel kategorii blokuje istniejące wiersze kategorii w kolejności ID,
aby serializować zmiany hierarchii i generowanie slugów. To rozwiązanie
przeznaczone dla małego katalogu restauracji; przy dużym katalogu należy
ocenić czas oczekiwania na blokady. Zapis usługi utrzymuje blokadę współdzieloną
kategorii, a edycja/usunięcie blokuje usługę. Błąd wycofuje transakcję.
Dotyczy to operacji katalogu; nie zastępuje testów współbieżnych rezerwacji.
Wszystkie zapytania trzech analizowanych paneli korzystają z prepare/execute.
Pozostają wymagane rola admin i prawidłowy CSRF. Schemat SQL nie wymaga zmian.

### Testy katalogu

```text
php tests/stage3_catalog.php /sciezka/do/test_credentials.php
```

Warunki uruchomienia są takie jak dla `stage2_auth.php`: osobna, pusta,
lokalna baza `michelin_test_*`, konfiguracja poza projektem, PHP z PDO MySQL,
mbstring i proc_open. Test sam importuje SQL i uruchamia kopię aplikacji
w katalogu tymczasowym. Nie wskazuj bazy użytkowników. Testy usuwania dotyczą
wyłącznie tej oddzielnej bazy. Kolejny przebieg wymaga nowej pustej bazy.

Rzeczywiście wykonano na PHP 8.4.28 / MySQL 8.4.13:

- **154/154 kontroli OK**: dodawanie i edycja kategorii, rodzice i cykle,
  slug dla polskich nazw i kolizji przy 100 znakach, granice sort_order,
  dodawanie i edycja usług, cena, czas, kategoria, dostępność, błędne wartości,
  blokada usuwania powiązanych rekordów i usuwanie niepowiązanych,
  odmowa dostępu klientowi/pracownikowi/anonimowemu, brak/błędny CSRF,
  escapowanie XSS, nawigacja i brak błędów PHP/SQL w logu;
- `php -l`: **24/24 pliki PHP bez błędów**.

Nie wykonywano testów wyglądu w rzeczywistej przeglądarce, obciążeniowych
ani równoległych żądań HTTP. Nie realizowano kolejnego etapu. Narzędzia i baza
testowa są tymczasowe, poza projektem, i po pracy są usuwane.
