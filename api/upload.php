<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
verifyCsrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method Not Allowed', 405);

$duzenle     = ((string)($_GET['action'] ?? ($_POST['action'] ?? ''))) === 'duzenle';
$tur         = $_POST['tur'] ?? '';
$entityId    = (int)($_POST['entity_id'] ?? 0);
$kategori    = trim(metin($_POST['kategori'] ?? ''));
$belgeAdi    = trim(metin($_POST['belge_adi'] ?? ''));
$belgeNo     = mb_substr(trim(metin($_POST['belge_no'] ?? '')), 0, 100);
$belgeNo     = $belgeNo !== '' ? $belgeNo : null;
$belgeTarihi = dateStr('belge_tarihi', $_POST);
$bitisTarihi = dateStr('bitis_tarihi', $_POST);
$arsiv       = !empty($_POST['arsiv']) ? 1 : 0;

$turModul   = match($tur) { 'arac' => 'arac', 'manlift' => 'manlift', default => 'personel' };
$belgeTablo = match($tur) { 'arac' => 'arac_belgeler', 'manlift' => 'manlift_belgeler', default => 'personel_belgeler' };
$sahipKolon = match($tur) { 'arac' => 'arac_id', 'manlift' => 'manlift_id', default => 'personel_id' };

$db = getDb();
$eskiBelge = null;
$duzenleId = 0;

if ($duzenle) {
    $duzenleId = (int)($_POST['id'] ?? 0);
    if (!in_array($tur, ['personel', 'arac', 'manlift'], true) || $duzenleId < 1) {
        jsonError('Geçersiz istek: tur ve id zorunludur.');
    }
    requirePermission($turModul);
    foreach (['kategori', 'belge_adi', 'belge_no', 'belge_tarihi', 'bitis_tarihi', 'arsiv'] as $_alan) {
        if (!array_key_exists($_alan, $_POST)) jsonError('Eksik alan: ' . $_alan . '. Düzenleme formu bütün alanları göndermelidir.');
    }
    if ($kategori === '' || $belgeAdi === '') jsonError('Kategori ve belge adı boş bırakılamaz.');
    $eskiBelge = logKayitAl($db, $belgeTablo, $duzenleId);
    if (!$eskiBelge) jsonError('Belge bulunamadı.', 404);
    $sahipId = (int)$eskiBelge[$sahipKolon];
    if ($entityId > 0 && $entityId !== $sahipId) jsonError('Bu belge seçili kayda ait değil.');
    $entityId = $sahipId;
} elseif (!in_array($tur, ['personel', 'arac', 'manlift', 'izin'], true) || $entityId < 1 || $belgeAdi === '') {
    jsonError('Eksik alanlar: tur, entity_id, belge_adi zorunludur.');
}

if ($belgeTarihi && $bitisTarihi && $belgeTarihi > $bitisTarihi) {
    jsonError('Belge tarihi geçerlilik bitiş tarihinden sonra olamaz.');
}

$_oturumPersonelId = (int)($_SESSION['personel_id'] ?? 0);
$_kendiBelgesi = (!$duzenle && $tur === 'personel' && $entityId === $_oturumPersonelId && $_oturumPersonelId > 0);
$_kendiIzni = false;
if (!$duzenle && $tur === 'izin' && $_oturumPersonelId > 0 && !hasPermission('personel')) {
    $_ki = getDb()->prepare("SELECT personel_id, durum FROM izin_talepleri WHERE id = ?");
    $_ki->execute([$entityId]);
    $_kiSatir = $_ki->fetch();
    $_kendiIzni = $_kiSatir && (int)$_kiSatir['personel_id'] === $_oturumPersonelId && $_kiSatir['durum'] === 'bekliyor';
}
if (!$duzenle && !$_kendiBelgesi && !$_kendiIzni) requirePermission($turModul);
$_belgeOnayDurumu = ($_kendiBelgesi && !hasPermission('personel')) ? 'bekliyor' : 'onaylandi';

$incoming = $_FILES['dosya'] ?? null;
$files = [];
if ($incoming && isset($incoming['name'])) {
    if (is_array($incoming['name'])) {
        $count = count($incoming['name']);
        for ($i = 0; $i < $count; $i++) {
            $files[] = [
                'name'     => $incoming['name'][$i],
                'type'     => $incoming['type'][$i] ?? '',
                'tmp_name' => $incoming['tmp_name'][$i] ?? '',
                'error'    => $incoming['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size'     => $incoming['size'][$i] ?? 0,
            ];
        }
    } else {
        $files[] = $incoming;
    }
}

$files = array_values(array_filter($files, fn($f) => ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));

$dirTur = ($tur === 'izin') ? 'izin' : $tur;

$saveFile = function (array $file) use ($dirTur, $entityId): string {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        jsonError('Dosya yükleme hatası: ' . $file['error']);
    }
    if ($file['size'] > MAX_FILE_SIZE) {
        jsonError('Dosya boyutu 20 MB sınırını aşıyor: ' . $file['name']);
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mimeType, ALLOWED_MIME, true)) {
        jsonError('İzin verilmeyen dosya türü (' . $file['name'] . '). Sadece PDF ve resim dosyaları kabul edilir.');
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXT, true)) {
        jsonError('Geçersiz dosya uzantısı: ' . $file['name']);
    }

    $dir = UPLOAD_PATH . $dirTur . '/' . $entityId . '/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        jsonError('Klasör oluşturulamadı.', 500);
    }

    $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
    $destPath = $dir . $safeName;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        jsonError('Dosya kaydedilemedi.', 500);
    }
    @chmod($destPath, 0644);

    return $dirTur . '/' . $entityId . '/' . $safeName;
};

if ($duzenle) {
    if (count($files) > 1) jsonError('Düzenlemede yalnız bir dosya seçilebilir; birden fazla dosya için "Belge Ekle" kullanın.');
    $yeniYol = $files ? $saveFile($files[0]) : null;
    $set = 'kategori=?, belge_adi=?, belge_no=?, belge_tarihi=?, bitis_tarihi=?, arsiv=?';
    $prm = [$kategori, $belgeAdi, $belgeNo, $belgeTarihi, $bitisTarihi, $arsiv];
    if ($yeniYol !== null) { $set .= ', dosya_yolu=?'; $prm[] = $yeniYol; }
    $prm[] = $duzenleId;
    $db->prepare("UPDATE $belgeTablo SET $set WHERE id=?")->execute($prm);
    if ($yeniYol !== null && !empty($eskiBelge['dosya_yolu']) && $eskiBelge['dosya_yolu'] !== $yeniYol) {
        belgeDosyasiSil($eskiBelge['dosya_yolu']);
    }
    $yeniBelge = logKayitAl($db, $belgeTablo, $duzenleId);
    logla($db, $belgeTablo, $duzenleId, 'guncelle', $eskiBelge, $yeniBelge,
          'Belge düzenlendi: ' . $belgeAdi . ' (' . $tur . ' #' . $entityId . ')' . ($yeniYol !== null ? ' · dosya değiştirildi' : ''));
    jsonOk([
        'id'          => $duzenleId,
        'dosya_yolu'  => $yeniBelge['dosya_yolu'] ?? null,
        'onay_durumu' => $yeniBelge['onay_durumu'] ?? null,
    ], $yeniYol !== null ? 'Belge ve dosyası güncellendi.' : 'Belge güncellendi.');
}

if ($tur === 'izin' && count($files) > 1) jsonError('İzin evrakı için tek dosya seçin.');

$storedPaths = array_map($saveFile, $files);
if (empty($storedPaths)) {
    $storedPaths = [null];
}

$insertedIds = [];

switch ($tur) {
    case 'personel':
        $stmt = $db->prepare("
            INSERT INTO personel_belgeler (personel_id, kategori, belge_adi, belge_no, belge_tarihi, dosya_yolu, bitis_tarihi, arsiv, onay_durumu)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($storedPaths as $sp) {
            $stmt->execute([$entityId, $kategori, $belgeAdi, $belgeNo, $belgeTarihi, $sp, $bitisTarihi, $arsiv, $_belgeOnayDurumu]);
            $insertedIds[] = (int)$db->lastInsertId();
        }
        break;

    case 'arac':
        $stmt = $db->prepare("
            INSERT INTO arac_belgeler (arac_id, kategori, belge_adi, belge_no, belge_tarihi, dosya_yolu, bitis_tarihi, arsiv)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($storedPaths as $sp) {
            $stmt->execute([$entityId, $kategori, $belgeAdi, $belgeNo, $belgeTarihi, $sp, $bitisTarihi, $arsiv]);
            $insertedIds[] = (int)$db->lastInsertId();
        }
        break;

    case 'manlift':
        $stmt = $db->prepare("
            INSERT INTO manlift_belgeler (manlift_id, kategori, belge_adi, belge_no, belge_tarihi, dosya_yolu, bitis_tarihi, arsiv)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($storedPaths as $sp) {
            $stmt->execute([$entityId, $kategori, $belgeAdi, $belgeNo, $belgeTarihi, $sp, $bitisTarihi, $arsiv]);
            $insertedIds[] = (int)$db->lastInsertId();
        }
        break;

    case 'izin':
        $firstPath = $storedPaths[0] ?? null;
        if ($firstPath) {
            $_izEski = logKayitAl($db, 'izin_talepleri', $entityId);
            if (!$_izEski) { belgeDosyasiSil($firstPath); jsonError('İzin kaydı bulunamadı.', 404); }
            $db->prepare("UPDATE izin_talepleri SET belge_yolu=?, belge_adi=?, belge_no=?, belge_tarihi=? WHERE id=?")
               ->execute([$firstPath, $belgeAdi, $belgeNo, $belgeTarihi, $entityId]);
            if (!empty($_izEski['belge_yolu']) && $_izEski['belge_yolu'] !== $firstPath) belgeDosyasiSil($_izEski['belge_yolu']);
            logla($db, 'izin_talepleri', $entityId, 'guncelle', $_izEski, logKayitAl($db, 'izin_talepleri', $entityId),
                  'İzin evrakı yüklendi: ' . $belgeAdi . ($belgeNo !== null ? ' · belge no ' . $belgeNo : '')
                  . ($_kendiIzni ? ' (personel kendi talebine ekledi)' : '')
                  . (!empty($_izEski['belge_yolu']) ? ' · önceki evrakın yerine geçti' : ''));
        }
        break;
}

foreach ($insertedIds as $_bid) {
    logla($db, $tur . '_belgeler', $_bid, 'ekle', null, null,
          'Belge yüklendi: ' . ($belgeAdi ?: $kategori) . ' (' . $tur . ' #' . $entityId . ')');
}

$_belgeMesaj = count($files) > 1 ? count($files) . ' belge yüklendi.' : 'Belge yüklendi.';
if (($_belgeOnayDurumu ?? 'onaylandi') === 'bekliyor') $_belgeMesaj .= ' Yetkili onayı bekliyor.';

jsonOk([
    'ids'         => $insertedIds,
    'id'          => $insertedIds[0] ?? (int)$db->lastInsertId(),
    'dosya_yolu'  => $storedPaths[0] ?? null,
    'dosya_yollari' => $storedPaths,
    'adet'        => count($files),
    'onay_durumu' => $_belgeOnayDurumu ?? 'onaylandi',
], $_belgeMesaj);
