<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth(); yazmaIstegiCsrf();
requirePermission('teknik_servis');
subeMerkezi('teknik_servis');

$db     = getDb();
$method = $_SERVER['REQUEST_METHOD'];

function _tsTip($v): string   { return in_array($v, ['ariza','bakim','expertiz','eksik_parca'], true) ? $v : 'ariza'; }
function _tsDurum($v): string { return in_array($v, ['acik','devam','tamamlandi'], true) ? $v : 'acik'; }
function _tsArizaKaynak($v): ?string { $v = (string)$v; return in_array($v, ['makine','musteri'], true) ? $v : null; }
function _tsOnc($v): string   { return in_array($v, ['dusuk','normal','yuksek','acil'], true) ? $v : 'normal'; }
function _tsKaynak($v): string { return in_array($v, ['manuel','planli_bakim','qr'], true) ? $v : 'manuel'; }

function _tsPortalBildir(PDO $db, array $eski, string $yeniDurum, ?string $yapilan): void { tsPortalBildir($db, $eski, $yeniDurum, $yapilan); }

function _tsIslemde(PDO $db, callable $fn): void {
    islemIcinde($db, fn() => $fn());
}
function _tsGorevKapat(PDO $db, int $servisId): void {
    $db->prepare("UPDATE gorevler SET durum='tamamlandi', tamamlanma=?, updated_at=datetime('now','localtime')
                  WHERE kaynak_modul='teknik_servis' AND kaynak_id=? AND kaynak='otomatik' AND durum='acik'")
       ->execute([date('Y-m-d'), $servisId]);
}

function _tsAcikParcaTalebiVarMi(PDO $db, int $servisId): bool { return tsAcikParcaTalebiVarMi($db, $servisId); }

function _tsKontrolTamamlanmamisVarMi(PDO $db, int $servisId): bool { return tsKontrolTamamlanmamisVarMi($db, $servisId); }

function _tsMakineErisim(PDO $db, ?string $tur, ?int $id): void {
    if (!$tur || !$id) return;
    if (!in_array($tur, ['arac', 'manlift'], true)) return;
    $tablo = $tur === 'arac' ? 'araclar' : 'manliftler';
    $st = $db->prepare("SELECT sube FROM $tablo WHERE id = ?");
    $st->execute([$id]);
    $sube = $st->fetchColumn();
    if ($sube === false) jsonError('Makine bulunamadı.', 404);
    subeErisimDogrula($sube ?: null);
}

function _tsMakineDurumSenkron(PDO $db, ?string $tur, ?int $id, string $servisDurum, ?string $servisTipi = null, ?string $baslangic = null, ?string $bitis = null): void {
    teknikServisMakineDurumSenkron($db, $tur, $id, $servisDurum, $servisTipi, $baslangic, $bitis);
}

function _tsKunyeJoin(): string {
    return " LEFT JOIN manliftler m ON (s.makine_tur='manlift' AND m.id=s.makine_id)
             LEFT JOIN araclar a ON (s.makine_tur='arac' AND a.id=s.makine_id)
             LEFT JOIN musteri_makineleri mm ON mm.id=s.musteri_makine_id";
}
function _tsKunyeSelect(): string {
    return " COALESCE(m.kod, a.plaka) AS makine_kod,
             COALESCE(m.marka, a.marka, mm.marka) AS makine_marka,
             COALESCE(m.model, a.model, mm.model) AS makine_model,
             COALESCE(m.seri_no, a.ruhsat_no, mm.seri_no) AS makine_seri_no,
             CASE WHEN a.id IS NOT NULL THEN COALESCE(NULLIF(TRIM(a.kategori),''), 'Araç') ELSE COALESCE(m.kategori, mm.kategori) END AS makine_kategori,
             COALESCE(m.makine_kodu, a.makine_kodu) AS makine_stok_kodu,
             COALESCE(m.metraj, a.metraj) AS makine_metraj,
             COALESCE(m.sube, a.sube) AS makine_sube,
             COALESCE(m.calisma_saati, a.calisma_saati, mm.calisma_saati) AS makine_calisma_saati,
             COALESCE(m.imal_yili, mm.yil) AS makine_imal_yili,
             COALESCE(m.durum, a.durum) AS makine_durum,
             COALESCE(m.aktif, a.aktif) AS makine_aktif,
             CASE WHEN mm.id IS NOT NULL THEN 'musteri' WHEN m.id IS NOT NULL OR a.id IS NOT NULL THEN 'filo' END AS makine_sahip";
}
function _tsKiralamaJoin(): string {
    return " LEFT JOIN kiralamalar kk ON kk.id=s.kiralama_id
             LEFT JOIN musteriler km ON km.id=kk.musteri_id";
}
function _tsKiralamaSelect(): string {
    return " kk.kod AS kiralama_kod, kk.siparis_no AS kiralama_siparis_no, kk.musteri_id AS kiralama_musteri_id, km.unvan AS kiralama_musteri,
             kk.lokasyon AS kiralama_lokasyon, kk.baslangic AS kiralama_baslangic, COALESCE(kk.gercek_bitis, kk.bitis) AS kiralama_bitis,
             kk.durum AS kiralama_durum, COALESCE(NULLIF(TRIM(kk.sube), ''), NULLIF(TRIM(km.sube), '')) AS kiralama_sube";
}
function _tsMaliyetSelect(): string {
    return " (SELECT COALESCE(SUM(h.miktar*h.birim_fiyat),0) FROM stok_hareket h WHERE h.servis_id=s.id AND h.kaynak='servis' AND COALESCE(h.tip,'cikis')='cikis') AS malzeme_tutar,
             (SELECT COALESCE(SUM(COALESCE(k.saat,0)*COALESCE(k.kisi,1)),0) FROM teknik_servis_kalemleri k WHERE k.servis_id=s.id AND k.tur='iscilik') AS iscilik_saat,
             (SELECT COUNT(*) FROM teknik_servis_kalemleri k WHERE k.servis_id=s.id) AS kalem_sayisi";
}
function _tsKiralamaZengin(array $r): array {
    if (!array_key_exists('kiralama_sube', $r)) return $r;
    $sube = subeKisitiHam();
    if (array_key_exists('servis_sube', $r)) {
        $sb = trim((string)($r['servis_sube'] ?? ''));
        $r['servis_baska_sube'] = ($sube !== null && $sb !== '' && $sb !== $sube) ? 1 : 0;
        unset($r['servis_sube']);
    }
    if (array_key_exists('musteri_sube', $r)) {
        $r['musteri_baska_sube'] = 0;
        if (!empty($r['musteri_id']) && $sube !== null && $sube !== trim((string)($r['musteri_sube'] ?? ''))) {
            $r['musteri_unvan'] = null;
            $r['musteri_baska_sube'] = 1;
        }
        unset($r['musteri_sube']);
    }
    $r['kiralama_baska_sube'] = 0;
    if (!empty($r['kiralama_id']) && $sube !== null && $sube !== (string)($r['kiralama_sube'] ?? '')) {
        foreach (['kiralama_kod', 'kiralama_siparis_no', 'kiralama_musteri_id', 'kiralama_musteri', 'kiralama_lokasyon', 'kiralama_baslangic', 'kiralama_bitis', 'kiralama_durum'] as $a) $r[$a] = null;
        $r['kiralama_baska_sube'] = 1;
    }
    unset($r['kiralama_sube']);
    if (array_key_exists('malzeme_tutar', $r)) { $r['malzeme_tutar'] = round((float)$r['malzeme_tutar'], 2); $r['iscilik_saat'] = round((float)$r['iscilik_saat'], 3); $r['kalem_sayisi'] = (int)$r['kalem_sayisi']; }
    return $r;
}
function _tsBaskaSubeMaskesi(array $r): array {
    if (!smBaskaSube($r)) return $r;
    foreach (['irtibat_ad', 'irtibat_tel', 'saha_adres', 'saha_il', 'saha_ilce', 'saha_kabul_ad', 'musteri_ad', 'musteri_tel'] as $a) if (array_key_exists($a, $r)) $r[$a] = null;
    if (is_array($r['tutanak'] ?? null)) { $r['tutanak']['musteri'] = null; $r['tutanak']['kiralama_kod'] = null; }
    if (is_array($r['saha_ziyaretler'] ?? null)) {
        foreach ($r['saha_ziyaretler'] as &$z) foreach (['musteri_ad', 'musteri_tel', 'servis_yetkili_ad', 'imza_musteri_url', 'imza_teknisyen_url'] as $a) if (array_key_exists($a, $z)) $z[$a] = null;
        unset($z);
    }
    return $r;
}
function _tsKunyeZengin(PDO $db, array $r): array {
    static $katOnbellek = [], $birimOnbellek = [];
    $r = _tsKiralamaZengin($r);
    if (!array_key_exists('makine_kod', $r)) return $r;
    $kat = trim((string)($r['makine_kategori'] ?? ''));
    if ($kat === '' && ($r['makine_tur'] ?? '') === 'manlift' && (trim((string)($r['makine_marka'] ?? '')) !== '' || trim((string)($r['makine_model'] ?? '')) !== '')) {
        $anahtar = ($r['makine_marka'] ?? '') . '|' . ($r['makine_model'] ?? '');
        $katOnbellek[$anahtar] ??= (string)(makineKategoriAdi($db, ['kategori' => null, 'marka' => $r['makine_marka'], 'model' => $r['makine_model']]) ?? '');
        $kat = $katOnbellek[$anahtar];
    }
    $r['makine_kategori'] = $kat !== '' ? $kat : null;
    $r['makine_metraj'] = metrajSayi($r['makine_metraj'] ?? null);
    $r['makine_olcu_birim'] = null;
    if ($r['makine_metraj'] !== null) {
        $birimOnbellek[$kat] ??= mkBirim($db, $kat !== '' ? $kat : null);
        $r['makine_olcu_birim'] = $birimOnbellek[$kat];
    }
    $r['makine_calisma_saati'] = isset($r['makine_calisma_saati']) && (float)$r['makine_calisma_saati'] > 0 ? (float)$r['makine_calisma_saati'] : null;
    $r['makine_imal_yili'] = !empty($r['makine_imal_yili']) ? (int)$r['makine_imal_yili'] : null;
    $r['makine_aktif'] = isset($r['makine_aktif']) ? (int)$r['makine_aktif'] : null;
    $r['makine_durum'] = null;
    return $r;
}

function _tsDetay(PDO $db, int $id): ?array {
    if ($id < 1) return null;
    $st = $db->prepare("SELECT s.*, p.ad_soyad AS teknisyen_ad, mu.unvan AS musteri_unvan, mu.sube AS musteri_sube, " . servisSubeSql('s', 'kk', 'mu', 'km') . " AS servis_sube, " . _tsKunyeSelect() . ", " . _tsKiralamaSelect() . ", " . _tsMaliyetSelect() . "
                        FROM teknik_servis s LEFT JOIN personel p ON p.id=s.teknisyen_id
                        LEFT JOIN musteriler mu ON mu.id=s.musteri_id" . _tsKunyeJoin() . _tsKiralamaJoin() . " WHERE s.id=?");
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) return null;
    $r = _tsKunyeZengin($db, $r);
    $r['donus'] = tsDonusOzet($db, $r);

    $r['tutanak'] = null;
    if (!empty($r['tutanak_id'])) {
        $tq = $db->prepare("
            SELECT t.id, t.belge_no, t.tarih, t.genel_durum, t.hasar_notu, t.bilesen_hasar,
                   t.ariza_kodlari, t.km, t.calisma_saati,
                   k.kod AS kiralama_kod, k.ekipman_adi, m.unvan AS musteri, k.sube AS tutanak_sube
            FROM iade_tutanaklari t
            LEFT JOIN kiralamalar k ON k.id = t.kiralama_id
            LEFT JOIN musteriler  m ON m.id = k.musteri_id
            WHERE t.id = ?");
        $tq->execute([(int)$r['tutanak_id']]);
        if ($tut = $tq->fetch()) {
            $tk = subeKisitiHam();
            if ($tk !== null && trim((string)($tut['tutanak_sube'] ?? '')) !== $tk) { $tut['musteri'] = null; $tut['kiralama_kod'] = null; }
            unset($tut['tutanak_sube']);
            $fq = $db->prepare("SELECT aci_tipi, dosya_yolu FROM iade_tutanak_fotograflari WHERE tutanak_id=?");
            $fq->execute([(int)$r['tutanak_id']]);
            $tut['fotograflar'] = $fq->fetchAll();
            $r['tutanak'] = $tut;
        }
    }

    $pSt = $db->prepare("
        SELECT k.id, k.aciklama, k.miktar, k.birim_fiyat, k.teslim_alinan,
               s.id AS siparis_id, s.kod AS siparis_kod, s.durum AS siparis_durum,
               t.unvan AS tedarikci_unvan
        FROM satinalma_kalemleri k
        JOIN satinalma_siparisleri s ON s.id = k.siparis_id
        JOIN tedarikciler t ON t.id = s.tedarikci_id
        WHERE k.teknik_servis_id = ?
        ORDER BY k.id DESC
    ");
    $pSt->execute([$id]);
    $r['parca_talepleri'] = $pSt->fetchAll();

    $kSt = $db->prepare("SELECT id, kalem_id, kategori, madde, durum, not_metin FROM servis_kontrol_sonuclari WHERE teknik_servis_id=? ORDER BY id");
    $kSt->execute([$id]);
    $r['kontrol_sonuclari'] = $kSt->fetchAll();

    $fSt = $db->prepare("SELECT id, dosya_yolu, kaynak, created_at FROM teknik_servis_fotograflari WHERE servis_id=? ORDER BY id");
    $fSt->execute([$id]);
    $r['fotograflar'] = $fSt->fetchAll();

    $r['kalemler'] = tsKalemListe($db, $id);
    $pq = $db->prepare("SELECT h.id, h.tarih, h.miktar, h.birim_fiyat, p.ad AS parca_ad, p.parca_no
                        FROM stok_hareket h LEFT JOIN yedek_parca p ON p.id=h.parca_id
                        WHERE h.servis_id=? AND h.kaynak='servis' AND COALESCE(h.tip,'cikis')='cikis' ORDER BY h.id");
    $pq->execute([$id]);
    $r['parcalar'] = $pq->fetchAll();
    $r['ziyaretler'] = ssZiyaretler($db, $id);
    $r['saha_ziyaretler'] = ssZiyaretDetay($db, $id);
    $r['fiyat_yetki'] = smFiyatErisimi($db, $id);
    $r = _tsBaskaSubeMaskesi($r);
    return $r + smDetayEk($db, $id);
}

require_once __DIR__ . '/servis-kalem-lib.php';
require_once __DIR__ . '/saha-servis-lib.php';
require_once __DIR__ . '/servis-talep-lib.php';
$_tsAction = (string)($_GET['action'] ?? '');
if (in_array($_tsAction, ['kalemler', 'depo_baglam', 'kalem', 'foto'], true)) {
    $_tsId = (int)($_GET['id'] ?? 0);
    try {
        if ($method === 'GET' && $_tsAction === 'kalemler') {
            $s = tsServis($db, $_tsId);
            $parcalar = $db->query('SELECT id, ad, parca_no, stok FROM yedek_parca ORDER BY ad')->fetchAll(PDO::FETCH_ASSOC);
            jsonOk(['kalemler' => tsKalemListe($db, $_tsId), 'bulgular' => tsBulgular($db, $_tsId), 'parcalar' => $parcalar,
                    'kilitli' => $s['durum'] === 'tamamlandi', 'depo' => tsDepoServisiMi($db, $s)]);
        }
        if ($method === 'GET' && $_tsAction === 'depo_baglam') {
            $b = tsDepoBaglam($db, $_tsId);
            if ($b === null) jsonError('Bu servis dönüş kontrolünden açılmadı.', 404);
            jsonOk($b);
        }
        requirePermission('teknik_servis', 'yaz');
        verifyCsrf();
        $body = govdeOku();
        if ($method === 'POST' && $_tsAction === 'kalem') {
            $db->beginTransaction();
            $r = tsKalemEkle($db, $_tsId, $body);
            $db->commit();
            jsonOk($r, $r['eksi_stok'] ? 'Satır eklendi; stok eksiye düştü — parça sipariş edilmeli.' : 'Satır eklendi.');
        }
        if ($method === 'DELETE' && $_tsAction === 'kalem') {
            $db->beginTransaction();
            tsKalemSil($db, (int)($_GET['kalem_id'] ?? 0));
            $db->commit();
            jsonOk(null, 'Satır silindi.');
        }
        if ($method === 'POST' && $_tsAction === 'foto') {
            $s = tsServis($db, $_tsId);
            if ($s['durum'] === 'tamamlandi') throw new DomainException('Servis tamamlandı; fotoğraf eklenemez.');
            if ($_gh = gorselVeriHatasi($body['veri'] ?? null)) throw new DomainException($_gh);
            $yol = _tsFotoKaydet((string)($body['veri'] ?? ''), $_tsId);
            if (!$yol) throw new DomainException('Fotoğraf okunamadı (JPEG/PNG/WEBP, en çok ' . (int)(MAX_FILE_SIZE / 1048576) . ' MB).');
            $db->prepare("INSERT INTO teknik_servis_fotograflari(servis_id,dosya_yolu,kaynak,created_at) VALUES(?,?,'tamir_sonrasi',?)")->execute([$_tsId, $yol, date('Y-m-d H:i:s')]);
            $fid = (int)$db->lastInsertId();
            logla($db, 'teknik_servis_fotograflari', $fid, 'ekle', null, ['servis_id' => $_tsId, 'kaynak' => 'tamir_sonrasi'], 'Tamir sonrası fotoğraf — servis #' . $_tsId);
            jsonOk(['id' => $fid], 'Fotoğraf eklendi.');
        }
        jsonError('Method Not Allowed', 405);
    } catch (TsStokOnayi $e) {
        if ($db->inTransaction()) $db->rollBack();
        jsonError($e->getMessage(), 409, $e->ek);
    } catch (DomainException $e) {
        if ($db->inTransaction()) $db->rollBack();
        jsonError($e->getMessage(), 409);
    }
}

if (in_array($_tsAction, ['masraf', 'fiyat'], true)) {
    $_tsId = (int)($_GET['id'] ?? 0);
    try {
        if ($method === 'GET' && $_tsAction === 'masraf') {
            jsonOk(smMasrafBaglam($db, $_tsId, smFiyatErisimi($db, $_tsId), hasPermission('teknik_servis', 'yaz')));
        }
        if ($method !== 'PUT') jsonError('Method Not Allowed', 405);
        $body = govdeOku();
        if ($_tsAction === 'masraf') {
            requirePermission('teknik_servis', 'yaz');
            verifyCsrf();
            $db->beginTransaction();
            $r = smMasrafKaydet($db, $_tsId, is_array($body['kalemler'] ?? null) ? $body['kalemler'] : []);
            $db->commit();
            jsonOk($r, $r['degisen'] ? 'Masraf satırları güncellendi.' : 'Masrafta değişiklik yok.');
        }
        if (!smFiyatYetkisi($db)) jsonError('Servis fiyatlandırma yetkiniz yok (Ayarlar > Teslim Adımı Yetkileri > Servis fiyatlandırma).', 403);
        if (array_key_exists('musteriye_yansit', $body)) requirePermission('teknik_servis', 'yaz');
        if ($_bedelHata = smBedelYetkiHatasi($body, hasPermission('kiralama_takip', 'yaz'), tsServis($db, $_tsId))) jsonError($_bedelHata, 403);
        verifyCsrf();
        ssFiyatSubeDogrula($db, $_tsId);
        $db->beginTransaction();
        $r = smFiyatKaydet($db, $_tsId, $body);
        $db->commit();
        jsonOk($r, $r['fiyat_durum'] === 'ucretsiz' ? 'Ücretsiz olarak işaretlendi.' : 'Müşteri fiyatı kaydedildi.');
    } catch (DomainException $e) {
        if ($db->inTransaction()) $db->rollBack();
        jsonError($e->getMessage(), 409);
    }
}

if ($method === 'GET' && $_tsAction === 'pdf') {
    require_once __DIR__ . '/teknik-servis-belge-lib.php';
    $_tsKayit = _tsDetay($db, (int)($_GET['id'] ?? 0));
    if (!$_tsKayit) jsonError('Servis kaydı bulunamadı.', 404);
    if (in_array($_tsKayit['makine_tur'] ?? '', ['manlift', 'arac'], true) && (int)($_tsKayit['makine_id'] ?? 0) > 0) {
        $_tsKayit['makine_durum'] = makineDurumu($db, (string)$_tsKayit['makine_tur'], (int)$_tsKayit['makine_id']);
    }
    $_tsGoster = smFiyatYetkisi($db) && !smBaskaSube($_tsKayit);
    bpIndir(tsPdf($db, ssTutarGizle($_tsKayit, $db), $_tsGoster));
}

function _tsFotoKaydet(string $dataUrl, int $sid): ?string { return tsFotoKaydet($dataUrl, $sid); }

if ($method === 'POST' && ($_GET['action'] ?? '') === 'tutanaktan') {
    verifyCsrf();
    $body=govdeOku();
    $tid=(int)($body['tutanak_id']??0);$t=hdKaynak($db,$tid);
    if(!$t)jsonError('Tutanak bulunamadı.',404);
    if(($sube=subeKisitiHam())!==null&&$sube!==$t['sube'])jsonError('Bu tutanak başka bir şubeye ait.',403);
    $db->beginTransaction();
    try {
        $var=$db->prepare('SELECT id FROM teknik_servis WHERE tutanak_id=? ORDER BY id DESC LIMIT 1');$var->execute([$tid]);$mevcut=(int)$var->fetchColumn();
        if(!$mevcut){$eh=$db->prepare("SELECT id FROM hasar_degerlendirmeleri WHERE tutanak_id=? AND COALESCE(durum,'')<>'tasindi' LIMIT 1");$eh->execute([$tid]);if($eid=(int)$eh->fetchColumn())throw new DomainException('Bu tutanağa bağlı eski hasar değerlendirme kaydı (#'.$eid.') arşivde; servis o kayıttan "Servis kaydına taşı" ile açılır ya da kayıt orada kapatılır.');}
        $sid=hdTutanakServisSenkron($db,$tid);
        if(!$sid)throw new DomainException('Servise aktarılacak hasar veya eksik bulgusu yok.');
        $db->commit();
        if($mevcut&&$mevcut===$sid)jsonOk(['id'=>$sid,'mukerrer'=>true],'Bağlı servis zaten var.');
        jsonOk(['id'=>$sid],'Tutanak bulguları servis kaydına aktarıldı.');
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();if($e instanceof DomainException)jsonError($e->getMessage(),409);error_log($e->getMessage());jsonError('Servis açılamadı.',500);}
}

function _tsKontrolKayit(PDO $db, int $id): array {
    $st = $db->prepare("SELECT id FROM teknik_servis WHERE id=?");
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) jsonError('Servis kaydı bulunamadı.', 404);
    return $r;
}

if ($method === 'POST' && ($_GET['action'] ?? '') === 'kontrol') {
    verifyCsrf();
    $sid = (int)($_GET['id'] ?? 0);
    if ($sid < 1) jsonError('Geçersiz ID.');
    _tsKontrolKayit($db, $sid);

    $body = govdeOku();
    $sablonId = ((int)($body['sablon_id'] ?? 0)) ?: null;
    $sonuclar = is_array($body['sonuclar'] ?? null) ? $body['sonuclar'] : [];

    $sablonAdi = null;
    if ($sablonId) {
        $sAd = $db->prepare("SELECT ad FROM servis_kontrol_sablonlari WHERE id=?");
        $sAd->execute([$sablonId]);
        $sablonAdi = $sAd->fetchColumn() ?: null;
    }

    $db->prepare("DELETE FROM servis_kontrol_sonuclari WHERE teknik_servis_id=?")->execute([$sid]);
    $ins = $db->prepare("INSERT INTO servis_kontrol_sonuclari (teknik_servis_id, kalem_id, madde, kategori, durum, not_metin) VALUES (?,?,?,?,?,?)");
    $kaydedilen = 0;
    foreach ($sonuclar as $s) {
        if (!is_array($s)) continue;
        $madde = trim(metin($s['madde'] ?? ''));
        if ($madde === '') continue;
        $durum = in_array($s['durum'] ?? '', ['uygun', 'uygun_degil', 'nk'], true) ? $s['durum'] : 'nk';
        $ins->execute([$sid, (int)($s['kalem_id'] ?? 0), $madde, trim(metin($s['kategori'] ?? '')), $durum, trim(metin($s['not'] ?? ''))]);
        $kaydedilen++;
    }
    $db->prepare("UPDATE teknik_servis SET kontrol_sablon_id=?, kontrol_sablon_adi=? WHERE id=?")
       ->execute([$sablonId, $sablonAdi, $sid]);
    logla($db, 'teknik_servis', $sid, 'guncelle', null, null, 'Kontrol listesi uygulandı' . ($sablonAdi ? ": $sablonAdi" : '') . " ($kaydedilen madde)");
    jsonOk(['kaydedilen' => $kaydedilen], 'Kontrol listesi kaydedildi.');
}

switch ($method) {

    case 'GET':
        if (isset($_GET['id'])) {
            $r = _tsDetay($db, (int)$_GET['id']);
            if (!$r) jsonError('Kayıt bulunamadı.', 404);
            jsonOk(ssTutarGizle($r, $db));
        }

        $where = []; $params = [];
        if (($d = trim(metin($_GET['durum'] ?? ''))) !== '' && $d !== 'hepsi') { $where[] = "s.durum=?"; $params[] = $d; }
        if (($t = trim(metin($_GET['tip'] ?? ''))) !== '')   { $where[] = "s.tip=?"; $params[] = $t; }
        if (($mt = trim(metin($_GET['makine_tur'] ?? ''))) !== '' && ($mi = (int)($_GET['makine_id'] ?? 0)) > 0) {
            $where[] = "s.makine_tur=? AND s.makine_id=?"; $params[] = $mt; $params[] = $mi;
        }
        if (in_array($y = (string)($_GET['yer'] ?? ''), ['saha', 'atolye'], true)) { $where[] = "COALESCE(s.yer,'atolye')=?"; $params[] = $y; }
        if (in_array($sd = (string)($_GET['saha_durum'] ?? ''), SS_DURUMLAR, true)) { $where[] = 's.saha_durum=?'; $params[] = $sd; }
        if (!empty($_GET['ofis_inceleme'])) $where[] = 'COALESCE(s.ofis_inceleme,0)=1';
        if (in_array($fd = (string)($_GET['fiyat_durum'] ?? ''), ['bekliyor', 'fiyatlandi', 'ucretsiz'], true)) { $where[] = 's.fiyat_durum=?'; $params[] = $fd; }
        if ($fd === 'fiyatli') $where[] = "s.fiyat_durum IN ('fiyatlandi','ucretsiz')";
        if (($q = trim(metin($_GET['q'] ?? ''))) !== '') {
            $aranan = ['s.makine_ad', 's.ariza', 's.yapilan', 'm.kod', 'a.plaka', 'm.seri_no', 'a.ruhsat_no', 'm.marka', 'a.marka', 'm.model', 'a.model',
                       'm.makine_kodu', 'a.makine_kodu', 'mm.marka', 'mm.model', 'mm.seri_no', 's.saha_adres', 's.saha_il', 's.saha_ilce'];
            $like = '%' . trArama($q) . '%';
            $parca = array_map(fn($k) => trAramaSql($k) . ' LIKE ?', $aranan);
            $araP = array_fill(0, count($aranan), $like);
            $kisitQ = subeKisitiHam();
            foreach ([['kk', ['kk.kod', 'kk.siparis_no', 'kk.lokasyon', 'km.unvan']], ['mu', ['mu.unvan']]] as [$tablo, $alanlar]) {
                $ic = implode(' OR ', array_map(fn($k) => trAramaSql($k) . ' LIKE ?', $alanlar));
                if ($kisitQ !== null) { $parca[] = "(TRIM(COALESCE($tablo.sube,''))=? AND ($ic))"; $araP[] = $kisitQ; }
                else $parca[] = "($ic)";
                array_push($araP, ...array_fill(0, count($alanlar), $like));
            }
            $where[] = '(' . implode(' OR ', $parca) . ')';
            array_push($params, ...$araP);
        }
        $sql = "SELECT s.*, p.ad_soyad AS teknisyen_ad, t.belge_no AS tutanak_belge_no, mu.unvan AS musteri_unvan, mu.sube AS musteri_sube,
                       (SELECT COUNT(*) FROM teknik_servis_fotograflari f WHERE f.servis_id = s.id) AS foto_sayisi,
                       (SELECT z.id FROM servis_ziyaretleri z WHERE z.servis_id = s.id AND z.rapor_no IS NOT NULL ORDER BY z.id DESC LIMIT 1) AS son_ziyaret_id,
                       (SELECT z.rapor_no FROM servis_ziyaretleri z WHERE z.servis_id = s.id AND z.rapor_no IS NOT NULL ORDER BY z.id DESC LIMIT 1) AS son_rapor_no, " . _tsKunyeSelect() . ", " . _tsKiralamaSelect() . ", " . servisSubeSql('s', 'kk', 'mu', 'km') . " AS servis_sube, " . _tsMaliyetSelect() . "
                FROM teknik_servis s
                LEFT JOIN personel p ON p.id = s.teknisyen_id
                LEFT JOIN iade_tutanaklari t ON t.id = s.tutanak_id
                LEFT JOIN musteriler mu ON mu.id = s.musteri_id" . _tsKunyeJoin() . _tsKiralamaJoin();
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY (s.durum<>'tamamlandi') DESC, s.tarih DESC, s.id DESC";
        $st = $db->prepare($sql); $st->execute($params);
        $fiyatYetki = smFiyatYetkisi($db);
        $rows = array_map(fn($r) => ssTutarGizle(_tsBaskaSubeMaskesi(_tsKunyeZengin($db, $r)), $db), $st->fetchAll());

        $ozet = ['acik'=>0,'devam'=>0,'tamamlandi'=>0,'toplam_maliyet'=>0.0];
        foreach ($rows as $r) {
            $ozet[$r['durum']] = ($ozet[$r['durum']] ?? 0) + 1;
            $ozet['toplam_maliyet'] += (float)($r['maliyet'] ?? 0);
        }
        $ozet['toplam_maliyet'] = $fiyatYetki ? round($ozet['toplam_maliyet'], 2) : null;
        jsonOk(['list' => $rows, 'ozet' => $ozet, 'fiyat_yetki' => $fiyatYetki]);
        break;

    case 'POST':
        verifyCsrf();
        $body = govdeOku();
        $tarih = dateStr('tarih', $body);
        if (!$tarih) jsonError('Tarih zorunludur.');
        $ariza = trim(metin($body['ariza'] ?? ''));
        if ($ariza === '') jsonError('Arıza / talep açıklaması zorunludur.');

        $makineTur = in_array($body['makine_tur'] ?? '', ['manlift','arac'], true) ? $body['makine_tur'] : null;
        $makineId  = ((int)($body['makine_id'] ?? 0)) ?: null;
        if (!$makineTur) $makineId = null;
        _tsMakineErisim($db, $makineTur, $makineId);
        $musteriId = ((int)($body['musteri_id'] ?? 0)) ?: null;
        $mmId = !$makineTur ? (((int)($body['musteri_makine_id'] ?? 0)) ?: null) : null;
        $makineAd = trim(metin($body['makine_ad'] ?? '')) ?: null;
        if ($mmId && !$musteriId) jsonError('Müşterinin makinesi için önce müşteriyi seçin.');
        if ($musteriId) {
            $mq = $db->prepare('SELECT sube FROM musteriler WHERE id=?'); $mq->execute([$musteriId]);
            $mRow = $mq->fetch(PDO::FETCH_ASSOC);
            if (!$mRow) jsonError('Müşteri bulunamadı.', 404);
            $kisit = subeKisitiHam();
            if ($kisit !== null && trim((string)($mRow['sube'] ?? '')) !== $kisit) jsonError('Bu müşteri başka bir şubeye ait; erişim yetkiniz yok.', 403);
        }
        if ($mmId) {
            try { $makineAd = ssMusteriMakineAd(ssMusteriMakineDogrula($db, $musteriId, $mmId)); }
            catch (DomainException $e) { jsonError($e->getMessage(), 409); }
        }

        $benzerAcikKayit = ($makineTur && $makineId) ? teknikServisAcikKaydiBul($db, $makineTur, $makineId) : null;
        $kiralamaId = tsKaynakKiralamaBul($db, $makineTur, $makineId, $tarih, ((int)($body['kiralama_id'] ?? 0)) ?: null);
        $talepId = (int)($body['talep_id'] ?? 0);
        if ($talepId > 0) { $talep = stTalep($db, $talepId); if (!$talep) jsonError('Talep bulunamadı.', 404); if ($talep['durum'] !== 'bekliyor') jsonError('Talep zaten sonuçlandı; yeni servis açılmaz.', 409); if (!stSubeIzinli($talep)) jsonError('Bu talep başka bir şubeye ait; erişim yetkiniz yok.', 403); }

        $db->beginTransaction();
        try {
        $st = $db->prepare("
            INSERT INTO teknik_servis (makine_tur, makine_id, makine_ad, tip, tarih, durum, oncelik, ariza, yapilan, teknisyen_id, maliyet, tamamlanma, notlar, musteriye_yansit, kaynak, kiralama_id, gerekce, planlanan_tarih, musteri_id, musteri_makine_id, ariza_kaynak)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $servisDurum = _tsDurum($body['durum'] ?? 'acik');
        $gerekce = ssGerekce($body['gerekce'] ?? null);
        if ($gerekce) $body['tip'] = SS_GEREKCE_TIP[$gerekce];
        $st->execute([
            $makineTur, $makineId, $makineAd,
            _tsTip($body['tip'] ?? 'ariza'), $tarih, $servisDurum, _tsOnc($body['oncelik'] ?? 'normal'),
            $ariza, trim(metin($body['yapilan'] ?? '')) ?: null,
            ((int)($body['teknisyen_id'] ?? 0)) ?: null,
            max(0, (float)($body['maliyet'] ?? 0)),
            dateStr('tamamlanma', $body),
            trim(metin($body['notlar'] ?? '')) ?: null,
            !empty($body['musteriye_yansit']) ? 1 : 0,
            _tsKaynak($body['kaynak'] ?? 'manuel'),
            $kiralamaId,
            $gerekce,
            dateStr('planlanan_tarih', $body),
            $musteriId,
            $mmId,
            _tsArizaKaynak($body['ariza_kaynak'] ?? null),
        ]);
        _tsMakineDurumSenkron($db, $makineTur, $makineId, $servisDurum, _tsTip($body['tip'] ?? 'ariza'), $tarih, dateStr('tamamlanma', $body));
        $yeniId = (int)$db->lastInsertId();
        if ($talepId > 0) stTalepBagla($db, $talepId, $yeniId);
        if ($servisDurum !== 'tamamlandi') {
            $servisOnc = _tsOnc($body['oncelik'] ?? 'normal');
            gorevOlustur($db, 'Servis kaydı: ' . ($makineAd ?: 'makine'),
                $ariza, 'teknik_servis', $yeniId, $servisOnc === 'acil' ? 'yuksek' : $servisOnc);
        } else {
            $yoneticiMi = ($_SESSION['rol'] ?? '') === 'admin';
            if (_tsAcikParcaTalebiVarMi($db, $yeniId) && !(!empty($body['parca_yoksay']) && $yoneticiMi)) throw new DomainException('Bu servis kaydına bağlı, henüz teslim alınmamış bir parça talebi var.');
            if (_tsKontrolTamamlanmamisVarMi($db, $yeniId) && !(!empty($body['kontrol_yoksay']) && $yoneticiMi)) throw new DomainException('Bu servis kaydının kontrol listesinde henüz değerlendirilmemiş madde var.');
            $yeniAcik = array_merge(tsServis($db, $yeniId), ['durum' => 'acik']);
            if ($eng = tsTamamlamaEngeli($db, $yeniAcik)) {
                if (empty($body['masrafsiz_kapat'])) throw new DomainException($eng);
                tsMasrafsizKapat($db, $yeniAcik, trim(metin($body['yapilan'] ?? '')) ?: null);
            }
            if (!dateStr('tamamlanma', $body)) $db->prepare("UPDATE teknik_servis SET tamamlanma=? WHERE id=?")->execute([date('Y-m-d'), $yeniId]);
            tsTamamlandi($db, $yeniId);
        }
        $db->commit();
        } catch (DomainException $e) { if ($db->inTransaction()) $db->rollBack(); jsonError($e->getMessage(), 409); }
        catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
        jsonOk([
            'id' => $yeniId,
            'benzer_acik_kayit' => $benzerAcikKayit ? (int)$benzerAcikKayit['id'] : null,
        ], 'Servis kaydı eklendi.');

    case 'PUT':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $body = govdeOku();

        $cur = $db->prepare("SELECT * FROM teknik_servis WHERE id=?");
        $cur->execute([$id]);
        $mevcut = $cur->fetch();
        if (!$mevcut) jsonError('Kayıt bulunamadı.', 404);
        _tsMakineErisim($db, $mevcut['makine_tur'] ?? null, $mevcut['makine_id'] ? (int)$mevcut['makine_id'] : null);

        $tsYeniTek = ((int)($body['teknisyen_id'] ?? 0)) ?: null;
        if (!(count($body) <= 4 && isset($body['durum']) && !isset($body['ariza']))
            && $tsYeniTek !== ($mevcut['teknisyen_id'] !== null ? (int)$mevcut['teknisyen_id'] : null)
            && ($tsKisit = subeKisitiHam()) !== null && ($tsSb = _ssServisSubesi($db, $id)) !== '' && $tsSb !== $tsKisit) {
            jsonError('Bu servis başka şubenin; teknisyen atamasını o şubenin kullanıcısı ya da yönetici yapar.', 403);
        }
        if (count($body) <= 4 && isset($body['durum']) && !isset($body['ariza'])) {
            $durum = _tsDurum($body['durum']);
            if ($durum === 'tamamlandi' && _tsAcikParcaTalebiVarMi($db, $id)
                && !(!empty($body['parca_yoksay']) && ($_SESSION['rol'] ?? '') === 'admin')) {
                jsonError('Bu servis kaydına bağlı, henüz teslim alınmamış bir parça talebi var.', 409, ['acik_parca_var' => true]);
            }
            if ($durum === 'tamamlandi' && _tsKontrolTamamlanmamisVarMi($db, $id)
                && !(!empty($body['kontrol_yoksay']) && ($_SESSION['rol'] ?? '') === 'admin')) {
                jsonError('Bu servis kaydının kontrol listesinde henüz değerlendirilmemiş madde var.', 409, ['kontrol_nk_var' => true]);
            }
            $masrafsiz = $durum === 'tamamlandi' && !empty($body['masrafsiz_kapat']) && tsTamamlamaEngeli($db, $mevcut) !== null;
            if ($durum === 'tamamlandi' && !$masrafsiz && ($eng = tsTamamlamaEngeli($db, $mevcut))) jsonError($eng, 409, ['yapilan_is_yok' => true]);
            $tam = $mevcut['tamamlanma'];
            if ($durum === 'tamamlandi' && !$tam) $tam = date('Y-m-d');
            _tsIslemde($db, function () use ($db, $durum, $tam, $id, $mevcut, $masrafsiz) {
                if ($masrafsiz) tsMasrafsizKapat($db, $mevcut);
                $db->prepare("UPDATE teknik_servis SET durum=?, tamamlanma=?, updated_at=datetime('now','localtime') WHERE id=?")
                   ->execute([$durum, $tam, $id]);
                _tsMakineDurumSenkron($db, $mevcut['makine_tur'], $mevcut['makine_id'] ? (int)$mevcut['makine_id'] : null, $durum, $mevcut['tip'] ?? null);
                if ($durum === 'tamamlandi') _tsGorevKapat($db, $id);
                if ($durum === 'tamamlandi' && ($mevcut['durum'] ?? '') !== 'tamamlandi') tsTamamlandi($db, $id);
            });
            _tsPortalBildir($db, $mevcut, $durum, $mevcut['yapilan'] ?? null);
            jsonOk(null, 'Durum güncellendi.');
        }

        $tarih = dateStr('tarih', $body);
        if (!$tarih) jsonError('Tarih zorunludur.');
        $ariza = trim(metin($body['ariza'] ?? ''));
        if ($ariza === '') jsonError('Arıza / talep açıklaması zorunludur.');
        $makineTur = in_array($body['makine_tur'] ?? '', ['manlift','arac'], true) ? $body['makine_tur'] : null;
        $makineId  = ((int)($body['makine_id'] ?? 0)) ?: null;
        if (!$makineTur) $makineId = null;
        _tsMakineErisim($db, $makineTur, $makineId);

        $servisDurum = _tsDurum($body['durum'] ?? 'acik');
        if ($servisDurum === 'tamamlandi' && _tsAcikParcaTalebiVarMi($db, $id)
            && !(!empty($body['parca_yoksay']) && ($_SESSION['rol'] ?? '') === 'admin')) {
            jsonError('Bu servis kaydına bağlı, henüz teslim alınmamış bir parça talebi var.', 409, ['acik_parca_var' => true]);
        }
        if ($servisDurum === 'tamamlandi' && _tsKontrolTamamlanmamisVarMi($db, $id)
            && !(!empty($body['kontrol_yoksay']) && ($_SESSION['rol'] ?? '') === 'admin')) {
            jsonError('Bu servis kaydının kontrol listesinde henüz değerlendirilmemiş madde var.', 409, ['kontrol_nk_var' => true]);
        }
        $masrafsiz = $servisDurum === 'tamamlandi' && !empty($body['masrafsiz_kapat']) && tsTamamlamaEngeli($db, $mevcut) !== null;
        $masrafsizYapilan = trim(metin($body['yapilan'] ?? '')) ?: null;
        if ($servisDurum === 'tamamlandi' && !$masrafsiz && ($eng = tsTamamlamaEngeli($db, $mevcut))) jsonError($eng, 409, ['yapilan_is_yok' => true]);
        $yansit = smYansitKarar($db, $mevcut, $body);
        $gerekce = array_key_exists('gerekce', $body) ? ssGerekce($body['gerekce']) : ($mevcut['gerekce'] ?? null);
        if ($gerekce && array_key_exists('gerekce', $body)) $body['tip'] = SS_GEREKCE_TIP[$gerekce];
        $planlanan = array_key_exists('planlanan_tarih', $body) ? dateStr('planlanan_tarih', $body) : ($mevcut['planlanan_tarih'] ?? null);
        $ayniMakine = $makineTur === ($mevcut['makine_tur'] ?? null) && $makineId === (($mevcut['makine_id'] ?? null) ? (int)$mevcut['makine_id'] : null);
        $kiralamaId = ($ayniMakine && !empty($mevcut['kiralama_id'])) ? (int)$mevcut['kiralama_id'] : tsKaynakKiralamaBul($db, $makineTur, $makineId, $tarih, ((int)($body['kiralama_id'] ?? 0)) ?: null);
        $mmId = ($makineTur && $makineId) ? null : (((int)($mevcut['musteri_makine_id'] ?? 0)) ?: null);
        $makineAd = trim(metin($body['makine_ad'] ?? '')) ?: ($mmId ? ($mevcut['makine_ad'] ?? null) : null);
        $arizaKaynak = array_key_exists('ariza_kaynak', $body) ? _tsArizaKaynak($body['ariza_kaynak']) : ($mevcut['ariza_kaynak'] ?? null);
        _tsIslemde($db, function () use ($db, $makineTur, $makineId, $makineAd, $mmId, $body, $tarih, $servisDurum, $ariza, $yansit, $id, $mevcut, $kiralamaId, $masrafsiz, $masrafsizYapilan, $gerekce, $planlanan, $arizaKaynak) {
        if ($masrafsiz) tsMasrafsizKapat($db, $mevcut, $masrafsizYapilan);
        $st = $db->prepare("
            UPDATE teknik_servis SET makine_tur=?, makine_id=?, makine_ad=?, tip=?, tarih=?, durum=?, oncelik=?, ariza=?, yapilan=?, teknisyen_id=?, maliyet=?, tamamlanma=?, notlar=?, musteriye_yansit=?, kiralama_id=?,
                gerekce=?, planlanan_tarih=?, musteri_makine_id=?, ariza_kaynak=?, updated_at=datetime('now','localtime')
            WHERE id=?
        ");
        $st->execute([
            $makineTur, $makineId, $makineAd,
            _tsTip($body['tip'] ?? 'ariza'), $tarih, $servisDurum, _tsOnc($body['oncelik'] ?? 'normal'),
            $ariza, trim(metin($body['yapilan'] ?? '')) ?: null,
            ((int)($body['teknisyen_id'] ?? 0)) ?: null,
            (float)($mevcut['maliyet'] ?? 0),
            dateStr('tamamlanma', $body),
            trim(metin($body['notlar'] ?? '')) ?: null,
            $yansit,
            $kiralamaId,
            $gerekce,
            $planlanan,
            $mmId,
            $arizaKaynak,
            $id,
        ]);
        _tsMakineDurumSenkron($db, $makineTur, $makineId, $servisDurum, _tsTip($body['tip'] ?? 'ariza'));
        if ($servisDurum === 'tamamlandi') _tsGorevKapat($db, $id);
        if ($servisDurum === 'tamamlandi' && ($mevcut['durum'] ?? '') !== 'tamamlandi') tsTamamlandi($db, $id);
        smYansitSonrasi($db, $id, $mevcut);
        });
        _tsPortalBildir($db, $mevcut, $servisDurum, trim(metin($body['yapilan'] ?? '')));
        jsonOk(null, 'Servis kaydı güncellendi.');

    case 'DELETE':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $bag=$db->prepare('SELECT 1 FROM hasar_degerlendirmeleri WHERE servis_id=?');$bag->execute([$id]);
        if($bag->fetchColumn())jsonError('Bu servis değerlendirme kararına bağlı; silinemez. Servis iş akışıyla sonuçlandırın.',409);

        $dSt = $db->prepare("SELECT makine_tur, makine_id FROM teknik_servis WHERE id=?");
        $dSt->execute([$id]);
        $dRow = $dSt->fetch();
        if (!$dRow) jsonError('Kayıt bulunamadı.', 404);
        _tsMakineErisim($db, $dRow['makine_tur'] ?? null, $dRow['makine_id'] ? (int)$dRow['makine_id'] : null);

        if (_tsAcikParcaTalebiVarMi($db, $id)
            && !(!empty($_GET['parca_yoksay']) && ($_SESSION['rol'] ?? '') === 'admin')) {
            jsonError('Bu servis kaydına bağlı, henüz teslim alınmamış bir parça talebi var — silinirse siparişin hangi iş için açıldığı kaybolur.', 409, ['acik_parca_var' => true]);
        }

        $_logEski = logKayitAl($db, 'teknik_servis', $id);
        if ($_logEski && tsDepoServisiMi($db, $_logEski)) jsonError('Dönüş kontrolünden açılan servis kaydı silinemez (dönüş kontrolünün kanıtı). Gerekirse durumunu güncelleyin.', 409);

        $iadeEdilen = 0;
        $db->beginTransaction();
        try {
            $hSt = $db->prepare("SELECT id, parca_id, miktar, tip FROM stok_hareket WHERE servis_id=? AND kaynak='servis'");
            $hSt->execute([$id]);
            $geri = $db->prepare("UPDATE yedek_parca SET stok=stok+?, updated_at=datetime('now','localtime') WHERE id=?");
            foreach ($hSt->fetchAll() as $h) {
                if (($h['tip'] ?? 'cikis') !== 'cikis') continue;
                $geri->execute([(int)$h['miktar'], (int)$h['parca_id']]);
                $iadeEdilen += (int)$h['miktar'];
            }
            $db->prepare("DELETE FROM stok_hareket WHERE servis_id=? AND kaynak='servis'")->execute([$id]);
            $fotoSt = $db->prepare("SELECT dosya_yolu FROM teknik_servis_fotograflari WHERE servis_id=?");
            $fotoSt->execute([$id]);
            $silinecekFotolar = $fotoSt->fetchAll(PDO::FETCH_COLUMN);
            $db->prepare("DELETE FROM teknik_servis_fotograflari WHERE servis_id=?")->execute([$id]);
            stServisSilindi($db, $id);
            $db->prepare("DELETE FROM teknik_servis WHERE id=?")->execute([$id]);
            $db->commit();
            foreach ($silinecekFotolar as $yol) {
                if (str_starts_with((string)$yol, 'servis/' . $id . '/')) @unlink(UPLOAD_PATH . $yol);
            }
            @rmdir(UPLOAD_PATH . 'servis/' . $id);
        } catch (\Throwable $e) {
            $db->rollBack();
            jsonError('Servis silinemedi, hiçbir değişiklik yapılmadı.', 500);
        }

        if (!empty($_logEski['makine_tur']) && !empty($_logEski['makine_id'])) {
            _tsMakineDurumSenkron($db, $_logEski['makine_tur'], (int)$_logEski['makine_id'], 'tamamlandi');
        }

        logla($db, 'teknik_servis', $id, 'sil', $_logEski, null,
            'Servis kaydı SİLİNDİ' . ($iadeEdilen > 0 ? ' · ' . $iadeEdilen . ' adet parça stoğa iade edildi' : ''));

        jsonOk(['stoga_iade' => $iadeEdilen],
            $iadeEdilen > 0
                ? 'Kayıt silindi, ' . $iadeEdilen . ' adet parça stoğa iade edildi.'
                : 'Kayıt silindi.');

    default:
        jsonError('Method Not Allowed', 405);
}
