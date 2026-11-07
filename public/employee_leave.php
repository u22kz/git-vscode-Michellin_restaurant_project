<?php
require_once 'auth_check.php';
require_once 'db_connect.php';

require_role(['employee']);

$userId = current_user_id();

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Pobranie profilu zalogowanego pracownika
$stmt = $pdo->prepare("
    SELECT e.employee_id, u.name, u.surname
    FROM employees e
    JOIN users u ON u.user_id = e.user_id
    WHERE e.user_id = ?
      AND e.is_active = 1
      AND u.is_active = 1
    LIMIT 1
");
$stmt->execute([$userId]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    http_response_code(403);
    exit('Nie znaleziono aktywnego profilu pracownika.');
}

$employeeId = (int)$employee['employee_id'];
$message = '';
$messageType = 'info';

// Obsługa formularzy
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $action = $_POST['action'] ?? '';

    // Złożenie nowego wniosku
    if ($action === 'create') {
        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? '';
        $reason = trim($_POST['reason'] ?? '');

        $validStart = preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)
            && checkdate(
                (int)substr($startDate, 5, 2),
                (int)substr($startDate, 8, 2),
                (int)substr($startDate, 0, 4)
            );

        $validEnd = preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)
            && checkdate(
                (int)substr($endDate, 5, 2),
                (int)substr($endDate, 8, 2),
                (int)substr($endDate, 0, 4)
            );

        if (!$validStart || !$validEnd) {
            $message = 'Wprowadź prawidłowe daty.';
            $messageType = 'danger';
        } elseif ($startDate < date('Y-m-d')) {
            $message = 'Nie można składać wniosku z datą rozpoczęcia w przeszłości.';
            $messageType = 'danger';
        } elseif ($endDate < $startDate) {
            $message = 'Data zakończenia nie może być wcześniejsza niż data rozpoczęcia.';
            $messageType = 'danger';
        } else {
            // Sprawdzenie, czy nie istnieje inny aktywny wniosek
            $check = $pdo->prepare("
                SELECT COUNT(*)
                FROM employee_leave_requests
                WHERE employee_id = ?
                  AND status IN ('pending', 'approved')
                  AND start_date <= ?
                  AND end_date >= ?
            ");
            $check->execute([$employeeId, $endDate, $startDate]);

            if ((int)$check->fetchColumn() > 0) {
                $message = 'Masz już wniosek oczekujący lub zatwierdzony w tym terminie.';
                $messageType = 'warning';
            } else {
                $insert = $pdo->prepare("
                    INSERT INTO employee_leave_requests
                    (employee_id, start_date, end_date, reason)
                    VALUES (?, ?, ?, ?)
                ");

                $insert->execute([
                    $employeeId,
                    $startDate,
                    $endDate,
                    $reason !== '' ? $reason : null
                ]);

                header('Location: employee_leave.php?msg=created');
                exit;
            }
        }
    }

    // Anulowanie własnego oczekującego wniosku
    elseif ($action === 'cancel') {
        $requestId = (int)($_POST['request_id'] ?? 0);

        $cancel = $pdo->prepare("
            UPDATE employee_leave_requests
            SET status = 'cancelled'
            WHERE leave_request_id = ?
              AND employee_id = ?
              AND status = 'pending'
        ");

        $cancel->execute([$requestId, $employeeId]);

        if ($cancel->rowCount() > 0) {
            header('Location: employee_leave.php?msg=cancelled');
            exit;
        } else {
            $message = 'Nie udało się anulować wniosku.';
            $messageType = 'danger';
        }
    }
}

// Komunikaty po przekierowaniu
$messages = [
    'created' => ['Wniosek został złożony.', 'success'],
    'cancelled' => ['Wniosek został anulowany.', 'success']
];

$msgCode = $_GET['msg'] ?? '';

if (isset($messages[$msgCode])) {
    $message = $messages[$msgCode][0];
    $messageType = $messages[$msgCode][1];
}

// Pobranie wniosków pracownika
$stmt = $pdo->prepare("
    SELECT
        r.*,
        u.name AS reviewer_name,
        u.surname AS reviewer_surname
    FROM employee_leave_requests r
    LEFT JOIN users u ON u.user_id = r.reviewed_by_user_id
    WHERE r.employee_id = ?
    ORDER BY r.created_at DESC
");
$stmt->execute([$employeeId]);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$statusLabels = [
    'pending' => 'Oczekuje na decyzję',
    'approved' => 'Zatwierdzony',
    'rejected' => 'Odrzucony',
    'cancelled' => 'Anulowany'
];

$statusColors = [
    'pending' => 'warning',
    'approved' => 'success',
    'rejected' => 'danger',
    'cancelled' => 'secondary'
];
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Moje wnioski urlopowe - Michelin Restaurant</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">
</head>

<body class="bg-light">

<nav class="navbar navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand" href="dashboard_employee.php">
            Michelin Restaurant
        </a>

        <div class="d-flex gap-3 align-items-center">
            <a href="dashboard_employee.php" class="btn btn-outline-light btn-sm">
                Panel pracownika
            </a>
            <a href="logout.php" class="btn btn-outline-danger btn-sm">
                Wyloguj
            </a>
        </div>
    </div>
</nav>

<main class="container py-4">

    <h2 class="mb-1">Moje wnioski urlopowe</h2>

    <p class="text-muted mb-4">
        Witaj, <?= e($employee['name'] . ' ' . $employee['surname']) ?>.
        Tutaj możesz składać i sprawdzać swoje wnioski urlopowe.
    </p>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= e($messageType) ?>">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-header">
            <strong>Złóż nowy wniosek urlopowy</strong>
        </div>

        <div class="card-body">
            <form method="POST">
                <?= csrf_field() ?>

                <input type="hidden" name="action" value="create">

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="start_date" class="form-label">
                            Data rozpoczęcia
                        </label>

                        <input type="date"
                               class="form-control"
                               id="start_date"
                               name="start_date"
                               min="<?= e(date('Y-m-d')) ?>"
                               required>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label for="end_date" class="form-label">
                            Data zakończenia
                        </label>

                        <input type="date"
                               class="form-control"
                               id="end_date"
                               name="end_date"
                               min="<?= e(date('Y-m-d')) ?>"
                               required>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="reason" class="form-label">
                        Powód / dodatkowe informacje
                    </label>

                    <textarea class="form-control"
                              id="reason"
                              name="reason"
                              rows="3"
                              maxlength="500"
                              placeholder="Opcjonalne informacje..."></textarea>
                </div>

                <button type="submit" class="btn btn-primary">
                    Złóż wniosek
                </button>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header">
            <strong>Historia moich wniosków</strong>
        </div>

        <div class="card-body">

            <?php if (empty($requests)): ?>

                <p class="text-muted mb-0">
                    Nie złożyłeś jeszcze żadnego wniosku urlopowego.
                </p>

            <?php else: ?>

                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Od</th>
                                <th>Do</th>
                                <th>Powód</th>
                                <th>Status</th>
                                <th>Decyzja administratora</th>
                                <th>Akcje</th>
                            </tr>
                        </thead>

                        <tbody>
                        <?php foreach ($requests as $request): ?>
                            <tr>
                                <td><?= e($request['start_date']) ?></td>

                                <td><?= e($request['end_date']) ?></td>

                                <td>
                                    <?= e($request['reason'] ?: '-') ?>
                                </td>

                                <td>
                                    <span class="badge bg-<?= e(
                                        $statusColors[$request['status']] ?? 'secondary'
                                    ) ?>">
                                        <?= e(
                                            $statusLabels[$request['status']] ?? $request['status']
                                        ) ?>
                                    </span>
                                </td>

                                <td>
                                    <?php if ($request['reviewed_at']): ?>
                                        <?= e($request['reviewed_at']) ?>

                                        <?php if ($request['reviewer_name']): ?>
                                            <br>
                                            <small class="text-muted">
                                                <?= e(
                                                    $request['reviewer_name'] . ' ' .
                                                    $request['reviewer_surname']
                                                ) ?>
                                            </small>
                                        <?php endif; ?>

                                        <?php if ($request['admin_comment']): ?>
                                            <br>
                                            <small>
                                                <?= e($request['admin_comment']) ?>
                                            </small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">Brak decyzji</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($request['status'] === 'pending'): ?>
                                        <form method="POST"
                                              onsubmit="return confirm('Czy na pewno anulować ten wniosek?');">

                                            <?= csrf_field() ?>

                                            <input type="hidden"
                                                   name="action"
                                                   value="cancel">

                                            <input type="hidden"
                                                   name="request_id"
                                                   value="<?= (int)$request['leave_request_id'] ?>">

                                            <button type="submit"
                                                    class="btn btn-sm btn-outline-danger">
                                                Anuluj
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        -
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

</main>

</body>
</html>