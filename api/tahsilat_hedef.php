<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('yonetim');

$db     = getDb();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $ay = trim(metin($_GET['ay'] ?? date('Y-m')));
    if (!preg_match('/^\d{4}-\d{2}$/', $ay)) $ay = date('Y-m');

    $hStmt = $db->prepare("SELECT sube, hedef FROM tahsilat_hedefleri WHERE ay=?");
    $hStmt->execute([$ay]);
    $hedefMap = [];
    foreach ($hStmt->fetchAll() as $h) $hedefMap[$h['sube']] = (float)$h['hedef'];

    $subeler = [];
    foreach ($db->query("SELECT ad FROM subeler WHERE aktif=1 ORDER BY ad")->fetchAll() as $s) $subeler[] = $s['ad'];

    $tStmt = $db->prepare("
        SELECT t.tutar, t.yontem, COALESCE(m.sube,'') AS sube
        FROM tahsilatlar t
        LEFT JOIN musteriler m ON m.id = t.musteri_id
        WHERE substr(t.tarih,1,7) = ?
    ");
    $tStmt->execute([$ay]);
    $genelGerc = 0.0;
    $subeGerc  = [];
    $yontem    = [];
    foreach ($tStmt->fetchAll() as $t) {
        $tut = (float)$t['tutar'];
        $genelGerc += $tut;
        $sb = $t['sube'] ?: '— Belirtilmemiş —';
        $subeGerc[$sb] = ($subeGerc[$sb] ?? 0) + $tut;
        $yn = $t['yontem'] ?: 'belirtilmemiş';
        $yontem[$yn] = ($yontem[$yn] ?? 0) + $tut;
    }

    $tumSube = array_unique(array_merge($subeler, array_keys($hedefMap), array_keys($subeGerc)));
    $subeList = [];
    foreach ($tumSube as $sb) {
        if ($sb === '') continue;
        $hed = $hedefMap[$sb] ?? 0;
        $ger = $subeGerc[$sb] ?? 0;
        $subeList[] = [
            'sube'   => $sb,
            'hedef'  => round($hed, 2),
            'gerceklesme' => round($ger, 2),
            'oran'   => $hed > 0 ? round($ger / $hed * 100) : null,
        ];
    }
    usort($subeList, fn($a, $b) => $b['gerceklesme'] <=> $a['gerceklesme']);

    $genelHedef = $hedefMap[''] ?? 0;
    $yontemList = [];
    foreach ($yontem as $y => $v) $yontemList[] = ['yontem' => $y, 'tutar' => round($v, 2)];
    usort($yontemList, fn($a, $b) => $b['tutar'] <=> $a['tutar']);

    jsonOk([
        'ay'           => $ay,
        'genel_hedef'  => round($genelHedef, 2),
        'genel_gerc'   => round($genelGerc, 2),
        'genel_oran'   => $genelHedef > 0 ? round($genelGerc / $genelHedef * 100) : null,
        'subeler'      => $subeList,
        'yontem'       => $yontemList,
        'sube_listesi' => $subeler,
    ]);
}

elseif ($method === 'POST') {
    verifyCsrf();
    $body  = govdeOku();
    $ay    = trim(metin($body['ay'] ?? ''));
    $sube  = trim(metin($body['sube'] ?? ''));
    $hedef = max(0, (float)($body['hedef'] ?? 0));
    if (!preg_match('/^\d{4}-\d{2}$/', $ay)) jsonError('Geçersiz ay.');

    $st = $db->prepare("
        INSERT INTO tahsilat_hedefleri (ay, sube, hedef) VALUES (?,?,?)
        ON CONFLICT(ay, sube) DO UPDATE SET hedef=excluded.hedef, updated_at=datetime('now','localtime')
    ");
    $st->execute([$ay, $sube, $hedef]);
    logla($db, 'tahsilat_hedef', null, 'guncelle', null, null, 'Tahsilat hedefi kaydedildi');
    jsonOk(null, 'Hedef kaydedildi.');
}

else {
    jsonError('Method Not Allowed', 405);
}
