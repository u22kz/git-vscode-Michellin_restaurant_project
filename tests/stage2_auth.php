<?php
/**
 * Testy integracyjne HTTP na kopii aplikacji w katalogu tymczasowym.
 * Użycie: php tests/stage2_auth.php /sciezka/do/test_credentials.php
 * Konfiguracja musi wskazywać pustą lokalną bazę michelin_test_*.
 * Test importuje SQL, modyfikuje tylko dane testowe i pozostawia bazę do inspekcji.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if ($argc !== 2 || !is_file($argv[1])) {
    fwrite(STDERR, "Użycie: php tests/stage2_auth.php /sciezka/do/test_credentials.php\n");
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
function check_stage2(bool $condition, string $label): void
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

function login_stage2(string $email, string $password, string $role): TestBrowser
{
    $browser = new TestBrowser();
    $browser->request('login.php');
    $oldCookie = $browser->cookie;
    [$status, , $location] = $browser->post('login.php', ['email' => $email, 'password' => $password]);
    check_stage2($status === 302 && $location === "dashboard_$role.php", "Logowanie: $role");
    check_stage2($browser->cookie !== $oldCookie, "Regeneracja ID sesji: $role");
    [$status] = $browser->request($location);
    check_stage2($status === 200, "Panel: $role");
    return $browser;
}

function snapshot_stage2(PDO $pdo): string
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

    $visitor = new TestBrowser();
    $visitor->cookie = 'PHPSESSID=chosen-by-attacker';
    [$status] = $visitor->request('register.php');
    check_stage2($status === 200 && $visitor->cookie !== 'PHPSESSID=chosen-by-attacker', 'Strict mode odrzuca narzucone ID sesji');
    $headers = strtolower(implode("\n", $visitor->headers));
    check_stage2(str_contains($headers, 'httponly') && str_contains($headers, 'samesite=lax')
        && str_contains($headers, 'cache-control: no-store'), 'Ciasteczka HttpOnly/SameSite i zakaz cache');

    $registration = ['name' => 'Anna-Maria', 'surname' => "O'Neill", 'phone' => '+48 500 600 700',
        'email' => 'stage2@example.com', 'password' => 'Test-password-2026!', 'password_confirm' => 'Test-password-2026!'];
    $invalidRegistrations = [
        'email' => ['email' => 'not-an-email'],
        'duplikat email' => ['email' => 'JAN.KOWALSKI@example.com'],
        'krótkie hasło' => ['password' => 'short', 'password_confirm' => 'short'],
        'długie hasło' => ['password' => str_repeat('a', 73), 'password_confirm' => str_repeat('a', 73)],
        'hasło z NUL' => ['password' => "abcdefgh\0", 'password_confirm' => "abcdefgh\0"],
        'potwierdzenie hasła' => ['password_confirm' => 'different'],
        'puste imię' => ['name' => ''],
        'nazwisko z cyfrą' => ['surname' => 'Nowak1'],
        'długie nazwisko' => ['surname' => str_repeat('a', 81)],
        'telefon bez cyfr' => ['phone' => '---------'],
        'telefon ze zbyt małą liczbą cyfr' => ['phone' => '1        2'],
        'XSS w imieniu' => ['name' => '<script>alert(1)</script>'],
        'SQL injection' => ['email' => "' OR 1=1 --"],
    ];
    foreach (array_keys($registration) as $field) {
        $invalidRegistrations['tablica: ' . $field] = [$field => ['malformed']];
    }
    foreach ($invalidRegistrations as $label => $change) {
        $before = snapshot_stage2($pdo);
        [$status, $body] = $visitor->post('register.php', array_replace($registration, $change));
        $expectedStatus = str_starts_with($label, 'tablica:') || $label === 'hasło z NUL' ? 400 : 200;
        check_stage2($status === $expectedStatus && $before === snapshot_stage2($pdo)
            && !str_contains($body, '<script>alert(1)</script>'), 'Rejestracja odrzuca: ' . $label);
    }
    [$status] = $visitor->post('register.php', $registration + ['role' => 'admin', 'is_active' => 0, 'user_id' => 6]);
    $newUser = $pdo->query("SELECT * FROM users WHERE customer_email = 'stage2@example.com'")->fetch();
    check_stage2($status === 200 && $newUser && $newUser['role'] === 'client' && (int)$newUser['is_active'] === 1,
        'Poprawna rejestracja; role=admin i obce ID z POST są ignorowane');
    check_stage2(password_verify($registration['password'], $newUser['password'])
        && $newUser['password'] !== $registration['password'], 'Hasło zapisane przez password_hash');
    $before = snapshot_stage2($pdo);
    $visitor->post('register.php', $registration);
    check_stage2($before === snapshot_stage2($pdo), 'Ponowna rejestracja nie duplikuje konta');

    $client = login_stage2($registration['email'], $registration['password'], 'client');
    $secondClient = login_stage2($registration['email'], $registration['password'], 'client');
    $employee = login_stage2('piotr.wisniewski@example.com', $fixturePassword, 'employee');
    $admin = login_stage2('adam.zielinski@example.com', $fixturePassword, 'admin');
    foreach (['dashboard_admin.php', 'dashboard_employee.php', 'admin_reservations.php', 'admin_employees.php',
        'admin_availability.php', 'admin_categories.php', 'admin_services.php', 'admin_stats.php', 'employee_leave.php'] as $page) {
        [$status, , $location] = $client->request($page);
        check_stage2($status === 302 && $location === 'index.php?error=access_denied', 'Klient nie ma dostępu: ' . $page);
    }
    foreach (['admin_reservations.php', 'admin_employees.php', 'admin_availability.php', 'admin_categories.php', 'admin_services.php', 'admin_stats.php'] as $page) {
        [$status] = $admin->request($page);
        check_stage2($status === 200, 'Administrator ma dostęp: ' . $page);
    }

    $csrfRequests = [
        [$visitor, 'register.php', $registration],
        [$visitor, 'login.php', ['email' => 'jan.kowalski@example.com', 'password' => 'wrong']],
        [$client, 'profile.php', ['update_info' => 1, 'name' => 'Zmiana', 'surname' => 'Test', 'phone' => '500600700']],
        [$client, 'profile.php', ['change_password' => 1, 'current_password' => $registration['password'],
            'new_password' => 'Other-password-2026', 'new_password_confirm' => 'Other-password-2026']],
        [$client, 'logout.php', []],
        [$client, 'dashboard_client.php', ['action' => 'cancel', 'reservation_id' => 1]],
        [$employee, 'dashboard_employee.php', ['reservation_id' => 1, 'status' => 'cancelled']],
        [$employee, 'employee_leave.php', ['action' => 'create', 'start_date' => '2090-01-02', 'end_date' => '2090-01-03']],
        [$admin, 'dashboard_admin.php', ['action' => 'change_role', 'user_id' => $newUser['user_id'], 'role' => 'admin']],
        [$admin, 'dashboard_admin.php', ['action' => 'toggle_active', 'user_id' => $newUser['user_id']]],
        [$admin, 'admin_reservations.php', ['action' => 'change_status', 'reservation_id' => 1, 'status' => 'cancelled']],
        [$admin, 'admin_employees.php', ['action' => 'toggle', 'employee_id' => 1]],
        [$admin, 'admin_availability.php', ['action' => 'delete', 'availability_id' => 1]],
        [$admin, 'admin_categories.php', ['action' => 'delete', 'category_id' => 5]],
        [$admin, 'admin_services.php', ['action' => 'toggle', 'service_id' => 1]],
    ];
    foreach ($csrfRequests as [$browser, $path, $data]) {
        foreach ([[], ['csrf_token' => 'invalid'], ['csrf_token' => ['array']]] as $token) {
            $before = snapshot_stage2($pdo);
            [$status] = $browser->request($path, $data + $token);
            check_stage2($status === 403 && $before === snapshot_stage2($pdo), 'CSRF blokuje zapis: ' . $path);
        }
    }
    $before = snapshot_stage2($pdo);
    [$status] = $client->post('profile.php', ['update_info' => 1, 'csrf_token' => $admin->csrf]);
    check_stage2($status === 403 && $before === snapshot_stage2($pdo), 'Token innej sesji jest odrzucany');

    foreach ([
        [$admin, 'admin_reservations.php', ['action' => 'change_status', 'reservation_id' => ['1'], 'status' => 'cancelled']],
        [$admin, 'admin_employees.php', ['action' => 'toggle', 'employee_id' => ['1']]],
        [$admin, 'admin_employees.php', ['action' => 'save', 'service_ids' => [['1']]]],
        [$admin, 'admin_availability.php', ['action' => 'delete', 'availability_id' => ['1']]],
        [$employee, 'employee_leave.php', ['action' => 'create', 'start_date' => ['2090-01-02']]],
        [$client, 'dashboard_client.php', ['action' => 'create', 'reservation_date' => ['2090-01-02']]],
        [$client, 'dashboard_client.php', ['action' => 'create', 'reservation_date' => "2090-01-02\0"]],
        [$client, 'profile.php', ['update_info' => 1, 'name' => "\xff"]],
    ] as [$browser, $path, $data]) {
        $before = snapshot_stage2($pdo);
        [$status] = $browser->post($path, $data);
        check_stage2($status === 400 && $before === snapshot_stage2($pdo), 'Nieprawidłowy typ/kodowanie bez zapisu: ' . $path);
    }
    foreach (['admin_reservations.php?date_from[]=x', 'admin_availability.php?success[]=x',
        'admin_employees.php?edit[]=1', 'employee_leave.php?msg[]=x',
        'dashboard_client.php?ajax=employees&date[]=x'] as $path) {
        $browser = str_starts_with($path, 'admin_') ? $admin : (str_starts_with($path, 'employee_') ? $employee : $client);
        [$status] = $browser->request($path);
        check_stage2($status === 400, 'Nieprawidłowy parametr GET: ' . $path);
    }
    [$status] = $admin->post('admin_employees.php', ['action' => 'save', 'employee_id' => 1,
        'position' => 'Szef kuchni', 'description' => 'Test', 'is_active' => 1, 'service_ids' => ['1', '2', '3']]);
    check_stage2($status === 302 && (int)$pdo->query('SELECT COUNT(*) FROM employee_services WHERE employee_id = 1')->fetchColumn() === 3,
        'Prawidłowa lista service_ids nadal zapisuje przypisania');
    $before = snapshot_stage2($pdo);
    [$status, $body] = $client->post('dashboard_client.php', ['action' => 'create', 'service_id' => 1, 'employee_id' => 1,
        'reservation_date' => date('Y-m-d', strtotime('next Saturday')), 'start_time' => '18:00', 'guest_count' => '4294967296']);
    check_stage2($status === 200 && str_contains($body, 'Wystąpił błąd bazy danych podczas rezerwacji.')
        && !str_contains($body, 'SQLSTATE') && !str_contains($body, 'Out of range')
        && $before === snapshot_stage2($pdo), 'Błąd PDO jest ukryty, a transakcja wycofana');

    [$status] = $admin->post('admin_reservations.php', ['action' => 'change_status', 'reservation_id' => 1, 'status' => 'completed']);
    check_stage2($status === 302 && $pdo->query('SELECT status FROM reservations WHERE reservation_id = 1')->fetchColumn() === 'completed', 'Poprawny CSRF: administrator zmienia status rezerwacji');
    [$status] = $admin->post('admin_employees.php', ['action' => 'toggle', 'employee_id' => 7]);
    check_stage2($status === 302 && (int)$pdo->query('SELECT is_active FROM employees WHERE employee_id = 7')->fetchColumn() === 0, 'Poprawny CSRF: administrator zmienia aktywność pracownika');
    [$status] = $admin->post('admin_availability.php', ['action' => 'delete', 'availability_id' => 1]);
    check_stage2($status === 302 && (int)$pdo->query('SELECT COUNT(*) FROM employee_availability WHERE availability_id = 1')->fetchColumn() === 0, 'Poprawny CSRF: administrator usuwa testowy wpis grafiku');
    [$status] = $employee->post('employee_leave.php', ['action' => 'create', 'start_date' => '2090-01-02', 'end_date' => '2090-01-03']);
    check_stage2($status === 302 && (int)$pdo->query('SELECT COUNT(*) FROM employee_leave_requests WHERE employee_id = 1')->fetchColumn() === 1, 'Poprawny CSRF: pracownik składa wniosek');
    $before = snapshot_stage2($pdo);
    [$status] = $employee->post('dashboard_employee.php', ['reservation_id' => 2, 'status' => 'cancelled', 'employee_id' => 2]);
    check_stage2($status === 200 && $before === snapshot_stage2($pdo), 'Pracownik nie zmienia rezerwacji innego pracownika przez podmianę ID');
    $pdo->exec("UPDATE reservations SET reservation_date = CURRENT_DATE + INTERVAL 1 MONTH, status = 'confirmed' WHERE reservation_id = 1");
    $before = snapshot_stage2($pdo);
    [$status] = $client->post('dashboard_client.php', ['action' => 'cancel', 'reservation_id' => 1, 'user_id' => 1]);
    check_stage2($status === 200 && $before === snapshot_stage2($pdo), 'Klient nie anuluje cudzej rezerwacji przez podmianę ID');

    $client->request('profile.php?user_id=6');
    $adminBefore = $pdo->query('SELECT * FROM users WHERE user_id = 6')->fetch();
    [$status] = $client->post('profile.php?user_id=6', ['update_info' => 1, 'user_id' => 6, 'id' => 6,
        'role' => 'admin', 'email' => 'stolen@example.com', 'name' => 'Żaneta', 'surname' => 'Nowak', 'phone' => '500600701']);
    $updated = $pdo->query('SELECT * FROM users WHERE user_id = ' . (int)$newUser['user_id'])->fetch();
    check_stage2($status === 200 && $updated['name'] === 'Żaneta' && $updated['role'] === 'client'
        && $updated['customer_email'] === $registration['email']
        && $adminBefore === $pdo->query('SELECT * FROM users WHERE user_id = 6')->fetch(), 'Profil aktualizuje wyłącznie własne dozwolone pola');
    foreach (['name', 'surname', 'phone'] as $field) {
        $before = snapshot_stage2($pdo);
        [$status] = $client->post('profile.php', array_replace(['update_info' => 1, 'name' => 'Jan', 'surname' => 'Nowak', 'phone' => '500600700'], [$field => ['array']]));
        check_stage2($status === 400 && $before === snapshot_stage2($pdo), 'Profil odrzuca tablicę: ' . $field);
    }
    $malicious = '<script>alert("xss")</script>';
    $pdo->prepare('UPDATE users SET name = ? WHERE user_id = ?')->execute([$malicious, $newUser['user_id']]);
    foreach (['profile.php', 'dashboard_client.php', 'index.php'] as $page) {
        [, $body] = $client->request($page);
        check_stage2(!str_contains($body, $malicious) && str_contains($body, '&lt;script&gt;'), 'XSS zapisany w danych jest escapowany: ' . $page);
    }
    [, $body] = $admin->request('dashboard_admin.php');
    check_stage2(!str_contains($body, $malicious) && str_contains($body, '&lt;script&gt;'), 'XSS escapowany w panelu admina');
    $pdo->prepare('UPDATE users SET name = ? WHERE user_id = ?')->execute(['Żaneta', $newUser['user_id']]);

    $changePassword = ['change_password' => 1, 'current_password' => $registration['password'],
        'new_password' => 'New-password-2026!', 'new_password_confirm' => 'New-password-2026!', 'user_id' => 6];
    foreach ([['current_password' => 'incorrect'], ['current_password' => ['array']],
        ['new_password' => 'short', 'new_password_confirm' => 'short'], ['new_password_confirm' => 'different'],
        ['new_password' => ['array']], ['new_password_confirm' => ['array']]] as $invalid) {
        $before = snapshot_stage2($pdo);
        [$status] = $client->post('profile.php', array_replace($changePassword, $invalid));
        $expectedStatus = count(array_filter($invalid, 'is_array')) > 0 ? 400 : 200;
        check_stage2($status === $expectedStatus && $before === snapshot_stage2($pdo), 'Niepoprawna zmiana hasła nie zmienia danych');
    }
    $oldSession = new TestBrowser();
    $oldSession->cookie = $client->cookie;
    [$status] = $client->post('profile.php', $changePassword);
    $hash = $pdo->query('SELECT password FROM users WHERE user_id = ' . (int)$newUser['user_id'])->fetchColumn();
    check_stage2($status === 200 && password_verify($changePassword['new_password'], $hash)
        && !password_verify($registration['password'], $hash)
        && $adminBefore === $pdo->query('SELECT * FROM users WHERE user_id = 6')->fetch(), 'Bezpieczna zmiana hasła tylko własnego konta');
    check_stage2($oldSession->cookie !== $client->cookie, 'Zmiana hasła regeneruje ID sesji');
    foreach ([$secondClient, $oldSession] as $expired) {
        [$status, , $location] = $expired->request('profile.php');
        check_stage2($status === 302 && $location === 'login.php', 'Zmiana hasła unieważnia wcześniejsze sesje');
    }

    // Próby z odrębnych sesji korzystają z jednego licznika w bazie.
    for ($i = 1; $i <= 5; ++$i) {
        $attacker = new TestBrowser();
        $attacker->request('login.php');
        [$status] = $attacker->post('login.php', ['email' => 'jan.kowalski@example.com', 'password' => 'wrong-password']);
        check_stage2($status === 200 && (int)$pdo->query('SELECT failed_login_attempts FROM users WHERE user_id = 1')->fetchColumn() === $i, "Licznik błędnych logowań: $i");
    }
    [$status, , $location] = $attacker->post('login.php', ['email' => 'jan.kowalski@example.com', 'password' => $fixturePassword]);
    check_stage2($status === 200 && $location === '' && (int)$pdo->query('SELECT locked_until > CURRENT_TIMESTAMP FROM users WHERE user_id = 1')->fetchColumn() === 1, 'Blokada konta po 5 próbach obejmuje poprawne hasło');
    $pdo->exec('UPDATE users SET locked_until = CURRENT_TIMESTAMP - INTERVAL 1 MINUTE WHERE user_id = 1');
    $attacker->post('login.php', ['email' => 'jan.kowalski@example.com', 'password' => 'wrong-password']);
    check_stage2((int)$pdo->query('SELECT failed_login_attempts FROM users WHERE user_id = 1')->fetchColumn() === 1, 'Po wygaśnięciu blokady licznik zaczyna od 1');
    $jan = login_stage2('jan.kowalski@example.com', $fixturePassword, 'client');
    check_stage2((int)$pdo->query('SELECT failed_login_attempts FROM users WHERE user_id = 1')->fetchColumn() === 0, 'Poprawne logowanie zeruje licznik');
    foreach ([['email' => "' OR 1=1 --", 'password' => $fixturePassword], ['email' => ['array'], 'password' => 'anything'],
        ['email' => 'jan.kowalski@example.com', 'password' => ['array']]] as $data) {
        $before = snapshot_stage2($pdo);
        [$status, , $location] = $attacker->post('login.php', $data);
        $expectedStatus = count(array_filter($data, 'is_array')) > 0 ? 400 : 200;
        check_stage2($status === $expectedStatus && $location === '' && $before === snapshot_stage2($pdo), 'Niepoprawne dane logowania / SQL injection nie dają dostępu');
    }

    $admin->post('dashboard_admin.php', ['action' => 'change_role', 'user_id' => $newUser['user_id'], 'role' => 'employee']);
    [$status] = $client->request('dashboard_employee.php');
    check_stage2($status === 200, 'Nowa rola jest odczytywana z bazy w istniejącej sesji');
    [$status] = $client->request('dashboard_client.php');
    check_stage2($status === 302, 'Poprzednia rola nie uprawnia do starego panelu');
    $admin->post('dashboard_admin.php', ['action' => 'change_role', 'user_id' => $newUser['user_id'], 'role' => 'admin']);
    [$status] = $client->request('dashboard_admin.php');
    check_stage2($status === 200, 'Nadanie roli admin przez administratora działa');
    $admin->post('dashboard_admin.php', ['action' => 'change_role', 'user_id' => $newUser['user_id'], 'role' => 'client']);
    [$status] = $client->request('dashboard_admin.php');
    check_stage2($status === 302, 'Odebranie roli admin blokuje istniejącą sesję');
    $client->request('profile.php');
    $before = snapshot_stage2($pdo);
    [$status] = $client->post('dashboard_admin.php', ['action' => 'toggle_active', 'user_id' => 1]);
    check_stage2($status === 302 && $before === snapshot_stage2($pdo), 'Klient nie zmienia danych administratora nawet z poprawnym CSRF');

    $admin->post('dashboard_admin.php', ['action' => 'toggle_active', 'user_id' => $newUser['user_id']]);
    [$status, , $location] = $client->request('profile.php');
    check_stage2($status === 302 && $location === 'login.php', 'Dezaktywacja odbiera dostęp aktywnej sesji');
    $before = snapshot_stage2($pdo);
    [$status] = $client->post('profile.php', ['update_info' => 1, 'name' => 'Test', 'surname' => 'Test', 'phone' => '500600700']);
    check_stage2($status !== 200 && $before === snapshot_stage2($pdo), 'Dezaktywowana sesja nie może zapisywać danych');
    $disabled = new TestBrowser();
    $disabled->request('login.php');
    [$status, , $location] = $disabled->post('login.php', ['email' => $registration['email'], 'password' => $changePassword['new_password']]);
    check_stage2($status === 200 && $location === '', 'Dezaktywowane konto nie może się zalogować');
    $admin->post('dashboard_admin.php', ['action' => 'toggle_active', 'user_id' => $newUser['user_id']]);
    [$status] = $client->request('profile.php');
    check_stage2($status === 302, 'Reaktywacja konta nie przywraca unieważnionej sesji');
    $client = login_stage2($registration['email'], $changePassword['new_password'], 'client');
    [$status] = $client->request('logout.php');
    check_stage2($status === 405, 'GET nie wylogowuje użytkownika');
    [$status] = $client->request('profile.php');
    check_stage2($status === 200, 'Sesja pozostaje aktywna po GET logout');
    $copiedSession = new TestBrowser();
    $copiedSession->cookie = $client->cookie;
    [$status, , $location] = $client->post('logout.php', []);
    check_stage2($status === 303 && $location === 'login.php', 'POST logout wylogowuje i przekierowuje');
    foreach ([$client, $copiedSession] as $loggedOut) {
        [$status, , $location] = $loggedOut->request('profile.php');
        check_stage2($status === 302 && $location === 'login.php', 'Wylogowanie niszczy sesję po stronie serwera');
    }
    [$status] = $visitor->request('dashboard_client%20copy.php', ['action' => 'create']);
    check_stage2($status === 410, 'Stary formularz pozostaje zablokowany');
    $log = file_get_contents($runtime . '/server.log');
    check_stage2(!preg_match('/PHP (Warning|Fatal error|Parse error|Deprecated|Notice)/', $log), 'Brak błędów PHP w logu HTTP');
    echo "Etap 2: $checks kontroli OK.\n";
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
