<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/operasyon-adim.php';
require_once __DIR__ . '/referans-lib.php';
requireAuth();
$db = getDb();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') jsonError('Method Not Allowed', 405);
$where = ['id = ?'];
$params = [(int)($_GET['kiralama_id'] ?? 0)];
subeKosulEkle($where, $params, 'sube');
$k = $db->prepare('SELECT makine_tur, makine_id, operatorlu, operator_id FROM kiralamalar WHERE ' . implode(' AND ', $where));
$k->execute($params);
$r = $k->fetch(PDO::FETCH_ASSOC);
if (!$r || !$r['makine_id']) jsonError('Kiralama bulunamadı.', 404);
if (!hasPermission('kiralama_takip', 'oku') && !refErisimVar($db, $r)) jsonError('Bu işlem için yetkiniz yok.', 403);
jsonOk(refBilgi($db, (string)$r['makine_tur'], (int)$r['makine_id']));
