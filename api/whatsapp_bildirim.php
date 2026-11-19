<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/whatsapp.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('ayarlar');
$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $ay = waAyar($db);
    $liste = $db->query("SELECT l.id, l.olay, l.alici, l.kanal, l.telefon, l.mesaj, l.durum, l.hata, l.created_at, l.gonderim_tarihi,
                                m.unvan AS musteri_unvan, k.ad_soyad AS kontak_ad
                         FROM whatsapp_log l
                         LEFT JOIN musteriler m ON m.id = l.musteri_id
                         LEFT JOIN musteri_kontaklari k ON k.id = l.kontak_id
                         ORDER BY l.id DESC LIMIT 100")->fetchAll();
    $izinli = (int)$db->query("SELECT COUNT(*) FROM musteri_kontaklari WHERE durum='aktif' AND wa_izin=1")->fetchColumn();
    jsonOk(['liste' => $liste, 'ozet' => [
        'aktif' => $ay['aktif'], 'sablon' => $ay['sablon'], 'ofis_aktif' => $ay['ofis_aktif'] && $ay['ofis_no'] !== '',
        'eski_kanal' => $ay['eski_aktif'], 'izinli_kisi' => $izinli,
    ]]);
}

if ($method !== 'POST') jsonError('Method Not Allowed', 405);
verifyCsrf();
if (($_GET['action'] ?? '') !== 'test') jsonError('Geçersiz işlem.');
$body = govdeOku();
$tel = waTelefon(trim(metin($body['telefon'] ?? '')));
if ($tel === null) jsonError('Geçerli bir numara yazın (ör. 0532 123 45 67 ya da 905321234567).');
$ay = waAyar($db);
if ($ay['token'] === '' || $ay['phone_id'] === '') jsonError('Önce erişim anahtarını ve telefon numarası kimliğini kaydedin.');

$mesaj = 'Bu bir deneme mesajıdır. Müşteri portalı bildirimleri bu kanaldan gönderilecek.';
$ad = trim((string)($_SESSION['ad_soyad'] ?? '')) ?: 'Yönetici';
$r = waBulutGonder($ay, $tel, $ad, $mesaj);
$db->prepare("INSERT INTO whatsapp_log (olay, alici, kanal, telefon, mesaj, durum, hata, mesaj_id, gonderim_tarihi) VALUES ('test','ofis','cloud',?,?,?,?,?,datetime('now','localtime'))")
   ->execute([$tel, $mesaj, $r['ok'] ? 'gonderildi' : 'hata', $r['ok'] ? null : $r['hata'], $r['mesaj_id']]);
logla($db, 'whatsapp_log', (int)$db->lastInsertId(), 'ekle', null, null, 'WhatsApp deneme mesajı: ' . $tel . ' → ' . ($r['ok'] ? 'gönderildi' : 'hata'));
if (!$r['ok']) jsonError('Gönderilemedi: ' . $r['hata'], 502);
jsonOk(['mesaj_id' => $r['mesaj_id']], 'Deneme mesajı gönderildi. Telefonunuzu kontrol edin.');
