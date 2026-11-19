<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$sess = requireAuth();
$db   = getDb();
$method = $_SERVER['REQUEST_METHOD'];

$userRow = $db->prepare("SELECT rol FROM users WHERE id=?")->execute([$sess['user_id']]) ? null : null;
$stmt = $db->prepare("SELECT rol FROM users WHERE id=?");
$stmt->execute([$sess['user_id']]);
$me = $stmt->fetch();
if (!$me || $me['rol'] !== 'admin') {
    jsonError('Bu işlem için yetkiniz yok.', 403);
}

if ($method === 'POST' && ($_GET['action'] ?? '') === 'davet') {
    verifyCsrf();
    require_once __DIR__ . '/eposta.php';
    $uid = (int)($_GET['id'] ?? 0);
    if ($uid < 1) jsonError('Geçersiz kullanıcı.');
    $st = $db->prepare("SELECT id, username, ad_soyad, email FROM users WHERE id=?");
    $st->execute([$uid]);
    $u = $st->fetch();
    if (!$u) jsonError('Kullanıcı bulunamadı.', 404);
    if (empty($u['email'])) jsonError('Bu kullanıcının e-posta adresi yok. Önce Düzenle\'den e-posta ekleyin.');

    $db->prepare("UPDATE password_resets SET used=1 WHERE user_id=? AND used=0")->execute([$uid]);
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+7 day'));
    $db->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?,?,?)")
       ->execute([$uid, tokenOzet($token), $expiresAt]);

    $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = guvenliHost();
    $siteBase = siteKokYolu();
    $link = "{$scheme}://{$host}{$siteBase}/sifre-sifirla.html?token={$token}";

    $konu  = 'Kurumsal Panel Hesabınız Hazır';
    $metin = "Merhaba " . ($u['ad_soyad'] ?: $u['username']) . ",\n\n"
           . "Kurumsal Panel'de sizin için bir hesap oluşturuldu.\n"
           . "Kullanıcı adınız: " . $u['username'] . "\n\n"
           . "Aşağıdaki bağlantıya tıklayarak kendi şifrenizi belirleyin (7 gün geçerlidir):\n\n"
           . $link . "\n\n"
           . "Şifrenizi belirledikten sonra bu kullanıcı adı ve yeni şifrenizle giriş yapabilirsiniz.";
    $sonuc = epostaGonder($db, $u['email'], $konu, $metin, 'personel_davet');

    logla($db, 'users', $uid, 'guncelle', null, null, 'Personel daveti gönderildi: ' . $u['username']);
    jsonOk(
        ['link' => $link, 'eposta_gonderildi' => $sonuc['basarili']],
        $sonuc['basarili']
            ? 'Davet e-postası gönderildi.'
            : 'E-posta gönderilemedi ama bağlantı oluşturuldu — aşağıdan kopyalayıp elden iletebilirsiniz.'
    );
}

switch ($method) {
    case 'GET':
        $rows = $db->query("SELECT u.id, u.username, u.ad_soyad, u.email, u.rol, u.yetkiler, u.sube, u.created_at,
                                   u.son_giris, u.son_giris_ip, COALESCE(u.totp_aktif,0) AS totp_aktif,
                                   u.personel_id, p.ad_soyad AS personel_ad
                            FROM users u LEFT JOIN personel p ON p.id = u.personel_id
                            ORDER BY u.id")->fetchAll();
        jsonOk($rows);

    case 'POST':
        verifyCsrf();
        $body     = govdeOku();
        $username = trim(metin($body['username'] ?? ''));
        $sifre    = trim(metin($body['sifre'] ?? ''));
        $ad_soyad = trim(metin($body['ad_soyad'] ?? ''));
        $email    = trim(metin($body['email'] ?? ''));
        $yetkiler = yetkiDizesiTemizle((string)($body['yetkiler'] ?? ''));
        $sube     = trim(metin($body['sube'] ?? ''));
        $personelId = ((int)($body['personel_id'] ?? 0)) ?: null;
        if ($personelId !== null) {
            $pChk = $db->prepare("SELECT COUNT(*) FROM personel WHERE id=?");
            $pChk->execute([$personelId]);
            if ((int)$pChk->fetchColumn() === 0) jsonError('Seçilen personel kaydı bulunamadı.');
        }

        if ($username === '' || $sifre === '') jsonError('Kullanıcı adı ve şifre zorunludur.');
        parolaDogrula($sifre, $username);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError('Geçerli bir e-posta adresi girin.');

        try {
            $hash = password_hash($sifre, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->prepare("INSERT INTO users (username, password, ad_soyad, email, rol, yetkiler, sube, personel_id) VALUES (?,?,?,?,'user',?,?,?)")
               ->execute([$username, $hash, $ad_soyad, $email ?: null, $yetkiler, $sube ?: null, $personelId]);
            $yeniId = (int)$db->lastInsertId();
        } catch (\Exception $e) {
            jsonError('Bu kullanıcı adı zaten kullanılıyor.');
        }
        logla($db, 'users', $yeniId, 'ekle', null, [
            'username' => $username, 'ad_soyad' => $ad_soyad, 'email' => $email ?: null, 'rol' => 'user',
            'yetkiler' => $yetkiler, 'sube' => $sube ?: null, 'personel_id' => $personelId,
        ], 'Kullanıcı oluşturuldu: ' . $username);
        jsonOk(['id' => $yeniId], 'Kullanıcı oluşturuldu.');

    case 'PUT':
        verifyCsrf();
        $id   = (int)($_GET['id'] ?? 0);
        $body = govdeOku();
        if ($id < 1) jsonError('Geçersiz ID.');

        $yetkiler = yetkiDizesiTemizle((string)($body['yetkiler'] ?? ''));
        $ad_soyad = trim(metin($body['ad_soyad'] ?? ''));
        $email    = trim(metin($body['email'] ?? ''));
        $sube     = trim(metin($body['sube'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError('Geçerli bir e-posta adresi girin.');

        $personelId = ((int)($body['personel_id'] ?? 0)) ?: null;
        if ($personelId !== null) {
            $pChk = $db->prepare("SELECT COUNT(*) FROM personel WHERE id=?");
            $pChk->execute([$personelId]);
            if ((int)$pChk->fetchColumn() === 0) jsonError('Seçilen personel kaydı bulunamadı.');
        }

        $eskiSt = $db->prepare("SELECT username, ad_soyad, email, rol, yetkiler, sube, personel_id FROM users WHERE id=?");
        $eskiSt->execute([$id]);
        $eskiKayit = $eskiSt->fetch() ?: null;
        $eskiAd = $eskiKayit ? $eskiKayit['ad_soyad'] : false;

        $hash = null;
        if (!empty($body['sifre'])) {
            $sifre = trim(metin($body['sifre']));
            parolaDogrula($sifre);
            $hash = password_hash($sifre, PASSWORD_BCRYPT, ['cost' => 12]);
        }

        $db->beginTransaction();
        try {
            $db->prepare("UPDATE users SET ad_soyad=?, email=?, yetkiler=?, sube=?, personel_id=? WHERE id=? AND rol!='admin'")
               ->execute([$ad_soyad, $email ?: null, $yetkiler, $sube ?: null, $personelId, $id]);
            if ($eskiAd !== false && $eskiAd !== '' && $ad_soyad !== '' && $ad_soyad !== $eskiAd) {
                foreach (['kiralamalar', 'musteriler', 'teklifler', 'hakedisler', 'tahsilatlar'] as $t) {
                    $db->prepare("UPDATE $t SET temsilci=? WHERE temsilci=?")->execute([$ad_soyad, $eskiAd]);
                }
            }
            if ($hash !== null) {
                $db->prepare("UPDATE users SET password=? WHERE id=? AND rol!='admin'")->execute([$hash, $id]);
                sifreDamgaGuncelle($db, $id);
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Taloo kullanici_guncelle: ' . $e->getMessage());
            if ($hash !== null) guvenlikLog('sifre_degistirilemedi', (string)($eskiKayit['username'] ?? $id), $id);
            jsonError('Kullanıcı güncellenemedi, hiçbir kayıt değiştirilmedi. Biraz sonra tekrar deneyin.', 500);
        }

        if ($hash !== null) {
            $_kad = $db->query("SELECT username FROM users WHERE id=" . (int)$id)->fetchColumn();
            guvenlikLog('sifre_degisti', (string)($_kad ?: $id), $id,
                        ['degistiren' => (string)($_SESSION['username'] ?? '?')]);
        }
        if ($eskiKayit && $eskiKayit['rol'] !== 'admin') {
            $eskiIz = ['ad_soyad' => $eskiKayit['ad_soyad'], 'email' => $eskiKayit['email'], 'yetkiler' => $eskiKayit['yetkiler'],
                       'sube' => $eskiKayit['sube'], 'personel_id' => $eskiKayit['personel_id'] !== null ? (int)$eskiKayit['personel_id'] : null];
            $yeniIz = ['ad_soyad' => $ad_soyad, 'email' => $email ?: null, 'yetkiler' => $yetkiler,
                       'sube' => $sube ?: null, 'personel_id' => $personelId];
            if (!empty($body['sifre'])) { $eskiIz['sifre'] = ''; $yeniIz['sifre'] = 'değiştirildi'; }
            logla($db, 'users', $id, 'guncelle', $eskiIz, $yeniIz, 'Kullanıcı güncellendi: ' . $eskiKayit['username']);
        }
        jsonOk(null, 'Kullanıcı güncellendi.');

    case 'DELETE':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $eskiSt = $db->prepare("SELECT username, ad_soyad, email, rol, yetkiler, sube, personel_id FROM users WHERE id=?");
        $eskiSt->execute([$id]);
        $eskiKayit = $eskiSt->fetch() ?: null;
        $sil = $db->prepare("DELETE FROM users WHERE id=? AND rol!='admin'");
        $sil->execute([$id]);
        if ($sil->rowCount() > 0 && $eskiKayit) {
            logla($db, 'users', $id, 'sil', $eskiKayit, null, 'Kullanıcı silindi: ' . $eskiKayit['username']);
        }
        jsonOk(null, 'Kullanıcı silindi.');

    default:
        jsonError('Method Not Allowed', 405);
}
