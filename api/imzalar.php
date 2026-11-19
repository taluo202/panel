<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDb();

if ($method !== 'GET') {
    requirePermission('ayarlar');
} elseif (!hasPermission('formlar') && !hasPermission('ayarlar')) {
    jsonError('Bu modül için yetkiniz yok.', 403);
}

$imgDir   = UPLOAD_PATH . 'signatures/';
$imgAllowed = ['image/jpeg', 'image/png', 'image/webp'];
$extAllowed = ['jpg', 'jpeg', 'png', 'webp'];

if ($method === 'GET') {
    $rol = trim(metin($_GET['rol'] ?? ''));
    if ($rol) {
        $stmt = $db->prepare("SELECT * FROM imzalar WHERE rol = ? ORDER BY ad_soyad");
        $stmt->execute([$rol]);
    } else {
        $stmt = $db->query("SELECT * FROM imzalar ORDER BY rol DESC, ad_soyad");
    }
    $rows = $stmt->fetchAll();
    if (!empty($_GET['veri'])) {
        require_once __DIR__ . '/tutanak-yaz.php';
        foreach ($rows as &$r) $r['img_veri'] = itImzaGorseli((string)($r['img_yolu'] ?? ''));
        unset($r);
    }
    jsonOk($rows);

} elseif ($method === 'POST') {
    verifyCsrf();
    $id          = (int)($_POST['id'] ?? 0);
    $ad_soyad    = trim(metin($_POST['ad_soyad'] ?? ''));
    $unvan       = trim(metin($_POST['unvan'] ?? ''));
    $rol         = trim(metin($_POST['rol'] ?? 'operatör'));
    $personel_id = isset($_POST['personel_id']) && $_POST['personel_id'] ? (int)$_POST['personel_id'] : null;

    if (!$ad_soyad) { jsonError('Ad Soyad zorunludur.'); }
    if (!in_array($rol, ['yonetici', 'operatör'], true)) $rol = 'operatör';

    $img_yolu = null;
    if (isset($_FILES['imza']) && $_FILES['imza']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['imza'];
        if ($file['size'] > 5 * 1024 * 1024) { jsonError('İmza görseli 5 MB\'tan büyük olamaz.'); }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!in_array($mime, $imgAllowed, true)) { jsonError('Sadece JPG, PNG veya WEBP kabul edilir.'); }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $extAllowed, true)) { jsonError('Geçersiz dosya uzantısı.'); }
        if (!is_dir($imgDir)) mkdir($imgDir, 0755, true);
        $hash     = bin2hex(random_bytes(16));
        $img_yolu = 'signatures/' . $hash . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], UPLOAD_PATH . $img_yolu)) {
            jsonError('Dosya kaydedilemedi.', 500);
        }
        @chmod(UPLOAD_PATH . $img_yolu, 0644);
    }

    if ($id > 0) {
        $oldSt = $db->prepare("SELECT img_yolu FROM imzalar WHERE id=?");
        $oldSt->execute([$id]);
        $old = $oldSt->fetch() ?: null;
        if ($img_yolu) {
            if ($old && $old['img_yolu']) @unlink(UPLOAD_PATH . $old['img_yolu']);
            $db->prepare("UPDATE imzalar SET ad_soyad=?, unvan=?, rol=?, personel_id=?, img_yolu=? WHERE id=?")->execute([$ad_soyad, $unvan, $rol, $personel_id, $img_yolu, $id]);
        } else {
            $db->prepare("UPDATE imzalar SET ad_soyad=?, unvan=?, rol=?, personel_id=? WHERE id=?")->execute([$ad_soyad, $unvan, $rol, $personel_id, $id]);
        }
        logla($db, 'imzalar', $id, 'guncelle', null, null, 'İmza güncellendi');
        jsonOk(['id' => $id], 'İmza güncellendi.');
    } else {
        $db->prepare("INSERT INTO imzalar (ad_soyad, unvan, rol, personel_id, img_yolu) VALUES (?,?,?,?,?)")
           ->execute([$ad_soyad, $unvan, $rol, $personel_id, $img_yolu]);
        $_imzaId = (int)$db->lastInsertId();
        logla($db, 'imzalar', $_imzaId, 'ekle', null, null, 'İmza eklendi');
        jsonOk(['id' => $_imzaId], 'İmza eklendi.');
    }

} elseif ($method === 'DELETE') {
    verifyCsrf();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) { jsonError('ID gerekli.'); }
    $row = $db->prepare("SELECT img_yolu FROM imzalar WHERE id=?");
    $row->execute([$id]);
    $r = $row->fetch();
    if ($r && $r['img_yolu']) @unlink(UPLOAD_PATH . $r['img_yolu']);
    $db->prepare("DELETE FROM imzalar WHERE id=?")->execute([$id]);
    logla($db, 'imzalar', $id, 'sil', null, null, 'İmza silindi');
    jsonOk(null, 'İmza silindi.');

} else {
    jsonError('Desteklenmeyen istek yöntemi.', 405);
}
