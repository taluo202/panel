<?php
declare(strict_types=1);
require_once __DIR__ . '/belge-pdf-lib.php';


const SA_DURUM_ETIKET = [
    'taslak' => 'Taslak', 'gonderildi' => 'Gönderildi', 'kismi_teslim' => 'Kısmi Teslim',
    'teslim_alindi' => 'Teslim Alındı — Fatura Bekleniyor', 'tamamlandi' => 'Tamamlandı', 'iptal' => 'İptal',
];

function saSayi(mixed $n): string { return rtrim(rtrim(number_format((float)$n, 2, ',', '.'), '0'), ','); }

function saTarih(?string $d): string {
    $d = (string)$d;
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m) ? "$m[3].$m[2].$m[1]" : $d;
}

function saWaMetin(PDO $db, array $s): string {
    $firma = bpFirmaAyar($db)['firma_unvan'] ?? '';
    $satirlar = [];
    foreach ($s['kalemler'] ?? [] as $i => $k) {
        $satirlar[] = ((int)($k['sira'] ?? 0) ?: $i + 1) . '. ' . trim((string)$k['aciklama']) . ' — ' . saSayi($k['miktar']) . ' ' . (trim((string)($k['birim'] ?? '')) ?: 'adet')
            . (trim((string)($k['stok_kodu'] ?? '')) !== '' ? ' (' . trim((string)$k['stok_kodu']) . ')' : '')
            . (trim((string)($k['notlar'] ?? '')) !== '' ? ' — Not: ' . trim((string)$k['notlar']) : '');
    }
    $metin = 'Merhaba' . (trim((string)($s['tedarikci_unvan'] ?? '')) !== '' ? ' ' . trim((string)$s['tedarikci_unvan']) : '') . ",\n"
        . ($firma !== '' ? $firma . ' olarak ' : '') . 'aşağıdaki malzemeleri sipariş etmek istiyoruz.' . "\n"
        . 'Sipariş no: ' . ($s['kod'] ?? '') . ' · Tarih: ' . saTarih($s['tarih'] ?? '') . "\n\n"
        . implode("\n", $satirlar);
    if (trim((string)($s['notlar'] ?? '')) !== '') $metin .= "\n\nNot: " . trim((string)$s['notlar']);
    return $metin . "\n\nFiyat ve teslim tarihini bildirmenizi rica ederiz. Teşekkürler.";
}

function saEpostaMetin(PDO $db, array $s): string {
    $firma = bpFirmaAyar($db);
    return 'Merhaba,' . "\n\n"
        . ($s['kod'] ?? '') . ' numaralı satınalma siparişimiz ekteki PDF dosyasındadır.' . "\n"
        . 'Fiyat ve teslim tarihini bildirmenizi rica ederiz.' . "\n\n"
        . 'Saygılarımızla,' . "\n" . ($firma['firma_unvan'] ?: '')
        . ($firma['firma_telefon'] !== '' ? "\n" . $firma['firma_telefon'] : '')
        . ($firma['firma_eposta'] !== '' ? "\n" . $firma['firma_eposta'] : '');
}

function saPdf(PDO $db, array $s): array {
    $kod = (string)($s['kod'] ?? '');
    $baslik = 'Satınalma Siparişi';
    $bl = [];
    $ciftler = array_values(array_filter([
        ['Tedarikçi', $s['tedarikci_unvan'] ?? ''], ['Yetkili', $s['tedarikci_yetkili'] ?? ''],
        ['Telefon', $s['tedarikci_telefon'] ?? ''], ['E-posta', $s['tedarikci_email'] ?? ''],
        ['Adres', $s['tedarikci_adres'] ?? ''],
        ['Vergi Dairesi / No', implode(' / ', array_filter([trim((string)($s['tedarikci_vergi_dairesi'] ?? '')), trim((string)($s['tedarikci_vergi_no'] ?? ''))]))],
    ], fn($c) => trim((string)$c[1]) !== ''));
    if ($ciftler) $bl[] = ['bolum' => 'Tedarikçi', 'bilgi' => array_map(fn($c) => [$c[0], trim((string)$c[1])], $ciftler)];
    $satirlar = [];
    foreach ($s['kalemler'] ?? [] as $i => $k) {
        $satirlar[] = [(string)((int)($k['sira'] ?? 0) ?: $i + 1), trim((string)($k['stok_kodu'] ?? '')), ['metin' => trim((string)($k['aciklama'] ?? '')), 'kalin' => true],
                       saSayi($k['miktar'] ?? 0), trim((string)($k['birim'] ?? '')) ?: 'adet', trim((string)($k['notlar'] ?? ''))];
    }
    $bl[] = ['bolum' => 'Sipariş Kalemleri', 'tablo' => [['Sıra', 'Stok Kodu', 'Malzeme', 'Miktar', 'Birim', 'Not'], $satirlar ?: [['', '', '', '', '', '']], [0.6, 1.6, 3.6, 0.9, 0.9, 2.4], [3]]];
    if (trim((string)($s['notlar'] ?? '')) !== '') $bl[] = ['bolum' => 'Not', 'metin' => trim((string)$s['notlar'])];
    $bl[] = ['metin' => 'Fiyat ve teslim tarihinin tarafımıza bildirilmesini rica ederiz. Faturada sipariş numarasının (' . $kod . ') belirtilmesi eşleştirmeyi kolaylaştırır.', 'pt' => 8.2];
    $c = new BelgePdf($db, []);
    $c->satinalma(['pdf_baslik' => $baslik . ' ' . $kod, 'baslik' => 'SATINALMA SİPARİŞİ', 'no' => $kod, 'alt' => 'Tarih: ' . saTarih($s['tarih'] ?? ''),
                   'alt_ek' => $baslik . ' ' . $kod, 'bloklar' => $bl]);
    return ['ad' => bpDosyaAdi($kod !== '' ? $kod : 'satinalma-' . ($s['id'] ?? '')), 'tur' => 'application/pdf', 'veri' => $c->cikti()];
}
