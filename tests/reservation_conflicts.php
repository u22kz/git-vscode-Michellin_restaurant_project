<?php
/**
 * Testy integracyjne HTTP na kopii aplikacji w katalogu tymczasowym.
 * Użycie: php tests/reservation_conflicts.php /sciezka/do/test_credentials.php
 * Konfiguracja musi wskazywać pustą lokalną bazę michelin_test_*.
 * Test importuje SQL, modyfikuje tylko dane testowe i pozostawia bazę do inspekcji.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if ($argc !== 2 || !is_file($argv[1])) {
    fwrite(STDERR, "Użycie: php tests/reservation_conflicts.php /sciezka/do/test_credentials.php\n");
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
$serverB = null;
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
    $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE user_id IN (1, 2, 3, 6)');
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
    $engines = $pdo->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('employees','reservations','services')")->fetchAll(PDO::FETCH_KEY_PAIR);
    check_stage4(count($engines)===3 && count(array_filter($engines,static fn($engine)=>$engine==='InnoDB'))===3,'Tabele mechanizmu rezerwacji używają InnoDB');
    // Drugi niezależny proces PHP: pojedynczy php -S serializowałby wszystkie żądania.
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException('Nie można przydzielić drugiego portu.');
    $addressB = stream_socket_get_name($socket,false);
    fclose($socket);
    $commandB = $command;
    $commandB[array_search('-S',$commandB,true)+1] = $addressB;
    $serverB = proc_open($commandB,[0=>['pipe','r'],1=>['file',$runtime.'/serverB.log','a'],2=>['file',$runtime.'/serverB.log','a']],$pipesB,$runtime);
    if (!is_resource($serverB)) throw new RuntimeException('Nie można uruchomić drugiego serwera.');
    fclose($pipesB[0]);
    for ($i=0; $i<50; ++$i) {
        $probe = @stream_socket_client('tcp://'.$addressB,$errno,$error,0.1);
        if ($probe) { fclose($probe); break; }
        usleep(100000);
    }
    // Bariera tylko w tymczasowych kopiach: sygnalizacja tuż przed blokadą pracownika.
    $hook = 'if (is_file(__DIR__ . "/../barrier")) { file_put_contents(__DIR__ . "/../ready-" . current_user_id(), "ready"); }' . "\n";
    foreach (['dashboard_client.php'=>"\$stmt->execute(['employee_id' => \$employee_id]);", 'reservation_status.php'=>'$stmt->execute([$employeeId]);'] as $file=>$needle) {
        $copy = file_get_contents($runtime.'/public/'.$file);
        if (substr_count($copy,$needle)!==1) throw new RuntimeException('Nie znaleziono jednoznacznego punktu synchronizacji.');
        file_put_contents($runtime.'/public/'.$file,str_replace($needle,$hook.$needle,$copy));
    }
    $clientA = login_stage4('jan.kowalski@example.com',$fixturePassword,'client');
    $clientB = login_stage4('anna.nowak@example.com',$fixturePassword,'client');
    $admin = login_stage4('adam.zielinski@example.com',$fixturePassword,'admin');
    $employee = login_stage4('piotr.wisniewski@example.com',$fixturePassword,'employee');
    $pdo->exec('UPDATE services SET duration_minutes=60 WHERE service_id=1');
    $pdo->exec('UPDATE services SET duration_minutes=30 WHERE service_id=2');
    $pdo->exec('UPDATE services SET duration_minutes=120 WHERE service_id=3');
    $pdo->exec('INSERT INTO employee_services (employee_id,service_id) VALUES (2,1)');
    $pdo->exec('DELETE FROM employee_availability WHERE employee_id IN (1,2)');
    $stmt=$pdo->prepare("INSERT INTO employee_availability (employee_id,day_of_week,start_time,end_time) VALUES (?,?,'09:00','17:00')");
    foreach ([1,2] as $id) for ($day=1;$day<=7;++$day) $stmt->execute([$id,$day]);
    $date=date('Y-m-d',strtotime('+40 days'));
    $data=['action'=>'create','service_id'=>'1','employee_id'=>'1','reservation_date'=>$date,'start_time'=>'10:00','guest_count'=>'2'];
    $seed=$pdo->prepare("INSERT INTO reservations (user_id,employee_id,service_id,reservation_date,start_time,end_time,guest_count,status) VALUES (1,1,1,?,'10:00','11:00',1,'pending')");
    $seed->execute([$date]);
    $baseId=(int)$pdo->lastInsertId();
    $deleteNew=static function() use($pdo,$date,$baseId): void {
        $pdo->prepare('DELETE FROM reservations WHERE reservation_date=? AND reservation_id<>?')->execute([$date,$baseId]);
    };
    foreach ([['09:30',1,false],['10:00',1,false],['10:15',2,false],['10:30',1,false],['09:30',3,false],
        ['09:00',1,true],['11:00',1,true],['16:00',1,true],['16:30',1,false],['08:30',1,false]] as [$time,$service,$accepted]) {
        $before=snapshot_stage4($pdo);
        [$http,$body,$location]=$clientA->post('dashboard_client.php',array_replace($data,['start_time'=>$time,'service_id'=>$service,'end_time'=>'23:00']));
        check_stage4($accepted ? ($http===302 && $location==='dashboard_client.php?created=1')
            : ($http===200 && str_contains($body,'alert-danger') && $before===snapshot_stage4($pdo)),"Przedział $time / usługa $service");
        if ($accepted) $deleteNew();
    }
    [$http]=$clientA->post('dashboard_client.php',array_replace($data,['employee_id'=>'2']));
    check_stage4($http===302,'Ten sam czas u innego pracownika');
    $deleteNew();
    $otherDate=date('Y-m-d',strtotime('+41 days'));
    [$http]=$clientA->post('dashboard_client.php',array_replace($data,['reservation_date'=>$otherDate]));
    check_stage4($http===302,'Ten sam czas w innym dniu');
    $pdo->prepare('DELETE FROM reservations WHERE reservation_date=?')->execute([$otherDate]);
    [$http,$body]=$clientA->post('dashboard_client.php',array_replace($data,['reservation_date'=>'2000-01-01']));
    check_stage4($http===200 && str_contains($body,'przeszłości'),'Termin przeszły odrzucony');
    foreach (['confirmed'=>false,'cancelled'=>true,'completed'=>true,'no_show'=>true] as $status=>$accepted) {
        $pdo->prepare('UPDATE reservations SET status=? WHERE reservation_id=?')->execute([$status,$baseId]);
        [$http,$body]=$clientA->post('dashboard_client.php',$data);
        check_stage4($accepted ? $http===302 : ($http===200 && str_contains($body,'już zajęty')),"Blokowanie przez status $status");
        if ($accepted) $deleteNew();
    }
    $pdo->prepare("UPDATE reservations SET status='cancelled' WHERE reservation_id=?")->execute([$baseId]);
    $clientA->post('dashboard_client.php',$data);
    foreach ([[$admin,'admin_reservations.php'],[$employee,'dashboard_employee.php']] as [$browser,$page]) {
        foreach (['pending','confirmed'] as $status) {
            $before=snapshot_stage4($pdo);
            [$http,$body]=$browser->post($page,['action'=>'change_status','reservation_id'=>$baseId,'status'=>$status,'expected_status'=>'cancelled']);
            check_stage4($http===200 && str_contains($body,'Niedozwolona zmiana') && $before===snapshot_stage4($pdo),"Przywrócenie blokowane: $page / $status");
        }
    }
    $deleteNew();
    [$http,$body]=$admin->post('admin_reservations.php',['action'=>'change_status','reservation_id'=>$baseId,'status'=>'confirmed','expected_status'=>'cancelled']);
    check_stage4($http===200 && str_contains($body,'Niedozwolona zmiana'),'Status końcowy nie wraca nawet przy wolnym terminie');
    $pdo->prepare('DELETE FROM reservations WHERE reservation_id=?')->execute([$baseId]);

    function open_post(string $address, TestBrowser $browser, string $path, array $post) {
        $socket=stream_socket_client('tcp://'.$address,$errno,$error,5);
        if (!$socket) throw new RuntimeException('Błąd połączenia HTTP');
        $body=http_build_query($post+['csrf_token'=>$browser->csrf]);
        $request="POST /$path HTTP/1.1\r\nHost: $address\r\nCookie: {$browser->cookie}\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body;
        $offset=0;
        while ($offset<strlen($request)) {
            $written=fwrite($socket,substr($request,$offset));
            if (!$written) throw new RuntimeException('Nie wysłano żądania HTTP');
            $offset+=$written;
        }
        stream_set_timeout($socket,15);
        return $socket;
    }
    $race=static function(TestBrowser $a, TestBrowser $b, string $pathB, array $postB, array $readyIds,
        string $pathA='dashboard_client.php', ?array $postA=null, string $loss='Ten termin jest już zajęty', bool $checkActiveCount=true) use($pdo,$runtime,$address,$addressB,$data,$date,$testToken): void {
        foreach (glob($runtime.'/ready-*') as $file) unlink($file);
        file_put_contents($runtime.'/barrier','on');
        $sockets=[];
        try {
            $pdo->beginTransaction();
            $pdo->query('SELECT employee_id FROM employees WHERE employee_id=1 FOR UPDATE')->fetchAll();
            $sockets[]=open_post($address,$a,$pathA,$postA ?? $data);
            $sockets[]=open_post($addressB,$b,$pathB,$postB);
            $deadline=microtime(true)+8;
            do {
                clearstatcache();
                $ready=count(array_filter($readyIds,static fn($id)=>is_file($runtime.'/ready-'.$id)))===count($readyIds);
                if ($ready) break;
                usleep(20000);
            } while (microtime(true)<$deadline);
            if (!$ready) throw new RuntimeException('Oba żądania nie dotarły do bariery — test nie potwierdza współbieżności.');
            $pdo->commit();
            $responses=[];
            foreach ($sockets as $socket) {
                $response=stream_get_contents($socket);
                if (stream_get_meta_data($socket)['timed_out']) throw new RuntimeException('Timeout HTTP');
                if (!str_contains($response,'X-Michelin-Test-Run: '.$testToken)) throw new RuntimeException('Obca odpowiedź HTTP');
                $responses[]=$response;
            }
            $wins=count(array_filter($responses,static fn($r)=>preg_match('/^HTTP\/\S+ 302 /',$r)));
            $losses=count(array_filter($responses,static fn($r)=>preg_match('/^HTTP\/\S+ 200 /',$r) && str_contains($r,$loss)));
            check_stage4($wins===1 && $losses===1,'Równoległe HTTP: jeden sukces i jedna odmowa');
            $stmt=$pdo->prepare("SELECT COUNT(*) FROM reservations WHERE employee_id=1 AND reservation_date=? AND status IN ('pending','confirmed')");
            $stmt->execute([$date]);
            $active=(int)$stmt->fetchColumn();
            if ($checkActiveCount) check_stage4($active===1,'MySQL: dokładnie jedna aktywna rezerwacja po wyścigu');
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($sockets as $socket) fclose($socket);
            if (is_file($runtime.'/barrier')) unlink($runtime.'/barrier');
        }
    };
    for ($iteration=1;$iteration<=3;++$iteration) {
        $race($clientA,$clientB,'dashboard_client.php',$data,[1,2]);
        $pdo->prepare('DELETE FROM reservations WHERE reservation_date=?')->execute([$date]);
    }
    // Podwójny klik: ten sam cookie. PHP blokuje tę sesję, baza nadal odrzuca drugi POST.
    $race($clientA,$clientA,'dashboard_client.php',$data,[1]);
    $before=snapshot_stage4($pdo);
    $clientA->request('dashboard_client.php?created=1');
    $clientA->request('dashboard_client.php?created=1');
    check_stage4($before===snapshot_stage4($pdo),'Odświeżenie strony po POST/Redirect/GET nie tworzy rezerwacji');
    $pdo->prepare('DELETE FROM reservations WHERE reservation_date=?')->execute([$date]);
    $seed->execute([$date]);
    $statusId=(int)$pdo->lastInsertId();
    $confirm=['action'=>'change_status','reservation_id'=>$statusId,'status'=>'confirmed','expected_status'=>'pending'];
    $cancel=array_replace($confirm,['status'=>'cancelled']);
    $race($admin,$employee,'dashboard_employee.php',$cancel,[6,3],'admin_reservations.php',$confirm,
        'Status rezerwacji został zmieniony',false);
    $stmt=$pdo->prepare('SELECT status FROM reservations WHERE reservation_id=?');
    $stmt->execute([$statusId]);
    check_stage4(in_array($stmt->fetchColumn(),['confirmed','cancelled'],true),'W bazie pozostał status zwycięskiego żądania');
    // Filtrowanie i paginacja: 22 własne wpisy oraz jeden innego pracownika/klienta.
    $from=date('Y-m-d',strtotime('+100 days'));
    $to=date('Y-m-d',strtotime('+121 days'));
    $insert=$pdo->prepare("INSERT INTO reservations (user_id,employee_id,service_id,reservation_date,start_time,end_time,guest_count,status,comment) VALUES (?,?,1,?,'14:00','15:00',1,'pending',?)");
    $filterIds=[];
    for ($i=0;$i<22;++$i) {
        $insert->execute([1,1,date('Y-m-d',strtotime('+'.(100+$i).' days')),'STATUS_FILTER_'.$i]);
        $filterIds[]=(int)$pdo->lastInsertId();
    }
    $insert->execute([2,2,$from,'FOREIGN_STATUS_FILTER']);
    $foreignId=(int)$pdo->lastInsertId();
    $filter='admin_reservations.php?'.http_build_query(['date_from'=>$from,'date_to'=>$to,'employee_id'=>1,'status'=>'pending']);
    [, $body]=$admin->request($filter.'&page=1');
    preg_match_all('/name="reservation_id"\s+value="(\d+)"/',$body,$idsPage1);
    [, $body2]=$admin->request($filter.'&page=2');
    preg_match_all('/name="reservation_id"\s+value="(\d+)"/',$body2,$idsPage2);
    check_stage4(count($idsPage1[1])===20 && count($idsPage2[1])===2 && !array_intersect($idsPage1[1],$idsPage2[1]),'Filtry status/data/pracownik i rozłączne strony 20+2');
    check_stage4(!str_contains($body.$body2,'FOREIGN_STATUS_FILTER') && str_contains($body,'employee_id=1'),'Filtr pracownika i zachowanie parametrów w paginacji');
    [, $last]=$admin->request($filter.'&page=999');
    preg_match_all('/name="reservation_id"\s+value="(\d+)"/',$last,$idsLast);
    check_stage4($idsLast[1]===$idsPage2[1],'Numer spoza zakresu wskazuje ostatnią stronę');
    [, $all]=$admin->request('admin_reservations.php?'.http_build_query(['date_from'=>$from,'date_to'=>$from]));
    check_stage4(str_contains($all,'STATUS_FILTER_0') && str_contains($all,'FOREIGN_STATUS_FILTER'),'Administrator widzi wszystkich pracowników i klientów');
    [, $own]=$employee->request('dashboard_employee.php');
    check_stage4(!str_contains($own,'name="reservation_id" value="'.$foreignId.'"') && str_contains($own,'name="reservation_id" value="'.$filterIds[0].'"'),'Pracownik widzi wyłącznie rezerwacje swojego employee_id');
    [, $own]=$clientA->request('dashboard_client.php');
    check_stage4(!str_contains($own,'FOREIGN_STATUS_FILTER') && str_contains($own,'STATUS_FILTER_0'),'Klient widzi wyłącznie własne rezerwacje');
    [$http]=$clientA->request('admin_reservations.php');
    check_stage4($http===302,'Klient nie ma dostępu do panelu administratora');
    $change=['action'=>'change_status','reservation_id'=>$filterIds[0],'status'=>'confirmed','expected_status'=>'pending'];
    foreach ([[$admin,'admin_reservations.php'],[$employee,'dashboard_employee.php']] as [$browser,$path]) {
        $before=snapshot_stage4($pdo);
        [$http]=$browser->request($path,$change+['csrf_token'=>'bad']);
        check_stage4($http===403 && $before===snapshot_stage4($pdo),'Błędny CSRF blokuje zmianę statusu');
    }
    $before=snapshot_stage4($pdo);
    [$http]=$employee->post('dashboard_employee.php',array_replace($change,['reservation_id'=>$foreignId,'employee_id'=>2]));
    check_stage4($http===200 && $before===snapshot_stage4($pdo),'POST employee_id nie pozwala zmienić cudzej rezerwacji');
    $pdo->exec('UPDATE employees SET is_active=0 WHERE employee_id=1');
    $before=snapshot_stage4($pdo);
    $employee->post('dashboard_employee.php',$change);
    check_stage4($before===snapshot_stage4($pdo),'Nieaktywny profil pracownika nie zmienia statusu');
    $pdo->exec('UPDATE employees SET is_active=1 WHERE employee_id=1');
    [$http,,$location]=$admin->post('admin_reservations.php',$change);
    check_stage4($http===302,'Poprawne potwierdzenie pending → confirmed');
    [, $body]=$admin->request($location);
    check_stage4(str_contains($body,'Status rezerwacji został zaktualizowany.'),'Komunikat po rzeczywistej zmianie');
    [, $body]=$admin->request($location);
    check_stage4(!str_contains($body,'Status rezerwacji został zaktualizowany.'),'Samo updated=1 nie tworzy sukcesu');
    [, $body]=$admin->request('admin_reservations.php?'.http_build_query(['date_from'=>$from,'date_to'=>$to,'employee_id'=>1,'status'=>'confirmed']));
    check_stage4(str_contains($body,'STATUS_FILTER_0') && !str_contains($body,'STATUS_FILTER_1'),'Filtr statusu confirmed');
    $before=snapshot_stage4($pdo);
    $admin->post('admin_reservations.php',array_replace($change,['status'=>'cancelled']));
    check_stage4($before===snapshot_stage4($pdo),'Stary expected_status nie nadpisuje nowego statusu');
    [$http]=$employee->post('dashboard_employee.php',array_replace($change,['status'=>'cancelled','expected_status'=>'confirmed']));
    check_stage4($http===302,'Poprawne anulowanie confirmed → cancelled');
    foreach (['cancelled','completed','no_show'] as $terminal) {
        $pdo->prepare('UPDATE reservations SET status=? WHERE reservation_id=?')->execute([$terminal,$filterIds[0]]);
        $before=snapshot_stage4($pdo);
        $admin->post('admin_reservations.php',array_replace($change,['expected_status'=>$terminal]));
        check_stage4($before===snapshot_stage4($pdo),'Status końcowy nie wraca do confirmed: '.$terminal);
    }
    $collisions=$pdo->query("SELECT COUNT(*) FROM reservations a JOIN reservations b ON a.reservation_id<b.reservation_id
        AND a.employee_id=b.employee_id AND a.reservation_date=b.reservation_date
        AND a.start_time<b.end_time AND a.end_time>b.start_time
        WHERE a.status IN ('pending','confirmed') AND b.status IN ('pending','confirmed')")->fetchColumn();
    check_stage4((int)$collisions===0,'Brak par aktywnych kolidujących rezerwacji w bazie');
    $log=file_get_contents($runtime.'/server.log').file_get_contents($runtime.'/serverB.log');
    check_stage4(!preg_match('/PHP (Warning|Fatal error|Parse error|Deprecated|Notice)|SQLSTATE/',$log),'Brak błędów PHP/SQL');
    echo "Kolizje i współbieżność: $checks kontroli OK.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'BŁĄD: ' . $e->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if (is_resource($serverB)) { proc_terminate($serverB); proc_close($serverB); }
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
