<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('yonetim');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonError('Method Not Allowed', 405);

$db    = getDb();
$today = new DateTime(date('Y-m-d'));

$subeFiltre = trim(metin($_GET['sube'] ?? ''));
$subeFiltre = subeKisiti() ?? $subeFiltre;

$kalemler = cariBorcKalemleri($db);
$borclar  = [];
$faturasiz = [];
foreach ($kalemler as $mid => $x) {
    foreach ($x['borclar'] as $b) {
        if ((float)$b['tutar'] <= 0) continue;
        $borclar[$mid][] = ['tarih' => $b['tarih'] ?: date('Y-m-d'), 'tutar' => (float)$b['tutar']];
    }
    $tp = array_sum(array_column($x['faturalanmamis'], 'tutar'));
    if ($tp > 0) $faturasiz[$mid] = round($tp, 2);
}

$tahsil = [];
foreach ($db->query("SELECT musteri_id, COALESCE(SUM(tutar),0) t FROM tahsilatlar GROUP BY musteri_id")->fetchAll() as $r) {
    $tahsil[(int)$r['musteri_id']] = (float)$r['t'];
}

$unvan = [];
foreach ($db->query("SELECT id, unvan, telefon, sube FROM musteriler")->fetchAll() as $r) {
    $unvan[(int)$r['id']] = ['unvan' => $r['unvan'], 'telefon' => $r['telefon'], 'sube' => $r['sube']];
}
if ($subeFiltre !== '') {
    foreach (array_keys($borclar) as $mid) {
        if ((string)($unvan[$mid]['sube'] ?? '') !== $subeFiltre) unset($borclar[$mid]);
    }
}

$bucketAdlari = ['guncel', 'b30', 'b60', 'b90', 'b90p'];
$genel = array_fill_keys($bucketAdlari, 0.0);
$musteriler = [];

foreach ($borclar as $mid => $liste) {
    $kalanOdeme = $tahsil[$mid] ?? 0.0;
    $b = array_fill_keys($bucketAdlari, 0.0);
    $toplamAcik = 0.0;
    foreach ($liste as $borc) {
        $kalan = $borc['tutar'];
        if ($kalanOdeme > 0) {
            $dus = min($kalanOdeme, $kalan);
            $kalan -= $dus; $kalanOdeme -= $dus;
        }
        if ($kalan <= 0.009) continue;
        try { $bt = new DateTime($borc['tarih']); $yas = $bt > $today ? 0 : (int)$today->diff($bt)->days; } catch (\Exception $e) { $yas = 0; }
        if ($yas <= 0)       $k = 'guncel';
        elseif ($yas <= 30)  $k = 'b30';
        elseif ($yas <= 60)  $k = 'b60';
        elseif ($yas <= 90)  $k = 'b90';
        else                 $k = 'b90p';
        $b[$k] += $kalan; $toplamAcik += $kalan;
    }
    if ($toplamAcik <= 0.009) continue;
    foreach ($bucketAdlari as $bk) $genel[$bk] += $b[$bk];
    $musteriler[] = [
        'id' => $mid, 'unvan' => $unvan[$mid]['unvan'] ?? ('#' . $mid), 'telefon' => $unvan[$mid]['telefon'] ?? '',
        'toplam' => round($toplamAcik, 2),
        'guncel' => round($b['guncel'], 2), 'b30' => round($b['b30'], 2),
        'b60' => round($b['b60'], 2), 'b90' => round($b['b90'], 2), 'b90p' => round($b['b90p'], 2),
        'faturalanmamis' => $faturasiz[$mid] ?? 0.0,
    ];
}

usort($musteriler, fn($a, $b) => $b['toplam'] <=> $a['toplam']);
foreach ($bucketAdlari as $bk) $genel[$bk] = round($genel[$bk], 2);
$genelToplam = round(array_sum($genel), 2);

jsonOk([
    'genel' => $genel,
    'genel_toplam' => $genelToplam,
    'musteriler' => $musteriler,
    'faturalanmamis_toplam' => round(array_sum($faturasiz), 2),
]);
