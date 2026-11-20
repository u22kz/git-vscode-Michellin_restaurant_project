<?php
/** Test funkcji zmiany statusu z atrapą PDO. Nie wykonuje zapytań SQL. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
date_default_timezone_set('Europe/Warsaw');
require __DIR__.'/../public/reservation_status.php';
final class StatusPDO extends PDO
{
    public bool $transaction = false;
    public bool $committed = false;
    public bool $rolledBack = false;
    public array $queries = [];
    public array $writes = [];
    public function __construct(public array $fixture = []) {}
    public function beginTransaction(): bool { $this->transaction = true; return true; }
    public function inTransaction(): bool { return $this->transaction; }
    public function commit(): bool { $this->transaction = false; return $this->committed = true; }
    public function rollBack(): bool { $this->transaction = false; $this->writes = []; return $this->rolledBack = true; }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new StatusStatement($this,$query);
    }
}
final class StatusStatement extends PDOStatement
{
    public function __construct(private StatusPDO $db, private string $sql) {}
    public function execute(?array $params = null): bool {
        $this->db->queries[] = [$this->sql,$params];
        if (str_starts_with($this->sql,'UPDATE')) {
            if ($this->db->fixture['fail'] ?? false) throw new PDOException('Test awarii');
            $this->db->writes[] = $params;
        }
        return true;
    }
    public function rowCount(): int { return $this->db->fixture['affected'] ?? 1; }
    public function fetchColumn(int $column = 0): mixed { return $this->db->fixture['employee'] ?? 1; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        if (str_contains($this->sql,'SELECT * FROM reservations')) {
            return ['reservation_id'=>7, 'employee_id'=>$this->db->fixture['changed_employee'] ?? 1,
                'service_id'=>1, 'status'=>$this->db->fixture['old_status'] ?? 'pending',
                'reservation_date'=>$this->db->fixture['date'] ?? '2099-01-01',
                'start_time'=>'10:00:00','end_time'=>$this->db->fixture['end'] ?? '11:00:00'];
        }
        if (str_contains($this->sql,'SELECT reservation_id FROM reservations')) {
            return ($this->db->fixture['conflict'] ?? false) ? ['reservation_id'=>8] : false;
        }
        if (str_contains($this->sql,'JOIN users')) return ($this->db->fixture['available'] ?? true) ? ['employee_id'=>1] : false;
        return ['employee_id'=>1];
    }
}
$checks = 0;
$statuses = ['pending','confirmed','cancelled','completed','no_show'];
$cases = [];
$validEdges = ['pending:confirmed','pending:cancelled','confirmed:cancelled','confirmed:completed','confirmed:no_show'];
foreach ($statuses as $old) foreach ($statuses as $new) {
    $cases[] = [['old_status'=>$old], $new, 1, in_array($old.':'.$new,$validEdges,true)];
}
$cases = array_merge($cases, [
    [['conflict'=>true], 'confirmed', null, false],
    [['employee'=>0], 'confirmed', null, false], [[], 'confirmed', 2, false],
    [['changed_employee'=>2], 'confirmed', null, false], [['date'=>'2000-01-01'], 'confirmed', null, false],
    [['end'=>'09:00:00'], 'confirmed', null, false], [['fail'=>true], 'confirmed', null, false],
    [['old_status'=>'confirmed','expected_status'=>'pending'], 'cancelled', null, false],
    [['expected_status'=>''], 'confirmed', null, false], [['affected'=>0], 'confirmed', null, false],
    [[], 'invalid', null, false],
]);
foreach ($cases as [$fixture,$status,$owner,$expected]) {
    $pdo = new StatusPDO($fixture);
    $success = false;
    try { change_reservation_status($pdo,7,$status,$fixture['expected_status'] ?? ($fixture['old_status'] ?? 'pending'),$owner); $success = true; }
    catch (RuntimeException $e) {}
    if ($success !== $expected || $pdo->transaction || ($expected && (!$pdo->committed || count($pdo->writes)!==1))
        || (!$expected && ($pdo->writes || $pdo->committed))) throw new RuntimeException('Nieprawidłowy wynik scenariusza: '.json_encode([$fixture,$status]));
    ++$checks;
    if ($expected) {
        $queries = array_column($pdo->queries,0);
        if (!str_contains($queries[1],'FROM employees') || !str_contains($queries[1],'FOR UPDATE')
            || !str_contains($queries[2],'FROM reservations') || !str_contains($queries[2],'FOR UPDATE')
            || !str_contains(end($queries),'AND status = ?')) {
            throw new RuntimeException('Nieprawidłowa kolejność blokad lub brak warunku statusu');
        }
        ++$checks;
    }
    if (!$expected && count($pdo->queries)>1 && !$pdo->rolledBack) throw new RuntimeException('Brak rollback');
}
echo "Zmiany statusu (atrapa PDO): $checks kontroli OK.\n";