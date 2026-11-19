<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/saha-servis-lib.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$seviye = $method === 'GET' ? 'oku' : 'yaz';
if (!hasPermission('kiralama_takip', $seviye) && !hasPermission('teknik_servis', $seviye)) {
    jsonError('Bu işlem için yetkiniz yok.', 403);
}
$db = getDb();

function _mmGovde(PDO $db, array $b): array {
    $mid = (int)($b['musteri_id'] ?? 0);
    if ($mid < 1) jsonError('Müşteri seçin.');
    $q = $db->prepare('SELECT COUNT(*) FROM musteriler WHERE id=?'); $q->execute([$mid]);
    if (!(int)$q->fetchColumn()) jsonError('Müşteri bulunamadı.', 404);
    subeKayitDogrula($db, 'musteriler', $mid);
    $marka = trim(metin($b['marka'] ?? '')); $model = trim(metin($b['model'] ?? ''));
    if ($marka === '' && $model === '') jsonError('Marka ya da model yazın.');
    $yil = (int)($b['yil'] ?? 0);
    $saat = ($b['calisma_saati'] ?? '') === '' ? null : max(0, (float)$b['calisma_saati']);
    return [$mid, trim(metin($b['kategori'] ?? '')) ?: null, $marka ?: null, $model ?: null,
            trim(metin($b['seri_no'] ?? '')) ?: null, ($yil >= 1950 && $yil <= 2100) ? $yil : null, $saat,
            trim(metin($b['notlar'] ?? '')) ?: null];
}

switch ($method) {
    case 'GET':
        $mid = (int)($_GET['musteri_id'] ?? 0);
        if ($mid < 1) jsonError('Müşteri seçin.');
        $q = $db->prepare("SELECT mm.*, (SELECT COUNT(*) FROM teknik_servis t WHERE t.musteri_makine_id = mm.id) AS servis_adet
                           FROM musteri_makineleri mm WHERE mm.musteri_id=? ORDER BY mm.aktif DESC, mm.marka, mm.model, mm.id");
        $q->execute([$mid]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['servis_adet'] = (int)$r['servis_adet']; $r['aktif'] = (int)$r['aktif']; $r['ad'] = ssMusteriMakineAd($r); }
        unset($r);
        jsonOk($rows);

    case 'POST':
        verifyCsrf();
        $g = _mmGovde($db, govdeOku());
        $db->prepare('INSERT INTO musteri_makineleri (musteri_id, kategori, marka, model, seri_no, yil, calisma_saati, notlar) VALUES (?,?,?,?,?,?,?,?)')
           ->execute($g);
        $id = (int)$db->lastInsertId();
        logla($db, 'musteri_makineleri', $id, 'ekle', null, logKayitAl($db, 'musteri_makineleri', $id), 'Müşteri makinesi eklendi');
        jsonOk(['id' => $id], 'Makine eklendi.');

    case 'PUT':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        $eski = $id > 0 ? logKayitAl($db, 'musteri_makineleri', $id) : null;
        if (!$eski) jsonError('Makine bulunamadı.', 404);
        subeKayitDogrula($db, 'musteriler', (int)$eski['musteri_id']);
        $g = _mmGovde($db, govdeOku());
        $db->prepare("UPDATE musteri_makineleri SET musteri_id=?, kategori=?, marka=?, model=?, seri_no=?, yil=?, calisma_saati=?, notlar=?,
                      updated_at=datetime('now','localtime') WHERE id=?")->execute([...$g, $id]);
        logla($db, 'musteri_makineleri', $id, 'guncelle', $eski, logKayitAl($db, 'musteri_makineleri', $id), 'Müşteri makinesi güncellendi');
        jsonOk(null, 'Makine güncellendi.');

    case 'DELETE':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        $eski = $id > 0 ? logKayitAl($db, 'musteri_makineleri', $id) : null;
        if (!$eski) jsonError('Makine bulunamadı.', 404);
        subeKayitDogrula($db, 'musteriler', (int)$eski['musteri_id']);
        $q = $db->prepare('SELECT COUNT(*) FROM teknik_servis WHERE musteri_makine_id=?'); $q->execute([$id]);
        if ((int)$q->fetchColumn() > 0) {
            $db->prepare("UPDATE musteri_makineleri SET aktif=0, updated_at=datetime('now','localtime') WHERE id=?")->execute([$id]);
            logla($db, 'musteri_makineleri', $id, 'guncelle', $eski, logKayitAl($db, 'musteri_makineleri', $id), 'Müşteri makinesi pasife alındı (servis geçmişi var)');
            jsonOk(['pasif' => true], 'Makinenin servis geçmişi olduğu için silinmedi, pasife alındı.');
        }
        $db->prepare('DELETE FROM musteri_makineleri WHERE id=?')->execute([$id]);
        logla($db, 'musteri_makineleri', $id, 'sil', $eski, null, 'Müşteri makinesi SİLİNDİ');
        jsonOk(['pasif' => false], 'Makine silindi.');

    default:
        jsonError('Method Not Allowed', 405);
}
