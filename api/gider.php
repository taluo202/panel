<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('makine');

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDb();

function _giderKat($v): string {
    return in_array($v, ['yakit','bakim','sigorta','lastik','onarim','diger'], true) ? $v : 'diger';
}

switch ($method) {

    case 'GET':
        $where = []; $params = [];
        if (($t = trim(metin($_GET['makine_tur'] ?? ''))) !== '' && ($i = (int)($_GET['makine_id'] ?? 0)) > 0) {
            $where[] = "makine_tur=? AND makine_id=?"; array_push($params, $t, $i);
        }
        if (($ay = trim(metin($_GET['ay'] ?? ''))) !== '' && preg_match('/^\d{4}-\d{2}$/', $ay)) {
            $where[] = "substr(tarih,1,7)=?"; $params[] = $ay;
        }
        $sql = "SELECT * FROM giderler";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY tarih DESC, id DESC";
        $st = $db->prepare($sql); $st->execute($params);
        jsonOk($st->fetchAll());
        break;

    case 'POST':
        verifyCsrf();
        $body = govdeOku();
        $tur  = trim(metin($body['makine_tur'] ?? ''));
        $mid  = (int)($body['makine_id'] ?? 0);
        $tutar= max(0, (float)($body['tutar'] ?? 0));
        if (!in_array($tur, ['manlift','arac'], true) || $mid < 1) jsonError('Makine seçilmelidir.');
        if ($tutar <= 0) jsonError('Tutar 0\'dan büyük olmalıdır.');
        $db->prepare("INSERT INTO giderler (makine_tur, makine_id, tarih, kategori, tutar, aciklama) VALUES (?,?,?,?,?,?)")
           ->execute([$tur, $mid, dateStr('tarih', $body) ?: date('Y-m-d'), _giderKat($body['kategori'] ?? 'diger'), $tutar, trim(metin($body['aciklama'] ?? '')) ?: null]);
        $_yeniId = (int)$db->lastInsertId();
        logla($db, 'giderler', $_yeniId, 'ekle', null, logKayitAl($db, 'giderler', $_yeniId), 'Gider eklendi');
        jsonOk(['id' => $_yeniId], 'Gider eklendi.');

    case 'PUT':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $body = govdeOku();
        $tutar= max(0, (float)($body['tutar'] ?? 0));
        if ($tutar <= 0) jsonError('Tutar 0\'dan büyük olmalıdır.');
        $_logEski = logKayitAl($db, 'giderler', $id);
        $db->prepare("UPDATE giderler SET tarih=?, kategori=?, tutar=?, aciklama=? WHERE id=?")
           ->execute([dateStr('tarih', $body) ?: date('Y-m-d'), _giderKat($body['kategori'] ?? 'diger'), $tutar, trim(metin($body['aciklama'] ?? '')) ?: null, $id]);
        logla($db, 'giderler', $id, 'guncelle', $_logEski, logKayitAl($db, 'giderler', $id), 'Gider güncellendi');
        jsonOk(null, 'Gider güncellendi.');

    case 'DELETE':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $_logEski = logKayitAl($db, 'giderler', $id);
        $db->prepare("DELETE FROM giderler WHERE id=?")->execute([$id]);
        logla($db, 'giderler', $id, 'sil', $_logEski, null,
              'Gider SİLİNDİ · ' . number_format((float)($_logEski['tutar'] ?? 0), 2, ',', '.') . ' ₺');
        jsonOk(null, 'Gider silindi.');

    default:
        jsonError('Method Not Allowed', 405);
}
