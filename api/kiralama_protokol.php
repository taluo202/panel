<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/kiralama-protokol-lib.php';
require_once __DIR__ . '/belge-pdf-lib.php';
$db = getDb();
kpSema($db);
header('Cache-Control: no-store');
if (session_status() === PHP_SESSION_NONE) session_start();
$m = $_SERVER['REQUEST_METHOD'];
$metin = fn($v): string => is_string($v) ? $v : '';
$hata = function (Throwable $e): void {
    $kod = (int)$e->getCode();
    jsonError($e->getMessage(), in_array($kod, [403, 404, 409, 410], true) ? $kod : 400);
};
$pdfVer = function (PDO $db, array $p): void {
    try { $ek = kpPdf($db, $p); }
    catch (Throwable $e) { error_log('kiralama_protokol pdf: ' . $e->getMessage()); jsonError('PDF hazırlanamadı.', 500); }
    bpIndir($ek);
};

if (isset($_GET['t']) || isset($_GET['k'])) {
    $t = $metin($_GET['t'] ?? '');
    $g = null;
    $personel = !empty($_SESSION['user_id']);
    header('Referrer-Policy: no-referrer');
    try {
        if ($t !== '') {
            $ip = istekIp();
            if (kpBaglantiSinirda($db, $ip)) jsonError('Bu cihazdan çok fazla geçersiz bağlantı denendi. Birkaç dakika sonra tekrar deneyin.', 429);
            if ($personel && !meBaglantiIzinli($db, 'protokol', $t)) jsonError('Bu belgeyi görme yetkiniz yok.', 403);
            try { $g = kpTokenCoz($db, $t, kpGoruntulenmeSayilir($m, isset($_GET['pdf']))); }
            catch (DomainException $e) { kpBaglantiDenemesi($db, $ip, false); throw $e; }
            $pid = (int)$g['protokol_id'];
        } else {
            $p0 = kpGetir($db, (int)$_GET['k']);
            if (!meBelgeGorur($db, (int)$p0['musteri_id'], $p0['sube'] !== null ? (string)$p0['sube'] : null)) jsonError('Bu belge hesabınıza ait değil.', 403);
            $pid = (int)$p0['id'];
        }
        $d = kpGosterilecek($db, $pid, isset($_GET['guncel']));
    } catch (DomainException $e) { $hata($e); }
    $p = $d['protokol'];
    $portalMusteri = !$personel && mePortalMusteri($db) === (int)$p['musteri_id'];
    $hesapsiz = $g !== null && !$personel && !$portalMusteri;
    if (!$hesapsiz && !meBelgeGorur($db, (int)$p['musteri_id'], $p['sube'] !== null ? (string)$p['sube'] : null)) jsonError('Bu belge hesabınıza ait değil.', 403);
    $baglantiKullanildi = $g !== null && (int)$g['imzalandi'] === 1;
    if ($m === 'GET') {
        if (isset($_GET['pdf'])) $pdfVer($db, $p);
        jsonOk(['protokol' => kpOzetSatir($p), 'html' => kpHtml($p, kpLogo($db), kpKirayaVeren($db, $p)), 'guncel_farkli' => $d['guncel_farkli'],
                'guncellendi' => $g !== null && $p['id'] !== (int)$g['protokol_id'],
                'imzalayabilir' => ($portalMusteri || ($hesapsiz && !$baglantiKullanildi)) && $p['durum'] === 'bekliyor', 'personel' => $personel, 'hesapsiz' => $hesapsiz,
                'son_gecerlilik' => $g['son_gecerlilik'] ?? null]);
    }
    if ($m !== 'POST' || ($_GET['action'] ?? '') !== 'imzala') jsonError('Method Not Allowed', 405);
    verifyCsrf();
    if (!$portalMusteri && !$hesapsiz) jsonError($personel ? 'Personel oturumuyla müşteri adına imza atılamaz. Protokolü müşteriye gönderin ya da sahada teslimde imzalatın.'
                                                           : 'Protokolü size gönderilen bağlantıdan ya da müşteri portalı hesabınızla imzalayabilirsiniz.', 403);
    if ($hesapsiz && $baglantiKullanildi) jsonError('Bu bağlantıyla protokol zaten imzalandı.', 409);
    $b = json_decode(file_get_contents('php://input'), true);
    if (!is_array($b)) jsonError('İstek okunamadı.');
    if (empty($b['onay'])) jsonError('"Kiralama protokolünü okudum, kabul ediyorum" kutusunu işaretleyin.');
    try {
        $r = kpImzalaKapsam($db, (string)$p['kapsam'], $metin($b['ozet'] ?? ''), $metin($b['imza'] ?? ''), $metin($b['ad'] ?? ''), $metin($b['unvan'] ?? ''),
                            $hesapsiz ? 'baglanti' : 'portal', trim(($g ? 'gonderim:' . (int)$g['id'] : '') . ($portalMusteri ? ' portal:' . (int)($_SESSION['portal_kontak_id'] ?? 0) : '')),
                            $hesapsiz ? 'Müşteri (bağlantıyla)' : 'Müşteri portalı');
    } catch (DomainException | InvalidArgumentException $e) { $hata($e); }
    if ($g) $db->prepare('UPDATE kiralama_protokol_gonderimleri SET imzalandi=1 WHERE id=?')->execute([(int)$g['id']]);
    jsonOk(['protokol' => kpOzetSatir($r), 'html' => kpHtml($r, kpLogo($db), kpKirayaVeren($db, $r))], 'Protokol imzalandı. Teşekkür ederiz.');
}

requireAuth();
requirePermission('kiralama_takip');
$yazabilir = hasPermission('kiralama_takip', 'yaz');
$kiralamaKapsami = function (PDO $db, int $kid): array {
    $s = $db->prepare('SELECT k.sube, k.musteri_id, m.telefon, m.email FROM kiralamalar k JOIN musteriler m ON m.id=k.musteri_id WHERE k.id=?');
    $s->execute([$kid]);
    $k = $s->fetch(PDO::FETCH_ASSOC);
    if (!$k) jsonError('Kiralama bulunamadı.', 404);
    subeErisimDogrula($k['sube'] !== null ? (string)$k['sube'] : null);
    return [$k, kpKapsam($db, $kid)];
};

try {
    if ($m === 'GET' && isset($_GET['pdf'])) {
        if ((int)$_GET['pdf'] > 0) {
            $p = kpGetir($db, (int)$_GET['pdf']);
            subeErisimDogrula($p['sube'] !== null ? (string)$p['sube'] : null);
        } else $p = kpCoz($db, $kiralamaKapsami($db, (int)($_GET['kiralama_id'] ?? 0))[1]);
        $pdfVer($db, $p);
    }
    if ($m === 'GET') {
        [$k, $kapsam] = $kiralamaKapsami($db, (int)($_GET['kiralama_id'] ?? 0));
        $p = kpCoz($db, $kapsam);
        $kt = $db->prepare("SELECT telefon, email FROM musteri_kontaklari WHERE musteri_id=? AND durum='aktif' ORDER BY id LIMIT 1");
        $kt->execute([(int)$k['musteri_id']]);
        $kon = $kt->fetch(PDO::FETCH_ASSOC) ?: [];
        jsonOk(['protokol' => kpOzetSatir($p), 'html' => kpHtml($p, kpLogo($db), kpKirayaVeren($db, $p)), 'arsiv' => array_values(array_filter(kpArsiv($db, $kapsam), fn($a) => $a['id'] !== $p['id'])),
                'gonderimler' => kpGecmis($db, $kapsam), 'imza_kanit' => kpImzaKanit($p, $db), 'portal' => mePortalDurum($db, (int)$k['musteri_id']), 'yazabilir' => $yazabilir,
                'alici_oneri' => ['telefon' => ($k['telefon'] ?? null) ?: (($kon['telefon'] ?? null) ?: null), 'eposta' => ($kon['email'] ?? null) ?: (($k['email'] ?? null) ?: null)]]);
    }
    if ($m !== 'POST') jsonError('Method Not Allowed', 405);
    verifyCsrf();
    if (!$yazabilir) jsonError('Protokol göndermek için kiralama yazma yetkisi gerekir.', 403);
    $b = govdeOku();
    $action = $metin($_GET['action'] ?? '');
    if ($action === 'gonder') {
        $kapsam = $kiralamaKapsami($db, (int)($_GET['kiralama_id'] ?? 0))[1];
        $r = kpGonder($db, $kapsam, $metin($b['kanal'] ?? ''), $metin($b['alici'] ?? ''), (string)($_SESSION['username'] ?? 'Ofis'));
        jsonOk($r, $r['mesaj']);
    }
    if ($action === 'iptal') {
        $q = $db->prepare('SELECT g.id, p.sube FROM kiralama_protokol_gonderimleri g JOIN kiralama_protokolleri p ON p.id=g.protokol_id WHERE g.id=?');
        $q->execute([(int)($_GET['gonderim_id'] ?? 0)]);
        $g = $q->fetch(PDO::FETCH_ASSOC);
        if (!$g) jsonError('Gönderim bulunamadı.', 404);
        subeErisimDogrula($g['sube'] !== null ? (string)$g['sube'] : null);
        kpGonderimIptal($db, (int)$g['id']);
        jsonOk(null, 'Bağlantı iptal edildi; müşteri artık açamaz.');
    }
    jsonError('Geçersiz işlem.', 400);
} catch (DomainException | InvalidArgumentException $e) {
    $hata($e);
}
