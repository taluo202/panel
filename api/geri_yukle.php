<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/yedek-lib.php';
require_once __DIR__ . '/zip-basit.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
if (($_SESSION['rol'] ?? '') !== 'admin') jsonError('Sadece yönetici geri yükleme yapabilir.', 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method Not Allowed', 405);
verifyCsrf();

function _geriYuklenebilirDosya(string $goreli): bool {
    foreach (explode('/', $goreli) as $parca) {
        if ($parca === '' || !preg_match('/^[A-Za-z0-9_-][A-Za-z0-9_.-]*$/', $parca)) return false;
    }
    $uzanti = strtolower(pathinfo($goreli, PATHINFO_EXTENSION));
    if (in_array($uzanti, ALLOWED_EXT, true)) return true;
    return $uzanti === 'svg' && $goreli === 'firma/logo.svg';
}


const GERI_YUKLENMEYEN = [...YEDEK_DISI_TABLOLAR, 'sqlite_sequence', 'islem_log'];

$aksiyon = $_POST['aksiyon'] ?? 'incele';
if (!in_array($aksiyon, ['incele', 'uygula'], true)) jsonError('Geçersiz aksiyon.');

$f = $_FILES['yedek'] ?? null;
if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) jsonError('Yedek dosyası yüklenemedi.');
$arsiv = (string)$f['tmp_name'];
if (!is_uploaded_file($arsiv)) jsonError('Geçersiz yükleme.');

$icindekiler = zipIcindekiler($arsiv);
if (!$icindekiler) jsonError('Bu dosya okunabilir bir ZIP arşivi değil. Taloo yedeğinin kendisini (taloo-yedek-…zip) seçtiğinizden emin olun.');
if (zipToplamAcikBoyut($icindekiler) > ZIP_TOPLAM_SINIRI) jsonError('Yedek arşivi açıldığında izin verilen boyutu aşıyor; bu bir Taloo yedeği değil ya da arşiv bozuk.', 413);
$dbAdi = isset($icindekiler['veritabani/panel.sqlite.enc'])
    ? 'veritabani/panel.sqlite.enc'
    : 'veritabani/panel.sqlite';
if (!isset($icindekiler[$dbAdi])) {
    jsonError('Arşivde veritabanı bulunamadı. Bu bir Taloo yedeği değil ya da arşiv eksik indirilmiş olabilir.');
}

$bilgiHam = zipCikar($arsiv, 'YEDEK-BILGI.json', $icindekiler);
$bilgi    = $bilgiHam ? (json_decode($bilgiHam, true) ?: []) : [];

$dbHam = zipCikar($arsiv, $dbAdi, $icindekiler);
if ($dbHam === null) jsonError('Arşivdeki veritabanı bozuk (CRC doğrulaması başarısız). Yedeği yeniden indirin.');

if (yedekSifreliMi($dbHam)) {
    $sifre = (string)($_POST['sifre'] ?? '');
    if ($sifre === '') {
        jsonError('Bu yedek şifrelidir. Yedeği indirirken belirlediğiniz parolayı girin.', 400, ['sifre_gerekli' => true]);
    }
    $cozulen = yedekCoz($dbHam, $sifre);
    if ($cozulen === null) {
        jsonError('Parola hatalı ya da yedek dosyası bozulmuş.', 400, ['sifre_gerekli' => true]);
    }
    $dbHam = $cozulen;
    unset($cozulen);
}
try { $gecici = ozelGeciciDosya('geri'); } catch (\Throwable $e) { hataGunlugu('geri_yukle.gecici', $e); jsonError('Yedek geçici dosyaya yazılamadı (sunucu diski dolu olabilir). Hiçbir değişiklik yapılmadı.', 500); }
if (file_put_contents($gecici, $dbHam) === false) {
    @unlink($gecici);
    jsonError('Yedek geçici dosyaya yazılamadı (sunucu diski dolu olabilir). Hiçbir değişiklik yapılmadı.', 500);
}
unset($dbHam);

$temizle = function () use ($gecici) { @unlink($gecici); };

try {
    $yedekDb = new PDO('sqlite:' . $gecici);
    $yedekDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $yedekDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $yedekTablolar = $yedekDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
} catch (\Throwable $e) {
    $temizle();
    error_log('Taloo geri yukleme: arsiv veritabani acilamadi: ' . $e->getMessage());
    jsonError('Arşivdeki veritabanı açılamadı.');
}
if (!$yedekTablolar) { $temizle(); jsonError('Yedekteki veritabanı boş görünüyor.'); }

$db = getDb();
$mevcutTablolar = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);

$ortak         = array_values(array_diff(array_intersect($yedekTablolar, $mevcutTablolar), GERI_YUKLENMEYEN));
$sadeceYedekte = array_values(array_diff($yedekTablolar, $mevcutTablolar, GERI_YUKLENMEYEN));
$sadeceBurada  = array_values(array_diff($mevcutTablolar, $yedekTablolar, GERI_YUKLENMEYEN));

$dosyaGirdileri = array_values(array_filter(array_keys($icindekiler), fn($a) => str_starts_with($a, 'dosyalar/')));

if ($aksiyon === 'incele') {
    $karsilastirma = [];
    foreach ($ortak as $t) {
        $y = 0; $m = 0;
        try { $y = (int)$yedekDb->query('SELECT COUNT(*) FROM "' . $t . '"')->fetchColumn(); } catch (\Throwable $e) { hataGunlugu('geri_yukle.onizleme_sayim', $e); }
        try { $m = (int)$db->query('SELECT COUNT(*) FROM "' . $t . '"')->fetchColumn(); } catch (\Throwable $e) { hataGunlugu('geri_yukle.onizleme_sayim', $e); }
        if ($y === 0 && $m === 0) continue;
        $karsilastirma[] = ['tablo' => $t, 'yedekte' => $y, 'suanda' => $m, 'fark' => $y - $m];
    }
    usort($karsilastirma, fn($a, $b) => abs($b['fark']) <=> abs($a['fark']));
    $temizle();
    jsonOk([
        'bilgi'          => $bilgi,
        'tablolar'       => $karsilastirma,
        'sadece_yedekte' => $sadeceYedekte,
        'sadece_burada'  => $sadeceBurada,
        'dosya_adedi'    => count($dosyaGirdileri),
        'uyari'          => 'Geri yükleme, aşağıdaki tabloların ŞU ANKİ içeriğini siler ve yedektekiyle değiştirir. Onaylamadan önce sayıları karşılaştırın.',
    ]);
}

if (($_POST['onay'] ?? '') !== 'EVET') {
    $temizle();
    jsonError('Geri yükleme onaylanmadı.');
}

$emniyetDizin = dirname(_resolveDbPath());
$emniyet = $emniyetDizin . '/emniyet-' . date('Y-m-d-His') . '.sqlite';
try {
    if (!is_dir($emniyetDizin)) @mkdir($emniyetDizin, 0775, true);
    $db->exec('VACUUM INTO ' . $db->quote($emniyet));
} catch (\Throwable $e) {
    $temizle();
    error_log('Taloo geri yukleme: emniyet yedegi alinamadi: ' . $e->getMessage());
    jsonError('Emniyet yedeği alınamadı, bu yüzden geri yükleme İPTAL edildi.', 500);
}

$eskiler = glob($emniyetDizin . '/emniyet-*.sqlite') ?: [];
if (count($eskiler) > 5) {
    usort($eskiler, fn($a, $b) => filemtime($b) <=> filemtime($a));
    foreach (array_slice($eskiler, 5) as $silinecek) @unlink($silinecek);
}

$yedekDb = null;

try {
    $_mevcut = geriYukleMevcutSirlar($db);
} catch (\Throwable $e) {
    $temizle();
    error_log('Taloo geri yukleme: mevcut sirlar okunamadi: ' . $e->getMessage());
    jsonError('Mevcut şifreler ve anahtarlar okunamadı; geri yükleme BAŞLAMADAN durduruldu, hiçbir değişiklik yapılmadı. Kimsenin girişi etkilenmedi.', 500);
}
$mevcutSifreler  = $_mevcut['sifreler'];
$mevcutTotp      = $_mevcut['totp'];
$mevcutTotpYedek = $_mevcut['totp_yedek'];
$mevcutRozet     = $_mevcut['rozet'];
$mevcutSirlar    = $_mevcut['sirlar'];
$mevcutPortal    = $_mevcut['portal'];
$mevcutKodIz     = $_mevcut['kod_iz'];

$aktarilan = [];
try {
    $db->exec('PRAGMA foreign_keys = OFF');
    $db->exec('ATTACH ' . $db->quote($gecici) . ' AS yedek');
    $db->beginTransaction();

    $tetikler = [];
    $tq = $db->query("SELECT name, tbl_name, sql FROM main.sqlite_master WHERE type='trigger' AND sql IS NOT NULL");
    foreach ($tq->fetchAll() as $tr) {
        if (!in_array($tr['tbl_name'], $ortak, true)) continue;
        $tetikler[] = $tr;
        $db->exec('DROP TRIGGER main."' . str_replace('"', '""', $tr['name']) . '"');
    }

    foreach ($ortak as $t) {
        $hedefS = [];
        foreach ($db->query('PRAGMA main.table_info("' . $t . '")')->fetchAll() as $c) $hedefS[] = $c['name'];
        $kaynakS = [];
        foreach ($db->query('PRAGMA yedek.table_info("' . $t . '")')->fetchAll() as $c) $kaynakS[] = $c['name'];

        $kesisim = array_values(array_intersect($hedefS, $kaynakS));
        if (!$kesisim) continue;
        $liste = '"' . implode('","', $kesisim) . '"';
        $db->exec('DELETE FROM main."' . $t . '"');
        $db->exec('INSERT INTO main."' . $t . '" (' . $liste . ') SELECT ' . $liste . ' FROM yedek."' . $t . '"');
        $aktarilan[$t] = (int)$db->query('SELECT COUNT(*) FROM main."' . $t . '"')->fetchColumn();
    }

    if ($mevcutSifreler) {
        $sf = $db->prepare('UPDATE users SET password=? WHERE username=?');
        foreach ($mevcutSifreler as $kadi => $hash) $sf->execute([$hash, $kadi]);
    }
    if ($mevcutTotp) {
        $tf = $db->prepare('UPDATE users SET totp_secret=? WHERE username=?');
        foreach ($mevcutTotp as $kadi => $seed) $tf->execute([$seed, $kadi]);
    }
    if ($mevcutTotpYedek) {
        $ty = $db->prepare('UPDATE users SET totp_yedek=? WHERE username=?');
        foreach ($mevcutTotpYedek as $kadi => $yk) $ty->execute([$yk, $kadi]);
    }
    if ($mevcutRozet) {
        $rz = $db->prepare('UPDATE personel SET pin_kod=COALESCE(?, pin_kod), qr_token=COALESCE(?, qr_token) WHERE id=?');
        foreach ($mevcutRozet as $satir) $rz->execute($satir);
    }
    if ($mevcutSirlar) {
        $sr = $db->prepare("INSERT INTO ayarlar (anahtar, deger) VALUES (?,?)
                            ON CONFLICT(anahtar) DO UPDATE SET deger=excluded.deger");
        foreach ($mevcutSirlar as $k => $v) $sr->execute([$k, $v]);
    }
    if ($mevcutPortal) {
        $pf = $db->prepare('UPDATE musteri_kontaklari SET sifre_hash=? WHERE id=? AND email=?');
        foreach ($mevcutPortal as $satir) $pf->execute($satir);
    }
    if ($mevcutKodIz !== '') {
        $yedekKodIz = '';
        try { $yedekKodIz = (string)$db->query("SELECT deger FROM main.ayarlar WHERE anahtar='kod_iz_anahtar'")->fetchColumn(); } catch (\Throwable $e) { hataGunlugu('geri_yukle.kod_iz', $e); }
        if ($yedekKodIz === '') $db->prepare("INSERT INTO ayarlar (anahtar, deger) VALUES ('kod_iz_anahtar', ?) ON CONFLICT(anahtar) DO UPDATE SET deger=excluded.deger")->execute([$mevcutKodIz]);
    }

    foreach ($tetikler as $tr) $db->exec($tr['sql']);

    $db->commit();
} catch (\Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    try { $db->exec('DETACH yedek'); } catch (\Throwable $e2) {}
    $db->exec('PRAGMA foreign_keys = ON');
    $temizle();
    error_log('Taloo geri yukleme: basarisiz: ' . $e->getMessage());
    jsonError('Geri yükleme başarısız oldu, HİÇBİR DEĞİŞİKLİK YAPILMADI.'
        . ' (Emniyet yedeği: ' . basename($emniyet) . ')', 500);
}

try { $db->exec('DETACH yedek'); } catch (\Throwable $e) {}
$db->exec('PRAGMA foreign_keys = ON');

$fkSorun = 0;
try { $fkSorun = count($db->query('PRAGMA foreign_key_check')->fetchAll()); } catch (\Throwable $e) { hataGunlugu('geri_yukle.fk_kontrol', $e); }

$dosyaYazilan = 0; $dosyaHata = 0;
$kok = rtrim(UPLOAD_PATH, '/' . chr(92));
foreach ($dosyaGirdileri as $ad) {
    $goreli = substr($ad, strlen('dosyalar/'));
    if ($goreli === '' || str_contains($goreli, '..') || str_starts_with($goreli, '/')) { $dosyaHata++; continue; }
    if (!_geriYuklenebilirDosya($goreli)) { $dosyaHata++; continue; }
    $veri = zipCikar($arsiv, $ad, $icindekiler);
    if ($veri === null) { $dosyaHata++; continue; }
    $hedef = $kok . '/' . $goreli;
    $dizin = dirname($hedef);
    if (!is_dir($dizin) && !@mkdir($dizin, 0775, true)) { $dosyaHata++; continue; }
    if (@file_put_contents($hedef, $veri) === false) { $dosyaHata++; continue; }
    $dosyaYazilan++;
}

$temizle();

logla($db, 'islem_log', null, 'guncelle', null, null,
    'YEDEK GERİ YÜKLENDİ · ' . count($aktarilan) . ' tablo, ' . $dosyaYazilan . ' dosya · '
    . 'yedek tarihi: ' . (string)($bilgi['olusturulma'] ?? 'bilinmiyor')
    . ' · emniyet kopyası: ' . basename($emniyet));

jsonOk([
    'tablolar'       => $aktarilan,
    'tablo_adedi'    => count($aktarilan),
    'dosya_yazilan'  => $dosyaYazilan,
    'dosya_hata'     => $dosyaHata,
    'fk_sorun'       => $fkSorun,
    'emniyet_yedegi' => basename($emniyet),
], 'Geri yükleme tamamlandı. Değişikliklerin görünmesi için sayfayı yenileyin.');
