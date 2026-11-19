<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();

$db     = getDb();
$method = $_SERVER['REQUEST_METHOD'];

function tutanakModul(string $tur): string {
    return match ($tur) {
        'arac'    => 'arac',
        'manlift' => 'manlift',
        default   => 'personel',
    };
}

function tutanakDosyalari(): array {
    $incoming = $_FILES['dosya'] ?? null;
    $files = [];
    if ($incoming && isset($incoming['name'])) {
        if (is_array($incoming['name'])) {
            $count = count($incoming['name']);
            for ($i = 0; $i < $count; $i++) {
                $files[] = [
                    'name'     => $incoming['name'][$i],
                    'tmp_name' => $incoming['tmp_name'][$i] ?? '',
                    'error'    => $incoming['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $incoming['size'][$i] ?? 0,
                ];
            }
        } else {
            $files[] = $incoming;
        }
    }
    return array_values(array_filter($files, fn($f) => ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
}

function tutanakDosyaKaydet(array $file, string $tur, int $entityId): string {
    if ($file['error'] !== UPLOAD_ERR_OK) jsonError('Dosya yükleme hatası: ' . $file['error']);
    if ($file['size'] > MAX_FILE_SIZE)    jsonError('Dosya boyutu 20 MB sınırını aşıyor: ' . $file['name']);
    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mimeType, ALLOWED_MIME, true)) jsonError('İzin verilmeyen dosya türü (' . $file['name'] . '). Sadece PDF ve resim kabul edilir.');
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXT, true)) jsonError('Geçersiz dosya uzantısı: ' . $file['name']);
    $dir = UPLOAD_PATH . 'tutanak/' . $tur . '/' . $entityId . '/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) jsonError('Klasör oluşturulamadı.', 500);
    $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $safeName)) jsonError('Dosya kaydedilemedi.', 500);
    @chmod($dir . $safeName, 0644);
    return 'tutanak/' . $tur . '/' . $entityId . '/' . $safeName;
}

function tutanakDosyaSil(?string $rel): void {
    if (!$rel || !preg_match('#^tutanak/[a-z]+/\d+/[a-f0-9]{32}\.[a-z0-9]{2,5}$#', $rel)) return;
    $full = realpath(UPLOAD_PATH . $rel);
    $base = realpath(UPLOAD_PATH);
    if ($full && $base && strncmp($full, $base, strlen($base)) === 0 && file_exists($full)) @unlink($full);
}

$gecerliTurler = ['personel', 'arac', 'manlift'];

switch ($method) {

    case 'GET':
        if (isset($_GET['dosya'])) {
            $id = (int)$_GET['dosya'];
            $stmt = $db->prepare("SELECT tur, baslik, dosya_yolu FROM tutanaklar WHERE id=?");
            $stmt->execute([$id]);
            $t = $stmt->fetch();
            if (!$t || !$t['dosya_yolu']) { http_response_code(404); echo 'Dosya bulunamadı.'; exit; }
            requirePermission(tutanakModul($t['tur']));

            $rel = $t['dosya_yolu'];
            if (!preg_match('#^tutanak/[a-z]+/\d+/[a-f0-9]{32}\.[a-z0-9]{2,5}$#', $rel)) {
                http_response_code(403); echo 'Erişim reddedildi.'; exit;
            }
            $fullPath   = realpath(UPLOAD_PATH . $rel);
            $uploadBase = realpath(UPLOAD_PATH);
            if (!$fullPath || !$uploadBase || substr($fullPath, 0, strlen($uploadBase)) !== $uploadBase || !file_exists($fullPath)) {
                http_response_code(404); echo 'Dosya bulunamadı.'; exit;
            }
            $ext     = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            $mimeMap = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
            $mime    = $mimeMap[$ext] ?? 'application/octet-stream';
            $safe    = preg_replace('/[^a-zA-Z0-9_\-\.ÇçĞğİıÖöŞşÜü ]/', '_', (string)$t['baslik']) . '.' . $ext;
            header('Content-Type: ' . $mime);
            header('Content-Disposition: inline; filename="' . $safe . '"');
            header('Content-Length: ' . filesize($fullPath));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, max-age=3600');
            readfile($fullPath);
            exit;
        }

        $tur      = $_GET['tur'] ?? '';
        $entityId = (int)($_GET['entity_id'] ?? 0);
        if (!in_array($tur, $gecerliTurler, true) || $entityId < 1) jsonError('Eksik veya geçersiz parametre.');
        requirePermission(tutanakModul($tur));
        $stmt = $db->prepare("SELECT * FROM tutanaklar WHERE tur=? AND entity_id=? ORDER BY id DESC");
        $stmt->execute([$tur, $entityId]);
        jsonOk($stmt->fetchAll());
        break;

    case 'POST':
        verifyCsrf();
        if (($_GET['action'] ?? '') === 'duzenle') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id < 1) jsonError('Geçersiz ID.');
            $_tuEski = logKayitAl($db, 'tutanaklar', $id);
            if (!$_tuEski) jsonError('Evrak bulunamadı.', 404);
            requirePermission(tutanakModul((string)$_tuEski['tur']));
            alanlarZorunlu($_POST, ['baslik', 'tarih', 'belge_no']);
            $baslik = mb_substr(trim(metin($_POST['baslik'])), 0, 200);
            if ($baslik === '') jsonError('Evrak başlığı boş bırakılamaz.');
            $tarih = dateStr('tarih', $_POST);
            if (!$tarih) jsonError('Geçerli bir evrak tarihi girin.');
            $belgeNo = belgeNoAl($_POST);
            $files = tutanakDosyalari();
            if (count($files) > 1) jsonError('Düzenlemede yalnız bir dosya seçilebilir; birden fazla dosya için "Evrak Yükle" kullanın.');
            $yeniYol = $files ? tutanakDosyaKaydet($files[0], (string)$_tuEski['tur'], (int)$_tuEski['entity_id']) : null;
            $set = 'baslik=?, tarih=?, belge_no=?';
            $prm = [$baslik, $tarih, $belgeNo];
            if ($yeniYol !== null) { $set .= ', dosya_yolu=?'; $prm[] = $yeniYol; }
            $prm[] = $id;
            $db->prepare("UPDATE tutanaklar SET $set WHERE id=?")->execute($prm);
            if ($yeniYol !== null && !empty($_tuEski['dosya_yolu']) && $_tuEski['dosya_yolu'] !== $yeniYol) {
                tutanakDosyaSil($_tuEski['dosya_yolu']);
            }
            logla($db, 'tutanaklar', $id, 'guncelle', $_tuEski, logKayitAl($db, 'tutanaklar', $id),
                'Evrak düzenlendi: ' . $baslik . ' (' . $_tuEski['tur'] . ' #' . (int)$_tuEski['entity_id'] . ')' . ($yeniYol !== null ? ' · dosya değiştirildi' : ''));
            jsonOk(['id' => $id], $yeniYol !== null ? 'Evrak ve dosyası güncellendi.' : 'Evrak güncellendi.');
        }

        $tur        = $_POST['tur'] ?? '';
        $entityId   = (int)($_POST['entity_id'] ?? 0);
        $baslikGiris = trim(metin($_POST['baslik'] ?? ''));

        if (!in_array($tur, $gecerliTurler, true) || $entityId < 1) jsonError('Geçersiz tür veya kayıt.');
        requirePermission(tutanakModul($tur));

        $tablo = match ($tur) { 'arac' => 'araclar', 'manlift' => 'manliftler', default => 'personel' };
        $chk = $db->prepare("SELECT 1 FROM $tablo WHERE id=?");
        $chk->execute([$entityId]);
        if (!$chk->fetchColumn()) jsonError('İlgili kayıt bulunamadı.', 404);

        $files = tutanakDosyalari();
        if (empty($files)) jsonError('Lütfen en az bir dosya seçin.');

        $olusturan = trim((string)($_SESSION['ad_soyad'] ?? ($_SESSION['username'] ?? ''))) ?: null;
        $tarih     = dateStr('tarih', $_POST) ?? date('Y-m-d');
        $belgeNo   = belgeNoAl($_POST);
        $ins = $db->prepare("
            INSERT INTO tutanaklar (tur, entity_id, tarih, baslik, belge_no, dosya_yolu, olusturan)
            VALUES (?,?,?,?,?,?,?)
        ");

        $eklenen = 0;
        $adet = count($files);
        foreach ($files as $i => $file) {
            $rel = tutanakDosyaKaydet($file, $tur, $entityId);
            $ad = $baslikGiris !== ''
                ? ($adet > 1 ? mb_substr($baslikGiris, 0, 190) . ' (' . ($i + 1) . '/' . $adet . ')' : mb_substr($baslikGiris, 0, 200))
                : (pathinfo($file['name'], PATHINFO_FILENAME) ?: 'Evrak');

            $ins->execute([$tur, $entityId, $tarih, $ad, $belgeNo, $rel, $olusturan]);
            $eklenen++;
        }

        if ($eklenen > 0) {
            logla($db, 'tutanaklar', null, 'ekle', null, null,
                $eklenen . ' evrak yüklendi · ' . $tur . ' #' . $entityId . ($belgeNo !== null ? ' · belge no ' . $belgeNo : ''));
        }
        jsonOk(['adet' => $eklenen], $eklenen > 1 ? $eklenen . ' evrak yüklendi.' : 'Evrak yüklendi.');

    case 'PUT':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) jsonError('Geçersiz istek gövdesi.');
        $_tuEski = logKayitAl($db, 'tutanaklar', $id);
        if (!$_tuEski) jsonError('Evrak bulunamadı.', 404);
        requirePermission(tutanakModul((string)$_tuEski['tur']));
        alanlarZorunlu($body, ['baslik', 'tarih', 'belge_no']);
        $baslik = mb_substr(trim(metin($body['baslik'])), 0, 200);
        if ($baslik === '') jsonError('Evrak başlığı boş bırakılamaz.');
        $tarih = dateStr('tarih', $body);
        if (!$tarih) jsonError('Geçerli bir evrak tarihi girin.');
        $belgeNo = belgeNoAl($body);
        $db->prepare("UPDATE tutanaklar SET baslik=?, tarih=?, belge_no=? WHERE id=?")->execute([$baslik, $tarih, $belgeNo, $id]);
        logla($db, 'tutanaklar', $id, 'guncelle', $_tuEski, logKayitAl($db, 'tutanaklar', $id),
            'Evrak düzenlendi: ' . $baslik . ' (' . $_tuEski['tur'] . ' #' . (int)$_tuEski['entity_id'] . ')');
        jsonOk(['id' => $id], 'Evrak güncellendi.');

    case 'DELETE':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $stmt = $db->prepare("SELECT tur, dosya_yolu FROM tutanaklar WHERE id=?");
        $stmt->execute([$id]);
        $t = $stmt->fetch();
        if (!$t) jsonError('Evrak bulunamadı.', 404);
        requirePermission(tutanakModul($t['tur']));

        tutanakDosyaSil($t['dosya_yolu'] ?? null);
        $_tuEski = logKayitAl($db, 'tutanaklar', $id);
        $db->prepare("DELETE FROM tutanaklar WHERE id=?")->execute([$id]);
        logla($db, 'tutanaklar', $id, 'sil', $_tuEski, null,
            'Evrak SİLİNDİ (dosyası da kaldırıldı): ' . ($_tuEski['baslik'] ?? ('#' . $id)));
        jsonOk(null, 'Evrak silindi.');

    default:
        jsonError('Method Not Allowed', 405);
}
