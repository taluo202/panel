<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonError('Method Not Allowed', 405);

$q = trim(metin($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) jsonOk(['gruplar' => []]);

$db   = getDb();
$like = '%' . trArama($q) . '%';

$kisit = subeKisiti();
$subeAnd = fn(string $kolon) => $kisit !== null ? " AND $kolon = " . $db->quote($kisit) : '';

$gruplar = [];
$ekle = function (string $baslik, string $icon, string $link, array $items) use (&$gruplar) {
    if ($items) $gruplar[] = ['baslik' => $baslik, 'icon' => $icon, 'link' => $link, 'items' => $items];
};

if (hasPermission('arac')) {
    $st = $db->prepare("SELECT id, plaka, marka, model FROM araclar WHERE " . trAramaSql('plaka') . " LIKE ? OR " . trAramaSql('marka') . " LIKE ? OR " . trAramaSql('model') . " LIKE ? ORDER BY plaka LIMIT 6");
    $st->execute([$like, $like, $like]);
    $items = [];
    foreach ($st->fetchAll() as $r) $items[] = ['metin' => $r['plaka'], 'alt' => trim(($r['marka'] ?? '') . ' ' . ($r['model'] ?? '')), 'link' => 'arac-detay.html?id=' . $r['id']];
    $ekle('Araçlar', 'directions_car', 'arac.html', $items);
}

if (hasPermission('manlift')) {
    $st = $db->prepare("SELECT id, kod, marka, model, seri_no FROM manliftler WHERE " . trAramaSql('kod') . " LIKE ? OR " . trAramaSql('marka') . " LIKE ? OR " . trAramaSql('model') . " LIKE ? OR " . trAramaSql('seri_no') . " LIKE ? ORDER BY kod LIMIT 6");
    $st->execute([$like, $like, $like, $like]);
    $items = [];
    foreach ($st->fetchAll() as $r) $items[] = ['metin' => $r['kod'], 'alt' => trim(($r['marka'] ?? '') . ' ' . ($r['model'] ?? '')), 'link' => 'manlift-detay.html?id=' . $r['id']];
    $ekle('Makineler', 'precision_manufacturing', 'manlift.html', $items);
}

if (hasPermission('kiralama_takip')) {
    $st = $db->prepare("SELECT id, unvan, telefon, vergi_no FROM musteriler WHERE " . trAramaSql('unvan') . " LIKE ? OR " . trAramaSql('telefon') . " LIKE ? OR " . trAramaSql('vergi_no') . " LIKE ? ORDER BY unvan LIMIT 6");
    $st->execute([$like, $like, $like]);
    $items = [];
    foreach ($st->fetchAll() as $r) $items[] = ['metin' => $r['unvan'], 'alt' => $r['telefon'] ?: ($r['vergi_no'] ?: ''), 'link' => 'musteri-detay.html?id=' . $r['id']];
    $ekle('Müşteriler', 'groups', 'musteriler.html', $items);
}

if (hasPermission('personel')) {
    $st = $db->prepare("SELECT id, ad_soyad, pozisyon FROM personel WHERE " . trAramaSql('ad_soyad') . " LIKE ? ORDER BY ad_soyad LIMIT 6");
    $st->execute([$like]);
    $items = [];
    foreach ($st->fetchAll() as $r) $items[] = ['metin' => $r['ad_soyad'], 'alt' => $r['pozisyon'] ?: '', 'link' => 'personel-detay.html?id=' . $r['id']];
    $ekle('Personel', 'badge', 'personel.html', $items);
}

if (hasPermission('yedek_parca')) {
    $st = $db->prepare("SELECT id, ad, parca_no FROM yedek_parca WHERE " . trAramaSql('ad') . " LIKE ? OR " . trAramaSql('parca_no') . " LIKE ? ORDER BY ad LIMIT 6");
    $st->execute([$like, $like]);
    $items = [];
    foreach ($st->fetchAll() as $r) $items[] = ['metin' => $r['ad'], 'alt' => $r['parca_no'] ?: '', 'link' => 'yedek-parca.html?q=' . urlencode($r['ad'])];
    $ekle('Yedek Parça', 'build', 'yedek-parca.html', $items);
}

if (hasPermission('kiralama_takip')) {
    $st = $db->prepare("SELECT k.id, k.kod, k.ekipman_adi, m.unvan AS musteri FROM kiralamalar k LEFT JOIN musteriler m ON m.id=k.musteri_id
        WHERE (" . trAramaSql('k.kod') . " LIKE ? OR " . trAramaSql('k.ekipman_adi') . " LIKE ?)" . $subeAnd('k.sube') . " ORDER BY k.id DESC LIMIT 6");
    $st->execute([$like, $like]);
    $items = [];
    foreach ($st->fetchAll() as $r) $items[] = ['metin' => $r['kod'] ?: ('#' . $r['id']), 'alt' => trim(($r['ekipman_adi'] ?? '') . ($r['musteri'] ? ' · ' . $r['musteri'] : '')), 'link' => 'kiralama-takip.html?q=' . urlencode($r['kod'] ?: $r['ekipman_adi'])];
    $ekle('Kiralamalar', 'assignment', 'kiralama-takip.html', $items);
}

if (hasPermission('kiralama_takip')) {
    $st = $db->prepare("SELECT t.id, t.kod, m.unvan AS musteri FROM teklifler t LEFT JOIN musteriler m ON m.id=t.musteri_id WHERE " . trAramaSql('t.kod') . " LIKE ?" . $subeAnd('t.sube') . " ORDER BY t.id DESC LIMIT 6");
    $st->execute([$like]);
    $items = [];
    foreach ($st->fetchAll() as $r) $items[] = ['metin' => $r['kod'] ?: ('#' . $r['id']), 'alt' => $r['musteri'] ?: '', 'link' => 'teklifler.html?q=' . urlencode($r['kod'] ?: '')];
    $ekle('Teklifler', 'request_quote', 'teklifler.html', $items);
}

if (hasPermission('kiralama_takip')) {
    $st = $db->prepare("SELECT h.id, h.kod, h.fatura_no, m.unvan AS musteri FROM hakedisler h LEFT JOIN musteriler m ON m.id=h.musteri_id
        WHERE (" . trAramaSql('h.kod') . " LIKE ? OR " . trAramaSql('h.fatura_no') . " LIKE ?)" . $subeAnd('h.sube') . " ORDER BY h.id DESC LIMIT 6");
    $st->execute([$like, $like]);
    $items = [];
    foreach ($st->fetchAll() as $r) $items[] = ['metin' => $r['kod'] ?: ('#' . $r['id']), 'alt' => trim(($r['musteri'] ?? '') . ($r['fatura_no'] ? ' · Fat: ' . $r['fatura_no'] : '')), 'link' => 'hakedisler.html?q=' . urlencode($r['kod'] ?: $r['fatura_no'])];
    $ekle('Hakedişler', 'receipt', 'hakedisler.html', $items);
}

if (hasPermission('nakliye')) {
    $st = $db->prepare("SELECT id, ekipman, nakliyeci, plaka FROM nakliyeler WHERE (" . trAramaSql('ekipman') . " LIKE ? OR " . trAramaSql('nakliyeci') . " LIKE ? OR " . trAramaSql('plaka') . " LIKE ?)" . $subeAnd('sube') . " ORDER BY id DESC LIMIT 6");
    $st->execute([$like, $like, $like]);
    $items = [];
    foreach ($st->fetchAll() as $r) $items[] = ['metin' => $r['ekipman'] ?: ('#' . $r['id']), 'alt' => trim(($r['nakliyeci'] ?? '') . ($r['plaka'] ? ' · ' . $r['plaka'] : '')), 'link' => 'nakliye.html?q=' . urlencode($r['ekipman'] ?: $r['nakliyeci'])];
    $ekle('Nakliye', 'local_shipping', 'nakliye.html', $items);
}

if (hasPermission('cek_senet')) {
    $st = $db->prepare("SELECT c.id, c.kisi, c.banka, c.belge_no FROM cek_senet c LEFT JOIN musteriler m ON m.id=c.musteri_id WHERE (" . trAramaSql('c.kisi') . " LIKE ? OR " . trAramaSql('c.banka') . " LIKE ? OR " . trAramaSql('c.belge_no') . " LIKE ?)" . $subeAnd(cekSenetSubeIfade()) . " ORDER BY c.id DESC LIMIT 6");
    $st->execute([$like, $like, $like]);
    $items = [];
    foreach ($st->fetchAll() as $r) $items[] = ['metin' => $r['kisi'] ?: ('#' . $r['id']), 'alt' => trim(($r['banka'] ?? '') . ($r['belge_no'] ? ' · ' . $r['belge_no'] : '')), 'link' => 'cek-senet.html?q=' . urlencode($r['kisi'] ?: $r['banka'])];
    $ekle('Çek / Senet', 'payments', 'cek-senet.html', $items);
}

if (hasPermission('satinalma')) {
    $st = $db->prepare("SELECT id, unvan, telefon FROM tedarikciler WHERE " . trAramaSql('unvan') . " LIKE ? OR " . trAramaSql('telefon') . " LIKE ? ORDER BY unvan LIMIT 6");
    $st->execute([$like, $like]);
    $items = [];
    foreach ($st->fetchAll() as $r) $items[] = ['metin' => $r['unvan'], 'alt' => $r['telefon'] ?: '', 'link' => 'tedarikciler.html?q=' . urlencode($r['unvan'])];
    $ekle('Tedarikçiler', 'local_shipping', 'tedarikciler.html', $items);

    $st = $db->prepare("SELECT s.id, s.kod, t.unvan AS tedarikci FROM satinalma_siparisleri s JOIN tedarikciler t ON t.id=s.tedarikci_id WHERE " . trAramaSql('s.kod') . " LIKE ? ORDER BY s.id DESC LIMIT 6");
    $st->execute([$like]);
    $items = [];
    foreach ($st->fetchAll() as $r) $items[] = ['metin' => $r['kod'] ?: ('#' . $r['id']), 'alt' => $r['tedarikci'] ?: '', 'link' => 'satinalma.html?q=' . urlencode($r['kod'] ?: '')];
    $ekle('Satınalma Siparişleri', 'shopping_cart', 'satinalma.html', $items);
}

if (hasPermission('personel')) {
    $st = $db->prepare("SELECT m.id, m.aciklama, m.tarih, p.ad_soyad FROM personel_masraf m JOIN personel p ON p.id=m.personel_id
        WHERE " . trAramaSql('p.ad_soyad') . " LIKE ? OR " . trAramaSql('m.aciklama') . " LIKE ? ORDER BY m.id DESC LIMIT 6");
    $st->execute([$like, $like]);
    $items = [];
    foreach ($st->fetchAll() as $r) $items[] = ['metin' => $r['ad_soyad'], 'alt' => ($r['aciklama'] ?: '') . ' · ' . $r['tarih'], 'link' => 'avans-masraf.html?q=' . urlencode($r['ad_soyad'])];
    $ekle('Personel Masrafları', 'payments', 'avans-masraf.html', $items);
}

jsonOk(['gruplar' => $gruplar]);
