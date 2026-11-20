<?php
/** Test obliczeń endpointu z atrapą PDO. Nie testuje wykonania SQL ani autoryzacji HTTP. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
date_default_timezone_set('Europe/Warsaw');
function require_role(array $roles): void {}
function current_user_id(): int { return 1; }
final class SlotsResponse extends Exception {}
final class SlotsPDO
{
    public function __construct(public array $fixture) {}
    public function prepare(string $sql): object {
        return new class($this->fixture,$sql) {
            public function __construct(private array $fixture, private string $sql) {}
            public function execute(array $params): void {
                if ($this->fixture['fail'] ?? false) throw new PDOException('Test awarii dostępności');
            }
            public function fetchColumn(): int { return $this->fixture['duration'] ?? 60; }
            public function fetchAll(int $mode): array {
                if (str_contains($this->sql,'SELECT DISTINCT')) return $this->fixture['employees'] ?? [['employee_id'=>1,'name'=>'Jan','surname'=>'Test']];
                if (str_contains($this->sql,'FROM employee_availability')) return $this->fixture['windows'] ?? [['start_time'=>'09:00:00','end_time'=>'12:00:00']];
                if (str_contains($this->sql,'FROM reservations')) return $this->fixture['busy'] ?? [];
                throw new LogicException('Nieobsługiwane zapytanie');
            }
        };
    }
}
$source=file_get_contents(__DIR__.'/../public/dashboard_client.php');
$source=substr($source,5,strpos($source,' * Obsługa formularzy POST:')-5);
$source=substr($source,0,strrpos($source,'/*'));
$source=preg_replace('/^require_once .*;\R/m','',$source);
$source=preg_replace('/^\s*header\([^\n]*\);/m','',$source);
$source=preg_replace('/\bexit;/', 'throw new SlotsResponse();',$source);
$source=preg_replace('/filter_input\(INPUT_GET, (\'[^\']+\'), FILTER_VALIDATE_INT\)/','filter_var($_GET[$1] ?? null, FILTER_VALIDATE_INT)',$source);
$checks=0;
function slots_case(array $fixture, array $query, array $expected): void {
    global $source,$checks;
    $_SERVER['REQUEST_METHOD']='GET';
    $_GET=array_replace(['ajax'=>'slots','service_id'=>'1','employee_id'=>'1','date'=>'2030-05-06'],$query);
    $_SESSION=['reservation_client_success'=>'created','reservation_created_id'=>42];
    $pdo=new SlotsPDO($fixture);
    ob_start();
    try { eval($source); } catch (SlotsResponse $e) {}
    $actual=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
    if ($actual!==$expected) throw new RuntimeException('Nieprawidłowe godziny: '.json_encode([$fixture,$query,$actual,$expected]));
    if ($_SESSION['reservation_client_success']!=='created') throw new RuntimeException('AJAX zużył potwierdzenie');
    ++$checks;
}
slots_case([],[],['09:00','09:15','09:30','09:45','10:00','10:15','10:30','10:45','11:00']);
slots_case(['busy'=>[['start_time'=>'10:00:00','end_time'=>'11:00:00']]],[],['09:00','11:00']);
slots_case(['busy'=>[['start_time'=>'09:30:00','end_time'=>'11:30:00']]],[],[]);
slots_case(['duration'=>120],[],['09:00','09:15','09:30','09:45','10:00']);
slots_case(['duration'=>180],[],['09:00']);
slots_case(['duration'=>181],[],[]);
slots_case(['duration'=>0],[],[]);
slots_case(['windows'=>[]],[],[]);
slots_case(['employees'=>[]],[],[]);
slots_case([],['employee_id'=>'2'],[]);
slots_case([],['employee_id'=>'bad'],[]);
slots_case([],['service_id'=>'-1'],[]);
slots_case([],['date'=>'2000-01-01'],[]);
slots_case([],['date'=>'2030-02-30'],[]);
slots_case(['windows'=>[['start_time'=>'09:07:00','end_time'=>'10:15:00']]],[],['09:15']);
slots_case(['windows'=>[['start_time'=>'09:00:01','end_time'=>'10:00:00']]],[],[]);
slots_case(['windows'=>[['start_time'=>'09:00:00','end_time'=>'10:00:00'],['start_time'=>'11:00:00','end_time'=>'12:00:00']]],[],['09:00','11:00']);
slots_case(['windows'=>[['start_time'=>'09:00:00','end_time'=>'10:00:00'],['start_time'=>'09:00:00','end_time'=>'10:00:00']]],[],['09:00']);
slots_case(['windows'=>[['start_time'=>'23:00:00','end_time'=>'23:59:59']]],[],[]);
slots_case(['windows'=>[['start_time'=>'02:00:00','end_time'=>'03:00:00']]],['date'=>'2030-03-31'],[]);
slots_case(['fail'=>true],[],['error'=>'Nie udało się pobrać dostępności.']);
echo "Wolne godziny (atrapa PDO): $checks kontroli OK.\n";
