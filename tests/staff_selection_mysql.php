<?php
/**
 * Test rzeczywistego SELECT z endpointu na danych CTE (MySQL 8+).
 * Wyłącznie SELECT: bez CREATE, importu, INSERT/UPDATE/DELETE i bez nowej bazy.
 * php tests/staff_selection_mysql.php /sciezka/do/istniejacej_konfiguracji.php
 * CTE przesłaniają nazwy tabel, więc test nie odczytuje danych użytkowników.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc !== 2 || !is_file($argv[1])) {
    fwrite(STDERR, "Nie wykonano: podaj konfigurację istniejącego MySQL 8+. Test nie tworzy bazy.\n");
    exit(2);
}
$config = require $argv[1];
$pdo = new PDO('mysql:host=' . $config['host'] . ';port=' . ($config['port'] ?? 3306)
    . ';dbname=' . $config['dbname'] . ';charset=utf8mb4', $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
$source = file_get_contents(__DIR__ . '/../public/dashboard_client.php');
if (!preg_match('/\$pdo->prepare\("(\s*SELECT DISTINCT.*?)"\);/s', $source, $match)) {
    throw new RuntimeException('Nie znaleziono zapytania endpointu.');
}
$cte = <<<'SQL'
WITH
ids AS (SELECT 1 AS id UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
    UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8
    UNION ALL SELECT 9 UNION ALL SELECT 10),
employees AS (SELECT id AS employee_id, id AS user_id, IF(id=2,0,1) AS is_active FROM ids),
users AS (SELECT id AS user_id, CONCAT('Osoba ',id) AS name, 'Test' AS surname,
    IF(id=3,0,1) AS is_active, IF(id=9,'client','employee') AS role FROM ids),
services AS (SELECT 1 AS service_id, 1 AS is_available, 60 AS duration_minutes
    UNION ALL SELECT 2,0,60),
employee_services AS (SELECT id AS employee_id, 1 AS service_id FROM ids WHERE id<>4
    UNION ALL SELECT 1,2),
employee_availability AS (SELECT id AS employee_id, IF(id=10,2,1) AS day_of_week,
    CAST('09:00:00' AS TIME) AS start_time,
    CAST(IF(id=8,'09:59:00','10:00:00') AS TIME) AS end_time FROM ids),
employee_days_off AS (SELECT 5 AS employee_id, CAST('2030-05-06' AS DATE) AS day_off_date),
employee_leave_requests AS (SELECT 6 AS employee_id, 'approved' AS status,
    CAST('2030-05-06' AS DATE) AS start_date, CAST('2030-05-06' AS DATE) AS end_date
    UNION ALL SELECT 7,'pending','2030-05-06','2030-05-06'),
restaurant_days_off AS (SELECT CAST('2030-05-07' AS DATE) AS day_off_date)
SQL;
$params = ['service_id'=>1, 'weekday'=>1, 'earliest'=>0, 'day_off_date'=>'2030-05-06',
    'leave_date'=>'2030-05-06', 'restaurant_date'=>'2030-05-06'];
$stmt = $pdo->prepare($cte . $match[1]);
$stmt->execute($params);
$ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
$checks = 0;
$labels = [1=>'Aktywny pracownik i grafik dokładnie długości usługi',2=>'Nieaktywny pracownik',
    3=>'Nieaktywne konto',4=>'Nieprzypisana usługa',5=>'Dzień wolny',6=>'Zatwierdzony urlop',
    7=>'Oczekujący urlop nie blokuje',8=>'Zbyt krótki grafik',9=>'Konto bez roli employee',10=>'Inny dzień tygodnia'];
foreach ($labels as $id=>$label) {
    if (in_array($id,$ids,true) !== in_array($id,[1,7],true)) throw new RuntimeException($label);
    echo "OK: $label\n"; ++$checks;
}
foreach ([['restaurant_date'=>'2030-05-07'], ['service_id'=>2], ['earliest'=>32401]] as $change) {
    $stmt->execute(array_replace($params,$change));
    if ($stmt->fetch()) throw new RuntimeException('Oczekiwano pustej listy: ' . json_encode($change));
    ++$checks;
}
echo "SELECT pracowników MySQL: $checks kontroli OK.\n";
