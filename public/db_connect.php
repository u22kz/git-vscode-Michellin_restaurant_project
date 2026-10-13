<?php
/**
 * Polaczenie PDO z baza danych Michelin Restaurant.
 * Wymaga pliku private/db_credentials.php poza public_html.
 */

$configFile = dirname(__DIR__) . '/private/db_credentials.php';
if (!is_file($configFile)) {
    error_log('Brak prywatnego pliku konfiguracji bazy danych.');
    http_response_code(503);
    exit('Wystapil problem z polaczeniem z baza danych.');
}

$config = require $configFile;
if (!is_array($config)) {
    error_log('Niepoprawny format konfiguracji bazy.');
    http_response_code(503);
    exit('Wystapil problem z polaczeniem z baza danych.');
}

$db_host = $config['host'] ?? '';
$db_name = $config['dbname'] ?? '';
$db_user = $config['username'] ?? '';
$db_pass = $config['password'] ?? '';
$db_charset = 'utf8mb4';

if ($db_host === '' || $db_name === '' || $db_user === '') {
    error_log('Niekompletna konfiguracja bazy danych.');
    http_response_code(503);
    exit('Wystapil problem z polaczeniem z baza danych.');
}

$dsn = "mysql:host={$db_host};dbname={$db_name};charset={$db_charset}";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);
} catch (PDOException $e) {
    error_log('Blad polaczenia PDO: ' . $e->getMessage());
    http_response_code(503);
    exit('Wystapil problem z polaczeniem z baza danych.');
}
