<?php
declare(strict_types=1);

const TC_ACIK_TEKLIF_DURUMLARI = ['taslak', 'gonderildi', 'onaylandi'];
const TC_SARTLAR_AZAMI = 20000;

function tcSartlar(array $body, ?string $mevcut = null): ?string {
    if (!array_key_exists('sartlar', $body) || $body['sartlar'] === null) return $mevcut;
    $v = trim(str_replace(["\r\n", "\r"], "\n", metin($body['sartlar'])));
    if (mb_strlen($v) > TC_SARTLAR_AZAMI) throw new DomainException('Teklif şartları en fazla ' . number_format(TC_SARTLAR_AZAMI, 0, ',', '.') . ' karakter olabilir.');
    return $v;
}

function tcTeklifBaslangic(array $t, ?string $bugun = null): string {
    foreach (['planlanan_baslangic', 'tarih'] as $a) {
        $v = trim((string)($t[$a] ?? ''));
        if ($v !== '') return substr($v, 0, 10);
    }
    return $bugun ?? date('Y-m-d');
}

function tcAralik(string $bas, float $sure, ?string $tip): array {
    $gun = max(1, (int)round($sure * kiraBirimGun($tip)));
    $bit = (new DateTime($bas))->modify('+' . ($gun - 1) . ' day')->format('Y-m-d');
    return ['bas' => $bas, 'bit' => $bit, 'gun' => $gun];
}

function tcOlcuMetni(?float $m): string {
    return $m === null ? '' : rtrim(rtrim(number_format($m, 2, '.', ''), '0'), '.');
}

function tcCesitAdi(PDO $db, string $kategori, ?float $metraj): string {
    if ($metraj === null) return $kategori;
    $birim = mkBirim($db, $kategori);
    return trim($kategori . ' ' . tcOlcuMetni($metraj) . ($birim !== '' ? ' ' . $birim : ''));
}

function tcKategoriVar(PDO $db, string $kategori): bool {
    mkKatalogEsitle($db);
    $q = $db->prepare('SELECT 1 FROM makine_kategorileri WHERE ad=?');
    $q->execute([$kategori]);
    return (bool)$q->fetchColumn();
}

function tcKalemNormalize(PDO $db, array $k): array {
    $tur = (($k['kalem_turu'] ?? 'serbest') === 'cesit') ? 'cesit' : 'serbest';
    $aciklama = trim(metin($k['aciklama'] ?? ''));
    $tip = in_array($k['kira_tipi'] ?? '', kiraTipleri(), true) ? (string)$k['kira_tipi'] : 'gunluk';
    $out = ['kalem_turu' => $tur, 'kategori' => null, 'metraj' => null, 'aciklama' => $aciklama, 'kira_tipi' => $tip,
            'miktar' => max(0, (float)($k['miktar'] ?? 1)), 'sure' => max(0, (float)($k['sure'] ?? 1)),
            'birim_ucret' => max(0, (float)($k['birim_ucret'] ?? 0))];
    if ($tur !== 'cesit') return $out;
    $kategori = trim(metin($k['kategori'] ?? ''));
    if ($kategori === '') throw new DomainException('Çeşit kaleminde kategori seçilmelidir.');
    if (!tcKategoriVar($db, $kategori)) throw new DomainException('Kategori listede yok: ' . $kategori);
    $metraj = metrajSayi($k['metraj'] ?? null);
    if (($k['metraj'] ?? '') !== '' && $k['metraj'] !== null && $metraj === null) throw new DomainException('Ölçü sayı olmalı (örn. 12 ya da 12,5).');
    $out['kategori'] = $kategori;
    $out['metraj'] = $metraj;
    $out['miktar'] = max(1, (int)round((float)($k['miktar'] ?? 1)));
    if ($out['aciklama'] === '') $out['aciklama'] = tcCesitAdi($db, $kategori, $metraj);
    return $out;
}

function tcFilo(PDO $db, ?string $kategori, ?float $metraj, ?string $kisit = null, ?array $liste = null): array {
    $out = [];
    foreach ($liste ?? makineListesi($db) as $m) {
        if ((int)($m['aktif'] ?? 1) !== 1) continue;
        if (in_array((string)($m['durum_ham'] ?? ''), KIRALANAMAZ_DURUMLAR, true)) continue;
        if ($kategori !== null && trim((string)($m['katalog_kategori'] ?? '')) !== $kategori) continue;
        if ($metraj !== null && (!is_numeric($m['metraj'] ?? null) || abs((float)$m['metraj'] - $metraj) > 0.001)) continue;
        if ($kisit !== null && trim((string)($m['sube'] ?? '')) !== $kisit) continue;
        $out[] = $m;
    }
    return $out;
}

function tcAcikServis(PDO $db, string $tur, int $id, string $bas, ?string $bit): ?array {
    $st = $db->prepare("SELECT tip, tarih, tamamlanma FROM teknik_servis WHERE makine_tur=? AND makine_id=? AND durum<>'tamamlandi'
                        AND (tamamlanma IS NULL OR tamamlanma='' OR tamamlanma>=?) AND (? IS NULL OR tarih IS NULL OR tarih='' OR tarih<=?)
                        ORDER BY tarih LIMIT 1");
    $st->execute([$tur, $id, $bas, $bit, $bit]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function tcMakineUygunluk(PDO $db, string $tur, int $id, string $bas, ?string $bit): array {
    $k = kiralamaKaydiKontrol($db, $tur, $id, $bas, $bit);
    if ($k['engel'] !== null) return ['uygun' => false, 'neden' => 'engel', 'mesaj' => $k['engel'], 'cakisma' => []];
    if ($k['cakisma']) {
        require_once __DIR__ . '/hizli-kiralama-lib.php';
        $maskeli = hkCakismaMaskele($k['cakisma'], subeKisiti());
        $c = $maskeli[0];
        $mesaj = 'Bu tarihlerde kirada: ' . ($c['kod'] ?: 'Kayıtlı rezervasyon') . ' (' . $c['baslangic'] . ' → ' . ($c['bitis'] ?: 'açık uç') . ')';
        return ['uygun' => false, 'neden' => 'kirada', 'mesaj' => $mesaj, 'cakisma' => $maskeli];
    }
    if ($s = tcAcikServis($db, $tur, $id, $bas, $bit)) {
        $mesaj = 'Serviste' . (!empty($s['tamamlanma']) ? ' (' . tarihGoster($s['tamamlanma']) . ' tarihine kadar)' : ' (bitiş belirsiz)');
        return ['uygun' => false, 'neden' => 'serviste', 'mesaj' => $mesaj, 'cakisma' => []];
    }
    return ['uygun' => true, 'neden' => null, 'mesaj' => null, 'cakisma' => []];
}

function tcAcikTalepler(PDO $db, string $bas, ?string $bit, int $haricTeklifId = 0, ?string $kisit = null): array {
    $ph = implode(',', array_fill(0, count(TC_ACIK_TEKLIF_DURUMLARI), '?'));
    $sql = "SELECT k.kategori, k.metraj, k.miktar, k.sure, k.kira_tipi, t.id AS teklif_id, t.kod, t.tarih, t.planlanan_baslangic, t.sube
            FROM teklif_kalemleri k JOIN teklifler t ON t.id = k.teklif_id
            WHERE k.kalem_turu='cesit' AND t.durum IN ($ph) AND t.id<>?";
    $p = array_merge(TC_ACIK_TEKLIF_DURUMLARI, [$haricTeklifId]);
    if ($kisit !== null) { $sql .= ' AND t.sube=?'; $p[] = $kisit; }
    $st = $db->prepare($sql);
    $st->execute($p);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $kat = trim((string)($r['kategori'] ?? ''));
        if ($kat === '') continue;
        $ar = tcAralik(tcTeklifBaslangic($r), (float)$r['sure'], $r['kira_tipi']);
        if (!tarihCakisir($bas, $bit, $ar['bas'], $ar['bit'])) continue;
        $metraj = is_numeric($r['metraj']) ? (float)$r['metraj'] : null;
        $key = $kat . '|' . tcOlcuMetni($metraj);
        $o = $out[$key] ?? ['kategori' => $kat, 'metraj' => $metraj, 'adet' => 0, 'teklifler' => []];
        $o['adet'] += max(1, (int)round((float)$r['miktar']));
        $kod = (string)($r['kod'] ?: ('#' . $r['teklif_id']));
        if (!in_array($kod, $o['teklifler'], true)) $o['teklifler'][] = $kod;
        $out[$key] = $o;
    }
    return $out;
}

function tcMusaitAdet(PDO $db, string $kategori, ?float $metraj, string $bas, ?string $bit, int $haricTeklifId = 0, ?string $kisit = null): array {
    $makineler = [];
    $musait = 0;
    foreach (tcFilo($db, $kategori, $metraj, $kisit) as $m) {
        $u = tcMakineUygunluk($db, (string)$m['tur'], (int)$m['id'], $bas, $bit);
        if ($u['uygun']) $musait++;
        $makineler[] = [
            'tur' => $m['tur'], 'id' => (int)$m['id'], 'kod' => $m['kod'] ?? null, 'marka' => $m['marka'] ?? null, 'model' => $m['model'] ?? null,
            'kategori' => $m['katalog_kategori'] ?? null, 'metraj' => is_numeric($m['metraj'] ?? null) ? (float)$m['metraj'] : null,
            'olcu_birim' => $m['olcu_birim'] ?? null, 'seri_no' => $m['seri_no'] ?? null, 'makine_kodu' => $m['makine_kodu'] ?? null,
            'sube' => $m['sube'] ?? null, 'durum' => $m['durum'] ?? null, 'temin_sekli' => $m['temin_sekli'] ?? 'stok',
            'uygun' => $u['uygun'], 'neden' => $u['neden'], 'mesaj' => $u['mesaj'],
        ];
    }
    usort($makineler, fn($a, $b) => [$b['uygun'], $a['metraj'] ?? 0, $a['kod'] ?? ''] <=> [$a['uygun'], $b['metraj'] ?? 0, $b['kod'] ?? '']);
    $talep = tcAcikTalepler($db, $bas, $bit, $haricTeklifId, $kisit);
    $acik = 0; $acikTeklifler = [];
    foreach ($talep as $t) {
        if ($t['kategori'] !== $kategori) continue;
        if ($metraj !== null && ($t['metraj'] === null || abs($t['metraj'] - $metraj) > 0.001)) continue;
        $acik += $t['adet'];
        $acikTeklifler = array_values(array_unique(array_merge($acikTeklifler, $t['teklifler'])));
    }
    return ['kategori' => $kategori, 'metraj' => $metraj, 'olcu_birim' => mkBirim($db, $kategori), 'baslangic' => $bas, 'bitis' => $bit,
            'filo' => count($makineler), 'musait' => $musait, 'acik_teklif_adet' => $acik, 'net' => $musait - $acik,
            'acik_teklifler' => $acikTeklifler, 'makineler' => $makineler];
}

function tcEksikYukseklikler(PDO $db, string $bas, string $bit, ?string $kisit = null): array {
    $liste = makineListesi($db);
    $filo = tcFilo($db, null, null, $kisit, $liste);
    $satir = [];
    foreach ($filo as $m) {
        $kat = trim((string)($m['katalog_kategori'] ?? ''));
        if ($kat === '') continue;
        $metraj = is_numeric($m['metraj'] ?? null) ? (float)$m['metraj'] : null;
        $key = $kat . '|' . tcOlcuMetni($metraj);
        $s = $satir[$key] ?? ['kategori' => $kat, 'metraj' => $metraj, 'olcu_birim' => $m['olcu_birim'] ?? mkBirim($db, $kat),
                              'filo' => 0, 'musait' => 0, 'talep' => 0, 'eksik' => 0, 'teklifler' => []];
        $s['filo']++;
        if (tcMakineUygunluk($db, (string)$m['tur'], (int)$m['id'], $bas, $bit)['uygun']) $s['musait']++;
        $satir[$key] = $s;
    }
    foreach (tcAcikTalepler($db, $bas, $bit, 0, $kisit) as $key => $t) {
        $s = $satir[$key] ?? ['kategori' => $t['kategori'], 'metraj' => $t['metraj'], 'olcu_birim' => mkBirim($db, $t['kategori']),
                              'filo' => 0, 'musait' => 0, 'talep' => 0, 'eksik' => 0, 'teklifler' => []];
        $s['talep'] += $t['adet'];
        $s['teklifler'] = array_values(array_unique(array_merge($s['teklifler'], $t['teklifler'])));
        $satir[$key] = $s;
    }
    foreach ($satir as &$s) $s['eksik'] = max(0, $s['talep'] - $s['musait']);
    unset($s);
    $out = array_values($satir);
    usort($out, fn($a, $b) => [$b['eksik'], $b['talep'], $a['kategori'], $a['metraj'] ?? 0] <=> [$a['eksik'], $a['talep'], $b['kategori'], $b['metraj'] ?? 0]);
    return ['baslangic' => $bas, 'bitis' => $bit, 'satirlar' => $out,
            'eksik_toplam' => array_sum(array_column($out, 'eksik')), 'talep_toplam' => array_sum(array_column($out, 'talep'))];
}

function tcAtamalariCoz($ham, array $kalemler): array {
    $cesit = [];
    foreach ($kalemler as $k) if (($k['kalem_turu'] ?? 'serbest') === 'cesit') $cesit[(int)$k['id']] = $k;
    $out = ['atamalar' => [], 'hata' => null];
    if (!is_array($ham)) return $out;
    $gorulen = [];
    foreach ($ham as $kalemId => $liste) {
        $kalemId = (int)$kalemId;
        if (!isset($cesit[$kalemId])) { $out['hata'] = 'Atama yapılan kalem bu teklifin çeşit kalemi değil (#' . $kalemId . ').'; return $out; }
        if (!is_array($liste)) continue;
        foreach ($liste as $a) {
            if (!is_array($a)) continue;
            $tur = $a['makine_tur'] ?? '';
            $id = (int)($a['makine_id'] ?? 0);
            if ($id < 1 && $tur === '') continue;
            if (!in_array($tur, ['manlift', 'arac'], true) || $id < 1) { $out['hata'] = 'Geçersiz makine seçimi.'; return $out; }
            $key = $tur . ':' . $id;
            if (isset($gorulen[$key])) { $out['hata'] = 'Aynı makine iki adede atanmış; bir makine aynı tarihlerde iki kez kiralanamaz.'; return $out; }
            $gorulen[$key] = true;
            $out['atamalar'][$kalemId][] = ['tur' => $tur, 'id' => $id];
        }
    }
    return $out;
}

function tcMakineKunye(PDO $db, string $tur, int $id): array {
    $kunye = ['metraj' => null, 'seri_no' => null, 'makine_kodu' => null];
    if (!in_array($tur, ['arac', 'manlift'], true) || $id < 1) return $kunye;
    $sutun = $tur === 'arac' ? 'makine_kodu' : 'metraj, seri_no, makine_kodu';
    $st = $db->prepare("SELECT $sutun FROM " . ($tur === 'arac' ? 'araclar' : 'manliftler') . ' WHERE id=?');
    $st->execute([$id]);
    $m = $st->fetch(PDO::FETCH_ASSOC);
    if (!$m) return $kunye;
    return ['metraj' => metrajSayi($m['metraj'] ?? null), 'seri_no' => ($m['seri_no'] ?? null) ?: null, 'makine_kodu' => ($m['makine_kodu'] ?? null) ?: null];
}
