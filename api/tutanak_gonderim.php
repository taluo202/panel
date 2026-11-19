<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tutanak-gonderim-lib.php';
require_once __DIR__ . '/musteri-erisim-lib.php';
$db = getDb();
tgSema($db);
$m = $_SERVER['REQUEST_METHOD'];
$hata = function (DomainException $e): void {
    $kod = $e->getCode();
    jsonError($e->getMessage(), in_array($kod, [403, 404, 409, 410], true) ? $kod : 400);
};
$metin = fn($v): string => is_string($v) ? $v : '';

if ($m === 'GET' && isset($_GET['t'])) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!meBaglantiIzinli($db, 'tutanak', $metin($_GET['t']))) {
        if (isset($_GET['f'])) { http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); exit('Giriş gerekli.'); }
        jsonError('Bu belge yalnız müşteri portalı hesabınızla (ya da firmamızın panelinden) açılır.', 403);
    }
    $foto = isset($_GET['f']);
    try { $d = tgTokenCoz($db, $metin($_GET['t']), !$foto); }
    catch (DomainException $e) {
        if ($foto) { http_response_code($e->getCode() === 410 ? 410 : 404); header('Content-Type: text/plain; charset=utf-8'); exit('Bulunamadı.'); }
        $hata($e);
    }
    if ($foto) hbFotoGonder($metin($_GET['f']), hbFotoListesi([$d['belge']]));
    jsonOk($d + ['firma' => _hbAyar($db, ['firma_unvan', 'firma_telefon', 'firma_eposta', 'firma_web', 'firma_adres', 'firma_logo',
                                          'firma_vergi_dairesi', 'firma_vergi_no', 'firma_mersis'])]);
}

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('kiralama_takip');
$tid = (int)($_GET['id'] ?? 0);
$sube = function ($s): void {
    $k = subeKisiti();
    if ($k !== null && trim((string)$s) !== $k) jsonError('Bu kaydı görme yetkiniz yok (başka şube).', 403);
};

if ($m === 'GET' && isset($_GET['pdf'])) {
    $q = $db->prepare('SELECT g.tutanak_id, g.surum, k.sube FROM tutanak_gonderimleri g JOIN kiralamalar k ON k.id=g.kiralama_id WHERE g.id=?');
    $q->execute([(int)$_GET['pdf']]);
    $g = $q->fetch(PDO::FETCH_ASSOC);
    if (!$g) jsonError('Gönderim bulunamadı.', 404);
    $sube($g['sube']);
    try { $ek = tgPdf($db, (int)$g['tutanak_id'], (int)$g['surum']); } catch (DomainException $e) { $hata($e); }
    bpIndir($ek);
}
if ($m === 'GET') {
    $q = $db->prepare('SELECT t.asama, k.sube, k.musteri_id, m.telefon, m.email FROM iade_tutanaklari t JOIN kiralamalar k ON k.id=t.kiralama_id
                       LEFT JOIN musteriler m ON m.id=k.musteri_id WHERE t.id=?');
    $q->execute([$tid]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) jsonError('Tutanak bulunamadı.', 404);
    $sube($r['sube']);
    $engel = null;
    if ($r['asama'] === 'saha_alim') $engel = 'Teslim alma kaydı müşteriye ayrıca gönderilmez; iade tutanağı dönüş kontrolünde oluşunca gider.';
    else { try { tgUygun($db, $tid); } catch (DomainException $e) { $engel = $e->getMessage(); } }
    jsonOk(['gonderimler' => tgGecmis($db, $tid), 'gonderilebilir' => $engel === null, 'engel' => $engel,
            'bicim' => meTeslimBicimi($db, (int)$r['musteri_id']),
            'alici_oneri' => ['telefon' => ($r['telefon'] ?? null) ?: null, 'eposta' => ($r['email'] ?? null) ?: null],
            'yazabilir' => hasPermission('kiralama_takip', 'yaz')]);
}
if ($m !== 'POST') jsonError('Method Not Allowed', 405);
verifyCsrf();
if (!hasPermission('kiralama_takip', 'yaz')) jsonError('Tutanak göndermek için kiralama yazma yetkisi gerekir.', 403);
$b = govdeOku();
$action = $metin($_GET['action'] ?? '');

if ($action === 'iptal') {
    $q = $db->prepare('SELECT g.id, k.sube FROM tutanak_gonderimleri g JOIN kiralamalar k ON k.id=g.kiralama_id WHERE g.id=?');
    $q->execute([(int)($_GET['gonderim_id'] ?? 0)]);
    $g = $q->fetch(PDO::FETCH_ASSOC);
    if (!$g) jsonError('Gönderim bulunamadı.', 404);
    $sube($g['sube']);
    tgIptal($db, (int)$g['id']);
    jsonOk(null, 'Bağlantı iptal edildi; müşteri artık açamaz.');
}
if ($action === 'gonder') {
    $q = $db->prepare('SELECT k.sube FROM iade_tutanaklari t JOIN kiralamalar k ON k.id=t.kiralama_id WHERE t.id=?');
    $q->execute([$tid]);
    $s = $q->fetchColumn();
    if ($s === false) jsonError('Tutanak bulunamadı.', 404);
    $sube($s);
    try { $r = tgElleGonder($db, $tid, $metin($b['kanal'] ?? ''), $metin($b['alici'] ?? ''), (string)($_SESSION['username'] ?? 'Ofis')); }
    catch (DomainException $e) { $hata($e); }
    jsonOk($r, $r['mesaj']);
}
jsonError('Geçersiz işlem.', 400);
