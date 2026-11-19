<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method Not Allowed', 405);
verifyCsrf();

$body = govdeOku();
$tur  = trim(metin($body['tur'] ?? ''));
$rows = $body['rows'] ?? null;
if (!is_array($rows)) jsonError('Satır verisi geçersiz.');
if (count($rows) > 5000) jsonError('En fazla 5000 satır aktarılabilir.');

$db = getDb();
$eklenen = 0; $atlanan = 0; $hatalar = [];

try {
islemIcinde($db, function (PDO $db) use ($tur, $rows, &$eklenen, &$atlanan, &$hatalar): void {
if ($tur === 'musteri') {
    requirePermission('kiralama_takip');
    $st = $db->prepare("
        INSERT INTO musteriler (tur, unvan, yetkili, telefon, email, vergi_no, vergi_dairesi, adres, notlar, durum, sube, temsilci,
                                wolvox_cari_kodu, acilis_bakiye, acilis_tarihi)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $kodVar = $db->prepare("SELECT unvan FROM musteriler WHERE wolvox_cari_kodu=? LIMIT 1");
    $adVar = $db->prepare("SELECT COUNT(*) FROM musteriler WHERE unvan=? COLLATE NOCASE AND COALESCE(vergi_no,'')=? AND COALESCE(wolvox_cari_kodu,'')=''");
    foreach ($rows as $i => $r) {
        $unvan = trim((string)($r['unvan'] ?? ''));
        if ($unvan === '') { $atlanan++; continue; }
        $cariKod = trim((string)($r['wolvox_cari_kodu'] ?? ''));
        if ($cariKod !== '') {
            $kodVar->execute([$cariKod]);
            $mevcutAd = $kodVar->fetchColumn();
            if ($mevcutAd !== false) { $atlanan++; $hatalar[] = 'Satır ' . ($i + 1) . ': ' . $unvan . ' — cari kodu ' . $cariKod . ' zaten kayıtlı (' . $mevcutAd . ')'; continue; }
        } else {
            $adVar->execute([$unvan, trim((string)($r['vergi_no'] ?? ''))]);
            if ((int)$adVar->fetchColumn() > 0) { $atlanan++; $hatalar[] = 'Satır ' . ($i + 1) . ': ' . $unvan . ' — bu müşteri zaten kayıtlı'; continue; }
        }
        $tip = (strtolower(trim((string)($r['tur'] ?? ''))) === 'bireysel' || stripos((string)($r['tur'] ?? ''), 'şah') !== false) ? 'bireysel' : 'kurumsal';
        try {
            islemIcinde($db, fn() => $st->execute([
                $tip, $unvan,
                trim((string)($r['yetkili'] ?? '')) ?: null,
                trim((string)($r['telefon'] ?? '')) ?: null,
                trim((string)($r['email'] ?? '')) ?: null,
                trim((string)($r['vergi_no'] ?? '')) ?: null,
                trim((string)($r['vergi_dairesi'] ?? '')) ?: null,
                trim((string)($r['adres'] ?? '')) ?: null,
                trim((string)($r['notlar'] ?? '')) ?: null,
                (strtolower(trim((string)($r['durum'] ?? 'aktif'))) === 'pasif') ? 'pasif' : 'aktif',
                trim((string)($r['sube'] ?? '')) ?: null,
                trim((string)($r['temsilci'] ?? '')) ?: null,
                $cariKod ?: null,
                sayiTR($r['acilis_bakiye'] ?? 0),
                tarihTR($r['acilis_tarihi'] ?? ''),
            ]));
            $eklenen++;
        } catch (\Exception $e) { $atlanan++; $hatalar[] = 'Satır ' . ($i + 1) . ': ' . $unvan; }
    }
}



elseif ($tur === 'musteri_wolvox_kod') {
    if (($_SESSION['rol'] ?? '') !== 'admin') throw new DomainException('Cari kodu eşleştirmesini yalnızca yönetici yapabilir.', 400);
    $kodVar = $db->prepare("SELECT id FROM musteriler WHERE wolvox_cari_kodu=? LIMIT 1");
    $bul = $db->prepare("SELECT TRIM(unvan) AS unvan, COALESCE(wolvox_cari_kodu,'') AS kod FROM musteriler WHERE id=?");
    $yaz = $db->prepare("UPDATE musteriler SET wolvox_cari_kodu=?, updated_at=datetime('now','localtime') WHERE id=? AND COALESCE(wolvox_cari_kodu,'')=''");
    foreach ($rows as $i => $r) {
        $id = (int)($r['id'] ?? 0);
        $unvan = trim((string)($r['unvan'] ?? ''));
        $kod = trim((string)($r['wolvox_cari_kodu'] ?? ''));
        $ad = 'Satır ' . ($i + 1) . ': ' . $unvan;
        if ($id < 1 || $unvan === '' || !ctype_digit($kod) || strlen($kod) > 12) { $atlanan++; $hatalar[] = $ad . ' — kayıt numarası, ünvan ya da sayısal cari kodu eksik'; continue; }
        $bul->execute([$id]);
        $m = $bul->fetch(PDO::FETCH_ASSOC);
        if (!$m || $m['unvan'] !== $unvan) { $atlanan++; $hatalar[] = $ad . ' — bu numarada bu ünvanla müşteri yok'; continue; }
        if ($m['kod'] !== '') { $atlanan++; if ($m['kod'] !== $kod) $hatalar[] = $ad . ' — müşteride başka bir cari kodu kayıtlı (' . $m['kod'] . ')'; continue; }
        $kodVar->execute([$kod]);
        if ($kodVar->fetchColumn() !== false) { $atlanan++; $hatalar[] = $ad . ' — cari kodu ' . $kod . ' başka bir müşteride kayıtlı'; continue; }
        $yaz->execute([$kod, $id]);
        if ($yaz->rowCount() === 1) $eklenen++; else $atlanan++;
    }
}

elseif ($tur === 'yedek_parca') {
    requirePermission('yedek_parca');
    $st = $db->prepare("
        INSERT INTO yedek_parca (ad, parca_no, aciklama, sube, stok, kritik_stok, fiyat, uyumlu_makine, seri_no, uretim_no)
        VALUES (?,?,?,?,?,?,?,?,?,?)
    ");
    foreach ($rows as $i => $r) {
        $ad = trim((string)($r['ad'] ?? ''));
        if ($ad === '') { $atlanan++; continue; }
        try {
            islemIcinde($db, fn() => $st->execute([
                $ad,
                trim((string)($r['parca_no'] ?? '')) ?: null,
                trim((string)($r['aciklama'] ?? '')) ?: null,
                trim((string)($r['sube'] ?? '')) ?: null,
                max(0, (int)($r['stok'] ?? 0)),
                max(0, (int)($r['kritik_stok'] ?? 0)),
                max(0, sayiTR($r['fiyat'] ?? 0)),
                trim((string)($r['uyumlu_makine'] ?? '')) ?: null,
                trim((string)($r['seri_no'] ?? '')) ?: null,
                trim((string)($r['uretim_no'] ?? '')) ?: null,
            ]));
            $eklenen++;
        } catch (\Exception $e) { $atlanan++; $hatalar[] = 'Satır ' . ($i + 1) . ': ' . $ad; }
    }
}

elseif ($tur === 'personel') {
    requirePermission('personel');
    $st = $db->prepare("
        INSERT INTO personel (ad_soyad, tc, email, telefon, ise_giris, pozisyon, adres, notlar, durum)
        VALUES (?,?,?,?,?,?,?,?,'aktif')
    ");
    foreach ($rows as $i => $r) {
        $ad = trim(metin($r['ad_soyad'] ?? ''));
        if ($ad === '') { $atlanan++; continue; }
        $tc = preg_replace('/\D/', '', metin($r['tc'] ?? ''));
        if ($tc !== '' && strlen($tc) !== 11) { $atlanan++; $hatalar[] = 'Satır ' . ($i + 1) . ': ' . $ad . ' — T.C. Kimlik No 11 haneli olmalıdır'; continue; }
        try {
            islemIcinde($db, fn() => $st->execute([
                $ad,
                $tc ?: null,
                trim((string)($r['email'] ?? '')) ?: null,
                trim((string)($r['telefon'] ?? '')) ?: null,
                dateStr('ise_giris', $r),
                trim((string)($r['pozisyon'] ?? '')) ?: null,
                trim((string)($r['adres'] ?? '')) ?: null,
                trim((string)($r['notlar'] ?? '')) ?: null,
            ]));
            $eklenen++;
        } catch (\Exception $e) { $atlanan++; $hatalar[] = 'Satır ' . ($i + 1) . ': ' . $ad; }
    }
}

elseif ($tur === 'arac') {
    requirePermission('arac');
    $mkAdlar = array_column(mkListe($db), 'ad');
    $st = $db->prepare("
        INSERT INTO araclar (plaka, marka, model, ruhsat_no, tescil_tarihi, trafik_bitis, kasko_bitis, tuv_muayene_bitis, periyodik_kontrol_bitis, sube, notlar, aktif)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,1)
    ");
    foreach ($rows as $i => $r) {
        $plaka = strtoupper(trim(preg_replace('/\s+/', ' ', (string)($r['plaka'] ?? ''))));
        if ($plaka === '') { $atlanan++; continue; }
        try {
            islemIcinde($db, fn() => $st->execute([
                $plaka,
                trim((string)($r['marka'] ?? '')) ?: null,
                trim((string)($r['model'] ?? '')) ?: null,
                trim((string)($r['ruhsat_no'] ?? '')) ?: null,
                tarihTR($r['tescil_tarihi'] ?? ''),
                dateStr('trafik_bitis', $r),
                dateStr('kasko_bitis', $r),
                dateStr('tuv_muayene_bitis', $r),
                dateStr('periyodik_kontrol_bitis', $r),
                trim((string)($r['sube'] ?? '')) ?: null,
                trim((string)($r['notlar'] ?? '')) ?: null,
            ]));
            $eklenen++;
            if (($mkKat = trim((string)($r['kategori'] ?? ''))) !== '') {
                if (in_array($mkKat, $mkAdlar, true)) $db->prepare('UPDATE araclar SET kategori=? WHERE id=?')->execute([$mkKat, (int)$db->lastInsertId()]);
                else $hatalar[] = 'Satır ' . ($i + 1) . ': ' . $plaka . ' — kategori listede yok: ' . $mkKat . ' (kategorisiz eklendi)';
            }
        } catch (\Exception $e) {
            $atlanan++;
            $hatalar[] = 'Satır ' . ($i + 1) . ': ' . $plaka . (strpos($e->getMessage(), 'UNIQUE') !== false ? ' (bu plaka zaten kayıtlı)' : '');
        }
    }
}

elseif ($tur === 'tedarikci') {
    requirePermission('satinalma');
    $st = $db->prepare("
        INSERT INTO tedarikciler (unvan, yetkili, telefon, email, vergi_dairesi, vergi_no, adres, notlar, aktif)
        VALUES (?,?,?,?,?,?,?,?,1)
    ");
    foreach ($rows as $i => $r) {
        $unvan = trim((string)($r['unvan'] ?? ''));
        if ($unvan === '') { $atlanan++; continue; }
        try {
            islemIcinde($db, fn() => $st->execute([
                $unvan,
                trim((string)($r['yetkili'] ?? '')) ?: null,
                trim((string)($r['telefon'] ?? '')) ?: null,
                trim((string)($r['email'] ?? '')) ?: null,
                trim((string)($r['vergi_dairesi'] ?? '')) ?: null,
                trim((string)($r['vergi_no'] ?? '')) ?: null,
                trim((string)($r['adres'] ?? '')) ?: null,
                trim((string)($r['notlar'] ?? '')) ?: null,
            ]));
            $eklenen++;
        } catch (\Exception $e) { $atlanan++; $hatalar[] = 'Satır ' . ($i + 1) . ': ' . $unvan; }
    }
}

elseif ($tur === 'manlift') {
    requirePermission('manlift');
    $mkAdlar = array_column(mkListe($db), 'ad');
    $st = $db->prepare("
        INSERT INTO manliftler (kod, marka, model, seri_no, imal_yili, ruhsat_no, tescil_tarihi, periyodik_bitis, metraj, sube, notlar, alis_tarihi, makine_kodu, aktif)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)
    ");
    foreach ($rows as $i => $r) {
        $kod = trim((string)($r['kod'] ?? ''));
        if ($kod === '') { $atlanan++; continue; }
        try {
            islemIcinde($db, fn() => $st->execute([
                $kod,
                trim((string)($r['marka'] ?? '')) ?: null,
                trim((string)($r['model'] ?? '')) ?: null,
                trim((string)($r['seri_no'] ?? '')) ?: null,
                ((int)($r['imal_yili'] ?? 0)) ?: null,
                trim((string)($r['ruhsat_no'] ?? '')) ?: null,
                tarihTR($r['tescil_tarihi'] ?? ''),
                dateStr('periyodik_bitis', $r),
                metrajNormal($r['metraj'] ?? null),
                trim((string)($r['sube'] ?? '')) ?: null,
                trim((string)($r['notlar'] ?? '')) ?: null,
                dateStr('alis_tarihi', $r),
                trim((string)($r['makine_kodu'] ?? '')) ?: null,
            ]));
            $eklenen++;
            if (($mkKat = trim((string)($r['kategori'] ?? ''))) !== '') {
                if (in_array($mkKat, $mkAdlar, true)) $db->prepare('UPDATE manliftler SET kategori=? WHERE id=?')->execute([$mkKat, (int)$db->lastInsertId()]);
                else $hatalar[] = 'Satır ' . ($i + 1) . ': ' . $kod . ' — kategori listede yok: ' . $mkKat . ' (kategorisiz eklendi)';
            }
        } catch (\Exception $e) {
            $atlanan++;
            $hatalar[] = 'Satır ' . ($i + 1) . ': ' . $kod . (strpos($e->getMessage(), 'UNIQUE') !== false ? ' (bu kod zaten kayıtlı)' : '');
        }
    }
}

else {
    throw new DomainException('Geçersiz içe aktarım türü.', 400);
}

if ($eklenen > 0 || $atlanan > 0) {
    logla($db, 'import', null, 'ekle', null, null,
          sprintf('Toplu içe aktarma (%s): %d eklendi, %d atlandı%s',
                  $tur, $eklenen, $atlanan, $hatalar ? ', ' . count($hatalar) . ' hata' : ''));
}
});
} catch (DomainException $e) {
    jsonError($e->getMessage(), 400);
}

jsonOk([
    'eklenen' => $eklenen,
    'atlanan' => $atlanan,
    'hatalar' => array_slice($hatalar, 0, 20),
], $tur === 'musteri_wolvox_kod' ? "$eklenen müşteriye cari kodu yazıldı, $atlanan atlandı." : "$eklenen kayıt eklendi, $atlanan atlandı.");
