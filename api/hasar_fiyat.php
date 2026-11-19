<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/hasar-fiyat-lib.php';
requireAuth();
header('Cache-Control: no-store');
if (!hasPermission('kiralama_takip', 'yaz')) jsonError('Fiyatlandırma için kiralama yazma yetkiniz yok.', 403);
$db = getDb(); $method = $_SERVER['REQUEST_METHOD']; $id = (int)($_GET['id'] ?? 0);
try {
    if ($method === 'GET') {
        if (!empty($_GET['liste'])) jsonOk(hfListe($db));
        $r = hdGet($db, $id); if (!$r) jsonError('Hasar kaydı bulunamadı.', 404);
        subeErisimDogrula($r['sube'] ?? null);
        jsonOk(hfVeri($db, $id));
    }
    if ($method !== 'PUT') jsonError('Method Not Allowed', 405);
    verifyCsrf();
    jsonError('Hasar değerlendirme arşivde; yeni fiyat girilmez. Bedeli Teknik Servis › Fiyatlandırılacaklar\'da servis kaydı üzerinden fiyatlandırın.', 409);
} catch (DomainException $e) {
    if ($db->inTransaction()) $db->rollBack();
    jsonError($e->getMessage(), 409);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('Hasar fiyat: ' . $e->getMessage());
    jsonError('Fiyat kaydedilemedi; kayıt korunuyor. Tekrar deneyin.', 500);
}
