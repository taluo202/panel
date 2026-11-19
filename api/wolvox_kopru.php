<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/wolvox-kopru-lib.php';




$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) jsonError('Method Not Allowed', 405);

$db = getDb();
$ip = istekIp();
if (kopruCokDeneme($db, $ip)) jsonError('Çok fazla hatalı anahtar denemesi. 10 dakika bekleyin.', 429);

$gelen = trim((string)($_SERVER['HTTP_X_TALOO_KOPRU'] ?? ''));
if (!kopruAnahtarGecerli(kopruAyarOku($db, 'wolvox_kopru_ozet'), $gelen)) {
    if ($gelen !== '') {
        logAttempt(kopruDenemeAdi($ip), '', false);
        guvenlikLog('kopru_hatali_anahtar', KOPRU_KIM);
    }
    jsonError('Köprü anahtarı yok ya da geçersiz.', 401);
}

$oncekiTemas = kopruAyarOku($db, 'wolvox_kopru_son_temas') ?: null;
$yeniEkle = kopruAyarOku($db, 'wolvox_kopru_yeni_cari_ekle') === '1';

if ($method === 'GET') {
    if (($_GET['action'] ?? '') !== 'durum') jsonError('Geçersiz istek.', 400);
    kopruAyarYaz($db, 'wolvox_kopru_son_temas', date('Y-m-d H:i:s'));
    jsonOk(['surum' => KOPRU_SURUM, 'son_temas' => $oncekiTemas, 'yeni_cari_ekle' => $yeniEkle]);
}

if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > KOPRU_EN_COK_GOVDE) jsonError('İstek gövdesi çok büyük.', 413);
$ham = (string)file_get_contents('php://input', false, null, 0, KOPRU_EN_COK_GOVDE + 1);
if (strlen($ham) > KOPRU_EN_COK_GOVDE) jsonError('İstek gövdesi çok büyük.', 413);
$govde = govdeCoz($ham, 'application/json');
if ($govde === null || !$govde) jsonError('Geçersiz istek gövdesi.', 400);
$sorun = kopruIstekHatasi($govde);
if ($sorun) jsonError($sorun[1], $sorun[0]);

$sonuc = kopruCariUygula($db, $govde['cariler'], $yeniEkle);

$simdi = date('Y-m-d H:i:s');
$kaynak = _kopruMetin($govde['kaynak'] ?? '', 64);
kopruAyarYaz($db, 'wolvox_kopru_son_temas', $simdi);
kopruAyarYaz($db, 'wolvox_kopru_son_sonuc', json_encode(
    ['tarih' => $simdi, 'kaynak' => $kaynak, 'gelen' => count($govde['cariler'])] + array_diff_key($sonuc, ['hatalar' => 1])
    + ['hata_sayisi' => count($sonuc['hatalar']), 'hatalar' => array_slice($sonuc['hatalar'], 0, 10)],
    JSON_UNESCAPED_UNICODE));

$yazilan = $sonuc['guncellenen'] + $sonuc['pasife_alinan'] + $sonuc['aktife_alinan'] + $sonuc['eklenen'];
if ($yazilan > 0) {
    logla($db, 'musteriler', null, 'guncelle', null, null,
          'Wolvox köprüsü gönderimi' . ($kaynak !== '' ? " ($kaynak)" : '') . ': ' . count($govde['cariler']) . ' cari; '
          . $sonuc['guncellenen'] . ' güncellendi, ' . $sonuc['pasife_alinan'] . ' pasife, ' . $sonuc['aktife_alinan'] . ' aktife alındı, '
          . $sonuc['eklenen'] . ' eklendi', KOPRU_KIM);
}
jsonOk($sonuc);
