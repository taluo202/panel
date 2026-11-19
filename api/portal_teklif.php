<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/whatsapp.php';

$oturum = portalRequireAuth();
$mid = (int)$oturum['musteri_id'];
$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

const PORTAL_TEKLIF_GORUNUR = "t.durum IN ('gonderildi','onaylandi','reddedildi','donustu') AND COALESCE(t.onay_durumu,'gerekmiyor') IN ('gerekmiyor','onaylandi')";

function _ptKalemler(PDO $db, array $ids): array {
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT teklif_id, aciklama, kira_tipi, miktar, sure, birim_ucret FROM teklif_kalemleri WHERE teklif_id IN ($ph) ORDER BY id");
    $st->execute($ids);
    $m = [];
    foreach ($st->fetchAll() as $k) { $m[(int)$k['teklif_id']][] = array_diff_key($k, ['teklif_id' => 1]); }
    return $m;
}

function _ptSatir(array $t, array $kalemler): array {
    $bugun = date('Y-m-d');
    $izin = ['id', 'kod', 'tarih', 'gecerlilik', 'durum', 'kdv_oran', 'notlar', 'sartlar', 'iskonto_oran', 'temsilci',
             'musteri_karar', 'musteri_karar_kisi', 'musteri_karar_tarihi', 'musteri_karar_notu',
             'musteri_unvan', 'yetkili', 'musteri_adres', 'vergi_dairesi', 'vergi_no'];
    $r = array_intersect_key($t, array_flip($izin));
    $r['kalemler'] = $kalemler;
    $r['hesap'] = teklifToplam($kalemler, (float)$t['kdv_oran'], (float)($t['iskonto_oran'] ?? 0));
    $r['suresi_doldu'] = !empty($t['gecerlilik']) && $t['gecerlilik'] < $bugun;
    $r['karar_verilebilir'] = $t['durum'] === 'gonderildi' && !$r['suresi_doldu'];
    return $r;
}

$sqlTemel = "SELECT t.*, m.unvan AS musteri_unvan, m.yetkili, m.adres AS musteri_adres, m.vergi_dairesi, m.vergi_no
             FROM teklifler t JOIN musteriler m ON m.id = t.musteri_id
             WHERE t.musteri_id = ? AND " . PORTAL_TEKLIF_GORUNUR;

if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $st = $db->prepare($sqlTemel . " AND t.id = ?");
        $st->execute([$mid, (int)$_GET['id']]);
        $t = $st->fetch();
        if (!$t) jsonError('Teklif bulunamadı.', 404);
        require_once __DIR__ . '/tutanak-yaz.php';
        jsonOk(_ptSatir($t, _ptKalemler($db, [(int)$t['id']])[(int)$t['id']] ?? []) + ['firma_imza' => firmaImzasi($db)]);
    }
    $st = $db->prepare($sqlTemel . " ORDER BY t.tarih DESC, t.id DESC LIMIT 100");
    $st->execute([$mid]);
    $rows = $st->fetchAll();
    $km = _ptKalemler($db, array_map('intval', array_column($rows, 'id')));
    jsonOk(array_map(fn($t) => _ptSatir($t, $km[(int)$t['id']] ?? []), $rows));
}

if ($method !== 'POST') jsonError('Method Not Allowed', 405);
verifyCsrf();
$action = (string)($_GET['action'] ?? '');
if (!in_array($action, ['onayla', 'reddet'], true)) jsonError('Geçersiz işlem.');
$id = (int)($_GET['id'] ?? 0);
$body = govdeOku();
$not = mb_substr(trim(metin($body['not'] ?? '')), 0, 1000);

$st = $db->prepare($sqlTemel . " AND t.id = ?");
$st->execute([$mid, $id]);
$t = $st->fetch();
if (!$t) jsonError('Teklif bulunamadı.', 404);
if ($t['durum'] !== 'gonderildi') jsonError('Bu teklif için karar zaten verilmiş.', 409);
if (!empty($t['gecerlilik']) && $t['gecerlilik'] < date('Y-m-d')) {
    jsonError('Teklifin geçerlilik süresi (' . tarihGoster($t['gecerlilik']) . ') dolmuş; güncel teklif için temsilcinizle görüşün.', 409);
}

$kSt = $db->prepare("SELECT ad_soyad, email FROM musteri_kontaklari WHERE id=?");
$kSt->execute([$oturum['kontak_id']]);
$kontak = $kSt->fetch() ?: [];
$kisi = trim((string)($kontak['ad_soyad'] ?? '')) ?: ('#' . $oturum['kontak_id']);
$yeniDurum = $action === 'onayla' ? 'onaylandi' : 'reddedildi';

$u = $db->prepare("UPDATE teklifler SET durum=?, musteri_karar=?, musteri_karar_kisi=?, musteri_karar_tarihi=datetime('now','localtime'),
                          musteri_karar_notu=?, updated_at=datetime('now','localtime')
                   WHERE id=? AND musteri_id=? AND durum='gonderildi'");
$u->execute([$yeniDurum, $yeniDurum, $kisi, $not !== '' ? $not : null, $id, $mid]);
if ($u->rowCount() !== 1) jsonError('Teklif bu sırada değişti; sayfayı yenileyin.', 409);

$kod = $t['kod'] ?: ('#' . $id);
$hesap = teklifToplam(_ptKalemler($db, [$id])[$id] ?? [], (float)$t['kdv_oran'], (float)($t['iskonto_oran'] ?? 0));
$ozet = $kisi . (!empty($kontak['email']) ? ' (' . $kontak['email'] . ')' : '') . ' teklifi portaldan '
      . ($action === 'onayla' ? 'ONAYLADI' : 'REDDETTİ') . ' — ' . number_format((float)$hesap['genel_toplam'], 2, ',', '.') . ' ₺ (KDV dahil).'
      . ($t['temsilci'] ? "\nTemsilci: " . $t['temsilci'] : '')
      . ($action === 'onayla' ? "\nSıradaki adım: teslimat tarih/adresini netleştirip teklifi kiralamaya dönüştürün." : '')
      . ($not !== '' ? "\nMüşteri notu: " . $not : '');
gorevOlustur($db, ($action === 'onayla' ? 'Teklif onaylandı: ' : 'Teklif reddedildi: ') . $kod . ' — ' . ($t['musteri_unvan'] ?? ''),
    $ozet, 'teklif_karar', $id, $action === 'onayla' ? 'yuksek' : 'normal');
logla($db, 'teklifler', $id, 'guncelle', ['durum' => 'gonderildi'], ['durum' => $yeniDurum, 'musteri_karar_notu' => $not],
    'Müşteri portalından teklif ' . ($action === 'onayla' ? 'onaylandı' : 'reddedildi') . ' (' . $kisi . ')');

waOfisBildir($db, $action === 'onayla' ? 'teklif_onay' : 'teklif_red',
    ($action === 'onayla' ? '✅ Teklif onaylandı: ' : '❌ Teklif reddedildi: ') . $kod . ' — ' . ($t['musteri_unvan'] ?? '') . '. '
    . preg_replace('/\s*\n\s*/', ' · ', $ozet) . ' → ' . waPanelLink('teklifler.html?ac=' . $id));

jsonOk(null, $action === 'onayla'
    ? 'Teşekkürler, onayınız temsilcimize iletildi. Teslimat ayrıntıları için sizinle iletişime geçeceğiz.'
    : 'Kararınız temsilcimize iletildi.');
