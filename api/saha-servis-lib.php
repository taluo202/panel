<?php
declare(strict_types=1);
require_once __DIR__ . '/servis-kalem-lib.php';

const SS_DURUMLAR = ['atanmadi', 'atandi', 'yolda', 'sahada', 'rapor_gonderildi', 'iptal'];
const SS_SONUCLAR = [
    'yerinde_cozuldu' => 'Yerinde çözüldü',
    'subeye_gelecek'  => 'Şubeye gelecek',
    'parca_bekliyor'  => 'Parça bekleniyor',
    'tekrar_ziyaret'  => 'Tekrar ziyaret',
    'yapilamadi'      => 'İş yapılamadı',
];
const SS_FIYAT_TURLERI = ['parca' => 'Parça', 'iscilik' => 'İşçilik', 'yol' => 'Yol / ziyaret', 'diger' => 'Diğer'];
const SS_GEREKCELER = ['ana_bakim' => 'Ana bakım', 'ariza' => 'Arıza', 'kontrol' => 'Kontrol / ziyaret', 'ara_bakim' => 'Ara bakım', 'kesif' => 'Keşif', 'tanitim' => 'Tanıtım / eğitim'];
const SS_GEREKCE_TIP = ['ana_bakim' => 'bakim', 'ariza' => 'ariza', 'kontrol' => 'expertiz', 'ara_bakim' => 'bakim', 'kesif' => 'expertiz', 'tanitim' => 'expertiz'];
const SS_ARIZA_KAYNAK = ['makine' => 'Makine kaynaklı', 'musteri' => 'Müşteri kaynaklı'];
const SS_BILESENLER = ['tekerlek' => 'Tekerlek', 'korkuluk' => 'Korkuluklar', 'joystick' => 'Joystick', 'boya' => 'Boya', 'aku' => 'Akü',
    'kaporta' => 'Kaporta', 'destek_ayaklari' => 'Destek Ayakları', 'sepet' => 'Sepet / platform', 'bom' => 'Bom / makas kolu',
    'hidrolik' => 'Hidrolik sistem', 'motor' => 'Motor', 'elektrik' => 'Elektrik / kumanda', 'diger' => 'Diğer'];
const SS_BILESEN_DURUM = ['az_hasarli' => 'Az hasarlı / arızalı', 'cok_hasarli' => 'Çok hasarlı / arızalı'];

function ssGerekce(mixed $v): ?string { return is_string($v) && isset(SS_GEREKCELER[$v]) ? $v : null; }

function ssGerekceVarsayilan(?string $tip): string { return match ($tip) { 'bakim' => 'ana_bakim', 'expertiz' => 'kontrol', default => 'ariza' }; }

function ssBilesenTemizle(mixed $ham): ?string {
    $v = is_string($ham) ? json_decode($ham, true) : $ham;
    if (!is_array($v)) return null;
    $out = [];
    foreach ($v as $k => $d) if (is_string($k) && isset(SS_BILESENLER[$k]) && is_string($d) && isset(SS_BILESEN_DURUM[$d])) $out[$k] = $d;
    return $out ? json_encode($out, JSON_UNESCAPED_UNICODE) : null;
}

function ssBilesenListe(?string $json): array {
    $v = json_decode((string)$json, true);
    $out = [];
    if (is_array($v)) foreach (SS_BILESENLER as $k => $ad) if (isset($v[$k], SS_BILESEN_DURUM[$v[$k]])) $out[] = ['bilesen' => $k, 'ad' => $ad, 'durum' => $v[$k], 'durum_ad' => SS_BILESEN_DURUM[$v[$k]]];
    return $out;
}

function _ssSutunEkle(PDO $db, string $tablo, array $sutunlar): void {
    $var = array_flip(array_column($db->query("PRAGMA table_info($tablo)")->fetchAll(PDO::FETCH_ASSOC), 'name'));
    foreach ($sutunlar as $ad => $tanim) {
        if (!isset($var[$ad])) $db->exec("ALTER TABLE $tablo ADD COLUMN $ad $tanim");
    }
}

function ssSema(PDO $db): void {
    _ssSutunEkle($db, 'teknik_servis', [
        'yer' => "TEXT DEFAULT 'atolye'", 'musteri_id' => 'INTEGER', 'musteri_makine_id' => 'INTEGER',
        'saha_adres' => 'TEXT', 'saha_il' => 'TEXT', 'saha_ilce' => 'TEXT', 'santiye_id' => 'INTEGER',
        'irtibat_ad' => 'TEXT', 'irtibat_tel' => 'TEXT', 'planlanan_tarih' => 'TEXT',
        'saha_durum' => 'TEXT', 'saha_sonuc' => 'TEXT', 'ofis_inceleme' => 'INTEGER DEFAULT 0',
        'musteri_tutar' => 'REAL', 'fiyat_durum' => 'TEXT',
    ]);
    $db->exec("CREATE TABLE IF NOT EXISTS musteri_makineleri (
        id INTEGER PRIMARY KEY AUTOINCREMENT, musteri_id INTEGER NOT NULL, kategori TEXT, marka TEXT, model TEXT,
        seri_no TEXT, yil INTEGER, calisma_saati REAL, notlar TEXT, aktif INTEGER NOT NULL DEFAULT 1,
        created_at TEXT DEFAULT (datetime('now','localtime')), updated_at TEXT DEFAULT (datetime('now','localtime')));
      CREATE INDEX IF NOT EXISTS idx_mm_musteri ON musteri_makineleri(musteri_id);
      CREATE TABLE IF NOT EXISTS servis_ziyaretleri (
        id INTEGER PRIMARY KEY AUTOINCREMENT, servis_id INTEGER NOT NULL, teknisyen_id INTEGER,
        yola_cikis TEXT, varis TEXT, ayrilis TEXT, enlem REAL, boylam REAL, tespit TEXT, yapilan TEXT,
        sonuc TEXT, sonuc_not TEXT, musteri_ad TEXT, imza_musteri TEXT, imza_teknisyen TEXT, rapor_no TEXT UNIQUE,
        created_at TEXT DEFAULT (datetime('now','localtime')));
      CREATE INDEX IF NOT EXISTS idx_sz_servis ON servis_ziyaretleri(servis_id);
      CREATE TABLE IF NOT EXISTS servis_fiyat_kalemleri (
        id INTEGER PRIMARY KEY AUTOINCREMENT, servis_id INTEGER NOT NULL, tur TEXT NOT NULL, aciklama TEXT,
        miktar REAL NOT NULL DEFAULT 1, birim TEXT, birim_fiyat REAL NOT NULL DEFAULT 0, kdv_oran REAL NOT NULL DEFAULT 20,
        kaynak_kalem_id INTEGER, created_at TEXT DEFAULT (datetime('now','localtime')));
      CREATE INDEX IF NOT EXISTS idx_sfk_servis ON servis_fiyat_kalemleri(servis_id);
      CREATE INDEX IF NOT EXISTS idx_ts_teknisyen_saha ON teknik_servis(teknisyen_id, saha_durum);");
    _ssSutunEkle($db, 'teknik_servis', ['saha_kabul' => 'TEXT', 'gerekce' => 'TEXT', 'ucretlendir' => 'INTEGER']);
    _ssSutunEkle($db, 'servis_ziyaretleri', [
        'is_baslangic' => 'TEXT', 'is_bitis' => 'TEXT', 'iscilik_dk' => 'INTEGER', 'km_cikis' => 'REAL', 'km_donus' => 'REAL',
        'arac_id' => 'INTEGER', 'arac_plaka' => 'TEXT', 'calisma_elektrik' => 'REAL', 'calisma_dizel' => 'REAL', 'calisma_toplam' => 'REAL',
        'ariza_kaynak' => 'TEXT', 'musteri_talep' => 'TEXT', 'gerekce' => 'TEXT', 'teklif_isteniyor' => 'INTEGER DEFAULT 0', 'ucretlendir' => 'INTEGER',
        'musteri_tel' => 'TEXT', 'servis_yetkili_ad' => 'TEXT', 'bilesen_hasar' => 'TEXT', 'yol_kalem_id' => 'INTEGER', 'iscilik_kalem_id' => 'INTEGER',
    ]);
    _ssSutunEkle($db, 'teknik_servis_fotograflari', ['bilesen' => 'TEXT']);
}

function ssMusteriMakineDogrula(PDO $db, int $musteriId, int $makineId): array {
    $q = $db->prepare('SELECT * FROM musteri_makineleri WHERE id=? AND musteri_id=?');
    $q->execute([$makineId, $musteriId]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new DomainException('Seçilen makine bu müşteriye ait değil.');
    return $r;
}

function ssMusteriMakineAd(array $m): string {
    $ad = trim(implode(' ', array_filter([(string)($m['marka'] ?? ''), (string)($m['model'] ?? '')])));
    return ($ad !== '' ? $ad : 'Müşteri makinesi') . (trim((string)($m['seri_no'] ?? '')) !== '' ? ' · SN ' . trim((string)$m['seri_no']) : '');
}

function ssTutarGizle(array $r, ?PDO $db = null): array {
    require_once __DIR__ . '/servis-maliyet-lib.php';
    return smTutarGizle($r, $db);
}

function _ssSantiyeDogrula(PDO $db, ?int $santiyeId, ?int $musteriId): ?int {
    if (!$santiyeId) return null;
    $q = $db->prepare('SELECT musteri_id FROM santiyeler WHERE id=?');
    $q->execute([$santiyeId]);
    $sm = $q->fetchColumn();
    if ($sm === false) throw new DomainException('Şantiye bulunamadı.');
    if ($sm !== null && (int)$sm !== (int)$musteriId) throw new DomainException('Seçilen şantiye bu müşteriye ait değil.');
    return $santiyeId;
}

function ssIsAc(PDO $db, array $b): int {
    $ariza = trim(metin($b['ariza'] ?? ''));
    if ($ariza === '') throw new DomainException('Şikâyet / arıza açıklaması zorunludur.');
    $adres = trim(metin($b['saha_adres'] ?? ''));
    if ($adres === '') throw new DomainException('Servis adresi zorunludur.');
    $tur = in_array($b['makine_tur'] ?? '', ['manlift', 'arac'], true) ? $b['makine_tur'] : null;
    $mid = (int)($b['makine_id'] ?? 0) ?: null;
    $musteri = (int)($b['musteri_id'] ?? 0) ?: null;
    $mmId = (int)($b['musteri_makine_id'] ?? 0) ?: null;
    if ($tur && $mid) {
        $q = $db->prepare('SELECT * FROM ' . ($tur === 'arac' ? 'araclar' : 'manliftler') . ' WHERE id=?');
        $q->execute([$mid]);
        $mk = $q->fetch(PDO::FETCH_ASSOC);
        if (!$mk) throw new DomainException('Makine bulunamadı.');
        $makineAd = $tur === 'arac'
            ? trim(($mk['plaka'] ?? '') . ' ' . ($mk['marka'] ?? ''))
            : trim(($mk['kod'] ?? '') . ' · ' . trim(($mk['marka'] ?? '') . ' ' . ($mk['model'] ?? '')), ' ·');
        $mmId = null;
    } elseif ($mmId && $musteri) {
        $makineAd = ssMusteriMakineAd(ssMusteriMakineDogrula($db, $musteri, $mmId));
        $tur = null; $mid = null;
    } else {
        throw new DomainException('Makine seçin (filo makinesi ya da müşteri makinesi).');
    }
    if ($musteri) {
        $q = $db->prepare('SELECT sube FROM musteriler WHERE id=?'); $q->execute([$musteri]);
        $mRow = $q->fetch(PDO::FETCH_ASSOC);
        if (!$mRow) throw new DomainException('Müşteri bulunamadı.');
        $kisit = subeKisitiHam();
        if ($kisit !== null && trim((string)($mRow['sube'] ?? '')) !== $kisit) throw new DomainException('Bu müşteri başka bir şubeye ait; erişim yetkiniz yok.');
    }
    $santiye = _ssSantiyeDogrula($db, (int)($b['santiye_id'] ?? 0) ?: null, $musteri);
    $tek = (int)($b['teknisyen_id'] ?? 0) ?: null;
    $tarih = dateStr('tarih', $b) ?: date('Y-m-d');
    $onc = in_array($b['oncelik'] ?? '', ['dusuk', 'normal', 'yuksek', 'acil'], true) ? $b['oncelik'] : 'normal';
    $gerekce = ssGerekce($b['gerekce'] ?? null);
    $tip = $gerekce ? SS_GEREKCE_TIP[$gerekce] : (in_array($b['tip'] ?? '', ['ariza', 'bakim', 'expertiz'], true) ? $b['tip'] : 'ariza');
    $db->prepare("INSERT INTO teknik_servis (makine_tur, makine_id, makine_ad, tip, tarih, durum, oncelik, ariza, teknisyen_id, kaynak,
                    yer, musteri_id, musteri_makine_id, saha_adres, saha_il, saha_ilce, santiye_id, irtibat_ad, irtibat_tel, planlanan_tarih, saha_durum, kiralama_id, gerekce, notlar)
                  VALUES (?,?,?,?,?,'acik',?,?,?,?,'saha',?,?,?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([$tur, $mid, $makineAd, $tip, $tarih, $onc, $ariza, $tek,
                  ($b['kaynak'] ?? '') === 'portal' ? 'portal' : 'manuel',
                  $musteri, $mmId, $adres, trim(metin($b['saha_il'] ?? '')) ?: null, trim(metin($b['saha_ilce'] ?? '')) ?: null,
                  $santiye, trim(metin($b['irtibat_ad'] ?? '')) ?: null,
                  trim(metin($b['irtibat_tel'] ?? '')) ?: null, dateStr('planlanan_tarih', $b), $tek ? 'atandi' : 'atanmadi',
                  tsKaynakKiralamaBul($db, $tur, $mid, $tarih, ((int)($b['kiralama_id'] ?? 0)) ?: null), $gerekce,
                  trim(metin($b['notlar'] ?? '')) ?: null]);
    $id = (int)$db->lastInsertId();
    teknikServisMakineDurumSenkron($db, $tur, $mid, 'acik', $tip, $tarih, null);
    gorevOlustur($db, 'Saha servisi: ' . $makineAd, $ariza . ' — ' . $adres, 'teknik_servis', $id, $onc === 'acil' ? 'yuksek' : $onc);
    logla($db, 'teknik_servis', $id, 'ekle', null, logKayitAl($db, 'teknik_servis', $id), 'Saha servisi açıldı: ' . $makineAd);
    return $id;
}

function ssSahaGuncelle(PDO $db, int $sid, array $b): void {
    $q = $db->prepare("SELECT * FROM teknik_servis WHERE id=? AND yer='saha'");
    $q->execute([$sid]);
    $s = $q->fetch(PDO::FETCH_ASSOC);
    if (!$s) throw new DomainException('Saha servisi bulunamadı.');
    if (in_array($s['saha_durum'], ['rapor_gonderildi', 'iptal'], true)) throw new DomainException('Raporu gönderilmiş ya da iptal edilmiş iş değiştirilemez.');
    $eski = logKayitAl($db, 'teknik_servis', $sid);
    $tek = array_key_exists('teknisyen_id', $b) ? ((int)$b['teknisyen_id'] ?: null) : ($s['teknisyen_id'] !== null ? (int)$s['teknisyen_id'] : null);
    $durum = $s['saha_durum'];
    if ($tek && $durum === 'atanmadi') $durum = 'atandi';
    if (!$tek && $durum === 'atandi') $durum = 'atanmadi';
    $kisit = subeKisitiHam();
    if ($kisit !== null && ($sb = _ssServisSubesi($db, $sid)) !== '' && $sb !== $kisit) throw new DomainException('Bu saha işi başka şubenin; planlamayı o şubenin kullanıcısı ya da yönetici yapar.');
    $al = fn(string $k) => array_key_exists($k, $b) ? (trim(metin($b[$k] ?? '')) ?: null) : $s[$k];
    $adres = $al('saha_adres');
    if ($adres === null) throw new DomainException('Servis adresi zorunludur.');
    $kabul = $tek === ($s['teknisyen_id'] !== null ? (int)$s['teknisyen_id'] : null) ? $s['saha_kabul'] : null;
    $db->prepare("UPDATE teknik_servis SET teknisyen_id=?, saha_kabul=?, saha_durum=?, planlanan_tarih=?, saha_adres=?, saha_il=?, saha_ilce=?, santiye_id=?,
                    irtibat_ad=?, irtibat_tel=?, updated_at=datetime('now','localtime') WHERE id=?")
       ->execute([$tek, $kabul, $durum,array_key_exists('planlanan_tarih', $b) ? dateStr('planlanan_tarih', $b) : $s['planlanan_tarih'],
                  $adres, $al('saha_il'), $al('saha_ilce'),
                  array_key_exists('santiye_id', $b) ? _ssSantiyeDogrula($db, (int)$b['santiye_id'] ?: null, $s['musteri_id'] !== null ? (int)$s['musteri_id'] : null) : $s['santiye_id'],
                  $al('irtibat_ad'), $al('irtibat_tel'), $sid]);
    logla($db, 'teknik_servis', $sid, 'guncelle', $eski, logKayitAl($db, 'teknik_servis', $sid), 'Saha servisi güncellendi');
}


function _ssIsSatir(PDO $db, int $sid): array {
    $q = $db->prepare("SELECT * FROM teknik_servis WHERE id=? AND yer='saha'");
    $q->execute([$sid]);
    $s = $q->fetch(PDO::FETCH_ASSOC);
    if (!$s) throw new DomainException('Saha servisi bulunamadı.');
    return $s;
}

function _ssAtananDogrula(array $s, int $personelId): void {
    if (($_SESSION['rol'] ?? '') === 'admin') return;
    if ($personelId < 1 || (int)($s['teknisyen_id'] ?? 0) !== $personelId) throw new DomainException('Bu iş size atanmamış.');
}

function _ssAcikZiyaret(PDO $db, int $sid): ?array {
    $q = $db->prepare('SELECT * FROM servis_ziyaretleri WHERE servis_id=? AND rapor_no IS NULL ORDER BY id DESC LIMIT 1');
    $q->execute([$sid]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

function ssBenimIsler(PDO $db, int $personelId): array {
    $q = $db->prepare("SELECT s.id, s.makine_ad, s.makine_tur, s.makine_id, s.musteri_makine_id, s.ariza, s.oncelik, s.durum,
                              s.saha_adres, s.saha_il, s.saha_ilce, s.irtibat_ad, s.irtibat_tel, s.planlanan_tarih, s.saha_durum, s.saha_sonuc, s.saha_kabul,
                              mu.unvan AS musteri_unvan
                       FROM teknik_servis s LEFT JOIN musteriler mu ON mu.id = s.musteri_id
                       WHERE s.yer='saha' AND s.teknisyen_id=?
                         AND (s.saha_durum IN ('atandi','yolda','sahada')
                              OR (s.saha_durum='rapor_gonderildi' AND s.updated_at >= datetime('now','localtime','-7 days')))
                       ORDER BY CASE s.saha_durum WHEN 'sahada' THEN 0 WHEN 'yolda' THEN 1 WHEN 'atandi' THEN 2 ELSE 3 END,
                                COALESCE(s.planlanan_tarih,'9999-12-31'), s.id");
    $q->execute([$personelId]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function ssIsErisim(PDO $db, int $sid, int $personelId): array {
    $s = _ssIsSatir($db, $sid);
    _ssAtananDogrula($s, $personelId);
    return $s;
}

function _ssZiyaretSatir(array $z): array {
    $sayi = fn($v) => $v === null || $v === '' ? null : (float)$v;
    $kc = $sayi($z['km_cikis'] ?? null); $kd = $sayi($z['km_donus'] ?? null);
    return [
        'id' => (int)$z['id'], 'rapor_no' => $z['rapor_no'] ?? null, 'yola_cikis' => $z['yola_cikis'] ?? null, 'varis' => $z['varis'] ?? null, 'ayrilis' => $z['ayrilis'] ?? null,
        'is_baslangic' => $z['is_baslangic'] ?? null, 'is_bitis' => $z['is_bitis'] ?? null, 'iscilik_dk' => isset($z['iscilik_dk']) ? (int)$z['iscilik_dk'] : null,
        'km_cikis' => $kc, 'km_donus' => $kd, 'km_toplam' => $kc !== null && $kd !== null && $kd >= $kc ? round($kd - $kc, 1) : null,
        'arac_id' => isset($z['arac_id']) ? (int)$z['arac_id'] : null, 'arac_plaka' => $z['arac_plaka'] ?? null,
        'calisma_elektrik' => $sayi($z['calisma_elektrik'] ?? null), 'calisma_dizel' => $sayi($z['calisma_dizel'] ?? null), 'calisma_toplam' => $sayi($z['calisma_toplam'] ?? null),
        'ariza_kaynak' => $z['ariza_kaynak'] ?? null, 'musteri_talep' => $z['musteri_talep'] ?? null, 'gerekce' => $z['gerekce'] ?? null,
        'teklif_isteniyor' => (int)($z['teklif_isteniyor'] ?? 0), 'ucretlendir' => isset($z['ucretlendir']) ? (int)$z['ucretlendir'] : null,
        'tespit' => $z['tespit'] ?? null, 'yapilan' => $z['yapilan'] ?? null, 'sonuc' => $z['sonuc'] ?? null, 'sonuc_not' => $z['sonuc_not'] ?? null,
        'musteri_ad' => $z['musteri_ad'] ?? null, 'musteri_tel' => $z['musteri_tel'] ?? null, 'servis_yetkili_ad' => $z['servis_yetkili_ad'] ?? null,
        'bilesenler' => ssBilesenListe($z['bilesen_hasar'] ?? null),
    ];
}

function ssIs(PDO $db, int $sid): array {
    $s = _ssIsSatir($db, $sid);
    $q = $db->prepare('SELECT unvan, telefon FROM musteriler WHERE id=?'); $q->execute([(int)$s['musteri_id']]);
    $mu = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    if ($s['musteri_makine_id']) {
        $q = $db->prepare('SELECT kategori, marka, model, seri_no, yil, calisma_saati FROM musteri_makineleri WHERE id=?');
        $q->execute([(int)$s['musteri_makine_id']]);
        $makine = ($q->fetch(PDO::FETCH_ASSOC) ?: []) + ['sahip' => 'musteri'];
    } elseif ($s['makine_tur'] && $s['makine_id']) {
        $tablo = $s['makine_tur'] === 'arac' ? 'araclar' : 'manliftler';
        $q = $db->prepare("SELECT * FROM $tablo WHERE id=?"); $q->execute([(int)$s['makine_id']]);
        $m = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        $makine = ['sahip' => 'filo', 'kod' => $m['kod'] ?? ($m['plaka'] ?? null), 'marka' => $m['marka'] ?? null, 'model' => $m['model'] ?? null,
                   'seri_no' => $m['seri_no'] ?? null, 'calisma_saati' => $m['calisma_saati'] ?? null,
                   'yil' => $m['imal_yili'] ?? ($m['model_yili'] ?? null), 'makine_kodu' => $m['makine_kodu'] ?? null, 'kategori' => $m['kategori'] ?? null];
    } else $makine = ['sahip' => null];
    $z = $db->prepare('SELECT * FROM servis_ziyaretleri WHERE servis_id=? ORDER BY id');
    $z->execute([$sid]);
    $f = $db->prepare('SELECT id, dosya_yolu, kaynak, bilesen FROM teknik_servis_fotograflari WHERE servis_id=? ORDER BY id');
    $f->execute([$sid]);
    $p = $db->prepare('SELECT ad_soyad FROM personel WHERE id=?'); $p->execute([(int)$s['teknisyen_id']]);
    return [
        'id' => (int)$s['id'], 'durum' => $s['durum'], 'saha_durum' => $s['saha_durum'], 'saha_sonuc' => $s['saha_sonuc'], 'oncelik' => $s['oncelik'],
        'saha_kabul' => $s['saha_kabul'] ?? null, 'gerekce' => ($s['gerekce'] ?? null) ?: ssGerekceVarsayilan($s['tip'] ?? null), 'tip' => $s['tip'] ?? null,
        'ariza' => $s['ariza'], 'makine_ad' => $s['makine_ad'], 'makine_tur' => $s['makine_tur'], 'makine_id' => $s['makine_id'] !== null ? (int)$s['makine_id'] : null,
        'musteri_unvan' => $mu['unvan'] ?? null, 'musteri_telefon' => $mu['telefon'] ?? null,
        'saha_adres' => $s['saha_adres'], 'saha_il' => $s['saha_il'], 'saha_ilce' => $s['saha_ilce'],
        'irtibat_ad' => $s['irtibat_ad'], 'irtibat_tel' => $s['irtibat_tel'], 'planlanan_tarih' => $s['planlanan_tarih'], 'notlar' => $s['notlar'] ?? null,
        'teknisyen_id' => $s['teknisyen_id'] !== null ? (int)$s['teknisyen_id'] : null, 'teknisyen_ad' => $p->fetchColumn() ?: null,
        'makine' => $makine, 'ziyaretler' => array_map('_ssZiyaretSatir', $z->fetchAll(PDO::FETCH_ASSOC)), 'kalemler' => tsKalemListe($db, $sid), 'fotograflar' => $f->fetchAll(PDO::FETCH_ASSOC),
    ];
}

function _ssKm(mixed $v, string $ad): ?float {
    if ($v === null || (is_string($v) && trim($v) === '')) return null;
    $n = str_replace([' ', ','], ['', '.'], (string)$v);
    if (!is_numeric($n) || (float)$n < 0 || (float)$n > 10000000) throw new DomainException($ad . ' geçerli bir sayı olmalı.');
    return round((float)$n, 1);
}

function _ssArac(PDO $db, array $b): array {
    $id = (int)($b['arac_id'] ?? 0);
    if ($id > 0) {
        $q = $db->prepare('SELECT plaka FROM araclar WHERE id=?'); $q->execute([$id]);
        $pl = $q->fetchColumn();
        if ($pl === false) throw new DomainException('Seçilen araç bulunamadı.');
        return [$id, (string)$pl];
    }
    $pl = mb_substr(trim(metin($b['arac_plaka'] ?? '')), 0, 20);
    return [null, $pl !== '' ? mb_strtoupper($pl) : null];
}

function ssAdim(PDO $db, int $sid, string $adim, array $b, int $personelId): array {
    $s = _ssIsSatir($db, $sid);
    _ssAtananDogrula($s, $personelId);
    $teknisyen = $s['teknisyen_id'] !== null ? (int)$s['teknisyen_id'] : ($personelId ?: null);
    $simdi = date('Y-m-d H:i:s');
    if ($adim === 'kabul') {
        if ($s['saha_durum'] !== 'atandi') throw new DomainException('İş "atandı" durumunda değil.');
        if (!$s['saha_kabul']) {
            $db->prepare("UPDATE teknik_servis SET saha_kabul=?, updated_at=datetime('now','localtime') WHERE id=?")->execute([$simdi, $sid]);
            logla($db, 'teknik_servis', $sid, 'guncelle', null, ['saha_kabul' => $simdi], 'Saha servisi: teknisyen işi gördü ve kabul etti');
        }
        return ['saha_durum' => 'atandi', 'ziyaret_id' => 0, 'saha_kabul' => $s['saha_kabul'] ?: $simdi];
    }
    if ($adim === 'bitti') {
        $z = _ssAcikZiyaret($db, $sid);
        if ($s['saha_durum'] !== 'sahada' || !$z || !$z['varis']) throw new DomainException('Önce "Vardım" deyin.');
        if (!$z['is_bitis']) {
            $db->prepare('UPDATE servis_ziyaretleri SET is_bitis=? WHERE id=?')->execute([$simdi, (int)$z['id']]);
            logla($db, 'servis_ziyaretleri', (int)$z['id'], 'guncelle', null, ['is_bitis' => $simdi], 'Saha servisi: teknisyen servisi bitirdi (form doldurulacak)');
        }
        return ['saha_durum' => 'sahada', 'ziyaret_id' => (int)$z['id'], 'is_bitis' => $z['is_bitis'] ?: $simdi];
    }
    if ($adim === 'qr') {
        if (!$s['makine_tur'] || $s['makine_tur'] !== ($b['makine_tur'] ?? null) || (int)$s['makine_id'] !== (int)($b['makine_id'] ?? 0)) {
            throw new DomainException('Bu QR işin makinesi değil.');
        }
        if ($s['saha_durum'] === 'sahada') return ['saha_durum' => 'sahada', 'ziyaret_id' => (int)(_ssAcikZiyaret($db, $sid)['id'] ?? 0)];
        $adim = 'vardi';
    }
    $kabul = $s['saha_kabul'] ?: $simdi;
    if ($adim === 'yolda') {
        if ($s['saha_durum'] !== 'atandi') throw new DomainException('Yola çıkmak için iş "atandı" durumunda olmalı.');
        $km = _ssKm($b['km_cikis'] ?? null, 'Çıkış km');
        [$aracId, $plaka] = _ssArac($db, $b);
        $db->prepare('INSERT INTO servis_ziyaretleri (servis_id, teknisyen_id, yola_cikis, km_cikis, arac_id, arac_plaka, created_at) VALUES (?,?,?,?,?,?,?)')
           ->execute([$sid, $teknisyen, $simdi, $km, $aracId, $plaka, $simdi]);
        $zid = (int)$db->lastInsertId();
        $yeni = 'yolda';
    } elseif ($adim === 'vardi') {
        if (!in_array($s['saha_durum'], ['atandi', 'yolda'], true)) throw new DomainException('Bu işte "Vardım" denemez (durum: ' . ($s['saha_durum'] ?? '—') . ').');
        $enlem = is_numeric($b['enlem'] ?? null) && abs((float)$b['enlem']) <= 90 ? (float)$b['enlem'] : null;
        $boylam = is_numeric($b['boylam'] ?? null) && abs((float)$b['boylam']) <= 180 ? (float)$b['boylam'] : null;
        $z = _ssAcikZiyaret($db, $sid);
        if ($z) {
            $db->prepare('UPDATE servis_ziyaretleri SET varis=?, is_baslangic=COALESCE(is_baslangic, ?), enlem=?, boylam=? WHERE id=?')->execute([$simdi, $simdi, $enlem, $boylam, (int)$z['id']]);
            $zid = (int)$z['id'];
        } else {
            $db->prepare('INSERT INTO servis_ziyaretleri (servis_id, teknisyen_id, varis, is_baslangic, enlem, boylam, created_at) VALUES (?,?,?,?,?,?,?)')->execute([$sid, $teknisyen, $simdi, $simdi, $enlem, $boylam, $simdi]);
            $zid = (int)$db->lastInsertId();
        }
        $yeni = 'sahada';
    } else {
        throw new DomainException('Geçersiz adım.');
    }
    $db->prepare("UPDATE teknik_servis SET saha_durum=?, saha_kabul=?, durum=CASE WHEN ?='sahada' AND durum='acik' THEN 'devam' ELSE durum END,
                    updated_at=datetime('now','localtime') WHERE id=?")->execute([$yeni, $kabul, $yeni, $sid]);
    logla($db, 'teknik_servis', $sid, 'guncelle', null, ['saha_durum' => $yeni], $yeni === 'yolda' ? 'Saha servisi: teknisyen yola çıktı' : 'Saha servisi: teknisyen sahada, servise başladı');
    return ['saha_durum' => $yeni, 'ziyaret_id' => $zid];
}

function ssOfisDurum(PDO $db, int $sid, string $adim): array {
    $s = _ssIsSatir($db, $sid);
    if (($s['yer'] ?? '') !== 'saha') throw new DomainException('Bu kayıt saha servisi değil.');
    if (empty($s['teknisyen_id'])) throw new DomainException('Önce teknisyen atayın.');
    if (!in_array($adim, ['yolda', 'vardi'], true)) throw new DomainException('Geçersiz saha durumu.');
    $r = ssAdim($db, $sid, $adim, [], (int)$s['teknisyen_id']);
    logla($db, 'teknik_servis', $sid, 'guncelle', ['saha_durum' => $s['saha_durum']], ['saha_durum' => $r['saha_durum']], 'Saha durumu ofisten işaretlendi: ' . ($adim === 'yolda' ? 'Yolda' : 'Sahada'));
    return $r;
}

function _ssResimGecerli(string $dataUrl): bool {
    if (!preg_match('/^data:image\/(jpe?g|png|webp);base64,([a-zA-Z0-9+\/=]+)$/', $dataUrl, $m)) return false;
    $bin = base64_decode($m[2], true);
    return $bin !== false && strlen($bin) >= 100 && strlen($bin) <= MAX_FILE_SIZE && (bool)@getimagesizefromstring($bin);
}

function ssFiyatBekliyorAc(PDO $db, int $sid): ?int {
    $q = $db->prepare('SELECT musteri_makine_id, fiyat_durum, makine_ad, ucretlendir FROM teknik_servis WHERE id=?');
    $q->execute([$sid]);
    $s = $q->fetch(PDO::FETCH_ASSOC);
    if (!$s || !$s['musteri_makine_id'] || in_array($s['fiyat_durum'], ['fiyatlandi', 'ucretsiz'], true)) return null;
    if ($s['ucretlendir'] !== null && (int)$s['ucretlendir'] === 0) return null;
    if ($s['fiyat_durum'] !== 'bekliyor') $db->prepare("UPDATE teknik_servis SET fiyat_durum='bekliyor' WHERE id=?")->execute([$sid]);
    return gorevOlustur($db, 'Fiyatlandır: ' . ($s['makine_ad'] ?: 'saha servisi'),
        'Hizmet servisi tamamlandı. Parça, işçilik ve yol ücretini fiyatlandırın (Teknik Servis > Fiyatlandırılacaklar).', 'servis_fiyat', $sid);
}

function ssUcretKarari(PDO $db, int $sid, int $karar): void {
    require_once __DIR__ . '/servis-maliyet-lib.php';
    $db->prepare('UPDATE teknik_servis SET ucretlendir=? WHERE id=?')->execute([$karar, $sid]);
    $s = tsServis($db, $sid);
    if (in_array(smAkis($db, $s), ['hasar', 'donus'], true) || (int)($s['faturalandi'] ?? 0) === 1) return;
    $hizmet = !empty($s['musteri_makine_id']);
    if ($karar === 1) {
        if (!$hizmet && (int)($s['musteriye_yansit'] ?? 0) !== 1) $db->prepare('UPDATE teknik_servis SET musteriye_yansit=1 WHERE id=?')->execute([$sid]);
        if (in_array($s['fiyat_durum'] ?? '', ['fiyatlandi', 'ucretsiz'], true)) return;
        if (($s['fiyat_durum'] ?? '') !== 'bekliyor') $db->prepare("UPDATE teknik_servis SET fiyat_durum='bekliyor' WHERE id=?")->execute([$sid]);
        gorevOlustur($db, 'Fiyatlandır: ' . ($s['makine_ad'] ?: 'saha servisi'),
            'Teknisyen servisin ücretlendirileceğini bildirdi. Teknik Servis > Fiyatlandırılacaklar sekmesinden parça, işçilik ve yol satırlarını fiyatlandırın.', 'servis_fiyat', $sid);
        return;
    }
    if (!$hizmet && (int)($s['musteriye_yansit'] ?? 0) === 1) $db->prepare('UPDATE teknik_servis SET musteriye_yansit=0 WHERE id=?')->execute([$sid]);
    if (($s['fiyat_durum'] ?? '') === 'bekliyor') {
        $db->prepare('UPDATE teknik_servis SET fiyat_durum=NULL WHERE id=?')->execute([$sid]);
        $db->prepare("UPDATE gorevler SET durum='tamamlandi', tamamlanma=?, updated_at=datetime('now','localtime') WHERE kaynak_modul='servis_fiyat' AND kaynak_id=? AND durum='acik'")
           ->execute([date('Y-m-d'), $sid]);
    }
}

function ssZiyaretler(PDO $db, int $sid): array {
    $q = $db->prepare('SELECT z.id, z.rapor_no, z.sonuc, z.ayrilis, p.ad_soyad AS teknisyen_ad FROM servis_ziyaretleri z
                       LEFT JOIN personel p ON p.id = z.teknisyen_id WHERE z.servis_id=? AND z.rapor_no IS NOT NULL ORDER BY z.id DESC');
    $q->execute([$sid]);
    return array_map(fn($z) => $z + ['sonuc_etiket' => SS_SONUCLAR[$z['sonuc']] ?? $z['sonuc']], $q->fetchAll(PDO::FETCH_ASSOC));
}

function ssZiyaretDetay(PDO $db, int $sid): array {
    $q = $db->prepare('SELECT z.*, p.ad_soyad AS teknisyen_ad FROM servis_ziyaretleri z LEFT JOIN personel p ON p.id = z.teknisyen_id WHERE z.servis_id=? ORDER BY z.id');
    $q->execute([$sid]);
    $url = fn(?string $yol) => $yol ? 'api/dosya.php?y=' . $yol : null;
    return array_map(fn($z) => _ssZiyaretSatir($z) + [
        'teknisyen_ad' => $z['teknisyen_ad'], 'sonuc_etiket' => SS_SONUCLAR[$z['sonuc'] ?? ''] ?? null,
        'gerekce_etiket' => SS_GEREKCELER[$z['gerekce'] ?? ''] ?? null, 'ariza_kaynak_etiket' => SS_ARIZA_KAYNAK[$z['ariza_kaynak'] ?? ''] ?? null,
        'imza_musteri_url' => $url($z['imza_musteri']), 'imza_teknisyen_url' => $url($z['imza_teknisyen']),
    ], $q->fetchAll(PDO::FETCH_ASSOC));
}

function ssFiyatOneri(PDO $db, int $sid): array {
    require_once __DIR__ . '/servis-maliyet-lib.php';
    return smOneriSatirlari($db, $sid);
}

function _ssServisSubesi(PDO $db, int $sid): string {
    $q = $db->prepare('SELECT ' . servisSubeSql('s', 'k', 'ms', 'mk') . ' FROM teknik_servis s LEFT JOIN kiralamalar k ON k.id = s.kiralama_id
                       LEFT JOIN musteriler ms ON ms.id = s.musteri_id LEFT JOIN musteriler mk ON mk.id = k.musteri_id WHERE s.id=?');
    $q->execute([$sid]);
    return trim((string)$q->fetchColumn());
}

function ssFiyatSubeDogrula(PDO $db, int $sid): void {
    $kisit = subeKisitiHam();
    if ($kisit === null) return;
    $sb = _ssServisSubesi($db, $sid);
    if ($sb !== '' && $sb !== $kisit) throw new DomainException('Bu servis başka bir şubeye ait; fiyatına erişim yetkiniz yok.');
}

function ssOkumaErisim(PDO $db, int $sid, int $personelId): void {
    $q = $db->prepare('SELECT teknisyen_id FROM teknik_servis WHERE id=?');
    $q->execute([$sid]);
    $s = $q->fetch(PDO::FETCH_ASSOC);
    if (!$s) throw new DomainException('Servis kaydı bulunamadı.');
    if (($_SESSION['rol'] ?? '') === 'admin') return;
    if ($personelId > 0 && (int)($s['teknisyen_id'] ?? 0) === $personelId) return;
    $kisit = subeKisitiHam();
    if ($kisit === null) return;
    $sb = _ssServisSubesi($db, $sid);
    if ($sb !== '' && $sb !== $kisit) throw new DomainException('Bu servis başka bir şubeye ait; raporuna erişim yetkiniz yok.');
}

function ssZiyaretServisId(PDO $db, int $zid): int {
    $q = $db->prepare('SELECT servis_id FROM servis_ziyaretleri WHERE id=?');
    $q->execute([$zid]);
    $sid = (int)$q->fetchColumn();
    if ($sid < 1) throw new DomainException('Rapor bulunamadı.');
    return $sid;
}

function ssFiyatGetir(PDO $db, int $sid): array {
    $q = $db->prepare('SELECT s.id, s.makine_ad, s.musteri_tutar, s.fiyat_durum, s.durum, mu.unvan AS musteri_unvan FROM teknik_servis s LEFT JOIN musteriler mu ON mu.id = s.musteri_id WHERE s.id=?');
    $q->execute([$sid]);
    $s = $q->fetch(PDO::FETCH_ASSOC);
    if (!$s) throw new DomainException('Servis kaydı bulunamadı.');
    $k = $db->prepare('SELECT tur, aciklama, miktar, birim, birim_fiyat, kdv_oran, kaynak_kalem_id FROM servis_fiyat_kalemleri WHERE servis_id=? ORDER BY id');
    $k->execute([$sid]);
    $kayitli = $k->fetchAll(PDO::FETCH_ASSOC);
    return $s + ['kalemler' => $kayitli ?: ssFiyatOneri($db, $sid), 'oneri' => !$kayitli];
}

function ssFiyatKaydet(PDO $db, int $sid, array $kalemler, bool $ucretsiz): array {
    $q = $db->prepare('SELECT * FROM teknik_servis WHERE id=?'); $q->execute([$sid]);
    $s = $q->fetch(PDO::FETCH_ASSOC);
    if (!$s) throw new DomainException('Servis kaydı bulunamadı.');
    if ((int)$s['faturalandi'] === 1) throw new DomainException('Faturalandırılmış servis yeniden fiyatlandırılamaz; önce fatura işaretini kaldırın.');
    require_once __DIR__ . '/servis-maliyet-lib.php';
    if (!empty($s['fatura_yontemi']) || in_array(smAkis($db, $s), ['hasar', 'donus'], true))
        throw new DomainException('Bu servisin bedeli Teknik Servis › Fiyatlandırılacaklar\'da kararlaştırılır (bedel kararı ve hakediş bağı orada).');
    if ($s['kaynak'] === 'depo_inceleme' || smHasarBagi($db, $sid) || (!$s['musteri_makine_id'] && $s['fiyat_durum'] === null && (int)$s['musteriye_yansit'] !== 1))
        throw new DomainException('Bu servis fiyatlandırma akışında değil (hizmet işi ya da müşteriye yansıtılan servis olmalı).');
    $temiz = [];
    if (!$ucretsiz) {
        foreach ($kalemler as $k) {
            if (!is_array($k)) continue;
            $tur = (string)($k['tur'] ?? '');
            if (!isset(SS_FIYAT_TURLERI[$tur])) throw new DomainException('Geçersiz satır türü.');
            $miktar = (float)str_replace(',', '.', (string)($k['miktar'] ?? 0));
            $fiyat = (float)str_replace(',', '.', (string)($k['birim_fiyat'] ?? 0));
            if ($miktar <= 0) throw new DomainException('Miktar sıfırdan büyük olmalı.');
            if ($fiyat < 0) throw new DomainException('Birim fiyat negatif olamaz.');
            $kdv = in_array((float)($k['kdv_oran'] ?? 20), [0.0, 1.0, 10.0, 20.0], true) ? (float)$k['kdv_oran'] : 20.0;
            $temiz[] = [$sid, $tur, mb_substr(trim(metin($k['aciklama'] ?? '')), 0, 300) ?: SS_FIYAT_TURLERI[$tur], $miktar,
                        trim(metin($k['birim'] ?? '')) ?: null, $fiyat, $kdv, (int)($k['kaynak_kalem_id'] ?? 0) ?: null];
        }
        if (!$temiz) throw new DomainException('En az bir satır girin ya da "Ücretsiz" işaretleyin.');
    }
    $eski = logKayitAl($db, 'teknik_servis', $sid);
    $db->prepare('DELETE FROM servis_fiyat_kalemleri WHERE servis_id=?')->execute([$sid]);
    $ins = $db->prepare('INSERT INTO servis_fiyat_kalemleri (servis_id, tur, aciklama, miktar, birim, birim_fiyat, kdv_oran, kaynak_kalem_id) VALUES (?,?,?,?,?,?,?,?)');
    $toplam = 0.0;
    foreach ($temiz as $t) { $ins->execute($t); $toplam += $t[3] * $t[5]; }
    $toplam = round($toplam, 2);
    $durum = $ucretsiz ? 'ucretsiz' : 'fiyatlandi';
    $db->prepare("UPDATE teknik_servis SET musteri_tutar=?, fiyat_durum=?, updated_at=datetime('now','localtime') WHERE id=?")->execute([$toplam, $durum, $sid]);
    smToplamYaz($db, $sid, false);
    $db->prepare("UPDATE gorevler SET durum='tamamlandi', tamamlanma=?, updated_at=datetime('now','localtime') WHERE kaynak_modul='servis_fiyat' AND kaynak_id=? AND durum='acik'")
       ->execute([date('Y-m-d'), $sid]);
    logla($db, 'teknik_servis', $sid, 'guncelle', $eski, logKayitAl($db, 'teknik_servis', $sid),
          $ucretsiz ? 'Saha servisi ücretsiz işaretlendi' : 'Saha servisi fiyatlandırıldı · ' . number_format($toplam, 2, ',', '.') . ' ₺ (KDV hariç)');
    return ['musteri_tutar' => $toplam, 'fiyat_durum' => $durum];
}

function ssRaporBelge(PDO $db, int $zid): array {
    $q = $db->prepare('SELECT z.*, p.ad_soyad AS teknisyen_ad FROM servis_ziyaretleri z LEFT JOIN personel p ON p.id = z.teknisyen_id WHERE z.id=? AND z.rapor_no IS NOT NULL');
    $q->execute([$zid]);
    $z = $q->fetch(PDO::FETCH_ASSOC);
    if (!$z) throw new DomainException('Rapor bulunamadı.');
    $q = $db->prepare('SELECT s.*, mu.unvan AS musteri_unvan FROM teknik_servis s LEFT JOIN musteriler mu ON mu.id = s.musteri_id WHERE s.id=?');
    $q->execute([(int)$z['servis_id']]);
    $s = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    $mk = [];
    if (!empty($s['musteri_makine_id'])) {
        $m = $db->prepare('SELECT marka, model, seri_no, kategori, yil, calisma_saati FROM musteri_makineleri WHERE id=?'); $m->execute([(int)$s['musteri_makine_id']]); $mk = $m->fetch(PDO::FETCH_ASSOC) ?: [];
    } elseif (!empty($s['makine_tur']) && !empty($s['makine_id'])) {
        $m = $db->prepare($s['makine_tur'] === 'arac' ? 'SELECT plaka AS kod, marka, model, kategori, metraj, makine_kodu, calisma_saati FROM araclar WHERE id=?'
            : 'SELECT kod, marka, model, seri_no, kategori, metraj, makine_kodu, imal_yili AS yil, calisma_saati FROM manliftler WHERE id=?');
        $m->execute([(int)$s['makine_id']]);
        if ($mk = $m->fetch(PDO::FETCH_ASSOC) ?: []) {
            $mk['kategori'] = makineKategoriAdi($db, $mk);
            $mk['metraj'] = metrajSayi($mk['metraj'] ?? null);
            $mk['olcu_birim'] = $mk['metraj'] !== null ? mkBirim($db, $mk['kategori']) : null;
        }
    }
    $seri = ($mk['seri_no'] ?? null) ?: null;
    $sayi = fn($x) => str_replace('.', ',', (string)(round((float)$x, 3) + 0));
    $oto = array_values(array_filter([(int)($z['iscilik_kalem_id'] ?? 0), (int)($z['yol_kalem_id'] ?? 0)]));
    $hepsi = array_values(array_filter(tsKalemListe($db, (int)$z['servis_id']), fn($k) => !in_array((int)$k['id'], $oto, true)));
    $kalemler = array_map(fn($k) => ['metin' => $k['tur'] === 'iscilik'
        ? 'İşçilik · ' . $k['aciklama'] . ' · ' . $sayi($k['saat']) . ' saat × ' . (int)$k['kisi'] . ' kişi'
        : 'Malzeme · ' . $k['aciklama'] . ' · ' . $sayi($k['miktar']) . ' ' . ($k['birim'] ?: 'adet') . ($k['parca_id'] ? ' · stoktan' : '')],
        array_values(array_filter($hepsi, fn($k) => in_array($k['tur'], ['malzeme', 'iscilik'], true))));
    $pq = $db->prepare("SELECT k.aciklama, k.miktar, k.birim, k.parca_id, k.stok_kodu, p.ad AS parca_ad, p.parca_no FROM teknik_servis_kalemleri k
                        LEFT JOIN yedek_parca p ON p.id = k.parca_id WHERE k.servis_id=? AND k.tur='malzeme' ORDER BY k.id");
    $pq->execute([(int)$z['servis_id']]);
    $parcalar = array_map(fn($p) => ['kod' => (string)(($p['parca_id'] ? $p['parca_no'] : $p['stok_kodu']) ?? ''),
        'ad' => (string)(($p['parca_id'] && $p['parca_ad']) ? $p['parca_ad'] : $p['aciklama']), 'miktar' => $sayi($p['miktar']), 'birim' => $p['birim'] ?: 'adet',
        'stoktan' => (bool)$p['parca_id']], $pq->fetchAll(PDO::FETCH_ASSOC));
    $url = fn(?string $yol) => $yol ? 'api/dosya.php?y=' . $yol : null;
    $f = $db->prepare("SELECT dosya_yolu, bilesen FROM teknik_servis_fotograflari WHERE servis_id=? AND kaynak IN ('saha','tamir_sonrasi') ORDER BY id");
    $f->execute([(int)$z['servis_id']]);
    $fotoSatir = $f->fetchAll(PDO::FETCH_ASSOC);
    $zs = _ssZiyaretSatir($z);
    $gerekce = ($z['gerekce'] ?? null) ?: (($s['gerekce'] ?? null) ?: ssGerekceVarsayilan($s['tip'] ?? null));
    $saat = fn(?string $t) => $t ? substr($t, 11, 5) : null;
    return [
        'rapor_no' => $z['rapor_no'], 'servis_id' => (int)$z['servis_id'], 'tarih' => substr((string)($z['ayrilis'] ?: $z['created_at']), 0, 10),
        'varis' => $z['varis'], 'ayrilis' => $z['ayrilis'], 'yola_cikis' => $z['yola_cikis'], 'kabul' => $s['saha_kabul'] ?? null,
        'musteri' => ['unvan' => $s['musteri_unvan'] ?? null, 'adres' => trim(implode(', ', array_filter([$s['saha_adres'] ?? '', $s['saha_ilce'] ?? '', $s['saha_il'] ?? ''])))],
        'makine' => ['ad' => $s['makine_ad'] ?? null, 'seri_no' => $seri, 'kod' => $mk['kod'] ?? null, 'marka' => $mk['marka'] ?? null, 'model' => $mk['model'] ?? null,
                     'kategori' => ($mk['kategori'] ?? null) ?: null, 'metraj' => $mk['metraj'] ?? null, 'olcu_birim' => $mk['olcu_birim'] ?? null,
                     'makine_kodu' => ($mk['makine_kodu'] ?? null) ?: null, 'yil' => !empty($mk['yil']) ? (int)$mk['yil'] : null,
                     'sahip' => !empty($s['musteri_makine_id']) ? 'musteri' : 'filo'],
        'calisma' => ['elektrik' => $zs['calisma_elektrik'], 'dizel' => $zs['calisma_dizel'], 'toplam' => $zs['calisma_toplam']],
        'ariza_kaynak' => $zs['ariza_kaynak'], 'ariza_kaynak_etiket' => SS_ARIZA_KAYNAK[$zs['ariza_kaynak'] ?? ''] ?? null,
        'bilesenler' => $zs['bilesenler'],
        'sikayet' => $zs['musteri_talep'] ?: ($s['ariza'] ?? null), 'gerekce' => $gerekce, 'gerekce_etiket' => SS_GEREKCELER[$gerekce] ?? null,
        'tespit' => $z['tespit'], 'yapilan' => $z['yapilan'], 'sonuc' => $z['sonuc'],
        'sonuc_etiket' => SS_SONUCLAR[$z['sonuc']] ?? $z['sonuc'], 'sonuc_not' => $z['sonuc_not'], 'kalemler' => $kalemler, 'parcalar' => $parcalar,
        'is_tamam' => $z['sonuc'] === 'yerinde_cozuldu', 'teklif_isteniyor' => (bool)$zs['teklif_isteniyor'],
        'iscilik' => ['baslangic' => $saat($zs['is_baslangic']), 'bitis' => $saat($zs['is_bitis']), 'sure_dk' => $zs['iscilik_dk']],
        'yol' => ['cikis_km' => $zs['km_cikis'], 'donus_km' => $zs['km_donus'], 'toplam_km' => $zs['km_toplam'], 'plaka' => $zs['arac_plaka']],
        'fotolar' => array_map(fn($x) => $url($x['dosya_yolu']), $fotoSatir),
        'foto_etiketleri' => array_map(fn($x) => SS_BILESENLER[(string)$x['bilesen']] ?? null, $fotoSatir),
        'musteri_ad' => $z['musteri_ad'], 'musteri_tel' => $zs['musteri_tel'],
        'imza_musteri_url' => $url($z['imza_musteri']), 'imza_teknisyen_url' => $url($z['imza_teknisyen']), 'teknisyen_ad' => $z['teknisyen_ad'],
        'servis_yetkili_ad' => $zs['servis_yetkili_ad'] ?: $z['teknisyen_ad'],
    ];
}

function ssRaporPdf(PDO $db, array $r): array {
    require_once __DIR__ . '/belge-pdf-lib.php';
    $imza = function (?string $url): ?string {
        $yol = $url ? substr($url, strlen('api/dosya.php?y=')) : '';
        if ($yol === '' || str_contains($yol, '..') || !preg_match('~^servis/\d+/[a-f0-9]+\.(png|jpe?g|webp)$~', $yol)) return null;
        $ham = @file_get_contents(UPLOAD_PATH . $yol);
        if ($ham === false || !($j = bpJpeg($ham, 600))) return null;
        return 'data:image/jpeg;base64,' . base64_encode($j);
    };
    $fotolar = [];
    foreach ($r['fotolar'] as $i => $u) {
        $yol = substr((string)$u, strlen('api/dosya.php?y='));
        if ($yol !== '' && !str_contains($yol, '..')) $fotolar[] = ['yol' => $yol, 'etiket' => $r['foto_etiketleri'][$i] ?? ('Fotoğraf ' . ($i + 1))];
    }
    $fotolar = array_slice($fotolar, 0, 9);
    $c = new BelgePdf($db, array_column($fotolar, 'yol'));
    $c->servisFormu($r + ['foto_pdf' => $fotolar, 'imza_musteri_veri' => $imza($r['imza_musteri_url']), 'imza_teknisyen_veri' => $imza($r['imza_teknisyen_url'])]);
    return ['ad' => bpDosyaAdi((string)$r['rapor_no']), 'tur' => 'application/pdf', 'veri' => $c->cikti()];
}

function _ssSaat(mixed $v, string $taban): ?string {
    $v = is_scalar($v) ? trim((string)$v) : '';
    if ($v === '') return null;
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $v, $m)) {
        if ((int)$m[1] > 23 || (int)$m[2] > 59) throw new DomainException('Saat SS:DD biçiminde olmalı.');
        return substr($taban, 0, 10) . sprintf(' %02d:%02d:00', (int)$m[1], (int)$m[2]);
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2})(?::\d{2})?$/', $v, $m) && strtotime($m[1]) !== false) return $m[1] . ' ' . $m[2] . ':' . $m[3] . ':00';
    throw new DomainException('Saat SS:DD biçiminde olmalı.');
}

function _ssFormAlanlari(PDO $db, array $s, array $z, array $b): array {
    $simdi = date('Y-m-d H:i:s');
    $tabanBas = (string)($z['is_baslangic'] ?: ($z['varis'] ?: $simdi));
    $bas = _ssSaat($b['is_baslangic'] ?? null, $tabanBas) ?? $tabanBas;
    $bitHam = trim(is_scalar($b['is_bitis'] ?? null) ? (string)$b['is_bitis'] : '');
    $bit = _ssSaat($bitHam, $bas) ?? (string)($z['is_bitis'] ?: $simdi);
    if (strtotime($bit) < strtotime($bas) && preg_match('/^\d{1,2}:\d{2}$/', $bitHam)) $bit = date('Y-m-d H:i:s', strtotime($bit . ' +1 day'));
    if (strtotime($bit) < strtotime($bas)) throw new DomainException('İşin bitiş saati başlangıçtan önce olamaz.');
    $dk = (int)floor((strtotime($bit) - strtotime($bas)) / 60);
    if ($dk > 24 * 60) throw new DomainException('İşçilik süresi 24 saati aşamaz; başlangıç ve bitiş saatini kontrol edin.');
    $kmC = array_key_exists('km_cikis', $b) && trim((string)$b['km_cikis']) !== '' ? _ssKm($b['km_cikis'], 'Çıkış km') : ($z['km_cikis'] !== null ? (float)$z['km_cikis'] : null);
    $kmD = _ssKm($b['km_donus'] ?? null, 'Dönüş km');
    if ($kmC !== null && $kmD !== null && $kmD < $kmC) throw new DomainException('Dönüş km çıkış km\'den küçük olamaz.');
    [$aracId, $plaka] = (int)($b['arac_id'] ?? 0) > 0 || trim(metin($b['arac_plaka'] ?? '')) !== '' ? _ssArac($db, $b)
        : [$z['arac_id'] !== null ? (int)$z['arac_id'] : null, $z['arac_plaka']];
    $e = _ssKm($b['calisma_elektrik'] ?? null, 'Elektrik çalışma saati');
    $d = _ssKm($b['calisma_dizel'] ?? null, 'Dizel çalışma saati');
    $t = _ssKm($b['calisma_toplam'] ?? null, 'Toplam çalışma saati') ?? ($e !== null || $d !== null ? round((float)$e + (float)$d, 1) : null);
    $ak = (string)($b['ariza_kaynak'] ?? '');
    $talep = trim(metin($b['musteri_talep'] ?? ''));
    $q = $db->prepare('SELECT ad_soyad FROM personel WHERE id=?'); $q->execute([(int)($z['teknisyen_id'] ?? $s['teknisyen_id'] ?? 0)]);
    return [
        'is_baslangic' => $bas, 'is_bitis' => $bit, 'iscilik_dk' => $dk, 'km_cikis' => $kmC, 'km_donus' => $kmD, 'arac_id' => $aracId, 'arac_plaka' => $plaka,
        'calisma_elektrik' => $e, 'calisma_dizel' => $d, 'calisma_toplam' => $t, 'ariza_kaynak' => isset(SS_ARIZA_KAYNAK[$ak]) ? $ak : null,
        'musteri_talep' => mb_substr($talep !== '' ? $talep : (string)($s['ariza'] ?? ''), 0, 2000) ?: null, 'gerekce' => ssGerekce($b['gerekce'] ?? null),
        'teklif_isteniyor' => !empty($b['teklif_isteniyor']) ? 1 : 0, 'musteri_tel' => mb_substr(trim(metin($b['musteri_tel'] ?? '')), 0, 30) ?: null,
        'servis_yetkili_ad' => mb_substr(trim(metin($b['servis_yetkili_ad'] ?? '')), 0, 120) ?: ($q->fetchColumn() ?: null),
        'bilesen_hasar' => ssBilesenTemizle($b['bilesen_hasar'] ?? null),
    ];
}

function _ssOtoKalemler(PDO $db, int $sid, int $zid, string $raporNo, array $f): void {
    $kalem = function (array $g) use ($db, $sid): ?int {
        try { return tsKalemEkle($db, $sid, $g)['id']; } catch (DomainException $e) { return null; }
    };
    $isc = $f['iscilik_dk'] > 0 ? $kalem(['tur' => 'iscilik', 'aciklama' => 'Saha işçiliği · ' . $raporNo . ' · ' . substr($f['is_baslangic'], 11, 5) . '–' . substr($f['is_bitis'], 11, 5),
                                           'saat' => round($f['iscilik_dk'] / 60, 3), 'kisi' => 1]) : null;
    $km = $f['km_cikis'] !== null && $f['km_donus'] !== null ? round($f['km_donus'] - $f['km_cikis'], 1) : null;
    $yol = $kalem(['tur' => 'yol', 'aciklama' => _ssYolAciklama($raporNo, $km, $f['arac_plaka']), 'miktar' => 1, 'birim' => 'sefer']);
    $db->prepare('UPDATE servis_ziyaretleri SET iscilik_kalem_id=?, yol_kalem_id=? WHERE id=?')->execute([$isc, $yol, $zid]);
}

function _ssYolAciklama(string $raporNo, ?float $km, ?string $plaka): string {
    return implode(' · ', array_filter(['Yol / ziyaret', $raporNo, $km !== null ? str_replace('.', ',', (string)($km + 0)) . ' km' : '', (string)$plaka], fn($x) => $x !== ''));
}

function ssDonusKm(PDO $db, int $zid, mixed $kmDonus, int $personelId): array {
    $q = $db->prepare('SELECT * FROM servis_ziyaretleri WHERE id=? AND rapor_no IS NOT NULL');
    $q->execute([$zid]);
    $z = $q->fetch(PDO::FETCH_ASSOC);
    if (!$z) throw new DomainException('Rapor bulunamadı.');
    $s = _ssIsSatir($db, (int)$z['servis_id']);
    _ssAtananDogrula($s, $personelId);
    $km = _ssKm($kmDonus, 'Dönüş km');
    if ($km === null) throw new DomainException('Dönüş km yazın.');
    if ($z['km_cikis'] !== null && $km < (float)$z['km_cikis']) throw new DomainException('Dönüş km çıkış km\'den küçük olamaz.');
    $db->prepare('UPDATE servis_ziyaretleri SET km_donus=? WHERE id=?')->execute([$km, $zid]);
    $toplam = $z['km_cikis'] !== null ? round($km - (float)$z['km_cikis'], 1) : null;
    if ($z['yol_kalem_id'] && (int)($s['faturalandi'] ?? 0) !== 1)
        $db->prepare('UPDATE teknik_servis_kalemleri SET aciklama=? WHERE id=? AND servis_id=?')
           ->execute([_ssYolAciklama((string)$z['rapor_no'], $toplam, $z['arac_plaka']), (int)$z['yol_kalem_id'], (int)$z['servis_id']]);
    logla($db, 'servis_ziyaretleri', $zid, 'guncelle', ['km_donus' => $z['km_donus']], ['km_donus' => $km], 'Saha servisi ' . $z['rapor_no'] . ': dönüş km girildi');
    return ['km_donus' => $km, 'km_toplam' => $toplam];
}

function ssRaporGonder(PDO $db, int $sid, array $b, int $personelId): array {
    islemKilitAl($db);
    $s = _ssIsSatir($db, $sid);
    _ssAtananDogrula($s, $personelId);
    $z = _ssAcikZiyaret($db, $sid);
    if ($s['saha_durum'] !== 'sahada' || !$z || !$z['varis']) {
        $son = $db->prepare('SELECT rapor_no FROM servis_ziyaretleri WHERE servis_id=? ORDER BY id DESC LIMIT 1');
        $son->execute([$sid]);
        if ($s['saha_durum'] !== 'sahada' && $son->fetchColumn()) throw new DomainException('Bu ziyaretin raporu zaten gönderildi.');
        throw new DomainException('Önce "Vardım" deyin.');
    }
    $tespit = trim(metin($b['tespit'] ?? ''));
    if ($tespit === '') throw new DomainException('Tespit yazın.');
    $sonuc = (string)($b['sonuc'] ?? '');
    if (!isset(SS_SONUCLAR[$sonuc])) throw new DomainException('Sonuç seçin.');
    $yapilan = trim(metin($b['yapilan'] ?? ''));
    if ($sonuc === 'yerinde_cozuldu' && $yapilan === '') throw new DomainException('Yapılan işi yazın.');
    $imzaT = (string)($b['imza_teknisyen'] ?? '');
    if ($imzaT === '') throw new DomainException('Teknisyen imzası eksik.');
    $musteriAd = trim(metin($b['musteri_ad'] ?? ''));
    $imzaM = (string)($b['imza_musteri'] ?? '');
    $musteriZorunlu = $sonuc !== 'yapilamadi';
    if ($musteriZorunlu && $musteriAd === '') throw new DomainException('Müşteri adı yazın.');
    if ($musteriZorunlu && $imzaM === '') throw new DomainException('Müşteri imzası eksik.');
    if ($imzaM !== '' && !_ssResimGecerli($imzaM)) throw new DomainException('Müşteri imzası okunamadı (PNG/JPEG, en çok ' . (int)(MAX_FILE_SIZE / 1048576) . ' MB). Yeniden imzalatın.');
    if (!_ssResimGecerli($imzaT)) throw new DomainException('Teknisyen imzası okunamadı (PNG/JPEG). Yeniden imzalayın.');
    $ucret = 1;
    $f = _ssFormAlanlari($db, $s, $z, $b);

    require_once __DIR__ . '/hasar-belge-lib.php';
    $raporNo = hbNo($db, 'SSR', date('Y-m-d'));
    $yolM = $imzaM !== '' ? tsFotoKaydet($imzaM, $sid) : null;
    $yolT = tsFotoKaydet($imzaT, $sid);
    if (($imzaM !== '' && !$yolM) || !$yolT) throw new DomainException('İmza kaydedilemedi; tekrar deneyin.');
    $simdi = date('Y-m-d H:i:s');
    $db->prepare('UPDATE servis_ziyaretleri SET ayrilis=?, tespit=?, yapilan=?, sonuc=?, sonuc_not=?, musteri_ad=?, imza_musteri=?, imza_teknisyen=?, rapor_no=?,
                    is_baslangic=?, is_bitis=?, iscilik_dk=?, km_cikis=?, km_donus=?, arac_id=?, arac_plaka=?, calisma_elektrik=?, calisma_dizel=?, calisma_toplam=?,
                    ariza_kaynak=?, musteri_talep=?, gerekce=?, teklif_isteniyor=?, ucretlendir=?, musteri_tel=?, servis_yetkili_ad=?, bilesen_hasar=? WHERE id=?')
       ->execute([$simdi, $tespit, $yapilan ?: null, $sonuc, trim(metin($b['sonuc_not'] ?? '')) ?: null, $musteriAd ?: null, $yolM, $yolT, $raporNo,
                  $f['is_baslangic'], $f['is_bitis'], $f['iscilik_dk'], $f['km_cikis'], $f['km_donus'], $f['arac_id'], $f['arac_plaka'],
                  $f['calisma_elektrik'], $f['calisma_dizel'], $f['calisma_toplam'], $f['ariza_kaynak'], $f['musteri_talep'], $f['gerekce'],
                  $f['teklif_isteniyor'], $ucret, $f['musteri_tel'], $f['servis_yetkili_ad'], $f['bilesen_hasar'], (int)$z['id']]);
    if ($f['gerekce']) $db->prepare('UPDATE teknik_servis SET gerekce=?, tip=? WHERE id=?')->execute([$f['gerekce'], SS_GEREKCE_TIP[$f['gerekce']], $sid]);
    if ($f['ariza_kaynak']) $db->prepare('UPDATE teknik_servis SET ariza_kaynak=? WHERE id=?')->execute([$f['ariza_kaynak'], $sid]);
    _ssOtoKalemler($db, $sid, (int)$z['id'], $raporNo, $f);
    ssUcretKarari($db, $sid, $ucret);

    $yapilanTum = trim(trim((string)$s['yapilan']) . ($yapilan !== '' ? "\n" . $raporNo . ': ' . $yapilan : ''));
    $sahaDurum = in_array($sonuc, ['yerinde_cozuldu', 'subeye_gelecek'], true) ? 'rapor_gonderildi' : 'atandi';
    $db->prepare("UPDATE teknik_servis SET saha_sonuc=?, saha_durum=?, ofis_inceleme=1, yapilan=?, updated_at=datetime('now','localtime') WHERE id=?")
       ->execute([$sonuc, $sahaDurum, $yapilanTum ?: null, $sid]);
    $servisDurum = $s['durum'] === 'acik' ? 'devam' : $s['durum'];
    $sebep = null;
    if ($sonuc === 'yerinde_cozuldu') {
        $s2 = _ssIsSatir($db, $sid);
        $sebep = tsTamamlamaEngeli($db, $s2)
            ?? (tsAcikParcaTalebiVarMi($db, $sid) ? 'Bu servise bağlı, teslim alınmamış parça talebi var; parça gelince servisi ofis kapatır.' : null)
            ?? (tsKontrolTamamlanmamisVarMi($db, $sid) ? 'Kontrol listesinde değerlendirilmemiş madde var; ofis tamamlayınca servis kapanır.' : null);
        if ($sebep === null) {
            $db->prepare("UPDATE teknik_servis SET durum='tamamlandi', tamamlanma=? WHERE id=?")->execute([date('Y-m-d'), $sid]);
            teknikServisMakineDurumSenkron($db, $s['makine_tur'], $s['makine_id'] !== null ? (int)$s['makine_id'] : null, 'tamamlandi');
            $db->prepare("UPDATE gorevler SET durum='tamamlandi', tamamlanma=?, updated_at=datetime('now','localtime')
                          WHERE kaynak_modul='teknik_servis' AND kaynak_id=? AND kaynak='otomatik' AND durum='acik'")->execute([date('Y-m-d'), $sid]);
            tsTamamlandi($db, $sid);
            tsPortalBildir($db, $s, 'tamamlandi', $yapilan);
            $servisDurum = 'tamamlandi';
        }
    } elseif ($sonuc === 'subeye_gelecek') {
        $db->prepare("UPDATE teknik_servis SET yer='atolye' WHERE id=?")->execute([$sid]);
        gorevOlustur($db, 'Makineyi getirt: ' . ($s['makine_ad'] ?: 'saha servisi'),
            'Teknisyen makinenin sahada onarılamayacağını bildirdi (' . $raporNo . '). Nakliyeyi planlayın.', 'servis_getir', $sid, 'yuksek');
    }
    if ($servisDurum === 'devam') $db->prepare("UPDATE teknik_servis SET durum='devam' WHERE id=? AND durum='acik'")->execute([$sid]);
    gorevOlustur($db, 'Servis raporu: ' . ($s['makine_ad'] ?: 'saha servisi') . ' — ' . SS_SONUCLAR[$sonuc],
        $tespit . ($sebep ? ' · ' . $sebep : ''), 'servis_rapor', (int)$z['id']);
    logla($db, 'servis_ziyaretleri', (int)$z['id'], 'ekle', null, ['servis_id' => $sid, 'rapor_no' => $raporNo, 'sonuc' => $sonuc],
          'Saha servis raporu ' . $raporNo . ' — ' . SS_SONUCLAR[$sonuc]);
    return ['rapor_no' => $raporNo, 'ziyaret_id' => (int)$z['id'], 'servis_durum' => $servisDurum, 'saha_durum' => $sahaDurum, 'tamamlanamadi_sebep' => $sebep];
}
