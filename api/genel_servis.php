<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
subeMerkezi('genel_servis');


$db     = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

function _gsTur($v): string
{
    $g = ['genel_bakim', 'yag_degisimi', 'lastik', 'fren', 'hidrolik', 'akü', 'muayene', 'diger'];
    return in_array(metin($v), $g, true) ? metin($v) : 'genel_bakim';
}

function _gsMakine(PDO $db, ?string $tur, int $id): array
{
    if (!in_array((string)$tur, ['arac', 'manlift'], true) || $id < 1) jsonError('Geçersiz makine.');
    requirePermission($tur, $_SERVER['REQUEST_METHOD'] === 'GET' ? 'oku' : 'yaz');
    $tablo  = $tur === 'arac' ? 'araclar' : 'manliftler';
    $adAlan = $tur === 'arac' ? 'plaka' : 'kod';
    $st = $db->prepare("SELECT id, $adAlan AS ad, marka, model, sube FROM $tablo WHERE id=?");
    $st->execute([$id]);
    $m = $st->fetch();
    if (!$m) jsonError('Makine bulunamadı.', 404);
    subeErisimDogrula($m['sube'] ?? null);
    $m['tur'] = $tur;
    return $m;
}

function _gsKayit(PDO $db, int $id): array
{
    $st = $db->prepare("SELECT * FROM genel_servis WHERE id=?");
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) jsonError('Servis kaydı bulunamadı.', 404);
    _gsMakine($db, $r['makine_tur'], (int)$r['makine_id']);
    return $r;
}

function _gsDurum(array $r, ?float $guncelKmSaat): array
{
    $bugun = date('Y-m-d');
    $gunKalan = null;
    if (!empty($r['sonraki_tarih'])) {
        $gunKalan = (int)floor((strtotime($r['sonraki_tarih']) - strtotime($bugun)) / 86400);
    }
    $kmKalan = null;
    if (!empty($r['sonraki_km']) && $guncelKmSaat !== null) {
        $kmKalan = round((float)$r['sonraki_km'] - $guncelKmSaat, 1);
    }

    $durum = 'normal';
    if (($gunKalan !== null && $gunKalan < 0) || ($kmKalan !== null && $kmKalan < 0)) {
        $durum = 'gecti';
    } elseif (($gunKalan !== null && $gunKalan <= 15) || ($kmKalan !== null && $kmKalan <= 500)) {
        $durum = 'yaklasan';
    }
    return ['durum' => $durum, 'gun_kalan' => $gunKalan, 'km_kalan' => $kmKalan];
}

function _gsGuncelKmSaat(PDO $db, string $tur, int $id): ?float
{
    $tablo = $tur === 'arac' ? 'araclar' : 'manliftler';
    $st = $db->prepare("SELECT calisma_saati FROM $tablo WHERE id=?");
    $st->execute([$id]);
    $v = $st->fetchColumn();
    return $v === false || $v === null ? null : (float)$v;
}

function _gsKalemleriKaydet(PDO $db, int $servisId, array $kalemler): float
{
    $db->prepare("DELETE FROM genel_servis_kalemleri WHERE servis_id=?")->execute([$servisId]);
    $ins = $db->prepare("INSERT INTO genel_servis_kalemleri (servis_id, ad, kod, marka, model, tutar) VALUES (?,?,?,?,?,?)");
    $toplam = 0.0;
    foreach ($kalemler as $k) {
        if (!is_array($k)) continue;
        $ad = trim(metin($k['ad'] ?? ''));
        if ($ad === '') continue;
        $tutar = max(0, (float)($k['tutar'] ?? 0));
        $ins->execute([
            $servisId, $ad,
            trim(metin($k['kod'] ?? '')) ?: null,
            trim(metin($k['marka'] ?? '')) ?: null,
            trim(metin($k['model'] ?? '')) ?: null,
            $tutar,
        ]);
        $toplam += $tutar;
    }
    return round($toplam, 2);
}

if ($method === 'POST' && $action === 'belge') {
    verifyCsrf();
    $sid = (int)($_GET['servis_id'] ?? 0);
    if ($sid < 1) jsonError('Servis kaydı seçilmedi.');
    _gsKayit($db, $sid);

    $body  = govdeOku();
    $gelen = $body['belgeler'] ?? [];
    if (!is_array($gelen) || !$gelen) jsonError('Belge gönderilmedi.');
    if (count($gelen) > 10) jsonError('Tek seferde en fazla 10 belge eklenebilir.');

    $dir = UPLOAD_PATH . 'genel_servis/' . $sid . '/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) jsonError('Klasör oluşturulamadı.', 500);

    $belgeNo  = belgeNoAl($body);
    $belgeTar = dateStr('belge_tarihi', $body);
    $ins = $db->prepare("INSERT INTO genel_servis_belgeler (servis_id, belge_adi, belge_no, belge_tarihi, dosya_yolu) VALUES (?,?,?,?,?)");
    $eklenen = 0; $atlanan = 0;
    foreach ($gelen as $b) {
        if (!is_array($b) || empty($b['veri'])) { $atlanan++; continue; }
        if (!preg_match('/^data:(image\/(jpe?g|png|webp)|application\/pdf);base64,([A-Za-z0-9+\/=]+)$/', (string)$b['veri'], $m)) { $atlanan++; continue; }
        $ikili = base64_decode($m[3], true);
        if ($ikili === false || strlen($ikili) < 100 || strlen($ikili) > MAX_FILE_SIZE) { $atlanan++; continue; }
        $pdfMi = str_starts_with($m[1], 'application/');
        if ($pdfMi) { if (substr($ikili, 0, 4) !== '%PDF') { $atlanan++; continue; } }
        else        { if (!getimagesizefromstring($ikili)) { $atlanan++; continue; } }
        $uz = $pdfMi ? 'pdf' : ($m[2] === 'jpg' ? 'jpeg' : $m[2]);
        $ad = bin2hex(random_bytes(16)) . '.' . $uz;
        if (file_put_contents($dir . $ad, $ikili) === false) { $atlanan++; continue; }
        @chmod($dir . $ad, 0644);
        $ins->execute([$sid, mb_substr(trim(metin($b['ad'] ?? '')), 0, 200) ?: ('Servis belgesi.' . $uz), $belgeNo, $belgeTar, 'genel_servis/' . $sid . '/' . $ad]);
        $eklenen++;
    }
    if ($eklenen === 0) jsonError('Hiçbir belge kaydedilemedi. Dosyalar bozuk ya da desteklenmeyen biçimde (JPG/PNG/WEBP/PDF).');
    logla($db, 'genel_servis', $sid, 'guncelle', null, null, 'Servis evrakı eklendi · ' . $eklenen . ' adet' . ($belgeNo !== null ? ' · belge no ' . $belgeNo : ''));
    jsonOk(['eklenen' => $eklenen, 'atlanan' => $atlanan],
           $eklenen . ' belge eklendi.' . ($atlanan ? ' ' . $atlanan . ' dosya okunamadı.' : ''));
}

if ($method === 'GET' && $action === 'belgeler') {
    $sid = (int)($_GET['servis_id'] ?? 0);
    if ($sid < 1) jsonError('Servis kaydı seçilmedi.');
    _gsKayit($db, $sid);
    $st = $db->prepare("SELECT id, belge_adi, belge_no, belge_tarihi, dosya_yolu, created_at FROM genel_servis_belgeler WHERE servis_id=? ORDER BY id");
    $st->execute([$sid]);
    jsonOk($st->fetchAll());
}

if ($method === 'GET' && $action === 'kalemler') {
    $sid = (int)($_GET['servis_id'] ?? 0);
    if ($sid < 1) jsonError('Servis kaydı seçilmedi.');
    _gsKayit($db, $sid);
    $st = $db->prepare("SELECT id, ad, kod, marka, model, tutar FROM genel_servis_kalemleri WHERE servis_id=? ORDER BY id");
    $st->execute([$sid]);
    jsonOk($st->fetchAll());
}

if ($method === 'DELETE' && $action === 'belge') {
    verifyCsrf();
    $bid = (int)($_GET['id'] ?? 0);
    if ($bid < 1) jsonError('Geçersiz ID.');
    $st = $db->prepare("SELECT * FROM genel_servis_belgeler WHERE id=?");
    $st->execute([$bid]);
    $b = $st->fetch();
    if (!$b) jsonError('Belge bulunamadı.', 404);
    _gsKayit($db, (int)$b['servis_id']);
    $tam = UPLOAD_PATH . $b['dosya_yolu'];
    if (is_file($tam)) @unlink($tam);
    $db->prepare("DELETE FROM genel_servis_belgeler WHERE id=?")->execute([$bid]);
    logla($db, 'genel_servis_belgeler', $bid, 'sil', $b, null, 'Servis evrakı silindi: ' . ($b['belge_adi'] ?? ('#' . $bid)));
    jsonOk(null, 'Belge silindi.');
}

if ($method === 'PUT' && $action === 'belge') {
    verifyCsrf();
    $bid = (int)($_GET['id'] ?? 0);
    if ($bid < 1) jsonError('Geçersiz ID.');
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) jsonError('Geçersiz istek gövdesi.');
    alanlarZorunlu($body, ['belge_adi', 'belge_no', 'belge_tarihi']);
    $eski = logKayitAl($db, 'genel_servis_belgeler', $bid);
    if (!$eski) jsonError('Belge bulunamadı.', 404);
    _gsKayit($db, (int)$eski['servis_id']);
    $belgeAdi = belgeAdiAl($body);
    if ($belgeAdi === '') jsonError('Belge adı boş bırakılamaz.');
    $db->prepare("UPDATE genel_servis_belgeler SET belge_adi=?, belge_no=?, belge_tarihi=? WHERE id=?")
       ->execute([$belgeAdi, belgeNoAl($body), dateStr('belge_tarihi', $body), $bid]);
    logla($db, 'genel_servis_belgeler', $bid, 'guncelle', $eski, logKayitAl($db, 'genel_servis_belgeler', $bid),
          'Servis evrakı düzenlendi: ' . $belgeAdi . ' (bakım #' . (int)$eski['servis_id'] . ')');
    jsonOk(['id' => $bid], 'Servis evrakı güncellendi.');
}

if ($method === 'GET' && $action === 'yaklasan') {
    if (!hasPermission('arac', 'oku') && !hasPermission('manlift', 'oku')) {
        jsonError('Bu modül için yetkiniz yok.', 403);
    }
    $gun = max(1, min(365, (int)($_GET['gun'] ?? 30)));
    $sinir = date('Y-m-d', strtotime('+' . $gun . ' days'));

    $rows = $db->query("
        SELECT g.* FROM genel_servis g
        JOIN (SELECT makine_tur, makine_id, MAX(tarih) mt, MAX(id) mi
              FROM genel_servis GROUP BY makine_tur, makine_id) s
          ON s.makine_tur = g.makine_tur AND s.makine_id = g.makine_id AND s.mi = g.id
        WHERE g.sonraki_tarih IS NOT NULL
        ORDER BY g.sonraki_tarih
    ")->fetchAll();

    $kisit = subeKisiti();
    $out = [];
    foreach ($rows as $r) {
        $tablo  = $r['makine_tur'] === 'arac' ? 'araclar' : 'manliftler';
        $adAlan = $r['makine_tur'] === 'arac' ? 'plaka' : 'kod';
        $ms = $db->prepare("SELECT $adAlan AS ad, marka, sube, calisma_saati FROM $tablo WHERE id=?");
        $ms->execute([(int)$r['makine_id']]);
        $m = $ms->fetch();
        if (!$m) continue;
        if ($kisit !== null && trim((string)($m['sube'] ?? '')) !== $kisit) continue;
        if ($r['sonraki_tarih'] > $sinir) continue;

        $d = _gsDurum($r, $m['calisma_saati'] !== null ? (float)$m['calisma_saati'] : null);
        $out[] = array_merge($r, [
            'makine_ad' => $m['ad'], 'marka' => $m['marka'], 'sube' => $m['sube'],
        ], $d);
    }
    jsonOk($out);
}

if ($method === 'GET' && $action === 'rapor') {
    if (!hasPermission('arac', 'oku') && !hasPermission('manlift', 'oku')) {
        jsonError('Bu modül için yetkiniz yok.', 403);
    }
    $bas = dateStr('bas', $_GET) ?: date('Y-01-01');
    $bit = dateStr('bit', $_GET) ?: date('Y-m-d');

    $rows = $db->prepare("SELECT * FROM genel_servis WHERE tarih BETWEEN ? AND ? ORDER BY tarih DESC");
    $rows->execute([$bas, $bit]);
    $liste = $rows->fetchAll();

    $kisit = subeKisiti();
    $turOzet = []; $makineOzet = []; $toplam = 0.0; $adet = 0; $sonuc = [];
    foreach ($liste as $r) {
        $tablo  = $r['makine_tur'] === 'arac' ? 'araclar' : 'manliftler';
        $adAlan = $r['makine_tur'] === 'arac' ? 'plaka' : 'kod';
        $ms = $db->prepare("SELECT $adAlan AS ad, sube FROM $tablo WHERE id=?");
        $ms->execute([(int)$r['makine_id']]);
        $m = $ms->fetch();
        if (!$m) continue;
        if ($kisit !== null && trim((string)($m['sube'] ?? '')) !== $kisit) continue;

        $r['makine_ad'] = $m['ad'];
        $r['sube'] = $m['sube'];
        $sonuc[] = $r;

        $t = $r['tur'];
        $turOzet[$t] = ['tur' => $t,
                        'adet' => ($turOzet[$t]['adet'] ?? 0) + 1,
                        'maliyet' => round(($turOzet[$t]['maliyet'] ?? 0) + (float)$r['maliyet'], 2)];
        $k = $r['makine_tur'] . ':' . $r['makine_id'];
        $makineOzet[$k] = ['makine' => $m['ad'], 'tur' => $r['makine_tur'],
                           'adet' => ($makineOzet[$k]['adet'] ?? 0) + 1,
                           'maliyet' => round(($makineOzet[$k]['maliyet'] ?? 0) + (float)$r['maliyet'], 2)];
        $toplam += (float)$r['maliyet'];
        $adet++;
    }
    usort($makineOzet, fn($a, $b) => $b['maliyet'] <=> $a['maliyet']);
    jsonOk([
        'bas' => $bas, 'bit' => $bit, 'liste' => $sonuc,
        'tur_ozet' => array_values($turOzet),
        'makine_ozet' => array_values($makineOzet),
        'toplam_maliyet' => round($toplam, 2), 'adet' => $adet,
    ]);
}

if ($method === 'GET') {
    $tur = trim(metin($_GET['makine_tur'] ?? ''));
    $id  = (int)($_GET['makine_id'] ?? 0);
    _gsMakine($db, $tur, $id);

    $st = $db->prepare("SELECT g.*, (SELECT COUNT(*) FROM genel_servis_belgeler b WHERE b.servis_id = g.id) AS belge_adet,
                          (SELECT COUNT(*) FROM genel_servis_kalemleri k WHERE k.servis_id = g.id) AS kalem_adet
                        FROM genel_servis g WHERE g.makine_tur=? AND g.makine_id=? ORDER BY g.tarih DESC, g.id DESC");
    $st->execute([$tur, $id]);
    $liste = $st->fetchAll();

    $guncel = _gsGuncelKmSaat($db, $tur, $id);
    $siradaki = null;
    if ($liste) $siradaki = array_merge(['kayit_id' => (int)$liste[0]['id']], _gsDurum($liste[0], $guncel));

    jsonOk([
        'liste' => $liste,
        'siradaki' => $siradaki,
        'guncel_km_saat' => $guncel,
        'toplam_maliyet' => round(array_sum(array_map(fn($r) => (float)$r['maliyet'], $liste)), 2),
    ]);
}

if ($method === 'POST') {
    verifyCsrf();
    $body = govdeOku();
    $tur  = trim(metin($body['makine_tur'] ?? ''));
    $id   = (int)($body['makine_id'] ?? 0);
    $m    = _gsMakine($db, $tur, $id);

    $tarih = dateStr('tarih', $body);
    if (!$tarih) jsonError('Bakım tarihi zorunludur.');
    $yapilan = trim(metin($body['yapilan'] ?? ''));
    if ($yapilan === '') jsonError('Yapılan işlem yazılmalıdır — "bakımda ne yapıldı" kaydın asıl değeridir.');

    $hatirlatGun = max(0, min(3650, (int)($body['hatirlat_gun'] ?? 0)));
    $sonraki = dateStr('sonraki_tarih', $body);
    if (!$sonraki && $hatirlatGun > 0) $sonraki = date('Y-m-d', strtotime($tarih . ' +' . $hatirlatGun . ' days'));

    $kalemler = is_array($body['kalemler'] ?? null) ? $body['kalemler'] : null;
    $maliyet  = $kalemler !== null ? 0.0 : max(0, (float)($body['maliyet'] ?? 0));

    $st = $db->prepare("INSERT INTO genel_servis
        (makine_tur, makine_id, tarih, tur, yapilan, km_saat, maliyet, servis_firma, sonraki_tarih, sonraki_km, hatirlat_gun, notlar, olusturan)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $st->execute([
        $tur, $id, $tarih, _gsTur($body['tur'] ?? null), $yapilan,
        isset($body['km_saat']) && $body['km_saat'] !== '' ? (float)$body['km_saat'] : null,
        $maliyet,
        trim(metin($body['servis_firma'] ?? '')) ?: null,
        $sonraki,
        isset($body['sonraki_km']) && $body['sonraki_km'] !== '' ? (float)$body['sonraki_km'] : null,
        $hatirlatGun,
        trim(metin($body['notlar'] ?? '')) ?: null,
        $_SESSION['username'] ?? null,
    ]);
    $yeni = (int)$db->lastInsertId();
    if ($kalemler !== null) {
        $maliyet = _gsKalemleriKaydet($db, $yeni, $kalemler);
        $db->prepare("UPDATE genel_servis SET maliyet=? WHERE id=?")->execute([$maliyet, $yeni]);
    }
    logla($db, 'genel_servis', $yeni, 'ekle', null, logKayitAl($db, 'genel_servis', $yeni),
          'Genel servis: ' . $m['ad'] . ' · ' . $tarih . ' · ' . mb_substr($yapilan, 0, 60));
    jsonOk(['id' => $yeni, 'sonraki_tarih' => $sonraki, 'maliyet' => $maliyet], 'Bakım kaydı eklendi.');
}

if ($method === 'PUT') {
    verifyCsrf();
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) jsonError('Geçersiz ID.');
    $mevcut = _gsKayit($db, $id);
    $body   = govdeOku();
    $eski   = logKayitAl($db, 'genel_servis', $id);

    $tarih = dateStr('tarih', $body) ?: $mevcut['tarih'];
    $yapilan = trim(metin($body['yapilan'] ?? ''));
    if ($yapilan === '') jsonError('Yapılan işlem yazılmalıdır.');
    $hatirlatGun = max(0, min(3650, (int)($body['hatirlat_gun'] ?? 0)));
    $sonraki = dateStr('sonraki_tarih', $body);
    if (!$sonraki && $hatirlatGun > 0) $sonraki = date('Y-m-d', strtotime($tarih . ' +' . $hatirlatGun . ' days'));

    $kalemler = is_array($body['kalemler'] ?? null) ? $body['kalemler'] : null;
    $maliyet  = $kalemler !== null ? _gsKalemleriKaydet($db, $id, $kalemler) : max(0, (float)($body['maliyet'] ?? 0));

    $db->prepare("UPDATE genel_servis SET tarih=?, tur=?, yapilan=?, km_saat=?, maliyet=?, servis_firma=?,
                    sonraki_tarih=?, sonraki_km=?, hatirlat_gun=?, notlar=?, updated_at=datetime('now','localtime')
                  WHERE id=?")
       ->execute([
        $tarih, _gsTur($body['tur'] ?? $mevcut['tur']), $yapilan,
        isset($body['km_saat']) && $body['km_saat'] !== '' ? (float)$body['km_saat'] : null,
        $maliyet,
        trim(metin($body['servis_firma'] ?? '')) ?: null,
        $sonraki,
        isset($body['sonraki_km']) && $body['sonraki_km'] !== '' ? (float)$body['sonraki_km'] : null,
        $hatirlatGun,
        trim(metin($body['notlar'] ?? '')) ?: null,
        $id,
       ]);
    logla($db, 'genel_servis', $id, 'guncelle', $eski, logKayitAl($db, 'genel_servis', $id), 'Genel servis güncellendi');
    jsonOk(['sonraki_tarih' => $sonraki, 'maliyet' => $maliyet], 'Bakım kaydı güncellendi.');
}

if ($method === 'DELETE') {
    verifyCsrf();
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) jsonError('Geçersiz ID.');
    $mevcut = _gsKayit($db, $id);
    $eski = logKayitAl($db, 'genel_servis', $id);
    $db->prepare("DELETE FROM genel_servis WHERE id=?")->execute([$id]);
    logla($db, 'genel_servis', $id, 'sil', $eski, null, 'Genel servis kaydı silindi');
    jsonOk(null, 'Bakım kaydı silindi.');
}

jsonError('Method Not Allowed', 405);
