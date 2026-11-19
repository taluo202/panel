<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
if (($_SESSION['rol'] ?? '') !== 'admin') jsonError('Sadece admin bu işlemi yapabilir.', 403);
verifyCsrf();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$db     = getDb();

if ($action === 'katalog') {

    if ($method === 'POST') {
        $kategori = trim(metin($_POST['kategori'] ?? ''));
        $dosyaAdi = trim(metin($_POST['dosya_adi'] ?? ''));
        if ($kategori === '') jsonError('Kategori zorunludur.');
        if ($dosyaAdi === '') jsonError('Dosya adı zorunludur.');
        if (!isset($_FILES['dosya']) || $_FILES['dosya']['error'] !== UPLOAD_ERR_OK) jsonError('Dosya yükleme hatası.');

        $file = $_FILES['dosya'];
        if ((int)$file['size'] > MAX_FILE_SIZE) jsonError('Dosya boyutu 20 MB sınırını aşıyor.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!in_array($mime, ALLOWED_MIME, true)) jsonError('İzin verilmeyen dosya türü.');
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_EXT, true)) jsonError('İzin verilmeyen dosya uzantısı.');

        $slotId = (int)($db->query("SELECT COALESCE(MAX(id),0)+1 FROM kiralama_kataloglar")->fetchColumn());
        $dir    = UPLOAD_PATH . 'kiralama_kat/' . $slotId . '/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $hash = bin2hex(random_bytes(16));
        $rel  = 'kiralama_kat/' . $slotId . '/' . $hash . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], UPLOAD_PATH . $rel)) jsonError('Dosya kaydedilemedi.');

        $db->prepare("INSERT INTO kiralama_kataloglar (id, kategori, dosya_adi, dosya_yolu) VALUES (?,?,?,?)")
           ->execute([$slotId, $kategori, $dosyaAdi, $rel]);
        logla($db, 'kiralama_kataloglar', $slotId, 'ekle', null, null, 'Katalog yüklendi');
        jsonOk(['id' => $slotId], 'Katalog yüklendi.');
    }

    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $row = $db->prepare("SELECT dosya_yolu FROM kiralama_kataloglar WHERE id=?")->execute([$id]) ? null : null;
        $st  = $db->prepare("SELECT dosya_yolu FROM kiralama_kataloglar WHERE id=?");
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) jsonError('Katalog bulunamadı.', 404);
        _dosyaSil($row['dosya_yolu']);
        $db->prepare("DELETE FROM kiralama_kataloglar WHERE id=?")->execute([$id]);
        logla($db, 'kiralama_kataloglar', $id, 'sil', null, null, 'Katalog silindi');
        jsonOk(null, 'Katalog silindi.');
    }
}

if ($action === 'urun') {

    if ($method === 'POST') {
        $isim     = trim(metin($_POST['isim'] ?? ''));
        $kategori = trim(metin($_POST['kategori'] ?? ''));
        $aciklama = trim(metin($_POST['aciklama'] ?? '')) ?: null;
        $gorsel   = trim(metin($_POST['gorsel'] ?? '')) ?: null;
        $sira     = max(0, (int)($_POST['sira'] ?? 0));
        if ($isim === '') jsonError('İsim zorunludur.');
        if ($kategori === '') jsonError('Kategori zorunludur.');

        $newId = (int)($db->query("SELECT COALESCE(MAX(id),10000)+1 FROM kiralama_urunler")->fetchColumn());

        $db->prepare("INSERT INTO kiralama_urunler (id, isim, kategori, gorsel, aciklama, sira) VALUES (?,?,?,?,?,?)")
           ->execute([$newId, $isim, $kategori, $gorsel, $aciklama, $sira]);

        $gorselYerel = null;
        if (isset($_FILES['gorsel_dosya']) && $_FILES['gorsel_dosya']['error'] === UPLOAD_ERR_OK) {
            $gorselYerel = _gorselYukle($newId, $_FILES['gorsel_dosya']);
            if ($gorselYerel) {
                $db->prepare("UPDATE kiralama_urunler SET gorsel_yerel=? WHERE id=?")->execute([$gorselYerel, $newId]);
            }
        }

        logla($db, 'kiralama_urunler', $newId, 'ekle', null, null, 'Vitrin ürünü eklendi');
        jsonOk(['id' => $newId], 'Ürün eklendi.');
    }

    if ($method === 'PUT') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $body     = govdeOku();
        $isim     = trim(metin($body['isim'] ?? ''));
        $kategori = trim(metin($body['kategori'] ?? ''));
        $aciklama = trim(metin($body['aciklama'] ?? '')) ?: null;
        $gorsel   = trim(metin($body['gorsel'] ?? '')) ?: null;
        $sira     = max(0, (int)($body['sira'] ?? 0));
        if ($isim === '') jsonError('İsim zorunludur.');
        if ($kategori === '') jsonError('Kategori zorunludur.');
        $db->prepare("UPDATE kiralama_urunler SET isim=?, kategori=?, gorsel=?, aciklama=?, sira=? WHERE id=?")
           ->execute([$isim, $kategori, $gorsel, $aciklama, $sira, $id]);
        logla($db, 'kiralama_urunler', $id, 'guncelle', null, null, 'Vitrin ürünü güncellendi');
        jsonOk(null, 'Ürün güncellendi.');
    }

    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $st = $db->prepare("SELECT gorsel_yerel FROM kiralama_urunler WHERE id=?");
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) jsonError('Ürün bulunamadı.', 404);
        if ($row['gorsel_yerel']) _dosyaSil($row['gorsel_yerel']);
        $db->prepare("DELETE FROM kiralama_urunler WHERE id=?")->execute([$id]);
        logla($db, 'kiralama_urunler', $id, 'sil', null, null, 'Vitrin ürünü silindi');
        jsonOk(null, 'Ürün silindi.');
    }
}

if ($action === 'gorsel') {

    if ($method === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        if (!isset($_FILES['gorsel_dosya']) || $_FILES['gorsel_dosya']['error'] !== UPLOAD_ERR_OK) jsonError('Dosya yükleme hatası.');

        $st = $db->prepare("SELECT gorsel_yerel FROM kiralama_urunler WHERE id=?");
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) jsonError('Ürün bulunamadı.', 404);
        if ($row['gorsel_yerel']) _dosyaSil($row['gorsel_yerel']);

        $rel = _gorselYukle($id, $_FILES['gorsel_dosya']);
        if (!$rel) jsonError('Görsel kaydedilemedi.');
        $db->prepare("UPDATE kiralama_urunler SET gorsel_yerel=? WHERE id=?")->execute([$rel, $id]);
        jsonOk(['gorsel_yerel' => $rel], 'Görsel güncellendi.');
    }

    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $st = $db->prepare("SELECT gorsel_yerel FROM kiralama_urunler WHERE id=?");
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) jsonError('Ürün bulunamadı.', 404);
        if ($row['gorsel_yerel']) _dosyaSil($row['gorsel_yerel']);
        $db->prepare("UPDATE kiralama_urunler SET gorsel_yerel=NULL WHERE id=?")->execute([$id]);
        jsonOk(null, 'Görsel kaldırıldı.');
    }
}

if ($action === 'urun_katalog') {

    if ($method === 'POST') {
        $urunId  = (int)($_POST['urun_id'] ?? 0);
        $dosyaAdi = trim(metin($_POST['dosya_adi'] ?? ''));
        if ($urunId < 1) jsonError('Geçersiz ürün ID.');
        if ($dosyaAdi === '') jsonError('Dosya adı zorunludur.');

        $st = $db->prepare("SELECT id FROM kiralama_urunler WHERE id=?");
        $st->execute([$urunId]);
        if (!$st->fetch()) jsonError('Ürün bulunamadı.', 404);

        if (!isset($_FILES['dosya']) || $_FILES['dosya']['error'] !== UPLOAD_ERR_OK) jsonError('Dosya yükleme hatası.');
        $file = $_FILES['dosya'];
        if ((int)$file['size'] > MAX_FILE_SIZE) jsonError('Dosya boyutu 20 MB sınırını aşıyor.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!in_array($mime, ALLOWED_MIME, true)) jsonError('İzin verilmeyen dosya türü.');
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_EXT, true)) jsonError('İzin verilmeyen dosya uzantısı.');

        $dir  = UPLOAD_PATH . 'kiralama_urun_kat/' . $urunId . '/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $hash = bin2hex(random_bytes(16));
        $rel  = 'kiralama_urun_kat/' . $urunId . '/' . $hash . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], UPLOAD_PATH . $rel)) jsonError('Dosya kaydedilemedi.');

        $db->prepare("INSERT INTO kiralama_urun_kataloglar (urun_id, dosya_adi, dosya_yolu) VALUES (?,?,?)")
           ->execute([$urunId, $dosyaAdi, $rel]);
        jsonOk(['id' => (int)$db->lastInsertId()], 'Katalog yüklendi.');
    }

    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $st = $db->prepare("SELECT dosya_yolu FROM kiralama_urun_kataloglar WHERE id=?");
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) jsonError('Katalog bulunamadı.', 404);
        _dosyaSil($row['dosya_yolu']);
        $db->prepare("DELETE FROM kiralama_urun_kataloglar WHERE id=?")->execute([$id]);
        logla($db, 'kiralama_urun_kataloglar', $id, 'sil', null, null, 'Ürün kataloğu silindi');
        jsonOk(null, 'Katalog silindi.');
    }
}

if ($action === 'galeri') {

    if ($method === 'POST') {
        $urunId = (int)($_POST['urun_id'] ?? 0);
        if ($urunId < 1) jsonError('Geçersiz ürün ID.');
        $st = $db->prepare("SELECT id FROM kiralama_urunler WHERE id=?");
        $st->execute([$urunId]);
        if (!$st->fetch()) jsonError('Ürün bulunamadı.', 404);
        if (!isset($_FILES['gorsel_dosya']) || $_FILES['gorsel_dosya']['error'] !== UPLOAD_ERR_OK) jsonError('Dosya yükleme hatası.');

        $rel = _galeriYukle($urunId, $_FILES['gorsel_dosya']);
        if (!$rel) jsonError('Görsel kaydedilemedi.');

        $siraSt = $db->prepare("SELECT COALESCE(MAX(sira),0)+1 FROM kiralama_urun_gorseller WHERE urun_id=?");
        $siraSt->execute([$urunId]);
        $sira = (int)$siraSt->fetchColumn();
        $db->prepare("INSERT INTO kiralama_urun_gorseller (urun_id, gorsel_yolu, sira) VALUES (?,?,?)")
           ->execute([$urunId, $rel, $sira]);
        $newId = (int)$db->lastInsertId();
        jsonOk(['id' => $newId, 'gorsel_yolu' => $rel], 'Görsel eklendi.');
    }

    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $st = $db->prepare("SELECT gorsel_yolu FROM kiralama_urun_gorseller WHERE id=?");
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) jsonError('Görsel bulunamadı.', 404);
        _dosyaSil($row['gorsel_yolu']);
        $db->prepare("DELETE FROM kiralama_urun_gorseller WHERE id=?")->execute([$id]);
        jsonOk(null, 'Görsel silindi.');
    }
}

http_response_code(405);
echo 'Method Not Allowed';

function _galeriYukle(int $urunId, array $file): ?string {
    if ((int)$file['size'] > MAX_FILE_SIZE) return null;
    $imgMime = ['image/jpeg','image/png','image/webp'];
    $imgExt  = ['jpg','jpeg','png','webp'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, $imgMime, true)) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $imgExt, true)) return null;
    $dir = UPLOAD_PATH . 'kiralama_galeri/' . $urunId . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $hash = bin2hex(random_bytes(16));
    $rel  = 'kiralama_galeri/' . $urunId . '/' . $hash . '.' . $ext;
    return move_uploaded_file($file['tmp_name'], UPLOAD_PATH . $rel) ? $rel : null;
}

function _gorselYukle(int $id, array $file): ?string {
    if ((int)$file['size'] > MAX_FILE_SIZE) return null;
    $imgMime = ['image/jpeg','image/png','image/webp'];
    $imgExt  = ['jpg','jpeg','png','webp'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, $imgMime, true)) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $imgExt, true)) return null;
    $dir = UPLOAD_PATH . 'kiralama_gorsel/' . $id . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $hash = bin2hex(random_bytes(16));
    $rel  = 'kiralama_gorsel/' . $id . '/' . $hash . '.' . $ext;
    return move_uploaded_file($file['tmp_name'], UPLOAD_PATH . $rel) ? $rel : null;
}

function _dosyaSil(?string $rel): void {
    if (!$rel) return;
    if (!preg_match('#^kiralama[_a-z]*/\d+/[a-f0-9]{32}\.[a-z0-9]{2,5}$#', $rel)) return;
    $full = realpath(UPLOAD_PATH . $rel);
    $base = realpath(UPLOAD_PATH);
    if ($full && $base && substr($full, 0, strlen($base)) === $base && file_exists($full)) {
        unlink($full);
    }
}
