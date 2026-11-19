<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('yonetim');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonError('Method Not Allowed', 405);

if (!hasPermission('kiralama_takip') || !hasPermission('arac') || !hasPermission('manlift')) {
    jsonError('Yönetim paneli için ayrıca kiralama takip, araç ve makine yetkileri de gerekir.', 403);
}

$db    = getDb();
$today = date('Y-m-d');
$yil   = date('Y');

$bas = dateStr('bas', $_GET) ?? $yil . '-01-01';
$bit = dateStr('bit', $_GET) ?? $today;
if ($bas > $bit) { $t = $bas; $bas = $bit; $bit = $t; }
$subeFiltre = trim(metin($_GET['sube'] ?? ''));
$subeFiltre = subeKisiti() ?? $subeFiltre;
$gunSayisi  = (int)((new DateTime($bas))->diff(new DateTime($bit))->days) + 1;

$tipFiltre   = trim(metin($_GET['tip'] ?? ''));
$markaFiltre = trim(metin($_GET['marka'] ?? ''));
$metrajMin   = ($_GET['metraj_min'] ?? '') !== '' ? (float)$_GET['metraj_min'] : null;
$metrajMax   = ($_GET['metraj_max'] ?? '') !== '' ? (float)$_GET['metraj_max'] : null;

$musteriSube = [];
foreach ($db->query("SELECT id, sube FROM musteriler")->fetchAll() as $r) $musteriSube[(int)$r['id']] = $r['sube'];
$makineler = makineListesi($db);

function _donemOzet(PDO $db, array $kiralamalar, array $musteriSube, array $makineler, string $bas, string $bit, string $subeFiltre): array {
    $ciro = 0.0;
    foreach ($kiralamalar as $k) {
        $sb = trim((string)($k['sube'] ?? '')) ?: trim((string)($musteriSube[(int)$k['musteri_id']] ?? '')) ?: '— Belirtilmemiş —';
        if ($subeFiltre !== '' && $sb !== $subeFiltre) continue;
        $gtPencereli = kiralamaTutarPencereli($k, $bas, $bit);
        if ($gtPencereli === null) continue;
        $ciro += $gtPencereli;
    }

    $tsStmt = $db->prepare("SELECT tutar, musteri_id FROM tahsilatlar WHERE tarih >= ? AND tarih <= ?");
    $tsStmt->execute([$bas, $bit]);
    $tahsil = 0.0;
    foreach ($tsStmt->fetchAll() as $r) {
        if ($subeFiltre !== '') {
            $sb = trim((string)($musteriSube[(int)$r['musteri_id']] ?? '')) ?: '— Belirtilmemiş —';
            if ($sb !== $subeFiltre) continue;
        }
        $tahsil += (float)$r['tutar'];
    }

    $gunSayisi = max(1, (int)((new DateTime($bas))->diff(new DateTime($bit))->days) + 1);
    $dGun = [];
    foreach ($makineler as $m) {
        if ($subeFiltre !== '' && trim((string)($m['sube'] ?? '')) !== $subeFiltre) continue;
        $dGun[$m['tur'] . ':' . $m['id']] = 0;
    }
    foreach ($kiralamalar as $k) {
        if (!$k['makine_tur'] || !$k['makine_id']) continue;
        $key = $k['makine_tur'] . ':' . $k['makine_id'];
        if (!array_key_exists($key, $dGun)) continue;
        $kesisim = kiralamaDonemKesisimi($k, $bas, $bit);
        if ($kesisim === null) continue;
        $dGun[$key] += $kesisim['gun'];
    }
    $dolulukToplam = 0.0; $makSayisi = count($dGun);
    foreach ($dGun as $g) $dolulukToplam += min($gunSayisi, $g) / $gunSayisi * 100;
    $ortDoluluk = $makSayisi > 0 ? round($dolulukToplam / $makSayisi) : 0;

    return ['ciro' => round($ciro, 2), 'tahsil' => round($tahsil, 2), 'doluluk' => $ortDoluluk];
}

$kiralamalar = $db->query("SELECT * FROM kiralamalar WHERE durum<>'iptal'")->fetchAll();
$ciroDonem = 0.0;
$musteriCiro = [];
$subeCiro = [];
$musteriIskonto = [];
foreach ($kiralamalar as $k) {
    $sb = trim((string)($k['sube'] ?? '')) ?: trim((string)($musteriSube[(int)$k['musteri_id']] ?? '')) ?: '— Belirtilmemiş —';
    if (!isset($subeCiro[$sb])) $subeCiro[$sb] = ['ciro' => 0.0, 'adet' => 0];
    if ($k['baslangic'] >= $bas && $k['baslangic'] <= $bit) {
        $subeCiro[$sb]['adet'] += 1;
    }
    $gtPencereli = kiralamaTutarPencereli($k, $bas, $bit);
    if ($gtPencereli === null) continue;
    $subeCiro[$sb]['ciro'] += $gtPencereli;
    if ($subeFiltre !== '' && $sb !== $subeFiltre) continue;
    $ciroDonem += $gtPencereli;
    $mid = (int)$k['musteri_id'];
    $musteriCiro[$mid] = ($musteriCiro[$mid] ?? 0) + $gtPencereli;
    $iskOran = kiralamaGecerliIskonto($k);
    if ($iskOran > 0 && $gtPencereli > 0) {
        $bruteOran = (100 - $iskOran) > 0 ? (100 / (100 - $iskOran)) : 1;
        $musteriIskonto[$mid] = ($musteriIskonto[$mid] ?? 0) + ($gtPencereli * $bruteOran - $gtPencereli);
    }
}

$tahakkukToplam = 0.0; $tahsilToplamGenel = 0.0;
foreach ($kiralamalar as $k) {
    $sb = trim((string)($k['sube'] ?? '')) ?: trim((string)($musteriSube[(int)$k['musteri_id']] ?? '')) ?: '— Belirtilmemiş —';
    if ($subeFiltre !== '' && $sb !== $subeFiltre) continue;
    $tahakkukToplam += kiralamaTutar($k)['genel_toplam'];
}
if ($subeFiltre !== '') {
    $tsAll = $db->query("SELECT musteri_id, COALESCE(SUM(tutar),0) t FROM tahsilatlar GROUP BY musteri_id")->fetchAll();
    foreach ($tsAll as $r) {
        $sb = trim((string)($musteriSube[(int)$r['musteri_id']] ?? '')) ?: '— Belirtilmemiş —';
        if ($sb === $subeFiltre) $tahsilToplamGenel += (float)$r['t'];
    }
} else {
    $tahsilToplamGenel = (float)$db->query("SELECT COALESCE(SUM(tutar),0) FROM tahsilatlar")->fetchColumn();
}
$tahsilEdilmemis = round($tahakkukToplam - $tahsilToplamGenel, 2);
$_musteriFiltre = null;
if ($subeFiltre !== '') { $_musteriFiltre = []; foreach ($musteriSube as $_mid => $_sb) { if ((trim((string)$_sb) ?: '— Belirtilmemiş —') === $subeFiltre) $_musteriFiltre[] = (int)$_mid; } }
$_alacak = alacakOzeti($db, $_musteriFiltre);
$acikAlacak = $_alacak['acik'];

$tsSql = "SELECT t.tutar, t.musteri_id FROM tahsilatlar t WHERE t.tarih >= ? AND t.tarih <= ?";
$tsStmt = $db->prepare($tsSql); $tsStmt->execute([$bas, $bit]);
$tahsilDonem = 0.0;
foreach ($tsStmt->fetchAll() as $r) {
    if ($subeFiltre !== '') {
        $sb = trim((string)($musteriSube[(int)$r['musteri_id']] ?? '')) ?: '— Belirtilmemiş —';
        if ($sb !== $subeFiltre) continue;
    }
    $tahsilDonem += (float)$r['tutar'];
}

$dso = $ciroDonem > 0 ? round(max(0, $tahsilEdilmemis) / ($ciroDonem / max(1, $gunSayisi))) : 0;
$tahsilatOrani = $ciroDonem > 0 ? round($tahsilDonem / $ciroDonem * 100) : 0;

$oncekiBit = date('Y-m-d', strtotime($bas . ' -1 day'));
$oncekiBas = date('Y-m-d', strtotime($oncekiBit . ' -' . ($gunSayisi - 1) . ' days'));
$onceki = _donemOzet($db, $kiralamalar, $musteriSube, $makineler, $oncekiBas, $oncekiBit, $subeFiltre);

$aylar = [];
$ayIter = new DateTime(date('Y-m-01', strtotime($bas)));
$ayBitIter = new DateTime(date('Y-m-01', strtotime($bit)));
while ($ayIter <= $ayBitIter) { $aylar[] = $ayIter->format('Y-m'); $ayIter->modify('+1 month'); }
if (!$aylar) $aylar[] = date('Y-m', strtotime($bas));
$ayTahsil = array_fill_keys($aylar, 0.0);
$ayGider  = array_fill_keys($aylar, 0.0);

$ts = $db->prepare("SELECT substr(tarih,1,7) ay, tutar, musteri_id FROM tahsilatlar WHERE tarih >= ? AND tarih <= ?");
$ts->execute([$bas, $bit]);
foreach ($ts->fetchAll() as $r) {
    if (!isset($ayTahsil[$r['ay']])) continue;
    if ($subeFiltre !== '') {
        $sb = trim((string)($musteriSube[(int)$r['musteri_id']] ?? '')) ?: '— Belirtilmemiş —';
        if ($sb !== $subeFiltre) continue;
    }
    $ayTahsil[$r['ay']] += (float)$r['tutar'];
}
$makineSube = [];
foreach ($makineler as $m) $makineSube[$m['tur'] . ':' . $m['id']] = trim((string)($m['sube'] ?? '')) ?: '— Belirtilmemiş —';
try {
    $gs = $db->prepare("SELECT substr(tarih,1,7) ay, tutar, makine_tur, makine_id FROM giderler WHERE tarih >= ? AND tarih <= ?");
    $gs->execute([$bas, $bit]);
    foreach ($gs->fetchAll() as $r) {
        if (!isset($ayGider[$r['ay']])) continue;
        if ($subeFiltre !== '' && ($makineSube[$r['makine_tur'].':'.$r['makine_id']] ?? '') !== $subeFiltre) continue;
        $ayGider[$r['ay']] += (float)$r['tutar'];
    }
} catch (\Exception $e) { hataGunlugu('yonetim.giderler', $e, true); }

$aylikTrend = [];
foreach ($aylar as $a) {
    $aylikTrend[] = [
        'ay'      => $a,
        'tahsilat'=> round($ayTahsil[$a], 2),
        'gider'   => round($ayGider[$a], 2),
        'net'     => round($ayTahsil[$a] - $ayGider[$a], 2),
    ];
}

arsort($musteriCiro);

$unvanlar = [];
foreach ($db->query("SELECT id, unvan FROM musteriler")->fetchAll() as $r) $unvanlar[(int)$r['id']] = $r['unvan'];

$tumKalemler = cariBorcKalemleri($db);
$tahsilHar = [];
foreach ($db->query("SELECT musteri_id, COALESCE(SUM(tutar),0) t FROM tahsilatlar GROUP BY musteri_id")->fetchAll() as $r) {
    $tahsilHar[(int)$r['musteri_id']] = (float)$r['t'];
}

$topMusteri = [];
foreach (array_slice($musteriCiro, 0, 10, true) as $mid => $ciro) {
    if ($ciro <= 0) continue;
    $mid = (int)$mid;
    if (!isset($unvanlar[$mid])) continue;
    $kalem  = $tumKalemler[$mid] ?? ['borclar' => [], 'faturalanmamis' => []];
    $borc   = array_sum(array_column($kalem['borclar'], 'tutar'));
    $tahsil = $tahsilHar[$mid] ?? 0.0;
    $isk    = round($musteriIskonto[$mid] ?? 0, 2);
    $topMusteri[] = [
        'id' => $mid, 'unvan' => $unvanlar[$mid],
        'ciro' => round($ciro, 2),
        'bakiye' => round($borc - $tahsil, 2),
        'iskonto' => $isk,
        'iskonto_oran' => $ciro > 0 ? round($isk / ($ciro + $isk) * 100, 1) : 0,
        'tahsilat' => round($tahsil, 2),
        'tahsilat_orani' => $borc > 0 ? round(min(100, $tahsil / $borc * 100)) : null,
    ];
}

$subeList = [];
foreach ($subeCiro as $sb => $v) {
    $subeList[] = ['sube' => $sb, 'ciro' => round($v['ciro'], 2), 'kiralama_adet' => $v['adet']];
}
usort($subeList, fn($a, $b) => $b['ciro'] <=> $a['ciro']);

$winBas = $bas; $winBit = $bit; $winGun = max(1, $gunSayisi);
$unit   = KIRA_BIRIM_GUN;

$mp = [];
foreach ($makineler as $m) {
    if ($subeFiltre !== '' && trim((string)($m['sube'] ?? '')) !== $subeFiltre) continue;
    if ($tipFiltre !== '' && $m['tur'] !== $tipFiltre) continue;
    if ($markaFiltre !== '' && trim((string)($m['marka'] ?? '')) !== $markaFiltre) continue;
    if ($metrajMin !== null && ($m['metraj'] === null || $m['metraj'] < $metrajMin)) continue;
    if ($metrajMax !== null && ($m['metraj'] === null || $m['metraj'] > $metrajMax)) continue;
    $key = $m['tur'] . ':' . $m['id'];
    $mp[$key] = [
        'tur' => $m['tur'], 'id' => $m['id'], 'ad' => $m['ad'], 'kategori' => $m['kategori'],
        'sube' => $m['sube'], 'marka' => $m['marka'], 'metraj' => $m['metraj'],
        'doluluk_gun' => 0, 'gelir' => 0.0, 'gider' => 0.0,
        'amortisman' => aylikAmortisman((float)$m['alis_bedeli'], (int)$m['faydali_omur_yil']) * ($winGun / 30),
    ];
}
$ks = $db->query("SELECT makine_tur, makine_id, baslangic, bitis, gercek_bitis, kira_tipi, birim_ucret, miktar, iskonto_oran, onay_durumu FROM kiralamalar WHERE makine_tur IS NOT NULL AND makine_id IS NOT NULL AND durum<>'iptal'")->fetchAll();
foreach ($ks as $k) {
    $key = $k['makine_tur'] . ':' . $k['makine_id'];
    if (!isset($mp[$key])) continue;
    $kesisim = kiralamaDonemKesisimi($k, $winBas, $winBit);
    if ($kesisim === null) continue;
    $gun = $kesisim['gun'];
    $mp[$key]['doluluk_gun'] += $gun;
    $tip  = $k['kira_tipi'] ?: 'gunluk';
    $gelir = (float)$k['birim_ucret'] * max(1, (int)$k['miktar']) * ($gun / ($unit[$tip] ?? 1));
    $iskontoOran = kiralamaGecerliIskonto($k);
    $mp[$key]['gelir'] += $gelir - round($gelir * $iskontoOran / 100, 2);
}
try {
    $gs2 = $db->prepare("SELECT makine_tur, makine_id, COALESCE(SUM(tutar),0) t FROM giderler WHERE tarih >= ? AND tarih <= ? GROUP BY makine_tur, makine_id");
    $gs2->execute([$winBas, $winBit]);
    foreach ($gs2->fetchAll() as $g) {
        $key = $g['makine_tur'] . ':' . $g['makine_id'];
        if (isset($mp[$key])) $mp[$key]['gider'] += (float)$g['t'];
    }
} catch (\Exception $e) { hataGunlugu('yonetim.makine_gider', $e, true); }

$servisMaliyet = makineServisMaliyetleri($db, $winBas, $winBit);
$nakliyeMaliyet = makineNakliyeMaliyetleri($db, $winBas, $winBit);

$makineList = [];
foreach ($mp as $r) {
    $key = $r['tur'] . ':' . $r['id'];
    $r['servis_maliyet']  = $servisMaliyet[$key] ?? 0.0;
    $r['nakliye_maliyet'] = $nakliyeMaliyet[$key] ?? 0.0;
    $r['doluluk_gun']   = min($winGun, $r['doluluk_gun']);
    $r['doluluk_yuzde'] = $winGun > 0 ? round($r['doluluk_gun'] / $winGun * 100) : 0;
    $r['gelir']         = round($r['gelir'], 2);
    $r['net']           = round($r['gelir'] - $r['gider'] - $r['servis_maliyet'] - $r['nakliye_maliyet'] - $r['amortisman'], 2);
    $makineList[] = $r;
}
$enKarli = $makineList;
usort($enKarli, fn($a, $b) => $b['net'] <=> $a['net']);
$enDolu = $makineList;
usort($enDolu, fn($a, $b) => $b['doluluk_yuzde'] <=> $a['doluluk_yuzde']);
$ortDoluluk = count($makineList) ? round(array_sum(array_column($makineList, 'doluluk_yuzde')) / count($makineList)) : 0;

$markaAgg = [];
foreach ($makineList as $m) {
    $mk = trim((string)($m['marka'] ?? '')) ?: '— Belirtilmemiş —';
    if (!isset($markaAgg[$mk])) $markaAgg[$mk] = ['marka' => $mk, 'adet' => 0, 'gelir' => 0.0, 'net' => 0.0, 'doluluk_toplam' => 0];
    $markaAgg[$mk]['adet']++;
    $markaAgg[$mk]['gelir'] += $m['gelir'];
    $markaAgg[$mk]['net']   += $m['net'];
    $markaAgg[$mk]['doluluk_toplam'] += $m['doluluk_yuzde'];
}
$markaKarsilastirma = [];
foreach ($markaAgg as $mk) {
    $mk['gelir'] = round($mk['gelir'], 2);
    $mk['net']   = round($mk['net'], 2);
    $mk['ortalama_doluluk'] = $mk['adet'] > 0 ? round($mk['doluluk_toplam'] / $mk['adet']) : 0;
    unset($mk['doluluk_toplam']);
    $markaKarsilastirma[] = $mk;
}
usort($markaKarsilastirma, fn($a, $b) => $b['net'] <=> $a['net']);

$metrajBantlari = [
    ['etiket' => '0-10 m',  'min' => 0,  'max' => 10],
    ['etiket' => '10-15 m', 'min' => 10, 'max' => 15],
    ['etiket' => '15-20 m', 'min' => 15, 'max' => 20],
    ['etiket' => '20-25 m', 'min' => 20, 'max' => 25],
    ['etiket' => '25+ m',   'min' => 25, 'max' => 999],
];
$metrajAgg = [];
foreach ($metrajBantlari as $b) $metrajAgg[$b['etiket']] = ['bant' => $b['etiket'], 'adet' => 0, 'gelir' => 0.0, 'net' => 0.0, 'doluluk_toplam' => 0];
foreach ($makineList as $m) {
    if ($m['tur'] !== 'manlift' || $m['metraj'] === null) continue;
    foreach ($metrajBantlari as $b) {
        if ($m['metraj'] > $b['min'] && $m['metraj'] <= $b['max']) {
            $metrajAgg[$b['etiket']]['adet']++;
            $metrajAgg[$b['etiket']]['gelir'] += $m['gelir'];
            $metrajAgg[$b['etiket']]['net']   += $m['net'];
            $metrajAgg[$b['etiket']]['doluluk_toplam'] += $m['doluluk_yuzde'];
            break;
        }
    }
}
$metrajKarsilastirma = [];
foreach ($metrajAgg as $b) {
    if ($b['adet'] === 0) continue;
    $b['gelir'] = round($b['gelir'], 2);
    $b['net']   = round($b['net'], 2);
    $b['ortalama_doluluk'] = round($b['doluluk_toplam'] / $b['adet']);
    unset($b['doluluk_toplam']);
    $metrajKarsilastirma[] = $b;
}

$enCokServis = [];
try {
    $tsvStmt = $db->prepare("SELECT makine_tur, makine_id, makine_ad, tip, COALESCE(maliyet,0) maliyet FROM teknik_servis WHERE tarih >= ? AND tarih <= ?");
    $tsvStmt->execute([$bas, $bit]);
    $svcAgg = [];
    foreach ($tsvStmt->fetchAll() as $s) {
        $key = ($s['makine_tur'] ?: '?') . ':' . ($s['makine_id'] ?: 0);
        if ($subeFiltre !== '' && ($makineSube[$key] ?? '') !== $subeFiltre) continue;
        if (!isset($svcAgg[$key])) $svcAgg[$key] = ['makine_ad' => $s['makine_ad'] ?: $key, 'makine_tur' => $s['makine_tur'] ?: null, 'makine_id' => $s['makine_id'] ? (int)$s['makine_id'] : null, 'ariza' => 0, 'bakim' => 0, 'expertiz' => 0, 'toplam' => 0, 'maliyet' => 0.0];
        $tip = in_array($s['tip'], ['ariza','bakim','expertiz'], true) ? $s['tip'] : 'ariza';
        $svcAgg[$key][$tip]++;
        $svcAgg[$key]['toplam']++;
        $svcAgg[$key]['maliyet'] += (float)$s['maliyet'];
    }
    foreach ($svcAgg as $s) { $s['maliyet'] = round($s['maliyet'], 2); $enCokServis[] = $s; }
    usort($enCokServis, fn($a, $b) => $b['toplam'] <=> $a['toplam']);
} catch (\Exception $e) { hataGunlugu('yonetim.servis_ozet', $e, true); }

$subelerAktif = array_values(array_map(fn($r) => $r['ad'], $db->query("SELECT ad FROM subeler WHERE aktif=1 ORDER BY ad COLLATE NOCASE")->fetchAll()));
$markalarTum  = array_values(array_unique(array_filter(array_map(fn($m) => trim((string)($m['marka'] ?? '')), $makineler))));
sort($markalarTum, SORT_STRING | SORT_FLAG_CASE);

jsonOk([
    'ozet' => [
        'ciro_donem'      => round($ciroDonem, 2),
        'tahsilat_donem'  => round($tahsilDonem, 2),
        'acik_alacak'     => $acikAlacak,
        'faturalanmamis'  => $_alacak['faturalanmamis'],
        'geciken_alacak'  => $_alacak['geciken'],
        'tahsilat_orani'  => $tahsilatOrani,
        'dso'             => $dso,
        'ortalama_doluluk'=> $ortDoluluk,
        'bas'             => $bas,
        'bit'             => $bit,
        'sube'            => $subeFiltre,
        'tip'             => $tipFiltre,
        'marka'           => $markaFiltre,
        'metraj_min'      => $metrajMin,
        'metraj_max'      => $metrajMax,
        'gun_sayisi'      => $gunSayisi,
    ],
    'onceki_donem' => [
        'bas' => $oncekiBas, 'bit' => $oncekiBit,
        'ciro' => $onceki['ciro'], 'tahsilat' => $onceki['tahsil'], 'ortalama_doluluk' => $onceki['doluluk'],
        'tahsilat_orani' => $onceki['ciro'] > 0 ? round($onceki['tahsil'] / $onceki['ciro'] * 100) : null,
    ],
    'sube_listesi'   => $subelerAktif,
    'marka_listesi'  => $markalarTum,
    'aylik_trend'    => $aylikTrend,
    'top_musteriler' => $topMusteri,
    'subeler'        => $subeList,
    'en_karli'       => array_slice($enKarli, 0, 8),
    'en_dolu'        => array_slice($enDolu, 0, 8),
    'marka_karsilastirma'  => $markaKarsilastirma,
    'metraj_karsilastirma' => $metrajKarsilastirma,
    'en_cok_servis'        => array_slice($enCokServis, 0, 10),
]);
