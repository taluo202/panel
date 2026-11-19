<?php
declare(strict_types=1);

const MK_SABLONLAR = [
    'platform' => ['ad' => 'Platform', 'ana' => ['etiket' => 'Çalışma yüksekliği', 'birim' => 'm'], 'alanlar' => [
        ['k' => 'platform_yuksekligi', 'etiket' => 'Platform yüksekliği', 'birim' => 'm'],
        ['k' => 'yatay_erisim', 'etiket' => 'Yatay erişim', 'birim' => 'm'],
        ['k' => 'kapasite_kg', 'etiket' => 'Sepet / platform kapasitesi', 'birim' => 'kg'],
        ['k' => 'genislik_m', 'etiket' => 'Genişlik', 'birim' => 'm'],
        ['k' => 'agirlik_kg', 'etiket' => 'Ağırlık', 'birim' => 'kg'],
        ['k' => 'guc', 'etiket' => 'Güç / yakıt', 'secenekler' => ['Akülü', 'Dizel', 'Hibrit', 'Elektrik', 'Benzinli']]]],
    'forklift' => ['ad' => 'Forklift', 'ana' => ['etiket' => 'Kaldırma kapasitesi', 'birim' => 'ton'], 'alanlar' => [
        ['k' => 'kaldirma_yuksekligi', 'etiket' => 'Kaldırma yüksekliği', 'birim' => 'm'],
        ['k' => 'direk_tipi', 'etiket' => 'Direk tipi', 'secenekler' => ['Simplex', 'Duplex', 'Triplex']],
        ['k' => 'agirlik_kg', 'etiket' => 'Ağırlık', 'birim' => 'kg'],
        ['k' => 'guc', 'etiket' => 'Güç / yakıt', 'secenekler' => ['Dizel', 'LPG', 'Akülü']]]],
    'vinc' => ['ad' => 'Vinç', 'ana' => ['etiket' => 'Kaldırma kapasitesi', 'birim' => 'ton'], 'alanlar' => [
        ['k' => 'bom_uzunlugu', 'etiket' => 'Bom uzunluğu', 'birim' => 'm'],
        ['k' => 'max_erisim', 'etiket' => 'En fazla erişim', 'birim' => 'm'],
        ['k' => 'ehliyet', 'etiket' => 'Ehliyet sınıfı', 'secenekler' => ['B', 'C', 'CE']],
        ['k' => 'guc', 'etiket' => 'Güç / yakıt', 'secenekler' => ['Dizel', 'Benzinli']]]],
    'is_makinesi' => ['ad' => 'İş makinesi', 'ana' => ['etiket' => 'Çalışma ağırlığı', 'birim' => 'ton'], 'alanlar' => [
        ['k' => 'motor_gucu_hp', 'etiket' => 'Motor gücü', 'birim' => 'HP'],
        ['k' => 'kova_m3', 'etiket' => 'Kova hacmi', 'birim' => 'm³'],
        ['k' => 'kazi_derinligi', 'etiket' => 'Kazı derinliği', 'birim' => 'm'],
        ['k' => 'guc', 'etiket' => 'Güç / yakıt', 'secenekler' => ['Dizel']]]],
    'genel' => ['ad' => 'Genel', 'ana' => ['etiket' => 'Ölçü', 'birim' => ''], 'alanlar' => [
        ['k' => 'guc', 'etiket' => 'Güç / yakıt', 'secenekler' => ['Akülü', 'Dizel', 'Hibrit', 'Elektrik', 'Benzinli', 'LPG']]]],
];

function mkSema(PDO $db): void {
    static $hazir = [];
    $db->exec("CREATE TABLE IF NOT EXISTS makine_kategorileri (
      id INTEGER PRIMARY KEY AUTOINCREMENT, ad TEXT NOT NULL UNIQUE, sablon TEXT NOT NULL, kaynak TEXT NOT NULL DEFAULT 'elle',
      sira INTEGER NOT NULL DEFAULT 0, ana_grup TEXT, created_at TEXT DEFAULT (datetime('now','localtime')))");
    $k = spl_object_id($db);
    if (isset($hazir[$k])) return;
    $kol = array_column($db->query('PRAGMA table_info(makine_kategorileri)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('ana_grup', $kol, true)) $db->exec('ALTER TABLE makine_kategorileri ADD COLUMN ana_grup TEXT');
    $hazir[$k] = true;
}

function mkSablonTahmin(string $ad): string {
    $t = trArama($ad);
    if (str_contains($t, 'forklift')) return 'forklift';
    if (str_contains($t, 'vinc')) return 'vinc';
    if (str_contains($t, 'platform')) return 'platform';
    foreach (['kepce', 'ekskavator', 'loder', 'kazici', 'silindir', 'greyder'] as $k) if (str_contains($t, $k)) return 'is_makinesi';
    return 'genel';
}

function mkKatalogEsitle(PDO $db): void {
    mkSema($db);
    $ins = $db->prepare("INSERT OR IGNORE INTO makine_kategorileri(ad, sablon, kaynak, sira) VALUES(?,?,'katalog',?)");
    foreach ($db->query("SELECT kategori, MIN(sira) AS s FROM kiralama_urunler WHERE TRIM(COALESCE(kategori,''))<>'' GROUP BY kategori")->fetchAll(PDO::FETCH_ASSOC) as $r)
        $ins->execute([$r['kategori'], mkSablonTahmin((string)$r['kategori']), (int)$r['s']]);
}

function mkListe(PDO $db): array {
    mkKatalogEsitle($db);
    return array_map(fn($r) => ['ad' => $r['ad'], 'sablon' => isset(MK_SABLONLAR[$r['sablon']]) ? $r['sablon'] : 'genel', 'kaynak' => $r['kaynak'], 'ana_grup' => trim((string)($r['ana_grup'] ?? ''))],
        $db->query("SELECT ad, sablon, kaynak, ana_grup FROM makine_kategorileri ORDER BY (kaynak='katalog') DESC, sira, ad")->fetchAll(PDO::FETCH_ASSOC));
}

function mkAnaGrupYaz(PDO $db, string $ad, string $anaGrup): array {
    mkKatalogEsitle($db);
    $anaGrup = trim(preg_replace('/\s+/u', ' ', $anaGrup) ?? '');
    if (mb_strlen($anaGrup) > 80) throw new DomainException('Ana grup en fazla 80 karakter olabilir.');
    $q = $db->prepare('SELECT ad, sablon, kaynak, ana_grup FROM makine_kategorileri WHERE ad=?');
    $q->execute([$ad]);
    $eski = $q->fetch(PDO::FETCH_ASSOC);
    if (!$eski) throw new DomainException('Kategori listede yok: ' . $ad);
    $db->prepare('UPDATE makine_kategorileri SET ana_grup=? WHERE ad=?')->execute([$anaGrup !== '' ? $anaGrup : null, $ad]);
    return ['eski' => $eski, 'yeni' => ['ad' => $ad, 'ana_grup' => $anaGrup]];
}

function mkAnaGrupHaritasi(PDO $db): array {
    $out = [];
    foreach (mkListe($db) as $k) {
        $sablon = MK_SABLONLAR[$k['sablon']]['ad'] ?? 'Genel';
        $out[$k['ad']] = ['ana_grup' => $k['ana_grup'] !== '' ? $k['ana_grup'] : $sablon, 'kaynak' => $k['ana_grup'] !== '' ? 'katalog' : 'sablon'];
    }
    return $out;
}

function mkSablon(PDO $db, ?string $kategori): string {
    if ($kategori === null || trim($kategori) === '') return 'genel';
    mkSema($db);
    $q = $db->prepare('SELECT sablon FROM makine_kategorileri WHERE ad=?');
    $q->execute([$kategori]);
    $s = $q->fetchColumn();
    return ($s && isset(MK_SABLONLAR[$s])) ? $s : mkSablonTahmin($kategori);
}

function mkBirim(PDO $db, ?string $kategori): string { return MK_SABLONLAR[mkSablon($db, $kategori)]['ana']['birim']; }

function mkEkle(PDO $db, string $ad, string $sablon): array {
    mkKatalogEsitle($db);
    $ad = trim(preg_replace('/\s+/u', ' ', $ad) ?? '');
    if (mb_strlen($ad) < 2 || mb_strlen($ad) > 80) throw new DomainException('Kategori adı 2–80 karakter olmalı.');
    if (!isset(MK_SABLONLAR[$sablon])) throw new DomainException('Geçersiz alan şablonu.');
    foreach ($db->query('SELECT ad, sablon, kaynak FROM makine_kategorileri')->fetchAll(PDO::FETCH_ASSOC) as $r)
        if (trArama($r['ad']) === trArama($ad)) return $r + ['yeni' => false];
    $db->prepare("INSERT INTO makine_kategorileri(ad, sablon, kaynak, sira) VALUES(?,?,'elle',1000)")->execute([$ad, $sablon]);
    return ['ad' => $ad, 'sablon' => $sablon, 'kaynak' => 'elle', 'yeni' => true];
}

function mkOzellikler(string $sablon, $ham): array {
    if (is_string($ham)) $ham = json_decode($ham, true);
    if (!is_array($ham)) return [];
    $out = [];
    foreach (MK_SABLONLAR[$sablon]['alanlar'] ?? [] as $a) {
        $v = $ham[$a['k']] ?? null;
        if ($v === null || (is_string($v) && trim($v) === '')) continue;
        if (!is_scalar($v)) throw new DomainException($a['etiket'] . ': geçersiz değer.');
        if (isset($a['secenekler'])) {
            if (!in_array((string)$v, $a['secenekler'], true)) throw new DomainException($a['etiket'] . ': listeden seçin (' . implode(', ', $a['secenekler']) . ').');
            $out[$a['k']] = (string)$v;
            continue;
        }
        $s = str_replace(',', '.', trim((string)$v));
        if (!is_numeric($s) || (float)$s < 0) throw new DomainException($a['etiket'] . ': sayı girin.');
        $out[$a['k']] = (float)$s;
    }
    return $out;
}

function mkGovde(PDO $db, array $b, ?string $mevcut = null): ?array {
    if (!array_key_exists('kategori', $b) && !array_key_exists('ozellikler', $b)) return null;
    $kat = is_string($b['kategori'] ?? null) ? trim($b['kategori']) : '';
    if ($kat !== '' && $kat !== $mevcut) {
        mkKatalogEsitle($db);
        $q = $db->prepare('SELECT 1 FROM makine_kategorileri WHERE ad=?');
        $q->execute([$kat]);
        if (!$q->fetchColumn()) throw new DomainException('Kategori listede yok: ' . $kat);
    }
    $oz = mkOzellikler(mkSablon($db, $kat !== '' ? $kat : null), $b['ozellikler'] ?? null);
    $out = ['kategori' => $kat !== '' ? $kat : null, 'ozellikler' => $oz ? json_encode($oz, JSON_UNESCAPED_UNICODE) : null];
    if (array_key_exists('metraj', $b)) $out['metraj'] = metrajNormal($b['metraj']);
    return $out;
}

function mkYaz(PDO $db, string $tablo, int $id, ?array $g): void {
    if ($g === null) return;
    if (!in_array($tablo, ['manliftler', 'araclar'], true)) throw new InvalidArgumentException('Geçersiz tablo.');
    $set = 'kategori=?, ozellikler=?'; $p = [$g['kategori'], $g['ozellikler']];
    if (array_key_exists('metraj', $g)) { $set .= ', metraj=?'; $p[] = $g['metraj']; }
    $p[] = $id;
    $db->prepare("UPDATE $tablo SET $set WHERE id=?")->execute($p);
}

function makineKategoriAdi(PDO $db, array $m): ?string {
    $k = trim((string)($m['kategori'] ?? ''));
    return $k !== '' ? $k : makineKategorisi($db, $m['marka'] ?? null, $m['model'] ?? null);
}

function makineEkipmanAdi(PDO $db, ?string $tur, ?int $id): string {
    if (!$tur || !$id || !in_array($tur, ['arac', 'manlift'], true)) return '';
    $st = $db->prepare('SELECT marka, model, kategori FROM ' . ($tur === 'arac' ? 'araclar' : 'manliftler') . ' WHERE id = ?');
    $st->execute([$id]);
    $m = $st->fetch(PDO::FETCH_ASSOC);
    return $m ? trim(($m['marka'] ?? '') . ' ' . (makineKategoriAdi($db, $m) ?? '')) : '';
}

function mkGoc(PDO $db): int {
    if ($db->query("SELECT 1 FROM ayarlar WHERE anahtar='makine_kategori_goc'")->fetchColumn()) return 0;
    mkKatalogEsitle($db);
    $n = 0;
    foreach (['manliftler', 'araclar'] as $t) {
        $u = $db->prepare("UPDATE $t SET kategori=? WHERE id=? AND (kategori IS NULL OR kategori='')");
        foreach ($db->query("SELECT id, marka, model FROM $t WHERE kategori IS NULL OR kategori=''")->fetchAll(PDO::FETCH_ASSOC) as $m)
            if (($k = makineKategorisi($db, $m['marka'], $m['model'])) !== null) { $u->execute([$k, (int)$m['id']]); $n++; }
    }
    $db->prepare("INSERT OR REPLACE INTO ayarlar(anahtar, deger) VALUES('makine_kategori_goc', ?)")->execute([date('Y-m-d H:i:s')]);
    return $n;
}

function mkGuc(?string $ozellikler): ?string {
    $o = $ozellikler ? json_decode($ozellikler, true) : null;
    $g = is_array($o) ? trim((string)($o['guc'] ?? '')) : '';
    return $g !== '' ? trArama($g) : null;
}
