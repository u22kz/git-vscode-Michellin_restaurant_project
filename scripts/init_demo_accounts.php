<?php
// Narzędzie lokalne. Hasła jawne trafiają tylko do terminala po zatwierdzeniu transakcji.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if ($argc !== 2 || $argv[1] !== '--initialize-demo-accounts') {
    fwrite(STDERR, "Użycie: php scripts/init_demo_accounts.php --initialize-demo-accounts\n");
    exit(1);
}

require dirname(__DIR__) . '/public/db_connect.php';

if (($config['allow_demo_accounts'] ?? false) !== true) {
    fwrite(STDERR, "Inicjalizacja wyłączona. W lokalnej konfiguracji bazy demonstracyjnej ustaw allow_demo_accounts na true.\n");
    exit(1);
}

// Identyfikatory, adresy i role muszą odpowiadać dokładnie danym z database.sql.
$accounts = [
    1 => ['jan.kowalski@example.com', 'client'],
    2 => ['anna.nowak@example.com', 'client'],
    3 => ['piotr.wisniewski@example.com', 'employee'],
    4 => ['maria.wojcik@example.com', 'employee'],
    5 => ['tomasz.kaczmarek@example.com', 'employee'],
    6 => ['adam.zielinski@example.com', 'admin'],
    7 => ['michal.szymanski@example.com', 'employee'],
    8 => ['katarzyna.wozniak@example.com', 'employee'],
    9 => ['piotr.kozlowski@example.com', 'employee'],
    10 => ['magdalena.jankowska@example.com', 'employee'],
];

try {
    // Zapobiega częściowej inicjalizacji na starej bazie z tabelą MyISAM.
    $engine = $pdo->query("SELECT ENGINE FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'")->fetchColumn();
    if (strcasecmp((string)$engine, 'InnoDB') !== 0) {
        throw new RuntimeException('Tabela users musi używać InnoDB.');
    }

    $pdo->beginTransaction();
    $select = $pdo->prepare('SELECT customer_email, role, password, is_active
        FROM users WHERE user_id = ? FOR UPDATE');
    $update = $pdo->prepare('UPDATE users SET password = ?, failed_login_attempts = 0,
        locked_until = NULL WHERE user_id = ? AND password = ?');
    $created = [];

    foreach ($accounts as $id => [$email, $role]) {
        $select->execute([$id]);
        $user = $select->fetch();
        $placeholder = 'TEST_HASH_' . $id;
        if (!$user || $user['customer_email'] !== $email || $user['role'] !== $role
            || $user['password'] !== $placeholder || (int)$user['is_active'] !== 1) {
            continue;
        }

        $password = bin2hex(random_bytes(16));
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $update->execute([$hash, $id, $placeholder]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Nie udało się zainicjalizować konta demonstracyjnego.');
        }
        $created[] = [$email, $role, $password];
    }
    $pdo->commit();

    foreach ($created as [$email, $role, $password]) {
        fwrite(STDOUT, "$email\t$role\t$password\n");
    }
    fwrite(STDOUT, 'Zainicjalizowano kont: ' . count($created) . PHP_EOL);
    fwrite(STDOUT, "Zapisz hasła w menedżerze haseł i ustaw allow_demo_accounts na false.\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Inicjalizacja nie powiodła się: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
