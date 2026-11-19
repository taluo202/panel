<?php
declare(strict_types=1);
require_once __DIR__ . '/hasar-fiyat-lib.php';

const AK_KARARLAR = ['ariza_degil' => 'Arıza değil', 'yerinde_onarim' => 'Yerinde onarım', 'degisim' => 'Makine değişimi', 'geri_al' => 'Makine geri alındı'];
const AK_KUSUR = ['firma' => 'Firma', 'musteri' => 'Müşteri', 'belirsiz' => 'Belirsiz'];
const AK_ISLEMLER = ['ariza_degil', 'yerinde_onarim', 'degisim', 'geri_al', 'kusur'];
const AK_TICARI = ['degisim', 'geri_al'];
const AK_ESIK_VARSAYILAN = 24.0;
const AK_ORAN_VARSAYILAN = 100.0;

function akSema(PDO $db): void {
    $kol = array_column($db->query('PRAGMA table_info(hasar_degerlendirmeleri)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['ariza_karar TEXT', 'ariza_karar_sebep TEXT', 'ariza_karar_zaman TEXT', 'ariza_karar_kullanici TEXT',
              'kusur_sahibi TEXT', 'kusur_sebep TEXT', 'kusur_zaman TEXT', 'kusur_kullanici TEXT',
              'bildirim_zaman TEXT', 'cozum_zaman TEXT', 'kiralama_kapansin INTEGER', 'onarim_kalem_id INTEGER'] as $tanim) {
        if (!in_array(strtok($tanim, ' '), $kol, true)) $db->exec('ALTER TABLE hasar_degerlendirmeleri ADD COLUMN ' . $tanim);
    }
    $db->exec("CREATE TABLE IF NOT EXISTS hasar_indirim_onerileri (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        degerlendirme_id INTEGER REFERENCES hasar_degerlendirmeleri(id) ON DELETE RESTRICT, servis_id INTEGER,
        kiralama_id INTEGER NOT NULL, para_birimi TEXT NOT NULL, bildirim_zaman TEXT NOT NULL, cozum_zaman TEXT NOT NULL,
        sure_saat REAL NOT NULL, esik_saat REAL NOT NULL, gun REAL NOT NULL, gunluk_ucret REAL NOT NULL, oran REAL NOT NULL, tutar REAL NOT NULL,
        durum TEXT NOT NULL DEFAULT 'oneri',             -- oneri | onaylandi | reddedildi | iptal
        onay_gun REAL, onay_oran REAL, onay_tutar REAL, hakedis_id INTEGER, hakedis_kalem_id INTEGER,
        karar_sebep TEXT, karar_veren TEXT, karar_zaman TEXT, gorev_id INTEGER,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')));
      CREATE INDEX IF NOT EXISTS idx_hio_deger ON hasar_indirim_onerileri(degerlendirme_id, durum);");
    akSemaServis($db);
}

function akSemaServis(PDO $db): void {
    $semaOku = fn() => (string)($db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='hasar_indirim_onerileri'")->fetchColumn() ?: '');
    $sema = $semaOku();
    if ($sema === '') return;
    $desen = '/degerlendirme_id\s+INTEGER\s+NOT\s+NULL/i';
    if (preg_match($desen, $sema) && !$db->inTransaction()) {
        $fk = (int)$db->query('PRAGMA foreign_keys')->fetchColumn();
        $db->exec('PRAGMA foreign_keys = OFF');
        try {
            $db->beginTransaction();
            islemKilitAl($db);
            if ($semaOku() !== $sema) throw new \RuntimeException('__zaten__');
            $yeni = preg_replace('/^CREATE TABLE\s+("?)hasar_indirim_onerileri\1/i', 'CREATE TABLE hasar_indirim_onerileri_v145', preg_replace($desen, 'degerlendirme_id INTEGER', $sema, 1));
            $kol = implode(',', array_map(fn($c) => '"' . $c['name'] . '"', $db->query('PRAGMA table_info(hasar_indirim_onerileri)')->fetchAll(PDO::FETCH_ASSOC)));
            $ix = $db->query("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='hasar_indirim_onerileri' AND sql IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
            $db->exec('DROP TABLE IF EXISTS hasar_indirim_onerileri_v145');
            $db->exec($yeni);
            $db->exec("INSERT INTO hasar_indirim_onerileri_v145 ($kol) SELECT $kol FROM hasar_indirim_onerileri");
            $db->exec('DROP TABLE hasar_indirim_onerileri');
            $db->exec('ALTER TABLE hasar_indirim_onerileri_v145 RENAME TO hasar_indirim_onerileri');
            foreach ($ix as $s) $db->exec($s);
            $db->commit();
        } catch (\Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            if ($e->getMessage() !== '__zaten__') error_log('hasar_indirim_onerileri v145 yeniden kurulumu: ' . $e->getMessage());
        } finally {
            $db->exec('PRAGMA foreign_keys = ' . ($fk ? 'ON' : 'OFF'));
        }
    }
    $kol = array_column($db->query('PRAGMA table_info(hasar_indirim_onerileri)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('servis_id', $kol, true)) $db->exec('ALTER TABLE hasar_indirim_onerileri ADD COLUMN servis_id INTEGER');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_hio_servis ON hasar_indirim_onerileri(servis_id, durum)');
}

function akIndirimAktif(PDO $db): bool {
    try { $s = $db->prepare("SELECT deger FROM ayarlar WHERE anahtar='ariza_indirim_aktif'"); $s->execute(); return trim((string)$s->fetchColumn()) === '1'; }
    catch (\Throwable $e) { return false; }
}

function akAyar(PDO $db): array {
    $oku = function (string $k, float $v) use ($db): float {
        try {
            $s = $db->prepare('SELECT deger FROM ayarlar WHERE anahtar=?'); $s->execute([$k]);
            $d = $s->fetchColumn();
        } catch (\Throwable $e) { $d = false; }
        $d = ($d === false || $d === null) ? '' : str_replace(',', '.', trim((string)$d));
        return ($d !== '' && is_numeric($d)) ? (float)$d : $v;
    };
    return ['esik_saat' => max(0.0, $oku('ariza_indirim_esik_saat', AK_ESIK_VARSAYILAN)),
            'oran' => max(0.0, min(100.0, $oku('ariza_indirim_oran', AK_ORAN_VARSAYILAN)))];
}

function akKiralama(PDO $db, int $kid): ?array {
    $s = $db->prepare('SELECT k.*, m.unvan AS musteri FROM kiralamalar k LEFT JOIN musteriler m ON m.id=k.musteri_id WHERE k.id=?');
    $s->execute([$kid]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}
function akSaatFark(?string $bas, ?string $bit): ?float {
    $b = $bas ? strtotime($bas) : false; $c = $bit ? strtotime($bit) : false;
    if (!$b || !$c || $c < $b) return null;
    return round(($c - $b) / 3600, 2);
}
function akSureMetni(?float $saat): string {
    if ($saat === null) return '—';
    $gun = (int)floor($saat / 24); $kalan = (int)round($saat - $gun * 24);
    if ($kalan === 24) { $gun++; $kalan = 0; }
    return trim(($gun ? $gun . ' gün ' : '') . ($kalan || !$gun ? $kalan . ' saat' : ''));
}
function akSayi(float $n): string { return rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ','); }

function akArizaKaydiAc(PDO $db, int $servisId, int $kiralamaId, string $aciklama, string $bildiren): int {
    if (hdSemaEski($db)) return 0;
    $k = akKiralama($db, $kiralamaId);
    if (!$k) return 0;
    $simdi = date('Y-m-d H:i:s');
    $kod = $k['kod'] ?: '#' . $k['id'];
    $snap = ['kaynak' => 'portal_ariza', 'servis_id' => $servisId, 'aciklama' => $aciklama, 'bildiren' => $bildiren, 'bildirim_zaman' => $simdi];
    $json = json_encode($snap, JSON_UNESCAPED_UNICODE);
    $esik = akAyar($db)['esik_saat'];
    $db->prepare("INSERT INTO hasar_degerlendirmeleri(tutanak_id,depo_kontrol_id,kiralama_id,makine_tur,makine_id,sube,bulgu_turu,ozet,kaynak_veri,kaynak_hash,
                      hedef_zaman,oncelik,kritik,servis_id,bildirim_zaman,created_at,updated_at)
                  VALUES(NULL,NULL,?,?,?,?,'ariza',?,?,?,?,'yuksek',0,?,?,?,?)")
       ->execute([(int)$k['id'], $k['makine_tur'], (int)$k['makine_id'], $k['sube'] ?? null,
                  $kod . ' · ' . ($k['ekipman_adi'] ?: 'Makine') . ' · Portal arıza bildirimi: ' . mb_substr($aciklama, 0, 200),
                  $json, hash('sha256', $json), date('Y-m-d H:i:s', time() + (int)round($esik * 3600)), $servisId, $simdi, $simdi, $simdi]);
    $id = (int)$db->lastInsertId();
    hdGecmis($db, $id, 'olustu', 'Müşteri portalından arıza bildirildi (' . $bildiren . '); servis #' . $servisId . ' açıldı. Karar bekliyor: arıza değil / yerinde onarım / makine değişimi / geri al.', $snap);
    logla($db, 'hasar_degerlendirmeleri', $id, 'ekle', null, ['servis_id' => $servisId, 'kaynak' => 'portal_ariza'], 'Portal arızasından değerlendirme kaydı — ' . $kod);
    return $id;
}

function akIndirimHesapla(array $k, string $bildirim, string $cozum, float $esikSaat, float $oran): ?array {
    $saat = akSaatFark($bildirim, $cozum);
    if ($saat === null || $saat <= 0 || $saat <= $esikSaat) return null;
    $gun = max(1.0, floor($saat / 24));
    $mik = max(1, (int)($k['miktar'] ?? 1));
    $birim = (float)($k['birim_ucret'] ?? 0);
    $isk = kiralamaGecerliIskonto($k);
    $gunluk = round($birim * $mik / kiraBirimGun($k['kira_tipi'] ?? 'gunluk') * (1 - $isk / 100), 2);
    return ['sure_saat' => $saat, 'esik_saat' => $esikSaat, 'gun' => $gun, 'gunluk_ucret' => $gunluk, 'oran' => $oran,
            'tutar' => round($gun * $gunluk * $oran / 100, 2), 'para_birimi' => hfPara($k)];
}

function akIndirimOneriUret(PDO $db, array $r, array $k, string $cozum): ?int {
    if (!akIndirimAktif($db)) return null;
    $hid = (int)$r['id'];
    if (($r['kusur_sahibi'] ?? '') === 'musteri' || empty($r['bildirim_zaman'])) return null;
    $s = $db->prepare("SELECT id FROM hasar_indirim_onerileri WHERE degerlendirme_id=? AND durum IN ('oneri','onaylandi')"); $s->execute([$hid]);
    if ($s->fetchColumn()) return null;
    $a = akAyar($db);
    $h = akIndirimHesapla($k, (string)$r['bildirim_zaman'], $cozum, $a['esik_saat'], $a['oran']);
    if (!$h) return null;
    if ($h['tutar'] <= 0) { hdGecmis($db, $hid, 'indirim_yok', 'Arıza süresi eşiği aştı ama kiralamada günlük ücret yok; indirim önerisi üretilmedi.'); return null; }
    $db->prepare('INSERT INTO hasar_indirim_onerileri(degerlendirme_id,kiralama_id,para_birimi,bildirim_zaman,cozum_zaman,sure_saat,esik_saat,gun,gunluk_ucret,oran,tutar,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
       ->execute([$hid, (int)$k['id'], $h['para_birimi'], $r['bildirim_zaman'], $cozum, $h['sure_saat'], $h['esik_saat'], $h['gun'], $h['gunluk_ucret'], $h['oran'], $h['tutar'], date('Y-m-d H:i:s')]);
    $oid = (int)$db->lastInsertId();
    $kod = $k['kod'] ?: '#' . $k['id'];
    $gid = gorevOlustur($db, 'Kira indirimi önerisi: ' . $kod,
        'Arıza ' . akSureMetni($h['sure_saat']) . ' sürdü (eşik ' . akSayi($a['esik_saat']) . ' saat). ' . akSayi($h['gun']) . ' gün kira indirimi önerisi bekleyen hakediş için onayınızı bekliyor.',
        'hasar_indirim', $hid, 'normal');
    $db->prepare('UPDATE hasar_indirim_onerileri SET gorev_id=? WHERE id=?')->execute([$gid, $oid]);
    hdGecmis($db, $hid, 'indirim_oneri', 'Arıza süresi eşiği aştı (' . akSureMetni($h['sure_saat']) . '); ' . akSayi($h['gun']) . ' gün kira indirimi önerisi ofis onayına sunuldu.', ['oneri_id' => $oid, 'gun' => $h['gun']]);
    logla($db, 'hasar_indirim_onerileri', $oid, 'ekle', null, $h, 'Kira indirimi önerisi — Hasar #' . $hid . ' · ' . $kod);
    return $oid;
}

function akCozumIsle(PDO $db, array $r, string $neden): void {
    $hid = (int)$r['id']; $simdi = date('Y-m-d H:i:s');
    $db->prepare('UPDATE hasar_degerlendirmeleri SET cozum_zaman=?, updated_at=? WHERE id=? AND cozum_zaman IS NULL')->execute([$simdi, $simdi, $hid]);
    $r['cozum_zaman'] = $simdi;
    $sure = akSaatFark($r['bildirim_zaman'] ?? null, $simdi);
    hdGecmis($db, $hid, 'cozum', $neden . '; arıza çözüldü' . ($sure !== null ? ' (bildirimden ' . akSureMetni($sure) . ' sonra)' : '') . '.');
    $k = akKiralama($db, (int)$r['kiralama_id']);
    if ($k) akIndirimOneriUret($db, $r, $k, $simdi);
    if (($r['fiyat_durum'] ?? '') === 'bekliyor') { hfFiyatGoreviAc($db, $hid); return; }
    hfSonuclandir($db, $hid, $neden . '; değerlendirme sonuçlandı.');
}

function akServisTamamlandi(PDO $db, int $sid): void {
    $s = $db->prepare("SELECT * FROM hasar_degerlendirmeleri WHERE servis_id=? AND durum NOT IN ('sonuclandi','iptal')"); $s->execute([$sid]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!hfArizaKaydiMi($r) && empty($r['ariza_karar'])) continue;
        if (empty($r['cozum_zaman'])) akCozumIsle($db, $r, 'Servis #' . $sid . ' tamamlandı');
        elseif (($r['fiyat_durum'] ?? '') !== 'bekliyor') hfSonuclandir($db, (int)$r['id'], 'Servis #' . $sid . ' tamamlandı; değerlendirme sonuçlandı.');
    }
}

function akMakineDegisti(PDO $db, int $eskiKiralamaId, string $yeniKod): void {
    $s = $db->prepare("SELECT * FROM hasar_degerlendirmeleri WHERE kiralama_id=? AND ariza_karar='degisim' AND cozum_zaman IS NULL AND durum NOT IN ('sonuclandi','iptal')");
    $s->execute([$eskiKiralamaId]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) akCozumIsle($db, $r, 'Makine değiştirildi (' . $yeniKod . ')');
    $q = $db->prepare("SELECT id FROM teknik_servis WHERE kiralama_id=? AND tip='ariza' AND durum<>'tamamlandi'");
    $q->execute([$eskiKiralamaId]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $sid) akServisIndirimUret($db, (int)$sid, date('Y-m-d H:i:s'), false);
}

function akServisIndirimUret(PDO $db, int $sid, string $cozum, bool $sahaDenetle = true): ?int {
    if (!akIndirimAktif($db)) return null;
    $q = $db->prepare('SELECT * FROM teknik_servis WHERE id=?'); $q->execute([$sid]);
    $s = $q->fetch(PDO::FETCH_ASSOC);
    if (!$s || ($s['tip'] ?? '') !== 'ariza' || empty($s['kiralama_id'])) return null;
    if (in_array($s['kaynak'] ?? '', ['depo_inceleme', 'donus_kontrolu'], true) || !empty($s['donus_kontrol_id'])) return null;
    if (($s['ariza_kaynak'] ?? '') === 'musteri') return null;
    $k = akKiralama($db, (int)$s['kiralama_id']);
    if (!$k) return null;
    if ($sahaDenetle && (($k['durum'] ?? '') !== 'aktif' || trim((string)($k['gercek_bitis'] ?? '')) !== '')) return null;
    $v = $db->prepare("SELECT id FROM hasar_indirim_onerileri WHERE servis_id=? AND durum IN ('oneri','onaylandi')"); $v->execute([$sid]);
    if ($v->fetchColumn()) return null;
    $talepZaman = '';
    try { $tq = $db->prepare('SELECT created_at FROM servis_talepleri WHERE servis_id=? ORDER BY id LIMIT 1'); $tq->execute([$sid]); $talepZaman = (string)($tq->fetchColumn() ?: ''); } catch (\Throwable $e) { hataGunlugu('ariza.talep_zaman', $e); }
    $bildirim = $talepZaman !== '' ? $talepZaman : (string)($s['created_at'] ?: $s['tarih'] . ' 00:00:00');
    $a = akAyar($db);
    $h = akIndirimHesapla($k, $bildirim, $cozum, $a['esik_saat'], $a['oran']);
    if (!$h || $h['tutar'] <= 0) return null;
    $db->prepare('INSERT INTO hasar_indirim_onerileri(degerlendirme_id,servis_id,kiralama_id,para_birimi,bildirim_zaman,cozum_zaman,sure_saat,esik_saat,gun,gunluk_ucret,oran,tutar,created_at) VALUES(NULL,?,?,?,?,?,?,?,?,?,?,?,?)')
       ->execute([$sid, (int)$k['id'], $h['para_birimi'], $bildirim, $cozum, $h['sure_saat'], $h['esik_saat'], $h['gun'], $h['gunluk_ucret'], $h['oran'], $h['tutar'], date('Y-m-d H:i:s')]);
    $oid = (int)$db->lastInsertId();
    $kod = $k['kod'] ?: '#' . $k['id'];
    $gid = gorevOlustur($db, 'Kira indirimi önerisi: ' . $kod,
        'Arıza ' . akSureMetni($h['sure_saat']) . ' sürdü (eşik ' . akSayi($a['esik_saat']) . ' saat). ' . akSayi($h['gun']) . ' gün kira indirimi önerisi onayınızı bekliyor (Teknik Servis › servis #' . $sid . ' › Masraf ve fiyat).',
        'servis_indirim', $sid, 'normal');
    $db->prepare('UPDATE hasar_indirim_onerileri SET gorev_id=? WHERE id=?')->execute([$gid, $oid]);
    logla($db, 'hasar_indirim_onerileri', $oid, 'ekle', null, $h, 'Kira indirimi önerisi — Servis #' . $sid . ' · ' . $kod);
    return $oid;
}

function akServisIndirimVeri(PDO $db, int $sid): array {
    require_once __DIR__ . '/hasar-fiyat-lib.php';
    $q = $db->prepare('SELECT * FROM hasar_indirim_onerileri WHERE servis_id=? ORDER BY id DESC'); $q->execute([$sid]);
    $oneriler = array_map(fn($o) => ['id' => (int)$o['id'], 'durum' => $o['durum'], 'gun' => (float)$o['gun'], 'oran' => (float)$o['oran'], 'tutar' => (float)$o['tutar'], 'para_birimi' => $o['para_birimi'],
                                     'sure_saat' => (float)$o['sure_saat'], 'esik_saat' => (float)$o['esik_saat'], 'gunluk_ucret' => (float)$o['gunluk_ucret'], 'onay_tutar' => $o['onay_tutar'] !== null ? (float)$o['onay_tutar'] : null,
                                     'hakedis_id' => $o['hakedis_id'] !== null ? (int)$o['hakedis_id'] : null, 'karar_sebep' => $o['karar_sebep'], 'karar_veren' => $o['karar_veren'], 'karar_zaman' => $o['karar_zaman']], $q->fetchAll(PDO::FETCH_ASSOC));
    $s = $db->prepare('SELECT kiralama_id FROM teknik_servis WHERE id=?'); $s->execute([$sid]);
    $k = ($kid = (int)$s->fetchColumn()) ? akKiralama($db, $kid) : null;
    return ['oneriler' => $oneriler, 'uygun_hakedisler' => $k ? hfUygunHakedisler($db, $k) : []];
}

function akServisIndirimKarar(PDO $db, int $sid, array $b): array {
    $s = $db->prepare('SELECT * FROM hasar_indirim_onerileri WHERE id=? AND servis_id=?'); $s->execute([(int)($b['oneri_id'] ?? 0), $sid]);
    $o = $s->fetch(PDO::FETCH_ASSOC);
    if (!$o) throw new DomainException('İndirim önerisi bulunamadı.');
    $k = akKiralama($db, (int)$o['kiralama_id']);
    if (!$k) throw new DomainException('Kiralama bulunamadı.');
    return _akIndirimUygula($db, $o, $k, $b, 'Servis #' . $sid, null, $sid);
}

function akHakedisSilindi(PDO $db, int $hakedisId): void {
    $s = $db->prepare("SELECT * FROM hasar_indirim_onerileri WHERE hakedis_id=? AND durum='onaylandi'"); $s->execute([$hakedisId]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $servisli = !empty($o['servis_id']);
        $gid = gorevOlustur($db, 'Kira indirimi yeniden onay bekliyor', 'Bağlı hakediş silindi; indirim önerisi yeniden onay bekliyor.', $servisli ? 'servis_indirim' : 'hasar_indirim', $servisli ? (int)$o['servis_id'] : (int)$o['degerlendirme_id'], 'normal');
        $db->prepare("UPDATE hasar_indirim_onerileri SET durum='oneri', hakedis_id=NULL, hakedis_kalem_id=NULL, onay_gun=NULL, onay_oran=NULL, onay_tutar=NULL, karar_sebep=?, gorev_id=? WHERE id=?")
           ->execute(['Bağlı hakediş silindi; yeniden onay bekliyor.', $gid, (int)$o['id']]);
        if (!$servisli) hdGecmis($db, (int)$o['degerlendirme_id'], 'indirim_oneri', 'Bağlı hakediş silindi; kira indirimi yeniden onay bekliyor.', ['oneri_id' => (int)$o['id']]);
        else logla($db, 'hasar_indirim_onerileri', (int)$o['id'], 'guncelle', ['durum' => 'onaylandi'], ['durum' => 'oneri'], 'Bağlı hakediş silindi; kira indirimi yeniden onay bekliyor — Servis #' . (int)$o['servis_id']);
    }
}

function _akGorevKapat(PDO $db, ?int $gid): void {
    if ($gid) $db->prepare("UPDATE gorevler SET durum='tamamlandi', tamamlanma=?, updated_at=? WHERE id=? AND durum='acik'")->execute([date('Y-m-d'), date('Y-m-d H:i:s'), $gid]);
}

function akServisKapat(PDO $db, int $sid, string $not): void {
    $s = $db->prepare('SELECT * FROM teknik_servis WHERE id=?'); $s->execute([$sid]);
    $sv = $s->fetch(PDO::FETCH_ASSOC);
    if (!$sv || $sv['durum'] === 'tamamlandi') return;
    $yap = trim(trim((string)$sv['yapilan']) . "\n" . $not);
    $db->prepare("UPDATE teknik_servis SET durum='tamamlandi', tamamlanma=?, yapilan=?, saha_durum=CASE WHEN saha_durum IN ('atanmadi','atandi','yolda','sahada') THEN 'iptal' ELSE saha_durum END, updated_at=? WHERE id=?")
       ->execute([date('Y-m-d'), $yap, date('Y-m-d H:i:s'), $sid]);
    teknikServisMakineDurumSenkron($db, $sv['makine_tur'], $sv['makine_id'] !== null ? (int)$sv['makine_id'] : null, 'tamamlandi');
    $db->prepare("UPDATE gorevler SET durum='tamamlandi', tamamlanma=?, updated_at=? WHERE kaynak_modul='teknik_servis' AND kaynak_id=? AND kaynak='otomatik' AND durum='acik'")
       ->execute([date('Y-m-d'), date('Y-m-d H:i:s'), $sid]);
    require_once __DIR__ . '/servis-kalem-lib.php';
    tsTamamlandi($db, $sid);
    tsPortalBildir($db, $sv, 'tamamlandi', $not);
    logla($db, 'teknik_servis', $sid, 'guncelle', ['durum' => $sv['durum']], ['durum' => 'tamamlandi'], 'Servis kapatıldı — ' . $not);
}

function akOnarimKalemiSenkron(PDO $db, array $r): void {
    $hid = (int)$r['id']; $kal = null;
    if (!empty($r['onarim_kalem_id'])) { $s = $db->prepare('SELECT * FROM hasar_kalemleri WHERE id=?'); $s->execute([(int)$r['onarim_kalem_id']]); $kal = $s->fetch(PDO::FETCH_ASSOC) ?: null; }
    if (($r['kusur_sahibi'] ?? '') === 'musteri') {
        if (!$kal) {
            $k = akKiralama($db, (int)$r['kiralama_id']);
            $db->prepare("INSERT INTO hasar_kalemleri(degerlendirme_id,tur,aciklama,foto_ids,para_birimi,created_at) VALUES(?,'onarim',?,'[]',?,?)")
               ->execute([$hid, 'Onarım bedeli (müşteri kusuru): parça + işçilik', $k ? hfPara($k) : 'TL', date('Y-m-d H:i:s')]);
            $db->prepare('UPDATE hasar_degerlendirmeleri SET onarim_kalem_id=? WHERE id=?')->execute([(int)$db->lastInsertId(), $hid]);
        }
        if (empty($r['fiyat_durum']) || $r['fiyat_durum'] === 'iptal') $db->prepare("UPDATE hasar_degerlendirmeleri SET fiyat_durum='bekliyor' WHERE id=?")->execute([$hid]);
        $servisDurum = _hfServisDurum($db, $r);
        if (empty($r['servis_id']) || $servisDurum === 'tamamlandi') hfFiyatGoreviAc($db, $hid);
        return;
    }
    if ($kal && $kal['tutar'] === null) {
        $db->prepare('DELETE FROM hasar_kalemleri WHERE id=?')->execute([(int)$kal['id']]);
        $db->prepare('UPDATE hasar_degerlendirmeleri SET onarim_kalem_id=NULL WHERE id=?')->execute([$hid]);
    }
    if (hfArizaKaydiMi($r) && ($r['fiyat_durum'] ?? '') === 'bekliyor') {
        $s = $db->prepare('SELECT COUNT(*) FROM hasar_kalemleri WHERE degerlendirme_id=?'); $s->execute([$hid]);
        if ((int)$s->fetchColumn() === 0) {
            $db->prepare('UPDATE hasar_degerlendirmeleri SET fiyat_durum=NULL WHERE id=?')->execute([$hid]);
            _akGorevKapat($db, (int)($r['fiyat_gorev_id'] ?? 0));
        }
    }
}

function akOnarimOneri(PDO $db, ?int $sid): ?array {
    if (!$sid) return null;
    require_once __DIR__ . '/saha-servis-lib.php';
    $top = 0.0; $satir = [];
    foreach (ssFiyatOneri($db, $sid) as $x) {
        if ($x['tur'] === 'yol') continue;
        $t = round((float)$x['miktar'] * (float)$x['birim_fiyat'], 2); $top += $t;
        $satir[] = ['tur' => $x['tur'], 'aciklama' => $x['aciklama'], 'tutar' => $t];
    }
    return ['tutar' => round($top, 2), 'satirlar' => $satir];
}

function akKararUygula(PDO $db, array $r, string $islem, array $b, string $gerekce): array {
    $hid = (int)$r['id']; $simdi = date('Y-m-d H:i:s'); $kul = (string)($_SESSION['username'] ?? 'Sistem');
    $k = akKiralama($db, (int)$r['kiralama_id']);
    $sahada = $k && $k['durum'] === 'aktif';
    $out = ['servis_id' => $r['servis_id'] ? (int)$r['servis_id'] : null, 'durum' => $r['durum'], 'mesaj' => 'Karar kaydedildi.'];
    if ($islem === 'kusur') {
        $sahip = (string)($b['kusur_sahibi'] ?? '');
        if (!isset(AK_KUSUR[$sahip])) throw new DomainException('Kusur sahibini seçin: firma, müşteri ya da belirsiz.');
        if (in_array($r['fiyat_durum'] ?? '', ['fiyatlandi', 'faturalandi'], true)) throw new DomainException('Hasar fiyatlandırılmış; kusur sahibi değiştirilemez. Önce fiyatı / fatura işaretini geri alın.');
        $db->prepare('UPDATE hasar_degerlendirmeleri SET kusur_sahibi=?, kusur_sebep=?, kusur_zaman=?, kusur_kullanici=? WHERE id=?')->execute([$sahip, $gerekce, $simdi, $kul, $hid]);
        $r['kusur_sahibi'] = $sahip;
        akOnarimKalemiSenkron($db, $r);
        if ($sahip === 'musteri') {
            $s = $db->prepare("SELECT id, gorev_id FROM hasar_indirim_onerileri WHERE degerlendirme_id=? AND durum='oneri'"); $s->execute([$hid]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $o) {
                $db->prepare("UPDATE hasar_indirim_onerileri SET durum='iptal', karar_sebep=?, karar_veren=?, karar_zaman=? WHERE id=?")->execute(['Müşteri kusuru: kira indirimi uygulanmaz.', $kul, $simdi, (int)$o['id']]);
                _akGorevKapat($db, (int)$o['gorev_id']);
                hdGecmis($db, $hid, 'indirim_iptal', 'Kusur müşteride: bekleyen kira indirimi önerisi iptal edildi.', ['oneri_id' => (int)$o['id']]);
            }
        }
        logla($db, 'hasar_degerlendirmeleri', $hid, 'guncelle', null, ['kusur_sahibi' => $sahip], 'Kusur sahibi: ' . AK_KUSUR[$sahip] . ' — ' . $gerekce);
        $out['mesaj'] = $sahip === 'musteri' ? 'Kusur müşteride: "Onarım bedeli" satırı fiyatlandırmaya eklendi; kira indirimi önerilmez.'
                       : ($sahip === 'firma' ? 'Kusur firmada: müşteriye bedel yansımaz, maliyet iç kayıtta kalır.' : 'Kusur belirsiz olarak kaydedildi.');
        return $out;
    }
    if (!isset(AK_KARARLAR[$islem])) throw new DomainException('Geçersiz karar.');
    $db->prepare('UPDATE hasar_degerlendirmeleri SET ariza_karar=?, ariza_karar_sebep=?, ariza_karar_zaman=?, ariza_karar_kullanici=?, bildirim_zaman=COALESCE(bildirim_zaman,created_at) WHERE id=?')
       ->execute([$islem, $gerekce, $simdi, $kul, $hid]);
    $kod = $k ? ($k['kod'] ?: '#' . $k['id']) : '';
    if ($islem === 'ariza_degil') {
        if (!empty($r['depo_kontrol_id'])) hfIptal($db, $r);
        $s = $db->prepare("SELECT id, gorev_id FROM hasar_indirim_onerileri WHERE degerlendirme_id=? AND durum='oneri'"); $s->execute([$hid]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $o) { $db->prepare("UPDATE hasar_indirim_onerileri SET durum='iptal', karar_sebep='Arıza değil.', karar_veren=?, karar_zaman=? WHERE id=?")->execute([$kul, $simdi, (int)$o['id']]); _akGorevKapat($db, (int)$o['gorev_id']); }
        $db->prepare("UPDATE hasar_degerlendirmeleri SET durum='sonuclandi', karar='ariza_degil', cozum_zaman=COALESCE(cozum_zaman,?), sonuclanma=? WHERE id=?")->execute([$simdi, $simdi, $hid]);
        if (!empty($r['servis_id'])) akServisKapat($db, (int)$r['servis_id'], 'Arıza değil: ' . $gerekce);
        logla($db, 'hasar_degerlendirmeleri', $hid, 'guncelle', ['durum' => $r['durum']], ['durum' => 'sonuclandi', 'ariza_karar' => 'ariza_degil'], 'Arıza değil — kayıt kapatıldı: ' . $gerekce);
        $out['durum'] = 'sonuclandi'; $out['mesaj'] = 'Kayıt "arıza değil" kararıyla kapatıldı' . (!empty($r['servis_id']) ? '; bağlı servis tamamlandı' : '') . '.';
        return $out;
    }
    if (!$sahada) throw new DomainException('Makine sahada değil (kiralama aktif değil); bu karar yalnız sahadaki makine için verilir.');
    $atanan = !empty($r['atanan_id']) ? (int)$r['atanan_id'] : null;
    $makineAd = $k['ekipman_adi'] ?: 'Makine';
    if ($islem === 'yerinde_onarim') {
        $adres = mb_substr(trim(metin($b['saha_adres'] ?? '')), 0, 300) ?: trim((string)($k['lokasyon'] ?? ''));
        if ($adres === '') throw new DomainException('Servis adresini yazın (kiralamada şantiye adresi yok).');
        if (!empty($r['servis_id'])) {
            $db->prepare("UPDATE teknik_servis SET yer='saha', saha_durum=CASE WHEN saha_durum IN ('yolda','sahada','rapor_gonderildi') THEN saha_durum ELSE ? END, saha_adres=?, musteri_id=COALESCE(musteri_id,?),
                          irtibat_ad=COALESCE(irtibat_ad,?), irtibat_tel=COALESCE(irtibat_tel,?), planlanan_tarih=COALESCE(planlanan_tarih,?), teknisyen_id=COALESCE(?,teknisyen_id), kiralama_id=COALESCE(kiralama_id,?), updated_at=? WHERE id=? AND durum<>'tamamlandi'")
               ->execute([$atanan ? 'atandi' : 'atanmadi', $adres, (int)$k['musteri_id'], $k['saha_irtibat_ad'] ?? null, $k['saha_irtibat_tel'] ?? null, substr((string)$r['hedef_zaman'], 0, 10), $atanan, (int)$k['id'], $simdi, (int)$r['servis_id']]);
            $sid = (int)$r['servis_id'];
            logla($db, 'teknik_servis', $sid, 'guncelle', null, ['yer' => 'saha'], 'Yerinde onarım kararı: servis sahaya çevrildi (Hasar #' . $hid . ')');
        } else {
            require_once __DIR__ . '/saha-servis-lib.php';
            $sid = ssIsAc($db, ['ariza' => $r['ozet'] . "\nKarar: " . $gerekce, 'saha_adres' => $adres, 'makine_tur' => $r['makine_tur'], 'makine_id' => $r['makine_id'],
                                 'musteri_id' => $k['musteri_id'], 'teknisyen_id' => $atanan, 'planlanan_tarih' => substr((string)$r['hedef_zaman'], 0, 10), 'oncelik' => $r['oncelik'],
                                 'irtibat_ad' => $k['saha_irtibat_ad'] ?? null, 'irtibat_tel' => $k['saha_irtibat_tel'] ?? null, 'tip' => 'ariza']);
            $db->prepare('UPDATE teknik_servis SET kiralama_id=? WHERE id=?')->execute([(int)$k['id'], $sid]);
        }
        $db->prepare("UPDATE hasar_degerlendirmeleri SET durum='incelemede', servis_id=? WHERE id=?")->execute([$sid, $hid]);
        $out['servis_id'] = $sid; $out['durum'] = 'incelemede';
        $out['mesaj'] = 'Yerinde onarım: saha servisi #' . $sid . ($atanan ? ' teknisyene atandı' : ' açıldı (teknisyen atanacak)') . '. Çözüm süresi kira indirimi önerisine yansır.';
        return $out;
    }
    if ($islem === 'degisim') {
        $db->prepare("UPDATE hasar_degerlendirmeleri SET durum='incelemede' WHERE id=?")->execute([$hid]);
        $out['durum'] = 'incelemede'; $out['degisim_link'] = 'kiralama-takip.html?degistir=' . (int)$k['id'];
        $out['mesaj'] = 'Makine değişimi kararı kaydedildi. Değişimi Kiralama Takip penceresinden tamamlayın; değişim günü çözüm sayılır.';
        return $out;
    }
    $kapansin = !empty($b['kiralama_kapansin']) ? 1 : 0;
    if (!empty($r['servis_id'])) {
        $db->prepare("UPDATE teknik_servis SET yer='atolye', saha_durum=CASE WHEN saha_durum IN ('atanmadi','atandi','yolda','sahada') THEN 'iptal' ELSE saha_durum END, updated_at=? WHERE id=? AND durum<>'tamamlandi'")->execute([$simdi, (int)$r['servis_id']]);
        $sid = (int)$r['servis_id'];
    } else {
        $db->prepare("INSERT INTO teknik_servis(makine_tur,makine_id,makine_ad,tip,tarih,durum,oncelik,ariza,kiralama_id,teknisyen_id,kaynak,yer) VALUES(?,?,?,'ariza',?,'acik',?,?,?,?,'manuel','atolye')")
           ->execute([$r['makine_tur'], $r['makine_id'], $makineAd, date('Y-m-d'), $r['oncelik'] === 'yuksek' ? 'yuksek' : 'normal', $r['ozet'] . "\nKarar (geri al): " . $gerekce, (int)$k['id'], $atanan]);
        $sid = (int)$db->lastInsertId();
        teknikServisMakineDurumSenkron($db, $r['makine_tur'], (int)$r['makine_id'], 'acik');
        gorevOlustur($db, 'Servis: ' . $makineAd, $r['ozet'], 'teknik_servis', $sid, $r['oncelik'] === 'yuksek' ? 'yuksek' : 'normal');
        logla($db, 'teknik_servis', $sid, 'ekle', null, null, 'Geri al kararı: atölye servisi açıldı (Hasar #' . $hid . ')');
    }
    gorevOlustur($db, 'Makineyi getirt: ' . $makineAd . ' · ' . $kod,
        'Arıza kararı: makine geri alınacak (atölye servisi). Nakliyeyi planlayın.' . ($kapansin ? ' Kiralama da kapanacak (Kiralama Takip → İade).' : ' Kiralama açık kalır; arıza süresi kira indirimi önerisine yansır.'),
        'servis_getir', $sid, 'yuksek');
    $db->prepare("UPDATE hasar_degerlendirmeleri SET durum='incelemede', servis_id=?, kiralama_kapansin=? WHERE id=?")->execute([$sid, $kapansin, $hid]);
    $out['servis_id'] = $sid; $out['durum'] = 'incelemede';
    $out['mesaj'] = 'Geri al kararı: makine geri alınacak (atölye servisi) (servis #' . $sid . '); ofise "Makineyi getirt" görevi açıldı.' . ($kapansin ? ' Kiralamayı Kiralama Takip → İade ile kapatın.' : ' Kiralama açık kalır.');
    return $out;
}

function akIndirimKarar(PDO $db, array $r, array $b): array {
    $hid = (int)$r['id'];
    $s = $db->prepare('SELECT * FROM hasar_indirim_onerileri WHERE id=? AND degerlendirme_id=?'); $s->execute([(int)($b['oneri_id'] ?? 0), $hid]);
    $o = $s->fetch(PDO::FETCH_ASSOC);
    if (!$o) throw new DomainException('İndirim önerisi bulunamadı.');
    if (($r['kusur_sahibi'] ?? '') === 'musteri' && (string)($b['islem'] ?? '') === 'onayla') throw new DomainException('Kusur müşteride; kira indirimi uygulanmaz.');
    $k = akKiralama($db, (int)$r['kiralama_id']);
    if (!$k) throw new DomainException('Kiralama bulunamadı.');
    return _akIndirimUygula($db, $o, $k, $b, 'Hasar #' . $hid, $hid, null);
}

function _akIndirimUygula(PDO $db, array $o, array $k, array $b, string $ref, ?int $hid, ?int $sid): array {
    $simdi = date('Y-m-d H:i:s'); $kul = (string)($_SESSION['username'] ?? 'Sistem');
    if ($o['durum'] !== 'oneri') throw new DomainException('Bu öneri zaten sonuçlandı (' . $o['durum'] . ').');
    $islem = (string)($b['islem'] ?? ''); $sebep = mb_substr(trim((string)($b['sebep'] ?? '')), 0, 1000);
    $gecmis = function (string $olay, string $metin, array $veri) use ($db, $hid, $sid, $o): void {
        if ($hid) hdGecmis($db, $hid, $olay, $metin, $veri);
        else logla($db, 'hasar_indirim_onerileri', (int)$o['id'], 'guncelle', null, $veri, $metin . ' — Servis #' . (int)$sid);
    };
    if ($islem === 'reddet') {
        if ($sebep === '') throw new DomainException('Red sebebini yazın.');
        $db->prepare("UPDATE hasar_indirim_onerileri SET durum='reddedildi', karar_sebep=?, karar_veren=?, karar_zaman=? WHERE id=?")->execute([$sebep, $kul, $simdi, (int)$o['id']]);
        _akGorevKapat($db, (int)$o['gorev_id']);
        $gecmis('indirim_red', 'Kira indirimi önerisi reddedildi: ' . $sebep, ['oneri_id' => (int)$o['id']]);
        logla($db, 'hasar_indirim_onerileri', (int)$o['id'], 'guncelle', ['durum' => 'oneri'], ['durum' => 'reddedildi'], 'Kira indirimi REDDEDİLDİ — ' . $ref . ': ' . $sebep);
        return ['durum' => 'reddedildi', 'mesaj' => 'İndirim önerisi reddedildi.'];
    }
    if ($islem !== 'onayla') throw new DomainException('Geçersiz işlem: onayla ya da reddet.');
    $gun = isset($b['gun']) && is_numeric($b['gun']) ? (float)$b['gun'] : (float)$o['gun'];
    $oran = isset($b['oran']) && is_numeric($b['oran']) ? (float)$b['oran'] : (float)$o['oran'];
    if ($gun <= 0 || $gun > 3650) throw new DomainException('Gün sayısı 0\'dan büyük olmalı.');
    if ($oran <= 0 || $oran > 100) throw new DomainException('Oran 0 ile 100 arasında olmalı.');
    $degisti = abs($gun - (float)$o['gun']) > 0.001 || abs($oran - (float)$o['oran']) > 0.001;
    if ($degisti && $sebep === '') throw new DomainException('Öneriyi değiştirdiniz; sebebini yazın.');
    $tutar = round($gun * (float)$o['gunluk_ucret'] * $oran / 100, 2);
    if ($tutar <= 0) throw new DomainException('İndirim tutarı 0; kiralamada günlük ücret tanımlı değil.');
    $uygun = array_column(hfUygunHakedisler($db, $k), null, 'id');
    $hakedisId = (int)($b['hakedis_id'] ?? 0);
    $hk = $uygun[$hakedisId] ?? null;
    if (!$hk) throw new DomainException('Bekleyen hakedişi seçin (kiralamanın faturası kesilmemiş hakedişi). Yoksa önce dönem hakedişini oluşturun.');
    $hkKod = $hk['kod'] ?: '#' . $hakedisId;
    if ($hk['faturali']) throw new DomainException('Hakediş ' . $hkKod . ' faturası kesilmiş; kesilmiş faturaya dokunulmaz. İndirimi bekleyen (sonraki) hakedişe uygulayın.');
    if ($hk['para_birimi'] !== $o['para_birimi']) throw new DomainException('Hakediş ' . $hk['para_birimi'] . ', indirim ' . $o['para_birimi'] . ' cinsinden; aynı para biriminde hakediş seçin.');
    $h = $db->prepare('SELECT tutar FROM hakedisler WHERE id=?'); $h->execute([$hakedisId]);
    $mevcut = (float)$h->fetchColumn();
    if ($mevcut - $tutar < -0.005) throw new DomainException('İndirim (' . number_format($tutar, 2, ',', '.') . ') hakediş tutarını (' . number_format($mevcut, 2, ',', '.') . ') aşıyor; gün ya da oranı düşürün.');
    $gunM = akSayi($gun);
    $db->prepare("INSERT INTO hakedis_kalemleri(hakedis_id,tur,aciklama,miktar,birim,tutar) VALUES(?,'indirim',?,?,?,?)")
       ->execute([$hakedisId, 'Arıza kira indirimi: ' . $gunM . ' gün (%' . akSayi($oran) . ') · ' . $ref . ' · ' . ($k['kod'] ?: '#' . $k['id']), $gun, -round((float)$o['gunluk_ucret'] * $oran / 100, 2), -$tutar]);
    $kalemId = (int)$db->lastInsertId();
    hfHakedisTutarDegis($db, $hakedisId, -$tutar, 'arıza kira indirimi ' . $gunM . ' gün (' . $ref . ')');
    $onay = $db->prepare("UPDATE hasar_indirim_onerileri SET durum='onaylandi', onay_gun=?, onay_oran=?, onay_tutar=?, hakedis_id=?, hakedis_kalem_id=?, karar_sebep=?, karar_veren=?, karar_zaman=? WHERE id=? AND durum='oneri'");
    $onay->execute([$gun, $oran, $tutar, $hakedisId, $kalemId, $sebep ?: null, $kul, $simdi, (int)$o['id']]);
    if ($onay->rowCount() !== 1) throw new DomainException('Öneri bu sırada değişti (başka biri karar vermiş olabilir); sayfayı yenileyin.');
    _akGorevKapat($db, (int)$o['gorev_id']);
    $gecmis('indirim_onay', 'Kira indirimi onaylandı: ' . $gunM . ' gün → hakediş ' . $hkKod . ($degisti ? ' (öneri değiştirildi: ' . $sebep . ')' : '') . '.', ['oneri_id' => (int)$o['id'], 'gun' => $gun, 'hakedis_id' => $hakedisId]);
    logla($db, 'hasar_indirim_onerileri', (int)$o['id'], 'guncelle', ['durum' => 'oneri'], ['durum' => 'onaylandi', 'gun' => $gun, 'oran' => $oran, 'tutar' => $tutar, 'hakedis_id' => $hakedisId],
          'Kira indirimi ONAYLANDI — ' . $ref . ' · ' . $gunM . ' gün · ' . number_format($tutar, 2, ',', '.') . ' ' . $o['para_birimi'] . ' → ' . $hkKod . ($sebep !== '' ? ' · ' . $sebep : ''));
    return ['durum' => 'onaylandi', 'tutar' => $tutar, 'hakedis_kod' => $hkKod, 'mesaj' => 'İndirim onaylandı: ' . $gunM . ' gün, hakediş ' . $hkKod . ' tutarından düşüldü.'];
}

function akArizaVeri(PDO $db, array $r, bool $ticari): array {
    $k = akKiralama($db, (int)$r['kiralama_id']);
    $a = akAyar($db);
    $sure = akSaatFark($r['bildirim_zaman'] ?? null, $r['cozum_zaman'] ?? date('Y-m-d H:i:s'));
    $out = ['karar' => $r['ariza_karar'] ?? null, 'karar_ad' => AK_KARARLAR[$r['ariza_karar'] ?? ''] ?? null, 'karar_sebep' => $r['ariza_karar_sebep'] ?? null,
            'karar_zaman' => $r['ariza_karar_zaman'] ?? null, 'karar_kullanici' => $r['ariza_karar_kullanici'] ?? null,
            'kusur_sahibi' => $r['kusur_sahibi'] ?? null, 'kusur_ad' => AK_KUSUR[$r['kusur_sahibi'] ?? ''] ?? null, 'kusur_sebep' => $r['kusur_sebep'] ?? null,
            'kusur_zaman' => $r['kusur_zaman'] ?? null, 'kusur_kullanici' => $r['kusur_kullanici'] ?? null,
            'bildirim_zaman' => $r['bildirim_zaman'] ?? null, 'cozum_zaman' => $r['cozum_zaman'] ?? null, 'sure_saat' => $sure, 'sure_metni' => akSureMetni($sure),
            'esik_saat' => $a['esik_saat'], 'oran' => $a['oran'], 'esik_asildi' => $sure !== null && $sure > $a['esik_saat'],
            'makine_sahada' => $k && $k['durum'] === 'aktif', 'kiralama_id' => $k ? (int)$k['id'] : null, 'kiralama_kod' => $k['kod'] ?? null,
            'kiralama_kapansin' => (int)($r['kiralama_kapansin'] ?? 0), 'onarim_kalem_id' => $r['onarim_kalem_id'] ?? null, 'portal_arizasi' => hfArizaKaydiMi($r),
            'degisim_link' => $k ? 'kiralama-takip.html?degistir=' . (int)$k['id'] : null, 'saha_adres_varsayilan' => $k['lokasyon'] ?? null];
    if ($ticari) {
        $s = $db->prepare('SELECT * FROM hasar_indirim_onerileri WHERE degerlendirme_id=? ORDER BY id DESC'); $s->execute([(int)$r['id']]);
        $out['indirim'] = ['oneriler' => $s->fetchAll(PDO::FETCH_ASSOC), 'uygun_hakedisler' => $k ? hfUygunHakedisler($db, $k) : []];
    }
    return $out;
}
