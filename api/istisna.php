<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();

$db     = getDb();
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') jsonError('Desteklenmeyen metod.', 405);

require_once __DIR__ . '/zincir-lib.php';
istisnaTaraKilitli($db);

$st = $db->query("
    SELECT g.* FROM gorevler g
    WHERE g.durum='acik' AND g.kaynak_modul IS NOT NULL AND g.kaynak_modul<>''
    ORDER BY (g.oncelik='yuksek') DESC, (g.son_tarih IS NULL), g.son_tarih ASC, g.id DESC
");
$rows = array_values(array_filter($st->fetchAll(), fn($r) => hdGorevGorunur($db, $r)));
$rows = array_map(fn($g) => gorevKaynakBilgisi(hdGorevMaskele($db, $g)), $rows);
foreach ($rows as &$r) $r['kaynak_kilitli'] = hdKilitliGorev($db, $r);
unset($r);

$ozet = [];
foreach ($rows as $r) {
    $ozet[$r['kaynak_modul']] = ($ozet[$r['kaynak_modul']] ?? 0) + 1;
}

jsonOk(['list' => $rows, 'ozet' => $ozet]);
