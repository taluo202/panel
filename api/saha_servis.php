<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/saha-servis-lib.php';
require_once __DIR__ . '/servis-talep-lib.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth(); yazmaIstegiCsrf();
requirePermission('teknik_servis');
subeMerkezi('teknik_servis');
$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$action = (string)($_GET['action'] ?? '');
$id = (int)($_GET['id'] ?? 0);
$govde = fn() => govdeOku();

try {
    if ($method === 'POST' && $action === 'ac') {
        requirePermission('teknik_servis', 'yaz');
        verifyCsrf();
        $b = $govde();
        $db->beginTransaction();
        $yeni = ssIsAc($db, $b);
        if ((int)($b['talep_id'] ?? 0) > 0) stTalepBagla($db, (int)$b['talep_id'], $yeni);
        $db->commit();
        jsonOk(['id' => $yeni], 'Saha servisi açıldı.');
    }
    if ($method === 'PUT' && $action === 'saha') {
        requirePermission('teknik_servis', 'yaz');
        verifyCsrf();
        $b = $govde();
        $db->beginTransaction();
        ssSahaGuncelle($db, $id, $b);
        $db->commit();
        jsonOk(null, 'Saha servisi güncellendi.');
    }
    if ($method === 'PUT' && $action === 'saha_durum') {
        requirePermission('teknik_servis', 'yaz');
        verifyCsrf();
        $b = $govde();
        $db->beginTransaction();
        $r = ssOfisDurum($db, $id, (string)($b['adim'] ?? ''));
        $db->commit();
        jsonOk($r, $r['saha_durum'] === 'yolda' ? 'Saha servisi yolda olarak işaretlendi.' : 'Saha servisi sahada olarak işaretlendi.');
    }
    if ($method === 'PUT' && $action === 'incelendi') {
        requirePermission('teknik_servis', 'yaz');
        verifyCsrf();
        $q = $db->prepare("UPDATE teknik_servis SET ofis_inceleme=0 WHERE id=? AND yer='saha'");
        $q->execute([$id]);
        if (!$q->rowCount()) jsonError('Saha servisi bulunamadı.', 404);
        logla($db, 'teknik_servis', $id, 'guncelle', null, null, 'Saha servis raporu ofiste incelendi');
        jsonOk(null, 'İncelendi olarak işaretlendi.');
    }
    $personelId = (int)($_SESSION['personel_id'] ?? 0);
    if ($method === 'GET' && $action === 'benim') {
        if ($personelId < 1) jsonError('Personel kaydınıza bağlı değilsiniz. Yöneticiniz hesabınızı personel kaydına bağlayınca saha işleriniz burada görünür.', 409);
        jsonOk(ssBenimIsler($db, $personelId));
    }
    if ($action === 'fiyat' && in_array($method, ['GET', 'PUT'], true)) {
        require_once __DIR__ . '/servis-maliyet-lib.php';
        if (!smFiyatYetkisi($db)) jsonError('Servis fiyatlandırma yetkiniz yok.', 403);
        ssFiyatSubeDogrula($db, $id);
        if ($method === 'GET') jsonOk(ssFiyatGetir($db, $id));
        verifyCsrf();
        $b = $govde();
        $db->beginTransaction();
        $r = ssFiyatKaydet($db, $id, is_array($b['kalemler'] ?? null) ? $b['kalemler'] : [], !empty($b['ucretsiz']));
        $db->commit();
        jsonOk($r, $r['fiyat_durum'] === 'ucretsiz' ? 'Ücretsiz olarak işaretlendi.' : 'Fiyatlandırma kaydedildi.');
    }
    if ($method === 'GET' && $action === 'ziyaretler') {
        ssOkumaErisim($db, $id, $personelId);
        jsonOk(ssZiyaretler($db, $id));
    }
    if ($method === 'GET' && $action === 'rapor_belge') {
        ssOkumaErisim($db, ssZiyaretServisId($db, (int)($_GET['ziyaret_id'] ?? 0)), $personelId);
        jsonOk(ssRaporBelge($db, (int)($_GET['ziyaret_id'] ?? 0)));
    }
    if ($method === 'GET' && $action === 'rapor_pdf') {
        ssOkumaErisim($db, ssZiyaretServisId($db, (int)($_GET['ziyaret_id'] ?? 0)), $personelId);
        $r = ssRaporBelge($db, (int)($_GET['ziyaret_id'] ?? 0));
        try { $pdf = ssRaporPdf($db, $r); }
        catch (DomainException $e) { throw $e; }
        catch (Throwable $e) { error_log('saha_servis rapor_pdf: ' . $e->getMessage()); jsonError('PDF hazırlanamadı.', 500); }
        bpIndir($pdf);
    }
    if ($method === 'GET' && $action === 'is') {
        ssIsErisim($db, $id, $personelId);
        jsonOk(ssIs($db, $id));
    }
    if ($method === 'GET' && $action === 'parcalar') {
        jsonOk($db->query('SELECT id, ad, parca_no, stok, birim FROM yedek_parca WHERE COALESCE(aktif,1)=1 ORDER BY ad')->fetchAll(PDO::FETCH_ASSOC));
    }
    if ($method === 'GET' && $action === 'araclar') {
        jsonOk($db->query("SELECT id, plaka, marka, model FROM araclar WHERE COALESCE(aktif,1)=1 ORDER BY plaka")->fetchAll(PDO::FETCH_ASSOC));
    }
    if (($method === 'POST' && in_array($action, ['adim', 'rapor', 'foto', 'kalem', 'donus_km'], true)) || ($method === 'DELETE' && $action === 'kalem')) {
        requirePermission('teknik_servis', 'yaz');
        verifyCsrf();
        $b = $method === 'POST' ? $govde() : [];
        $db->beginTransaction();
        if ($action === 'adim') {
            $r = ssAdim($db, $id, (string)($b['adim'] ?? ''), $b, $personelId);
            $db->commit();
            $mesaj = ['yolda' => 'Yola çıktınız.', 'sahada' => 'Sahadasınız; servis başladı.'][$r['saha_durum']] ?? 'Kaydedildi.';
            if (($b['adim'] ?? '') === 'kabul') $mesaj = 'İşi kabul ettiniz.';
            if (($b['adim'] ?? '') === 'bitti') $mesaj = 'Servis bitiş saati kaydedildi; formu doldurup imzalatın.';
            jsonOk($r, $mesaj);
        }
        if ($action === 'rapor') {
            $r = ssRaporGonder($db, $id, $b, $personelId);
            $db->commit();
            jsonOk($r, 'Rapor gönderildi · ' . $r['rapor_no']);
        }
        if ($action === 'donus_km') {
            $r = ssDonusKm($db, (int)($b['ziyaret_id'] ?? 0), $b['km_donus'] ?? null, $personelId);
            $db->commit();
            jsonOk($r, 'Dönüş km kaydedildi.');
        }
        if ($action === 'kalem' && $method === 'DELETE') {
            $kid = (int)($_GET['kalem_id'] ?? 0);
            $q = $db->prepare('SELECT servis_id FROM teknik_servis_kalemleri WHERE id=?'); $q->execute([$kid]);
            $ksid = (int)$q->fetchColumn();
            if (!$ksid) throw new DomainException('Satır bulunamadı.');
            $s = ssIsErisim($db, $ksid, $personelId);
            if (in_array($s['saha_durum'], ['rapor_gonderildi', 'iptal'], true)) throw new DomainException('Raporu gönderilmiş işte parça silinemez.');
            tsKalemSil($db, $kid);
            $db->commit();
            jsonOk(null, 'Satır silindi.');
        }
        $s = ssIsErisim($db, $id, $personelId);
        if ($action === 'kalem') {
            if ($s['saha_durum'] !== 'sahada') throw new DomainException('Parça yalnız sahadayken eklenir.');
            if (($b['tur'] ?? '') !== 'malzeme') throw new DomainException('Sahada yalnız parça eklenir; işçilik süresi başlangıç ve bitiş saatinden hesaplanır.');
            $r = tsKalemEkle($db, $id, $b);
            $db->commit();
            jsonOk($r, $r['eksi_stok'] ? 'Parça eklendi; stok eksiye düştü — parça sipariş edilmeli.' : 'Parça eklendi.');
        }
        if ($s['durum'] === 'tamamlandi') throw new DomainException('Servis tamamlandı; fotoğraf eklenemez.');
        if ($_gh = gorselVeriHatasi($b['veri'] ?? null)) throw new DomainException($_gh);
        $yol = tsFotoKaydet((string)($b['veri'] ?? ''), $id);
        if (!$yol) throw new DomainException('Fotoğraf okunamadı (JPEG/PNG/WEBP, en çok ' . (int)(MAX_FILE_SIZE / 1048576) . ' MB).');
        $bilesen = isset(SS_BILESENLER[(string)($b['bilesen'] ?? '')]) ? (string)$b['bilesen'] : null;
        $db->prepare("INSERT INTO teknik_servis_fotograflari(servis_id,dosya_yolu,kaynak,bilesen,created_at) VALUES(?,?,'saha',?,?)")->execute([$id, $yol, $bilesen, date('Y-m-d H:i:s')]);
        $fid = (int)$db->lastInsertId();
        logla($db, 'teknik_servis_fotograflari', $fid, 'ekle', null, ['servis_id' => $id, 'kaynak' => 'saha', 'bilesen' => $bilesen], 'Saha fotoğrafı — servis #' . $id);
        $db->commit();
        jsonOk(['id' => $fid], 'Fotoğraf eklendi.');
    }
    jsonError('Geçersiz işlem.', 400);
} catch (TsStokOnayi $e) {
    if ($db->inTransaction()) $db->rollBack();
    jsonError($e->getMessage(), 409, $e->ek);
} catch (DomainException $e) {
    if ($db->inTransaction()) $db->rollBack();
    jsonError($e->getMessage(), 409);
}
