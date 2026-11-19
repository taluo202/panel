<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
if (!in_array($_GET['action'] ?? '', ['teslim_baglantilari', 'baglanti_yeniden'], true)) requirePermission('nakliye');
elseif (!hasPermission('nakliye', 'oku') && !hasPermission('kiralama_takip', 'oku')) jsonError('Bu modül için yetkiniz yok.', 403);

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDb();

function _nakYon($v): string { return in_array($v, ['gidis','donus','gidis_donus','tek'], true) ? $v : 'gidis'; }

function _nakTip($v): string { return $v === 'avk' ? 'avk' : 'dis'; }

function _nakTipSuz($v): string { return in_array($v, ['avk', 'dis'], true) ? $v : ''; }

function _nakNakliyeci(string $tip, string $ad): ?string {
    $ad = trim($ad);
    if ($tip === 'avk' && ($ad === '' || $ad === 'AVK Nakliye')) return NAKLIYE_KENDI_AD;
    return $ad !== '' ? $ad : null;
}

function _nakArac(PDO $db, array $body): array {
    $aracId = ((int)($body['arac_id'] ?? 0)) ?: null;
    $plaka  = trim(metin($body['plaka'] ?? '')) ?: null;
    if ($aracId) {
        $st = $db->prepare("SELECT plaka FROM araclar WHERE id=?");
        $st->execute([$aracId]);
        $ap = $st->fetchColumn();
        if ($ap === false) $aracId = null;
        elseif ($plaka === null) $plaka = (string)$ap;
    }
    return [$aracId, $plaka];
}

function _nkSiparisSatirlari(PDO $db, string $whereSql, array $params): array {
    $st = $db->prepare("SELECT k.id, k.kod, k.ekipman_adi, k.durum,
                         COALESCE(ml.kod, a.plaka) AS makine_kod
                        FROM kiralamalar k
                        LEFT JOIN manliftler ml ON k.makine_tur='manlift' AND ml.id = k.makine_id
                        LEFT JOIN araclar a    ON k.makine_tur='arac'    AND a.id  = k.makine_id
                        WHERE $whereSql ORDER BY k.id");
    $st->execute($params);
    return $st->fetchAll();
}

function _nkMakinelerYukle(PDO $db, array $rows): array {
    if (!$rows) return [];
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $sonuc = array_fill_keys($ids, []);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("
        SELECT nm.nakliye_id, k.id AS kiralama_id, k.kod, k.ekipman_adi, k.metraj,
               COALESCE(ml.kod, a.plaka) AS makine_kod
        FROM nakliye_makineleri nm
        JOIN kiralamalar k ON k.id = nm.kiralama_id
        LEFT JOIN manliftler ml ON k.makine_tur='manlift' AND ml.id = k.makine_id
        LEFT JOIN araclar a    ON k.makine_tur='arac'    AND a.id  = k.makine_id
        WHERE nm.nakliye_id IN ($ph)
        ORDER BY k.id
    ");
    $st->execute($ids);
    foreach ($st->fetchAll() as $r) {
        $nid = (int)$r['nakliye_id'];
        unset($r['nakliye_id']);
        $r['kiralama_id'] = (int)$r['kiralama_id'];
        $sonuc[$nid][] = $r;
    }
    $eksikKid = [];
    foreach ($rows as $r) {
        $nid = (int)$r['id'];
        if (empty($sonuc[$nid]) && !empty($r['kiralama_id'])) $eksikKid[$nid] = (int)$r['kiralama_id'];
    }
    if ($eksikKid) {
        $kids = array_values(array_unique($eksikKid));
        $ph2 = implode(',', array_fill(0, count($kids), '?'));
        $st2 = $db->prepare("
            SELECT k.id AS kiralama_id, k.kod, k.ekipman_adi, k.metraj,
                   COALESCE(ml.kod, a.plaka) AS makine_kod
            FROM kiralamalar k
            LEFT JOIN manliftler ml ON k.makine_tur='manlift' AND ml.id = k.makine_id
            LEFT JOIN araclar a    ON k.makine_tur='arac'    AND a.id  = k.makine_id
            WHERE k.id IN ($ph2)
        ");
        $st2->execute($kids);
        $kMap = [];
        foreach ($st2->fetchAll() as $r) { $r['kiralama_id'] = (int)$r['kiralama_id']; $kMap[$r['kiralama_id']] = $r; }
        foreach ($eksikKid as $nid => $kid) if (isset($kMap[$kid])) $sonuc[$nid] = [$kMap[$kid]];
    }
    return $sonuc;
}

function _nkMakinelerBelirle(PDO $db, string $method, int $nakliyeId, ?int $kiralamaId, array $izinliIds, array $body): array {
    if (array_key_exists('makineler', $body) && is_array($body['makineler'])) {
        $istenen = array_map('intval', $body['makineler']);
        return array_values(array_intersect($istenen, $izinliIds));
    }
    if ($method === 'POST') {
        return $kiralamaId ? [$kiralamaId] : [];
    }
    $st = $db->prepare("SELECT kiralama_id FROM nakliye_makineleri WHERE nakliye_id=?");
    $st->execute([$nakliyeId]);
    $mevcut = array_map('intval', array_column($st->fetchAll(), 'kiralama_id'));
    return array_values(array_intersect($mevcut, $izinliIds));
}

function _nkBaglariYaz(PDO $db, int $nakliyeId, array $kiralamaIds): void {
    $db->prepare("DELETE FROM nakliye_makineleri WHERE nakliye_id=? AND yuklendi_zaman IS NULL AND COALESCE(plan_disi,0)=0")->execute([$nakliyeId]);
    if (!$kiralamaIds) return;
    $ins = $db->prepare("INSERT OR IGNORE INTO nakliye_makineleri(nakliye_id, kiralama_id) VALUES (?,?)");
    foreach (array_unique($kiralamaIds) as $kid) $ins->execute([$nakliyeId, $kid]);
}

function _nkSiparisBilgi(PDO $db, ?int $kiralamaId): array {
    if (!$kiralamaId) return [null, []];
    $kst = $db->prepare("SELECT siparis_no FROM kiralamalar WHERE id=?");
    $kst->execute([$kiralamaId]);
    $krow = $kst->fetch();
    if (!$krow) jsonError('Seçilen kiralama bulunamadı.');
    $siparisNo = $krow['siparis_no'] ?: null;
    $izinliIds = $siparisNo
        ? array_map('intval', array_column(_nkSiparisSatirlari($db, 'k.siparis_no=?', [$siparisNo]), 'id'))
        : [$kiralamaId];
    return [$siparisNo, $izinliIds];
}

if ($method === 'POST' && ($_GET['action'] ?? '') === 'kanit') {
    verifyCsrf();
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) jsonError('Geçersiz ID.');
    subeKayitDogrula($db, 'nakliyeler', $id);

    $body = govdeOku();
    $veri = (string)($body['veri'] ?? '');
    if (!preg_match('/^data:(image\/(jpe?g|png|webp)|application\/pdf);base64,([A-Za-z0-9+\/=]+)$/', $veri, $m)) {
        jsonError('Desteklenmeyen dosya biçimi (JPG/PNG/WEBP/PDF).');
    }
    $ikili = base64_decode($m[3], true);
    if ($ikili === false || strlen($ikili) < 100 || strlen($ikili) > MAX_FILE_SIZE) jsonError('Dosya okunamadı veya çok büyük.');
    $pdfMi = str_starts_with($m[1], 'application/');
    if ($pdfMi) { if (substr($ikili, 0, 4) !== '%PDF') jsonError('Dosya bozuk.'); }
    else        { if (!getimagesizefromstring($ikili)) jsonError('Dosya bozuk.'); }

    $st = $db->prepare("SELECT teslim_kanit_dosya FROM nakliyeler WHERE id=?");
    $st->execute([$id]);
    $eski = $st->fetchColumn();

    $dir = UPLOAD_PATH . 'nakliye/' . $id . '/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) jsonError('Klasör oluşturulamadı.', 500);
    $uz = $pdfMi ? 'pdf' : ($m[2] === 'jpg' ? 'jpeg' : $m[2]);
    $ad = bin2hex(random_bytes(16)) . '.' . $uz;
    if (file_put_contents($dir . $ad, $ikili) === false) jsonError('Dosya kaydedilemedi.', 500);
    @chmod($dir . $ad, 0644);
    $rel = 'nakliye/' . $id . '/' . $ad;
    $kanitNo = belgeNoAl($body, 'teslim_kanit_no');
    $db->prepare("UPDATE nakliyeler SET teslim_kanit_dosya=?, teslim_kanit_no=? WHERE id=?")->execute([$rel, $kanitNo, $id]);
    logla($db, 'nakliyeler', $id, 'guncelle', null, null, 'Teslim kanıtı yüklendi' . ($kanitNo !== null ? ' · belge no ' . $kanitNo : ''));
    if ($eski && $eski !== $rel) { $eskiTam = UPLOAD_PATH . $eski; if (is_file($eskiTam)) @unlink($eskiTam); }
    jsonOk(['dosya' => $rel, 'teslim_kanit_no' => $kanitNo], 'Teslim kanıtı kaydedildi.');
}

if ($method === 'PUT' && ($_GET['action'] ?? '') === 'kanit_no') {
    verifyCsrf();
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) jsonError('Geçersiz ID.');
    subeKayitDogrula($db, 'nakliyeler', $id);
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) jsonError('Geçersiz istek gövdesi.');
    alanlarZorunlu($body, ['teslim_kanit_no']);
    $st = $db->prepare("SELECT teslim_kanit_dosya, teslim_kanit_no FROM nakliyeler WHERE id=?");
    $st->execute([$id]);
    $eski = $st->fetch();
    if (!$eski) jsonError('Nakliye kaydı bulunamadı.', 404);
    if (empty($eski['teslim_kanit_dosya'])) jsonError('Bu nakliyede teslim kanıtı yok; önce kanıtı yükleyin.');
    $kanitNo = belgeNoAl($body, 'teslim_kanit_no');
    $db->prepare("UPDATE nakliyeler SET teslim_kanit_no=? WHERE id=?")->execute([$kanitNo, $id]);
    logla($db, 'nakliyeler', $id, 'guncelle', ['teslim_kanit_no' => $eski['teslim_kanit_no']], ['teslim_kanit_no' => $kanitNo],
          'Teslim kanıtı belge no düzenlendi');
    jsonOk(['id' => $id, 'teslim_kanit_no' => $kanitNo], 'Teslim kanıtı belge no güncellendi.');
}

if ($method === 'DELETE' && ($_GET['action'] ?? '') === 'kanit') {
    verifyCsrf();
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) jsonError('Geçersiz ID.');
    subeKayitDogrula($db, 'nakliyeler', $id);
    $st = $db->prepare("SELECT teslim_kanit_dosya FROM nakliyeler WHERE id=?");
    $st->execute([$id]);
    $eski = $st->fetchColumn();
    if ($eski) { $eskiTam = UPLOAD_PATH . $eski; if (is_file($eskiTam)) @unlink($eskiTam); }
    $db->prepare("UPDATE nakliyeler SET teslim_kanit_dosya=NULL, teslim_kanit_no=NULL WHERE id=?")->execute([$id]);
    logla($db, 'nakliyeler', $id, 'guncelle', null, null, 'Teslim kanıtı silindi');
    jsonOk(null, 'Teslim kanıtı silindi.');
}

if ($method === 'GET' && ($_GET['action'] ?? '') === 'siparis_kiralamalari') {
    $kid = (int)($_GET['kiralama_id'] ?? 0);
    if ($kid < 1) jsonError('Geçersiz kiralama ID.');
    subeKayitDogrula($db, 'kiralamalar', $kid);
    $kst = $db->prepare("SELECT siparis_no FROM kiralamalar WHERE id=?");
    $kst->execute([$kid]);
    $krow = $kst->fetch();
    if (!$krow) jsonError('Kiralama bulunamadı.', 404);
    $siparisNo = $krow['siparis_no'] ?: null;
    $satirlar = $siparisNo
        ? _nkSiparisSatirlari($db, 'k.siparis_no=?', [$siparisNo])
        : _nkSiparisSatirlari($db, 'k.id=?', [$kid]);
    jsonOk(['siparis_no' => $siparisNo, 'kiralamalar' => $satirlar]);
}

if (in_array($_GET['action'] ?? '', ['teslim_baglantilari', 'baglanti_yeniden'], true)) {
    require_once __DIR__ . '/teslim-baglanti-lib.php';
    $yonetici = ($_SESSION['rol'] ?? '') === 'admin';
    try {
        if ($method === 'GET' && $_GET['action'] === 'teslim_baglantilari') {
            $id = (int)($_GET['id'] ?? 0);
            if ($id < 1) jsonError('Geçersiz ID.');
            subeKayitDogrula($db, 'nakliyeler', $id);
            jsonOk(tbDurum($db, $id, $yonetici));
        }
        if ($method !== 'POST' || $_GET['action'] !== 'baglanti_yeniden') jsonError('Method Not Allowed', 405);
        verifyCsrf();
        if (!$yonetici) jsonError('Teslim bağlantısını yalnız sistem yöneticisi yeniden gönderebilir.', 403);
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) jsonError('İstek okunamadı.');
        if (($nid = (int)($body['nakliye_id'] ?? 0)) > 0) subeKayitDogrula($db, 'nakliyeler', $nid);
        $db->beginTransaction();
        $r = tbYenidenGonder($db, $body, opOturumYapan($db));
        $db->commit();
        $nid = (int)$r['nakliye']['id'];
        $msj = tbMesaj($db, $nid, $r['yon'], $r['baglanti'], $r['not'], $r['alici']);
        $konu = ($r['yon'] === 'donus' ? 'Teslim alma' : 'Teslim') . ' bağlantısı' . (trim((string)($r['nakliye']['plaka'] ?? '')) !== '' ? ' — ' . $r['nakliye']['plaka'] : '');
        $ep = null;
        try { $ep = tbEposta($db, $nid, $r['alici']['eposta'], $konu, $msj['metin']); }
        catch (Throwable $e) { error_log('nakliye baglanti_yeniden eposta: ' . $e->getMessage()); $ep = ['durum' => 'hata', 'mesaj' => 'E-posta gönderilemedi.']; }
        jsonOk(['baglanti' => $r['baglanti'], 'yon' => $r['yon'], 'alici' => $r['alici'], 'metin' => $msj['metin'], 'wa' => $msj['wa'],
                'eposta' => $ep, 'mailto' => $r['alici']['eposta'] !== ''
                    ? 'mailto:' . rawurlencode($r['alici']['eposta']) . '?subject=' . rawurlencode($konu) . '&body=' . rawurlencode($msj['metin']) : null,
                'onceki_gecersiz' => $r['onceki_gecersiz'], 'saha_guncellenen' => $r['saha_guncellenen'], 'makine_adet' => $r['makine_adet']],
               $r['onceki_gecersiz'] > 0 ? 'Yeni bağlantı oluşturuldu; önceki bağlantı artık açılmaz.' : 'Yeni bağlantı oluşturuldu.');
    } catch (TbYok $e) {
        if ($db->inTransaction()) $db->rollBack();
        jsonError($e->getMessage(), 404);
    } catch (InvalidArgumentException $e) {
        if ($db->inTransaction()) $db->rollBack();
        jsonError($e->getMessage(), 400);
    } catch (DomainException $e) {
        if ($db->inTransaction()) $db->rollBack();
        jsonError($e->getMessage(), 409);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('nakliye teslim baglantisi: ' . $e->getMessage());
        jsonError('İşlem tamamlanamadı.', 500);
    }
}

switch ($method) {

    case 'GET':
        if (isset($_GET['id'])) {
            $id = (int)$_GET['id'];
            subeKayitDogrula($db, 'nakliyeler', $id);
            $st = $db->prepare("SELECT n.*, m.unvan AS musteri_unvan, k.kod AS kiralama_kod
                                FROM nakliyeler n
                                LEFT JOIN musteriler m ON m.id = n.musteri_id
                                LEFT JOIN kiralamalar k ON k.id = n.kiralama_id
                                WHERE n.id=?");
            $st->execute([$id]);
            $r = $st->fetch();
            if (!$r) jsonError('Nakliye kaydı bulunamadı.', 404);
            $makMap = _nkMakinelerYukle($db, [$r]);
            $r['makineler'] = $makMap[$id] ?? [];
            $r['siparis_kiralamalari'] = !empty($r['siparis_no'])
                ? _nkSiparisSatirlari($db, 'k.siparis_no=?', [$r['siparis_no']])
                : [];
            jsonOk($r);
        }

        $where = []; $params = [];
        if (($kid = (int)($_GET['kiralama_id'] ?? 0)) > 0) {
            $where[] = "(n.kiralama_id=? OR n.id IN (SELECT nakliye_id FROM nakliye_makineleri WHERE kiralama_id=?))";
            array_push($params, $kid, $kid);
        }
        if (($sp = trim(metin($_GET['siparis'] ?? ''))) !== '') { $where[] = "n.siparis_no=?"; $params[] = $sp; }
        if (($sb = trim(metin($_GET['sube'] ?? ''))) !== '')     { $where[] = "n.sube=?";     $params[] = $sb; }
        subeKosulEkle($where, $params, 'n.sube');
        if (($tm = trim(metin($_GET['temsilci'] ?? ''))) !== '') { $where[] = "n.temsilci=?"; $params[] = $tm; }
        if (($yn = trim(metin($_GET['yon'] ?? ''))) !== '' && $yn !== 'hepsi') { $where[] = "n.yon=?"; $params[] = $yn; }
        if (($ay = trim(metin($_GET['ay'] ?? ''))) !== '' && preg_match('/^\d{4}-\d{2}$/', $ay)) { $where[] = "substr(n.tarih,1,7)=?"; $params[] = $ay; }
        if (($q = trim(metin($_GET['q'] ?? ''))) !== '') {
            $where[] = "(" . trAramaSql('n.ekipman') . " LIKE ? OR " . trAramaSql('n.nakliyeci') . " LIKE ? OR " . trAramaSql('n.plaka') . " LIKE ? OR " . trAramaSql('n.lokasyon') . " LIKE ? OR " . trAramaSql('m.unvan') . " LIKE ? OR n.siparis_no LIKE ?)";
            $like = '%' . trArama($q) . '%'; array_push($params, $like, $like, $like, $like, $like, '%' . $q . '%');
        }
        $sql = "SELECT n.*, m.unvan AS musteri_unvan, k.kod AS kiralama_kod
                FROM nakliyeler n
                LEFT JOIN musteriler m ON m.id = n.musteri_id
                LEFT JOIN kiralamalar k ON k.id = n.kiralama_id";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY n.tarih DESC, n.id DESC";
        $st = $db->prepare($sql); $st->execute($params);
        $rows = $st->fetchAll();

        $tipSuz = _nakTipSuz($_GET['tip'] ?? '');
        $tipSayilari = ['avk' => 0, 'dis' => 0];
        foreach ($rows as $r) $tipSayilari[_nakTip($r['tip'] ?? 'dis')]++;
        if ($tipSuz !== '') $rows = array_values(array_filter($rows, fn($r) => _nakTip($r['tip'] ?? 'dis') === $tipSuz));

        $makMap = _nkMakinelerYukle($db, $rows);
        try {
            require_once __DIR__ . '/teslim-baglanti-lib.php';
            $tbUyari = tbTeslimUyarilari($db, $rows);
        } catch (Throwable $e) {
            error_log('nakliye teslim uyarisi: ' . $e->getMessage());
            $tbUyari = [];
        }

        $ozet = ['adet'=>0, 'odenen'=>0.0, 'yansitilan'=>0.0, 'fark'=>0.0, 'tevkifat'=>0.0, 'musteri_toplam'=>0.0];
        foreach ($rows as &$r) {
            $h = nakliyeHesap((float)$r['yansitilan'], (float)$r['odenen'],
                              (float)($r['kdv_oran'] ?? 20), $r['odeme_turu'] ?? null,
                              (int)($r['vade_gun'] ?? 0), $r['tarih'] ?? null);
            $r['fark']            = $h['kar'];
            $r['kar']             = $h['kar'];
            $r['kar_orani']       = $h['kar_orani'];
            $r['tevkifat_var']    = $h['tevkifat_var'];
            $r['tevkifat_orani']  = $h['tevkifat_orani'];
            $r['tevkifat_tutari'] = $h['tevkifat_tutari'];
            $r['musteri_toplam']  = $h['musteri_toplam'];
            $r['vade_tarihi']     = $h['vade_tarihi'];
            $r['makineler']       = $makMap[(int)$r['id']] ?? [];
            $r['teslim_uyari']    = $tbUyari[(int)$r['id']] ?? null;
            $ozet['adet']++;
            $ozet['odenen']         += (float)$r['odenen'];
            $ozet['yansitilan']     += (float)$r['yansitilan'];
            $ozet['tevkifat']       += $h['tevkifat_tutari'];
            $ozet['musteri_toplam'] += $h['musteri_toplam'];
        }
        unset($r);
        $ozet['odenen'] = round($ozet['odenen'], 2);
        $ozet['yansitilan'] = round($ozet['yansitilan'], 2);
        $ozet['tevkifat'] = round($ozet['tevkifat'], 2);
        $ozet['musteri_toplam'] = round($ozet['musteri_toplam'], 2);
        $ozet['fark'] = round($ozet['yansitilan'] - $ozet['odenen'], 2);

        $nk = $db->query("SELECT nakliyeci, COUNT(*) adet, MAX(tarih) son
                          FROM nakliyeler WHERE nakliyeci IS NOT NULL AND TRIM(nakliyeci) <> ''
                          GROUP BY nakliyeci ORDER BY adet DESC, son DESC LIMIT 100")->fetchAll();

        jsonOk(['list' => $rows, 'ozet' => $ozet, 'nakliyeciler' => $nk, 'tip' => $tipSuz, 'tip_sayilari' => $tipSayilari,
                'tevkifat_esik' => NAKLIYE_TEVKIFAT_ESIK, 'tevkifat_orani' => NAKLIYE_TEVKIFAT_ORAN,
                'vade_max' => NAKLIYE_VADE_MAX]);
        break;

    case 'POST':
        verifyCsrf();
        $body  = govdeOku();
        $tarih = dateStr('tarih', $body) ?: date('Y-m-d');

        if (trim((string)($body['ekipman'] ?? '')) === ''
            && (int)($body['kiralama_id'] ?? 0) < 1
            && (int)($body['musteri_id'] ?? 0) < 1) {
            jsonError('Nakliye kaydı için ekipman, kiralama veya müşteri bilgisinden en az biri gerekli.');
        }

        $musteriId  = ((int)($body['musteri_id'] ?? 0)) ?: null;
        $kiralamaId = ((int)($body['kiralama_id'] ?? 0)) ?: null;
        if ($kiralamaId) subeKayitDogrula($db, 'kiralamalar', $kiralamaId);
        $sube = kayitSubesi(metin($body['sube'] ?? ''));
        $tem  = trim(metin($body['temsilci'] ?? '')) ?: null;
        if ($musteriId) {
            $mt = musteriSubeTemsilci($db, $musteriId);
            $sube = $sube ?: $mt['sube'];
            $tem  = $tem  ?: $mt['temsilci'];
        }

        [$siparisNo, $izinliIds] = _nkSiparisBilgi($db, $kiralamaId);
        $tip = _nakTip($body['tip'] ?? 'dis');
        [$aracId, $plaka] = _nakArac($db, $body);

        $h = nakliyeHesap(
            (float)($body['yansitilan'] ?? 0), (float)($body['odenen'] ?? 0),
            (float)($body['kdv_oran'] ?? 20), nakliyeOdemeTuru($body['odeme_turu'] ?? null),
            (int)($body['vade_gun'] ?? 0), $tarih
        );

        $yeniId = islemIcinde($db, function (PDO $db) use ($tarih, $body, $tip, $kiralamaId, $musteriId, $plaka, $h, $sube, $tem, $aracId, $siparisNo, $izinliIds): int {
        $db->prepare("
            INSERT INTO nakliyeler (tarih, yon, tip, kiralama_id, musteri_id, ekipman, nakliyeci, plaka, lokasyon, odenen, yansitilan, sube, temsilci, aciklama,
                                    odeme_turu, vade_gun, vade_tarihi, kdv_oran, tevkifat_var, tevkifat_orani, tevkifat_tutari,
                                    sofor_adi, arac_id, planlanan_tarih, gerceklesen_tarih, km_baslangic, km_bitis, siparis_no, fiyat_bekliyor)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $tarih, _nakYon($body['yon'] ?? 'gidis'), $tip, $kiralamaId, $musteriId,
            trim(metin($body['ekipman'] ?? '')) ?: null,
            _nakNakliyeci($tip, metin($body['nakliyeci'] ?? '')),
            $plaka,
            trim(metin($body['lokasyon'] ?? '')) ?: null,
            $h['odenen'], $h['yansitilan'],
            $sube, $tem,
            trim(metin($body['aciklama'] ?? '')) ?: null,
            $h['odeme_turu'], $h['vade_gun'], $h['vade_tarihi'],
            $h['kdv_oran'], $h['tevkifat_var'], $h['tevkifat_orani'], $h['tevkifat_tutari'],
            trim(metin($body['sofor_adi'] ?? '')) ?: null,
            $aracId,
            dateStr('planlanan_tarih', $body), dateStr('gerceklesen_tarih', $body),
            isset($body['km_baslangic']) && $body['km_baslangic'] !== '' ? (float)$body['km_baslangic'] : null,
            isset($body['km_bitis']) && $body['km_bitis'] !== '' ? (float)$body['km_bitis'] : null,
            $siparisNo,
            !empty($body['fiyat_bekliyor']) ? 1 : 0,
        ]);
        $yeniId = (int)$db->lastInsertId();

        $makineler = _nkMakinelerBelirle($db, 'POST', $yeniId, $kiralamaId, $izinliIds, $body);
        _nkBaglariYaz($db, $yeniId, $makineler);
        if ($siparisNo) siparisNakliyeSenkron($db, $siparisNo);
        return $yeniId;
        });

        jsonOk(['id' => $yeniId, 'hesap' => $h], 'Nakliye kaydedildi.');

    case 'PUT':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        if ($id > 0) subeKayitDogrula($db, 'nakliyeler', $id);
        $eskiSt = $db->prepare("SELECT siparis_no, hakedis_id, yansitilan, kiralama_id, tip, yon FROM nakliyeler WHERE id=?");
        $eskiSt->execute([$id]);
        $eskiNak = $eskiSt->fetch() ?: [];
        $eskiSiparisNo = ($eskiNak['siparis_no'] ?? null) ?: null;

        $body = govdeOku();
        if (!empty($eskiNak['hakedis_id'])
            && (round((float)($body['yansitilan'] ?? 0), 2) !== round((float)$eskiNak['yansitilan'], 2)
                || (int)($body['kiralama_id'] ?? 0) !== (int)$eskiNak['kiralama_id']
                || !empty($body['fiyat_bekliyor']))) {
            jsonError('Bu nakliye hakediş #' . (int)$eskiNak['hakedis_id'] . ' ile faturalandı; müşteri tutarı ve bağlı kiralama değiştirilemez. Önce hakedişi düzeltin.', 409);
        }
        $tarih = dateStr('tarih', $body) ?: date('Y-m-d');

        if (trim((string)($body['ekipman'] ?? '')) === ''
            && (int)($body['kiralama_id'] ?? 0) < 1
            && (int)($body['musteri_id'] ?? 0) < 1) {
            jsonError('Nakliye kaydı için ekipman, kiralama veya müşteri bilgisinden en az biri gerekli.');
        }

        $musteriId  = ((int)($body['musteri_id'] ?? 0)) ?: null;
        $kiralamaId = ((int)($body['kiralama_id'] ?? 0)) ?: null;
        if ($kiralamaId) subeKayitDogrula($db, 'kiralamalar', $kiralamaId);
        $sube = kayitSubesi(metin($body['sube'] ?? ''));
        $tem  = trim(metin($body['temsilci'] ?? '')) ?: null;
        if ($musteriId) {
            $mt = musteriSubeTemsilci($db, $musteriId);
            $sube = $sube ?: $mt['sube'];
            $tem  = $tem  ?: $mt['temsilci'];
        }

        [$siparisNo, $izinliIds] = _nkSiparisBilgi($db, $kiralamaId);
        $tip = _nakTip($body['tip'] ?? 'dis');
        [$aracId, $plaka] = _nakArac($db, $body);

        $h = nakliyeHesap(
            (float)($body['yansitilan'] ?? 0), (float)($body['odenen'] ?? 0),
            (float)($body['kdv_oran'] ?? 20), nakliyeOdemeTuru($body['odeme_turu'] ?? null),
            (int)($body['vade_gun'] ?? 0), $tarih
        );

        islemIcinde($db, function (PDO $db) use ($id, $tarih, $body, $tip, $kiralamaId, $musteriId, $plaka, $h, $sube, $tem, $aracId, $siparisNo, $izinliIds, $eskiNak, $eskiSiparisNo): void {
        $db->prepare("
            UPDATE nakliyeler SET tarih=?, yon=?, tip=?, kiralama_id=?, musteri_id=?, ekipman=?, nakliyeci=?, plaka=?, lokasyon=?, odenen=?, yansitilan=?, sube=?, temsilci=?, aciklama=?,
                   odeme_turu=?, vade_gun=?, vade_tarihi=?, kdv_oran=?, tevkifat_var=?, tevkifat_orani=?, tevkifat_tutari=?,
                   sofor_adi=?, arac_id=?, planlanan_tarih=?, gerceklesen_tarih=?, km_baslangic=?, km_bitis=?, siparis_no=?, fiyat_bekliyor=?
            WHERE id=?
        ")->execute([
            $tarih, _nakYon($body['yon'] ?? 'gidis'), $tip, $kiralamaId, $musteriId,
            trim(metin($body['ekipman'] ?? '')) ?: null,
            _nakNakliyeci($tip, metin($body['nakliyeci'] ?? '')),
            $plaka,
            trim(metin($body['lokasyon'] ?? '')) ?: null,
            $h['odenen'], $h['yansitilan'],
            $sube, $tem,
            trim(metin($body['aciklama'] ?? '')) ?: null,
            $h['odeme_turu'], $h['vade_gun'], $h['vade_tarihi'],
            $h['kdv_oran'], $h['tevkifat_var'], $h['tevkifat_orani'], $h['tevkifat_tutari'],
            trim(metin($body['sofor_adi'] ?? '')) ?: null,
            $aracId,
            dateStr('planlanan_tarih', $body), dateStr('gerceklesen_tarih', $body),
            isset($body['km_baslangic']) && $body['km_baslangic'] !== '' ? (float)$body['km_baslangic'] : null,
            isset($body['km_bitis']) && $body['km_bitis'] !== '' ? (float)$body['km_bitis'] : null,
            $siparisNo,
            !empty($body['fiyat_bekliyor']) ? 1 : 0,
            $id,
        ]);

        $makineler = _nkMakinelerBelirle($db, 'PUT', $id, $kiralamaId, $izinliIds, $body);
        _nkBaglariYaz($db, $id, $makineler);
        if ($eskiNak) sfDegisirseTemizle($db, $eskiNak, $id, 'nakliye kaydında sipariş/kiralama, çeşit ya da yön değişti', (int)($_SESSION['user_id'] ?? 0));
        if ($siparisNo) siparisNakliyeSenkron($db, $siparisNo);
        if ($eskiSiparisNo && $eskiSiparisNo !== $siparisNo) siparisNakliyeSenkron($db, $eskiSiparisNo);
        });

        jsonOk(['hesap' => $h], 'Nakliye güncellendi.');

    case 'DELETE':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        if ($id > 0) subeKayitDogrula($db, 'nakliyeler', $id);
        islemYanit($db, function (PDO $db) use ($id): void {
            $spSt = $db->prepare("SELECT siparis_no, hakedis_id FROM nakliyeler WHERE id=?");
            $spSt->execute([$id]);
            $silinecek = $spSt->fetch() ?: [];
            $siparisNo = ($silinecek['siparis_no'] ?? null) ?: null;
            if (!empty($silinecek['hakedis_id'])) {
                throw new DomainException('Bu nakliye hakediş #' . (int)$silinecek['hakedis_id'] . ' ile faturalandı; silinemez. Önce hakedişi düzeltin.', 409);
            }
            $db->prepare("DELETE FROM nakliyeler WHERE id=?")->execute([$id]);
            if ($siparisNo) siparisNakliyeSenkron($db, $siparisNo);
        });
        jsonOk(null, 'Nakliye silindi.');

    default:
        jsonError('Method Not Allowed', 405);
}
