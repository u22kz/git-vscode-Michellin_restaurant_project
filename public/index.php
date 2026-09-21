<?php
/**
 * Plik: index.php
 * Cel: Strona główna aplikacji - powitanie oraz linki do logowania,
 *      rejestracji lub (jeśli użytkownik jest już zalogowany) do jego panelu.
 */
require_once 'auth_check.php';

$logged_in = is_logged_in();
$role      = current_role();

// Komunikat o braku dostępu (przekierowanie z require_role w auth_check.php)
$access_denied = isset($_GET['error']) && $_GET['error'] === 'access_denied';
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Michelin Restaurant - Rezerwacje</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand" href="index.php">Michelin Restaurant</a>
        <div>
            <?php if ($logged_in): ?>
                <a class="btn btn-outline-light btn-sm me-2" href="<?= htmlspecialchars(dashboard_url_for_role($role), ENT_QUOTES, 'UTF-8') ?>">Mój panel</a>
                <a class="btn btn-outline-light btn-sm me-2" href="profile.php">Profil</a>
                <a class="btn btn-danger btn-sm" href="logout.php">Wyloguj</a>
            <?php else: ?>
                <a class="btn btn-outline-light btn-sm me-2" href="login.php">Zaloguj się</a>
                <a class="btn btn-light btn-sm" href="register.php">Zarejestruj się</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<div class="container py-5">

    <?php if ($access_denied): ?>
        <div class="alert alert-warning" role="alert">
            Nie masz uprawnień do wyświetlenia żądanej strony.
        </div>
    <?php endif; ?>

    <div class="p-5 mb-4 bg-white rounded-3 shadow-sm text-center">
        <h1 class="display-5 fw-bold">Witamy w Michelin Restaurant</h1>
        <p class="fs-5 text-muted">
            System rezerwacji usług naszej restauracji. Zarezerwuj stolik,
            menu degustacyjne lub inną z naszych usług w kilku krokach.
        </p>

        <?php if ($logged_in): ?>
            <p class="fs-5">
                Jesteś zalogowany jako
                <strong><?= htmlspecialchars(current_user_name(), ENT_QUOTES, 'UTF-8') ?></strong>
                (rola: <?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>).
            </p>
            <a href="<?= htmlspecialchars(dashboard_url_for_role($role), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary btn-lg">
                Przejdź do panelu
            </a>
        <?php else: ?>
            <a href="login.php" class="btn btn-primary btn-lg me-2">Zaloguj się</a>
            <a href="register.php" class="btn btn-outline-primary btn-lg">Załóż konto</a>
        <?php endif; ?>
    </div>

</div>

</body>
</html>
