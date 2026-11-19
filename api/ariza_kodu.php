<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$db     = getDb();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    requireAuth();
    $marka = trim(metin($_GET['marka'] ?? ''));
    $q     = trim(metin($_GET['q'] ?? ''));

    if (($_GET['action'] ?? '') === 'markalar') {
        $st = $db->query("SELECT marka, COUNT(*) adet FROM ariza_kodlari WHERE aktif=1 GROUP BY marka ORDER BY marka");
        jsonOk($st->fetchAll());
    }

    $where = ['aktif=1']; $params = [];
    if ($marka !== '') {
        $where[] = "(instr(LOWER(?), LOWER(marka)) > 0 OR LOWER(marka) = LOWER(?))";
        $params[] = $marka; $params[] = $marka;
    }
    if ($q !== '') {
        $where[] = "(" . trAramaSql('arama') . " LIKE ? OR " . trAramaSql('kod') . " LIKE ? OR " . trAramaSql('aciklama') . " LIKE ? OR " . trAramaSql('kategori') . " LIKE ?)";
        $like = '%' . trArama($q) . '%';
        array_push($params, '%' . trArama($q) . '%', $like, $like, $like);
    }
    $sql = "SELECT MIN(id) AS id, marka, kategori_kod, kategori, kod, aciklama,
                   GROUP_CONCAT(NULLIF(model,''), ', ') AS modeller
            FROM ariza_kodlari
            WHERE " . implode(' AND ', $where) . "
            GROUP BY marka, kod, aciklama
            ORDER BY marka, kategori_kod, kod LIMIT 500";
    $st = $db->prepare($sql);
    $st->execute($params);
    jsonOk($st->fetchAll());
}

if ($method === 'POST') {
    requireAuth();
    requirePermission('kiralama_takip');
    verifyCsrf();
    $body = govdeOku();
    $marka = trim(metin($body['marka'] ?? ''));
    $kod   = trim(metin($body['kod'] ?? ''));
    $acik  = trim(metin($body['aciklama'] ?? ''));
    if ($marka === '' || $kod === '' || $acik === '') jsonError('Marka, kod ve açıklama zorunludur.');
    $model = trim(metin($body['model'] ?? '')); $katAd = trim(metin($body['kategori'] ?? ''));
    if (mb_strlen($marka) > 100 || mb_strlen($kod) > 100 || mb_strlen($model) > 200 || mb_strlen($katAd) > 200
        || mb_strlen(metin($body['kategori_kod'] ?? '')) > 50 || mb_strlen($acik) > 2000) {
        jsonError('Arıza kodu alanları çok uzun (marka/kod en çok 100, model/kategori 200, açıklama 2000 karakter).');
    }
    $db->prepare("INSERT INTO ariza_kodlari (marka, model, kategori_kod, kategori, kod, aciklama, arama) VALUES (?,?,?,?,?,?,?)")
       ->execute([$marka, $model, trim(metin($body['kategori_kod'] ?? '')), $katAd, $kod, $acik,
                  trArama($kod . ' ' . $katAd . ' ' . $acik . ' ' . $model)]);
    $_akId = (int)$db->lastInsertId();
    logla($db, 'ariza_kodlari', $_akId, 'ekle', null, null, 'Arıza kodu eklendi');
    jsonOk(['id' => $_akId], 'Arıza kodu eklendi.');
}

if ($method === 'DELETE') {
    requireAuth();
    requirePermission('kiralama_takip');
    verifyCsrf();
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) jsonError('Geçersiz ID.');
    $db->prepare("DELETE FROM ariza_kodlari WHERE id=?")->execute([$id]);
    logla($db, 'ariza_kodlari', $id, 'sil', null, null, 'Arıza kodu silindi');
    jsonOk(null, 'Arıza kodu silindi.');
}

jsonError('Method Not Allowed', 405);
