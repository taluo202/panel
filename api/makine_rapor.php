<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('makine');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonError('Method Not Allowed', 405);

$db = getDb();
$ay = trim(metin($_GET['ay'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $ay)) $ay = date('Y-m');
$ayBas   = $ay . '-01';
$ayBit   = date('Y-m-t', strtotime($ayBas));
$ayGun   = (int)date('t', strtotime($ayBas));
$unit    = KIRA_BIRIM_GUN;

$tipFiltre    = trim(metin($_GET['tip'] ?? ''));
$markaFiltre  = trim(metin($_GET['marka'] ?? ''));
$metrajMin    = ($_GET['metraj_min'] ?? '') !== '' ? (float)$_GET['metraj_min'] : null;
$metrajMax    = ($_GET['metraj_max'] ?? '') !== '' ? (float)$_GET['metraj_max'] : null;

$makinelerTum = makineListesi($db);
$makineler = array_values(array_filter($makinelerTum, function ($m) use ($tipFiltre, $markaFiltre, $metrajMin, $metrajMax) {
    if ($tipFiltre !== '' && $m['tur'] !== $tipFiltre) return false;
    if ($markaFiltre !== '' && trim((string)($m['marka'] ?? '')) !== $markaFiltre) return false;
    if ($metrajMin !== null && ($m['metraj'] === null || $m['metraj'] < $metrajMin)) return false;
    if ($metrajMax !== null && ($m['metraj'] === null || $m['metraj'] > $metrajMax)) return false;
    return true;
}));
$mp = [];
foreach ($makineler as $m) {
    $key = $m['tur'] . ':' . $m['id'];
    $amort = aylikAmortisman((float)$m['alis_bedeli'], (int)$m['faydali_omur_yil']);
    $mp[$key] = [
        'tur' => $m['tur'], 'id' => $m['id'], 'ad' => $m['ad'], 'kategori' => $m['kategori'],
        'sube' => $m['sube'], 'marka' => $m['marka'], 'metraj' => $m['metraj'], 'alis_bedeli' => (float)$m['alis_bedeli'],
        'doluluk_gun' => 0, 'gelir' => 0.0, 'gider' => 0.0, 'amortisman' => $amort,
    ];
}

$markalarTum = array_values(array_unique(array_filter(array_map(fn($m) => trim((string)($m['marka'] ?? '')), $makinelerTum))));
sort($markalarTum, SORT_STRING | SORT_FLAG_CASE);

$ks = $db->query("SELECT makine_tur, makine_id, baslangic, bitis, gercek_bitis, kira_tipi, birim_ucret, miktar, durum, iskonto_oran, onay_durumu FROM kiralamalar WHERE makine_tur IS NOT NULL AND makine_id IS NOT NULL AND durum<>'iptal'")->fetchAll();
$mpSablon = $mp;

$mrHesapla = function (string $ay) use ($db, $ks, $mpSablon, $unit): array {
    $ayBas = $ay . '-01';
    $ayBit = date('Y-m-t', strtotime($ayBas));
    $ayGun = (int)date('t', strtotime($ayBas));
    $mp = $mpSablon;

    foreach ($ks as $k) {
        $key = $k['makine_tur'] . ':' . $k['makine_id'];
        if (!isset($mp[$key])) continue;
        $kesisim = kiralamaDonemKesisimi($k, $ayBas, $ayBit);
        if ($kesisim === null) continue;
        $gun = $kesisim['gun'];
        $mp[$key]['doluluk_gun'] += $gun;
        $tip  = $k['kira_tipi'] ?: 'gunluk';
        $sure = $gun / ($unit[$tip] ?? 1);
        $gelir = (float)$k['birim_ucret'] * max(1,(int)$k['miktar']) * $sure;
        $iskontoOran = kiralamaGecerliIskonto($k);
        $mp[$key]['gelir'] += $gelir - round($gelir * $iskontoOran / 100, 2);
    }

    $gs = $db->prepare("SELECT makine_tur, makine_id, COALESCE(SUM(tutar),0) t FROM giderler WHERE substr(tarih,1,7)=? GROUP BY makine_tur, makine_id");
    $gs->execute([$ay]);
    foreach ($gs->fetchAll() as $g) {
        $key = $g['makine_tur'] . ':' . $g['makine_id'];
        if (isset($mp[$key])) $mp[$key]['gider'] += (float)$g['t'];
    }

    $giderKategori = ['yakit'=>0.0,'bakim'=>0.0,'sigorta'=>0.0,'lastik'=>0.0,'onarim'=>0.0,'diger'=>0.0];
    $gk = $db->prepare("SELECT kategori, COALESCE(SUM(tutar),0) t FROM giderler WHERE substr(tarih,1,7)=? GROUP BY kategori");
    $gk->execute([$ay]);
    foreach ($gk->fetchAll() as $g) {
        $kat = $g['kategori'] ?: 'diger';
        if (!isset($giderKategori[$kat])) $kat = 'diger';
        $giderKategori[$kat] += (float)$g['t'];
    }
    foreach ($giderKategori as $k => $v) $giderKategori[$k] = round($v, 2);

    $servisMaliyet = makineServisMaliyetleri($db, $ayBas, $ayBit);

    $list = []; $top = ['makine'=>0,'gelir'=>0.0,'gider'=>0.0,'servis_maliyet'=>0.0,'amortisman'=>0.0,'net'=>0.0,'doluluk_gun'=>0,'aktif_makine'=>0];
    foreach ($mp as $r) {
        $r['doluluk_gun']   = min($ayGun, $r['doluluk_gun']);
        $r['doluluk_yuzde'] = $ayGun > 0 ? round($r['doluluk_gun'] / $ayGun * 100) : 0;
        $r['gelir']         = round($r['gelir'], 2);
        $r['servis_maliyet'] = $servisMaliyet[$r['tur'] . ':' . $r['id']] ?? 0.0;
        $r['toplam_gider']  = round($r['gider'] + $r['servis_maliyet'] + $r['amortisman'], 2);
        $r['net']           = round($r['gelir'] - $r['toplam_gider'], 2);
        $r['roi']           = $r['alis_bedeli'] > 0 ? round($r['net'] / $r['alis_bedeli'] * 100, 1) : null;
        $list[] = $r;
        $top['makine']++;
        if ($r['doluluk_gun'] > 0) $top['aktif_makine']++;
        $top['gelir'] += $r['gelir']; $top['gider'] += $r['gider']; $top['servis_maliyet'] += $r['servis_maliyet'];
        $top['amortisman'] += $r['amortisman']; $top['net'] += $r['net'];
        $top['doluluk_gun'] += $r['doluluk_gun'];
    }
    usort($list, fn($a,$b) => $b['net'] <=> $a['net']);
    foreach (['gelir','gider','servis_maliyet','amortisman','net'] as $k) $top[$k] = round($top[$k], 2);
    $top['ortalama_doluluk'] = ($top['makine'] > 0 && $ayGun > 0) ? round($top['doluluk_gun'] / ($top['makine'] * $ayGun) * 100) : 0;
    return ['ay' => $ay, 'ay_gun' => $ayGun, 'list' => $list, 'toplam' => $top, 'gider_kategori' => $giderKategori];
};

$simdi = $mrHesapla($ay);

$onceki = null;
if (($_GET['kiyas'] ?? '1') !== '0') {
    $oncekiAy = date('Y-m', strtotime($ay . '-01 -1 month'));
    $o = $mrHesapla($oncekiAy);
    $onceki = ['ay' => $oncekiAy, 'ay_gun' => $o['ay_gun'], 'toplam' => $o['toplam'], 'gider_kategori' => $o['gider_kategori']];
}

jsonOk([
    'ay' => $ay, 'ay_gun' => $simdi['ay_gun'], 'list' => $simdi['list'], 'toplam' => $simdi['toplam'], 'gider_kategori' => $simdi['gider_kategori'],
    'filtre' => ['tip' => $tipFiltre, 'marka' => $markaFiltre, 'metraj_min' => $metrajMin, 'metraj_max' => $metrajMax],
    'marka_listesi' => $markalarTum,
    'onceki' => $onceki,
]);
