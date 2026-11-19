<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/belge-zinciri-lib.php';

$oturum = portalRequireAuth();
$mid = (int)$oturum['musteri_id'];
$db = getDb();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonError('Method Not Allowed', 405);

const PORTAL_HAKEDIS_FATURALI = "(h.durum IN ('faturalandi','tahsil_edildi') OR TRIM(COALESCE(h.fatura_no,'')) <> '')";

$tur = (string)($_GET['tur'] ?? '');
$id  = (int)($_GET['id'] ?? 0);

if ($tur === '') {
    $st = $db->prepare("SELECT id, kod, sozlesme_no, ekipman_adi, baslangic, bitis, gercek_bitis, durum, makine_tur, makine_id, metraj, seri_no, makine_kodu FROM kiralamalar
                        WHERE musteri_id=? AND durum<>'iptal' ORDER BY baslangic DESC, id DESC");
    $st->execute([$mid]);
    $kira = $st->fetchAll();
    $ids = array_map('intval', array_column($kira, 'id'));
    $tut = []; $hak = []; $hasar = []; $protokol = [];
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $s = $db->prepare("SELECT t.id, t.kiralama_id, t.belge_no, t.islem, t.tarih, t.belge_surumu, t.asama, t.inceleme_sonucu FROM iade_tutanaklari t
                           WHERE t.kiralama_id IN ($ph) AND EXISTS (SELECT 1 FROM tutanak_surumleri s WHERE s.tutanak_id=t.id)
                           AND " . bzPortalGorunurSql('t') . " ORDER BY t.tarih, t.id");
        $s->execute($ids);
        foreach ($s->fetchAll() as $t) $tut[(int)$t['kiralama_id']][] = $t;
        $s = $db->prepare("SELECT h.id, h.kiralama_id, h.kod, h.donem_bas, h.donem_bit, h.fatura_no, h.fatura_tarihi, h.vade_tarihi, h.genel_toplam
                           FROM hakedisler h WHERE h.musteri_id=? AND h.kiralama_id IN ($ph) AND " . PORTAL_HAKEDIS_FATURALI . "
                           ORDER BY h.donem_bas, h.id");
        $s->execute([$mid, ...$ids]);
        foreach ($s->fetchAll() as $h) $hak[(int)$h['kiralama_id']][] = $h;
        require_once __DIR__ . '/hasar-belge-lib.php';
        $hasar = hbPortalListe($db, $ids);
        require_once __DIR__ . '/kiralama-protokol-lib.php';
        $protokol = kpPortalListe($db, $mid);
    }
    foreach ($kira as &$k) {
        $k['tutanaklar'] = $tut[(int)$k['id']] ?? [];
        $k['hakedisler'] = $hak[(int)$k['id']] ?? [];
        $k['hasar'] = $hasar[(int)$k['id']] ?? [];
        $k['protokoller'] = $protokol[(int)$k['id']] ?? [];
        $k['makine_kunye'] = kiralamaMakineKunye($db, $k);
        unset($k['makine_tur'], $k['makine_id']);
    }
    unset($k);
    jsonOk($kira);
}

if ($tur === 'servis_bedel') {
    require_once __DIR__ . '/servis-bedel-lib.php';
    jsonOk(sbPortalListe($db, $mid));
}

if ($id < 1) jsonError('Geçersiz belge.');

if ($tur === 'hasar' || $tur === 'hasar_foto') {
    require_once __DIR__ . '/hasar-belge-lib.php';
    try { $d = hbPortalDosya($db, $id, $mid); }
    catch (DomainException $e) {
        if ($tur === 'hasar_foto') { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('Bulunamadı.'); }
        jsonError('Belge bulunamadı.', 404);
    }
    if ($tur === 'hasar_foto') hbFotoGonder((string)($_GET['f'] ?? ''), hbFotoListesi($d['belgeler']));
    jsonOk($d);
}

if ($tur === 'tutanak' || $tur === 'tutanak_foto') {
    $yok = function () use ($tur): void {
        if ($tur === 'tutanak_foto') { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('Bulunamadı.'); }
        jsonError('Belge bulunamadı.', 404);
    };
    $s = $db->prepare("SELECT t.id FROM iade_tutanaklari t JOIN kiralamalar k ON k.id=t.kiralama_id WHERE t.id=? AND k.musteri_id=? AND " . bzPortalGorunurSql('t'));
    $s->execute([$id, $mid]);
    if (!$s->fetchColumn()) $yok();
    $s = $db->prepare("SELECT veri FROM tutanak_surumleri WHERE tutanak_id=? ORDER BY surum DESC LIMIT 1");
    $s->execute([$id]);
    $veri = json_decode((string)$s->fetchColumn(), true);
    if (!is_array($veri)) { if ($tur === 'tutanak_foto') $yok(); jsonError('Belge sürümü bulunamadı.', 404); }
    $veri = tsMusteriGorunumu($veri);
    if ($tur === 'tutanak_foto') {
        require_once __DIR__ . '/hasar-belge-lib.php';
        hbFotoGonder((string)($_GET['f'] ?? ''), hbFotoListesi([['icerik' => $veri]]));
    }
    jsonOk($veri);
}

if ($tur === 'sozlesme') {
    $s = $db->prepare("
        SELECT k.*, m.unvan AS musteri_unvan, m.telefon AS musteri_telefon, m.adres AS musteri_adres, m.yetkili AS musteri_yetkili,
               m.vergi_no, m.vergi_dairesi, ml.kod AS manlift_kod, p.ad_soyad AS operator_ad
        FROM kiralamalar k
        JOIN musteriler m ON m.id = k.musteri_id
        LEFT JOIN manliftler ml ON ml.id = k.manlift_id
        LEFT JOIN personel p ON p.id = k.operator_id
        WHERE k.id=? AND k.musteri_id=? AND k.durum<>'iptal'");
    $s->execute([$id, $mid]);
    $k = $s->fetch();
    if (!$k) jsonError('Belge bulunamadı.', 404);
    $k['hesap'] = kiralamaTutar($k);
    $k['makine_kunye'] = kiralamaMakineKunye($db, $k);
    $k['sozlesme_sartlari'] = sozlesmeSartlari($db);
    require_once __DIR__ . '/tutanak-yaz.php';
    $k['firma_imza'] = firmaImzasi($db, (int)$k['id']);
    require_once __DIR__ . '/kiralama-protokol-lib.php';
    $k['kiraci_imza'] = kpSozlesmeImzasi($db, (int)$k['id']);
    $izin = ['id', 'kod', 'ekipman_adi', 'baslangic', 'bitis', 'gercek_bitis', 'kira_tipi', 'birim_ucret', 'miktar', 'kdv_oran',
             'doviz', 'vade_gun', 'lokasyon', 'metraj', 'seri_no', 'makine_kodu', 'sozlesme_no', 'sozlesme_imza_tarihi', 'notlar',
             'operatorlu', 'operator_ad', 'manlift_kod', 'musteri_unvan', 'musteri_telefon', 'musteri_adres', 'musteri_yetkili',
             'vergi_no', 'vergi_dairesi', 'hesap', 'iskonto_oran', 'makine_kunye', 'sozlesme_sartlari', 'firma_imza', 'kiraci_imza'];
    jsonOk(array_intersect_key($k, array_flip($izin)));
}

if ($tur === 'hakedis') {
    $s = $db->prepare("
        SELECT h.id, h.kod, h.donem_bas, h.donem_bit, h.aciklama, h.tutar, h.kdv_oran, h.kdv_tutar, h.genel_toplam, h.fatura_no,
               h.fatura_tarihi, h.vade_tarihi, h.tevkifat_var, h.tevkifat_orani, h.tevkifat_tutari, h.musteriden_tahsil_kdv,
               m.unvan AS musteri_unvan, m.adres AS musteri_adres, m.vergi_dairesi, m.vergi_no, k.kod AS kiralama_kod, k.ekipman_adi
        FROM hakedisler h JOIN musteriler m ON m.id=h.musteri_id LEFT JOIN kiralamalar k ON k.id=h.kiralama_id
        WHERE h.id=? AND h.musteri_id=? AND " . PORTAL_HAKEDIS_FATURALI);
    $s->execute([$id, $mid]);
    $h = $s->fetch();
    if (!$h) jsonError('Belge bulunamadı.', 404);
    $kl = $db->prepare("SELECT aciklama, tutar FROM hakedis_kalemleri WHERE hakedis_id=? ORDER BY id");
    $kl->execute([(int)$h['id']]);
    $h['kalemler'] = $kl->fetchAll();
    $km = $db->prepare("SELECT kiralama_id FROM hakedisler WHERE id=?");
    $km->execute([(int)$h['id']]);
    $h['makine_kunye'] = hakedisMakineKunye($db, $km->fetchColumn());
    require_once __DIR__ . '/tutanak-yaz.php';
    $h['firma_imza'] = firmaImzasi($db);
    jsonOk($h);
}

jsonError('Geçersiz belge türü.');
