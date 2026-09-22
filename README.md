### System rezerwacju usług restauracji Michellin<br>

### Autorzy projektu: Krzysztof Zańko i Bruno Toczyński

Dodano listę ignorowanych plików ".gitignore" która nie śledzi plików ,które i tak są niewpływające lub automatycznie generowane.

## Baza danych

Baza danych projektu została utworzona w MySQL.

Plik z pełną strukturą bazy oraz danymi testowymi znajduje się w katalogu 'database' i nosi nazwę:

`database.sql`

### Baza zawiera następujące tabele:

- `users` – użytkownicy systemu oraz ich role,
- `employees` – pracownicy restauracji,
- `categories` – kategorie i podkategorie usług,
- `services` – usługi oferowane przez restaurację,
- `employee_services` – przypisanie pracowników do usług,
- `employee_availability` – godziny dostępności pracowników,
- `reservations` – rezerwacje klientów.

### W bazie zastosowano:

- klucze główne (PRIMARY KEY),
- klucze obce (FOREIGN KEY),
- relacje 1:N,
- relację N:M poprzez tabelę `employee_services`,
- ograniczenia `NOT NULL`, `UNIQUE` i `CHECK`,
- indeksy dla wybranych kolumn,
- dane testowe umożliwiające sprawdzenie działania bazy.

### Odtworzenie bazy

1. Uruchomić MySQL.
2. Otworzyć phpMyAdmin.
3. Zaimportować plik `database.sql`.
4. Po imporcie zostanie utworzona baza `michelin_restaurant` wraz ze wszystkimi tabelami i danymi testowymi.

### Diagram ERD bazy znajduje się w dokumentacji projektu.
