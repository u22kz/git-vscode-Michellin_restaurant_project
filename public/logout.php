<?php
/**
 * Plik: logout.php
 * Cel: Bezpieczne zakończenie sesji użytkownika (wylogowanie).
 */
require_once 'auth_check.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Użyj przycisku Wyloguj w aplikacji.');
}

csrf_require_valid();
destroy_user_session();

// Po wylogowaniu odsyłamy na stronę logowania
header('Location: login.php', true, 303);
exit;
