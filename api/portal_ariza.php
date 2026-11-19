<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/whatsapp.php';
require_once __DIR__ . '/servis-talep-lib.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$oturum = portalRequireAuth();
$db = getDb();

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'liste') {
    header('Cache-Control: no-store');
    jsonOk(['list' => array_map('stPortalGorunum', stMusteriTalepleri($db, (int)$oturum['musteri_id']))]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method Not Allowed', 405);
verifyCsrf();

$body = govdeOku();

$kiralamaId = (int)($body['kiralama_id'] ?? 0);
$aciklama   = trim(metin($body['aciklama'] ?? ''));
if ($kiralamaId < 1) jsonError('Kiralama seçimi zorunludur.');
if ($aciklama === '') jsonError('Arıza açıklaması zorunludur.');

const PORTAL_ARIZA_FOTO_MAX = 5;
$fotoHam = $body['fotograflar'] ?? [];
if (!is_array($fotoHam)) $fotoHam = [];
if (count($fotoHam) > PORTAL_ARIZA_FOTO_MAX) jsonError('En fazla ' . PORTAL_ARIZA_FOTO_MAX . ' fotoğraf eklenebilir.');
$fotolar = [];
foreach ($fotoHam as $i => $f) {
    if (!is_string($f) || !preg_match('/^data:image\/(jpe?g|png|webp);base64,([a-zA-Z0-9+\/=]+)$/', $f, $m)) jsonError(($i + 1) . '. fotoğraf okunamadı (JPG, PNG veya WEBP olmalı).');
    $ikili = base64_decode($m[2], true);
    if ($ikili !== false && ($_gh = gorselBoyutHatasi($ikili))) jsonError(($i + 1) . '. fotoğraf: ' . $_gh);
    if ($ikili === false || strlen($ikili) < 100 || strlen($ikili) > 5 * 1024 * 1024 || !@getimagesizefromstring($ikili)) {
        jsonError(($i + 1) . '. fotoğraf geçerli bir görsel değil ya da 5 MB\'tan büyük.');
    }
    $fotolar[] = [$m[1] === 'jpg' ? 'jpeg' : $m[1], $ikili];
}

$kSt = $db->prepare("SELECT id, makine_tur, makine_id, ekipman_adi, durum FROM kiralamalar WHERE id=? AND musteri_id=?");
$kSt->execute([$kiralamaId, $oturum['musteri_id']]);
$kira = $kSt->fetch();
if (!$kira) jsonError('Kiralama bulunamadı.', 404);
if ($kira['durum'] !== 'aktif') jsonError('Sadece aktif kiralamalar için arıza bildirilebilir.');
if (!$kira['makine_tur'] || !$kira['makine_id']) jsonError('Bu kiralamaya bağlı bir makine bulunamadı.');

$kontakSt = $db->prepare("SELECT ad_soyad, telefon FROM musteri_kontaklari WHERE id=?");
$kontakSt->execute([$oturum['kontak_id']]);
$kontak = $kontakSt->fetch(PDO::FETCH_ASSOC) ?: [];
$kontakAd = (string)($kontak['ad_soyad'] ?? '') ?: '#' . $oturum['kontak_id'];

$db->beginTransaction();
try {
    $talepId = stTalepAc($db, ['kaynak' => 'portal', 'musteri_id' => (int)$oturum['musteri_id'], 'kontak_id' => (int)$oturum['kontak_id'], 'kiralama_id' => $kiralamaId,
                               'sikayet' => $aciklama, 'arayan_ad' => $kontakAd, 'arayan_tel' => (string)($kontak['telefon'] ?? '') ?: null]);
    $db->commit();
} catch (DomainException $e) {
    if ($db->inTransaction()) $db->rollBack();
    jsonError($e->getMessage(), 409);
}

$fotoSayisi = 0; $yollar = [];
if ($fotolar) {
    $dizin = UPLOAD_PATH . 'servis-talep/' . $talepId . '/';
    if (is_dir($dizin) || mkdir($dizin, 0755, true)) {
        foreach ($fotolar as [$uzanti, $ikili]) {
            $ad = bin2hex(random_bytes(16)) . '.' . $uzanti;
            if (file_put_contents($dizin . $ad, $ikili) === false) continue;
            @chmod($dizin . $ad, 0644);
            $yollar[] = 'servis-talep/' . $talepId . '/' . $ad;
            $fotoSayisi++;
        }
    }
    if ($yollar) stFotograflarYaz($db, $talepId, $yollar);
}

$talep = stTalep($db, $talepId);
$makineAd = $talep['makine_ad'] ?? ($kira['ekipman_adi'] ?: 'Makine');
waMusteriBildir($db, (int)$oturum['musteri_id'], (int)$oturum['kontak_id'], 'ariza_alindi',
    $makineAd . ' için servis talebiniz alındı (talep no #' . $talepId . '). Ekibimiz planlayıp bilgi verecek; durumu portalda Taleplerim\'den izleyebilirsiniz.');
waOfisBildir($db, 'ariza_bildirimi', 'Portaldan servis talebi: ' . ($talep['musteri_unvan'] ?? 'müşteri') . ' — ' . $makineAd
    . ' (' . ($kira['ekipman_adi'] ?: '') . '). ' . $kontakAd . ': ' . mb_substr($aciklama, 0, 300)
    . ($fotoSayisi ? ' [' . $fotoSayisi . ' fotoğraf]' : '') . ' → ' . waPanelLink('teknik-servis.html?sekme=talep&talep=' . $talepId));

if ($fotoSayisi < count($fotolar)) {
    jsonOk(['talep_id' => $talepId, 'foto' => $fotoSayisi], 'Servis talebiniz alındı; fotoğrafların bir kısmı kaydedilemedi.');
}
jsonOk(['talep_id' => $talepId, 'foto' => $fotoSayisi], 'Servis talebiniz alındı; ekibimiz planlayıp bilgi verecek.');
