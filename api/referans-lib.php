<?php
declare(strict_types=1);
require_once __DIR__ . '/tutanak_kurallari.php';
require_once __DIR__ . '/operasyon-adim.php';

function refKatalog(?string $dosya = null): array {
    static $onbellek = [];
    $dosya ??= dirname(__DIR__) . '/img/referans/katalog.json';
    if (!array_key_exists($dosya, $onbellek)) {
        $j = is_file($dosya) ? json_decode((string)file_get_contents($dosya), true) : null;
        $onbellek[$dosya] = is_array($j) && is_array($j['kategoriler'] ?? null) ? $j : ['surum' => '', 'kategoriler' => []];
    }
    return $onbellek[$dosya];
}

function refKategoriAilesi(string $kategori, array $katalog): ?string {
    $t = trArama($kategori);
    $var = fn(string ...$k) => array_reduce($k, fn($ok, $x) => $ok && str_contains($t, $x), true);
    $aday = null;
    if ($var('orumcek')) $aday = 'Örümcek Platformlar';
    elseif ($var('forklift')) $aday = 'Dizel Forkliftler';
    elseif ($var('arac ustu') || $var('arac uzeri')) {
        if ($var('vinc')) $aday = $var('kirmali') ? 'Araç Üstü Kırmalı Vinç' : 'Araç Üstü Kırmasız Taşımalı Vinç';
        else $aday = $var('kirmali') ? 'Araç Üstü Kırmalı Platformlar' : 'Araç Üstü Sepetli Platformlar';
    }
    elseif ($var('makasli') || $var('makas')) {
        if ($var('paletli')) $aday = 'Arazi Tipi Paletli Akülü Makaslı Platform';
        elseif ($var('elle') || $var('cekilebilir') || $var('romork')) $aday = 'Elle Çekilebilir Makaslı Platformlar';
        elseif ($var('arazi') || $var('4x4')) $aday = $var('dizel') ? '4x4 Arazi Tipi Dizel Makaslı Platformlar' : '4X4 Arazi Tipi Akülü Makaslı Platformlar';
        else $aday = 'Akülü Makaslı Platformlar';
    }
    elseif ($var('eklemli')) $aday = 'Akülü Eklemli Platformlar';
    elseif ($var('teleskopik')) $aday = 'Akülü Teleskopik Platformlar';
    elseif ($var('dikey') || $var('direk')) $aday = $var('cift') ? 'Çift Direk Dikey Platform' : 'Dikey Direk Platformlar';
    return ($aday !== null && isset($katalog['kategoriler'][$aday])) ? $aday : null;
}

function refSet(?string $kategori, ?array $katalog = null): ?array {
    if ($kategori === null || trim($kategori) === '') return null;
    $katalog ??= refKatalog();
    $set = $katalog['kategoriler'][$kategori] ?? null;
    if (!is_array($set)) { $aile = refKategoriAilesi($kategori, $katalog); $set = $aile !== null ? $katalog['kategoriler'][$aile] : null; }
    return is_array($set) ? $set : null;
}

function refOrnek(?string $kategori, string $sema, ?array $katalog = null): ?string {
    if ($kategori === null || $sema === '') return null;
    $katalog ??= refKatalog();
    $set = refSet($kategori, $katalog);
    if ($set === null || !in_array($sema, (array)($set['acilar'] ?? []), true)) return null;
    $v = (string)($katalog['surum'] ?? '');
    return 'img/referans/' . $set['klasor'] . '/' . $sema . '.webp' . ($v !== '' ? '?v=' . rawurlencode($v) : '');
}

function refBilgi(PDO $db, string $tur, int $makineId, ?array $katalog = null): array {
    $katalog ??= refKatalog();
    $tur = $tur === 'arac' ? 'arac' : 'manlift';
    $s = $db->prepare('SELECT marka, model, kategori FROM ' . ($tur === 'arac' ? 'araclar' : 'manliftler') . ' WHERE id=?');
    $s->execute([$makineId]);
    $m = $s->fetch(PDO::FETCH_ASSOC) ?: ['marka' => null, 'model' => null, 'kategori' => null];
    $u = makineKatalogUrunu($db, $m['marka'], $m['model']);
    $kat = makineKategoriAdi($db, $m);
    $acilar = [];
    foreach (tutanakKurallari($tur)['fotograflar'] as $f) {
        $sema = (string)($f['sema'] ?? '');
        $acilar[] = ['ad' => (string)$f['ad'], 'sema' => $sema, 'tarif' => (string)($f['tarif'] ?? ''),
                     'zorunlu' => !empty($f['zorunlu']), 'ornek' => refOrnek($kat, $sema, $katalog)];
    }
    return ['kategori' => $kat, 'klasor' => refSet($kat, $katalog)['klasor'] ?? null,
            'katalog_gorsel' => $u['gorsel_url'] ?? null, 'yan_gorsel' => refOrnek($kat, 'sag', $katalog), 'acilar' => $acilar];
}

function refHazirlikBilgi(PDO $db, array $k, ?array $katalog = null): array {
    $katalog ??= refKatalog();
    $r = refBilgi($db, (string)$k['makine_tur'], (int)$k['makine_id'], $katalog);
    foreach (hzYuruyenAcilar((string)$k['makine_tur'], $r['kategori'], $k['ekipman_adi'] ?? null) as $a)
        $r['acilar'][] = $a + ['ornek' => refOrnek($r['kategori'], $a['sema'], $katalog)];
    return $r;
}

function refErisimVar(PDO $db, array $k): bool {
    if (hasPermission('kiralama_takip', 'oku') || opOturumYetkisi($db, 'depo') || opOturumYetkisi($db, 'yukleme')) return true;
    $pid = (int)($_SESSION['personel_id'] ?? 0);
    return $pid > 0 && (int)($k['operatorlu'] ?? 0) === 1 && (int)($k['operator_id'] ?? 0) === $pid;
}

function refKartGorsel(PDO $db, ?string $marka, ?string $model, ?string $kategori = null): ?string {
    $u = makineKatalogUrunu($db, $marka, $model);
    $kat = ($kategori !== null && trim($kategori) !== '') ? $kategori : ($u['kategori'] ?? null);
    return refOrnek($kat, 'sag') ?? ($u['gorsel_url'] ?? null);
}
