<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();

$id  = (int)($_GET['id'] ?? 0);
$tur = $_GET['tur'] ?? '';

if ($id < 1 || !in_array($tur, ['personel', 'arac', 'manlift', 'izin'], true)) {
    http_response_code(400); echo 'Geçersiz istek.'; exit;
}

$turModul = match($tur) { 'arac' => 'arac', 'manlift' => 'manlift', default => 'personel' };
requirePermission($turModul);

$db = getDb();

switch ($tur) {
    case 'personel':
        $stmt = $db->prepare("SELECT belge_adi, dosya_yolu FROM personel_belgeler WHERE id=?");
        break;
    case 'arac':
        $stmt = $db->prepare("SELECT belge_adi, dosya_yolu FROM arac_belgeler WHERE id=?");
        break;
    case 'manlift':
        $stmt = $db->prepare("SELECT belge_adi, dosya_yolu FROM manlift_belgeler WHERE id=?");
        break;
    case 'izin':
        $stmt = $db->prepare("SELECT belge_adi, belge_yolu AS dosya_yolu FROM izin_talepleri WHERE id=?");
        break;
    default:
        http_response_code(400); exit;
}

$stmt->execute([$id]);
$belge = $stmt->fetch();

if (!$belge || !$belge['dosya_yolu']) {
    http_response_code(404); echo 'Belge bulunamadı.'; exit;
}

$ustDizin = str_repeat('.', 2);
$bosBayt  = chr(0);

$rel = ltrim(str_replace('\\', '/', (string)$belge['dosya_yolu']), '/');
if ($rel === ''
    || strpos($rel, $ustDizin) !== false
    || strpos($rel, $bosBayt) !== false
    || preg_match('#^[a-zA-Z]:#', $rel)) {
    http_response_code(403); echo 'Erişim reddedildi.'; exit;
}

$uploadBase = realpath(UPLOAD_PATH);
$fullPath   = realpath(UPLOAD_PATH . $rel);
$sinir = $uploadBase === false ? '' : rtrim($uploadBase, '/\\') . DIRECTORY_SEPARATOR;
if (!$uploadBase || !$fullPath || !is_file($fullPath)
    || ($fullPath !== $uploadBase && strncmp($fullPath, $sinir, strlen($sinir)) !== 0)) {
    http_response_code(404); echo 'Dosya bulunamadı. (Belge kaydı var ama dosya sunucuda yok veya yolu farklı)'; exit;
}

$ext      = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$mimeMap  = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
$mime     = $mimeMap[$ext] ?? 'application/octet-stream';
$safeName = preg_replace('/[^a-zA-Z0-9_\-\.ÇçĞğİıÖöŞşÜü ]/', '_', $belge['belge_adi']) . '.' . $ext;

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $safeName . '"');
header('Content-Length: ' . filesize($fullPath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');

$fp = fopen($fullPath, 'rb');
if ($fp === false) { http_response_code(500); exit; }
while (!feof($fp)) {
    $parca = fread($fp, 8192);
    if ($parca === false) break;
    echo $parca;
}
fclose($fp);
exit;
