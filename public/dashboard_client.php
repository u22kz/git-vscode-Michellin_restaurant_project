<?php
require_once 'auth_check.php';
require_once 'db_connect.php';

require_role(['client']);

$user_id = (int) current_user_id();

$errors = [];
$isPageView = $_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['ajax']);
$success = $isPageView ? ($_SESSION['reservation_client_success'] ?? '') : '';
$createdId = $isPageView ? (int)($_SESSION['reservation_created_id'] ?? 0) : 0;
if (!isset($_GET['ajax'])) unset($_SESSION['reservation_client_success'], $_SESSION['reservation_created_id']);
$escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

$status_labels = [
    'pending'   => ['Oczekująca', 'bg-warning text-dark'],
    'confirmed' => ['Potwierdzona', 'bg-success'],
    'cancelled' => ['Anulowana', 'bg-secondary'],
    'completed' => ['Zrealizowana', 'bg-primary'],
    'no_show'   => ['Nieobecność', 'bg-danger'],
];

$is_valid_date = static function ($date) {
    if (!preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}\z/', $date)) return false;
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    return $parsed && $parsed->format('Y-m-d') === $date;
};

/*
 * AJAX: pobieranie pracowników obsługujących usługę
 * w wybranym dniu, z uwzględnieniem ich dostępności i dni wolnych.
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && in_array($_GET['ajax'] ?? '', ['employees', 'slots'], true)) {
    header('Content-Type: application/json; charset=utf-8');

    $service_id = filter_input(INPUT_GET, 'service_id', FILTER_VALIDATE_INT);
    $date = trim($_GET['date'] ?? '');

    if (!$service_id || $service_id < 1 || !$is_valid_date($date) || $date < date('Y-m-d')) {
        echo json_encode([]);
        exit;
    }

    try {
        $weekday = (int) date('N', strtotime($date));

        $stmt = $pdo->prepare("
            SELECT DISTINCT
                e.employee_id,
                u.name,
                u.surname
            FROM employees e
            JOIN users u ON u.user_id = e.user_id
            JOIN employee_services es ON es.employee_id = e.employee_id
            JOIN services s ON s.service_id = es.service_id
            WHERE es.service_id = :service_id
              AND s.is_available = 1 AND s.duration_minutes > 0
              AND e.is_active = 1
              AND u.is_active = 1
              AND u.role = 'employee'

              AND EXISTS (
                  SELECT 1
                  FROM employee_availability ea
                  WHERE ea.employee_id = e.employee_id
                    AND ea.day_of_week = :weekday
                    AND TIME_TO_SEC(ea.end_time) - GREATEST(TIME_TO_SEC(ea.start_time), :earliest)
                        >= s.duration_minutes * 60
              )

              AND NOT EXISTS (
                  SELECT 1
                  FROM employee_days_off d
                  WHERE d.employee_id = e.employee_id
                    AND d.day_off_date = :day_off_date
              )

              AND NOT EXISTS (
                  SELECT 1
                  FROM employee_leave_requests l
                  WHERE l.employee_id = e.employee_id
                    AND l.status = 'approved'
                    AND :leave_date BETWEEN l.start_date AND l.end_date
              )

              AND NOT EXISTS (
                  SELECT 1
                  FROM restaurant_days_off rd
                  WHERE rd.day_off_date = :restaurant_date
              )

            ORDER BY u.surname, u.name
        ");

        $stmt->execute([
            'service_id' => $service_id,
            'weekday' => $weekday,
            'earliest' => $date === date('Y-m-d') ? ((int)date('H') * 3600 + (int)date('i') * 60 + (int)date('s') + 1) : 0,
            'day_off_date' => $date,
            'leave_date' => $date,
            'restaurant_date' => $date,
        ]);

        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (($_GET['ajax'] ?? '') === 'slots') {
            $employeeId = filter_input(INPUT_GET, 'employee_id', FILTER_VALIDATE_INT);
            if (!$employeeId || !in_array($employeeId, array_map('intval', array_column($employees, 'employee_id')), true)) {
                echo json_encode([]); exit;
            }
            $stmt = $pdo->prepare('SELECT duration_minutes FROM services WHERE service_id = ? AND is_available = 1');
            $stmt->execute([$service_id]);
            $duration = (int)$stmt->fetchColumn();
            if ($duration <= 0) { echo json_encode([]); exit; }
            $stmt = $pdo->prepare('SELECT start_time, end_time FROM employee_availability WHERE employee_id = ? AND day_of_week = ? ORDER BY start_time');
            $stmt->execute([$employeeId, $weekday]);
            $windows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $pdo->prepare("SELECT start_time, end_time FROM reservations WHERE employee_id = ? AND reservation_date = ? AND status IN ('pending','confirmed')");
            $stmt->execute([$employeeId, $date]);
            $busy = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $slots = [];
            $now = new DateTimeImmutable();
            foreach ($windows as $window) {
                $minute = (int)substr($window['start_time'],0,2)*60+(int)substr($window['start_time'],3,2);
                if (substr($window['start_time'],6,2) !== '00') ++$minute;
                for ($minute=(int)(ceil($minute/15)*15); $minute<1440; $minute+=15) {
                    $time = sprintf('%02d:%02d', intdiv($minute,60), $minute%60);
                    $start = new DateTimeImmutable($date.' '.$time);
                    $end = $start->modify('+'.$duration.' minutes');
                    if ($start->format('H:i') !== $time || $start <= $now || $end->format('Y-m-d') !== $date
                        || $start->format('P') !== $end->format('P') || $end->format('H:i:s') > $window['end_time']) continue;
                    foreach ($busy as $reservation) {
                        if ($reservation['start_time'] < $end->format('H:i:s') && $reservation['end_time'] > $start->format('H:i:s')) continue 2;
                    }
                    $slots[$time] = $time;
                }
            }
            sort($slots);
            echo json_encode(array_values($slots));
        } else {
            echo json_encode($employees);
        }
    } catch (PDOException $e) {
        error_log('Błąd pobierania dostępności: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Nie udało się pobrać dostępności.']);
    }

    exit;
}

/*
 * Obsługa formularzy POST:
 * - utworzenie rezerwacji,
 * - anulowanie własnej rezerwacji.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.';
    } else {
        $action = $_POST['action'] ?? 'create';

        /*
         * Anulowanie rezerwacji przez klienta.
         * Klient może anulować wyłącznie własną przyszłą rezerwację.
         */
        if ($action === 'cancel') {
            $reservation_id = filter_input(INPUT_POST, 'reservation_id', FILTER_VALIDATE_INT);
            $expected_status = $_POST['expected_status'] ?? '';

            if (!$reservation_id || $reservation_id <= 0 || !in_array($expected_status, ['pending', 'confirmed'], true)) {
                $errors[] = 'Nieprawidłowy numer rezerwacji.';
            } else {
                try {
                    $stmt = $pdo->prepare("
                        UPDATE reservations
                        SET status = 'cancelled'
                        WHERE reservation_id = :reservation_id
                          AND user_id = :user_id
                          AND status IN ('pending', 'confirmed')
                          AND status = :expected_status
                          AND (
                              reservation_date > CURDATE()
                              OR (
                                  reservation_date = CURDATE()
                                  AND start_time > CURTIME()
                              )
                          )
                    ");

                    $stmt->execute([
                        'reservation_id' => $reservation_id,
                        'user_id' => $user_id,
                        'expected_status' => $expected_status,
                    ]);

                    if ($stmt->rowCount() === 1) {
                        $_SESSION['reservation_client_success'] = 'cancelled';
                        header('Location: dashboard_client.php?cancelled=1');
                        exit;
                    }

                    $errors[] = 'Nie można anulować tej rezerwacji. Być może jest już przeszła, anulowana lub nie należy do Ciebie.';
                } catch (PDOException $e) {
                    error_log('Błąd anulowania rezerwacji: ' . $e->getMessage());
                    $errors[] = 'Wystąpił błąd podczas anulowania rezerwacji.';
                }
            }
        }

        /*
         * Tworzenie nowej rezerwacji.
         */
        elseif ($action === 'create') {
            $service_id = filter_input(INPUT_POST, 'service_id', FILTER_VALIDATE_INT);
            $employee_id = filter_input(INPUT_POST, 'employee_id', FILTER_VALIDATE_INT);
            $date = trim($_POST['reservation_date'] ?? '');
            $time = trim($_POST['start_time'] ?? '');
            $guests = filter_input(INPUT_POST, 'guest_count', FILTER_VALIDATE_INT);
            $comment = trim($_POST['comment'] ?? '');

            if (!$service_id || $service_id <= 0) {
                $errors[] = 'Wybierz usługę.';
            }

            if (!$employee_id || $employee_id <= 0) {
                $errors[] = 'Wybierz pracownika.';
            }

            if (!$is_valid_date($date)) {
                $errors[] = 'Podaj prawidłową datę.';
            } elseif ($date < date('Y-m-d')) {
                $errors[] = 'Nie można rezerwować terminów z przeszłości.';
            }

            if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
                $errors[] = 'Podaj prawidłową godzinę.';
            }

            if (!$guests || $guests < 1 || $guests > 4294967295) {
                $errors[] = 'Liczba gości musi być liczbą całkowitą od 1 do 4294967295.';
            }

            if (mb_strlen($comment) > 1000) {
                $errors[] = 'Komentarz może mieć maksymalnie 1000 znaków.';
            }

            if (empty($errors)) {
                try {
                    $pdo->beginTransaction();
                    /*
                     * Pobranie usługi. Czas trwania pobieramy z bazy,
                     * a nie z danych przesłanych przez przeglądarkę.
                     */
                    $stmt = $pdo->prepare("
                        SELECT service_id, duration_minutes
                        FROM services
                        WHERE service_id = :service_id
                          AND is_available = 1
                        FOR SHARE
                    ");
                    $stmt->execute(['service_id' => $service_id]);
                    $service = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$service) {
                        throw new RuntimeException('Wybrana usługa jest niedostępna.');
                    }

                    $duration = (int)$service['duration_minutes'];

                    if ($duration <= 0) {
                        throw new RuntimeException('Wybrana usługa ma nieprawidłowy czas trwania.');
                    }

                    $start = new DateTimeImmutable($date . ' ' . $time . ':00');
                    if ($start->format('Y-m-d H:i') !== $date . ' ' . $time) {
                        throw new RuntimeException('Wybrana godzina nie istnieje w strefie Europe/Warsaw z powodu zmiany czasu.');
                    }
                    $end = $start->modify('+' . $duration . ' minutes');
                    if ($start->format('P') !== $end->format('P')) {
                        throw new RuntimeException('Wybierz przedział, który nie obejmuje zmiany czasu.');
                    }

                    if ($end->format('Y-m-d') !== $date) {
                        throw new RuntimeException('Usługa musi zakończyć się przed północą.');
                    }

                    if ($start <= new DateTimeImmutable()) {
                        throw new RuntimeException('Nie można zarezerwować terminu, który już się rozpoczął.');
                    }

                    $start_time = $start->format('H:i:s');
                    $end_time = $end->format('H:i:s');
                    $weekday = (int)$start->format('N');

                    /*
                     * Transakcja i blokada rekordu pracownika.
                     * Dzięki temu dwa równoczesne zgłoszenia dotyczące
                     * tego samego pracownika są obsługiwane kolejno.
                     */
                    $stmt = $pdo->prepare("
                        SELECT e.employee_id
                        FROM employees e
                        JOIN users u ON u.user_id = e.user_id
                        WHERE e.employee_id = :employee_id
                          AND e.is_active = 1
                          AND u.is_active = 1 AND u.role = 'employee'
                        FOR UPDATE
                    ");
                    $stmt->execute(['employee_id' => $employee_id]);

                    if (!$stmt->fetch()) {
                        throw new RuntimeException('Wybrany pracownik jest niedostępny.');
                    }

                    /*
                     * Sprawdzenie, czy pracownik obsługuje tę usługę.
                     */
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*)
                        FROM employee_services
                        WHERE employee_id = :employee_id
                          AND service_id = :service_id
                    ");
                    $stmt->execute([
                        'employee_id' => $employee_id,
                        'service_id' => $service_id,
                    ]);

                    if ((int)$stmt->fetchColumn() === 0) {
                        throw new RuntimeException('Ten pracownik nie obsługuje wybranej usługi.');
                    }

                    /*
                     * Sprawdzenie godzin pracy.
                     * Cała usługa musi mieścić się w godzinach pracy.
                     */
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*)
                        FROM employee_availability
                        WHERE employee_id = :employee_id
                          AND day_of_week = :weekday
                          AND start_time <= :start_time
                          AND end_time >= :end_time
                    ");
                    $stmt->execute([
                        'employee_id' => $employee_id,
                        'weekday' => $weekday,
                        'start_time' => $start_time,
                        'end_time' => $end_time,
                    ]);

                    if ((int)$stmt->fetchColumn() === 0) {
                        throw new RuntimeException('Wybrana godzina znajduje się poza godzinami pracy pracownika.');
                    }

                    /*
                     * Sprawdzenie dnia wolnego pracownika.
                     */
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*)
                        FROM employee_days_off
                        WHERE employee_id = :employee_id
                          AND day_off_date = :date
                    ");
                    $stmt->execute([
                        'employee_id' => $employee_id,
                        'date' => $date,
                    ]);

                    if ((int)$stmt->fetchColumn() > 0) {
                        throw new RuntimeException('Pracownik ma w tym dniu wolne.');
                    }

                    /*
                     * Sprawdzenie zatwierdzonego urlopu.
                     */
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*)
                        FROM employee_leave_requests
                        WHERE employee_id = :employee_id
                          AND status = 'approved'
                          AND :date BETWEEN start_date AND end_date
                    ");
                    $stmt->execute([
                        'employee_id' => $employee_id,
                        'date' => $date,
                    ]);

                    if ((int)$stmt->fetchColumn() > 0) {
                        throw new RuntimeException('Pracownik przebywa w tym dniu na urlopie.');
                    }

                    /*
                     * Sprawdzenie zamknięcia restauracji.
                     */
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*)
                        FROM restaurant_days_off
                        WHERE day_off_date = :date
                    ");
                    $stmt->execute(['date' => $date]);

                    if ((int)$stmt->fetchColumn() > 0) {
                        throw new RuntimeException('Restauracja jest zamknięta w wybranym dniu.');
                    }

                    /*
                     * Wykrywanie nakładających się rezerwacji.
                     * Warunek wykrywa także częściowe nakładanie się
                     * przedziałów czasowych.
                     */
                    $stmt = $pdo->prepare("
                        SELECT reservation_id
                        FROM reservations
                        WHERE employee_id = :employee_id
                          AND reservation_date = :date
                          AND status IN ('pending', 'confirmed')
                          AND start_time < :end_time
                          AND end_time > :start_time
                        LIMIT 1
                        FOR UPDATE
                    ");
                    $stmt->execute([
                        'employee_id' => $employee_id,
                        'date' => $date,
                        'start_time' => $start_time,
                        'end_time' => $end_time,
                    ]);

                    if ($stmt->fetch()) {
                        throw new RuntimeException('Ten termin jest już zajęty. Wybierz inną godzinę.');
                    }

                    // Oczekiwanie na blokady mogło trwać do rozpoczęcia terminu.
                    if ($start <= new DateTimeImmutable()) {
                        throw new RuntimeException('Termin już się rozpoczął. Wybierz późniejszą godzinę.');
                    }

                    /*
                     * Ostateczny zapis rezerwacji.
                     */
                    $stmt = $pdo->prepare("
                        INSERT INTO reservations (
                            user_id,
                            employee_id,
                            service_id,
                            reservation_date,
                            start_time,
                            end_time,
                            guest_count,
                            status,
                            comment
                        ) VALUES (
                            :user_id,
                            :employee_id,
                            :service_id,
                            :reservation_date,
                            :start_time,
                            :end_time,
                            :guest_count,
                            'pending',
                            :comment
                        )
                    ");

                    $stmt->execute([
                        'user_id' => $user_id,
                        'employee_id' => $employee_id,
                        'service_id' => $service_id,
                        'reservation_date' => $date,
                        'start_time' => $start_time,
                        'end_time' => $end_time,
                        'guest_count' => $guests,
                        'comment' => $comment !== '' ? $comment : null,
                    ]);

                    $newReservationId = (int)$pdo->lastInsertId();
                    $pdo->commit();

                    $_SESSION['reservation_client_success'] = 'created';
                    $_SESSION['reservation_created_id'] = $newReservationId;
                    header('Location: dashboard_client.php?created=1');
                    exit;

                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    error_log('Błąd tworzenia rezerwacji: ' . $e->getMessage());
                    $errors[] = 'Wystąpił błąd bazy danych podczas rezerwacji. Spróbuj ponownie.';
                } catch (RuntimeException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errors[] = $e->getMessage();
                }
            }
        } else {
            $errors[] = 'Nieznana operacja.';
        }
    }
}

/*
 * Pobranie dostępnych usług.
 */
try {
    $stmt = $pdo->prepare("
        SELECT service_id, name, price, unit, duration_minutes
        FROM services
        WHERE is_available = 1
        ORDER BY name
    ");
    $stmt->execute();
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Błąd pobierania usług: ' . $e->getMessage());
    $services = [];
    $errors[] = 'Nie udało się pobrać listy usług.';
}

/*
 * Historia rezerwacji zalogowanego klienta.
 */
try {
    $stmt = $pdo->prepare("
        SELECT
            r.reservation_id,
            r.reservation_date,
            r.start_time,
            r.end_time,
            r.guest_count,
            r.status,
            r.comment,
            s.name AS service_name,
            u.name AS employee_name,
            u.surname AS employee_surname
        FROM reservations r
        JOIN services s ON s.service_id = r.service_id
        JOIN employees e ON e.employee_id = r.employee_id
        JOIN users u ON u.user_id = e.user_id
        WHERE r.user_id = :user_id
        ORDER BY r.reservation_date DESC, r.start_time DESC
    ");
    $stmt->execute(['user_id' => $user_id]);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Błąd historii rezerwacji: ' . $e->getMessage());
    $reservations = [];
    $errors[] = 'Nie udało się pobrać historii rezerwacji.';
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel klienta - Michelin Restaurant</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="index.php">Michelin Restaurant</a>
        <div>
            <span class="text-light me-3">
                Witaj, <?= $escape(current_user_name()) ?>
            </span>
            <a class="btn btn-outline-light btn-sm me-2" href="profile.php">Profil</a>
            <?= logout_button('btn btn-danger btn-sm') ?>
        </div>
    </div>
</nav>

<div class="container pb-5">
    <h1 class="mb-4">Panel klienta</h1>

    <?php if ($success === 'created'): ?>
        <div class="alert alert-success">
            Rezerwacja została złożona i oczekuje na potwierdzenie.
            <?php foreach ($reservations as $reservation): ?>
                <?php if ((int)$reservation['reservation_id'] === $createdId): ?>
                    <div class="mt-2">
                        <strong><?= $escape($reservation['service_name']) ?></strong><br>
                        Pracownik: <?= $escape($reservation['employee_name'].' '.$reservation['employee_surname']) ?><br>
                        Termin: <?= $escape($reservation['reservation_date']) ?>,
                        <?= $escape(substr($reservation['start_time'],0,5).'–'.substr($reservation['end_time'],0,5)) ?><br>
                        Status: <?= $escape($status_labels[$reservation['status']][0] ?? $reservation['status']) ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($success === 'cancelled'): ?>
        <div class="alert alert-success">
            Rezerwacja została anulowana.
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= $escape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h5 mb-3">Nowa rezerwacja</h2>

            <?php if (empty($services)): ?>
                <p class="text-muted mb-0">Brak dostępnych usług.</p>
            <?php else: ?>
                <form method="post" action="dashboard_client.php" class="row g-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">

                    <div class="col-md-6">
                        <label for="service_id" class="form-label">Usługa</label>
                        <select class="form-select" id="service_id" name="service_id" required>
                            <option value="">-- wybierz usługę --</option>
                            <?php foreach ($services as $service): ?>
                                <option value="<?= (int)$service['service_id'] ?>">
                                    <?= $escape($service['name']) ?>
                                    (<?= number_format((float)$service['price'], 2, ',', ' ') ?> zł /
                                    <?= $escape($service['unit']) ?>,
                                    <?= (int)$service['duration_minutes'] ?> min)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label for="reservation_date" class="form-label">Data</label>
                        <input
                            type="date"
                            class="form-control"
                            id="reservation_date"
                            name="reservation_date"
                            min="<?= date('Y-m-d') ?>"
                            required
                        >
                    </div>

                    <div class="col-md-6">
                        <label for="employee_id" class="form-label">Pracownik</label>
                        <select class="form-select" id="employee_id" name="employee_id" required disabled>
                            <option value="">Najpierw wybierz usługę i datę</option>
                        </select>
                        <small class="text-muted" id="employee_info">
                            Lista pracowników zostanie pobrana po wybraniu usługi i daty.
                        </small>
                    </div>

                    <div class="col-md-3">
                        <label for="start_time" class="form-label">Godzina rozpoczęcia</label>
                        <select class="form-select" id="start_time" name="start_time" required disabled><option value="">Najpierw wybierz pracownika</option></select><small id="slot_info" class="text-muted" aria-live="polite"></small>
                    </div>

                    <div class="col-md-3">
                        <label for="guest_count" class="form-label">Liczba gości</label>
                        <input type="number" class="form-control" id="guest_count" name="guest_count" min="1" max="4294967295" step="1" value="1" required>
                    </div>

                    <div class="col-md-9">
                        <label for="comment" class="form-label">Komentarz (opcjonalnie)</label>
                        <input type="text" class="form-control" id="comment" name="comment" maxlength="1000">
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            Zarezerwuj
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <h2 class="h5 mb-3">Moje rezerwacje</h2>

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
                                <th>Akcja</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reservations as $r): ?>
                                <?php
                                    $status = $r['status'];
                                    $status_label = $status_labels[$status][0] ?? $status;
                                    $status_class = $status_labels[$status][1] ?? 'bg-secondary';

                                    $is_future = (
                                        $r['reservation_date'] > date('Y-m-d')
                                        || (
                                            $r['reservation_date'] === date('Y-m-d')
                                            && $r['start_time'] > date('H:i:s')
                                        )
                                    );

                                    $can_cancel = $is_future && in_array($status, ['pending', 'confirmed'], true);
                                ?>
                                <tr>
                                    <td><?= $escape($r['service_name']) ?></td>
                                    <td><?= $escape($r['reservation_date']) ?></td>
                                    <td>
                                        <?= $escape(substr($r['start_time'], 0, 5)) ?>–<?= $escape(substr($r['end_time'], 0, 5)) ?>
                                    </td>
                                    <td><?= (int)$r['guest_count'] ?></td>
                                    <td>
                                        <?= $escape($r['employee_name'] . ' ' . $r['employee_surname']) ?>
                                    </td>
                                    <td>
                                        <span class="badge <?= $escape($status_class) ?>">
                                            <?= $escape($status_label) ?>
                                        </span>
                                    </td>
                                    <td><?= $escape($r['comment'] ?? '') ?></td>
                                    <td>
                                        <?php if ($can_cancel): ?>
                                            <form method="post" onsubmit="return confirm('Czy na pewno anulować tę rezerwację?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="cancel">
                                                <input type="hidden" name="expected_status" value="<?= $escape($status) ?>">
                                                <input type="hidden" name="reservation_id" value="<?= (int)$r['reservation_id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    Anuluj
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
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

<script>
const serviceSelect = document.getElementById('service_id');
const dateInput = document.getElementById('reservation_date');
const employeeSelect = document.getElementById('employee_id');
const employeeInfo = document.getElementById('employee_info');
let employeeRequest = 0;
let slotRequest = 0;
const timeSelect = document.getElementById('start_time');
const slotInfo = document.getElementById('slot_info');
function resetSlots() {
    ++slotRequest;
    if (!timeSelect) return;
    timeSelect.replaceChildren(new Option('Najpierw wybierz pracownika', ''));
    timeSelect.disabled = true;
    slotInfo.textContent = '';
}
async function loadSlots() {
    resetSlots();
    if (!employeeSelect?.value || !serviceSelect?.value || !dateInput?.value) return;
    const request = slotRequest;
    slotInfo.textContent = 'Pobieranie wolnych godzin...';
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 15000);
    try {
        const url = new URL('dashboard_client.php', location.href);
        url.search = new URLSearchParams({ajax:'slots',service_id:serviceSelect.value,employee_id:employeeSelect.value,date:dateInput.value});
        const response = await fetch(url, {signal: controller.signal});
        if (!response.ok) throw new Error();
        const slots = await response.json();
        if (!Array.isArray(slots) || slots.some(t=>typeof t !== 'string' || !/^(?:[01]\d|2[0-3]):[0-5]\d$/.test(t))) throw new Error();
        if (request !== slotRequest) return;
        timeSelect.replaceChildren(new Option(slots.length ? 'Wybierz godzinę' : 'Brak wolnych godzin', ''));
        slots.forEach(t=>timeSelect.add(new Option(t,t)));
        timeSelect.disabled = !slots.length;
        slotInfo.textContent = slots.length ? 'Dostępność zostanie ponownie sprawdzona przy zapisie.' : 'Wybierz innego pracownika lub datę.';
    } catch (error) {
        if (request !== slotRequest) return;
        slotInfo.textContent = 'Nie udało się pobrać godzin. Zmień wybór lub odśwież stronę i spróbuj ponownie.';
    } finally {
        clearTimeout(timeout);
    }
}
employeeSelect?.addEventListener('change', loadSlots);
const bookingForm = timeSelect?.closest('form');
let submitting = false;
bookingForm?.addEventListener('submit', event => {
    if (submitting || timeSelect.disabled || !timeSelect.value) { event.preventDefault(); return; }
    submitting = true;
    bookingForm.querySelector('button[type="submit"]').disabled = true;
});
window.addEventListener('pageshow', () => {
    submitting = false;
    if (bookingForm) bookingForm.querySelector('button[type="submit"]').disabled = false;
});

async function loadEmployees() {
    if (!serviceSelect || !dateInput || !employeeSelect || !employeeInfo) return;
    resetSlots();
    const request = ++employeeRequest;
    const serviceId = serviceSelect.value;
    const date = dateInput.value;

    employeeSelect.innerHTML = '';
    employeeSelect.disabled = true;

    if (!serviceId || !date) {
        employeeSelect.innerHTML = '<option value="">Najpierw wybierz usługę i datę</option>';
        employeeInfo.textContent = 'Lista pracowników zostanie pobrana po wybraniu usługi i daty.';
        return;
    }

    employeeSelect.innerHTML = '<option value="">Ładowanie...</option>';
    employeeInfo.textContent = 'Sprawdzanie dostępnych pracowników...';
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 15000);

    try {
        const url = new URL('dashboard_client.php', window.location.href);
        url.searchParams.set('ajax', 'employees');
        url.searchParams.set('service_id', serviceId);
        url.searchParams.set('date', date);

        const response = await fetch(url, {signal: controller.signal});
        if (!response.ok) {
            throw new Error('Błąd pobierania danych');
        }

        const employees = await response.json();
        if (request !== employeeRequest) return;
        employeeSelect.innerHTML = '';

        if (!Array.isArray(employees) || employees.some(e=>!e || !e.employee_id || typeof e.name !== 'string' || typeof e.surname !== 'string')) throw new Error();
        if (employees.length === 0) {
            employeeSelect.innerHTML = '<option value="">Brak dostępnych pracowników</option>';
            employeeInfo.textContent = 'Wybierz inną datę lub usługę.';
            return;
        }

        employeeSelect.add(new Option('-- wybierz pracownika --', ''));

        employees.forEach(employee => {
            const option = new Option(
                employee.name + ' ' + employee.surname,
                employee.employee_id
            );
            employeeSelect.add(option);
        });

        employeeSelect.disabled = false;
        employeeInfo.textContent = 'Wybierz pracownika z listy. Dokładna dostępność godziny zostanie sprawdzona przy zapisie.';
    } catch (error) {
        if (request !== employeeRequest) return;
        employeeSelect.innerHTML = '<option value="">Nie udało się pobrać pracowników</option>';
        employeeInfo.textContent = 'Odśwież stronę i spróbuj ponownie.';
    } finally {
        clearTimeout(timeout);
    }
}

serviceSelect?.addEventListener('change', loadEmployees);
dateInput?.addEventListener('change', loadEmployees);
loadEmployees();
</script>

</body>
</html>
