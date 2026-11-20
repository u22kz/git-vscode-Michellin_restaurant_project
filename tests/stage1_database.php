<?php
// Testy modyfikują dane wyłącznie w transakcjach zakończonych ROLLBACK.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/public/db_connect.php';

if (strpos($db_name, 'michelin_test_') !== 0) {
    fwrite(STDERR, "Test wymaga osobnej bazy o nazwie zaczynającej się od michelin_test_.\n");
    exit(1);
}

$checks = 0;
function check(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($label);
    }
    ++$checks;
    echo "OK: $label\n";
}

function rejects(PDO $pdo, string $sql, int $errorCode, string $label): void
{
    $pdo->beginTransaction();
    try {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            check((int)$e->errorInfo[1] === $errorCode, $label);
            return;
        }
        throw new RuntimeException('Baza zaakceptowała niedozwolone dane: ' . $label);
    } finally {
        $pdo->rollBack();
    }
}

try {
    $tables = $pdo->query('SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
        FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll();
    check(count($tables) === 11, '11 tabel');
    foreach ($tables as $table) {
        check($table['ENGINE'] === 'InnoDB'
            && $table['TABLE_COLLATION'] === 'utf8mb4_unicode_ci',
            $table['TABLE_NAME'] . ': InnoDB i utf8mb4');
    }
    check((int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'PRIMARY KEY'")->fetchColumn() === 11,
        'Każda tabela ma PK');
    check((int)$pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()")->fetchColumn() === 15, '15 kluczy obcych');

    // Zgodność typów oraz brak osieroconych wierszy dla każdego FK.
    $keys = $pdo->query('SELECT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME,
        k.REFERENCED_COLUMN_NAME, c.COLUMN_TYPE AS child_type, p.COLUMN_TYPE AS parent_type
        FROM information_schema.KEY_COLUMN_USAGE k
        JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA = k.TABLE_SCHEMA
            AND c.TABLE_NAME = k.TABLE_NAME AND c.COLUMN_NAME = k.COLUMN_NAME
        JOIN information_schema.COLUMNS p ON p.TABLE_SCHEMA = k.REFERENCED_TABLE_SCHEMA
            AND p.TABLE_NAME = k.REFERENCED_TABLE_NAME AND p.COLUMN_NAME = k.REFERENCED_COLUMN_NAME
        WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL')->fetchAll();
    foreach ($keys as $key) {
        $table = $key['TABLE_NAME'];
        $column = $key['COLUMN_NAME'];
        $parent = $key['REFERENCED_TABLE_NAME'];
        $parentColumn = $key['REFERENCED_COLUMN_NAME'];
        check($key['child_type'] === $key['parent_type'], "$table.$column: zgodność typu FK");
        $orphans = $pdo->query("SELECT COUNT(*) FROM `$table` c LEFT JOIN `$parent` p
            ON c.`$column` = p.`$parentColumn`
            WHERE c.`$column` IS NOT NULL AND p.`$parentColumn` IS NULL")->fetchColumn();
        check((int)$orphans === 0, "$table.$column: brak osieroconych danych");
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND SEQ_IN_INDEX = 1');
        $stmt->execute([$table, $column]);
        check((int)$stmt->fetchColumn() > 0, "$table.$column: indeks FK");
    }

    foreach (['users' => 10, 'employees' => 7, 'categories' => 10, 'services' => 6,
        'employee_services' => 13, 'employee_availability' => 28, 'reservations' => 3] as $table => $count) {
        check((int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() === $count, "$table: liczba danych demo");
    }
    check($pdo->query('SELECT surname FROM users WHERE user_id = 3')->fetchColumn() === 'Wiśniewski', 'Polskie znaki');
    check((int)$pdo->query("SELECT COUNT(*) FROM reservations r JOIN users u USING (user_id)
        WHERE u.role <> 'client'")->fetchColumn() === 0, 'Rezerwacje demo należą do klientów');
    check((int)$pdo->query('SELECT COUNT(*) FROM reservations r
        LEFT JOIN employee_services es ON es.employee_id = r.employee_id AND es.service_id = r.service_id
        JOIN services s ON s.service_id = r.service_id
        WHERE es.employee_id IS NULL OR TIMESTAMPDIFF(MINUTE, r.start_time, r.end_time) <> s.duration_minutes
        OR NOT EXISTS (SELECT 1 FROM employee_availability a WHERE a.employee_id = r.employee_id
            AND a.day_of_week = WEEKDAY(r.reservation_date) + 1
            AND a.start_time <= r.start_time AND a.end_time >= r.end_time)')->fetchColumn() === 0,
        'Rezerwacje demo: usługi, czas trwania i dostępność');
    check($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES) === false, 'Natywne zapytania przygotowane PDO');

    rejects($pdo, 'UPDATE reservations SET user_id = 4294967295 WHERE reservation_id = 1', 1452, 'FK odrzuca brak rodzica');
    rejects($pdo, 'DELETE FROM users WHERE user_id = 1', 1451, 'RESTRICT chroni historię rezerwacji');
    rejects($pdo, 'INSERT INTO employee_services VALUES (1, 1)', 1062, 'PK chroni relację N:M');
    rejects($pdo, "UPDATE users SET customer_email = 'jan.kowalski@example.com' WHERE user_id = 2", 1062, 'UNIQUE email');
    rejects($pdo, 'UPDATE services SET price = -1 WHERE service_id = 1', 3819, 'CHECK cena');
    rejects($pdo, 'UPDATE services SET duration_minutes = 0 WHERE service_id = 1', 3819, 'CHECK czas usługi');
    rejects($pdo, 'UPDATE employee_availability SET day_of_week = 0 WHERE availability_id = 1', 3819, 'CHECK dzień tygodnia');
    rejects($pdo, 'UPDATE employee_availability SET end_time = start_time WHERE availability_id = 1', 3819, 'CHECK godziny pracy');
    rejects($pdo, 'UPDATE reservations SET guest_count = 0 WHERE reservation_id = 1', 3819, 'CHECK liczba gości');
    rejects($pdo, 'UPDATE reservations SET end_time = start_time WHERE reservation_id = 1', 3819, 'CHECK godziny rezerwacji');
    rejects($pdo, "INSERT INTO employee_leave_requests (employee_id, start_date, end_date)
        VALUES (1, '2026-10-12', '2026-10-11')", 3819, 'CHECK daty urlopu');

    $oldName = $pdo->query('SELECT name FROM users WHERE user_id = 1')->fetchColumn();
    $pdo->beginTransaction();
    $pdo->exec("UPDATE users SET name = 'Rollback test' WHERE user_id = 1");
    $pdo->rollBack();
    check($pdo->query('SELECT name FROM users WHERE user_id = 1')->fetchColumn() === $oldName, 'ROLLBACK przywraca dane');

    $second = new PDO($dsn, $db_user, $db_pass, $options);
    $second->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $pdo->beginTransaction();
    try {
        $pdo->query('SELECT employee_id FROM employees WHERE employee_id = 1 FOR UPDATE')->fetchAll();
        rejects($second, 'UPDATE employees SET position = position WHERE employee_id = 1', 1205,
            'FOR UPDATE blokuje zapis z drugiego połączenia');
    } finally {
        $pdo->rollBack();
    }
    echo "Zakończono: $checks kontroli OK.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'BŁĄD: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
