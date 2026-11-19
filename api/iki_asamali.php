<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();


$db     = getDb();
$uid    = (int)$_SESSION['user_id'];
$rol    = (string)($_SESSION['rol'] ?? '');
$metot  = $_SERVER['REQUEST_METHOD'];
$action = (string)($_GET['action'] ?? '');

function _2faSifreDogrula(PDO $db, int $uid, string $sifre): void {
    if ($sifre === '') jsonError('Şifrenizi girin.');
    if (!yenidenDogrula($db, $uid, $sifre)) {
        jsonError('Şifre hatalı.');
    }
}

if ($metot === 'GET') {
    $st = $db->prepare("SELECT totp_aktif, totp_kuruldu, totp_yedek FROM users WHERE id = ?");
    $st->execute([$uid]);
    $u = $st->fetch() ?: [];
    $yedek = json_decode((string)($u['totp_yedek'] ?? '[]'), true);
    jsonOk([
        'aktif'         => (int)($u['totp_aktif'] ?? 0) === 1,
        'kuruldu'       => $u['totp_kuruldu'] ?? null,
        'kalan_yedek'   => is_array($yedek) ? count($yedek) : 0,
        'mod'           => ayarOku('iki_asamali', 'kapali'),
        'zorunlu'       => ikiAsamaliZorunlu($rol),
    ]);
}

if ($metot !== 'POST') jsonError('Method Not Allowed', 405);
verifyCsrf();
$body = govdeOku();

if ($action === 'baslat') {
    _2faSifreDogrula($db, $uid, (string)($body['sifre'] ?? ''));
    $mevcut = $db->prepare("SELECT totp_secret, totp_aktif FROM users WHERE id = ?");
    $mevcut->execute([$uid]);
    $m2 = $mevcut->fetch() ?: [];
    if ((int)($m2['totp_aktif'] ?? 0) === 1
        && totpDogrula((string)$m2['totp_secret'], (string)($body['kod'] ?? '')) === null) {
        jsonError('İki aşamalı doğrulama zaten açık; yeniden kurmak için uygulamanızdaki güncel kodu da girin.');
    }
    $anahtar = totpAnahtarUret();
    $_SESSION['totp_kurulum'] = $anahtar;

    $firma = ayarOku('firma_unvan', 'Taloo');
    $etiket = rawurlencode($firma . ':' . (string)$_SESSION['username']);
    $uri = 'otpauth://totp/' . $etiket
         . '?secret=' . $anahtar
         . '&issuer=' . rawurlencode($firma)
         . '&algorithm=SHA1&digits=' . TOTP_HANE . '&period=' . TOTP_ADIM;

    jsonOk([
        'anahtar'      => $anahtar,
        'anahtar_okur' => trim(chunk_split($anahtar, 4, ' ')),
        'uri'          => $uri,
    ], 'Anahtar üretildi. Uygulamanıza ekleyip kodu doğrulayın.');
}

if ($action === 'etkinlestir') {
    $anahtar = (string)($_SESSION['totp_kurulum'] ?? '');
    if ($anahtar === '') jsonError('Önce kurulumu başlatın.');
    $adim = totpDogrula($anahtar, (string)($body['kod'] ?? ''));
    if ($adim === null) jsonError('Kod doğrulanamadı. Telefonunuzdaki güncel kodu girin.');

    $kodlar = totpYedekKodUret();
    $hashli = array_map(fn($k) => password_hash($k, PASSWORD_BCRYPT, ['cost' => 10]), $kodlar);
    $db->prepare("UPDATE users SET totp_secret=?, totp_aktif=1, totp_yedek=?, totp_son_adim=?, totp_kuruldu=datetime('now','localtime') WHERE id=?")
       ->execute([$anahtar, json_encode($hashli), $adim, $uid]);
    unset($_SESSION['totp_kurulum']);
    guvenlikLog('2fa_acildi', (string)$_SESSION['username'], $uid);

    jsonOk(['yedek_kodlar' => $kodlar],
        'İki aşamalı doğrulama açıldı. Kurtarma kodlarını güvenli bir yere kaydedin — bir daha gösterilmez.');
}

if ($action === 'kapat') {
    if (ikiAsamaliZorunlu($rol)) {
        jsonError('Yöneticiniz iki aşamalı doğrulamayı zorunlu kılmış; kapatılamaz.', 403);
    }
    _2faSifreDogrula($db, $uid, (string)($body['sifre'] ?? ''));
    $st = $db->prepare("SELECT totp_secret, totp_aktif FROM users WHERE id = ?");
    $st->execute([$uid]);
    $u = $st->fetch() ?: [];
    if ((int)($u['totp_aktif'] ?? 0) !== 1) jsonError('Zaten kapalı.');
    if (totpDogrula((string)$u['totp_secret'], (string)($body['kod'] ?? '')) === null) {
        jsonError('Kod doğrulanamadı.');
    }
    $db->prepare("UPDATE users SET totp_secret=NULL, totp_aktif=0, totp_yedek=NULL, totp_kuruldu=NULL WHERE id=?")
       ->execute([$uid]);
    guvenlikLog('2fa_kapatildi', (string)$_SESSION['username'], $uid);
    guvenlikAlarmi('İki aşamalı doğrulama KAPATILDI.', [
        'Kullanıcı' => (string)$_SESSION['username'],
        'IP'        => istekIp(),
    ]);
    jsonOk(null, 'İki aşamalı doğrulama kapatıldı.');
}

if ($action === 'yedek_yenile') {
    _2faSifreDogrula($db, $uid, (string)($body['sifre'] ?? ''));
    $st = $db->prepare("SELECT totp_secret, totp_aktif FROM users WHERE id = ?");
    $st->execute([$uid]);
    $u = $st->fetch() ?: [];
    if ((int)($u['totp_aktif'] ?? 0) !== 1) jsonError('İki aşamalı doğrulama açık değil.');
    if (totpDogrula((string)$u['totp_secret'], (string)($body['kod'] ?? '')) === null) {
        jsonError('Kod doğrulanamadı.');
    }
    $kodlar = totpYedekKodUret();
    $hashli = array_map(fn($k) => password_hash($k, PASSWORD_BCRYPT, ['cost' => 10]), $kodlar);
    $db->prepare("UPDATE users SET totp_yedek=? WHERE id=?")->execute([json_encode($hashli), $uid]);
    guvenlikLog('2fa_yedek_yenilendi', (string)$_SESSION['username'], $uid);
    jsonOk(['yedek_kodlar' => $kodlar], 'Yeni kurtarma kodları üretildi. Eskiler geçersiz.');
}

if ($action === 'sifirla') {
    if ($rol !== 'admin') jsonError('Bu işlemi yalnızca sistem yöneticisi yapabilir.', 403);
    $hedef = (int)($body['user_id'] ?? 0);
    if ($hedef <= 0) jsonError('Kullanıcı seçilmedi.');
    $st = $db->prepare("SELECT username FROM users WHERE id = ?");
    $st->execute([$hedef]);
    $ad = $st->fetchColumn();
    if ($ad === false) jsonError('Kullanıcı bulunamadı.', 404);
    $db->prepare("UPDATE users SET totp_secret=NULL, totp_aktif=0, totp_yedek=NULL, totp_kuruldu=NULL WHERE id=?")
       ->execute([$hedef]);
    guvenlikLog('2fa_sifirlandi', (string)$ad, $hedef,
                ['sifirlayan' => (string)$_SESSION['username']]);
    guvenlikAlarmi('Bir kullanıcının iki aşamalı doğrulaması sıfırlandı.', [
        'Kullanıcı'  => (string)$ad,
        'Sıfırlayan' => (string)$_SESSION['username'],
        'IP'         => istekIp(),
    ]);
    jsonOk(null, "$ad için iki aşamalı doğrulama sıfırlandı. Kullanıcı yeniden kurmalı.");
}

if ($action === 'mod') {
    if ($rol !== 'admin') jsonError('Bu ayarı yalnızca sistem yöneticisi değiştirebilir.', 403);
    $mod = (string)($body['mod'] ?? '');
    if (!in_array($mod, ['kapali', 'admin', 'herkes'], true)) jsonError('Geçersiz seçim.');
    if ($mod !== 'kapali') {
        $st = $db->prepare("SELECT totp_aktif FROM users WHERE id = ?");
        $st->execute([$uid]);
        if ((int)$st->fetchColumn() !== 1) {
            jsonError('Önce kendi hesabınızda iki aşamalı doğrulamayı açın; yoksa kilitli kalırsınız.');
        }
    }
    $db->prepare("INSERT INTO ayarlar (anahtar, deger) VALUES ('iki_asamali', ?)
                  ON CONFLICT(anahtar) DO UPDATE SET deger=excluded.deger")->execute([$mod]);
    guvenlikLog('2fa_zorunluluk', (string)$_SESSION['username'], $uid, ['mod' => $mod]);
    jsonOk(['mod' => $mod], 'Ayar kaydedildi.');
}

jsonError('Geçersiz işlem.');
