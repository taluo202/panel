<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/hasar-belge-lib.php';
require_once __DIR__ . '/musteri-erisim-lib.php';
$db = getDb();
$m = $_SERVER['REQUEST_METHOD'];
$hata = function (DomainException $e): void {
    $kod = $e->getCode();
    jsonError($e->getMessage(), in_array($kod, [403, 404, 409, 410], true) ? $kod : 400);
};

if ($m === 'GET' && isset($_GET['t'])) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!meBaglantiIzinli($db, 'hasar', (string)$_GET['t'])) {
        if (isset($_GET['f'])) { http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); exit('Giriş gerekli.'); }
        jsonError('Bu belge yalnız müşteri portalı hesabınızla (ya da firmamızın panelinden) açılır.', 403);
    }
    $foto = isset($_GET['f']);
    try { $d = hbTokenCoz($db, (string)$_GET['t'], !$foto); }
    catch (DomainException $e) {
        if ($foto) { http_response_code($e->getCode() === 410 ? 410 : 404); header('Content-Type: text/plain; charset=utf-8'); exit('Bulunamadı.'); }
        $hata($e);
    }
    if ($foto) hbFotoGonder((string)$_GET['f'], hbFotoListesi($d['belgeler']));
    jsonOk($d + ['firma' => _hbAyar($db, ['firma_unvan', 'firma_telefon', 'firma_eposta', 'firma_web', 'firma_adres', 'firma_logo',
                                          'firma_vergi_dairesi', 'firma_vergi_no', 'firma_mersis'])]);
}

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('kiralama_takip');
$hid = (int)($_GET['id'] ?? 0);
$subeKontrol = function (array $d): void {
    $s = subeKisiti();
    if ($s !== null && trim((string)$d['sube']) !== $s) jsonError('Bu kaydı görme yetkiniz yok (başka şube).', 403);
};

if ($m === 'GET' && isset($_GET['pdf'])) {
    $q = $db->prepare('SELECT g.id, k.sube FROM hasar_dosya_gonderimleri g JOIN kiralamalar k ON k.id=g.kiralama_id WHERE g.id=?');
    $q->execute([(int)$_GET['pdf']]);
    $g = $q->fetch(PDO::FETCH_ASSOC);
    if (!$g) jsonError('Gönderim bulunamadı.', 404);
    $subeKontrol($g);
    try { $ek = hbPdf($db, (int)$g['id']); } catch (DomainException $e) { $hata($e); }
    bpIndir($ek);
}
if ($m === 'GET' && isset($_GET['kontrol_id'])) {
    try { $d = hbKontrolBelgesi($db, (int)$_GET['kontrol_id']); } catch (DomainException $e) { $hata($e); }
    $subeKontrol($d);
    jsonOk($d);
}
if ($m === 'GET') {
    try { $d = hbDosya($db, $hid); } catch (DomainException $e) { $hata($e); }
    $subeKontrol($d);
    jsonOk($d);
}
if ($m !== 'POST') jsonError('Method Not Allowed', 405);
verifyCsrf();
if (!hasPermission('kiralama_takip', 'yaz')) jsonError('Dosya göndermek için kiralama yazma yetkisi gerekir.', 403);
$b = govdeOku();
$action = (string)($_GET['action'] ?? '');
$gid = (int)($_GET['gonderim_id'] ?? 0);
if ($action === 'iptal') {
    $q = $db->prepare('SELECT degerlendirme_id FROM hasar_dosya_gonderimleri WHERE id=?');
    $q->execute([$gid]);
    $hid = (int)$q->fetchColumn();
    if (!$hid) jsonError('Gönderim bulunamadı.', 404);
}
try { $subeKontrol(hbDosya($db, $hid)); } catch (DomainException $e) { $hata($e); }
$db->beginTransaction();
try {
    if ($action === 'gonder') {
        $r = hbGonder($db, $hid, (string)($b['kanal'] ?? ''), (string)($b['alici'] ?? ''));
        $db->commit();
        jsonOk($r, 'Hasar dosyası gönderimi kaydedildi.');
    }
    if ($action === 'iptal') {
        hbBaglantiIptal($db, $gid);
        $db->commit();
        jsonOk(null, 'Bağlantı iptal edildi.');
    }
    throw new DomainException('Geçersiz işlem.', 400);
} catch (DomainException $e) {
    $db->rollBack();
    $hata($e);
}
