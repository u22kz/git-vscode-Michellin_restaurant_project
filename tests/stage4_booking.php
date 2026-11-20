<?php
/**
 * Testy integracyjne HTTP na kopii aplikacji w katalogu tymczasowym.
 * Użycie: php tests/stage4_booking.php /sciezka/do/test_credentials.php
 * Konfiguracja musi wskazywać pustą lokalną bazę michelin_test_*.
 * Test importuje SQL, modyfikuje tylko dane testowe i pozostawia bazę do inspekcji.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if ($argc !== 2 || !is_file($argv[1])) {
    fwrite(STDERR, "Użycie: php tests/stage4_booking.php /sciezka/do/test_credentials.php\n");
    exit(1);
}
$config = require $argv[1];
if (!is_array($config) || !is_string($config['dbname'] ?? null)
    || !preg_match('/\Amichelin_test_[a-z0-9_]+\z/', $config['dbname'])
    || !in_array($config['host'] ?? '', ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Wymagana osobna lokalna baza michelin_test_*.\n");
    exit(1);
}

$checks = 0;
function check_stage4(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($label);
    }
    ++$checks;
    echo "OK: $label\n";
}

final class TestBrowser
{
    public string $cookie = '';
    public string $csrf = '';
    public array $headers = [];
    public function request(string $path, ?array $data = null): array
    {
        global $baseUrl, $testToken;
        $context = stream_context_create(['http' => [
            'method' => $data === null ? 'GET' : 'POST',
            'header' => 'Cookie: ' . $this->cookie . "\r\nContent-Type: application/x-www-form-urlencoded\r\n",
            'content' => $data === null ? '' : http_build_query($data),
            'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 15,
        ]]);
        $body = file_get_contents($baseUrl . '/' . $path, false, $context);
        $this->headers = $http_response_header ?? [];
        if ($body === false || !in_array('X-Michelin-Test-Run: ' . $testToken, $this->headers, true)) {
            throw new RuntimeException('Odpowiedź nie pochodzi z uruchomionej kopii testowej.');
        }
        preg_match('/HTTP\/\S+ (\d+)/', $this->headers[0], $status);
        $location = '';
        foreach ($this->headers as $header) {
            if (preg_match('/^Set-Cookie: (PHPSESSID=[^;]*)/i', $header, $m)) {
                $this->cookie = $m[1];
            }
            if (preg_match('/^Location: (.+)/i', $header, $m)) {
                $location = $m[1];
            }
        }
        if (preg_match('/name="csrf_token" value="([^"]+)"/', $body, $m)) {
            $this->csrf = $m[1];
        }
        return [(int)$status[1], $body, $location];
    }
    public function post(string $path, array $data): array
    {
        return $this->request($path, $data + ['csrf_token' => $this->csrf]);
    }
}

function login_stage4(string $email, string $password, string $role): TestBrowser
{
    $browser = new TestBrowser();
    $browser->request('login.php');
    $oldCookie = $browser->cookie;
    [$status, , $location] = $browser->post('login.php', ['email' => $email, 'password' => $password]);
    check_stage4($status === 302 && $location === "dashboard_$role.php", "Logowanie: $role");
    check_stage4($browser->cookie !== $oldCookie, "Regeneracja ID sesji: $role");
    [$status] = $browser->request($location);
    check_stage4($status === 200, "Panel: $role");
    return $browser;
}

function snapshot_stage4(PDO $pdo): string
{
    $rows = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $rows[$table] = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
    }
    return hash('sha256', serialize($rows));
}

$runtime = null;
$server = null;
$exitCode = 0;
try {
    $pdo = new PDO('mysql:host=' . $config['host'] . ';port=' . ($config['port'] ?? 3306)
        . ';dbname=' . $config['dbname'] . ';charset=utf8mb4', $config['username'], $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    if ((int)$pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn() !== 0) {
        throw new RuntimeException('Baza nie jest pusta. Test nie resetuje istniejącej bazy.');
    }
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/database/database.sql'));
    $fixturePassword = bin2hex(random_bytes(12));
    $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE user_id IN (1, 3, 6)');
    $stmt->execute([password_hash($fixturePassword, PASSWORD_DEFAULT)]);

    $runtime = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'michelin-auth-' . bin2hex(random_bytes(8));
    mkdir($runtime, 0700);
    foreach (['public', 'private', 'sessions'] as $directory) {
        mkdir($runtime . '/' . $directory, 0700);
    }
    foreach (glob(dirname(__DIR__) . '/public/*.php') as $source) {
        copy($source, $runtime . '/public/' . basename($source));
    }
    file_put_contents($runtime . '/private/db_credentials.php', '<?php return ' . var_export($config, true) . ';');
    $testToken = bin2hex(random_bytes(16));
    file_put_contents($runtime . '/router.php', "<?php header('X-Michelin-Test-Run: $testToken'); return false;");
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) {
        throw new RuntimeException('Nie można przydzielić lokalnego portu.');
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $baseUrl = 'http://' . $address;
    $command = [PHP_BINARY];
    if (php_ini_loaded_file()) {
        array_push($command, '-c', php_ini_loaded_file());
    }
    array_push($command, '-d', 'session.save_path="' . str_replace('\\', '/', $runtime) . '/sessions"', '-d', 'display_errors=0',
        '-d', 'log_errors=1', '-S', $address, '-t', $runtime . '/public', $runtime . '/router.php');
    $server = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $runtime . '/server.log', 'a'],
        2 => ['file', $runtime . '/server.log', 'a']], $pipes, $runtime);
    if (!is_resource($server)) {
        throw new RuntimeException('Nie można uruchomić serwera testowego.');
    }
    fclose($pipes[0]);
    for ($i = 0; $i < 50; ++$i) {
        $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($probe) { fclose($probe); break; }
        usleep(100000);
    }

    date_default_timezone_set('Europe/Warsaw');
    $client = login_stage4('jan.kowalski@example.com', $fixturePassword, 'client');
    $admin = login_stage4('adam.zielinski@example.com', $fixturePassword, 'admin');
    $employee = login_stage4('piotr.wisniewski@example.com', $fixturePassword, 'employee');
    $anonymous = new TestBrowser();
    $anonymous->request('login.php');
    $pdo->exec('UPDATE services SET duration_minutes = 60 WHERE service_id = 1');
    $pdo->exec('DELETE FROM employee_availability WHERE employee_id = 1');
    $schedule = $pdo->prepare('INSERT INTO employee_availability (employee_id,day_of_week,start_time,end_time) VALUES (1,?,\'09:00\',\'17:00\')');
    for ($day=1; $day<=7; ++$day) $schedule->execute([$day]);
    $date = date('Y-m-d',strtotime('+21 days'));
    $data = ['action'=>'create','service_id'=>'1','employee_id'=>'1','reservation_date'=>$date,
        'start_time'=>'12:00','guest_count'=>'2','comment'=>'OWN_BOOKING_TEST'];
    $readSlots = static function () use ($client,$date): array {
        [$http,$body]=$client->request('dashboard_client.php?'.http_build_query(['ajax'=>'slots','service_id'=>1,'employee_id'=>1,'date'=>$date]));
        check_stage4($http===200,'Endpoint godzin odpowiada JSON');
        return json_decode($body,true,512,JSON_THROW_ON_ERROR);
    };
    $slots=$readSlots();
    check_stage4(count($slots)===29 && $slots[0]==='09:00' && end($slots)==='16:00','Godziny obejmują grafik i cały czas usługi');
    $reject = static function (array $post, int $expected = 200, bool $csrf = true) use ($client,$pdo): void {
        $before = snapshot_stage4($pdo);
        [$status,$body] = $csrf ? $client->post('dashboard_client.php',$post) : $client->request('dashboard_client.php',$post);
        check_stage4($status === $expected && $before === snapshot_stage4($pdo)
            && ($expected !== 200 || str_contains($body,'alert-danger')), 'Błędne żądanie nie zmienia bazy');
    };
    foreach ([$anonymous,$admin,$employee] as $browser) {
        [$status] = $browser->request('dashboard_client.php');
        check_stage4($status === 302,'Panel tylko dla klienta');
        $before = snapshot_stage4($pdo);
        [$status] = $browser->post('dashboard_client.php',$data);
        check_stage4($status === 302 && $before === snapshot_stage4($pdo),'Nieuprawniony POST bez zapisu');
    }
    $reject($data,403,false);
    $reject($data + ['csrf_token'=>'bad'],403,false);
    foreach ([['service_id'=>'4294967295'],['employee_id'=>'4294967295'],['service_id'=>'4'],
        ['reservation_date'=>'2000-01-01'],['reservation_date'=>'2030-02-30'],['start_time'=>'08:30'],
        ['start_time'=>'16:30'],['start_time'=>'24:00'],['start_time'=>'23:30'],
        ['guest_count'=>'0'],['guest_count'=>'-1'],['guest_count'=>'1.5'],['guest_count'=>'4294967296'],
        ['comment'=>str_repeat('ą',1001)]] as $change) $reject(array_replace($data,$change));
    foreach ([['services','is_available','service_id',1],['employees','is_active','employee_id',1],['users','is_active','user_id',3]] as [$table,$column,$key,$id]) {
        $pdo->exec("UPDATE $table SET $column = 0 WHERE $key = $id");
        check_stage4($readSlots()===[],'Nieaktywna usługa/profil/konto nie ma godzin');
        $reject($data);
        $pdo->exec("UPDATE $table SET $column = 1 WHERE $key = $id");
    }
    $pdo->prepare('INSERT INTO employee_days_off (employee_id,day_off_date) VALUES (1,?)')->execute([$date]);
    check_stage4($readSlots()===[],'Dzień wolny ukrywa godziny');
    $reject($data);
    $pdo->prepare('DELETE FROM employee_days_off WHERE employee_id=1 AND day_off_date=?')->execute([$date]);
    $pdo->prepare("INSERT INTO employee_leave_requests (employee_id,start_date,end_date,status) VALUES (1,?,?,'approved')")->execute([$date,$date]);
    check_stage4($readSlots()===[],'Zatwierdzony urlop ukrywa godziny');
    $reject($data);
    $pdo->prepare('DELETE FROM employee_leave_requests WHERE employee_id=1 AND start_date=?')->execute([$date]);
    $pdo->prepare("INSERT INTO restaurant_days_off (day_off_date,name) VALUES (?,'Test')")->execute([$date]);
    check_stage4($readSlots()===[],'Zamknięcie restauracji ukrywa godziny');
    $reject($data);
    $pdo->prepare('DELETE FROM restaurant_days_off WHERE day_off_date=?')->execute([$date]);
    [$status,,$location] = $client->post('dashboard_client.php',$data + ['user_id'=>'2','duration_minutes'=>'1','end_time'=>'12:01']);
    check_stage4($status===302 && $location==='dashboard_client.php?created=1','Utworzenie rezerwacji i przekierowanie');
    $row = $pdo->query('SELECT * FROM reservations ORDER BY reservation_id DESC LIMIT 1')->fetch();
    check_stage4((int)$row['user_id']===1 && $row['start_time']==='12:00:00' && $row['end_time']==='13:00:00'
        && $row['status']==='pending' && (int)$row['guest_count']===2,'MySQL: właściciel z sesji, czas z usługi, status i goście');
    $ownId = (int)$row['reservation_id'];
    [$status,$body] = $client->request($location);
    check_stage4($status===200 && str_contains($body,'Rezerwacja została złożona'),'Potwierdzenie zapisu');
    check_stage4(str_contains($body,'Pracownik:') && str_contains($body,'Termin:') && str_contains($body,'Status:'),'Szczegóły potwierdzenia');
    $slots=$readSlots();
    check_stage4(!in_array('12:00',$slots,true) && in_array('11:00',$slots,true) && in_array('13:00',$slots,true),'Zajęty przedział ukryty, granice dostępne');
    $reject($data); // Istniejąca kontrola konfliktu nadal blokuje ten sam termin.
    $pdo->prepare("INSERT INTO reservations (user_id,employee_id,service_id,reservation_date,start_time,end_time,guest_count,status,comment) VALUES (2,1,1,?,'14:00','15:00',1,'pending','FOREIGN_BOOKING_TEST')")->execute([$date]);
    $foreignId=(int)$pdo->lastInsertId();
    [, $body] = $client->request('dashboard_client.php');
    check_stage4(str_contains($body,'OWN_BOOKING_TEST') && !str_contains($body,'FOREIGN_BOOKING_TEST'),'Historia zawiera tylko własne rezerwacje');
    $reject(['action'=>'cancel','reservation_id'=>$foreignId,'expected_status'=>'pending','user_id'=>'2']);
    $cancel = ['action'=>'cancel','reservation_id'=>$ownId,'expected_status'=>'pending'];
    $reject($cancel,403,false);
    foreach (['completed','cancelled','no_show'] as $status) {
        $pdo->prepare('UPDATE reservations SET status=? WHERE reservation_id=?')->execute([$status,$ownId]);
        $reject($cancel);
    }
    foreach (['pending','confirmed'] as $status) {
        $pdo->prepare('UPDATE reservations SET status=? WHERE reservation_id=?')->execute([$status,$ownId]);
        [$http,,$location] = $client->post('dashboard_client.php',array_replace($cancel,['expected_status'=>$status]));
        $stmt=$pdo->prepare('SELECT status FROM reservations WHERE reservation_id=?');
        $stmt->execute([$ownId]);
        check_stage4($http===302 && $location==='dashboard_client.php?cancelled=1' && $stmt->fetchColumn()==='cancelled','Anulowanie własnej przyszłej aktywnej rezerwacji');
    }
    $pdo->prepare("UPDATE reservations SET status='pending',reservation_date='2000-01-01' WHERE reservation_id=?")->execute([$ownId]);
    $reject($cancel);
    $log = file_get_contents($runtime . '/server.log');
    check_stage4(!preg_match('/PHP (Warning|Fatal error|Parse error|Deprecated|Notice)|SQLSTATE/', $log),'Brak błędów PHP/SQL');
    echo "Etap 4 — formularz rezerwacji: $checks kontroli OK.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'BŁĄD: ' . $e->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    // Usuwamy tylko własny losowy podkatalog systemowego katalogu tymczasowego.
    if ($runtime !== null && is_dir($runtime)
        && realpath(dirname($runtime)) === realpath(sys_get_temp_dir())
        && preg_match('/\Amichelin-auth-[a-f0-9]{16}\z/', basename($runtime))) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runtime,
            FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); }
            else { unlink($file->getPathname()); }
        }
        rmdir($runtime);
    }
}
exit($exitCode);
