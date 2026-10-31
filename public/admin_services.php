
<?php
require_once 'auth_check.php';
require_once 'db_connect.php';

require_role(['admin']);

function service_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$errors = [];

$formData = [
    'category_id' => '',
    'name' => '',
    'description' => '',
    'price' => '',
    'unit' => 'osoba',
    'duration_minutes' => '60',
    'is_available' => '1'
];

$editId = null;
$editService = null;
$formSubmitted = false;

$statusMessages = [
    'created' => 'Usługa została dodana.',
    'updated' => 'Zmiany w usłudze zostały zapisane.',
    'deleted' => 'Usługa została usunięta.',
    'availability' => 'Status dostępności usługi został zmieniony.'
];

$status = $_GET['status'] ?? '';
$successMessage = is_string($status)
    ? ($statusMessages[$status] ?? '')
    : '';

// ID usługi przeznaczonej do edycji.
if (isset($_GET['edit'])) {
    $requestedId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);

    if ($requestedId === false || $requestedId <= 0) {
        $errors[] = 'Nieprawidłowy identyfikator usługi.';
    } else {
        $editId = (int) $requestedId;
    }
}

// --------------------------------------------------
// OBSŁUGA FORMULARZY
// --------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify()) {
        $errors[] = 'Błąd zabezpieczenia formularza. Odśwież stronę i spróbuj ponownie.';
    } else {

        $action = $_POST['action'] ?? '';

        // DODAWANIE LUB EDYCJA USŁUGI
        if ($action === 'save') {

            $formSubmitted = true;

            $rawId = is_string($_POST['service_id'] ?? null)
                ? trim($_POST['service_id'])
                : '';

            $serviceId = null;

            if ($rawId !== '') {
                $validatedId = filter_var($rawId, FILTER_VALIDATE_INT);

                if ($validatedId === false || $validatedId <= 0) {
                    $errors[] = 'Nieprawidłowy identyfikator usługi.';
                } else {
                    $serviceId = (int) $validatedId;
                }
            }

            $editId = $serviceId;

            $name = is_string($_POST['name'] ?? null)
                ? trim($_POST['name'])
                : '';

            $description = is_string($_POST['description'] ?? null)
                ? trim($_POST['description'])
                : '';

            $categoryRaw = is_string($_POST['category_id'] ?? null)
                ? trim($_POST['category_id'])
                : '';

            $priceRaw = is_string($_POST['price'] ?? null)
                ? trim($_POST['price'])
                : '';

            $unit = is_string($_POST['unit'] ?? null)
                ? trim($_POST['unit'])
                : '';

            $durationRaw = is_string($_POST['duration_minutes'] ?? null)
                ? trim($_POST['duration_minutes'])
                : '';

            $availableRaw = is_string($_POST['is_available'] ?? null)
                ? $_POST['is_available']
                : '0';

            $formData = [
                'category_id' => $categoryRaw,
                'name' => $name,
                'description' => $description,
                'price' => $priceRaw,
                'unit' => $unit,
                'duration_minutes' => $durationRaw,
                'is_available' => $availableRaw === '1' ? '1' : '0'
            ];

            // WALIDACJA NAZWY
            $nameLength = preg_match_all('/./us', $name);

            if ($name === '') {
                $errors[] = 'Nazwa usługi jest wymagana.';
            } elseif ($nameLength === false || $nameLength > 150) {
                $errors[] = 'Nazwa usługi może mieć maksymalnie 150 znaków.';
            }

            // WALIDACJA KATEGORII
            $categoryId = filter_var($categoryRaw, FILTER_VALIDATE_INT);

            if ($categoryId === false || $categoryId <= 0) {
                $errors[] = 'Wybierz prawidłową kategorię.';
            }

            // WALIDACJA CENY: DECIMAL(10,2)
            if (!preg_match('/^\d{1,8}([.,]\d{1,2})?$/D', $priceRaw)) {
                $errors[] = 'Cena musi być liczbą nieujemną z maksymalnie dwoma miejscami po przecinku.';
            } else {
                $price = str_replace(',', '.', $priceRaw);
            }

            // WALIDACJA JEDNOSTKI
            $unitLength = preg_match_all('/./us', $unit);

            if ($unit === '') {
                $errors[] = 'Jednostka rozliczeniowa jest wymagana.';
            } elseif ($unitLength === false || $unitLength > 20) {
                $errors[] = 'Jednostka może mieć maksymalnie 20 znaków.';
            }

            // WALIDACJA CZASU TRWANIA
            $duration = filter_var($durationRaw, FILTER_VALIDATE_INT);

            if ($duration === false || $duration < 1 || $duration > 65535) {
                $errors[] = 'Czas trwania musi być liczbą od 1 do 65535 minut.';
            }

            try {

                // Sprawdzenie istnienia kategorii.
                if ($categoryId !== false && $categoryId > 0) {
                    $stmt = $pdo->prepare(
                        'SELECT 1 FROM categories WHERE category_id = :id'
                    );
                    $stmt->execute(['id' => $categoryId]);

                    if (!$stmt->fetchColumn()) {
                        $errors[] = 'Wybrana kategoria nie istnieje.';
                    }
                }

                // Sprawdzenie istnienia edytowanej usługi.
                if ($serviceId !== null) {
                    $stmt = $pdo->prepare(
                        'SELECT 1 FROM services WHERE service_id = :id'
                    );
                    $stmt->execute(['id' => $serviceId]);

                    if (!$stmt->fetchColumn()) {
                        $errors[] = 'Edytowana usługa nie istnieje.';
                    }
                }

                if (empty($errors)) {

                    $descriptionValue = $description !== ''
                        ? $description
                        : null;

                    if ($serviceId === null) {

                        $stmt = $pdo->prepare(
                            'INSERT INTO services
                            (
                                category_id,
                                name,
                                description,
                                price,
                                unit,
                                duration_minutes,
                                is_available
                            )
                            VALUES
                            (
                                :category_id,
                                :name,
                                :description,
                                :price,
                                :unit,
                                :duration_minutes,
                                :is_available
                            )'
                        );

                        $stmt->execute([
                            'category_id' => $categoryId,
                            'name' => $name,
                            'description' => $descriptionValue,
                            'price' => $price,
                            'unit' => $unit,
                            'duration_minutes' => $duration,
                            'is_available' => $formData['is_available']
                        ]);

                        header('Location: admin_services.php?status=created');
                        exit;

                    } else {

                        $stmt = $pdo->prepare(
                            'UPDATE services
                             SET category_id = :category_id,
                                 name = :name,
                                 description = :description,
                                 price = :price,
                                 unit = :unit,
                                 duration_minutes = :duration_minutes,
                                 is_available = :is_available
                             WHERE service_id = :id'
                        );

                        $stmt->execute([
                            'category_id' => $categoryId,
                            'name' => $name,
                            'description' => $descriptionValue,
                            'price' => $price,
                            'unit' => $unit,
                            'duration_minutes' => $duration,
                            'is_available' => $formData['is_available'],
                            'id' => $serviceId
                        ]);

                        header('Location: admin_services.php?status=updated');
                        exit;
                    }
                }

            } catch (PDOException $e) {
                error_log('Błąd zapisu usługi: ' . $e->getMessage());
                $errors[] = 'Wystąpił błąd podczas zapisywania usługi.';
            }

        // USUWANIE USŁUGI
        } elseif ($action === 'delete') {

            $deleteId = filter_var(
                $_POST['service_id'] ?? '',
                FILTER_VALIDATE_INT
            );

            if ($deleteId === false || $deleteId <= 0) {
                $errors[] = 'Nieprawidłowy identyfikator usługi.';
            } else {

                try {

                    $stmt = $pdo->prepare(
                        'SELECT 1 FROM services WHERE service_id = :id'
                    );
                    $stmt->execute(['id' => $deleteId]);

                    if (!$stmt->fetchColumn()) {
                        $errors[] = 'Usługa nie istnieje.';
                    }

                    // Sprawdzenie rezerwacji.
                    $stmt = $pdo->prepare(
                        'SELECT COUNT(*) FROM reservations WHERE service_id = :id'
                    );
                    $stmt->execute(['id' => $deleteId]);
                    $reservationsCount = (int) $stmt->fetchColumn();

                    // Sprawdzenie przypisania do pracowników.
                    $stmt = $pdo->prepare(
                        'SELECT COUNT(*) FROM employee_services WHERE service_id = :id'
                    );
                    $stmt->execute(['id' => $deleteId]);
                    $employeesCount = (int) $stmt->fetchColumn();

                    if ($reservationsCount > 0) {
                        $errors[] = 'Nie można usunąć usługi, która posiada rezerwacje.';
                    }

                    if ($employeesCount > 0) {
                        $errors[] = 'Najpierw usuń przypisania tej usługi do pracowników.';
                    }

                    if (empty($errors)) {

                        $stmt = $pdo->prepare(
                            'DELETE FROM services WHERE service_id = :id'
                        );
                        $stmt->execute(['id' => $deleteId]);

                        header('Location: admin_services.php?status=deleted');
                        exit;
                    }

                } catch (PDOException $e) {
                    error_log('Błąd usuwania usługi: ' . $e->getMessage());
                    $errors[] = 'Wystąpił błąd podczas usuwania usługi.';
                }
            }

        // ZMIANA DOSTĘPNOŚCI
        } elseif ($action === 'toggle') {

            $toggleId = filter_var(
                $_POST['service_id'] ?? '',
                FILTER_VALIDATE_INT
            );

            if ($toggleId === false || $toggleId <= 0) {
                $errors[] = 'Nieprawidłowy identyfikator usługi.';
            } else {

                try {

                    $stmt = $pdo->prepare(
                        'UPDATE services
                         SET is_available = CASE
                             WHEN is_available = 1 THEN 0
                             ELSE 1
                         END
                         WHERE service_id = :id'
                    );

                    $stmt->execute(['id' => $toggleId]);

                    if ($stmt->rowCount() === 0) {
                        $errors[] = 'Nie znaleziono usługi do zmiany statusu.';
                    } else {
                        header('Location: admin_services.php?status=availability');
                        exit;
                    }

                } catch (PDOException $e) {
                    error_log('Błąd zmiany dostępności usługi: ' . $e->getMessage());
                    $errors[] = 'Nie udało się zmienić dostępności usługi.';
                }
            }

        } else {
            $errors[] = 'Nieznana operacja.';
        }
    }
}

// --------------------------------------------------
// POBRANIE USŁUGI DO EDYCJI
// --------------------------------------------------

if ($editId !== null) {

    try {

        $stmt = $pdo->prepare(
            'SELECT * FROM services WHERE service_id = :id'
        );
        $stmt->execute(['id' => $editId]);

        $editService = $stmt->fetch();

        if (!$editService) {
            $errors[] = 'Nie znaleziono usługi do edycji.';
            $editId = null;
        } elseif (!$formSubmitted) {

            $formData = [
                'category_id' => (string) $editService['category_id'],
                'name' => $editService['name'],
                'description' => $editService['description'] ?? '',
                'price' => number_format((float) $editService['price'], 2, '.', ''),
                'unit' => $editService['unit'],
                'duration_minutes' => (string) $editService['duration_minutes'],
                'is_available' => (int) $editService['is_available'] === 1 ? '1' : '0'
            ];
        }

    } catch (PDOException $e) {
        error_log('Błąd pobierania usługi: ' . $e->getMessage());
        $errors[] = 'Nie udało się pobrać usługi.';
    }
}

// --------------------------------------------------
// POBRANIE KATEGORII
// --------------------------------------------------

$categories = [];

try {

    $stmt = $pdo->query(
        'SELECT category_id, name
         FROM categories
         ORDER BY sort_order ASC, name ASC'
    );

    $categories = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log('Błąd pobierania kategorii: ' . $e->getMessage());
    $errors[] = 'Nie udało się pobrać kategorii.';
}

// --------------------------------------------------
// POBRANIE LISTY USŁUG
// --------------------------------------------------

$services = [];

try {

    $stmt = $pdo->query(
        'SELECT
            s.service_id,
            s.category_id,
            s.name,
            s.description,
            s.price,
            s.unit,
            s.duration_minutes,
            s.is_available,
            c.name AS category_name,
            (
                SELECT COUNT(*)
                FROM reservations r
                WHERE r.service_id = s.service_id
            ) AS reservations_count,
            (
                SELECT COUNT(*)
                FROM employee_services es
                WHERE es.service_id = s.service_id
            ) AS employees_count
         FROM services s
         INNER JOIN categories c
            ON c.category_id = s.category_id
         ORDER BY s.created_at DESC, s.service_id DESC'
    );

    $services = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log('Błąd pobierania usług: ' . $e->getMessage());
    $errors[] = 'Nie udało się pobrać listy usług.';
}

?>
<!DOCTYPE html>
<html lang="pl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Zarządzanie usługami - Michelin Restaurant</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
</head>

<body class="bg-light">

<nav class="navbar navbar-dark bg-dark mb-4">
    <div class="container">

        <a class="navbar-brand" href="index.php">
            Michelin Restaurant
        </a>

        <div>
            <a href="dashboard_admin.php" class="btn btn-outline-light btn-sm me-2">
                Panel administratora
            </a>

            <a href="admin_categories.php" class="btn btn-outline-light btn-sm me-2">
                Kategorie
            </a>

            <a href="profile.php" class="btn btn-outline-light btn-sm me-2">
                Profil
            </a>

            <a href="logout.php" class="btn btn-danger btn-sm">
                Wyloguj
            </a>
        </div>

    </div>
</nav>

<div class="container pb-5">

    <h1 class="mb-4">Zarządzanie usługami</h1>

    <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success">
            <?= service_h($successMessage) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= service_h($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- FORMULARZ -->
        <div class="col-lg-4">

            <div class="card shadow-sm">
                <div class="card-body">

                    <h2 class="h5 card-title mb-3">
                        <?= $editService ? 'Edytuj usługę' : 'Dodaj usługę' ?>
                    </h2>

                    <?php if (empty($categories)): ?>

                        <div class="alert alert-warning">
                            Najpierw dodaj przynajmniej jedną kategorię.
                        </div>

                    <?php else: ?>

                        <form method="post" action="admin_services.php">

                            <?= csrf_field() ?>

                            <input type="hidden" name="action" value="save">

                            <?php if ($editService): ?>
                                <input
                                    type="hidden"
                                    name="service_id"
                                    value="<?= (int) $editService['service_id'] ?>"
                                >
                            <?php endif; ?>

                            <div class="mb-3">

                                <label for="name" class="form-label">
                                    Nazwa usługi
                                </label>

                                <input
                                    type="text"
                                    class="form-control"
                                    id="name"
                                    name="name"
                                    maxlength="150"
                                    required
                                    value="<?= service_h($formData['name']) ?>"
                                >

                            </div>

                            <div class="mb-3">

                                <label for="category_id" class="form-label">
                                    Kategoria
                                </label>

                                <select
                                    class="form-select"
                                    id="category_id"
                                    name="category_id"
                                    required
                                >

                                    <option value="">Wybierz kategorię</option>

                                    <?php foreach ($categories as $category): ?>

                                        <option
                                            value="<?= (int) $category['category_id'] ?>"
                                            <?= $formData['category_id'] === (string) $category['category_id']
                                                ? 'selected'
                                                : '' ?>
                                        >
                                            <?= service_h($category['name']) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                            <div class="mb-3">

                                <label for="description" class="form-label">
                                    Opis usługi
                                </label>

                                <textarea
                                    class="form-control"
                                    id="description"
                                    name="description"
                                    rows="3"
                                ><?= service_h($formData['description']) ?></textarea>

                            </div>

                            <div class="mb-3">

                                <label for="price" class="form-label">
                                    Cena
                                </label>

                                <input
                                    type="number"
                                    class="form-control"
                                    id="price"
                                    name="price"
                                    min="0"
                                    max="99999999.99"
                                    step="0.01"
                                    required
                                    value="<?= service_h($formData['price']) ?>"
                                >

                            </div>

                            <div class="mb-3">

                                <label for="unit" class="form-label">
                                    Jednostka rozliczeniowa
                                </label>

                                <input
                                    type="text"
                                    class="form-control"
                                    id="unit"
                                    name="unit"
                                    maxlength="20"
                                    required
                                    value="<?= service_h($formData['unit']) ?>"
                                >

                                <div class="form-text">
                                    Przykład: osoba, sztuka, godzina.
                                </div>

                            </div>

                            <div class="mb-3">

                                <label for="duration_minutes" class="form-label">
                                    Czas trwania (minuty)
                                </label>

                                <input
                                    type="number"
                                    class="form-control"
                                    id="duration_minutes"
                                    name="duration_minutes"
                                    min="1"
                                    max="65535"
                                    step="1"
                                    required
                                    value="<?= service_h($formData['duration_minutes']) ?>"
                                >

                            </div>

                            <div class="form-check mb-3">

                                <input
                                    type="hidden"
                                    name="is_available"
                                    value="0"
                                >

                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    id="is_available"
                                    name="is_available"
                                    value="1"
                                    <?= $formData['is_available'] === '1' ? 'checked' : '' ?>
                                >

                                <label class="form-check-label" for="is_available">
                                    Usługa dostępna
                                </label>

                            </div>

                            <button type="submit" class="btn btn-primary">
                                <?= $editService ? 'Zapisz zmiany' : 'Dodaj usługę' ?>
                            </button>

                            <?php if ($editService): ?>

                                <a
                                    href="admin_services.php"
                                    class="btn btn-outline-secondary"
                                >
                                    Anuluj
                                </a>

                            <?php endif; ?>

                        </form>

                    <?php endif; ?>

                </div>
            </div>

        </div>

        <!-- LISTA USŁUG -->
        <div class="col-lg-8">

            <div class="card shadow-sm">
                <div class="card-body">

                    <h2 class="h5 card-title mb-3">
                        Lista usług
                    </h2>

                    <?php if (empty($services)): ?>

                        <p class="text-muted mb-0">
                            Brak usług w bazie danych.
                        </p>

                    <?php else: ?>

                        <div class="table-responsive">

                            <table class="table table-striped align-middle">

                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Usługa</th>
                                        <th>Kategoria</th>
                                        <th>Cena</th>
                                        <th>Czas</th>
                                        <th>Status</th>
                                        <th>Akcje</th>
                                    </tr>
                                </thead>

                                <tbody>

                                    <?php foreach ($services as $service): ?>

                                        <?php
                                        $hasDependencies =
                                            (int) $service['reservations_count'] > 0 ||
                                            (int) $service['employees_count'] > 0;
                                        ?>

                                        <tr>

                                            <td>
                                                <?= (int) $service['service_id'] ?>
                                            </td>

                                            <td>
                                                <strong>
                                                    <?= service_h($service['name']) ?>
                                                </strong>

                                                <br>

                                                <small class="text-muted">
                                                    <?= service_h($service['unit']) ?>
                                                </small>
                                            </td>

                                            <td>
                                                <?= service_h($service['category_name']) ?>
                                            </td>

                                            <td>
                                                <?= number_format((float) $service['price'], 2, ',', ' ') ?>
                                            </td>

                                            <td>
                                                <?= (int) $service['duration_minutes'] ?> min
                                            </td>

                                            <td>

                                                <?php if ((int) $service['is_available'] === 1): ?>

                                                    <span class="badge bg-success">
                                                        Dostępna
                                                    </span>

                                                <?php else: ?>

                                                    <span class="badge bg-secondary">
                                                        Niedostępna
                                                    </span>

                                                <?php endif; ?>

                                            </td>

                                            <td>

                                                <div class="d-flex flex-wrap gap-2">

                                                    <a
                                                        href="admin_services.php?edit=<?= (int) $service['service_id'] ?>"
                                                        class="btn btn-sm btn-outline-primary"
                                                    >
                                                        Edytuj
                                                    </a>

                                                    <form
                                                        method="post"
                                                        action="admin_services.php"
                                                    >

                                                        <?= csrf_field() ?>

                                                        <input
                                                            type="hidden"
                                                            name="action"
                                                            value="toggle"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="service_id"
                                                            value="<?= (int) $service['service_id'] ?>"
                                                        >

                                                        <?php if ((int) $service['is_available'] === 1): ?>

                                                            <button
                                                                type="submit"
                                                                class="btn btn-sm btn-outline-warning"
                                                            >
                                                                Dezaktywuj
                                                            </button>

                                                        <?php else: ?>

                                                            <button
                                                                type="submit"
                                                                class="btn btn-sm btn-outline-success"
                                                            >
                                                                Aktywuj
                                                            </button>

                                                        <?php endif; ?>

                                                    </form>

                                                    <?php if (!$hasDependencies): ?>

                                                        <form
                                                            method="post"
                                                            action="admin_services.php"
                                                            onsubmit="return confirm('Czy na pewno chcesz usunąć tę usługę?');"
                                                        >

                                                            <?= csrf_field() ?>

                                                            <input
                                                                type="hidden"
                                                                name="action"
                                                                value="delete"
                                                            >

                                                            <input
                                                                type="hidden"
                                                                name="service_id"
                                                                value="<?= (int) $service['service_id'] ?>"
                                                            >

                                                            <button
                                                                type="submit"
                                                                class="btn btn-sm btn-outline-danger"
                                                            >
                                                                Usuń
                                                            </button>

                                                        </form>

                                                    <?php else: ?>

                                                        <button
                                                            type="button"
                                                            class="btn btn-sm btn-outline-secondary"
                                                            disabled
                                                            title="Usługa posiada rezerwacje lub przypisanych pracowników."
                                                        >
                                                            Usuń
                                                        </button>

                                                    <?php endif; ?>

                                                </div>

                                                <small class="text-muted d-block mt-1">
                                                    Rezerwacje: <?= (int) $service['reservations_count'] ?> |
                                                    Pracownicy: <?= (int) $service['employees_count'] ?>
                                                </small>

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

    </div>

</div>

</body>
</html>
