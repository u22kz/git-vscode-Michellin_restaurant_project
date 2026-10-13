# Michelin Restaurant

Projekt aplikacji internetowej do obsługi restauracji i systemu rezerwacji.

Aplikacja powstaje w ramach szkolnego projektu zespołowego. Jej celem jest umożliwienie użytkownikom zakładania kont, logowania się oraz rezerwowania usług restauracyjnych.

Aktualny link do strony: https://u22kz.serv00.net/

## Organizacja projektu

Projekt jest realizowany zespołowo, a jego kod źródłowy znajduje się we wspólnym repozytorium GitHub.

Dodano plik `.gitignore`, który wyklucza ze śledzenia zbędne pliki, pliki generowane automatycznie oraz inne elementy niewymagające przechowywania w repozytorium.

## Wykorzystane technologie

- PHP – obsługa logiki aplikacji.
- MySQL/MariaDB – przechowywanie danych.
- HTML5 i CSS3 – struktura i wygląd strony.
- JavaScript – interaktywne elementy aplikacji.
- Git i GitHub – kontrola wersji.
- phpMyAdmin – zarządzanie bazą danych.
- Serv00.com – hosting projektu.

## Struktura projektu

```text
/
├── database/
│   ├── database.sql
│   └── [diagram ERD]
├── public/
│   ├── auth_check.php
│   ├── db_connect.php
│   ├── index.php
│   ├── login.php
│   ├── logout.php
│   └── register.php
├── .gitignore
└── README.md
```

Wraz z rozwojem projektu będą dodawane kolejne pliki odpowiedzialne za panele użytkowników, obsługę rezerwacji i pozostałe funkcjonalności.

## Baza danych

Baza danych projektu została utworzona w MySQL/MariaDB.

Plik z pełną strukturą bazy oraz danymi testowymi znajduje się w:

`database/database.sql`

Baza zawiera następujące tabele:

- `users` – użytkownicy systemu oraz ich role.
- `employees` – pracownicy restauracji.
- `categories` – kategorie i podkategorie usług.
- `services` – usługi oferowane przez restaurację.
- `employee_services` – przypisanie pracowników do usług.
- `employee_availability` – godziny dostępności pracowników.
- `reservations` – rezerwacje klientów.

W bazie zastosowano:

- Klucze główne (`PRIMARY KEY`).
- Klucze obce (`FOREIGN KEY`).
- Relacje 1:N.
- Relację N:M poprzez tabelę `employee_services`.
- Ograniczenia `NOT NULL`, `UNIQUE` i `CHECK`.
- Indeksy dla wybranych kolumn.
- Dane testowe umożliwiające sprawdzenie działania bazy.

### Odtworzenie bazy

1. Uruchomić MySQL/MariaDB.
2. Otworzyć phpMyAdmin.
3. Utworzyć pustą bazę danych, jeżeli nie została jeszcze utworzona.
4. Zaimportować plik `database/database.sql`.
5. Sprawdzić, czy wszystkie tabele i dane testowe zostały poprawnie zaimportowane.

**Uwaga:** plik SQL przeznaczony do importu na Serv00 może różnić się od wersji lokalnej, ponieważ baza na hostingu jest tworzona wcześniej w panelu administracyjnym.

Diagram ERD bazy znajduje się w dokumentacji projektu.

## Etap 1 – Przygotowanie projektu i bazy danych

W ramach pierwszego etapu wykonano:

- Utworzenie wspólnego repozytorium GitHub.
- Przygotowanie struktury projektu.
- Zaprojektowanie bazy danych.
- Utworzenie tabel i relacji.
- Przygotowanie diagramu ERD.
- Dodanie danych testowych.
- Przygotowanie pliku SQL.
- Dokumentację bazy danych w README.

## Etap 2 – Użytkownicy, rejestracja i logowanie

**Termin: 16 października 2026 r.**

Drugi etap obejmuje przygotowanie systemu kont użytkowników, rejestracji, logowania i kontroli dostępu.

W projekcie przygotowano następujące pliki:

- `register.php` – obsługa rejestracji użytkowników.
- `login.php` – obsługa logowania.
- `logout.php` – wylogowanie użytkownika.
- `auth_check.php` – kontrola uwierzytelnienia i dostępu.
- `db_connect.php` – połączenie aplikacji z bazą danych.
- `index.php` – strona główna aplikacji.

### Role użytkowników

W bazie danych przewidziano trzy role:

| Rola | Opis |
|---|---|
| `client` | Klient restauracji |
| `employee` | Pracownik restauracji |
| `admin` | Administrator systemu |

Role przechowywane są w kolumnie `role` tabeli `users`.

Aplikacja powinna wykorzystywać te role do kontroli dostępu do odpowiednich funkcji i paneli.

### Bezpieczeństwo logowania

System powinien zapewniać:

- Bezpieczne hashowanie haseł przy rejestracji.
- Weryfikację haseł podczas logowania.
- Obsługę sesji użytkownika.
- Możliwość wylogowania.
- Ochronę stron wymagających logowania.
- Kontrolę dostępu na podstawie roli.
- Walidację danych po stronie serwera.

Działanie powyższych mechanizmów wymaga sprawdzenia podczas testów aplikacji.

## Hosting – Serv00.com

Do uruchomienia projektu wykorzystywany jest hosting Serv00.com.

W panelu administracyjnym DevilWEB przygotowano bazę danych MySQL/MariaDB.

Struktura bazy i dane testowe zostały poprawnie zaimportowane przez phpMyAdmin.

### Wdrożenie aplikacji

1. Utworzyć stronę WWW obsługującą PHP w panelu Serv00.
2. Przesłać pliki aplikacji do katalogu strony WWW.
3. Skonfigurować połączenie PHP z bazą MySQL.
4. Sprawdzić działanie strony głównej.
5. Przetestować rejestrację i logowanie.
6. Sprawdzić działanie sesji i uprawnień użytkowników.

**Dane dostępowe do bazy nie powinny być umieszczane w publicznym repozytorium GitHub.**

## Status projektu

| Element | Status |
|---|---|
| Repozytorium GitHub | Przygotowane |
| Struktura bazy danych | Wykonana |
| Diagram ERD | Wykonany |
| Dane testowe | Dodane |
| Baza MySQL na Serv00 | Zaimportowana |
| Pliki PHP do logowania i rejestracji | Dodane do projektu |
| Konfiguracja połączenia PHP z hostingiem | Do wykonania |
| Testy rejestracji i logowania na hostingu | Do wykonania |
| Testy kontroli uprawnień | Do wykonania |

## Kontrola wersji

Zmiany w projekcie są zapisywane za pomocą systemu Git.

Każdy commit dokumentuje kolejne etapy pracy, poprawki i dodawane funkcjonalności.

Historia commitów umożliwia sprawdzenie postępów oraz wkładu poszczególnych członków zespołu.
