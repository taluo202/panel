<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/eposta.php';
require_once __DIR__ . '/musteri-erisim-lib.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('kiralama_takip');

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDb();

function _davetGonder(PDO $db, array $kontak, ?bool &$gitti = null): string {
    return meDavetLink($db, $kontak, null, $gitti);
}

function _davetMesaji(bool $gitti, string $basari): string {
    return $gitti ? $basari : 'Davet bağlantısı oluşturuldu, ancak e-posta GÖNDERİLEMEDİ (e-posta ayarlarını kontrol edin). Bağlantıyı kişiye elle iletin.';
}

switch ($method) {

    case 'GET':
        $musteriId = (int)($_GET['musteri_id'] ?? 0);
        if ($musteriId < 1) jsonError('musteri_id zorunludur.');
        subeKayitDogrula($db, 'musteriler', $musteriId);
        $st = $db->prepare("SELECT id, ad_soyad, email, telefon, durum, son_giris, created_at, wa_izin, wa_izin_tarihi FROM musteri_kontaklari WHERE musteri_id=? ORDER BY id DESC");
        $st->execute([$musteriId]);
        jsonOk($st->fetchAll());
        break;

    case 'POST':
        verifyCsrf();
        $body = govdeOku();

        if (trim(metin($_GET['action'] ?? '')) === 'yeniden-gonder') {
            $id = (int)($_GET['id'] ?? 0);
            $st = $db->prepare("SELECT k.*, m.sube FROM musteri_kontaklari k JOIN musteriler m ON m.id=k.musteri_id WHERE k.id=?");
            $st->execute([$id]);
            $k = $st->fetch();
            if (!$k) jsonError('Kişi bulunamadı.', 404);
            subeErisimDogrula($k['sube']);
            if (($k['durum'] ?? '') === 'pasif') jsonError('Bu kişinin portal erişimi kapalı; davet göndermek için önce erişimi açın.');
            if (!empty($k['sifre_hash'])) jsonError('Bu kişinin şifresi zaten var; yeniden davet gönderilmez. Kişi giriş ekranındaki "Şifremi unuttum" bağlantısıyla şifresini yeniler.');
            $link = _davetGonder($db, $k, $gitti);
            jsonOk(['link' => $link, 'eposta_gitti' => $gitti], _davetMesaji($gitti, 'Davet yeniden gönderildi.'));
        }

        $musteriId = (int)($body['musteri_id'] ?? 0);
        $adSoyad   = trim(metin($body['ad_soyad'] ?? ''));
        $email     = trim((string)($body['email'] ?? ''));
        $telefon   = trim(metin($body['telefon'] ?? ''));
        if ($musteriId < 1) jsonError('musteri_id zorunludur.');
        if ($adSoyad === '') jsonError('Ad soyad zorunludur.');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError('Geçerli bir e-posta girin.');
        subeKayitDogrula($db, 'musteriler', $musteriId);

        $var = $db->prepare("SELECT id FROM musteri_kontaklari WHERE email = ? COLLATE NOCASE");
        $var->execute([$email]);
        if ($var->fetchColumn()) jsonError('Bu e-posta adresiyle zaten bir portal kişisi kayıtlı.');

        $ins = $db->prepare("INSERT INTO musteri_kontaklari (musteri_id, ad_soyad, email, telefon) VALUES (?,?,?,?)");
        $ins->execute([$musteriId, $adSoyad, $email, $telefon]);
        $id = (int)$db->lastInsertId();

        $link = _davetGonder($db, ['id' => $id, 'ad_soyad' => $adSoyad, 'email' => $email], $gitti);
        logla($db, 'musteri_kontaklari', $id, 'ekle', null, ['ad_soyad' => $adSoyad, 'email' => $email], "Portal davet gönderildi: $adSoyad");
        jsonOk(['id' => $id, 'link' => $link, 'eposta_gitti' => $gitti], _davetMesaji($gitti, 'Davet gönderildi.'));
        break;

    case 'PUT':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $body = govdeOku();
        $durum = trim(metin($body['durum'] ?? ''));
        if (!in_array($durum, ['aktif', 'pasif'], true)) jsonError('Geçersiz durum.');

        $st = $db->prepare("SELECT k.*, m.sube FROM musteri_kontaklari k JOIN musteriler m ON m.id=k.musteri_id WHERE k.id=?");
        $st->execute([$id]);
        $k = $st->fetch();
        if (!$k) jsonError('Kişi bulunamadı.', 404);
        subeErisimDogrula($k['sube']);

        $db->prepare("UPDATE musteri_kontaklari SET durum=?, updated_at=datetime('now','localtime') WHERE id=?")
           ->execute([$durum, $id]);
        logla($db, 'musteri_kontaklari', $id, 'guncelle', ['durum' => $k['durum']], ['durum' => $durum],
            ($durum === 'pasif' ? 'Portal erişimi kapatıldı' : 'Portal erişimi açıldı') . ": " . $k['ad_soyad']);
        jsonOk(null, $durum === 'pasif' ? 'Erişim kapatıldı.' : 'Erişim açıldı.');
        break;

    default:
        jsonError('Desteklenmeyen metod.', 405);
}
