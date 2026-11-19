<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
verifyCsrf();

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') jsonError('Method Not Allowed', 405);

$id  = (int)($_GET['id'] ?? 0);
$tur = $_GET['tur'] ?? '';

if ($id < 1 || !in_array($tur, ['personel', 'arac', 'manlift', 'izin'], true)) {
    jsonError('Geçersiz istek.');
}

$turModul = match($tur) { 'arac' => 'arac', 'manlift' => 'manlift', default => 'personel' };
requirePermission($turModul);

$db = getDb();

switch ($tur) {
    case 'personel':
        $stmt = $db->prepare("SELECT dosya_yolu, belge_adi, kategori, personel_id FROM personel_belgeler WHERE id=?");
        $del  = $db->prepare("DELETE FROM personel_belgeler WHERE id=?");
        break;
    case 'arac':
        $stmt = $db->prepare("SELECT dosya_yolu, belge_adi, kategori, arac_id FROM arac_belgeler WHERE id=?");
        $del  = $db->prepare("DELETE FROM arac_belgeler WHERE id=?");
        break;
    case 'manlift':
        $stmt = $db->prepare("SELECT dosya_yolu, belge_adi, kategori, manlift_id FROM manlift_belgeler WHERE id=?");
        $del  = $db->prepare("DELETE FROM manlift_belgeler WHERE id=?");
        break;
    case 'izin':
        try { izinKaydiSil($db, $id); }
        catch (DomainException $e) { jsonError($e->getMessage(), (int)$e->getCode() === 404 ? 404 : 409); }
        jsonOk(null, 'İzin ve belgesi silindi.');
    default:
        jsonError('Geçersiz tür.');
}

$stmt->execute([$id]);
$belge = $stmt->fetch();
if (!$belge) jsonError('Kayıt bulunamadı.', 404);

$del->execute([$id]);
belgeDosyasiSil($belge['dosya_yolu'] ?? null);

logla($db, $tur . '_belgeler', $id, 'sil', [
    'belge_adi'  => $belge['belge_adi'] ?? null,
    'kategori'   => $belge['kategori'] ?? null,
    'dosya_yolu' => $belge['dosya_yolu'] ?? null,
], null, 'Belge silindi: ' . ($belge['belge_adi'] ?: ($belge['kategori'] ?: '(adsız)'))
   . ' (' . $tur . ' #' . (int)($belge['personel_id'] ?? $belge['arac_id'] ?? $belge['manlift_id'] ?? 0) . ')');

jsonOk(null, 'Belge silindi.');
