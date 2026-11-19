<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDb();

const SUBE_TASIYAN_TABLOLAR = ['kiralamalar', 'musteriler', 'manliftler', 'yedek_parca', 'araclar', 'teklifler', 'hakedisler', 'tahsilatlar', 'cek_senet', 'users'];

function _subeAdminGuard(): void {
    if (($_SESSION['rol'] ?? '') !== 'admin') jsonError('Bu işlem için admin yetkisi gerekir.', 403);
}

function _subeKullanim(PDO $db, string $ad): int {
    $n = 0;
    foreach (SUBE_TASIYAN_TABLOLAR as $t) {
        $st = $db->prepare("SELECT COUNT(*) FROM $t WHERE sube = ?");
        $st->execute([$ad]);
        $n += (int)$st->fetchColumn();
    }
    return $n;
}

switch ($method) {

    case 'GET':
        $rows = $db->query("SELECT id, ad, aktif, il, adres, telefon, eposta, COALESCE(belgede_goster,1) AS belgede_goster, COALESCE(sira,0) AS sira FROM subeler ORDER BY aktif DESC, COALESCE(sira,0), ad COLLATE NOCASE")->fetchAll();
        $kullanimMap = [];
        foreach (SUBE_TASIYAN_TABLOLAR as $t) {
            try {
                $gSt = $db->query("SELECT sube, COUNT(*) n FROM $t WHERE sube IS NOT NULL AND sube<>'' GROUP BY sube");
                foreach ($gSt->fetchAll() as $g) $kullanimMap[$g['sube']] = ($kullanimMap[$g['sube']] ?? 0) + (int)$g['n'];
            } catch (\Exception $e) { hataGunlugu('sube.kullanim.' . $t, $e, true); }
        }
        foreach ($rows as &$r) { $r['kullanim'] = $kullanimMap[$r['ad']] ?? 0; }
        unset($r);
        jsonOk($rows);
        break;

    case 'POST':
        verifyCsrf();
        _subeAdminGuard();
        $body = govdeOku();
        $ad   = trim(metin($body['ad'] ?? ''));
        if ($ad === '') jsonError('Şube adı zorunludur.');
        try {
            $db->prepare("INSERT INTO subeler (ad) VALUES (?)")->execute([$ad]);
        } catch (\PDOException $e) {
            if (strpos($e->getMessage(), 'UNIQUE') !== false) jsonError('Bu şube zaten kayıtlı.');
            jsonError('Veritabanı hatası.', 500);
        }
        $subeId = (int)$db->lastInsertId();
        logla($db, 'subeler', $subeId, 'ekle', null, null, 'Şube eklendi: ' . $ad);
        jsonOk(['id' => $subeId], 'Şube eklendi.');

    case 'PUT':
        verifyCsrf();
        _subeAdminGuard();
        $id   = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $body = govdeOku();

        $cur = $db->prepare("SELECT ad, aktif FROM subeler WHERE id=?");
        $cur->execute([$id]);
        $mevcut = $cur->fetch();
        if (!$mevcut) jsonError('Şube bulunamadı.', 404);
        $eski = (string)$mevcut['ad'];

        $ad = trim(isset($body['ad']) ? metin($body['ad']) : $eski);
        $aktif = isset($body['aktif']) ? (int)(bool)$body['aktif'] : (int)($mevcut['aktif'] ?? 1);
        if ($ad === '') jsonError('Şube adı zorunludur.');

        $ilet = [];
        foreach (['il' => 60, 'adres' => 300, 'telefon' => 40, 'eposta' => 120] as $alan => $uz) {
            if (!array_key_exists($alan, $body)) continue;
            $v = mb_substr(trim(metin($body[$alan])), 0, $uz);
            if ($alan === 'il') $v = ilYazim($v);
            if ($alan === 'eposta' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) jsonError('Şube e-posta adresi geçersiz.');
            $ilet[$alan] = $v !== '' ? $v : null;
        }
        if (array_key_exists('belgede_goster', $body)) $ilet['belgede_goster'] = (int)(bool)$body['belgede_goster'];
        if (array_key_exists('sira', $body)) $ilet['sira'] = max(0, min(999, (int)$body['sira']));
        $db->beginTransaction();
        try {
            if ($ilet) {
                $db->prepare('UPDATE subeler SET ' . implode(', ', array_map(fn($k) => "$k=?", array_keys($ilet))) . ' WHERE id=?')
                   ->execute([...array_values($ilet), $id]);
            }
            $db->prepare("UPDATE subeler SET ad=?, aktif=? WHERE id=?")->execute([$ad, $aktif, $id]);
            if ($ad !== $eski) {
                foreach (SUBE_TASIYAN_TABLOLAR as $t) {
                    $db->prepare("UPDATE $t SET sube=? WHERE sube=?")->execute([$ad, $eski]);
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            if (strpos($e->getMessage(), 'UNIQUE') !== false) jsonError('Bu şube adı zaten var.');
            error_log('Taloo sube_guncelle: ' . $e->getMessage());
            jsonError('Şube güncellenemedi, hiçbir kayıt değiştirilmedi. Biraz sonra tekrar deneyin.', 500);
        }
        logla($db, 'subeler', $id,
            'guncelle',
            ['ad' => $eski, 'aktif' => (int)($mevcut['aktif'] ?? 1)],
            ['ad' => $ad, 'aktif' => $aktif],
            $ad !== $eski
                ? 'Şube yeniden adlandırıldı: ' . $eski . ' → ' . $ad . ' (ilişkili kayıtlardaki şube adı da güncellendi)'
                : 'Şube güncellendi: ' . $ad . ($aktif ? '' : ' (pasife alındı)') . ($ilet ? ' (belge bilgileri: ' . implode(', ', array_keys($ilet)) . ')' : ''));
        jsonOk(null, 'Şube güncellendi.');

    case 'DELETE':
        verifyCsrf();
        _subeAdminGuard();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $cur = $db->prepare("SELECT ad FROM subeler WHERE id=?");
        $cur->execute([$id]);
        $ad = $cur->fetchColumn();
        if ($ad === false) jsonError('Şube bulunamadı.', 404);
        if (_subeKullanim($db, (string)$ad) > 0) {
            jsonError('Bu şube kayıtlarda kullanılıyor. Silmek yerine pasife alın.');
        }
        $db->prepare("DELETE FROM subeler WHERE id=?")->execute([$id]);
        logla($db, 'subeler', $id, 'sil', ['ad' => $ad], null, 'Şube silindi: ' . $ad);
        jsonOk(null, 'Şube silindi.');

    default:
        jsonError('Method Not Allowed', 405);
}
