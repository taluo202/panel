<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();


$db     = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$_oturumPersonelId = (int)($_SESSION['personel_id'] ?? 0);

function _kfKendiKiralamasiMi(PDO $db, int $kiralamaId, int $oturumPersonelId): bool {
    if ($oturumPersonelId < 1 || $kiralamaId < 1) return false;
    $st = $db->prepare("SELECT 1 FROM kiralamalar WHERE id=? AND operatorlu=1 AND operator_id=?");
    $st->execute([$kiralamaId, $oturumPersonelId]);
    return (bool)$st->fetchColumn();
}

function _kfKiralamaDogrula(PDO $db, int $kid, int $oturumPersonelId): array {
    $where  = ['id = ?'];
    $params = [$kid];
    subeKosulEkle($where, $params, 'sube');
    $st = $db->prepare("SELECT id, kod, sube, makine_tur, makine_id FROM kiralamalar WHERE " . implode(' AND ', $where));
    $st->execute($params);
    $k = $st->fetch();
    if (!$k) jsonError('Kiralama bulunamadı (ya da başka şubeye ait).', 404);
    $depoYetkili = opYeniZincir($db, ['id' => $kid]) && (opOturumYetkisi($db, 'depo') || opOturumYetkisi($db, 'yukleme'));
    if (!$depoYetkili && !_kfKendiKiralamasiMi($db, $kid, $oturumPersonelId)) requirePermission('kiralama_takip');
    return $k;
}

function _kfFotoKaydet(string $dataUrl, int $kid): ?string {
    if (!preg_match('/^data:image\/(jpe?g|png|webp);base64,([a-zA-Z0-9+\/=]+)$/', $dataUrl, $m)) return null;
    $binary = base64_decode($m[2], true);
    if ($binary === false || strlen($binary) < 100 || strlen($binary) > MAX_FILE_SIZE) return null;
    if (!gorselPikselUygun($binary)) return null;

    $ext = $m[1] === 'jpg' ? 'jpeg' : $m[1];
    $dir = UPLOAD_PATH . 'kiralama/' . $kid . '/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return null;
    $ad = bin2hex(random_bytes(16)) . '.' . $ext;
    if (file_put_contents($dir . $ad, $binary) === false) return null;
    @chmod($dir . $ad, 0644);
    return 'kiralama/' . $kid . '/' . $ad;
}

if ($method === 'GET') {
    $kid = (int)($_GET['kiralama_id'] ?? 0);
    if ($kid < 1) jsonError('Kiralama seçilmedi.');
    _kfKiralamaDogrula($db, $kid, $_oturumPersonelId);
    $st = $db->prepare("SELECT id, aci_tipi, aciklama, dosya_yolu, yon, created_at
                        FROM kiralama_fotograflari WHERE kiralama_id=? ORDER BY id");
    $st->execute([$kid]);
    jsonOk($st->fetchAll());
}

if ($method === 'POST') {
    verifyCsrf();
    $kid = (int)($_GET['kiralama_id'] ?? 0);
    if ($kid < 1) jsonError('Kiralama seçilmedi.');
    $k = _kfKiralamaDogrula($db, $kid, $_oturumPersonelId);

    $body   = govdeOku();
    $gelen  = $body['fotograflar'] ?? [];
    $yon    = ((string)($body['yon'] ?? 'gidis')) === 'donus' ? 'donus' : 'gidis';
    if (!is_array($gelen) || !$gelen) jsonError('Fotoğraf gönderilmedi.');
    if (count($gelen) > 20) jsonError('Tek seferde en fazla 20 fotoğraf eklenebilir.');
    if ($_gh = gorselListeHatasi($gelen)) jsonError($_gh);

    $ins = $db->prepare("INSERT INTO kiralama_fotograflari (kiralama_id, aci_tipi, aciklama, dosya_yolu, yon, created_at) VALUES (?,?,?,?,?,?)");
    $eklenen = 0; $bozuk = 0; $basarisizIndeksler = []; $ids = [];
    foreach (array_values($gelen) as $indeks => $f) {
        if (!is_array($f) || empty($f['veri'])) { $bozuk++; $basarisizIndeksler[] = $indeks; continue; }
        $yol = _kfFotoKaydet((string)$f['veri'], $kid);
        if ($yol === null) { $bozuk++; $basarisizIndeksler[] = $indeks; continue; }
        $ins->execute([$kid, trim(metin($f['aci_tipi'] ?? '')) ?: null, trim(metin($f['aciklama'] ?? '')) ?: null, $yol, $yon, date('Y-m-d H:i:s')]);
        $ids[] = (int)$db->lastInsertId();
        $eklenen++;
    }
    if ($eklenen === 0) jsonError('Hiçbir fotoğraf kaydedilemedi. Dosyalar bozuk ya da desteklenmeyen biçimde olabilir.');

    $yeniDurum = !empty($k['makine_tur']) && !empty($k['makine_id'])
        ? makineDurumu($db, (string)$k['makine_tur'], (int)$k['makine_id']) : null;

    $etiket = $yon === 'donus' ? 'Dönüş fotoğrafı' : 'Çıkış fotoğrafı';
    logla($db, 'kiralamalar', $kid, 'guncelle', null, null,
          $etiket . ' eklendi · ' . $eklenen . ' adet' . ($bozuk ? ' (' . $bozuk . ' atlandı)' : '')
          . ($yeniDurum ? ' · makine durumu: ' . $yeniDurum : ''));
    jsonOk(['eklenen' => $eklenen, 'ids' => $ids, 'atlanan' => $bozuk, 'basarisiz_indeksler' => $basarisizIndeksler, 'makine_durum' => $yeniDurum],
           $eklenen . ' fotoğraf eklendi.' . ($bozuk ? ' ' . $bozuk . ' dosya okunamadı, atlandı.' : ''));
}

if ($method === 'DELETE') {
    verifyCsrf();
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) jsonError('Geçersiz ID.');
    $st = $db->prepare("SELECT * FROM kiralama_fotograflari WHERE id=?");
    $st->execute([$id]);
    $foto = $st->fetch();
    if (!$foto) jsonError('Fotoğraf bulunamadı.', 404);
    _kfKiralamaDogrula($db, (int)$foto['kiralama_id'], $_oturumPersonelId);

    $db->beginTransaction();
    try {
        islemKilitAl($db);
        if ($engel = hdFotoSilmeEngeli($db, $foto)) { $db->rollBack(); jsonError($engel, 409); }
        $db->prepare("DELETE FROM kiralama_fotograflari WHERE id=?")->execute([$id]);
        logla($db, 'kiralamalar', (int)$foto['kiralama_id'], 'guncelle', null, null,
              (($foto['yon'] ?? 'gidis') === 'donus' ? 'Dönüş' : 'Çıkış') . ' fotoğrafı silindi.');
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('kiralama_foto silme: ' . $e->getMessage());
        jsonError('Fotoğraf silinemedi.', 500);
    }
    $tam = UPLOAD_PATH . $foto['dosya_yolu'];
    if (is_file($tam)) @unlink($tam);
    jsonOk(null, 'Fotoğraf silindi.');
}

jsonError('Method Not Allowed', 405);
