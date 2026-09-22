<?php
/**
 * Plik: profile.php
 * Cel: Profil zalogowanego użytkownika (dowolna rola) - podgląd danych
 *      oraz edycja imienia, nazwiska i telefonu (bez zmiany e-maila i roli).
 *      Osobny formularz pozwala zmienić hasło.
 */
require_once 'auth_check.php';
require_once 'db_connect.php';

// Dostępne dla każdego zalogowanego użytkownika, niezależnie od roli
require_login();

$user_id = current_user_id();

$info_errors    = [];
$info_success   = false;
$password_errors  = [];
$password_success = false;

// --- Pobranie aktualnych danych użytkownika ---
try {
    $stmt = $pdo->prepare(
        'SELECT name, surname, customer_phone, customer_email, role, password
         FROM users WHERE user_id = :id'
    );
    $stmt->execute(['id' => $user_id]);
    $user = $stmt->fetch();

    if ($user === false) {
        // Sesja wskazuje na użytkownika, którego już nie ma w bazie
        header('Location: logout.php');
        exit;
    }
} catch (PDOException $e) {
    error_log('Błąd pobierania profilu: ' . $e->getMessage());
    die('Wystąpił błąd podczas wczytywania profilu.');
}

// --- Obsługa formularza edycji danych (imię, nazwisko, telefon) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_info'])) {

    $name    = trim($_POST['name'] ?? '');
    $surname = trim($_POST['surname'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');

    if ($name === '' || $surname === '' || $phone === '') {
        $info_errors[] = 'Wszystkie pola są wymagane.';
    }
    if (mb_strlen($name) > 50) {
        $info_errors[] = 'Imię może mieć maksymalnie 50 znaków.';
    }
    if (mb_strlen($surname) > 80) {
        $info_errors[] = 'Nazwisko może mieć maksymalnie 80 znaków.';
    }
    if ($phone !== '' && !preg_match('/^[0-9+\-\s]{9,20}$/', $phone)) {
        $info_errors[] = 'Numer telefonu ma nieprawidłowy format.';
    }

    if (empty($info_errors)) {
        try {
            $stmt = $pdo->prepare(
                'UPDATE users SET name = :name, surname = :surname, customer_phone = :phone
                 WHERE user_id = :id'
            );
            $stmt->execute([
                'name'    => $name,
                'surname' => $surname,
                'phone'   => $phone,
                'id'      => $user_id,
            ]);

            // Aktualizujemy dane w sesji (wyświetlane np. w nawigacji) i w bieżącym widoku
            $_SESSION['name'] = $name . ' ' . $surname;
            $user['name']            = $name;
            $user['surname']         = $surname;
            $user['customer_phone']  = $phone;

            $info_success = true;
        } catch (PDOException $e) {
            error_log('Błąd aktualizacji profilu: ' . $e->getMessage());
            $info_errors[] = 'Wystąpił błąd podczas zapisu danych.';
        }
    }
}

// --- Obsługa formularza zmiany hasła ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {

    $current_password = $_POST['current_password'] ?? '';
    $new_password      = $_POST['new_password'] ?? '';
    $new_password_confirm = $_POST['new_password_confirm'] ?? '';

    if ($current_password === '' || $new_password === '' || $new_password_confirm === '') {
        $password_errors[] = 'Wszystkie pola hasła są wymagane.';
    } elseif (!password_verify($current_password, $user['password'])) {
        $password_errors[] = 'Aktualne hasło jest nieprawidłowe.';
    } elseif (strlen($new_password) < 8) {
        $password_errors[] = 'Nowe hasło musi mieć co najmniej 8 znaków.';
    } elseif ($new_password !== $new_password_confirm) {
        $password_errors[] = 'Nowe hasła nie są identyczne.';
    }

    if (empty($password_errors)) {
        try {
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('UPDATE users SET password = :password WHERE user_id = :id');
            $stmt->execute(['password' => $new_hash, 'id' => $user_id]);
            $password_success = true;
        } catch (PDOException $e) {
            error_log('Błąd zmiany hasła: ' . $e->getMessage());
            $password_errors[] = 'Wystąpił błąd podczas zmiany hasła.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Mój profil - Michelin Restaurant</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="index.php">Michelin Restaurant</a>
        <div>
            <a class="btn btn-outline-light btn-sm me-2" href="<?= htmlspecialchars(dashboard_url_for_role(current_role()), ENT_QUOTES, 'UTF-8') ?>">Mój panel</a>
            <a class="btn btn-danger btn-sm" href="logout.php">Wyloguj</a>
        </div>
    </div>
</nav>

<div class="container pb-5" style="max-width: 640px;">
    <h1 class="mb-4">Mój profil</h1>

    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h5 card-title">Dane konta</h2>

            <?php if ($info_success): ?>
                <div class="alert alert-success">Dane zostały zaktualizowane.</div>
            <?php endif; ?>
            <?php if (!empty($info_errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($info_errors as $error): ?>
                            <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" action="profile.php">
                <input type="hidden" name="update_info" value="1">

                <div class="mb-3">
                    <label class="form-label">Adres e-mail (nie można zmienić)</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($user['customer_email'], ENT_QUOTES, 'UTF-8') ?>" disabled>
                </div>
                <div class="mb-3">
                    <label class="form-label">Rola w systemie</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8') ?>" disabled>
                </div>
                <div class="mb-3">
                    <label for="name" class="form-label">Imię</label>
                    <input type="text" class="form-control" id="name" name="name" maxlength="50"
                           value="<?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="mb-3">
                    <label for="surname" class="form-label">Nazwisko</label>
                    <input type="text" class="form-control" id="surname" name="surname" maxlength="80"
                           value="<?= htmlspecialchars($user['surname'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="mb-3">
                    <label for="phone" class="form-label">Telefon</label>
                    <input type="text" class="form-control" id="phone" name="phone" maxlength="20"
                           value="<?= htmlspecialchars($user['customer_phone'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>

                <button type="submit" class="btn btn-primary">Zapisz zmiany</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h2 class="h5 card-title">Zmiana hasła</h2>

            <?php if ($password_success): ?>
                <div class="alert alert-success">Hasło zostało zmienione.</div>
            <?php endif; ?>
            <?php if (!empty($password_errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($password_errors as $error): ?>
                            <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" action="profile.php">
                <input type="hidden" name="change_password" value="1">

                <div class="mb-3">
                    <label for="current_password" class="form-label">Aktualne hasło</label>
                    <input type="password" class="form-control" id="current_password" name="current_password" required>
                </div>
                <div class="mb-3">
                    <label for="new_password" class="form-label">Nowe hasło (min. 8 znaków)</label>
                    <input type="password" class="form-control" id="new_password" name="new_password" minlength="8" required>
                </div>
                <div class="mb-3">
                    <label for="new_password_confirm" class="form-label">Powtórz nowe hasło</label>
                    <input type="password" class="form-control" id="new_password_confirm" name="new_password_confirm" minlength="8" required>
                </div>

                <button type="submit" class="btn btn-outline-primary">Zmień hasło</button>
            </form>
        </div>
    </div>
</div>

</body>
</html>
