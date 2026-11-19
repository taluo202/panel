<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function _anTarih($v, string $varsayilan): string {
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', metin($v)) ? metin($v) : $varsayilan;
}

function _anGunSayisi(string $bas, string $bit): int {
    return max(1, (int)((new DateTimeImmutable($bas))->diff(new DateTimeImmutable($bit))->days) + 1);
}

function analizOncekiDonem(string $bas, string $bit): array {
    $gun = _anGunSayisi($bas, $bit);
    $oBit = (new DateTimeImmutable($bas))->modify('-1 day');
    $oBas = $oBit->modify('-' . ($gun - 1) . ' days');
    return [$oBas->format('Y-m-d'), $oBit->format('Y-m-d')];
}

function analizDkMaliyetleri(PDO $db, string $bas, string $bit): array {
    $out = [];
    try {
        $rows = $db->query("SELECT makine_tur, makine_id, temin_baslangic, temin_bitis, maliyet_tipi,
                                   maliyet_birim_tutari, maliyet_para_birimi, nakliye_gelis, nakliye_iade, durum
                            FROM dis_kiralama_kayitlari")->fetchAll();
    } catch (\Throwable $e) { hataGunlugu('analiz.dk_maliyet', $e, true); return []; }
    foreach ($rows as $r) {
        if (($r['durum'] ?? '') === 'iptal') continue;
        $tBas = (string)($r['temin_baslangic'] ?? '');
        if ($tBas === '') continue;
        $tBit = (string)($r['temin_bitis'] ?? '') ?: $bit;
        $dBas = max($tBas, $bas); $dBit = min($tBit, $bit);
        $k = $r['makine_tur'] . ':' . (int)$r['makine_id'];
        if (!isset($out[$k])) $out[$k] = ['maliyet' => 0.0, 'gun' => 0, 'doviz' => false];
        if (($r['maliyet_para_birimi'] ?? 'TL') !== 'TL' && ($r['maliyet_para_birimi'] ?? '') !== '') $out[$k]['doviz'] = true;
        if ($dBas <= $dBit) {
            $gun = _anGunSayisi($dBas, $dBit);
            $out[$k]['gun'] += $gun;
            $birim = (float)($r['maliyet_birim_tutari'] ?? 0);
            $bolen = ['gunluk' => 1, 'haftalik' => 7, 'aylik' => 30][$r['maliyet_tipi'] ?? 'gunluk'] ?? 1;
            $out[$k]['maliyet'] += $birim * $gun / $bolen;
        }
        if ($tBas >= $bas && $tBas <= $bit) $out[$k]['maliyet'] += (float)($r['nakliye_gelis'] ?? 0);
        if (!empty($r['temin_bitis']) && $r['temin_bitis'] >= $bas && $r['temin_bitis'] <= $bit) $out[$k]['maliyet'] += (float)($r['nakliye_iade'] ?? 0);
    }
    foreach ($out as &$v) $v['maliyet'] = round($v['maliyet'], 2);
    unset($v);
    return $out;
}

function analizMakineKarlilik(PDO $db, string $bas, string $bit, ?string $sube = null, array $turler = ['arac', 'manlift']): array {
    $winGun = _anGunSayisi($bas, $bit);
    $unit = KIRA_BIRIM_GUN;
    $liste = [];
    try {
        foreach ($db->query("SELECT 'arac' tur, id, liste_fiyat_aylik FROM araclar UNION ALL SELECT 'manlift', id, liste_fiyat_aylik FROM manliftler")->fetchAll() as $r)
            $liste[$r['tur'] . ':' . $r['id']] = (float)($r['liste_fiyat_aylik'] ?? 0);
    } catch (\Throwable $e) { hataGunlugu('analiz.liste_fiyat', $e, true); }
    $dk = analizDkMaliyetleri($db, $bas, $bit);

    $mp = [];
    foreach (makineListesi($db) as $m) {
        if (!in_array($m['tur'], $turler, true)) continue;
        if ($sube !== null && $sube !== '' && trim((string)($m['sube'] ?? '')) !== $sube) continue;
        if (($m['durum_ham'] ?? '') === 'satildi') continue;
        $key = $m['tur'] . ':' . $m['id'];
        $dis = ($m['temin_sekli'] ?? 'stok') === 'dis';
        $uygun = $dis ? min($winGun, (int)($dk[$key]['gun'] ?? 0)) : $winGun;
        if ((int)($m['aktif'] ?? 1) !== 1 && !$dis) $uygun = 0;
        $mp[$key] = [
            'tur' => $m['tur'], 'id' => (int)$m['id'], 'ad' => $m['ad'], 'sube' => $m['sube'],
            'marka' => $m['marka'], 'model' => $m['model'] ?? null, 'metraj' => $m['metraj'],
            'dk' => $dis, 'durum' => $m['durum'],
            'uygun_gun' => $uygun, 'dolu_gun' => 0, 'kiralama_adet' => 0,
            'gelir' => 0.0, 'gider' => 0.0,
            'amortisman' => $dis ? 0.0 : round(aylikAmortisman((float)$m['alis_bedeli'], (int)$m['faydali_omur_yil']) * ($winGun / 30), 2),
            'dk_maliyet' => $dis ? (float)($dk[$key]['maliyet'] ?? 0) : 0.0,
            'liste_fiyat_aylik' => $liste[$key] ?? 0.0,
        ];
    }
    $ks = $db->query("SELECT makine_tur, makine_id, baslangic, bitis, gercek_bitis, kira_tipi, birim_ucret, miktar, iskonto_oran, onay_durumu, doviz, durum
                      FROM kiralamalar WHERE makine_tur IS NOT NULL AND makine_id IS NOT NULL AND durum<>'iptal'")->fetchAll();
    $dovizli = 0;
    foreach ($ks as $k) {
        $key = $k['makine_tur'] . ':' . $k['makine_id'];
        if (!isset($mp[$key])) continue;
        $kes = kiralamaDonemKesisimi($k, $bas, $bit);
        if ($kes === null) continue;
        $mp[$key]['dolu_gun'] += $kes['gun'];
        $mp[$key]['kiralama_adet']++;
        if (($k['doviz'] ?? 'TL') !== 'TL' && ($k['doviz'] ?? '') !== '') { $dovizli++; continue; }
        $mp[$key]['gelir'] += (float)kiralamaTutarPencereli($k, $bas, $bit);
    }
    try {
        $gs = $db->prepare("SELECT makine_tur, makine_id, COALESCE(SUM(tutar),0) t FROM giderler WHERE tarih >= ? AND tarih <= ? GROUP BY makine_tur, makine_id");
        $gs->execute([$bas, $bit]);
        foreach ($gs->fetchAll() as $g) { $key = $g['makine_tur'] . ':' . $g['makine_id']; if (isset($mp[$key])) $mp[$key]['gider'] += (float)$g['t']; }
    } catch (\Throwable $e) { hataGunlugu('analiz.giderler', $e, true); }
    $servis = makineServisMaliyetleri($db, $bas, $bit);
    $nakliye = makineNakliyeMaliyetleri($db, $bas, $bit);

    $satirlar = [];
    foreach ($mp as $key => $r) {
        $r['servis_maliyet'] = $servis[$key] ?? 0.0;
        $r['nakliye_maliyet'] = $nakliye[$key] ?? 0.0;
        $r['dolu_gun'] = min($r['uygun_gun'] > 0 ? $r['uygun_gun'] : $winGun, $r['dolu_gun']);
        $r['bos_gun'] = max(0, $r['uygun_gun'] - $r['dolu_gun']);
        $r['doluluk'] = $r['uygun_gun'] > 0 ? round($r['dolu_gun'] / $r['uygun_gun'] * 100, 1) : null;
        $r['gelir'] = round($r['gelir'], 2);
        $r['gider'] = round($r['gider'], 2);
        $r['toplam_maliyet'] = round($r['gider'] + $r['servis_maliyet'] + $r['nakliye_maliyet'] + $r['amortisman'] + $r['dk_maliyet'], 2);
        $r['net'] = round($r['gelir'] - $r['toplam_maliyet'], 2);
        $r['gunluk_gelir'] = $r['dolu_gun'] > 0 ? round($r['gelir'] / $r['dolu_gun'], 2) : null;
        $r['oneriler'] = _anMakineOnerileri($r, $winGun);
        $satirlar[] = $r;
    }
    usort($satirlar, fn($a, $b) => $b['net'] <=> $a['net']);

    $say = count($satirlar);
    $uygunT = array_sum(array_column($satirlar, 'uygun_gun'));
    $doluT = array_sum(array_column($satirlar, 'dolu_gun'));
    $ozet = [
        'bas' => $bas, 'bit' => $bit, 'gun' => $winGun, 'makine_adet' => $say,
        'gelir' => round(array_sum(array_column($satirlar, 'gelir')), 2),
        'maliyet' => round(array_sum(array_column($satirlar, 'toplam_maliyet')), 2),
        'net' => round(array_sum(array_column($satirlar, 'net')), 2),
        'doluluk' => $uygunT > 0 ? round($doluT / $uygunT * 100, 1) : null,
        'bos_makine_gun' => max(0, $uygunT - $doluT),
        'zarar_eden' => count(array_filter($satirlar, fn($r) => $r['net'] < 0)),
        'dusuk_doluluk' => count(array_filter($satirlar, fn($r) => $r['doluluk'] !== null && $r['doluluk'] < 30 && $r['uygun_gun'] >= 14)),
        'dovizli_kiralama_haric' => $dovizli,
        'yontem' => 'Gelir: dönemle kesişen kira bedeli (KDV, nakliye ve operatör hariç; döviz kiralamalar hariç). '
                  . 'Maliyet: gider kayıtları + teknik servis + nakliye (bize) + amortisman (stok) veya tedarik bedeli (DK).',
    ];
    return ['ozet' => $ozet, 'makineler' => $satirlar];
}

function _anMakineOnerileri(array $r, int $winGun): array {
    $o = [];
    $dol = $r['doluluk'];
    $dolY = $dol === null ? '' : number_format((float)$dol, 1, ',', '.');
    if ($r['uygun_gun'] >= 14 && $dol !== null) {
        if ($dol < 30) {
            $o[] = ['kod' => 'dusuk_doluluk', 'seviye' => 'uyari', 'metin' => $r['dk']
                ? "DK makinesinin doluluğu %{$dolY} — tedarikçiye iade veya temin dönemini kısaltmak değerlendirilmeli."
                : "Doluluk %{$dolY} — fiyat, şube konumu veya transfer gözden geçirilmeli; uzun süre böyle kalırsa satış değerlendirilebilir."];
        } elseif ($dol >= 85) {
            $o[] = ['kod' => 'yuksek_talep', 'seviye' => 'firsat', 'metin' => "Doluluk %{$dolY} — talep yüksek; fiyat artışı veya benzer makine eklenmesi düşünülebilir."];
        }
    }
    if ($r['net'] < 0 && ($r['gelir'] > 0 || $r['toplam_maliyet'] > 0)) {
        $o[] = ['kod' => 'zarar', 'seviye' => 'kritik', 'metin' => $r['dk']
            ? 'Tedarik bedeli + giderler kira gelirini aşıyor (net ' . number_format($r['net'], 0, ',', '.') . ' ₺).'
            : 'Dönemde zarar ediyor (net ' . number_format($r['net'], 0, ',', '.') . ' ₺).'];
    }
    if ($r['gelir'] > 0 && $r['servis_maliyet'] > $r['gelir'] * 0.3) {
        $p = round($r['servis_maliyet'] / $r['gelir'] * 100);
        $o[] = ['kod' => 'servis_yuk', 'seviye' => 'uyari', 'metin' => "Servis maliyeti / gelir oranı %{$p} — sık arıza; yenileme veya satışla karşılaştırılmalı."];
    }
    if ($r['liste_fiyat_aylik'] > 0 && $r['gunluk_gelir'] !== null && $r['dolu_gun'] >= 7) {
        $hedefGunluk = $r['liste_fiyat_aylik'] / 30;
        if ($r['gunluk_gelir'] < $hedefGunluk * 0.8) {
            $p = round((1 - $r['gunluk_gelir'] / $hedefGunluk) * 100);
            $o[] = ['kod' => 'liste_alti', 'seviye' => 'uyari', 'metin' => "Ortalama günlük kira, liste fiyatının %{$p} altında."];
        }
    }
    return $o;
}

function analizMusteriRisk(PDO $db, ?array $musteriIdler = null, ?string $bugun = null): array {
    $bugun = $bugun ?: date('Y-m-d');
    $filtre = $musteriIdler === null ? null : array_flip(array_map('intval', $musteriIdler));
    $alacak = alacakOzeti($db, $musteriIdler, $bugun);
    $kalemler = cariBorcKalemleri($db);

    $tahs = [];
    foreach ($db->query("SELECT musteri_id, hakedis_id, tarih, tutar FROM tahsilatlar WHERE musteri_id IS NOT NULL ORDER BY tarih, id")->fetchAll() as $t)
        $tahs[(int)$t['musteri_id']][] = $t;

    $musteriler = [];
    foreach ($db->query("SELECT id, unvan, sube, temsilci, risk_limit, durum FROM musteriler")->fetchAll() as $m) $musteriler[(int)$m['id']] = $m;

    $cek = [];
    try {
        foreach ($db->query("SELECT musteri_id, durum, COUNT(*) n, COALESCE(SUM(tutar),0) t FROM cek_senet WHERE yon='alinan' AND musteri_id IS NOT NULL GROUP BY musteri_id, durum")->fetchAll() as $c)
            $cek[(int)$c['musteri_id']][$c['durum']] = ['n' => (int)$c['n'], 't' => (float)$c['t']];
    } catch (\Throwable $e) { hataGunlugu('analiz.cek_senet', $e, true); }

    $aktifKira = [];
    foreach ($db->query("SELECT musteri_id, COUNT(*) n FROM kiralamalar WHERE durum='aktif' AND gercek_bitis IS NULL GROUP BY musteri_id")->fetchAll() as $r) $aktifKira[(int)$r['musteri_id']] = (int)$r['n'];

    $out = [];
    foreach ($musteriler as $mid => $m) {
        if ($filtre !== null && !isset($filtre[$mid])) continue;
        $x = $kalemler[$mid] ?? ['borclar' => [], 'faturalanmamis' => []];
        $faturalar = array_values(array_filter($x['borclar'], fn($b) => (float)$b['tutar'] > 0));
        $faturasiz = round(array_sum(array_column($x['faturalanmamis'], 'tutar')), 2);
        $acik = (float)($alacak['musteri'][$mid] ?? 0);
        $ck = $cek[$mid] ?? [];
        if (!$faturalar && $faturasiz <= 0 && $acik <= 0 && !$ck && empty($aktifKira[$mid])) continue;

        $g = _anOdemeGecmisi($faturalar, $tahs[$mid] ?? [], $bugun);
        $karsiliksiz = $ck['karsiliksiz'] ?? ['n' => 0, 't' => 0.0];
        $portfoy = $ck['portfoyde'] ?? ['n' => 0, 't' => 0.0];
        $limit = (float)($m['risk_limit'] ?? 0);
        $maruz = $acik + $faturasiz;

        $skor = 0.0; $gerekce = [];
        if ($acik > 0 && $g['geciken_tutar'] > 0) {
            $oran = min(1.0, $g['geciken_tutar'] / $acik);
            $skor += 35 * $oran;
            $gerekce[] = 'Vadesi geçmiş pay %' . round($oran * 100) . ' (' . number_format($g['geciken_tutar'], 0, ',', '.') . ' ₺).';
        }
        if ($g['ort_gecikme'] !== null && $g['ort_gecikme'] > 0) {
            $skor += 25 * min(1.0, $g['ort_gecikme'] / 60);
            $gerekce[] = 'Faturaları vadeden ortalama ' . round($g['ort_gecikme']) . ' gün sonra ödüyor.';
        } elseif ($g['ort_gecikme'] !== null && $g['kapanan'] >= 2) {
            $gerekce[] = 'Kapanan ' . $g['kapanan'] . ' faturayı vadesinde ödemiş.';
        }
        if ($g['en_uzun_acik_gecikme'] >= 90) { $skor += 15; $gerekce[] = $g['en_uzun_acik_gecikme'] . ' gündür ödenmeyen fatura var.'; }
        elseif ($g['en_uzun_acik_gecikme'] >= 30) { $skor += 8; $gerekce[] = $g['en_uzun_acik_gecikme'] . ' gündür ödenmeyen fatura var.'; }
        if ($karsiliksiz['n'] > 0) {
            $skor += min(30, 20 * $karsiliksiz['n']);
            $gerekce[] = $karsiliksiz['n'] . ' karşılıksız çek/senet (' . number_format($karsiliksiz['t'], 0, ',', '.') . ' ₺).';
        }
        if ($limit > 0) {
            if ($maruz > $limit) { $skor += 15; $gerekce[] = 'Risk limiti aşılmış (' . number_format($maruz, 0, ',', '.') . ' / ' . number_format($limit, 0, ',', '.') . ' ₺).'; }
            elseif ($maruz > $limit * 0.8) { $skor += 7; $gerekce[] = 'Risk limiti kullanımı %' . round($maruz / $limit * 100) . '.'; }
        }
        $veriYetersiz = count($faturalar) === 0;
        $skor = (int)round(min(100, $skor));
        $seviye = $skor >= 50 ? 'yuksek' : ($skor >= 25 ? 'orta' : 'dusuk');
        if ($veriYetersiz && !$gerekce) $gerekce[] = 'Henüz faturalanmış işlem yok — ödeme davranışı ölçülemedi.';
        elseif (!$gerekce) $gerekce[] = $acik > 0 ? 'Açık alacak var ama vadesi geçmiş borç yok.' : 'Açık borç ve gecikme yok.';

        $out[] = [
            'musteri_id' => $mid, 'unvan' => $m['unvan'], 'sube' => $m['sube'], 'temsilci' => $m['temsilci'],
            'skor' => $skor, 'seviye' => $seviye, 'veri_yetersiz' => $veriYetersiz,
            'acik_alacak' => round($acik, 2), 'geciken' => round($g['geciken_tutar'], 2), 'faturalanmamis' => $faturasiz,
            'ort_gecikme_gun' => $g['ort_gecikme'] !== null ? round($g['ort_gecikme'], 1) : null,
            'en_uzun_acik_gecikme' => $g['en_uzun_acik_gecikme'],
            'fatura_adet' => count($faturalar), 'kapanan_fatura' => $g['kapanan'], 'gec_kapanan' => $g['gec_kapanan'],
            'karsiliksiz_cek' => $karsiliksiz['n'], 'portfoy_cek' => round($portfoy['t'], 2),
            'risk_limit' => $limit, 'aktif_kiralama' => $aktifKira[$mid] ?? 0,
            'son_odeme' => $g['son_odeme'],
            'gerekce' => $gerekce,
        ];
    }
    usort($out, fn($a, $b) => [$b['skor'], $b['acik_alacak']] <=> [$a['skor'], $a['acik_alacak']]);
    $ozet = ['yuksek' => 0, 'orta' => 0, 'dusuk' => 0, 'yuksek_acik' => 0.0];
    foreach ($out as $r) { $ozet[$r['seviye']]++; if ($r['seviye'] === 'yuksek') $ozet['yuksek_acik'] += $r['acik_alacak']; }
    $ozet['yuksek_acik'] = round($ozet['yuksek_acik'], 2);
    $ozet['yontem'] = 'Skor 0-100: vadesi geçmiş pay (35), ortalama ödeme gecikmesi (25), uzun süredir açık fatura (15), '
                    . 'karşılıksız çek/senet (30), risk limiti kullanımı (15). 50+ yüksek, 25-49 orta.';
    return ['ozet' => $ozet, 'musteriler' => $out];
}

function _anOdemeGecmisi(array $faturalar, array $tahsilatlar, string $bugun): array {
    usort($faturalar, fn($a, $b) => strcmp((string)$a['tarih'], (string)$b['tarih']));
    $kalan = []; $hidIdx = [];
    foreach ($faturalar as $i => $f) { $kalan[$i] = (float)$f['tutar']; if (!empty($f['hakedis_id'])) $hidIdx[(int)$f['hakedis_id']] = $i; }
    $kapanis = [];
    $kredi = 0.0; $sonOdeme = null;
    $uygula = function (float $tutar, string $tarih, ?int $hedef) use (&$kalan, &$kapanis, $faturalar) {
        if ($hedef !== null && isset($kalan[$hedef]) && $kalan[$hedef] > 0.009) {
            $d = min($tutar, $kalan[$hedef]); $kalan[$hedef] -= $d; $tutar -= $d;
            if ($kalan[$hedef] <= 0.009) $kapanis[$hedef] = max($tarih, (string)$faturalar[$hedef]['tarih']);
        }
        foreach ($faturalar as $i => $f) {
            if ($tutar <= 0.009) break;
            if ($kalan[$i] <= 0.009 || (string)$f['tarih'] > $tarih) continue;
            $d = min($tutar, $kalan[$i]); $kalan[$i] -= $d; $tutar -= $d;
            if ($kalan[$i] <= 0.009) $kapanis[$i] = $tarih;
        }
        return $tutar;
    };
    $olaylar = [];
    foreach ($faturalar as $i => $f) $olaylar[] = [(string)$f['tarih'], 0, 'f', $i];
    foreach ($tahsilatlar as $j => $t) $olaylar[] = [(string)$t['tarih'], 1, 't', $j];
    usort($olaylar, fn($a, $b) => [$a[0], $a[1], $a[3]] <=> [$b[0], $b[1], $b[3]]);
    foreach ($olaylar as [$tarih, , $tip, $idx]) {
        if ($tip === 'f') {
            if ($kredi > 0.009) $kredi = $uygula($kredi, $tarih, $idx);
            continue;
        }
        $t = $tahsilatlar[$idx];
        $sonOdeme = $tarih;
        $hid = (int)($t['hakedis_id'] ?? 0);
        $kredi += $uygula((float)$t['tutar'], $tarih, $hid && isset($hidIdx[$hid]) ? $hidIdx[$hid] : null);
    }
    $gTop = 0.0; $gAg = 0.0; $kapanan = 0; $gecKapanan = 0; $gecikenTutar = 0.0; $enUzun = 0;
    foreach ($faturalar as $i => $f) {
        $vade = $f['vade'] ?? null;
        if (!$vade) continue;
        $tutar = (float)$f['tutar'];
        if (isset($kapanis[$i])) {
            $gec = (int)((strtotime($kapanis[$i]) - strtotime($vade)) / 86400);
            $kapanan++; if ($gec > 0) $gecKapanan++;
            $gTop += max(0, $gec) * $tutar; $gAg += $tutar;
        } elseif ($vade < $bugun && $kalan[$i] > 0.009) {
            $gec = (int)((strtotime($bugun) - strtotime($vade)) / 86400);
            $gecikenTutar += $kalan[$i];
            $enUzun = max($enUzun, $gec);
            $gTop += $gec * $tutar; $gAg += $tutar;
        }
    }
    return [
        'ort_gecikme' => $gAg > 0 ? $gTop / $gAg : null,
        'kapanan' => $kapanan, 'gec_kapanan' => $gecKapanan,
        'geciken_tutar' => round($gecikenTutar, 2), 'en_uzun_acik_gecikme' => $enUzun,
        'son_odeme' => $sonOdeme,
    ];
}

function analizMusteriKapsami(PDO $db, ?string $sube): ?array {
    if ($sube === null || $sube === '') return null;
    $st = $db->prepare("SELECT id FROM musteriler WHERE sube = ?");
    $st->execute([$sube]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

function analizCiroTahsilat(PDO $db, string $bas, string $bit, ?string $sube): array {
    $musSube = [];
    foreach ($db->query("SELECT id, sube FROM musteriler")->fetchAll() as $r) $musSube[(int)$r['id']] = trim((string)$r['sube']);
    $ciro = 0.0; $adet = 0;
    $st = $db->prepare("SELECT * FROM kiralamalar WHERE durum<>'iptal' AND baslangic <= ?");
    $st->execute([$bit]);
    foreach ($st->fetchAll() as $k) {
        $sb = trim((string)($k['sube'] ?? '')) ?: ($musSube[(int)$k['musteri_id']] ?? '');
        if ($sube !== null && $sube !== '' && $sb !== $sube) continue;
        $c = kiralamaTutarPencereli($k, $bas, $bit);
        if ($c === null) continue;
        $ciro += $c;
        if ($k['baslangic'] >= $bas) $adet++;
    }
    $ts = $db->prepare("SELECT musteri_id, tutar FROM tahsilatlar WHERE tarih >= ? AND tarih <= ?");
    $ts->execute([$bas, $bit]);
    $tahsil = 0.0;
    foreach ($ts->fetchAll() as $r) {
        if ($sube !== null && $sube !== '' && ($musSube[(int)$r['musteri_id']] ?? '') !== $sube) continue;
        $tahsil += (float)$r['tutar'];
    }
    return ['ciro' => round($ciro, 2), 'tahsilat' => round($tahsil, 2), 'yeni_kiralama' => $adet];
}

function analizKpiPaketi(PDO $db, string $bas, string $bit, ?string $sube): array {
    [$oBas, $oBit] = analizOncekiDonem($bas, $bit);
    $simdi = analizCiroTahsilat($db, $bas, $bit, $sube);
    $once = analizCiroTahsilat($db, $oBas, $oBit, $sube);
    $kar = analizMakineKarlilik($db, $bas, $bit, $sube);
    $karOnce = analizMakineKarlilik($db, $oBas, $oBit, $sube);
    $kapsam = analizMusteriKapsami($db, $sube);
    $alacak = alacakOzeti($db, $kapsam);
    $risk = analizMusteriRisk($db, $kapsam);

    $degisim = fn($a, $b) => $b != 0 ? round(($a - $b) / abs($b) * 100, 1) : null;
    $kisa = fn($r) => [
        'makine' => $r['ad'], 'tur' => $r['tur'], 'sube' => $r['sube'], 'dk' => $r['dk'],
        'gelir' => $r['gelir'], 'maliyet' => $r['toplam_maliyet'], 'net' => $r['net'],
        'doluluk' => $r['doluluk'], 'bos_gun' => $r['bos_gun'],
        'oneriler' => array_column($r['oneriler'], 'metin'),
    ];
    $mk = $kar['makineler'];
    $enIyi = array_map($kisa, array_slice($mk, 0, 5));
    $enKotu = array_map($kisa, array_slice(array_reverse($mk), 0, 5));
    $dusuk = array_values(array_filter($mk, fn($r) => $r['doluluk'] !== null && $r['doluluk'] < 30 && $r['uygun_gun'] >= 14));
    usort($dusuk, fn($a, $b) => $b['bos_gun'] <=> $a['bos_gun']);

    $trend = [];
    $ay = new DateTimeImmutable(substr($bit, 0, 7) . '-01');
    for ($i = 5; $i >= 0; $i--) {
        $a = $ay->modify("-$i month");
        $ab = $a->format('Y-m-d'); $ae = $a->format('Y-m-t');
        $trend[] = ['ay' => $a->format('Y-m')] + analizCiroTahsilat($db, $ab, min($ae, date('Y-m-d')), $sube);
    }

    $gecIade = 0;
    foreach ($db->query("SELECT k.sube, k.musteri_id, m.sube msube FROM kiralamalar k LEFT JOIN musteriler m ON m.id=k.musteri_id
                         WHERE k.durum='aktif' AND k.gercek_bitis IS NULL AND k.bitis IS NOT NULL AND k.bitis < date('now','localtime')")->fetchAll() as $r) {
        $sb = trim((string)($r['sube'] ?? '')) ?: trim((string)($r['msube'] ?? ''));
        if ($sube !== null && $sube !== '' && $sb !== $sube) continue;
        $gecIade++;
    }
    $istisna = [];
    try {
        foreach ($db->query("SELECT kaynak_modul, COUNT(*) n FROM gorevler WHERE durum='acik' AND kaynak_modul IS NOT NULL AND kaynak_modul<>'' GROUP BY kaynak_modul")->fetchAll() as $r)
            $istisna[$r['kaynak_modul']] = (int)$r['n'];
    } catch (\Throwable $e) { hataGunlugu('analiz.istisna', $e, true); }

    return [
        'donem' => ['bas' => $bas, 'bit' => $bit, 'gun' => _anGunSayisi($bas, $bit), 'sube' => $sube ?: 'tüm şubeler'],
        'onceki_donem' => ['bas' => $oBas, 'bit' => $oBit],
        'ciro' => ['bu_donem' => $simdi['ciro'], 'onceki' => $once['ciro'], 'degisim_yuzde' => $degisim($simdi['ciro'], $once['ciro'])],
        'tahsilat' => ['bu_donem' => $simdi['tahsilat'], 'onceki' => $once['tahsilat'], 'degisim_yuzde' => $degisim($simdi['tahsilat'], $once['tahsilat'])],
        'yeni_kiralama' => ['bu_donem' => $simdi['yeni_kiralama'], 'onceki' => $once['yeni_kiralama']],
        'filo' => [
            'makine_adet' => $kar['ozet']['makine_adet'],
            'doluluk_yuzde' => $kar['ozet']['doluluk'], 'onceki_doluluk_yuzde' => $karOnce['ozet']['doluluk'],
            'bos_makine_gun' => $kar['ozet']['bos_makine_gun'],
            'net_kar' => $kar['ozet']['net'], 'onceki_net_kar' => $karOnce['ozet']['net'],
            'zarar_eden_makine' => $kar['ozet']['zarar_eden'], 'dusuk_doluluklu_makine' => $kar['ozet']['dusuk_doluluk'],
        ],
        'alacak' => ['acik_faturali' => $alacak['acik'], 'vadesi_gecmis' => $alacak['geciken'],
                     'vadesi_gecmis_fatura_adet' => $alacak['geciken_adet'], 'faturalanmamis_is' => $alacak['faturalanmamis']],
        'musteri_risk' => [
            'yuksek_riskli_adet' => $risk['ozet']['yuksek'], 'orta_riskli_adet' => $risk['ozet']['orta'],
            'yuksek_riskli_acik_alacak' => $risk['ozet']['yuksek_acik'],
            'en_riskli' => array_map(fn($r) => ['musteri' => $r['unvan'], 'skor' => $r['skor'], 'acik' => $r['acik_alacak'],
                'geciken' => $r['geciken'], 'gerekce' => $r['gerekce']], array_slice(array_filter($risk['musteriler'], fn($r) => $r['seviye'] !== 'dusuk'), 0, 5)),
        ],
        'en_karli_makineler' => $enIyi,
        'en_zayif_makineler' => $enKotu,
        'en_cok_bos_kalan' => array_map($kisa, array_slice($dusuk, 0, 5)),
        'aylik_trend' => $trend,
        'operasyon' => ['geciken_iade' => $gecIade, 'acik_istisna_gorevleri' => $istisna],
        'notlar' => [$kar['ozet']['yontem'], $risk['ozet']['yontem']],
    ];
}
