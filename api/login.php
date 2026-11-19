<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method Not Allowed', 405);
}

$body     = govdeOku();
$username = trim((string)($body['username'] ?? ''));
$password = (string)($body['password'] ?? '');
$kod      = trim((string)($body['kod'] ?? ''));
$ip       = istekIp();

$db = getDb();

const GIRIS_SAHTE_OZET = '$2y$12$7.HnzJd5cWvaSvp7pInZp.5HgFdnCJyv741wDHhkaK4VNa45LcmEW';

function _oturumAc(PDO $db, array $user, bool $kurulumSifresi = false): void {
    session_regenerate_id(true);
    try {
        $db->prepare("UPDATE users SET son_giris = datetime('now','localtime'), son_giris_ip = ? WHERE id = ?")
           ->execute([istekIp(), $user['id']]);
    } catch (\Throwable $e) { hataGunlugu('login.son_giris', $e); }
    guvenlikLog('giris', (string)$user['username'], (int)$user['id']);
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['username']  = $user['username'];
    $_SESSION['ad_soyad']  = $user['ad_soyad'];
    $_SESSION['rol']       = $user['rol'];
    $_SESSION['yetkiler']  = $user['yetkiler'] ?? '';
    $_SESSION['sube']      = $user['sube'] ?? '';
    $_SESSION['personel_id'] = ((int)($user['personel_id'] ?? 0)) ?: null;
    $_SESSION['sifre_damga'] = (string)($user['sifre_degisti'] ?? '');
    $_SESSION['son_islem']   = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    unset($_SESSION['2fa_bekleyen'], $_SESSION['2fa_zaman'], $_SESSION['2fa_kurulum_sifresi']);
    if ($kurulumSifresi) $_SESSION['sifre_degistir_zorunlu'] = true;
    else unset($_SESSION['sifre_degistir_zorunlu']);

    jsonOk([
        'user'       => ['username' => $user['username'], 'ad_soyad' => $user['ad_soyad'], 'rol' => $user['rol'], 'sube' => $user['sube'] ?? ''],
        'csrf_token' => $_SESSION['csrf'],
        'iki_asamali_kurulmali' => (ikiAsamaliZorunlu((string)$user['rol']) && (int)($user['totp_aktif'] ?? 0) !== 1),
        'sifre_degistir_zorunlu' => $kurulumSifresi,
    ], $kurulumSifresi ? 'Kurulum şifresiyle giriş yaptınız; devam etmeden önce şifrenizi değiştirin.' : 'Giriş başarılı.');
}

if (!empty($_SESSION['2fa_bekleyen']) && $kod !== '') {
    if (time() - (int)($_SESSION['2fa_zaman'] ?? 0) > 300) {
        unset($_SESSION['2fa_bekleyen'], $_SESSION['2fa_zaman']);
        jsonError('Doğrulama süresi doldu. Yeniden giriş yapın.', 401);
    }
    $bek = (int)$_SESSION['2fa_bekleyen'];
    $bekKurulum = !empty($_SESSION['2fa_kurulum_sifresi']);
    $st = $db->prepare("SELECT id, username, password, ad_soyad, rol, yetkiler, sube, personel_id, totp_secret, totp_aktif, totp_yedek, totp_son_adim, sifre_degisti FROM users WHERE id = ? LIMIT 1");
    $st->execute([$bek]);
    $user = $st->fetch();
    if (!$user) { unset($_SESSION['2fa_bekleyen']); jsonError('Oturum bulunamadı.', 401); }

    checkRateLimit($user['username'], $ip);

    $sonAdim = (int)($user['totp_son_adim'] ?? 0);
    $adim = totpDogrula((string)$user['totp_secret'], $kod, $sonAdim > 0 ? $sonAdim : null);
    if ($adim !== null) {
        $db->prepare("UPDATE users SET totp_son_adim = ? WHERE id = ?")->execute([$adim, $user['id']]);
        logAttempt($user['username'], $ip, true);
        _oturumAc($db, $user, $bekKurulum);
    }

    $yedek = json_decode((string)($user['totp_yedek'] ?? '[]'), true);
    if (is_array($yedek)) {
        foreach ($yedek as $i => $h) {
            if (password_verify(strtoupper($kod), (string)$h)) {
                unset($yedek[$i]);
                $db->prepare("UPDATE users SET totp_yedek=? WHERE id=?")
                   ->execute([json_encode(array_values($yedek)), $user['id']]);
                logAttempt($user['username'], $ip, true);
                guvenlikLog('2fa_kurtarma', (string)$user['username'], (int)$user['id'],
                            ['kalan_kurtarma_kodu' => count($yedek)]);
                _oturumAc($db, $user, $bekKurulum);
            }
        }
    }
    logAttempt($user['username'], $ip, false);
    guvenlikLog('2fa_basarisiz', (string)$user['username'], (int)$user['id']);

    if (totpDogrula((string)$user['totp_secret'], $kod) !== null) {
        jsonError('Bu kod az önce kullanıldı. Uygulamanızdaki YENİ kodu bekleyip girin.', 401);
    }
    jsonError('Doğrulama kodu hatalı.', 401);
}

if ($username === '' || $password === '') {
    jsonError('Kullanıcı adı ve şifre zorunludur.');
}

checkRateLimit($username, $ip);

$stmt = $db->prepare("SELECT id, username, password, ad_soyad, rol, yetkiler, sube, personel_id, totp_aktif, sifre_degisti FROM users WHERE username = ? LIMIT 1");
$stmt->execute([$username]);
$user = $stmt->fetch();

$sifreDogru = password_verify($password, $user ? (string)$user['password'] : GIRIS_SAHTE_OZET);
if (!$user || !$sifreDogru) {
    logAttempt($username, $ip, false);
    guvenlikLog('giris_basarisiz', $username, $user ? (int)$user['id'] : null);
    jsonError('Kullanıcı adı veya şifre hatalı.', 401);
}

logAttempt($username, $ip, true);
$kurulumSifresi = kurulumSifresiMi($user, $password);

if ((int)($user['totp_aktif'] ?? 0) === 1) {
    session_regenerate_id(true);
    $_SESSION['2fa_bekleyen'] = (int)$user['id'];
    $_SESSION['2fa_zaman']    = time();
    $_SESSION['2fa_kurulum_sifresi'] = $kurulumSifresi;
    $_SESSION['csrf']         = $_SESSION['csrf'] ?? bin2hex(random_bytes(32));
    jsonOk([
        'iki_asamali' => true,
        'csrf_token'  => $_SESSION['csrf'],
    ], 'Telefonunuzdaki 6 haneli kodu girin.');
}

_oturumAc($db, $user, $kurulumSifresi);
