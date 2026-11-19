<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();

$method  = $_SERVER['REQUEST_METHOD'];
$db      = getDb();
$aksiyon = (string)($_GET['action'] ?? '');

if ($aksiyon === 'nakliye_araclari' && $method === 'GET') {
    if (!hasPermission('nakliye') && !hasPermission('kiralama_takip') && !hasPermission('arac')) jsonError('Bu modül için yetkiniz yok.', 403);
    $hepsi  = !empty($_GET['hepsi']);
    $where  = $hepsi ? ['COALESCE(a.aktif,1)=1'] : ['COALESCE(a.nakliye_araci,0)=1'];
    $params = [];
    $kisit  = subeKisitiHam();
    if ($kisit !== null) { $where[] = "(a.sube=? OR a.sube IS NULL OR TRIM(a.sube)='')"; $params[] = $kisit; }
    elseif (($sb = trim(metin($_GET['sube'] ?? ''))) !== '') { $where[] = 'a.sube=?'; $params[] = $sb; }
    if (($q = trim(metin($_GET['q'] ?? ''))) !== '') {
        $where[] = '(' . trAramaSql('a.plaka') . ' LIKE ? OR ' . trAramaSql('a.marka') . ' LIKE ? OR ' . trAramaSql('a.model') . ' LIKE ?)';
        $like = '%' . trArama($q) . '%';
        array_push($params, $like, $like, $like);
    }
    $st = $db->prepare("SELECT a.id, a.plaka, a.marka, a.model, a.sube, COALESCE(a.aktif,1) AS aktif, a.durum,
                               COALESCE(a.nakliye_araci,0) AS nakliye_araci,
                               (SELECT COUNT(*) FROM nakliyeler n WHERE n.arac_id=a.id) AS nakliye_sayisi
                        FROM araclar a WHERE " . implode(' AND ', $where) . "
                        ORDER BY COALESCE(a.nakliye_araci,0) DESC, a.plaka");
    $st->execute($params);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id']; $r['aktif'] = (int)$r['aktif'];
        $r['nakliye_araci'] = (int)$r['nakliye_araci']; $r['nakliye_sayisi'] = (int)$r['nakliye_sayisi'];
    }
    unset($r);
    jsonOk(['list' => $rows, 'sube_kisiti' => $kisit]);
}

if (($aksiyon === 'nakliye_araci_ata' || $aksiyon === 'nakliye_araci_kaldir') && $method === 'PUT') {
    if (!hasPermission('nakliye', 'yaz') && !hasPermission('kiralama_takip', 'yaz') && !hasPermission('arac', 'yaz')) {
        jsonError('Nakliye aracı atamak için Nakliye ya da Kiralama Takip yazma yetkisi gerekir.', 403);
    }
    verifyCsrf();
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) jsonError('Geçersiz ID.');
    $st = $db->prepare("SELECT id, plaka, sube, COALESCE(nakliye_araci,0) AS nakliye_araci FROM araclar WHERE id=?");
    $st->execute([$id]);
    $a = $st->fetch();
    if (!$a) jsonError('Araç bulunamadı.', 404);
    $kisit = subeKisitiHam();
    if ($kisit !== null && trim((string)$a['sube']) !== '' && trim((string)$a['sube']) !== $kisit) {
        jsonError('Bu araç başka bir şubeye ait; erişim yetkiniz yok.', 403);
    }
    $yeni = $aksiyon === 'nakliye_araci_ata' ? 1 : 0;
    $eski = logKayitAl($db, 'araclar', $id);
    $db->prepare("UPDATE araclar SET nakliye_araci=?, updated_at=datetime('now','localtime') WHERE id=?")->execute([$yeni, $id]);
    logla($db, 'araclar', $id, 'guncelle', $eski, logKayitAl($db, 'araclar', $id),
          ($yeni ? 'Nakliye aracı olarak işaretlendi: ' : 'Nakliye aracı işareti kaldırıldı: ') . $a['plaka']);
    jsonOk(['id' => $id, 'nakliye_araci' => $yeni], $yeni ? 'Araç nakliye araçlarına eklendi.' : 'Araç nakliye araçlarından çıkarıldı.');
}

requirePermission('arac');
subeMerkezi('arac');

switch ($method) {

    case 'GET':
        if (isset($_GET['id'])) {
            $id   = (int)$_GET['id'];
            if ($id > 0) subeKayitDogrula($db, 'araclar', $id);
            $stmt = $db->prepare("SELECT * FROM araclar WHERE id = ?");
            $stmt->execute([$id]);
            $a = $stmt->fetch();
            if (!$a) jsonError('Araç bulunamadı.', 404);

            $bStmt = $db->prepare("SELECT * FROM arac_belgeler WHERE arac_id = ? ORDER BY kategori, created_at DESC");
            $bStmt->execute([$id]);
            $a['belgeler'] = $bStmt->fetchAll();
            $a['gecmis'] = makineGecmis($db, 'arac', $id);
            $a['kirada'] = makineKiradaMi($db, 'arac', $id);
            $a['gorunen_durum'] = opGorunenDurumKarar((string)($a['durum'] ?: 'musait'), $a['kirada'], $a['kirada'] ? opGorunenDurum($db, 'arac', $id) : null);
            $ds = $db->prepare("SELECT 1 FROM teknik_servis WHERE makine_tur='arac' AND makine_id=? AND durum<>'tamamlandi' AND kaynak='depo_inceleme' LIMIT 1");
            $ds->execute([$id]);
            $a['depo_servis'] = (bool)$ds->fetchColumn();
            jsonOk($a);
        }

        $search = trim(metin($_GET['q'] ?? ''));
        $params = [];
        $where  = [];
        if ($search !== '') {
            $where[]  = "(" . trAramaSql('plaka') . " LIKE ? OR " . trAramaSql('marka') . " LIKE ? OR " . trAramaSql('model') . " LIKE ? OR " . trAramaSql('ruhsat_no') . " LIKE ?)";
            $like     = '%' . trArama($search) . '%';
            $params   = array_merge($params, [$like, $like, $like, $like]);
        }
        if (($sb = trim(metin($_GET['sube'] ?? ''))) !== '') { $where[] = "sube=?"; $params[] = $sb; }
        subeKosulEkle($where, $params, 'sube');
        if (($dr = trim(metin($_GET['durum'] ?? ''))) !== '') { $where[] = "durum=?"; $params[] = $dr; }
        $sql = "SELECT id, plaka, marka, model, sube, aktif, durum, COALESCE(nakliye_araci,0) AS nakliye_araci, temin_sekli, tescil_tarihi, trafik_bitis, trafik_sigorta_tutar, kasko_bitis, kasko_tutar, tuv_muayene_bitis, periyodik_kontrol_bitis, calisma_saati, son_bakim_saat, bakim_araligi_saat, yakit_sarfiyat_100km, rolanti_birim_ucret_saat, maliyet_amortisman_gun, maliyet_bakim_gun, maliyet_sigorta_gun, maliyet_mtv_gun, mtv_yillik, rolanti_litre_saat, temin_sekli, dis_kira_bedeli, makine_kodu, liste_fiyat_aylik, liste_fiyat_gunluk, liste_fiyat_haftalik, yakit_tipi, alis_tarihi, kategori, ozellikler, metraj,
                (SELECT g.sonraki_tarih FROM genel_servis g WHERE g.makine_tur='arac' AND g.makine_id=araclar.id
                 ORDER BY g.tarih DESC, g.id DESC LIMIT 1) AS gs_sonraki_tarih
                FROM araclar";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY plaka";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $kiradaSet = makineKiradaSeti($db, 'arac');
        $akis = opGorunenDurumToplu($db, 'arac');
        foreach ($rows as &$r) {
            $r['kirada'] = in_array((int)$r['id'], $kiradaSet, true);
            $r['gorunen_durum'] = opGorunenDurumKarar((string)($r['durum'] ?: 'musait'), $r['kirada'], $akis[(int)$r['id']] ?? null);
        }
        unset($r);
        jsonOk($rows);
        break;

    case 'POST':
        verifyCsrf();
        $body  = govdeOku();
        $plaka = strtoupper(preg_replace('/\s+/', ' ', trim(metin($body['plaka'] ?? ''))));
        if ($plaka === '') jsonError('Plaka zorunludur.');

        $durumIzinli = ['musait','serviste','nakliyede','satildi','pasif','bakim_bekliyor','inceleme_bekliyor','bakimda','kiraya_gidecek','kiradan_gelecek'];
        $durum = in_array($body['durum'] ?? '', $durumIzinli, true) ? $body['durum'] : 'musait';
        try { $mkG = mkGovde($db, $body); } catch (DomainException $e) { jsonError($e->getMessage()); }

        $stmt = $db->prepare("
            INSERT INTO araclar (plaka, ruhsat_no, marka, model, tescil_tarihi,
                trafik_baslangic, trafik_bitis, trafik_sigorta_tutar,
                kasko_baslangic, kasko_bitis, kasko_tutar,
                makine_kirilma_baslangic, makine_kirilma_bitis,
                tuv_muayene_bitis, periyodik_kontrol_bitis, notlar, sube, alis_bedeli, faydali_omur_yil, aktif,
                calisma_saati, son_bakim_saat, bakim_araligi_saat, durum, garanti_bitis,
                yakit_sarfiyat_100km, rolanti_birim_ucret_saat, maliyet_amortisman_gun, maliyet_bakim_gun,
                maliyet_sigorta_gun, maliyet_mtv_gun, mtv_yillik, rolanti_litre_saat, temin_sekli, dis_kira_bedeli, makine_kodu, liste_fiyat_aylik, alis_tarihi, liste_fiyat_gunluk, liste_fiyat_haftalik, yakit_tipi)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        try {
            $stmt->execute([
                $plaka,
                trim(metin($body['ruhsat_no'] ?? '')) ?: null,
                trim(metin($body['marka'] ?? '')) ?: null,
                trim(metin($body['model'] ?? '')) ?: null,
                dateStr('tescil_tarihi', $body),
                dateStr('trafik_baslangic', $body),
                dateStr('trafik_bitis', $body),
                max(0, (float)($body['trafik_sigorta_tutar'] ?? 0)) ?: null,
                dateStr('kasko_baslangic', $body),
                dateStr('kasko_bitis', $body),
                max(0, (float)($body['kasko_tutar'] ?? 0)) ?: null,
                dateStr('makine_kirilma_baslangic', $body),
                dateStr('makine_kirilma_bitis', $body),
                dateStr('tuv_muayene_bitis', $body),
                dateStr('periyodik_kontrol_bitis', $body),
                trim(metin($body['notlar'] ?? '')) ?: null,
                trim(metin($body['sube'] ?? '')) ?: null,
                max(0, (float)($body['alis_bedeli'] ?? 0)),
                max(0, (int)($body['faydali_omur_yil'] ?? 0)),
                isset($body['aktif']) ? (int)(bool)$body['aktif'] : 1,
                max(0, (float)($body['calisma_saati'] ?? 0)),
                max(0, (float)($body['son_bakim_saat'] ?? 0)),
                max(0, (float)($body['bakim_araligi_saat'] ?? 0)),
                $durum,
                dateStr('garanti_bitis', $body),
                max(0, (float)($body['yakit_sarfiyat_100km'] ?? 0)),
                max(0, (float)($body['rolanti_birim_ucret_saat'] ?? 0)),
                max(0, (float)($body['maliyet_amortisman_gun'] ?? 0)),
                max(0, (float)($body['maliyet_bakim_gun'] ?? 0)),
                max(0, (float)($body['maliyet_sigorta_gun'] ?? 0)),
                max(0, (float)($body['maliyet_mtv_gun'] ?? 0)),
                max(0, (float)($body['mtv_yillik'] ?? 0)),
                max(0, (float)($body['rolanti_litre_saat'] ?? 0)),
                trim(metin($body['temin_sekli'] ?? '')) === 'dis' ? 'dis' : 'stok',
                max(0, (float)($body['dis_kira_bedeli'] ?? 0)),
                trim(metin($body['makine_kodu'] ?? '')) ?: null,
                max(0, (float)($body['liste_fiyat_aylik'] ?? 0)),
                dateStr('alis_tarihi', $body),
                max(0, (float)($body['liste_fiyat_gunluk'] ?? 0)),
                max(0, (float)($body['liste_fiyat_haftalik'] ?? 0)),
                makineYakitTipi($body['yakit_tipi'] ?? null),
            ]);
        } catch (\PDOException $e) {
            if (strpos($e->getMessage(), 'UNIQUE') !== false) jsonError('Bu plaka zaten kayıtlı.');
            jsonError('Veritabanı hatası oluştu.', 500);
        }
        $yeniId = (int)$db->lastInsertId();
        mkYaz($db, 'araclar', $yeniId, $mkG);
        jsonOk(['id' => $yeniId], 'Araç eklendi.');

    case 'PUT':
        verifyCsrf();
        $id    = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        if ($id > 0) subeKayitDogrula($db, 'araclar', $id);
        $body  = govdeOku();
        $_eskiKayitTam = logKayitAl($db, 'araclar', $id);
        $plaka = strtoupper(preg_replace('/\s+/', ' ', trim(metin($body['plaka'] ?? ''))));
        $durumIzinli = ['musait','serviste','nakliyede','satildi','pasif','bakim_bekliyor','inceleme_bekliyor','bakimda','kiraya_gidecek','kiradan_gelecek'];
        if (count($body) === 1 && isset($body['durum'])) {
            $yeniDurum = in_array($body['durum'], $durumIzinli, true) ? $body['durum'] : null;
            if ($yeniDurum === null) jsonError('Geçersiz durum.');
            if($yeniDurum==='nakliyede'&&($e=hzMakineEngel($db,'arac',$id)))jsonError($e,409);
            if (!in_array($yeniDurum, ['satildi','pasif','nakliyede','serviste'], true)
                && makineAcikServisVarMi($db, 'arac', $id)) {
                jsonError('Bu makinede açık bir servis kaydı var; önce servisi kapatın.', 409);
            }
            $_eskiKayit = logKayitAl($db, 'araclar', $id);
            $db->beginTransaction();
            try {
                if ($opHata = opMakineKartiHareketi($db, 'arac', $id, $yeniDurum)) throw new DomainException($opHata);
                $db->prepare("UPDATE araclar SET durum=?, updated_at=datetime('now','localtime') WHERE id=?")
                   ->execute([$yeniDurum, $id]);
                logla($db, 'araclar', $id, 'guncelle', $_eskiKayit, logKayitAl($db, 'araclar', $id), 'Durum: ' . $yeniDurum);
                $db->commit();
            } catch (DomainException $e) { $db->rollBack(); jsonError($e->getMessage(), 403); }
            catch (\Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
            jsonOk(['durum' => $yeniDurum], 'Durum güncellendi.');
        }

        if ($plaka === '') jsonError('Plaka zorunludur.');

        $durumIzinli = ['musait','serviste','nakliyede','satildi','pasif','bakim_bekliyor','inceleme_bekliyor','bakimda','kiraya_gidecek','kiradan_gelecek'];

        $mevcutDurum = $db->prepare("SELECT durum FROM araclar WHERE id=?");
        $mevcutDurum->execute([$id]);
        $eskiDurum = $mevcutDurum->fetchColumn() ?: 'musait';
        $mkMevcut = $db->query('SELECT kategori FROM araclar WHERE id=' . (int)$id)->fetchColumn() ?: null;
        try { $mkG = mkGovde($db, $body, $mkMevcut); } catch (DomainException $e) { jsonError($e->getMessage()); }
        $durum = in_array($body['durum'] ?? '', $durumIzinli, true) ? $body['durum'] : $eskiDurum;
        if($durum==='nakliyede'&&$eskiDurum!=='nakliyede'&&($e=hzMakineEngel($db,'arac',$id)))jsonError($e,409);
        if (!in_array($durum, ['satildi', 'pasif', 'nakliyede', 'serviste'], true)
            && makineAcikServisVarMi($db, 'arac', $id)) {
            $durum = 'serviste';
        }

        $korunacak = ['notlar', 'alis_tarihi', 'rolanti_birim_ucret_saat', 'maliyet_mtv_gun', 'liste_fiyat_gunluk', 'liste_fiyat_haftalik', 'yakit_tipi'];
        if (array_diff($korunacak, array_keys($body))) {
            $kq = $db->prepare('SELECT ' . implode(', ', $korunacak) . ' FROM araclar WHERE id=?');
            $kq->execute([$id]);
            $kayitliAlan = $kq->fetch(PDO::FETCH_ASSOC) ?: [];
            foreach ($korunacak as $_k) {
                if (!array_key_exists($_k, $body)) $body[$_k] = $kayitliAlan[$_k] ?? null;
            }
        }

        $stmt = $db->prepare("
            UPDATE araclar SET plaka=?, ruhsat_no=?, marka=?, model=?, tescil_tarihi=?,
                trafik_baslangic=?, trafik_bitis=?, trafik_sigorta_tutar=?,
                kasko_baslangic=?, kasko_bitis=?, kasko_tutar=?,
                makine_kirilma_baslangic=?, makine_kirilma_bitis=?,
                tuv_muayene_bitis=?, periyodik_kontrol_bitis=?, notlar=?, sube=?, alis_bedeli=?, faydali_omur_yil=?, aktif=?,
                calisma_saati=?, son_bakim_saat=?, bakim_araligi_saat=?, durum=?, garanti_bitis=?,
                yakit_sarfiyat_100km=?, rolanti_birim_ucret_saat=?, maliyet_amortisman_gun=?, maliyet_bakim_gun=?,
                maliyet_sigorta_gun=?, maliyet_mtv_gun=?, mtv_yillik=?, rolanti_litre_saat=?, temin_sekli=?, dis_kira_bedeli=?, makine_kodu=?, liste_fiyat_aylik=?, alis_tarihi=?, liste_fiyat_gunluk=?, liste_fiyat_haftalik=?, yakit_tipi=?,
                updated_at=datetime('now','localtime')
            WHERE id=?
        ");
        $db->beginTransaction();
        if ($durum !== $eskiDurum && ($opHata = opMakineKartiHareketi($db, 'arac', $id, $durum))) { $db->rollBack(); jsonError($opHata, 403); }
        try {
            $stmt->execute([
                $plaka,
                trim(metin($body['ruhsat_no'] ?? '')) ?: null,
                trim(metin($body['marka'] ?? '')) ?: null,
                trim(metin($body['model'] ?? '')) ?: null,
                dateStr('tescil_tarihi', $body),
                dateStr('trafik_baslangic', $body),
                dateStr('trafik_bitis', $body),
                max(0, (float)($body['trafik_sigorta_tutar'] ?? 0)) ?: null,
                dateStr('kasko_baslangic', $body),
                dateStr('kasko_bitis', $body),
                max(0, (float)($body['kasko_tutar'] ?? 0)) ?: null,
                dateStr('makine_kirilma_baslangic', $body),
                dateStr('makine_kirilma_bitis', $body),
                dateStr('tuv_muayene_bitis', $body),
                dateStr('periyodik_kontrol_bitis', $body),
                trim(metin($body['notlar'] ?? '')) ?: null,
                pasifSubeKoru($db, 'araclar', $id, metin($body['sube'] ?? '')),
                max(0, (float)($body['alis_bedeli'] ?? 0)),
                max(0, (int)($body['faydali_omur_yil'] ?? 0)),
                isset($body['aktif']) ? (int)(bool)$body['aktif'] : 1,
                max(0, (float)($body['calisma_saati'] ?? 0)),
                max(0, (float)($body['son_bakim_saat'] ?? 0)),
                max(0, (float)($body['bakim_araligi_saat'] ?? 0)),
                $durum,
                dateStr('garanti_bitis', $body),
                max(0, (float)($body['yakit_sarfiyat_100km'] ?? 0)),
                max(0, (float)($body['rolanti_birim_ucret_saat'] ?? 0)),
                max(0, (float)($body['maliyet_amortisman_gun'] ?? 0)),
                max(0, (float)($body['maliyet_bakim_gun'] ?? 0)),
                max(0, (float)($body['maliyet_sigorta_gun'] ?? 0)),
                max(0, (float)($body['maliyet_mtv_gun'] ?? 0)),
                max(0, (float)($body['mtv_yillik'] ?? 0)),
                max(0, (float)($body['rolanti_litre_saat'] ?? 0)),
                trim(metin($body['temin_sekli'] ?? '')) === 'dis' ? 'dis' : 'stok',
                max(0, (float)($body['dis_kira_bedeli'] ?? 0)),
                trim(metin($body['makine_kodu'] ?? '')) ?: null,
                max(0, (float)($body['liste_fiyat_aylik'] ?? 0)),
                dateStr('alis_tarihi', $body),
                max(0, (float)($body['liste_fiyat_gunluk'] ?? 0)),
                max(0, (float)($body['liste_fiyat_haftalik'] ?? 0)),
                makineYakitTipi($body['yakit_tipi'] ?? null),
                $id,
            ]);
            mkYaz($db, 'araclar', $id, $mkG);
            $db->commit();
        } catch (\PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            if (strpos($e->getMessage(), 'UNIQUE') !== false) jsonError('Bu plaka başka bir araçta kayıtlı.');
            jsonError('Veritabanı hatası oluştu.', 500);
        }
        logla($db, 'araclar', $id, 'guncelle', $_eskiKayitTam, logKayitAl($db, 'araclar', $id), 'Araç güncellendi: ' . $plaka);
        jsonOk(null, 'Araç güncellendi.');

    case 'DELETE':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        if ($id > 0) subeKayitDogrula($db, 'araclar', $id);
        $bStmt = $db->prepare("SELECT dosya_yolu FROM arac_belgeler WHERE arac_id = ? AND dosya_yolu IS NOT NULL");
        $bStmt->execute([$id]);
        $uploadBase = realpath(UPLOAD_PATH);
        foreach ($bStmt->fetchAll(PDO::FETCH_COLUMN) as $rel) {
            if (preg_match('#^[a-z]+/\d+/[a-f0-9]{32}\.[a-z0-9]{2,5}$#', $rel)) {
                $full = realpath(UPLOAD_PATH . $rel);
                if ($full && $uploadBase && strncmp($full, $uploadBase, strlen($uploadBase)) === 0 && file_exists($full)) {
                    unlink($full);
                }
            }
        }
        $tStmt = $db->prepare("SELECT dosya_yolu FROM tutanaklar WHERE tur='arac' AND entity_id=? AND dosya_yolu IS NOT NULL");
        $tStmt->execute([$id]);
        foreach ($tStmt->fetchAll(PDO::FETCH_COLUMN) as $rel) {
            if (preg_match('#^tutanak/[a-z]+/\d+/[a-f0-9]{32}\.[a-z0-9]{2,5}$#', $rel)) {
                $full = realpath(UPLOAD_PATH . $rel);
                if ($full && $uploadBase && strncmp($full, $uploadBase, strlen($uploadBase)) === 0 && file_exists($full)) {
                    unlink($full);
                }
            }
        }
        $db->prepare("DELETE FROM tutanaklar WHERE tur='arac' AND entity_id=?")->execute([$id]);
        $db->prepare("DELETE FROM araclar WHERE id=?")->execute([$id]);
        jsonOk(null, 'Araç silindi.');

    default:
        jsonError('Method Not Allowed', 405);
}
