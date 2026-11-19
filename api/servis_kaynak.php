<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/servis-kaynak-lib.php';
require_once __DIR__ . '/servis-talep-lib.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth(); yazmaIstegiCsrf();
requirePermission('teknik_servis');
subeMerkezi('teknik_servis');
$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$action = (string)($_GET['action'] ?? '');
$id = (int)($_GET['id'] ?? 0);

try {
    if ($method === 'GET' && $action === 'makine_kiralamalari') {
        if ($id > 0) jsonOk(skServisKiralamalari($db, $id));
        $tur = in_array($_GET['makine_tur'] ?? '', ['manlift', 'arac'], true) ? (string)$_GET['makine_tur'] : null;
        $tarih = dateStr('tarih', $_GET) ?: date('Y-m-d');
        jsonOk(skMakineKiralamalari($db, $tur, (int)($_GET['makine_id'] ?? 0) ?: null, $tarih));
    }
    if ($method === 'GET' && $action === 'aktif_kiralamalar') {
        jsonOk(['tarih' => date('Y-m-d'), 'gruplar' => skAktifKiralamalar($db, date('Y-m-d'))]);
    }
    if ($method === 'PUT' && $action === 'bagla') {
        requirePermission('teknik_servis', 'yaz');
        verifyCsrf();
        $b = govdeOku();
        $db->beginTransaction();
        $r = skBagla($db, $id, (int)($b['kiralama_id'] ?? 0));
        $db->commit();
        jsonOk($r, $r['degisti'] ? 'Servis kiralamaya bağlandı.' : 'Servis zaten bu kiralamaya bağlı.');
    }
    if ($method === 'POST' && $action === 'sahaya_ac') {
        requirePermission('teknik_servis', 'yaz');
        verifyCsrf();
        $b = govdeOku();
        $db->beginTransaction();
        $r = skKiralamadanSahaAc($db, is_array($b) ? $b : []);
        if (is_array($b) && (int)($b['talep_id'] ?? 0) > 0 && !empty($r['idler'])) stTalepBagla($db, (int)$b['talep_id'], (int)$r['idler'][0]);
        $db->commit();
        $n = count($r['idler']);
        jsonOk($r, $n === 1 ? 'Saha servisi açıldı.' : $n . ' makine için saha servisi açıldı.');
    }
    jsonError('Geçersiz işlem.', 400);
} catch (DomainException $e) {
    if ($db->inTransaction()) $db->rollBack();
    jsonError($e->getMessage(), 409);
}
