<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('makine');

$db     = getDb();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {

    case 'GET':
        if (isset($_GET['id'])) {
            $st = $db->prepare("SELECT * FROM ekipman_satis WHERE id=?");
            $st->execute([(int)$_GET['id']]);
            $r = $st->fetch();
            if (!$r) jsonError('Kayıt bulunamadı.', 404);
            jsonOk($r);
        }

        $where = []; $params = [];
        if (($mt = trim(metin($_GET['makine_tur'] ?? ''))) !== '' && ($mi = (int)($_GET['makine_id'] ?? 0)) > 0) {
            $where[] = "makine_tur=? AND makine_id=?"; $params[] = $mt; $params[] = $mi;
        }
        $sql = "SELECT * FROM ekipman_satis";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY tarih DESC, id DESC";
        $st = $db->prepare($sql); $st->execute($params);
        jsonOk($st->fetchAll());
        break;

    case 'POST':
        verifyCsrf();
        $body = govdeOku();

        $makineTur = in_array($body['makine_tur'] ?? '', ['manlift','arac'], true) ? $body['makine_tur'] : null;
        $makineId  = (int)($body['makine_id'] ?? 0);
        if (!$makineTur || $makineId < 1) jsonError('Ekipman seçimi zorunludur.');
        $tarih = dateStr('tarih', $body);
        if (!$tarih) jsonError('Satış tarihi zorunludur.');

        $tablo = $makineTur === 'arac' ? 'araclar' : 'manliftler';
        $adKolon = $makineTur === 'arac' ? 'plaka' : 'kod';
        $mSt = $db->prepare("SELECT $adKolon AS ad FROM $tablo WHERE id=?");
        $mSt->execute([$makineId]);
        $makineAd = $mSt->fetchColumn();
        if ($makineAd === false) jsonError('Ekipman bulunamadı.', 404);

        if (makineKiradaMi($db, $makineTur, $makineId)) {
            jsonError('Bu ekipman şu an aktif bir kiralamada (kirada) — satılamaz. Önce iade alınmalı veya kiralama kapatılmalı.');
        }

        $satisTutari = max(0, (float)($body['satis_tutari'] ?? 0));
        $kdvOran     = max(0, (float)($body['kdv_oran'] ?? 20));
        $kdvTutari   = round($satisTutari * $kdvOran / 100, 2);
        $genelToplam = round($satisTutari + $kdvTutari, 2);

        $st = $db->prepare("
            INSERT INTO ekipman_satis (makine_tur, makine_id, makine_ad, tarih, alici_unvan, alici_vergi_no, satis_tutari, kdv_oran, kdv_tutari, genel_toplam, notlar)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ");
        $st->execute([
            $makineTur, $makineId, $makineAd, $tarih,
            trim(metin($body['alici_unvan'] ?? '')) ?: null,
            trim(metin($body['alici_vergi_no'] ?? '')) ?: null,
            $satisTutari, $kdvOran, $kdvTutari, $genelToplam,
            trim(metin($body['notlar'] ?? '')) ?: null,
        ]);

        $satisId = (int)$db->lastInsertId();
        $db->prepare("UPDATE $tablo SET durum='satildi', aktif=0, updated_at=datetime('now','localtime') WHERE id=?")->execute([$makineId]);

        logla($db, 'ekipman_satis', $satisId, 'ekle', null, logKayitAl($db, 'ekipman_satis', $satisId),
            'Ekipman SATILDI: ' . ($makineAd ?: ($makineTur . '#' . $makineId))
            . ' · ' . number_format($genelToplam, 2, ',', '.') . ' ₺ (KDV dahil)'
            . (($u = trim(metin($body['alici_unvan'] ?? ''))) !== '' ? ' · Alıcı: ' . $u : ''));

        jsonOk(['id' => $satisId], 'Ekipman satışı kaydedildi.');

    case 'PUT':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) jsonError('Geçersiz istek gövdesi.');
        foreach (['tarih', 'alici_unvan', 'alici_vergi_no', 'satis_tutari', 'kdv_oran', 'notlar'] as $_alan) {
            if (!array_key_exists($_alan, $body)) jsonError('Eksik alan: ' . $_alan . '. Düzenleme formu bütün alanları göndermelidir.');
        }
        $_satisEski = logKayitAl($db, 'ekipman_satis', $id);
        if (!$_satisEski) jsonError('Kayıt bulunamadı.', 404);
        $tarih = dateStr('tarih', $body);
        if (!$tarih) jsonError('Satış tarihi zorunludur.');
        $satisTutari = max(0, (float)$body['satis_tutari']);
        $kdvOran     = max(0, (float)$body['kdv_oran']);
        $kdvTutari   = round($satisTutari * $kdvOran / 100, 2);
        $genelToplam = round($satisTutari + $kdvTutari, 2);
        $db->prepare("UPDATE ekipman_satis SET tarih=?, alici_unvan=?, alici_vergi_no=?, satis_tutari=?, kdv_oran=?, kdv_tutari=?, genel_toplam=?, notlar=? WHERE id=?")
           ->execute([
               $tarih,
               trim(metin($body['alici_unvan'])) ?: null,
               trim(metin($body['alici_vergi_no'])) ?: null,
               $satisTutari, $kdvOran, $kdvTutari, $genelToplam,
               trim(metin($body['notlar'])) ?: null,
               $id,
           ]);
        logla($db, 'ekipman_satis', $id, 'guncelle', $_satisEski, logKayitAl($db, 'ekipman_satis', $id),
            'Ekipman satışı düzenlendi: ' . ($_satisEski['makine_ad'] ?: ($_satisEski['makine_tur'] . '#' . $_satisEski['makine_id']))
            . ' · ' . number_format($genelToplam, 2, ',', '.') . ' ₺ (KDV dahil)');
        jsonOk(['id' => $id, 'genel_toplam' => $genelToplam], 'Satış kaydı güncellendi.');

    case 'DELETE':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $st = $db->prepare("SELECT * FROM ekipman_satis WHERE id=?");
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) jsonError('Kayıt bulunamadı.', 404);

        $_satisLogEski = logKayitAl($db, 'ekipman_satis', $id);
        $db->prepare("DELETE FROM ekipman_satis WHERE id=?")->execute([$id]);
        logla($db, 'ekipman_satis', $id, 'sil', $_satisLogEski, null,
            'Ekipman satışı GERİ ALINDI: ' . ($r['makine_ad'] ?: ($r['makine_tur'] . '#' . $r['makine_id']))
            . ' · ' . number_format((float)$r['genel_toplam'], 2, ',', '.') . ' ₺');

        $baskaVarMi = $db->prepare("SELECT 1 FROM ekipman_satis WHERE makine_tur=? AND makine_id=? LIMIT 1");
        $baskaVarMi->execute([$r['makine_tur'], $r['makine_id']]);
        if (!$baskaVarMi->fetchColumn()) {
            $tablo = $r['makine_tur'] === 'arac' ? 'araclar' : 'manliftler';
            $acikServisVarMi = $db->prepare("SELECT 1 FROM teknik_servis WHERE makine_tur=? AND makine_id=? AND durum<>'tamamlandi' AND COALESCE(makine_baska_iste,0)=0 LIMIT 1");
            $acikServisVarMi->execute([$r['makine_tur'], $r['makine_id']]);
            $yeniDurum = $acikServisVarMi->fetchColumn() ? 'serviste' : 'musait';
            $db->prepare("UPDATE $tablo SET durum=?, aktif=1, updated_at=datetime('now','localtime') WHERE id=?")->execute([$yeniDurum, (int)$r['makine_id']]);
        }
        jsonOk(null, 'Satış kaydı silindi.');

    default:
        jsonError('Method Not Allowed', 405);
}
