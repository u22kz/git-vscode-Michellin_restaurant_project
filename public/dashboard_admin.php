<?php
/**
 * Plik: dashboard_admin.php
 * Cel: Panel administratora - lista wszystkich użytkowników wraz z
 *      możliwością zmiany ich roli oraz aktywowania/dezaktywowania konta.
 */
require_once 'auth_check.php';
require_once 'db_connect.php';

// Tylko administrator ma dostęp do tego panelu
require_role(['admin']);

$admin_id = current_user_id();

$allowed_roles = ['client', 'employee', 'admin'];
$errors  = [];
$success = false;

// --- Obsługa zmiany roli użytkownika ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_role') {

    $target_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $new_role  = $_POST['role'] ?? '';

    if (!$target_id) {
        $errors[] = 'Nieprawidłowy użytkownik.';
    }
    if (!in_array($new_role, $allowed_roles, true)) {
        $errors[] = 'Nieprawidłowa rola.';
    }
    if ($target_id === $admin_id) {
        $errors[] = 'Nie możesz zmienić roli własnego konta.';
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare('UPDATE users SET role = :role WHERE user_id = :id');
            $stmt->execute(['role' => $new_role, 'id' => $target_id]);
            $success = true;
        } catch (PDOException $e) {
            error_log('Błąd zmiany roli użytkownika: ' . $e->getMessage());
            $errors[] = 'Wystąpił błąd podczas zapisu zmiany roli.';
        }
    }
}

// --- Obsługa aktywacji / dezaktywacji konta ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_active') {

    $target_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);

    if (!$target_id) {
        $errors[] = 'Nieprawidłowy użytkownik.';
    } elseif ($target_id === $admin_id) {
        $errors[] = 'Nie możesz dezaktywować własnego konta.';
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare('UPDATE users SET is_active = NOT is_active WHERE user_id = :id');
            $stmt->execute(['id' => $target_id]);
            $success = true;
        } catch (PDOException $e) {
            error_log('Błąd zmiany statusu aktywności konta: ' . $e->getMessage());
            $errors[] = 'Wystąpił błąd podczas zapisu zmiany.';
        }
    }
}

// --- Pobranie listy wszystkich użytkowników ---
try {
    $stmt = $pdo->query(
        'SELECT user_id, name, surname, customer_email, customer_phone, role, is_active, created_at
         FROM users
         ORDER BY user_id'
    );
    $users = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Błąd pobierania listy użytkowników: ' . $e->getMessage());
    $users = [];
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Panel administratora - Michelin Restaurant</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="index.php">Michelin Restaurant</a>
        <div>
            <span class="text-light me-3">Witaj, <?= htmlspecialchars(current_user_name(), ENT_QUOTES, 'UTF-8') ?></span>
            <a class="btn btn-outline-light btn-sm me-2" href="profile.php">Profil</a>
            <a class="btn btn-danger btn-sm" href="logout.php">Wyloguj</a>
        </div>
    </div>
</nav>

<div class="container pb-5">
    <h1 class="mb-4">Panel administratora</h1>

    <?php if ($success): ?>
        <div class="alert alert-success">Zmiana została zapisana.</div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <h2 class="h5 card-title">Użytkownicy systemu</h2>

            <?php if (empty($users)): ?>
                <p class="text-muted mb-0">Brak użytkowników w bazie.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Imię i nazwisko</th>
                                <th>E-mail</th>
                                <th>Telefon</th>
                                <th>Rola</th>
                                <th>Status</th>
                                <th>Zmień rolę</th>
                                <th>Konto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                                <?php $is_self = ((int) $u['user_id'] === $admin_id); ?>
                                <tr>
                                    <td><?= (int) $u['user_id'] ?></td>
                                    <td><?= htmlspecialchars($u['name'] . ' ' . $u['surname'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($u['customer_email'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($u['customer_phone'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><span class="badge bg-info text-dark"><?= htmlspecialchars($u['role'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td>
                                        <?php if ((int) $u['is_active'] === 1): ?>
                                            <span class="badge bg-success">Aktywne</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Nieaktywne</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($is_self): ?>
                                            <span class="text-muted small">To Twoje konto</span>
                                        <?php else: ?>
                                            <form method="post" action="dashboard_admin.php" class="d-flex gap-2">
                                                <input type="hidden" name="action" value="change_role">
                                                <input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>">
                                                <select name="role" class="form-select form-select-sm">
                                                    <?php foreach ($allowed_roles as $role_option): ?>
                                                        <option value="<?= htmlspecialchars($role_option, ENT_QUOTES, 'UTF-8') ?>"
                                                            <?= $role_option === $u['role'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($role_option, ENT_QUOTES, 'UTF-8') ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="btn btn-sm btn-outline-primary">Zapisz</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$is_self): ?>
                                            <form method="post" action="dashboard_admin.php">
                                                <input type="hidden" name="action" value="toggle_active">
                                                <input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>">
                                                <?php if ((int) $u['is_active'] === 1): ?>
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Dezaktywuj</button>
                                                <?php else: ?>
                                                    <button type="submit" class="btn btn-sm btn-outline-success">Aktywuj</button>
                                                <?php endif; ?>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

</body>
</html>
