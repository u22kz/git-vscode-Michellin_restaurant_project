
<?php
require_once 'auth_check.php';
require_once 'db_connect.php';
require_role(['admin']);

$errors = [];
$success = '';

$escape = static function ($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

$formEmployeeId = 0;
$formUserId = 0;
$formPosition = '';
$formDescription = '';
$formActive = true;
$selectedServiceIds = [];

// Obsługa formularzy
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $action = $_POST['action'] ?? '';

    // Aktywacja / dezaktywacja pracownika
    if ($action === 'toggle') {
        $employeeId = (int)($_POST['employee_id'] ?? 0);

        if ($employeeId > 0) {
            $stmt = $pdo->prepare("
                UPDATE employees
                SET is_active = IF(is_active = 1, 0, 1)
                WHERE employee_id = ?
            ");
            $stmt->execute([$employeeId]);
        }

        header('Location: admin_employees.php');
        exit;
    }

    // Dodawanie lub edytowanie pracownika
    if ($action === 'save') {
        $formEmployeeId = (int)($_POST['employee_id'] ?? 0);
        $formUserId = (int)($_POST['user_id'] ?? 0);
        $formPosition = trim($_POST['position'] ?? '');
        $formDescription = trim($_POST['description'] ?? '');
        $formActive = isset($_POST['is_active']);

        $serviceIds = $_POST['service_ids'] ?? [];

        if (!is_array($serviceIds)) {
            $serviceIds = [];
        }

        $selectedServiceIds = array_values(array_unique(
            array_filter(
                array_map('intval', $serviceIds),
                static fn($id) => $id > 0
            )
        ));

        if ($formPosition === '') {
            $errors[] = 'Stanowisko jest wymagane.';
        } elseif (mb_strlen($formPosition) > 100) {
            $errors[] = 'Stanowisko może mieć maksymalnie 100 znaków.';
        }

        // Sprawdzenie konta użytkownika przy dodawaniu
        if ($formEmployeeId === 0) {
            if ($formUserId <= 0) {
                $errors[] = 'Wybierz konto użytkownika.';
            } else {
                $stmt = $pdo->prepare("
                    SELECT u.user_id
                    FROM users u
                    LEFT JOIN employees e ON e.user_id = u.user_id
                    WHERE u.user_id = ?
                      AND u.role = 'employee'
                      AND u.is_active = 1
                      AND e.employee_id IS NULL
                ");
                $stmt->execute([$formUserId]);

                if (!$stmt->fetch()) {
                    $errors[] = 'Wybrane konto nie jest aktywnym kontem pracownika lub ma już profil.';
                }
            }
        } else {
            $stmt = $pdo->prepare("
                SELECT employee_id
                FROM employees
                WHERE employee_id = ?
            ");
            $stmt->execute([$formEmployeeId]);

            if (!$stmt->fetch()) {
                $errors[] = 'Nie znaleziono pracownika.';
            }
        }

        // Sprawdzenie, czy wszystkie wybrane usługi istnieją
        if (!empty($selectedServiceIds)) {
            $placeholders = implode(',', array_fill(0, count($selectedServiceIds), '?'));

            $stmt = $pdo->prepare("
                SELECT service_id
                FROM services
                WHERE service_id IN ($placeholders)
            ");
            $stmt->execute($selectedServiceIds);

            $foundServices = array_map(
                'intval',
                $stmt->fetchAll(PDO::FETCH_COLUMN)
            );

            if (count($foundServices) !== count($selectedServiceIds)) {
                $errors[] = 'Jedna z wybranych usług nie istnieje.';
            }
        }

        // Zapis danych
        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                if ($formEmployeeId > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE employees
                        SET position = ?,
                            description = ?,
                            is_active = ?
                        WHERE employee_id = ?
                    ");

                    $stmt->execute([
                        $formPosition,
                        $formDescription,
                        $formActive ? 1 : 0,
                        $formEmployeeId
                    ]);

                    $employeeId = $formEmployeeId;
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO employees
                            (user_id, position, description, is_active)
                        VALUES (?, ?, ?, ?)
                    ");

                    $stmt->execute([
                        $formUserId,
                        $formPosition,
                        $formDescription,
                        $formActive ? 1 : 0
                    ]);

                    $employeeId = (int)$pdo->lastInsertId();
                }

                // Zapis przypisanych usług
                $stmt = $pdo->prepare("
                    DELETE FROM employee_services
                    WHERE employee_id = ?
                ");
                $stmt->execute([$employeeId]);

                if (!empty($selectedServiceIds)) {
                    $stmt = $pdo->prepare("
                        INSERT INTO employee_services
                            (employee_id, service_id)
                        VALUES (?, ?)
                    ");

                    foreach ($selectedServiceIds as $serviceId) {
                        $stmt->execute([$employeeId, $serviceId]);
                    }
                }

                $pdo->commit();

                header('Location: admin_employees.php');
                exit;

            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $errors[] = 'Wystąpił błąd podczas zapisywania pracownika. Sprawdź dane i spróbuj ponownie.';
            }
        }
    }
}

// Pobranie danych pracownika do edycji
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];

    if ($editId > 0) {
        $stmt = $pdo->prepare("
            SELECT e.*, u.name, u.surname, u.customer_email
            FROM employees e
            JOIN users u ON u.user_id = e.user_id
            WHERE e.employee_id = ?
        ");
        $stmt->execute([$editId]);

        $employeeToEdit = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($employeeToEdit) {
            $formEmployeeId = (int)$employeeToEdit['employee_id'];
            $formUserId = (int)$employeeToEdit['user_id'];
            $formPosition = $employeeToEdit['position'];
            $formDescription = $employeeToEdit['description'] ?? '';
            $formActive = (bool)$employeeToEdit['is_active'];

            $stmt = $pdo->prepare("
                SELECT service_id
                FROM employee_services
                WHERE employee_id = ?
            ");
            $stmt->execute([$formEmployeeId]);

            $selectedServiceIds = array_map(
                'intval',
                $stmt->fetchAll(PDO::FETCH_COLUMN)
            );
        } else {
            $errors[] = 'Nie znaleziono pracownika do edycji.';
        }
    }
}

// Lista kont, które mogą otrzymać profil pracownika
$stmt = $pdo->query("
    SELECT u.user_id, u.name, u.surname, u.customer_email
    FROM users u
    LEFT JOIN employees e ON e.user_id = u.user_id
    WHERE u.role = 'employee'
      AND u.is_active = 1
      AND e.employee_id IS NULL
    ORDER BY u.surname, u.name
");
$candidateUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Lista wszystkich usług
$stmt = $pdo->query("
    SELECT service_id, name, price, unit, duration_minutes, is_available
    FROM services
    ORDER BY name
");
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Lista pracowników
$stmt = $pdo->query("
    SELECT
        e.employee_id,
        e.position,
        e.description,
        e.is_active,
        u.name,
        u.surname,
        u.customer_email,
        (
            SELECT COUNT(*)
            FROM employee_services es
            WHERE es.employee_id = e.employee_id
        ) AS services_count,
        (
            SELECT COUNT(*)
            FROM employee_availability ea
            WHERE ea.employee_id = e.employee_id
        ) AS availability_count,
        (
            SELECT COUNT(*)
            FROM reservations r
            WHERE r.employee_id = e.employee_id
        ) AS reservations_count
    FROM employees e
    JOIN users u ON u.user_id = e.user_id
    ORDER BY u.surname, u.name
");
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

$isEditing = $formEmployeeId > 0;
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pracownicy - Michelin Restaurant</title>

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

    <h1 class="mb-4">Zarządzanie pracownikami</h1>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger">
            <?= $escape($error) ?>
        </div>
    <?php endforeach; ?>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success">
            Operacja została wykonana pomyślnie.
        </div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-header">
            <strong>
                <?= $isEditing ? 'Edytuj pracownika' : 'Dodaj profil pracownika' ?>
            </strong>
        </div>

        <div class="card-body">

            <?php if (!$isEditing): ?>

                <?php if (empty($candidateUsers)): ?>
                    <div class="alert alert-info">
                        Nie ma kont użytkowników z rolą employee,
                        którym można przypisać nowy profil.
                        Najpierw zmień rolę wybranego użytkownika
                        w panelu administratora.
                    </div>
                <?php else: ?>

                    <form method="POST">
                        <?= csrf_field() ?>

                        <input type="hidden" name="action" value="save">

                        <div class="mb-3">
                            <label class="form-label">Konto użytkownika</label>

                            <select name="user_id" class="form-select" required>
                                <option value="">Wybierz użytkownika</option>

                                <?php foreach ($candidateUsers as $user): ?>
                                    <option
                                        value="<?= (int)$user['user_id'] ?>"
                                        <?= $formUserId === (int)$user['user_id'] ? 'selected' : '' ?>
                                    >
                                        <?= $escape($user['name'] . ' ' . $user['surname']) ?>
                                        (<?= $escape($user['customer_email']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                <?php endif; ?>

            <?php else: ?>

                <form method="POST">
                    <?= csrf_field() ?>

                    <input type="hidden" name="action" value="save">
                    <input
                        type="hidden"
                        name="employee_id"
                        value="<?= $formEmployeeId ?>"
                    >

                    <div class="alert alert-secondary">
                        Edytujesz profil pracownika o ID:
                        <?= $formEmployeeId ?>
                    </div>

            <?php endif; ?>

                <?php if ($isEditing || !empty($candidateUsers)): ?>

                    <div class="mb-3">
                        <label class="form-label">Stanowisko</label>

                        <input
                            type="text"
                            name="position"
                            class="form-control"
                            maxlength="100"
                            value="<?= $escape($formPosition) ?>"
                            placeholder="np. Szef kuchni"
                            required
                        >
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Opis pracownika</label>

                        <textarea
                            name="description"
                            class="form-control"
                            rows="3"
                            placeholder="Opis doświadczenia i obowiązków..."
                        ><?= $escape($formDescription) ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label d-block">
                            Przypisane usługi
                        </label>

                        <?php if (empty($services)): ?>

                            <p class="text-muted">
                                Najpierw dodaj usługi w module zarządzania usługami.
                            </p>

                        <?php else: ?>

                            <div class="border rounded p-3">

                                <?php foreach ($services as $service): ?>
                                    <div class="form-check mb-2">

                                        <input
                                            class="form-check-input"
                                            type="checkbox"
                                            name="service_ids[]"
                                            value="<?= (int)$service['service_id'] ?>"
                                            id="service_<?= (int)$service['service_id'] ?>"
                                            <?= in_array(
                                                (int)$service['service_id'],
                                                $selectedServiceIds,
                                                true
                                            ) ? 'checked' : '' ?>
                                        >

                                        <label
                                            class="form-check-label"
                                            for="service_<?= (int)$service['service_id'] ?>"
                                        >
                                            <?= $escape($service['name']) ?>
                                            — <?= number_format((float)$service['price'], 2, ',', ' ') ?>
                                            zł / <?= $escape($service['unit']) ?>
                                            (<?= (int)$service['duration_minutes'] ?> min)

                                            <?php if (!(bool)$service['is_available']): ?>
                                                <span class="text-muted">
                                                    (niedostępna)
                                                </span>
                                            <?php endif; ?>
                                        </label>

                                    </div>
                                <?php endforeach; ?>

                            </div>

                        <?php endif; ?>
                    </div>

                    <div class="form-check mb-3">
                        <input
                            type="checkbox"
                            name="is_active"
                            value="1"
                            class="form-check-input"
                            id="is_active"
                            <?= $formActive ? 'checked' : '' ?>
                        >

                        <label class="form-check-label" for="is_active">
                            Pracownik aktywny
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <?= $isEditing ? 'Zapisz zmiany' : 'Dodaj pracownika' ?>
                    </button>

                    <?php if ($isEditing): ?>
                        <a href="admin_employees.php" class="btn btn-secondary">
                            Anuluj
                        </a>
                    <?php endif; ?>

                </form>

                <?php endif; ?>

        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header">
            <strong>Lista pracowników</strong>
        </div>

        <div class="card-body">

            <?php if (empty($employees)): ?>

                <p class="text-muted mb-0">
                    Nie dodano jeszcze żadnych pracowników.
                </p>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table table-striped table-hover align-middle">

                        <thead>
                            <tr>
                                <th>Pracownik</th>
                                <th>Stanowisko</th>
                                <th>Usługi</th>
                                <th>Dostępność</th>
                                <th>Rezerwacje</th>
                                <th>Status</th>
                                <th>Akcje</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($employees as $employee): ?>

                                <tr>
                                    <td>
                                        <strong>
                                            <?= $escape(
                                                $employee['name'] . ' ' . $employee['surname']
                                            ) ?>
                                        </strong>

                                        <br>

                                        <small class="text-muted">
                                            <?= $escape($employee['customer_email']) ?>
                                        </small>
                                    </td>

                                    <td>
                                        <?= $escape($employee['position']) ?>
                                    </td>

                                    <td>
                                        <?= (int)$employee['services_count'] ?>
                                    </td>

                                    <td>
                                        <?= (int)$employee['availability_count'] ?>
                                    </td>

                                    <td>
                                        <?= (int)$employee['reservations_count'] ?>
                                    </td>

                                    <td>
                                        <?php if ($employee['is_active']): ?>
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
                                                href="admin_employees.php?edit=<?= (int)$employee['employee_id'] ?>"
                                                class="btn btn-sm btn-primary"
                                            >
                                                Edytuj
                                            </a>

                                            <form method="POST">
                                                <?= csrf_field() ?>

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="toggle"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="employee_id"
                                                    value="<?= (int)$employee['employee_id'] ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="btn btn-sm btn-outline-secondary"
                                                >
                                                    <?= $employee['is_active']
                                                        ? 'Dezaktywuj'
                                                        : 'Aktywuj' ?>
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
