<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/whatsapp.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$oturum = portalRequireAuth();

$db = getDb();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $st = $db->prepare("
        SELECT t.id, t.ihtiyac_json, t.notlar, t.created_at,
               COALESCE(g.durum, 'acik') AS gorev_durum
        FROM musteri_talepleri t
        LEFT JOIN gorevler g ON g.kaynak_modul = 'musteri_talep' AND g.kaynak_id = t.id
        WHERE t.musteri_id = ?
        ORDER BY t.created_at DESC
    ");
    $st->execute([$oturum['musteri_id']]);
    jsonOk($st->fetchAll());
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method Not Allowed', 405);
verifyCsrf();

$body = govdeOku();

$ihtiyac = $body['ihtiyac'] ?? null;
if (!is_array($ihtiyac) || !$ihtiyac) jsonError('İhtiyaç bilgisi zorunludur.');
$notlar = trim(metin($body['notlar'] ?? ''));

$kSt = $db->prepare("SELECT ad_soyad, email FROM musteri_kontaklari WHERE id=?");
$kSt->execute([$oturum['kontak_id']]);
$kontak = $kSt->fetch();

$mSt = $db->prepare("SELECT unvan FROM musteriler WHERE id=?");
$mSt->execute([$oturum['musteri_id']]);
$musteri = $mSt->fetch();

$ins = $db->prepare("INSERT INTO musteri_talepleri (musteri_id, kontak_id, ihtiyac_json, notlar) VALUES (?,?,?,?)");
$ins->execute([$oturum['musteri_id'], $oturum['kontak_id'], json_encode($ihtiyac, JSON_UNESCAPED_UNICODE), $notlar]);
$talepId = (int)$db->lastInsertId();

$ihtiyacOzet = [];
if (!empty($ihtiyac['kategori'])) $ihtiyacOzet[] = 'Kategori: ' . $ihtiyac['kategori'];
if (!empty($ihtiyac['sure'])) $ihtiyacOzet[] = 'Süre: ' . $ihtiyac['sure'];
if (!empty($ihtiyac['adres'])) $ihtiyacOzet[] = 'Teslimat adresi: ' . $ihtiyac['adres'];
if (!empty($ihtiyac['aciklama'])) $ihtiyacOzet[] = 'Açıklama: ' . $ihtiyac['aciklama'];
$ozet = ($kontak['ad_soyad'] ?? '') . ' (' . ($kontak['email'] ?? '') . ') — ' . ($musteri['unvan'] ?? '') . "\n"
      . implode("\n", $ihtiyacOzet) . ($notlar !== '' ? "\nNot: " . $notlar : '');
gorevOlustur($db, 'Portal talebi: ' . ($musteri['unvan'] ?? '#' . $oturum['musteri_id']), $ozet, 'musteri_talep', $talepId, 'normal');
logla($db, 'musteri_talepleri', $talepId, 'ekle', null, ['musteri_id' => $oturum['musteri_id'], 'kontak_id' => $oturum['kontak_id']],
    'Portal talebi: ' . ($kontak['ad_soyad'] ?? '') . ' — ' . ($musteri['unvan'] ?? ''));

waOfisBildir($db, 'makine_talebi', 'Portaldan makine talebi: ' . preg_replace('/\s*\n\s*/', ' · ', $ozet));

jsonOk(['id' => $talepId], 'Talebiniz alındı, bir temsilcimiz sizinle iletişime geçecek.');
