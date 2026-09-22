<?php
/**
 * Plik: login.php
 * Cel: Logowanie użytkownika - pobranie konta z bazy po adresie e-mail,
 *      weryfikacja hasła (password_verify), utworzenie sesji PHP
 *      i przekierowanie do panelu odpowiedniego dla roli użytkownika.
 */
require_once 'auth_check.php';
require_once 'db_connect.php';

// Zalogowany użytkownik nie potrzebuje strony logowania
if (is_logged_in()) {
    header('Location: ' . dashboard_url_for_role(current_role()));
    exit;
}

$errors = [];
$old_email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $old_email = $email;

    if ($email === '' || $password === '') {
        $errors[] = 'Podaj adres e-mail i hasło.';
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare(
                'SELECT user_id, name, surname, password, role, is_active
                 FROM users
                 WHERE customer_email = :email'
            );
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            // Celowo generyczny komunikat - nie zdradzamy, czy problemem
            // był zły e-mail, złe hasło czy nieaktywne konto (ochrona przed
            // wyliczaniem zarejestrowanych adresów e-mail).
            $generic_error = 'Nieprawidłowy adres e-mail lub hasło.';

            if ($user === false) {
                $errors[] = $generic_error;
            } elseif (!password_verify($password, $user['password'])) {
                $errors[] = $generic_error;
            } elseif ((int) $user['is_active'] !== 1) {
                $errors[] = 'To konto zostało dezaktywowane. Skontaktuj się z administratorem.';
            } else {
                // Dane logowania poprawne - tworzymy sesję.
                // regenerate_id chroni przed atakiem session fixation.
                session_regenerate_id(true);

                $_SESSION['user_id'] = (int) $user['user_id'];
                $_SESSION['name']    = $user['name'] . ' ' . $user['surname'];
                $_SESSION['role']    = $user['role'];

                header('Location: ' . dashboard_url_for_role($user['role']));
                exit;
            }
        } catch (PDOException $e) {
            error_log('Błąd logowania: ' . $e->getMessage());
            $errors[] = 'Wystąpił błąd podczas logowania. Spróbuj ponownie później.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Logowanie - Michelin Restaurant</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container py-5" style="max-width: 420px;">
    <h1 class="mb-4 text-center">Logowanie</h1>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger" role="alert">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="login.php" class="bg-white p-4 rounded-3 shadow-sm" novalidate>
        <div class="mb-3">
            <label for="email" class="form-label">Adres e-mail</label>
            <input type="email" class="form-control" id="email" name="email"
                   value="<?= htmlspecialchars($old_email, ENT_QUOTES, 'UTF-8') ?>" required>
        </div>
        <div class="mb-3">
            <label for="password" class="form-label">Hasło</label>
            <input type="password" class="form-control" id="password" name="password" required>
        </div>
        <button type="submit" class="btn btn-primary w-100">Zaloguj się</button>
    </form>

    <p class="text-center mt-3">
        Nie masz konta? <a href="register.php">Zarejestruj się</a>
    </p>
</div>

</body>
</html>
