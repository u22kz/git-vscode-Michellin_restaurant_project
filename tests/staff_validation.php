<?php
/** Test logiki formularzy z atrapą PDO, bez bazy i bez testowania silnika SQL/autoryzacji. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
date_default_timezone_set('Europe/Warsaw');
final class StaffSaved extends Exception {}
function require_role(array $roles): void {}
function csrf_require_valid(): void {}
function current_user_id(): int { return 1; }
final class StaffPDO
{
    public bool $transaction = false;
    public int $writes = 0;
    public int $commits = 0;
    public int $overlap = 0;
    public bool $failWrite = false;
    public function beginTransaction(): void { $this->transaction = true; }
    public function inTransaction(): bool { return $this->transaction; }
    public function commit(): void { $this->transaction = false; ++$this->commits; }
    public function rollBack(): void { $this->transaction = false; $this->writes = 0; }
    public function lastInsertId(): string { return '1'; }
    public function prepare(string $sql): object
    {
        return new class($this, $sql) {
            public function __construct(private StaffPDO $db, private string $sql) {}
            public function execute(array $params = []): void {
                if (preg_match('/^\s*(INSERT|UPDATE|DELETE)\b/', $this->sql)) {
                    if ($this->db->failWrite) throw new PDOException('Test awarii zapisu');
                    ++$this->db->writes;
                }
            }
            public function fetch(): array|false { return ['employee_id' => 1, 'user_id' => 1]; }
            public function fetchColumn(): int { return $this->db->overlap; }
            public function fetchAll(int $mode = 0): array { return [1]; }
            public function rowCount(): int { return 1; }
        };
    }
}
function staff_case(string $file, array $post, bool $accepted, int $overlap = 0, bool $failWrite = false): void
{
    $source = file_get_contents(__DIR__ . '/../public/' . $file);
    $marker = match ($file) {
        'admin_availability.php' => '// Pobranie danych do edycji',
        'employee_leave.php' => '// Komunikaty po przekierowaniu',
        default => '// Pobranie danych pracownika do edycji',
    };
    $source = substr($source, 5, strpos($source, $marker) - 5);
    $source = preg_replace('/^require_once .*;\R/m', '', $source);
    $source = preg_replace('/function e\(\$value\) \{.*?\}/s', '', $source);
    $source = preg_replace('/^\s*header\([^\n]*\);/m', '', $source);
    $source = preg_replace('/\bexit;/', 'throw new StaffSaved();', $source);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = $post;
    $_GET = [];
    $pdo = new StaffPDO();
    $pdo->overlap = $overlap;
    $pdo->failWrite = $failWrite;
    $saved = false;
    try { eval($source); } catch (StaffSaved $e) { $saved = true; }
    if ($saved !== $accepted || $pdo->inTransaction()
        || (!$accepted && ($pdo->writes !== 0 || (empty($errors) && empty($message))))
        || ($accepted && $pdo->writes === 0)) {
        throw new RuntimeException('Nieprawidłowy wynik: ' . $file . ' ' . json_encode($post));
    }
}
$checks = 0;
$availability = ['action'=>'save','availability_id'=>'0','employee_id'=>'1','day_of_week'=>'1','start_time'=>'09:00','end_time'=>'17:00'];
foreach ([[], ['availability_id'=>'1']] as $change) {
    staff_case('admin_availability.php', array_replace($availability, $change), true); ++$checks;
}
foreach ([['day_of_week'=>'0'], ['day_of_week'=>'8'], ['day_of_week'=>'1x'], ['day_of_week'=>'1.5'],
    ['employee_id'=>'1x'], ['availability_id'=>'-1'], ['start_time'=>'17:00'], ['start_time'=>'18:00'],
    ['start_time'=>'9:00'], ['end_time'=>'24:00'], ['end_time'=>'12:60'], ['start_time'=>'']] as $change) {
    staff_case('admin_availability.php', array_replace($availability, $change), false); ++$checks;
}
staff_case('admin_availability.php', $availability, false, 1); ++$checks;
staff_case('admin_availability.php', $availability, false, 0, true); ++$checks;
$employee = ['action'=>'save','employee_id'=>'1','position'=>'Kelner','is_active'=>'1','service_ids'=>['1']];
foreach ([[], ['service_ids'=>[]], ['service_ids'=>['1','1']], ['is_active'=>'0'], ['employee_id'=>'0','user_id'=>'1']] as $change) {
    staff_case('admin_employees.php', array_replace($employee, $change), true); ++$checks;
}
foreach ([['position'=>''], ['position'=>str_repeat('a',101)], ['description'=>str_repeat('a',65536)],
    ['is_active'=>'yes'], ['employee_id'=>'1x'], ['user_id'=>'-1'], ['service_ids'=>['1x']],
    ['service_ids'=>['0']], ['service_ids'=>'1']] as $change) {
    staff_case('admin_employees.php', array_replace($employee, $change), false); ++$checks;
}
staff_case('admin_employees.php', $employee, false, 0, true); ++$checks;
staff_case('admin_employees.php', ['action'=>'toggle','employee_id'=>'1x'], false); ++$checks;
staff_case('admin_availability.php', ['action'=>'delete','availability_id'=>'1x'], false); ++$checks;
$day = ['action'=>'day_off_add','employee_id'=>'1','day_off_date'=>date('Y-m-d', strtotime('+7 days')),'reason'=>'Wolne'];
foreach ([[], ['action'=>'closure_add']] as $change) {
    staff_case('admin_availability.php', array_replace($day, $change), true); ++$checks;
}
foreach ([['day_off_date'=>'2027-02-30'], ['day_off_date'=>'2000-01-01'], ['employee_id'=>'2'],
    ['reason'=>str_repeat('a',256)], ['action'=>'closure_add','reason'=>''],
    ['action'=>'closure_add','reason'=>str_repeat('a',151)], ['action'=>'leave_review','leave_request_id'=>'1','status'=>'invalid']] as $change) {
    staff_case('admin_availability.php', array_replace($day, $change), false); ++$checks;
}
$leave = ['action'=>'create','start_date'=>date('Y-m-d', strtotime('+7 days')),
    'end_date'=>date('Y-m-d', strtotime('+8 days')),'reason'=>'Urlop'];
staff_case('employee_leave.php', $leave, true); ++$checks;
foreach ([['start_date'=>'2030-02-30'], ['end_date'=>'2030-02-30'], ['start_date'=>'2000-01-01'],
    ['end_date'=>date('Y-m-d')], ['reason'=>str_repeat('a',65536)]] as $change) {
    staff_case('employee_leave.php', array_replace($leave,$change), false); ++$checks;
}
staff_case('employee_leave.php', $leave, false, 1); ++$checks;
staff_case('employee_leave.php', $leave, false, 0, true); ++$checks;
staff_case('employee_leave.php', ['action'=>'cancel','request_id'=>'1x'], false); ++$checks;
echo "Walidacja formularzy (atrapa PDO): $checks kontroli OK.\n";
