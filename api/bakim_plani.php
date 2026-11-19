<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('teknik_servis');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonError('Method Not Allowed', 405);

$db    = getDb();
$today = date('Y-m-d');
$ufuk  = date('Y-m-d', strtotime('+' . max(7, (int)($_GET['gun'] ?? 60)) . ' days'));

$plan = [];

$SIGORTA_EVRAK = ['Trafik Sigortası', 'Kasko', 'Makine Kırılma'];
$grupBul = fn($kontrol) => in_array($kontrol, $SIGORTA_EVRAK, true) ? 'sigorta_evrak' : 'bakim';

$kunye = function ($tur, array $r) use ($db): array {
    static $katOnbellek = [], $birimOnbellek = [];
    $arac = $tur === 'arac';
    $kat = trim((string)($r['kategori'] ?? ''));
    if ($kat === '' && !$arac && (trim((string)($r['marka'] ?? '')) !== '' || trim((string)($r['model'] ?? '')) !== '')) {
        $anahtar = ($r['marka'] ?? '') . '|' . ($r['model'] ?? '');
        $katOnbellek[$anahtar] ??= (string)(makineKategoriAdi($db, ['kategori' => null, 'marka' => $r['marka'] ?? null, 'model' => $r['model'] ?? null]) ?? '');
        $kat = $katOnbellek[$anahtar];
    }
    if ($kat === '' && $arac) $kat = 'Araç';
    $metraj = metrajSayi($r['metraj'] ?? null);
    $birim = null;
    if ($metraj !== null) {
        $birimOnbellek[$kat] ??= mkBirim($db, $kat !== '' ? $kat : null);
        $birim = $birimOnbellek[$kat];
    }
    return [
        'makine_kod'           => $arac ? $r['plaka'] : $r['kod'],
        'makine_kategori'      => $kat !== '' ? $kat : null,
        'makine_marka'         => $r['marka'] ?? null,
        'makine_model'         => $r['model'] ?? null,
        'makine_seri_no'       => $arac ? null : ($r['seri_no'] ?? null),
        'makine_stok_kodu'     => $r['makine_kodu'] ?? null,
        'makine_metraj'        => $metraj,
        'makine_olcu_birim'    => $birim,
        'makine_sube'          => $r['sube'] ?? null,
        'makine_calisma_saati' => isset($r['calisma_saati']) ? (float)$r['calisma_saati'] : null,
    ];
};

$ekleTarih = function ($tur, array $r, $ad, $kontrol, $tarih) use (&$plan, $today, $ufuk, $grupBul, $kunye) {
    if (!$tarih || $tarih > $ufuk) return;
    $plan[] = [
        'makine_tur' => $tur, 'makine_id' => (int)$r['id'], 'makine_ad' => $ad,
        'kontrol' => $kontrol,
        'grup'    => $grupBul($kontrol),
        'olcu'    => 'tarih',
        'tarih'   => $tarih,
        'durum'   => $tarih < $today ? 'gecti' : 'yaklasan',
    ] + $kunye($tur, $r);
};

$ekleSaat = function ($tur, array $r, $ad) use (&$plan, $kunye) {
    $d = saatBakimDurumu($r);
    if ($d === null || $d['durum'] === 'normal') return;
    $plan[] = [
        'makine_tur' => $tur, 'makine_id' => (int)$r['id'], 'makine_ad' => $ad,
        'kontrol' => 'Saat Bazlı Bakım',
        'grup'    => 'bakim',
        'olcu'    => 'saat',
        'tarih'   => null,
        'durum'   => $d['durum'],
        'gecen_saat'  => $d['gecen_saat'],
        'kalan_saat'  => $d['kalan_saat'],
        'aralik_saat' => $d['aralik_saat'],
        'calisma_saati' => $d['calisma_saati'],
    ] + $kunye($tur, $r);
};

$manliftler = $db->query("
    SELECT id, kod, marka, model, seri_no, kategori, metraj, makine_kodu, sube,
           periyodik_bitis, calisma_saati, son_bakim_saat, bakim_araligi_saat
    FROM manliftler WHERE (aktif IS NULL OR aktif=1)
")->fetchAll();
foreach ($manliftler as $m) {
    $ad = trim($m['kod'] . ($m['marka'] ? ' — ' . $m['marka'] : ''));
    $ekleTarih('manlift', $m, $ad, 'Periyodik Kontrol', $m['periyodik_bitis']);
    $ekleSaat('manlift', $m, $ad);
}

$araclar = $db->query("
    SELECT id, plaka, marka, model, kategori, metraj, makine_kodu, sube,
           trafik_bitis, kasko_bitis, makine_kirilma_bitis, tuv_muayene_bitis, periyodik_kontrol_bitis,
           calisma_saati, son_bakim_saat, bakim_araligi_saat
    FROM araclar WHERE (aktif IS NULL OR aktif=1)
")->fetchAll();
foreach ($araclar as $a) {
    $ad = trim($a['plaka'] . ($a['marka'] ? ' — ' . $a['marka'] : ''));
    $ekleTarih('arac', $a, $ad, 'TÜV Muayene',        $a['tuv_muayene_bitis']);
    $ekleTarih('arac', $a, $ad, 'Periyodik Kontrol',  $a['periyodik_kontrol_bitis']);
    $ekleSaat('arac', $a, $ad);
    $ekleTarih('arac', $a, $ad, 'Trafik Sigortası',   $a['trafik_bitis']);
    $ekleTarih('arac', $a, $ad, 'Kasko',              $a['kasko_bitis']);
    $ekleTarih('arac', $a, $ad, 'Makine Kırılma',     $a['makine_kirilma_bitis']);
}

$acik = [];
try {
    foreach ($db->query("SELECT makine_tur, makine_id FROM teknik_servis WHERE durum<>'tamamlandi' AND makine_id IS NOT NULL AND COALESCE(makine_baska_iste,0)=0")->fetchAll() as $s) {
        $acik[$s['makine_tur'] . ':' . $s['makine_id']] = true;
    }
} catch (\Exception $e) { hataGunlugu('bakim_plani.acik_servis', $e, true); }
foreach ($plan as &$p) $p['serviste'] = isset($acik[$p['makine_tur'] . ':' . $p['makine_id']]);
unset($p);

usort($plan, function ($a, $b) {
    $oncelik = fn($r) => $r['durum'] === 'gecti' ? 0 : 1;
    if ($oncelik($a) !== $oncelik($b)) return $oncelik($a) <=> $oncelik($b);
    if ($a['olcu'] !== $b['olcu'])     return $a['olcu'] === 'tarih' ? -1 : 1;
    if ($a['olcu'] === 'tarih')        return strcmp((string)$a['tarih'], (string)$b['tarih']);
    return $a['kalan_saat'] <=> $b['kalan_saat'];
});

$ozet = [
    'gecti' => 0, 'yaklasan' => 0,
    'bakim'         => ['gecti' => 0, 'yaklasan' => 0],
    'sigorta_evrak' => ['gecti' => 0, 'yaklasan' => 0],
];
foreach ($plan as $p) {
    $ozet[$p['durum']]++;
    $ozet[$p['grup']][$p['durum']]++;
}

jsonOk(['plan' => $plan, 'ozet' => $ozet, 'bugun' => $today]);
