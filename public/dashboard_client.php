<?php
require_once 'auth_check.php';
require_once 'db_connect.php';

require_role(['client']);

$user_id = (int) current_user_id();

$errors = [];
$success = '';
$escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

$status_labels = [
    'pending'   => ['Oczekująca', 'bg-warning text-dark'],
    'confirmed' => ['Potwierdzona', 'bg-success'],
    'cancelled' => ['Anulowana', 'bg-secondary'],
    'completed' => ['Zrealizowana', 'bg-primary'],
    'no_show'   => ['Nieobecność', 'bg-danger'],
];

$is_valid_date = static function ($date) {
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    return $parsed && $parsed->format('Y-m-d') === $date;
};

/*
 * AJAX: pobieranie pracowników obsługujących usługę
 * w wybranym dniu, z uwzględnieniem ich dostępności i dni wolnych.
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['ajax'] ?? '') === 'employees') {
    header('Content-Type: application/json; charset=utf-8');

    $service_id = filter_input(INPUT_GET, 'service_id', FILTER_VALIDATE_INT);
    $date = trim($_GET['date'] ?? '');

    if (!$service_id || !$is_valid_date($date) || $date < date('Y-m-d')) {
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
            WHERE es.service_id = :service_id
              AND e.is_active = 1
              AND u.is_active = 1

              AND EXISTS (
                  SELECT 1
                  FROM employee_availability ea
                  WHERE ea.employee_id = e.employee_id
                    AND ea.day_of_week = :weekday
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
            'day_off_date' => $date,
            'leave_date' => $date,
            'restaurant_date' => $date,
        ]);

        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (PDOException $e) {
        error_log('Błąd pobierania pracowników: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Nie udało się pobrać pracowników.']);
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

            if (!$reservation_id || $reservation_id <= 0) {
                $errors[] = 'Nieprawidłowy numer rezerwacji.';
            } else {
                try {
                    $stmt = $pdo->prepare("
                        UPDATE reservations
                        SET status = 'cancelled'
                        WHERE reservation_id = :reservation_id
                          AND user_id = :user_id
                          AND status IN ('pending', 'confirmed')
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
                    ]);

                    if ($stmt->rowCount() === 1) {
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

            if (!$guests || $guests < 1) {
                $errors[] = 'Liczba gości musi wynosić co najmniej 1.';
            }

            if (mb_strlen($comment) > 1000) {
                $errors[] = 'Komentarz może mieć maksymalnie 1000 znaków.';
            }

            if (empty($errors)) {
                try {
                    /*
                     * Pobranie usługi. Czas trwania pobieramy z bazy,
                     * a nie z danych przesłanych przez przeglądarkę.
                     */
                    $stmt = $pdo->prepare("
                        SELECT service_id, duration_minutes
                        FROM services
                        WHERE service_id = :service_id
                          AND is_available = 1
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
                    $end = $start->modify('+' . $duration . ' minutes');

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
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare("
                        SELECT employee_id
                        FROM employees
                        WHERE employee_id = :employee_id
                          AND is_active = 1
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

                    $pdo->commit();

                    header('Location: dashboard_client.php?created=1');
                    exit;

                } catch (RuntimeException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errors[] = $e->getMessage();

                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    error_log('Błąd tworzenia rezerwacji: ' . $e->getMessage());
                    $errors[] = 'Wystąpił błąd bazy danych podczas rezerwacji. Spróbuj ponownie.';
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
    $stmt = $pdo->query("
        SELECT service_id, name, price, unit, duration_minutes
        FROM services
        WHERE is_available = 1
        ORDER BY name
    ");
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
            <a class="btn btn-danger btn-sm" href="logout.php">Wyloguj</a>
        </div>
    </div>
</nav>

<div class="container pb-5">
    <h1 class="mb-4">Panel klienta</h1>

    <?php if (isset($_GET['created'])): ?>
        <div class="alert alert-success">
            Rezerwacja została złożona i oczekuje na potwierdzenie.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['cancelled'])): ?>
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

                    <div class="col-md-3">
                        <label for="start_time" class="form-label">Godzina rozpoczęcia</label>
                        <input type="time" class="form-control" id="start_time" name="start_time" required>
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
                        <label for="guest_count" class="form-label">Liczba gości</label>
                        <input type="number" class="form-control" id="guest_count" name="guest_count" min="1" value="1" required>
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

async function loadEmployees() {
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

    try {
        const url = new URL('dashboard_client.php', window.location.href);
        url.searchParams.set('ajax', 'employees');
        url.searchParams.set('service_id', serviceId);
        url.searchParams.set('date', date);

        const response = await fetch(url);
        if (!response.ok) {
            throw new Error('Błąd pobierania danych');
        }

        const employees = await response.json();
        employeeSelect.innerHTML = '';

        if (!Array.isArray(employees) || employees.length === 0) {
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
        employeeSelect.innerHTML = '<option value="">Nie udało się pobrać pracowników</option>';
        employeeInfo.textContent = 'Odśwież stronę i spróbuj ponownie.';
    }
}

serviceSelect.addEventListener('change', loadEmployees);
dateInput.addEventListener('change', loadEmployees);
</script>

</body>
</html>