<?php
/**
 * Plik: logout.php
 * Cel: Bezpieczne zakończenie sesji użytkownika (wylogowanie).
 */
require_once 'auth_check.php';

// Czyścimy wszystkie dane zapisane w sesji
$_SESSION = [];

// Usuwamy ciasteczko sesyjne po stronie przeglądarki (jeśli istnieje)
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Niszczymy sesję po stronie serwera
session_destroy();

// Po wylogowaniu odsyłamy na stronę logowania
header('Location: login.php');
exit;
