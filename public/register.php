<?php
/**
 * Plik: register.php
 * Cel: Rejestracja nowego konta klienta ('client') wraz z pełną walidacją
 *      danych po stronie serwera oraz bezpiecznym zapisem hasła (password_hash).
 */
require_once 'auth_check.php';
require_once 'db_connect.php';
require_once 'form_validation.php';

// Zalogowany użytkownik nie potrzebuje strony rejestracji
if (is_logged_in()) {
    header('Location: ' . dashboard_url_for_role(current_role()));
    exit;
}

$errors  = [];
$success = false;

// Wartości pól do ponownego wyświetlenia w formularzu po błędzie (bez hasła)
$old = [
    'name'    => '',
    'surname' => '',
    'phone'   => '',
    'email'   => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- Weryfikacja tokenu CSRF - zawsze jako pierwszy krok obsługi POST ---
    if (!csrf_verify()) {
        $errors[] = 'Błąd weryfikacji formularza (token wygasł). Odśwież stronę i spróbuj ponownie.';
    }

    // --- Pobranie i wstępne oczyszczenie danych wejściowych ---
    $name             = trim(post_string('name'));
    $surname          = trim(post_string('surname'));
    $phone            = trim(post_string('phone'));
    $email            = trim(post_string('email'));
    $password         = post_string('password');
    $password_confirm = post_string('password_confirm');

    $old = compact('name', 'surname', 'phone', 'email');

    // --- Walidacja pól wymaganych ---
    if ($name === '' || $surname === '' || $phone === '' || $email === '' || $password === '' || $password_confirm === '') {
        $errors[] = 'Wszystkie pola są wymagane.';
    }

    $errors = array_merge($errors, personal_details_errors($name, $surname, $phone));

    // --- Walidacja adresu e-mail ---
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Podany adres e-mail jest nieprawidłowy.';
    }
    if (mb_strlen($email) > 100) {
        $errors[] = 'Adres e-mail jest zbyt długi.';
    }

    // --- Walidacja hasła ---
    if (($passwordError = password_validation_error($password)) !== null) {
        $errors[] = $passwordError;
    }
    if ($password !== $password_confirm) {
        $errors[] = 'Podane hasła nie są identyczne.';
    }

    // --- Sprawdzenie unikalności adresu e-mail (dopiero jeśli reszta walidacji przeszła) ---
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare('SELECT user_id FROM users WHERE customer_email = :email');
            $stmt->execute(['email' => $email]);

            if ($stmt->fetch() !== false) {
                $errors[] = 'Ten adres e-mail jest już zarejestrowany.';
            }
        } catch (PDOException $e) {
            error_log('Błąd sprawdzania e-maila przy rejestracji: ' . $e->getMessage());
            $errors[] = 'Wystąpił błąd podczas rejestracji. Spróbuj ponownie.';
        }
    }

    // --- Jeśli wszystko OK -> zapis do bazy ---
    if (empty($errors)) {
        try {
            // password_hash tworzy bezpieczny, "solony" hash hasła - hasło
            // NIGDY nie jest zapisywane w bazie jako czysty tekst.
            $password_hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare(
                'INSERT INTO users (name, surname, customer_phone, customer_email, password, role)
                 VALUES (:name, :surname, :phone, :email, :password, :role)'
            );

            $stmt->execute([
                'name'     => $name,
                'surname'  => $surname,
                'phone'    => $phone,
                'email'    => $email,
                'password' => $password_hash,
                'role'     => 'client', // rejestracja publiczna tworzy wyłącznie konta klienta
            ]);

            $success = true;
            $old = ['name' => '', 'surname' => '', 'phone' => '', 'email' => '']; // czyścimy formularz
        } catch (PDOException $e) {
            error_log('Błąd zapisu nowego użytkownika: ' . $e->getMessage());
            // UNIQUE chroni też przed dwoma równoczesnymi rejestracjami.
            $errors[] = (int)($e->errorInfo[1] ?? 0) === 1062
                ? 'Ten adres e-mail jest już zarejestrowany.'
                : 'Wystąpił błąd podczas zapisu konta. Spróbuj ponownie później.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Rejestracja - Michelin Restaurant</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container py-5" style="max-width: 520px;">
    <h1 class="mb-4 text-center">Rejestracja konta</h1>

    <?php if ($success): ?>
        <div class="alert alert-success" role="alert">
            Konto zostało utworzone pomyślnie. Możesz się teraz
            <a href="login.php" class="alert-link">zalogować</a>.
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger" role="alert">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="register.php" class="bg-white p-4 rounded-3 shadow-sm" novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
            <label for="name" class="form-label">Imię</label>
            <input type="text" class="form-control" id="name" name="name" maxlength="50"
                   value="<?= htmlspecialchars($old['name'], ENT_QUOTES, 'UTF-8') ?>" required>
        </div>
        <div class="mb-3">
            <label for="surname" class="form-label">Nazwisko</label>
            <input type="text" class="form-control" id="surname" name="surname" maxlength="80"
                   value="<?= htmlspecialchars($old['surname'], ENT_QUOTES, 'UTF-8') ?>" required>
        </div>
        <div class="mb-3">
            <label for="phone" class="form-label">Telefon</label>
            <input type="text" class="form-control" id="phone" name="phone" maxlength="20"
                   value="<?= htmlspecialchars($old['phone'], ENT_QUOTES, 'UTF-8') ?>" required>
        </div>
        <div class="mb-3">
            <label for="email" class="form-label">Adres e-mail</label>
            <input type="email" class="form-control" id="email" name="email" maxlength="100"
                   value="<?= htmlspecialchars($old['email'], ENT_QUOTES, 'UTF-8') ?>" required>
        </div>
        <div class="mb-3">
            <label for="password" class="form-label">Hasło (min. 8 znaków)</label>
            <input type="password" class="form-control" id="password" name="password" minlength="8" required>
        </div>
        <div class="mb-3">
            <label for="password_confirm" class="form-label">Powtórz hasło</label>
            <input type="password" class="form-control" id="password_confirm" name="password_confirm" minlength="8" required>
        </div>
        <button type="submit" class="btn btn-primary w-100">Zarejestruj się</button>
    </form>

    <p class="text-center mt-3">
        Masz już konto? <a href="login.php">Zaloguj się</a>
    </p>
</div>

</body>
</html>
