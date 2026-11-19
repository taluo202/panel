<?php
declare(strict_types=1);

class SfYetkiHatasi extends DomainException {}

const SF_GIDIS_YONLERI = ['gidis', 'gidis_donus', 'tek'];

function sfSema(PDO $db): void {
    $var = array_flip(array_column($db->query('PRAGMA table_info(nakliyeler)')->fetchAll(PDO::FETCH_ASSOC), 'name'));
    foreach (['sofor_birakan_id' => 'INTEGER', 'sofor_birak_zaman' => 'TEXT', 'sofor_dusen_id' => 'INTEGER'] as $ad => $tanim) {
        if (!isset($var[$ad])) $db->exec("ALTER TABLE nakliyeler ADD COLUMN $ad $tanim");
    }
}

function sfUstlenmeTarihiUygun(?string $tarih): bool {
    return $tarih === null || $tarih === '' || substr($tarih, 0, 10) <= date('Y-m-d', strtotime('+1 day'));
}

function _sfNakliye(PDO $db, int $nakliyeId): array {
    $s = $db->prepare('SELECT * FROM nakliyeler WHERE id=?');
    $s->execute([$nakliyeId]);
    $n = $s->fetch(PDO::FETCH_ASSOC);
    if (!$n) throw new DomainException('Nakliye aracı bulunamadı.');
    return $n;
}

function _sfBizimGidisMi(array $n): bool {
    return ($n['tip'] ?? '') === 'avk' && in_array((string)($n['yon'] ?: 'gidis'), SF_GIDIS_YONLERI, true);
}

function _sfPersonelAd(PDO $db, ?int $personelId): ?string {
    if (!$personelId) return null;
    $s = $db->prepare('SELECT ad_soyad FROM personel WHERE id=?');
    $s->execute([$personelId]);
    $ad = $s->fetchColumn();
    return $ad !== false ? (string)$ad : '#' . $personelId;
}

function sfDisCift(?array $n, string $yon): array {
    $c = fn(string $ad, string $tel): array => ['ad' => trim((string)($n[$ad] ?? '')), 'tel' => trim((string)($n[$tel] ?? ''))];
    $g = $c('dis_sofor_ad', 'dis_sofor_tel');
    if ($yon !== 'donus') return $g;
    $d = $c('dis_sofor_donus_ad', 'dis_sofor_donus_tel');
    return $d['ad'] !== '' ? $d : $g;
}

function _sfAktifPersonel(PDO $db, int $personelId): bool {
    $s = $db->prepare("SELECT 1 FROM personel WHERE id=? AND COALESCE(durum,'aktif')='aktif'");
    $s->execute([$personelId]);
    return (bool)$s->fetchColumn();
}

function _sfYuklendiMi(PDO $db, int $nakliyeId): bool {
    $s = $db->prepare('SELECT 1 FROM nakliye_makineleri WHERE nakliye_id=? AND yuklendi_zaman IS NOT NULL LIMIT 1');
    $s->execute([$nakliyeId]);
    return (bool)$s->fetchColumn();
}

function _sfAracKiralamalari(PDO $db, array $n, bool $acik = true): array {
    $kosul = $acik ? " AND k.durum='aktif' AND k.gercek_bitis IS NULL AND k.makine_id IS NOT NULL" : '';
    $s = $db->prepare('SELECT k.* FROM nakliye_makineleri nm JOIN kiralamalar k ON k.id=nm.kiralama_id WHERE nm.nakliye_id=?' . $kosul . ' ORDER BY k.id');
    $s->execute([(int)$n['id']]);
    $bag = $db->prepare('SELECT COUNT(*) FROM nakliye_makineleri WHERE nakliye_id=?');
    $bag->execute([(int)$n['id']]);
    if ((int)$bag->fetchColumn() > 0) return $s->fetchAll(PDO::FETCH_ASSOC);
    $sp = trim((string)($n['siparis_no'] ?? ''));
    if ($sp !== '') {
        $q = $db->prepare('SELECT k.* FROM kiralamalar k WHERE k.siparis_no=?' . $kosul . ' ORDER BY k.id');
        $q->execute([$sp]);
    } else {
        $q = $db->prepare('SELECT k.* FROM kiralamalar k WHERE k.id=?' . $kosul);
        $q->execute([(int)($n['kiralama_id'] ?? 0)]);
    }
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function _sfAracSubesi(PDO $db, array $n): ?string {
    $k = _sfAracKiralamalari($db, $n, false);
    $sube = $k ? ($k[0]['sube'] ?? null) : ($n['sube'] ?? null);
    $sube = trim((string)$sube);
    return $sube !== '' ? $sube : null;
}

function _sfSubeDenetle(?string $kisit, ?string $isSubesi): void {
    if ($kisit !== null && trim((string)$isSubesi) !== $kisit) throw new SfYetkiHatasi('Bu iş başka bir şubeye ait; erişim yetkiniz yok.');
}

function _sfYuklemeSirasinda(PDO $db, array $k): bool {
    if (!opYeniZincir($db, $k)) return false;
    $durum = opAdimDurumu($db, (int)$k['id']);
    if (!in_array($durum['depo_hazirlik']['sonuc'] ?? null, ['tamam', 'atlandi'], true)) return false;
    return opSiradakiHesapla($durum, $k) === 'yukleme';
}

function _sfIsTarihi(array $n, array $k): ?string {
    $t = trim((string)($n['tarih'] ?? '')) ?: trim((string)($k['baslangic'] ?? ''));
    return $t !== '' ? substr($t, 0, 10) : null;
}

function _sfUygunDizi(PDO $db, array $n, array $k): array {
    $pid = $n['sofor_personel_id'] !== null ? (int)$n['sofor_personel_id'] : null;
    return ['nakliye_id' => (int)$n['id'], 'kiralama_id' => (int)$k['id'],
            'siparis_no' => (string)(($n['siparis_no'] ?? '') ?: ($k['siparis_no'] ?? '')),
            'sube' => trim((string)($k['sube'] ?? '')) ?: null, 'tarih' => _sfIsTarihi($n, $k),
            'sofor_personel_id' => $pid, 'sofor_ad' => _sfPersonelAd($db, $pid)];
}

function sfUygunArac(PDO $db, string $tur, int $makineId): ?array {
    if (!in_array($tur, ['manlift', 'arac'], true) || $makineId < 1) return null;
    $s = $db->prepare("SELECT * FROM kiralamalar WHERE makine_tur=? AND makine_id=? AND durum='aktif' AND gercek_bitis IS NULL ORDER BY baslangic, id");
    $s->execute([$tur, $makineId]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $k) {
        if (!_sfYuklemeSirasinda($db, $k)) continue;
        $sp = trim((string)($k['siparis_no'] ?? ''));
        $q = $sp !== ''
            ? $db->prepare("SELECT * FROM nakliyeler WHERE siparis_no=? AND tip='avk' AND COALESCE(yon,'gidis') IN ('gidis','gidis_donus','tek') ORDER BY id")
            : $db->prepare("SELECT * FROM nakliyeler WHERE (kiralama_id=? OR id IN (SELECT nakliye_id FROM nakliye_makineleri WHERE kiralama_id=?))
                            AND tip='avk' AND COALESCE(yon,'gidis') IN ('gidis','gidis_donus','tek') ORDER BY id");
        $q->execute($sp !== '' ? [$sp] : [(int)$k['id'], (int)$k['id']]);
        $acik = null; $bos = null;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $n) {
            $b = $db->prepare('SELECT kiralama_id FROM nakliye_makineleri WHERE nakliye_id=?');
            $b->execute([(int)$n['id']]);
            $liste = array_map('intval', $b->fetchAll(PDO::FETCH_COLUMN));
            if (!$liste) $bos ??= $n;
            elseif (in_array((int)$k['id'], $liste, true)) { $acik = $n; break; }
        }
        if ($n = $acik ?? $bos) return _sfUygunDizi($db, $n, $k);
    }
    return null;
}

function _sfAracinIsi(PDO $db, array $n): ?array {
    foreach (_sfAracKiralamalari($db, $n) as $k) if (_sfYuklemeSirasinda($db, $k)) return $k;
    return null;
}

function _sfLogla(PDO $db, array $n, ?int $yeniPid, ?string $kaynak, string $ozet): void {
    $eskiPid = $n['sofor_personel_id'] !== null ? (int)$n['sofor_personel_id'] : null;
    logla($db, 'nakliyeler', (int)$n['id'], 'guncelle',
          ['sofor_personel_id' => $eskiPid, 'sofor' => _sfPersonelAd($db, $eskiPid), 'sofor_kaynak' => $n['sofor_kaynak'] ?? null],
          ['sofor_personel_id' => $yeniPid, 'sofor' => _sfPersonelAd($db, $yeniPid), 'sofor_kaynak' => $kaynak],
          $ozet . (trim((string)($n['plaka'] ?? '')) !== '' ? ' (' . $n['plaka'] . ')' : ''));
}

function sfUstlen(PDO $db, int $nakliyeId, array $kim): array {
    $pid = (int)($kim['personel_id'] ?? 0);
    $uid = (int)($kim['kullanici_id'] ?? 0);
    if ($pid < 1) throw new SfYetkiHatasi('Şoför olmak için hesabınız bir personel kaydına bağlı olmalı.');
    if (!_sfAktifPersonel($db, $pid) || !opYetkiVar($db, $uid > 0 ? 'kullanici' : 'personel', $uid > 0 ? $uid : $pid, 'saha'))
        throw new SfYetkiHatasi('Sahada teslim yetkiniz yok.');
    $n = _sfNakliye($db, $nakliyeId);
    _sfSubeDenetle($kim['kisit'] ?? null, _sfAracSubesi($db, $n));
    if (!_sfBizimGidisMi($n)) throw new DomainException('Şoför yalnız bizim aracımızın gidişinde üstlenilir.');
    if (_sfYuklendiMi($db, $nakliyeId)) throw new DomainException('Araç yüklendi; şoför yüklemede seçilen ekipten belirlendi.');
    $k = _sfAracinIsi($db, $n);
    if (!$k) throw new DomainException('Bu iş henüz üstlenilemez: çıkış kontrolü bitmeli ve sıradaki adım yükleme olmalı.');
    $tarih = _sfIsTarihi($n, $k);
    if (!sfUstlenmeTarihiUygun($tarih))
        throw new DomainException('Bu işin tarihi ' . $tarih . '; en erken bir gün önceden üstlenilebilir.');
    $sp = (string)(($n['siparis_no'] ?? '') ?: ($k['siparis_no'] ?? '')) ?: null;
    $u = $db->prepare("UPDATE nakliyeler SET sofor_personel_id=?, sofor_zaman=?, sofor_yapan=?, sofor_kaynak='ustlenme', sofor_siparis_no=?,
                              sofor_birakan_id=NULL, sofor_birak_zaman=NULL
                       WHERE id=? AND sofor_personel_id IS NULL");
    $u->execute([$pid, date('Y-m-d H:i:s'), $uid ?: null, $sp, $nakliyeId]);
    if ($u->rowCount() === 0) {
        $simdi = _sfNakliye($db, $nakliyeId);
        $mevcut = $simdi['sofor_personel_id'] !== null ? (int)$simdi['sofor_personel_id'] : null;
        if ($mevcut === $pid) return ['nakliye_id' => $nakliyeId, 'sofor_personel_id' => $pid, 'sofor_ad' => _sfPersonelAd($db, $pid)];
        throw new DomainException('Bu işin şoförü zaten var: ' . _sfPersonelAd($db, $mevcut));
    }
    $ad = _sfPersonelAd($db, $pid);
    _sfLogla($db, $n, $pid, 'ustlenme', 'Şoför işi üstlendi: ' . $ad);
    return ['nakliye_id' => $nakliyeId, 'sofor_personel_id' => $pid, 'sofor_ad' => $ad];
}

function sfBirak(PDO $db, int $nakliyeId, array $kim): void {
    $pid = (int)($kim['personel_id'] ?? 0);
    $n = _sfNakliye($db, $nakliyeId);
    _sfSubeDenetle($kim['kisit'] ?? null, _sfAracSubesi($db, $n));
    if ($n['sofor_personel_id'] === null) return;
    if ($pid < 1 || (int)$n['sofor_personel_id'] !== $pid) throw new SfYetkiHatasi('Bu işi yalnız üstlenen kişi bırakabilir.');
    if (($n['sofor_kaynak'] ?? '') !== 'ustlenme') throw new DomainException('Bu işin şoförünü iş dağıtımı belirledi; değişiklik için ofise başvurun.');
    if (_sfYuklendiMi($db, $nakliyeId)) throw new DomainException('Araç yüklendi; şoförlük artık bırakılamaz.');
    $u = $db->prepare("UPDATE nakliyeler SET sofor_personel_id=NULL, sofor_zaman=NULL, sofor_yapan=NULL, sofor_kaynak=NULL, sofor_siparis_no=NULL,
                              sofor_birakan_id=?, sofor_birak_zaman=?
                       WHERE id=? AND sofor_personel_id=? AND sofor_kaynak='ustlenme'");
    $u->execute([$pid, date('Y-m-d H:i:s'), $nakliyeId, $pid]);
    if ($u->rowCount() > 0) _sfLogla($db, $n, null, null, 'Şoför üstlendiği işi bıraktı: ' . _sfPersonelAd($db, $pid));
}

function sfAta(PDO $db, int $nakliyeId, ?int $personelId, array $kim): array {
    $n = _sfNakliye($db, $nakliyeId);
    _sfSubeDenetle($kim['kisit'] ?? null, _sfAracSubesi($db, $n));
    if (!_sfBizimGidisMi($n)) throw new DomainException('Şoför yalnız bizim aracımızın gidişine atanır.');
    if (_sfYuklendiMi($db, $nakliyeId)) throw new DomainException('Araç yüklendi; şoför yüklemede seçilen ekipten belirlendi.');
    if ($personelId !== null && $personelId > 0) {
        if (!_sfAktifPersonel($db, $personelId)) throw new DomainException('Seçilen personel aktif değil.');
        if (!opYetkiVar($db, 'personel', $personelId, 'saha')) throw new DomainException('Seçilen personelin "Sahada teslim" yetkisi yok.');
        $h = $db->prepare('SELECT rol, sube FROM users WHERE personel_id=? ORDER BY id LIMIT 1');
        $h->execute([$personelId]);
        $hesap = $h->fetch(PDO::FETCH_ASSOC);
        $hesapSube = $hesap && ($hesap['rol'] ?? '') !== 'admin' ? trim((string)($hesap['sube'] ?? '')) : '';
        if ($hesapSube !== '' && $hesapSube !== (string)_sfAracSubesi($db, $n)) throw new DomainException('Bu personel başka şubede; işi göremez.');
        $sp = (string)($n['siparis_no'] ?? '') ?: null;
        $db->prepare("UPDATE nakliyeler SET sofor_personel_id=?, sofor_zaman=?, sofor_yapan=?, sofor_kaynak='atama', sofor_siparis_no=?,
                             sofor_birakan_id=NULL, sofor_birak_zaman=NULL WHERE id=?")
           ->execute([$personelId, date('Y-m-d H:i:s'), ((int)($kim['kullanici_id'] ?? 0)) ?: null, $sp, $nakliyeId]);
        $ad = _sfPersonelAd($db, $personelId);
        _sfLogla($db, $n, $personelId, 'atama', 'Şoför atandı: ' . $ad);
        return ['nakliye_id' => $nakliyeId, 'sofor_personel_id' => $personelId, 'sofor_ad' => $ad];
    }
    if ($n['sofor_personel_id'] !== null) {
        $db->prepare('UPDATE nakliyeler SET sofor_personel_id=NULL, sofor_zaman=NULL, sofor_yapan=NULL, sofor_kaynak=NULL, sofor_siparis_no=NULL WHERE id=?')
           ->execute([$nakliyeId]);
        _sfLogla($db, $n, null, null, 'Şoför kaldırıldı');
    }
    return ['nakliye_id' => $nakliyeId, 'sofor_personel_id' => null, 'sofor_ad' => null];
}

function sfTemizle(PDO $db, int $nakliyeId, string $neden, int $kullaniciId, bool $dustu = false): void {
    $s = $db->prepare('SELECT * FROM nakliyeler WHERE id=?');
    $s->execute([$nakliyeId]);
    $n = $s->fetch(PDO::FETCH_ASSOC);
    if (!$n || $n['sofor_personel_id'] === null) return;
    $db->prepare('UPDATE nakliyeler SET sofor_personel_id=NULL, sofor_zaman=NULL, sofor_yapan=NULL, sofor_kaynak=NULL, sofor_siparis_no=NULL'
                 . ($dustu ? ', sofor_dusen_id=sofor_personel_id' : '') . ' WHERE id=?')
       ->execute([$nakliyeId]);
    _sfLogla($db, $n, null, null, 'Şoför kaydı silindi: ' . $neden . ($kullaniciId > 0 ? '' : ' (sistem)'));
}

function sfDegisirseTemizle(PDO $db, array $eski, int $nakliyeId, string $neden, int $kullaniciId): void {
    $s = $db->prepare('SELECT kiralama_id, siparis_no, tip, yon FROM nakliyeler WHERE id=?');
    $s->execute([$nakliyeId]);
    $yeni = $s->fetch(PDO::FETCH_ASSOC);
    if (!$yeni) return;
    $iz = fn(array $r): array => [(int)($r['kiralama_id'] ?? 0), trim((string)($r['siparis_no'] ?? '')),
                                 ($r['tip'] ?? '') === 'avk' ? 'avk' : 'dis',
                                 in_array((string)(($r['yon'] ?? null) ?: 'gidis'), SF_GIDIS_YONLERI, true) ? 'gidis' : (string)$r['yon']];
    if ($iz($eski) !== $iz($yeni)) sfTemizle($db, $nakliyeId, $neden, $kullaniciId);
}

function sfYuklemeEsitle(PDO $db, int $nakliyeId, array $ekipIdleri, bool $dis, int $kullaniciId): void {
    if ($nakliyeId < 1) return;
    $s = $db->prepare('SELECT * FROM nakliyeler WHERE id=?');
    $s->execute([$nakliyeId]);
    $n = $s->fetch(PDO::FETCH_ASSOC);
    if (!$n) return;
    $ekip = array_values(array_filter(array_map('intval', $ekipIdleri), fn($i) => $i > 0));
    if (!$dis) {
        $me = $db->prepare('SELECT personel_id FROM nakliye_ekip WHERE nakliye_id=? ORDER BY rowid');
        $me->execute([$nakliyeId]);
        $ekip = array_values(array_unique(array_merge($ekip, array_map('intval', $me->fetchAll(PDO::FETCH_COLUMN)))));
    }
    $mevcut = $n['sofor_personel_id'] !== null ? (int)$n['sofor_personel_id'] : null;
    if (!$dis && $mevcut !== null && in_array($mevcut, $ekip, true)) return;
    if ($dis || !$ekip) {
        if ($mevcut === null) return;
        sfTemizle($db, $nakliyeId, $dis ? 'yüklemede dış nakliye şoförü seçildi' : 'yüklemede ekip seçilmedi', $kullaniciId, true);
        return;
    }
    $yeni = $ekip[0];
    $db->prepare("UPDATE nakliyeler SET sofor_personel_id=?, sofor_zaman=?, sofor_yapan=?, sofor_kaynak='yukleme', sofor_siparis_no=?,
                         sofor_birakan_id=NULL, sofor_birak_zaman=NULL" . ($mevcut !== null ? ', sofor_dusen_id=?' : '') . ' WHERE id=?')
       ->execute(array_merge([$yeni, date('Y-m-d H:i:s'), $kullaniciId ?: null, (string)($n['siparis_no'] ?? '') ?: null],
                             $mevcut !== null ? [$mevcut] : [], [$nakliyeId]));
    _sfLogla($db, $n, $yeni, 'yukleme', 'Şoför yüklemede belirlendi: ' . _sfPersonelAd($db, $yeni)
             . ($mevcut !== null ? ' (önceki şoför yükleme ekibinde seçilmedi)' : ''));
}

function _sfDepodaMi(PDO $db, array $k): bool {
    return in_array(opSiradakiAdim($db, $k), ['depo_hazirlik', 'yukleme'], true);
}

function sfBaskaAracaYuklendi(PDO $db, array $kayitlar, int $nakliyeId, int $kullaniciId): void {
    $araclar = [];
    foreach ($kayitlar as $k) {
        $sp = trim((string)($k['siparis_no'] ?? ''));
        $q = $sp !== ''
            ? $db->prepare("SELECT * FROM nakliyeler WHERE siparis_no=? AND tip='avk' AND sofor_personel_id IS NOT NULL ORDER BY id")
            : $db->prepare("SELECT * FROM nakliyeler WHERE (kiralama_id=? OR id IN (SELECT nakliye_id FROM nakliye_makineleri WHERE kiralama_id=?))
                            AND tip='avk' AND sofor_personel_id IS NOT NULL ORDER BY id");
        $q->execute($sp !== '' ? [$sp] : [(int)$k['id'], (int)$k['id']]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $n) $araclar[(int)$n['id']] = $n;
    }
    foreach ($araclar as $id => $n) {
        if ($id === $nakliyeId || !_sfBizimGidisMi($n) || _sfYuklendiMi($db, $id)) continue;
        foreach (_sfAracKiralamalari($db, $n) as $k) if (_sfDepodaMi($db, $k)) continue 2;
        sfTemizle($db, $id, 'makineler başka araçla yüklendi', $kullaniciId, true);
    }
}

function sfTeslimEngeli(PDO $db, int $kiralamaId, int $personelId): ?string {
    if ($personelId < 1) return null;
    $s = $db->prepare("SELECT nm.nakliye_id FROM nakliye_makineleri nm JOIN nakliyeler n ON n.id=nm.nakliye_id
                       WHERE nm.kiralama_id=? AND nm.yuklendi_zaman IS NOT NULL AND COALESCE(n.yon,'gidis') IN ('gidis','gidis_donus','tek')");
    $s->execute([$kiralamaId]);
    $araclar = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    if (!$araclar) return null;
    $in = implode(',', array_fill(0, count($araclar), '?'));
    $e = $db->prepare("SELECT 1 FROM nakliye_ekip WHERE personel_id=? AND nakliye_id IN ($in) LIMIT 1");
    $e->execute(array_merge([$personelId], $araclar));
    if ($e->fetchColumn()) return null;
    $k = $db->prepare('SELECT siparis_no FROM kiralamalar WHERE id=?');
    $k->execute([$kiralamaId]);
    $sp = trim((string)$k->fetchColumn());
    $d = $db->prepare("SELECT 1 FROM nakliyeler WHERE sofor_dusen_id=? AND (id IN ($in) OR kiralama_id=?"
                      . ($sp !== '' ? ' OR siparis_no=?' : '') . ') LIMIT 1');
    $d->execute(array_merge([$personelId], $araclar, [$kiralamaId], $sp !== '' ? [$sp] : []));
    return $d->fetchColumn() ? 'bu makine yüklemede başka bir teslim ekibine verildi; teslimi o ekip yapar.' : null;
}

function sfIletisim(PDO $db, int $kiralamaId): array {
    $s = $db->prepare('SELECT k.saha_irtibat_ad, k.saha_irtibat_tel, k.saha_konum, k.lokasyon, k.il,
                              m.unvan AS musteri_unvan, m.yetkili AS musteri_yetkili, m.telefon AS musteri_telefon
                       FROM kiralamalar k JOIN musteriler m ON m.id=k.musteri_id WHERE k.id=?');
    $s->execute([$kiralamaId]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new DomainException('Kiralama bulunamadı.');
    try {
        $i = hkIrtibat($r);
    } catch (DomainException $e) {
        error_log('sfIletisim: kiralama #' . $kiralamaId . ' kayıtlı konum geçersiz, lokasyon + il aramasına dönüldü');
        $i = hkIrtibat(['saha_konum' => null] + $r);
    }
    return $i + ['musteri_unvan' => (string)$r['musteri_unvan'], 'lokasyon' => (string)($r['lokasyon'] ?? ''), 'il' => (string)($r['il'] ?? '')];
}

function sfPlanliIsler(PDO $db, int $personelId, ?string $kisit): array {
    if ($personelId < 1) return [];
    $s = $db->prepare("SELECT * FROM nakliyeler n WHERE n.sofor_personel_id=? AND n.tip='avk' AND COALESCE(n.yon,'gidis') IN ('gidis','gidis_donus','tek')
                         AND NOT EXISTS (SELECT 1 FROM nakliye_makineleri nm WHERE nm.nakliye_id=n.id AND nm.yuklendi_zaman IS NOT NULL)
                       ORDER BY n.tarih, n.id");
    $s->execute([$personelId]);
    $out = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $n) {
        $ks = _sfAracKiralamalari($db, $n);
        if (!$ks) continue;
        if ($kisit !== null && trim((string)($ks[0]['sube'] ?? '')) !== $kisit) continue;
        if (!array_filter($ks, fn($k) => _sfDepodaMi($db, $k))) continue;
        $bilgi = makineBilgiToplu($db, array_map(fn($k) => ['tur' => $k['makine_tur'], 'id' => (int)$k['makine_id']], $ks));
        $mk = [];
        foreach ($ks as $k) {
            $b = $bilgi[$k['makine_tur'] . ':' . (int)$k['makine_id']] ?? [];
            $mk[] = ['kod' => $b['kod'] ?? null, 'marka' => $b['marka'] ?? null, 'model' => $b['model'] ?? null, 'kategori' => $b['kategori'] ?? null,
                     'seri_no' => $b['seri_no'] ?? null, 'metraj' => $b['metraj'] ?? null, 'olcu_birim' => $b['olcu_birim'] ?? null,
                     'makine_kodu' => $b['makine_kodu'] ?? null];
        }
        $il = sfIletisim($db, (int)$ks[0]['id']);
        $out[] = ['nakliye_id' => (int)$n['id'], 'siparis_no' => (string)(($n['siparis_no'] ?? '') ?: ($ks[0]['siparis_no'] ?? '')),
                  'tarih' => _sfIsTarihi($n, $ks[0]), 'plaka' => (string)($n['plaka'] ?? ''), 'musteri_unvan' => $il['musteri_unvan'],
                  'lokasyon' => $il['lokasyon'], 'il' => $il['il'], 'iletisim' => $il, 'makineler' => $mk,
                  'sofor_kaynak' => $n['sofor_kaynak'] ?? null];
    }
    usort($out, fn(array $a, array $b): int => [(string)$a['tarih'], $a['nakliye_id']] <=> [(string)$b['tarih'], $b['nakliye_id']]);
    return array_slice($out, 0, 30);
}
