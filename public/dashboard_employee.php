<?php
/**
 * Plik: dashboard_employee.php
 * Cel: Panel pracownika - lista rezerwacji przypisanych do zalogowanego
 *      pracownika oraz możliwość zmiany ich statusu.
 */
require_once 'auth_check.php';
require_once 'db_connect.php';

// Tylko pracownik ma dostęp do tego panelu
require_role(['employee']);

$user_id = current_user_id();

// Dozwolone statusy rezerwacji (muszą odpowiadać ENUM w tabeli reservations)
$allowed_statuses = ['pending', 'confirmed', 'cancelled', 'completed', 'no_show'];
$status_labels = [
    'pending'   => ['Oczekująca', 'bg-warning text-dark'],
    'confirmed' => ['Potwierdzona', 'bg-success'],
    'cancelled' => ['Anulowana', 'bg-secondary'],
    'completed' => ['Zrealizowana', 'bg-primary'],
    'no_show'   => ['Nieobecność', 'bg-danger'],
];

$errors  = [];
$success = false;

// --- Sprawdzenie, czy zalogowany użytkownik ma przypisany profil pracownika ---
try {
    $stmt = $pdo->prepare(
        'SELECT employee_id, position FROM employees WHERE user_id = :user_id AND is_active = 1'
    );
    $stmt->execute(['user_id' => $user_id]);
    $employee = $stmt->fetch();
} catch (PDOException $e) {
    error_log('Błąd pobierania profilu pracownika: ' . $e->getMessage());
    $employee = false;
}

$employee_id = $employee ? (int) $employee['employee_id'] : null;

// --- Obsługa zmiany statusu rezerwacji ---
if ($employee_id !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $reservation_id = filter_input(INPUT_POST, 'reservation_id', FILTER_VALIDATE_INT);
    $new_status     = $_POST['status'] ?? '';

    if (!$reservation_id) {
        $errors[] = 'Nieprawidłowa rezerwacja.';
    }
    if (!in_array($new_status, $allowed_statuses, true)) {
        $errors[] = 'Nieprawidłowy status.';
    }

    if (empty($errors)) {
        try {
            // Warunek "AND employee_id = :employee_id" gwarantuje, że pracownik
            // może zmieniać status WYŁĄCZNIE swoich własnych rezerwacji.
            $stmt = $pdo->prepare(
                'UPDATE reservations
                 SET status = :status
                 WHERE reservation_id = :reservation_id AND employee_id = :employee_id'
            );
            $stmt->execute([
                'status'         => $new_status,
                'reservation_id' => $reservation_id,
                'employee_id'    => $employee_id,
            ]);

            if ($stmt->rowCount() > 0) {
                $success = true;
            } else {
                $errors[] = 'Nie znaleziono rezerwacji lub nie masz do niej uprawnień.';
            }
        } catch (PDOException $e) {
            error_log('Błąd zmiany statusu rezerwacji: ' . $e->getMessage());
            $errors[] = 'Wystąpił błąd podczas zapisu statusu.';
        }
    }
}

// --- Pobranie rezerwacji przypisanych do tego pracownika ---
$reservations = [];
if ($employee_id !== null) {
    try {
        $stmt = $pdo->prepare(
            'SELECT r.reservation_id, r.reservation_date, r.start_time, r.end_time,
                    r.guest_count, r.status, r.comment,
                    s.name AS service_name,
                    u.name AS client_name, u.surname AS client_surname, u.customer_phone
             FROM reservations r
             JOIN services s ON s.service_id = r.service_id
             JOIN users u    ON u.user_id = r.user_id
             WHERE r.employee_id = :employee_id
             ORDER BY r.reservation_date DESC, r.start_time DESC'
        );
        $stmt->execute(['employee_id' => $employee_id]);
        $reservations = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Błąd pobierania rezerwacji pracownika: ' . $e->getMessage());
        $reservations = [];
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Panel pracownika - Michelin Restaurant</title>
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
    <h1 class="mb-4">Panel pracownika</h1>

    <?php if ($employee === false): ?>
        <div class="alert alert-warning">
            Twoje konto ma rolę pracownika, ale nie znaleziono przypisanego do niego
            aktywnego profilu pracownika. Skontaktuj się z administratorem restauracji.
        </div>
    <?php else: ?>

        <p class="text-muted">Stanowisko: <strong><?= htmlspecialchars($employee['position'], ENT_QUOTES, 'UTF-8') ?></strong></p>

        <?php if ($success): ?>
            <div class="alert alert-success">Status rezerwacji został zaktualizowany.</div>
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
                <h2 class="h5 card-title">Moje rezerwacje</h2>

                <?php if (empty($reservations)): ?>
                    <p class="text-muted mb-0">Brak przypisanych rezerwacji.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>Klient</th>
                                    <th>Telefon</th>
                                    <th>Usługa</th>
                                    <th>Data</th>
                                    <th>Godzina</th>
                                    <th>Goście</th>
                                    <th>Status</th>
                                    <th>Zmień status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reservations as $r): ?>
                                    <?php
                                        $status_key   = $r['status'];
                                        $status_label = $status_labels[$status_key][0] ?? $status_key;
                                        $status_class = $status_labels[$status_key][1] ?? 'bg-secondary';
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($r['client_name'] . ' ' . $r['client_surname'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($r['customer_phone'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($r['service_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($r['reservation_date'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars(substr($r['start_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?>-<?= htmlspecialchars(substr($r['end_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= (int) $r['guest_count'] ?></td>
                                        <td><span class="badge <?= htmlspecialchars($status_class, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($status_label, ENT_QUOTES, 'UTF-8') ?></span></td>
                                        <td>
                                            <form method="post" action="dashboard_employee.php" class="d-flex gap-2">
                                                <input type="hidden" name="reservation_id" value="<?= (int) $r['reservation_id'] ?>">
                                                <select name="status" class="form-select form-select-sm">
                                                    <?php foreach ($allowed_statuses as $status_option): ?>
                                                        <option value="<?= htmlspecialchars($status_option, ENT_QUOTES, 'UTF-8') ?>"
                                                            <?= $status_option === $status_key ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($status_labels[$status_option][0], ENT_QUOTES, 'UTF-8') ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="btn btn-sm btn-outline-primary">Zapisz</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
