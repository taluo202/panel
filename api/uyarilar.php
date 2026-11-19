<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$sess = requireAuth();

$db    = getDb();
$today = date('Y-m-d');
$w7    = date('Y-m-d', strtotime('+7 days'));
$w15   = date('Y-m-d', strtotime('+15 days'));
$w30   = date('Y-m-d', strtotime('+30 days'));

$rol = $_SESSION['rol'] ?? '';
$yet = $_SESSION['yetkiler'] ?? '';
$izinli = fn(string $modul): bool => hasPermission($modul, 'oku');

$kisit  = subeKisiti();
$subeQ  = $kisit !== null ? $db->quote($kisit) : null;
$kSube  = $subeQ !== null ? " AND k.sube = $subeQ" : '';
$oturumPersonel = (int)($_SESSION['personel_id'] ?? 0);
$sahaHesabi = $rol !== 'admin' && $yet !== 'tum' && $oturumPersonel > 0 && !array_filter(array_map('trim', explode(',', (string)$yet)));
$teslimAlindiSql = "(k.id > COALESCE((SELECT son_eski_kiralama FROM operasyon_gecis WHERE id=1),0)"
    . " AND COALESCE((SELECT oa.sonuc FROM operasyon_adimlari oa WHERE oa.kiralama_id=k.id AND oa.adim='saha_teslim_alma' ORDER BY oa.id DESC LIMIT 1),'') IN ('tamam','atlandi'))";

$gruplar = [];
$ekle = function (string $tip, string $baslik, string $icon, string $link, array $items) use (&$gruplar) {
    if ($items) $gruplar[] = ['tip' => $tip, 'baslik' => $baslik, 'icon' => $icon, 'link' => $link, 'adet' => count($items), 'items' => $items];
};
$dShort = fn(?string $d) => $d ? (function ($p) { return count($p) === 3 ? $p[2] . '.' . $p[1] : implode('-', $p); })(explode('-', $d)) : '';
$tl = fn($n) => number_format((float)$n, 0, ',', '.') . ' ₺';

if ($izinli('kiralama_takip')) {
    try {
        $st = $db->prepare("SELECT k.id, k.kod, k.ekipman_adi, k.bitis, m.unvan AS musteri
            FROM kiralamalar k LEFT JOIN musteriler m ON m.id=k.musteri_id
            WHERE k.durum='aktif' AND k.gercek_bitis IS NULL AND k.bitis IS NOT NULL AND k.bitis<=? AND NOT $teslimAlindiSql$kSube ORDER BY k.bitis LIMIT 20");
        $st->execute([$w7]);
        $items = [];
        foreach ($st->fetchAll() as $r) $items[] = ['anahtar' => "iade:{$r['id']}:{$r['bitis']}", 'metin' => $r['musteri'] ?: ($r['ekipman_adi'] ?: $r['kod']), 'alt' => 'İade: ' . $dShort($r['bitis']) . ($r['ekipman_adi'] ? ' · ' . $r['ekipman_adi'] : ''), 'kritik' => $r['bitis'] < $today, 'tarih' => $r['bitis']];
        $ekle('iade', 'İade Yaklaşan Kiralamalar', 'event_repeat', 'kiralama-takip.html', $items);
    } catch (\Exception $e) {}
}

if ($izinli('kiralama_takip')) {
    try {
        $st = $db->query("SELECT k.id, k.kod, k.ekipman_adi, k.bitis, m.unvan AS musteri FROM kiralamalar k LEFT JOIN musteriler m ON m.id=k.musteri_id
            WHERE k.durum='aktif' AND k.gercek_bitis IS NULL AND $teslimAlindiSql$kSube ORDER BY k.bitis LIMIT 20");
        $items = [];
        foreach ($st->fetchAll() as $r) $items[] = ['anahtar' => "acikalim:{$r['id']}:$today", 'metin' => $r['musteri'] ?: ($r['ekipman_adi'] ?: $r['kod']),
            'alt' => 'Makine teslim alındı, kiralama kapanmadı' . ($r['kod'] ? ' · ' . $r['kod'] : '') . ($r['bitis'] ? ' · bitiş ' . $dShort($r['bitis']) : ''), 'kritik' => true, 'tarih' => $r['bitis']];
        $ekle('acik_alim', 'Teslim Alındı, Kiralaması Açık', 'assignment_late', 'kiralama-takip.html', $items);
    } catch (\Exception $e) {}
}

if ($izinli('kiralama_takip')) {
    try {
        $st = $db->query("
            SELECT k.id, k.kod, k.ekipman_adi, k.gercek_bitis, m.unvan AS musteri
            FROM kiralamalar k LEFT JOIN musteriler m ON m.id=k.musteri_id
            WHERE " . tutanaksizKiralamaKosulu('k') . "$kSube
            ORDER BY k.gercek_bitis DESC LIMIT 20
        ");
        $items = [];
        foreach ($st->fetchAll() as $r) $items[] = ['anahtar' => "tutanaksiz:{$r['id']}:$today", 'metin' => $r['musteri'] ?: ($r['ekipman_adi'] ?: $r['kod']), 'alt' => 'Tutanak/fotoğraf eksik · ' . $dShort($r['gercek_bitis']) . ($r['ekipman_adi'] ? ' · ' . $r['ekipman_adi'] : ''), 'kritik' => true, 'tarih' => $r['gercek_bitis']];
        $ekle('tutanaksiz', 'Tutanaksız Tamamlanan Kiralamalar', 'warning', 'iade-tutanaklari.html', $items);
    } catch (\Exception $e) {}
}

if ($izinli('kiralama_takip')) {
    try {
        $st = $db->prepare("SELECT k.id, k.kod, k.ekipman_adi, k.bitis, k.sozlesme_no, m.unvan AS musteri
            FROM kiralamalar k LEFT JOIN musteriler m ON m.id=k.musteri_id
            WHERE k.durum='aktif' AND k.otomatik_yenileme=1 AND k.gercek_bitis IS NULL AND k.bitis IS NOT NULL AND k.bitis<=?$kSube ORDER BY k.bitis LIMIT 20");
        $st->execute([$w15]);
        $items = [];
        foreach ($st->fetchAll() as $r) $items[] = ['anahtar' => "sozlesme:{$r['id']}:{$r['bitis']}", 'metin' => $r['musteri'] ?: ($r['ekipman_adi'] ?: $r['kod']), 'alt' => 'Sözleşme yenileme: ' . $dShort($r['bitis']) . ($r['sozlesme_no'] ? ' · ' . $r['sozlesme_no'] : ''), 'kritik' => $r['bitis'] < $today, 'tarih' => $r['bitis']];
        $ekle('sozlesme', 'Sözleşme Yenileme Yaklaşan', 'description', 'kiralama-takip.html', $items);
    } catch (\Exception $e) {}
}

require_once __DIR__ . '/operasyon-adim.php';
try {
    if (opOturumYetkisi($db, 'depo')) {
        $st = $db->prepare("SELECT k.id, k.kod, k.siparis_no, k.makine_id, k.durum, k.gercek_bitis, k.baslangic, m.unvan AS musteri
            FROM kiralamalar k LEFT JOIN musteriler m ON m.id=k.musteri_id
            WHERE k.kaynak='qr' AND k.durum='aktif' AND k.gercek_bitis IS NULL AND k.makine_id IS NOT NULL
              AND (k.olusturan_id IS NULL OR k.olusturan_id<>?)$kSube ORDER BY k.id DESC LIMIT 500");
        $st->execute([(int)($_SESSION['user_id'] ?? 0)]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $sira = opSiradakiToplu($db, $rows);
        $sp = [];
        foreach ($rows as $r) {
            if (($sira[(int)$r['id']] ?? null) !== 'depo_hazirlik') continue;
            $no = ($r['siparis_no'] ?? '') !== '' ? (string)$r['siparis_no'] : (string)$r['kod'];
            $sp[$no] ??= ['n' => 0, 'musteri' => (string)($r['musteri'] ?? ''), 'tarih' => $r['baslangic']];
            $sp[$no]['n']++;
            if ($r['baslangic'] && (!$sp[$no]['tarih'] || $r['baslangic'] < $sp[$no]['tarih'])) $sp[$no]['tarih'] = $r['baslangic'];
        }
        $items = [];
        foreach ($sp as $no => $s) $items[] = ['anahtar' => "qrsiparis:$no", 'metin' => $no . ' · ' . $s['n'] . ' makine',
            'alt' => $s['musteri'], 'kritik' => false, 'tarih' => $s['tarih']];
        $ekle('qrsiparis', 'QR ile Yeni Sipariş', 'qr_code_scanner', 'teknik-servis.html?sekme=kontrol&gun=hepsi', array_slice($items, 0, 20));
    }
} catch (\Exception $e) { error_log('uyarilar qrsiparis: ' . $e->getMessage()); }

if ($izinli('kiralama_takip') || $izinli('finans')) {
    try {
        require_once __DIR__ . '/zincir-lib.php';
        $_cariFark = musteriCariFarklari($db);
        $st = $db->prepare("
            SELECT m.id, m.unvan,
                   (SELECT COUNT(*) FROM hakedisler h WHERE h.musteri_id=m.id) hak_adet,
                   (SELECT COUNT(*) FROM kiralamalar k WHERE k.musteri_id=m.id) kir_adet
            FROM musteriler m
            " . ($subeQ !== null ? "WHERE m.sube = $subeQ" : '') . "
            ORDER BY m.unvan");
        $st->execute();
        $items = [];
        foreach ($st->fetchAll() as $r) {
            $_cf = $_cariFark[(int)$r['id']] ?? null;
            if ($_cf === null) continue;
            $fark = (float)$_cf['fark'];
            if ($fark <= 0.01 || (float)$_cf['tahsilat'] <= 0) continue;
            $neden = (int)$r['hak_adet'] === 0
                ? ((int)$r['kir_adet'] > 0 ? 'hiç hakediş yok · ' . (int)$r['kir_adet'] . ' kiralama' : 'hakediş yok')
                : 'hakediş var ama faturalanmamış';
            $items[] = [
                'anahtar' => "alacakeksi:{$r['id']}:" . round($fark),
                'tutar'   => $fark,
                'metin'   => $r['unvan'],
                'alt'     => $tl($fark) . ' fazla tahsilat · ' . $neden,
                'kritik'  => (int)$r['hak_adet'] === 0 && (int)$r['kir_adet'] > 0,
                'tarih'   => $today,
            ];
        }
        usort($items, fn($a, $b) => $b['tutar'] <=> $a['tutar']);
        $ekle('alacakeksi', 'Tahsilatı Var, Hakedişi Yok', 'price_check', 'hakedisler.html',
              array_slice($items, 0, 20));
    } catch (\Exception $e) {}
}

if ($izinli('kiralama_takip') || $izinli('finans')) {
    try {
        $st = $db->prepare("
            SELECT h.id, h.kod, h.kiralama_id, h.donem_bas, h.donem_bit, m.unvan
            FROM hakedisler h LEFT JOIN musteriler m ON m.id = h.musteri_id
            WHERE h.kiralama_id IS NOT NULL AND h.donem_bas IS NOT NULL AND h.donem_bit IS NOT NULL
              AND h.donem_bas <> '' AND h.donem_bit <> ''
              " . ($subeQ !== null ? "AND h.sube = $subeQ" : '') . "
            ORDER BY h.kiralama_id, h.donem_bas, h.id");
        $st->execute();
        $gruplar2 = [];
        foreach ($st->fetchAll() as $r) $gruplar2[(int)$r['kiralama_id']][] = $r;
        $items = [];
        foreach ($gruplar2 as $kid => $liste) {
            for ($i = 1; $i < count($liste); $i++) {
                $onc = $liste[$i - 1];
                $bu  = $liste[$i];
                if ($bu['donem_bas'] > $onc['donem_bit']) continue;
                $items[] = [
                    'anahtar' => "donemcakisma:{$onc['id']}:{$bu['id']}",
                    'metin'   => $bu['unvan'] ?: ('Kiralama #' . $kid),
                    'alt'     => ($onc['kod'] ?: '#' . $onc['id']) . ' ' . $dShort($onc['donem_bas']) . '–' . $dShort($onc['donem_bit'])
                               . ' ile ' . ($bu['kod'] ?: '#' . $bu['id']) . ' ' . $dShort($bu['donem_bas']) . '–' . $dShort($bu['donem_bit'])
                               . ' aynı günü kapsıyor',
                    'kritik'  => false,
                    'tarih'   => $bu['donem_bas'],
                ];
            }
        }
        $ekle('donemcakisma', 'Üst Üste Binen Hakediş Dönemi', 'event_busy', 'hakedisler.html',
              array_slice($items, 0, 20));
    } catch (\Exception $e) {}
}

if ($izinli('cek_senet')) {
    try {
        $st = $db->prepare("SELECT c.id, c.tip, c.tutar, c.vade_tarihi, c.kisi, m.unvan AS musteri
            FROM cek_senet c LEFT JOIN musteriler m ON m.id=c.musteri_id
            WHERE c.durum='portfoyde' AND c.vade_tarihi<=?" . ($subeQ !== null ? ' AND ' . cekSenetSubeIfade() . " = $subeQ" : '') . " ORDER BY c.vade_tarihi LIMIT 20");
        $st->execute([$w15]);
        $items = [];
        foreach ($st->fetchAll() as $r) $items[] = ['anahtar' => "cek:{$r['id']}:{$r['vade_tarihi']}", 'metin' => ($r['kisi'] ?: $r['musteri'] ?: ($r['tip'] === 'senet' ? 'Senet' : 'Çek')), 'alt' => ($r['tip'] === 'senet' ? 'Senet' : 'Çek') . ' · ' . $tl($r['tutar']) . ' · ' . $dShort($r['vade_tarihi']), 'kritik' => $r['vade_tarihi'] < $today, 'tarih' => $r['vade_tarihi']];
        $ekle('cek', 'Çek / Senet Vadesi', 'payments', 'cek-senet.html', $items);
    } catch (\Exception $e) {}
}

if ($izinli('personel')) {
    $kat = ['Ehliyet', 'SRC', 'Psikoteknik', 'İSG Eğitimi', 'Sağlık Raporu', 'Yüksekte Çalışma'];
    $ph = implode(',', array_fill(0, count($kat), '?'));
    try {
        $st = $db->prepare("SELECT pb.id, pb.kategori, pb.bitis_tarihi, p.ad_soyad
            FROM personel_belgeler pb JOIN personel p ON p.id=pb.personel_id
            WHERE COALESCE(p.durum,'aktif') <> 'pasif' AND pb.kategori IN ($ph) AND pb.bitis_tarihi IS NOT NULL AND (pb.arsiv IS NULL OR pb.arsiv=0) AND pb.bitis_tarihi<=? ORDER BY pb.bitis_tarihi LIMIT 20");
        $st->execute(array_merge($kat, [$w30]));
        $items = [];
        foreach ($st->fetchAll() as $r) $items[] = ['anahtar' => "personel:{$r['id']}:{$r['bitis_tarihi']}", 'metin' => $r['ad_soyad'], 'alt' => $r['kategori'] . ' · ' . $dShort($r['bitis_tarihi']), 'kritik' => $r['bitis_tarihi'] < $today, 'tarih' => $r['bitis_tarihi']];
        $ekle('personel', 'Personel Evrak Süresi', 'badge', 'personel.html', $items);
    } catch (\Exception $e) {}
}

if ($izinli('arac')) {
    try {
        $st = $db->prepare("SELECT id, plaka, trafik_bitis, kasko_bitis, tuv_muayene_bitis, periyodik_kontrol_bitis
            FROM araclar WHERE (aktif IS NULL OR aktif=1) AND (trafik_bitis<=? OR kasko_bitis<=? OR tuv_muayene_bitis<=? OR periyodik_kontrol_bitis<=?) ORDER BY plaka LIMIT 30");
        $st->execute([$w30, $w30, $w30, $w30]);
        $items = [];
        foreach ($st->fetchAll() as $r) {
            foreach (['trafik' => ['Trafik', $r['trafik_bitis']], 'kasko' => ['Kasko', $r['kasko_bitis']], 'tuv' => ['TÜV', $r['tuv_muayene_bitis']], 'periyodik' => ['Periyodik', $r['periyodik_kontrol_bitis']]] as $alanAnahtar => [$ad, $tar]) {
                if ($tar && $tar <= $w30) $items[] = ['anahtar' => "arac:{$r['id']}:{$alanAnahtar}:{$tar}", 'metin' => $r['plaka'], 'alt' => $ad . ' · ' . $dShort($tar), 'kritik' => $tar < $today, 'tarih' => $tar];
            }
        }
        usort($items, fn($a, $b) => $b['kritik'] <=> $a['kritik']);
        $ekle('arac', 'Araç Evrak / Muayene', 'directions_car', 'arac.html', array_slice($items, 0, 20));
    } catch (\Exception $e) {}
}

if ($izinli('manlift')) {
    try {
        $st = $db->prepare("SELECT id, kod, marka, periyodik_bitis FROM manliftler
            WHERE (aktif IS NULL OR aktif=1) AND periyodik_bitis IS NOT NULL AND periyodik_bitis<=? ORDER BY periyodik_bitis LIMIT 20");
        $st->execute([$w30]);
        $items = [];
        foreach ($st->fetchAll() as $r) $items[] = ['anahtar' => "manlift:{$r['id']}:{$r['periyodik_bitis']}", 'metin' => $r['kod'] . ($r['marka'] ? ' · ' . $r['marka'] : ''), 'alt' => 'Periyodik · ' . $dShort($r['periyodik_bitis']), 'kritik' => $r['periyodik_bitis'] < $today, 'tarih' => $r['periyodik_bitis']];
        $ekle('manlift', 'Makine Periyodik Kontrol', 'precision_manufacturing', 'manlift.html', $items);
    } catch (\Exception $e) {}
}

$saatBakimItems = [];
foreach ([
    ['modul' => 'arac',    'tablo' => 'araclar',    'ad' => "plaka || CASE WHEN marka IS NOT NULL AND marka<>'' THEN ' · ' || marka ELSE '' END"],
    ['modul' => 'manlift', 'tablo' => 'manliftler', 'ad' => "kod || CASE WHEN marka IS NOT NULL AND marka<>'' THEN ' · ' || marka ELSE '' END"],
] as $kaynak) {
    if (!$izinli($kaynak['modul'])) continue;
    try {
        $st = $db->query("
            SELECT id, {$kaynak['ad']} AS ad, calisma_saati, son_bakim_saat, bakim_araligi_saat
            FROM {$kaynak['tablo']}
            WHERE (aktif IS NULL OR aktif=1) AND " . saatBakimSqlKosul() . "
            LIMIT 20
        ");
        foreach ($st->fetchAll() as $r) {
            $d = saatBakimDurumu($r);
            if ($d === null || $d['durum'] === 'normal') continue;
            $sa = fn($n) => number_format(abs($n), 0, ',', '.') . ' sa';
            $saatBakimItems[] = [
                'anahtar' => "saatbakim:{$kaynak['modul']}:{$r['id']}:" . (float)($r['son_bakim_saat'] ?? 0),
                'metin'   => $r['ad'],
                'alt'     => $d['durum'] === 'gecti'
                    ? 'Bakım aralığı aşıldı · ' . $sa($d['kalan_saat']) . ' fazla çalıştı'
                    : 'Bakıma yaklaşıyor · ' . $sa($d['kalan_saat']) . ' kaldı',
                'kritik'  => $d['durum'] === 'gecti',
                'tarih'   => null,
                'kalan_saat' => $d['kalan_saat'],
            ];
        }
    } catch (\Exception $e) {}
}
usort($saatBakimItems, fn($a, $b) => $a['kalan_saat'] <=> $b['kalan_saat']);
$ekle('saatbakim', 'Saat Bazlı Bakım Zamanı', 'timer', 'teknik-servis.html', array_slice($saatBakimItems, 0, 20));

if ($izinli('yedek_parca')) {
    try {
        $st = $db->query("
            SELECT id, ad, parca_no, stok, kritik_stok, sube
            FROM yedek_parca
            WHERE COALESCE(kritik_stok,0) > 0 AND COALESCE(stok,0) <= COALESCE(kritik_stok,0)
            ORDER BY (COALESCE(stok,0) - COALESCE(kritik_stok,0)) ASC
            LIMIT 20
        ");
        $items = [];
        foreach ($st->fetchAll() as $r) {
            $stok = (int)$r['stok'];
            $items[] = [
                'anahtar' => "kritikstok:{$r['id']}:{$stok}",
                'metin'   => $r['ad'] . ($r['parca_no'] ? ' · ' . $r['parca_no'] : ''),
                'alt'     => $stok < 0
                    ? 'STOK EKSİDE: ' . $stok . ' adet (acilen sipariş edilmeli)'
                    : 'Stok ' . $stok . ' adet · kritik eşik ' . (int)$r['kritik_stok']
                       . ($r['sube'] ? ' · ' . $r['sube'] : ''),
                'kritik'  => $stok <= 0,
                'tarih'   => null,
            ];
        }
        $ekle('kritikstok', 'Kritik Stok Seviyesi', 'inventory_2', 'yedek-parca.html', $items);
    } catch (\Exception $e) {}
}

if ($izinli('teknik_servis')) {
    try {
        $st = $db->query("SELECT s.id, s.makine_ad, s.saha_sonuc, mu.unvan AS musteri,
                                 (SELECT MAX(z.id) FROM servis_ziyaretleri z WHERE z.servis_id = s.id AND z.rapor_no IS NOT NULL) AS son_ziyaret
                          FROM teknik_servis s LEFT JOIN musteriler mu ON mu.id = s.musteri_id
                          WHERE COALESCE(s.ofis_inceleme,0) = 1 ORDER BY s.updated_at DESC LIMIT 20");
        $items = [];
        foreach ($st->fetchAll() as $r) $items[] = ['anahtar' => "sahrapor:{$r['id']}:{$r['son_ziyaret']}", 'metin' => $r['makine_ad'] ?: 'Saha servisi',
            'alt' => (SS_SONUCLAR[$r['saha_sonuc']] ?? 'Rapor') . ($r['musteri'] ? ' · ' . $r['musteri'] : ''), 'kritik' => $r['saha_sonuc'] === 'subeye_gelecek', 'tarih' => $today];
        $ekle('sahrapor', 'Saha Servis Raporu Geldi', 'engineering', 'teknik-servis.html', $items);
        $pid = (int)($_SESSION['personel_id'] ?? 0);
        if ($pid > 0) {
            $st = $db->prepare("SELECT s.id, s.makine_ad, s.saha_durum, s.saha_adres, s.planlanan_tarih FROM teknik_servis s
                                WHERE s.yer='saha' AND s.teknisyen_id=? AND s.saha_durum IN ('atandi','yolda') ORDER BY COALESCE(s.planlanan_tarih,'9999-12-31') LIMIT 20");
            $st->execute([$pid]);
            $items = [];
            foreach ($st->fetchAll() as $r) $items[] = ['anahtar' => "sahais:{$r['id']}:{$r['saha_durum']}", 'metin' => $r['makine_ad'] ?: 'Saha servisi',
                'alt' => trim(($r['saha_adres'] ?: '') . ($r['planlanan_tarih'] ? ' · plan ' . $dShort($r['planlanan_tarih']) : '')), 'kritik' => false, 'tarih' => $r['planlanan_tarih']];
            $ekle('sahais', 'Saha Servis İşlerim', 'directions_car', 'saha-servis.html', $items);
        }
    } catch (\Exception $e) {}
}

if ($izinli('teknik_servis')) {
    try {
        $st = $db->query("SELECT id, makine_ad, tip, oncelik, tarih FROM teknik_servis WHERE durum<>'tamamlandi' ORDER BY (oncelik='acil') DESC, tarih DESC LIMIT 20");
        $tipEt = ['ariza' => 'Arıza', 'bakim' => 'Bakım', 'expertiz' => 'Expertiz'];
        $items = [];
        foreach ($st->fetchAll() as $r) $items[] = ['anahtar' => "servis:{$r['id']}:{$r['tarih']}", 'metin' => $r['makine_ad'] ?: ($tipEt[$r['tip']] ?? $r['tip']), 'alt' => ($tipEt[$r['tip']] ?? $r['tip']) . ' · ' . $dShort($r['tarih']), 'kritik' => $r['oncelik'] === 'acil', 'tarih' => $r['tarih']];
        $ekle('servis', 'Açık Teknik Servis', 'build_circle', 'teknik-servis.html', $items);
    } catch (\Exception $e) {}
    try {
        require_once __DIR__ . '/servis-talep-lib.php';
        $items = [];
        foreach (array_slice(stTalepListe($db, 'bekliyor'), 0, 20) as $t) $items[] = ['anahtar' => "talep:{$t['id']}", 'metin' => ($t['musteri_unvan'] ?: 'Müşteri') . ' · ' . $t['makine_ad'], 'alt' => ($t['kaynak_ad'] ?? '') . ' · ' . mb_substr((string)$t['sikayet'], 0, 60), 'kritik' => false, 'tarih' => substr((string)$t['created_at'], 0, 10)];
        $ekle('talep', 'Gelen Servis Talepleri', 'support_agent', 'teknik-servis.html?sekme=talep', $items);
    } catch (\Exception $e) {}
}

require_once __DIR__ . '/personel-onay-lib.php';
if (array_filter(array_column(poYetki(), 'oku'))) {
    try {
        $poTur = ['izin' => 'İzin talebi', 'mesai' => 'Mesai', 'masraf' => 'Masraf', 'belge' => 'Belge'];
        $items = [];
        foreach (array_slice(poListe($db, poYetki()), 0, 20) as $r) $items[] = ['anahtar' => "ponay:{$r['tur']}:{$r['id']}", 'metin' => $r['ad_soyad'] ?: 'Personel',
            'alt' => ($r['tur'] === 'mesai' && $r['alt_tur'] === 'konaklama' ? 'Konaklama' : ($poTur[$r['tur']] ?? $r['tur'])) . ($r['tarih'] ? ' · ' . $dShort((string)$r['tarih']) : ''), 'kritik' => false, 'tarih' => $r['tarih']];
        $ekle('ponay', 'Onay Bekleyen Personel Talepleri', 'how_to_reg', 'kiralama-talepleri.html?sekme=personel', $items);
    } catch (\Exception $e) {}
}

try {
    $st = $db->prepare("SELECT g.*, p.ad_soyad AS atanan FROM gorevler g LEFT JOIN personel p ON p.id=g.atanan_id
        WHERE g.durum='acik' AND g.son_tarih IS NOT NULL AND g.son_tarih<=? AND COALESCE(g.kaynak,'manuel')<>'bildirim'"
        . ($sahaHesabi ? ' AND g.atanan_id=?' : '') . " ORDER BY g.son_tarih LIMIT 200");
    $st->execute($sahaHesabi ? [$today, $oturumPersonel] : [$today]);
    $items = [];
    $gorunur = array_slice(array_values(array_filter($st->fetchAll(), fn($g) => hdGorevGorunur($db, $g))), 0, 20);
    foreach ($gorunur as $r) $items[] = ['anahtar' => "gorev:{$r['id']}:{$r['son_tarih']}", 'metin' => $r['baslik'], 'alt' => ($r['atanan'] ? '👤 ' . $r['atanan'] . ' · ' : '') . $dShort($r['son_tarih']), 'kritik' => $r['son_tarih'] < $today];
    $ekle('gorev', 'Geciken Görevler', 'checklist', 'gorevler.html', $items);
} catch (\Exception $e) {}

$userId = (int)($sess['user_id'] ?? 0);
$gorulenSet = [];
if ($userId > 0) {
    try {
        $gst = $db->prepare("SELECT oge_anahtari FROM bildirim_gorulen WHERE kullanici_id=?");
        $gst->execute([$userId]);
        foreach ($gst->fetchAll(PDO::FETCH_COLUMN) as $a) $gorulenSet[$a] = true;
    } catch (\Exception $e) {}
}
if ($gorulenSet) {
    foreach ($gruplar as &$g) {
        $g['items'] = array_values(array_filter($g['items'], fn($it) => !isset($gorulenSet[$it['anahtar'] ?? ''])));
        $g['adet'] = count($g['items']);
    }
    unset($g);
    $gruplar = array_values(array_filter($gruplar, fn($g) => $g['adet'] > 0));
}

if ($userId > 0 && !empty($_GET['goruldu'])) {
    verifyCsrf();
    try {
        $ist = $db->prepare("INSERT OR IGNORE INTO bildirim_gorulen (kullanici_id, oge_anahtari) VALUES (?, ?)");
        foreach ($gruplar as $g) foreach ($g['items'] as $it) {
            if (!empty($it['anahtar'])) $ist->execute([$userId, $it['anahtar']]);
        }
    } catch (\Exception $e) {}
}

$toplam = array_sum(array_map(fn($g) => $g['adet'], $gruplar));
$kritik = 0;
foreach ($gruplar as $g) foreach ($g['items'] as $it) if (!empty($it['kritik'])) $kritik++;

jsonOk(['toplam' => $toplam, 'kritik' => $kritik, 'gruplar' => $gruplar]);
