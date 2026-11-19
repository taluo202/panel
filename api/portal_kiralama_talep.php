<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/whatsapp.php';

$oturum = portalRequireAuth();
$mid = (int)$oturum['musteri_id'];
$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $st = $db->prepare("
        SELECT t.id, t.kiralama_id, t.tur, t.istenen_tarih, t.eski_bitis, t.onaylanan_tarih, t.adres, t.aciklama,
               t.durum, t.karar_notu, t.karar_tarihi, t.created_at, k.kod AS kiralama_kod, k.ekipman_adi
        FROM portal_kiralama_talepleri t JOIN kiralamalar k ON k.id = t.kiralama_id
        WHERE t.musteri_id = ? AND k.musteri_id = ?
        ORDER BY t.id DESC LIMIT 100");
    $st->execute([$mid, $mid]);
    jsonOk($st->fetchAll());
}

if ($method !== 'POST') jsonError('Method Not Allowed', 405);
verifyCsrf();
$body = govdeOku();

$kSt = $db->prepare("SELECT ad_soyad FROM musteri_kontaklari WHERE id=?");
$kSt->execute([$oturum['kontak_id']]);
$kontakAd = (string)($kSt->fetchColumn() ?: ('#' . $oturum['kontak_id']));

if (($_GET['action'] ?? '') === 'iptal') {
    $id = (int)($_GET['id'] ?? 0);
    $st = $db->prepare("SELECT * FROM portal_kiralama_talepleri WHERE id=? AND musteri_id=?");
    $st->execute([$id, $mid]);
    $t = $st->fetch();
    if (!$t) jsonError('Talep bulunamadı.', 404);
    if ($t['durum'] !== 'bekliyor') jsonError('Yalnız sonuçlanmamış talep geri çekilebilir.', 409);
    $db->prepare("UPDATE portal_kiralama_talepleri SET durum='iptal', karar_veren=?, karar_tarihi=datetime('now','localtime')
                  WHERE id=? AND durum='bekliyor'")->execute(['Müşteri: ' . $kontakAd, $id]);
    portalTalepGorevKapat($db, (string)$t['tur'], $id);
    logla($db, 'portal_kiralama_talepleri', $id, 'guncelle', ['durum' => 'bekliyor'], ['durum' => 'iptal'],
        'Portal talebi müşteri tarafından geri çekildi (' . $kontakAd . ')');
    waOfisBildir($db, 'portal_talep_iptal', $kontakAd . ' ' . mb_strtolower(PORTAL_KT_TURLER[$t['tur']] ?? 'talep') . ' talebini geri çekti (#' . $id . ').');
    jsonOk(null, 'Talebiniz geri çekildi.');
}

$kid   = (int)($body['kiralama_id'] ?? 0);
$tur   = (string)($body['tur'] ?? '');
$tarih = dateStr('istenen_tarih', $body);
$aciklama = mb_substr(trim(metin($body['aciklama'] ?? '')), 0, 1000);
$adres    = mb_substr(trim(metin($body['adres'] ?? '')), 0, 500);
if (!isset(PORTAL_KT_TURLER[$tur])) jsonError('Geçersiz talep türü.');
if ($kid < 1) jsonError('Kiralama seçimi zorunludur.');
if (!$tarih) jsonError($tur === 'uzatma' ? 'Yeni bitiş tarihini seçin.' : 'Toplama tarihini seçin.');

$st = $db->prepare("SELECT id, kod, ekipman_adi, baslangic, bitis, gercek_bitis, durum, sube, lokasyon
                    FROM kiralamalar WHERE id=? AND musteri_id=?");
$st->execute([$kid, $mid]);
$k = $st->fetch();
if (!$k) jsonError('Kiralama bulunamadı.', 404);
if ($k['durum'] !== 'aktif' || !empty($k['gercek_bitis'])) jsonError('Talep yalnız devam eden kiralamalar için açılabilir.');

$bugun = date('Y-m-d');
$ustSinir = date('Y-m-d', strtotime(($k['bitis'] ?: $bugun) . ' +1 year'));
if ($tur === 'uzatma') {
    if (!$k['bitis']) jsonError('Bu kiralama süresiz (bitiş tarihi yok); uzatma gerekmiyor.');
    if ($tarih <= $k['bitis']) jsonError('Yeni bitiş tarihi mevcut bitişten (' . tarihGoster($k['bitis']) . ') sonra olmalı.');
    if ($tarih < $bugun) jsonError('Yeni bitiş tarihi geçmişte olamaz.');
} else {
    if ($tarih < $bugun) jsonError('Toplama tarihi bugünden önce olamaz.');
    if ($k['bitis'] && $k['bitis'] >= $bugun && $tarih > $k['bitis']) {
        jsonError('Toplama tarihi kiralama bitişinden (' . tarihGoster($k['bitis']) . ') sonra olamaz. Daha uzun kullanım için önce süre uzatma talep edin.');
    }
}
if ($tarih > $ustSinir) jsonError('Bir yıldan uzun ileri tarih portaldan talep edilemez; lütfen temsilcinizle görüşün.');

$st = $db->prepare("SELECT 1 FROM portal_kiralama_talepleri WHERE kiralama_id=? AND tur=? AND durum='bekliyor'");
$st->execute([$kid, $tur]);
if ($st->fetchColumn()) jsonError('Bu kiralama için sonuçlanmamış bir ' . mb_strtolower(PORTAL_KT_TURLER[$tur]) . ' talebiniz zaten var.', 409);

$db->prepare("INSERT INTO portal_kiralama_talepleri (kiralama_id, musteri_id, kontak_id, tur, istenen_tarih, eski_bitis, adres, aciklama)
              VALUES (?,?,?,?,?,?,?,?)")
   ->execute([$kid, $mid, $oturum['kontak_id'], $tur, $tarih, $k['bitis'] ?: null, $adres !== '' ? $adres : null, $aciklama !== '' ? $aciklama : null]);
$talepId = (int)$db->lastInsertId();

$mSt = $db->prepare("SELECT unvan FROM musteriler WHERE id=?");
$mSt->execute([$mid]);
$unvan = (string)($mSt->fetchColumn() ?: ('#' . $mid));
$kod = $k['kod'] ?: ('#' . $kid);

if ($tur === 'uzatma') {
    $baslik = 'Süre uzatma talebi: ' . $kod . ' — ' . $unvan;
    $ozet = $kontakAd . ' bitişin ' . tarihGoster($k['bitis']) . ' → ' . tarihGoster($tarih) . ' uzatılmasını istiyor ('
          . ($k['ekipman_adi'] ?: 'ekipman') . '). Onay: sistem yöneticisi veya ' . (trim((string)$k['sube']) ?: 'kiralamanın') . ' şube yetkilisi.';
    $sonTarih = $k['bitis'] >= $bugun ? $k['bitis'] : $bugun;
} else {
    $baslik = 'İade / toplama talebi: ' . $kod . ' — ' . $unvan;
    $ozet = $kontakAd . ' makinenin ' . tarihGoster($tarih) . ' tarihinde toplanmasını istiyor (' . ($k['ekipman_adi'] ?: 'ekipman') . ').'
          . "\nAdres: " . ($adres !== '' ? $adres : ($k['lokasyon'] ?: 'kiralama lokasyonu'));
    $sonTarih = $tarih;
}
if ($aciklama !== '') $ozet .= "\nNot: " . $aciklama;
$gid = gorevOlustur($db, $baslik, $ozet, PORTAL_KT_GOREV[$tur], $talepId, 'yuksek');
$db->prepare("UPDATE gorevler SET son_tarih=? WHERE id=?")->execute([$sonTarih, $gid]);

logla($db, 'portal_kiralama_talepleri', $talepId, 'ekle', null,
    ['kiralama_id' => $kid, 'tur' => $tur, 'istenen_tarih' => $tarih, 'eski_bitis' => $k['bitis']],
    'Portal: ' . mb_strtolower(PORTAL_KT_TURLER[$tur]) . ' talebi — ' . $kod . ' (' . $kontakAd . ')');

waOfisBildir($db, $tur === 'uzatma' ? 'portal_uzatma_talebi' : 'portal_iade_talebi',
    $baslik . '. ' . preg_replace('/\s*\n\s*/', ' · ', $ozet) . ' → ' . waPanelLink('kiralama-talepleri.html?ac=' . $talepId));

jsonOk(['id' => $talepId], $tur === 'uzatma'
    ? 'Uzatma talebiniz alındı. Yetkili onayladığında kiralamanızın bitişi güncellenecek.'
    : 'Toplama talebiniz alındı. Planlandığında buradan görebilirsiniz.');
