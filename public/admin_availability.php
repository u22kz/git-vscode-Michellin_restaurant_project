<?php
require_once 'auth_check.php';
require_once 'db_connect.php';
require_role(['admin']);

$errors = [];

$escape = static function ($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

$days = [
    1 => 'Poniedziałek',
    2 => 'Wtorek',
    3 => 'Środa',
    4 => 'Czwartek',
    5 => 'Piątek',
    6 => 'Sobota',
    7 => 'Niedziela'
];

$formAvailabilityId = 0;
$formEmployeeId = 0;
$formDayOfWeek = 1;
$formStartTime = '09:00';
$formEndTime = '17:00';

// Obsługa formularzy
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    $action = $_POST['action'] ?? '';

    // Wyjątki od grafiku i decyzje urlopowe pozostają w tym samym panelu.
    if (in_array($action, ['day_off_add', 'day_off_delete', 'closure_add', 'closure_delete', 'leave_review'], true)) {
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT employee_id FROM employees ORDER BY employee_id FOR UPDATE');
            $lock->execute();
            $employeeIds = array_map('intval', $lock->fetchAll(PDO::FETCH_COLUMN));
            $positiveId = static function (string $field): int {
                $id = filter_var($_POST[$field] ?? '', FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
                if ($id === false) throw new RuntimeException('Nieprawidłowy identyfikator.');
                return $id;
            };
            if ($action === 'day_off_add' || $action === 'closure_add') {
                $date = $_POST['day_off_date'] ?? '';
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if (!$parsed || $parsed->format('Y-m-d') !== $date || $date < date('Y-m-d')) {
                    throw new RuntimeException('Podaj prawidłową datę, nie wcześniejszą niż dzisiaj.');
                }
                $reason = trim($_POST['reason'] ?? '');
                if ($action === 'day_off_add') {
                    $id = $positiveId('employee_id');
                    if (!in_array($id, $employeeIds, true)) throw new RuntimeException('Pracownik nie istnieje.');
                    if (mb_strlen($reason) > 255) throw new RuntimeException('Powód może mieć najwyżej 255 znaków.');
                    $stmt = $pdo->prepare('INSERT INTO employee_days_off (employee_id, day_off_date, reason, created_by_user_id) VALUES (?, ?, ?, ?)');
                    $stmt->execute([$id, $date, $reason ?: null, current_user_id()]);
                } else {
                    if ($reason === '' || mb_strlen($reason) > 150) throw new RuntimeException('Nazwa zamknięcia musi mieć od 1 do 150 znaków.');
                    $stmt = $pdo->prepare('INSERT INTO restaurant_days_off (day_off_date, name, created_by_user_id) VALUES (?, ?, ?)');
                    $stmt->execute([$date, $reason, current_user_id()]);
                }
            } elseif ($action === 'leave_review') {
                $id = $positiveId('leave_request_id');
                $status = $_POST['status'] ?? '';
                $comment = trim($_POST['admin_comment'] ?? '');
                if (!in_array($status, ['approved', 'rejected'], true) || strlen($comment) > 65535) {
                    throw new RuntimeException('Nieprawidłowa decyzja lub zbyt długi komentarz.');
                }
                $stmt = $pdo->prepare('SELECT * FROM employee_leave_requests WHERE leave_request_id = ? FOR UPDATE');
                $stmt->execute([$id]);
                $request = $stmt->fetch();
                if (!$request || $request['status'] !== 'pending') throw new RuntimeException('Wniosek nie oczekuje już na decyzję.');
                if ($status === 'approved') {
                    if ($request['start_date'] < date('Y-m-d')) throw new RuntimeException('Nie można zatwierdzić urlopu z początkiem w przeszłości.');
                    $stmt = $pdo->prepare("SELECT leave_request_id FROM employee_leave_requests WHERE employee_id = ? AND status = 'approved' AND start_date <= ? AND end_date >= ? FOR UPDATE");
                    $stmt->execute([$request['employee_id'], $request['end_date'], $request['start_date']]);
                    if ($stmt->fetch()) throw new RuntimeException('Urlop nakłada się na zatwierdzony wniosek.');
                }
                $stmt = $pdo->prepare('UPDATE employee_leave_requests SET status = ?, admin_comment = ?, reviewed_by_user_id = ?, reviewed_at = NOW() WHERE leave_request_id = ?');
                $stmt->execute([$status, $comment ?: null, current_user_id(), $id]);
            } else {
                $id = $positiveId('record_id');
                $sql = $action === 'day_off_delete'
                    ? 'DELETE FROM employee_days_off WHERE day_off_id = ?'
                    : 'DELETE FROM restaurant_days_off WHERE restaurant_day_off_id = ?';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$id]);
                if ($stmt->rowCount() !== 1) throw new RuntimeException('Nie znaleziono dnia wolnego.');
            }
            $pdo->commit();
            header('Location: admin_availability.php?success=exception');
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = $e->getCode() === '23000' ? 'Taki dzień wolny już istnieje lub wskazany rekord nie istnieje.' : 'Nie udało się zapisać zmian dostępności.';
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = $e->getMessage();
        }
    }

    // Usuwanie przedziału dostępności
    if ($action === 'delete') {
        $availabilityId = filter_var($_POST['availability_id'] ?? '', FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);

        if ($availabilityId !== false) {
            try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT employee_id FROM employees ORDER BY employee_id FOR UPDATE');
            $lock->execute();
            $lock->fetchAll();
            $stmt = $pdo->prepare("
                DELETE FROM employee_availability
                WHERE availability_id = ?
            ");
            $stmt->execute([$availabilityId]);
            if ($stmt->rowCount() !== 1) throw new RuntimeException('Nie znaleziono przedziału.');
            $pdo->commit();
            header('Location: admin_availability.php?success=deleted');
            exit;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = 'Nie udało się usunąć przedziału.';
            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = $e->getMessage();
            }
        } else {
            $errors[] = 'Nieprawidłowy identyfikator dostępności.';
        }
    }

    // Dodawanie lub edytowanie dostępności
    if ($action === 'save') {
        try {
        $pdo->beginTransaction();
        // Jedna kolejność blokad także przy przenoszeniu grafiku do innej osoby.
        $lock = $pdo->prepare('SELECT employee_id FROM employees ORDER BY employee_id FOR UPDATE');
        $lock->execute();
        $lock->fetchAll();
        $formAvailabilityId = (int)($_POST['availability_id'] ?? 0);
        $formEmployeeId = (int)($_POST['employee_id'] ?? 0);
        $formDayOfWeek = (int)($_POST['day_of_week'] ?? 0);
        $formStartTime = trim($_POST['start_time'] ?? '');
        $formEndTime = trim($_POST['end_time'] ?? '');
        foreach (['availability_id' => 0, 'employee_id' => 1, 'day_of_week' => 1] as $field => $min) {
            if (filter_var($_POST[$field] ?? ($min === 0 ? '0' : ''), FILTER_VALIDATE_INT,
                ['options' => ['min_range' => $min, 'max_range' => $field === 'day_of_week' ? 7 : 4294967295]]) === false) {
                $errors[] = 'Nieprawidłowy identyfikator lub dzień tygodnia.';
            }
        }

        // Sprawdzenie pracownika
        if ($formEmployeeId <= 0) {
            $errors[] = 'Wybierz pracownika.';
        } else {
            $stmt = $pdo->prepare("
                SELECT employee_id
                FROM employees
                WHERE employee_id = ?
            ");
            $stmt->execute([$formEmployeeId]);

            if (!$stmt->fetch()) {
                $errors[] = 'Wybrany pracownik nie istnieje.';
            }
        }

        // Sprawdzenie dnia tygodnia
        if (!array_key_exists($formDayOfWeek, $days)) {
            $errors[] = 'Wybierz prawidłowy dzień tygodnia.';
        }

        // Sprawdzenie godzin
        $timePattern = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';

        if (
            !preg_match($timePattern, $formStartTime) ||
            !preg_match($timePattern, $formEndTime)
        ) {
            $errors[] = 'Podaj prawidłowe godziny.';
        } elseif ($formStartTime >= $formEndTime) {
            $errors[] = 'Godzina zakończenia musi być późniejsza niż rozpoczęcia.';
        }

        // Sprawdzenie istnienia edytowanego wpisu
        if ($formAvailabilityId > 0) {
            $stmt = $pdo->prepare("
                SELECT availability_id
                FROM employee_availability
                WHERE availability_id = ?
            ");
            $stmt->execute([$formAvailabilityId]);

            if (!$stmt->fetch()) {
                $errors[] = 'Nie znaleziono edytowanego wpisu.';
            }
        }

        // Sprawdzenie nakładania się godzin
        if (empty($errors)) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM employee_availability
                WHERE employee_id = ?
                  AND day_of_week = ?
                  AND availability_id <> ?
                  AND start_time < ?
                  AND end_time > ?
            ");

            $stmt->execute([
                $formEmployeeId,
                $formDayOfWeek,
                $formAvailabilityId,
                $formEndTime,
                $formStartTime
            ]);

            if ((int)$stmt->fetchColumn() > 0) {
                $errors[] = 'Ten przedział godzinowy nakłada się na inną dostępność pracownika.';
            }
        }

        // Zapis danych
        if (empty($errors)) {
                if ($formAvailabilityId > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE employee_availability
                        SET employee_id = ?,
                            day_of_week = ?,
                            start_time = ?,
                            end_time = ?
                        WHERE availability_id = ?
                    ");

                    $stmt->execute([
                        $formEmployeeId,
                        $formDayOfWeek,
                        $formStartTime,
                        $formEndTime,
                        $formAvailabilityId
                    ]);

                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO employee_availability
                            (employee_id, day_of_week, start_time, end_time)
                        VALUES (?, ?, ?, ?)
                    ");

                    $stmt->execute([
                        $formEmployeeId,
                        $formDayOfWeek,
                        $formStartTime,
                        $formEndTime
                    ]);

                }

                $pdo->commit();
                header('Location: admin_availability.php?success=' . ($formAvailabilityId > 0 ? 'updated' : 'added'));
                exit;

        }
        $pdo->rollBack();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Wystąpił błąd podczas zapisywania dostępności.';
        }
    }
}

// Pobranie danych do edycji
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];

    if ($editId > 0) {
        $stmt = $pdo->prepare("
            SELECT *
            FROM employee_availability
            WHERE availability_id = ?
        ");
        $stmt->execute([$editId]);

        $availabilityToEdit = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($availabilityToEdit) {
            $formAvailabilityId = (int)$availabilityToEdit['availability_id'];
            $formEmployeeId = (int)$availabilityToEdit['employee_id'];
            $formDayOfWeek = (int)$availabilityToEdit['day_of_week'];
            $formStartTime = substr($availabilityToEdit['start_time'], 0, 5);
            $formEndTime = substr($availabilityToEdit['end_time'], 0, 5);
        } else {
            $errors[] = 'Nie znaleziono wpisu do edycji.';
        }
    }
}

// Lista pracowników
$stmt = $pdo->prepare("
    SELECT
        e.employee_id,
        e.position,
        e.is_active,
        u.name,
        u.surname
    FROM employees e
    JOIN users u ON u.user_id = e.user_id
    ORDER BY u.surname, u.name
");
$stmt->execute();

$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Lista dostępności
$stmt = $pdo->prepare("
    SELECT
        ea.availability_id,
        ea.employee_id,
        ea.day_of_week,
        ea.start_time,
        ea.end_time,
        e.position,
        e.is_active,
        u.name,
        u.surname
    FROM employee_availability ea
    JOIN employees e ON e.employee_id = ea.employee_id
    JOIN users u ON u.user_id = e.user_id
    ORDER BY ea.day_of_week, u.surname, ea.start_time
");
$stmt->execute();

$availabilities = $stmt->fetchAll(PDO::FETCH_ASSOC);

$isEditing = $formAvailabilityId > 0;

$stmt = $pdo->prepare('SELECT d.*, u.name, u.surname FROM employee_days_off d JOIN employees e ON e.employee_id = d.employee_id JOIN users u ON u.user_id = e.user_id ORDER BY d.day_off_date DESC');
$stmt->execute();
$daysOff = $stmt->fetchAll();
$stmt = $pdo->prepare('SELECT * FROM restaurant_days_off ORDER BY day_off_date DESC');
$stmt->execute();
$closures = $stmt->fetchAll();
$stmt = $pdo->prepare('SELECT l.*, u.name, u.surname FROM employee_leave_requests l JOIN employees e ON e.employee_id = l.employee_id JOIN users u ON u.user_id = e.user_id ORDER BY l.created_at DESC');
$stmt->execute();
$leaveRequests = $stmt->fetchAll();

$successMessages = [
    'exception' => 'Zapisano zmianę dostępności. Istniejące rezerwacje pozostają bez zmian — sprawdź je w panelu rezerwacji.',
    'added' => 'Dodano dostępność pracownika.',
    'updated' => 'Zaktualizowano dostępność pracownika.',
    'deleted' => 'Usunięto przedział dostępności.'
];
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Dostępność pracowników - Michelin Restaurant</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
</head>

<body class="bg-light">

<nav class="navbar navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="dashboard_admin.php">
            Michelin Restaurant - Panel administratora
        </a>

        <a href="dashboard_admin.php" class="btn btn-outline-light btn-sm">
            Powrót do panelu
        </a>
    </div>
</nav>

<div class="container pb-5">

    <h1 class="mb-4">Dostępność pracowników</h1>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger">
            <?= $escape($error) ?>
        </div>
    <?php endforeach; ?>

    <?php if (isset($_GET['success']) && isset($successMessages[$_GET['success']])): ?>
        <div class="alert alert-success">
            <?= $escape($successMessages[$_GET['success']]) ?>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4">

        <div class="card-header">
            <strong>
                <?= $isEditing ? 'Edytuj dostępność' : 'Dodaj dostępność' ?>
            </strong>
        </div>

        <div class="card-body">

            <?php if (empty($employees)): ?>

                <div class="alert alert-info mb-0">
                    Nie ma jeszcze żadnych profili pracowników.
                    Najpierw dodaj pracownika w module zarządzania pracownikami.
                </div>

            <?php else: ?>

                <form method="POST">

                    <?= csrf_field() ?>

                    <input type="hidden" name="action" value="save">

                    <input
                        type="hidden"
                        name="availability_id"
                        value="<?= $formAvailabilityId ?>"
                    >

                    <div class="mb-3">

                        <label class="form-label">
                            Pracownik
                        </label>

                        <select
                            name="employee_id"
                            class="form-select"
                            required
                        >
                            <option value="">Wybierz pracownika</option>

                            <?php foreach ($employees as $employee): ?>

                                <option
                                    value="<?= (int)$employee['employee_id'] ?>"
                                    <?= $formEmployeeId === (int)$employee['employee_id'] ? 'selected' : '' ?>
                                >
                                    <?= $escape(
                                        $employee['name'] . ' ' . $employee['surname']
                                    ) ?>
                                    - <?= $escape($employee['position']) ?>

                                    <?= $employee['is_active'] ? '' : '(nieaktywny)' ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="mb-3">

                        <label class="form-label">
                            Dzień tygodnia
                        </label>

                        <select
                            name="day_of_week"
                            class="form-select"
                            required
                        >

                            <?php foreach ($days as $number => $dayName): ?>

                                <option
                                    value="<?= $number ?>"
                                    <?= $formDayOfWeek === $number ? 'selected' : '' ?>
                                >
                                    <?= $escape($dayName) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="row">

                        <div class="col-md-6 mb-3">

                            <label class="form-label">
                                Godzina rozpoczęcia
                            </label>

                            <input
                                type="time"
                                name="start_time"
                                class="form-control"
                                value="<?= $escape($formStartTime) ?>"
                                required
                            >

                        </div>

                        <div class="col-md-6 mb-3">

                            <label class="form-label">
                                Godzina zakończenia
                            </label>

                            <input
                                type="time"
                                name="end_time"
                                class="form-control"
                                value="<?= $escape($formEndTime) ?>"
                                required
                            >

                        </div>

                    </div>

                    <button type="submit" class="btn btn-primary">
                        <?= $isEditing ? 'Zapisz zmiany' : 'Dodaj dostępność' ?>
                    </button>

                    <?php if ($isEditing): ?>

                        <a
                            href="admin_availability.php"
                            class="btn btn-secondary"
                        >
                            Anuluj
                        </a>

                    <?php endif; ?>

                </form>

            <?php endif; ?>

        </div>
    </div>

    <div class="card shadow-sm mb-4">

        <div class="card-header">
            <strong>Harmonogram pracowników</strong>
        </div>

        <div class="card-body">

            <?php if (empty($availabilities)): ?>

                <p class="text-muted mb-0">
                    Nie dodano jeszcze żadnych przedziałów dostępności.
                </p>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table table-striped table-hover align-middle">

                        <thead>
                            <tr>
                                <th>Pracownik</th>
                                <th>Stanowisko</th>
                                <th>Dzień</th>
                                <th>Od</th>
                                <th>Do</th>
                                <th>Status</th>
                                <th>Akcje</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($availabilities as $availability): ?>

                                <tr>

                                    <td>
                                        <?= $escape(
                                            $availability['name'] . ' ' . $availability['surname']
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= $escape($availability['position']) ?>
                                    </td>

                                    <td>
                                        <?= $escape(
                                            $days[(int)$availability['day_of_week']] ?? 'Nieznany'
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= $escape(substr($availability['start_time'], 0, 5)) ?>
                                    </td>

                                    <td>
                                        <?= $escape(substr($availability['end_time'], 0, 5)) ?>
                                    </td>

                                    <td>

                                        <?php if ($availability['is_active']): ?>

                                            <span class="badge bg-success">
                                                Aktywny
                                            </span>

                                        <?php else: ?>

                                            <span class="badge bg-secondary">
                                                Nieaktywny
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <td>

                                        <div class="d-flex gap-2">

                                            <a
                                                href="admin_availability.php?edit=<?= (int)$availability['availability_id'] ?>"
                                                class="btn btn-sm btn-primary"
                                            >
                                                Edytuj
                                            </a>

                                            <form
                                                method="POST"
                                                onsubmit="return confirm('Czy na pewno usunąć ten przedział dostępności?');"
                                            >

                                                <?= csrf_field() ?>

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="delete"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="availability_id"
                                                    value="<?= (int)$availability['availability_id'] ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="btn btn-sm btn-outline-danger"
                                                >
                                                    Usuń
                                                </button>

                                            </form>

                                        </div>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </div>
    </div>

    <div class="card shadow-sm my-4">
        <div class="card-header">Dni wolne i zamknięcia restauracji</div>
        <div class="card-body">
            <p class="text-muted">Dni wolne i zatwierdzone urlopy blokują nowe rezerwacje. Nie anulują istniejących rezerwacji. <a href="admin_reservations.php">Sprawdź rezerwacje</a>.</p>
            <form method="post" class="row g-2 mb-4">
                <?= csrf_field() ?><input type="hidden" name="action" value="day_off_add">
                <div class="col-md-3"><label class="form-label">Pracownik</label><select name="employee_id" class="form-select" required>
                    <option value="">Wybierz pracownika</option>
                    <?php foreach ($employees as $employee): ?><option value="<?= (int)$employee['employee_id'] ?>"><?= $escape($employee['name'].' '.$employee['surname']) ?></option><?php endforeach; ?>
                </select></div>
                <div class="col-md-3"><label class="form-label">Dzień wolny</label><input class="form-control" type="date" name="day_off_date" min="<?= date('Y-m-d') ?>" required></div>
                <div class="col-md-4"><label class="form-label">Powód</label><input class="form-control" name="reason" maxlength="255"></div>
                <div class="col-md-2 align-self-end"><button class="btn btn-primary">Dodaj dzień wolny</button></div>
            </form>
            <?php foreach ($daysOff as $day): ?>
                <form method="post" class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <?= csrf_field() ?><input type="hidden" name="action" value="day_off_delete"><input type="hidden" name="record_id" value="<?= (int)$day['day_off_id'] ?>">
                    <span><?= $escape($day['day_off_date'].' — '.$day['name'].' '.$day['surname'].' — '.($day['reason'] ?? '')) ?></span><button class="btn btn-sm btn-outline-danger">Usuń dzień wolny</button>
                </form>
            <?php endforeach; ?>
            <hr>
            <form method="post" class="row g-2 mb-4">
                <?= csrf_field() ?><input type="hidden" name="action" value="closure_add">
                <div class="col-md-3"><label class="form-label">Data zamknięcia</label><input class="form-control" type="date" name="day_off_date" min="<?= date('Y-m-d') ?>" required></div>
                <div class="col-md-6"><label class="form-label">Nazwa / powód zamknięcia</label><input class="form-control" name="reason" maxlength="150" required></div>
                <div class="col-md-3 align-self-end"><button class="btn btn-primary">Dodaj zamknięcie</button></div>
            </form>
            <?php foreach ($closures as $day): ?>
                <form method="post" class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <?= csrf_field() ?><input type="hidden" name="action" value="closure_delete"><input type="hidden" name="record_id" value="<?= (int)$day['restaurant_day_off_id'] ?>">
                    <span><?= $escape($day['day_off_date'].' — '.$day['name']) ?></span><button class="btn btn-sm btn-outline-danger">Usuń zamknięcie</button>
                </form>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="card shadow-sm mb-4">
        <div class="card-header">Wnioski urlopowe</div>
        <div class="card-body">
            <?php if (!$leaveRequests): ?><p class="text-muted">Brak wniosków.</p><?php endif; ?>
            <?php foreach ($leaveRequests as $request): ?>
                <div class="border-bottom py-3">
                    <p><?= $escape($request['name'].' '.$request['surname'].' — '.$request['start_date'].' – '.$request['end_date']) ?>
                        <strong><?= $escape(['pending'=>'Oczekujący','approved'=>'Zatwierdzony','rejected'=>'Odrzucony','cancelled'=>'Anulowany'][$request['status']] ?? $request['status']) ?></strong></p>
                    <p><?= $escape($request['reason'] ?? '') ?></p>
                    <p><?= $escape($request['admin_comment'] ?? '') ?></p>
                    <?php if ($request['status'] === 'pending'): ?>
                        <form method="post" class="d-flex flex-wrap gap-2">
                            <?= csrf_field() ?><input type="hidden" name="action" value="leave_review"><input type="hidden" name="leave_request_id" value="<?= (int)$request['leave_request_id'] ?>">
                            <input class="form-control" name="admin_comment" aria-label="Komentarz administratora" placeholder="Komentarz administratora">
                            <button class="btn btn-success" name="status" value="approved">Zatwierdź</button>
                            <button class="btn btn-outline-danger" name="status" value="rejected">Odrzuć</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

</body>
</html>
