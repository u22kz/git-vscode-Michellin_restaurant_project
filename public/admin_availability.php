
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
    csrf_verify();

    $action = $_POST['action'] ?? '';

    // Usuwanie przedziału dostępności
    if ($action === 'delete') {
        $availabilityId = (int)($_POST['availability_id'] ?? 0);

        if ($availabilityId > 0) {
            $stmt = $pdo->prepare("
                DELETE FROM employee_availability
                WHERE availability_id = ?
            ");
            $stmt->execute([$availabilityId]);

            header('Location: admin_availability.php?success=deleted');
            exit;
        }

        $errors[] = 'Nieprawidłowy identyfikator dostępności.';
    }

    // Dodawanie lub edytowanie dostępności
    if ($action === 'save') {
        $formAvailabilityId = (int)($_POST['availability_id'] ?? 0);
        $formEmployeeId = (int)($_POST['employee_id'] ?? 0);
        $formDayOfWeek = (int)($_POST['day_of_week'] ?? 0);
        $formStartTime = trim($_POST['start_time'] ?? '');
        $formEndTime = trim($_POST['end_time'] ?? '');

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
            try {
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

                    header('Location: admin_availability.php?success=updated');
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

                    header('Location: admin_availability.php?success=added');
                }

                exit;

            } catch (PDOException $e) {
                $errors[] = 'Wystąpił błąd podczas zapisywania dostępności.';
            }
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
$stmt = $pdo->query("
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

$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Lista dostępności
$stmt = $pdo->query("
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

$availabilities = $stmt->fetchAll(PDO::FETCH_ASSOC);

$isEditing = $formAvailabilityId > 0;

$successMessages = [
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

    <div class="card shadow-sm">

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

</div>

</body>
</html>
