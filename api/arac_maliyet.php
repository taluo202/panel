<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('arac_maliyet');

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDb();

function _amHesapla(array $g): array {
    $yolKm      = max(0, (float)($g['yol_km'] ?? 0));
    $calismaSaat = max(0, (float)($g['calisma_saati'] ?? 0));
    $gunSayisi  = max(0.01, (float)($g['gun_sayisi'] ?? 1));
    $operatorSayisi = max(0, (int)($g['operator_sayisi'] ?? 1));
    $mazotFiyati = max(0, (float)($g['mazot_fiyati'] ?? 0));
    $sarfiyat100 = max(0, (float)($g['yakit_sarfiyat_100km'] ?? 0));
    $rolantiUcret = max(0, (float)($g['rolanti_birim_ucret_saat'] ?? 0));
    $rolantiLitre = max(0, (float)($g['rolanti_litre_saat'] ?? 0));
    $amortisman  = max(0, (float)($g['amortisman_gun'] ?? 0));
    $bakim       = max(0, (float)($g['bakim_gun'] ?? 0));
    $sigorta     = max(0, (float)($g['sigorta_gun'] ?? 0));
    $mtv         = max(0, (float)($g['mtv_gun'] ?? 0));
    $opMaas      = max(0, (float)($g['operator_maas_gun'] ?? 0));
    $opSgk       = max(0, (float)($g['operator_sgk_gun'] ?? 0));
    $opYemek     = max(0, (float)($g['operator_yemek_gun'] ?? 0));
    $opTazminat  = max(0, (float)($g['operator_tazminat_gun'] ?? 0));
    $vergiOran   = max(0, min(100, (float)($g['vergi_orani'] ?? 0)));
    $karOran     = max(0, (float)($g['kar_marji_orani'] ?? 0));

    $yolYakit     = round(($yolKm / 100) * $sarfiyat100 * $mazotFiyati, 2);
    $rolantiYakit = $rolantiLitre > 0
        ? round($rolantiLitre * $calismaSaat * $mazotFiyati, 2)
        : round($rolantiUcret * $calismaSaat, 2);
    $aracGunluk   = round(($amortisman + $bakim + $sigorta + $mtv) * $gunSayisi, 2);
    $opGunluk     = round(($opMaas + $opSgk + $opYemek + $opTazminat) * $gunSayisi * $operatorSayisi, 2);

    $araToplam = round($yolYakit + $rolantiYakit + $aracGunluk + $opGunluk, 2);
    $vergiTutari = round($araToplam * $vergiOran / 100, 2);
    $toplamMaliyet = round($araToplam + $vergiTutari, 2);
    $karTutari = round($toplamMaliyet * $karOran / 100, 2);
    $onerilenFiyat = round($toplamMaliyet + $karTutari, 2);

    return [
        'yol_yakit_maliyeti'     => $yolYakit,
        'rolanti_yakit_maliyeti' => $rolantiYakit,
        'arac_gunluk_toplam'     => $aracGunluk,
        'operator_gunluk_toplam' => $opGunluk,
        'ara_toplam'             => $araToplam,
        'vergi_tutari'           => $vergiTutari,
        'toplam_maliyet'         => $toplamMaliyet,
        'kar_tutari'             => $karTutari,
        'onerilen_fiyat'         => $onerilenFiyat,
    ];
}

function _amVarsayilanlar(PDO $db): array {
    $rows = $db->query("SELECT anahtar, deger FROM ayarlar WHERE anahtar LIKE 'maliyet_%'")->fetchAll();
    $out = [];
    foreach ($rows as $r) $out[$r['anahtar']] = $r['deger'];
    return [
        'mazot_fiyati'          => (float)($out['maliyet_mazot_fiyati'] ?? 0),
        'operator_maas_gun'     => (float)($out['maliyet_operator_maas_gun'] ?? 0),
        'operator_sgk_gun'      => (float)($out['maliyet_operator_sgk_gun'] ?? 0),
        'operator_yemek_gun'    => (float)($out['maliyet_operator_yemek_gun'] ?? 0),
        'operator_tazminat_gun' => (float)($out['maliyet_operator_tazminat_gun'] ?? 0),
        'vergi_orani'           => (float)($out['maliyet_vergi_orani'] ?? 0),
    ];
}

if ($method === 'GET' && ($_GET['action'] ?? '') === 'varsayilan') {
    jsonOk(_amVarsayilanlar($db));
}

if ($method === 'GET' && ($_GET['action'] ?? '') === 'makineler') {
    $ortak = 'id, marka, model, yakit_sarfiyat_100km, rolanti_litre_saat, maliyet_amortisman_gun, maliyet_bakim_gun, maliyet_sigorta_gun, maliyet_mtv_gun, mtv_yillik';
    $kosul = '1=1';
    $araclar = $db->query("SELECT $ortak, plaka, trafik_sigorta_tutar, kasko_tutar FROM araclar WHERE $kosul ORDER BY plaka COLLATE NOCASE")->fetchAll();
    $manliftler = $db->query("SELECT $ortak, kod, sigorta_yillik FROM manliftler WHERE $kosul ORDER BY kod COLLATE NOCASE")->fetchAll();
    jsonOk(['arac' => $araclar, 'manlift' => $manliftler]);
}

const AM_MAKINE_JOIN = "
    LEFT JOIN araclar a ON a.id = h.makine_id AND h.makine_tur = 'arac'
    LEFT JOIN manliftler ml ON ml.id = h.makine_id AND h.makine_tur = 'manlift'
";
const AM_MAKINE_SELECT = "COALESCE(a.plaka, ml.kod) AS kod, COALESCE(a.marka, ml.marka) AS marka, COALESCE(a.model, ml.model) AS model";

if ($method === 'GET' && ($_GET['action'] ?? '') === 'karsilastir') {
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? ''))), fn($v) => $v > 0)));
    if (!$ids) jsonError('Karşılaştırılacak hesaplama seçilmedi.');
    if (count($ids) > 5) jsonError('En fazla 5 hesaplama karşılaştırılabilir.');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("
        SELECT h.*, " . AM_MAKINE_SELECT . ", m.unvan AS musteri_unvan
        FROM arac_maliyet_hesap h
        " . AM_MAKINE_JOIN . "
        LEFT JOIN musteriler m ON m.id = h.musteri_id
        WHERE h.id IN ($ph)
    ");
    $st->execute($ids);
    $rows = $st->fetchAll();
    jsonOk($rows);
}

if ($method === 'GET' && ($_GET['action'] ?? '') === 'analiz') {
    $ozet = $db->query("
        SELECT COUNT(*) AS adet, COALESCE(SUM(toplam_maliyet),0) AS toplam_maliyet_sum,
               COALESCE(AVG(toplam_maliyet),0) AS ortalama_maliyet,
               COALESCE(SUM(CASE WHEN kar_marji_orani>0 THEN onerilen_fiyat ELSE 0 END),0) AS toplam_onerilen_fiyat_sum
        FROM arac_maliyet_hesap
    ")->fetch();

    $araclar = $db->query("
        SELECT h.makine_id, h.makine_tur, " . AM_MAKINE_SELECT . ", COUNT(*) AS adet, COALESCE(SUM(h.toplam_maliyet),0) AS toplam
        FROM arac_maliyet_hesap h " . AM_MAKINE_JOIN . "
        GROUP BY h.makine_id, h.makine_tur ORDER BY adet DESC LIMIT 8
    ")->fetchAll();

    $kalem = $db->query("
        SELECT COALESCE(SUM(yol_yakit_maliyeti),0) AS yol_yakit, COALESCE(SUM(rolanti_yakit_maliyeti),0) AS rolanti_yakit,
               COALESCE(SUM(arac_gunluk_toplam),0) AS arac_gunluk, COALESCE(SUM(operator_gunluk_toplam),0) AS operator_gunluk,
               COALESCE(SUM(vergi_tutari),0) AS vergi
        FROM arac_maliyet_hesap
    ")->fetch();

    $aylar = [];
    for ($i = 5; $i >= 0; $i--) $aylar[date('Y-m', strtotime("first day of -$i month"))] = ['adet' => 0, 'toplam' => 0.0];
    $tr = $db->query("SELECT substr(tarih,1,7) ay, COUNT(*) adet, COALESCE(SUM(toplam_maliyet),0) toplam FROM arac_maliyet_hesap GROUP BY substr(tarih,1,7)")->fetchAll();
    foreach ($tr as $r) {
        if (isset($aylar[$r['ay']])) $aylar[$r['ay']] = ['adet' => (int)$r['adet'], 'toplam' => round((float)$r['toplam'], 2)];
    }

    jsonOk([
        'ozet' => [
            'adet' => (int)$ozet['adet'],
            'toplam_maliyet_sum' => round((float)$ozet['toplam_maliyet_sum'], 2),
            'ortalama_maliyet' => round((float)$ozet['ortalama_maliyet'], 2),
            'toplam_onerilen_fiyat_sum' => round((float)$ozet['toplam_onerilen_fiyat_sum'], 2),
        ],
        'araclar' => array_map(fn($r) => [
            'makine_id' => (int)$r['makine_id'], 'makine_tur' => $r['makine_tur'], 'plaka' => $r['kod'], 'marka' => $r['marka'],
            'adet' => (int)$r['adet'], 'toplam' => round((float)$r['toplam'], 2),
        ], $araclar),
        'kalem_dagilimi' => [
            'yol_yakit' => round((float)$kalem['yol_yakit'], 2),
            'rolanti_yakit' => round((float)$kalem['rolanti_yakit'], 2),
            'arac_gunluk' => round((float)$kalem['arac_gunluk'], 2),
            'operator_gunluk' => round((float)$kalem['operator_gunluk'], 2),
            'vergi' => round((float)$kalem['vergi'], 2),
        ],
        'aylik_trend' => array_map(fn($ay, $v) => ['ay' => $ay, 'adet' => $v['adet'], 'toplam' => $v['toplam']], array_keys($aylar), array_values($aylar)),
    ]);
}

if ($method === 'POST' && ($_GET['action'] ?? '') === 'teklif_olustur') {
    verifyCsrf();
    requirePermission('kiralama_takip');

    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) jsonError('Geçersiz ID.');
    $body = govdeOku();

    $hSt = $db->prepare("SELECT h.*, " . AM_MAKINE_SELECT . " FROM arac_maliyet_hesap h " . AM_MAKINE_JOIN . " WHERE h.id=?");
    $hSt->execute([$id]);
    $h = $hSt->fetch();
    if (!$h) jsonError('Hesaplama bulunamadı.', 404);
    if ($h['teklif_id']) jsonError('Bu hesaplama zaten bir teklife dönüştürülmüş.');

    $musteriId = (int)($h['musteri_id'] ?: ($body['musteri_id'] ?? 0));
    if ($musteriId < 1) jsonError('Önce hesaplamaya (veya bu isteğe) bir müşteri seçilmelidir.');
    $mChk = $db->prepare("SELECT id FROM musteriler WHERE id=?");
    $mChk->execute([$musteriId]);
    if (!$mChk->fetchColumn()) jsonError('Müşteri bulunamadı.', 404);

    $fiyat = (float)$h['kar_marji_orani'] > 0 ? (float)$h['onerilen_fiyat'] : (float)$h['toplam_maliyet'];
    $aciklama = trim((string)($h['baslik'] ?: '')) ?: ('Maliyet Hesabı — ' . $h['kod']);

    $db->beginTransaction();
    try {
        $tarih = date('Y-m-d');
        $mt = musteriSubeTemsilci($db, $musteriId);
        $db->prepare("
            INSERT INTO teklifler (musteri_id, tarih, gecerlilik, durum, kdv_oran, notlar, sube, temsilci, iskonto_oran, onay_durumu)
            VALUES (?,?,?,?,?,?,?,?,0,'gerekmiyor')
        ")->execute([
            $musteriId, $tarih, date('Y-m-d', strtotime('+15 days')), 'taslak', 20,
            'Araç Maliyet Hesabı\'ndan otomatik oluşturuldu (' . $h['kod'] . ', ' . $tarih . ').' . ($h['notlar'] ? ' ' . $h['notlar'] : ''),
            $mt['sube'], $mt['temsilci'],
        ]);
        $teklifId = (int)$db->lastInsertId();
        $kod = 'TKF-' . substr($tarih, 0, 4) . '-' . str_pad((string)$teklifId, 4, '0', STR_PAD_LEFT);
        $db->prepare("UPDATE teklifler SET kod=? WHERE id=?")->execute([$kod, $teklifId]);

        $db->prepare("INSERT INTO teklif_kalemleri (teklif_id, aciklama, kira_tipi, miktar, sure, birim_ucret) VALUES (?,?,?,?,?,?)")
           ->execute([$teklifId, $aciklama, 'gunluk', 1, 1, $fiyat]);

        $db->prepare("UPDATE arac_maliyet_hesap SET teklif_id=? WHERE id=?")->execute([$teklifId, $id]);
        $db->commit();
    } catch (\Exception $e) {
        $db->rollBack();
        jsonError('Teklif oluşturulamadı.', 500);
    }
    logla($db, 'teklifler', $teklifId, 'ekle', null, null, 'Maliyet hesabından teklif oluşturuldu: ' . $kod);
    jsonOk(['teklif_id' => $teklifId, 'kod' => $kod], 'Teklif oluşturuldu: ' . $kod);
}

switch ($method) {

    case 'GET':
        if (isset($_GET['id'])) {
            $st = $db->prepare("
                SELECT h.*, " . AM_MAKINE_SELECT . ", m.unvan AS musteri_unvan
                FROM arac_maliyet_hesap h
                " . AM_MAKINE_JOIN . "
                LEFT JOIN musteriler m ON m.id = h.musteri_id
                WHERE h.id=?
            ");
            $st->execute([(int)$_GET['id']]);
            $row = $st->fetch();
            if (!$row) jsonError('Hesaplama bulunamadı.', 404);
            $mt = (string)($row['makine_tur'] ?? ''); $mi = (int)($row['makine_id'] ?? 0);
            $row['makine_kunye'] = makineBilgiToplu($db, [['tur' => $mt, 'id' => $mi]])["$mt:$mi"] ?? null;
            jsonOk($row);
        }

        $where = []; $params = [];
        if (($mid = (int)($_GET['makine_id'] ?? ($_GET['arac_id'] ?? 0))) > 0) { $where[] = "h.makine_id=?"; $params[] = $mid; }
        if (($mt = trim(metin($_GET['makine_tur'] ?? ''))) !== '') { $where[] = "h.makine_tur=?"; $params[] = $mt; }
        if (($tb = trim(metin($_GET['tarih_bas'] ?? ''))) !== '') { $where[] = "h.tarih>=?"; $params[] = $tb; }
        if (($te = trim(metin($_GET['tarih_bit'] ?? ''))) !== '') { $where[] = "h.tarih<=?"; $params[] = $te; }
        if (($q = trim(metin($_GET['q'] ?? ''))) !== '') {
            $where[] = "(" . trAramaSql('h.baslik') . " LIKE ? OR " . trAramaSql('a.plaka') . " LIKE ? OR " . trAramaSql('ml.kod') . " LIKE ? OR " . trAramaSql('m.unvan') . " LIKE ?)";
            $like = '%' . trArama($q) . '%'; array_push($params, $like, $like, $like, $like);
        }
        $sql = "
            SELECT h.id, h.makine_id, h.makine_tur, h.musteri_id, h.baslik, h.tarih, h.yol_km, h.calisma_saati, h.gun_sayisi,
                   h.operator_sayisi, h.rolanti_litre_saat, h.yol_yakit_maliyeti, h.rolanti_yakit_maliyeti, h.arac_gunluk_toplam,
                   h.operator_gunluk_toplam, h.ara_toplam, h.vergi_orani, h.vergi_tutari, h.kar_marji_orani,
                   h.kar_tutari, h.toplam_maliyet, h.onerilen_fiyat, h.notlar, h.created_at,
                   " . AM_MAKINE_SELECT . ", m.unvan AS musteri_unvan
            FROM arac_maliyet_hesap h
            " . AM_MAKINE_JOIN . "
            LEFT JOIN musteriler m ON m.id = h.musteri_id
        ";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY h.tarih DESC, h.id DESC";
        $st = $db->prepare($sql);
        $st->execute($params);
        jsonOk($st->fetchAll());
        break;

    case 'POST':
        verifyCsrf();
        $body = govdeOku();
        $aracId = (int)($body['makine_id'] ?? ($body['arac_id'] ?? 0));
        $makineTur = in_array($body['makine_tur'] ?? '', ['arac', 'manlift'], true) ? $body['makine_tur'] : 'arac';
        if ($aracId < 1) jsonError('Araç/Makine seçilmelidir.');
        $aChk = $db->prepare("SELECT id FROM " . ($makineTur === 'manlift' ? 'manliftler' : 'araclar') . " WHERE id=?");
        $aChk->execute([$aracId]);
        if (!$aChk->fetchColumn()) jsonError(($makineTur === 'manlift' ? 'Makine' : 'Araç') . ' bulunamadı.', 404);

        $tarih = dateStr('tarih', $body) ?: date('Y-m-d');
        $sonuc = _amHesapla($body);

        $st = $db->prepare("
            INSERT INTO arac_maliyet_hesap
              (makine_id, makine_tur, musteri_id, baslik, tarih, yol_km, calisma_saati, gun_sayisi, operator_sayisi,
               mazot_fiyati, yakit_sarfiyat_100km, rolanti_birim_ucret_saat, rolanti_litre_saat,
               amortisman_gun, bakim_gun, sigorta_gun, mtv_gun,
               operator_maas_gun, operator_sgk_gun, operator_yemek_gun, operator_tazminat_gun,
               vergi_orani, kar_marji_orani,
               yol_yakit_maliyeti, rolanti_yakit_maliyeti, arac_gunluk_toplam, operator_gunluk_toplam,
               ara_toplam, vergi_tutari, toplam_maliyet, kar_tutari, onerilen_fiyat, notlar)
            VALUES (?,?,?,?,?,?,?,?,?, ?,?,?,?, ?,?,?,?, ?,?,?,?, ?,?, ?,?,?,?, ?,?,?,?,?,?)
        ");
        $st->execute([
            $aracId, $makineTur, ((int)($body['musteri_id'] ?? 0)) ?: null, trim(metin($body['baslik'] ?? '')) ?: null, $tarih,
            max(0, (float)($body['yol_km'] ?? 0)), max(0, (float)($body['calisma_saati'] ?? 0)),
            max(0.01, (float)($body['gun_sayisi'] ?? 1)), max(0, (int)($body['operator_sayisi'] ?? 1)),
            max(0, (float)($body['mazot_fiyati'] ?? 0)), max(0, (float)($body['yakit_sarfiyat_100km'] ?? 0)),
            max(0, (float)($body['rolanti_birim_ucret_saat'] ?? 0)), max(0, (float)($body['rolanti_litre_saat'] ?? 0)),
            max(0, (float)($body['amortisman_gun'] ?? 0)), max(0, (float)($body['bakim_gun'] ?? 0)),
            max(0, (float)($body['sigorta_gun'] ?? 0)), max(0, (float)($body['mtv_gun'] ?? 0)),
            max(0, (float)($body['operator_maas_gun'] ?? 0)), max(0, (float)($body['operator_sgk_gun'] ?? 0)),
            max(0, (float)($body['operator_yemek_gun'] ?? 0)), max(0, (float)($body['operator_tazminat_gun'] ?? 0)),
            max(0, min(100, (float)($body['vergi_orani'] ?? 0))), max(0, (float)($body['kar_marji_orani'] ?? 0)),
            $sonuc['yol_yakit_maliyeti'], $sonuc['rolanti_yakit_maliyeti'], $sonuc['arac_gunluk_toplam'], $sonuc['operator_gunluk_toplam'],
            $sonuc['ara_toplam'], $sonuc['vergi_tutari'], $sonuc['toplam_maliyet'], $sonuc['kar_tutari'], $sonuc['onerilen_fiyat'],
            trim(metin($body['notlar'] ?? '')) ?: null,
        ]);
        $_mhId = (int)$db->lastInsertId();
        logla($db, 'arac_maliyet_hesap', $_mhId, 'ekle', null, null, 'Maliyet hesabı kaydedildi');
        jsonOk(array_merge(['id' => $_mhId], $sonuc), 'Maliyet hesabı kaydedildi.');

    case 'PUT':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $body = govdeOku();

        $cur = $db->prepare("SELECT id FROM arac_maliyet_hesap WHERE id=?");
        $cur->execute([$id]);
        if (!$cur->fetch()) jsonError('Hesaplama bulunamadı.', 404);

        $aracId = (int)($body['makine_id'] ?? ($body['arac_id'] ?? 0));
        $makineTur = in_array($body['makine_tur'] ?? '', ['arac', 'manlift'], true) ? $body['makine_tur'] : 'arac';
        if ($aracId < 1) jsonError('Araç/Makine seçilmelidir.');
        $tarih = dateStr('tarih', $body) ?: date('Y-m-d');
        $sonuc = _amHesapla($body);

        $st = $db->prepare("
            UPDATE arac_maliyet_hesap SET
              makine_id=?, makine_tur=?, musteri_id=?, baslik=?, tarih=?, yol_km=?, calisma_saati=?, gun_sayisi=?, operator_sayisi=?,
              mazot_fiyati=?, yakit_sarfiyat_100km=?, rolanti_birim_ucret_saat=?, rolanti_litre_saat=?,
              amortisman_gun=?, bakim_gun=?, sigorta_gun=?, mtv_gun=?,
              operator_maas_gun=?, operator_sgk_gun=?, operator_yemek_gun=?, operator_tazminat_gun=?,
              vergi_orani=?, kar_marji_orani=?,
              yol_yakit_maliyeti=?, rolanti_yakit_maliyeti=?, arac_gunluk_toplam=?, operator_gunluk_toplam=?,
              ara_toplam=?, vergi_tutari=?, toplam_maliyet=?, kar_tutari=?, onerilen_fiyat=?, notlar=?,
              updated_at=datetime('now','localtime')
            WHERE id=?
        ");
        $st->execute([
            $aracId, $makineTur, ((int)($body['musteri_id'] ?? 0)) ?: null, trim(metin($body['baslik'] ?? '')) ?: null, $tarih,
            max(0, (float)($body['yol_km'] ?? 0)), max(0, (float)($body['calisma_saati'] ?? 0)),
            max(0.01, (float)($body['gun_sayisi'] ?? 1)), max(0, (int)($body['operator_sayisi'] ?? 1)),
            max(0, (float)($body['mazot_fiyati'] ?? 0)), max(0, (float)($body['yakit_sarfiyat_100km'] ?? 0)),
            max(0, (float)($body['rolanti_birim_ucret_saat'] ?? 0)), max(0, (float)($body['rolanti_litre_saat'] ?? 0)),
            max(0, (float)($body['amortisman_gun'] ?? 0)), max(0, (float)($body['bakim_gun'] ?? 0)),
            max(0, (float)($body['sigorta_gun'] ?? 0)), max(0, (float)($body['mtv_gun'] ?? 0)),
            max(0, (float)($body['operator_maas_gun'] ?? 0)), max(0, (float)($body['operator_sgk_gun'] ?? 0)),
            max(0, (float)($body['operator_yemek_gun'] ?? 0)), max(0, (float)($body['operator_tazminat_gun'] ?? 0)),
            max(0, min(100, (float)($body['vergi_orani'] ?? 0))), max(0, (float)($body['kar_marji_orani'] ?? 0)),
            $sonuc['yol_yakit_maliyeti'], $sonuc['rolanti_yakit_maliyeti'], $sonuc['arac_gunluk_toplam'], $sonuc['operator_gunluk_toplam'],
            $sonuc['ara_toplam'], $sonuc['vergi_tutari'], $sonuc['toplam_maliyet'], $sonuc['kar_tutari'], $sonuc['onerilen_fiyat'],
            trim(metin($body['notlar'] ?? '')) ?: null,
            $id,
        ]);
        logla($db, 'arac_maliyet_hesap', $id, 'guncelle', null, null, 'Maliyet hesabı güncellendi');
        jsonOk(array_merge(['id' => $id], $sonuc), 'Maliyet hesabı güncellendi.');

    case 'DELETE':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $db->prepare("DELETE FROM arac_maliyet_hesap WHERE id=?")->execute([$id]);
        logla($db, 'arac_maliyet_hesap', $id, 'sil', null, null, 'Maliyet hesabı silindi');
        jsonOk(null, 'Hesaplama silindi.');

    default:
        jsonError('Method Not Allowed', 405);
}
