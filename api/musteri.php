<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['action'] ?? '') === 'secici') {
    $_izin = false;
    foreach (['kiralama_takip', 'cek_senet', 'nakliye', 'yedek_parca', 'arac_maliyet', 'finans'] as $_m) {
        if (hasPermission($_m, 'oku')) { $_izin = true; break; }
    }
    $_yalnizUnvan = !$_izin && hasPermission('teknik_servis', 'yaz');
    if (!$_izin && !$_yalnizUnvan) jsonError('Bu modül için yetkiniz yok.', 403);
    $st = getDb()->prepare('SELECT id, unvan' . ($_yalnizUnvan ? '' : ', sube') . ' FROM musteriler ORDER BY unvan COLLATE NOCASE');
    $st->execute();
    jsonOk($st->fetchAll());
}

requirePermission('kiralama_takip');

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDb();

if ($method === 'GET' && ($_GET['action'] ?? '') === 'iller') {
    require_once __DIR__ . '/il-veri.php';
    jsonOk(trIlListesi());
}

if ($method === 'GET' && ($_GET['action'] ?? '') === 'ilceler') {
    require_once __DIR__ . '/il-veri.php';
    $ara = ilNormalize(metin($_GET['il'] ?? ''));
    $bulunan = [];
    foreach (trIlIlce() as $il => $ilceler) if (ilNormalize($il) === $ara) { $bulunan = $ilceler; break; }
    sort($bulunan, SORT_LOCALE_STRING);
    jsonOk(array_values($bulunan));
}

function _musVergiTuru($v): ?string {
    return in_array($v, ['e_fatura', 'e_arsiv', 'istisna'], true) ? $v : null;
}

function _musIl($v): ?string {
    $il = ilYazim(metin($v));
    if ($il === '') return null;
    require_once __DIR__ . '/il-veri.php';
    if (!in_array($il, trIlListesi(), true)) jsonError('İl listeden seçilmeli.');
    return $il;
}

if ($method === 'POST' && ($_GET['action'] ?? '') === 'wolvox_senkron') {
    verifyCsrf();
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) jsonError('Geçersiz ID.');
    subeKayitDogrula($db, 'musteriler', $id);
    $body  = govdeOku();
    $durum = in_array($body['durum'] ?? '', ['senkron', 'uyumsuz'], true) ? $body['durum'] : null;
    if (!$durum) jsonError('Geçersiz senkron durumu.');
    $db->prepare("UPDATE musteriler SET wolvox_senkron_durum=?, wolvox_senkron_tarihi=datetime('now','localtime') WHERE id=?")
       ->execute([$durum, $id]);
    logla($db, 'musteriler', $id, 'guncelle', null, null, 'Wolvox cari eşleşmesi ' . ($durum === 'senkron' ? 'doğrulandı' : 'UYUMSUZ işaretlendi'));
    jsonOk(null, 'Kaydedildi.');
}

switch ($method) {

    case 'GET':
        if (isset($_GET['id'])) {
            $id = (int)$_GET['id'];
            $stmt = $db->prepare("SELECT * FROM musteriler WHERE id=?");
            $stmt->execute([$id]);
            $m = $stmt->fetch();
            if (!$m) jsonError('Müşteri bulunamadı.', 404);
            $kisit = subeKisiti();
            $finansGizli = $kisit !== null && trim((string)($m['sube'] ?? '')) !== $kisit;
            $m['finans_gizli']   = $finansGizli;
            $m['duzenlenebilir'] = !$finansGizli;
            $subeKos = $kisit !== null ? ' AND sube = ?' : '';
            $subePar = fn(array $p) => $kisit !== null ? array_merge($p, [$kisit]) : $p;
            if ($finansGizli) {
                foreach (['risk_limit', 'acilis_bakiye', 'acilis_tarihi'] as $_f) $m[$_f] = null;
            }

            $kStmt = $db->prepare("SELECT * FROM kiralamalar WHERE musteri_id=?$subeKos ORDER BY baslangic DESC, id DESC");
            $kStmt->execute($subePar([$id]));
            $kiralamalar = $kStmt->fetchAll();
            foreach ($kiralamalar as &$k) { $k['hesap'] = kiralamaTutar($k); }
            unset($k);
            $m['kiralamalar'] = $kiralamalar;

            $_mSube = trim((string)($m['sube'] ?? ''));
            $_etkinSube = fn($s) => trim((string)$s) !== '' ? trim((string)$s) : $_mSube;
            $tStmt = $db->prepare("SELECT t.*, h.kod AS hakedis_kod, h.sube AS _hakedis_sube FROM tahsilatlar t LEFT JOIN hakedisler h ON h.id=t.hakedis_id WHERE t.musteri_id=? ORDER BY t.tarih DESC, t.id DESC");
            $tStmt->execute([$id]);
            $tahsilatlar = $finansGizli ? [] : $tStmt->fetchAll();
            if ($kisit !== null) $tahsilatlar = array_values(array_filter($tahsilatlar, fn($t) => $_etkinSube(!empty($t['hakedis_id']) ? ($t['_hakedis_sube'] ?? '') : ($t['sube'] ?? '')) === $kisit));
            foreach ($tahsilatlar as &$_t) unset($_t['_hakedis_sube']);
            unset($_t);
            $m['tahsilatlar'] = $tahsilatlar;

            $hStmt = $db->prepare("SELECT * FROM hakedisler WHERE musteri_id=? ORDER BY fatura_tarihi DESC, id DESC");
            $hStmt->execute([$id]);
            $hakedisler = $finansGizli ? [] : $hStmt->fetchAll();
            if ($kisit !== null) $hakedisler = array_values(array_filter($hakedisler, fn($h) => $_etkinSube($h['sube'] ?? '') === $kisit));
            $today = date('Y-m-d');
            foreach ($hakedisler as &$h) {
                $odenen = hakedisOdenen($db, (int)$h['id']);
                $h['odenen'] = $odenen;
                $h['kalan']  = round((float)$h['genel_toplam'] - $odenen, 2);
                $h['geciken'] = ($h['durum'] !== 'tahsil_edildi' && !empty($h['vade_tarihi']) && $h['vade_tarihi'] < $today) ? 1 : 0;
            }
            unset($h);
            $m['hakedisler'] = $hakedisler;

            $teklifler = [];
            try {
                $qs = $db->prepare("SELECT id, kod, tarih, gecerlilik, durum, kdv_oran, iskonto_oran FROM teklifler WHERE musteri_id=?$subeKos ORDER BY tarih DESC, id DESC");
                $qs->execute($subePar([$id]));
                foreach ($qs->fetchAll() as $t) {
                    $kq = $db->prepare("SELECT * FROM teklif_kalemleri WHERE teklif_id=?");
                    $kq->execute([(int)$t['id']]);
                    $tp = teklifToplam($kq->fetchAll(), (float)($t['kdv_oran'] ?? 20), (float)($t['iskonto_oran'] ?? 0));
                    $t['genel_toplam'] = $tp['genel_toplam'] ?? 0;
                    $teklifler[] = $t;
                }
            } catch (\Exception $e) { hataGunlugu('musteri.teklifler', $e, true); }
            $m['teklifler'] = $teklifler;

            $cekler = [];
            try {
                $cq = $db->prepare("SELECT id, tip, yon, tutar, vade_tarihi, durum, banka, belge_no FROM cek_senet WHERE musteri_id=? ORDER BY vade_tarihi DESC, id DESC");
                $cq->execute([$id]);
                $cekler = $cq->fetchAll();
            } catch (\Exception $e) { hataGunlugu('musteri.cekler', $e, true); }
            $m['cek_senet'] = $finansGizli ? [] : $cekler;

            $nakliyeler = [];
            try {
                $nq = $db->prepare("SELECT id, tarih, yon, ekipman, lokasyon, odenen, yansitilan FROM nakliyeler WHERE musteri_id=?$subeKos ORDER BY tarih DESC, id DESC");
                $nq->execute($subePar([$id]));
                $nakliyeler = $nq->fetchAll();
            } catch (\Exception $e) { hataGunlugu('musteri.nakliyeler', $e, true); }
            $m['nakliyeler'] = $nakliyeler;

            $m['cari'] = $finansGizli ? null : musteriBakiye($db, $id);
            jsonOk($m);
        }

        $search = trim(metin($_GET['q'] ?? ''));
        $where  = [];
        $params = [];
        if ($search !== '') {
            $where[] = "(" . trAramaSql('unvan') . " LIKE ? OR " . trAramaSql('yetkili') . " LIKE ? OR " . trAramaSql('telefon') . " LIKE ? OR " . trAramaSql('vergi_no') . " LIKE ?)";
            $like = '%' . trArama($search) . '%';
            $params = [$like, $like, $like, $like];
        }
        if (($sb = trim(metin($_GET['sube'] ?? ''))) !== '')      { $where[] = "sube=?";     $params[] = $sb; }
        $kisit = subeKisiti();
        if (($tm = trim(metin($_GET['temsilci'] ?? ''))) !== '')  { $where[] = "temsilci=?"; $params[] = $tm; }
        $sql = "SELECT * FROM musteriler";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY unvan COLLATE NOCASE";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $list = $stmt->fetchAll();

        $kalemler = cariBorcKalemleri($db);
        $borcMap = []; $faturasizMap = [];
        foreach ($kalemler as $m => $x) {
            $borcMap[$m]     = array_sum(array_column($x['borclar'], 'tutar'));
            $faturasizMap[$m] = array_sum(array_column($x['faturalanmamis'], 'tutar'));
        }
        $aktifMap = [];
        $aSt = $db->prepare("SELECT musteri_id, COUNT(*) n FROM kiralamalar WHERE durum='aktif'"
                          . ($kisit !== null ? " AND sube = ?" : '') . " GROUP BY musteri_id");
        $aSt->execute($kisit !== null ? [$kisit] : []);
        foreach ($aSt->fetchAll() as $r) {
            $aktifMap[(int)$r['musteri_id']] = (int)$r['n'];
        }
        $tahsilMap = [];
        foreach ($db->query("SELECT musteri_id, COALESCE(SUM(tutar),0) t FROM tahsilatlar GROUP BY musteri_id")->fetchAll() as $r) {
            $tahsilMap[(int)$r['musteri_id']] = (float)$r['t'];
        }

        require_once __DIR__.'/cek-cari-kurallari.php';$cekCari=crVeri($db);
        foreach ($list as &$row) {
            $mid    = (int)$row['id'];
            $borc   = round($borcMap[$mid] ?? 0, 2);
            $tahsil = round($tahsilMap[$mid] ?? 0, 2);
            $row['toplam_borc']    = $borc;
            $row['toplam_tahsil']  = $tahsil;
            $row['cek_mahsup']=round($cekCari['mahsup'][$mid]??0,2);$row['cek_risk']=$cekCari['risk'][$mid]??['bekliyor'=>0,'avukatlik'=>0,'batik'=>0];
            $row['bakiye']         = round($borc - $tahsil - $row['cek_mahsup'], 2);
            $row['faturalanmamis'] = round($faturasizMap[$mid] ?? 0, 2);
            $row['aktif_kiralama'] = (int)($aktifMap[$mid] ?? 0);
            $gizli = $kisit !== null && trim((string)($row['sube'] ?? '')) !== $kisit;
            $row['finans_gizli']   = $gizli;
            $row['duzenlenebilir'] = !$gizli;
            if ($gizli) {
                foreach (['toplam_borc', 'toplam_tahsil', 'cek_mahsup', 'cek_risk', 'bakiye', 'faturalanmamis',
                          'risk_limit', 'acilis_bakiye', 'acilis_tarihi'] as $_f) $row[$_f] = null;
            }
        }
        unset($row);
        jsonOk($list);
        break;

    case 'POST':
        verifyCsrf();
        $body  = govdeOku();
        $unvan = trim(metin($body['unvan'] ?? ''));
        if ($unvan === '') jsonError('Ünvan / Ad zorunludur.');

        $stmt = $db->prepare("
            INSERT INTO musteriler (tur, unvan, yetkili, telefon, email, vergi_dairesi, vergi_no, adres, notlar, durum, sube, temsilci, risk_limit, iskonto_oran, wolvox_cari_kodu, acilis_bakiye, acilis_tarihi, vergi_turu, il, odeme_tercihi) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            in_array($body['tur'] ?? '', ['kurumsal','bireysel'], true) ? $body['tur'] : 'kurumsal',
            $unvan,
            trim(metin($body['yetkili'] ?? '')) ?: null,
            trim(metin($body['telefon'] ?? '')) ?: null,
            trim(metin($body['email'] ?? '')) ?: null,
            trim(metin($body['vergi_dairesi'] ?? '')) ?: null,
            trim(metin($body['vergi_no'] ?? '')) ?: null,
            trim(metin($body['adres'] ?? '')) ?: null,
            trim(metin($body['notlar'] ?? '')) ?: null,
            in_array($body['durum'] ?? '', ['aktif','pasif'], true) ? $body['durum'] : 'aktif',
            kayitSubesi(metin($body['sube'] ?? '')),
            trim(metin($body['temsilci'] ?? '')) ?: null,
            max(0, (float)($body['risk_limit'] ?? 0)),
            max(0, min(100, (float)($body['iskonto_oran'] ?? 0))),
            trim(metin($body['wolvox_cari_kodu'] ?? '')) ?: null,
            (float)($body['acilis_bakiye'] ?? 0),
            dateStr('acilis_tarihi', $body) ?: null,
            _musVergiTuru($body['vergi_turu'] ?? null),
            _musIl($body['il'] ?? ''),
            musteriOdemeTercihi($body['odeme_tercihi'] ?? null),
        ]);
        $_yeniId = (int)$db->lastInsertId();
        logla($db, 'musteriler', $_yeniId, 'ekle', null, logKayitAl($db, 'musteriler', $_yeniId), 'Müşteri eklendi');
        jsonOk(['id' => $_yeniId], 'Müşteri eklendi.');

    case 'PUT':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        if ($id > 0) subeKayitDogrula($db, 'musteriler', $id);
        $_logEski = logKayitAl($db, 'musteriler', $id);
        $body  = govdeOku();
        $unvan = trim(metin($body['unvan'] ?? ''));
        if ($unvan === '') jsonError('Ünvan / Ad zorunludur.');

        $stmt = $db->prepare("
            UPDATE musteriler SET tur=?, unvan=?, yetkili=?, telefon=?, email=?,
                vergi_dairesi=?, vergi_no=?, adres=?, notlar=?, durum=?, sube=?, temsilci=?, risk_limit=?, iskonto_oran=?,
                wolvox_cari_kodu=?, acilis_bakiye=?, acilis_tarihi=?, vergi_turu=?, il=?, odeme_tercihi=?, updated_at=datetime('now','localtime')
            WHERE id=?
        ");
        $stmt->execute([
            in_array($body['tur'] ?? '', ['kurumsal','bireysel'], true) ? $body['tur'] : 'kurumsal',
            $unvan,
            trim(metin($body['yetkili'] ?? '')) ?: null,
            trim(metin($body['telefon'] ?? '')) ?: null,
            trim(metin($body['email'] ?? '')) ?: null,
            trim(metin($body['vergi_dairesi'] ?? '')) ?: null,
            trim(metin($body['vergi_no'] ?? '')) ?: null,
            trim(metin($body['adres'] ?? '')) ?: null,
            trim(metin($body['notlar'] ?? '')) ?: null,
            in_array($body['durum'] ?? '', ['aktif','pasif'], true) ? $body['durum'] : 'aktif',
            kayitSubesi(pasifSubeKoru($db, 'musteriler', $id, metin($body['sube'] ?? ''))),
            trim(metin($body['temsilci'] ?? '')) ?: null,
            max(0, (float)($body['risk_limit'] ?? 0)),
            max(0, min(100, (float)($body['iskonto_oran'] ?? 0))),
            trim(metin($body['wolvox_cari_kodu'] ?? '')) ?: null,
            (float)($body['acilis_bakiye'] ?? 0),
            dateStr('acilis_tarihi', $body) ?: null,
            _musVergiTuru($body['vergi_turu'] ?? null),
            _musIl($body['il'] ?? ''),
            array_key_exists('odeme_tercihi', $body) ? musteriOdemeTercihi($body['odeme_tercihi']) : ($_logEski['odeme_tercihi'] ?? null),
            $id,
        ]);
        logla($db, 'musteriler', $id, 'guncelle', $_logEski, logKayitAl($db, 'musteriler', $id), 'Müşteri güncellendi');
        jsonOk(null, 'Müşteri güncellendi.');

    case 'DELETE':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        if ($id > 0) subeKayitDogrula($db, 'musteriler', $id);
        $chk = $db->prepare("SELECT COUNT(*) FROM kiralamalar WHERE musteri_id=?");
        $chk->execute([$id]);
        if ((int)$chk->fetchColumn() > 0) {
            jsonError('Bu müşterinin kiralama kayıtları var. Önce onları silin veya müşteriyi pasife alın.');
        }
        $_logEski = logKayitAl($db, 'musteriler', $id);
        $db->prepare("DELETE FROM musteriler WHERE id=?")->execute([$id]);
        logla($db, 'musteriler', $id, 'sil', $_logEski, null, 'Müşteri SİLİNDİ: ' . ($_logEski['unvan'] ?? ('#' . $id)));
        jsonOk(null, 'Müşteri silindi.');

    default:
        jsonError('Method Not Allowed', 405);
}
