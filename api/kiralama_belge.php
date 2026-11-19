<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('kiralama_takip');


$db     = getDb();
$method = $_SERVER['REQUEST_METHOD'];

function _kbTur($v): string
{
    $g = ['kira_formu', 'calisma_sozlesmesi', 'teslim_formu', 'hasar_dosyasi', 'diger'];
    return in_array(metin($v), $g, true) ? metin($v) : 'kira_formu';
}

function _kbKiralama(PDO $db, int $kid): array
{
    $where = ['id = ?']; $params = [$kid];
    subeKosulEkle($where, $params, 'sube');
    $st = $db->prepare("SELECT id, kod, sube FROM kiralamalar WHERE " . implode(' AND ', $where));
    $st->execute($params);
    $k = $st->fetch();
    if (!$k) jsonError('Kiralama bulunamadı (ya da başka şubeye ait).', 404);
    return $k;
}

function _kbBelge(PDO $db, int $id): array
{
    $st = $db->prepare("SELECT * FROM kiralama_belgeler WHERE id=?");
    $st->execute([$id]);
    $b = $st->fetch();
    if (!$b) jsonError('Belge bulunamadı.', 404);
    _kbKiralama($db, (int)$b['kiralama_id']);
    return $b;
}

if ($method === 'GET') {
    $kid = (int)($_GET['kiralama_id'] ?? 0);
    if ($kid < 1) jsonError('Kiralama seçilmedi.');
    _kbKiralama($db, $kid);
    $st = $db->prepare("SELECT id, belge_turu, form_no, form_tarihi, belge_adi, dosya_yolu, notlar, yukleyen, created_at
                        FROM kiralama_belgeler WHERE kiralama_id=? ORDER BY id DESC");
    $st->execute([$kid]);
    jsonOk($st->fetchAll());
}

if ($method === 'POST') {
    verifyCsrf();
    $kid = (int)($_GET['kiralama_id'] ?? 0);
    if ($kid < 1) jsonError('Kiralama seçilmedi.');
    $k = _kbKiralama($db, $kid);

    $body  = govdeOku();
    $gelen = $body['belgeler'] ?? [];
    if (!is_array($gelen) || !$gelen) jsonError('Belge gönderilmedi.');
    if (count($gelen) > 10) jsonError('Tek seferde en fazla 10 belge eklenebilir.');

    $tur     = _kbTur($body['belge_turu'] ?? null);
    $formNo  = trim(metin($body['form_no'] ?? '')) ?: null;
    $formTar = dateStr('form_tarihi', $body);
    $notlar  = trim(metin($body['notlar'] ?? '')) ?: null;

    $dir = UPLOAD_PATH . 'kiralama_belge/' . $kid . '/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) jsonError('Klasör oluşturulamadı.', 500);

    $ins = $db->prepare("INSERT INTO kiralama_belgeler
        (kiralama_id, belge_turu, form_no, form_tarihi, belge_adi, dosya_yolu, notlar, yukleyen)
        VALUES (?,?,?,?,?,?,?,?)");
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
        $ins->execute([$kid, $tur, $formNo, $formTar,
                       trim(metin($b['ad'] ?? '')) ?: ('Kira formu.' . $uz),
                       'kiralama_belge/' . $kid . '/' . $ad, $notlar, $_SESSION['username'] ?? null]);
        $eklenen++;
    }
    if ($eklenen === 0) jsonError('Hiçbir belge kaydedilemedi. Dosyalar bozuk ya da desteklenmeyen biçimde (JPG/PNG/WEBP/PDF).');

    logla($db, 'kiralamalar', $kid, 'guncelle', null, null,
          'Kira formu eklendi · ' . $eklenen . ' belge' . ($formNo ? ' · form no ' . $formNo : ''));
    jsonOk(['eklenen' => $eklenen, 'atlanan' => $atlanan],
           $eklenen . ' belge eklendi.' . ($atlanan ? ' ' . $atlanan . ' dosya okunamadı.' : ''));
}

if ($method === 'PUT') {
    verifyCsrf();
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) jsonError('Geçersiz ID.');
    $b = _kbBelge($db, $id);
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) jsonError('Geçersiz istek gövdesi.');
    alanlarZorunlu($body, ['belge_adi', 'belge_turu', 'form_no', 'form_tarihi', 'notlar']);
    $belgeAdi = belgeAdiAl($body);
    if ($belgeAdi === '') jsonError('Belge adı boş bırakılamaz.');
    $db->prepare("UPDATE kiralama_belgeler SET belge_adi=?, belge_turu=?, form_no=?, form_tarihi=?, notlar=? WHERE id=?")
       ->execute([
        $belgeAdi,
        _kbTur(metin($body['belge_turu'])),
        belgeNoAl($body, 'form_no'),
        dateStr('form_tarihi', $body),
        trim(metin($body['notlar'])) ?: null,
        $id,
       ]);
    logla($db, 'kiralama_belgeler', $id, 'guncelle', $b, logKayitAl($db, 'kiralama_belgeler', $id),
          'Kiralama evrakı düzenlendi: ' . $belgeAdi . ' (kiralama #' . (int)$b['kiralama_id'] . ')');
    jsonOk(['id' => $id], 'Belge bilgileri güncellendi.');
}

if ($method === 'DELETE') {
    verifyCsrf();
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) jsonError('Geçersiz ID.');
    $b = _kbBelge($db, $id);
    $tam = UPLOAD_PATH . $b['dosya_yolu'];
    if (is_file($tam)) @unlink($tam);
    $db->prepare("DELETE FROM kiralama_belgeler WHERE id=?")->execute([$id]);
    logla($db, 'kiralamalar', (int)$b['kiralama_id'], 'guncelle', null, null, 'Kira formu silindi.');
    jsonOk(null, 'Belge silindi.');
}

jsonError('Method Not Allowed', 405);
