<?php
/**
 * Test kodu PHP formularza bez bazy: PDO zastąpione atrapą, funkcje CSRF/sesji prawdziwe.
 * Nie potwierdza wykonania SQL, blokad InnoDB ani trwałego zapisu.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = $_POST = [];
ini_set('session.save_path', sys_get_temp_dir());
require __DIR__ . '/../public/auth_check.php';
$_SESSION = ['user_id'=>11, 'role'=>'client', 'csrf_token'=>'test-token'];
final class ReservationSaved extends Exception {}
final class ReservationPDO
{
    public bool $transaction = false;
    public int $commits = 0;
    public array $inserts = [];
    public array $queries = [];
    public function __construct(public array $fixture = []) {}
    public function beginTransaction(): void { $this->transaction = true; }
    public function lastInsertId(): string { return '42'; }
    public function inTransaction(): bool { return $this->transaction; }
    public function commit(): void { ++$this->commits; $this->transaction = false; }
    public function rollBack(): void { $this->transaction = false; $this->inserts = []; }
    public function prepare(string $sql): object {
        return new class($this, $sql) {
            public function __construct(private ReservationPDO $db, private string $sql) {}
            public function execute(array $params = []): void {
                $this->db->queries[] = [$this->sql, $params];
                if (str_contains($this->sql, 'INSERT INTO reservations')) {
                    if ($this->db->fixture['fail'] ?? false) throw new PDOException('Test awarii');
                    $this->db->inserts[] = $params;
                }
            }
            public function fetch(int $mode = 0): array|false {
                if (str_contains($this->sql, 'FROM services')) {
                    return ($this->db->fixture['service'] ?? true)
                        ? ['service_id'=>1,'duration_minutes'=>$this->db->fixture['duration'] ?? 60] : false;
                }
                if (str_contains($this->sql, 'FROM employees')) {
                    return ($this->db->fixture['employee'] ?? true) ? ['employee_id'=>2] : false;
                }
                if (str_contains($this->sql, 'FROM reservations')) {
                    return ($this->db->fixture['conflict'] ?? false) ? ['reservation_id'=>1] : false;
                }
                throw new LogicException('Nieobsługiwany fetch');
            }
            public function fetchColumn(): int {
                foreach (['employee_services'=>'assigned','employee_availability'=>'schedule',
                    'employee_days_off'=>'day_off','employee_leave_requests'=>'leave','restaurant_days_off'=>'closed'] as $table=>$key) {
                    if (str_contains($this->sql, 'FROM '.$table)) {
                        return (int)($this->db->fixture[$key] ?? in_array($key,['assigned','schedule'],true));
                    }
                }
                throw new LogicException('Nieobsługiwany count');
            }
            public function fetchAll(int $mode = 0): array { return []; }
            public function rowCount(): int { return (int)($this->db->fixture['cancelled'] ?? 0); }
        };
    }
}
$source = file_get_contents(__DIR__ . '/../public/dashboard_client.php');
$source = substr($source, 5, strpos($source, '?>') - 5);
$source = preg_replace('/^require_once .*;\R/m', '', $source);
$source = preg_replace('/^\s*header\([^\n]*\);/m', '', $source);
$source = preg_replace('/\bexit;/', 'throw new ReservationSaved();', $source);
// CLI nie ma wejścia HTTP filter_input; wstrzykujemy identyczną walidację danych testowych.
$source = preg_replace('/filter_input\(INPUT_POST, (\'[^\']+\'), FILTER_VALIDATE_INT\)/',
    'filter_var($_POST[$1] ?? null, FILTER_VALIDATE_INT)', $source);
$checks = 0;
function booking_case(array $post, array $fixture, bool $accepted): ReservationPDO {
    global $source, $checks;
    $_POST = $post;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $pdo = new ReservationPDO($fixture);
    $saved = false;
    ob_start();
    try { eval($source); } catch (ReservationSaved $e) { $saved = true; }
    finally { ob_end_clean(); }
    if ($saved !== $accepted || $pdo->transaction || (!$accepted && (empty($errors) || $pdo->inserts))
        || ($accepted && ($post['action'] ?? 'create') === 'create' && ($pdo->commits !== 1 || count($pdo->inserts) !== 1))) {
        throw new RuntimeException('Nieprawidłowy wynik scenariusza: '.json_encode([$post,$fixture]));
    }
    ++$checks;
    return $pdo;
}
$valid = ['action'=>'create','service_id'=>'1','employee_id'=>'2',
    'reservation_date'=>date('Y-m-d',strtotime('+14 days')),'start_time'=>'12:00',
    'guest_count'=>'2','comment'=>'Kolacja','csrf_token'=>'test-token'];
try {
    $db = booking_case($valid + ['user_id'=>'999','duration_minutes'=>'1','end_time'=>'12:01'], [], true);
    $row = $db->inserts[0];
    if ($row['user_id'] !== 11 || $row['start_time'] !== '12:00:00' || $row['end_time'] !== '13:00:00') {
        throw new RuntimeException('Dane POST zmieniły właściciela lub czas usługi.');
    }
    ++$checks;
    foreach ([['guest_count'=>'0'], ['guest_count'=>'-1'], ['guest_count'=>'1.5'], ['guest_count'=>'abc'],
        ['guest_count'=>'4294967296'], ['service_id'=>'0'], ['service_id'=>'1x'], ['employee_id'=>'-1'],
        ['reservation_date'=>'2000-01-01'], ['reservation_date'=>'2030-02-30'], ['reservation_date'=>'2030-2-01'],
        ['start_time'=>'24:00'], ['start_time'=>'12:60'], ['start_time'=>'9:00'],
        ['comment'=>str_repeat('ą',1001)], ['csrf_token'=>''], ['csrf_token'=>'bad'], ['start_time'=>'23:30'],
        ['reservation_date'=>'2030-03-31','start_time'=>'02:30'],
        ['reservation_date'=>'2030-03-31','start_time'=>'01:30']] as $change) {
        booking_case(array_replace($valid,$change), [], false);
    }
    $withoutCsrf = $valid; unset($withoutCsrf['csrf_token']);
    booking_case($withoutCsrf, [], false);
    booking_case(array_replace($valid,['comment'=>str_repeat('ą',1000),'guest_count'=>'4294967295']), [], true);
    foreach ([['service'=>false], ['employee'=>false], ['assigned'=>0], ['schedule'=>0],
        ['day_off'=>1], ['leave'=>1], ['closed'=>1], ['conflict'=>true], ['duration'=>0], ['fail'=>true]] as $fixture) {
        booking_case($valid, $fixture, false);
    }
    $cancel = ['action'=>'cancel','reservation_id'=>'7','expected_status'=>'pending','user_id'=>'999','csrf_token'=>'test-token'];
    booking_case($cancel, ['cancelled'=>0], false);
    $db = booking_case($cancel, ['cancelled'=>1], true);
    [$sql,$params] = $db->queries[0];
    if ($params['user_id'] !== 11 || !str_contains($sql,'AND user_id = :user_id')
        || !str_contains($sql,"status IN ('pending', 'confirmed')") || !str_contains($sql,'start_time > CURTIME()')) {
        throw new RuntimeException('Anulowanie nie ogranicza właściciela, statusu lub czasu.');
    }
    ++$checks;
    booking_case(array_replace($cancel,['csrf_token'=>'']), [], false);
    booking_case(array_replace($cancel,['reservation_id'=>'7x']), [], false);
    echo "Rezerwacje — logika PHP z atrapą PDO: $checks kontroli OK.\n";
} finally {
    $_SESSION = [];
    session_destroy();
}
