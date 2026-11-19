<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
if (($_SESSION['rol'] ?? '') !== 'admin') jsonError('Denetim izi yalnızca yöneticiye açıktır.', 403);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonError('Method Not Allowed', 405);

$db = getDb();

$where = [];
$params = [];

if (($t = trim(metin($_GET['tablo'] ?? ''))) !== '')      { $where[] = 'tablo = ?';  $params[] = $t; }
if (($e = trim(metin($_GET['eylem'] ?? ''))) !== '')      { $where[] = 'eylem = ?';  $params[] = $e; }
if (($k = (int)($_GET['kullanici_id'] ?? 0)) > 0)  { $where[] = 'kullanici_id = ?'; $params[] = $k; }
if (($b = dateStr('bas', $_GET)) !== null)         { $where[] = 'tarih >= ?'; $params[] = $b . ' 00:00:00'; }
if (($s = dateStr('bit', $_GET)) !== null)         { $where[] = 'tarih <= ?'; $params[] = $s . ' 23:59:59'; }

if (($ki = (int)($_GET['kayit_id'] ?? 0)) > 0)     { $where[] = 'kayit_id = ?'; $params[] = $ki; }

if (($q = trim(metin($_GET['q'] ?? ''))) !== '') {
    $where[] = '(' . trAramaSql('ozet') . ' LIKE ? OR ' . trAramaSql('kullanici_ad') . ' LIKE ?)';
    $params[] = '%' . trArama($q) . '%';
    $params[] = '%' . trArama($q) . '%';
}

$sql = 'SELECT id, tarih, kullanici_id, kullanici_ad, tablo, kayit_id, eylem, ozet, degisenler, ip
        FROM islem_log' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC';

$perPage = min(200, max(10, (int)($_GET['per_page'] ?? 50)));
$sayfa   = sayfaNo();
[$toplam, $sayfaSayisi] = sayfalamaBilgisi($db, $sql, $params, $perPage);

$st = $db->prepare($sql . ' LIMIT ? OFFSET ?');
$st->execute(array_merge($params, [$perPage, ($sayfa - 1) * $perPage]));
$rows = $st->fetchAll();

foreach ($rows as &$r) {
    $r['degisenler'] = $r['degisenler'] ? (json_decode($r['degisenler'], true) ?: null) : null;
    $d = $r['degisenler'];
    if (is_array($d) && isset($d['once'], $d['sonra']) && is_array($d['once']) && is_array($d['sonra'])) {
        $duzeltilmis = [];
        foreach (array_unique(array_merge(array_keys($d['once']), array_keys($d['sonra']))) as $alan) {
            $duzeltilmis[$alan] = [$d['once'][$alan] ?? null, $d['sonra'][$alan] ?? null];
        }
        foreach (['makine_tur', 'makine_id'] as $alan) {
            if (array_key_exists($alan, $d)) $duzeltilmis[$alan] = [null, $d[$alan]];
        }
        $r['degisenler'] = $duzeltilmis;
    }
}
unset($r);

$tablolar = $db->query("SELECT DISTINCT tablo FROM islem_log ORDER BY tablo")->fetchAll(PDO::FETCH_COLUMN);
$kisiler  = $db->query("SELECT DISTINCT kullanici_id, kullanici_ad FROM islem_log
                        WHERE kullanici_id IS NOT NULL ORDER BY kullanici_ad")->fetchAll();

jsonOk([
    'list'       => $rows,
    'tablolar'   => $tablolar,
    'kullanicilar' => $kisiler,
    'sayfalama'  => ['sayfa' => $sayfa, 'sayfa_sayisi' => $sayfaSayisi, 'toplam' => $toplam, 'per_page' => $perPage],
]);
