Informacje o projekcie restauracji Michellin i ich dalsze postępy.<br>
+Dodano listę ignorowanych plików ".gitignore" która nie śledzi plików ,który i tak są zbędne lub automatycznie się tworzą.
## Baza danych

Baza danych projektu została utworzona w MySQL.

Plik z pełną strukturą bazy oraz danymi testowymi znajduje się w:

`database.sql`

Baza zawiera następujące tabele:

- `users` – użytkownicy systemu oraz ich role,
- `employees` – pracownicy restauracji,
- `categories` – kategorie i podkategorie usług,
- `services` – usługi oferowane przez restaurację,
- `employee_services` – przypisanie pracowników do usług,
- `employee_availability` – godziny dostępności pracowników,
- `reservations` – rezerwacje klientów.

W bazie zastosowano:
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

Diagram ERD bazy znajduje się w dokumentacji projektu.
