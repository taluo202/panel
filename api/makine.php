<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
subeMerkezi('makine');

const MAKINE_SECICI_MODULLER = ['kiralama_takip', 'teknik_servis', 'nakliye',
                                'arac', 'manlift', 'makine_rapor', 'makine', 'formlar'];

$_izinli = false;
foreach (MAKINE_SECICI_MODULLER as $_m) {
    if (hasPermission($_m, 'oku')) { $_izinli = true; break; }
}
if (!$_izinli) {
    jsonError('Makine listesini görüntüleme yetkiniz yok.', 403);
}

$tumu = !empty($_GET['hepsi']);
$liste = makineListesi(getDb());
if (!$tumu) $liste = array_values(array_filter($liste, fn($m) => (int)($m['aktif'] ?? 1) === 1));
$liste = array_map(function ($m) {
    unset($m['alis_bedeli'], $m['faydali_omur_yil']);
    return $m;
}, $liste);

$_sube = subeKisiti();
if ($_sube !== null) {
    $liste = array_values(array_filter($liste, fn($m) => (string)($m['sube'] ?? '') === $_sube));
}

if (!empty($_GET['kiralama'])) {
    $_kiraKisit = subeKisitiHam();
    if ($_kiraKisit !== null) {
        $liste = array_values(array_filter($liste,
            fn($m) => trim((string)($m['sube'] ?? '')) === $_kiraKisit));
    }
}

$_genel = hasPermission('kiralama_takip', 'oku') || hasPermission('teknik_servis', 'oku')
       || hasPermission('nakliye', 'oku') || hasPermission('makine_rapor', 'oku') || hasPermission('makine', 'oku')
       || hasPermission('formlar', 'oku');
if (!$_genel) {
    $_turler = [];
    if (hasPermission('arac', 'oku'))    $_turler[] = 'arac';
    if (hasPermission('manlift', 'oku')) $_turler[] = 'manlift';
    $liste = array_values(array_filter($liste, fn($m) => in_array($m['tur'] ?? '', $_turler, true)));
}

if (!empty($_GET['kiralama'])) {
    $db = getDb();
    $_manliftIds = array_column(array_filter($liste, fn($m) => $m['tur'] === 'manlift'), 'id');
    $_aracIds    = array_column(array_filter($liste, fn($m) => $m['tur'] === 'arac'), 'id');
    $_saatMap = [];
    if ($_manliftIds) {
        $ph = implode(',', array_fill(0, count($_manliftIds), '?'));
        $st = $db->prepare("SELECT id, calisma_saati, son_bakim_saat, bakim_araligi_saat FROM manliftler WHERE id IN ($ph)");
        $st->execute($_manliftIds);
        foreach ($st->fetchAll() as $r) $_saatMap['manlift:' . $r['id']] = $r;
    }
    if ($_aracIds) {
        $ph = implode(',', array_fill(0, count($_aracIds), '?'));
        $st = $db->prepare("SELECT id, calisma_saati, son_bakim_saat, bakim_araligi_saat FROM araclar WHERE id IN ($ph)");
        $st->execute($_aracIds);
        foreach ($st->fetchAll() as $r) $_saatMap['arac:' . $r['id']] = $r;
    }
    $_tedMap = [];
    try {
        foreach ($db->query("SELECT d.makine_tur, d.makine_id, t.unvan FROM dis_kiralama_kayitlari d JOIN tedarikciler t ON t.id = d.tedarikci_id
                             WHERE d.durum IN ('musait','rezerve','musteride') ORDER BY d.temin_baslangic, d.id")->fetchAll() as $_r) {
            $_tedMap[$_r['makine_tur'] . ':' . $_r['makine_id']] = $_r['unvan'];
        }
    } catch (\Throwable $e) { hataGunlugu('makine.detay', $e, true); }
    $liste = array_map(function ($m) use ($_saatMap, $_tedMap) {
        $satir = $_saatMap[$m['tur'] . ':' . $m['id']] ?? null;
        $m['bakim_durumu'] = $satir ? saatBakimDurumu($satir) : null;
        if (isset($m['dk']) && is_array($m['dk'])) $m['dk']['tedarikci'] = $_tedMap[$m['tur'] . ':' . $m['id']] ?? null;
        return $m;
    }, $liste);
}

jsonOk($liste);
