<?php
/**
 * Plik: auth_check.php
 * Cel: Jeden, wspólny plik z funkcjami do obsługi sesji PHP oraz kontroli
 *      dostępu (autoryzacji) na podstawie roli użytkownika. Dołączamy go
 *      (require) na początku KAŻDEJ chronionej strony, dzięki czemu logika
 *      sprawdzania uprawnień nie powtarza się w wielu miejscach.
 *
 * Ochrona dostępu odbywa się wyłącznie po stronie serwera (PHP) -
 * nie polegamy na ukrywaniu linków/przycisków w HTML.
 */

// Uruchamiamy sesję tylko wtedy, gdy jeszcze nie jest aktywna
// (zapobiega błędowi przy wielokrotnym session_start()).
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if (!session_start()) {
        http_response_code(503);
        exit('Nie można uruchomić sesji. Spróbuj ponownie później.');
    }
}
header('Cache-Control: no-store');

/**
 * Sprawdza, czy użytkownik jest zalogowany.
 */
function is_logged_in(): bool
{
    return isset($_SESSION['user_id'], $_SESSION['role']);
}

/**
 * Zwraca rolę aktualnie zalogowanego użytkownika (lub null, jeśli nikt nie jest zalogowany).
 */
function current_role(): ?string
{
    return $_SESSION['role'] ?? null;
}

/**
 * Zwraca id aktualnie zalogowanego użytkownika (lub null).
 */
function current_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

/**
 * Zwraca imię aktualnie zalogowanego użytkownika (do wyświetlenia w interfejsie).
 */
function current_user_name(): string
{
    return $_SESSION['name'] ?? '';
}

/**
 * Wymusza, aby użytkownik był zalogowany.
 * Jeśli nie jest - przekierowuje na stronę logowania i kończy działanie skryptu (exit).
 */
function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Wymusza, aby zalogowany użytkownik miał jedną z dozwolonych ról.
 * W środku najpierw wywołuje require_login(), więc niezalogowany
 * użytkownik zostanie odesłany do logowania, a zalogowany, ale z
 * niewłaściwą rolą - do strony głównej z komunikatem o braku dostępu.
 *
 * Przykład użycia w chronionym pliku:
 *   require_once 'auth_check.php';
 *   require_role(['admin']);
 *
 * @param string[] $allowed_roles np. ['admin'] albo ['client', 'employee']
 */
function require_role(array $allowed_roles): void
{
    require_login();

    if (!in_array(current_role(), $allowed_roles, true)) {
        header('Location: index.php?error=access_denied');
        exit;
    }
}

/**
 * Zwraca nazwę pliku panelu odpowiedniego dla danej roli.
 * Używane po zalogowaniu (login.php) oraz w linkach nawigacyjnych.
 */
function dashboard_url_for_role(string $role): string
{
    switch ($role) {
        case 'admin':
            return 'dashboard_admin.php';
        case 'employee':
            return 'dashboard_employee.php';
        case 'client':
        default:
            return 'dashboard_client.php';
    }
}

/**
 * ------------------------------------------------------------------
 * OCHRONA CSRF (Cross-Site Request Forgery)
 * ------------------------------------------------------------------
 * Token CSRF to losowy sekret przypisany do sesji, zapisywany w sesji
 * użytkownika i jednocześnie wysyłany jako ukryte pole w każdym
 * formularzu POST. Przy odbiorze formularza sprawdzamy, czy token
 * z formularza zgadza się z tokenem w sesji - jeśli nie, żądanie
 * nie mogło pochodzić z naszego formularza (np. z innej, złośliwej
 * strony), więc je odrzucamy.
 */

/**
 * Zwraca aktualny token CSRF dla sesji użytkownika.
 * Jeśli token jeszcze nie istnieje - tworzy nowy, kryptograficznie
 * bezpieczny token (random_bytes) i zapisuje go w sesji.
 * Ten sam token jest używany dla wszystkich formularzy w obrębie
 * jednej sesji (nie trzeba go tworzyć od nowa przy każdym formularzu).
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Zwraca gotowy znacznik <input type="hidden"> z tokenem CSRF,
 * do wstawienia wewnątrz każdego formularza POST.
 */
function csrf_field(): string
{
    $token = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * Sprawdza, czy token CSRF przesłany w formularzu (POST) zgadza się
 * z tokenem zapisanym w sesji. Używa hash_equals() zamiast zwykłego
 * porównania "===", żeby uniknąć tzw. ataku czasowego (timing attack).
 *
 * Wywoływana na samym początku obsługi każdego żądania POST,
 * zanim jakiekolwiek dane z formularza zostaną użyte.
 *
 * @return bool true, jeśli token jest poprawny
 */
function csrf_verify(): bool
{
    $sent_token = $_POST['csrf_token'] ?? '';

    if (!is_string($sent_token) || $sent_token === ''
        || !isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $sent_token);
}

/** Zatrzymuje żądanie, zanim formularz będzie mógł zmienić dane. */
function csrf_require_valid(): void
{
    if (!csrf_verify()) {
        http_response_code(403);
        exit('Nieprawidłowy token formularza. Odśwież stronę i spróbuj ponownie.');
    }
}

/** Usuwa zarówno dane sesji na serwerze, jak i jej ciasteczko. */
function destroy_user_session(): void
{
    $_SESSION = [];
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'],
    ]);
    session_destroy();
}

/** Przycisk zachowuje wygląd nawigacji, ale wylogowanie jest operacją POST. */
function logout_button(string $classes = 'btn btn-danger btn-sm'): string
{
    return '<form method="post" action="logout.php" class="d-inline">'
        . csrf_field() . '<button type="submit" class="'
        . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . '">Wyloguj</button></form>';
}

// Sesja identyfikuje konto; bieżące uprawnienia zawsze pochodzą z bazy.
if (isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/db_connect.php';
    try {
        $authStmt = $pdo->prepare('SELECT user_id, name, surname, role, is_active, password
            FROM users WHERE user_id = ?');
        $authStmt->execute([$_SESSION['user_id']]);
        $authUser = $authStmt->fetch();
        $passwordStamp = $_SESSION['password_stamp'] ?? '';
        if (!$authUser || (int)$authUser['is_active'] !== 1
            || !in_array($authUser['role'], ['client', 'employee', 'admin'], true)
            || !is_string($passwordStamp)
            || !hash_equals(hash('sha256', $authUser['password']), $passwordStamp)) {
            // Zmiana hasła unieważnia też sesje otwarte na innych urządzeniach.
            $_SESSION = [];
            session_regenerate_id(true);
        } else {
            if (($_SESSION['role'] ?? null) !== $authUser['role']) {
                session_regenerate_id(true);
                unset($_SESSION['csrf_token']);
            }
            $_SESSION['role'] = $authUser['role'];
            $_SESSION['name'] = $authUser['name'] . ' ' . $authUser['surname'];
        }
        unset($authUser, $authStmt, $passwordStamp);
    } catch (PDOException $e) {
        error_log('Błąd weryfikacji sesji: ' . $e->getMessage());
        http_response_code(503);
        exit('Nie można zweryfikować sesji. Spróbuj ponownie później.');
    }
}

// Ochrona wspólna dla wszystkich formularzy korzystających z auth_check.php.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_require_valid();
}

require_once __DIR__ . '/form_validation.php';
$allowServiceIds = basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'admin_employees.php';
if (!request_has_valid_shape($_GET) || !request_has_valid_shape($_POST, $allowServiceIds)) {
    http_response_code(400);
    exit('Nieprawidłowy format danych formularza. Odśwież stronę i spróbuj ponownie.');
}
