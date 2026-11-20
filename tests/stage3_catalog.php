<?php
/**
 * Testy integracyjne HTTP na kopii aplikacji w katalogu tymczasowym.
 * Użycie: php tests/stage3_catalog.php /sciezka/do/test_credentials.php
 * Konfiguracja musi wskazywać pustą lokalną bazę michelin_test_*.
 * Test importuje SQL, modyfikuje tylko dane testowe i pozostawia bazę do inspekcji.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if ($argc !== 2 || !is_file($argv[1])) {
    fwrite(STDERR, "Użycie: php tests/stage3_catalog.php /sciezka/do/test_credentials.php\n");
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
function check_stage3(bool $condition, string $label): void
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

function login_stage3(string $email, string $password, string $role): TestBrowser
{
    $browser = new TestBrowser();
    $browser->request('login.php');
    $oldCookie = $browser->cookie;
    [$status, , $location] = $browser->post('login.php', ['email' => $email, 'password' => $password]);
    check_stage3($status === 302 && $location === "dashboard_$role.php", "Logowanie: $role");
    check_stage3($browser->cookie !== $oldCookie, "Regeneracja ID sesji: $role");
    [$status] = $browser->request($location);
    check_stage3($status === 200, "Panel: $role");
    return $browser;
}

function snapshot_stage3(PDO $pdo): string
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

    $admin = login_stage3('adam.zielinski@example.com', $fixturePassword, 'admin');
    $client = login_stage3('jan.kowalski@example.com', $fixturePassword, 'client');
    $employee = login_stage3('piotr.wisniewski@example.com', $fixturePassword, 'employee');
    $anonymous = new TestBrowser();
    $anonymous->request('login.php');
    [, $dashboard] = $admin->request('dashboard_admin.php');
    foreach (['admin_categories.php', 'admin_services.php', 'admin_employees.php',
        'admin_availability.php', 'admin_reservations.php', 'admin_stats.php'] as $page) {
        check_stage3(str_contains($dashboard, 'href="' . $page . '"'), 'Nawigacja: ' . $page);
        [$status] = $admin->request($page);
        check_stage3($status === 200, 'Strona administratora: ' . $page);
    }

    $category = ['action' => 'save', 'name' => 'Żółć i dania', 'parent_id' => '', 'sort_order' => '5', 'description' => 'Opis testowy'];
    $service = ['action' => 'save', 'name' => "Kolacja O'Brien", 'category_id' => '5',
        'description' => 'Opis usługi', 'price' => '12,50', 'unit' => 'osoba', 'duration_minutes' => '60', 'is_available' => '1'];
    $mutations = [
        ['admin_categories.php', $category],
        ['admin_categories.php', $category + ['category_id' => 1]],
        ['admin_categories.php', ['action' => 'delete', 'category_id' => 5]],
        ['admin_services.php', $service],
        ['admin_services.php', $service + ['service_id' => 1]],
        ['admin_services.php', ['action' => 'delete', 'service_id' => 1]],
        ['admin_services.php', ['action' => 'toggle', 'service_id' => 1]],
    ];
    foreach ([$anonymous, $client, $employee] as $browser) {
        foreach (['dashboard_admin.php', 'admin_categories.php', 'admin_services.php'] as $page) {
            [$status] = $browser->request($page);
            check_stage3($status === 302, 'Dostęp tylko dla administratora: ' . $page);
        }
        foreach ($mutations as [$page, $data]) {
            $before = snapshot_stage3($pdo);
            [$status] = $browser->post($page, $data);
            check_stage3($status === 302 && $before === snapshot_stage3($pdo), 'Nieuprawniony POST nie zmienia danych: ' . $page);
        }
    }
    foreach ($mutations as [$page, $data]) {
        foreach ([[], ['csrf_token' => 'invalid'], ['csrf_token' => ['bad']]] as $token) {
            $before = snapshot_stage3($pdo);
            [$status] = $admin->request($page, $data + $token);
            check_stage3($status === 403 && $before === snapshot_stage3($pdo), 'CSRF blokuje operację: ' . $page);
        }
    }

    $saveCategory = static function (array $data) use ($admin, $pdo): int {
        [$status, , $location] = $admin->post('admin_categories.php', $data);
        check_stage3($status === 302 && str_starts_with($location, 'admin_categories.php?status='), 'Zapis kategorii i przekierowanie');
        if (isset($data['category_id'])) { return (int)$data['category_id']; }
        return (int)$pdo->query('SELECT MAX(category_id) FROM categories')->fetchColumn();
    };
    $saveService = static function (array $data) use ($admin, $pdo): int {
        [$status, , $location] = $admin->post('admin_services.php', $data);
        check_stage3($status === 302 && str_starts_with($location, 'admin_services.php?status='), 'Zapis usługi i przekierowanie');
        if (isset($data['service_id'])) { return (int)$data['service_id']; }
        return (int)$pdo->query('SELECT MAX(service_id) FROM services')->fetchColumn();
    };
    $reject = static function (string $page, array $data, int $expected = 200) use ($admin, $pdo): void {
        $before = snapshot_stage3($pdo);
        [$status, $body] = $admin->post($page, $data);
        check_stage3($status === $expected && $before === snapshot_stage3($pdo)
            && ($expected !== 200 || str_contains($body, 'alert-danger')),
            'Odrzucone dane bez zapisu: ' . $page . ' / ' . ($data['action'] ?? ''));
    };

    $parentId = $saveCategory($category);
    $parent = $pdo->query('SELECT * FROM categories WHERE category_id = ' . $parentId)->fetch();
    check_stage3($parent['slug'] === 'zolc-i-dania' && $parent['parent_id'] === null && (int)$parent['sort_order'] === 5,
        'Kategoria: polski slug, NULL parent_id i sort_order');
    $childId = $saveCategory(array_replace($category, ['name' => 'Podkategoria', 'parent_id' => $parentId]));
    check_stage3((int)$pdo->query('SELECT parent_id FROM categories WHERE category_id = ' . $childId)->fetchColumn() === $parentId, 'Powiązanie podkategorii');
    $saveCategory(array_replace($category, ['category_id' => $childId, 'name' => 'Edytowana kategoria', 'sort_order' => '12', 'parent_id' => $parentId]));
    $child = $pdo->query('SELECT * FROM categories WHERE category_id = ' . $childId)->fetch();
    check_stage3($child['name'] === 'Edytowana kategoria' && $child['slug'] === 'edytowana-kategoria' && (int)$child['sort_order'] === 12, 'Edycja nazwy, slugu i kolejności');
    $reject('admin_categories.php', array_replace($category, ['category_id' => $parentId, 'parent_id' => $parentId]));
    $reject('admin_categories.php', array_replace($category, ['category_id' => $parentId, 'parent_id' => $childId]));
    $reject('admin_categories.php', ['action' => 'delete', 'category_id' => $parentId]);
    foreach ([['name' => ' '], ['name' => str_repeat('a', 101)], ['sort_order' => '-1'], ['sort_order' => '1.5'],
        ['sort_order' => '2147483648'], ['sort_order' => 'abc'], ['parent_id' => '4294967295'],
        ['category_id' => '4294967295'], ['description' => str_repeat('ą', 32768)]] as $invalid) {
        $reject('admin_categories.php', array_replace($category, $invalid));
    }
    $reject('admin_categories.php', array_replace($category, ['name' => ['bad']]), 400);
    $longCategory = array_replace($category, ['name' => str_repeat('a', 100), 'sort_order' => '2147483647']);
    $long1 = $saveCategory($longCategory);
    $long2 = $saveCategory($longCategory);
    $slugs = $pdo->query("SELECT slug FROM categories WHERE category_id IN ($long1, $long2) ORDER BY category_id")->fetchAll(PDO::FETCH_COLUMN);
    check_stage3(strlen($slugs[0]) === 100 && strlen($slugs[1]) === 100 && $slugs[0] !== $slugs[1]
        && str_ends_with($slugs[1], '-2'), 'Powtórzona nazwa 100 znaków ma unikalny slug mieszczący się w kolumnie');
    $saveCategory($longCategory + ['category_id' => $long2]);
    check_stage3($pdo->query('SELECT slug FROM categories WHERE category_id = ' . $long2)->fetchColumn() === $slugs[1], 'Edycja nie zmienia własnego poprawnego slugu');

    $service['category_id'] = (string)$childId;
    $serviceId = $saveService($service);
    $saved = $pdo->query('SELECT * FROM services WHERE service_id = ' . $serviceId)->fetch();
    check_stage3($saved['price'] === '12.50' && (int)$saved['duration_minutes'] === 60 && (int)$saved['category_id'] === $childId,
        'Dodanie usługi z ceną dziesiętną, czasem i kategorią');
    $service['service_id'] = $serviceId;
    $saveService(array_replace($service, ['price' => '99.99']));
    check_stage3($pdo->query('SELECT price FROM services WHERE service_id = ' . $serviceId)->fetchColumn() === '99.99', 'Edycja ceny');
    $saveService(array_replace($service, ['duration_minutes' => '120']));
    check_stage3((int)$pdo->query('SELECT duration_minutes FROM services WHERE service_id = ' . $serviceId)->fetchColumn() === 120, 'Edycja czasu trwania');
    $saveService(array_replace($service, ['price' => '0', 'duration_minutes' => '1', 'is_available' => '0', 'category_id' => $parentId]));
    $saved = $pdo->query('SELECT * FROM services WHERE service_id = ' . $serviceId)->fetch();
    check_stage3($saved['price'] === '0.00' && (int)$saved['duration_minutes'] === 1 && (int)$saved['is_available'] === 0
        && (int)$saved['category_id'] === $parentId, 'Cena zero, minimalny czas, zmiana kategorii i wyłączenie');
    $saveService(array_replace($service, ['price' => '99999999.99', 'duration_minutes' => '65535']));
    $saved = $pdo->query('SELECT * FROM services WHERE service_id = ' . $serviceId)->fetch();
    check_stage3($saved['price'] === '99999999.99' && (int)$saved['duration_minutes'] === 65535, 'Górne granice DECIMAL i SMALLINT');
    $unchecked = $service; unset($unchecked['is_available']);
    $saveService($unchecked);
    check_stage3((int)$pdo->query('SELECT is_available FROM services WHERE service_id = ' . $serviceId)->fetchColumn() === 0, 'Niezaznaczony checkbox oznacza niedostępność');
    foreach ([1, 0] as $expected) {
        [$status] = $admin->post('admin_services.php', ['action' => 'toggle', 'service_id' => $serviceId]);
        check_stage3($status === 302 && (int)$pdo->query('SELECT is_available FROM services WHERE service_id = ' . $serviceId)->fetchColumn() === $expected, 'Przełączanie dostępności');
    }
    foreach (['-1', 'abc', 'NaN', '1e2', '1.234', '100000000', '1,2,3', ''] as $price) {
        $reject('admin_services.php', array_replace($service, ['price' => $price]));
    }
    foreach (['0', '-1', '1.5', '65536', 'abc', ''] as $duration) {
        $reject('admin_services.php', array_replace($service, ['duration_minutes' => $duration]));
    }
    foreach (['2', '-1', 'yes', ''] as $available) {
        $reject('admin_services.php', array_replace($service, ['is_available' => $available]));
    }
    foreach ([['name' => ' '], ['name' => str_repeat('a', 151)], ['category_id' => '4294967295'],
        ['category_id' => '0'], ['service_id' => '4294967295'], ['unit' => ''], ['unit' => str_repeat('a', 21)],
        ['description' => str_repeat('ą', 32768)], ['category_id' => "1 OR 1=1"]] as $invalid) {
        $reject('admin_services.php', array_replace($service, $invalid));
    }
    $reject('admin_services.php', array_replace($service, ['is_available' => ['bad']]), 400);
    $reject('admin_services.php', ['action' => 'toggle', 'service_id' => '4294967295']);

    $payload = '<script>alert(1)</script>';
    $xssId = $saveCategory(array_replace($category, ['name' => $payload, 'description' => $payload]));
    [, $body] = $admin->request('admin_categories.php?edit=' . $xssId);
    check_stage3(!str_contains($body, $payload) && str_contains($body, '&lt;script&gt;'), 'Kategorie: XSS escapowany w widoku i formularzu');
    $saveService(array_replace($service, ['name' => $payload, 'description' => $payload]));
    [, $body] = $admin->request('admin_services.php?edit=' . $serviceId);
    check_stage3(!str_contains($body, $payload) && str_contains($body, '&lt;script&gt;'), 'Usługi: XSS escapowany w widoku i formularzu');

    $reject('admin_categories.php', ['action' => 'delete', 'category_id' => $childId]);
    $reject('admin_services.php', ['action' => 'delete', 'service_id' => 1]);
    $pdo->prepare('INSERT INTO employee_services (employee_id, service_id) VALUES (?, ?)')->execute([1, $serviceId]);
    $reject('admin_services.php', ['action' => 'delete', 'service_id' => $serviceId]);
    // Usuwamy wyłącznie powiązanie utworzone przed chwilą w izolowanej bazie testowej.
    $pdo->prepare('DELETE FROM employee_services WHERE employee_id = ? AND service_id = ?')->execute([1, $serviceId]);
    [$status] = $admin->post('admin_services.php', ['action' => 'delete', 'service_id' => $serviceId]);
    check_stage3($status === 302 && (int)$pdo->query('SELECT COUNT(*) FROM services WHERE service_id = ' . $serviceId)->fetchColumn() === 0, 'Usunięcie niepowiązanej usługi');
    [$status] = $admin->post('admin_categories.php', ['action' => 'delete', 'category_id' => $childId]);
    check_stage3($status === 302 && (int)$pdo->query('SELECT COUNT(*) FROM categories WHERE category_id = ' . $childId)->fetchColumn() === 0, 'Usunięcie pustej podkategorii');
    [$status] = $admin->post('admin_categories.php', ['action' => 'delete', 'category_id' => $parentId]);
    check_stage3($status === 302 && (int)$pdo->query('SELECT COUNT(*) FROM categories WHERE category_id = ' . $parentId)->fetchColumn() === 0, 'Usunięcie pustej kategorii');
    $reject('admin_categories.php', ['action' => 'delete', 'category_id' => $parentId]);
    $reject('admin_services.php', ['action' => 'delete', 'service_id' => $serviceId]);
    foreach (['admin_categories.php?action=delete&category_id=1', 'admin_services.php?action=delete&service_id=1'] as $path) {
        $before = snapshot_stage3($pdo);
        [$status] = $admin->request($path);
        check_stage3($status === 200 && $before === snapshot_stage3($pdo), 'GET nie usuwa danych');
    }
    $log = file_get_contents($runtime . '/server.log');
    check_stage3(!preg_match('/PHP (Warning|Fatal error|Parse error|Deprecated|Notice)|SQLSTATE/', $log), 'Brak błędów PHP i SQL w logu testów katalogu');
    echo "Etap 3 — kategorie i usługi: $checks kontroli OK.\n";
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
