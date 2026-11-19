<?php
declare(strict_types=1);
require_once __DIR__ . '/hasar-akis.php';

const TS_KALEM_TURLERI = ['malzeme' => 'Malzeme', 'iscilik' => 'İşçilik', 'yol' => 'Yol / ulaşım', 'diger' => 'Diğer'];

final class TsStokOnayi extends DomainException {
    public array $ek;
    public function __construct(string $mesaj, array $ek) { parent::__construct($mesaj); $this->ek = $ek; }
}

function tsKalemSema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS teknik_servis_kalemleri (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        servis_id INTEGER NOT NULL REFERENCES teknik_servis(id) ON DELETE CASCADE,
        tur TEXT NOT NULL,                 -- malzeme | iscilik
        aciklama TEXT NOT NULL,
        parca_id INTEGER,                  -- stoktan malzeme (yedek_parca)
        miktar REAL, birim TEXT,           -- malzeme
        saat REAL, kisi INTEGER,           -- işçilik
        stok_hareket_id INTEGER,           -- stoktan düşümün hareketi (geri alma için)
        hasar_kalem_id INTEGER,            -- bağlı depo bulgusu
        yapan TEXT NOT NULL,
        created_at TEXT NOT NULL);
      CREATE INDEX IF NOT EXISTS idx_tsk_servis ON teknik_servis_kalemleri(servis_id);
      CREATE INDEX IF NOT EXISTS idx_tsk_hareket ON teknik_servis_kalemleri(stok_hareket_id);");
    tsKaynakKiralamaDoldur($db);
}

function tsHasarSema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS servis_bulgulari (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        servis_id INTEGER NOT NULL,
        kaynak TEXT NOT NULL,
        depo_kontrol_id INTEGER, tutanak_id INTEGER, degerlendirme_id INTEGER, hasar_kalem_id INTEGER,
        tur TEXT NOT NULL, aciklama TEXT NOT NULL, adet REAL,
        foto_ids TEXT NOT NULL DEFAULT '[]',
        created_at TEXT NOT NULL);
      CREATE INDEX IF NOT EXISTS idx_sb_servis ON servis_bulgulari(servis_id);
      CREATE UNIQUE INDEX IF NOT EXISTS ux_sb_hk ON servis_bulgulari(hasar_kalem_id) WHERE hasar_kalem_id IS NOT NULL;");
    $ekle = function (string $tablo, array $sutunlar) use ($db): void {
        $var = array_flip(array_column($db->query("PRAGMA table_info($tablo)")->fetchAll(PDO::FETCH_ASSOC), 'name'));
        foreach ($sutunlar as $ad => $tanim) if (!isset($var[$ad])) $db->exec("ALTER TABLE $tablo ADD COLUMN $ad $tanim");
    };
    $ekle('teknik_servis', ['ariza_kaynak' => 'TEXT', 'makine_baska_iste' => 'INTEGER DEFAULT 0', 'donus_kontrol_id' => 'INTEGER',
                            'fatura_yontemi' => 'TEXT', 'hakedis_id' => 'INTEGER', 'hakedis_kalem_id' => 'INTEGER']);
    $ekle('teknik_servis_kalemleri', ['servis_bulgu_id' => 'INTEGER']);
}

function tsHasarGocKapsami(array $h): string {
    $durum = (string)($h['durum'] ?? '');
    $fd = (string)($h['fiyat_durum'] ?? '');
    $bagli = in_array($fd, ['fiyatlandi', 'faturalandi', 'ucretsiz'], true) || !empty($h['hakedis_id']) || !empty($h['fatura_kalem_id']);
    if ($durum === 'ertelendi') return 'kapanis';
    if (in_array($durum, ['acik', 'incelemede', 'aksesuar_bekliyor'], true)) return $bagli ? 'kapanis' : 'tasi';
    if ($durum === 'sonuclandi' && $fd === 'bekliyor' && !$bagli) return 'tasi';
    return 'dokunma';
}

function _tsHasarBulgulariKopyala(PDO $db, int $sid, array $h): array {
    $ids = [];
    $k = $db->prepare('SELECT * FROM hasar_kalemleri WHERE degerlendirme_id=? ORDER BY id'); $k->execute([(int)$h['id']]);
    $var = $db->prepare('SELECT id FROM servis_bulgulari WHERE hasar_kalem_id=?');
    $tur = ['hasar' => 'hasar', 'eksik' => 'eksik', 'ariza' => 'ariza', 'sayac' => 'sayac', 'ariza_kodu' => 'ariza_kodu', 'aksesuar' => 'aksesuar'];
    foreach ($k->fetchAll(PDO::FETCH_ASSOC) as $x) {
        $var->execute([(int)$x['id']]);
        $bid = (int)$var->fetchColumn();
        if (!$bid) {
            $bid = tsBulguYaz($db, $sid, 'goc', ['tur' => $tur[$x['tur']] ?? 'hasar', 'aciklama' => (string)$x['aciklama'] ?: 'Bulgu', 'foto_ids' => json_decode((string)$x['foto_ids'], true) ?: [],
                                                 'depo_kontrol_id' => $h['depo_kontrol_id'] ?? null, 'tutanak_id' => $h['tutanak_id'] ?? null, 'degerlendirme_id' => (int)$h['id'], 'hasar_kalem_id' => (int)$x['id']]);
        }
        $db->prepare('UPDATE teknik_servis_kalemleri SET servis_bulgu_id=? WHERE hasar_kalem_id=? AND servis_bulgu_id IS NULL')->execute([$bid, (int)$x['id']]);
        $ids[(int)$x['id']] = ['bulgu_id' => $bid, 'tur' => $x['tur'], 'aciklama' => (string)$x['aciklama']];
    }
    return $ids;
}

function tsHasarBedelBagli(array $h): bool {
    return !empty($h['fatura_yontemi']) || !empty($h['hakedis_id']) || !empty($h['fatura_kalem_id']) || in_array((string)($h['fiyat_durum'] ?? ''), ['fiyatlandi', 'faturalandi'], true);
}

function tsHasarTasi(PDO $db, array $h): int {
    require_once __DIR__ . '/servis-maliyet-lib.php';
    if (tsHasarBedelBagli($h)) throw new DomainException('Bedeli bağlı (fiyatlanmış / hakedişte / faturalı) hasar kaydı servise taşınmaz; arşivde kapatın.');
    $hid = (int)$h['id']; $simdi = date('Y-m-d H:i:s');
    $kq = $db->prepare('SELECT * FROM kiralamalar WHERE id=?'); $kq->execute([(int)$h['kiralama_id']]);
    $k = $kq->fetch(PDO::FETCH_ASSOC) ?: [];
    $sid = (int)($h['servis_id'] ?? 0);
    $acildi = false;
    if ($sid) { $sq = $db->prepare('SELECT * FROM teknik_servis WHERE id=?'); $sq->execute([$sid]); if (!$sq->fetch()) $sid = 0; }
    if (!$sid) {
        $tur = (string)($h['makine_tur'] ?: ($k['makine_tur'] ?? '')); $mid = (int)($h['makine_id'] ?: ($k['makine_id'] ?? 0));
        $kaynak = !empty($h['depo_kontrol_id']) ? 'depo_inceleme' : (!empty($h['tutanak_id']) ? 'donus_kontrolu' : 'manuel');
        $baska = $tur !== '' && $mid > 0 && makineKiradaMi($db, $tur, $mid);
        $db->prepare("INSERT INTO teknik_servis(makine_tur,makine_id,makine_ad,tip,tarih,durum,oncelik,ariza,tutanak_id,kiralama_id,kaynak,musteriye_yansit,maliyet_kaynak,donus_kontrol_id,makine_baska_iste,created_at,updated_at)
                      VALUES(?,?,?,'ariza',?,'acik',?,?,?,?,?,1,'kalem',?,?,?,?)")
           ->execute([$tur ?: null, $mid ?: null, $k['ekipman_adi'] ?? 'Makine', date('Y-m-d'), ($h['oncelik'] ?? 'normal') === 'yuksek' ? 'yuksek' : 'normal', (string)$h['ozet'],
                      !empty($h['tutanak_id']) ? (int)$h['tutanak_id'] : null, (int)$h['kiralama_id'], $kaynak, !empty($h['depo_kontrol_id']) ? (int)$h['depo_kontrol_id'] : null, (int)$baska, $simdi, $simdi]);
        $sid = (int)$db->lastInsertId(); $acildi = true;
        if ($tur !== '' && $mid > 0 && !$baska) teknikServisMakineDurumSenkron($db, $tur, $mid, 'acik', 'ariza');
        gorevOlustur($db, 'Servis: ' . ($k['ekipman_adi'] ?? 'Makine') . ' (hasar kaydından taşındı)', (string)$h['ozet'], 'teknik_servis', $sid);
        if (!empty($h['tutanak_id'])) {
            $f = $db->prepare('SELECT dosya_yolu FROM iade_tutanak_fotograflari WHERE tutanak_id=?'); $f->execute([(int)$h['tutanak_id']]);
            $ins = $db->prepare("INSERT INTO teknik_servis_fotograflari(servis_id,dosya_yolu,kaynak,created_at) VALUES(?,?,'donus_kontrolu',?)");
            foreach ($f->fetchAll(PDO::FETCH_COLUMN) as $yol) $ins->execute([$sid, $yol, $simdi]);
        }
    } elseif (!empty($h['depo_kontrol_id'])) {
        $db->prepare('UPDATE teknik_servis SET donus_kontrol_id=COALESCE(donus_kontrol_id,?) WHERE id=?')->execute([(int)$h['depo_kontrol_id'], $sid]);
    }
    $bulgular = _tsHasarBulgulariKopyala($db, $sid, $h);
    foreach ($bulgular as $b) if (in_array($b['tur'], ['eksik', 'aksesuar'], true)) tsEksikKalemYaz($db, $sid, $b['aciklama'], 1.0, $b['bulgu_id']);
    if (($h['durum'] ?? '') === 'aksesuar_bekliyor' && trim((string)($h['aksesuar_ad'] ?? '')) !== '') tsEksikKalemYaz($db, $sid, (string)$h['aksesuar_ad'], (float)($h['aksesuar_miktar'] ?: 1), null);
    $ks = ($h['kusur_sahibi'] ?? '') === 'musteri' ? 'musteri' : (($h['kusur_sahibi'] ?? '') === 'firma' ? 'makine' : null);
    if ($ks) $db->prepare('UPDATE teknik_servis SET ariza_kaynak=COALESCE(ariza_kaynak,?) WHERE id=?')->execute([$ks, $sid]);
    $s = tsServis($db, $sid);
    if ((($h['fiyat_durum'] ?? '') === 'bekliyor' || ($h['kusur_sahibi'] ?? '') === 'musteri') && empty($s['musteri_makine_id']) && (int)($s['musteriye_yansit'] ?? 0) !== 1) {
        $db->prepare('UPDATE teknik_servis SET musteriye_yansit=1 WHERE id=?')->execute([$sid]);
        $s = tsServis($db, $sid);
    }
    if (($h['fiyat_durum'] ?? '') === 'bekliyor' && $s['durum'] === 'tamamlandi' && !in_array($s['fiyat_durum'] ?? '', ['fiyatlandi', 'ucretsiz'], true)) {
        $db->prepare("UPDATE teknik_servis SET fiyat_durum='bekliyor' WHERE id=?")->execute([$sid]);
        $v = $db->prepare("SELECT 1 FROM gorevler WHERE kaynak_modul='servis_fiyat' AND kaynak_id=? AND durum='acik'"); $v->execute([$sid]);
        if (!$v->fetchColumn()) gorevOlustur($db, 'Fiyatlandır: ' . ($s['makine_ad'] ?: 'servis #' . $sid), 'Hasar kaydı servis kaydına taşındı; bedeli Teknik Servis › Fiyatlandırılacaklar\'da fiyatlandırın.', 'servis_fiyat', $sid);
    }
    $db->prepare('UPDATE hasar_indirim_onerileri SET servis_id=? WHERE degerlendirme_id=? AND servis_id IS NULL')->execute([$sid, $hid]);
    $eskiDurum = (string)$h['durum'];
    $db->prepare("UPDATE hasar_degerlendirmeleri SET durum='tasindi', sonuclanma=?, servis_id=?, updated_at=? WHERE id=?")->execute([$simdi, $sid, $simdi, $hid]);
    $db->prepare("UPDATE gorevler SET durum='tamamlandi', tamamlanma=?, updated_at=? WHERE kaynak_modul IN ('hasar_degerlendirme','hasar_aksesuar','hasar_fiyat') AND kaynak_id=? AND durum='acik'")->execute([date('Y-m-d'), $simdi, $hid]);
    hdGecmis($db, $hid, 'tasindi', 'Servis kaydı #' . $sid . '\'e taşındı (v145 geçişi).', ['servis_id' => $sid, 'bulgu' => count($bulgular)]);
    logla($db, 'hasar_degerlendirmeleri', $hid, 'guncelle', ['durum' => $eskiDurum], ['durum' => 'tasindi', 'servis_id' => $sid], 'Hasar kaydı servis kaydına taşındı — Hasar #' . $hid . ' → Servis #' . $sid);
    return $sid;
}

function tsHasarGoc(PDO $db): array {
    try {
        $q = $db->query("SELECT deger FROM ayarlar WHERE anahtar='hasar_servis_goc'");
        if ($q && $q->fetchColumn() === '1') return [];
        $tablolar = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('hasar_degerlendirmeleri','servis_bulgulari','hasar_kalemleri')")->fetchAll(PDO::FETCH_COLUMN);
        if (count($tablolar) < 3 || hdSemaEski($db)) return [];
    } catch (\Throwable $e) { return []; }
    $kendiIslem = !$db->inTransaction();
    try {
        if ($kendiIslem) $db->beginTransaction();
        $ozet = ['tasinan' => 0, 'servis_acilan' => 0, 'kapanis' => 0, 'bulgu' => 0];
        $toplam = (int)$db->query('SELECT COUNT(*) FROM hasar_degerlendirmeleri')->fetchColumn();
        if ($toplam === 0) { $db->exec("INSERT OR REPLACE INTO ayarlar(anahtar, deger) VALUES('hasar_servis_goc', '1')"); if ($kendiIslem) $db->commit(); return $ozet; }
        $bagli = $db->query("SELECT h.* FROM hasar_degerlendirmeleri h JOIN teknik_servis s ON s.id=h.servis_id WHERE h.depo_kontrol_id IS NOT NULL ORDER BY h.id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($bagli as $h) {
            $ozet['bulgu'] += count(_tsHasarBulgulariKopyala($db, (int)$h['servis_id'], $h));
            $db->prepare('UPDATE teknik_servis SET donus_kontrol_id=COALESCE(donus_kontrol_id,?) WHERE id=?')->execute([(int)$h['depo_kontrol_id'], (int)$h['servis_id']]);
        }
        $oncekiServis = (int)$db->query('SELECT COUNT(*) FROM teknik_servis')->fetchColumn();
        foreach ($db->query('SELECT * FROM hasar_degerlendirmeleri ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $kapsam = tsHasarGocKapsami($h);
            if ($kapsam === 'kapanis') { $ozet['kapanis']++; continue; }
            if ($kapsam !== 'tasi') continue;
            tsHasarTasi($db, $h);
            $ozet['tasinan']++;
        }
        $ozet['servis_acilan'] = (int)$db->query('SELECT COUNT(*) FROM teknik_servis')->fetchColumn() - $oncekiServis;
        logla($db, 'ayarlar', null, 'guncelle', null, $ozet, 'Hasar → servis geçişi: ' . $ozet['tasinan'] . ' taşındı, ' . $ozet['servis_acilan'] . ' servis açıldı, ' . $ozet['kapanis'] . ' arşivde kapanış bekliyor');
        $db->exec("INSERT OR REPLACE INTO ayarlar(anahtar, deger) VALUES('hasar_servis_goc', '1')");
        if ($kendiIslem) $db->commit();
        return $ozet;
    } catch (\Throwable $e) {
        if ($kendiIslem && $db->inTransaction()) $db->rollBack();
        error_log('tsHasarGoc: ' . $e->getMessage());
        return [];
    }
}

function tsBulguYaz(PDO $db, int $sid, string $kaynak, array $b): int {
    $tur = (string)($b['tur'] ?? '');
    if (!in_array($tur, ['hasar', 'eksik', 'ariza', 'sayac', 'ariza_kodu', 'aksesuar'], true)) throw new DomainException('Bulgu türü geçersiz.');
    $aciklama = mb_substr(trim((string)($b['aciklama'] ?? '')), 0, 500);
    if ($aciklama === '') throw new DomainException('Bulgu açıklaması boş olamaz.');
    $fotolar = array_values(array_filter(array_map('intval', (array)($b['foto_ids'] ?? [])), fn($x) => $x > 0));
    $db->prepare('INSERT INTO servis_bulgulari(servis_id,kaynak,depo_kontrol_id,tutanak_id,degerlendirme_id,hasar_kalem_id,tur,aciklama,adet,foto_ids,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
       ->execute([$sid, $kaynak, (int)($b['depo_kontrol_id'] ?? 0) ?: null, (int)($b['tutanak_id'] ?? 0) ?: null, (int)($b['degerlendirme_id'] ?? 0) ?: null,
                  (int)($b['hasar_kalem_id'] ?? 0) ?: null, $tur, $aciklama, isset($b['adet']) && $b['adet'] !== null && $b['adet'] !== '' ? (float)$b['adet'] : null,
                  json_encode($fotolar), date('Y-m-d H:i:s')]);
    $id = (int)$db->lastInsertId();
    if ($fotolar) {
        $f = $db->prepare('SELECT dosya_yolu FROM kiralama_fotograflari WHERE id=?');
        $var = $db->prepare('SELECT 1 FROM teknik_servis_fotograflari WHERE servis_id=? AND dosya_yolu=? LIMIT 1');
        $ins = $db->prepare('INSERT INTO teknik_servis_fotograflari(servis_id,dosya_yolu,kaynak,created_at) VALUES(?,?,?,?)');
        foreach ($fotolar as $fid) {
            $f->execute([$fid]); $yol = $f->fetchColumn();
            if (!$yol) continue;
            $var->execute([$sid, $yol]);
            if (!$var->fetchColumn()) $ins->execute([$sid, $yol, 'donus_kontrolu', date('Y-m-d H:i:s')]);
        }
    }
    return $id;
}

function tsEksikKalemYaz(PDO $db, int $sid, string $ad, float $adet, ?int $bulguId): int {
    $aciklama = mb_substr('Eksik parça: ' . trim($ad), 0, 300);
    $q = $db->prepare("SELECT id FROM teknik_servis_kalemleri WHERE servis_id=? AND kaynak='eksik' AND aciklama=? LIMIT 1");
    $q->execute([$sid, $aciklama]);
    if ($id = $q->fetchColumn()) return (int)$id;
    $db->prepare("INSERT INTO teknik_servis_kalemleri(servis_id,tur,kaynak,aciklama,miktar,birim,birim_maliyet,servis_bulgu_id,yapan,created_at) VALUES(?,'malzeme','eksik',?,?,'adet',NULL,?,'Dönüş kontrolü',?)")
       ->execute([$sid, $aciklama, $adet > 0 ? round($adet, 3) : 1.0, $bulguId, date('Y-m-d H:i:s')]);
    $id = (int)$db->lastInsertId();
    logla($db, 'teknik_servis_kalemleri', $id, 'ekle', null, ['servis_id' => $sid, 'tur' => 'malzeme', 'kaynak' => 'eksik', 'aciklama' => $aciklama, 'miktar' => $adet],
          'Eksik parça satırı — servis #' . $sid);
    return $id;
}

function tsServis(PDO $db, int $sid): array {
    $s = $db->prepare('SELECT * FROM teknik_servis WHERE id=?'); $s->execute([$sid]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new DomainException('Servis kaydı bulunamadı.');
    return $r;
}

function tsBulgular(PDO $db, int $sid): array {
    $s = $db->prepare('SELECT id, tur, aciklama, adet, foto_ids, kaynak, depo_kontrol_id FROM servis_bulgulari WHERE servis_id=? ORDER BY id');
    $s->execute([$sid]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'tur' => $r['tur'], 'aciklama' => $r['aciklama'], 'adet' => $r['adet'] !== null ? (float)$r['adet'] : null,
                                'foto_ids' => array_map('intval', json_decode((string)$r['foto_ids'], true) ?: []), 'kaynak' => $r['kaynak'],
                                'depo_kontrol_id' => $r['depo_kontrol_id'] !== null ? (int)$r['depo_kontrol_id'] : null],
                     $s->fetchAll(PDO::FETCH_ASSOC));
}

function tsKalemListe(PDO $db, int $sid): array {
    $s = $db->prepare('SELECT k.id, k.tur, k.kaynak, k.aciklama, k.parca_id, p.parca_no, k.miktar, k.birim, k.saat, k.kisi, k.servis_bulgu_id,
                              sb.aciklama AS bulgu, k.yapan, k.created_at
                       FROM teknik_servis_kalemleri k LEFT JOIN yedek_parca p ON p.id=k.parca_id
                       LEFT JOIN servis_bulgulari sb ON sb.id=k.servis_bulgu_id WHERE k.servis_id=? ORDER BY k.id');
    $s->execute([$sid]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

function _tsSayi($v, string $ad): float {
    if (!is_numeric($v) || !is_finite((float)$v) || (float)$v <= 0 || (float)$v > 100000) throw new DomainException($ad . ' sıfırdan büyük bir sayı olmalı.');
    return round((float)$v, 3);
}

function _tsStokDus(PDO $db, array $p, int $miktar, int $sid, string $aciklama): int {
    $db->prepare('UPDATE yedek_parca SET stok=stok-?, updated_at=? WHERE id=?')->execute([$miktar, date('Y-m-d H:i:s'), (int)$p['id']]);
    $db->prepare("INSERT INTO stok_hareket(parca_id,tarih,tip,miktar,kaynak,servis_id,birim_fiyat,aciklama,created_at) VALUES(?,?,'cikis',?,'servis',?,?,?,?)")
       ->execute([(int)$p['id'], date('Y-m-d'), $miktar, $sid, smStokMaliyeti($p), $aciklama, date('Y-m-d H:i:s')]);
    return (int)$db->lastInsertId();
}

function tsKalemEkle(PDO $db, int $sid, array $b): array {
    require_once __DIR__ . '/servis-maliyet-lib.php';
    islemKilitAl($db);
    $s = tsServis($db, $sid);
    $tur = (string)($b['tur'] ?? '');
    if (!isset(TS_KALEM_TURLERI[$tur])) throw new DomainException('Satır türünü seçin (malzeme / işçilik / yol / diğer).');
    smHakedisBagiKilidi($s);
    smKilitDenetle($s, $tur, 'ekleme');
    $aciklama = mb_substr(trim((string)($b['aciklama'] ?? '')), 0, 300);
    $hk = (int)($b['servis_bulgu_id'] ?? $b['hasar_kalem_id'] ?? 0) ?: null;
    if ($hk && !in_array($hk, array_column(tsBulgular($db, $sid), 'id'), true)) throw new DomainException('Seçilen bulgu bu servise bağlı değil.');
    $pid = null; $miktar = null; $birim = null; $saat = null; $kisi = null; $hid = null; $kalan = null;
    $kaynak = null; $stokKodu = null; $tedarikci = null; $bm = smFiyatYetkisi($db) ? smBirimMaliyetOku($b) : null;
    smDonustur($db, $sid);
    if ($tur === 'malzeme') {
        $miktar = _tsSayi($b['miktar'] ?? null, 'Miktar');
        $pid = (int)($b['parca_id'] ?? 0) ?: null;
        if ($pid) {
            if ($miktar != floor($miktar)) throw new DomainException('Stoktan malzeme tam sayı miktarla düşülür.');
            $q = $db->prepare('SELECT * FROM yedek_parca WHERE id=?'); $q->execute([$pid]); $p = $q->fetch(PDO::FETCH_ASSOC);
            if (!$p) throw new DomainException('Parça bulunamadı.');
            if ($miktar > (int)$p['stok'] && empty($b['stok_onaylandi'])) {
                $eksik = (int)$miktar - (int)$p['stok'];
                throw new TsStokOnayi('Stok yetersiz: "' . $p['ad'] . '" için ' . (int)$p['stok'] . ' adet var, ' . (int)$miktar
                    . ' adet yazılıyor. Devam ederseniz stok ' . $eksik . ' adet eksiye düşer.',
                    ['onaylanabilir' => true, 'mevcut_stok' => (int)$p['stok'], 'eksik' => $eksik]);
            }
            if ($aciklama === '') $aciklama = $p['ad'] . ($p['parca_no'] ? ' (' . $p['parca_no'] . ')' : '');
            $hid = _tsStokDus($db, $p, (int)$miktar, $sid, $aciklama);
            $q = $db->prepare('SELECT stok FROM yedek_parca WHERE id=?'); $q->execute([$pid]); $kalan = (int)$q->fetchColumn();
            $birim = 'adet'; $kaynak = 'stok'; $bm = smStokMaliyeti($p);
        } else {
            if ($aciklama === '') throw new DomainException('Elle malzemede malzeme adını yazın.');
            $birim = mb_substr(trim((string)($b['birim'] ?? '')), 0, 20) ?: 'adet';
            $kaynak = 'dis';
            $stokKodu = mb_substr(trim((string)($b['stok_kodu'] ?? '')), 0, 60) ?: null;
            $tedarikci = mb_substr(trim((string)($b['tedarikci'] ?? '')), 0, 120) ?: null;
        }
    } elseif ($tur === 'iscilik') {
        if ($aciklama === '') throw new DomainException('Yapılan işi yazın.');
        $saat = _tsSayi($b['saat'] ?? null, 'Saat');
        $kisi = (int)($b['kisi'] ?? 0);
        if ($kisi < 1 || $kisi > 50) throw new DomainException('Kişi sayısı 1–50 arasında olmalı.');
    } else {
        if ($aciklama === '' && $tur === 'yol') $aciklama = 'Yol / ulaşım';
        if ($aciklama === '') throw new DomainException('Masrafın açıklamasını yazın.');
        $miktar = _tsSayi($b['miktar'] ?? null, 'Miktar');
        $birim = mb_substr(trim((string)($b['birim'] ?? '')), 0, 20) ?: ($tur === 'yol' ? 'sefer' : 'adet');
    }
    $yapan = (string)($_SESSION['username'] ?? 'Sistem');
    $db->prepare('INSERT INTO teknik_servis_kalemleri(servis_id,tur,kaynak,aciklama,parca_id,miktar,birim,saat,kisi,stok_hareket_id,servis_bulgu_id,stok_kodu,tedarikci,birim_maliyet,yapan,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
       ->execute([$sid, $tur, $kaynak, $aciklama, $pid, $miktar, $birim, $saat, $kisi, $hid, $hk, $stokKodu, $tedarikci, $bm, $yapan, date('Y-m-d H:i:s')]);
    $id = (int)$db->lastInsertId();
    smMaliyetYenile($db, $sid);
    smFiyatYenidenBekle($db, $sid);
    logla($db, 'teknik_servis_kalemleri', $id, 'ekle', null, ['servis_id' => $sid, 'tur' => $tur, 'kaynak' => $kaynak, 'aciklama' => $aciklama, 'miktar' => $miktar, 'saat' => $saat, 'kisi' => $kisi,
          'stok_hareket_id' => $hid, 'stok_kodu' => $stokKodu, 'tedarikci' => $tedarikci, 'birim_maliyet' => $bm],
          'Yapılan iş satırı eklendi — servis #' . $sid . ($kalan !== null && $kalan < 0 ? ' · STOK EKSİYE DÜŞTÜ' : ''));
    return ['id' => $id, 'kalan_stok' => $kalan, 'eksi_stok' => $kalan !== null && $kalan < 0];
}

function tsKalemSil(PDO $db, int $kalemId): int {
    require_once __DIR__ . '/servis-maliyet-lib.php';
    islemKilitAl($db);
    $q = $db->prepare('SELECT * FROM teknik_servis_kalemleri WHERE id=?'); $q->execute([$kalemId]); $k = $q->fetch(PDO::FETCH_ASSOC);
    if (!$k) throw new DomainException('Satır bulunamadı.');
    $sid = (int)$k['servis_id'];
    if (($k['kaynak'] ?? '') === 'eksik' && !smFiyatYetkisi($db)) throw new DomainException('Eksik parça satırı dönüş kontrolünden gelir; yalnız fiyatlandırma yetkilisi silebilir.');
    smHakedisBagiKilidi(tsServis($db, $sid));
    smKilitDenetle(tsServis($db, $sid), (string)$k['tur'], 'silme');
    smDonustur($db, $sid);
    if ($k['stok_hareket_id']) {
        $q = $db->prepare('SELECT * FROM stok_hareket WHERE id=?'); $q->execute([(int)$k['stok_hareket_id']]); $h = $q->fetch(PDO::FETCH_ASSOC);
        if ($h) {
            $db->prepare('UPDATE yedek_parca SET stok=stok+? WHERE id=?')->execute([(int)$h['miktar'], (int)$h['parca_id']]);
            $db->prepare('DELETE FROM stok_hareket WHERE id=?')->execute([(int)$h['id']]);
        }
    }
    $db->prepare('DELETE FROM teknik_servis_kalemleri WHERE id=?')->execute([$kalemId]);
    smKalemSilindi($db, $sid, $kalemId);
    smMaliyetYenile($db, $sid);
    logla($db, 'teknik_servis_kalemleri', $kalemId, 'sil', $k, null, 'Yapılan iş satırı silindi — servis #' . $sid . ($k['stok_hareket_id'] ? ' (stok geri alındı)' : ''));
    return $sid;
}

function tsDepoBaglam(PDO $db, int $sid): ?array {
    $s = tsServis($db, $sid);
    $ids = [];
    if (!empty($s['donus_kontrol_id'])) $ids[] = (int)$s['donus_kontrol_id'];
    $q = $db->prepare('SELECT depo_kontrol_id FROM hasar_degerlendirmeleri WHERE servis_id=? AND depo_kontrol_id IS NOT NULL ORDER BY id');
    $q->execute([$sid]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $x) if (!in_array((int)$x, $ids, true)) $ids[] = (int)$x;
    if (!$ids) return null;
    require_once __DIR__ . '/belge-zinciri-lib.php';
    $bulgular = tsBulgular($db, $sid);
    $dk = $db->prepare('SELECT d.id, d.kiralama_id, d.yapan, d.updated_at, d.veri, k.kod AS kiralama_kod, k.sube FROM depo_kontrolleri d JOIN kiralamalar k ON k.id=d.kiralama_id WHERE d.id=?');
    $f = $db->prepare("SELECT id, aci_tipi, aciklama, dosya_yolu, yon FROM kiralama_fotograflari WHERE kiralama_id=? AND yon IN ('gidis','donus') ORDER BY id");
    $sube = subeKisitiHam();
    $kontroller = [];
    foreach ($ids as $kid) {
        $dk->execute([$kid]); $d = $dk->fetch(PDO::FETCH_ASSOC);
        if (!$d) continue;
        $gizli = $sube !== null && $sube !== trim((string)($d['sube'] ?? ''));
        $v = json_decode((string)($d['veri'] ?? ''), true) ?: [];
        $f->execute([(int)$d['kiralama_id']]);
        $fotolar = ['gidis' => [], 'donus' => []];
        foreach ($f->fetchAll(PDO::FETCH_ASSOC) as $x) $fotolar[$x['yon']][] = $x;
        $tespit = bzIadeTutanagiKontrol($db, $kid);
        $kontroller[] = [
            'kontrol_id' => $kid, 'kiralama_id' => (int)$d['kiralama_id'], 'kiralama_kod' => $gizli ? null : $d['kiralama_kod'],
            'inceleme' => ['yapan' => $gizli ? null : ($d['yapan'] ?? null), 'zaman' => $d['updated_at'] ?? null, 'imza' => $gizli ? null : ($v['imza']['veri'] ?? null),
                           'sayac' => $v['sayac'] ?? null, 'sayac_gidis' => $v['sayac_gidis'] ?? null, 'aksesuar_farklar' => $v['aksesuar_farklar'] ?? [],
                           'saha_notu' => $v['saha_teslim_alma']['hasar_notu'] ?? null],
            'bulgular' => array_values(array_filter($bulgular, fn($b) => $b['depo_kontrol_id'] === null || $b['depo_kontrol_id'] === $kid)),
            'fotolar' => $fotolar,
            'tespit' => $tespit ? ['tutanak_id' => (int)$tespit['id'], 'belge_no' => $tespit['belge_no'] ?? null] : null,
        ];
    }
    if (!$kontroller) return null;
    $g = $db->prepare('SELECT id, tarih, tip, durum, kaynak, SUBSTR(ariza,1,200) AS ariza, tamamlanma FROM teknik_servis
                       WHERE makine_tur=? AND makine_id=? AND id<>? ORDER BY tarih DESC, id DESC LIMIT 20');
    $g->execute([$s['makine_tur'], (int)$s['makine_id'], $sid]);
    $hg = $db->prepare('SELECT id, created_at, ozet, durum, sube FROM hasar_degerlendirmeleri
                        WHERE makine_tur=? AND makine_id=? AND COALESCE(servis_id,0)<>? ORDER BY id DESC LIMIT 20');
    $hg->execute([$s['makine_tur'], (int)$s['makine_id'], $sid]);
    return ['kontroller' => $kontroller, 'gecmis_servis' => array_map(fn($r) => ['id' => (int)$r['id']] + $r, $g->fetchAll(PDO::FETCH_ASSOC)),
            'gecmis_hasar' => array_map(function ($r) use ($sube) {
                if ($sube !== null && $sube !== (string)($r['sube'] ?? '')) $r['ozet'] = 'Başka şubenin işi';
                unset($r['sube']); return $r;
            }, $hg->fetchAll(PDO::FETCH_ASSOC))];
}

function tsDonusOzet(PDO $db, array $s): ?array {
    if (empty($s['donus_kontrol_id'])) return null;
    $q = $db->prepare('SELECT d.id, d.kiralama_id, d.yapan, d.updated_at, d.veri, k.kod AS kiralama_kod, k.durum AS kiralama_durum, k.sube FROM depo_kontrolleri d JOIN kiralamalar k ON k.id=d.kiralama_id WHERE d.id=?');
    $q->execute([(int)$s['donus_kontrol_id']]);
    $d = $q->fetch(PDO::FETCH_ASSOC);
    if (!$d) return null;
    $sube = subeKisitiHam();
    $gizli = $sube !== null && $sube !== trim((string)($d['sube'] ?? ''));
    $v = json_decode((string)($d['veri'] ?? ''), true) ?: [];
    require_once __DIR__ . '/belge-zinciri-lib.php';
    $t = bzIadeTutanagiKontrol($db, (int)$d['id']);
    if ($gizli) return ['kontrol_id' => 0, 'kiralama_id' => 0, 'kiralama_kod' => null, 'kiralama_durum' => null, 'yapan' => null, 'zaman' => $d['updated_at'] ?? null,
                        'imzali' => !empty($v['imza']['veri']), 'tespit' => null, 'baska_iste' => (int)($s['makine_baska_iste'] ?? 0) === 1];
    return ['kontrol_id' => (int)$d['id'], 'kiralama_id' => (int)$d['kiralama_id'], 'kiralama_kod' => $d['kiralama_kod'], 'kiralama_durum' => $d['kiralama_durum'],
            'yapan' => $d['yapan'] ?? null, 'zaman' => $d['updated_at'] ?? null, 'imzali' => !empty($v['imza']['veri']),
            'tespit' => $t ? ['tutanak_id' => (int)$t['id'], 'belge_no' => $t['belge_no'] ?? null] : null, 'baska_iste' => (int)($s['makine_baska_iste'] ?? 0) === 1];
}

function tsDonusKaynakli(PDO $db, array $s): bool {
    if (($s['kaynak'] ?? '') === 'depo_inceleme' || !empty($s['donus_kontrol_id'])) return true;
    if (($s['kaynak'] ?? '') !== 'donus_kontrolu') return false;
    $q = $db->prepare('SELECT 1 FROM servis_bulgulari WHERE servis_id=? LIMIT 1'); $q->execute([(int)($s['id'] ?? 0)]);
    return (bool)$q->fetchColumn();
}

function tsDepoServisiMi(PDO $db, array $s): bool {
    if (tsDonusKaynakli($db, $s)) return true;
    $q = $db->prepare('SELECT 1 FROM hasar_degerlendirmeleri WHERE servis_id=? AND depo_kontrol_id IS NOT NULL LIMIT 1');
    $q->execute([(int)$s['id']]);
    return (bool)$q->fetchColumn();
}

function tsTamamlamaEngeli(PDO $db, array $s): ?string {
    if (($s['durum'] ?? '') === 'tamamlandi' || ($s['tip'] ?? '') === 'eksik_parca' || !tsDepoServisiMi($db, $s)) return null;
    $q = $db->prepare("SELECT COUNT(*) FROM teknik_servis_kalemleri WHERE servis_id=? AND tur IN ('malzeme','iscilik') AND COALESCE(kaynak,'')<>'eksik'"); $q->execute([(int)$s['id']]);
    return (int)$q->fetchColumn() > 0 ? null
        : 'Dönüş kontrolünden gelen servis, "Yapılan iş" bölümüne en az bir malzeme ya da işçilik satırı yazılmadan tamamlanamaz.';
}

function tsKaynakKiralamaBul(PDO $db, ?string $tur, ?int $mid, string $tarih, ?int $istenen = null): ?int {
    if (!$tur || !$mid || !in_array($tur, ['arac', 'manlift'], true)) return null;
    if ($istenen) {
        $q = $db->prepare("SELECT id, sube FROM kiralamalar WHERE id=? AND makine_tur=? AND makine_id=? AND durum<>'iptal' AND baslangic<=?");
        $q->execute([$istenen, $tur, $mid, $tarih]);
        $k = $q->fetch(PDO::FETCH_ASSOC);
        $kisit = subeKisitiHam();
        if ($k && ($kisit === null || trim((string)($k['sube'] ?? '')) === $kisit)) return (int)$k['id'];
    }
    $q = $db->prepare("SELECT id FROM kiralamalar WHERE makine_tur=? AND makine_id=? AND durum<>'iptal' AND baslangic<=?
                       AND ((durum='aktif' AND gercek_bitis IS NULL) OR COALESCE(gercek_bitis, bitis, '9999-12-31')>=?)
                       ORDER BY baslangic DESC, id DESC LIMIT 1");
    $q->execute([$tur, $mid, $tarih, $tarih]);
    if ($k = $q->fetchColumn()) return (int)$k;
    $q = $db->prepare("SELECT id FROM kiralamalar WHERE makine_tur=? AND makine_id=? AND durum<>'iptal' AND COALESCE(gercek_bitis, bitis)<?
                       ORDER BY COALESCE(gercek_bitis, bitis) DESC, id DESC LIMIT 1");
    $q->execute([$tur, $mid, $tarih]);
    return ($k = $q->fetchColumn()) ? (int)$k : null;
}

function tsKaynakKiralamaDoldur(PDO $db): void {
    try {
        $q = $db->query("SELECT deger FROM ayarlar WHERE anahtar='ts_kaynak_kiralama_goc'");
        if ($q && $q->fetchColumn() === '1') return;
        $db->exec("UPDATE teknik_servis SET kiralama_id=(
                     SELECT k.id FROM kiralamalar k WHERE k.makine_tur=teknik_servis.makine_tur AND k.makine_id=teknik_servis.makine_id AND k.durum<>'iptal'
                       AND k.baslangic<=teknik_servis.tarih
                       AND ((k.durum='aktif' AND k.gercek_bitis IS NULL) OR COALESCE(k.gercek_bitis, k.bitis, '9999-12-31')>=teknik_servis.tarih)
                     ORDER BY k.baslangic DESC, k.id DESC LIMIT 1)
                   WHERE kiralama_id IS NULL AND makine_tur IS NOT NULL AND makine_id IS NOT NULL");
        $db->exec("UPDATE teknik_servis SET kiralama_id=(
                     SELECT k.id FROM kiralamalar k WHERE k.makine_tur=teknik_servis.makine_tur AND k.makine_id=teknik_servis.makine_id AND k.durum<>'iptal'
                       AND COALESCE(k.gercek_bitis, k.bitis)<teknik_servis.tarih
                     ORDER BY COALESCE(k.gercek_bitis, k.bitis) DESC, k.id DESC LIMIT 1)
                   WHERE kiralama_id IS NULL AND makine_tur IS NOT NULL AND makine_id IS NOT NULL");
        $db->exec("INSERT OR REPLACE INTO ayarlar(anahtar, deger) VALUES('ts_kaynak_kiralama_goc', '1')");
    } catch (\Throwable $e) { hataGunlugu('sema.ts_kaynak_goc', $e); }
}

function tsMasrafsizKapat(PDO $db, array $s, ?string $yapilan = null): int {
    $sid = (int)($s['id'] ?? 0);
    if ($sid < 1 || ($s['durum'] ?? '') === 'tamamlandi') return 0;
    $q = $db->prepare("SELECT COUNT(*) FROM teknik_servis_kalemleri WHERE servis_id=? AND tur IN ('malzeme','iscilik') AND COALESCE(kaynak,'')<>'eksik'"); $q->execute([$sid]);
    if ((int)$q->fetchColumn() > 0) return 0;
    $aciklama = mb_substr(trim((string)($yapilan ?? '')) ?: trim((string)($s['yapilan'] ?? '')) ?: trim((string)($s['ariza'] ?? '')) ?: 'Masrafsız kapanış', 0, 300);
    $yapan = (string)($_SESSION['username'] ?? 'Sistem');
    $db->prepare('INSERT INTO teknik_servis_kalemleri(servis_id,tur,aciklama,saat,kisi,yapan,created_at) VALUES(?,?,?,?,?,?,?)')
       ->execute([$sid, 'iscilik', $aciklama, 0, 1, $yapan, date('Y-m-d H:i:s')]);
    $id = (int)$db->lastInsertId();
    logla($db, 'teknik_servis_kalemleri', $id, 'ekle', null, ['servis_id' => $sid, 'tur' => 'iscilik', 'aciklama' => $aciklama, 'saat' => 0, 'kisi' => 1, 'masrafsiz' => 1],
          'Masrafsız kapanış — servis #' . $sid . ' (malzeme/işçilik yazılmadı)');
    return $id;
}

function tsTamamlandi(PDO $db, int $sid): array {
    require_once __DIR__ . '/hasar-fiyat-lib.php';
    $q = $db->prepare("SELECT id FROM hasar_degerlendirmeleri WHERE servis_id=? AND depo_kontrol_id IS NOT NULL AND fiyat_durum='bekliyor'");
    $q->execute([$sid]);
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $hid) if ($g = hfFiyatGoreviAc($db, (int)$hid)) $out[] = $g;
    require_once __DIR__ . '/hasar-belge-lib.php';
    hbTsr($db, $sid);
    require_once __DIR__ . '/saha-servis-lib.php';
    if ($g = ssFiyatBekliyorAc($db, $sid)) $out[] = $g;
    require_once __DIR__ . '/servis-maliyet-lib.php';
    $sv = tsServis($db, $sid);
    if (($sv['tip'] ?? '') === 'ariza' && empty($sv['musteri_makine_id']) && (int)($sv['musteriye_yansit'] ?? 0) !== 1 && (!empty($sv['kiralama_id']) || !empty($sv['musteri_id'])) && smAkis($db, $sv) === 'ic')
        $db->prepare('UPDATE teknik_servis SET musteriye_yansit=1 WHERE id=?')->execute([$sid]);
    if ($g = smFiyatBekliyorAc($db, $sid)) $out[] = $g;
    require_once __DIR__ . '/ariza-karar-lib.php';
    akServisTamamlandi($db, $sid);
    akServisIndirimUret($db, $sid, date('Y-m-d H:i:s'));
    return $out;
}

function tsAcikParcaTalebiVarMi(PDO $db, int $servisId): bool {
    $st = $db->prepare("
        SELECT 1 FROM satinalma_kalemleri k JOIN satinalma_siparisleri s ON s.id = k.siparis_id
        WHERE k.teknik_servis_id = ? AND s.durum <> 'iptal' AND k.teslim_alinan < k.miktar - 0.0001
        LIMIT 1
    ");
    $st->execute([$servisId]);
    return (bool)$st->fetchColumn();
}

function tsKontrolTamamlanmamisVarMi(PDO $db, int $servisId): bool {
    $st = $db->prepare("
        SELECT 1 FROM servis_kontrol_sonuclari s
        JOIN teknik_servis t ON t.id = s.teknik_servis_id
        WHERE s.teknik_servis_id = ? AND t.kontrol_sablon_id IS NOT NULL AND s.durum = 'nk'
        LIMIT 1
    ");
    $st->execute([$servisId]);
    return (bool)$st->fetchColumn();
}

function tsFotoKaydet(string $dataUrl, int $sid): ?string {
    if (!preg_match('/^data:image\/(jpe?g|png|webp);base64,([a-zA-Z0-9+\/=]+)$/', $dataUrl, $m)) return null;
    $bin = base64_decode($m[2], true);
    if ($bin === false || strlen($bin) < 100 || strlen($bin) > MAX_FILE_SIZE || !gorselPikselUygun($bin)) return null;
    $dir = UPLOAD_PATH . 'servis/' . $sid . '/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return null;
    $ad = bin2hex(random_bytes(16)) . '.' . ($m[1] === 'jpg' ? 'jpeg' : $m[1]);
    if (file_put_contents($dir . $ad, $bin) === false) return null;
    @chmod($dir . $ad, 0644);
    return 'servis/' . $sid . '/' . $ad;
}

function tsPortalBildir(PDO $db, array $eski, string $yeniDurum, ?string $yapilan): void {
    if ($yeniDurum !== 'tamamlandi' || ($eski['durum'] ?? '') === 'tamamlandi') return;
    if (($eski['kaynak'] ?? '') !== 'portal' || empty($eski['portal_kontak_id'])) return;
    require_once __DIR__ . '/whatsapp.php';
    $st = $db->prepare("SELECT musteri_id FROM musteri_kontaklari WHERE id=?");
    $st->execute([(int)$eski['portal_kontak_id']]);
    $mid = (int)$st->fetchColumn();
    if ($mid < 1) return;
    waMusteriBildir($db, $mid, (int)$eski['portal_kontak_id'], 'ariza_giderildi',
        ($eski['makine_ad'] ?: 'Makine') . ' için bildirdiğiniz arıza giderildi (servis no #' . $eski['id'] . ').'
        . (trim((string)$yapilan) !== '' ? ' Yapılan işlem: ' . trim((string)$yapilan) . '.' : '') . ' İyi çalışmalar dileriz.');
}
