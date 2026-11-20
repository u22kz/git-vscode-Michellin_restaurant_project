<?php
// Skopiuj do private/db_credentials.php i uzupełnij lokalnie.
// Katalog private musi pozostać poza katalogiem udostępnianym przez WWW.
return [
    'host' => '127.0.0.1',
    'port' => 3306,
    'dbname' => 'michelin_restaurant',
    'username' => 'michelin_app',
    'password' => 'UZUPELNIJ_LOKALNIE',

    // Włącz tylko na lokalnej bazie demonstracyjnej, na czas inicjalizacji kont.
    'allow_demo_accounts' => false,
];
