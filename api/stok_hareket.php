<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();

$db     = getDb();
$method = $_SERVER['REQUEST_METHOD'];

function _yetki(string $kaynak): void {
    requirePermission($kaynak === 'servis' ? 'teknik_servis' : 'yedek_parca');
}

if ($method === 'GET') {
    requirePermission('yedek_parca');
    if (isset($_GET['satislar'])) {
        $st = $db->query("SELECT h.*, p.ad AS parca_ad, p.parca_no, m.unvan AS musteri_ad
            FROM stok_hareket h JOIN yedek_parca p ON p.id=h.parca_id LEFT JOIN musteriler m ON m.id=h.musteri_id
            WHERE h.kaynak='satis' ORDER BY h.tarih DESC, h.id DESC");
        $rows = $st->fetchAll();
        $toplamCiro = 0.0;
        foreach ($rows as $r) $toplamCiro += (float)$r['miktar'] * (float)$r['satis_fiyat'];
        jsonOk(['list' => $rows, 'toplam_ciro' => round($toplamCiro, 2)]);
    }
    if (($sid = (int)($_GET['servis_id'] ?? 0)) > 0) {
        $st = $db->prepare("SELECT h.*, p.ad AS parca_ad, p.parca_no FROM stok_hareket h JOIN yedek_parca p ON p.id=h.parca_id
            WHERE h.servis_id=? AND h.kaynak='servis' ORDER BY h.id DESC");
        $st->execute([$sid]);
        $rows = $st->fetchAll();
        $toplam = 0.0;
        foreach ($rows as $r) $toplam += (float)$r['miktar'] * (float)$r['birim_fiyat'];
        jsonOk(['list' => $rows, 'toplam_maliyet' => round($toplam, 2)]);
    }
    $pid = (int)($_GET['parca_id'] ?? 0);
    if ($pid < 1) jsonError('parca_id gerekli.');
    $st = $db->prepare("SELECT h.*, ts.makine_ad AS servis_makine FROM stok_hareket h
        LEFT JOIN teknik_servis ts ON ts.id=h.servis_id WHERE h.parca_id=? ORDER BY h.tarih DESC, h.id DESC");
    $st->execute([$pid]);
    jsonOk($st->fetchAll());
}

elseif ($method === 'POST') {
    verifyCsrf();
    $body   = govdeOku();
    $kaynakGirdi = $body['kaynak'] ?? 'manuel';
    $kaynak = in_array($kaynakGirdi, ['servis','satis'], true) ? $kaynakGirdi : 'manuel';
    _yetki($kaynak);

    $parcaId = (int)($body['parca_id'] ?? 0);
    $miktar  = (int)($body['miktar'] ?? 0);
    if ($parcaId < 1 || $miktar < 1) jsonError('Parça ve miktar zorunludur.');

    $pStmt = $db->prepare("SELECT * FROM yedek_parca WHERE id=?");
    $pStmt->execute([$parcaId]);
    $parca = $pStmt->fetch();
    if (!$parca) jsonError('Parça bulunamadı.', 404);

    $servisId = ($kaynak === 'servis') ? (((int)($body['servis_id'] ?? 0)) ?: null) : null;
    if ($kaynak === 'servis' && !$servisId) jsonError('Servis ID gerekli.');
    if ($kaynak === 'servis') { try { smFaturaKilidi(tsServis($db, $servisId), 'parça sarfı'); } catch (DomainException $e) { jsonError($e->getMessage(), 409); } }

    $musteriId = null;
    $satisFiyat = 0.0;
    if ($kaynak === 'satis') {
        $musteriId = ((int)($body['musteri_id'] ?? 0)) ?: null;
        if (!$musteriId) jsonError('Müşteri seçimi zorunludur.');
        $satisFiyat = max(0, (float)($body['satis_fiyat'] ?? 0));
        if ($satisFiyat <= 0) jsonError('Satış fiyatı zorunludur.');
        if ($miktar > (int)$parca['stok']) jsonError('Stok yetersiz. Mevcut stok: ' . (int)$parca['stok']);
    }

    $stokOnaylandi = !empty($body['stok_onaylandi']);
    if ($kaynak === 'servis' && $miktar > (int)$parca['stok'] && !$stokOnaylandi) {
        $eksik = $miktar - (int)$parca['stok'];
        jsonError(
            'Stok yetersiz: "' . $parca['ad'] . '" icin ' . (int)$parca['stok'] . ' adet var, '
            . $miktar . ' adet yaziliyor. Devam ederseniz stok ' . $eksik . ' adet eksiye duser.',
            409,
            ['onaylanabilir' => true, 'mevcut_stok' => (int)$parca['stok'], 'eksik' => $eksik]
        );
    }

    $tip = in_array($kaynak, ['servis','satis'], true) ? 'cikis' : (in_array($body['tip'] ?? '', ['giris','cikis','sayim'], true) ? $body['tip'] : 'cikis');
    $birimFiyat = $kaynak === 'servis' ? smStokMaliyeti($parca) : ($kaynak === 'satis' ? (float)$parca['fiyat'] : max(0, (float)($body['birim_fiyat'] ?? $parca['fiyat'])));
    $tarih = dateStr('tarih', $body) ?: date('Y-m-d');

    $db->beginTransaction();
    try {
        if ($tip === 'sayim') {
            $db->prepare("UPDATE yedek_parca SET stok=?, updated_at=datetime('now','localtime') WHERE id=?")->execute([$miktar, $parcaId]);
        } elseif ($tip === 'giris') {
            $db->prepare("UPDATE yedek_parca SET stok=stok+?, updated_at=datetime('now','localtime') WHERE id=?")->execute([$miktar, $parcaId]);
        } else {
            if ($kaynak === 'servis') {
                $db->prepare("UPDATE yedek_parca SET stok=stok-?, updated_at=datetime('now','localtime') WHERE id=?")
                   ->execute([$miktar, $parcaId]);
            } elseif ($kaynak === 'satis') {
                $ds = $db->prepare("UPDATE yedek_parca SET stok=stok-?, updated_at=datetime('now','localtime') WHERE id=? AND stok>=?");
                $ds->execute([$miktar, $parcaId, $miktar]);
                if ($ds->rowCount() === 0) {
                    $db->rollBack();
                    jsonError('Stok yetersiz — bu parçadan yeterli adet kalmamış (eş zamanlı başka bir satış olmuş olabilir).', 409);
                }
            } else {
                $db->prepare("UPDATE yedek_parca SET stok=MAX(0, stok-?), updated_at=datetime('now','localtime') WHERE id=?")
                   ->execute([$miktar, $parcaId]);
            }
        }
        $db->prepare("INSERT INTO stok_hareket (parca_id, tarih, tip, miktar, kaynak, servis_id, birim_fiyat, aciklama, musteri_id, satis_fiyat) VALUES (?,?,?,?,?,?,?,?,?,?)")
           ->execute([$parcaId, $tarih, $tip, $miktar, $kaynak, $servisId, $birimFiyat, trim(metin($body['aciklama'] ?? '')) ?: null, $musteriId, $satisFiyat]);
        $hid = (int)$db->lastInsertId();

        if ($kaynak === 'servis' && $servisId) {
            $tut = $miktar * $birimFiyat;
            $db->prepare("UPDATE teknik_servis SET maliyet = COALESCE(maliyet,0) + ?, updated_at=datetime('now','localtime') WHERE id=?")->execute([$tut, $servisId]);
            smMaliyetYenile($db, $servisId);
        }
        $db->commit();
    } catch (\Exception $e) { $db->rollBack(); jsonError('İşlem başarısız.', 500); }

    $kalanStok = null;
    try {
        $kSt = $db->prepare("SELECT stok FROM yedek_parca WHERE id=?");
        $kSt->execute([$parcaId]);
        $kalanStok = (int)$kSt->fetchColumn();
    } catch (\Throwable $e) {}

    logla($db, 'stok_hareket', $hid, 'ekle', null, null,
          'Stok hareketi (' . $kaynak . ')' . ($kalanStok !== null ? ' · kalan: ' . $kalanStok : '')
          . ($kalanStok !== null && $kalanStok < 0 ? ' · EKSİYE DÜŞTÜ' : ''));

    jsonOk(['id' => $hid, 'kalan_stok' => $kalanStok, 'eksi_stok' => ($kalanStok !== null && $kalanStok < 0)],
        $kaynak === 'satis' ? 'Satış kaydedildi.' : 'Stok hareketi kaydedildi.');
}

elseif ($method === 'DELETE') {
    verifyCsrf();
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) jsonError('Geçersiz ID.');
    $st = $db->prepare("SELECT * FROM stok_hareket WHERE id=?");
    $st->execute([$id]);
    $h = $st->fetch();
    if (!$h) jsonError('Kayıt bulunamadı.', 404);
    _yetki($h['kaynak']);
    $bag = $db->prepare('SELECT servis_id FROM teknik_servis_kalemleri WHERE stok_hareket_id=?'); $bag->execute([$id]);
    if ($bs = $bag->fetchColumn()) jsonError('Bu sarf Teknik Servis #' . (int)$bs . ' → "Yapılan iş" satırına bağlı; satırı oradan silin.', 409);
    if ($h['kaynak'] === 'servis' && $h['servis_id']) {
        $fs = $db->prepare('SELECT faturalandi FROM teknik_servis WHERE id=?'); $fs->execute([(int)$h['servis_id']]);
        if ((int)$fs->fetchColumn() === 1) jsonError('Servis faturalandırıldı; parça sarfı geri alınamaz. Önce Faturalandırma ekranında fatura işaretini kaldırın.', 409);
    }

    try {
        islemIcinde($db, function (PDO $db) use ($id): void {
            $st = $db->prepare("SELECT * FROM stok_hareket WHERE id=?");
            $st->execute([$id]);
            $h = $st->fetch();
            $sil = $db->prepare("DELETE FROM stok_hareket WHERE id=?");
            $sil->execute([$id]);
            if (!$h || $sil->rowCount() !== 1) throw new DomainException('Bu hareket zaten geri alınmış; sayfayı yenileyin.', 409);
            if ($h['tip'] === 'giris') {
                $db->prepare("UPDATE yedek_parca SET stok=MAX(0, stok-?) WHERE id=?")->execute([(int)$h['miktar'], (int)$h['parca_id']]);
            } elseif ($h['tip'] === 'cikis') {
                $db->prepare("UPDATE yedek_parca SET stok=stok+? WHERE id=?")->execute([(int)$h['miktar'], (int)$h['parca_id']]);
            }
            if ($h['kaynak'] === 'servis' && $h['servis_id']) {
                $tut = (int)$h['miktar'] * (float)$h['birim_fiyat'];
                $db->prepare("UPDATE teknik_servis SET maliyet=MAX(0, COALESCE(maliyet,0) - ?) WHERE id=?")->execute([$tut, (int)$h['servis_id']]);
                smMaliyetYenile($db, (int)$h['servis_id']);
            }
            logla($db, 'stok_hareket', $id, 'sil', $h, null, 'Stok hareketi GERİ ALINDI');
        });
    } catch (DomainException $e) { jsonError($e->getMessage(), 409); }
    catch (\Throwable $e) { error_log('stok hareketi geri alinamadi: ' . $e->getMessage()); jsonError('İşlem başarısız.', 500); }

    jsonOk(null, 'Hareket geri alındı.');
}

else jsonError('Method Not Allowed', 405);
