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
        $where = []; $params = [];
        if (($mt = trim(metin($_GET['makine_tur'] ?? ''))) !== '' && ($mi = (int)($_GET['makine_id'] ?? 0)) > 0) {
            $where[] = "makine_tur=? AND makine_id=?"; $params[] = $mt; $params[] = $mi;
        }
        $sql = "SELECT * FROM ekipman_transfer";
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
        if (!$tarih) jsonError('Transfer tarihi zorunludur.');
        $yeniSube = trim(metin($body['yeni_sube'] ?? ''));
        if ($yeniSube === '') jsonError('Yeni şube zorunludur.');

        $tablo = $makineTur === 'arac' ? 'araclar' : 'manliftler';
        $adKolon = $makineTur === 'arac' ? 'plaka' : 'kod';
        $mSt = $db->prepare("SELECT $adKolon AS ad, sube FROM $tablo WHERE id=?");
        $mSt->execute([$makineId]);
        $makine = $mSt->fetch();
        if (!$makine) jsonError('Ekipman bulunamadı.', 404);
        $eskiSube = $makine['sube'];

        if ($eskiSube === $yeniSube) jsonError('Ekipman zaten bu şubede.');

        $st = $db->prepare("
            INSERT INTO ekipman_transfer (makine_tur, makine_id, makine_ad, tarih, eski_sube, yeni_sube, aciklama)
            VALUES (?,?,?,?,?,?,?)
        ");
        $st->execute([
            $makineTur, $makineId, $makine['ad'], $tarih, $eskiSube, $yeniSube,
            trim(metin($body['aciklama'] ?? '')) ?: null,
        ]);

        $transferId = (int)$db->lastInsertId();
        $db->prepare("UPDATE $tablo SET sube=?, updated_at=datetime('now','localtime') WHERE id=?")->execute([$yeniSube, $makineId]);

        logla($db, 'ekipman_transfer', $transferId, 'ekle', null, null,
            'Şube transferi: ' . ($makine['ad'] ?: ($makineTur . '#' . $makineId)) . ' · '
            . (($eskiSube ?: '(şubesiz)')) . ' → ' . $yeniSube);

        jsonOk(['id' => $transferId], 'Transfer kaydedildi.');

    case 'PUT':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) jsonError('Geçersiz istek gövdesi.');
        foreach (['tarih', 'aciklama'] as $_alan) {
            if (!array_key_exists($_alan, $body)) jsonError('Eksik alan: ' . $_alan . '. Düzenleme formu bütün alanları göndermelidir.');
        }
        $_trEski = logKayitAl($db, 'ekipman_transfer', $id);
        if (!$_trEski) jsonError('Transfer kaydı bulunamadı.', 404);
        $tarih = dateStr('tarih', $body);
        if (!$tarih) jsonError('Transfer tarihi zorunludur.');
        $aciklama = trim(metin($body['aciklama'])) ?: null;
        $db->prepare("UPDATE ekipman_transfer SET tarih=?, aciklama=? WHERE id=?")->execute([$tarih, $aciklama, $id]);
        logla($db, 'ekipman_transfer', $id, 'guncelle', $_trEski, logKayitAl($db, 'ekipman_transfer', $id),
            'Şube transfer kaydı düzenlendi: ' . ($_trEski['makine_ad'] ?: ($_trEski['makine_tur'] . '#' . $_trEski['makine_id'])));
        jsonOk(['id' => $id], 'Transfer kaydı güncellendi.');

    case 'DELETE':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $_trLogEski = logKayitAl($db, 'ekipman_transfer', $id);
        $db->prepare("DELETE FROM ekipman_transfer WHERE id=?")->execute([$id]);
        logla($db, 'ekipman_transfer', $id, 'sil', $_trLogEski, null, 'Transfer kaydı silindi');
        jsonOk(null, 'Transfer kaydı silindi.');

    default:
        jsonError('Method Not Allowed', 405);
}
