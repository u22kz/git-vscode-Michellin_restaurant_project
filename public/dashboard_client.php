<?php
/**
 * Plik: dashboard_client.php
 * Cel: Panel klienta - formularz tworzenia nowej rezerwacji oraz lista
 *      własnych, wcześniej złożonych rezerwacji.
 *
 * Uproszczenie na tym etapie: przypisanie pracownika do rezerwacji odbywa
 * się automatycznie - system wybiera pierwszego aktywnego pracownika,
 * który obsługuje wybraną usługę (tabela employee_services). Zaawansowane
 * sprawdzanie konfliktów terminów i wybór konkretnego pracownika przez
 * klienta to funkcjonalność kolejnego etapu projektu.
 */
require_once 'auth_check.php';
require_once 'db_connect.php';

// Tylko klient ma dostęp do tego panelu
require_role(['client']);

$user_id = current_user_id();

$errors  = [];
$success = false;

// --- Pobranie listy dostępnych usług (do formularza rezerwacji) ---
try {
    $stmt = $pdo->query(
        'SELECT service_id, name, price, unit, duration_minutes
         FROM services
         WHERE is_available = 1
         ORDER BY name'
    );
    $services = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Błąd pobierania usług: ' . $e->getMessage());
    $services = [];
}

// --- Obsługa formularza nowej rezerwacji ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $service_id = filter_input(INPUT_POST, 'service_id', FILTER_VALIDATE_INT);
    $date       = trim($_POST['reservation_date'] ?? '');
    $time       = trim($_POST['start_time'] ?? '');
    $guests     = filter_input(INPUT_POST, 'guest_count', FILTER_VALIDATE_INT);
    $comment    = trim($_POST['comment'] ?? '');

    // --- Walidacja podstawowa ---
    if (!$service_id) {
        $errors[] = 'Wybierz usługę.';
    }
    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $errors[] = 'Podaj poprawną datę rezerwacji.';
    } elseif ($date < date('Y-m-d')) {
        $errors[] = 'Data rezerwacji nie może być z przeszłości.';
    }
    if ($time === '' || !preg_match('/^\d{2}:\d{2}$/', $time)) {
        $errors[] = 'Podaj poprawną godzinę rezerwacji.';
    }
    if (!$guests || $guests < 1) {
        $errors[] = 'Liczba gości musi być liczbą całkowitą większą od zera.';
    }
    if (mb_strlen($comment) > 1000) {
        $errors[] = 'Komentarz jest zbyt długi.';
    }

    // --- Sprawdzenie, czy wybrana usługa faktycznie istnieje i jest dostępna ---
    $service = null;
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare(
                'SELECT service_id, name, duration_minutes
                 FROM services WHERE service_id = :id AND is_available = 1'
            );
            $stmt->execute(['id' => $service_id]);
            $service = $stmt->fetch();

            if ($service === false) {
                $errors[] = 'Wybrana usługa jest niedostępna.';
            }
        } catch (PDOException $e) {
            error_log('Błąd sprawdzania usługi: ' . $e->getMessage());
            $errors[] = 'Wystąpił błąd podczas składania rezerwacji.';
        }
    }

    // --- Wyliczenie godziny końcowej na podstawie czasu trwania usługi ---
    $start_time = null;
    $end_time   = null;
    if (empty($errors) && $service !== null) {
        $start_time = $time . ':00';
        $end_timestamp = strtotime($date . ' ' . $start_time) + ((int) $service['duration_minutes'] * 60);
        $end_time = date('H:i:s', $end_timestamp);
    }

    // --- Znalezienie pracownika obsługującego wybraną usługę ---
    $employee_id = null;
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare(
                'SELECT e.employee_id
                 FROM employee_services es
                 JOIN employees e ON e.employee_id = es.employee_id
                 WHERE es.service_id = :service_id AND e.is_active = 1
                 LIMIT 1'
            );
            $stmt->execute(['service_id' => $service_id]);
            $row = $stmt->fetch();

            if ($row === false) {
                $errors[] = 'Brak pracownika obsługującego wybraną usługę. Skontaktuj się z restauracją.';
            } else {
                $employee_id = (int) $row['employee_id'];
            }
        } catch (PDOException $e) {
            error_log('Błąd szukania pracownika: ' . $e->getMessage());
            $errors[] = 'Wystąpił błąd podczas składania rezerwacji.';
        }
    }

    // --- Zapis rezerwacji ---
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO reservations
                    (user_id, employee_id, service_id, reservation_date, start_time, end_time, guest_count, status, comment)
                 VALUES
                    (:user_id, :employee_id, :service_id, :date, :start_time, :end_time, :guests, :status, :comment)'
            );
            $stmt->execute([
                'user_id'     => $user_id,
                'employee_id' => $employee_id,
                'service_id'  => $service_id,
                'date'        => $date,
                'start_time'  => $start_time,
                'end_time'    => $end_time,
                'guests'      => $guests,
                'status'      => 'pending',
                'comment'     => $comment !== '' ? $comment : null,
            ]);
            $success = true;
        } catch (PDOException $e) {
            error_log('Błąd zapisu rezerwacji: ' . $e->getMessage());
            $errors[] = 'Wystąpił błąd podczas zapisu rezerwacji. Spróbuj ponownie.';
        }
    }
}

// --- Pobranie historii rezerwacji tego klienta ---
try {
    $stmt = $pdo->prepare(
        'SELECT r.reservation_id, r.reservation_date, r.start_time, r.end_time,
                r.guest_count, r.status, r.comment,
                s.name AS service_name,
                u.name AS employee_name, u.surname AS employee_surname
         FROM reservations r
         JOIN services s   ON s.service_id = r.service_id
         JOIN employees e  ON e.employee_id = r.employee_id
         JOIN users u      ON u.user_id = e.user_id
         WHERE r.user_id = :user_id
         ORDER BY r.reservation_date DESC, r.start_time DESC'
    );
    $stmt->execute(['user_id' => $user_id]);
    $reservations = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Błąd pobierania historii rezerwacji: ' . $e->getMessage());
    $reservations = [];
}

// Etykiety statusów po polsku (do wyświetlenia)
$status_labels = [
    'pending'   => ['Oczekująca', 'bg-warning text-dark'],
    'confirmed' => ['Potwierdzona', 'bg-success'],
    'cancelled' => ['Anulowana', 'bg-secondary'],
    'completed' => ['Zrealizowana', 'bg-primary'],
    'no_show'   => ['Nieobecność', 'bg-danger'],
];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Panel klienta - Michelin Restaurant</title>
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
    <h1 class="mb-4">Panel klienta</h1>

    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h5 card-title">Nowa rezerwacja</h2>

            <?php if ($success): ?>
                <div class="alert alert-success">Rezerwacja została złożona i oczekuje na potwierdzenie.</div>
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

            <?php if (empty($services)): ?>
                <p class="text-muted mb-0">Obecnie brak dostępnych usług do zarezerwowania.</p>
            <?php else: ?>
                <form method="post" action="dashboard_client.php" class="row g-3">
                    <div class="col-md-6">
                        <label for="service_id" class="form-label">Usługa</label>
                        <select class="form-select" id="service_id" name="service_id" required>
                            <option value="">-- wybierz usługę --</option>
                            <?php foreach ($services as $service): ?>
                                <option value="<?= (int) $service['service_id'] ?>">
                                    <?= htmlspecialchars($service['name'], ENT_QUOTES, 'UTF-8') ?>
                                    (<?= htmlspecialchars(number_format((float) $service['price'], 2), ENT_QUOTES, 'UTF-8') ?> zł / <?= htmlspecialchars($service['unit'], ENT_QUOTES, 'UTF-8') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="reservation_date" class="form-label">Data</label>
                        <input type="date" class="form-control" id="reservation_date" name="reservation_date"
                               min="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label for="start_time" class="form-label">Godzina</label>
                        <input type="time" class="form-control" id="start_time" name="start_time" required>
                    </div>
                    <div class="col-md-3">
                        <label for="guest_count" class="form-label">Liczba gości</label>
                        <input type="number" class="form-control" id="guest_count" name="guest_count" min="1" required>
                    </div>
                    <div class="col-md-9">
                        <label for="comment" class="form-label">Komentarz (opcjonalnie)</label>
                        <input type="text" class="form-control" id="comment" name="comment" maxlength="1000">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">Zarezerwuj</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h2 class="h5 card-title">Moje rezerwacje</h2>

            <?php if (empty($reservations)): ?>
                <p class="text-muted mb-0">Nie masz jeszcze żadnych rezerwacji.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Usługa</th>
                                <th>Data</th>
                                <th>Godzina</th>
                                <th>Goście</th>
                                <th>Pracownik</th>
                                <th>Status</th>
                                <th>Komentarz</th>
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
                                    <td><?= htmlspecialchars($r['service_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($r['reservation_date'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars(substr($r['start_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?>-<?= htmlspecialchars(substr($r['end_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= (int) $r['guest_count'] ?></td>
                                    <td><?= htmlspecialchars($r['employee_name'] . ' ' . $r['employee_surname'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><span class="badge <?= htmlspecialchars($status_class, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($status_label, ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td><?= htmlspecialchars($r['comment'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
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
