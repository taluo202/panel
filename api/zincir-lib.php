<?php
declare(strict_types=1);

function hakedisFaturaliSql(string $h = 'h'): string {
    return "($h.durum IN ('faturalandi','tahsil_edildi') OR COALESCE($h.fatura_no,'')<>'')";
}

function hakedisKiralamaFaturasindanSql(string $h = 'h', string $k = 'k'): string {
    return "(COALESCE($h.fatura_kaynak,'')='kiralama' OR (COALESCE($h.fatura_kaynak,'')='' AND COALESCE($k.faturalandi,0)=1"
         . " AND $h.durum='faturalandi' AND COALESCE($h.fatura_no,'')=COALESCE($k.fatura_no,'') AND COALESCE($h.fatura_tarihi,'')=COALESCE($k.fatura_tarihi,'')))";
}

function kiralamaAyriFaturaliHakedisler(PDO $db, array $kiralamaIdler): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $kiralamaIdler), fn($x) => $x > 0)));
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT h.kiralama_id, COALESCE(NULLIF(h.kod,''), '#' || h.id) AS kod
                        FROM hakedisler h JOIN kiralamalar k ON k.id = h.kiralama_id
                        WHERE h.kiralama_id IN ($ph) AND " . hakedisFaturaliSql('h') . "
                          AND NOT " . hakedisKiralamaFaturasindanSql('h', 'k') . "
                        ORDER BY h.kiralama_id, h.id");
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['kiralama_id']][] = (string)$r['kod'];
    return $out;
}

function kiralamaFaturasiHakedisleri(PDO $db, array $k): array {
    $st = $db->prepare("SELECT h.* FROM hakedisler h JOIN kiralamalar k ON k.id=h.kiralama_id
                        WHERE h.kiralama_id=? AND COALESCE(h.tutar,0)>0
                          AND ((h.durum='beklemede' AND COALESCE(h.fatura_no,'')='') OR " . hakedisKiralamaFaturasindanSql('h', 'k') . ")
                        ORDER BY h.donem_bas, h.id");
    $st->execute([(int)$k['id']]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function kiralamaCiftFaturaHatasi(PDO $db, array $kiralamalar): ?string {
    $ayri = kiralamaAyriFaturaliHakedisler($db, array_column($kiralamalar, 'id'));
    if (!$ayri) return null;
    $satir = [];
    foreach ($kiralamalar as $k) {
        $kid = (int)$k['id'];
        if (!isset($ayri[$kid])) continue;
        $satir[] = (($k['kod'] ?? '') ?: ('#' . $kid)) . ' (' . implode(', ', $ayri[$kid]) . ')';
    }
    return 'Bu kiralamaların hakedişi Hakedişler sayfasında ayrıca faturalanmış: ' . implode('; ', $satir)
         . '. Aynı iş iki kez faturalanmasın diye kiralama burada faturalanamaz. Kalan dönemleri Hakedişler sayfasından faturalayın.';
}

function servisFaturaSubeKosulu(string $kosul): array {
    $sql = "SELECT s.id FROM teknik_servis s LEFT JOIN kiralamalar k ON k.id=s.kiralama_id
            LEFT JOIN musteriler ms ON ms.id=s.musteri_id LEFT JOIN musteriler mk ON mk.id=k.musteri_id WHERE ($kosul)";
    $w = []; $p = [];
    subeKosulEkle($w, $p, servisSubeSql('s', 'k', 'ms', 'mk'));
    if ($w) $sql .= ' AND ' . implode(' AND ', $w);
    return [$sql, $p];
}

function musteriCariFarklari(PDO $db): array {
    $tahsil = [];
    foreach ($db->query("SELECT musteri_id, COALESCE(SUM(tutar),0) t FROM tahsilatlar WHERE musteri_id IS NOT NULL GROUP BY musteri_id")->fetchAll(PDO::FETCH_ASSOC) as $r)
        $tahsil[(int)$r['musteri_id']] = (float)$r['t'];
    require_once __DIR__ . '/cek-cari-kurallari.php';
    foreach (crVeri($db, null)['mahsup'] as $mid => $tutar) $tahsil[(int)$mid] = ($tahsil[(int)$mid] ?? 0.0) + (float)$tutar;
    $borc = [];
    foreach (cariBorcKalemleri($db) as $mid => $x) $borc[(int)$mid] = array_sum(array_map(fn($b) => (float)$b['tutar'], $x['borclar']));
    $out = [];
    foreach ($tahsil as $mid => $t) $out[$mid] = ['tahsilat' => round($t, 2), 'borc' => round($borc[$mid] ?? 0.0, 2), 'fark' => round($t - ($borc[$mid] ?? 0.0), 2)];
    return $out;
}

const ZINCIR_GOREV_BILGI = [
    'hakedis_eksik'          => ['Hakedişi Eksik', 'kiralama-takip.html?detay={id}', 'receipt_long'],
    'fiyat_eksik'            => ['Fiyatı Girilmemiş', 'kiralama-takip.html?detay={id}', 'money_off'],
    'fatura_bekliyor'        => ['Fatura Kesilmedi', 'hakedisler.html?ac={id}', 'receipt_long'],
    'tahsilat_gecikti'       => ['Tahsilat Gecikti', 'hakedisler.html?ac={id}', 'payments'],
    'nakliye_faturasiz'      => ['Nakliye Faturalanmadı', 'nakliye.html', 'local_shipping'],
    'servis_faturasiz'       => ['Servis Faturalanmadı', 'faturalandirma.html?sekme=servis', 'build'],
    'servis_ucretsiz'        => ['Faturadan Çıkan Servis', 'teknik-servis.html?ac={id}', 'money_off'],
    'hasar_fatura_bekliyor'  => ['Hasar Faturalanmadı', 'faturalandirma.html?sekme=hasar', 'car_crash'],
    'hasar_tahsilat_gecikti' => ['Hasar Tahsilatı Gecikti', 'faturalandirma.html?sekme=hasar', 'payments'],
    'aktarim_kira'           => ['Muhasebe Aktarımı', 'faturalandirma.html', 'sync_problem'],
    'aktarim_servis'         => ['Muhasebe Aktarımı', 'faturalandirma.html?sekme=servis', 'sync_problem'],
    'aktarim_hasar'          => ['Muhasebe Aktarımı', 'faturalandirma.html?sekme=hasar', 'sync_problem'],
    'dk_iade_bekliyor'       => ['Dış Kiralama İadesi', 'dis-kiralama.html', 'assignment_return'],
    'doviz_kur_eksik'        => ['Döviz Kuru Eksik', 'hakedisler.html?ac={id}', 'currency_exchange'],
    'hakedis_kontrol'        => ['Hakediş Kontrolü', 'kiralama-takip.html?detay={id}', 'fact_check'],
    'servis_fiyat'           => ['Fiyatlandır', 'teknik-servis.html?ac={id}', 'sell'],
    'nakliye_plan_disi'      => ['Plan Dışı Nakliye', 'kiralama-takip.html?detay={id}', 'local_shipping'],
    'kiralama_protokol'      => ['Kiralama Protokolü', 'kiralama-takip.html?detay={id}', 'contract'],
    'toplama_bildirim'       => ['Toplama', 'kiralama-takip.html?detay={id}', 'local_shipping'],
    'yola_cikti_bildirim'    => ['Yola Çıktı', 'kiralama-takip.html?detay={id}', 'local_shipping'],
    'servis_getir'           => ['Makineyi Getirt', 'teknik-servis.html?ac={id}', 'local_shipping'],
    'servis_rapor'           => ['Servis Raporu', 'teknik-servis.html?ac={id}', 'engineering'],
];

const ZINCIR_MUAF_KURALLAR = ['fiyat_eksik', 'servis_ucretsiz', 'dk_iade_bekliyor'];

function gorevKaynakBilgisi(array $g): array {
    $b = ZINCIR_GOREV_BILGI[(string)($g['kaynak_modul'] ?? '')] ?? null;
    if (!$b) return $g;
    $g['kaynak_etiket'] = $b[0];
    $g['kaynak_link'] = str_replace('{id}', (string)(int)($g['kaynak_id'] ?? 0), $b[1]);
    $g['kaynak_ikon'] = $b[2];
    return $g;
}

function zincirGorevYaz(PDO $db, string $kod, int $id, string $baslik, string $aciklama, string $oncelik, ?string $sonTarih): int {
    $gid = gorevOlustur($db, $baslik, $aciklama, $kod, $id, $oncelik);
    $db->prepare("UPDATE gorevler SET son_tarih=COALESCE(son_tarih, ?), updated_at=CASE WHEN COALESCE(aciklama,'')<>? THEN datetime('now','localtime') ELSE updated_at END, aciklama=? WHERE id=?")
       ->execute([$sonTarih, $aciklama, $aciklama, $gid]);
    return $gid;
}

function zincirMuafIdler(PDO $db, string $kod): array {
    $st = $db->prepare("SELECT DISTINCT kaynak_id FROM gorevler WHERE kaynak_modul=? AND kaynak='otomatik' AND durum='tamamlandi' AND COALESCE(kapanis,'')<>'otomatik'");
    $st->execute([$kod]);
    return array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
}

function _zTl(float $n): string { return number_format($n, 2, ',', '.') . ' ₺'; }
function _zTarih(?string $t): string { return $t ? date('d.m.Y', strtotime($t)) : '—'; }
function _zPara(array $k): string {
    $p = strtoupper(trim((string)($k['doviz'] ?? '')));
    return ($p === '' || $p === 'TRY') ? 'TL' : $p;
}

function zincirKurallari(PDO $db, string $bugun, array $ozet): array {
    $once = fn(int $gun) => date('Y-m-d', strtotime($bugun . ' -' . $gun . ' days'));
    $q = function (string $sql, array $p = []) use ($db): array { $s = $db->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); };
    $ad = fn(array $r, string $alan = 'kod') => (($r[$alan] ?? '') ?: ('#' . $r['id']));
    $k = [];

    $k['hakedis_eksik'] = ['yuksek', function () use ($db, $q, $bugun, $once, $ad) {
        $out = [];
        foreach ($q("SELECT * FROM kiralamalar WHERE durum='tamamlandi' AND COALESCE(gercek_bitis,'')<>'' AND gercek_bitis >= ? AND gercek_bitis <= ?
                     AND (COALESCE(birim_ucret,0)>0 OR (COALESCE(operatorlu,0)=1 AND COALESCE(operator_ucret,0)>0))", [$once(400), $bugun]) as $r) {
            $e = kiralamaHakedisEksik($db, $r);
            if ($e === null) continue;
            $out[] = [(int)$r['id'], 'Hakedişi eksik: ' . $ad($r) . ' · ' . ($r['ekipman_adi'] ?: 'kiralama'),
                'Kiralama kapandı (' . _zTarih($r['gercek_bitis']) . ') ama şu günler hiçbir hakedişte yok: ' . $e['metin']
                . '. Yaklaşık ' . number_format($e['tutar'], 2, ',', '.') . ' ' . (_zPara($r) === 'TL' ? '₺' : _zPara($r)) . ' (KDV hariç). Hakedişler › Toplu Üret ile o ayları üretin.'];
        }
        return $out;
    }];

    $k['fiyat_eksik'] = ['normal', function () use ($q, $ad) {
        $out = [];
        foreach ($q("SELECT k.id, k.kod, k.ekipman_adi FROM kiralamalar k WHERE k.durum IN ('aktif','tamamlandi')
                     AND COALESCE(k.birim_ucret,0)=0 AND COALESCE(k.operator_ucret,0)=0 AND COALESCE(k.nakliye_ucret,0)=0
                     AND NOT EXISTS (SELECT 1 FROM hakedisler h WHERE h.kiralama_id=k.id AND COALESCE(h.genel_toplam,0)>0)") as $r)
            $out[] = [(int)$r['id'], 'Fiyatı girilmemiş: ' . $ad($r) . ' · ' . ($r['ekipman_adi'] ?: 'kiralama'),
                'Kiralamanın kira, operatör ve nakliye ücreti sıfır; hakediş ve fatura hiç doğmaz. Fiyatı girin. Ücretsiz bir işse bu görevi kapatın; bir daha açılmaz.'];
        return $out;
    }];

    $k['fatura_bekliyor'] = ['normal', function () use ($q, $bugun, $once, $ad) {
        $out = [];
        foreach ($q("SELECT h.id, h.kod, h.genel_toplam, h.donem_bit, h.created_at, COALESCE(k.faturalandi,0) AS kiralama_faturali
                     FROM hakedisler h LEFT JOIN kiralamalar k ON k.id=h.kiralama_id
                     WHERE h.durum='beklemede' AND COALESCE(h.fatura_no,'')='' AND COALESCE(h.genel_toplam,0)>0
                       AND ((COALESCE(h.vade_tarihi,'')<>'' AND h.vade_tarihi < ?)
                         OR COALESCE(NULLIF(h.donem_bit,''), substr(h.created_at,1,10)) <= ?)", [$bugun, $once(5)]) as $r)
            $out[] = [(int)$r['id'], 'Fatura kesilmedi: ' . $ad($r),
                'Hakediş dönemi ' . _zTarih($r['donem_bit'] ?: substr((string)$r['created_at'], 0, 10)) . ' tarihinde bitti ama faturası kesilmedi — ' . _zTl((float)$r['genel_toplam'])
                . ((int)$r['kiralama_faturali'] === 1 ? '. Kiralama daha önce faturalandı; bu dönem Faturalandırma ekranında görünmez, Hakedişler sayfasından faturalayın.' : '.')];
        return $out;
    }];

    $k['tahsilat_gecikti'] = ['yuksek', function () use ($q, $bugun, $once, $ozet, $ad) {
        $out = [];
        foreach ($q("SELECT h.id, h.kod, h.vade_tarihi, h.fatura_tarihi, h.donem_bit FROM hakedisler h
                     WHERE " . hakedisFaturaliSql('h') . "
                       AND ((COALESCE(h.vade_tarihi,'')<>'' AND h.vade_tarihi < ?)
                         OR (COALESCE(h.vade_tarihi,'')='' AND COALESCE(NULLIF(h.fatura_tarihi,''), h.donem_bit) <= ?))", [$bugun, $once(30)]) as $r) {
            $kalan = $ozet['hakedis_kalan'][(int)$r['id']] ?? null;
            if ($kalan === null) continue;
            $out[] = [(int)$r['id'], 'Tahsilat gecikti: ' . $ad($r),
                ($r['vade_tarihi'] ? 'Vadesi ' . _zTarih($r['vade_tarihi']) . ' geçti' : 'Vade girilmemiş; fatura tarihinden (' . _zTarih($r['fatura_tarihi'] ?: $r['donem_bit']) . ') bu yana 30 günden fazla geçti')
                . ', kalan tutar ' . _zTl((float)$kalan)];
        }
        return $out;
    }];

    $k['doviz_kur_eksik'] = ['yuksek', function () use ($db, $q, $ad) {
        $out = [];
        foreach ($q("SELECT id, kod, para_birimi, doviz_genel, fatura_tarihi, (durum IN ('faturalandi','tahsil_edildi') OR COALESCE(fatura_no,'')<>'') AS faturali FROM hakedisler
                     WHERE COALESCE(para_birimi,'TL') NOT IN ('TL','TRY') AND doviz_tutar IS NOT NULL AND COALESCE(kur_kesin,0)=0
                       AND (kur IS NULL OR durum IN ('faturalandi','tahsil_edildi') OR COALESCE(fatura_no,'')<>'')") as $r) {
            if ((int)$r['faturali'] === 1 && trim((string)$r['fatura_tarihi']) !== '') {
                try { hakedisFaturaKuruUygula($db, (int)$r['id'], (string)$r['fatura_tarihi'], false); continue; } catch (DomainException $e) {}
            }
            $out[] = [(int)$r['id'], 'Döviz kuru eksik: ' . $ad($r),
                'Hakediş ' . number_format((float)$r['doviz_genel'], 2, ',', '.') . ' ' . $r['para_birimi'] . '; TL karşılığı hesaplanamadı, cari ve raporlarda 0 ₺ görünüyor. '
                . 'Kuru Filo Referans › Tarihli döviz kurları bölümüne girin; fatura günü kuruyla TL\'ye kendiliğinden çevrilir.'];
        }
        return $out;
    }];

    $k['nakliye_faturasiz'] = ['normal', function () use ($q, $once) {
        $out = [];
        foreach ($q("SELECT n.id, n.tarih, n.yon, n.yansitilan, n.kiralama_id, COALESCE(n.fiyat_bekliyor,0) AS fb, k.kod, k.durum AS kdurum
                     FROM nakliyeler n LEFT JOIN kiralamalar k ON k.id=n.kiralama_id
                     WHERE n.hakedis_id IS NULL AND COALESCE(k.durum,'')<>'iptal'
                       AND ((COALESCE(n.fiyat_bekliyor,0)=1 AND (n.tarih <= ? OR k.durum='tamamlandi'))
                         OR (COALESCE(n.fiyat_bekliyor,0)=0 AND COALESCE(n.yansitilan,0)>0 AND n.tarih <= ?
                             AND ((n.kiralama_id IS NULL AND COALESCE(n.siparis_no,'')='') OR k.durum='tamamlandi')))", [$once(7), $once(2)]) as $r) {
            $yon = ['gidis' => 'gidiş', 'donus' => 'dönüş', 'gidis_donus' => 'gidiş + dönüş'][$r['yon']] ?? (string)$r['yon'];
            $neden = (int)$r['fb'] === 1
                ? 'Nakliyeci fiyatı henüz girilmedi' . ($r['kdurum'] === 'tamamlandi' ? ' ve iş kapandı' : '') . '; fiyat girilmezse bu nakliye müşteriye hiç faturalanmaz. Fiyatı girip ayrı hakedişle faturalayın.'
                : ($r['kiralama_id'] ? 'Kiralama kapandı ama bu nakliye hiçbir hakedişe girmedi (' . _zTl((float)$r['yansitilan']) . ').' : 'Kiralamaya bağlı değil; hiçbir hakedişe ve cariye girmez (' . _zTl((float)$r['yansitilan']) . ').');
            $out[] = [(int)$r['id'], 'Nakliye faturalanmadı: ' . $yon . ' ' . _zTarih($r['tarih']) . ($r['kod'] ? ' · ' . $r['kod'] : ''), $neden];
        }
        return $out;
    }];

    $k['servis_faturasiz'] = ['normal', function () use ($q, $once) {
        $out = [];
        foreach ($q("SELECT s.id, s.makine_ad, s.musteri_genel_toplam FROM teknik_servis s
                     WHERE s.durum='tamamlandi' AND s.fiyat_durum='fiyatlandi' AND s.fatura_yontemi='ayri' AND COALESCE(s.faturalandi,0)=0
                       AND COALESCE(NULLIF(s.tamamlanma,''), s.tarih) <= ?", [$once(3)]) as $r)
            $out[] = [(int)$r['id'], 'Servis faturalanmadı: ' . ($r['makine_ad'] ?: 'servis #' . $r['id']),
                'Servis fiyatlandı (' . _zTl((float)$r['musteri_genel_toplam']) . ', KDV dahil) ama faturası kesilmedi. Faturalandırma › Servis sekmesinden işaretleyin.'];
        return $out;
    }];

    $k['servis_ucretsiz'] = ['dusuk', function () use ($q, $once) {
        $out = [];
        foreach ($q("SELECT s.id, s.makine_ad, s.fiyat_durum FROM teknik_servis s
                     WHERE (s.kiralama_id IS NOT NULL OR s.musteri_id IS NOT NULL) AND COALESCE(s.faturalandi,0)=0
                       AND (s.fiyat_durum='ucretsiz' OR (COALESCE(s.fiyat_durum,'')='' AND EXISTS (SELECT 1 FROM islem_log l WHERE l.tablo='teknik_servis' AND l.kayit_id=s.id
                            AND l.ozet LIKE '%müşteriye yansıtılmıyor%' AND l.tarih >= ?)))
                       AND COALESCE(s.updated_at, s.created_at) >= ?", [$once(30), $once(30)]) as $r)
            $out[] = [(int)$r['id'], 'Faturadan çıkan servis: ' . ($r['makine_ad'] ?: 'servis #' . $r['id']),
                ($r['fiyat_durum'] === 'ucretsiz' ? 'Servis ücretsiz (firma karşılar) işaretlendi.' : 'Servis "müşteriye yansıtılmıyor" yapıldı; fiyatı faturalandırmadan çıktı.')
                . ' Bilinçli bir kararsa bu görevi kapatın; bir daha açılmaz.'];
        return $out;
    }];

    $k['hasar_fatura_bekliyor'] = ['normal', function () use ($q, $once) {
        $out = [];
        foreach ($q("SELECT id, ref, para_birimi, genel_toplam FROM hasar_faturalari WHERE faturalandi=0 AND substr(created_at,1,10) <= ?", [$once(3)]) as $r)
            $out[] = [(int)$r['id'], 'Hasar bedeli faturalanmadı: ' . $r['ref'],
                'Hasar bedeli ' . number_format((float)$r['genel_toplam'], 2, ',', '.') . ' ' . ($r['para_birimi'] === 'TL' ? '₺' : $r['para_birimi'])
                . ' fiyatlandı ama faturası kesilmedi. Faturalandırma › Hasar sekmesinden işaretleyin.'];
        return $out;
    }];

    $k['hasar_tahsilat_gecikti'] = ['yuksek', function () use ($q, $bugun, $ozet) {
        $out = [];
        foreach ($q("SELECT id, ref, vade_tarihi FROM hasar_faturalari WHERE faturalandi=1 AND COALESCE(vade_tarihi,'')<>'' AND vade_tarihi < ?", [$bugun]) as $r) {
            $kalan = $ozet['kalem_kalan']['hasar:' . (int)$r['id']] ?? null;
            if ($kalan === null) continue;
            $out[] = [(int)$r['id'], 'Hasar tahsilatı gecikti: ' . $r['ref'], 'Vadesi ' . _zTarih($r['vade_tarihi']) . ' geçti, kalan ' . _zTl((float)$kalan)];
        }
        return $out;
    }];

    foreach (['aktarim_kira' => ['kiralamalar', 'kod'], 'aktarim_servis' => ['teknik_servis', 'makine_ad'], 'aktarim_hasar' => ['hasar_faturalari', 'ref']] as $kod => [$tablo, $adAlan]) {
        $k[$kod] = ['normal', function () use ($q, $once, $tablo, $adAlan) {
            $out = [];
            foreach ($q("SELECT id, $adAlan AS ad, fatura_no, fatura_durum, fatura_hata_notu FROM $tablo
                         WHERE faturalandi=1 AND (fatura_durum='hata' OR (fatura_durum='aktarim_bekliyor' AND substr(COALESCE(updated_at, fatura_tarihi),1,10) <= ?))", [$once(3)]) as $r)
                $out[] = [(int)$r['id'], 'Muhasebe aktarımı: ' . ($r['ad'] ?: '#' . $r['id']) . ($r['fatura_no'] ? ' · ' . $r['fatura_no'] : ''),
                    $r['fatura_durum'] === 'hata' ? 'Muhasebeye aktarımda hata: ' . ($r['fatura_hata_notu'] ?: 'not yok') : '3 günden uzun süredir aktarım bekliyor.'];
            return $out;
        }];
    }

    $k['dk_iade_bekliyor'] = ['normal', function () use ($q, $bugun, $once) {
        $out = [];
        foreach ($q("SELECT d.id, d.makine_tur, d.makine_id, d.durum, d.temin_bitis, d.tedarikci_makine_kodu FROM dis_kiralama_kayitlari d
                     WHERE d.durum IN ('musait','rezerve','musteride','iade_bekliyor')
                       AND ((COALESCE(d.temin_bitis,'')<>'' AND d.temin_bitis < ?)
                         OR (d.durum='musteride' AND NOT EXISTS (SELECT 1 FROM kiralamalar k WHERE k.makine_tur=d.makine_tur AND k.makine_id=d.makine_id AND k.durum='aktif' AND k.gercek_bitis IS NULL))
                         OR (d.durum='musait' AND d.temin_baslangic <= ? AND NOT EXISTS (SELECT 1 FROM kiralamalar k WHERE k.makine_tur=d.makine_tur AND k.makine_id=d.makine_id AND k.durum='aktif')))",
                     [$bugun, $once(7)]) as $r) {
            $neden = ($r['temin_bitis'] && $r['temin_bitis'] < $bugun) ? 'Tedarikçiden temin süresi ' . _zTarih($r['temin_bitis']) . ' tarihinde bitti'
                   : ($r['durum'] === 'musteride' ? 'Kayıt "müşteride" ama makinenin açık kiralaması yok' : '7 günden uzun süredir boşta duruyor');
            $out[] = [(int)$r['id'], 'Dış kiralama iadesi: ' . ($r['tedarikci_makine_kodu'] ?: $r['makine_tur'] . ' #' . $r['makine_id']),
                $neden . '; tedarikçi maliyeti işlemeye devam ediyor olabilir. Makineyi iade edin ya da kaydı güncelleyin. Bilinçliyse görevi kapatın.'];
        }
        return $out;
    }];

    return $k;
}

function zincirTara(PDO $db, string $bugun, array $ozet): int {
    $acilan = 0;
    foreach (zincirKurallari($db, $bugun, $ozet) as $kod => [$oncelik, $bul]) {
        $muaf = in_array($kod, ZINCIR_MUAF_KURALLAR, true) ? zincirMuafIdler($db, $kod) : [];
        $gecerli = [];
        foreach ($bul() as [$id, $baslik, $aciklama]) {
            if (isset($muaf[$id])) continue;
            $gecerli[] = $id;
            zincirGorevYaz($db, $kod, $id, $baslik, $aciklama, $oncelik, $bugun);
            $acilan++;
        }
        gorevOtomatikKapat($db, $kod, $gecerli);
    }
    return $acilan;
}

function zincirGorevGorunur(PDO $db, array $g): bool {
    $km = (string)($g['kaynak_modul'] ?? ''); $id = (int)($g['kaynak_id'] ?? 0); $sube = subeKisitiHam();
    $tek = function (string $sql) use ($db, $id) { $s = $db->prepare($sql); $s->execute([$id]); return $s->fetch(PDO::FETCH_NUM); };
    $uygun = fn($r) => $r !== false && ($sube === null || $sube === trim((string)($r[0] ?? '')));
    $svSube = 'SELECT ' . servisSubeSql('s', 'k', 'ms', 'mk') . ' FROM teknik_servis s LEFT JOIN kiralamalar k ON k.id=s.kiralama_id LEFT JOIN musteriler ms ON ms.id=s.musteri_id LEFT JOIN musteriler mk ON mk.id=k.musteri_id WHERE s.id=?';
    switch ($km) {
        case 'hakedis_eksik': case 'fiyat_eksik': case 'aktarim_kira':
            return hasPermission('kiralama_takip', 'oku') && $uygun($tek('SELECT sube FROM kiralamalar WHERE id=?'));
        case 'nakliye_faturasiz':
            return (hasPermission('nakliye', 'oku') || hasPermission('kiralama_takip', 'oku'))
                && $uygun($tek("SELECT COALESCE(NULLIF(TRIM(k.sube),''), n.sube) FROM nakliyeler n LEFT JOIN kiralamalar k ON k.id=n.kiralama_id WHERE n.id=?"));
        case 'servis_faturasiz': case 'aktarim_servis':
            return hasPermission('kiralama_takip', 'oku') && $uygun($tek($svSube));
        case 'servis_ucretsiz':
            return hasPermission('finans', 'oku') && $uygun($tek($svSube));
        case 'hasar_fatura_bekliyor': case 'hasar_tahsilat_gecikti': case 'aktarim_hasar':
            return hasPermission('kiralama_takip', 'oku') && $uygun($tek('SELECT sube FROM hasar_faturalari WHERE id=?'));
        case 'dk_iade_bekliyor':
            return hasPermission('dis_kiralama', 'oku');
        case 'doviz_kur_eksik':
            return hasPermission('kiralama_takip', 'oku') && $uygun($tek('SELECT ' . hakedisSubeSql('h', 'm') . ' FROM hakedisler h LEFT JOIN musteriler m ON m.id=h.musteri_id WHERE h.id=?'));
    }
    return false;
}

function istisnaTaraKilitli(PDO $db, bool $zorla = false, int $aralikSn = 0): ?int {
    $simdi = time();
    $db->exec("INSERT OR IGNORE INTO ayarlar (anahtar, deger) VALUES ('istisna_son_tarama', '0')");
    $st = $db->prepare("UPDATE ayarlar SET deger=? WHERE anahtar='istisna_son_tarama'" . ($zorla ? '' : " AND CAST(deger AS INTEGER) <= ?"));
    $st->execute($zorla ? [(string)$simdi] : [(string)$simdi, $simdi - $aralikSn]);
    if ($st->rowCount() === 0) return null;
    return islemIcinde($db, fn(PDO $db) => istisnaTara($db));
}

function kiralamaHakedisEksik(PDO $db, array $r): ?array {
    if (($r['durum'] ?? '') !== 'tamamlandi' || trim((string)($r['gercek_bitis'] ?? '')) === '') return null;
    if ((float)($r['birim_ucret'] ?? 0) <= 0 && !(!empty($r['operatorlu']) && (float)($r['operator_ucret'] ?? 0) > 0)) return null;
    $son = !empty($r['ikame_makine_id']) ? date('Y-m-d', strtotime($r['gercek_bitis'] . ' -1 day')) : (string)$r['gercek_bitis'];
    if ($son < (string)$r['baslangic']) return null;
    $bos = hakedisBosAraliklar($db, (int)$r['id'], (string)$r['baslangic'], $son);
    if (!$bos) return null;
    $tutar = 0.0; $parca = [];
    foreach ($bos as [$a, $b, $g]) { $tutar += hakedisKalemleriHesapla($db, $r, $a, $b, true)['tutar']; $parca[] = _zTarih($a) . '–' . _zTarih($b) . ' (' . $g . ' gün)'; }
    if ($tutar <= 0) return null;
    return ['tutar' => round($tutar, 2), 'metin' => implode(', ', $parca)];
}

function kiralamaKapanisHakedisUyarisi(PDO $db, int $kiralamaId): string {
    $st = $db->prepare('SELECT * FROM kiralamalar WHERE id=?'); $st->execute([$kiralamaId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return '';
    $e = kiralamaHakedisEksik($db, $r);
    if ($e === null) return '';
    $ad = ($r['kod'] ?? '') ?: ('#' . $kiralamaId);
    zincirGorevYaz($db, 'hakedis_eksik', $kiralamaId, 'Hakedişi eksik: ' . $ad . ' · ' . ($r['ekipman_adi'] ?: 'kiralama'),
        'Kiralama kapandı (' . _zTarih($r['gercek_bitis']) . ') ama şu günler hiçbir hakedişte yok: ' . $e['metin']
        . '. Yaklaşık ' . number_format($e['tutar'], 2, ',', '.') . ' ' . (_zPara($r) === 'TL' ? '₺' : _zPara($r)) . ' (KDV hariç). Hakedişler › Toplu Üret ile o ayları üretin.',
        'yuksek', date('Y-m-d'));
    return ' Dikkat: ' . $ad . ' için şu günlerin hakedişi yok: ' . $e['metin'] . '. Hakedişler › Toplu Üret ile üretin; Dikkat Gerekenler sayfasına görev açıldı.';
}

const DOVIZ_PARALAR = ['EUR', 'USD'];

function dovizPara($ham): string {
    $p = strtoupper(trim((string)$ham));
    return ($p === '' || $p === 'TRY') ? 'TL' : $p;
}

function _dovizKurSaglayici(): string {
    $e = getenv('TALOO_KUR_KAYNAGI');
    return $e === false ? 'tcmb' : trim((string)$e);
}

function tcmbKurGetir(string $para, string $tarih): ?float {
    $kaynak = _dovizKurSaglayici();
    if ($kaynak === 'kapali') return null;
    if (str_starts_with($kaynak, 'sabit:')) {
        foreach (explode(',', substr($kaynak, 6)) as $parca) {
            [$p, $v] = array_pad(explode('=', $parca, 2), 2, '');
            if (strtoupper(trim($p)) === $para && is_numeric(trim($v))) return (float)trim($v);
        }
        return null;
    }
    if ($tarih > date('Y-m-d') || !function_exists('curl_init')) return null;
    $url = $tarih === date('Y-m-d') ? 'https://www.tcmb.gov.tr/kurlar/today.xml'
         : 'https://www.tcmb.gov.tr/kurlar/' . substr($tarih, 0, 4) . substr($tarih, 5, 2) . '/' . substr($tarih, 8, 2) . substr($tarih, 5, 2) . substr($tarih, 0, 4) . '.xml';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false]);
    $xml = curl_exec($ch);
    $kod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($xml) || $kod !== 200 || $xml === '') return null;
    $eski = libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
    libxml_use_internal_errors($eski);
    if (!$doc) return null;
    $ilan = (string)($doc['Tarih'] ?? '');
    if ($ilan !== '' && preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $ilan, $m) && "$m[3]-$m[2]-$m[1]" !== $tarih) return null;
    foreach ($doc->Currency as $c) {
        if ((string)$c['CurrencyCode'] !== $para) continue;
        $v = str_replace(',', '.', trim((string)$c->ForexSelling));
        return is_numeric($v) && (float)$v > 0 ? round((float)$v, 6) : null;
    }
    return null;
}

function dovizKuru(PDO $db, string $para, string $tarih, bool $agdan = true): ?float {
    static $denendi = [];
    $para = dovizPara($para);
    if ($para === 'TL') return 1.0;
    require_once __DIR__ . '/filo-referans-lib.php';
    frSema($db);
    $st = $db->prepare('SELECT kur FROM filo_kur_gecmisi WHERE tarih=? AND para=? ORDER BY id DESC LIMIT 1');
    $st->execute([$tarih, $para]);
    $v = $st->fetchColumn();
    if ($v !== false && (float)$v > 0) return (float)$v;
    if (!$agdan || !in_array($para, DOVIZ_PARALAR, true) || isset($denendi[$para . '|' . $tarih])) return null;
    if (ayarOkuDb($db, 'tcmb_kur_otomatik', '1') === '0') return null;
    $denendi[$para . '|' . $tarih] = true;
    $kur = tcmbKurGetir($para, $tarih);
    if ($kur === null || $kur <= 0) return null;
    $db->prepare('INSERT INTO filo_kur_gecmisi(tarih,para,kur,kaynak,kullanici) VALUES(?,?,?,?,?)')
       ->execute([$tarih, $para, $kur, 'TCMB döviz satış (otomatik)', 'Sistem']);
    return $kur;
}

function ayarOkuDb(PDO $db, string $anahtar, string $varsayilan = ''): string {
    $st = $db->prepare('SELECT deger FROM ayarlar WHERE anahtar=?'); $st->execute([$anahtar]);
    $v = $st->fetchColumn();
    return $v === false ? $varsayilan : (string)$v;
}

function dovizSonKur(PDO $db, string $para, string $tarih, bool $agdan = true): ?array {
    $para = dovizPara($para);
    if ($para === 'TL') return ['kur' => 1.0, 'tarih' => $tarih];
    if (($k = dovizKuru($db, $para, $tarih, $agdan)) !== null) return ['kur' => $k, 'tarih' => $tarih];
    $st = $db->prepare('SELECT kur, tarih FROM filo_kur_gecmisi WHERE para=? AND tarih<=? ORDER BY tarih DESC, id DESC LIMIT 1');
    $st->execute([$para, $tarih]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r && (float)$r['kur'] > 0 ? ['kur' => (float)$r['kur'], 'tarih' => (string)$r['tarih']] : null;
}

function _dovizTlHesap(float $dovizTutar, float $kdvOran, float $kur, array $tevkifat = []): array {
    $tl = round($dovizTutar * $kur, 2);
    return hakedisTutarHesapla($tl, $kdvOran, !empty($tevkifat['tevkifat_var']), $tevkifat['tevkifat_orani'] ?? null) + ['tutar' => $tl];
}

function hakedisDovizHesap(PDO $db, array $k, float $tutar, float $kdvOran, string $tarih): array {
    $para = dovizPara($k['doviz'] ?? 'TL');
    $kdv = round($tutar * $kdvOran / 100, 2);
    if ($para === 'TL') return ['para_birimi' => 'TL', 'tutar' => $tutar, 'kdv_tutar' => $kdv, 'genel_toplam' => round($tutar + $kdv, 2),
                                'doviz_tutar' => null, 'doviz_kdv' => null, 'doviz_genel' => null, 'kur' => null, 'kur_tarihi' => null, 'uyari' => null];
    $out = ['para_birimi' => $para, 'doviz_tutar' => $tutar, 'doviz_kdv' => $kdv, 'doviz_genel' => round($tutar + $kdv, 2)];
    $sk = dovizSonKur($db, $para, $tarih);
    if ($sk === null) return $out + ['tutar' => 0.0, 'kdv_tutar' => 0.0, 'genel_toplam' => 0.0, 'kur' => null, 'kur_tarihi' => null,
        'uyari' => $para . ' kuru bulunamadı; hakediş ' . $para . ' cinsinden kaydedildi, TL karşılığı fatura günü TCMB satış kuruyla hesaplanacak (Filo Referans › Tarihli döviz kurları).'];
    $tl = _dovizTlHesap($tutar, $kdvOran, $sk['kur']);
    return $out + ['tutar' => $tl['tutar'], 'kdv_tutar' => $tl['kdv_tutar'], 'genel_toplam' => $tl['genel_toplam'], 'kur' => $sk['kur'], 'kur_tarihi' => $sk['tarih'], 'uyari' => null];
}

function hakedisDovizYaz(PDO $db, int $hid, array $d): void {
    if (($d['para_birimi'] ?? 'TL') === 'TL') return;
    $db->prepare('UPDATE hakedisler SET para_birimi=?, doviz_tutar=?, doviz_kdv=?, doviz_genel=?, kur=?, kur_tarihi=?, kur_kesin=0, tutar=?, kdv_tutar=?, genel_toplam=? WHERE id=?')
       ->execute([$d['para_birimi'], $d['doviz_tutar'], $d['doviz_kdv'], $d['doviz_genel'], $d['kur'], $d['kur_tarihi'], $d['tutar'], $d['kdv_tutar'], $d['genel_toplam'], $hid]);
}

function hakedisDovizMi(array $h): bool {
    return dovizPara($h['para_birimi'] ?? 'TL') !== 'TL' && $h['doviz_tutar'] !== null && $h['doviz_tutar'] !== '';
}

function hakedisFaturaKuruUygula(PDO $db, int $hid, string $faturaTarihi, bool $agdan = true): ?array {
    $st = $db->prepare('SELECT * FROM hakedisler WHERE id=?'); $st->execute([$hid]);
    $h = $st->fetch(PDO::FETCH_ASSOC);
    if (!$h || !hakedisDovizMi($h)) return null;
    $para = dovizPara($h['para_birimi']);
    $kur = dovizKuru($db, $para, $faturaTarihi, $agdan);
    if ($kur === null || $kur <= 0)
        throw new DomainException('Hakediş ' . (($h['kod'] ?? '') ?: '#' . $hid) . ': fatura günü ' . date('d.m.Y', strtotime($faturaTarihi)) . ' için ' . $para
            . ' TCMB döviz satış kuru bulunamadı. Kuru Filo Referans › Tarihli döviz kurları bölümüne girip yeniden deneyin; döviz hakediş kursuz faturalanmaz.');
    $tl = _dovizTlHesap((float)$h['doviz_tutar'], (float)$h['kdv_oran'], $kur, $h);
    $db->prepare('UPDATE hakedisler SET kur=?, kur_tarihi=?, kur_kesin=1, tutar=?, kdv_tutar=?, genel_toplam=?, tevkifat_tutari=?, musteriden_tahsil_kdv=? WHERE id=?')
       ->execute([$kur, $faturaTarihi, $tl['tutar'], $tl['kdv_tutar'], $tl['genel_toplam'], $tl['tevkifat_tutari'], $tl['musteriden_tahsil_kdv'], $hid]);
    logla($db, 'hakedisler', $hid, 'guncelle', ['genel_toplam' => $h['genel_toplam'], 'kur' => $h['kur']], ['genel_toplam' => $tl['genel_toplam'], 'kur' => $kur],
          'Döviz hakediş fatura günü kuruyla TL\'ye çevrildi · ' . $para . ' ' . number_format((float)$h['doviz_genel'], 2, ',', '.') . ' × ' . $kur . ' = ' . number_format($tl['genel_toplam'], 2, ',', '.') . ' ₺');
    return ['kur' => $kur, 'genel_toplam' => $tl['genel_toplam']];
}

function hakedisTlGenel(PDO $db, array $h): ?float {
    if (!hakedisDovizMi($h) || (int)($h['kur_kesin'] ?? 0) === 1) return (float)$h['genel_toplam'];
    $faturali = in_array($h['durum'] ?? '', ['faturalandi', 'tahsil_edildi'], true) || trim((string)($h['fatura_no'] ?? '')) !== '';
    $tarih = $faturali && trim((string)($h['fatura_tarihi'] ?? '')) !== '' ? (string)$h['fatura_tarihi'] : date('Y-m-d');
    $sk = $faturali ? (($k = dovizKuru($db, (string)$h['para_birimi'], $tarih, false)) !== null ? ['kur' => $k] : null) : dovizSonKur($db, (string)$h['para_birimi'], $tarih, false);
    if ($sk === null) return null;
    return _dovizTlHesap((float)$h['doviz_tutar'], (float)$h['kdv_oran'], $sk['kur'], $h)['genel_toplam'];
}

function dovizHakedisGoc(PDO $db): void {
    try {
        $q = $db->query("SELECT deger FROM ayarlar WHERE anahtar='doviz_hakedis_goc'");
        if ($q && $q->fetchColumn() === '1') return;
        $db->exec("UPDATE hakedisler SET para_birimi=(SELECT UPPER(TRIM(k.doviz)) FROM kiralamalar k WHERE k.id=hakedisler.kiralama_id),
                          doviz_tutar=tutar, doviz_kdv=kdv_tutar, doviz_genel=genel_toplam, kur=NULL, kur_tarihi=NULL, kur_kesin=0
                   WHERE doviz_tutar IS NULL AND COALESCE(para_birimi,'TL')='TL'
                     AND kiralama_id IN (SELECT id FROM kiralamalar WHERE UPPER(TRIM(COALESCE(doviz,''))) IN ('EUR','USD'))");
        $db->exec("INSERT OR REPLACE INTO ayarlar(anahtar, deger) VALUES('doviz_hakedis_goc', '1')");
    } catch (\Throwable $e) { error_log('dovizHakedisGoc: ' . $e->getMessage()); }
}
