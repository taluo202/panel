<?php
declare(strict_types=1);
require_once __DIR__ . '/servis-kalem-lib.php';

const SM_TIPLER = ['stok' => 'Stoktan parça', 'dis' => 'Dışarıdan alınan parça', 'eksik' => 'Eksik parça', 'iscilik' => 'İşçilik', 'yol' => 'Yol / ulaşım', 'diger' => 'Diğer'];
const SM_KDV_ORANLARI = [0.0, 1.0, 10.0, 20.0];
const SM_FIYAT_TURU = ['stok' => 'parca', 'dis' => 'parca', 'eksik' => 'parca', 'iscilik' => 'iscilik', 'yol' => 'yol', 'diger' => 'diger'];
const SM_DEVIR_ACIKLAMA = 'Önceden elle girilen maliyet';
const SM_FIYAT_SUTUNLARI = ['musteri_tutar', 'musteri_ara_toplam', 'musteri_iskonto', 'musteri_kdv', 'musteri_genel_toplam', 'iskonto_tur', 'iskonto_deger'];
const SM_FATURALANABILIR_SQL = "s.durum='tamamlandi' AND COALESCE(s.fatura_yontemi,'')<>'hakedis'
    AND NOT EXISTS (SELECT 1 FROM hasar_degerlendirmeleri sm_h WHERE sm_h.servis_id=s.id AND COALESCE(sm_h.durum,'')<>'tasindi' AND (sm_h.depo_kontrol_id IS NOT NULL OR (sm_h.fiyat_durum IS NOT NULL AND sm_h.fiyat_durum<>'iptal')))
    AND (s.fiyat_durum='fiyatlandi' OR (s.fiyat_durum IS NULL AND COALESCE(s.maliyet_kaynak,'')<>'kalem' AND COALESCE(s.maliyet,0)>0 AND COALESCE(s.musteriye_yansit,0)=1))";
const SM_BEDEL = ['ayri' => 'Müşteriye yansır → ayrı servis faturası', 'hakedis' => 'Müşteriye yansır → hakedişe kalem olarak ekle', 'firma' => 'Firma karşılar (ücretsiz)'];

function smSema(PDO $db): void {
    $ekle = function (string $tablo, array $sutunlar) use ($db): void {
        $var = array_flip(array_column($db->query("PRAGMA table_info($tablo)")->fetchAll(PDO::FETCH_ASSOC), 'name'));
        foreach ($sutunlar as $ad => $tanim) if (!isset($var[$ad])) $db->exec("ALTER TABLE $tablo ADD COLUMN $ad $tanim");
    };
    $ekle('teknik_servis_kalemleri', ['kaynak' => 'TEXT', 'stok_kodu' => 'TEXT', 'tedarikci' => 'TEXT', 'birim_maliyet' => 'REAL']);
    $ekle('teknik_servis', ['maliyet_kaynak' => 'TEXT', 'iskonto_tur' => 'TEXT', 'iskonto_deger' => 'REAL', 'musteri_ara_toplam' => 'REAL',
                            'musteri_iskonto' => 'REAL', 'musteri_kdv' => 'REAL', 'musteri_genel_toplam' => 'REAL']);
    smEskiFiyatGoc($db);
}

function smEskiFiyatGoc(PDO $db): void {
    try {
        $q = $db->query("SELECT deger FROM ayarlar WHERE anahtar='sm_fiyat_toplam_goc'");
        if ($q && $q->fetchColumn() === '1') return;
        $ids = $db->query("SELECT id FROM teknik_servis WHERE fiyat_durum IN ('fiyatlandi','ucretsiz') AND musteri_genel_toplam IS NULL")->fetchAll(PDO::FETCH_COLUMN);
        $k = $db->prepare('SELECT miktar, birim_fiyat, kdv_oran FROM servis_fiyat_kalemleri WHERE servis_id=?');
        $u = $db->prepare('UPDATE teknik_servis SET musteri_ara_toplam=?, musteri_iskonto=0, musteri_kdv=?, musteri_genel_toplam=? WHERE id=?');
        foreach ($ids as $sid) {
            $s = $db->prepare('SELECT fiyat_durum, musteri_tutar FROM teknik_servis WHERE id=?'); $s->execute([(int)$sid]); $r = $s->fetch(PDO::FETCH_ASSOC);
            if (($r['fiyat_durum'] ?? '') === 'ucretsiz') { $u->execute([0, 0, 0, (int)$sid]); continue; }
            $k->execute([(int)$sid]);
            $t = smToplam($k->fetchAll(PDO::FETCH_ASSOC), null, null);
            $ara = round((float)($r['musteri_tutar'] ?? $t['ara']), 2);
            $u->execute([$ara, $t['kdv'], round($ara + $t['kdv'], 2), (int)$sid]);
        }
        $db->exec("INSERT OR REPLACE INTO ayarlar(anahtar, deger) VALUES('sm_fiyat_toplam_goc', '1')");
    } catch (\Throwable $e) { hataGunlugu('sema.sm_fiyat_goc', $e); }
}

function smToplam(array $satirlar, ?string $iskontoTur, $iskontoDeger): array {
    $net = []; $ara = 0.0;
    foreach (array_values($satirlar) as $i => $x) {
        $n = round((float)($x['miktar'] ?? 0) * (float)($x['birim_fiyat'] ?? 0), 2);
        $net[$i] = ['net' => $n, 'kdv_oran' => (float)($x['kdv_oran'] ?? 0)];
        $ara += $n;
    }
    $ara = round($ara, 2);
    $deger = max(0.0, (float)($iskontoDeger ?? 0));
    $iskonto = 0.0;
    if ($iskontoTur === 'oran') $iskonto = round($ara * min(100.0, $deger) / 100, 2);
    elseif ($iskontoTur === 'tutar') $iskonto = round(min($deger, $ara), 2);
    $matrah = round($ara - $iskonto, 2);
    $pay = []; $buyuk = null; $dagitilan = 0.0;
    foreach ($net as $i => $x) {
        $pay[$i] = $ara > 0 ? round($x['net'] * $matrah / $ara, 2) : 0.0;
        $dagitilan += $pay[$i];
        if ($buyuk === null || $x['net'] > $net[$buyuk]['net']) $buyuk = $i;
    }
    if ($buyuk !== null) $pay[$buyuk] = round($pay[$buyuk] + round($matrah - $dagitilan, 2), 2);
    $kdv = 0.0; $dokum = [];
    foreach ($net as $i => $x) {
        $v = round($pay[$i] * $x['kdv_oran'] / 100, 2);
        $kdv += $v;
        $a = (string)(float)$x['kdv_oran'];
        $dokum[$a] = ['oran' => $x['kdv_oran'], 'matrah' => round(($dokum[$a]['matrah'] ?? 0) + $pay[$i], 2), 'kdv' => round(($dokum[$a]['kdv'] ?? 0) + $v, 2)];
    }
    $kdv = round($kdv, 2);
    usort($dokum, fn($a, $b) => $b['oran'] <=> $a['oran']);
    return ['ara' => $ara, 'iskonto' => $iskonto, 'matrah' => $matrah, 'kdv' => $kdv, 'genel' => round($matrah + $kdv, 2),
            'satir_matrah' => array_values($pay), 'kdv_dokum' => array_values($dokum)];
}

function smAyarUcret(PDO $db, string $anahtar): float {
    try {
        $q = $db->prepare('SELECT deger FROM ayarlar WHERE anahtar=?'); $q->execute([$anahtar]);
        return max(0.0, (float)str_replace(',', '.', (string)$q->fetchColumn()));
    } catch (\Throwable $e) { return 0.0; }
}

function smKalemTipi(array $k): string {
    if (($k['kaynak'] ?? '') === 'eksik') return 'eksik';
    if (!empty($k['parca_id'])) return 'stok';
    $tur = (string)($k['tur'] ?? '');
    if (in_array($tur, ['iscilik', 'yol', 'diger'], true)) return $tur;
    return 'dis';
}

function smKalemZengin(array $r, array $ayar): array {
    $tip = smKalemTipi($r);
    $miktar = $tip === 'iscilik' ? round((float)($r['saat'] ?? 0) * max(1, (int)($r['kisi'] ?? 1)), 3) : round((float)($r['miktar'] ?? 0), 3);
    $bm = $r['birim_maliyet'];
    if ($bm === null && $tip === 'stok' && $r['hareket_birim'] !== null) $bm = $r['hareket_birim'];
    $bm = $bm === null ? null : round((float)$bm, 4);
    $kart = (float)($r['kart_fiyat'] ?? 0);
    $varsayilan = match ($tip) {
        'stok' => $kart > 0 ? $kart : (float)($bm ?? 0),
        'iscilik' => $ayar['saat'] > 0 ? $ayar['saat'] : (float)($bm ?? 0),
        'yol' => $ayar['yol'] > 0 ? $ayar['yol'] : (float)($bm ?? 0),
        default => (float)($bm ?? 0),
    };
    $etiket = ($r['kaynak'] ?? '') === 'devir' ? SM_DEVIR_ACIKLAMA : SM_TIPLER[$tip];
    return [
        'id' => (int)$r['id'], 'tip' => $tip, 'tip_etiket' => $etiket, 'tur' => $r['tur'], 'kaynak' => $r['kaynak'], 'aciklama' => (string)$r['aciklama'],
        'parca_id' => $r['parca_id'] !== null ? (int)$r['parca_id'] : null, 'parca_no' => $r['parca_no'], 'stok_kodu' => $r['stok_kodu'], 'tedarikci' => $r['tedarikci'],
        'miktar' => $miktar, 'birim' => $tip === 'iscilik' ? 'saat' : ((string)($r['birim'] ?? '') ?: 'adet'),
        'saat' => $r['saat'] !== null ? (float)$r['saat'] : null, 'kisi' => $r['kisi'] !== null ? (int)$r['kisi'] : null,
        'birim_maliyet' => $bm, 'maliyet_girildi' => $bm !== null, 'maliyet_tutar' => round($miktar * (float)($bm ?? 0), 2),
        'varsayilan_fiyat' => round($varsayilan, 4), 'stok_hareket_id' => $r['stok_hareket_id'] !== null ? (int)$r['stok_hareket_id'] : null, 'hasar_kalem_id' => $r['hasar_kalem_id'] !== null ? (int)$r['hasar_kalem_id'] : null,
        'servis_bulgu_id' => ($r['servis_bulgu_id'] ?? null) !== null ? (int)$r['servis_bulgu_id'] : null,
        'yapan' => $r['yapan'], 'created_at' => $r['created_at'],
    ];
}

function smKalemler(PDO $db, int $sid): array {
    $q = $db->prepare('SELECT k.id, k.tur, k.kaynak, k.aciklama, k.parca_id, p.parca_no, p.fiyat AS kart_fiyat, k.stok_kodu, k.tedarikci, k.miktar, k.birim,
                              k.saat, k.kisi, k.birim_maliyet, h.birim_fiyat AS hareket_birim, k.stok_hareket_id, k.hasar_kalem_id, k.servis_bulgu_id, k.yapan, k.created_at
                       FROM teknik_servis_kalemleri k LEFT JOIN yedek_parca p ON p.id=k.parca_id LEFT JOIN stok_hareket h ON h.id=k.stok_hareket_id
                       WHERE k.servis_id=? ORDER BY k.id');
    $q->execute([$sid]);
    $ayar = ['saat' => smAyarUcret($db, 'servis_saat_ucreti'), 'yol' => smAyarUcret($db, 'servis_yol_ucreti')];
    return array_map(fn($r) => smKalemZengin($r, $ayar), $q->fetchAll(PDO::FETCH_ASSOC));
}

function smBagsizHareketler(PDO $db, int $sid): array {
    $q = $db->prepare("SELECT h.id, h.parca_id, h.miktar, h.birim_fiyat, p.ad, p.parca_no, p.fiyat AS kart_fiyat FROM stok_hareket h LEFT JOIN yedek_parca p ON p.id=h.parca_id
                       WHERE h.servis_id=? AND h.kaynak='servis' AND COALESCE(h.tip,'cikis')='cikis'
                         AND NOT EXISTS (SELECT 1 FROM teknik_servis_kalemleri k WHERE k.stok_hareket_id=h.id) ORDER BY h.id");
    $q->execute([$sid]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function smMaliyetHesapla(PDO $db, int $sid): float {
    $t = 0.0;
    foreach (smKalemler($db, $sid) as $k) $t += $k['maliyet_tutar'];
    foreach (smBagsizHareketler($db, $sid) as $h) $t += round((float)$h['miktar'] * (float)$h['birim_fiyat'], 2);
    return round($t, 2);
}

function smEskiKayitMi(array $s): bool { return ($s['maliyet_kaynak'] ?? '') !== 'kalem'; }

function smDevirTutari(PDO $db, array $s): float {
    if (!smEskiKayitMi($s)) return 0.0;
    return max(0.0, round((float)($s['maliyet'] ?? 0) - smMaliyetHesapla($db, (int)$s['id']), 2));
}

function smDonustur(PDO $db, int $sid): bool {
    $s = tsServis($db, $sid);
    if (!smEskiKayitMi($s)) return false;
    $simdi = date('Y-m-d H:i:s');
    $yapan = 'Sistem (maliyet aktarımı)';
    $ins = $db->prepare('INSERT INTO teknik_servis_kalemleri(servis_id,tur,kaynak,aciklama,parca_id,miktar,birim,birim_maliyet,stok_hareket_id,yapan,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    $aktarilan = 0;
    foreach (smBagsizHareketler($db, $sid) as $h) {
        $ad = trim((string)($h['ad'] ?? '')) ?: 'Parça';
        $ins->execute([$sid, 'malzeme', 'stok', $ad . (trim((string)($h['parca_no'] ?? '')) !== '' ? ' (' . trim((string)$h['parca_no']) . ')' : ''),
                       (int)$h['parca_id'], (float)$h['miktar'], 'adet', (float)$h['birim_fiyat'], (int)$h['id'], $yapan, $simdi]);
        $aktarilan++;
    }
    $eski = round((float)($s['maliyet'] ?? 0), 2);
    $fark = round($eski - smMaliyetHesapla($db, $sid), 2);
    if ($fark > 0.004) $ins->execute([$sid, 'diger', 'devir', SM_DEVIR_ACIKLAMA, null, 1, 'adet', $fark, null, $yapan, $simdi]);
    $yeni = smMaliyetHesapla($db, $sid);
    if (abs($yeni - $eski) > 0.004) $db->prepare('UPDATE teknik_servis SET maliyet_kaynak=?, maliyet=?, updated_at=? WHERE id=?')->execute(['kalem', $yeni, $simdi, $sid]);
    else $db->prepare('UPDATE teknik_servis SET maliyet_kaynak=? WHERE id=?')->execute(['kalem', $sid]);
    logla($db, 'teknik_servis', $sid, 'guncelle', ['maliyet' => $eski, 'maliyet_kaynak' => $s['maliyet_kaynak'] ?? null], ['maliyet' => $yeni, 'maliyet_kaynak' => 'kalem'],
          'Servis maliyeti satırlara aktarıldı — servis #' . $sid . ' · ' . $aktarilan . ' parça sarfı satıra bağlandı'
          . ($fark > 0.004 ? ' · ' . number_format($fark, 2, ',', '.') . ' ₺ "' . SM_DEVIR_ACIKLAMA . '" satırına yazıldı' : ''));
    return true;
}

function smMaliyetYenile(PDO $db, int $sid): ?float {
    $q = $db->prepare('SELECT id, maliyet, maliyet_kaynak FROM teknik_servis WHERE id=?'); $q->execute([$sid]);
    $s = $q->fetch(PDO::FETCH_ASSOC);
    if (!$s || smEskiKayitMi($s)) return null;
    $yeni = smMaliyetHesapla($db, $sid);
    if (abs($yeni - round((float)($s['maliyet'] ?? 0), 2)) > 0.004)
        $db->prepare('UPDATE teknik_servis SET maliyet=?, updated_at=? WHERE id=?')->execute([$yeni, date('Y-m-d H:i:s'), $sid]);
    return $yeni;
}

function smHasarBagi(PDO $db, int $sid): ?int {
    try {
        $q = $db->prepare("SELECT id FROM hasar_degerlendirmeleri WHERE servis_id=? AND COALESCE(durum,'')<>'tasindi' AND (depo_kontrol_id IS NOT NULL OR (fiyat_durum IS NOT NULL AND fiyat_durum<>'iptal')) ORDER BY id LIMIT 1");
        $q->execute([$sid]);
        $h = $q->fetchColumn();
        return $h !== false ? (int)$h : null;
    } catch (\Throwable $e) { return null; }
}

function smAkis(PDO $db, array $s): string {
    if (smHasarBagi($db, (int)$s['id'])) return 'hasar';
    if (tsDonusKaynakli($db, $s)) return 'donus';
    if (!empty($s['musteri_makine_id'])) return 'hizmet';
    return (int)($s['musteriye_yansit'] ?? 0) === 1 ? 'yansit' : 'ic';
}

function smFiyatSatirlari(PDO $db, int $sid): array {
    $q = $db->prepare('SELECT id, tur, aciklama, miktar, birim, birim_fiyat, kdv_oran, kaynak_kalem_id FROM servis_fiyat_kalemleri WHERE servis_id=? ORDER BY id');
    $q->execute([$sid]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function smFiyatBaglam(PDO $db, array $s, array $kalemler): array {
    $sid = (int)$s['id'];
    $kayitli = smFiyatSatirlari($db, $sid);
    $kalemIdleri = array_column($kalemler, 'id');
    $bagli = []; $ek = [];
    foreach ($kayitli as $f) {
        $kk = $f['kaynak_kalem_id'] !== null ? (int)$f['kaynak_kalem_id'] : 0;
        if ($kk && in_array($kk, $kalemIdleri, true) && !isset($bagli[$kk])) $bagli[$kk] = $f;
        else $ek[] = ['fiyat_id' => (int)$f['id'], 'tur' => $f['tur'], 'aciklama' => $f['aciklama'], 'miktar' => (float)$f['miktar'], 'birim' => $f['birim'],
                      'birim_fiyat' => (float)$f['birim_fiyat'], 'kdv_oran' => (float)$f['kdv_oran']];
    }
    $satirlar = [];
    $eksik = false;
    foreach ($kalemler as $k) {
        if ($k['miktar'] <= 0) continue;
        $f = $bagli[$k['id']] ?? null;
        if (!$f) $eksik = true;
        $satirlar[] = ['kalem_id' => $k['id'], 'birim_fiyat' => $f ? (float)$f['birim_fiyat'] : $k['varsayilan_fiyat'], 'kdv_oran' => $f ? (float)$f['kdv_oran'] : 20.0, 'kayitli' => (bool)$f];
    }
    $fd = $s['fiyat_durum'] ?? null;
    $hq = $db->prepare("SELECT h.id, h.kod, h.fatura_no, h.durum, (SELECT COUNT(*) FROM tahsilatlar t WHERE t.hakedis_id=h.id) AS tahsilat FROM hakedisler h WHERE h.id=?");
    $hq->execute([(int)($s['hakedis_id'] ?? 0)]);
    $hk = $hq->fetch(PDO::FETCH_ASSOC);
    require_once __DIR__ . '/hasar-fiyat-lib.php';
    return [
        'fiyat_durum' => $fd, 'iskonto_tur' => $s['iskonto_tur'] ?? null, 'iskonto_deger' => $s['iskonto_deger'] !== null ? (float)$s['iskonto_deger'] : null,
        'bedel' => $fd === 'ucretsiz' ? 'firma' : (($s['fatura_yontemi'] ?? null) === 'hakedis' ? 'hakedis' : ($fd === 'fiyatlandi' ? 'ayri' : null)),
        'uygun_hakedisler' => smUygunHakedisler($db, $s),
        'hakedis' => $hk ? ['id' => (int)$hk['id'], 'kod' => $hk['kod'], 'faturali' => hfHakedisFaturali($hk)] : null,
        'satirlar' => $satirlar, 'ek' => $ek, 'degisti' => $fd === 'fiyatlandi' && $eksik,
        'kayitli_toplam' => in_array($fd, ['fiyatlandi', 'ucretsiz'], true) ? [
            'ara' => (float)($s['musteri_ara_toplam'] ?? $s['musteri_tutar'] ?? 0), 'iskonto' => (float)($s['musteri_iskonto'] ?? 0),
            'matrah' => (float)($s['musteri_tutar'] ?? 0), 'kdv' => (float)($s['musteri_kdv'] ?? 0),
            'genel' => (float)($s['musteri_genel_toplam'] ?? $s['musteri_tutar'] ?? 0)] : null,
        'ayar' => ['saat_ucreti' => smAyarUcret($db, 'servis_saat_ucreti'), 'yol_ucreti' => smAyarUcret($db, 'servis_yol_ucreti')],
    ];
}

function smMasrafBaglam(PDO $db, int $sid, bool $fiyatYetki, bool $masrafYetki): array {
    $s = tsServis($db, $sid);
    $kalemler = smKalemler($db, $sid);
    $eski = smEskiKayitMi($s);
    $akis = smAkis($db, $s);
    $mq = $db->prepare('SELECT m.unvan FROM teknik_servis s LEFT JOIN kiralamalar k ON k.id=s.kiralama_id LEFT JOIN musteriler m ON m.id=COALESCE(s.musteri_id, k.musteri_id) WHERE s.id=?');
    $mq->execute([$sid]);
    $musteri = $mq->fetchColumn() ?: null;
    $baskaSube = false;
    if (subeKisitiHam() !== null) {
        require_once __DIR__ . '/saha-servis-lib.php';
        try { ssFiyatSubeDogrula($db, $sid); } catch (DomainException $e) { $baskaSube = $musteri !== null; $musteri = null; }
    }
    $parcalar =$db->query('SELECT id, ad, parca_no, stok, birim FROM yedek_parca WHERE COALESCE(aktif,1)=1 ORDER BY ad')->fetchAll(PDO::FETCH_ASSOC);
    $out = [
        'servis' => ['id' => $sid, 'durum' => $s['durum'], 'faturalandi' => (int)($s['faturalandi'] ?? 0) === 1, 'maliyet' => round((float)($s['maliyet'] ?? 0), 2),
                     'eski_kayit' => $eski, 'musteriye_yansit' => (int)($s['musteriye_yansit'] ?? 0) === 1, 'hizmet' => !empty($s['musteri_makine_id']),
                     'akis' => $akis, 'hasar_id' => $akis === 'hasar' ? smHasarBagi($db, $sid) : null, 'musteri' => $musteri, 'musteri_baska_sube' => $baskaSube, 'ariza_kaynak' => $s['ariza_kaynak'] ?? null,
                     'fiyat_durum' => $s['fiyat_durum'] ?? null],
        'kalemler' => array_map(function ($k) use ($fiyatYetki) { if (!$fiyatYetki) unset($k['varsayilan_fiyat']); return $k; }, $kalemler),
        'devir' => smDevirTutari($db, $s),
        'bagsiz_parca' => array_map(fn($h) => ['hareket_id' => (int)$h['id'], 'ad' => $h['ad'], 'parca_no' => $h['parca_no'], 'miktar' => (float)$h['miktar'], 'birim_maliyet' => (float)$h['birim_fiyat']]
            + ($fiyatYetki ? ['varsayilan_fiyat' => (float)$h['kart_fiyat'] > 0 ? (float)$h['kart_fiyat'] : (float)$h['birim_fiyat']] : []), smBagsizHareketler($db, $sid)),
        'toplam_maliyet' => $eski ? round((float)($s['maliyet'] ?? 0), 2) : smMaliyetHesapla($db, $sid),
        'parcalar' => array_map(fn($p) => ['id' => (int)$p['id'], 'ad' => $p['ad'], 'parca_no' => $p['parca_no'], 'stok' => (int)$p['stok'], 'birim' => $p['birim']], $parcalar),
        'yetki' => ['masraf' => $masrafYetki, 'fiyat' => $fiyatYetki],
        'fiyat' => null,
    ];
    if ($fiyatYetki && in_array($akis, ['hizmet', 'yansit', 'ic', 'donus'], true)) $out['fiyat'] = smFiyatBaglam($db, $s, $kalemler);
    if (!$fiyatYetki) {
        $out['kalemler'] = array_map('smMaliyetSil', $out['kalemler']);
        $out['bagsiz_parca'] = array_map('smMaliyetSil', $out['bagsiz_parca']);
        unset($out['toplam_maliyet'], $out['devir']);
        unset($out['servis']['maliyet']);
    }
    return $out;
}

function _smSayi($v, string $ad, float $enCok = 1000000000.0, bool $sifirOlur = true): float {
    $s = is_string($v) ? preg_replace('/\s+/u', '', $v) : $v;
    if (is_string($s) && str_contains($s, ',')) $s = str_replace(',', '.', str_replace('.', '', $s));
    if (!is_numeric($s) || !is_finite((float)$s)) throw new DomainException($ad . ' sayı olmalı.');
    $n = (float)$s;
    if ($n < 0 || (!$sifirOlur && $n <= 0)) throw new DomainException($ad . ($sifirOlur ? ' negatif olamaz.' : ' sıfırdan büyük olmalı.'));
    if ($n > $enCok) throw new DomainException($ad . ' çok büyük.');
    return $n;
}

function smBirimMaliyetOku(array $b): ?float {
    if (!array_key_exists('birim_maliyet', $b) || $b['birim_maliyet'] === null || trim((string)$b['birim_maliyet']) === '') return null;
    return round(_smSayi($b['birim_maliyet'], 'Birim maliyet'), 4);
}

function smStokMaliyeti(array $p): float {
    $alis = (float)($p['alis_fiyati'] ?? 0);
    return round($alis > 0 ? $alis : (float)($p['fiyat'] ?? 0), 4);
}

function smFaturaKilidi(array $s, string $islem): void {
    if ((int)($s['faturalandi'] ?? 0) === 1) throw new DomainException('Servis faturalandırıldı; ' . $islem . ' yapılamaz. Önce Faturalandırma ekranında fatura işaretini kaldırın.');
}

function smKilitDenetle(array $s, string $tur, string $islem): void {
    smFaturaKilidi($s, 'satır ' . $islem);
    if (($s['durum'] ?? '') === 'tamamlandi' && !in_array($tur, ['yol', 'diger'], true))
        throw new DomainException('Servis tamamlandı; "Yapılan iş" satırları artık değiştirilemez (yol ve diğer masraf satırları eklenebilir).');
}

function smFiyatYenidenBekle(PDO $db, int $sid): void {
    $q = $db->prepare("UPDATE teknik_servis SET fiyat_durum='bekliyor' WHERE id=? AND fiyat_durum='fiyatlandi'");
    $q->execute([$sid]);
    if ($q->rowCount()) logla($db, 'teknik_servis', $sid, 'guncelle', ['fiyat_durum' => 'fiyatlandi'], ['fiyat_durum' => 'bekliyor'], 'Masraf satırı değişti; servis #' . $sid . ' yeniden fiyatlandırılmalı');
}

function smToplamYaz(PDO $db, int $sid, bool $logla = true): array {
    $s = tsServis($db, $sid);
    $fd = $s['fiyat_durum'] ?? null;
    if ($fd === 'ucretsiz') $t = ['ara' => 0.0, 'iskonto' => 0.0, 'matrah' => 0.0, 'kdv' => 0.0, 'genel' => 0.0];
    else $t = smToplam(smFiyatSatirlari($db, $sid), $s['iskonto_tur'] ?? null, $s['iskonto_deger'] ?? null);
    $db->prepare('UPDATE teknik_servis SET musteri_ara_toplam=?, musteri_iskonto=?, musteri_tutar=?, musteri_kdv=?, musteri_genel_toplam=? WHERE id=?')
       ->execute([$t['ara'], $t['iskonto'], $t['matrah'], $t['kdv'], $t['genel'], $sid]);
    if ($logla && abs((float)($s['musteri_genel_toplam'] ?? 0) - $t['genel']) > 0.004)
        logla($db, 'teknik_servis', $sid, 'guncelle', ['musteri_genel_toplam' => $s['musteri_genel_toplam'] ?? null], ['musteri_genel_toplam' => $t['genel']], 'Servis #' . $sid . ' müşteri toplamı yeniden hesaplandı');
    return $t;
}

function smKalemSilindi(PDO $db, int $sid, int $kalemId): void {
    $q = $db->prepare('DELETE FROM servis_fiyat_kalemleri WHERE servis_id=? AND kaynak_kalem_id=?');
    $q->execute([$sid, $kalemId]);
    $s = tsServis($db, $sid);
    if ($q->rowCount() && in_array($s['fiyat_durum'] ?? '', ['fiyatlandi', 'ucretsiz'], true)) smToplamYaz($db, $sid);
}

function smMasrafKaydet(PDO $db, int $sid, array $kalemler): array {
    $s = tsServis($db, $sid);
    smFaturaKilidi($s, 'maliyet değişikliği');
    $oncekiMaliyet = round((float)($s['maliyet'] ?? 0), 2);
    smDonustur($db, $sid);
    $mevcut = [];
    foreach (smKalemler($db, $sid) as $k) $mevcut[$k['id']] = $k;
    $guncelle = $db->prepare('UPDATE teknik_servis_kalemleri SET birim_maliyet=?, stok_kodu=?, tedarikci=? WHERE id=? AND servis_id=?');
    $degisen = 0;
    foreach ($kalemler as $b) {
        if (!is_array($b)) continue;
        $id = (int)($b['id'] ?? 0);
        if (!isset($mevcut[$id])) throw new DomainException('Satır bu servise ait değil.');
        $k = $mevcut[$id];
        $bm = array_key_exists('birim_maliyet', $b) && smFiyatYetkisi($db) ? smBirimMaliyetOku($b) : $k['birim_maliyet'];
        $kod = $k['stok_kodu']; $ted = $k['tedarikci'];
        if ($k['tip'] === 'dis') {
            if (array_key_exists('stok_kodu', $b)) $kod = mb_substr(trim(metin($b['stok_kodu'] ?? '')), 0, 60) ?: null;
            if (array_key_exists('tedarikci', $b)) $ted = mb_substr(trim(metin($b['tedarikci'] ?? '')), 0, 120) ?: null;
        }
        $eskiBm = $k['birim_maliyet'];
        if (($bm === null) === ($eskiBm === null) && abs((float)$bm - (float)$eskiBm) < 0.00005 && $kod === $k['stok_kodu'] && $ted === $k['tedarikci']) continue;
        $guncelle->execute([$bm, $kod, $ted, $id, $sid]);
        $degisen++;
        logla($db, 'teknik_servis_kalemleri', $id, 'guncelle', ['birim_maliyet' => $eskiBm, 'stok_kodu' => $k['stok_kodu'], 'tedarikci' => $k['tedarikci']],
              ['birim_maliyet' => $bm, 'stok_kodu' => $kod, 'tedarikci' => $ted], 'Masraf satırı maliyeti güncellendi — servis #' . $sid . ' · ' . $k['aciklama']);
    }
    $maliyet = smMaliyetYenile($db, $sid);
    if ($degisen) logla($db, 'teknik_servis', $sid, 'guncelle', ['maliyet' => $oncekiMaliyet], ['maliyet' => $maliyet],
          'Servis #' . $sid . ' iç maliyeti düzeltildi (' . $degisen . ' satır) · ' . number_format($oncekiMaliyet, 2, ',', '.') . ' ₺ → ' . number_format((float)$maliyet, 2, ',', '.') . ' ₺');
    return ['degisen' => $degisen, 'maliyet' => $maliyet];
}

function smFiyatKaydet(PDO $db, int $sid, array $b): array {
    $s = tsServis($db, $sid);
    if ((int)($s['faturalandi'] ?? 0) === 1) throw new DomainException('Faturalandırılmış servis yeniden fiyatlandırılamaz; önce fatura işaretini kaldırın.');
    if ($hid = smHasarBagi($db, $sid))
        throw new DomainException('Bu servisin müşteri bedeli hasar faturasıyla alınır (Hasar #' . $hid . '); burada fiyatlandırılmaz.');
    $bedel = array_key_exists('bedel', $b) ? (string)$b['bedel'] : (!empty($b['ucretsiz']) ? 'firma' : 'ayri');
    if (!isset(SM_BEDEL[$bedel])) throw new DomainException('Bedel kararını seçin: ayrı servis faturası, hakedişe ekle ya da firma karşılar.');
    $hakedisId = $bedel === 'hakedis' ? (int)($b['hakedis_id'] ?? 0) : 0;
    if ($bedel === 'hakedis' && $hakedisId < 1) throw new DomainException('Hakediş seçin: servis bedeli hangi hakedişe eklenecek?');
    smHakedisBagKaldir($db, $s);
    $s = tsServis($db, $sid);
    $eskiKayit = logKayitAl($db, 'teknik_servis', $sid);
    if (array_key_exists('musteriye_yansit', $b) && empty($s['musteri_makine_id'])) {
        $y = !empty($b['musteriye_yansit']) ? 1 : 0;
        if ($y !== (int)($s['musteriye_yansit'] ?? 0)) {
            $db->prepare('UPDATE teknik_servis SET musteriye_yansit=? WHERE id=?')->execute([$y, $sid]);
            $s['musteriye_yansit'] = $y;
        }
    }
    if (smAkis($db, $s) === 'ic') throw new DomainException('Müşteriye yansıtılmayan servis fiyatlandırılmaz; önce "Müşteriye yansıt" işaretleyin.');
    smDonustur($db, $sid);
    $s = tsServis($db, $sid);
    $ucretsiz = $bedel === 'firma';
    $kalemler = smKalemler($db, $sid);
    $gelen = [];
    foreach (is_array($b['satirlar'] ?? null) ? $b['satirlar'] : [] as $x) {
        if (!is_array($x)) continue;
        $anahtar = !empty($x['devir']) ? 'devir' : ((int)($x['hareket_id'] ?? 0) > 0 ? 'h' . (int)$x['hareket_id'] : (int)($x['kalem_id'] ?? 0));
        if (is_int($anahtar) && $anahtar < 1) continue;
        $gelen[$anahtar] = $x;
    }
    $kdvOku = function ($v): float {
        $n = is_numeric(str_replace(',', '.', (string)($v ?? 20))) ? (float)str_replace(',', '.', (string)($v ?? 20)) : -1;
        if (!in_array($n, SM_KDV_ORANLARI, true)) throw new DomainException('KDV oranı %0, %1, %10 ya da %20 olmalı.');
        return $n;
    };
    $yaz = [];
    foreach ($kalemler as $k) {
        if ($k['miktar'] <= 0) continue;
        $x = $gelen[$k['id']] ?? (($k['kaynak'] ?? '') === 'devir' ? ($gelen['devir'] ?? null) : ($k['stok_hareket_id'] ? ($gelen['h' . $k['stok_hareket_id']] ?? null) : null));
        $fiyat = $x !== null && array_key_exists('birim_fiyat', $x) && trim((string)$x['birim_fiyat']) !== '' ? round(_smSayi($x['birim_fiyat'], 'Müşteri birim fiyatı'), 4) : $k['varsayilan_fiyat'];
        $kdv = $x !== null && array_key_exists('kdv_oran', $x) ? $kdvOku($x['kdv_oran']) : 20.0;
        $aciklama = $k['tip'] === 'iscilik' ? 'İşçilik · ' . $k['aciklama'] : $k['aciklama'];
        $yaz[] = [$sid, SM_FIYAT_TURU[$k['tip']], mb_substr($aciklama, 0, 300), $k['miktar'], $k['birim'], $fiyat, $kdv, $k['id']];
    }
    foreach (is_array($b['ek'] ?? null) ? $b['ek'] : [] as $x) {
        if (!is_array($x)) continue;
        $tur = (string)($x['tur'] ?? 'diger');
        if (!isset(SS_FIYAT_TURLERI[$tur])) throw new DomainException('Geçersiz satır türü.');
        $miktar = _smSayi($x['miktar'] ?? 0, 'Miktar', 100000, false);
        $fiyat = round(_smSayi($x['birim_fiyat'] ?? 0, 'Müşteri birim fiyatı'), 4);
        $yaz[] = [$sid, $tur, mb_substr(trim(metin($x['aciklama'] ?? '')), 0, 300) ?: SS_FIYAT_TURLERI[$tur], round($miktar, 3), trim(metin($x['birim'] ?? '')) ?: null, $fiyat, $kdvOku($x['kdv_oran'] ?? 20), null];
    }
    if (!$ucretsiz && !$yaz) throw new DomainException('En az bir masraf satırı girin ya da "Ücretsiz" işaretleyin.');
    $iskTur = in_array($b['iskonto_tur'] ?? null, ['oran', 'tutar'], true) ? $b['iskonto_tur'] : null;
    $iskDeger = $iskTur ? round(_smSayi($b['iskonto_deger'] ?? 0, 'İndirim'), 2) : null;
    if ($iskTur && $iskDeger <= 0) { $iskTur = null; $iskDeger = null; }
    if ($iskTur === 'oran' && $iskDeger > 100) throw new DomainException('İndirim oranı %100\'ü aşamaz.');
    $hesap = smToplam(array_map(fn($r) => ['miktar' => $r[3], 'birim_fiyat' => $r[5], 'kdv_oran' => $r[6]], $yaz), $iskTur, $iskDeger);
    if ($iskTur === 'tutar' && $iskDeger > $hesap['ara'] + 0.004) throw new DomainException('İndirim tutarı müşteri ara toplamını aşamaz.');
    $db->prepare('DELETE FROM servis_fiyat_kalemleri WHERE servis_id=?')->execute([$sid]);
    $ins = $db->prepare('INSERT INTO servis_fiyat_kalemleri (servis_id, tur, aciklama, miktar, birim, birim_fiyat, kdv_oran, kaynak_kalem_id) VALUES (?,?,?,?,?,?,?,?)');
    foreach ($yaz as $r) $ins->execute($r);
    $durum = $ucretsiz ? 'ucretsiz' : 'fiyatlandi';
    $t = $ucretsiz ? ['ara' => 0.0, 'iskonto' => 0.0, 'matrah' => 0.0, 'kdv' => 0.0, 'genel' => 0.0] : $hesap;
    $yontem = $bedel === 'firma' ? 'yok' : $bedel;
    $db->prepare("UPDATE teknik_servis SET fiyat_durum=?, fatura_yontemi=?, iskonto_tur=?, iskonto_deger=?, musteri_ara_toplam=?, musteri_iskonto=?, musteri_tutar=?, musteri_kdv=?, musteri_genel_toplam=?,
                    updated_at=datetime('now','localtime') WHERE id=?")
       ->execute([$durum, $yontem, $iskTur, $iskDeger, $t['ara'], $t['iskonto'], $t['matrah'], $t['kdv'], $t['genel'], $sid]);
    if ($bedel === 'hakedis') smHakedisBagla($db, tsServis($db, $sid), $hakedisId, $t, array_map(fn($r) => ['kdv_oran' => $r[6]], $yaz));
    $db->prepare("UPDATE gorevler SET durum='tamamlandi', tamamlanma=?, updated_at=datetime('now','localtime') WHERE kaynak_modul='servis_fiyat' AND kaynak_id=? AND durum='acik'")
       ->execute([date('Y-m-d'), $sid]);
    $maliyet = round((float)tsServis($db, $sid)['maliyet'], 2);
    logla($db, 'teknik_servis', $sid, 'guncelle', $eskiKayit, logKayitAl($db, 'teknik_servis', $sid),
          $ucretsiz ? 'Servis #' . $sid . ' ücretsiz işaretlendi (garanti / iyi niyet)'
                    : 'Servis #' . $sid . ' fiyatlandırıldı · müşteri ' . number_format($t['genel'], 2, ',', '.') . ' ₺ (KDV dahil' . ($t['iskonto'] > 0 ? ', indirim ' . number_format($t['iskonto'], 2, ',', '.') . ' ₺' : '') . ') · maliyet ' . number_format($maliyet, 2, ',', '.') . ' ₺');
    return ['fiyat_durum' => $durum, 'fatura_yontemi' => $yontem, 'hakedis_id' => $bedel === 'hakedis' ? $hakedisId : null, 'toplam' => $t, 'maliyet' => $maliyet, 'kar' => round($t['matrah'] - $maliyet, 2)];
}

function smUygunHakedisler(PDO $db, array $s): array {
    if (empty($s['kiralama_id'])) return [];
    $q = $db->prepare('SELECT * FROM kiralamalar WHERE id=?'); $q->execute([(int)$s['kiralama_id']]);
    $k = $q->fetch(PDO::FETCH_ASSOC);
    if (!$k) return [];
    require_once __DIR__ . '/hasar-fiyat-lib.php';
    return hfUygunHakedisler($db, $k);
}

function smBedelYetkiHatasi(array $b, bool $kiralamaYaz, ?array $s = null): ?string {
    if ($kiralamaYaz) return null;
    if (($b['bedel'] ?? '') === 'hakedis') return 'Hakedişe eklemek için kiralama yazma yetkisi gerekir.';
    if ($s && ($s['fatura_yontemi'] ?? '') === 'hakedis' && !empty($s['hakedis_kalem_id'])) return 'Hakedişe bağlı servis bedelini değiştirmek için kiralama yazma yetkisi gerekir.';
    return null;
}

function smHakedisBagla(PDO $db, array $s, int $hakedisId, array $toplam, array $satirlar): int {
    require_once __DIR__ . '/hasar-fiyat-lib.php';
    require_once __DIR__ . '/servis-bedel-lib.php';
    $sid = (int)$s['id'];
    $uygun = array_column(smUygunHakedisler($db, $s), null, 'id');
    $hk = $uygun[$hakedisId] ?? null;
    if (!$hk) throw new DomainException('Seçilen hakediş bu servisin kiralamasına (ya da siparişine) ait değil.');
    if ($hk['faturali']) throw new DomainException('Hakediş ' . ($hk['kod'] ?: '#' . $hakedisId) . ' faturası kesilmiş; kesilmiş fatura değişmez. "Ayrı servis faturası" seçin.');
    if (($hk['para_birimi'] ?? 'TL') !== 'TL') throw new DomainException('Hakediş ' . $hk['para_birimi'] . ', servis bedeli TL cinsinden; "Ayrı servis faturası" seçin.');
    foreach ($satirlar as $r) if (abs((float)$r['kdv_oran'] - (float)$hk['kdv_oran']) > 0.001)
        throw new DomainException('Hakedişin KDV oranı %' . rtrim(rtrim(number_format((float)$hk['kdv_oran'], 2, '.', ''), '0'), '.') . '; farklı oranlı satır hakedişe eklenemez; "Ayrı servis faturası" seçin.');
    $matrah = round((float)$toplam['matrah'], 2);
    $db->prepare("INSERT INTO hakedis_kalemleri(hakedis_id,tur,aciklama,miktar,birim,tutar) VALUES(?,'servis',?,1,?,?)")
       ->execute([$hakedisId, mb_substr('Servis bedeli – ' . sbBelgeNo($s) . ' · ' . ($s['makine_ad'] ?: 'Servis #' . $sid), 0, 300), $matrah, $matrah]);
    $kalemId = (int)$db->lastInsertId();
    hfHakedisTutarDegis($db, $hakedisId, $matrah, 'servis bedeli eklendi (Servis #' . $sid . ')');
    $db->prepare('UPDATE teknik_servis SET hakedis_id=?, hakedis_kalem_id=? WHERE id=?')->execute([$hakedisId, $kalemId, $sid]);
    return $kalemId;
}

function smHakedisBagiKilidi(array $s): void {
    if (($s['fatura_yontemi'] ?? '') === 'hakedis') throw new DomainException('Servis bedeli hakedişe eklendi; satır eklemek ya da silmek için önce Fiyatlandırılacaklar\'da bedeli yeniden kararlaştırın (hakediş bağı kaldırılır).');
}

function smHakedisBagKaldir(PDO $db, array $s): void {
    if (($s['fatura_yontemi'] ?? '') !== 'hakedis' || empty($s['hakedis_kalem_id'])) return;
    require_once __DIR__ . '/hasar-fiyat-lib.php';
    $q = $db->prepare('SELECT id, hakedis_id, tutar FROM hakedis_kalemleri WHERE id=?'); $q->execute([(int)$s['hakedis_kalem_id']]);
    if ($kl = $q->fetch(PDO::FETCH_ASSOC)) {
        $hq = $db->prepare('SELECT h.*, (SELECT COUNT(*) FROM tahsilatlar t WHERE t.hakedis_id=h.id) AS tahsilat FROM hakedisler h WHERE h.id=?'); $hq->execute([(int)$kl['hakedis_id']]);
        if (($hk = $hq->fetch(PDO::FETCH_ASSOC)) && hfHakedisFaturali($hk)) throw new DomainException('Bağlı hakediş ' . ($hk['kod'] ?: '#' . (int)$kl['hakedis_id']) . ' faturası kesilmiş; servis bedeli değiştirilemez. Önce hakediş faturasını düzeltin.');
        hfHakedisTutarDegis($db, (int)$kl['hakedis_id'], -(float)$kl['tutar'], 'servis bedeli satırı çıkarıldı (Servis #' . (int)$s['id'] . ')');
        $db->prepare('DELETE FROM hakedis_kalemleri WHERE id=?')->execute([(int)$kl['id']]);
    }
    $db->prepare('UPDATE teknik_servis SET hakedis_id=NULL, hakedis_kalem_id=NULL WHERE id=?')->execute([(int)$s['id']]);
}

function smHakedisSilindi(PDO $db, int $hakedisId): void {
    $q = $db->prepare("SELECT id, makine_ad FROM teknik_servis WHERE hakedis_id=? AND fatura_yontemi='hakedis'"); $q->execute([$hakedisId]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $sid = (int)$s['id'];
        $db->prepare("UPDATE teknik_servis SET fiyat_durum='bekliyor', fatura_yontemi=NULL, hakedis_id=NULL, hakedis_kalem_id=NULL, updated_at=datetime('now','localtime') WHERE id=?")->execute([$sid]);
        $v = $db->prepare("SELECT 1 FROM gorevler WHERE kaynak_modul='servis_fiyat' AND kaynak_id=? AND durum='acik'"); $v->execute([$sid]);
        if (!$v->fetchColumn()) gorevOlustur($db, 'Fiyatlandır: ' . ($s['makine_ad'] ?: 'servis #' . $sid), 'Bağlı hakediş silindi; servis bedeli yeniden fiyatlandırılmalı (Teknik Servis › Fiyatlandırılacaklar).', 'servis_fiyat', $sid);
        logla($db, 'teknik_servis', $sid, 'guncelle', ['fiyat_durum' => 'fiyatlandi', 'hakedis_id' => $hakedisId], ['fiyat_durum' => 'bekliyor'], 'Bağlı hakediş silindi; servis #' . $sid . ' yeniden fiyat bekliyor');
    }
}

function smFiyatYetkisi(?PDO $db): bool {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (($_SESSION['rol'] ?? '') === 'admin' || ($_SESSION['yetkiler'] ?? '') === 'tum') return true;
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($db && $uid > 0) {
        try {
            $q = $db->prepare("SELECT izin FROM adim_yetkileri WHERE kisi_tur='kullanici' AND kisi_id=? AND yetki='servis_fiyat'");
            $q->execute([$uid]);
            $v = $q->fetchColumn();
            if ($v !== false) return (int)$v === 1;
        } catch (\Throwable $e) {}
    }
    return hasPermission('kiralama_takip', 'yaz');
}

function smFiyatErisimi(PDO $db, int $sid): bool {
    if (!smFiyatYetkisi($db)) return false;
    require_once __DIR__ . '/saha-servis-lib.php';
    try { ssFiyatSubeDogrula($db, $sid); return true; } catch (DomainException $e) { return false; }
}

function smYansitKarar(PDO $db, array $mevcut, array $body): int {
    if (smHasarBagi($db, (int)$mevcut['id'])) return 0;
    if (smAkis($db, $mevcut) === 'donus') return 1;
    if ((int)($mevcut['faturalandi'] ?? 0) === 1 || !array_key_exists('musteriye_yansit', $body)) return (int)($mevcut['musteriye_yansit'] ?? 0);
    return !empty($body['musteriye_yansit']) ? 1 : 0;
}

function smYansitSonrasi(PDO $db, int $sid, array $eski): void {
    $s = tsServis($db, $sid);
    if (!empty($s['musteri_makine_id']) || (int)($s['faturalandi'] ?? 0) === 1) return;
    if ((int)($eski['musteriye_yansit'] ?? 0) === 1 && (int)($s['musteriye_yansit'] ?? 0) === 0 && in_array($s['fiyat_durum'] ?? '', ['fiyatlandi', 'bekliyor', 'ucretsiz'], true)) {
        $db->prepare("UPDATE teknik_servis SET fiyat_durum=NULL WHERE id=?")->execute([$sid]);
        $db->prepare("UPDATE gorevler SET durum='tamamlandi', tamamlanma=?, updated_at=datetime('now','localtime') WHERE kaynak_modul='servis_fiyat' AND kaynak_id=? AND durum='acik'")
           ->execute([date('Y-m-d'), $sid]);
        logla($db, 'teknik_servis', $sid, 'guncelle', ['fiyat_durum' => $s['fiyat_durum']], ['fiyat_durum' => null], 'Servis #' . $sid . ' müşteriye yansıtılmıyor; fiyatlandırma faturalandırmadan çıkarıldı (fiyat satırları saklı)');
    }
}

function smFiyatBekliyorAc(PDO $db, int $sid): ?int {
    $s = tsServis($db, $sid);
    if (!in_array(smAkis($db, $s), ['yansit', 'donus'], true) || in_array($s['fiyat_durum'] ?? '', ['fiyatlandi', 'ucretsiz'], true)) return null;
    if (smEskiKayitMi($s) && (float)($s['maliyet'] ?? 0) > 0) return null;
    if (($s['fiyat_durum'] ?? '') !== 'bekliyor') $db->prepare("UPDATE teknik_servis SET fiyat_durum='bekliyor' WHERE id=?")->execute([$sid]);
    return gorevOlustur($db, 'Fiyatlandır: ' . ($s['makine_ad'] ?: 'servis #' . $sid),
        'Müşteriye yansıtılan servis tamamlandı. Teknik Servis > kayıt > "Masraf ve fiyat" bölümünde müşteri fiyatını, KDV\'yi ve indirimi girin.', 'servis_fiyat', $sid);
}

function smOneriSatirlari(PDO $db, int $sid): array {
    $out = []; $yolVar = false;
    foreach (smKalemler($db, $sid) as $k) {
        if ($k['miktar'] <= 0) continue;
        if ($k['tip'] === 'yol') $yolVar = true;
        $out[] = ['tur' => SM_FIYAT_TURU[$k['tip']], 'aciklama' => $k['aciklama'], 'miktar' => (float)$k['miktar'],
                  'birim' => $k['birim'], 'birim_fiyat' => $k['varsayilan_fiyat'], 'kdv_oran' => 20, 'kaynak_kalem_id' => $k['id']];
    }
    if (!$yolVar) {
        $q = $db->prepare('SELECT COUNT(*) FROM servis_ziyaretleri WHERE servis_id=? AND varis IS NOT NULL');
        $q->execute([$sid]);
        if ($n = (int)$q->fetchColumn())
            $out[] = ['tur' => 'yol', 'aciklama' => 'Yol / ziyaret', 'miktar' => $n, 'birim' => 'ziyaret', 'birim_fiyat' => smAyarUcret($db, 'servis_yol_ucreti'), 'kdv_oran' => 20, 'kaynak_kalem_id' => null];
    }
    return $out;
}

function smMusteriGorunumu(PDO $db, int $sid): array {
    $q = $db->prepare('SELECT s.id, s.tarih, s.tamamlanma, s.makine_ad, s.fiyat_durum, s.musteriye_yansit, s.maliyet, s.maliyet_kaynak, s.iskonto_tur, s.iskonto_deger,
                              s.musteri_ara_toplam, s.musteri_iskonto, s.musteri_tutar, s.musteri_kdv, s.musteri_genel_toplam, m.unvan AS musteri_unvan, k.kod AS kiralama_kod
                       FROM teknik_servis s LEFT JOIN kiralamalar k ON k.id=s.kiralama_id LEFT JOIN musteriler m ON m.id=COALESCE(s.musteri_id, k.musteri_id) WHERE s.id=?');
    $q->execute([$sid]);
    $s = $q->fetch(PDO::FETCH_ASSOC);
    if (!$s) throw new DomainException('Servis kaydı bulunamadı.');
    $fd = $s['fiyat_durum'];
    $satirlar = []; $toplam = null; $not = null;
    if ($fd === 'fiyatlandi') {
        $ham = smFiyatSatirlari($db, $sid);
        $h = smToplam($ham, $s['iskonto_tur'], $s['iskonto_deger']);
        foreach ($ham as $x) $satirlar[] = ['aciklama' => (string)$x['aciklama'], 'miktar' => (float)$x['miktar'], 'birim' => (string)($x['birim'] ?? ''),
                                            'birim_fiyat' => (float)$x['birim_fiyat'], 'kdv_oran' => (float)$x['kdv_oran'], 'tutar' => round((float)$x['miktar'] * (float)$x['birim_fiyat'], 2)];
        $toplam = ['ara' => (float)($s['musteri_ara_toplam'] ?? $h['ara']), 'iskonto' => (float)($s['musteri_iskonto'] ?? $h['iskonto']), 'matrah' => (float)($s['musteri_tutar'] ?? $h['matrah']),
                   'kdv' => (float)($s['musteri_kdv'] ?? $h['kdv']), 'genel' => (float)($s['musteri_genel_toplam'] ?? $h['genel']), 'kdv_dokum' => $h['kdv_dokum']];
    } elseif ($fd === 'ucretsiz') {
        $toplam = ['ara' => 0.0, 'iskonto' => 0.0, 'matrah' => 0.0, 'kdv' => 0.0, 'genel' => 0.0, 'kdv_dokum' => []];
        $not = 'Ücretsiz (garanti / iyi niyet)';
    } elseif ((int)$s['musteriye_yansit'] === 1 && ($s['maliyet_kaynak'] ?? '') !== 'kalem' && (float)$s['maliyet'] > 0) {
        $tutar = round((float)$s['maliyet'], 2);
        $toplam = ['ara' => $tutar, 'iskonto' => 0.0, 'matrah' => $tutar, 'kdv' => null, 'genel' => $tutar, 'kdv_dokum' => []];
        $not = 'Eski kayıt: tutar elle girilmiş, satır ve KDV ayrımı yok';
    }
    return ['servis_no' => 'SERVIS-' . (int)$s['id'], 'tarih' => $s['tamamlanma'] ?: $s['tarih'], 'makine' => $s['makine_ad'], 'musteri' => $s['musteri_unvan'],
            'kiralama' => $s['kiralama_kod'], 'fiyat_durum' => $fd, 'satirlar' => $satirlar, 'toplam' => $toplam, 'not' => $not,
            'iskonto' => $s['iskonto_tur'] ? ['tur' => $s['iskonto_tur'], 'deger' => (float)$s['iskonto_deger']] : null];
}

const SM_MALIYET_ALANLARI = ['maliyet', 'birim_maliyet', 'kart_fiyat', 'hareket_birim', 'toplam_maliyet', 'varsayilan_fiyat', 'maliyet_tutar', 'malzeme_tutar', 'maliyet_girildi'];

function smMaliyetSil(array $r): array {
    foreach (SM_MALIYET_ALANLARI as $a) unset($r[$a]);
    return $r;
}

function smBaskaSube(array $r): bool {
    if (array_key_exists('servis_baska_sube', $r)) return !empty($r['servis_baska_sube']) || !empty($r['kiralama_baska_sube']);
    return !empty($r['kiralama_baska_sube']) || !empty($r['musteri_baska_sube']);
}

function smTutarGizle(array $r, ?PDO $db = null): array {
    if (!smBaskaSube($r) && smFiyatYetkisi($db)) return $r;
    foreach (SM_FIYAT_SUTUNLARI as $a) unset($r[$a]);
    unset($r['fiyat']);
    $r = smMaliyetSil($r);
    if (isset($r['masraf']) && is_array($r['masraf'])) $r['masraf'] = array_map('smMaliyetSil', $r['masraf']);
    unset($r['masraf_devir']);
    foreach (['parcalar', 'parca_talepleri'] as $d) {
        if (isset($r[$d]) && is_array($r[$d])) $r[$d] = array_map(fn($x) => is_array($x) ? array_diff_key($x, ['birim_fiyat' => 1, 'tutar' => 1]) : $x, $r[$d]);
    }
    $r['fiyat_yetki'] = false;
    return $r;
}

function smDetayEk(PDO $db, int $sid): array {
    $s = tsServis($db, $sid);
    $ek = ['masraf' => smKalemler($db, $sid), 'masraf_devir' => smDevirTutari($db, $s), 'masraf_eski_kayit' => smEskiKayitMi($s)];
    foreach ($ek['masraf'] as &$k) unset($k['varsayilan_fiyat']);
    unset($k);
    if (in_array($s['fiyat_durum'] ?? '', ['fiyatlandi', 'ucretsiz'], true) && smFiyatErisimi($db, $sid)) $ek['fiyat'] = smMusteriGorunumu($db, $sid);
    return $ek;
}
