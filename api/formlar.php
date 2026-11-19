<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('formlar');

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDb();

if ($method === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $db->prepare("SELECT f.*, p.ad_soyad AS personel_ad FROM form_kayitlari f LEFT JOIN personel p ON p.id = f.personel_id WHERE f.id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) { jsonError('Kayıt bulunamadı.', 404); }
        $row['form_verisi'] = json_decode($row['form_verisi'] ?? '{}', true) ?: [];
        jsonOk($row);
    } else {
        $tur    = trim(metin($_GET['tur']    ?? ''));
        $ara    = trim(metin($_GET['ara']    ?? ''));
        $baslangic = trim(metin($_GET['baslangic'] ?? ''));
        $bitis     = trim(metin($_GET['bitis']     ?? ''));

        $where = []; $params = [];
        if ($tur)  { $where[] = 'f.tur = ?'; $params[] = $tur; }
        if ($ara)  { $where[] = '(' . trAramaSql('f.ad') . ' LIKE ? OR ' . trAramaSql('f.musteri') . ' LIKE ? OR ' . trAramaSql('p.ad_soyad') . ' LIKE ?)'; $like = '%' . trArama($ara) . '%'; $params[] = $like; $params[] = $like; $params[] = $like; }
        if ($baslangic) { $where[] = 'f.tarih >= ?'; $params[] = $baslangic; }
        if ($bitis)     { $where[] = 'f.tarih <= ?'; $params[] = $bitis; }

        $sql = "SELECT f.*, p.ad_soyad AS personel_ad FROM form_kayitlari f LEFT JOIN personel p ON p.id = f.personel_id";
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY f.created_at DESC';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['form_verisi'] = json_decode($row['form_verisi'] ?? '{}', true) ?: [];
        }
        jsonOk($rows);
    }

} elseif ($method === 'POST') {
    verifyCsrf();
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) { jsonError('Geçersiz istek gövdesi.'); }

    $tur         = trim(metin($body['tur'] ?? ''));
    $ad          = trim(metin($body['ad'] ?? ''));
    $musteri     = trim(metin($body['musteri'] ?? ''));
    $personel_id = isset($body['personel_id']) && $body['personel_id'] ? (int)$body['personel_id'] : null;
    $tarih       = trim(metin($body['tarih'] ?? date('Y-m-d')));
    $form_verisi = json_encode($body['form_verisi'] ?? [], JSON_UNESCAPED_UNICODE);

    if (!$tur)  { jsonError('Form türü zorunludur.'); }
    if (!$ad)   { jsonError('Form adı zorunludur.'); }

    $BILINEN_TURLER = ['satis_teklif','kiralama_teklif','manlift_servis','vinc_kiralama',
                       'gecici_gorev','ucretli_izin','ucretsiz_izin','yillik_izin',
                       'proforma','teslim_iade'];
    if (!in_array($tur, $BILINEN_TURLER, true)) jsonError('Bilinmeyen form türü: ' . $tur);

    $izinTuru = in_array($tur, ['ucretli_izin','ucretsiz_izin','yillik_izin'], true);
    if ($izinTuru) {
        if (!$personel_id) jsonError('Personel seçilmelidir.');
    } elseif ($musteri === '') {
        jsonError('Müşteri / firma adı zorunludur.');
    }

    if ($tarih === '' || !DateTime::createFromFormat('Y-m-d', $tarih)) {
        jsonError('Geçerli bir tarih girilmelidir.');
    }

    $fv = is_array($body['form_verisi'] ?? null) ? $body['form_verisi'] : [];
    $bas = trim(metin($fv['baslangic'] ?? ''));
    $bit = trim(metin($fv['bitis'] ?? ''));
    if ($bas !== '' && $bit !== '' && $bit < $bas) {
        jsonError('Bitiş tarihi başlangıç tarihinden önce olamaz.');
    }

    $gId = (int)($body['id'] ?? 0);
    if ($gId > 0) {
        $stmt = $db->prepare("UPDATE form_kayitlari SET tur=?, ad=?, musteri=?, personel_id=?, tarih=?, form_verisi=? WHERE id=?");
        $stmt->execute([$tur, $ad, $musteri, $personel_id, $tarih, $form_verisi, $gId]);
        logla($db, 'form_kayitlari', $gId, 'guncelle', null, null, 'Form güncellendi: ' . $ad);
        jsonOk(['id' => $gId, 'ad' => $ad], 'Form güncellendi.');
    } else {
        $stmt = $db->prepare("INSERT INTO form_kayitlari (tur, ad, musteri, personel_id, tarih, form_verisi) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$tur, $ad, $musteri, $personel_id, $tarih, $form_verisi]);
        $_formId = (int)$db->lastInsertId();
        logla($db, 'form_kayitlari', $_formId, 'ekle', null, null, 'Form kaydedildi: ' . $ad);
        jsonOk(['id' => $_formId, 'ad' => $ad], 'Form kaydedildi.');
    }

} elseif ($method === 'DELETE') {
    verifyCsrf();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) { jsonError('ID gerekli.'); }
    $db->prepare("DELETE FROM form_kayitlari WHERE id = ?")->execute([$id]);
    logla($db, 'form_kayitlari', $id, 'sil', null, null, 'Form kaydı silindi');
    jsonOk(null, 'Kayıt silindi.');

} else {
    jsonError('Desteklenmeyen istek yöntemi.', 405);
}
