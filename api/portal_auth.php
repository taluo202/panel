<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/eposta.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$method = $_SERVER['REQUEST_METHOD'];
$action = trim(metin($_GET['action'] ?? ($_POST['action'] ?? '')));
$db     = getDb();

if ($method === 'GET' && $action === 'session') {
    if (empty($_SESSION['portal_kontak_id'])) {
        jsonOk(['logged_in' => false, 'csrf_token' => csrfToken()]);
    }
    $st = $db->prepare("
        SELECT k.id, k.ad_soyad, k.email, k.telefon, k.durum, k.sifre_degisti, m.unvan AS musteri_unvan
        FROM musteri_kontaklari k JOIN musteriler m ON m.id = k.musteri_id
        WHERE k.id = ?
    ");
    $st->execute([$_SESSION['portal_kontak_id']]);
    $k = $st->fetch();
    if (!$k || $k['durum'] !== 'aktif'
        || (string)($k['sifre_degisti'] ?? '') !== (string)($_SESSION['portal_sifre_damga'] ?? '')) {
        portalOturumKapat();
        jsonOk(['logged_in' => false, 'csrf_token' => csrfToken()]);
    }
    jsonOk([
        'logged_in'  => true,
        'kontak'     => ['ad_soyad' => $k['ad_soyad'], 'email' => $k['email']],
        'musteri'    => ['unvan' => $k['musteri_unvan']],
        'csrf_token' => csrfToken(),
    ]);
}

if ($method !== 'POST') jsonError('Method Not Allowed', 405);

$body = govdeOku();
$ip   = istekIp();
const PORTAL_SAHTE_HASH = '$2y$12$IIfNv46tpYNd5sbK1s32MevKJBdajvvVbVg73ig/4475Oazk.WJzi';

if ($action === 'giris') {
    $email = trim((string)($body['email'] ?? ''));
    $sifre = (string)($body['sifre'] ?? '');
    if ($email === '' || $sifre === '') jsonError('E-posta ve şifre zorunludur.');

    portalCheckRateLimit($email, $ip);

    $st = $db->prepare("SELECT * FROM musteri_kontaklari WHERE email = ? COLLATE NOCASE LIMIT 1");
    $st->execute([$email]);
    $k = $st->fetch();

    if (!$k || empty($k['sifre_hash'])) password_verify($sifre, PORTAL_SAHTE_HASH);
    if (!$k || empty($k['sifre_hash']) || !password_verify($sifre, $k['sifre_hash'])) {
        portalLogAttempt($email, $ip, false);
        jsonError('E-posta veya şifre hatalı.', 403);
    }
    if ($k['durum'] !== 'aktif') {
        portalLogAttempt($email, $ip, false);
        jsonError('Erişiminiz kapatılmış. Lütfen firmanızla görüşün.', 403);
    }

    portalLogAttempt($email, $ip, true);
    portalOturumBaslat($k);
    $db->prepare("UPDATE musteri_kontaklari SET son_giris = datetime('now','localtime') WHERE id=?")
       ->execute([$k['id']]);
    logla($db, 'musteri_kontaklari', (int)$k['id'], 'guncelle', ['son_giris' => null], ['son_giris' => date('Y-m-d H:i:s')],
        'Müşteri portalı girişi');
    guvenlikLog('portal_giris', $email, (int)$k['id']);

    jsonOk([
        'kontak'     => ['ad_soyad' => $k['ad_soyad'], 'email' => $k['email']],
        'csrf_token' => csrfToken(),
    ], 'Giriş başarılı.');
}

elseif ($action === 'cikis') {
    portalOturumKapat();
    jsonOk(null, 'Çıkış yapıldı.');
}

elseif ($action === 'sifremi-unuttum') {
    $email = trim((string)($body['email'] ?? ''));
    $uSay = $db->prepare("SELECT COUNT(*) FROM portal_login_attempts WHERE ip=? AND email LIKE '[unuttum]%' AND attempted_at > datetime('now','-10 minutes','localtime')");
    $uSay->execute([$ip]);
    if ((int)$uSay->fetchColumn() >= 10) jsonError('Çok fazla deneme yapıldı. Lütfen birkaç dakika sonra tekrar deneyin.', 429);
    $eSay = $db->prepare("SELECT COUNT(*) FROM portal_login_attempts WHERE email = ? AND attempted_at > datetime('now','-60 minutes','localtime')");
    $eSay->execute(['[unuttum]' . mb_substr(mb_strtolower($email), 0, 120)]);
    if ((int)$eSay->fetchColumn() >= 3) jsonError('Bu adres için çok fazla şifre sıfırlama isteği gönderildi. Lütfen bir saat sonra tekrar deneyin.', 429);
    portalLogAttempt('[unuttum]' . mb_substr(mb_strtolower($email), 0, 120), $ip, true);
    $genelMesaj = 'Bu e-posta adresi kayıtlıysa, şifre sıfırlama bağlantısı gönderildi.';

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $st = $db->prepare("SELECT id, ad_soyad FROM musteri_kontaklari WHERE email = ? COLLATE NOCASE AND durum <> 'pasif' LIMIT 1");
        $st->execute([$email]);
        $k = $st->fetch();
        if ($k) {
            $db->prepare("UPDATE musteri_kontak_token SET used=1 WHERE kontak_id=? AND amac='sifirlama' AND used=0")
               ->execute([$k['id']]);
            $token     = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $db->prepare("INSERT INTO musteri_kontak_token (kontak_id, amac, token, expires_at) VALUES (?,'sifirlama',?,?)")
               ->execute([$k['id'], tokenOzet($token), $expiresAt]);

            $link = _portalLink('portal-davet-kabul.html', $token);
            $metin = "Merhaba " . $k['ad_soyad'] . ",\n\n"
                   . "Müşteri portalı şifrenizi sıfırlamak için bir talepte bulunuldu.\n"
                   . "Aşağıdaki bağlantıya tıklayarak yeni şifrenizi belirleyebilirsiniz (1 saat geçerlidir):\n\n"
                   . $link . "\n\n"
                   . "Bu talebi siz yapmadıysanız bu e-postayı yok sayabilirsiniz.";
            epostaGonder($db, $email, 'Müşteri Portalı Şifre Sıfırlama', $metin, 'portal_sifirlama');
        }
    }
    jsonOk(null, $genelMesaj);
}

elseif ($action === 'sifirla') {
    $token  = trim((string)($body['token'] ?? ''));
    $sifre  = (string)($body['sifre'] ?? '');
    $sifre2 = (string)($body['sifre2'] ?? '');
    if ($token === '') jsonError('Geçersiz bağlantı.');
    if ($sifre !== $sifre2) jsonError('Şifreler eşleşmiyor.');

    $st = $db->prepare("SELECT * FROM musteri_kontak_token WHERE token=? AND used=0 AND expires_at > datetime('now','localtime') LIMIT 1");
    $st->execute([tokenOzet($token)]);
    $tok = $st->fetch();
    if (!$tok) jsonError('Bağlantının süresi dolmuş veya geçersiz. Lütfen yeniden talep edin.', 400);

    $kSt = $db->prepare("SELECT * FROM musteri_kontaklari WHERE id=?");
    $kSt->execute([$tok['kontak_id']]);
    $k = $kSt->fetch();
    if (!$k) jsonError('Kişi bulunamadı.', 404);
    if (($k['durum'] ?? '') === 'pasif') jsonError('Erişiminiz kapatılmış. Lütfen firmanızla görüşün.', 403);
    if ($tok['amac'] === 'davet' && !empty($k['sifre_hash'])) jsonError('Bu davet bağlantısı artık geçersiz; şifrenizi giriş ekranındaki "Şifremi unuttum" bağlantısıyla yenileyin.', 400);

    parolaDogrula($sifre, $k['email']);

    $hash = password_hash($sifre, PASSWORD_BCRYPT, ['cost' => 12]);
    $db->prepare("UPDATE musteri_kontaklari SET sifre_hash=?, durum='aktif', updated_at=datetime('now','localtime') WHERE id=?")
       ->execute([$hash, $k['id']]);
    $db->prepare("UPDATE musteri_kontak_token SET used=1 WHERE id=?")->execute([$tok['id']]);
    $yeniDamga = portalSifreDamgaGuncelle($db, (int)$k['id']);
    logla($db, 'musteri_kontaklari', (int)$k['id'], 'guncelle',
        ['durum' => $k['durum'], 'sifre_degisti' => $k['sifre_degisti'] ?? null],
        ['durum' => 'aktif', 'sifre_degisti' => $yeniDamga],
        $tok['amac'] === 'davet' ? 'Portal daveti kabul edildi' : 'Portal şifresi sıfırlandı');
    guvenlikLog($tok['amac'] === 'davet' ? 'portal_davet_kabul' : 'portal_sifre_sifirlandi', $k['email'], (int)$k['id']);

    jsonOk(null, 'Şifreniz belirlendi. Şimdi giriş yapabilirsiniz.');
}

else {
    jsonError('Geçersiz işlem.');
}

function _portalLink(string $sayfa, string $token): string {
    $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = guvenliHost();
    $siteBase = siteKokYolu();
    return "{$scheme}://{$host}{$siteBase}/{$sayfa}?token={$token}";
}
