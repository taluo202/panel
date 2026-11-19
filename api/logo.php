<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
verifyCsrf();
if (($_SESSION['rol'] ?? '') !== 'admin') jsonError('Sadece yönetici logo değiştirebilir.', 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method Not Allowed', 405);

$f = $_FILES['logo'] ?? null;
if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) jsonError('Logo dosyası gerekli.');
if ($f['error'] !== UPLOAD_ERR_OK) jsonError('Yükleme hatası: ' . $f['error']);
if ($f['size'] > 5 * 1024 * 1024) jsonError('Logo en fazla 5 MB olabilir.');

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
$izin = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
if (!isset($izin[$mime])) jsonError('Sadece PNG, JPG, WEBP veya SVG.');
$ext = $izin[$mime];

$dir = UPLOAD_PATH . 'firma/';
if (!is_dir($dir) && !mkdir($dir, 0775, true)) jsonError('Klasör oluşturulamadı.', 500);

foreach (glob($dir . 'logo.*') ?: [] as $old) @unlink($old);

$rel  = 'firma/logo.' . $ext;
$dest = UPLOAD_PATH . $rel;
if (!move_uploaded_file($f['tmp_name'], $dest)) jsonError('Logo kaydedilemedi.', 500);
@chmod($dest, 0644);

$db = getDb();
$db->prepare("INSERT OR REPLACE INTO ayarlar (anahtar, deger) VALUES ('firma_logo', ?)")->execute([$rel]);

logla($db, 'ayarlar', null, 'guncelle', null, null, 'Firma logosu değiştirildi (' . $ext . ')');

jsonOk(['logo' => 'uploads/' . TENANT_SEG . $rel], 'Logo güncellendi.');
