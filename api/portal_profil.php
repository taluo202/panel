<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/whatsapp.php';

$oturum = portalRequireAuth();
$kid = (int)$oturum['kontak_id'];
$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

function _ppProfil(PDO $db, int $kid): array {
    $st = $db->prepare("SELECT k.ad_soyad, k.email, k.telefon, k.son_giris, k.wa_izin, k.wa_izin_tarihi, m.unvan AS musteri_unvan
                        FROM musteri_kontaklari k JOIN musteriler m ON m.id = k.musteri_id WHERE k.id = ?");
    $st->execute([$kid]);
    $p = $st->fetch() ?: [];
    $p['wa_aktif'] = waAyar($db)['aktif'];
    return $p;
}

if ($method === 'GET') jsonOk(_ppProfil($db, $kid));
if ($method !== 'POST') jsonError('Method Not Allowed', 405);
verifyCsrf();
$body = govdeOku();
$action = (string)($_GET['action'] ?? '');

if ($action === 'profil') {
    $ad = preg_replace('/\s+/u', ' ', trim(metin($body['ad_soyad'] ?? ''))) ?? '';
    $tel = trim(metin($body['telefon'] ?? ''));
    if (mb_strlen($ad) < 3 || mb_strlen($ad) > 100) jsonError('Ad soyad 3–100 karakter olmalı.');
    if ($tel !== '') {
        if (!preg_match('/^[0-9 +()\-]{7,25}$/', $tel)) jsonError('Telefon yalnız rakam, boşluk, +, ( ) ve - içerebilir.');
        $rakam = preg_replace('/\D/', '', $tel);
        if (strlen($rakam) < 10 || strlen($rakam) > 15) jsonError('Telefon numarası 10–15 haneli olmalı (ör. 0532 123 45 67).');
    }
    $eski = logKayitAl($db, 'musteri_kontaklari', $kid);
    $waIzin = array_key_exists('wa_izin', $body) ? (!empty($body['wa_izin']) ? 1 : 0) : (int)($eski['wa_izin'] ?? 0);
    if ($waIzin && waTelefon($tel) === null) jsonError('WhatsApp bildirimleri için geçerli bir cep telefonu yazın.');
    $izinTarihi = $waIzin ? (((int)($eski['wa_izin'] ?? 0)) ? ($eski['wa_izin_tarihi'] ?? date('Y-m-d H:i:s')) : date('Y-m-d H:i:s')) : null;
    $db->prepare("UPDATE musteri_kontaklari SET ad_soyad=?, telefon=?, wa_izin=?, wa_izin_tarihi=?, updated_at=datetime('now','localtime') WHERE id=?")
       ->execute([$ad, $tel, $waIzin, $izinTarihi, $kid]);
    $yeni = logKayitAl($db, 'musteri_kontaklari', $kid);
    unset($eski['sifre_hash'], $yeni['sifre_hash'], $eski['sifre_degisti'], $yeni['sifre_degisti']);
    logla($db, 'musteri_kontaklari', $kid, 'guncelle', $eski, $yeni, 'Portal: kişi kendi profilini güncelledi');
    jsonOk(_ppProfil($db, $kid), 'Bilgileriniz kaydedildi.');
}

if ($action === 'sifre') {
    $mevcut = (string)($body['mevcut'] ?? '');
    $yeni   = (string)($body['yeni'] ?? '');
    $yeni2  = (string)($body['yeni2'] ?? '');
    if ($mevcut === '' || $yeni === '') jsonError('Mevcut ve yeni şifre zorunludur.');
    if ($yeni !== $yeni2) jsonError('Yeni şifreler eşleşmiyor.');
    if ($yeni === $mevcut) jsonError('Yeni şifre mevcut şifreyle aynı olamaz.');

    $st = $db->prepare("SELECT email, sifre_hash FROM musteri_kontaklari WHERE id=?");
    $st->execute([$kid]);
    $k = $st->fetch();
    $ip = istekIp();
    portalCheckRateLimit((string)$k['email'], $ip);
    if (empty($k['sifre_hash']) || !password_verify($mevcut, (string)$k['sifre_hash'])) {
        portalLogAttempt((string)$k['email'], $ip, false);
        guvenlikLog('portal_sifre_degistir_hatali', (string)$k['email'], $kid);
        jsonError('Mevcut şifre hatalı.', 400);
    }
    parolaDogrula($yeni, (string)$k['email']);
    $db->prepare("UPDATE musteri_kontaklari SET sifre_hash=?, updated_at=datetime('now','localtime') WHERE id=?")
       ->execute([password_hash($yeni, PASSWORD_BCRYPT, ['cost' => 12]), $kid]);
    $_SESSION['portal_sifre_damga'] = portalSifreDamgaGuncelle($db, $kid);
    session_regenerate_id(true);
    guvenlikLog('portal_sifre_degisti', (string)$k['email'], $kid);
    jsonOk(['csrf_token' => csrfToken()], 'Şifreniz değiştirildi. Diğer cihazlardaki oturumlarınız kapatıldı.');
}

jsonError('Geçersiz işlem.');
