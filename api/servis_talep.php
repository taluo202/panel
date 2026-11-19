<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/servis-talep-lib.php';
requireAuth();
header('Cache-Control: no-store');
$db = getDb(); $method = $_SERVER['REQUEST_METHOD']; $action = (string)($_GET['action'] ?? ''); $id = (int)($_GET['id'] ?? 0);
if (!hasPermission('teknik_servis', 'oku')) jsonError('Servis talepleri için teknik servis yetkisi gerekir.', 403);
try {
    if ($method === 'GET') {
        if ($id) { $t = stTalep($db, $id); if (!$t) jsonError('Talep bulunamadı.', 404); if (!stSubeIzinli($t)) jsonError('Bu talep başka bir şubeye ait; erişim yetkiniz yok.', 403); jsonOk($t); }
        if ($action === 'musteri_bilgi') { $mid = (int)($_GET['musteri_id'] ?? 0); if ($mid < 1) jsonError('Müşteri seçin.'); $mq = $db->prepare('SELECT sube FROM musteriler WHERE id=?'); $mq->execute([$mid]); $m = $mq->fetch(PDO::FETCH_ASSOC); if (!$m) jsonError('Müşteri bulunamadı.', 404); subeErisimDogrula($m['sube'] ?? null); jsonOk(stMusteriSecenekleri($db, $mid)); }
        $durum = (string)($_GET['durum'] ?? 'bekliyor');
        jsonOk(['list' => stTalepListe($db, $durum === 'sonuc' ? 'sonuc' : 'bekliyor'), 'bekleyen' => stBekleyenSayi($db), 'yazabilir' => hasPermission('teknik_servis', 'yaz')]);
    }
    if ($method !== 'POST') jsonError('Method Not Allowed', 405);
    if (!hasPermission('teknik_servis', 'yaz')) jsonError('Talep açmak / sonuçlandırmak için teknik servis yazma yetkisi gerekir.', 403);
    verifyCsrf();
    $b = json_decode(file_get_contents('php://input'), true);
    if (!is_array($b)) jsonError('İstek okunamadı.');
    if ($action === 'reddet') {
        $t = stTalep($db, $id); if (!$t) jsonError('Talep bulunamadı.', 404);
        if (!stSubeIzinli($t)) jsonError('Bu talep başka bir şubeye ait; erişim yetkiniz yok.', 403);
        $db->beginTransaction();
        $out = stTalepReddet($db, $id, (string)($b['gerekce'] ?? ''));
        $db->commit();
        jsonOk($out, 'Talep reddedildi; gerekçe müşterinin portalında görünür.');
    }
    $musteriId = (int)($b['musteri_id'] ?? 0);
    if ($musteriId > 0) {
        $mq = $db->prepare('SELECT sube FROM musteriler WHERE id=?'); $mq->execute([$musteriId]);
        $m = $mq->fetch(PDO::FETCH_ASSOC); if (!$m) jsonError('Müşteri bulunamadı.', 404);
        subeErisimDogrula($m['sube'] ?? null);
    }
    $db->beginTransaction();
    $yeni = stTalepAc($db, ['kaynak' => 'telefon', 'musteri_id' => $musteriId, 'kiralama_id' => $b['kiralama_id'] ?? null, 'musteri_makine_id' => $b['musteri_makine_id'] ?? null,
                            'makine_metin' => $b['makine_metin'] ?? null, 'sikayet' => $b['sikayet'] ?? '', 'arayan_ad' => $b['arayan_ad'] ?? null, 'arayan_tel' => $b['arayan_tel'] ?? null]);
    $db->commit();
    jsonOk(['id' => $yeni], 'Telefon talebi kaydedildi; Gelen talepler listesinde.');
} catch (DomainException $e) {
    if ($db->inTransaction()) $db->rollBack();
    jsonError($e->getMessage(), 409);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('Servis talebi: ' . $e->getMessage());
    jsonError('İşlem tamamlanamadı; talep korunuyor. Tekrar deneyin.', 500);
}
