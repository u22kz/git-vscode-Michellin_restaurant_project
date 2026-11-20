<?php
/**
 * Polaczenie PDO z baza danych Michelin Restaurant.
 * Wymaga pliku private/db_credentials.php poza katalogiem public.
 */

$connectionFailure = static function (string $logMessage): void {
    error_log($logMessage);
    $message = 'Wystapil problem z polaczeniem z baza danych.';
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
    http_response_code(503);
    exit($message);
};

$configFile = dirname(__DIR__) . '/private/db_credentials.php';
if (!is_file($configFile)) {
    $connectionFailure('Brak prywatnego pliku konfiguracji bazy danych.');
}

$config = require $configFile;
if (!is_array($config)) {
    $connectionFailure('Niepoprawny format konfiguracji bazy.');
}

$db_host = $config['host'] ?? '';
$db_name = $config['dbname'] ?? '';
$db_user = $config['username'] ?? '';
$db_pass = $config['password'] ?? null;
$db_port = filter_var($config['port'] ?? 3306, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 65535],
]);
$db_charset = 'utf8mb4';

if (!is_string($db_host) || !is_string($db_name) || !is_string($db_user)
    || !is_string($db_pass) || $db_port === false
    || trim($db_host) === '' || trim($db_name) === '' || trim($db_user) === ''
    || preg_match('/[;\x00-\x1F]/', $db_host . $db_name)) {
    $connectionFailure('Niekompletna lub niepoprawna konfiguracja bazy danych.');
}

$dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset={$db_charset}";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);
    // Offset Warszawy dla bieżącego połączenia, również bez tabel stref MySQL.
    $timezone = (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))->format('P');
    $stmt = $pdo->prepare('SET time_zone = ?');
    $stmt->execute([$timezone]);
} catch (PDOException $e) {
    $connectionFailure('Blad polaczenia PDO: ' . $e->getMessage());
}
