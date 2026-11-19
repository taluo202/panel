<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
$_oturumPersonelId = (int)($_SESSION['personel_id'] ?? 0);

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDb();

function _mKategori($v): string { return in_array($v, ['yakit','yemek','yol','diger'], true) ? $v : 'diger'; }
function _mDurum($v): string { return in_array($v, ['bekliyor','onaylandi','reddedildi'], true) ? $v : 'bekliyor'; }

function _mFisKaydet(int $personelId): ?string {
    if (empty($_FILES['fis']) || ($_FILES['fis']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    $file = $_FILES['fis'];
    if ($file['error'] !== UPLOAD_ERR_OK) jsonError('Fiş yükleme hatası.');
    if ($file['size'] > MAX_FILE_SIZE) jsonError('Dosya boyutu 20 MB sınırını aşıyor.');
    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mimeType, ALLOWED_MIME, true)) jsonError('İzin verilmeyen dosya türü. Sadece PDF ve resim kabul edilir.');
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXT, true)) jsonError('Geçersiz dosya uzantısı.');

    $dir = UPLOAD_PATH . 'masraf/' . $personelId . '/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) jsonError('Klasör oluşturulamadı.', 500);
    $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $safeName)) jsonError('Fiş kaydedilemedi.', 500);
    @chmod($dir . $safeName, 0644);
    return 'masraf/' . $personelId . '/' . $safeName;
}

switch ($method) {

    case 'GET':
        if (isset($_GET['fis'])) {
            $id = (int)$_GET['fis'];
            $st = $db->prepare("SELECT fis_dosya, personel_id FROM personel_masraf WHERE id=?");
            $st->execute([$id]);
            $fisKaydi = $st->fetch();
            if (!$fisKaydi || !$fisKaydi['fis_dosya']) { http_response_code(404); echo 'Fiş bulunamadı.'; exit; }
            if ((int)$fisKaydi['personel_id'] !== $_oturumPersonelId) requirePermission('personel');
            $rel = $fisKaydi['fis_dosya'];
            if (!preg_match('#^masraf/\d+/[a-f0-9]{32}\.[a-z0-9]{2,5}$#', $rel)) { http_response_code(403); echo 'Erişim reddedildi.'; exit; }
            $fullPath = realpath(UPLOAD_PATH . $rel);
            $uploadBase = realpath(UPLOAD_PATH);
            if (!$fullPath || !$uploadBase || substr($fullPath, 0, strlen($uploadBase)) !== $uploadBase || !file_exists($fullPath)) {
                http_response_code(404); echo 'Fiş bulunamadı.'; exit;
            }
            $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            $mimeMap = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
            header('Content-Type: ' . ($mimeMap[$ext] ?? 'application/octet-stream'));
            header('Content-Disposition: inline; filename="fis.' . $ext . '"');
            header('Content-Length: ' . filesize($fullPath));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, max-age=3600');
            readfile($fullPath);
            exit;
        }

        $_pidFiltre = (int)($_GET['personel_id'] ?? 0);
        if (!($_pidFiltre > 0 && $_pidFiltre === $_oturumPersonelId)) requirePermission('personel');
        $where = []; $params = [];
        if (($aid = (int)($_GET['avans_id'] ?? 0)) > 0) { $where[] = "m.avans_id=?"; $params[] = $aid; }
        if (($pid = (int)($_GET['personel_id'] ?? 0)) > 0) { $where[] = "m.personel_id=?"; $params[] = $pid; }
        if (($d = trim(metin($_GET['durum'] ?? ''))) !== '' && $d !== 'hepsi') { $where[] = "m.durum=?"; $params[] = $d; }
        if (($k = trim(metin($_GET['kategori'] ?? ''))) !== '' && $k !== 'hepsi') { $where[] = "m.kategori=?"; $params[] = $k; }
        if (($ay = trim(metin($_GET['ay'] ?? ''))) !== '' && preg_match('/^\d{4}-\d{2}$/', $ay)) { $where[] = "substr(m.tarih,1,7)=?"; $params[] = $ay; }
        $sql = "SELECT m.*, p.ad_soyad AS personel_ad FROM personel_masraf m JOIN personel p ON p.id=m.personel_id";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY m.tarih DESC, m.id DESC";
        $st = $db->prepare($sql); $st->execute($params);
        $rows = $st->fetchAll();

        $kategoriToplam = ['yakit'=>0.0,'yemek'=>0.0,'yol'=>0.0,'diger'=>0.0];
        $genelToplam = 0.0;
        foreach ($rows as $r) {
            if ($r['durum'] === 'reddedildi') continue;
            $kat = in_array($r['kategori'], ['yakit','yemek','yol','diger'], true) ? $r['kategori'] : 'diger';
            $kategoriToplam[$kat] += (float)$r['tutar'];
            $genelToplam += (float)$r['tutar'];
        }
        foreach ($kategoriToplam as $kk => $vv) $kategoriToplam[$kk] = round($vv, 2);

        jsonOk(['list' => $rows, 'kategori_toplam' => $kategoriToplam, 'genel_toplam' => round($genelToplam, 2)]);
        break;

    case 'POST':
        verifyCsrf();
        if (($_GET['action'] ?? '') === 'duzenle') {
            requirePermission('personel');
            $id = (int)($_GET['id'] ?? 0);
            if ($id < 1) jsonError('Geçersiz ID.');
            $_mEski = logKayitAl($db, 'personel_masraf', $id);
            if (!$_mEski) jsonError('Masraf bulunamadı.', 404);
            alanlarZorunlu($_POST, ['tarih', 'kategori', 'tutar', 'aciklama', 'belge_no']);
            $tarih = dateStr('tarih', $_POST);
            if (!$tarih) jsonError('Tarih zorunludur.');
            $tutar = max(0, (float)$_POST['tutar']);
            if ($tutar <= 0) jsonError('Tutar 0\'dan büyük olmalıdır.');
            $yeniFis = _mFisKaydet((int)$_mEski['personel_id']);
            $set = 'tarih=?, kategori=?, tutar=?, aciklama=?, belge_no=?';
            $prm = [$tarih, _mKategori(metin($_POST['kategori'])), $tutar, trim(metin($_POST['aciklama'])) ?: null, belgeNoAl($_POST)];
            if ($yeniFis !== null) { $set .= ', fis_dosya=?'; $prm[] = $yeniFis; }
            $oncekiDurum = _mDurum($_mEski['durum'] ?? null);
            $yenidenOnay = $oncekiDurum !== 'bekliyor';
            if ($yenidenOnay) $set .= ", durum='bekliyor', onaylayan=NULL, ret_gerekcesi=NULL";
            $prm[] = $id;
            $db->prepare("UPDATE personel_masraf SET $set WHERE id=?")->execute($prm);
            if ($yeniFis !== null && !empty($_mEski['fis_dosya']) && $_mEski['fis_dosya'] !== $yeniFis) belgeDosyasiSil($_mEski['fis_dosya']);
            logla($db, 'personel_masraf', $id, 'guncelle', $_mEski, logKayitAl($db, 'personel_masraf', $id),
                'Masraf düzenlendi: ' . number_format($tutar, 2, ',', '.') . ' ₺ · personel #' . (int)$_mEski['personel_id'] . ($yeniFis !== null ? ' · fiş değiştirildi' : '')
                . ($yenidenOnay ? ' · yeniden onaya düştü (önceki durum: ' . $oncekiDurum . ')' : ''));
            jsonOk(['id' => $id, 'yeniden_onay' => $yenidenOnay],
                ($yeniFis !== null ? 'Masraf ve fişi güncellendi.' : 'Masraf güncellendi.') . ($yenidenOnay ? ' Yeniden onaya gönderildi.' : ''));
        }
        $avansId    = ((int)($_POST['avans_id'] ?? 0)) ?: null;
        $personelId = (int)($_POST['personel_id'] ?? 0);
        if (!($personelId > 0 && $personelId === $_oturumPersonelId)) requirePermission('personel');
        $tarih      = dateStr('tarih', $_POST);
        $tutar      = max(0, (float)($_POST['tutar'] ?? 0));
        if ($personelId < 1) jsonError('Personel seçilmelidir.');
        if (!$tarih) jsonError('Tarih zorunludur.');
        if ($tutar <= 0) jsonError('Tutar 0\'dan büyük olmalıdır.');

        if ($avansId) {
            $aChk = $db->prepare("SELECT COUNT(*) FROM personel_avans WHERE id=?");
            $aChk->execute([$avansId]);
            if ((int)$aChk->fetchColumn() === 0) jsonError('İlgili avans bulunamadı.');
        }

        $rel = _mFisKaydet($personelId);

        $db->prepare("
            INSERT INTO personel_masraf (avans_id, personel_id, tarih, kategori, tutar, aciklama, fis_dosya, belge_no, durum)
            VALUES (?,?,?,?,?,?,?,?,'bekliyor')
        ")->execute([
            $avansId, $personelId, $tarih, _mKategori($_POST['kategori'] ?? ''), $tutar,
            trim(metin($_POST['aciklama'] ?? '')) ?: null, $rel, belgeNoAl($_POST),
        ]);
        $masrafId = (int)$db->lastInsertId();
        logla($db, 'personel_masraf', $masrafId, 'ekle', null, null,
            'Masraf kaydedildi: ' . number_format($tutar, 2, ',', '.') . ' ₺ · personel #' . $personelId);
        jsonOk(['id' => $masrafId], 'Masraf kaydedildi.');

    case 'PUT':
        verifyCsrf();
        requirePermission('personel');
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $body = govdeOku();
        $cur = $db->prepare("SELECT id FROM personel_masraf WHERE id=?");
        $cur->execute([$id]);
        if (!$cur->fetch()) jsonError('Masraf bulunamadı.', 404);

        $durum = _mDurum($body['durum'] ?? '');
        $onaylayan = in_array($durum, ['onaylandi','reddedildi'], true) ? (trim((string)($_SESSION['username'] ?? '')) ?: null) : null;
        $gerekce = retGerekcesi($body, $durum);
        $_mEski = logKayitAl($db, 'personel_masraf', $id);
        $db->prepare("UPDATE personel_masraf SET durum=?, onaylayan=?, ret_gerekcesi=? WHERE id=?")->execute([$durum, $onaylayan, $gerekce, $id]);
        logla($db, 'personel_masraf', $id, 'guncelle', $_mEski, logKayitAl($db, 'personel_masraf', $id),
            'Masraf durumu: ' . $durum . ($onaylayan ? ' (' . $onaylayan . ')' : '') . ($gerekce !== null ? ' · gerekçe: ' . $gerekce : ''));
        jsonOk(null, 'Durum güncellendi.');

    case 'DELETE':
        verifyCsrf();
        requirePermission('personel');
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $st = $db->prepare("SELECT fis_dosya FROM personel_masraf WHERE id=?");
        $st->execute([$id]);
        $rel = $st->fetchColumn();
        if ($rel !== false && $rel && preg_match('#^masraf/\d+/[a-f0-9]{32}\.[a-z0-9]{2,5}$#', $rel)) {
            $full = realpath(UPLOAD_PATH . $rel);
            $base = realpath(UPLOAD_PATH);
            if ($full && $base && strncmp($full, $base, strlen($base)) === 0 && file_exists($full)) unlink($full);
        }
        $_mEski = logKayitAl($db, 'personel_masraf', $id);
        $db->prepare("DELETE FROM personel_masraf WHERE id=?")->execute([$id]);
        logla($db, 'personel_masraf', $id, 'sil', $_mEski, null, 'Masraf kaydı silindi (fiş görseli de silindi)');
        jsonOk(null, 'Masraf silindi.');

    default:
        jsonError('Method Not Allowed', 405);
}
