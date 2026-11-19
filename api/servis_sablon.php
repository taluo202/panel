<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/servis-kaynak-lib.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('teknik_servis');
subeMerkezi('teknik_servis');

$db     = getDb();
$method = $_SERVER['REQUEST_METHOD'];

function _sksMakineTur($v): ?string {
    return in_array($v, ['arac', 'manlift'], true) ? $v : null;
}

function _sksKalemleriKaydet(PDO $db, int $sablonId, array $kalemler): void {
    $db->prepare("DELETE FROM servis_kontrol_sablon_kalemleri WHERE sablon_id=?")->execute([$sablonId]);
    $ins = $db->prepare("INSERT INTO servis_kontrol_sablon_kalemleri (sablon_id, sira, kategori, madde) VALUES (?,?,?,?)");
    $sira = 0;
    foreach ($kalemler as $k) {
        if (!is_array($k)) continue;
        $madde = trim(metin($k['madde'] ?? ''));
        if ($madde === '') continue;
        $sira++;
        $ins->execute([$sablonId, $sira, trim(metin($k['kategori'] ?? '')), $madde]);
    }
}

switch ($method) {

    case 'GET':
        if (isset($_GET['id'])) {
            $id = (int)$_GET['id'];
            $st = $db->prepare("SELECT * FROM servis_kontrol_sablonlari WHERE id=?");
            $st->execute([$id]);
            $s = $st->fetch();
            if (!$s) jsonError('Şablon bulunamadı.', 404);
            $kSt = $db->prepare("SELECT id, sira, kategori, madde FROM servis_kontrol_sablon_kalemleri WHERE sablon_id=? ORDER BY sira, id");
            $kSt->execute([$id]);
            $s['kalemler'] = $kSt->fetchAll();
            jsonOk($s);
        }

        $where = []; $params = [];
        $mt = _sksMakineTur($_GET['makine_tur'] ?? null);
        if ($mt) { $where[] = "(s.makine_tur=? OR s.makine_tur IS NULL)"; $params[] = $mt; }
        if (!isset($_GET['hepsi'])) { $where[] = "s.aktif=1"; }
        $sql = "SELECT s.*, (SELECT COUNT(*) FROM servis_kontrol_sablon_kalemleri k WHERE k.sablon_id=s.id) AS kalem_adet
                FROM servis_kontrol_sablonlari s";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY s.ad";
        $st = $db->prepare($sql); $st->execute($params);
        jsonOk($st->fetchAll());
        break;

    case 'POST':
        requirePermission('teknik_servis', 'yaz');
        verifyCsrf();
        $body = govdeOku();
        $ad = trim(metin($body['ad'] ?? ''));
        if ($ad === '') jsonError('Şablon adı zorunludur.');
        $kalemler = is_array($body['kalemler'] ?? null) ? $body['kalemler'] : [];
        if (!$kalemler) jsonError('En az bir kontrol maddesi ekleyin.');

        $db->prepare("INSERT INTO servis_kontrol_sablonlari (ad, makine_tur, servis_tipi, kategori_esle) VALUES (?,?,?,?)")
           ->execute([$ad, _sksMakineTur($body['makine_tur'] ?? null), skServisTipi($body['servis_tipi'] ?? null), skKategoriEsle($body['kategori_esle'] ?? '') ?: null]);
        $id = (int)$db->lastInsertId();
        _sksKalemleriKaydet($db, $id, $kalemler);
        logla($db, 'servis_kontrol_sablonlari', $id, 'ekle', null, ['ad' => $ad], "Kontrol listesi şablonu eklendi: $ad");
        jsonOk(['id' => $id], 'Şablon oluşturuldu.');
        break;

    case 'PUT':
        requirePermission('teknik_servis', 'yaz');
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $cur = $db->prepare("SELECT * FROM servis_kontrol_sablonlari WHERE id=?");
        $cur->execute([$id]);
        $mevcut = $cur->fetch();
        if (!$mevcut) jsonError('Şablon bulunamadı.', 404);

        $body = govdeOku();
        $ad = trim(metin($body['ad'] ?? ''));
        if ($ad === '') jsonError('Şablon adı zorunludur.');
        $aktif = array_key_exists('aktif', $body) ? (empty($body['aktif']) ? 0 : 1) : (int)$mevcut['aktif'];
        $tipi = array_key_exists('servis_tipi', $body) ? skServisTipi($body['servis_tipi']) : skServisTipi($mevcut['servis_tipi'] ?? null);
        $esle = array_key_exists('kategori_esle', $body) ? (skKategoriEsle($body['kategori_esle']) ?: null) : ($mevcut['kategori_esle'] ?? null);

        $db->prepare("UPDATE servis_kontrol_sablonlari SET ad=?, makine_tur=?, servis_tipi=?, kategori_esle=?, aktif=?, updated_at=datetime('now','localtime') WHERE id=?")
           ->execute([$ad, array_key_exists('makine_tur', $body) ? _sksMakineTur($body['makine_tur']) : _sksMakineTur($mevcut['makine_tur'] ?? null), $tipi, $esle, $aktif, $id]);
        if (is_array($body['kalemler'] ?? null)) _sksKalemleriKaydet($db, $id, $body['kalemler']);
        logla($db, 'servis_kontrol_sablonlari', $id, 'guncelle', ['ad' => $mevcut['ad'], 'servis_tipi' => $mevcut['servis_tipi'] ?? null, 'kategori_esle' => $mevcut['kategori_esle'] ?? null, 'aktif' => (int)$mevcut['aktif']],
              ['ad' => $ad, 'servis_tipi' => $tipi, 'kategori_esle' => $esle, 'aktif' => $aktif], "Kontrol listesi şablonu güncellendi: $ad");
        jsonOk(null, 'Şablon güncellendi.');
        break;

    case 'DELETE':
        requirePermission('teknik_servis', 'yaz');
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $cur = $db->prepare("SELECT ad FROM servis_kontrol_sablonlari WHERE id=?");
        $cur->execute([$id]);
        $ad = $cur->fetchColumn();
        if ($ad === false) jsonError('Şablon bulunamadı.', 404);
        $db->prepare("UPDATE servis_kontrol_sablonlari SET aktif=0, updated_at=datetime('now','localtime') WHERE id=?")->execute([$id]);
        logla($db, 'servis_kontrol_sablonlari', $id, 'guncelle', null, null, "Kontrol listesi şablonu pasife alındı: $ad");
        jsonOk(null, 'Şablon pasife alındı.');
        break;

    default:
        jsonError('Desteklenmeyen metod.', 405);
}
