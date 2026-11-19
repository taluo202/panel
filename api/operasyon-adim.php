<?php
declare(strict_types=1);

const OP_ADIMLAR = [
    'depo_hazirlik'    => 'Çıkış kontrolü',
    'yukleme'          => 'Araca yüklendi / yola çıktı',
    'saha_teslim'      => 'Sahada teslim',
    'toplaniyor'       => 'Toplanıyor',
    'saha_teslim_alma' => 'Sahada teslim alma',
    'depo_inceleme'    => 'Dönüş kontrolü',
];
const OP_ISTEGE_BAGLI = ['toplaniyor'];
const OP_ATLANABILIR = ['depo_hazirlik', 'yukleme', 'saha_teslim', 'toplaniyor', 'depo_inceleme'];
const OP_YETKILER = ['depo' => 'Makine kontrolü (çıkış / dönüş)', 'yukleme' => 'Yükleme / toplama (şoför)', 'saha' => 'Sahada teslim',
                     'atlama' => 'Adım atlama', 'dagitim' => 'İş dağıtımı', 'servis_fiyat' => 'Servis fiyatlandırma'];
const OP_ADIM_YETKI = ['depo_hazirlik' => 'depo', 'yukleme' => 'yukleme', 'saha_teslim' => 'saha',
                       'toplaniyor' => 'yukleme', 'saha_teslim_alma' => 'saha', 'depo_inceleme' => 'depo'];

function opSema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS operasyon_adimlari (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        kiralama_id INTEGER NOT NULL REFERENCES kiralamalar(id) ON DELETE CASCADE,
        adim        TEXT NOT NULL,
        sonuc       TEXT NOT NULL,              -- tamam | atlandi | geri_alindi
        yapan_tur   TEXT NOT NULL,              -- kullanici | personel | dis_sofor | ofis_kagit
        yapan_id    INTEGER,
        yapan_ad    TEXT,
        kaynak      TEXT NOT NULL,              -- yetki | yonetici | dis_baglanti | menu | tutanak | hazirlik
        gerekce     TEXT,
        veri        TEXT,
        zaman       TEXT NOT NULL DEFAULT (datetime('now','localtime')));
      CREATE INDEX IF NOT EXISTS idx_opadim_kiralama ON operasyon_adimlari(kiralama_id, adim);
      CREATE TABLE IF NOT EXISTS adim_yetkileri (
        kisi_tur    TEXT NOT NULL,              -- kullanici | personel
        kisi_id     INTEGER NOT NULL,
        yetki       TEXT NOT NULL,
        izin        INTEGER NOT NULL,           -- 1 açık, 0 kapalı (satır yoksa varsayılan)
        guncelleyen TEXT,
        zaman       TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        PRIMARY KEY (kisi_tur, kisi_id, yetki));
      CREATE TABLE IF NOT EXISTS operasyon_gecis (id INTEGER PRIMARY KEY CHECK(id=1), son_eski_kiralama INTEGER NOT NULL);
      INSERT OR IGNORE INTO operasyon_gecis SELECT 1, COALESCE(MAX(id),0) FROM kiralamalar;");
    $db->exec("CREATE TABLE IF NOT EXISTS depo_atamalari (
        kiralama_id           INTEGER NOT NULL REFERENCES kiralamalar(id) ON DELETE CASCADE,
        adim                  TEXT NOT NULL,           -- depo_hazirlik | yukleme
        atanan_kullanici_id   INTEGER,
        ustlenen_kullanici_id INTEGER,
        gorev_id              INTEGER,
        guncelleyen           TEXT,
        zaman                 TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        PRIMARY KEY (kiralama_id, adim));
      CREATE TABLE IF NOT EXISTS nakliye_ekip (
        nakliye_id  INTEGER NOT NULL REFERENCES nakliyeler(id) ON DELETE CASCADE,
        personel_id INTEGER NOT NULL REFERENCES personel(id) ON DELETE CASCADE,
        PRIMARY KEY (nakliye_id, personel_id));
      CREATE TABLE IF NOT EXISTS teslim_baglantilari (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        nakliye_id    INTEGER NOT NULL REFERENCES nakliyeler(id) ON DELETE CASCADE,
        yon           TEXT NOT NULL DEFAULT 'gidis',
        belirtec_ozet TEXT NOT NULL UNIQUE,
        gecerlilik    TEXT NOT NULL,
        kullanildi    INTEGER NOT NULL DEFAULT 0,
        olusturan     TEXT,
        zaman         TEXT NOT NULL DEFAULT (datetime('now','localtime')));");
    foreach (["ALTER TABLE nakliyeler ADD COLUMN dis_sofor_ad TEXT",
              "ALTER TABLE nakliyeler ADD COLUMN dis_sofor_tel TEXT",
              "ALTER TABLE nakliye_makineleri ADD COLUMN yuklendi_zaman TEXT",
              "ALTER TABLE nakliye_makineleri ADD COLUMN yukleyen TEXT",
              "ALTER TABLE nakliye_makineleri ADD COLUMN plan_disi INTEGER DEFAULT 0",
              "ALTER TABLE nakliye_makineleri ADD COLUMN toplama_zaman TEXT",
              "ALTER TABLE nakliye_makineleri ADD COLUMN toplayan TEXT"] as $sql) {
        try { $db->exec($sql); } catch (\Throwable $e) { if (!str_contains($e->getMessage(), 'duplicate column')) hataGunlugu('sema.operasyon_adim', $e); }
    }
    opA4Goc($db);
}

function opYeniZincir(PDO $db, array $k): bool {
    $sinir = (int)$db->query('SELECT son_eski_kiralama FROM operasyon_gecis WHERE id=1')->fetchColumn();
    return (int)($k['id'] ?? 0) > $sinir;
}

function opIadeOlayi(PDO $db, array $k): string {
    return opYeniZincir($db, $k) ? 'inceleme' : 'iade';
}

function opDonusKapandi(PDO $db, array $k, bool $guncel = true, ?string $gerekce = null): void {
    if (empty($k['makine_id']) || !opYeniZincir($db, $k)) return;
    $d = opAdimDurumu($db, (int)$k['id']);
    if (!isset($d['saha_teslim_alma']))
        opAdimYaz($db, (int)$k['id'], 'saha_teslim_alma', 'atlandi', opOturumYapan($db), 'menu', $gerekce ?? 'Kiralama tutanaksız kapatıldı');
    if (!$guncel && !isset($d['depo_inceleme'])) {
        opAdimYaz($db, (int)$k['id'], 'depo_inceleme', 'atlandi', opOturumYapan($db), 'menu', 'Makine sonraki işte; inceleme o işin dönüşünde');
        require_once __DIR__ . '/belge-zinciri-lib.php';
        bzIncelemeAtlandi($db, $k, 'Makine sonraki işte; inceleme o işin dönüşünde');
    }
}

function opSonDonenKiralamaMi(PDO $db, array $k): bool {
    $s = $db->prepare("SELECT id FROM kiralamalar WHERE makine_tur=? AND makine_id=? AND durum='tamamlandi'
                       ORDER BY COALESCE(gercek_bitis, bitis, baslangic) DESC, id DESC LIMIT 1");
    $s->execute([(string)$k['makine_tur'], (int)$k['makine_id']]);
    return (int)$s->fetchColumn() === (int)$k['id'];
}

function opA4Goc(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS operasyon_goc (ad TEXT PRIMARY KEY, zaman TEXT NOT NULL)");
    if ($db->query("SELECT 1 FROM operasyon_goc WHERE ad='a4_inceleme'")->fetchColumn()) return;
    $acik = !$db->inTransaction();
    if ($acik) $db->beginTransaction();
    try {
        islemKilitAl($db);
        if (!$db->query("SELECT 1 FROM operasyon_goc WHERE ad='a4_inceleme'")->fetchColumn()) {
            $sinir = (int)$db->query('SELECT son_eski_kiralama FROM operasyon_gecis WHERE id=1')->fetchColumn();
            $db->prepare("INSERT INTO operasyon_adimlari(kiralama_id,adim,sonuc,yapan_tur,yapan_ad,kaynak,gerekce,zaman)
                          SELECT DISTINCT kiralama_id,'depo_inceleme','atlandi','kullanici','Sistem (A4 geçişi)','yonetici',
                                 'A4 öncesi teslim alındı; makine bakım akışıyla döndü.', ?
                          FROM operasyon_adimlari a WHERE a.id IN (SELECT MAX(id) FROM operasyon_adimlari WHERE adim='saha_teslim_alma' GROUP BY kiralama_id)
                            AND a.sonuc<>'geri_alindi' AND a.kiralama_id>?
                            AND a.kiralama_id NOT IN (SELECT id FROM kiralamalar WHERE durum='aktif')
                            AND a.kiralama_id NOT IN (SELECT kiralama_id FROM operasyon_adimlari WHERE adim='depo_inceleme')
                            -- Yarıda kalmış ilk koşumdan sonra A4 ile teslim alınan iş makineyi 'inceleme_bekliyor' yapar;
                            -- A4 öncesi dönüş bu duruma hiç giremez. Atlandı yazılsa makine kilitli kalırdı.
                            AND a.kiralama_id NOT IN (SELECT k.id FROM kiralamalar k
                                  LEFT JOIN manliftler mm ON k.makine_tur='manlift' AND mm.id=k.makine_id
                                  LEFT JOIN araclar ma ON k.makine_tur='arac' AND ma.id=k.makine_id
                                  WHERE COALESCE(mm.durum, ma.durum)='inceleme_bekliyor'
                                    AND NOT EXISTS (SELECT 1 FROM kiralamalar s WHERE s.makine_tur=k.makine_tur AND s.makine_id=k.makine_id
                                                    AND s.durum<>'iptal' AND s.id>k.id))")->execute([date('Y-m-d H:i:s'), $sinir]);
            $db->prepare("INSERT INTO operasyon_goc(ad,zaman) VALUES('a4_inceleme',?)")->execute([date('Y-m-d H:i:s')]);
        }
        if ($acik) $db->commit();
    } catch (\Throwable $e) {
        if ($acik && $db->inTransaction()) $db->rollBack();
        error_log('opA4Goc: ' . $e->getMessage());
    }
}

function _opAdminMi(PDO $db, int $userId): bool {
    $s = $db->prepare('SELECT rol FROM users WHERE id=?');
    $s->execute([$userId]);
    return $s->fetchColumn() === 'admin';
}

function _opAktifPersonel(PDO $db, ?int $personelId): bool {
    if (!$personelId) return false;
    $s = $db->prepare("SELECT 1 FROM personel WHERE id=? AND COALESCE(durum,'aktif')='aktif'");
    $s->execute([$personelId]);
    return (bool)$s->fetchColumn();
}

function _opVarsayilan(PDO $db, string $kisiTur, int $kisiId, string $yetki): bool {
    if ($kisiTur === 'personel') return false;
    $s = $db->prepare('SELECT yetkiler, personel_id FROM users WHERE id=?');
    $s->execute([$kisiId]);
    $u = $s->fetch(PDO::FETCH_ASSOC);
    if (!$u || !in_array($yetki, ['depo', 'yukleme', 'saha', 'dagitim', 'servis_fiyat'], true)) return false;
    if (yetkiDizesiIzin((string)$u['yetkiler'], 'kiralama_takip', 'yaz')) return true;
    if ($yetki === 'depo' && yetkiDizesiIzin((string)$u['yetkiler'], 'teknik_servis', 'yaz')) return true;
    return $yetki === 'saha' && _opAktifPersonel($db, $u['personel_id'] !== null ? (int)$u['personel_id'] : null);
}

function opYetkiVar(PDO $db, string $kisiTur, int $kisiId, string $yetki): bool {
    if (!isset(OP_YETKILER[$yetki]) || $kisiId < 1) return false;
    if ($kisiTur === 'personel') {
        $s = $db->prepare('SELECT id FROM users WHERE personel_id=? ORDER BY id LIMIT 1');
        $s->execute([$kisiId]);
        if ($uid = (int)$s->fetchColumn()) { $kisiTur = 'kullanici'; $kisiId = $uid; }
    }
    if ($kisiTur === 'kullanici' && _opAdminMi($db, $kisiId)) return true;
    $s = $db->prepare('SELECT izin FROM adim_yetkileri WHERE kisi_tur=? AND kisi_id=? AND yetki=?');
    $s->execute([$kisiTur, $kisiId, $yetki]);
    $v = $s->fetchColumn();
    return $v !== false ? (int)$v === 1 : _opVarsayilan($db, $kisiTur, $kisiId, $yetki);
}

function opOturumYetkisi(PDO $db, string $yetki): bool {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $uid = (int)($_SESSION['user_id'] ?? 0);
    return $uid > 0 && opYetkiVar($db, 'kullanici', $uid, $yetki);
}

function opOturumYapan(PDO $db): array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $ad = trim((string)($_SESSION['ad_soyad'] ?? '')) ?: (string)($_SESSION['username'] ?? '');
    return ['tur' => 'kullanici', 'id' => (int)($_SESSION['user_id'] ?? 0) ?: null, 'ad' => $ad];
}

function opAdimYaz(PDO $db, int $kiralamaId, string $adim, string $sonuc, array $yapan, string $kaynak,
                   ?string $gerekce = null, ?array $veri = null): int {
    if (!isset(OP_ADIMLAR[$adim])) throw new InvalidArgumentException('Geçersiz operasyon adımı: ' . $adim);
    if (!in_array($sonuc, ['tamam', 'atlandi', 'geri_alindi'], true)) throw new InvalidArgumentException('Geçersiz adım sonucu.');
    if (!in_array($kaynak, ['yetki', 'yonetici', 'dis_baglanti', 'menu', 'tutanak', 'hazirlik'], true)) throw new InvalidArgumentException('Geçersiz adım kaynağı.');
    $db->prepare('INSERT INTO operasyon_adimlari(kiralama_id,adim,sonuc,yapan_tur,yapan_id,yapan_ad,kaynak,gerekce,veri,zaman)
                  VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([
        $kiralamaId, $adim, $sonuc, (string)($yapan['tur'] ?? 'kullanici'),
        isset($yapan['id']) ? (int)$yapan['id'] : null, $yapan['ad'] ?? null, $kaynak,
        $gerekce !== null ? mb_substr(trim($gerekce), 0, 1000) : null,
        $veri !== null ? json_encode($veri, JSON_UNESCAPED_UNICODE) : null,
        date('Y-m-d H:i:s'),
    ]);
    $id = (int)$db->lastInsertId();
    logla($db, 'kiralamalar', $kiralamaId, 'guncelle', null, ['adim' => $adim, 'sonuc' => $sonuc, 'kaynak' => $kaynak],
          'Operasyon adımı: ' . OP_ADIMLAR[$adim] . ' — ' . ['tamam' => 'yapıldı', 'atlandi' => 'atlandı', 'geri_alindi' => 'geri alındı'][$sonuc]
          . ($gerekce ? ' (' . mb_substr(trim($gerekce), 0, 200) . ')' : ''));
    if (in_array($adim, ['depo_hazirlik', 'yukleme', 'toplaniyor', 'depo_inceleme'], true) && $sonuc !== 'geri_alindi')
        $db->prepare("UPDATE gorevler SET durum='tamamlandi', tamamlanma=datetime('now','localtime'), updated_at=datetime('now','localtime')
                      WHERE kaynak_modul=? AND kaynak_id=? AND durum='acik'")->execute(['depo_' . $adim, $kiralamaId]);
    return $id;
}

function opAdimDurumu(PDO $db, int $kiralamaId): array {
    $s = $db->prepare('SELECT adim, sonuc, zaman, yapan_ad FROM operasyon_adimlari WHERE kiralama_id=? ORDER BY id');
    $s->execute([$kiralamaId]);
    return _opDurumKur($s->fetchAll(PDO::FETCH_ASSOC));
}

function _opDurumKur(array $satirlar): array {
    $d = [];
    foreach ($satirlar as $r) {
        if ($r['sonuc'] === 'geri_alindi') unset($d[$r['adim']]);
        else $d[$r['adim']] = ['sonuc' => $r['sonuc'], 'zaman' => $r['zaman'], 'yapan_ad' => $r['yapan_ad']];
    }
    return $d;
}

function opGeriAlKontrol(PDO $db, int $kid, string $adim): void {
    $sonuc = opAdimDurumu($db, $kid)[$adim]['sonuc'] ?? null;
    if (!in_array($sonuc, ['tamam', 'atlandi'], true))
        throw new DomainException(OP_ADIMLAR[$adim] . ' bu kiralamada kayıtlı değil; geri alınacak bir şey yok.');
    if ($adim === 'depo_inceleme' && $sonuc === 'tamam')
        throw new DomainException('Tamamlanmış dönüş kontrolü geri alınamaz; düzeltme için yöneticiye başvurun.');
    if ($adim === 'depo_inceleme' && $sonuc === 'atlandi') {
        require_once __DIR__ . '/belge-zinciri-lib.php';
        if ($it = bzIadeTutanagi($db, $kid))
            throw new DomainException('Dönüş kontrolü atlandığı için iade tutanağı ' . ($it['belge_no'] ?: '#' . $it['id']) . ' oluştu; atlama geri alınamaz.');
    }
}

function opSiradakiHesapla(array $durum, array $k): ?string {
    if (empty($k['makine_id']) || ($k['durum'] ?? '') === 'iptal') return null;
    if (isset($durum['saha_teslim_alma'])) return isset($durum['depo_inceleme']) ? null : 'depo_inceleme';
    if (($k['durum'] ?? '') !== 'aktif' || !empty($k['gercek_bitis'])) return null;
    $adimlar = array_keys(OP_ADIMLAR);
    $enIleri = -1;
    foreach ($adimlar as $i => $a) if (isset($durum[$a])) $enIleri = $i;
    return $adimlar[$enIleri + 1] ?? null;
}

function opSiradakiAdim(PDO $db, array $k): ?string {
    if (!opYeniZincir($db, $k)) return null;
    return opSiradakiHesapla(opAdimDurumu($db, (int)$k['id']), $k);
}

function opSahaEkrani(PDO $db, string $tur, int $makineId): ?array {
    $s = $db->prepare("SELECT * FROM kiralamalar WHERE makine_tur=? AND makine_id=? AND durum='aktif' AND gercek_bitis IS NULL ORDER BY baslangic, id");
    $s->execute([$tur, $makineId]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $k) {
        $sira = opSiradakiAdim($db, $k);
        if ($sira === 'saha_teslim') return ['kiralama_id' => (int)$k['id'], 'islem' => 'teslim'];
        if (in_array($sira, ['toplaniyor', 'saha_teslim_alma'], true)) return ['kiralama_id' => (int)$k['id'], 'islem' => 'iade'];
    }
    return null;
}

function opDepoEngeli(PDO $db, string $tur, int $makineId): ?string {
    $s = $db->prepare("SELECT * FROM kiralamalar WHERE makine_tur=? AND makine_id=? AND durum='aktif' AND gercek_bitis IS NULL ORDER BY baslangic DESC, id DESC LIMIT 1");
    $s->execute([$tur, $makineId]);
    $k = $s->fetch(PDO::FETCH_ASSOC);
    if (!$k || !in_array(opSiradakiAdim($db, $k), ['depo_hazirlik', 'yukleme'], true)) return null;
    require_once __DIR__ . '/hazirlik-akis.php';
    return hzEngel($db, $k);
}

function _opGuncelAcikIs(PDO $db, string $tur, int $makineId): ?array {
    $s = $db->prepare("SELECT * FROM kiralamalar WHERE makine_tur=? AND makine_id=? AND durum='aktif' AND gercek_bitis IS NULL ORDER BY baslangic, id LIMIT 1");
    $s->execute([$tur, $makineId]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function opDepoIsi(PDO $db, string $tur, int $makineId): ?array {
    $s = $db->prepare("SELECT * FROM kiralamalar WHERE makine_tur=? AND makine_id=? AND durum<>'iptal'
                       AND id IN (SELECT kiralama_id FROM operasyon_adimlari WHERE adim='saha_teslim_alma') ORDER BY id DESC LIMIT 5");
    $s->execute([$tur, $makineId]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $k)
        if (opSiradakiAdim($db, $k) === 'depo_inceleme') return ['kiralama' => $k, 'kiralama_id' => (int)$k['id'], 'is' => 'inceleme'];
    $k = _opGuncelAcikIs($db, $tur, $makineId);
    $sira = $k ? opSiradakiAdim($db, $k) : null;
    return in_array($sira, ['depo_hazirlik', 'yukleme'], true)
        ? ['kiralama' => $k, 'kiralama_id' => (int)$k['id'], 'is' => $sira === 'yukleme' ? 'yukleme' : 'hazirlik'] : null;
}

const OP_GORUNEN = ['depo_hazirlik' => 'kiraya_gidecek', 'yukleme' => 'kiraya_gidecek', 'saha_teslim' => 'nakliyede',
                    'toplaniyor' => 'kirada', 'saha_teslim_alma' => 'kiradan_gelecek'];
const OP_AKIS_DURUMLARI = ['musait', 'kirada', 'kiraya_gidecek', 'nakliyede', 'kiradan_gelecek'];

function opGorunenDurumKarar(string $durum, bool $kirada, ?string $akis): string {
    return $kirada && in_array($durum, OP_AKIS_DURUMLARI, true) ? ($akis ?? 'kirada') : $durum;
}

function opGorunenDurum(PDO $db, string $tur, int $makineId): ?string {
    $k = _opGuncelAcikIs($db, $tur, $makineId);
    return $k ? OP_GORUNEN[opSiradakiAdim($db, $k) ?? ''] ?? null : null;
}

function opGorunenDurumToplu(PDO $db, string $tur): array {
    $s = $db->prepare("SELECT * FROM kiralamalar WHERE makine_tur=? AND makine_id IS NOT NULL AND durum='aktif' AND gercek_bitis IS NULL ORDER BY baslangic, id");
    $s->execute([$tur]);
    $guncel = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $k) $guncel[(int)$k['makine_id']] ??= $k;
    $sira = opSiradakiToplu($db, array_values($guncel));
    $out = [];
    foreach ($guncel as $mid => $k) if ($g = OP_GORUNEN[$sira[(int)$k['id']] ?? ''] ?? null) $out[$mid] = $g;
    return $out;
}

function opAdimDurumuToplu(PDO $db, array $kiralamalar): array {
    $sinir = (int)$db->query('SELECT son_eski_kiralama FROM operasyon_gecis WHERE id=1')->fetchColumn();
    $ids = array_values(array_filter(array_map(fn($k) => (int)$k['id'], $kiralamalar), fn($id) => $id > $sinir));
    $satir = [];
    if ($ids) {
        $s = $db->prepare('SELECT kiralama_id, adim, sonuc, zaman, yapan_ad FROM operasyon_adimlari WHERE kiralama_id IN ('
                          . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id');
        $s->execute($ids);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $satir[(int)$r['kiralama_id']][] = $r;
    }
    $out = [];
    foreach ($ids as $id) $out[$id] = _opDurumKur($satir[$id] ?? []);
    return $out;
}

function opSiradakiToplu(PDO $db, array $kiralamalar): array {
    $durum = opAdimDurumuToplu($db, $kiralamalar);
    $out = [];
    foreach ($kiralamalar as $k) {
        $id = (int)$k['id'];
        $out[$id] = isset($durum[$id]) ? opSiradakiHesapla($durum[$id], $k) : null;
    }
    return $out;
}


const _OP_SQL_IC_TAKMA = ['opk', 'dst', 'dsh', 'dss'];

function _opSqlTakma(string $k): string {
    if (!preg_match('/^[a-z_][a-z0-9_]*$/', $k) || in_array($k, _OP_SQL_IC_TAKMA, true)) throw new InvalidArgumentException('Geçersiz tablo takma adı.');
    return $k;
}

function _opYeniZincirSql(string $k): string {
    return "$k.id > COALESCE((SELECT son_eski_kiralama FROM operasyon_gecis WHERE id=1),0)";
}

function _opAdimKayitliSql(string $k, string $adim): string {
    if (!isset(OP_ADIMLAR[$adim])) throw new InvalidArgumentException('Geçersiz operasyon adımı: ' . $adim);
    return "(COALESCE((SELECT opk.sonuc FROM operasyon_adimlari opk WHERE opk.kiralama_id=$k.id AND opk.adim='$adim' ORDER BY opk.id DESC LIMIT 1),'geri_alindi')<>'geri_alindi')";
}

function _opTuretilirSql(string $k): string {
    return "(COALESCE($k.durum,'')='tamamlandi' AND COALESCE($k.makine_id,0)<>0 AND " . _opYeniZincirSql($k) . ')';
}

function opTeslimAlindiSql(string $k = 'k'): string {
    $k = _opSqlTakma($k);
    return '(' . _opYeniZincirSql($k) . ' AND ' . _opAdimKayitliSql($k, 'saha_teslim_alma') . ')';
}

function opBakimdaSql(string $k = 'k'): string {
    $k = _opSqlTakma($k);
    return '(' . _opTuretilirSql($k) . ' AND ' . _opAdimKayitliSql($k, 'saha_teslim_alma') . ' AND NOT ' . _opAdimKayitliSql($k, 'depo_inceleme') . ')';
}

function opServisteSql(string $k = 'k'): string {
    $k = _opSqlTakma($k);
    return '(' . _opTuretilirSql($k) . ' AND NOT (' . _opAdimKayitliSql($k, 'saha_teslim_alma') . ' AND NOT ' . _opAdimKayitliSql($k, 'depo_inceleme') . ')'
         . ' AND ' . opDepoServisAcikSql($k) . ')';
}

function opBakimdaSekmesiSql(string $k = 'k'): string {
    return '(' . opBakimdaSql($k) . ' OR ' . opServisteSql($k) . ')';
}

function opGorunenDurumSql(string $k = 'k'): string {
    $k = _opSqlTakma($k);
    return "(CASE WHEN " . opBakimdaSql($k) . " THEN 'bakimda' WHEN " . opServisteSql($k) . " THEN 'serviste' ELSE $k.durum END)";
}

function opKiralamaGorunenDurum(array $k, ?array $opDurum, bool $depoServisAcik = false): string {
    $d = (string)($k['durum'] ?? 'aktif');
    if ($opDurum === null || empty($k['makine_id']) || $d !== 'tamamlandi') return $d;
    if (isset($opDurum['saha_teslim_alma']) && !isset($opDurum['depo_inceleme'])) return 'bakimda';
    return $depoServisAcik ? 'serviste' : $d;
}

const _OP_DEPO_SERVIS_KOLLARI = [
    ['kid' => 'dst.kiralama_id', 'sid' => 'dst.id', 'from' => 'teknik_servis dst',
     'kosul' => "dst.kaynak='depo_inceleme' AND dst.durum<>'tamamlandi' AND dst.kiralama_id IS NOT NULL AND COALESCE(dst.makine_baska_iste,0)=0"],
    ['kid' => 'dsh.kiralama_id', 'sid' => 'dss.id', 'from' => 'hasar_degerlendirmeleri dsh JOIN teknik_servis dss ON dss.id=dsh.servis_id',
     'kosul' => "dsh.depo_kontrol_id IS NOT NULL AND dss.durum<>'tamamlandi' AND dsh.kiralama_id IS NOT NULL"],
];

function opDepoServisAcikSql(string $k = 'k'): string {
    $k = _opSqlTakma($k);
    return "($k.id IN (" . implode(' UNION ', array_map(fn($c) => "SELECT {$c['kid']} FROM {$c['from']} WHERE {$c['kosul']}", _OP_DEPO_SERVIS_KOLLARI)) . '))';
}

function opDepoServisAcikToplu(PDO $db, array $kiralamaIdleri): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $kiralamaIdleri), fn($i) => $i > 0)));
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $s = $db->prepare('SELECT kid, MIN(sid) AS sid FROM (' . implode(' UNION ALL ', array_map(
            fn($c) => "SELECT {$c['kid']} AS kid, {$c['sid']} AS sid FROM {$c['from']} WHERE {$c['kosul']} AND {$c['kid']} IN ($in)",
            _OP_DEPO_SERVIS_KOLLARI)) . ') GROUP BY kid');
    $s->execute(array_merge(...array_fill(0, count(_OP_DEPO_SERVIS_KOLLARI), $ids)));
    $out = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['kid']] = (int)$r['sid'];
    return $out;
}

function opTekKayitEngeli(PDO $db, array $k, string $adim): ?string {
    if (!opYeniZincir($db, $k)) return null;
    $d = opAdimDurumu($db, (int)$k['id'])[$adim] ?? null;
    if (!$d || $d['sonuc'] !== 'tamam') return null;
    return OP_ADIMLAR[$adim] . ' bu makinede ' . date('d.m.Y H:i', strtotime((string)$d['zaman'])) . ' tarihinde '
         . ($d['yapan_ad'] ?: 'bir kullanıcı') . ' tarafından kaydedildi; ikinci kez kaydedilemez.';
}

function opGuncelKiralama(PDO $db, string $tur, int $makineId): ?array {
    $bugun = date('Y-m-d');
    $s = $db->prepare("SELECT * FROM kiralamalar WHERE makine_tur=? AND makine_id=? AND durum<>'iptal' AND baslangic<=?
                       ORDER BY baslangic DESC, id DESC LIMIT 1");
    $s->execute([$tur, $makineId, $bugun]);
    $son = $s->fetch(PDO::FETCH_ASSOC);
    if ($son && $son['durum'] === 'aktif' && empty($son['gercek_bitis'])) return $son;
    $s = $db->prepare("SELECT * FROM kiralamalar WHERE makine_tur=? AND makine_id=? AND durum='aktif' AND gercek_bitis IS NULL
                       AND baslangic>? ORDER BY baslangic, id LIMIT 1");
    $s->execute([$tur, $makineId, $bugun]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

const OP_KART_GECIS = [
    'nakliyede'       => ['yukleme',          ['kiraya_gidecek']],
    'musait'          => ['saha_teslim',      ['kiraya_gidecek', 'nakliyede']],
    'kiradan_gelecek' => ['toplaniyor',       ['musait', 'kirada']],
    'bakim_bekliyor'  => ['saha_teslim_alma', ['musait', 'kirada', 'kiradan_gelecek']],
];
function opMakineKartiHareketi(PDO $db, string $tur, int $makineId, string $yeniDurum): ?string {
    if (in_array($tur, ['manlift', 'arac'], true) && $makineId > 0) {
        $q0 = $db->prepare('SELECT durum FROM ' . ($tur === 'arac' ? 'araclar' : 'manliftler') . ' WHERE id=?');
        $q0->execute([$makineId]);
        $simdi = (string)$q0->fetchColumn();
        if ($simdi === 'inceleme_bekliyor' && $yeniDurum !== 'inceleme_bekliyor')
            return 'Makine dönüşte dönüş kontrolü bekliyor; Teknik Servis → Makine Kontrolleri → Dönüş kontrolü\'nden kontrol edin ya da adımı gerekçeyle atlayın.';
        if ($yeniDurum === 'inceleme_bekliyor' && $simdi !== 'inceleme_bekliyor')
            return '"Dönüş kontrolü bekliyor" durumu yalnız teslim almayla oluşur.';
    }
    if (!isset(OP_KART_GECIS[$yeniDurum]) || !in_array($tur, ['manlift', 'arac'], true) || $makineId < 1) return null;
    [$adim, $oncekiler] = OP_KART_GECIS[$yeniDurum];
    $q = $db->prepare('SELECT durum FROM ' . ($tur === 'arac' ? 'araclar' : 'manliftler') . ' WHERE id=?');
    $q->execute([$makineId]);
    if (!in_array((string)$q->fetchColumn(), $oncekiler, true)) return null;
    $k = opGuncelKiralama($db, $tur, $makineId);
    if (!$k || !opYeniZincir($db, $k)) return null;
    if ((string)$k['baslangic'] > date('Y-m-d') && $adim !== 'yukleme') return null;
    if (($y = OP_ADIM_YETKI[$adim]) && !opOturumYetkisi($db, $y)) return 'Bu adım için "' . OP_YETKILER[$y] . '" yetkiniz yok.';
    if ((opAdimDurumu($db, (int)$k['id'])[$adim]['sonuc'] ?? null) === 'tamam') return null;
    if (in_array($adim, ['saha_teslim', 'saha_teslim_alma'], true)) return OP_SAHA_IMZASIZ_RET;
    opAdimYaz($db, (int)$k['id'], $adim, 'tamam', opOturumYapan($db), 'menu', 'Makine kartından işaretlendi');
    return null;
}

function opIsAdresi(string $is, array $p = []): string {
    $kontrol = in_array($is, ['hazirlik', 'inceleme'], true);
    return ($kontrol ? 'teknik-servis.html' : 'depo-isleri.html') . '?' . http_build_query(($kontrol ? ['sekme' => 'kontrol'] : []) + ['is' => $is] + $p);
}

const OP_SAHA_IMZASIZ_RET = 'Yeni kiralamalarda sahada teslim ve teslim alma imzalı kayıtla yapılır; makine kartından ya da '
                          . 'menüden imzasız işaretlenemez. Kiralama Takip\'te "Sıradaki adım" ile Sahada Teslim ekranını açın.';
