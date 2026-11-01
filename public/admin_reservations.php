
<?php
require_once 'auth_check.php';
require_once 'db_connect.php';
require_role(['admin']);

$errors = [];

$escape = static function ($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

$statusLabels = [
    'pending' => 'Oczekująca',
    'confirmed' => 'Potwierdzona',
    'cancelled' => 'Anulowana',
    'completed' => 'Zakończona',
    'no_show' => 'Klient się nie pojawił'
];

$allowedStatuses = array_keys($statusLabels);

// Zmiana statusu rezerwacji
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $action = $_POST['action'] ?? '';

    if ($action === 'change_status') {
        $reservationId = (int)($_POST['reservation_id'] ?? 0);
        $newStatus = $_POST['status'] ?? '';

        if ($reservationId <= 0) {
            $errors[] = 'Nieprawidłowy numer rezerwacji.';
        } elseif (!in_array($newStatus, $allowedStatuses, true)) {
            $errors[] = 'Wybrano nieprawidłowy status.';
        } else {
            $stmt = $pdo->prepare("
                UPDATE reservations
                SET status = ?
                WHERE reservation_id = ?
            ");
            $stmt->execute([$newStatus, $reservationId]);

            header('Location: admin_reservations.php?updated=1');
            exit;
        }
    }
}

// Filtry z adresu strony
$filterFrom = trim($_GET['date_from'] ?? '');
$filterTo = trim($_GET['date_to'] ?? '');
$filterStatus = $_GET['status'] ?? '';
$filterEmployee = (int)($_GET['employee_id'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));

$isValidDate = static function ($date) {
    if ($date === '') {
        return true;
    }

    $parsed = DateTime::createFromFormat('!Y-m-d', $date);

    return $parsed && $parsed->format('Y-m-d') === $date;
};

if (!$isValidDate($filterFrom)) {
    $errors[] = 'Data początkowa jest nieprawidłowa.';
    $filterFrom = '';
}

if (!$isValidDate($filterTo)) {
    $errors[] = 'Data końcowa jest nieprawidłowa.';
    $filterTo = '';
}

if ($filterFrom !== '' && $filterTo !== '' && $filterFrom > $filterTo) {
    $errors[] = 'Data początkowa nie może być późniejsza niż końcowa.';
    $filterFrom = '';
    $filterTo = '';
}

if ($filterStatus !== '' && !in_array($filterStatus, $allowedStatuses, true)) {
    $errors[] = 'Wybrano nieprawidłowy status filtra.';
    $filterStatus = '';
}

// Lista pracowników do filtra
$stmt = $pdo->query("
    SELECT
        e.employee_id,
        u.name,
        u.surname
    FROM employees e
    JOIN users u ON u.user_id = e.user_id
    ORDER BY u.surname, u.name
");
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Budowanie warunków filtrowania
$where = [];
$params = [];

if ($filterFrom !== '') {
    $where[] = 'r.reservation_date >= ?';
    $params[] = $filterFrom;
}

if ($filterTo !== '') {
    $where[] = 'r.reservation_date <= ?';
    $params[] = $filterTo;
}

if ($filterStatus !== '') {
    $where[] = 'r.status = ?';
    $params[] = $filterStatus;
}

if ($filterEmployee > 0) {
    $where[] = 'r.employee_id = ?';
    $params[] = $filterEmployee;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Liczba wyników i paginacja
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM reservations r
    $whereSql
");
$stmt->execute($params);

$totalReservations = (int)$stmt->fetchColumn();

$perPage = 20;
$totalPages = max(1, (int)ceil($totalReservations / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// Pobranie rezerwacji
$sql = "
    SELECT
        r.reservation_id,
        r.reservation_date,
        r.start_time,
        r.end_time,
        r.guest_count,
        r.status,
        r.comment,
        r.created_at,
        u.name AS customer_name,
        u.surname AS customer_surname,
        u.customer_email,
        e.employee_id,
        eu.name AS employee_name,
        eu.surname AS employee_surname,
        s.name AS service_name,
        s.price,
        s.unit
    FROM reservations r
    JOIN users u ON u.user_id = r.user_id
    JOIN employees e ON e.employee_id = r.employee_id
    JOIN users eu ON eu.user_id = e.user_id
    JOIN services s ON s.service_id = r.service_id
    $whereSql
    ORDER BY r.reservation_date DESC, r.start_time DESC
    LIMIT $perPage OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rezerwacje - Michelin Restaurant</title>

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

    <h1 class="mb-4">Zarządzanie rezerwacjami</h1>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger">
            <?= $escape($error) ?>
        </div>
    <?php endforeach; ?>

    <?php if (isset($_GET['updated'])): ?>
        <div class="alert alert-success">
            Status rezerwacji został zaktualizowany.
        </div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-header">
            <strong>Filtrowanie rezerwacji</strong>
        </div>

        <div class="card-body">
            <form method="GET">
                <div class="row">

                    <div class="col-md-3 mb-3">
                        <label class="form-label">Data od</label>
                        <input
                            type="date"
                            name="date_from"
                            class="form-control"
                            value="<?= $escape($filterFrom) ?>"
                        >
                    </div>

                    <div class="col-md-3 mb-3">
                        <label class="form-label">Data do</label>
                        <input
                            type="date"
                            name="date_to"
                            class="form-control"
                            value="<?= $escape($filterTo) ?>"
                        >
                    </div>

                    <div class="col-md-3 mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">Wszystkie statusy</option>

                            <?php foreach ($statusLabels as $value => $label): ?>
                                <option
                                    value="<?= $escape($value) ?>"
                                    <?= $filterStatus === $value ? 'selected' : '' ?>
                                >
                                    <?= $escape($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3 mb-3">
                        <label class="form-label">Pracownik</label>
                        <select name="employee_id" class="form-select">
                            <option value="0">Wszyscy pracownicy</option>

                            <?php foreach ($employees as $employee): ?>
                                <option
                                    value="<?= (int)$employee['employee_id'] ?>"
                                    <?= $filterEmployee === (int)$employee['employee_id'] ? 'selected' : '' ?>
                                >
                                    <?= $escape(
                                        $employee['name'] . ' ' . $employee['surname']
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                </div>

                <button type="submit" class="btn btn-primary">
                    Filtruj
                </button>

                <a href="admin_reservations.php" class="btn btn-secondary">
                    Wyczyść filtry
                </a>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Lista rezerwacji</strong>
            <span class="text-muted">
                Wyników: <?= $totalReservations ?>
            </span>
        </div>

        <div class="card-body">

            <?php if (empty($reservations)): ?>

                <p class="text-muted mb-0">
                    Brak rezerwacji spełniających wybrane filtry.
                </p>

            <?php else: ?>

                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle">

                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Termin</th>
                                <th>Klient</th>
                                <th>Usługa</th>
                                <th>Pracownik</th>
                                <th>Goście</th>
                                <th>Status</th>
                                <th>Zmiana statusu</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($reservations as $reservation): ?>
                                <tr>
                                    <td>
                                        <?= (int)$reservation['reservation_id'] ?>
                                    </td>

                                    <td>
                                        <?= $escape($reservation['reservation_date']) ?>
                                        <br>
                                        <?= $escape(substr($reservation['start_time'], 0, 5)) ?>
                                        –
                                        <?= $escape(substr($reservation['end_time'], 0, 5)) ?>
                                    </td>

                                    <td>
                                        <?= $escape(
                                            $reservation['customer_name'] . ' ' .
                                            $reservation['customer_surname']
                                        ) ?>
                                        <br>
                                        <small class="text-muted">
                                            <?= $escape($reservation['customer_email']) ?>
                                        </small>
                                    </td>

                                    <td>
                                        <?= $escape($reservation['service_name']) ?>
                                        <br>
                                        <small class="text-muted">
                                            <?= number_format((float)$reservation['price'], 2, ',', ' ') ?>
                                            zł / <?= $escape($reservation['unit']) ?>
                                        </small>
                                    </td>

                                    <td>
                                        <?= $escape(
                                            $reservation['employee_name'] . ' ' .
                                            $reservation['employee_surname']
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= (int)$reservation['guest_count'] ?>
                                    </td>

                                    <td>
                                        <?= $escape(
                                            $statusLabels[$reservation['status']] ??
                                            $reservation['status']
                                        ) ?>
                                    </td>

                                    <td>
                                        <form method="POST" class="d-flex gap-2">
                                            <?= csrf_field() ?>

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="change_status"
                                            >

                                            <input
                                                type="hidden"
                                                name="reservation_id"
                                                value="<?= (int)$reservation['reservation_id'] ?>"
                                            >

                                            <select
                                                name="status"
                                                class="form-select form-select-sm"
                                            >
                                                <?php foreach ($statusLabels as $value => $label): ?>
                                                    <option
                                                        value="<?= $escape($value) ?>"
                                                        <?= $reservation['status'] === $value ? 'selected' : '' ?>
                                                    >
                                                        <?= $escape($label) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>

                                            <button
                                                type="submit"
                                                class="btn btn-sm btn-primary"
                                            >
                                                Zapisz
                                            </button>
                                        </form>
                                    </td>
                                </tr>

                                <?php if (!empty($reservation['comment'])): ?>
                                    <tr>
                                        <td colspan="8" class="small text-muted">
                                            Komentarz:
                                            <?= $escape($reservation['comment']) ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>

                            <?php endforeach; ?>
                        </tbody>

                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <nav aria-label="Strony rezerwacji">
                        <ul class="pagination">

                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <?php
                                $query = $_GET;
                                $query['page'] = $i;
                                $url = 'admin_reservations.php?' . http_build_query($query);
                                ?>

                                <li class="page-item <?= $page === $i ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= $escape($url) ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                            <?php endfor; ?>

                        </ul>
                    </nav>
                <?php endif; ?>

            <?php endif; ?>

        </div>
    </div>

</div>

</body>
</html>
