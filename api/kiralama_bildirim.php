<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/eposta.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth(); yazmaIstegiCsrf();
requirePermission('kiralama_takip');


$db     = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$id     = (int)($_GET['id'] ?? 0);
$siparis = trim((string)($_GET['siparis'] ?? ''));
if ($id < 1 && $siparis === '') jsonError('Kiralama seçilmedi.');

$where  = [$siparis !== '' ? "k.siparis_no = ? AND k.durum <> 'iptal'" : 'k.id = ?'];
$params = [$siparis !== '' ? $siparis : $id];
subeKosulEkle($where, $params, 'k.sube');

$st = $db->prepare("
    SELECT k.*, m.unvan AS musteri_unvan, m.yetkili, m.telefon AS musteri_telefon, m.email AS musteri_email
    FROM kiralamalar k JOIN musteriler m ON m.id = k.musteri_id
    WHERE " . implode(' AND ', $where) . " ORDER BY k.id");
$st->execute($params);
$kayitlar = $st->fetchAll();
if (!$kayitlar) jsonError($siparis !== '' ? 'Siparişte bilgilendirilecek (iptal olmayan) kiralama yok.' : 'Kiralama bulunamadı.', 404);
$k = $kayitlar[0];

function _kbTarih(?string $t): string {
    $t = trim((string)$t);
    return $t === '' ? '—' : date('d.m.Y', strtotime($t));
}

function _kbPara(float $t, ?string $dv): string {
    $dv = strtoupper(trim((string)$dv)) ?: 'TL';
    return number_format($t, 2, ',', '.') . ' ' . (['TL' => '₺', 'TRY' => '₺', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'][$dv] ?? $dv);
}

function _kbDurum(array $k): string {
    return !empty($k['gercek_bitis']) ? 'teslim alınmıştır'
        : (($k['durum'] ?? '') === 'tamamlandi' ? 'tamamlanmıştır' : 'devam etmektedir');
}

$h = kiralamaTutar($k);

$firma = '';
try {
    $fs = $db->prepare("SELECT deger FROM ayarlar WHERE anahtar='firma_unvan'");
    $fs->execute();
    $firma = trim((string)$fs->fetchColumn());
} catch (\Throwable $e) { hataGunlugu('bildirim.firma_adi', $e); }
if ($firma === '') $firma = 'firmamız';

$hitap = 'Sayın ' . ($k['yetkili'] ?: ($k['musteri_unvan'] ?: 'yetkili')) . ',';
if ($siparis !== '') {
    $satirlar = [$hitap, '', $firma . ' olarak ' . $siparis . ' numaralı siparişiniz ile ilgili bilgilendirme:', ''];
    $toplam = [];
    foreach ($kayitlar as $i => $r) {
        $hr = kiralamaTutar($r);
        $dv = strtoupper(trim((string)($r['doviz'] ?? ''))) ?: 'TL';
        $toplam[$dv] = ($toplam[$dv] ?? 0) + (float)($hr['genel_toplam'] ?? 0);
        $bit = $r['gercek_bitis'] ?: ($r['bitis'] ?: '');
        $satirlar[] = ($i + 1) . ') ' . ($r['ekipman_adi'] ?? '') . ($r['kod'] ? ' (' . $r['kod'] . ')' : '');
        $satirlar[] = '   Başlangıç: ' . _kbTarih($r['baslangic'] ?? '') . ($bit !== '' ? ' · Bitiş: ' . _kbTarih($bit) : '')
            . ' · Süre: ' . (int)($hr['gun'] ?? 0) . ' gün';
        $satirlar[] = '   Durum: Ekipman ' . _kbDurum($r) . '. Tutar: ' . _kbPara((float)($hr['genel_toplam'] ?? 0), $dv) . ' (KDV dahil)';
    }
    $satirlar[] = '';
    $satirlar[] = 'Toplam: ' . implode(' + ', array_map(fn($dv) => _kbPara($toplam[$dv], $dv), array_keys($toplam))) . ' (KDV dahil)';
    $satirlar[] = '';
    $satirlar[] = 'İyi çalışmalar dileriz.';
    $mesaj = implode("\n", $satirlar);
    $konu  = $siparis . ' — Sipariş Bilgilendirmesi';
} else {
$durumMetin = _kbDurum($k);

$bitisTarihi = $k['gercek_bitis'] ?: ($k['bitis'] ?: '');
$satirlar = [
    $hitap,
    '',
    $firma . ' olarak "' . ($k['ekipman_adi'] ?? '') . '" kiralamanız'
        . ($k['kod'] ? ' (' . $k['kod'] . ')' : '') . ' ile ilgili bilgilendirme:',
    '',
    'Başlangıç: ' . _kbTarih($k['baslangic'] ?? ''),
];
if ($bitisTarihi !== '') $satirlar[] = 'Bitiş: ' . _kbTarih($bitisTarihi);
$satirlar[] = 'Süre: ' . (int)($h['gun'] ?? 0) . ' gün';
$satirlar[] = 'Durum: Ekipman ' . $durumMetin . '.';
$satirlar[] = 'Tutar: ' . _kbPara((float)($h['genel_toplam'] ?? 0), $k['doviz'] ?? 'TL') . ' (KDV dahil)';
if (!empty($k['faturalandi'])) {
    $satirlar[] = 'Fatura: ' . ($k['fatura_no'] ?: 'kesildi')
        . ($k['fatura_tarihi'] ? ' (' . _kbTarih($k['fatura_tarihi']) . ')' : '');
}
$satirlar[] = '';
$satirlar[] = 'İyi çalışmalar dileriz.';

$mesaj = implode("\n", $satirlar);
$konu  = ($k['kod'] ? $k['kod'] . ' — ' : '') . 'Kiralama Bilgilendirmesi';
}

if ($method === 'GET') {
    jsonOk([
        'mesaj'   => $mesaj,
        'konu'    => $konu,
        'telefon' => $k['musteri_telefon'] ?? '',
        'email'   => $k['musteri_email'] ?? '',
    ]);
}

if ($method === 'POST') {
    verifyCsrf();
    $alici = trim((string)($k['musteri_email'] ?? ''));
    if ($alici === '' || !filter_var($alici, FILTER_VALIDATE_EMAIL)) {
        jsonError('Müşterinin geçerli bir e-posta adresi kayıtlı değil. Müşteri kartından ekleyebilirsiniz.');
    }
    $snc = epostaGonder($db, $alici, $konu, $mesaj, 'kiralama_bildirim');
    if (empty($snc['basarili'])) {
        jsonError('E-posta gönderilemedi: ' . ($snc['hata'] ?? 'bilinmeyen hata')
            . ' — Ayarlar > E-posta Gönderimi bölümünü kontrol edin.');
    }
    foreach ($kayitlar as $r)
        logla($db, 'kiralamalar', (int)$r['id'], 'guncelle', null, null,
              ($siparis !== '' ? $siparis . ' siparişi' : 'Kiralama') . ' bilgilendirmesi e-posta ile gönderildi: ' . $alici);
    jsonOk(['alici' => $alici], 'E-posta gönderildi: ' . $alici);
}

jsonError('Method Not Allowed', 405);
