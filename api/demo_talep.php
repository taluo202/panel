<?php
declare(strict_types=1);


header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function demoYanit(int $kod, array $govde): void {
    http_response_code($kod);
    echo json_encode($govde, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    demoYanit(405, ['ok' => false, 'hata' => 'Yalnızca POST istekleri kabul edilir.']);
}

$raw  = (string)file_get_contents('php://input', false, null, 0, 20000);
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;

function demoTemizle($v, int $max = 200): string {
    if (!is_string($v)) return '';
    $v = trim(strip_tags($v));
    $v = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v) ?? '';
    return mb_substr($v, 0, $max);
}

if (demoTemizle($data['web'] ?? '') !== '') {
    demoYanit(200, ['ok' => true, 'mesaj' => 'Talebiniz alındı.']);
}

$adSoyad    = demoTemizle($data['ad_soyad'] ?? $data['ad'] ?? '', 120);
$firma      = demoTemizle($data['firma'] ?? $data['fi'] ?? '', 160);
$eposta     = filter_var(demoTemizle($data['eposta'] ?? $data['em'] ?? '', 160), FILTER_VALIDATE_EMAIL);
$telefon    = demoTemizle($data['telefon'] ?? $data['tl'] ?? '', 40);
$makineSayi = demoTemizle($data['makine_sayisi'] ?? $data['sz'] ?? '', 60);
$konu       = demoTemizle($data['konu'] ?? '14 Gün Ücretsiz Deneme / Demo', 160);
$mesaj      = demoTemizle($data['mesaj'] ?? '', 3000);
$kaynak     = demoTemizle($data['kaynak'] ?? 'tanitim-web', 60);
$en         = ($data['dil'] ?? '') === 'en';

if ($adSoyad === '' || !$eposta) {
    demoYanit(400, ['ok' => false, 'hata' => $en ? 'Please enter your full name and a valid email address.' : 'Lütfen ad soyad ve geçerli bir e-posta adresi giriniz.']);
}

$ip     = $_SERVER['REMOTE_ADDR'] ?? '';
$dbDir  = dirname(__DIR__) . '/db';
$dosya  = $dbDir . '/demo_talepleri.json';
if (!is_dir($dbDir)) @mkdir($dbDir, 0775, true);

$fh = @fopen($dosya, 'c+');
if (!$fh || !flock($fh, LOCK_EX)) {
    demoYanit(500, ['ok' => false, 'hata' => $en ? 'Your request could not be saved.' : 'Talep kaydedilemedi.']);
}
$mevcut = json_decode((string)stream_get_contents($fh), true);
if (!is_array($mevcut)) $mevcut = [];

$birSaatOnce = date('Y-m-d H:i:s', time() - 3600);
$sonSaat = 0;
foreach ($mevcut as $k) {
    if (($k['ip'] ?? '') === $ip && ($k['tarih'] ?? '') >= $birSaatOnce) $sonSaat++;
}
if ($sonSaat >= 5) {
    flock($fh, LOCK_UN); fclose($fh);
    demoYanit(429, ['ok' => false, 'hata' => $en ? 'Too many requests. Please try again later.' : 'Çok fazla talep gönderildi. Lütfen daha sonra tekrar deneyin.']);
}

$kayit = [
    'id'            => 'DEMO-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)),
    'tarih'         => date('Y-m-d H:i:s'),
    'ad_soyad'      => $adSoyad,
    'firma'         => $firma,
    'eposta'        => $eposta,
    'telefon'       => $telefon,
    'makine_sayisi' => $makineSayi,
    'konu'          => $konu,
    'mesaj'         => $mesaj,
    'kaynak'        => $kaynak,
    'ip'            => $ip,
    'durum'         => 'yeni',
];
array_unshift($mevcut, $kayit);
$mevcut = array_slice($mevcut, 0, 500);

$json = json_encode($mevcut, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
$yazildi = $json !== false && ftruncate($fh, 0) && rewind($fh) && fwrite($fh, $json) === strlen($json);
fflush($fh); flock($fh, LOCK_UN); fclose($fh);
if (!$yazildi) {
    demoYanit(500, ['ok' => false, 'hata' => $en ? 'Your request could not be saved.' : 'Talep kaydedilemedi.']);
}

try {
    if (is_file(__DIR__ . '/eposta.php')) {
        require_once __DIR__ . '/config.php';
        require_once __DIR__ . '/eposta.php';
        $db = getDb();
        if (function_exists('epostaGonder')) {
            $govde = "Tanıtım sitesinden yeni talep:\n\n"
                . "Ad Soyad: {$adSoyad}\nFirma: {$firma}\nE-posta: {$eposta}\n"
                . "Telefon: {$telefon}\nMakine Sayısı: {$makineSayi}\nKonu: {$konu}\n"
                . "Mesaj: {$mesaj}\nKaynak: {$kaynak}\nTarih: " . date('d.m.Y H:i');
            @epostaGonder($db, 'help@talootech.com', "[Taloo Talep] {$firma} - {$adSoyad}", $govde, 'demo_talep');
        }
    }
} catch (\Throwable $e) {
    error_log('Taloo demo_talep: bildirim e-postasi gonderilemedi: ' . $e->getMessage());
}

demoYanit(200, [
    'ok'    => true,
    'mesaj' => $en
        ? "Thank you {$adSoyad}. Your request has been received; our team will contact you at {$eposta} shortly."
        : "Teşekkürler {$adSoyad}. Talebiniz alındı; ekibimiz en kısa sürede {$eposta} adresinden sizinle iletişime geçecek.",
    'id'    => $kayit['id'],
]);
