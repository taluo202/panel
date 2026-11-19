<?php
declare(strict_types=1);


require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/kod-kapisi-lib.php';
require_once __DIR__ . '/api/musteri-erisim-lib.php';
require_once __DIR__ . '/api/operasyon-adim.php';
require_once __DIR__ . '/api/varlik-damga-lib.php';

function kapiDb(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . _resolveDbPath());
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        kkSema($pdo);
    }
    return $pdo;
}

function kapiRet(string $js): void {
    try {
        $ip = istekIp();
        $r = kkRetKaydet(kapiDb(), $ip, $js, time());
        if ($r['alarm']) {
            guvenlikLog('kod_kazima', null, null, ['ip' => $ip, 'farkli_dosya' => $r['farkli'], 'son_dosya' => $js]);
            guvenlikAlarmi('Kod kazıma girişimi: bir IP kısa sürede çok sayıda kod dosyası istedi ve 1 saat engellendi.',
                ['IP' => $ip, 'Farklı dosya (10 dk)' => (string)$r['farkli'], 'Son dosya' => $js]);
        }
    } catch (\Throwable $e) {
        error_log('kapi ret kaydi yazilamadi: ' . $e->getMessage());
    }
}

const ACIK_SAYFALAR = [
    'login.html', 'sifre-sifirla.html', 'kurulum.html',
    'kiralama.html', 'kiralama-urun.html',
    'portal-giris.html', 'portal-sifremi-unuttum.html', 'portal-davet-kabul.html',
    'portal.html', 'portal-sihirbaz.html',
    'hasar-dosyasi-goster.html', 'tutanak-goster.html', 'protokol-imza.html',
];

const ACIK_JS = [
    'pwa.js', 'eylem.js', 'app.js', 'belge.js',
    'aksesuar-sayim.js', 'tutanak-sablon.js', 'hasar-dosya-sablon.js',
    'saha-teslim.js', 'referans-foto.js',
    's-kiralama.js',
    'portal-yonlendir.js',
    's-login.js',
    's-sifre-sifirla.js',
    's-kurulum.js',
    's-portal-giris.js',
    's-portal-sifremi-unuttum.js',
    's-portal-davet-kabul.js',
    's-portal-sihirbaz.js',
    's-protokol-imza.js',
    's-tutanak-goster.js',
    's-hasar-dosyasi-goster.js',
    's-kiralama-urun.js',
];
const PORTAL_JS = ['portal.js', 'belge-sablon.js'];

if (isset($_GET['j'])) {
    $js = (string)$_GET['j'];
    if (!preg_match('/^[A-Za-z0-9._-]+\.js$/', $js) || str_contains($js, '..')) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Geçersiz dosya.');
    }
    $jsYol = __DIR__ . '/js/' . $js;
    if (!is_file($jsYol)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Dosya bulunamadı.');
    }
    if (session_status() === PHP_SESSION_NONE) session_start(['read_and_close' => true]);
    $girisli = !empty($_SESSION['user_id']) && oturumGecerli();
    if (!$girisli) {
        try { $engelli = kkEngelli(kapiDb(), istekIp(), time()); } catch (\Throwable $e) { $engelli = false; }
        if ($engelli) {
            http_response_code(429);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
            header('Retry-After: ' . KK_ENGEL_SURE);
            exit('Çok fazla istek.');
        }
    }
    $izin = $girisli
        || (in_array($js, PORTAL_JS, true) && !empty($_SESSION['portal_kontak_id']))
        || (in_array($js, ACIK_JS, true) && kkIzinli((array)($_SESSION['kk_acik'] ?? []), $js, time()));
    if ($izin && preg_match('/^s-([A-Za-z0-9._-]+)\.js$/', $js, $_sm)) {
        $_sSayfa = $_sm[1] . '.html';
        if (!is_file(__DIR__ . '/' . $_sSayfa)) {
            $izin = false;
        } elseif ($girisli && !in_array($_sSayfa, ACIK_SAYFALAR, true)) {
            $izin = navSayfaIzinli(['id' => (int)($_SESSION['user_id'] ?? 0), 'rol' => $_SESSION['rol'] ?? '', 'yetkiler' => $_SESSION['yetkiler'] ?? ''], $_sSayfa);
        }
    }
    if (!$izin) {
        kapiRet($js);
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        exit('Oturum gerekli.');
    }
    $iz = '';
    try {
        $iz = $girisli ? kkIz(kkIzAnahtar(kapiDb()), 'u:' . (int)$_SESSION['user_id'], date('Y-m-d'))
                       : (string)($_SESSION['kk_iz'] ?? '');
    } catch (\Throwable $e) { error_log('kopya izi uretilemedi: ' . $e->getMessage()); }
    $mtime = (int)filemtime($jsYol);
    $etag  = '"' . dechex($mtime) . '-' . dechex((int)filesize($jsYol)) . ($iz !== '' ? '-' . $iz : '') . '"';
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: private, max-age=604800');
    header('Vary: Cookie');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('ETag: ' . $etag);
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    $inm = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    $ims = strtotime((string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '')) ?: 0;
    if (($inm !== '' && $inm === $etag) || ($inm === '' && $iz === '' && $ims >= $mtime)) {
        http_response_code(304);
        exit;
    }
    $kod = (string)file_get_contents($jsYol);
    if ($iz !== '') $kod = kkIzli($kod, $iz, (int)date('Y'));
    header('Content-Length: ' . strlen($kod));
    echo $kod;
    exit;
}

$istenen = (string)($_GET['p'] ?? '');

if (!preg_match('/^[A-Za-z0-9._-]+\.html$/', $istenen) || str_contains($istenen, '..')) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Geçersiz sayfa.');
}

$tamYol = __DIR__ . '/' . $istenen;
if (!is_file($tamYol)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Sayfa bulunamadı.');
}

if (session_status() === PHP_SESSION_NONE) session_start();
$_girisli = !empty($_SESSION['user_id']) && oturumGecerli();
if (session_status() === PHP_SESSION_NONE) session_start();
if ($istenen === 'depo-isleri.html' && in_array((string)($_GET['is'] ?? ''), ['hazirlik', 'inceleme'], true)) {
    $_eskiSorgu = $_GET; unset($_eskiSorgu['p'], $_eskiSorgu['is']);
    $_yeni = explode('?', opIsAdresi((string)$_GET['is'], $_eskiSorgu), 2);
    parse_str($_yeni[1], $_GET);
    $istenen = $_yeni[0];
    if ($_girisli) {
        header('Location: ' . $_yeni[0] . '?' . $_yeni[1], true, 302);
        exit;
    }
}
$_bParam = kkBaglantiParam($istenen);
$acikSayfa = in_array($istenen, ACIK_SAYFALAR, true)
    || ($_bParam !== null && !$_girisli && (string)($_GET[$_bParam] ?? '') !== '');
if (!$acikSayfa) {
    if (!$_girisli) {
        $_sorgu = $_GET; unset($_sorgu['p']);
        $hedef = 'login.html?donus=' . rawurlencode($istenen . ($_sorgu ? '?' . http_build_query($_sorgu) : ''));
        header('Location: ' . $hedef, true, 302);
        exit;
    }

    $_k = ['id' => (int)($_SESSION['user_id'] ?? 0), 'rol' => $_SESSION['rol'] ?? '', 'yetkiler' => $_SESSION['yetkiler'] ?? ''];
    if (!navSayfaIzinli($_k, $istenen)) {
        header('Location: index.html', true, 302);
        exit;
    }
}

if ($acikSayfa) {
    $html = (string)file_get_contents($tamYol);
    try { $baglantiOk = kkBaglantiGecerli(kapiDb(), $istenen, (string)($_GET[$_bParam ?? 't'] ?? ''), date('Y-m-d H:i:s')); }
    catch (\Throwable $e) { $baglantiOk = false; }
    $_mTur = ['tutanak-goster.html' => 'tutanak', 'hasar-dosyasi-goster.html' => 'hasar', 'protokol-imza.html' => 'protokol'][$istenen] ?? null;
    $_pHesapsiz = $_mTur === 'protokol' && (string)($_GET['t'] ?? '') !== '' && !$_girisli;
    if ($_mTur === 'protokol') header('Referrer-Policy: no-referrer');
    if ($_pHesapsiz && $baglantiOk) {
        require_once __DIR__ . '/api/kiralama-protokol-lib.php';
        $_pDb = kapiDb();
        if (kpBaglantiSinirda($_pDb, istekIp())) {
            http_response_code(429);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Robots-Tag: noindex, nofollow, noarchive');
            exit('<!doctype html><html lang="tr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
               . '<title>Çok fazla deneme</title><body style="font-family:system-ui,sans-serif;max-width:32rem;margin:15vh auto;padding:0 1rem;color:#1f2937">'
               . '<h1 style="font-size:1.25rem">Bu cihazdan çok fazla geçersiz bağlantı denendi</h1><p>Birkaç dakika sonra yeniden deneyin.</p></body></html>');
        }
        try { kpTokenCoz($_pDb, (string)$_GET['t'], false); }
        catch (\Throwable $e) { kpBaglantiDenemesi($_pDb, istekIp(), false); $baglantiOk = false; }
    }
    if (!$baglantiOk) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        exit('<!doctype html><html lang="tr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<title>Bağlantı geçersiz</title><body style="font-family:system-ui,sans-serif;max-width:32rem;margin:15vh auto;padding:0 1rem;color:#1f2937">'
           . '<h1 style="font-size:1.25rem">Bu bağlantı geçersiz ya da süresi dolmuş</h1>'
           . '<p>Belgeyi görmek için size bağlantıyı gönderen firmadan yeni bir bağlantı isteyin.</p></body></html>');
    }
    if ($_mTur !== null && !$_pHesapsiz) {
        $_mKayit = $_mTur === 'protokol' && (string)($_GET['t'] ?? '') === '';
        if ($_mKayit ? (!$_girisli && mePortalMusteri(kapiDb()) === null) : !meBaglantiIzinli(kapiDb(), $_mTur, (string)($_GET['t'] ?? ''))) {
            meErisimReddi(kapiDb(), $istenen . ($_mKayit ? '?k=' . (int)($_GET['k'] ?? 0) : '?t=' . (string)($_GET['t'] ?? '')), true);
        }
    }
    $_SESSION['kk_acik'] = kkIzinVer((array)($_SESSION['kk_acik'] ?? []), kkSayfaKodlari($html, ACIK_JS), time());
    if (!$_girisli) {
        try {
            $kkDb = kapiDb();
            $_SESSION['kk_iz'] = kkIz(kkIzAnahtar($kkDb), 's:' . session_id(), date('Y-m-d'));
            kkIzKaydet($kkDb, $_SESSION['kk_iz'], istekIp(), $istenen, time());
        } catch (\Throwable $e) { error_log('kopya izi kaydedilemedi: ' . $e->getMessage()); }
    }
    session_write_close();
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate, private');
if (!in_array($istenen, ACIK_SAYFALAR, true)) {
    header('X-Robots-Tag: noindex, nofollow, noarchive');
}
echo varliklariDamgala((string)file_get_contents($tamYol), __DIR__);
