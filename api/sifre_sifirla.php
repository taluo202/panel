<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/eposta.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method Not Allowed', 405);

$body   = govdeOku();
$action = trim(metin($body['action'] ?? ''));
$db     = getDb();

function _resetRateLimit(PDO $db, string $ip): void {
    $c = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip=? AND attempted_at >= datetime('now','-10 minutes','localtime')");
    $c->execute([$ip]);
    if ((int)$c->fetchColumn() > 20) jsonError('Çok fazla deneme yapıldı. Lütfen birkaç dakika sonra tekrar deneyin.', 429);
    logAttempt('[sifre_sifirla]', $ip, true);
}

if ($action === 'talep') {
    $email = trim((string)($body['email'] ?? ''));
    $ip    = istekIp();
    _resetRateLimit($db, $ip);

    $genelMesaj = 'Bu e-posta adresi sistemde kayıtlıysa, şifre sıfırlama bağlantısı gönderildi.';

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $st = $db->prepare("SELECT id, ad_soyad, username FROM users WHERE email = ? COLLATE NOCASE LIMIT 1");
        $st->execute([$email]);
        $user = $st->fetch();

        if ($user) {
            $db->prepare("UPDATE password_resets SET used=1 WHERE user_id=? AND used=0")->execute([$user['id']]);

            $token     = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $db->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?,?,?)")
               ->execute([$user['id'], tokenOzet($token), $expiresAt]);

            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host   = guvenliHost();
            $siteBase = siteKokYolu();
            $link   = "{$scheme}://{$host}{$siteBase}/sifre-sifirla.html?token={$token}";

            $konu    = 'Şifre Sıfırlama Talebi';
            $metin   = "Merhaba " . ($user['ad_soyad'] ?: $user['username']) . ",\n\n"
                     . "Hesabınız için bir şifre sıfırlama talebinde bulunuldu.\n"
                     . "Aşağıdaki bağlantıya tıklayarak yeni şifrenizi belirleyebilirsiniz (1 saat geçerlidir):\n\n"
                     . $link . "\n\n"
                     . "Bu talebi siz yapmadıysanız bu e-postayı yok sayabilirsiniz.";
            epostaGonder($db, $email, $konu, $metin, 'sifre_sifirlama');
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

    $st = $db->prepare("SELECT * FROM password_resets WHERE token=? AND used=0 AND expires_at > datetime('now','localtime') LIMIT 1");
    $st->execute([tokenOzet($token)]);
    $reset = $st->fetch();
    if (!$reset) jsonError('Bağlantının süresi dolmuş veya geçersiz. Lütfen yeni bir sıfırlama talebi oluşturun.', 400);

    $_uAd = '';
    try {
        $_us = $db->prepare('SELECT username FROM users WHERE id=?');
        $_us->execute([$reset['user_id']]);
        $_uAd = (string)($_us->fetchColumn() ?: '');
    } catch (\Throwable $e) {}
    parolaDogrula($sifre, $_uAd);

    $hash = password_hash($sifre, PASSWORD_BCRYPT, ['cost' => 12]);
    if (!parolaYazVeDamgala($db, (int)$reset['user_id'], $hash)) {
        jsonError('Şifre değiştirilemedi, bağlantı hâlâ geçerli. Biraz sonra tekrar deneyin.', 500);
    }
    $_kad = $db->query("SELECT username FROM users WHERE id=" . (int)$reset['user_id'])->fetchColumn();
    guvenlikLog('sifre_sifirlandi', (string)($_kad ?: '?'), (int)$reset['user_id']);
    guvenlikAlarmi('Şifre sıfırlama bağlantısıyla parola değiştirildi.', [
        'Kullanıcı' => (string)($_kad ?: '?'),
        'IP'        => istekIp(),
    ]);
    $db->prepare("UPDATE password_resets SET used=1 WHERE id=?")->execute([$reset['id']]);

    jsonOk(null, 'Şifreniz güncellendi. Şimdi giriş yapabilirsiniz.');
}

else {
    jsonError('Geçersiz işlem.');
}
