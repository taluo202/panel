<?php
declare(strict_types=1);



require_once __DIR__ . '/config.php';

const HATA_KAYDI_AZAMI_BAYT   = 6144;
const HATA_KAYDI_OTURUM_SINIR = 30;
const HATA_KAYDI_DOSYA_SINIR  = 512 * 1024;

function tarayiciHataDosyasi(): string {
    $ozel = getenv('TALOO_TARAYICI_HATA');
    return is_string($ozel) && $ozel !== '' ? $ozel : dirname(DB_PATH) . '/tarayici-hata.log';
}

function hkAdresAdi(string $m): string {
    $m = preg_replace('#(?:https?|file)://[^\s)\'"]*/([^/\s)\'"?:]*)(?:\?[^\s)\'":]*)?#u', '$1', $m) ?? $m;
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $m) ?? '';
}

function hkMetin(mixed $v, int $n): string {
    $v = is_scalar($v) ? (string)$v : '';
    return mb_substr(hataMetniTemizle(hkAdresAdi($v), true), 0, $n);
}

function hkYigin(mixed $v): string {
    $v = is_string($v) ? $v : '';
    $satirlar = [];
    foreach (array_slice(preg_split('/\r?\n/', mb_substr($v, 0, 4000)) ?: [], 0, 8) as $s) {
        $s = trim(hkMetin($s, 200));
        if ($s !== '') $satirlar[] = $s;
    }
    return implode("\n", $satirlar);
}

function hkOku(string $dosya): array {
    $satirlar = [];
    foreach ([$dosya . '.1', $dosya] as $d) {
        if (!@is_file($d)) continue;
        foreach (@file($d, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $s) $satirlar[] = $s;
    }
    return $satirlar;
}

function hkTarayiciGruplari(): array {
    $grup = [];
    foreach (hkOku(tarayiciHataDosyasi()) as $s) {
        $k = json_decode($s, true);
        if (!is_array($k) || !isset($k['m'], $k['t'])) continue;
        $imza = md5(($k['m'] ?? '') . '|' . ($k['d'] ?? '') . '|' . ($k['l'] ?? 0));
        if (!isset($grup[$imza])) {
            $grup[$imza] = ['mesaj' => (string)$k['m'], 'dosya' => (string)($k['d'] ?? ''), 'satir' => (int)($k['l'] ?? 0),
                            'sutun' => (int)($k['c'] ?? 0), 'adet' => 0, 'ilk' => (string)$k['t'], 'son' => (string)$k['t'],
                            'sayfalar' => [], 'kisiler' => [], 'yigin' => '', 'tarayici' => ''];
        }
        $g = &$grup[$imza];
        $g['adet']++;
        if ((string)$k['t'] >= $g['son']) {
            $g['son'] = (string)$k['t'];
            $g['yigin'] = (string)($k['y'] ?? '');
            $g['tarayici'] = (string)($k['ua'] ?? '');
        }
        if ((string)$k['t'] < $g['ilk']) $g['ilk'] = (string)$k['t'];
        foreach (['sayfalar' => 's', 'kisiler' => 'k'] as $hedef => $alan) {
            $v = (string)($k[$alan] ?? '');
            if ($v !== '' && !in_array($v, $g[$hedef], true) && count($g[$hedef]) < 6) $g[$hedef][] = $v;
        }
        unset($g);
    }
    $liste = array_values($grup);
    usort($liste, fn($a, $b) => strcmp($b['son'], $a['son']));
    return array_slice($liste, 0, 200);
}

function hkSunucuGruplari(): array {
    $grup = [];
    foreach (hkOku(hataGunluguDosyasi()) as $s) {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \[([^\]]*)\] (.*)$/u', $s, $m)) continue;
        $imza = md5($m[2] . '|' . $m[3]);
        if (!isset($grup[$imza])) $grup[$imza] = ['baglam' => $m[2], 'mesaj' => mb_substr($m[3], 0, 700), 'adet' => 0, 'ilk' => $m[1], 'son' => $m[1]];
        $grup[$imza]['adet']++;
        if ($m[1] > $grup[$imza]['son']) $grup[$imza]['son'] = $m[1];
        if ($m[1] < $grup[$imza]['ilk']) $grup[$imza]['ilk'] = $m[1];
    }
    $liste = array_values($grup);
    usort($liste, fn($a, $b) => strcmp($b['son'], $a['son']));
    return array_slice($liste, 0, 200);
}

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
$yonetici = (($_SESSION['rol'] ?? '') === 'admin');
$method = $_SERVER['REQUEST_METHOD'] ?? '';
$action = (string)($_GET['action'] ?? '');

if ($method === 'GET') {
    if (!$yonetici) jsonError('Hata günlüğü yalnızca yöneticiye açıktır.', 403);
    $tarayici = hkTarayiciGruplari();
    $sunucu = hkSunucuGruplari();
    $sinir = date('Y-m-d H:i:s', time() - 86400);
    jsonOk([
        'tarayici' => $tarayici,
        'sunucu'   => $sunucu,
        'ozet'     => [
            'tarayici_tur' => count($tarayici),
            'sunucu_tur'   => count($sunucu),
            'tarayici_24'  => count(array_filter($tarayici, fn($g) => $g['son'] >= $sinir)),
            'sunucu_24'    => count(array_filter($sunucu, fn($g) => $g['son'] >= $sinir)),
        ],
    ]);
}

if ($method !== 'POST') jsonError('Method Not Allowed', 405);
verifyCsrf();

if ($action === 'temizle') {
    if (!$yonetici) jsonError('Hata günlüğünü yalnızca yönetici temizleyebilir.', 403);
    $hangi = (string)($_GET['tur'] ?? '');
    $dosya = $hangi === 'sunucu' ? hataGunluguDosyasi() : ($hangi === 'tarayici' ? tarayiciHataDosyasi() : '');
    if ($dosya === '') jsonError('Geçersiz günlük türü.', 400);
    foreach ([$dosya, $dosya . '.1'] as $d) {
        if (@is_file($d) && !@unlink($d)) jsonError('Günlük dosyası silinemedi.', 500);
    }
    guvenlikLog('hata_gunlugu_temizlendi', (string)($_SESSION['username'] ?? '?'), (int)($_SESSION['user_id'] ?? 0), ['tur' => $hangi]);
    jsonOk(null, 'Günlük temizlendi.');
}

$ham = (string)file_get_contents('php://input', false, null, 0, HATA_KAYDI_AZAMI_BAYT + 1);
if (strlen($ham) > HATA_KAYDI_AZAMI_BAYT) jsonError('Kayıt çok büyük.', 413);
$veri = json_decode($ham, true);
if (!is_array($veri)) jsonError('Geçersiz istek gövdesi.', 400);

$simdi = time();
$pencere = $_SESSION['_hata_kaydi'] ?? [0, 0];
if (!is_array($pencere) || count($pencere) !== 2 || $simdi - (int)$pencere[0] > 600) $pencere = [$simdi, 0];
$pencere[1] = (int)$pencere[1] + 1;
$_SESSION['_hata_kaydi'] = $pencere;
if ($pencere[1] > HATA_KAYDI_OTURUM_SINIR) jsonError('Çok fazla hata kaydı.', 429);

$mesaj = hkMetin($veri['mesaj'] ?? '', 400);
if ($mesaj === '') jsonError('Hata metni boş.', 400);

$kayit = [
    't'  => date('Y-m-d H:i:s'),
    'k'  => mb_substr((string)($_SESSION['username'] ?? ''), 0, 60),
    's'  => mb_substr(preg_replace('/[^\w.\-]/u', '', basename((string)parse_url(is_string($veri['sayfa'] ?? null) ? $veri['sayfa'] : '', PHP_URL_PATH))) ?? '', 0, 60),
    'm'  => $mesaj,
    'd'  => hkMetin($veri['dosya'] ?? '', 80),
    'l'  => max(0, min(9999999, (int)($veri['satir'] ?? 0))),
    'c'  => max(0, min(9999999, (int)($veri['sutun'] ?? 0))),
    'y'  => hkYigin($veri['yigin'] ?? ''),
    'ua' => mb_substr(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')) ?? '', 0, 160),
];

$dosya = tarayiciHataDosyasi();
$dizin = dirname($dosya);
if (!@is_dir($dizin) || !@is_writable($dizin)) jsonError('Hata günlüğü yazılamıyor.', 503);
clearstatcache(true, $dosya);
if (@is_file($dosya) && (int)@filesize($dosya) > HATA_KAYDI_DOSYA_SINIR) {
    @unlink($dosya . '.1');
    @rename($dosya, $dosya . '.1');
}
$satir = json_encode($kayit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
if ($satir === false || @file_put_contents($dosya, $satir . "\n", FILE_APPEND | LOCK_EX) === false) jsonError('Hata günlüğü yazılamıyor.', 503);
jsonOk(null, 'Kaydedildi.');
