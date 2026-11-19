<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonError('Method Not Allowed', 405);

if (!hasPermission('personel') && !hasPermission('mesai')) jsonError('Bu modül için yetkiniz yok.', 403);
require_once __DIR__ . '/personel-onay-lib.php';
$yetki = poYetki();

$db    = getDb();
$liste = poListe($db, $yetki, (int)($_GET['personel_id'] ?? 0));

$sayi = ['izin' => 0, 'mesai' => 0, 'masraf' => 0, 'belge' => 0];
foreach ($liste as $t) $sayi[$t['tur']]++;

jsonOk([
    'list'  => $liste,
    'sayi'  => $sayi + ['toplam' => count($liste)],
    'yetki' => $yetki,
]);
