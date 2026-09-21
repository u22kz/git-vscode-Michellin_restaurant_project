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
    session_start();
}

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
