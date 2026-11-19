<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/kod-kapisi-lib.php';

requireAuth();
if (($_SESSION['rol'] ?? '') !== 'admin') jsonError('Kopya izini yalnız yönetici sorgulayabilir.', 403);

$iz = strtolower(trim((string)($_GET['iz'] ?? '')));
if (!preg_match('/^tz[a-z0-9]{14}$/', $iz)) jsonError('İz "tz" ile başlayan 16 karakterdir (ör. tz1a2b3c4d5e6f70).', 400);

$db = getDb();
kkSema($db);
$r = kkIzCoz($db, $iz, time());
if ($r === null) jsonError('Bu iz bu kurulumda bulunamadı (son 400 gün tarandı).', 404);
logla($db, 'guvenlik', null, 'sorgu', null, ['iz' => $iz], 'Kopya izi sorgulandı: ' . $iz);
jsonOk($r);
