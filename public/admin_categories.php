
<?php
require_once 'auth_check.php';
require_once 'db_connect.php';

require_role(['admin']);

// Bezpieczne wyświetlanie tekstu w HTML.
function category_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Tworzenie podstawowej wersji slug z nazwy kategorii.
function category_slug_base(string $name): string
{
    $polish = [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e',
        'ł' => 'l', 'ń' => 'n', 'ó' => 'o',
        'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'Ą' => 'a', 'Ć' => 'c', 'Ę' => 'e',
        'Ł' => 'l', 'Ń' => 'n', 'Ó' => 'o',
        'Ś' => 's', 'Ź' => 'z', 'Ż' => 'z'
    ];

    $name = strtr($name, $polish);
    $slug = strtolower($name);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim((string) $slug, '-');

    return $slug !== '' ? $slug : 'kategoria';
}

// Zapewnienie unikalności slug.
function category_unique_slug(PDO $pdo, string $name, ?int $excludeId = null): string
{
    $base = category_slug_base($name);
    $slug = $base;
    $suffix = 2;

    while (true) {
        $sql = 'SELECT category_id FROM categories WHERE slug = :slug';
        $params = ['slug' => $slug];

        if ($excludeId !== null) {
            $sql .= ' AND category_id <> :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->fetchColumn() === false) {
            return $slug;
        }

        $slug = $base . '-' . $suffix;
        $suffix++;
    }
}

$errors = [];
$formData = [
    'name' => '',
    'description' => '',
    'sort_order' => '0',
    'parent_id' => ''
];

$editId = null;
$editCategory = null;
$formSubmitted = false;

// Komunikaty po poprawnym wykonaniu operacji.
$statusMessages = [
    'created' => 'Kategoria została dodana.',
    'updated' => 'Zmiany w kategorii zostały zapisane.',
    'deleted' => 'Kategoria została usunięta.'
];

$status = $_GET['status'] ?? '';
$successMessage = is_string($status)
    ? ($statusMessages[$status] ?? '')
    : '';

// Odczyt ID kategorii przeznaczonej do edycji.
if (isset($_GET['edit'])) {
    $requestedId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);

    if ($requestedId === false || $requestedId <= 0) {
        $errors[] = 'Nieprawidłowy identyfikator kategorii.';
    } else {
        $editId = (int) $requestedId;
    }
}

// --------------------------------------------------
// OBSŁUGA FORMULARZY POST
// --------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify()) {
        $errors[] = 'Błąd zabezpieczenia formularza. Odśwież stronę i spróbuj ponownie.';
    } else {

        $action = $_POST['action'] ?? '';

        // DODAWANIE LUB EDYCJA KATEGORII
        if ($action === 'save') {

            $formSubmitted = true;

            $rawId = $_POST['category_id'] ?? '';
            $categoryId = null;

            if ($rawId !== '') {
                $validatedId = filter_var($rawId, FILTER_VALIDATE_INT);

                if ($validatedId === false || $validatedId <= 0) {
                    $errors[] = 'Nieprawidłowy identyfikator kategorii.';
                } else {
                    $categoryId = (int) $validatedId;
                }
            }

            $editId = $categoryId;

            $name = is_string($_POST['name'] ?? null)
                ? trim($_POST['name'])
                : '';

            $description = is_string($_POST['description'] ?? null)
                ? trim($_POST['description'])
                : '';

            $sortRaw = is_string($_POST['sort_order'] ?? null)
                ? $_POST['sort_order']
                : '0';

            $parentRaw = is_string($_POST['parent_id'] ?? null)
                ? trim($_POST['parent_id'])
                : '';

            $formData = [
                'name' => $name,
                'description' => $description,
                'sort_order' => $sortRaw,
                'parent_id' => $parentRaw
            ];

            // Walidacja nazwy.
            $nameCharacters = [];
            $nameLength = preg_match_all('/./us', $name, $nameCharacters);

            if ($name === '') {
                $errors[] = 'Nazwa kategorii jest wymagana.';
            } elseif ($nameLength === false || $nameLength > 100) {
                $errors[] = 'Nazwa kategorii może mieć maksymalnie 100 znaków.';
            }

            // Walidacja kolejności wyświetlania.
            $sortOrder = filter_var($sortRaw, FILTER_VALIDATE_INT);

            if ($sortOrder === false || $sortOrder < 0) {
                $errors[] = 'Kolejność musi być liczbą całkowitą nie mniejszą niż zero.';
            }

            // Walidacja kategorii nadrzędnej.
            $parentId = null;

            if ($parentRaw !== '') {
                $validatedParent = filter_var($parentRaw, FILTER_VALIDATE_INT);

                if ($validatedParent === false || $validatedParent <= 0) {
                    $errors[] = 'Nieprawidłowa kategoria nadrzędna.';
                } else {
                    $parentId = (int) $validatedParent;
                }
            }

            try {

                // Sprawdzenie, czy edytowana kategoria istnieje.
                if ($categoryId !== null) {
                    $stmt = $pdo->prepare(
                        'SELECT 1 FROM categories WHERE category_id = :id'
                    );
                    $stmt->execute(['id' => $categoryId]);

                    if (!$stmt->fetchColumn()) {
                        $errors[] = 'Edytowana kategoria nie istnieje.';
                    }
                }

                // Sprawdzenie, czy wybrana kategoria nadrzędna istnieje.
                if ($parentId !== null) {
                    $stmt = $pdo->prepare(
                        'SELECT 1 FROM categories WHERE category_id = :id'
                    );
                    $stmt->execute(['id' => $parentId]);

                    if (!$stmt->fetchColumn()) {
                        $errors[] = 'Wybrana kategoria nadrzędna nie istnieje.';
                    }
                }

                // Zabezpieczenie przed przypisaniem kategorii do samej siebie
                // lub do jednej z jej kategorii potomnych.
                if ($parentId !== null && $categoryId !== null && empty($errors)) {

                    $currentId = $parentId;
                    $visited = [];

                    while ($currentId !== null) {

                        if ($currentId === $categoryId) {
                            $errors[] = 'Nie można utworzyć takiej hierarchii kategorii.';
                            break;
                        }

                        if (isset($visited[$currentId])) {
                            $errors[] = 'Wykryto nieprawidłową hierarchię kategorii.';
                            break;
                        }

                        $visited[$currentId] = true;

                        $stmt = $pdo->prepare(
                            'SELECT parent_id FROM categories WHERE category_id = :id'
                        );
                        $stmt->execute(['id' => $currentId]);

                        $nextId = $stmt->fetchColumn();

                        $currentId = ($nextId === false || $nextId === null)
                            ? null
                            : (int) $nextId;
                    }
                }

                // Zapis dopiero po przejściu wszystkich walidacji.
                if (empty($errors)) {

                    $slug = category_unique_slug($pdo, $name, $categoryId);
                    $descriptionValue = $description !== '' ? $description : null;

                    if ($categoryId === null) {

                        $stmt = $pdo->prepare(
                            'INSERT INTO categories
                            (parent_id, name, slug, description, sort_order)
                            VALUES
                            (:parent_id, :name, :slug, :description, :sort_order)'
                        );

                        $stmt->execute([
                            'parent_id' => $parentId,
                            'name' => $name,
                            'slug' => $slug,
                            'description' => $descriptionValue,
                            'sort_order' => $sortOrder
                        ]);

                        header('Location: admin_categories.php?status=created');
                        exit;

                    } else {

                        $stmt = $pdo->prepare(
                            'UPDATE categories
                             SET parent_id = :parent_id,
                                 name = :name,
                                 slug = :slug,
                                 description = :description,
                                 sort_order = :sort_order
                             WHERE category_id = :id'
                        );

                        $stmt->execute([
                            'parent_id' => $parentId,
                            'name' => $name,
                            'slug' => $slug,
                            'description' => $descriptionValue,
                            'sort_order' => $sortOrder,
                            'id' => $categoryId
                        ]);

                        header('Location: admin_categories.php?status=updated');
                        exit;
                    }
                }

            } catch (PDOException $e) {
                error_log('Błąd zapisu kategorii: ' . $e->getMessage());
                $errors[] = 'Wystąpił błąd podczas zapisywania kategorii.';
            }

        // USUWANIE KATEGORII
        } elseif ($action === 'delete') {

            $deleteId = filter_var(
                $_POST['category_id'] ?? '',
                FILTER_VALIDATE_INT
            );

            if ($deleteId === false || $deleteId <= 0) {
                $errors[] = 'Nieprawidłowy identyfikator kategorii.';
            } else {

                try {

                    $stmt = $pdo->prepare(
                        'SELECT 1 FROM categories WHERE category_id = :id'
                    );
                    $stmt->execute(['id' => $deleteId]);

                    if (!$stmt->fetchColumn()) {
                        $errors[] = 'Kategoria nie istnieje.';
                    }

                    // Nie pozwalamy usunąć kategorii z przypisanymi usługami.
                    $stmt = $pdo->prepare(
                        'SELECT COUNT(*) FROM services WHERE category_id = :id'
                    );
                    $stmt->execute(['id' => $deleteId]);
                    $servicesCount = (int) $stmt->fetchColumn();

                    if ($servicesCount > 0) {
                        $errors[] = 'Nie można usunąć kategorii, do której przypisane są usługi.';
                    }

                    // Nie pozwalamy usunąć kategorii posiadającej podkategorie.
                    $stmt = $pdo->prepare(
                        'SELECT COUNT(*) FROM categories WHERE parent_id = :id'
                    );
                    $stmt->execute(['id' => $deleteId]);
                    $childrenCount = (int) $stmt->fetchColumn();

                    if ($childrenCount > 0) {
                        $errors[] = 'Najpierw przenieś lub usuń podkategorie.';
                    }

                    if (empty($errors)) {

                        $stmt = $pdo->prepare(
                            'DELETE FROM categories WHERE category_id = :id'
                        );
                        $stmt->execute(['id' => $deleteId]);

                        header('Location: admin_categories.php?status=deleted');
                        exit;
                    }

                } catch (PDOException $e) {
                    error_log('Błąd usuwania kategorii: ' . $e->getMessage());
                    $errors[] = 'Wystąpił błąd podczas usuwania kategorii.';
                }
            }

        } else {
            $errors[] = 'Nieznana operacja.';
        }
    }
}

// --------------------------------------------------
// POBRANIE KATEGORII DO EDYCJI
// --------------------------------------------------

if ($editId !== null) {

    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM categories WHERE category_id = :id'
        );
        $stmt->execute(['id' => $editId]);
        $editCategory = $stmt->fetch();

        if (!$editCategory) {
            $errors[] = 'Nie znaleziono kategorii do edycji.';
            $editId = null;
        } elseif (!$formSubmitted) {

            $formData = [
                'name' => $editCategory['name'],
                'description' => $editCategory['description'] ?? '',
                'sort_order' => (string) $editCategory['sort_order'],
                'parent_id' => $editCategory['parent_id'] === null
                    ? ''
                    : (string) $editCategory['parent_id']
            ];
        }

    } catch (PDOException $e) {
        error_log('Błąd pobierania kategorii do edycji: ' . $e->getMessage());
        $errors[] = 'Nie udało się pobrać kategorii.';
    }
}

// --------------------------------------------------
// POBRANIE LISTY KATEGORII
// --------------------------------------------------

$categories = [];
$parentOptions = [];

try {

    $stmt = $pdo->query(
        'SELECT
            c.category_id,
            c.parent_id,
            c.name,
            c.slug,
            c.description,
            c.sort_order,
            p.name AS parent_name,
            (SELECT COUNT(*)
             FROM services s
             WHERE s.category_id = c.category_id) AS services_count,
            (SELECT COUNT(*)
             FROM categories child
             WHERE child.parent_id = c.category_id) AS children_count
         FROM categories c
         LEFT JOIN categories p ON p.category_id = c.parent_id
         ORDER BY c.sort_order ASC, c.name ASC'
    );

    $categories = $stmt->fetchAll();

    $stmt = $pdo->query(
        'SELECT category_id, name
         FROM categories
         ORDER BY sort_order ASC, name ASC'
    );

    $parentOptions = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log('Błąd pobierania listy kategorii: ' . $e->getMessage());
    $errors[] = 'Nie udało się pobrać listy kategorii.';
}

?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Zarządzanie kategoriami - Michelin Restaurant</title>

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

    <h1 class="mb-4">Zarządzanie kategoriami</h1>

    <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success">
            <?= category_h($successMessage) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= category_h($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- FORMULARZ DODAWANIA I EDYCJI -->
        <div class="col-lg-4">

            <div class="card shadow-sm">
                <div class="card-body">

                    <h2 class="h5 card-title mb-3">
                        <?= $editCategory ? 'Edytuj kategorię' : 'Dodaj kategorię' ?>
                    </h2>

                    <form method="post" action="admin_categories.php">

                        <?= csrf_field() ?>

                        <input type="hidden" name="action" value="save">

                        <?php if ($editCategory): ?>
                            <input
                                type="hidden"
                                name="category_id"
                                value="<?= (int) $editCategory['category_id'] ?>"
                            >
                        <?php endif; ?>

                        <div class="mb-3">
                            <label for="name" class="form-label">
                                Nazwa kategorii
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                id="name"
                                name="name"
                                maxlength="100"
                                required
                                value="<?= category_h($formData['name']) ?>"
                            >
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">
                                Opis kategorii
                            </label>

                            <textarea
                                class="form-control"
                                id="description"
                                name="description"
                                rows="3"
                            ><?= category_h($formData['description']) ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label for="parent_id" class="form-label">
                                Kategoria nadrzędna
                            </label>

                            <select
                                class="form-select"
                                id="parent_id"
                                name="parent_id"
                            >
                                <option value="">Brak - kategoria główna</option>

                                <?php foreach ($parentOptions as $option): ?>

                                    <?php if (
                                        !$editCategory ||
                                        (int) $option['category_id'] !== (int) $editCategory['category_id']
                                    ): ?>

                                        <option
                                            value="<?= (int) $option['category_id'] ?>"
                                            <?= $formData['parent_id'] === (string) $option['category_id']
                                                ? 'selected'
                                                : '' ?>
                                        >
                                            <?= category_h($option['name']) ?>
                                        </option>

                                    <?php endif; ?>

                                <?php endforeach; ?>

                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="sort_order" class="form-label">
                                Kolejność wyświetlania
                            </label>

                            <input
                                type="number"
                                class="form-control"
                                id="sort_order"
                                name="sort_order"
                                min="0"
                                step="1"
                                required
                                value="<?= category_h($formData['sort_order']) ?>"
                            >
                        </div>

                        <p class="text-muted small">
                            Adres slug zostanie wygenerowany automatycznie na podstawie nazwy kategorii.
                        </p>

                        <button type="submit" class="btn btn-primary">
                            <?= $editCategory ? 'Zapisz zmiany' : 'Dodaj kategorię' ?>
                        </button>

                        <?php if ($editCategory): ?>
                            <a href="admin_categories.php" class="btn btn-outline-secondary">
                                Anuluj
                            </a>
                        <?php endif; ?>

                    </form>

                </div>
            </div>

        </div>

        <!-- LISTA KATEGORII -->
        <div class="col-lg-8">

            <div class="card shadow-sm">
                <div class="card-body">

                    <h2 class="h5 card-title mb-3">
                        Lista kategorii
                    </h2>

                    <?php if (empty($categories)): ?>

                        <p class="text-muted mb-0">
                            Brak kategorii w bazie danych.
                        </p>

                    <?php else: ?>

                        <div class="table-responsive">

                            <table class="table table-striped align-middle">

                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Nazwa</th>
                                        <th>Kategoria nadrzędna</th>
                                        <th>Usługi</th>
                                        <th>Kolejność</th>
                                        <th>Akcje</th>
                                    </tr>
                                </thead>

                                <tbody>

                                    <?php foreach ($categories as $category): ?>

                                        <?php
                                        $hasDependencies =
                                            (int) $category['services_count'] > 0 ||
                                            (int) $category['children_count'] > 0;
                                        ?>

                                        <tr>

                                            <td>
                                                <?= (int) $category['category_id'] ?>
                                            </td>

                                            <td>
                                                <strong>
                                                    <?= category_h($category['name']) ?>
                                                </strong>

                                                <br>

                                                <small class="text-muted">
                                                    <?= category_h($category['slug']) ?>
                                                </small>
                                            </td>

                                            <td>
                                                <?= category_h($category['parent_name'] ?? '—') ?>
                                            </td>

                                            <td>
                                                <?= (int) $category['services_count'] ?>
                                            </td>

                                            <td>
                                                <?= (int) $category['sort_order'] ?>
                                            </td>

                                            <td>

                                                <div class="d-flex gap-2">

                                                    <a
                                                        href="admin_categories.php?edit=<?= (int) $category['category_id'] ?>"
                                                        class="btn btn-sm btn-outline-primary"
                                                    >
                                                        Edytuj
                                                    </a>

                                                    <?php if (!$hasDependencies): ?>

                                                        <form
                                                            method="post"
                                                            action="admin_categories.php"
                                                            onsubmit="return confirm('Czy na pewno chcesz usunąć tę kategorię?');"
                                                        >

                                                            <?= csrf_field() ?>

                                                            <input
                                                                type="hidden"
                                                                name="action"
                                                                value="delete"
                                                            >

                                                            <input
                                                                type="hidden"
                                                                name="category_id"
                                                                value="<?= (int) $category['category_id'] ?>"
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
                                                            title="Kategoria posiada usługi lub podkategorie."
                                                        >
                                                            Usuń
                                                        </button>

                                                    <?php endif; ?>

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

    </div>

</div>

</body>
</html>
