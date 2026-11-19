<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/zip-basit.php';
require_once __DIR__ . '/yedek-lib.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
if (($_SESSION['rol'] ?? '') !== 'admin') jsonError('Sadece yönetici veri yedeği alabilir.', 403);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method Not Allowed', 405);
verifyCsrf();


$tenantEk = TENANT_SLUG !== '' ? TENANT_SLUG . '-' : '';
$dosyaAdi = 'taloo-yedek-' . $tenantEk . date('Y-m-d-His') . '.zip';

$db = getDb();

$gecici = '';
try {
    $gecici = ozelGeciciDosya('yedek');
    @unlink($gecici);
    $_maske = umask(0077);
    try { $db->exec("VACUUM INTO " . $db->quote($gecici)); } finally { umask($_maske); }
} catch (\Throwable $e) {
    if ($gecici !== '') @unlink($gecici);
    error_log('Taloo yedek: anlik goruntu alinamadi: ' . $e->getMessage());
    jsonError('Veritabanı anlık görüntüsü alınamadı.', 500);
}

$sifreliYedek = (string)($_POST['sifre'] ?? '') !== '';
$sayimlar = [];
try {
    $kopya = new PDO('sqlite:' . $gecici);
    $kopya->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    yedekSirTemizle($kopya, $sifreliYedek);

    foreach ($kopya->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        try { $sayimlar[$t] = (int)$kopya->query("SELECT COUNT(*) FROM \"$t\"")->fetchColumn(); } catch (\Throwable $e) { hataGunlugu('yedek.sayim', $e); }
    }
    $kopya->exec('VACUUM');
    $kopya = null;
} catch (\Throwable $e) {
    @unlink($gecici);
    error_log('Taloo yedek: hazirlanamadi: ' . $e->getMessage());
    jsonError('Yedek hazırlanamadı.', 500);
}

$girdiler = [];
$dosyaAdet = 0; $dosyaBoyut = 0;
$kok = rtrim(UPLOAD_PATH, '/' . chr(92));
if (is_dir($kok)) {
    $gez = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($kok, FilesystemIterator::SKIP_DOTS));
    foreach ($gez as $f) {
        if (!$f->isFile()) continue;
        $goreli = ltrim(str_replace(chr(92), '/', substr($f->getPathname(), strlen($kok))), '/');
        if ($goreli === '' || str_contains($goreli, '..')) continue;
        $girdiler[] = ['ad' => 'dosyalar/' . $goreli, 'yol' => $f->getPathname()];
        $dosyaAdet++;
        $dosyaBoyut += $f->getSize();
    }
}

$bilgi = [
    'bicim'         => 'taloo-yedek-1',
    'olusturulma'   => date('c'),
    'tenant'        => TENANT_SLUG !== '' ? TENANT_SLUG : null,
    'php'           => PHP_VERSION,
    'tablo_sayilari'=> $sayimlar,
    'dosya_adedi'   => $dosyaAdet,
    'dosya_boyut'   => $dosyaBoyut,
    'not'           => 'Personel şifre özetleri, 2FA sırları, API anahtarları, PIN/QR rozetleri ve tek kullanımlık anahtarlar bilerek DIŞARIDA bırakıldı; müşteri portalı şifre özetleri (bcrypt) ve kopya izi anahtarı yedektedir.',
];

$okubeni = "TALOO VERİ YEDEĞİ\r\n"
    . "=================\r\n\r\n"
    . "Oluşturulma: " . date('d.m.Y H:i') . "\r\n"
    . "İçerik:\r\n"
    . "  veritabani/panel.sqlite  → bütün iş verisi (müşteri, kiralama, hakediş, tutanak...)\r\n"
    . "  dosyalar/                → yüklenen belgeler, tutanak fotoğrafları, imzalar (" . $dosyaAdet . " dosya)\r\n"
    . "  YEDEK-BILGI.json         → hangi tabloda kaç kayıt olduğu\r\n\r\n"
    . "GERİ YÜKLEME\r\n"
    . "  Panel > Ayarlar > Veri Yedekleme ekranından bu ZIP dosyasını seçin.\r\n"
    . "  Sistem önce içeriği gösterir, siz onaylamadan HİÇBİR ŞEY değiştirmez.\r\n"
    . "  Geri yükleme sırasında mevcut verinizin otomatik bir emniyet yedeği alınır.\r\n\r\n"
    . "GÜVENLİK\r\n"
    . "  Bu dosya firmanızın bütün ticari verisini içerir. Personel şifrelerini ve API\r\n"
    . "  anahtarlarını içermez (geri yükledikten sonra kullanıcılar mevcut şifreleriyle girmeye\r\n"
    . "  devam eder). Müşteri portalı şifre özetleri (bcrypt) ve kopya izi anahtarı YALNIZ parolayla\r\n"
    . "  şifrelenmiş yedekte bulunur; felaket sonrası yeni sunucu için şifreli yedek alın. Dosyayı gizli tutun.\r\n";

$sifre = (string)($_POST['sifre'] ?? '');
$dbGirdi = ['ad' => 'veritabani/panel.sqlite', 'yol' => $gecici];
if ($sifre !== '') {
    if (mb_strlen($sifre) < 8) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Yedek parolası en az 8 karakter olmalıdır.'], JSON_UNESCAPED_UNICODE);
        @unlink($gecici);
        exit;
    }
    $dbGirdi = ['ad' => 'veritabani/panel.sqlite.enc',
                'veri' => yedekSifrele((string)file_get_contents($gecici), $sifre)];
    $okubeni .= "\r\nBU YEDEK ŞİFRELİDİR\r\n"
        . "  veritabani/panel.sqlite.enc dosyası AES-256 ile şifrelenmiştir.\r\n"
        . "  Geri yüklerken indirme sırasında belirlediğiniz parola sorulacaktır.\r\n"
        . "  PAROLA HİÇBİR YERDE SAKLANMAZ. Unutulursa bu yedek AÇILAMAZ.\r\n";
    $dosyaAdi = str_replace('.zip', '-sifreli.zip', $dosyaAdi);
}

$girdiler = array_merge([
    ['ad' => 'YEDEK-BILGI.json', 'veri' => json_encode($bilgi, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)],
    ['ad' => 'OKUBENI.txt',      'veri' => $okubeni],
    $dbGirdi,
], $girdiler);

guvenlikLog('yedek_indirildi', (string)($_SESSION['username'] ?? '?'), (int)($_SESSION['user_id'] ?? 0), [
    'dosya'       => $dosyaAdi,
    'dosya_adedi' => $dosyaAdet,
    'sifreli'     => $sifre !== '',
]);
guvenlikAlarmi('Tam sistem yedeği indirildi.', [
    'Kullanıcı' => (string)($_SESSION['ad_soyad'] ?? $_SESSION['username'] ?? '?'),
    'IP'        => istekIp(),
]);

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $dosyaAdi . '"');
header('X-Content-Type-Options: nosniff');

$cikti = fopen('php://output', 'wb');
zipYaz($cikti, $girdiler);
fclose($cikti);
@unlink($gecici);
