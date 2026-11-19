<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
verifyCsrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method Not Allowed', 405);

$body  = govdeOku();
$id    = (int)($body['id'] ?? 0);
$tur   = $body['tur'] ?? '';
$arsiv = !empty($body['arsiv']) ? 1 : 0;

if ($id < 1 || !in_array($tur, ['personel', 'arac', 'manlift'], true)) jsonError('Geçersiz istek.');

$turModul = match($tur) { 'arac' => 'arac', 'manlift' => 'manlift', default => 'personel' };
requirePermission($turModul);

$tablo = match($tur) {
    'arac'    => 'arac_belgeler',
    'manlift' => 'manlift_belgeler',
    default   => 'personel_belgeler',
};

$db = getDb();
$st = $db->prepare("UPDATE $tablo SET arsiv=? WHERE id=?");
$st->execute([$arsiv, $id]);

logla($db, $tablo, $id, 'guncelle', null, null,
      $arsiv ? 'Belge arşive taşındı' : 'Belge arşivden çıkarıldı');

jsonOk(null, $arsiv ? 'Belge arşive taşındı.' : 'Belge arşivden çıkarıldı.');
