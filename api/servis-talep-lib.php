<?php
declare(strict_types=1);

const ST_KAYNAKLAR = ['portal' => 'Müşteri portalı', 'telefon' => 'Telefon'];
const ST_DURUMLAR = ['bekliyor' => 'Bekliyor', 'cevrildi' => 'Servis kaydına çevrildi', 'reddedildi' => 'Reddedildi'];

function stSema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS servis_talepleri (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        kaynak TEXT NOT NULL CHECK(kaynak IN ('portal','telefon')),
        musteri_id INTEGER NOT NULL,
        kontak_id INTEGER,
        kiralama_id INTEGER,
        makine_tur TEXT,
        makine_id INTEGER,
        musteri_makine_id INTEGER,
        makine_metin TEXT,
        sikayet TEXT NOT NULL,
        arayan_ad TEXT,
        arayan_tel TEXT,
        fotograflar TEXT NOT NULL DEFAULT '[]',
        durum TEXT NOT NULL DEFAULT 'bekliyor' CHECK(durum IN ('bekliyor','cevrildi','reddedildi')),
        servis_id INTEGER,
        gorev_id INTEGER,
        karar_veren TEXT,
        karar_notu TEXT,
        karar_tarihi TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')));
      CREATE INDEX IF NOT EXISTS idx_st_durum ON servis_talepleri(durum);
      CREATE INDEX IF NOT EXISTS idx_st_kiralama ON servis_talepleri(kiralama_id);");
}

function _stMakineAd(PDO $db, array $t): string {
    if (!empty($t['makine_tur']) && !empty($t['makine_id']) && in_array($t['makine_tur'], ['arac', 'manlift'], true)) {
        $b = makineBilgiToplu($db, [['tur' => $t['makine_tur'], 'id' => (int)$t['makine_id']]])[$t['makine_tur'] . ':' . (int)$t['makine_id']] ?? null;
        if ($b) {
            $parca = array_values(array_filter([trim((string)($b['kod'] ?? '')), trim(implode(' ', array_filter([(string)($b['marka'] ?? ''), (string)($b['model'] ?? '')]))), trim((string)($b['kategori'] ?? '')), trim((string)($b['seri_no'] ?? '')) !== '' ? 'SN ' . trim((string)$b['seri_no']) : '']));
            if ($parca) return implode(' · ', $parca);
        }
        $q = $db->prepare('SELECT ' . ($t['makine_tur'] === 'arac' ? 'plaka' : 'kod') . ' FROM ' . ($t['makine_tur'] === 'arac' ? 'araclar' : 'manliftler') . ' WHERE id=?');
        $q->execute([(int)$t['makine_id']]);
        if ($ad = $q->fetchColumn()) return (string)$ad;
    }
    if (!empty($t['musteri_makine_id'])) {
        require_once __DIR__ . '/saha-servis-lib.php';
        $q = $db->prepare('SELECT * FROM musteri_makineleri WHERE id=?'); $q->execute([(int)$t['musteri_makine_id']]);
        if ($m = $q->fetch(PDO::FETCH_ASSOC)) return ssMusteriMakineAd($m);
    }
    return trim((string)($t['makine_metin'] ?? '')) ?: 'Makine belirtilmedi';
}

function stTalepAc(PDO $db, array $v): int {
    $kaynak = (string)($v['kaynak'] ?? '');
    if (!isset(ST_KAYNAKLAR[$kaynak])) throw new DomainException('Talep kaynağı geçersiz.');
    $musteriId = (int)($v['musteri_id'] ?? 0);
    if ($musteriId < 1) throw new DomainException('Müşteri seçin.');
    $sikayet = mb_substr(trim((string)($v['sikayet'] ?? '')), 0, 2000);
    if (mb_strlen($sikayet) < 5) throw new DomainException('Şikâyeti yazın (en az 5 karakter).');
    $kiralamaId = (int)($v['kiralama_id'] ?? 0) ?: null;
    $makineTur = null; $makineId = null;
    if ($kiralamaId) {
        $q = $db->prepare('SELECT id, musteri_id, makine_tur, makine_id FROM kiralamalar WHERE id=?'); $q->execute([$kiralamaId]);
        $k = $q->fetch(PDO::FETCH_ASSOC);
        if (!$k || (int)$k['musteri_id'] !== $musteriId) throw new DomainException('Kiralama bu müşteriye ait değil.');
        $makineTur = $k['makine_tur'] ?: null; $makineId = (int)$k['makine_id'] ?: null;
        $v2 = $db->prepare("SELECT id FROM servis_talepleri WHERE kiralama_id=? AND durum='bekliyor' LIMIT 1"); $v2->execute([$kiralamaId]);
        if ($eski = $v2->fetchColumn()) throw new DomainException('Bu makine için bekleyen bir servis talebi zaten var (#' . (int)$eski . ').');
    } elseif (!empty($v['makine_tur']) && !empty($v['makine_id']) && in_array($v['makine_tur'], ['arac', 'manlift'], true)) {
        $makineTur = (string)$v['makine_tur']; $makineId = (int)$v['makine_id'];
    }
    $mmId = (int)($v['musteri_makine_id'] ?? 0) ?: null;
    if ($mmId) {
        $q = $db->prepare('SELECT musteri_id FROM musteri_makineleri WHERE id=?'); $q->execute([$mmId]);
        if ((int)$q->fetchColumn() !== $musteriId) throw new DomainException('Müşteri makinesi bu müşteriye ait değil.');
    }
    $fotolar = array_values(array_filter(array_map('strval', (array)($v['fotograflar'] ?? [])), fn($p) => $p !== ''));
    $simdi = date('Y-m-d H:i:s');
    $db->prepare('INSERT INTO servis_talepleri(kaynak,musteri_id,kontak_id,kiralama_id,makine_tur,makine_id,musteri_makine_id,makine_metin,sikayet,arayan_ad,arayan_tel,fotograflar,durum,created_at,updated_at)
                  VALUES(?,?,?,?,?,?,?,?,?,?,?,?,\'bekliyor\',?,?)')
       ->execute([$kaynak, $musteriId, (int)($v['kontak_id'] ?? 0) ?: null, $kiralamaId, $makineTur, $makineId, $mmId,
                  mb_substr(trim((string)($v['makine_metin'] ?? '')), 0, 200) ?: null, $sikayet,
                  mb_substr(trim((string)($v['arayan_ad'] ?? '')), 0, 120) ?: null, mb_substr(trim((string)($v['arayan_tel'] ?? '')), 0, 40) ?: null,
                  json_encode($fotolar, JSON_UNESCAPED_UNICODE), $simdi, $simdi]);
    $id = (int)$db->lastInsertId();
    $t = stTalep($db, $id);
    $gid = gorevOlustur($db, 'Servis talebi: ' . $t['makine_ad'] . ' — ' . $t['musteri_unvan'], ($t['arayan_ad'] ? $t['arayan_ad'] . ': ' : '') . $sikayet, 'servis_talep', $id, 'yuksek');
    $db->prepare('UPDATE servis_talepleri SET gorev_id=? WHERE id=?')->execute([$gid, $id]);
    logla($db, 'servis_talepleri', $id, 'ekle', null, ['kaynak' => $kaynak, 'musteri_id' => $musteriId, 'kiralama_id' => $kiralamaId, 'sikayet' => $sikayet],
          'Servis talebi alındı (' . ST_KAYNAKLAR[$kaynak] . ') — ' . $t['makine_ad'] . ' / ' . $t['musteri_unvan']);
    return $id;
}

function stSubeIzinli(array $t): bool {
    $k = subeKisitiHam();
    return $k === null || trim((string)($t['musteri_sube'] ?? '')) === $k;
}

function _stSubeKosulu(array &$params): string {
    $k = subeKisitiHam();
    if ($k === null) return '';
    $params[] = $k;
    return " AND TRIM(COALESCE((SELECT m.sube FROM musteriler m WHERE m.id=t.musteri_id),''))=?";
}

function _stSatir(PDO $db, array $t): array {
    $t['fotograflar'] = json_decode((string)($t['fotograflar'] ?? '[]'), true) ?: [];
    $t['makine_ad'] = _stMakineAd($db, $t);
    $q = $db->prepare('SELECT unvan, sube FROM musteriler WHERE id=?'); $q->execute([(int)$t['musteri_id']]);
    $mu = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    $t['musteri_unvan'] = (string)(($mu['unvan'] ?? '') ?: '#' . (int)$t['musteri_id']);
    $t['musteri_sube'] = $mu['sube'] ?? null;
    $t['kiralama_kod'] = null;
    if (!empty($t['kiralama_id'])) { $q = $db->prepare('SELECT kod FROM kiralamalar WHERE id=?'); $q->execute([(int)$t['kiralama_id']]); $t['kiralama_kod'] = $q->fetchColumn() ?: null; }
    $t['kaynak_ad'] = ST_KAYNAKLAR[$t['kaynak']] ?? $t['kaynak'];
    $t['servis'] = null;
    if (!empty($t['servis_id'])) {
        $q = $db->prepare('SELECT s.id, s.durum, s.teknisyen_id, s.planlanan_tarih, s.yer, s.saha_durum, s.tamamlanma, p.ad_soyad AS teknisyen_ad
                           FROM teknik_servis s LEFT JOIN personel p ON p.id=s.teknisyen_id WHERE s.id=?');
        $q->execute([(int)$t['servis_id']]);
        $t['servis'] = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    return $t;
}

function stTalep(PDO $db, int $id): ?array {
    $q = $db->prepare('SELECT * FROM servis_talepleri WHERE id=?'); $q->execute([$id]);
    $t = $q->fetch(PDO::FETCH_ASSOC);
    return $t ? _stSatir($db, $t) : null;
}

function stTalepListe(PDO $db, string $durum = 'bekliyor'): array {
    $params = []; $sube = _stSubeKosulu($params);
    if ($durum === 'sonuc') {
        $q = $db->prepare("SELECT t.* FROM servis_talepleri t WHERE t.durum IN ('cevrildi','reddedildi') AND t.created_at >= date('now','-90 days')" . $sube . ' ORDER BY t.id DESC');
    } else {
        $q = $db->prepare("SELECT t.* FROM servis_talepleri t WHERE t.durum='bekliyor'" . $sube . ' ORDER BY t.id DESC');
    }
    $q->execute($params);
    return array_map(fn($t) => _stSatir($db, $t), $q->fetchAll(PDO::FETCH_ASSOC));
}

function stMusteriTalepleri(PDO $db, int $musteriId): array {
    $q = $db->prepare('SELECT * FROM servis_talepleri WHERE musteri_id=? ORDER BY id DESC LIMIT 50'); $q->execute([$musteriId]);
    return array_map(fn($t) => _stSatir($db, $t), $q->fetchAll(PDO::FETCH_ASSOC));
}

function stPortalGorunum(array $t): array {
    $d = stPortalDurum($t);
    return ['id' => (int)$t['id'], 'kiralama_id' => $t['kiralama_id'] ? (int)$t['kiralama_id'] : null, 'kiralama_kod' => $t['kiralama_kod'] ?? null, 'makine_ad' => $t['makine_ad'] ?? null,
            'sikayet' => $t['sikayet'], 'created_at' => $t['created_at'], 'durum' => $t['durum'], 'etiket' => $d['etiket'], 'aciklama' => $d['aciklama'], 'foto_sayisi' => count($t['fotograflar'] ?? [])];
}

function stFotograflarYaz(PDO $db, int $id, array $yollar): void {
    $db->prepare('UPDATE servis_talepleri SET fotograflar=?, updated_at=? WHERE id=?')->execute([json_encode(array_values($yollar), JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'), $id]);
}

function stMusteriSecenekleri(PDO $db, int $musteriId): array {
    require_once __DIR__ . '/saha-servis-lib.php';
    $q = $db->prepare("SELECT id, kod, ekipman_adi, makine_tur, makine_id FROM kiralamalar WHERE musteri_id=? AND durum='aktif' AND gercek_bitis IS NULL ORDER BY baslangic DESC, id DESC LIMIT 200");
    $q->execute([$musteriId]);
    $kiralamalar = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $k) $kiralamalar[] = ['id' => (int)$k['id'], 'kod' => $k['kod'], 'ekipman_adi' => $k['ekipman_adi'], 'makine_ad' => _stMakineAd($db, $k + ['makine_metin' => $k['ekipman_adi']])];
    $m = $db->prepare('SELECT * FROM musteri_makineleri WHERE musteri_id=? AND COALESCE(aktif,1)=1 ORDER BY id'); $m->execute([$musteriId]);
    $makineler = array_map(fn($r) => ['id' => (int)$r['id'], 'ad' => ssMusteriMakineAd($r)], $m->fetchAll(PDO::FETCH_ASSOC));
    return ['kiralamalar' => $kiralamalar, 'makineler' => $makineler];
}

function stBekleyenSayi(PDO $db): int {
    try { $params = []; $sube = _stSubeKosulu($params); $q = $db->prepare("SELECT COUNT(*) FROM servis_talepleri t WHERE t.durum='bekliyor'" . $sube); $q->execute($params); return (int)$q->fetchColumn(); }
    catch (\Throwable $e) { return 0; }
}

function _stGorevKapat(PDO $db, array $t): void {
    if (!empty($t['gorev_id'])) $db->prepare("UPDATE gorevler SET durum='tamamlandi', tamamlanma=?, updated_at=? WHERE id=? AND durum='acik'")->execute([date('Y-m-d'), date('Y-m-d H:i:s'), (int)$t['gorev_id']]);
}

function stTalepReddet(PDO $db, int $id, string $gerekce): array {
    $t = stTalep($db, $id);
    if (!$t) throw new DomainException('Talep bulunamadı.');
    if ($t['durum'] !== 'bekliyor') throw new DomainException('Talep zaten sonuçlandı.');
    $gerekce = mb_substr(trim($gerekce), 0, 1000);
    if (mb_strlen($gerekce) < 5) throw new DomainException('Red gerekçesini yazın (en az 5 karakter).');
    $simdi = date('Y-m-d H:i:s');
    $u = $db->prepare("UPDATE servis_talepleri SET durum='reddedildi', karar_veren=?, karar_notu=?, karar_tarihi=?, updated_at=? WHERE id=? AND durum='bekliyor'");
    $u->execute([(string)($_SESSION['username'] ?? 'Sistem'), $gerekce, $simdi, $simdi, $id]);
    if ($u->rowCount() !== 1) throw new DomainException('Talep zaten sonuçlandı.');
    _stGorevKapat($db, $t);
    logla($db, 'servis_talepleri', $id, 'guncelle', ['durum' => 'bekliyor'], ['durum' => 'reddedildi', 'karar_notu' => $gerekce], 'Servis talebi reddedildi — ' . $gerekce);
    return ['id' => $id, 'durum' => 'reddedildi'];
}

function stTalepBagla(PDO $db, int $talepId, int $servisId): void {
    $t = stTalep($db, $talepId);
    if (!$t) throw new DomainException('Talep bulunamadı.');
    if ($t['durum'] !== 'bekliyor') throw new DomainException('Talep zaten sonuçlandı; yeni servis açılmaz.');
    if (!stSubeIzinli($t)) throw new DomainException('Bu talep başka bir şubeye ait; erişim yetkiniz yok.');
    $sq = $db->prepare('SELECT id, kiralama_id, musteri_id FROM teknik_servis WHERE id=?'); $sq->execute([$servisId]);
    $sv = $sq->fetch(PDO::FETCH_ASSOC);
    if (!$sv) throw new DomainException('Servis kaydı bulunamadı.');
    if (!empty($t['kiralama_id']) && !empty($sv['kiralama_id']) && (int)$t['kiralama_id'] !== (int)$sv['kiralama_id']) throw new DomainException('Talep ile servis kaydı uyuşmuyor: talep ' . ($t['kiralama_kod'] ?: '#' . (int)$t['kiralama_id']) . ' kiralamasına ait.');
    $servisMusteri = (int)($sv['musteri_id'] ?? 0);
    if (!$servisMusteri && !empty($sv['kiralama_id'])) { $kq = $db->prepare('SELECT musteri_id FROM kiralamalar WHERE id=?'); $kq->execute([(int)$sv['kiralama_id']]); $servisMusteri = (int)$kq->fetchColumn(); }
    if ($servisMusteri && (int)$t['musteri_id'] !== $servisMusteri) throw new DomainException('Talep ile servis kaydı uyuşmuyor: talep ' . $t['musteri_unvan'] . ' müşterisine ait.');
    $simdi = date('Y-m-d H:i:s');
    $ins = $db->prepare('INSERT INTO teknik_servis_fotograflari(servis_id,dosya_yolu,kaynak,created_at) VALUES(?,?,?,?)');
    $var = $db->prepare('SELECT 1 FROM teknik_servis_fotograflari WHERE servis_id=? AND dosya_yolu=? LIMIT 1');
    foreach ($t['fotograflar'] as $yol) { $var->execute([$servisId, $yol]); if (!$var->fetchColumn()) $ins->execute([$servisId, $yol, $t['kaynak'], $simdi]); }
    $db->prepare('UPDATE teknik_servis SET kaynak=?, portal_kontak_id=COALESCE(portal_kontak_id,?), updated_at=? WHERE id=?')->execute([$t['kaynak'], $t['kontak_id'] ? (int)$t['kontak_id'] : null, $simdi, $servisId]);
    $u = $db->prepare("UPDATE servis_talepleri SET durum='cevrildi', servis_id=?, karar_veren=?, karar_tarihi=?, updated_at=? WHERE id=? AND durum='bekliyor'");
    $u->execute([$servisId, (string)($_SESSION['username'] ?? 'Sistem'), $simdi, $simdi, $talepId]);
    if ($u->rowCount() !== 1) throw new DomainException('Talep zaten sonuçlandı; yeni servis açılmaz.');
    _stGorevKapat($db, $t);
    logla($db, 'servis_talepleri', $talepId, 'guncelle', ['durum' => 'bekliyor'], ['durum' => 'cevrildi', 'servis_id' => $servisId], 'Servis talebi servis kaydına çevrildi — Servis #' . $servisId);
}

function stServisSilindi(PDO $db, int $servisId): void {
    $q = $db->prepare("SELECT * FROM servis_talepleri WHERE servis_id=? AND durum='cevrildi'"); $q->execute([$servisId]);
    $simdi = date('Y-m-d H:i:s');
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $db->prepare("UPDATE servis_talepleri SET durum='bekliyor', servis_id=NULL, karar_veren=NULL, karar_tarihi=NULL, updated_at=? WHERE id=?")->execute([$simdi, (int)$t['id']]);
        if (!empty($t['gorev_id'])) $db->prepare("UPDATE gorevler SET durum='acik', tamamlanma=NULL, updated_at=? WHERE id=?")->execute([$simdi, (int)$t['gorev_id']]);
        logla($db, 'servis_talepleri', (int)$t['id'], 'guncelle', ['durum' => 'cevrildi', 'servis_id' => $servisId], ['durum' => 'bekliyor', 'servis_id' => null], 'Bağlı servis #' . $servisId . ' silindi; talep yeniden bekliyor');
    }
}

function stPortalDurum(array $t): array {
    $durum = (string)($t['durum'] ?? 'bekliyor');
    if ($durum === 'reddedildi') return ['kod' => 'reddedildi', 'etiket' => 'Reddedildi', 'aciklama' => trim((string)($t['karar_notu'] ?? '')) ?: null];
    if ($durum !== 'cevrildi') return ['kod' => 'alindi', 'etiket' => 'Talep alındı', 'aciklama' => null];
    $s = is_array($t['servis'] ?? null) ? $t['servis'] : [];
    if (($s['durum'] ?? '') === 'tamamlandi') return ['kod' => 'tamamlandi', 'etiket' => 'Tamamlandı', 'aciklama' => null];
    $teknisyen = trim((string)($s['teknisyen_ad'] ?? '')); $tarih = trim((string)($s['planlanan_tarih'] ?? ''));
    $parca = [];
    if ($teknisyen !== '') $parca[] = 'Teknisyen: ' . $teknisyen;
    if ($tarih !== '' && ($ts = strtotime($tarih))) $parca[] = date('d.m.Y', $ts);
    return ['kod' => 'planlandi', 'etiket' => 'Servis planlandı', 'aciklama' => $parca ? implode(' · ', $parca) : 'Servis kaydı açıldı'];
}
