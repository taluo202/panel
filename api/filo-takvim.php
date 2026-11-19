<?php
declare(strict_types=1);

function filoTakvimVerisi(PDO $db, array $filtre, ?string $kisit, bool $servisDetay = true, ?string $bugun = null): array {

$bugun = $bugun ?? date('Y-m-d');
$bas = dateStr('baslangic', $filtre) ?: $bugun;
$gun = (int)($filtre['gun'] ?? 30);
if ($gun < 7)   $gun = 7;
if ($gun > 120) $gun = 120;
$bit = date('Y-m-d', strtotime($bas . ' +' . ($gun - 1) . ' days'));

$fSube = trim(metin($filtre['sube'] ?? ''));
$fSube = $kisit ?? $fSube;
$fTur  = trim(metin($filtre['tur'] ?? ''));

$fMarka    = trim(metin($filtre['marka'] ?? ''));
$fKategori = trim(metin($filtre['kategori'] ?? ''));
$fMetrajMin = ($filtre['metraj_min'] ?? '') !== '' ? (float)$filtre['metraj_min'] : null;
$fMetrajMax = ($filtre['metraj_max'] ?? '') !== '' ? (float)$filtre['metraj_max'] : null;

$makineler = makineListesi($db);

$_subeKisit = $kisit;
if ($_subeKisit !== null) {
    $makineler = array_values(array_filter($makineler,
        fn($mk) => trim((string)($mk['sube'] ?? '')) === $_subeKisit));
}

$subeSet = []; $markaSet = []; $kategoriSet = []; $metrajSet = [];
$metrajMin = null; $metrajMax = null;
foreach ($makineler as $mk) {
    $sb = trim((string)($mk['sube'] ?? ''));            if ($sb !== '') $subeSet[$sb] = true;
    $mrk = trim((string)($mk['marka'] ?? ''));          if ($mrk !== '') $markaSet[$mrk] = true;
    $kat = trim((string)($mk['katalog_kategori'] ?? '')); if ($kat !== '') $kategoriSet[$kat] = true;
    if (is_numeric($mk['metraj'] ?? null)) {
        $mv = (float)$mk['metraj'];
        $metrajMin = ($metrajMin === null) ? $mv : min($metrajMin, $mv);
        $metrajMax = ($metrajMax === null) ? $mv : max($metrajMax, $mv);
        $birim = ($mk['olcu_birim'] ?? '') ?: (str_contains(trArama($mk['katalog_kategori'] ?? ''), 'forklift') ? 'ton' : 'm');
        $sayiMetni = rtrim(rtrim(number_format($mv, 2, '.', ''), '0'), '.');
        $metrajSet["$mv|$birim"] = ['deger' => $mv, 'etiket' => $sayiMetni . ' ' . $birim];
    }
}
$subeList = array_keys($subeSet);         sort($subeList);
$markaList = array_keys($markaSet);       sort($markaList, SORT_FLAG_CASE | SORT_STRING);
$kategoriList = array_keys($kategoriSet); sort($kategoriList, SORT_FLAG_CASE | SORT_STRING);
$metrajList = array_values($metrajSet);
usort($metrajList, fn($a, $b) => $a['deger'] <=> $b['deger']);

$gunEkle = fn(?string $t, int $g = 1): ?string => $t ? date('Y-m-d', strtotime($t . ' +' . $g . ' day')) : null;

$ks = $db->prepare("
    SELECT k.id, k.makine_tur, k.makine_id, k.kod, k.baslangic, k.bitis, k.gercek_bitis, k.durum, k.lokasyon, k.operatorlu,
           k.sube, k.onay_durumu,
           m.unvan AS musteri
    FROM kiralamalar k
    LEFT JOIN musteriler m ON m.id = k.musteri_id
    WHERE k.makine_tur IS NOT NULL AND k.makine_id IS NOT NULL AND k.durum<>'iptal'
      AND k.baslangic <= :pencereBit
      AND (COALESCE(NULLIF(k.gercek_bitis,''), NULLIF(k.bitis,'')) IS NULL
           OR COALESCE(NULLIF(k.gercek_bitis,''), NULLIF(k.bitis,'')) >= :pencereBas
           OR (k.durum = 'aktif' AND NULLIF(k.gercek_bitis,'') IS NULL))
");
$ks->execute([':pencereBas' => $bas, ':pencereBit' => $bit]);
$kByMakine = [];
foreach ($ks->fetchAll() as $k) {
    $kBitis  = kiralamaCakismaBitisi($k, $bugun);
    $gecikti = kiralamaIadeGecikti($k, $bugun);
    $kEnd = $kBitis ?: $bit;
    if (!tarihCakisir($k['baslangic'], $kBitis, $bas, $gunEkle($bit))) continue;
    $key = $k['makine_tur'] . ':' . $k['makine_id'];
    $detayGizli = $kisit !== null && trim((string)$k['sube']) !== $kisit;
    $acik = $kBitis === null;
    $kByMakine[$key][] = [
        'id'         => $detayGizli ? 0 : (int)$k['id'],
        'kod'        => $detayGizli ? null : $k['kod'],
        'musteri'    => $detayGizli ? 'Başka şube rezervasyonu' : $k['musteri'],
        'lokasyon'   => $detayGizli ? null : $k['lokasyon'],
        'baslangic'  => $k['baslangic'],
        'bitis'      => $kEnd,
        'bitis_haric'=> $gunEkle($kEnd),
        'musait_gun' => $kEnd,
        'acik'       => $acik,
        'iade_gecikti' => $gecikti,
        'planli_bitis' => $gecikti ? $k['bitis'] : null,
        'operatorlu' => $detayGizli ? 0 : (int)$k['operatorlu'],
        'durum'      => $k['durum'],
        'detay_gizli'=> $detayGizli,
        'onay_durumu'=> $detayGizli ? null : ($k['onay_durumu'] ?: 'gerekmiyor'),
        'rezervasyon_engeli' => $k['durum'] === 'aktif',
        'gun'        => max(1, (int)floor((strtotime($gecikti ? $bugun : $kEnd) - strtotime($k['baslangic'])) / 86400) + 1),
    ];
}

$sByMakine = [];
try {
    $ss = $db->query("
        SELECT id, makine_tur, makine_id, tip, tarih, tamamlanma, durum, ariza
        FROM teknik_servis
        WHERE makine_tur IS NOT NULL AND makine_id IS NOT NULL AND durum<>'tamamlandi'
    ")->fetchAll();
    foreach ($ss as $s) {
        $sAcik = empty($s['tamamlanma']);
        $sEnd = $sAcik ? $bit : $s['tamamlanma'];
        $sBas = $s['tarih'] ?: $bas;
        if ($sBas > $bit || $sEnd < $bas) continue;
        $key = $s['makine_tur'] . ':' . $s['makine_id'];
        $sByMakine[$key][] = [
            'id'         => $servisDetay ? (int)$s['id'] : 0,
            'detay_gizli'=> !$servisDetay,
            'tamamlanma' => $s['tamamlanma'],
            'tip'        => $s['tip'],
            'durum'      => $s['durum'],
            'ariza'      => $servisDetay ? $s['ariza'] : 'Teknik servis',
            'baslangic'  => $sBas,
            'bitis'      => $sEnd,
            'bitis_haric'=> $gunEkle($sEnd),
            'acik'       => $sAcik,
            'gun'        => $sAcik ? null : max(1, (int)floor((strtotime($sEnd) - strtotime($sBas)) / 86400) + 1),
        ];
    }
} catch (\Exception $e) { hataGunlugu('filo_takvim.servis', $e, true); }

$pByMakine = [];
try {
    $planEkle = function (string $key, string $ad, ?string $tarih) use (&$pByMakine, $bas, $bit, $gunEkle) {
        if (!$tarih || $tarih < $bas || $tarih > $bit) return;
        $pByMakine[$key][] = ['ad' => $ad, 'tarih' => $tarih,
                              'bitis_haric' => date('Y-m-d', strtotime($tarih . ' +1 day'))];
    };
    foreach ($db->query("SELECT id, periyodik_bitis FROM manliftler WHERE (aktif IS NULL OR aktif=1)")->fetchAll() as $r) {
        $planEkle('manlift:' . $r['id'], 'Periyodik Kontrol', $r['periyodik_bitis']);
    }
    foreach ($db->query("SELECT id, trafik_bitis, kasko_bitis, tuv_muayene_bitis, periyodik_kontrol_bitis
                         FROM araclar WHERE (aktif IS NULL OR aktif=1)")->fetchAll() as $r) {
        $k = 'arac:' . $r['id'];
        $planEkle($k, 'TÜV Muayene',       $r['tuv_muayene_bitis']);
        $planEkle($k, 'Periyodik Kontrol', $r['periyodik_kontrol_bitis']);
    }
} catch (\Exception $e) { hataGunlugu('filo_takvim.plan', $e, true); }

$out = [];
foreach ($makineler as $mk) {
    if ($fTur !== '' && $mk['tur'] !== $fTur) continue;
    if ($fSube !== '' && trim((string)($mk['sube'] ?? '')) !== $fSube) continue;
    if ($fMarka !== '' && trim((string)($mk['marka'] ?? '')) !== $fMarka) continue;
    if ($fKategori !== '') {
        $kat = trim((string)($mk['katalog_kategori'] ?? ''));
        if ($fKategori === '__yok__') { if ($kat !== '') continue; }
        elseif ($kat !== $fKategori) continue;
    }
    if ($fMetrajMin !== null || $fMetrajMax !== null) {
        if (!is_numeric($mk['metraj'] ?? null)) continue;
        $mv = (float)$mk['metraj'];
        if ($fMetrajMin !== null && $mv < $fMetrajMin) continue;
        if ($fMetrajMax !== null && $mv > $fMetrajMax) continue;
    }
    $key = $mk['tur'] . ':' . $mk['id'];
    $out[] = [
        'tur'        => $mk['tur'],
        'id'         => $mk['id'],
        'ad'         => $mk['ad'],
        'kod'        => $mk['kod'] ?? null,
        'model'      => $mk['model'] ?? null,
        'kategori'   => $mk['kategori'],
        'katalog_kategori' => $mk['katalog_kategori'] ?? null, 'olcu_birim' => $mk['olcu_birim'] ?? null,
        'marka'      => $mk['marka'] ?? null,
        'metraj'     => is_numeric($mk['metraj'] ?? null) ? (float)$mk['metraj'] : null,
        'seri_no'    => $mk['seri_no'] ?? null,
        'makine_kodu'=> $mk['makine_kodu'] ?? null,
        'temin_sekli'=> $mk['temin_sekli'] ?? 'stok',
        'dk'         => isset($mk['dk']) ? ['donemler' => $mk['dk']['donemler'], 'aktif' => $mk['dk']['aktif']] : null,
        'sube'       => $mk['sube'],
        'aktif'      => $mk['aktif'] ?? 1,
        'durum'      => makineDurumu($db, $mk['tur'], (int)$mk['id']) ?: 'musait',
        'durum_kayit'=> $mk['durum_ham'] ?? 'musait',
        'kiralamalar'=> $kByMakine[$key] ?? [],
        'servisler'  => $sByMakine[$key] ?? [],
        'planlar'    => $pByMakine[$key] ?? [],
    ];
}

return [
    'baslangic' => $bas,
    'bitis'     => $bit,
    'gun'       => $gun,
    'bugun'     => $bugun,
    'subeler'   => $subeList,
    'markalar'  => $markaList,
    'kategoriler' => $kategoriList,
    'metraj_aralik' => ['min' => $metrajMin, 'max' => $metrajMax],
    'metrajlar'   => $metrajList,
    'makineler' => $out,
];
}
