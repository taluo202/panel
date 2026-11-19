<?php
declare(strict_types=1);
require_once __DIR__ . '/makine-kategori-lib.php';
require_once __DIR__ . '/filo-referans-lib.php';

const FG_BASKA_SUBE = 'Başka şube';

function fgDetayGizli(array $k, ?string $kisit): bool {
    return $kisit !== null && trim((string)($k['sube'] ?? '')) !== $kisit;
}

function fgGunFarki(string $bas, string $bit): ?int {
    $a = DateTimeImmutable::createFromFormat('!Y-m-d', substr($bas, 0, 10));
    $b = DateTimeImmutable::createFromFormat('!Y-m-d', substr($bit, 0, 10));
    if (!$a || !$b) return null;
    return (int)$a->diff($b)->format('%r%a');
}

function fgIskontoSatiri(array $l): ?array {
    $f = json_decode((string)($l['degisenler'] ?? ''), true);
    $v = is_array($f) ? ($f['iskonto_oran'] ?? null) : null;
    if (!is_array($v) || count($v) !== 2) return null;
    $o = (float)$v[0]; $y = (float)$v[1];
    if (abs($o - $y) < 0.000001) return null;
    return ['tarih' => (string)$l['tarih'], 'kullanici' => trim((string)($l['kullanici_ad'] ?? '')) ?: 'Kullanıcı bilinmiyor', 'onceki' => round($o, 2), 'yeni' => round($y, 2)];
}

function kiralamaSonIskontoDegisimi(PDO $db, int $kiralamaId, ?string $bugun = null): ?array {
    $bugun = $bugun ?? date('Y-m-d');
    $q = $db->prepare("SELECT tarih, kullanici_ad, degisenler FROM islem_log WHERE tablo='kiralamalar' AND kayit_id=? AND degisenler LIKE '%iskonto_oran%' ORDER BY id DESC");
    $q->execute([$kiralamaId]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $s = fgIskontoSatiri($l);
        if ($s === null) continue;
        $s['gun'] = fgGunFarki($s['tarih'], $bugun);
        return $s;
    }
    return null;
}

function fgSonIskontoToplu(PDO $db, array $ids, ?string $bugun = null): array {
    $bugun = $bugun ?? date('Y-m-d');
    $ist = array_flip(array_map('intval', $ids));
    $out = [];
    if (!$ist) return $out;
    foreach ($db->query("SELECT kayit_id, tarih, kullanici_ad, degisenler FROM islem_log WHERE tablo='kiralamalar' AND degisenler LIKE '%iskonto_oran%' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $id = (int)$l['kayit_id'];
        if (!isset($ist[$id]) || isset($out[$id])) continue;
        $s = fgIskontoSatiri($l);
        if ($s === null) continue;
        $s['gun'] = fgGunFarki($s['tarih'], $bugun);
        $out[$id] = $s;
    }
    return $out;
}

function filoSinifUygula(PDO $db, array $makineler): array {
    frSema($db);
    $sinif = [];
    foreach ($db->query('SELECT tur, makine_id, ana_grup, alt_grup, enerji FROM filo_siniflar')->fetchAll(PDO::FETCH_ASSOC) as $r) $sinif[$r['tur'] . ':' . $r['makine_id']] = $r;
    $harita = mkAnaGrupHaritasi($db);
    foreach ($makineler as &$m) {
        $s = $sinif[$m['tur'] . ':' . $m['id']] ?? [];
        $kat = trim((string)($m['kategori'] ?? ''));
        $ana = trim((string)($s['ana_grup'] ?? ''));
        $kaynak = 'makine';
        if ($ana === '' && $kat !== '' && isset($harita[$kat])) { $ana = $harita[$kat]['ana_grup']; $kaynak = $harita[$kat]['kaynak']; }
        if ($ana === '') $kaynak = 'eksik';
        $alt = trim((string)($s['alt_grup'] ?? '')) ?: $kat;
        $yakit = makineYakitTipi($m['yakit_tipi'] ?? null) ?? makineYakitTipi($s['enerji'] ?? null);
        $m['ana_grup'] = $ana;
        $m['alt_grup'] = $alt;
        $m['ana_grup_kaynak'] = $kaynak;
        $m['yakit'] = $yakit;
    }
    unset($m);
    return $makineler;
}

function fgAktifMi(array $m): bool {
    return (int)($m['aktif'] ?? 1) === 1 && !in_array($m['durum'] ?? '', ['pasif', 'satildi', 'silindi'], true);
}

function filoBakimAnalizi(array $makineler, string $bugun): array {
    $yil = []; $takvim = []; $takvimGecmis = []; $ay = []; $gecikmis = 0; $yaklasan = 0; $eksik = 0; $toplam = 0;
    $bas = new DateTimeImmutable(substr($bugun, 0, 7) . '-01');
    for ($i = 0; $i < 12; $i++) $ay[$bas->modify("+$i month")->format('Y-m')] = 0;
    foreach ($makineler as $m) {
        if (!fgAktifMi($m)) continue;
        $toplam++;
        $t = (string)($m['periyodik'] ?? '');
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $t);
        if (!$d || $d->format('Y-m-d') !== $t) { $eksik++; continue; }
        $y = (int)$d->format('Y');
        $yil[$y] = ($yil[$y] ?? 0) + 1;
        $tk = $d->format('Y-m');
        $takvim[$tk] = ($takvim[$tk] ?? 0) + 1;
        $takvimGecmis[$tk] = ($takvimGecmis[$tk] ?? 0) + ($t < $bugun ? 1 : 0);
        if (isset($ay[$tk]) && $t >= $bugun) $ay[$tk]++;
        $fark = fgGunFarki($bugun, $t);
        if ($fark < 0) $gecikmis++; elseif ($fark <= 30) $yaklasan++;
    }
    ksort($yil); ksort($takvim);
    return [
        'toplam' => $toplam, 'gecikmis' => $gecikmis, 'yaklasan_30' => $yaklasan, 'eksik' => $eksik,
        'yil' => array_map(fn($k, $v) => ['yil' => (int)$k, 'adet' => $v], array_keys($yil), $yil),
        'ay' => array_map(fn($k, $v) => ['ay' => $k, 'adet' => $v], array_keys($ay), $ay),
        'takvim' => array_map(fn($k, $v) => ['yil' => (int)substr($k, 0, 4), 'ay' => (int)substr($k, 5, 2), 'adet' => $v, 'gecmis' => $takvimGecmis[$k] ?? 0], array_keys($takvim), $takvim),
        'plan_sayfasi' => 'teknik-servis.html?sekme=plan',
    ];
}

function fgKiraNetAylik(array $k): float {
    $tip = ($k['kira_tipi'] ?? '') ?: 'gunluk';
    $birim = (float)($k['birim_ucret'] ?? 0) * max(1, (int)($k['miktar'] ?? 1));
    return round($birim * 30 / kiraBirimGun($tip) * (1 - kiralamaGecerliIskonto($k) / 100), 2);
}

function filoKapasiteSube(array $makineler, array $aktifKiralamalar): array {
    $kira = [];
    foreach ($aktifKiralamalar as $k) $kira[($k['makine_tur'] ?? '') . ':' . (int)($k['makine_id'] ?? 0)][] = $k;
    $sube = [];
    foreach ($makineler as $m) {
        if (in_array($m['durum'] ?? '', ['satildi', 'pasif'], true)) continue;
        $sb = trim((string)($m['sube'] ?? '')) ?: 'Belirtilmemiş';
        if (!isset($sube[$sb])) $sube[$sb] = ['sube' => $sb, 'kapasite' => 0.0, 'bosta' => 0.0, 'fiyat_farki' => 0.0, 'gerceklesen' => 0.0, 'makine' => 0, 'kirada' => 0, 'bosta_adet' => 0, 'liste_eksik' => 0, 'kur_eksik' => 0];
        $s = &$sube[$sb];
        $s['makine']++;
        $liste = $kira[$m['tur'] . ':' . $m['id']] ?? [];
        if (!$liste) {
            $a = (float)($m['liste_fiyat_aylik'] ?? 0);
            if ($a > 0) { $s['bosta'] += $a; $s['kapasite'] += $a; $s['bosta_adet']++; } else $s['liste_eksik']++;
            unset($s);
            continue;
        }
        $s['kirada']++;
        foreach ($liste as $k) {
            if ((trim((string)($k['doviz'] ?? 'TL')) ?: 'TL') !== 'TL') { $s['kur_eksik']++; continue; }
            $lf = kiraListeFiyatAylik($m, $k['kira_tipi'] ?? null)['aylik'] * max(1, (int)($k['miktar'] ?? 1));
            if ($lf <= 0) { $s['liste_eksik']++; continue; }
            $net = fgKiraNetAylik($k);
            $s['kapasite'] += $lf; $s['gerceklesen'] += $net; $s['fiyat_farki'] += $lf - $net;
        }
        unset($s);
    }
    foreach ($sube as &$s) foreach (['kapasite', 'bosta', 'fiyat_farki', 'gerceklesen'] as $f) $s[$f] = round($s[$f], 2);
    unset($s);
    $out = array_values($sube);
    usort($out, fn($a, $b) => $b['kapasite'] <=> $a['kapasite'] ?: strcmp($a['sube'], $b['sube']));
    return $out;
}

function filoSubeAnaGrupDoluluk(array $makineler, array $dolu, int $donemGun): array {
    $h = []; $subeler = []; $anaAdet = [];
    foreach ($makineler as $m) {
        if (!fgAktifMi($m)) continue;
        $sb = trim((string)($m['sube'] ?? '')) ?: 'Belirtilmemiş';
        $ag = trim((string)($m['ana_grup'] ?? '')) ?: 'Ana grup belirtilmemiş';
        $k = $sb . "\0" . $ag;
        if (!isset($h[$k])) $h[$k] = ['sube' => $sb, 'ana_grup' => $ag, 'adet' => 0, 'uygun_gun' => 0, 'kirali_gun' => 0];
        $h[$k]['adet']++;
        $h[$k]['uygun_gun'] += $donemGun;
        $h[$k]['kirali_gun'] += min($donemGun, count($dolu[$m['tur'] . ':' . $m['id']] ?? []));
        $subeler[$sb] = true;
        $anaAdet[$ag] = ($anaAdet[$ag] ?? 0) + 1;
    }
    foreach ($h as &$c) $c['doluluk'] = $c['uygun_gun'] > 0 ? round($c['kirali_gun'] / $c['uygun_gun'] * 100, 2) : null;
    unset($c);
    $sb = array_keys($subeler); sort($sb);
    arsort($anaAdet);
    return ['subeler' => $sb, 'ana_gruplar' => array_keys($anaAdet), 'hucreler' => array_values($h), 'donem_gun' => $donemGun];
}

function filoAnaGrupKirilim(array $makineler): array {
    $g = [];
    foreach ($makineler as $m) {
        $ag = trim((string)($m['ana_grup'] ?? '')) ?: 'Ana grup belirtilmemiş';
        $alt = trim((string)($m['alt_grup'] ?? '')) ?: 'Kategori seçilmemiş';
        if (!isset($g[$ag])) $g[$ag] = ['ana_grup' => $ag, 'adet' => 0, 'kirada' => 0, 'alt' => []];
        $g[$ag]['adet']++;
        if (!empty($m['kirada'])) $g[$ag]['kirada']++;
        if (!isset($g[$ag]['alt'][$alt])) $g[$ag]['alt'][$alt] = ['ad' => $alt, 'adet' => 0, 'kirada' => 0];
        $g[$ag]['alt'][$alt]['adet']++;
        if (!empty($m['kirada'])) $g[$ag]['alt'][$alt]['kirada']++;
    }
    $out = [];
    foreach ($g as $x) {
        $alt = array_values($x['alt']);
        usort($alt, fn($a, $b) => $b['adet'] <=> $a['adet'] ?: strcmp($a['ad'], $b['ad']));
        $x['alt'] = $alt;
        $out[] = $x;
    }
    usort($out, fn($a, $b) => $b['adet'] <=> $a['adet'] ?: strcmp($a['ana_grup'], $b['ana_grup']));
    return $out;
}

function filoNoktaVerisi(array $makineler, array $netAylik, array $kiraliGun, int $yil): array {
    $out = [];
    foreach ($makineler as $m) {
        if (!fgAktifMi($m)) continue;
        $iy = (int)($m['imal_yili'] ?? 0);
        if ($iy <= 1900 || $iy > $yil) continue;
        $k = $m['tur'] . ':' . $m['id'];
        $out[] = [
            'tur' => $m['tur'], 'id' => (int)$m['id'], 'kod' => (string)($m['ad'] ?? ''), 'makine_kodu' => $m['makine_kodu'] ?? null,
            'marka' => (string)($m['marka'] ?? ''), 'model' => (string)($m['model'] ?? ''), 'kategori' => (string)($m['kategori'] ?? ''),
            'metraj' => $m['metraj'] ?? null, 'sube' => trim((string)($m['sube'] ?? '')) ?: 'Belirtilmemiş', 'ana_grup' => $m['ana_grup'] ?? '',
            'imal_yili' => $iy, 'yas' => $yil - $iy, 'liste_aylik' => round((float)($m['liste_fiyat_aylik'] ?? 0), 2),
            'gerceklesen_aylik' => round((float)($netAylik[$k] ?? 0), 2), 'kirali_gun' => (int)($kiraliGun[$k] ?? 0),
        ];
    }
    return $out;
}

function filoGecikenAyOzeti(array $gecikenler): array {
    $a = [];
    foreach ($gecikenler as $g) { $k = (string)($g['ay_kovasi'] ?? ''); if ($k !== '') $a[$k] = ($a[$k] ?? 0) + 1; }
    ksort($a);
    return array_map(fn($k, $v) => ['ay' => $k, 'adet' => $v], array_keys($a), $a);
}

function filoAktifKiralamaOzeti(array $aktif, array $makineAnahtar, bool $filtreVar, string $bugun): array {
    $ilSay = []; $dovizSay = []; $gecikenler = []; $gercekAylik = 0.0; $kurEksikAdet = 0;
    $ilBos = 0; $ilDetay = []; $ilMakineler = []; $ilIlce = []; $aktifFiltreli = []; $netAylik = [];
    $kisit = subeKisitiHam();
    $hesapAlan = array_flip(['id', 'makine_tur', 'makine_id', 'kira_tipi', 'birim_ucret', 'miktar', 'iskonto_oran', 'onay_durumu', 'doviz', 'sube']);
    foreach ($aktif as $k) {
        $key = ($k['makine_tur'] ?? '') . ':' . (int)($k['makine_id'] ?? 0);
        if ($filtreVar && !isset($makineAnahtar[$key])) continue;
        $gizli = fgDetayGizli($k, $kisit);
        $il = trim((string)($k['il'] ?? ''));
        if ($il !== '') {
            $ilSay[$il] = ($ilSay[$il] ?? 0) + 1;
            $ilDetay[$il]['aktif_kiralama'] = ($ilDetay[$il]['aktif_kiralama'] ?? 0) + 1;
            $ilce = $gizli ? FG_BASKA_SUBE : (trim((string)($k['ilce'] ?? '')) ?: 'İlçe belirtilmemiş');
            $ilIlce[$il][$ilce] = ($ilIlce[$il][$ilce] ?? 0) + 1;
            if (isset($makineAnahtar[$key]) && !isset($ilMakineler[$il][$key])) {
                $ilMakineler[$il][$key] = true;
                $ilDetay[$il]['makine_adedi'] = ($ilDetay[$il]['makine_adedi'] ?? 0) + 1;
                $field = 'tur_' . $k['makine_tur'];
                $ilDetay[$il][$field] = ($ilDetay[$il][$field] ?? 0) + 1;
            }
        } else $ilBos++;
        $dv = trim((string)($k['doviz'] ?? 'TL')) ?: 'TL';
        $dovizSay[$dv] = ($dovizSay[$dv] ?? 0) + 1;
        $birim = (float)($k['birim_ucret'] ?? 0) * max(1, (int)($k['miktar'] ?? 1));
        $aylikKarsilik = $birim * (30 / kiraBirimGun($k['kira_tipi'] ?? 'gunluk'));
        if ($dv === 'TL') $gercekAylik += $aylikKarsilik * (1 - kiralamaGecerliIskonto($k) / 100); else $kurEksikAdet++;
        $aktifFiltreli[] = array_intersect_key($k, $hesapAlan);
        if ($dv === 'TL') $netAylik[$key] = ($netAylik[$key] ?? 0) + fgKiraNetAylik($k);
        if (!empty($k['bitis']) && $k['bitis'] < $bugun) {
            $gecikenler[] = [
                'id' => (int)$k['id'], 'kod' => $k['kod'], 'musteri' => $gizli ? null : $k['musteri_unvan'],
                'musteri_id' => $gizli ? null : (int)($k['musteri_id'] ?? 0), 'detay_gizli' => $gizli,
                'makine_tur' => (string)($k['makine_tur'] ?? ''), 'makine_id' => (int)($k['makine_id'] ?? 0),
                'ekipman' => $k['ekipman_adi'], 'sube' => $k['sube'], 'il' => $il,
                'ilce' => $gizli ? null : trim((string)($k['ilce'] ?? '')), 'santiye_adres' => $gizli ? null : trim((string)($k['santiye_adres'] ?? '')),
                'bitis' => $k['bitis'], 'ay_kovasi' => (new DateTimeImmutable($k['bitis']))->modify('+1 day')->format('Y-m'),
                'gecikme_gun' => (int)((strtotime($bugun) - strtotime($k['bitis'])) / 86400),
            ];
        }
    }
    usort($gecikenler, fn($a, $b) => $b['gecikme_gun'] <=> $a['gecikme_gun']);
    foreach ($ilIlce as $il => $sayilar) { arsort($sayilar); $ilDetay[$il]['ilceler'] = array_map(fn($a, $s) => ['ad' => (string)$a, 'sayi' => $s], array_keys($sayilar), $sayilar); }
    return ['il_say' => $ilSay, 'il_detay' => $ilDetay, 'il_bos' => $ilBos, 'doviz_say' => $dovizSay, 'gecikenler' => $gecikenler,
        'gercek_aylik' => $gercekAylik, 'kur_eksik' => $kurEksikAdet, 'aktif_filtreli' => $aktifFiltreli, 'net_aylik' => $netAylik];
}
