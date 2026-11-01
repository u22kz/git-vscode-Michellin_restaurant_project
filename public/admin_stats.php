
<?php
require_once 'auth_check.php';
require_once 'db_connect.php';
require_role(['admin']);

$escape = static function ($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

// Podstawowe statystyki
$totalServices = (int)$pdo->query("
    SELECT COUNT(*) FROM services
")->fetchColumn();

$availableServices = (int)$pdo->query("
    SELECT COUNT(*) FROM services
    WHERE is_available = 1
")->fetchColumn();

$totalEmployees = (int)$pdo->query("
    SELECT COUNT(*) FROM employees
")->fetchColumn();

$activeEmployees = (int)$pdo->query("
    SELECT COUNT(*) FROM employees
    WHERE is_active = 1
")->fetchColumn();

$totalReservations = (int)$pdo->query("
    SELECT COUNT(*) FROM reservations
")->fetchColumn();

$upcomingReservations = (int)$pdo->query("
    SELECT COUNT(*) FROM reservations
    WHERE reservation_date >= CURDATE()
")->fetchColumn();

$todayReservations = (int)$pdo->query("
    SELECT COUNT(*) FROM reservations
    WHERE reservation_date = CURDATE()
")->fetchColumn();

$totalUsers = (int)$pdo->query("
    SELECT COUNT(*) FROM users
")->fetchColumn();

$activeUsers = (int)$pdo->query("
    SELECT COUNT(*) FROM users
    WHERE is_active = 1
")->fetchColumn();
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statystyki - Michelin Restaurant</title>

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

<main class="container pb-5">

    <h1 class="mb-4">Statystyki systemu</h1>

    <div class="row g-3">

        <div class="col-sm-6 col-lg-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <p class="text-muted mb-1">Wszystkie rezerwacje</p>
                    <h2 class="mb-0"><?= $totalReservations ?></h2>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <p class="text-muted mb-1">Rezerwacje na dziś</p>
                    <h2 class="mb-0"><?= $todayReservations ?></h2>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <p class="text-muted mb-1">Nadchodzące rezerwacje</p>
                    <h2 class="mb-0"><?= $upcomingReservations ?></h2>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <p class="text-muted mb-1">Wszyscy użytkownicy</p>
                    <h2 class="mb-0"><?= $totalUsers ?></h2>
                    <small class="text-muted">
                        Aktywni: <?= $activeUsers ?>
                    </small>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <p class="text-muted mb-1">Wszyscy pracownicy</p>
                    <h2 class="mb-0"><?= $totalEmployees ?></h2>
                    <small class="text-muted">
                        Aktywni: <?= $activeEmployees ?>
                    </small>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <p class="text-muted mb-1">Wszystkie usługi</p>
                    <h2 class="mb-0"><?= $totalServices ?></h2>
                    <small class="text-muted">
                        Dostępne: <?= $availableServices ?>
                    </small>
                </div>
            </div>
        </div>

    </div>

    <div class="alert alert-info mt-4 mb-0">
        Statystyki są pobierane bezpośrednio z bazy danych.
        Po dodaniu lub zmianie danych ich wartości zaktualizują się
        po ponownym otwarciu tej strony.
    </div>

</main>

</body>
</html>
