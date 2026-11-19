<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/whatsapp.php';

requireAuth();
requirePermission('kiralama_takip', 'oku');
$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

function _ktSatir(PDO $db, int $id): ?array {
    $st = $db->prepare("
        SELECT t.*, k.kod AS kiralama_kod, k.ekipman_adi, k.baslangic, k.bitis AS guncel_bitis, k.gercek_bitis,
               k.durum AS kiralama_durum, k.sube, k.lokasyon, k.makine_tur, k.makine_id, k.siparis_no,
               m.unvan AS musteri_unvan, c.ad_soyad AS kontak_ad, c.telefon AS kontak_telefon, c.email AS kontak_email
        FROM portal_kiralama_talepleri t
        JOIN kiralamalar k ON k.id = t.kiralama_id
        LEFT JOIN musteriler m ON m.id = t.musteri_id
        LEFT JOIN musteri_kontaklari c ON c.id = t.kontak_id
        WHERE t.id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

if ($method === 'GET') {
    $where = []; $params = [];
    $durum = (string)($_GET['durum'] ?? 'bekliyor');
    if ($durum === 'bekliyor') $where[] = "t.durum = 'bekliyor'";
    elseif ($durum === 'sonuc') $where[] = "t.durum <> 'bekliyor'";
    subeKosulEkle($where, $params, 'k.sube');
    $st = $db->prepare("
        SELECT t.*, k.kod AS kiralama_kod, k.ekipman_adi, k.baslangic, k.bitis AS guncel_bitis, k.gercek_bitis,
               k.durum AS kiralama_durum, k.sube, k.lokasyon, k.makine_tur, k.makine_id,
               m.unvan AS musteri_unvan, c.ad_soyad AS kontak_ad, c.telefon AS kontak_telefon, c.email AS kontak_email
        FROM portal_kiralama_talepleri t
        JOIN kiralamalar k ON k.id = t.kiralama_id
        LEFT JOIN musteriler m ON m.id = t.musteri_id
        LEFT JOIN musteri_kontaklari c ON c.id = t.kontak_id"
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . "
        ORDER BY (t.durum = 'bekliyor') DESC, t.istenen_tarih ASC, t.id DESC LIMIT 300");
    $st->execute($params);
    $liste = $st->fetchAll();
    foreach ($liste as &$r) {
        $r['karar_yetkisi'] = $r['durum'] === 'bekliyor' && portalTalepKararYetkisi((string)$r['tur'], $r['sube']);
        $r['cakisma'] = [];
        $r['engel'] = null;
        if ($r['durum'] === 'bekliyor' && $r['tur'] === 'uzatma' && $r['kiralama_durum'] === 'aktif') {
            $kk = kiralamaKaydiKontrol($db, $r['makine_tur'] ?: null, ((int)$r['makine_id']) ?: null,
                $r['baslangic'], $r['istenen_tarih'], (int)$r['kiralama_id']);
            $r['engel'] = $kk['engel'];
            $gizle = subeKisiti() !== null;
            $r['cakisma'] = array_map(fn($c) => ['kod' => $gizle ? null : ($c['kod'] ?? null),
                'musteri_unvan' => $gizle ? 'Kayıtlı rezervasyon' : ($c['musteri_unvan'] ?? ''),
                'baslangic' => $c['baslangic'] ?? null, 'bitis' => $c['bitis'] ?? null], $kk['cakisma']);
        }
    }
    unset($r);
    $bekleyen = count(array_filter($liste, fn($r) => $r['durum'] === 'bekliyor'));
    jsonOk(['list' => $liste, 'bekleyen' => $bekleyen, 'admin' => ($_SESSION['rol'] ?? '') === 'admin', 'sube' => subeKisitiHam()]);
}

if ($method !== 'POST') jsonError('Method Not Allowed', 405);
verifyCsrf();
$action = (string)($_GET['action'] ?? '');
if (!in_array($action, ['onayla', 'reddet'], true)) jsonError('Geçersiz işlem.');
$id = (int)($_GET['id'] ?? 0);
$body = govdeOku();
$t = _ktSatir($db, $id);
if (!$t) jsonError('Talep bulunamadı.', 404);
subeKayitDogrula($db, 'kiralamalar', (int)$t['kiralama_id']);
$tur = (string)$t['tur'];
if (!portalTalepKararYetkisi($tur, $t['sube'])) {
    jsonError($tur === 'uzatma'
        ? 'Süre uzatma talebini yalnız sistem yöneticisi ya da kiralamanın şube yetkilisi (' . (trim((string)$t['sube']) ?: 'şube girilmemiş — yalnız yönetici') . ') sonuçlandırabilir.'
        : 'Bu talebi sonuçlandırmak için kiralama yazma yetkisi gerekir.', 403);
}
if ($t['durum'] !== 'bekliyor') jsonError('Bu talep zaten sonuçlanmış.', 409);

$not = mb_substr(trim(metin($body['not'] ?? '')), 0, 1000);
$kimAd = (string)($_SESSION['ad_soyad'] ?? '') ?: (string)($_SESSION['username'] ?? 'kullanıcı');
$kod = $t['kiralama_kod'] ?: ('#' . $t['kiralama_id']);
$kid = (int)$t['kiralama_id'];

if ($action === 'reddet') {
    if ($not === '') jsonError('Ret gerekçesini yazın; müşteri portalda görecek.');
    islemYanit($db, function (PDO $db) use ($kimAd, $not, $id, $tur, $kod, $t): void {
        $u = $db->prepare("UPDATE portal_kiralama_talepleri SET durum='reddedildi', karar_veren=?, karar_notu=?, karar_tarihi=datetime('now','localtime')
                      WHERE id=? AND durum='bekliyor'");
        $u->execute([$kimAd, $not, $id]);
        if ($u->rowCount() !== 1) throw new DomainException('Bu talep zaten sonuçlanmış.', 409);
        portalTalepGorevKapat($db, $tur, $id);
        logla($db, 'portal_kiralama_talepleri', $id, 'guncelle', ['durum' => 'bekliyor'], ['durum' => 'reddedildi', 'karar_notu' => $not],
            PORTAL_KT_TURLER[$tur] . ' talebi reddedildi — ' . $kod . ' (kiralama değişmedi)');
        waMusteriBildir($db, (int)$t['musteri_id'], ((int)$t['kontak_id']) ?: null, $tur === 'uzatma' ? 'uzatma_red' : 'iade_red',
            $kod . ' (' . ($t['ekipman_adi'] ?: 'kiralama') . ') için ' . mb_strtolower(PORTAL_KT_TURLER[$tur]) . ' talebiniz onaylanmadı. Açıklama: ' . $not
            . ($tur === 'uzatma' ? ' Kiralamanızın bitişi ' . tarihGoster($t['guncel_bitis']) . ' olarak kalıyor.' : '') . ' Ayrıntı: ' . waPortalLink('talep'));
    });
    jsonOk(null, 'Talep reddedildi; kiralama değiştirilmedi.');
}

if ($t['kiralama_durum'] !== 'aktif' || !empty($t['gercek_bitis'])) {
    jsonError('Kiralama artık devam etmiyor (' . $t['kiralama_durum'] . '); talebi reddedin.', 409);
}

if ($tur === 'uzatma') {
    $yeni = (string)$t['istenen_tarih'];
    if ($t['guncel_bitis'] && $yeni <= $t['guncel_bitis']) {
        jsonError('Kiralamanın bitişi zaten ' . tarihGoster($t['guncel_bitis']) . '; bu talep artık gereksiz — gerekçesiyle reddedin.', 409);
    }
    kiralamaTarihDogrula($t['baslangic'], $yeni, null);
    kiralamaKaydiDogrula($db, $t['makine_tur'] ?: null, ((int)$t['makine_id']) ?: null, $t['baslangic'], $yeni,
        !empty($body['cakisma_onaylandi']), $kid);

    $eski = logKayitAl($db, 'kiralamalar', $kid);
    try {
        islemIcinde($db, function (PDO $db) use ($yeni, $kid, $t, $kimAd, $not, $id, $tur, $eski, $kod): void {
        $u = $db->prepare("UPDATE kiralamalar SET bitis=?, updated_at=datetime('now','localtime')
                           WHERE id=? AND durum='aktif' AND gercek_bitis IS NULL AND COALESCE(bitis,'') = COALESCE(?, '')");
        $u->execute([$yeni, $kid, $t['guncel_bitis']]);
        if ($u->rowCount() !== 1) throw new RuntimeException('Kiralama bu sırada değişti; sayfayı yenileyip tekrar deneyin.');
        $tu = $db->prepare("UPDATE portal_kiralama_talepleri SET durum='onaylandi', onaylanan_tarih=?, karar_veren=?, karar_notu=?, karar_tarihi=datetime('now','localtime')
                      WHERE id=? AND durum='bekliyor'");
        $tu->execute([$yeni, $kimAd, $not !== '' ? $not : null, $id]);
        if ($tu->rowCount() !== 1) throw new RuntimeException('Bu talep zaten sonuçlanmış.');
        portalTalepGorevKapat($db, $tur, $id);
        logla($db, 'kiralamalar', $kid, 'guncelle', $eski, logKayitAl($db, 'kiralamalar', $kid),
            'Portal süre uzatma talebi onaylandı: ' . tarihGoster($t['guncel_bitis']) . ' → ' . tarihGoster($yeni) . ' (' . $kimAd . ')');
        logla($db, 'portal_kiralama_talepleri', $id, 'guncelle', ['durum' => 'bekliyor'], ['durum' => 'onaylandi', 'onaylanan_tarih' => $yeni],
            'Süre uzatma talebi onaylandı — ' . $kod);
        if (!empty($t['siparis_no'])) siparisNakliyeSenkron($db, (string)$t['siparis_no']);
        });
    } catch (\Throwable $e) {
        if (!($e instanceof RuntimeException)) error_log('portal uzatma onayi: ' . $e->getMessage());
        jsonError($e instanceof RuntimeException ? $e->getMessage() : 'Uzatma kaydedilemedi.', 409);
    }
    waMusteriBildir($db, (int)$t['musteri_id'], ((int)$t['kontak_id']) ?: null, 'uzatma_onay',
        $kod . ' (' . ($t['ekipman_adi'] ?: 'kiralama') . ') için süre uzatma talebiniz onaylandı. Yeni bitiş tarihi: ' . tarihGoster($yeni) . '.'
        . ($not !== '' ? ' Not: ' . $not : ''));
    jsonOk(['bitis' => $yeni], 'Onaylandı: kiralamanın bitişi ' . tarihGoster($yeni) . ' oldu.');
}

$plan = dateStr('onaylanan_tarih', $body) ?: (string)$t['istenen_tarih'];
if ($plan < (string)$t['baslangic']) jsonError('Toplama tarihi kiralama başlangıcından önce olamaz.');
islemYanit($db, function (PDO $db) use ($plan, $kimAd, $not, $id, $tur, $kod, $t): void {
$tu = $db->prepare("UPDATE portal_kiralama_talepleri SET durum='onaylandi', onaylanan_tarih=?, karar_veren=?, karar_notu=?, karar_tarihi=datetime('now','localtime')
              WHERE id=? AND durum='bekliyor'");
$tu->execute([$plan, $kimAd, $not !== '' ? $not : null, $id]);
if ($tu->rowCount() !== 1) throw new DomainException('Bu talep zaten sonuçlanmış.', 409);
portalTalepGorevKapat($db, $tur, $id);
$gid = gorevOlustur($db, 'Toplama: ' . $kod . ' — ' . ($t['musteri_unvan'] ?: 'müşteri'),
    'Portal talebiyle planlandı (' . tarihGoster($plan) . '). Adres: ' . ($t['adres'] ?: ($t['lokasyon'] ?: '—'))
    . ($t['kontak_ad'] ? "\nİrtibat: " . $t['kontak_ad'] . ($t['kontak_telefon'] ? ' · ' . $t['kontak_telefon'] : '') : '')
    . ($not !== '' ? "\nNot: " . $not : ''), 'portal_toplama', $id, 'yuksek');
$db->prepare("UPDATE gorevler SET son_tarih=? WHERE id=?")->execute([$plan, $gid]);
logla($db, 'portal_kiralama_talepleri', $id, 'guncelle', ['durum' => 'bekliyor'], ['durum' => 'onaylandi', 'onaylanan_tarih' => $plan],
    'İade / toplama talebi planlandı — ' . $kod . ' (' . tarihGoster($plan) . ')');
waMusteriBildir($db, (int)$t['musteri_id'], ((int)$t['kontak_id']) ?: null, 'toplama_plan',
    $kod . ' (' . ($t['ekipman_adi'] ?: 'makine') . ') için toplama ' . tarihGoster($plan) . ' tarihine planlandı.' . ($not !== '' ? ' Not: ' . $not : '')
    . ' Lütfen makineyi teslime hazır tutun.');
});
jsonOk(['tarih' => $plan], 'Toplama ' . tarihGoster($plan) . ' tarihine planlandı; Yükleme / Toplama → Toplanacaklar\'da o gün görünür.');
