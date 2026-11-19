<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/servis-bedel-lib.php';
header('Cache-Control: no-store');
if (session_status() === PHP_SESSION_NONE) session_start();
$db = getDb();
$m = $_SERVER['REQUEST_METHOD'];
$hata = function (Throwable $e): void {
    $kod = (int)$e->getCode();
    if ($kod === 404 && empty($_SESSION['user_id'])) jsonError('Belge bulunamadı.', 404);
    jsonError($e->getMessage(), in_array($kod, [400, 403, 404, 409], true) ? $kod : 400);
};
$pdfVer = function (callable $uret): void {
    try { $ek = $uret(); }
    catch (DomainException $e) { throw $e; }
    catch (Throwable $e) { error_log('servis_bedel pdf: ' . $e->getMessage()); jsonError('PDF hazırlanamadı.', 500); }
    bpIndir($ek);
};

if ($m === 'GET' && isset($_GET['id']) && !isset($_GET['action'])) {
    if (!empty($_SESSION['user_id'])) requireAuth();
    try {
        $r = sbKayit($db, (int)$_GET['id']);
        sbErisimDogrula($db, $r);
        $b = sbBelge($db, $r);
        $pdfVer(fn() => sbPdf($db, $b));
    } catch (DomainException $e) { $hata($e); }
}

requireAuth(); yazmaIstegiCsrf();
if (!hasPermission('kiralama_takip') && !smFiyatYetkisi($db)) jsonError('Servis fiyatlandırma yetkiniz yok.', 403);
try {
    if ($m === 'GET' && isset($_GET['gonderim'])) {
        $g = sbGonderimGetir($db, (int)$_GET['gonderim']);
        sbErisimDogrula($db, sbKayit($db, (int)$g['servis_id']));
        $pdfVer(fn() => sbGonderimPdf($db, (int)$g['id']));
    }
    if ($m === 'GET' && ($_GET['action'] ?? '') === 'bilgi') {
        $r = sbKayit($db, (int)($_GET['id'] ?? 0));
        sbErisimDogrula($db, $r);
        jsonOk(sbBilgi($db, $r));
    }
    if (($_GET['action'] ?? '') === 'indirim') {
        $sid = (int)($_GET['id'] ?? 0);
        $r = sbKayit($db, $sid);
        require_once __DIR__ . '/saha-servis-lib.php';
        try { ssFiyatSubeDogrula($db, $sid); } catch (DomainException $e) { jsonError('Bu servis başka bir şubeye ait; erişim yetkiniz yok.', 403); }
        if (!smFiyatYetkisi($db) && !hasPermission('kiralama_takip', 'yaz')) jsonError('Kira indirimi için fiyatlandırma ya da kiralama yazma yetkisi gerekir.', 403);
        require_once __DIR__ . '/ariza-karar-lib.php';
        if ($m === 'GET') jsonOk(akServisIndirimVeri($db, $sid));
        if ($m !== 'PUT') jsonError('Method Not Allowed', 405);
        verifyCsrf();
        if (!hasPermission('kiralama_takip', 'yaz')) jsonError('Kira indirimi kararı için kiralama yazma yetkisi gerekir.', 403);
        $govde = json_decode(file_get_contents('php://input'), true);
        if (!is_array($govde)) jsonError('İstek okunamadı.');
        $db->beginTransaction();
        try { $o = akServisIndirimKarar($db, $sid, $govde); $db->commit(); }
        catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
        jsonOk($o, $o['mesaj']);
    }
    if ($m !== 'POST' || ($_GET['action'] ?? '') !== 'gonder') jsonError('Method Not Allowed', 405);
    verifyCsrf();
    if (!smFiyatYetkisi($db)) jsonError('Servis fiyatlandırma yetkiniz yok.', 403);
    $govde = json_decode(file_get_contents('php://input'), true);
    if (!is_array($govde)) jsonError('İstek okunamadı.');
    $r = sbKayit($db, (int)($_GET['id'] ?? 0));
    sbErisimDogrula($db, $r);
    $o = sbGonder($db, $r, is_string($govde['kanal'] ?? null) ? $govde['kanal'] : '', is_string($govde['alici'] ?? null) ? $govde['alici'] : '', (string)($_SESSION['username'] ?? 'Ofis'));
    $d = $o['denetim']; unset($o['denetim']);
    logla($db, 'teknik_servis', (int)$r['id'], 'guncelle', null, $d['yeni'], $d['ozet']);
    jsonOk($o, $o['mesaj']);
} catch (DomainException $e) {
    $hata($e);
}
