<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
verifyCsrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method Not Allowed', 405);

$db   = getDb();
$body = govdeOku();

if (($_GET['action'] ?? '') === 'tum_cihazlar') {
    $sifre = (string)($body['sifre'] ?? '');
    if (!yenidenDogrula($db, (int)$_SESSION['user_id'], $sifre)) jsonError('Şifre hatalı.', 403);

    try {
        sifreDamgaGuncelle($db, (int)$_SESSION['user_id']);
    } catch (\Throwable $e) {
        hataGunlugu('parola.tum_cihazlar', $e);
        jsonError('Diğer cihazlardaki oturumlar kapatılamadı. Biraz sonra tekrar deneyin.', 500);
    }
    $yeni =$db->prepare("SELECT sifre_degisti FROM users WHERE id = ?");
    $yeni->execute([$_SESSION['user_id']]);
    $_SESSION['sifre_damga'] = (string)($yeni->fetchColumn() ?: '');

    guvenlikLog('tum_cihazlar_cikis', (string)($_SESSION['username'] ?? '?'), (int)$_SESSION['user_id']);
    jsonOk(null, 'Diğer bütün cihazlardaki oturumlar kapatıldı.');
}
$mevcutPw  = (string)($body['mevcut_sifre'] ?? '');
$yeniPw    = (string)($body['yeni_sifre'] ?? '');
$yeniPw2   = (string)($body['yeni_sifre2'] ?? '');

if ($mevcutPw === '' || $yeniPw === '') jsonError('Tüm alanlar zorunludur.');
if ($yeniPw !== $yeniPw2) jsonError('Yeni şifreler eşleşmiyor.');
parolaDogrula($yeniPw, (string)($_SESSION['username'] ?? ''));
if (kurulumSifresiMi(['rol' => (string)($_SESSION['rol'] ?? '')], $yeniPw)) {
    jsonError('Yeni şifre kurulum şifresiyle aynı olamaz.');
}

if (!yenidenDogrula($db, (int)$_SESSION['user_id'], $mevcutPw)) {
    jsonError('Mevcut şifre hatalı.', 403);
}

$hash = password_hash($yeniPw, PASSWORD_BCRYPT, ['cost' => 12]);
if (!parolaYazVeDamgala($db, (int)$_SESSION['user_id'], $hash)) {
    jsonError('Şifre değiştirilemedi, eski şifreniz geçerli. Biraz sonra tekrar deneyin.', 500);
}
$yeniDamga = $db->prepare("SELECT sifre_degisti FROM users WHERE id = ?");
$yeniDamga->execute([$_SESSION['user_id']]);
$_SESSION['sifre_damga'] = (string)($yeniDamga->fetchColumn() ?: '');
unset($_SESSION['sifre_degistir_zorunlu']);
guvenlikLog('sifre_degisti', (string)($_SESSION['username'] ?? '?'), (int)$_SESSION['user_id']);

jsonOk(null, 'Şifre güncellendi. Diğer cihazlardaki oturumlar kapatıldı.');
