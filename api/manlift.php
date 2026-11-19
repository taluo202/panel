<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('manlift');
subeMerkezi('manlift');

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDb();

switch ($method) {

    case 'GET':
        if (isset($_GET['id'])) {
            $id   = (int)$_GET['id'];
            if ($id > 0) subeKayitDogrula($db, 'manliftler', $id);
            $stmt = $db->prepare("SELECT * FROM manliftler WHERE id = ?");
            $stmt->execute([$id]);
            $m = $stmt->fetch();
            if (!$m) jsonError('Makine bulunamadı.', 404);

            $bStmt = $db->prepare("SELECT * FROM manlift_belgeler WHERE manlift_id = ? ORDER BY created_at DESC");
            $bStmt->execute([$id]);
            $m['belgeler'] = $bStmt->fetchAll();
            $m['gecmis'] = makineGecmis($db, 'manlift', $id);
            $m['kirada'] = makineKiradaMi($db, 'manlift', $id);
            $m['gorunen_durum'] = opGorunenDurumKarar((string)($m['durum'] ?: 'musait'), $m['kirada'], $m['kirada'] ? opGorunenDurum($db, 'manlift', $id) : null);
            $ds = $db->prepare("SELECT 1 FROM teknik_servis WHERE makine_tur='manlift' AND makine_id=? AND durum<>'tamamlandi' AND kaynak='depo_inceleme' LIMIT 1");
            $ds->execute([$id]);
            $m['depo_servis'] = (bool)$ds->fetchColumn();
            jsonOk($m);
        }

        $search = trim(metin($_GET['q'] ?? ''));
        $params = [];
        $where  = [];
        if ($search !== '') {
            $where[]  = "(" . trAramaSql('kod') . " LIKE ? OR " . trAramaSql('seri_no') . " LIKE ? OR " . trAramaSql('marka') . " LIKE ? OR " . trAramaSql('model') . " LIKE ?)";
            $like     = '%' . trArama($search) . '%';
            $params   = array_merge($params, [$like, $like, $like, $like]);
        }
        if (($sb = trim(metin($_GET['sube'] ?? ''))) !== '') { $where[] = "sube=?"; $params[] = $sb; }
        subeKosulEkle($where, $params, 'sube');
        if (($dr = trim(metin($_GET['durum'] ?? ''))) !== '') { $where[] = "durum=?"; $params[] = $dr; }
        $sql = "SELECT id, kod, seri_no, marka, model, imal_yili, periyodik_bitis, metraj, sube, aktif, durum, temin_sekli, calisma_saati, son_bakim_saat, bakim_araligi_saat,
                       yakit_sarfiyat_100km, rolanti_birim_ucret_saat, rolanti_litre_saat, maliyet_amortisman_gun, maliyet_bakim_gun, maliyet_sigorta_gun, maliyet_mtv_gun, mtv_yillik, sigorta_yillik, makine_kodu, liste_fiyat_aylik, liste_fiyat_gunluk, liste_fiyat_haftalik, yakit_tipi, alis_tarihi, kategori, ozellikler,
                       (SELECT g.sonraki_tarih FROM genel_servis g WHERE g.makine_tur='manlift' AND g.makine_id=manliftler.id
                        ORDER BY g.tarih DESC, g.id DESC LIMIT 1) AS gs_sonraki_tarih
                FROM manliftler";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY kod";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $kiradaSet = makineKiradaSeti($db, 'manlift');
        $akis = opGorunenDurumToplu($db, 'manlift');
        foreach ($rows as &$r) {
            $r['kirada'] = in_array((int)$r['id'], $kiradaSet, true);
            $r['gorunen_durum'] = opGorunenDurumKarar((string)($r['durum'] ?: 'musait'), $r['kirada'], $akis[(int)$r['id']] ?? null);
        }
        unset($r);
        jsonOk($rows);
        break;

    case 'POST':
        verifyCsrf();
        $body = govdeOku();
        $kod  = trim(metin($body['kod'] ?? ''));
        if ($kod === '') jsonError('Makine kodu zorunludur.');

        $durumIzinli = ['musait','serviste','nakliyede','satildi','pasif','bakim_bekliyor','inceleme_bekliyor','bakimda','kiraya_gidecek','kiradan_gelecek'];
        $durum = in_array($body['durum'] ?? '', $durumIzinli, true) ? $body['durum'] : 'musait';
        try { $mkG = mkGovde($db, $body); } catch (DomainException $e) { jsonError($e->getMessage()); }

        $stmt = $db->prepare("
            INSERT INTO manliftler (kod, seri_no, model, marka, imal_yili, periyodik_bitis, notlar, metraj, sube, alis_bedeli, faydali_omur_yil, aktif,
                calisma_saati, son_bakim_saat, bakim_araligi_saat, ruhsat_no, tescil_tarihi, durum, garanti_bitis,
                yakit_sarfiyat_100km, rolanti_birim_ucret_saat, maliyet_amortisman_gun, maliyet_bakim_gun, maliyet_sigorta_gun, maliyet_mtv_gun, mtv_yillik, sigorta_yillik, rolanti_litre_saat, temin_sekli, dis_kira_bedeli, makine_kodu, liste_fiyat_aylik, alis_tarihi, liste_fiyat_gunluk, liste_fiyat_haftalik, yakit_tipi)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        try {
            $stmt->execute([
                $kod,
                trim(metin($body['seri_no'] ?? '')) ?: null,
                trim(metin($body['model'] ?? '')) ?: null,
                trim(metin($body['marka'] ?? '')) ?: null,
                ((int)($body['imal_yili'] ?? 0)) ?: null,
                dateStr('periyodik_bitis', $body),
                trim(metin($body['notlar'] ?? '')) ?: null,
                metrajNormal($body['metraj'] ?? null),
                trim(metin($body['sube'] ?? '')) ?: null,
                max(0, (float)($body['alis_bedeli'] ?? 0)),
                max(0, (int)($body['faydali_omur_yil'] ?? 0)),
                isset($body['aktif']) ? (int)(bool)$body['aktif'] : 1,
                max(0, (float)($body['calisma_saati'] ?? 0)),
                max(0, (float)($body['son_bakim_saat'] ?? 0)),
                max(0, (float)($body['bakim_araligi_saat'] ?? 0)),
                trim(metin($body['ruhsat_no'] ?? '')) ?: null,
                dateStr('tescil_tarihi', $body),
                $durum,
                dateStr('garanti_bitis', $body),
                max(0, (float)($body['yakit_sarfiyat_100km'] ?? 0)),
                max(0, (float)($body['rolanti_birim_ucret_saat'] ?? 0)),
                max(0, (float)($body['maliyet_amortisman_gun'] ?? 0)),
                max(0, (float)($body['maliyet_bakim_gun'] ?? 0)),
                max(0, (float)($body['maliyet_sigorta_gun'] ?? 0)),
                max(0, (float)($body['maliyet_mtv_gun'] ?? 0)),
                max(0, (float)($body['mtv_yillik'] ?? 0)),
                max(0, (float)($body['sigorta_yillik'] ?? 0)),
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
            if (strpos($e->getMessage(), 'UNIQUE') !== false) jsonError('Bu makine kodu zaten kayıtlı.');
            jsonError('Veritabanı hatası oluştu.', 500);
        }
        $yeniId = (int)$db->lastInsertId();
        mkYaz($db, 'manliftler', $yeniId, $mkG);
        jsonOk(['id' => $yeniId], 'Makine eklendi.');

    case 'PUT':
        verifyCsrf();
        $id   = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        if ($id > 0) subeKayitDogrula($db, 'manliftler', $id);
        $body = govdeOku();
        $_eskiKayitTam = logKayitAl($db, 'manliftler', $id);
        $kod  = trim(metin($body['kod'] ?? ''));
        $durumIzinli = ['musait','serviste','nakliyede','satildi','pasif','bakim_bekliyor','inceleme_bekliyor','bakimda','kiraya_gidecek','kiradan_gelecek'];
        if (count($body) === 1 && isset($body['durum'])) {
            $yeniDurum = in_array($body['durum'], $durumIzinli, true) ? $body['durum'] : null;
            if ($yeniDurum === null) jsonError('Geçersiz durum.');
            if($yeniDurum==='nakliyede'&&($e=hzMakineEngel($db,'manlift',$id)))jsonError($e,409);
            if (!in_array($yeniDurum, ['satildi','pasif','nakliyede','serviste'], true)
                && makineAcikServisVarMi($db, 'manlift', $id)) {
                jsonError('Bu makinede açık bir servis kaydı var; önce servisi kapatın.', 409);
            }
            $_eskiKayit = logKayitAl($db, 'manliftler', $id);
            $db->beginTransaction();
            try {
                if ($opHata = opMakineKartiHareketi($db, 'manlift', $id, $yeniDurum)) throw new DomainException($opHata);
                $db->prepare("UPDATE manliftler SET durum=?, updated_at=datetime('now','localtime') WHERE id=?")
                   ->execute([$yeniDurum, $id]);
                logla($db, 'manliftler', $id, 'guncelle', $_eskiKayit, logKayitAl($db, 'manliftler', $id), 'Durum: ' . $yeniDurum);
                $db->commit();
            } catch (DomainException $e) { $db->rollBack(); jsonError($e->getMessage(), 403); }
            catch (\Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
            jsonOk(['durum' => $yeniDurum], 'Durum güncellendi.');
        }

        if ($kod === '') jsonError('Makine kodu zorunludur.');

        $durumIzinli = ['musait','serviste','nakliyede','satildi','pasif','bakim_bekliyor','inceleme_bekliyor','bakimda','kiraya_gidecek','kiradan_gelecek'];

        $mevcutDurum = $db->prepare("SELECT durum FROM manliftler WHERE id=?");
        $mevcutDurum->execute([$id]);
        $eskiDurum = $mevcutDurum->fetchColumn() ?: 'musait';
        $mkMevcut = $db->query('SELECT kategori FROM manliftler WHERE id=' . (int)$id)->fetchColumn() ?: null;
        try { $mkG = mkGovde($db, $body, $mkMevcut); } catch (DomainException $e) { jsonError($e->getMessage()); }
        $durum = in_array($body['durum'] ?? '', $durumIzinli, true) ? $body['durum'] : $eskiDurum;
        if($durum==='nakliyede'&&$eskiDurum!=='nakliyede'&&($e=hzMakineEngel($db,'manlift',$id)))jsonError($e,409);
        if (!in_array($durum, ['satildi', 'pasif', 'nakliyede', 'serviste'], true)
            && makineAcikServisVarMi($db, 'manlift', $id)) {
            $durum = 'serviste';
        }

        $korunacak = ['liste_fiyat_gunluk', 'liste_fiyat_haftalik', 'yakit_tipi'];
        if (array_diff($korunacak, array_keys($body))) {
            $kq = $db->prepare('SELECT ' . implode(', ', $korunacak) . ' FROM manliftler WHERE id=?');
            $kq->execute([$id]);
            $kayitliAlan = $kq->fetch(PDO::FETCH_ASSOC) ?: [];
            foreach ($korunacak as $_k) {
                if (!array_key_exists($_k, $body)) $body[$_k] = $kayitliAlan[$_k] ?? null;
            }
        }

        $stmt = $db->prepare("
            UPDATE manliftler SET kod=?, seri_no=?, model=?, marka=?,
                imal_yili=?, periyodik_bitis=?, notlar=?, metraj=?, sube=?, alis_bedeli=?, faydali_omur_yil=?, aktif=?,
                calisma_saati=?, son_bakim_saat=?, bakim_araligi_saat=?, ruhsat_no=?, tescil_tarihi=?, durum=?, garanti_bitis=?,
                yakit_sarfiyat_100km=?, rolanti_birim_ucret_saat=?, maliyet_amortisman_gun=?, maliyet_bakim_gun=?, maliyet_sigorta_gun=?, maliyet_mtv_gun=?, mtv_yillik=?, sigorta_yillik=?, rolanti_litre_saat=?, temin_sekli=?, dis_kira_bedeli=?, makine_kodu=?, liste_fiyat_aylik=?, alis_tarihi=?, liste_fiyat_gunluk=?, liste_fiyat_haftalik=?, yakit_tipi=?,
                updated_at=datetime('now','localtime')
            WHERE id=?
        ");
        $db->beginTransaction();
        if ($durum !== $eskiDurum && ($opHata = opMakineKartiHareketi($db, 'manlift', $id, $durum))) { $db->rollBack(); jsonError($opHata, 403); }
        try {
            $stmt->execute([
                $kod,
                trim(metin($body['seri_no'] ?? '')) ?: null,
                trim(metin($body['model'] ?? '')) ?: null,
                trim(metin($body['marka'] ?? '')) ?: null,
                ((int)($body['imal_yili'] ?? 0)) ?: null,
                dateStr('periyodik_bitis', $body),
                trim(metin($body['notlar'] ?? '')) ?: null,
                metrajNormal($body['metraj'] ?? null),
                pasifSubeKoru($db, 'manliftler', $id, metin($body['sube'] ?? '')),
                max(0, (float)($body['alis_bedeli'] ?? 0)),
                max(0, (int)($body['faydali_omur_yil'] ?? 0)),
                isset($body['aktif']) ? (int)(bool)$body['aktif'] : 1,
                max(0, (float)($body['calisma_saati'] ?? 0)),
                max(0, (float)($body['son_bakim_saat'] ?? 0)),
                max(0, (float)($body['bakim_araligi_saat'] ?? 0)),
                trim(metin($body['ruhsat_no'] ?? '')) ?: null,
                dateStr('tescil_tarihi', $body),
                $durum,
                dateStr('garanti_bitis', $body),
                max(0, (float)($body['yakit_sarfiyat_100km'] ?? 0)),
                max(0, (float)($body['rolanti_birim_ucret_saat'] ?? 0)),
                max(0, (float)($body['maliyet_amortisman_gun'] ?? 0)),
                max(0, (float)($body['maliyet_bakim_gun'] ?? 0)),
                max(0, (float)($body['maliyet_sigorta_gun'] ?? 0)),
                max(0, (float)($body['maliyet_mtv_gun'] ?? 0)),
                max(0, (float)($body['mtv_yillik'] ?? 0)),
                max(0, (float)($body['sigorta_yillik'] ?? 0)),
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
            mkYaz($db, 'manliftler', $id, $mkG);
            $db->commit();
        } catch (\PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            if (strpos($e->getMessage(), 'UNIQUE') !== false) jsonError('Bu makine kodu başka bir kayıtta var.');
            jsonError('Veritabanı hatası oluştu.', 500);
        }
        logla($db, 'manliftler', $id, 'guncelle', $_eskiKayitTam, logKayitAl($db, 'manliftler', $id), 'Makine güncellendi: ' . $kod);
        jsonOk(null, 'Makine güncellendi.');

    case 'DELETE':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        if ($id > 0) subeKayitDogrula($db, 'manliftler', $id);
        $bStmt = $db->prepare("SELECT dosya_yolu FROM manlift_belgeler WHERE manlift_id = ? AND dosya_yolu IS NOT NULL");
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
        $tStmt = $db->prepare("SELECT dosya_yolu FROM tutanaklar WHERE tur='manlift' AND entity_id=? AND dosya_yolu IS NOT NULL");
        $tStmt->execute([$id]);
        foreach ($tStmt->fetchAll(PDO::FETCH_COLUMN) as $rel) {
            if (preg_match('#^tutanak/[a-z]+/\d+/[a-f0-9]{32}\.[a-z0-9]{2,5}$#', $rel)) {
                $full = realpath(UPLOAD_PATH . $rel);
                if ($full && $uploadBase && strncmp($full, $uploadBase, strlen($uploadBase)) === 0 && file_exists($full)) {
                    unlink($full);
                }
            }
        }
        $db->prepare("DELETE FROM tutanaklar WHERE tur='manlift' AND entity_id=?")->execute([$id]);
        $db->prepare("DELETE FROM manliftler WHERE id=?")->execute([$id]);
        jsonOk(null, 'Makine silindi.');

    default:
        jsonError('Method Not Allowed', 405);
}
