<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/belge-zinciri-lib.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('rapor');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonError('Method Not Allowed', 405);

$db  = getDb();
$tur = trim(metin($_GET['tur'] ?? ''));

$canPersonel = hasPermission('personel');
$canArac     = hasPermission('arac');
$canManlift  = hasPermission('manlift');
$canFinans   = hasPermission('finans');
$canKiralama = hasPermission('kiralama_takip');
$canParca    = hasPermission('yedek_parca');
$turYetki = [
    'personel_belgeler' => $canPersonel,
    'arac'              => $canArac,
    'manlift'           => $canManlift,
    'finans'            => $canFinans,
    'kiralama'          => $canKiralama,
    'roi'               => $canArac && $canManlift,
    'babs'              => $canFinans,
    'pnl'               => $canFinans,
    'parca_kar'         => $canParca,
    'hasar'             => $canKiralama,
];
if (isset($turYetki[$tur]) && !$turYetki[$tur]) {
    jsonError('Bu rapor türü için ayrıca ilgili modül yetkisi gerekir.', 403);
}

switch ($tur) {

    case 'personel_belgeler':
        $stmt = $db->query("
            SELECT p.id, p.ad_soyad, p.pozisyon, p.durum,
                   pb.kategori, pb.belge_adi, pb.bitis_tarihi
            FROM personel p
            LEFT JOIN personel_belgeler pb ON pb.personel_id = p.id
            WHERE p.durum != 'pasif'
            ORDER BY p.ad_soyad, pb.kategori, pb.bitis_tarihi
        ");
        jsonOk($stmt->fetchAll());
        break;

    case 'arac':
        $stmt = $db->query("
            SELECT plaka, marka, model, tescil_tarihi,
                   trafik_bitis, trafik_sigorta_tutar,
                   kasko_bitis, kasko_tutar,
                   makine_kirilma_bitis, tuv_muayene_bitis, periyodik_kontrol_bitis
            FROM araclar
            ORDER BY plaka
        ");
        jsonOk($stmt->fetchAll());
        break;

    case 'manlift':
        $stmt = $db->query("
            SELECT kod, marka, model, seri_no, imal_yili, periyodik_bitis
            FROM manliftler
            ORDER BY kod
        ");
        jsonOk($stmt->fetchAll());
        break;

    case 'finans':
        $ay = trim(metin($_GET['ay'] ?? date('Y-m')));
        if (!preg_match('/^\d{4}-\d{2}$/', $ay)) $ay = date('Y-m');
        $stmt = $db->prepare("
            SELECT p.ad_soyad, p.pozisyon,
                   COALESCE(f.toplam_maas, 0)   AS toplam_maas,
                   COALESCE(f.mesai, 0)          AS mesai,
                   COALESCE(f.haciz_kesinti, 0)  AS haciz_kesinti,
                   COALESCE(f.avans, 0)          AS avans,
                   COALESCE(f.bankaya_yatan, 0)  AS bankaya_yatan,
                   COALESCE(f.elden_kalan, 0)    AS elden_kalan,
                   COALESCE(f.bes, 0)            AS bes
            FROM personel p
            LEFT JOIN finans f ON f.personel_id = p.id AND f.ay = ?
            WHERE p.durum != 'pasif'
            ORDER BY p.ad_soyad
        ");
        $stmt->execute([$ay]);
        jsonOk(['ay' => $ay, 'rows' => $stmt->fetchAll()]);
        break;

    case 'kiralama':
        $rentals = $db->query("SELECT k.*, m.unvan AS musteri_unvan, m.sube AS musteri_sube FROM kiralamalar k JOIN musteriler m ON m.id=k.musteri_id")->fetchAll();
        $_kisit = subeKisiti();
        $_kisitMusteri = null;
        if ($_kisit !== null) {
            $rentals = array_values(array_filter($rentals, fn($r) => (trim((string)($r['sube'] ?? '')) ?: trim((string)($r['musteri_sube'] ?? ''))) === $_kisit));
            $_q = $db->prepare("SELECT id FROM musteriler WHERE TRIM(COALESCE(sube,''))=?"); $_q->execute([$_kisit]);
            $_kisitMusteri = array_map('intval', $_q->fetchAll(PDO::FETCH_COLUMN));
        }

        $aylar = [];
        for ($i = 11; $i >= 0; $i--) $aylar[date('Y-m', strtotime("first day of -$i month"))] = 0.0;
        $aylikKira = $aylar;
        $aylikTahsil = $aylar;

        $ekipman = []; $durum = ['aktif'=>0,'tamamlandi'=>0,'iptal'=>0];
        $musteriCiro = [];
        $toplamCiro = 0.0; $aktif = 0;

        foreach ($rentals as $r) {
            $h = kiralamaTutar($r);
            $gt = $h['genel_toplam'];
            $toplamCiro += $gt;
            $durum[$r['durum']] = ($durum[$r['durum']] ?? 0) + 1;
            if ($r['durum'] === 'aktif') $aktif++;

            foreach (array_keys($aylar) as $ay) {
                $ayBas = $ay . '-01';
                $ayBit = date('Y-m-t', strtotime($ayBas));
                $pay = kiralamaTutarPencereli($r, $ayBas, $ayBit);
                if ($pay !== null) $aylikKira[$ay] += $pay;
            }

            $ek = $r['ekipman_adi'] ?: '—';
            $ekipman[$ek] = ($ekipman[$ek] ?? 0) + $gt;

            $mu = $r['musteri_unvan'] ?: '—';
            $musteriCiro[$mu] = ($musteriCiro[$mu] ?? 0) + $gt;
        }

        $toplamTahsil = 0.0;
        $_kisitSet = $_kisitMusteri === null ? null : array_flip($_kisitMusteri);
        foreach ($db->query("SELECT tarih, tutar, musteri_id FROM tahsilatlar")->fetchAll() as $t) {
            if ($_kisitSet !== null && !isset($_kisitSet[(int)$t['musteri_id']])) continue;
            $toplamTahsil += (float)$t['tutar'];
            $ay = substr((string)$t['tarih'], 0, 7);
            if (isset($aylikTahsil[$ay])) $aylikTahsil[$ay] += (float)$t['tutar'];
        }
        $_alacak = alacakOzeti($db, $_kisitMusteri);

        arsort($ekipman); arsort($musteriCiro);
        $ekipmanTop = array_slice($ekipman, 0, 8, true);
        $musteriTop = array_slice($musteriCiro, 0, 5, true);

        jsonOk([
            'ozet' => [
                'toplam_kiralama' => count($rentals),
                'aktif'           => $aktif,
                'toplam_ciro'     => round($toplamCiro, 2),
                'toplam_tahsilat' => round($toplamTahsil, 2),
                'acik_alacak'     => $_alacak['acik'],
                'faturalanmamis'  => $_alacak['faturalanmamis'],
            ],
            'aylik' => [
                'etiketler' => array_keys($aylikKira),
                'kira'      => array_map(fn($v)=>round($v,2), array_values($aylikKira)),
                'tahsilat'  => array_map(fn($v)=>round($v,2), array_values($aylikTahsil)),
            ],
            'ekipman' => ['etiketler'=>array_keys($ekipmanTop), 'degerler'=>array_map(fn($v)=>round($v,2),array_values($ekipmanTop))],
            'durum'   => $durum,
            'musteri' => ['etiketler'=>array_keys($musteriTop), 'degerler'=>array_map(fn($v)=>round($v,2),array_values($musteriTop))],
        ]);
        break;

    case 'roi':
        $gelirMap = [];
        foreach ($db->query("SELECT * FROM kiralamalar WHERE makine_tur IS NOT NULL AND makine_id IS NOT NULL")->fetchAll() as $k) {
            $key = $k['makine_tur'] . '_' . $k['makine_id'];
            $gelirMap[$key] = ($gelirMap[$key] ?? 0) + kiralamaTutar($k)['genel_toplam'];
        }
        $giderMap = [];
        try {
            foreach ($db->query("SELECT makine_tur, makine_id, COALESCE(SUM(maliyet),0) t FROM teknik_servis WHERE makine_id IS NOT NULL GROUP BY makine_tur, makine_id")->fetchAll() as $g) {
                $giderMap[$g['makine_tur'] . '_' . $g['makine_id']] = (float)$g['t'];
            }
        } catch (\Exception $e) { hataGunlugu('rapor.servis_maliyet', $e, true); }
        try {
            foreach ($db->query("SELECT makine_tur, makine_id, COALESCE(SUM(tutar),0) t FROM giderler GROUP BY makine_tur, makine_id")->fetchAll() as $g) {
                $key = $g['makine_tur'] . '_' . $g['makine_id'];
                $giderMap[$key] = ($giderMap[$key] ?? 0) + (float)$g['t'];
            }
        } catch (\Exception $e) { hataGunlugu('rapor.giderler', $e, true); }

        $rows = [];
        foreach ($db->query("SELECT id, plaka AS ad, marka, model, alis_bedeli, faydali_omur_yil FROM araclar WHERE aktif IS NULL OR aktif=1")->fetchAll() as $a) {
            $key = 'arac_' . $a['id'];
            $gelir = round($gelirMap[$key] ?? 0, 2);
            $gider = round($giderMap[$key] ?? 0, 2);
            $alis  = (float)($a['alis_bedeli'] ?? 0);
            $rows[] = [
                'tur' => 'Araç', 'id' => (int)$a['id'], 'ad' => $a['ad'],
                'detay' => trim(($a['marka'] ?? '') . ' ' . ($a['model'] ?? '')),
                'alis_bedeli' => $alis, 'faydali_omur_yil' => (int)($a['faydali_omur_yil'] ?? 0),
                'gelir' => $gelir, 'gider' => $gider, 'net' => round($gelir - $gider, 2),
                'amortisman_orani' => $alis > 0 ? round(($gelir / $alis) * 100, 1) : null,
            ];
        }
        foreach ($db->query("SELECT id, kod AS ad, marka, model, alis_bedeli, faydali_omur_yil FROM manliftler WHERE aktif IS NULL OR aktif=1")->fetchAll() as $m) {
            $key = 'manlift_' . $m['id'];
            $gelir = round($gelirMap[$key] ?? 0, 2);
            $gider = round($giderMap[$key] ?? 0, 2);
            $alis  = (float)($m['alis_bedeli'] ?? 0);
            $rows[] = [
                'tur' => 'Makine', 'id' => (int)$m['id'], 'ad' => $m['ad'],
                'detay' => trim(($m['marka'] ?? '') . ' ' . ($m['model'] ?? '')),
                'alis_bedeli' => $alis, 'faydali_omur_yil' => (int)($m['faydali_omur_yil'] ?? 0),
                'gelir' => $gelir, 'gider' => $gider, 'net' => round($gelir - $gider, 2),
                'amortisman_orani' => $alis > 0 ? round(($gelir / $alis) * 100, 1) : null,
            ];
        }
        usort($rows, fn($a, $b) => $b['net'] <=> $a['net']);

        $toplamAlis = round(array_sum(array_column($rows, 'alis_bedeli')), 2);
        $toplamGelir = round(array_sum(array_column($rows, 'gelir')), 2);
        $toplamGider = round(array_sum(array_column($rows, 'gider')), 2);

        jsonOk([
            'rows' => $rows,
            'ozet' => [
                'toplam_alis'  => $toplamAlis,
                'toplam_gelir' => $toplamGelir,
                'toplam_gider' => $toplamGider,
                'toplam_net'   => round($toplamGelir - $toplamGider, 2),
            ],
        ]);
        break;

    case 'babs':
        $ay = trim(metin($_GET['ay'] ?? date('Y-m')));
        if (!preg_match('/^\d{4}-\d{2}$/', $ay)) $ay = date('Y-m');
        $ayBas = $ay . '-01';
        $ayBit = date('Y-m-t', strtotime($ayBas));

        $stmt = $db->query("
            SELECT k.*, m.unvan AS m_unvan, m.vergi_no AS m_vergi_no, m.vergi_dairesi AS m_vergi_dairesi
            FROM kiralamalar k JOIN musteriler m ON m.id = k.musteri_id
            WHERE k.durum<>'iptal'
        ");

        $matrah = 0.0; $kdv = 0.0; $musteriMap = [];
        foreach ($stmt->fetchAll() as $k) {
            $pay = kiralamaTutarPencereli($k, $ayBas, $ayBit) ?? 0.0;
            $tekSeferlik = 0.0;
            if (substr((string)$k['baslangic'], 0, 7) === $ay) {
                $h = kiralamaTutar($k);
                $tekSeferlik = round((float)$h['nakliye'] + (float)$h['operator_bedeli'], 2);
            }
            $payToplam = round($pay + $tekSeferlik, 2);
            if ($payToplam <= 0) continue;
            $kdvOran = (float)($k['kdv_oran'] ?? 20);
            $kdvPay  = round($payToplam * $kdvOran / 100, 2);
            $matrah += $payToplam;
            $kdv    += $kdvPay;
            $key = (int)$k['musteri_id'];
            if (!isset($musteriMap[$key])) {
                $musteriMap[$key] = ['unvan' => $k['m_unvan'], 'vergi_no' => $k['m_vergi_no'], 'vergi_dairesi' => $k['m_vergi_dairesi'], 'tutar' => 0.0];
            }
            $musteriMap[$key]['tutar'] += round($payToplam + $kdvPay, 2);
        }

        $esik = 5000.0;
        $bsListe = [];
        foreach ($musteriMap as $mm) {
            if ($mm['tutar'] >= $esik && !empty($mm['vergi_no'])) {
                $mm['tutar'] = round($mm['tutar'], 2);
                $bsListe[] = $mm;
            }
        }
        usort($bsListe, fn($a, $b) => $b['tutar'] <=> $a['tutar']);

        jsonOk([
            'ay'  => $ay,
            'kdv' => [
                'matrah'       => round($matrah, 2),
                'kdv_tutari'   => round($kdv, 2),
                'genel_toplam' => round($matrah + $kdv, 2),
            ],
            'bs_formu' => $bsListe,
            'bs_esik'  => $esik,
        ]);
        break;

    case 'pnl':
        $aylar = [];
        for ($i = 11; $i >= 0; $i--) $aylar[date('Y-m', strtotime("first day of -$i month"))] = 0.0;

        $gelirKira = $aylar; $gelirParca = $aylar; $gelirEkipman = $aylar; $gelirNakliye = $aylar;
        $giderPersonel = $aylar; $giderEkipmanIslet = $aylar; $giderServis = $aylar; $giderParcaMaliyet = $aylar; $giderNakliye = $aylar;

        foreach ($db->query("SELECT fatura_tarihi, tutar FROM hakedisler WHERE fatura_tarihi IS NOT NULL")->fetchAll() as $r) {
            $ay = substr((string)$r['fatura_tarihi'], 0, 7);
            if (isset($gelirKira[$ay])) $gelirKira[$ay] += (float)$r['tutar'];
        }
        foreach ($db->query("SELECT tarih, miktar, satis_fiyat, birim_fiyat FROM stok_hareket WHERE kaynak='satis'")->fetchAll() as $r) {
            $ay = substr((string)$r['tarih'], 0, 7);
            if (isset($gelirParca[$ay]))       $gelirParca[$ay]       += (float)$r['miktar'] * (float)$r['satis_fiyat'];
            if (isset($giderParcaMaliyet[$ay])) $giderParcaMaliyet[$ay] += (float)$r['miktar'] * (float)$r['birim_fiyat'];
        }
        foreach ($db->query("SELECT tarih, satis_tutari FROM ekipman_satis")->fetchAll() as $r) {
            $ay = substr((string)$r['tarih'], 0, 7);
            if (isset($gelirEkipman[$ay])) $gelirEkipman[$ay] += (float)$r['satis_tutari'];
        }
        foreach ($db->query("SELECT tarih, odenen, yansitilan, siparis_no FROM nakliyeler")->fetchAll() as $r) {
            $ay = substr((string)$r['tarih'], 0, 7);
            if (isset($giderNakliye[$ay])) $giderNakliye[$ay] += (float)$r['odenen'];
            if (empty($r['siparis_no']) && isset($gelirNakliye[$ay])) $gelirNakliye[$ay] += (float)$r['yansitilan'];
        }
        foreach ($db->query("SELECT ay, COALESCE(toplam_maas,0)+COALESCE(mesai,0)+COALESCE(prim,0) AS t FROM finans")->fetchAll() as $r) {
            if (isset($giderPersonel[$r['ay']])) $giderPersonel[$r['ay']] += (float)$r['t'];
        }
        foreach ($db->query("SELECT tarih, tutar FROM giderler")->fetchAll() as $r) {
            $ay = substr((string)$r['tarih'], 0, 7);
            if (isset($giderEkipmanIslet[$ay])) $giderEkipmanIslet[$ay] += (float)$r['tutar'];
        }
        foreach ($db->query("SELECT tarih, COALESCE(maliyet,0) t FROM teknik_servis")->fetchAll() as $r) {
            $ay = substr((string)$r['tarih'], 0, 7);
            if (isset($giderServis[$ay])) $giderServis[$ay] += (float)$r['t'];
        }

        $gelirToplam = $aylar; $giderToplam = $aylar; $net = $aylar;
        foreach (array_keys($aylar) as $ay) {
            $gelirToplam[$ay] = $gelirKira[$ay] + $gelirParca[$ay] + $gelirEkipman[$ay] + $gelirNakliye[$ay];
            $giderToplam[$ay] = $giderPersonel[$ay] + $giderEkipmanIslet[$ay] + $giderServis[$ay] + $giderParcaMaliyet[$ay] + $giderNakliye[$ay];
            $net[$ay] = $gelirToplam[$ay] - $giderToplam[$ay];
        }
        $yuvarla = function (array $arr): array { return array_values(array_map(fn($v) => round($v, 2), $arr)); };

        jsonOk([
            'etiketler' => array_keys($aylar),
            'gelir' => [
                'kira'    => $yuvarla($gelirKira),
                'parca'   => $yuvarla($gelirParca),
                'ekipman' => $yuvarla($gelirEkipman),
                'nakliye' => $yuvarla($gelirNakliye),
                'toplam'  => $yuvarla($gelirToplam),
            ],
            'gider' => [
                'personel'      => $yuvarla($giderPersonel),
                'ekipman_islet' => $yuvarla($giderEkipmanIslet),
                'servis'        => $yuvarla($giderServis),
                'parca_maliyet' => $yuvarla($giderParcaMaliyet),
                'nakliye'       => $yuvarla($giderNakliye),
                'toplam'        => $yuvarla($giderToplam),
            ],
            'net' => $yuvarla($net),
            'ozet' => [
                'toplam_gelir' => round(array_sum($gelirToplam), 2),
                'toplam_gider' => round(array_sum($giderToplam), 2),
                'net_kar'      => round(array_sum($net), 2),
            ],
        ]);
        break;

    case 'parca_kar':
        $rows = $db->query("
            SELECT h.parca_id, p.ad, p.parca_no, p.uyumlu_makine,
                   SUM(h.miktar) AS adet,
                   SUM(h.miktar * h.satis_fiyat) AS ciro,
                   SUM(h.miktar * h.birim_fiyat) AS maliyet
            FROM stok_hareket h
            JOIN yedek_parca p ON p.id = h.parca_id
            WHERE h.kaynak = 'satis'
            GROUP BY h.parca_id
        ")->fetchAll();

        $toplamCiro = 0.0; $toplamMaliyet = 0.0;
        foreach ($rows as &$r) {
            $r['adet']    = (int)$r['adet'];
            $r['ciro']    = round((float)$r['ciro'], 2);
            $r['maliyet'] = round((float)$r['maliyet'], 2);
            $r['kar']     = round($r['ciro'] - $r['maliyet'], 2);
            $r['kar_marji'] = $r['ciro'] > 0 ? round($r['kar'] / $r['ciro'] * 100, 1) : null;
            $toplamCiro    += $r['ciro'];
            $toplamMaliyet += $r['maliyet'];
        }
        unset($r);
        usort($rows, fn($a, $b) => $b['kar'] <=> $a['kar']);

        jsonOk([
            'rows' => $rows,
            'ozet' => [
                'toplam_ciro'    => round($toplamCiro, 2),
                'toplam_maliyet' => round($toplamMaliyet, 2),
                'toplam_kar'     => round($toplamCiro - $toplamMaliyet, 2),
                'ortalama_marj'  => $toplamCiro > 0 ? round(($toplamCiro - $toplamMaliyet) / $toplamCiro * 100, 1) : null,
            ],
        ]);
        break;

    case 'liderlik':
        $donem = in_array($_GET['donem'] ?? '', ['ay','yil','tum'], true) ? $_GET['donem'] : 'ay';
        $ay  = trim(metin($_GET['ay'] ?? date('Y-m')));
        if (!preg_match('/^\d{4}-\d{2}$/', $ay)) $ay = date('Y-m');
        $yil = trim(metin($_GET['yil'] ?? date('Y')));
        if (!preg_match('/^\d{4}$/', $yil)) $yil = date('Y');

        $opWhere = "k.operatorlu=1 AND k.operator_id IS NOT NULL";
        $opParams = [];
        if ($donem === 'ay')  { $opWhere .= " AND substr(kp.tarih,1,7)=?"; $opParams[] = $ay; }
        if ($donem === 'yil') { $opWhere .= " AND substr(kp.tarih,1,4)=?"; $opParams[] = $yil; }
        $opSt = $db->prepare("
            SELECT k.operator_id AS personel_id, p.ad_soyad,
                   SUM(CASE kp.durum WHEN 'tam' THEN 1 WHEN 'yarim' THEN 0.5 ELSE 0 END) AS calisma_gun,
                   COUNT(DISTINCT k.id) AS kiralama_adet
            FROM kiralama_puantaj kp
            JOIN kiralamalar k ON k.id = kp.kiralama_id
            JOIN personel p ON p.id = k.operator_id
            WHERE $opWhere
            GROUP BY k.operator_id
            HAVING calisma_gun > 0
            ORDER BY calisma_gun DESC
            LIMIT 15
        ");
        $opSt->execute($opParams);
        $operatorler = array_map(function ($r) {
            $r['calisma_gun'] = round((float)$r['calisma_gun'], 1);
            $r['personel_id'] = (int)$r['personel_id'];
            $r['kiralama_adet'] = (int)$r['kiralama_adet'];
            return $r;
        }, $opSt->fetchAll());

        $kSt = $db->query("SELECT * FROM kiralamalar WHERE durum<>'iptal' AND temsilci IS NOT NULL AND temsilci<>''");
        $ayBas = $ay . '-01'; $ayBit = date('Y-m-t', strtotime($ayBas));
        $yilBas = $yil . '-01-01'; $yilBit = $yil . '-12-31';
        $temsilciMap = [];
        foreach ($kSt->fetchAll() as $k) {
            if ($donem === 'ay')       { $pay = kiralamaTutarPencereli($k, $ayBas, $ayBit); }
            elseif ($donem === 'yil')  { $pay = kiralamaTutarPencereli($k, $yilBas, $yilBit); }
            else                       { $pay = kiralamaTutar($k)['genel_toplam']; }
            if ($pay === null || $pay <= 0) continue;
            $t = $k['temsilci'];
            if (!isset($temsilciMap[$t])) $temsilciMap[$t] = ['temsilci' => $t, 'ciro' => 0.0, 'kiralama_adet' => 0];
            $temsilciMap[$t]['ciro'] += $pay;
            $temsilciMap[$t]['kiralama_adet']++;
        }
        $temsilciler = array_values($temsilciMap);
        foreach ($temsilciler as &$t) $t['ciro'] = round($t['ciro'], 2);
        unset($t);
        usort($temsilciler, fn($a, $b) => $b['ciro'] <=> $a['ciro']);
        $temsilciler = array_slice($temsilciler, 0, 15);

        jsonOk([
            'donem' => $donem, 'ay' => $ay, 'yil' => $yil,
            'operatorler' => $operatorler,
            'temsilciler' => $temsilciler,
        ]);
        break;

    case 'hasar':
        $donem = in_array($_GET['donem'] ?? '', ['ay','yil','tum'], true) ? $_GET['donem'] : 'ay';
        $ay  = trim(metin($_GET['ay'] ?? date('Y-m')));
        if (!preg_match('/^\d{4}-\d{2}$/', $ay)) $ay = date('Y-m');
        $yil = trim(metin($_GET['yil'] ?? date('Y')));
        if (!preg_match('/^\d{4}$/', $yil)) $yil = date('Y');

        $tWhere = "1=1"; $tParams = [];
        if ($donem === 'ay')  { $tWhere = "substr(t.tarih,1,7)=?"; $tParams[] = $ay; }
        if ($donem === 'yil') { $tWhere = "substr(t.tarih,1,4)=?"; $tParams[] = $yil; }

        $iadeSt = $db->prepare("SELECT COUNT(*) FROM iade_tutanaklari t WHERE t.islem='iade' AND " . bzSayilanIadeSql('t') . " AND $tWhere");
        $iadeSt->execute($tParams);
        $toplamIade = (int)$iadeSt->fetchColumn();

        $hasarSt = $db->prepare("
            SELECT t.id, t.tarih, t.hasar_notu, t.personel_id, COALESCE(p.ad_soyad, t.imza_personel_ad) AS personel_ad,
                   t.kiralama_id, k.kod AS kiralama_kod, k.ekipman_adi, m.unvan AS musteri_unvan,
                   h.ek_ucret_toplam
            FROM iade_tutanaklari t
            LEFT JOIN personel p ON p.id = t.personel_id
            JOIN kiralamalar k ON k.id = t.kiralama_id
            JOIN musteriler m ON m.id = k.musteri_id
            LEFT JOIN (SELECT tutanak_id, SUM(genel_toplam) ek_ucret_toplam FROM hakedisler WHERE tutanak_id IS NOT NULL GROUP BY tutanak_id) h ON h.tutanak_id = t.id
            WHERE t.genel_durum='hasarli' AND " . bzSayilanIadeSql('t') . " AND $tWhere
            ORDER BY t.tarih DESC
        ");
        $hasarSt->execute($tParams);
        $hasarlar = $hasarSt->fetchAll();

        $toplamHasar = count($hasarlar);
        $toplamEkUcret = 0.0;
        $personelMap = []; $makineMap = [];
        foreach ($hasarlar as $r) {
            $toplamEkUcret += (float)($r['ek_ucret_toplam'] ?? 0);
            $pid = (int)$r['personel_id'];
            if (!isset($personelMap[$pid])) $personelMap[$pid] = ['personel_id'=>$pid, 'ad_soyad'=>$r['personel_ad'], 'adet'=>0];
            $personelMap[$pid]['adet']++;
        }
        $makineSt = $db->prepare("
            SELECT k.makine_tur, k.makine_id, k.ekipman_adi, COUNT(*) AS adet
            FROM iade_tutanaklari t JOIN kiralamalar k ON k.id = t.kiralama_id
            WHERE t.genel_durum='hasarli' AND " . bzSayilanIadeSql('t') . " AND $tWhere
            GROUP BY k.makine_tur, k.makine_id, k.ekipman_adi
            ORDER BY adet DESC LIMIT 10
        ");
        $makineSt->execute($tParams);
        $makineler = $makineSt->fetchAll();

        $personeller = array_values($personelMap);
        usort($personeller, fn($a,$b) => $b['adet'] <=> $a['adet']);
        $personeller = array_slice($personeller, 0, 10);

        foreach ($hasarlar as &$r) { $r['ek_ucret_toplam'] = round((float)($r['ek_ucret_toplam'] ?? 0), 2); }
        unset($r);

        jsonOk([
            'donem' => $donem, 'ay' => $ay, 'yil' => $yil,
            'ozet' => [
                'toplam_iade'    => $toplamIade,
                'toplam_hasar'   => $toplamHasar,
                'hasar_orani'    => $toplamIade > 0 ? round($toplamHasar / $toplamIade * 100, 1) : null,
                'toplam_ek_ucret'=> round($toplamEkUcret, 2),
            ],
            'personeller' => $personeller,
            'makineler'   => $makineler,
            'hasarlar'    => $hasarlar,
        ]);
        break;

    default:
        jsonError('Geçersiz rapor türü.');
}
