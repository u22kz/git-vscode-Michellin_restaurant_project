<?php
/**
 * Plik: db_connect.php
 * Cel: Nawiązanie bezpiecznego połączenia z bazą danych MySQL
 *      przy użyciu PDO. Plik ten jest dołączany (require) na początku
 *      każdej innej strony, która potrzebuje dostępu do bazy.
 *
 * UWAGA BEZPIECZEŃSTWA:                                                          --->trzeba poprawić
 * Dane dostępowe do bazy NIE powinny trafiać do publicznego repozytorium.
 * W tym projekcie szkolnym trzymamy je tutaj dla prostoty, ale w praktyce
 * warto wydzielić je do osobnego pliku (np. config.php) i dodać go do
 * .gitignore, żeby nie trafił na GitHuba.
 */

// --- Dane dostępowe do bazy danych (dostosuj do swojego środowiska) ---
$db_host    = '127.0.0.1';
$db_name    = 'michelin_restaurant';
$db_user    = 'root';
$db_pass    = '';
$db_charset = 'utf8mb4';

// DSN (Data Source Name) - opisuje PDO, z jaką bazą i jak się łączyć
$dsn = "mysql:host={$db_host};dbname={$db_name};charset={$db_charset}";

// Opcje PDO:
// - ERRMODE_EXCEPTION      -> błędy PDO zamieniają się w wyjątki (łapiemy je w try-catch)
// - FETCH_ASSOC            -> wyniki zapytań domyślnie jako tablice asocjacyjne (['kolumna' => wartość])
// - EMULATE_PREPARES=false -> wymusza PRAWDZIWE prepared statements po stronie serwera MySQL,
//                             a nie tylko symulowane przez PHP (dodatkowa ochrona przed SQL Injection)
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);
} catch (PDOException $e) {
    // Nigdy nie pokazujemy użytkownikowi szczegółów technicznych błędu
    // (mogłyby ujawnić strukturę bazy, hasła w konfiguracji itp.).
    // Szczegóły trafiają do logu serwera, do wiadomości tylko dla administratora.
    error_log('Błąd połączenia z bazą danych: ' . $e->getMessage());
    die('Wystąpił problem z połączeniem z bazą danych. Spróbuj ponownie później.');
}
