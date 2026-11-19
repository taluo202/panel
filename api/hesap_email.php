<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
verifyCsrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method Not Allowed', 405);

$body  = govdeOku();
$email = trim((string)($body['email'] ?? ''));

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonError('Geçerli bir e-posta adresi girin.');
}

$db = getDb();
$sifre = (string)($body['sifre'] ?? '');
if ($sifre === '') jsonError('E-posta adresini değiştirmek için şifrenizi girin.');
if (!yenidenDogrula($db, (int)$_SESSION['user_id'], $sifre)) jsonError('Şifre hatalı.', 403);

$eskiSt = $db->prepare("SELECT email FROM users WHERE id=?");
$eskiSt->execute([$_SESSION['user_id']]);
$eskiEmail = trim((string)($eskiSt->fetchColumn() ?: ''));
$db->prepare("UPDATE users SET email=? WHERE id=?")->execute([$email ?: null, $_SESSION['user_id']]);

if ($eskiEmail !== '' && strcasecmp($eskiEmail, $email) !== 0) {
    try {
        require_once __DIR__ . '/eposta.php';
        epostaGonder($db, $eskiEmail, 'Hesap e-posta adresiniz değiştirildi',
            'Merhaba ' . (string)($_SESSION['ad_soyad'] ?? $_SESSION['username'] ?? '') . ",\n\n"
            . 'Taloo hesabınızın (' . (string)($_SESSION['username'] ?? '') . ') e-posta adresi '
            . date('d.m.Y H:i') . ' tarihinde ' . ($email !== '' ? $email : '(boş)') . " olarak değiştirildi.\n"
            . "Bu değişikliği siz yapmadıysanız hemen yöneticinize haber verin.", 'eposta_degisti');
    } catch (\Throwable $e) {
        error_log('Taloo: e-posta degisikligi bildirimi gonderilemedi: ' . $e->getMessage());
    }
}

guvenlikLog('eposta_degisti', (string)($_SESSION['username'] ?? '?'), (int)$_SESSION['user_id'],
            ['yeni' => $email ?: '(kaldırıldı)']);

jsonOk(['email' => $email], $email ? 'E-posta kaydedildi.' : 'E-posta kaldırıldı.');
