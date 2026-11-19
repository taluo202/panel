<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$sess = requireAuth();

$db = getDb();

$today    = date('Y-m-d');
$warn30   = date('Y-m-d', strtotime('+30 days'));

$canArac     = hasPermission('arac');
$canManlift  = hasPermission('manlift');
$canPersonel = hasPermission('personel');
$canKiralama = hasPermission('kiralama_takip');
$canCekSenet = hasPermission('cek_senet');

$kullaniciSube = '';
try {
    $uSt = $db->prepare("SELECT sube FROM users WHERE id=?");
    $uSt->execute([$sess['user_id']]);
    $kullaniciSube = trim((string)($uSt->fetchColumn() ?: ''));
} catch (\Throwable $e) {}

$kisit = subeKisiti();

$subeOzet = null;
if ($kullaniciSube !== '' && $canKiralama) {
    try {
        $musteriSubeMap = [];
        foreach ($db->query("SELECT id, sube FROM musteriler")->fetchAll() as $r) $musteriSubeMap[(int)$r['id']] = $r['sube'];

        $kAll = $db->query("SELECT id, musteri_id, sube, durum FROM kiralamalar WHERE durum<>'iptal'")->fetchAll();
        $ciroAy = 0.0; $aktifSayi = 0; $ayBas = date('Y-m-01');
        $subeMusteri = [];
        foreach ($kAll as $k) {
            $sb = trim((string)($k['sube'] ?? '')) ?: trim((string)($musteriSubeMap[(int)$k['musteri_id']] ?? ''));
            if ($sb !== $kullaniciSube) continue;
            $subeMusteri[(int)$k['musteri_id']] = true;
            if ($k['durum'] === 'aktif') $aktifSayi++;
        }
        $tsAll = $db->query("SELECT musteri_id, tarih, tutar FROM tahsilatlar")->fetchAll();
        $tahsilToplam = 0.0;
        foreach ($tsAll as $t) {
            $sb = trim((string)($musteriSubeMap[(int)$t['musteri_id']] ?? ''));
            if ($sb !== $kullaniciSube) continue;
            $tahsilToplam += (float)$t['tutar'];
            if ($t['tarih'] >= $ayBas) $ciroAy += (float)$t['tutar'];
        }

        $_subeIdler = [];
        foreach (array_keys(cariBorcKalemleri($db)) as $mid) {
            $sb = trim((string)($musteriSubeMap[(int)$mid] ?? ''));
            if ($sb === $kullaniciSube || isset($subeMusteri[(int)$mid])) $_subeIdler[] = (int)$mid;
        }
        $_subeAlacak = alacakOzeti($db, $_subeIdler);

        $iadeSube = [];
        $ki2 = $db->prepare("
            SELECT k.id, k.kod, k.ekipman_adi, k.bitis, k.lokasyon, k.sube, m.unvan AS musteri
            FROM kiralamalar k LEFT JOIN musteriler m ON m.id=k.musteri_id
            WHERE k.durum='aktif' AND k.gercek_bitis IS NULL AND k.bitis IS NOT NULL AND k.bitis <= ?
            ORDER BY k.bitis LIMIT 30
        ");
        $ki2->execute([date('Y-m-d', strtotime('+7 days'))]);
        foreach ($ki2->fetchAll() as $k) {
            $sb = trim((string)($k['sube'] ?? '')) ?: trim((string)($musteriSubeMap[(int)($k['musteri_id'] ?? 0)] ?? ''));
            if ($sb !== $kullaniciSube) continue;
            $k['durum_etiket'] = ($k['bitis'] < $today) ? 'kritik' : 'yaklasan';
            $iadeSube[] = $k;
        }

        $subeOzet = [
            'sube'            => $kullaniciSube,
            'aktif_kiralama'  => $aktifSayi,
            'acik_alacak'     => $_subeAlacak['acik'],
            'bu_ay_tahsilat'  => round($ciroAy, 2),
            'iade_yaklasan'   => $iadeSube,
        ];
    } catch (\Throwable $e) {}
}

$araclar_uyari = [];
if ($canArac) {
    $aracUyari = $db->prepare("
        SELECT id, plaka,
            trafik_bitis, kasko_bitis, makine_kirilma_bitis,
            tuv_muayene_bitis, periyodik_kontrol_bitis,
            calisma_saati, son_bakim_saat, bakim_araligi_saat
        FROM araclar
        WHERE (aktif IS NULL OR aktif=1) AND (
             trafik_bitis <= :w OR kasko_bitis <= :w
           OR makine_kirilma_bitis <= :w OR tuv_muayene_bitis <= :w
           OR periyodik_kontrol_bitis <= :w
           OR " . saatBakimSqlKosul() . " )
        ORDER BY MIN(
            COALESCE(trafik_bitis,'9999'),
            COALESCE(kasko_bitis,'9999'),
            COALESCE(tuv_muayene_bitis,'9999'),
            COALESCE(periyodik_kontrol_bitis,'9999')
        )
    ");
    $aracUyari->execute([':w' => $warn30]);
    $araclar_uyari = $aracUyari->fetchAll();
}

function saatBakimDurum(array $row): ?array {
    $d = saatBakimDurumu($row);
    if ($d === null || $d['durum'] === 'normal') return null;
    return [
        'durum'         => $d['durum'] === 'gecti' ? 'kritik' : 'yaklasan',
        'kalan_saat'    => $d['kalan_saat'],
        'calisma_saati' => $d['calisma_saati'],
    ];
}

$personel_uyari = [];
if ($canPersonel) {
    $kritikKategoriler = ['Ehliyet', 'SRC', 'Psikoteknik', 'İSG Eğitimi', 'Sağlık Raporu', 'Yüksekte Çalışma'];
    $placeholders = implode(',', array_fill(0, count($kritikKategoriler), '?'));
    $pStmt = $db->prepare("
        SELECT pb.id, pb.belge_adi, pb.bitis_tarihi, pb.kategori,
               p.id as personel_id, p.ad_soyad
        FROM personel_belgeler pb
        JOIN personel p ON p.id = pb.personel_id
        WHERE COALESCE(p.durum,'aktif') <> 'pasif' AND pb.kategori IN ($placeholders)
          AND pb.bitis_tarihi IS NOT NULL
          AND (pb.arsiv IS NULL OR pb.arsiv=0)
          AND pb.bitis_tarihi <= ?
        ORDER BY pb.bitis_tarihi
    ");
    $pStmt->execute(array_merge($kritikKategoriler, [$warn30]));
    $personel_uyari = $pStmt->fetchAll();
}

$manlift_uyari = [];
if ($canManlift) {
    $mStmt = $db->prepare("
        SELECT id, kod, marka, model, periyodik_bitis, calisma_saati, son_bakim_saat, bakim_araligi_saat
        FROM manliftler
        WHERE (aktif IS NULL OR aktif=1) AND (
            (periyodik_bitis IS NOT NULL AND periyodik_bitis <= ?)
            OR " . saatBakimSqlKosul() . "
        )
        ORDER BY periyodik_bitis
    ");
    $mStmt->execute([$warn30]);
    $manlift_uyari = $mStmt->fetchAll();
    foreach ($manlift_uyari as &$_m) { $_m['saat_bakim'] = saatBakimDurum($_m); }
    unset($_m);
}

$izinli_personeller = [];
if ($canPersonel) {
    $izinliStmt = $db->prepare("
        SELECT it.id, it.baslangic, it.bitis, it.gun_sayisi, it.izin_turu,
               p.id as personel_id, p.ad_soyad
        FROM izin_talepleri it
        JOIN personel p ON p.id = it.personel_id
        WHERE it.durum = 'onaylandi'
          AND it.izin_turu NOT IN ('ekstra_hak')
          AND it.baslangic <= :today AND it.bitis >= :today
        ORDER BY it.baslangic
        LIMIT 10
    ");
    $izinliStmt->execute([':today' => $today]);
    $izinli_personeller = $izinliStmt->fetchAll();
}

$belgeParcalari = [];
if ($canPersonel) $belgeParcalari[] = "SELECT 'personel' as tur, belge_adi, created_at, personel_id as ilgili_id FROM personel_belgeler WHERE dosya_yolu IS NOT NULL";
if ($canArac)     $belgeParcalari[] = "SELECT 'arac'    as tur, belge_adi, created_at, arac_id    as ilgili_id FROM arac_belgeler    WHERE dosya_yolu IS NOT NULL";
if ($canManlift)  $belgeParcalari[] = "SELECT 'manlift' as tur, belge_adi, created_at, manlift_id as ilgili_id FROM manlift_belgeler WHERE dosya_yolu IS NOT NULL";
$sonBelgeler = $belgeParcalari
    ? $db->query(implode(' UNION ALL ', $belgeParcalari) . ' ORDER BY created_at DESC LIMIT 5')->fetchAll()
    : [];

$toplam_arac     = $canArac     ? (int)$db->query("SELECT COUNT(*) FROM araclar WHERE aktif IS NULL OR aktif=1")->fetchColumn() : 0;
$toplam_personel = $canPersonel ? (int)$db->query("SELECT COUNT(*) FROM personel WHERE durum='aktif'")->fetchColumn() : 0;
$toplam_manlift  = $canManlift  ? (int)$db->query("SELECT COUNT(*) FROM manliftler WHERE aktif IS NULL OR aktif=1")->fetchColumn() : 0;
$izinli_personel = count($izinli_personeller);

$_subeMusteriIdler = null;
if ($kisit !== null) {
    $_smSt = $db->prepare("SELECT id FROM musteriler WHERE TRIM(COALESCE(sube,'')) = ?");
    $_smSt->execute([$kisit]);
    $_subeMusteriIdler = array_map('intval', $_smSt->fetchAll(PDO::FETCH_COLUMN));
}

$aktif_kiralama = 0; $acik_alacak = 0.0; $bu_ay_tahsilat = 0.0; $faturalanmamis = 0.0;
if ($canKiralama) {
    try {
        if ($kisit !== null) {
            $s = $db->prepare("SELECT COUNT(*) FROM kiralamalar WHERE durum='aktif' AND sube=?");
            $s->execute([$kisit]);
            $aktif_kiralama = (int)$s->fetchColumn();
        } else {
            $aktif_kiralama = (int)$db->query("SELECT COUNT(*) FROM kiralamalar WHERE durum='aktif'")->fetchColumn();
        }

        $_alacak = alacakOzeti($db, $_subeMusteriIdler, $today);
        $faturalanmamis = $_alacak['faturalanmamis'];
        if ($_subeMusteriIdler === null) {
            $toplamTahsil = (float)$db->query("SELECT COALESCE(SUM(tutar),0) FROM tahsilatlar")->fetchColumn();
            $st = $db->prepare("SELECT COALESCE(SUM(tutar),0) FROM tahsilatlar WHERE tarih >= ?");
            $st->execute([date('Y-m-01')]);
            $bu_ay_tahsilat = (float)$st->fetchColumn();
        } elseif ($_subeMusteriIdler) {
            $ph = implode(',', array_fill(0, count($_subeMusteriIdler), '?'));
            $st = $db->prepare("SELECT COALESCE(SUM(tutar),0) FROM tahsilatlar WHERE musteri_id IN ($ph)");
            $st->execute($_subeMusteriIdler);
            $toplamTahsil = (float)$st->fetchColumn();
            $st2 = $db->prepare("SELECT COALESCE(SUM(tutar),0) FROM tahsilatlar WHERE tarih >= ? AND musteri_id IN ($ph)");
            $st2->execute(array_merge([date('Y-m-01')], $_subeMusteriIdler));
            $bu_ay_tahsilat = (float)$st2->fetchColumn();
        } else {
            $toplamTahsil = 0.0;
        }
        $acik_alacak = $_alacak['acik'];
    } catch (\Exception $e) {}
}

$geciken_adet = 0; $geciken_tutar = 0.0;
if ($canKiralama) {
    try {
        $_g = $_alacak ?? alacakOzeti($db, $_subeMusteriIdler, $today);
        $geciken_adet  = $_g['geciken_adet'];
        $geciken_tutar = $_g['geciken'];
    } catch (\Exception $e) {}
}

$warn7 = date('Y-m-d', strtotime('+7 days'));
$kiralama_iade = [];
if ($canKiralama) {
    try {
        $where = ["k.durum='aktif'", 'k.gercek_bitis IS NULL', 'k.bitis IS NOT NULL', 'k.bitis <= ?'];
        $params = [$warn7];
        if ($kisit !== null) { $where[] = 'k.sube=?'; $params[] = $kisit; }
        $ki = $db->prepare("
            SELECT k.id, k.kod, k.ekipman_adi, k.bitis, k.lokasyon, k.sube,
                   m.unvan AS musteri
            FROM kiralamalar k
            LEFT JOIN musteriler m ON m.id = k.musteri_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY k.bitis
            LIMIT 30
        ");
        $ki->execute($params);
        foreach ($ki->fetchAll() as $k) {
            $k['durum_etiket'] = ($k['bitis'] < $today) ? 'kritik' : 'yaklasan';
            $kiralama_iade[] = $k;
        }
    } catch (\Exception $e) {}
}

$teklif_suresi = [];
if ($canKiralama) {
    try {
        $where = ["t.durum IN ('taslak','gonderildi')", 't.gecerlilik IS NOT NULL', 't.gecerlilik <= ?'];
        $params = [$warn7];
        if ($kisit !== null) { $where[] = 't.sube=?'; $params[] = $kisit; }
        $ts = $db->prepare("
            SELECT t.id, t.kod, t.tarih, t.gecerlilik, t.durum, m.unvan AS musteri
            FROM teklifler t
            LEFT JOIN musteriler m ON m.id = t.musteri_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY t.gecerlilik
            LIMIT 30
        ");
        $ts->execute($params);
        foreach ($ts->fetchAll() as $t) {
            $t['durum_etiket'] = ($t['gecerlilik'] < $today) ? 'kritik' : 'yaklasan';
            $teklif_suresi[] = $t;
        }
    } catch (\Exception $e) {}
}

$cek_senet = [];
if ($canCekSenet) {
    try {
        $where = ["c.durum='portfoyde'", 'c.vade_tarihi <= ?'];
        $params = [$warn30];
        if ($kisit !== null) { $where[] = cekSenetSubeIfade() . ' = ?'; $params[] = $kisit; }
        $cs = $db->prepare("
            SELECT c.id, c.tip, c.yon, c.tutar, c.vade_tarihi, c.banka, c.kisi, m.unvan AS musteri
            FROM cek_senet c LEFT JOIN musteriler m ON m.id = c.musteri_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY c.vade_tarihi
            LIMIT 30
        ");
        $cs->execute($params);
        foreach ($cs->fetchAll() as $c) {
            $c['durum_etiket'] = ($c['vade_tarihi'] < $today) ? 'kritik' : 'yaklasan';
            $cek_senet[] = $c;
        }
    } catch (\Exception $e) {}
}

$_sahaRol = $_SESSION['rol'] ?? ''; $_sahaYet = (string)($_SESSION['yetkiler'] ?? ''); $_sahaPid = (int)($_SESSION['personel_id'] ?? 0);
$sahaHesabi = $_sahaRol !== 'admin' && $_sahaYet !== 'tum' && $_sahaPid > 0 && !array_filter(array_map('trim', explode(',', $_sahaYet)));
require_once __DIR__ . '/hasar-akis.php';
$gorevler = [];
try {
    $gr = $db->prepare("
        SELECT g.id, g.baslik, g.son_tarih, g.oncelik, g.kaynak_modul, g.kaynak_id, g.atanan_id, p.ad_soyad AS atanan
        FROM gorevler g LEFT JOIN personel p ON p.id=g.atanan_id
        WHERE g.durum='acik'" . ($sahaHesabi ? ' AND g.atanan_id=?' : '') . "
        ORDER BY (g.son_tarih IS NULL), g.son_tarih ASC, g.id DESC
        LIMIT 40
    ");
    $gr->execute($sahaHesabi ? [$_sahaPid] : []);
    $gorevSatirlari = array_slice(array_values(array_filter($gr->fetchAll(), fn($g) => hdGorevGorunur($db, $g))), 0, 8);
    foreach ($gorevSatirlari as $g) {
        $g['durum_etiket'] = ($g['son_tarih'] && $g['son_tarih'] < $today) ? 'kritik'
                            : (($g['son_tarih'] && $g['son_tarih'] <= $warn7) ? 'yaklasan' : 'normal');
        $gorevler[] = $g;
    }
} catch (\Exception $e) {}

$musait_makine = 0; $toplam_makine = 0;
foreach (['manlift' => [$canManlift, 'manliftler'], 'arac' => [$canArac, 'araclar']] as $_tur => [$_can, $_tablo]) {
    if (!$_can) continue;
    try {
        $_kirada = array_flip(makineKiradaSeti($db, $_tur));
        $_servis = array_flip(makineAcikServisSeti($db, $_tur));
        foreach ($db->query("SELECT id, durum FROM $_tablo WHERE aktif IS NULL OR aktif=1")->fetchAll() as $_m) {
            $toplam_makine++;
            $_mid = (int)$_m['id'];
            if (((string)($_m['durum'] ?? '') ?: 'musait') === 'musait' && !isset($_kirada[$_mid]) && !isset($_servis[$_mid])) $musait_makine++;
        }
    } catch (\Throwable $e) {}
}
$acik_servis = 0; $acil_servis = 0; $bekleyen_talep = 0;
if (hasPermission('teknik_servis')) {
    try { require_once __DIR__ . '/servis-talep-lib.php'; $bekleyen_talep = stBekleyenSayi($db); } catch (\Throwable $e) {}
    try {
        $_ts = $db->query("SELECT COUNT(*) AS n, COALESCE(SUM(oncelik='acil'),0) AS acil FROM teknik_servis WHERE durum<>'tamamlandi'")->fetch();
        $acik_servis = (int)$_ts['n']; $acil_servis = (int)$_ts['acil'];
    } catch (\Throwable $e) {}
}
$bekleyen_hakedis_adet = 0; $bekleyen_hakedis_tutar = 0.0;
if ($canKiralama) {
    try {
        $_hw = "h.durum='beklemede' AND COALESCE(h.fatura_no,'')=''"; $_hp = [];
        if ($kisit !== null) { $_hw .= ' AND ' . hakedisSubeSql('h', 'm') . '=?'; $_hp[] = $kisit; }
        $_hs = $db->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(h.genel_toplam),0) AS t FROM hakedisler h LEFT JOIN musteriler m ON m.id=h.musteri_id WHERE $_hw");
        $_hs->execute($_hp);
        $_hr = $_hs->fetch();
        $bekleyen_hakedis_adet = (int)$_hr['n']; $bekleyen_hakedis_tutar = round((float)$_hr['t'], 2);
    } catch (\Throwable $e) {}
}

function classifyDate(string $today, string $warn30, ?string $date): string {
    if (!$date) return 'yok';
    if ($date < $today)   return 'kritik';
    if ($date <= $warn30) return 'yaklasan';
    return 'tamam';
}

$araclarDetay = array_map(function ($a) use ($today, $warn30) {
    $tarihler = [
        'Trafik Sigortası'        => $a['trafik_bitis'],
        'Kasko'                   => $a['kasko_bitis'],
        'Makine Kırılma'          => $a['makine_kirilma_bitis'],
        'TÜV Muayene'             => $a['tuv_muayene_bitis'],
        'Periyodik Kontrol'       => $a['periyodik_kontrol_bitis'],
    ];
    $uyarilar = [];
    foreach ($tarihler as $tur => $tarih) {
        $s = classifyDate($today, $warn30, $tarih);
        if ($s !== 'tamam' && $s !== 'yok') {
            $uyarilar[] = ['tur' => $tur, 'tarih' => $tarih, 'durum' => $s];
        }
    }
    $saat = saatBakimDurum($a);
    if ($saat) {
        $uyarilar[] = ['tur' => 'Bakım (Saat)', 'tarih' => null, 'durum' => $saat['durum'], 'kalan_saat' => $saat['kalan_saat']];
    }
    return ['id' => (int)$a['id'], 'plaka' => $a['plaka'], 'uyarilar' => $uyarilar];
}, $araclar_uyari);

jsonOk([
    'ozet' => [
        'toplam_arac'     => $toplam_arac,
        'aktif_personel'  => $toplam_personel,
        'toplam_manlift'  => $toplam_manlift,
        'izinli_personel' => $izinli_personel,
        'aktif_kiralama'  => $aktif_kiralama,
        'acik_alacak'     => $acik_alacak,
        'faturalanmamis'  => round($faturalanmamis, 2),
        'bu_ay_tahsilat'  => $bu_ay_tahsilat,
        'geciken_adet'    => $geciken_adet,
        'geciken_tutar'   => $geciken_tutar,
        'musait_makine'   => $musait_makine,
        'toplam_makine'   => $toplam_makine,
        'acik_servis'     => $acik_servis,
        'bekleyen_talep'  => $bekleyen_talep,
        'acil_servis'     => $acil_servis,
        'bekleyen_hakedis_adet'  => $bekleyen_hakedis_adet,
        'bekleyen_hakedis_tutar' => $bekleyen_hakedis_tutar,
    ],
    'arac_uyarilari'      => $araclarDetay,
    'personel_uyarilari'  => $personel_uyari,
    'manlift_uyarilari'   => $manlift_uyari,
    'izinli_personeller'  => $izinli_personeller,
    'son_belgeler'        => $sonBelgeler,
    'kiralama_iade'       => $kiralama_iade,
    'teklif_suresi'       => $teklif_suresi,
    'cek_senet'           => $cek_senet,
    'gorevler'            => $gorevler,
    'sube_ozet'           => $subeOzet,
]);
