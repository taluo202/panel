<?php
declare(strict_types=1);
require_once __DIR__ . '/operasyon-adim.php';
require_once __DIR__ . '/tutanak_kurallari.php';
require_once __DIR__ . '/akis-onay.php';

const KO_SEBEP_MIN = 10;
const KO_NOT_MIN   = 5;
const KO_UCRET_SEKILLERI = ['gunluk' => 'Mevcut birim ücretle devam', 'sabit' => 'Uzatma için tek tutar', 'ucretsiz' => 'Ücretsiz'];
const KO_KARARLAR = ['yolda_beklet' => 'Yolda bekletilir', 'depoya_doner' => 'Geri döner (boşa giden sefer)', 'yine_de_teslim' => 'Yine de teslim edilir'];
const KO_HESAPLANAN_KALEMLER = ['kira', 'operator', 'nakliye'];

function koSema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS kiralama_uzatmalari (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        kiralama_id INTEGER NOT NULL REFERENCES kiralamalar(id) ON DELETE CASCADE,
        eski_bitis  TEXT,
        yeni_bitis  TEXT NOT NULL,
        ucret_sekli TEXT NOT NULL,              -- gunluk | sabit | ucretsiz
        tutar       REAL DEFAULT 0,             -- sabit: uzatma tutarı (KDV hariç, kiralamanın para birimi)
        doviz       TEXT,
        hakedis_id  INTEGER,                    -- sabit / ücretsiz: açılan bekleyen hakediş
        talep_id    INTEGER,                    -- portal uzatma talebi (varsa)
        sebep       TEXT,
        yapan       TEXT,
        zaman       TEXT NOT NULL DEFAULT (datetime('now','localtime')));
      CREATE INDEX IF NOT EXISTS idx_kir_uzatma ON kiralama_uzatmalari(kiralama_id);
      CREATE TABLE IF NOT EXISTS kiralama_tarih_tasimalari (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        kiralama_id      INTEGER NOT NULL REFERENCES kiralamalar(id) ON DELETE CASCADE,
        alan             TEXT NOT NULL,         -- baslangic | bitis | iptal
        eski_tarih       TEXT,
        yeni_tarih       TEXT,
        sebep            TEXT,
        musteri_kaynakli INTEGER DEFAULT 0,
        karar            TEXT,                  -- yolda_beklet | depoya_doner | yine_de_teslim
        sefer_tutar      REAL DEFAULT 0,
        sefer_hakedis_id INTEGER,
        doviz            TEXT,
        yapan            TEXT,
        zaman            TEXT NOT NULL DEFAULT (datetime('now','localtime')));
      CREATE INDEX IF NOT EXISTS idx_kir_tasima ON kiralama_tarih_tasimalari(kiralama_id);");
}


function _koYapanAd(PDO $db): string {
    $y = opOturumYapan($db);
    return (string)($y['ad'] ?? '') ?: 'kullanıcı';
}

function _koTarih(?string $t): string { return $t ? tarihGoster($t) : '—'; }

function _koGunEkle(string $tarih, int $gun): string { return date('Y-m-d', strtotime($tarih . ' ' . ($gun >= 0 ? '+' : '') . $gun . ' day')); }

function _koGunSayisi(string $bas, string $bit): int { return (int)(new DateTime($bas))->diff(new DateTime($bit))->days + 1; }

function _koMakineHam(PDO $db, array $k): ?string {
    if (!in_array($k['makine_tur'] ?? '', ['arac', 'manlift'], true) || empty($k['makine_id'])) return null;
    $s = $db->prepare('SELECT durum FROM ' . ($k['makine_tur'] === 'arac' ? 'araclar' : 'manliftler') . ' WHERE id=?');
    $s->execute([(int)$k['makine_id']]);
    $d = $s->fetchColumn();
    return $d === false ? null : (string)$d;
}

function _koMakineYaz(PDO $db, array $k, string $yeni, array $beklenen): bool {
    $ham = _koMakineHam($db, $k);
    if ($ham === null || !in_array($ham, $beklenen, true) || in_array($ham, ['satildi', 'pasif'], true)) return false;
    if (makineAcikServisVarMi($db, (string)$k['makine_tur'], (int)$k['makine_id'])) return false;
    $db->prepare('UPDATE ' . ($k['makine_tur'] === 'arac' ? 'araclar' : 'manliftler') . ' SET durum=? WHERE id=?')->execute([$yeni, (int)$k['makine_id']]);
    akisDenetimYaz($db, $k, ['durum' => $ham], ['durum' => $yeni], 'Geri alma: makine durumu ' . $ham . ' → ' . $yeni);
    return true;
}

function koHakedisOzet(PDO $db, int $kid): array {
    $st = $db->prepare("SELECT h.id, h.kod, h.durum, h.fatura_no, h.donem_bas, h.donem_bit, h.genel_toplam,
                               (SELECT COUNT(*) FROM tahsilatlar t WHERE t.hakedis_id=h.id) AS tahsilat
                        FROM hakedisler h WHERE h.kiralama_id=? ORDER BY h.donem_bas, h.id");
    $st->execute([$kid]);
    $o = ['toplam' => 0, 'beklemede' => 0, 'faturali' => 0, 'faturali_kodlar' => [], 'bekleyen' => []];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $h) {
        $o['toplam']++;
        if (koHakedisFaturali($h)) { $o['faturali']++; $o['faturali_kodlar'][] = $h['kod'] ?: ('#' . $h['id']); }
        else { $o['beklemede']++; $o['bekleyen'][] = $h; }
    }
    return $o;
}

function koHakedisFaturali(array $h): bool {
    return ($h['durum'] ?? 'beklemede') !== 'beklemede' || trim((string)($h['fatura_no'] ?? '')) !== '' || (int)($h['tahsilat'] ?? 0) > 0;
}

function koHakedisEtkiMetni(array $ozet, bool $yenidenAcma = false): string {
    if ($ozet['toplam'] === 0) $m = 'Bu kiralamanın hakedişi yok.';
    else $m = 'Bu kiralamanın ' . $ozet['toplam'] . ' hakedişi var: ' . $ozet['beklemede'] . ' beklemede, ' . $ozet['faturali'] . ' faturalı; '
            . 'faturalı hakedişlere dokunulmaz, bekleyenlerden dönem dışına düşenler yeniden hesaplanır.';
    if ($yenidenAcma) $m .= " 'Tamamlandı'dan dönüşte gerçek bitiş temizlenir; kiralama açık devam eder.";
    return $m;
}

function koBekleyenHakedisDuzelt(PDO $db, array $k, string $yeniBas, ?string $yeniBit): array {
    $kid = (int)$k['id'];
    $out = ['duzeltilen' => [], 'silinen' => [], 'uyari' => []];
    $ozet = koHakedisOzet($db, $kid);
    foreach ($ozet['bekleyen'] as $h) {
        $hid = (int)$h['id']; $kod = $h['kod'] ?: ('#' . $hid);
        $dBas = (string)($h['donem_bas'] ?? ''); $dBit = (string)($h['donem_bit'] ?? '');
        if ($dBas === '' || $dBit === '') continue;
        $yBit = $yeniBit ?: '9999-12-31';
        if ($dBas >= $yeniBas && $dBit <= $yBit) continue;
        $ozel = $db->prepare("SELECT COUNT(*) FROM hakedis_kalemleri WHERE hakedis_id=? AND tur NOT IN ('kira','operator','nakliye')");
        $ozel->execute([$hid]);
        if ((int)$ozel->fetchColumn() > 0) { $out['uyari'][] = $kod . ' elle kalem taşıyor (hasar/uzatma/sefer); dönemi elle kontrol edin.'; continue; }
        $kalemSay = $db->prepare("SELECT COUNT(*) FROM hakedis_kalemleri WHERE hakedis_id=?"); $kalemSay->execute([$hid]);
        if ((int)$kalemSay->fetchColumn() === 0) { $out['uyari'][] = $kod . ' elle girilmiş (kalemsiz) hakediş; tutarına dokunulmadı, dönemini elle kontrol edin.'; continue; }
        if ($dBit < $yeniBas || $dBas > $yBit) {
            $eski = logKayitAl($db, 'hakedisler', $hid);
            $db->prepare("UPDATE nakliyeler SET hakedis_id=NULL WHERE hakedis_id=?")->execute([$hid]);
            $db->prepare("DELETE FROM hakedis_kalemleri WHERE hakedis_id=?")->execute([$hid]);
            $db->prepare("DELETE FROM hakedisler WHERE id=?")->execute([$hid]);
            logla($db, 'hakedisler', $hid, 'sil', $eski, null, 'Bekleyen hakediş ' . $kod . ' kiralama dönemi dışına düştüğü için silindi');
            $out['silinen'][] = $kod;
            continue;
        }
        $nBas = max($dBas, $yeniBas); $nBit = min($dBit, $yBit);
        koHakedisYenidenHesapla($db, $k, $h, $nBas, $nBit);
        $out['duzeltilen'][] = $kod . ' (' . _koTarih($nBas) . ' – ' . _koTarih($nBit) . ')';
    }
    foreach ($ozet['faturali_kodlar'] as $fk) $out['uyari'][] = $fk . ' faturalı; dokunulmadı.';
    return $out;
}

function koHakedisYenidenHesapla(PDO $db, array $k, array $h, string $dBas, string $dBit): void {
    $hid = (int)$h['id'];
    $eski = logKayitAl($db, 'hakedisler', $hid) ?? [];
    $db->prepare("UPDATE nakliyeler SET hakedis_id=NULL WHERE hakedis_id=?")->execute([$hid]);
    $db->prepare("DELETE FROM hakedis_kalemleri WHERE hakedis_id=? AND tur IN ('kira','operator','nakliye')")->execute([$hid]);
    $hesap = hakedisKalemleriHesapla($db, $k, $dBas, $dBit, false);
    hakedisKalemleriYaz($db, $hid, $hesap);
    $d = $db->prepare("SELECT COALESCE(SUM(tutar),0) FROM hakedis_kalemleri WHERE hakedis_id=? AND tur NOT IN ('kira','operator','nakliye')");
    $d->execute([$hid]);
    $tutar = round($hesap['tutar'] + (float)$d->fetchColumn(), 2);
    $t = hakedisTutarHesapla($tutar, (float)($eski['kdv_oran'] ?? 20), !empty($eski['tevkifat_var']), $eski['tevkifat_orani'] ?? null);
    $db->prepare("UPDATE hakedisler SET donem_bas=?, donem_bit=?, tutar=?, kdv_tutar=?, genel_toplam=?, tevkifat_tutari=?, musteriden_tahsil_kdv=? WHERE id=?")
       ->execute([$dBas, $dBit, $tutar, $t['kdv_tutar'], $t['genel_toplam'], $t['tevkifat_tutari'], $t['musteriden_tahsil_kdv'], $hid]);
    logla($db, 'hakedisler', $hid, 'guncelle', $eski, logKayitAl($db, 'hakedisler', $hid),
          'Bekleyen hakediş ' . ($eski['kod'] ?? ('#' . $hid)) . ' dönemi kiralama tarihine göre düzeltildi: ' . _koTarih($dBas) . ' – ' . _koTarih($dBit));
}

function _koTutarOku($v, string $alan): float {
    if ($v === null || $v === '') return 0.0;
    if (!is_numeric($v)) throw new DomainException($alan . ' sayısal olmalı.');
    $t = (float)$v;
    if (!is_finite($t) || $t < 0 || $t > 1000000000) throw new DomainException($alan . ' geçersiz (0 ile 1.000.000.000 arasında olmalı).');
    return round($t, 2);
}

function koHakedisAc(PDO $db, array $k, ?string $dBas, ?string $dBit, string $kalemTur, string $aciklama, float $tutar, string $faturaTarihi): int {
    if (!is_finite($tutar) || $tutar > 1000000000) throw new DomainException('Hakediş tutarı geçersiz.');
    $tutar = round(max(0, $tutar), 2);
    $kdvOran = (float)($k['kdv_oran'] ?? 20);
    $kdvTutar = round($tutar * $kdvOran / 100, 2);
    $db->prepare("INSERT INTO hakedisler (kiralama_id, musteri_id, donem_bas, donem_bit, aciklama, tutar, kdv_oran, kdv_tutar, genel_toplam,
                                          fatura_tarihi, vade_tarihi, sube, temsilci, durum)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'beklemede')")
       ->execute([(int)$k['id'], (int)$k['musteri_id'], $dBas, $dBit, $aciklama, $tutar, $kdvOran, $kdvTutar, round($tutar + $kdvTutar, 2),
                  $faturaTarihi, _koGunEkle($faturaTarihi, 15), $k['sube'] ?? null, $k['temsilci'] ?? null]);
    $hid = (int)$db->lastInsertId();
    hakedisKodUret($db, $hid, $faturaTarihi);
    $db->prepare("INSERT INTO hakedis_kalemleri (hakedis_id, tur, aciklama, miktar, birim, tutar) VALUES (?,?,?,1,?,?)")
       ->execute([$hid, $kalemTur, $aciklama, $tutar, $tutar]);
    logla($db, 'hakedisler', $hid, 'ekle', null, logKayitAl($db, 'hakedisler', $hid),
          'Hakediş açıldı (' . $kalemTur . '): ' . $aciklama . ' · ' . number_format($tutar, 2, ',', '.') . ' ' . ($k['doviz'] ?? 'TL'));
    return $hid;
}

function koGecmis(PDO $db, int $kid, bool $tutarGorur, ?bool $uzatmaGorur = null): array {
    $uzatmaGorur = $uzatmaGorur ?? $tutarGorur;
    $u = $db->prepare("SELECT * FROM kiralama_uzatmalari WHERE kiralama_id=? ORDER BY id DESC");
    $u->execute([$kid]);
    $t = $db->prepare("SELECT * FROM kiralama_tarih_tasimalari WHERE kiralama_id=? ORDER BY id DESC");
    $t->execute([$kid]);
    $uz = $u->fetchAll(PDO::FETCH_ASSOC); $ts = $t->fetchAll(PDO::FETCH_ASSOC);
    foreach ($uz as &$r) { $r['ucret_sekli_etiket'] = KO_UCRET_SEKILLERI[$r['ucret_sekli']] ?? $r['ucret_sekli']; if (!$uzatmaGorur) unset($r['tutar']); }
    unset($r);
    foreach ($ts as &$r) { $r['karar_etiket'] = $r['karar'] ? (KO_KARARLAR[$r['karar']] ?? $r['karar']) : null; if (!$tutarGorur) unset($r['sefer_tutar']); }
    unset($r);
    return ['uzatmalar' => $uz, 'tarih_tasimalari' => $ts];
}


function koGeriAlSecenekleri(PDO $db, array $k): array {
    if (($k['durum'] ?? '') === 'iptal') return [];
    $kid = (int)$k['id'];
    $kapali = ($k['durum'] ?? '') === 'tamamlandi' || !empty($k['gercek_bitis']);
    $makineli = !empty($k['makine_id']);
    $guncel = $makineli ? operasyonKiralamaGuncelMi($db, $k, true) : true;
    $ozet = koHakedisOzet($db, $kid);
    $etkiH = koHakedisEtkiMetni($ozet, false);
    $etkiAc = koHakedisEtkiMetni($ozet, true);
    $gecikti = !$guncel ? 'Makine bu kiralamadan sonra başka bir işe çıktı; bu kaydın adımı geri alınamaz.' : null;
    $out = [];
    $ekle = function (string $hedef, string $etiket, string $sonra, ?string $engel, string $yetki, array $etkiler) use (&$out, $db) {
        $out[] = ['hedef' => $hedef, 'etiket' => $etiket, 'sonra' => $sonra, 'engel' => $engel, 'yetki' => $yetki,
                  'yetkili' => $yetki === 'yaz' || opOturumYetkisi($db, 'atlama'), 'etkiler' => $etkiler];
    };
    if (opYeniZincir($db, $k)) {
        $d = opAdimDurumu($db, $kid);
        if (isset($d['saha_teslim_alma'])) {
            $engel = $gecikti;
            if (($d['depo_inceleme']['sonuc'] ?? null) === 'tamam') $engel = 'Dönüş kontrolü tamamlandı (makine müsait/servise ayrıldı); teslim alma geri alınamaz.';
            elseif (($d['depo_inceleme']['sonuc'] ?? null) === 'atlandi') {
                try { opGeriAlKontrol($db, $kid, 'depo_inceleme'); } catch (DomainException $e) { $engel = $e->getMessage(); }
            }
            $ekle('saha_teslim_alma', 'Teslim alındı / Tamamlandı', 'Sahada (kiralama yeniden açılır)', $engel, 'atlama',
                  ['Teslim alma adımı günlükte geri alınır; iade belgesi silinmez.', 'Kiralama yeniden AKTİF olur.', $etkiAc,
                   'Makine (güncel işse) dönüş kontrolü / bakım beklemesinden çıkıp yeniden kirada görünür.']);
        } elseif (isset($d['toplaniyor'])) {
            $ekle('toplaniyor', 'Toplanıyor', 'Sahada (kirada)', $gecikti, 'atlama',
                  ['Toplanıyor adımı günlükte geri alınır; araçtaki toplama işareti kaldırılır.', 'Makine yeniden kirada görünür.', $etkiH]);
        } elseif (isset($d['saha_teslim'])) {
            $ekle('saha_teslim', 'Sahaya teslim', 'Yolda (nakliyede)', $gecikti, 'atlama',
                  ['Sahada teslim adımı günlükte geri alınır; teslim tutanağı belge olarak kalır.', 'Makine yeniden "nakliyede" olur.', $etkiH]);
        } elseif (isset($d['yukleme'])) {
            $ekle('yukleme', 'Yola çıktı', 'Çıkış kontrolü tamam', $gecikti, 'atlama',
                  ['Yükleme adımı günlükte geri alınır; araçtaki yükleme işareti kaldırılır (Yükleme / Toplama yeniden gösterir).', 'Makine "kiraya gidecek" olur.', $etkiH]);
        }
        if ($kapali && !isset($d['saha_teslim_alma'])) {
            $ekle('tamamlandi', 'Tamamlandı', 'Aktif', $gecikti, 'atlama', ['Kiralama yeniden AKTİF olur.', $etkiAc]);
        }
    } else {
        $ham = $makineli ? _koMakineHam($db, $k) : null;
        if ($kapali) {
            $ekle('tamamlandi', 'Tamamlandı', 'Aktif', $gecikti, 'atlama',
                  ['Kiralama yeniden AKTİF olur.', $etkiAc, $ham && in_array($ham, ['bakim_bekliyor', 'kiradan_gelecek'], true) ? 'Makine yeniden kirada görünür.' : 'Makine durumu değişmez.']);
        } elseif ($makineli && $ham !== null) {
            $tablo = [
                'bakimda'         => ['bakima',     'Bakıma alındı',        'Bakım bekliyor',    'bakim_bekliyor'],
                'bakim_bekliyor'  => ['iade',       'Teslim alındı',        'Sahada (kirada)',   'musait'],
                'kiradan_gelecek' => ['toplaniyor', 'Toplanıyor',           'Sahada (kirada)',   'musait'],
                'nakliyede'       => ['nakliye',    'Yola çıktı',           'Kiraya gidecek',    'kiraya_gidecek'],
                'musait'          => ['teslim',     'Sahaya teslim edildi', 'Yolda (nakliyede)', 'nakliyede'],
            ];
            if (isset($tablo[$ham]) && !($ham === 'musait' && (string)$k['baslangic'] > date('Y-m-d'))) {
                [$hedef, $etiket, $sonra, $yeniHam] = $tablo[$ham];
                $ekle($hedef, $etiket, $sonra, $gecikti, 'yaz', ['Makine durumu "' . $yeniHam . '" olur; kiralama kaydı ve tutarı değişmez.', $etkiH]);
            }
        }
    }
    return $out;
}

function koGeriAl(PDO $db, array $k, string $hedef, string $sebep): array {
    $sebep = trim($sebep);
    if (mb_strlen($sebep) < KO_SEBEP_MIN) throw new DomainException('Geri alma sebebi zorunludur (en az ' . KO_SEBEP_MIN . ' karakter).');
    $sec = null;
    foreach (koGeriAlSecenekleri($db, $k) as $s) if ($s['hedef'] === $hedef) $sec = $s;
    if (!$sec) throw new DomainException('Bu kiralamada geri alınabilecek böyle bir adım yok. Listeyi yenileyin.');
    if ($sec['engel']) throw new DomainException($sec['engel']);
    if (!$sec['yetkili']) throw new DomainException('Bu geri alma için "Adım atlama" yetkisi gerekir; yöneticinize başvurun.');
    $kid = (int)$k['id'];
    $yapan = opOturumYapan($db);
    $eski = logKayitAl($db, 'kiralamalar', $kid);
    $yenidenAc = in_array($hedef, ['saha_teslim_alma', 'tamamlandi'], true) || ($hedef === 'iade' && (($k['durum'] ?? '') === 'tamamlandi' || !empty($k['gercek_bitis'])));
    $duzeltme = ['duzeltilen' => [], 'silinen' => [], 'uyari' => []];
    if ($yenidenAc) {
        if (!empty($k['makine_id'])) {
            $c = kiralamaCakismalari($db, (string)$k['makine_tur'], (int)$k['makine_id'], (string)$k['baslangic'], $k['bitis'] ?: null, $kid);
            if ($c) throw new DomainException('Yeniden açılan kiralama başka bir rezervasyonla çakışıyor: ' . ($c[0]['kod'] ?: '#') . ' — ' . $c[0]['musteri_unvan'] . '. Önce o kaydı düzenleyin.');
        }
        $db->prepare("UPDATE kiralamalar SET durum='aktif', gercek_bitis=NULL, updated_at=datetime('now','localtime') WHERE id=?")->execute([$kid]);
        $kAcik = $k; $kAcik['durum'] = 'aktif'; $kAcik['gercek_bitis'] = null;
        $duzeltme = koBekleyenHakedisDuzelt($db, $kAcik, (string)$k['baslangic'], $k['bitis'] ?: null);
    }
    $guncel = !empty($k['makine_id']) && operasyonKiralamaGuncelMi($db, $k, true);
    switch ($hedef) {
        case 'yukleme':
            opAdimYaz($db, $kid, 'yukleme', 'geri_alindi', $yapan, 'yonetici', $sebep);
            $db->prepare("UPDATE nakliye_makineleri SET yuklendi_zaman=NULL, yukleyen=NULL WHERE kiralama_id=? AND yuklendi_zaman IS NOT NULL")->execute([$kid]);
            if ($guncel) _koMakineYaz($db, $k, 'kiraya_gidecek', ['nakliyede']);
            break;
        case 'saha_teslim':
            opAdimYaz($db, $kid, 'saha_teslim', 'geri_alindi', $yapan, 'yonetici', $sebep);
            if ($guncel) _koMakineYaz($db, $k, 'nakliyede', ['musait']);
            break;
        case 'toplaniyor':
            opAdimYaz($db, $kid, 'toplaniyor', 'geri_alindi', $yapan, 'yonetici', $sebep);
            $db->prepare("UPDATE nakliye_makineleri SET toplama_zaman=NULL, toplayan=NULL WHERE kiralama_id=? AND toplama_zaman IS NOT NULL")->execute([$kid]);
            if ($guncel) _koMakineYaz($db, $k, 'musait', ['kiradan_gelecek']);
            break;
        case 'saha_teslim_alma':
            $d = opAdimDurumu($db, $kid);
            if (($d['depo_inceleme']['sonuc'] ?? null) === 'atlandi') opAdimYaz($db, $kid, 'depo_inceleme', 'geri_alindi', $yapan, 'yonetici', $sebep);
            opAdimYaz($db, $kid, 'saha_teslim_alma', 'geri_alindi', $yapan, 'yonetici', $sebep);
            if ($guncel) _koMakineYaz($db, $k, 'musait', ['inceleme_bekliyor', 'bakim_bekliyor', 'kiradan_gelecek']);
            break;
        case 'tamamlandi':
            if (opYeniZincir($db, $k)) akisYenidenAcAdimlari($db, $k, $sebep);
            if ($guncel) _koMakineYaz($db, $k, 'musait', ['inceleme_bekliyor', 'bakim_bekliyor', 'kiradan_gelecek']);
            break;
        case 'bakima':     if ($guncel) _koMakineYaz($db, $k, 'bakim_bekliyor', ['bakimda']); break;
        case 'iade':       if ($guncel) _koMakineYaz($db, $k, 'musait', ['bakim_bekliyor']); break;
        case 'nakliye':    if ($guncel) _koMakineYaz($db, $k, 'kiraya_gidecek', ['nakliyede']); break;
        case 'teslim':     if ($guncel) _koMakineYaz($db, $k, 'nakliyede', ['musait']); break;
        default: throw new DomainException('Geçersiz geri alma hedefi.');
    }
    if (!empty($k['siparis_no'])) siparisNakliyeSenkron($db, (string)$k['siparis_no']);
    $ozetMetin = 'Durum geri alındı: ' . $sec['etiket'] . ' → ' . $sec['sonra'] . ' — sebep: ' . $sebep;
    logla($db, 'kiralamalar', $kid, 'guncelle', $eski, logKayitAl($db, 'kiralamalar', $kid) ?? $eski, $ozetMetin);
    if ($yenidenAc) akisDenetimYaz($db, $k, ['durum' => $k['durum'], 'gercek_bitis' => $k['gercek_bitis']], ['durum' => 'aktif', 'gercek_bitis' => null],
                                   'Geri alma: kiralama yeniden açıldı (gerçek bitiş temizlendi) — sebep: ' . $sebep);
    return ['hedef' => $hedef, 'etiket' => $sec['etiket'], 'sonra' => $sec['sonra'], 'yeniden_acildi' => $yenidenAc, 'hakedis' => $duzeltme, 'mesaj' => $ozetMetin];
}


function koUzatBilgi(PDO $db, array $k, bool $tutarGorur): array {
    $talep = null;
    try {
        $t = $db->prepare("SELECT id, istenen_tarih, aciklama, created_at FROM portal_kiralama_talepleri WHERE kiralama_id=? AND tur='uzatma' AND durum='bekliyor' ORDER BY id DESC LIMIT 1");
        $t->execute([(int)$k['id']]);
        $talep = $t->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) { $talep = null; }
    $out = ['id' => (int)$k['id'], 'kod' => $k['kod'], 'baslangic' => $k['baslangic'], 'bitis' => $k['bitis'], 'kira_tipi' => $k['kira_tipi'] ?: 'gunluk',
            'doviz' => $k['doviz'] ?: 'TL', 'uzatilabilir' => ($k['durum'] ?? '') === 'aktif' && empty($k['gercek_bitis']) && !empty($k['bitis']),
            'ucret_sekilleri' => KO_UCRET_SEKILLERI, 'portal_talep' => $talep, 'tutar_gorur' => $tutarGorur] + koGecmis($db, (int)$k['id'], $tutarGorur);
    if ($tutarGorur) { $out['birim_ucret'] = (float)($k['birim_ucret'] ?? 0); $out['miktar'] = max(1, (int)($k['miktar'] ?? 1)); }
    return $out;
}

function koUzat(PDO $db, array $k, array $g): array {
    if (($k['durum'] ?? '') !== 'aktif' || !empty($k['gercek_bitis'])) throw new DomainException('Yalnız devam eden (aktif, iade edilmemiş) kiralama uzatılabilir.');
    $eskiBitis = trim((string)($k['bitis'] ?? ''));
    if ($eskiBitis === '') throw new DomainException('Bu kiralama süresiz (bitiş tarihi yok); uzatma gerekmiyor. Bitiş vermek için Düzenle.');
    $yeniBitis = koUzatYeniBitis($eskiBitis, $g);
    $sekil = (string)($g['ucret_sekli'] ?? 'gunluk');
    if (!isset(KO_UCRET_SEKILLERI[$sekil])) throw new DomainException('Ücret şekli geçersiz (gunluk / sabit / ucretsiz).');
    $tutar = _koTutarOku($g['tutar'] ?? 0, 'Uzatma tutarı');
    $sebep = mb_substr(trim((string)($g['sebep'] ?? '')), 0, 1000);
    if ($sekil === 'sabit' && $tutar <= 0) throw new DomainException('Sabit uzatma için tutar girin (KDV hariç, ' . ($k['doviz'] ?: 'TL') . ').');
    if ($sekil === 'ucretsiz' && mb_strlen($sebep) < KO_NOT_MIN) throw new DomainException('Ücretsiz uzatmada sebep zorunludur (en az ' . KO_NOT_MIN . ' karakter).');
    if ($sekil !== 'sabit') $tutar = 0.0;
    $kid = (int)$k['id'];
    $eski = logKayitAl($db, 'kiralamalar', $kid);
    $u = $db->prepare("UPDATE kiralamalar SET bitis=?, updated_at=datetime('now','localtime') WHERE id=? AND durum='aktif' AND gercek_bitis IS NULL AND bitis=?");
    $u->execute([$yeniBitis, $kid, $eskiBitis]);
    if ($u->rowCount() !== 1) throw new DomainException('Kiralama bu sırada değişti; sayfayı yenileyip tekrar deneyin.');
    $dBas = _koGunEkle($eskiBitis, 1);
    $gun = _koGunSayisi($dBas, $yeniBitis);
    $hid = null;
    if ($sekil === 'sabit') {
        $hid = koHakedisAc($db, $k, $dBas, $yeniBitis, 'uzatma',
            'Uzatma — sabit ücret ' . _koTarih($dBas) . ' – ' . _koTarih($yeniBitis) . ' (' . $gun . ' gün)' . ($sebep !== '' ? ' · ' . $sebep : ''), $tutar, $yeniBitis);
    } elseif ($sekil === 'ucretsiz') {
        $hid = koHakedisAc($db, $k, $dBas, $yeniBitis, 'uzatma',
            'Ücretsiz uzatma ' . _koTarih($dBas) . ' – ' . _koTarih($yeniBitis) . ' (' . $gun . ' gün) · ' . $sebep, 0.0, $yeniBitis);
    }
    $talepId = (int)($g['talep_id'] ?? 0) ?: null;
    if ($talepId) {
        $t = $db->prepare("UPDATE portal_kiralama_talepleri SET durum='onaylandi', onaylanan_tarih=?, karar_veren=?, karar_notu=?, karar_tarihi=datetime('now','localtime')
                           WHERE id=? AND kiralama_id=? AND tur='uzatma' AND durum='bekliyor'");
        $t->execute([$yeniBitis, _koYapanAd($db), $sebep !== '' ? $sebep : null, $talepId, $kid]);
        if ($t->rowCount() === 1) {
            portalTalepGorevKapat($db, 'uzatma', $talepId);
            logla($db, 'portal_kiralama_talepleri', $talepId, 'guncelle', ['durum' => 'bekliyor'], ['durum' => 'onaylandi', 'onaylanan_tarih' => $yeniBitis],
                  'Süre uzatma talebi uzatma penceresinden onaylandı — ' . ($k['kod'] ?: ('#' . $kid)));
        } else $talepId = null;
    }
    $db->prepare("INSERT INTO kiralama_uzatmalari (kiralama_id, eski_bitis, yeni_bitis, ucret_sekli, tutar, doviz, hakedis_id, talep_id, sebep, yapan, zaman) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([$kid, $eskiBitis, $yeniBitis, $sekil, $tutar, $k['doviz'] ?: 'TL', $hid, $talepId, $sebep !== '' ? $sebep : null, _koYapanAd($db), date('Y-m-d H:i:s')]);
    $uzId = (int)$db->lastInsertId();
    if (!empty($k['siparis_no'])) siparisNakliyeSenkron($db, (string)$k['siparis_no']);
    $ozet = 'Uzatma: bitiş ' . _koTarih($eskiBitis) . ' → ' . _koTarih($yeniBitis) . ' (' . $gun . ' gün, ' . KO_UCRET_SEKILLERI[$sekil]
          . ($sekil === 'sabit' ? ' ' . number_format($tutar, 2, ',', '.') . ' ' . ($k['doviz'] ?: 'TL') : '') . ')' . ($sebep !== '' ? ' — ' . $sebep : '');
    logla($db, 'kiralamalar', $kid, 'guncelle', $eski, logKayitAl($db, 'kiralamalar', $kid), $ozet);
    return ['uzatma_id' => $uzId, 'eski_bitis' => $eskiBitis, 'yeni_bitis' => $yeniBitis, 'gun' => $gun, 'ucret_sekli' => $sekil, 'hakedis_id' => $hid, 'talep_id' => $talepId, 'mesaj' => $ozet];
}

function koUzatYeniBitis(string $eskiBitis, array $g): string {
    $yeni = dateStr('yeni_bitis', $g);
    if (!$yeni) {
        $gun = (int)($g['gun'] ?? 0);
        if ($gun < 1) throw new DomainException('Yeni bitiş tarihi ya da uzatma gün sayısı girin.');
        if ($gun > 3650) throw new DomainException('Uzatma en fazla 3650 gün olabilir.');
        $yeni = _koGunEkle($eskiBitis, $gun);
    }
    if ($yeni <= $eskiBitis) throw new DomainException('Yeni bitiş (' . _koTarih($yeni) . ') mevcut bitişten (' . _koTarih($eskiBitis) . ') sonra olmalı. Kısaltma için "Alım gününü taşı".');
    return $yeni;
}


function koMakineYolda(PDO $db, array $k): bool {
    if (empty($k['makine_id'])) return false;
    if (opYeniZincir($db, $k)) {
        $d = opAdimDurumu($db, (int)$k['id']);
        return isset($d['yukleme']) && !isset($d['saha_teslim']) && !isset($d['saha_teslim_alma']);
    }
    return _koMakineHam($db, $k) === 'nakliyede' && operasyonKiralamaGuncelMi($db, $k);
}

function koMakineSahada(PDO $db, array $k): bool {
    if (empty($k['makine_id'])) return false;
    if (opYeniZincir($db, $k)) {
        $d = opAdimDurumu($db, (int)$k['id']);
        return isset($d['saha_teslim']) || isset($d['toplaniyor']) || isset($d['saha_teslim_alma']);
    }
    if (!operasyonKiralamaGuncelMi($db, $k)) return false;
    $ham = _koMakineHam($db, $k);
    return in_array($ham, ['kiradan_gelecek', 'bakim_bekliyor', 'bakimda'], true) || ($ham === 'musait' && (string)$k['baslangic'] <= date('Y-m-d'));
}

function koSeferVarsayilan(PDO $db, array $k): float {
    if (strtoupper((string)($k['doviz'] ?: 'TL')) !== 'TL') return 0.0;
    try {
        $st = $db->prepare("SELECT deger FROM ayarlar WHERE anahtar = 'sefer_ucreti_varsayilan'");
        $st->execute();
        $v = (string)($st->fetchColumn() ?: '0');
    } catch (\Throwable $e) { $v = '0'; }
    return round(max(0, (float)str_replace(',', '.', $v)), 2);
}

function koTasiBilgi(PDO $db, array $k, bool $tutarGorur): array {
    $out = ['id' => (int)$k['id'], 'kod' => $k['kod'], 'baslangic' => $k['baslangic'], 'bitis' => $k['bitis'], 'doviz' => $k['doviz'] ?: 'TL',
            'tasinabilir' => ($k['durum'] ?? '') === 'aktif' && empty($k['gercek_bitis']),
            'yolda' => koMakineYolda($db, $k), 'sahada' => koMakineSahada($db, $k), 'kararlar' => KO_KARARLAR, 'tutar_gorur' => $tutarGorur,
            'nakliye_adet' => count(koKiralamaNakliyeleri($db, $k, null))] + koGecmis($db, (int)$k['id'], $tutarGorur, hasPermission('kiralama_takip', 'yaz'));
    if ($tutarGorur) $out['sefer_varsayilan'] = koSeferVarsayilan($db, $k);
    return $out;
}

function koKiralamaNakliyeleri(PDO $db, array $k, ?array $yonler): array {
    $kid = (int)$k['id'];
    $sip = trim((string)($k['siparis_no'] ?? ''));
    $st = $db->prepare("SELECT n.* FROM nakliyeler n WHERE n.kiralama_id=? OR n.id IN (SELECT nakliye_id FROM nakliye_makineleri WHERE kiralama_id=?)
                        OR (? <> '' AND n.siparis_no=?) ORDER BY n.tarih, n.id");
    $st->execute([$kid, $kid, $sip, $sip]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $n) {
        $yon = (string)($n['yon'] ?: 'gidis');
        if ($yonler !== null && !in_array($yon, $yonler, true)) continue;
        $b = $db->prepare('SELECT kiralama_id FROM nakliye_makineleri WHERE nakliye_id=?');
        $b->execute([(int)$n['id']]);
        $tasinan = array_map('intval', $b->fetchAll(PDO::FETCH_COLUMN));
        $n['paylasimli'] = (bool)array_filter($tasinan, fn($x) => $x !== $kid) || ($sip !== '' && !$tasinan && (int)$n['kiralama_id'] !== $kid);
        $out[] = $n;
    }
    return $out;
}

function koTarihTasi(PDO $db, array $k, array $g): array {
    if (($k['durum'] ?? '') !== 'aktif' || !empty($k['gercek_bitis'])) throw new DomainException('Yalnız devam eden (aktif, iade edilmemiş) kiralamanın günü taşınabilir.');
    $alan = (string)($g['alan'] ?? '');
    if (!in_array($alan, ['baslangic', 'bitis'], true)) throw new DomainException('Taşınacak gün: baslangic (teslim) ya da bitis (alım).');
    $yeni = dateStr('yeni_tarih', $g);
    if (!$yeni) throw new DomainException('Yeni tarihi seçin.');
    $sebep = mb_substr(trim((string)($g['sebep'] ?? '')), 0, 1000);
    if (mb_strlen($sebep) < KO_NOT_MIN) throw new DomainException('Taşıma sebebi zorunludur (en az ' . KO_NOT_MIN . ' karakter).');
    $musteri = !empty($g['musteri_kaynakli']);
    $eskiTarih = (string)($k[$alan] ?? '');
    if ($eskiTarih === '' && $alan === 'bitis') throw new DomainException('Kiralamanın bitişi yok (süresiz); önce Düzenle ile bitiş verin.');
    if ($yeni === $eskiTarih) throw new DomainException('Yeni tarih mevcut tarihle aynı.');
    $karar = null; $yolda = false;
    if ($alan === 'baslangic') {
        if (!empty($k['bitis']) && $yeni > (string)$k['bitis']) throw new DomainException('Teslim günü planlanan bitişten (' . _koTarih($k['bitis']) . ') sonra olamaz; önce bitişi uzatın.');
        if (koMakineSahada($db, $k)) throw new DomainException('Makine sahaya teslim edilmiş; teslim günü taşınamaz. Düzeltme için "Durumu geri al".');
        $yolda = koMakineYolda($db, $k);
        if ($yolda) {
            $karar = (string)($g['karar'] ?? '');
            if (!isset(KO_KARARLAR[$karar])) throw new DomainException('Makine yolda: ne yapılacağını seçin (yolda bekletilir / geri döner / yine de teslim).');
        }
    } else {
        if ($yeni < (string)$k['baslangic']) throw new DomainException('Alım günü başlangıçtan (' . _koTarih($k['baslangic']) . ') önce olamaz.');
        if ($yeni < date('Y-m-d')) throw new DomainException('Alım günü geçmiş bir güne taşınamaz; makine alındıysa Sahada Teslim ile teslim alın.');
    }
    $kid = (int)$k['id'];
    $eski = logKayitAl($db, 'kiralamalar', $kid);
    $u = $db->prepare("UPDATE kiralamalar SET $alan=?, updated_at=datetime('now','localtime') WHERE id=? AND durum='aktif' AND gercek_bitis IS NULL AND COALESCE($alan,'')=?");
    $u->execute([$yeni, $kid, $eskiTarih]);
    if ($u->rowCount() !== 1) throw new DomainException('Kiralama bu sırada değişti; sayfayı yenileyip tekrar deneyin.');
    $kYeni = $k; $kYeni[$alan] = $yeni;

    if ($yolda && $karar === 'depoya_doner') {
        if (opYeniZincir($db, $k)) opAdimYaz($db, $kid, 'yukleme', 'geri_alindi', opOturumYapan($db), 'yonetici', 'Teslim günü taşındı, araç geri döndü: ' . $sebep);
        $db->prepare("UPDATE nakliye_makineleri SET yuklendi_zaman=NULL, yukleyen=NULL WHERE kiralama_id=? AND yuklendi_zaman IS NOT NULL")->execute([$kid]);
        if (operasyonKiralamaGuncelMi($db, $k)) _koMakineYaz($db, $k, 'kiraya_gidecek', ['nakliyede']);
    }

    $yonler = $alan === 'baslangic' ? ['gidis', 'gidis_donus', 'tek'] : ['donus'];
    $kayan = 0; $paylasimli = 0;
    foreach (koKiralamaNakliyeleri($db, $k, $yonler) as $n) {
        if (!empty($n['gerceklesen_tarih'])) continue;
        if ($n['paylasimli']) { $paylasimli++; continue; }
        $tarihEsit = (string)$n['tarih'] === $eskiTarih; $planEsit = (string)($n['planlanan_tarih'] ?? '') === $eskiTarih || empty($n['planlanan_tarih']);
        if (!$tarihEsit && !$planEsit) continue;
        $nEski = logKayitAl($db, 'nakliyeler', (int)$n['id']);
        $db->prepare("UPDATE nakliyeler SET tarih=CASE WHEN tarih=? THEN ? ELSE tarih END, planlanan_tarih=CASE WHEN COALESCE(planlanan_tarih,'')='' OR planlanan_tarih=? THEN ? ELSE planlanan_tarih END WHERE id=?")
           ->execute([$eskiTarih, $yeni, $eskiTarih, $yeni, (int)$n['id']]);
        logla($db, 'nakliyeler', (int)$n['id'], 'guncelle', $nEski, logKayitAl($db, 'nakliyeler', (int)$n['id']),
              'Nakliye günü kiralamayla taşındı: ' . _koTarih($eskiTarih) . ' → ' . _koTarih($yeni) . ' (' . ($k['kod'] ?: ('#' . $kid)) . ')');
        $kayan++;
    }

    $duzeltme = koBekleyenHakedisDuzelt($db, $kYeni, (string)$kYeni['baslangic'], $kYeni['bitis'] ?: null);

    $seferHid = null; $seferTutar = 0.0;
    if ($musteri && !empty($g['sefer_ucreti_al'])) {
        $seferTutar = _koTutarOku($g['sefer_tutar'] ?? 0, 'Sefer ücreti');
        if ($seferTutar <= 0) $seferTutar = koSeferVarsayilan($db, $k);
        if ($seferTutar <= 0) throw new DomainException('Boşa giden sefer ücreti girilmedi (Ayarlar → "Boşa giden sefer ücreti" varsayılanı boş ya da kiralama TL dışı).');
        $seferHid = koSeferHakedisi($db, $k, $seferTutar, $eskiTarih, ($alan === 'baslangic' ? 'teslim' : 'alım') . ' günü taşındı: ' . $sebep);
    }
    $db->prepare("INSERT INTO kiralama_tarih_tasimalari (kiralama_id, alan, eski_tarih, yeni_tarih, sebep, musteri_kaynakli, karar, sefer_tutar, sefer_hakedis_id, doviz, yapan, zaman)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([$kid, $alan, $eskiTarih ?: null, $yeni, $sebep, $musteri ? 1 : 0, $karar, $seferTutar, $seferHid, $k['doviz'] ?: 'TL', _koYapanAd($db), date('Y-m-d H:i:s')]);
    if (!empty($k['siparis_no'])) siparisNakliyeSenkron($db, (string)$k['siparis_no']);
    $ozet = ($alan === 'baslangic' ? 'Teslim günü' : 'Alım günü') . ' taşındı: ' . _koTarih($eskiTarih) . ' → ' . _koTarih($yeni)
          . ($musteri ? ' (müşteri kaynaklı)' : '') . ($karar ? ' · ' . KO_KARARLAR[$karar] : '') . ($seferHid ? ' · boşa giden sefer ücreti hakedişi açıldı' : '') . ' — ' . $sebep;
    logla($db, 'kiralamalar', $kid, 'guncelle', $eski, logKayitAl($db, 'kiralamalar', $kid), $ozet);
    return ['alan' => $alan, 'eski_tarih' => $eskiTarih, 'yeni_tarih' => $yeni, 'karar' => $karar, 'nakliye_kayan' => $kayan, 'nakliye_paylasimli' => $paylasimli,
            'sefer_hakedis_id' => $seferHid, 'hakedis' => $duzeltme, 'mesaj' => $ozet];
}

function koSeferHakedisi(PDO $db, array $k, float $tutar, ?string $seferTarihi, string $neden): int {
    $tarih = $seferTarihi ?: date('Y-m-d');
    return koHakedisAc($db, $k, null, null, 'sefer', 'Boşa giden sefer — ' . _koTarih($tarih) . ' · ' . mb_substr($neden, 0, 300), $tutar, date('Y-m-d'));
}

function koIptalSeferi(PDO $db, array $k, array $body, string $iptalNedeni): ?int {
    if (empty($body['sefer_ucreti_al']) || empty($body['musteri_kaynakli'])) return null;
    $tutar = _koTutarOku($body['sefer_tutar'] ?? 0, 'Sefer ücreti');
    if ($tutar <= 0) $tutar = koSeferVarsayilan($db, $k);
    if ($tutar <= 0) throw new DomainException('Boşa giden sefer ücreti girilmedi (Ayarlar → "Boşa giden sefer ücreti" varsayılanı boş ya da kiralama TL dışı).');
    $hid = koSeferHakedisi($db, $k, $tutar, (string)$k['baslangic'], 'iptal: ' . $iptalNedeni);
    $db->prepare("INSERT INTO kiralama_tarih_tasimalari (kiralama_id, alan, eski_tarih, yeni_tarih, sebep, musteri_kaynakli, karar, sefer_tutar, sefer_hakedis_id, doviz, yapan, zaman)
                  VALUES (?,?,?,?,?,1,NULL,?,?,?,?,?)")
       ->execute([(int)$k['id'], 'iptal', $k['baslangic'], null, mb_substr($iptalNedeni, 0, 1000), $tutar, $hid, $k['doviz'] ?: 'TL', _koYapanAd($db), date('Y-m-d H:i:s')]);
    return $hid;
}

const KO_SIL_BAG_KOPAR = [['teknik_servis', 'kiralama_id'], ['teklifler', 'kiralama_id'], ['portal_kiralama_talepleri', 'kiralama_id'], ['nakliyeler', 'kiralama_id']];

final class KoSilici {
    public array $yedek = [];
    public array $sayim = [];
    public array $tablolar;
    private array $kolon = [];

    public function __construct(private PDO $db) {
        $this->tablolar = $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    }
    public function var(string $t, string $c): bool {
        if (!in_array($t, $this->tablolar, true)) return false;
        $this->kolon[$t] ??= array_column($this->db->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        return in_array($c, $this->kolon[$t], true);
    }
    private function yer(array $d): string { return implode(',', array_fill(0, count($d), '?')); }
    public function idler(string $t, string $c, array $d): array {
        if (!$d || !$this->var($t, $c)) return [];
        $s = $this->db->prepare("SELECT id FROM $t WHERE $c IN (" . $this->yer($d) . ')'); $s->execute(array_values($d));
        return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    }
    public function sil(string $t, string $c, array $d, ?string $ek = null): void {
        if (!$d || !$this->var($t, $c)) return;
        $w = "$c IN (" . $this->yer($d) . ')' . ($ek ? " AND $ek" : '');
        $s = $this->db->prepare("SELECT * FROM $t WHERE $w"); $s->execute(array_values($d));
        $satir = $s->fetchAll(PDO::FETCH_ASSOC);
        if (!$satir) return;
        $this->yedek[$t] = array_merge($this->yedek[$t] ?? [], $satir);
        $this->sayim[$t] = ($this->sayim[$t] ?? 0) + count($satir);
        $this->db->prepare("DELETE FROM $t WHERE $w")->execute(array_values($d));
    }
    public function kilitliSil(string $t, string $c, array $d): void {
        if (!$d || !$this->var($t, $c)) return;
        $q = $this->db->prepare("SELECT name, sql FROM sqlite_master WHERE type='trigger' AND tbl_name=? AND sql LIKE '%BEFORE DELETE%'");
        $q->execute([$t]);
        $kilit = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($kilit as $k) $this->db->exec('DROP TRIGGER IF EXISTS "' . str_replace('"', '', $k['name']) . '"');
        $this->sil($t, $c, $d);
        foreach ($kilit as $k) $this->db->exec($k['sql']);
    }
    public function kopar(string $t, string $c, array $d): void {
        if ($d && $this->var($t, $c)) $this->db->prepare("UPDATE $t SET $c=NULL WHERE $c IN (" . $this->yer($d) . ')')->execute(array_values($d));
    }
    public function gorevSil(string $modul, array $d): void {
        $this->sil('gorevler', 'kaynak_id', $d, 'kaynak_modul LIKE ' . $this->db->quote($modul));
    }
}

function koHasarAgaciSil(KoSilici $s, array $hd): void {
    if (!$hd) return;
    foreach (['hasar_kalemleri', 'hasar_degerlendirme_gecmisi', 'hasar_faturalari', 'hasar_indirim_onerileri', 'hasar_dosya_gonderimleri'] as $t) $s->sil($t, 'degerlendirme_id', $hd);
    $s->kilitliSil('hasar_belgeleri', 'degerlendirme_id', $hd);
    $s->gorevSil('hasar%', $hd);
    $s->sil('hasar_degerlendirmeleri', 'id', $hd);
}

function koTutanakAgaciSil(KoSilici $s, array $tt): void {
    if (!$tt) return;
    koHasarAgaciSil($s, $s->idler('hasar_degerlendirmeleri', 'tutanak_id', $tt));
    $s->kopar('teknik_servis', 'tutanak_id', $tt);
    $s->kopar('hakedisler', 'tutanak_id', $tt);
    foreach (['iade_tutanak_fotograflari', 'tutanak_duzeltmeleri', 'tutanak_gonderimleri'] as $t) $s->sil($t, 'tutanak_id', $tt);
    $s->kilitliSil('tutanak_surumleri', 'tutanak_id', $tt);
    $s->gorevSil('tutanak%', $tt);
    $s->sil('iade_tutanaklari', 'id', $tt);
}

function tutanakTamSil(PDO $db, int $id): array {
    $t = logKayitAl($db, 'iade_tutanaklari', $id);
    if (!$t) throw new DomainException('Tutanak bulunamadı.');
    $s = new KoSilici($db);
    koTutanakAgaciSil($s, [$id]);
    return ['tutanak' => $t, 'yedek' => $s->yedek, 'sayim' => $s->sayim];
}

function kiralamaTamSil(PDO $db, int $id, array $birlikte = []): array {
    $k = logKayitAl($db, 'kiralamalar', $id);
    if (!$k) throw new DomainException('Kiralama bulunamadı.');
    $s = new KoSilici($db);
    $hk = $s->idler('hakedisler', 'kiralama_id', [$id]);
    $th = array_values(array_unique(array_merge($s->idler('tahsilatlar', 'kiralama_id', [$id]), $s->idler('tahsilatlar', 'hakedis_id', $hk))));

    foreach (KO_SIL_BAG_KOPAR as [$t, $c]) if ($t !== 'nakliyeler') $s->kopar($t, $c, [$id]);
    $s->kopar('nakliyeler', 'hakedis_id', $hk);
    $s->kopar('cek_senet', 'tahsilat_id', $th);

    $s->sil('tahsilatlar', 'id', $th);
    $s->sil('hakedis_kalemleri', 'hakedis_id', $hk);
    koHasarAgaciSil($s, $s->idler('hasar_degerlendirmeleri', 'kiralama_id', [$id]));
    koTutanakAgaciSil($s, $s->idler('iade_tutanaklari', 'kiralama_id', [$id]));
    $s->kilitliSil('hasar_belgeleri', 'kiralama_id', [$id]);
    $s->sil('hakedisler', 'id', $hk);

    if ($s->var('kiralama_protokolleri', 'kiralama_idler')) {
        $silinen = array_map('intval', array_merge([$id], $birlikte));
        $pq = $db->prepare("SELECT id, kiralama_idler FROM kiralama_protokolleri WHERE siparis_no IS ? OR kiralama_idler LIKE ?");
        $pq->execute([$k['siparis_no'] ?: null, '%' . $id . '%']);
        foreach ($pq->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $kap = json_decode((string)$p['kiralama_idler'], true);
            if (!is_array($kap)) $kap = preg_split('/\D+/', (string)$p['kiralama_idler'], -1, PREG_SPLIT_NO_EMPTY);
            $kap = array_map('intval', $kap);
            if (!$kap || !in_array($id, $kap, true) || array_diff($kap, $silinen)) continue;
            $s->sil('kiralama_protokol_gonderimleri', 'protokol_id', [(int)$p['id']]);
            $s->sil('kiralama_protokolleri', 'id', [(int)$p['id']]);
        }
    }

    $s->gorevSil('kiralama%', [$id]);
    $s->gorevSil('hakedis%', $hk);
    $s->gorevSil('tahsilat%', $th);

    foreach ($s->tablolar as $t) {
        if ($t === 'kiralamalar' || in_array($t, array_column(KO_SIL_BAG_KOPAR, 0), true)) continue;
        $s->sil($t, 'kiralama_id', [$id]);
    }
    if ($s->var('nakliyeler', 'kiralama_id')) {
        $nq = $db->prepare('SELECT id, siparis_no FROM nakliyeler WHERE kiralama_id=?'); $nq->execute([$id]);
        foreach ($nq->fetchAll(PDO::FETCH_ASSOC) as $n) {
            $yeni = null;
            if ($s->var('nakliye_makineleri', 'kiralama_id')) {
                $b = $db->prepare("SELECT nm.kiralama_id FROM nakliye_makineleri nm JOIN kiralamalar k ON k.id = nm.kiralama_id WHERE nm.nakliye_id=? AND nm.kiralama_id<>? ORDER BY (k.durum='iptal'), k.id LIMIT 1");
                $b->execute([(int)$n['id'], $id]);
                $yeni = $b->fetchColumn() ?: null;
            }
            if (!$yeni && $n['siparis_no']) {
                $sq = $db->prepare("SELECT id FROM kiralamalar WHERE siparis_no=? AND id<>? ORDER BY (durum='iptal'), id LIMIT 1");
                $sq->execute([$n['siparis_no'], $id]);
                $yeni = $sq->fetchColumn() ?: null;
            }
            if ($yeni) { $db->prepare('UPDATE nakliyeler SET kiralama_id=? WHERE id=?')->execute([(int)$yeni, (int)$n['id']]); continue; }
            foreach (['nakliye_makineleri', 'nakliye_ekip', 'teslim_baglantilari'] as $t) $s->sil($t, 'nakliye_id', [(int)$n['id']]);
            $s->sil('nakliyeler', 'id', [(int)$n['id']]);
        }
    }

    $db->prepare('DELETE FROM kiralamalar WHERE id=?')->execute([$id]);
    return ['kiralama' => $k, 'yedek' => $s->yedek, 'sayim' => $s->sayim];
}

function kiralamaSilOzet(array $sayim): array {
    $ad = ['hakedisler' => 'hakediş', 'tahsilatlar' => 'tahsilat', 'iade_tutanaklari' => 'tutanak', 'kiralama_fotograflari' => 'fotoğraf',
           'iade_tutanak_fotograflari' => 'tutanak fotoğrafı', 'hasar_degerlendirmeleri' => 'hasar değerlendirmesi', 'hasar_faturalari' => 'hasar faturası',
           'kiralama_protokolleri' => 'protokol', 'tutanak_surumleri' => 'imzalı belge sürümü', 'hasar_belgeleri' => 'hasar belgesi', 'operasyon_adimlari' => 'kontrol/teslim adımı', 'kiralama_puantaj' => 'puantaj', 'kiralama_uzatmalari' => 'uzatma',
           'kiralama_belgeler' => 'belge', 'gorevler' => 'görev', 'nakliye_makineleri' => 'nakliye bağı', 'nakliyeler' => 'nakliye kaydı',
           'depo_kontrolleri' => 'dönüş kontrolü', 'sevk_hazirlik' => 'hazırlık kaydı'];
    $out = [];
    foreach ($sayim as $t => $n) if (isset($ad[$t])) $out[] = $n . ' ' . $ad[$t];
    $diger = array_sum(array_diff_key($sayim, $ad));
    if ($diger) $out[] = $diger . ' diğer kayıt';
    return $out;
}
