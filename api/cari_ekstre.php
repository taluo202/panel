<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('kiralama_takip');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonError('Method Not Allowed', 405);

$db  = getDb();
$mid = (int)($_GET['musteri_id'] ?? 0);
if ($mid < 1) jsonError('Müşteri ID gerekli.');
subeKayitDogrula($db, 'musteriler', $mid);

$mStmt = $db->prepare("SELECT * FROM musteriler WHERE id=?");
$mStmt->execute([$mid]);
$musteri = $mStmt->fetch();
if (!$musteri) jsonError('Müşteri bulunamadı.', 404);

$bas = dateStr('baslangic', $_GET);
$bit = dateStr('bitis', $_GET);

$kalem = cariBorcKalemleri($db, $mid, $bit)[$mid] ?? ['borclar' => [], 'faturalanmamis' => []];

$hepsi = [];
foreach ($kalem['borclar'] as $b) {
    $hepsi[] = [
        'tarih'    => $b['tarih'],
        'sira'     => 0,
        'tip'      => 'borc',
        'aciklama' => $b['aciklama'],
        'borc'     => (float)$b['tutar'],
        'odeme'    => 0.0,
    ];
}

require_once __DIR__.'/cek-cari-kurallari.php';$cekCari=crVeri($db,$bit,$mid);
foreach($cekCari['hareketler'] as $movement)$hepsi[]=$movement;
$ts = $db->prepare("SELECT * FROM tahsilatlar WHERE musteri_id=? ORDER BY tarih, id");
$ts->execute([$mid]);
$yontemEt = ['nakit'=>'Nakit', 'havale'=>'Havale/EFT', 'cek'=>'Çek', 'kredi_karti'=>'Kredi Kartı'];
foreach ($ts->fetchAll() as $t) {
    if(in_array((int)$t['id'],$cekCari['bagli_tahsilatlar'],true))continue;
    $yon = $yontemEt[$t['yontem']] ?? ($t['yontem'] ?: 'Tahsilat');
    $hepsi[] = [
        'tarih'    => $t['tarih'],
        'sira'     => 1,
        'tip'      => 'odeme',
        'aciklama' => 'Tahsilat — ' . $yon . ($t['aciklama'] ? ' (' . $t['aciklama'] . ')' : ''),
        'borc'     => 0.0,
        'odeme'    => (float)$t['tutar'],
    ];
}

usort($hepsi, function ($a, $b) {
    if ($a['tarih'] === $b['tarih']) return $a['sira'] <=> $b['sira'];
    return strcmp((string)$a['tarih'], (string)$b['tarih']);
});

$devir = 0.0;
$hareketler = [];
$toplamBorc = 0.0; $toplamOdeme = 0.0;
foreach ($hepsi as $h) {
    if ($bas && $h['tarih'] < $bas) { $devir += $h['borc'] - $h['odeme']; continue; }
    if ($bit && $h['tarih'] > $bit) continue;
    $hareketler[] = $h;
}

$bakiye = $devir;
$rows = [];
foreach ($hareketler as $h) {
    $bakiye += $h['borc'] - $h['odeme'];
    $toplamBorc += $h['borc'];
    $toplamOdeme += $h['odeme'];
    $rows[] = [
        'tarih'    => $h['tarih'],
        'tip'      => $h['tip'],
        'aciklama' => $h['aciklama'],
        'borc'     => round($h['borc'], 2),
        'odeme'    => round($h['odeme'], 2),
        'bakiye'   => round($bakiye, 2),
    ];
}

$faturalanmamis = $kalem['faturalanmamis'];

jsonOk(['cek_risk'=>$cekCari['risk'][$mid]??['bekliyor'=>0,'avukatlik'=>0,'batik'=>0],'cek_uyarilari'=>$cekCari['uyarilar'],
    'musteri' => [
        'id' => (int)$musteri['id'], 'unvan' => $musteri['unvan'], 'yetkili' => $musteri['yetkili'],
        'telefon' => $musteri['telefon'], 'email' => $musteri['email'], 'vergi_no' => $musteri['vergi_no'],
        'vergi_dairesi' => $musteri['vergi_dairesi'], 'adres' => $musteri['adres'], 'sube' => $musteri['sube'],
    ],
    'baslangic'   => $bas,
    'bitis'       => $bit,
    'devir'       => round($devir, 2),
    'hareketler'  => $rows,
    'toplam_borc' => round($toplamBorc, 2),
    'toplam_odeme'=> round($toplamOdeme, 2),
    'bakiye'      => round($bakiye, 2),
    'faturalanmamis'        => $faturalanmamis,
    'faturalanmamis_toplam' => round(array_sum(array_column($faturalanmamis, 'tutar')), 2),
]);
