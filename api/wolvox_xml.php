<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/zincir-lib.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('kiralama_takip');


if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonError('Method Not Allowed', 405);

$db = getDb();

$ay = [];
foreach ($db->query("SELECT anahtar, deger FROM ayarlar WHERE anahtar LIKE 'wolvox_%'")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $ay[substr($r['anahtar'], 7)] = $r['deger'];
}
$trsver     = trim((string)($ay['trsver']     ?? '')) ?: 'ASWFT1.02.03';
$dbfilename = trim((string)($ay['dbfilename'] ?? ''));
$dbname     = trim((string)($ay['dbname']     ?? ''));
$persuser   = trim((string)($ay['persuser']   ?? '')) ?: 'SYSDBA';
$subeKodu   = trim((string)($ay['sube_kodu']  ?? '')) ?: '001';
$depoAdi    = trim((string)($ay['depo_adi']   ?? ''));
$stokAdi    = trim((string)($ay['stok_adi']   ?? '')) ?: 'KİRALAMA HİZMETİ';
$sayacTanimi = trim((string)($ay['sayac_tanimi'] ?? ''));

$tur = ($_GET['tur'] ?? '') === 'hasar' ? 'hasar' : 'kira';
$idler = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? '')))));
if (!$idler) jsonError($tur === 'hasar' ? 'Aktarılacak hasar faturası seçilmedi.' : 'Aktarılacak kiralama seçilmedi.');
if (count($idler) > 500) jsonError('Tek seferde en fazla 500 kayıt aktarılabilir.');
$ph = implode(',', array_fill(0, count($idler), '?'));
if ($tur === 'hasar') {
    $where = ["f.id IN ($ph)"]; $params = $idler;
    subeKosulEkle($where, $params, 'f.sube');
    $st = $db->prepare("SELECT f.*, m.unvan AS musteri_unvan, m.vergi_dairesi, m.vergi_no, m.telefon AS musteri_telefon, m.adres AS musteri_adres,
                               m.wolvox_cari_kodu, k.kod AS kiralama_kod
                        FROM hasar_faturalari f JOIN musteriler m ON m.id=f.musteri_id LEFT JOIN kiralamalar k ON k.id=f.kiralama_id
                        WHERE " . implode(' AND ', $where) . " ORDER BY f.id");
    $st->execute($params);
    $rows = $st->fetchAll();
    if (!$rows) jsonError('Seçilen kayıtlar bulunamadı (ya da başka şubeye ait).', 404);
    foreach ($rows as $f) if ((int)$f['faturalandi'] !== 1)
        jsonError($f['ref'] . ': önce fatura tarihini girip "Faturalandı" işaretleyin — TL tutar fatura günü kuruyla hesaplanır.', 409);
} else {
    $where = ["k.id IN ($ph)", "k.durum<>'iptal'"];
    $params = $idler;
    subeKosulEkle($where, $params, 'k.sube');

    $st = $db->prepare("
        SELECT k.*, m.unvan AS musteri_unvan, m.vergi_dairesi, m.vergi_no,
               m.telefon AS musteri_telefon, m.adres AS musteri_adres,
               m.wolvox_cari_kodu
        FROM kiralamalar k JOIN musteriler m ON m.id = k.musteri_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY k.id
    ");
    $st->execute($params);
    $rows = $st->fetchAll();
    if (!$rows) jsonError('Seçilen kayıtlar bulunamadı (ya da başka şubeye ait).', 404);
    $_ayriFaturali = kiralamaAyriFaturaliHakedisler($db, array_column($rows, 'id'));
}


function wxCd(?string $v): string {
    $v = str_replace(']]>', ']]&gt;', (string)$v);
    return '<![CDATA[' . $v . ']]>';
}

function wxAlan(string $ad, $deger): string {
    return '  <' . $ad . '>' . wxCd((string)$deger) . '</' . $ad . '>' . "\n";
}

function wxSayi($n, int $basamak = 2): string {
    return number_format((float)$n, $basamak, ',', '');
}

function wxTarih(?string $tarih): string {
    $t = trim((string)$tarih);
    if ($t === '') return '';
    try {
        $d = new DateTime($t);
    } catch (\Exception $e) { return ''; }
    $sifir = new DateTime('1899-12-30 00:00:00');
    return (string)(int)$sifir->diff($d)->days;
}

function wxFaturaBaslik(array $c, string $fno, string $sayacTanimi, string $ozelKod, ?string $vade, string $aciklama): string {
    $cariKod = trim((string)($c['wolvox_cari_kodu'] ?? ''));
    $x  = "<FATURA>\n" . wxAlan('FATURA_DURUMU', '1') . wxAlan('BLCRKODU', ctype_digit($cariKod) ? $cariKod : '')
        . wxAlan('TICARI_UNVANI', $c['musteri_unvan'] ?? '') . wxAlan('ADI_SOYADI', $c['musteri_unvan'] ?? '')
        . wxAlan('VERGI_DAIRESI', $c['vergi_dairesi'] ?? '') . wxAlan('VERGI_NO', $c['vergi_no'] ?? '')
        . wxAlan('TEL1', $c['musteri_telefon'] ?? '') . wxAlan('FAKS', '') . wxAlan('ADRESI', $c['musteri_adres'] ?? '')
        . wxAlan('ILCESI', '') . wxAlan('ILI', '') . wxAlan('KDV_DURUMU', '0') . wxAlan('KPBDVZ_CARI', '1');
    foreach (['ISK_KUL_CARI', 'ISK_KUL_1', 'ISK_KUL_STOK', 'ISK_KUL_OZEL', 'ISK_KUL_ALT'] as $a) $x .= wxAlan($a, '0');
    foreach (['ISK_ORAN_CARI', 'ISK_ORAN_1', 'ISK_ORAN_ALT', 'ISK_TUTAR_ALT2'] as $a) $x .= wxAlan($a, '0');
    foreach (['ISK_TUTAR_CARI', 'ISK_TUTAR_1', 'ISK_TUTAR_STOK', 'ISK_TUTAR_OZEL'] as $a) $x .= wxAlan($a, wxSayi(0));
    $x .= wxAlan('FATURA_NO', $fno);
    if (trim($fno) === '' && $sayacTanimi !== '') $x .= wxAlan('SAYAC_TANIMI', $sayacTanimi);
    $x .= wxAlan('EKBILGI_1', '') . wxAlan('DOVIZ_KULLAN', '0') . wxAlan('DVZ_HSISLE_CARI', '0') . wxAlan('DVZ_HSISLE_STOK', '0')
        . wxAlan('OZEL_KODU', $ozelKod) . wxAlan('VADESI', wxTarih($vade ?? '')) . wxAlan('IPTAL', '0')
        . wxAlan('ACIKLAMA', $aciklama) . wxAlan('PAZ_DURUMU', '0') . "</FATURA>\n";
    return $x;
}
function wxHareket(string $ad, $mik, string $birim, $fiyat, int $basamak, float $kdvOran, string $depoAdi): string {
    $s = " <HAREKET>\n" . wxAlan('STOK_ADI', $ad) . wxAlan('BARKODU', '') . wxAlan('MIKTARI', wxSayi($mik, 2)) . wxAlan('BIRIMI', $birim)
       . wxAlan('KDV_ORANI', wxSayi($kdvOran, 0)) . wxAlan('KPB_FIYATI', wxSayi($fiyat, $basamak)) . wxAlan('KPBDVZ', '0');
    if ($depoAdi !== '') $s .= wxAlan('DEPO_ADI', $depoAdi);
    return $s . wxAlan('ISK_ORAN_1', '0') . wxAlan('OZEL_KODU', '') . " </HAREKET>\n";
}

$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$xml .= "<WFT>\n";
$xml .= "<AYAR>\n";
$xml .= wxAlan('TRSVER', $trsver);
if ($dbfilename !== '')      $xml .= wxAlan('DBFILENAME', $dbfilename);
elseif ($dbname !== '')      $xml .= wxAlan('DBNAME', $dbname);
$xml .= wxAlan('PERSUSER', $persuser);
$xml .= wxAlan('SUBE_KODU', $subeKodu);
$xml .= "</AYAR>\n";

$aktarilan = 0;
$hasarStokAdi = trim((string)($ay['hasar_stok_adi'] ?? '')) ?: 'HASAR BEDELİ';
if ($tur === 'hasar') {
    foreach ($rows as $f) {
        $satirlar = array_values(array_filter(json_decode((string)$f['kalemler'], true) ?: [], fn($x) => (float)$x['net'] > 0));
        if (!$satirlar) continue;
        $xml .= wxFaturaBaslik($f, (string)($f['fatura_no'] ?? ''), $sayacTanimi, (string)($f['kiralama_kod'] ?? ''), $f['vade_tarihi'] ?? '', $f['ref']);
        $xml .= "<FATURAHAREKET>\n";
        foreach ($satirlar as $x) $xml .= wxHareket($hasarStokAdi, 1, 'ADET', (float)$x['net'], 2, (float)$x['kdv_oran'], $depoAdi);
        $xml .= "</FATURAHAREKET>\n";
        $aktarilan++;
    }
    $rows = [];
}
foreach ($rows as $k) {
    $h = kiralamaTutar($k);
    if (($h['genel_toplam'] ?? 0) <= 0) continue;
    $_para = dovizPara($k['doviz'] ?? 'TL');
    if ($_para !== 'TL') {
        $_ft = trim((string)($k['fatura_tarihi'] ?? ''));
        if ((int)($k['faturalandi'] ?? 0) !== 1 || $_ft === '')
            jsonError(($k['kod'] ?: '#' . $k['id']) . ': ' . $_para . ' kiralama. Önce fatura tarihini girip "Faturalandı" işaretleyin; Wolvox\'a giden TL tutar fatura günü TCMB satış kuruyla hesaplanır.', 409);
        $_kur = dovizKuru($db, $_para, $_ft);
        if ($_kur === null) jsonError(($k['kod'] ?: '#' . $k['id']) . ': fatura günü ' . tarihGoster($_ft) . ' için ' . $_para . ' TCMB satış kuru bulunamadı (Filo Referans › Tarihli döviz kurları).', 409);
        foreach (['kira_bedeli', 'operator_bedeli', 'nakliye', 'ara_toplam', 'kdv', 'genel_toplam'] as $_a) $h[$_a] = round((float)($h[$_a] ?? 0) * $_kur, 2);
    }

    if (isset($_ayriFaturali[(int)$k['id']])) {
        $_kalan = kiralamaFaturasiHakedisleri($db, $k);
        if (!$_kalan) jsonError(($k['kod'] ?: '#' . $k['id']) . ': hakedişleri Hakedişler sayfasında ayrıca faturalanmış (' . implode(', ', $_ayriFaturali[(int)$k['id']]) . ') ve faturalanacak kalan hakediş yok. Aynı iş iki kez faturalanmasın diye XML üretilmedi; kalan dönem varsa önce Hakedişler › Toplu Üret ile hakedişini üretin.', 409);
        $xml .= wxFaturaBaslik($k, (string)($k['fatura_no'] ?? ''), $sayacTanimi, (string)($k['kod'] ?? ''), $k['vade_tarihi'] ?? '', trim(($k['kod'] ?? '') . ' ' . ($k['ekipman_adi'] ?? '') . ' (kalan dönem)'));
        $xml .= "<FATURAHAREKET>\n";
        foreach ($_kalan as $_h) {
            if ($_para !== 'TL' && (int)($_h['kur_kesin'] ?? 0) !== 1) jsonError('Hakediş ' . ($_h['kod'] ?: '#' . $_h['id']) . ' TL\'ye çevrilmemiş; önce kiralamayı fatura tarihiyle "Faturalandı" işaretleyin.', 409);
            $_ad = $stokAdi . ' ' . ($_h['donem_bas'] ? date('d.m.Y', strtotime($_h['donem_bas'])) . '-' . date('d.m.Y', strtotime((string)($_h['donem_bit'] ?: $_h['donem_bas']))) : ($_h['kod'] ?? ''));
            $xml .= wxHareket($_ad, 1, 'ADET', (float)$_h['tutar'], 2, (float)$_h['kdv_oran'], $depoAdi);
        }
        $xml .= "</FATURAHAREKET>\n";
        $aktarilan++;
        continue;
    }

    $xml .= wxFaturaBaslik($k, (string)($k['fatura_no'] ?? ''), $sayacTanimi, (string)($k['kod'] ?? ''), $k['vade_tarihi'] ?? '', trim(($k['kod'] ?? '') . ' ' . ($k['ekipman_adi'] ?? '')));

    $xml .= "<FATURAHAREKET>\n";

    $sure   = (float)($h['sure'] ?? 1) ?: 1.0;
    $miktar = max(1, (int)($k['miktar'] ?? 1));
    $netKira = (float)($h['kira_bedeli'] ?? 0);
    $birimFiyat = ($sure * $miktar) > 0 ? $netKira / ($sure * $miktar) : 0.0;
    $kdvOran = (float)($k['kdv_oran'] ?? 20);

    $kalem = fn(string $ad, $mik, string $birim, $fiyat, int $basamak): string => wxHareket($ad, $mik, $birim, $fiyat, $basamak, $kdvOran, $depoAdi);

    $xml .= $kalem($stokAdi, $sure * $miktar, kiraTipAdi($k['kira_tipi'] ?? 'gunluk'), $birimFiyat, 4);

    if (($h['operator_bedeli'] ?? 0) > 0) {
        $xml .= $kalem('OPERATÖR HİZMETİ', 1, 'ADET', $h['operator_bedeli'], 2);
    }
    if (($h['nakliye'] ?? 0) > 0) {
        $xml .= $kalem('NAKLİYE', 1, 'ADET', $h['nakliye'], 2);
    }

    $xml .= "</FATURAHAREKET>\n";
    $aktarilan++;
}
$xml .= "</WFT>\n";

if ($aktarilan === 0) jsonError('Seçilen kayıtların tutarı sıfır; fatura üretilmedi.');

logla($db, $tur === 'hasar' ? 'hasar_faturalari' : 'kiralamalar', null, 'guncelle', null, null,
    'Wolvox FATURA XML dışa aktarıldı · ' . $aktarilan . ' kayıt');

$dosya = 'wolvox-fatura-' . date('Y-m-d-His') . '.xml';
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/xml; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $dosya . '"');
header('X-Content-Type-Options: nosniff');
echo $xml;
