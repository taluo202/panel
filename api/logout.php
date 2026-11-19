<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method Not Allowed', 405);
verifyCsrf();

if (!empty($_SESSION['user_id'])) {
    guvenlikLog('cikis', (string)($_SESSION['username'] ?? '?'), (int)$_SESSION['user_id']);
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

jsonOk(null, 'Çıkış yapıldı.');
