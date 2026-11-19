<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('yedek_parca');

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDb();

switch ($method) {

    case 'GET':
        $where = []; $params = [];
        if (($pid = (int)($_GET['parca_id'] ?? 0)) > 0) {
            $adSt = $db->prepare("SELECT ad FROM yedek_parca WHERE id=?");
            $adSt->execute([$pid]);
            $ad = $adSt->fetchColumn();
            if ($ad !== false) { $where[] = "parca_ad=?"; $params[] = $ad; }
        }
        $sql = "SELECT * FROM stok_transfer";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY tarih DESC, id DESC LIMIT 200";
        $st = $db->prepare($sql); $st->execute($params);
        jsonOk($st->fetchAll());
        break;

    case 'POST':
        verifyCsrf();
        $body = govdeOku();
        $parcaId = (int)($body['parca_id'] ?? 0);
        $hedefSube = trim(metin($body['hedef_sube'] ?? ''));
        $miktar = (int)($body['miktar'] ?? 0);
        if ($parcaId < 1) jsonError('Parça seçilmelidir.');
        if ($hedefSube === '') jsonError('Hedef şube zorunludur.');
        if ($miktar < 1) jsonError('Miktar en az 1 olmalıdır.');

        $pSt = $db->prepare("SELECT * FROM yedek_parca WHERE id=?");
        $pSt->execute([$parcaId]);
        $parca = $pSt->fetch();
        if (!$parca) jsonError('Parça bulunamadı.', 404);

        $kaynakSube = trim((string)($parca['sube'] ?? ''));
        if ($kaynakSube === $hedefSube) jsonError('Kaynak ve hedef şube aynı olamaz.');
        if ($miktar > (int)$parca['stok']) jsonError('Yetersiz stok. Mevcut: ' . (int)$parca['stok']);

        $tarih = dateStr('tarih', $body) ?: date('Y-m-d');

        try {
            $transferId = islemIcinde($db, function (PDO $db) use ($parcaId, $miktar, $hedefSube, $tarih, $body): int {
            $pSt = $db->prepare("SELECT * FROM yedek_parca WHERE id=?");
            $pSt->execute([$parcaId]);
            $parca = $pSt->fetch();
            if (!$parca) throw new DomainException('Parça bulunamadı.', 404);
            $kaynakSube = trim((string)($parca['sube'] ?? ''));
            $dus = $db->prepare("UPDATE yedek_parca SET stok = stok - ?, updated_at=datetime('now','localtime') WHERE id=? AND stok >= ?");
            $dus->execute([$miktar, $parcaId, $miktar]);
            if ($dus->rowCount() !== 1) throw new DomainException('Yetersiz stok — bu arada başka bir işlem stoğu düşürmüş olabilir. Sayfayı yenileyin.', 409);
            $db->prepare("INSERT INTO stok_hareket (parca_id, tarih, tip, miktar, kaynak, aciklama) VALUES (?,?,?,?,?,?)")
               ->execute([$parcaId, $tarih, 'cikis', $miktar, 'manuel', 'Şube transferi → ' . $hedefSube]);

            $hSt = $db->prepare("SELECT id FROM yedek_parca WHERE sube=? AND ad=? AND COALESCE(parca_no,'')=COALESCE(?,'')");
            $hSt->execute([$hedefSube, $parca['ad'], $parca['parca_no']]);
            $hedefId = $hSt->fetchColumn();

            if ($hedefId) {
                $db->prepare("UPDATE yedek_parca SET stok = stok + ?, updated_at=datetime('now','localtime') WHERE id=?")->execute([$miktar, (int)$hedefId]);
            } else {
                $db->prepare("
                    INSERT INTO yedek_parca (ad, parca_no, aciklama, sube, stok, kritik_stok, fiyat, uyumlu_makine, seri_no, uretim_no)
                    VALUES (?,?,?,?,?,?,?,?,?,?)
                ")->execute([
                    $parca['ad'], $parca['parca_no'], $parca['aciklama'], $hedefSube, $miktar,
                    $parca['kritik_stok'], $parca['fiyat'], $parca['uyumlu_makine'], $parca['seri_no'], $parca['uretim_no'],
                ]);
                $hedefId = (int)$db->lastInsertId();
            }
            $db->prepare("INSERT INTO stok_hareket (parca_id, tarih, tip, miktar, kaynak, aciklama) VALUES (?,?,?,?,?,?)")
               ->execute([$hedefId, $tarih, 'giris', $miktar, 'manuel', 'Şube transferi ← ' . $kaynakSube]);

            $db->prepare("INSERT INTO stok_transfer (parca_ad, parca_no, tarih, kaynak_sube, hedef_sube, miktar, aciklama) VALUES (?,?,?,?,?,?,?)")
               ->execute([$parca['ad'], $parca['parca_no'], $tarih, $kaynakSube, $hedefSube, $miktar, trim(metin($body['aciklama'] ?? '')) ?: null]);
            $tid = (int)$db->lastInsertId();
            logla($db, 'stok_transfer', $tid, 'ekle', null, null,
                'Şube transferi: ' . $parca['ad'] . ' · ' . $miktar . ' adet · '
                . ($kaynakSube !== '' ? $kaynakSube : '(şubesiz)') . ' → ' . $hedefSube);
            return $tid;
            });
        } catch (DomainException $e) {
            jsonError($e->getMessage(), (int)$e->getCode() === 404 ? 404 : 409);
        } catch (\Throwable $e) {
            error_log('stok transferi yapilamadi: ' . $e->getMessage());
            jsonError('Transfer başarısız.', 500);
        }
        jsonOk(['id' => $transferId], 'Stok transfer edildi.');

    default:
        jsonError('Method Not Allowed', 405);
}
