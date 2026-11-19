<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('yonetim');

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDb();

$KATEGORILER = ['personel','yakit','bakim','sigorta','lastik','onarim','diger','genel'];
$GIDER_KATEGORILERI = ['yakit','bakim','sigorta','lastik','onarim','diger'];

function _bKategori($v, array $izinli): string { return in_array($v, $izinli, true) ? $v : 'genel'; }

function _butceGerceklesen(PDO $db, int $yil, int $ay, string $sube, string $kategori, array $giderKategorileri): float {
    $ayStr = sprintf('%04d-%02d', $yil, $ay);
    if ($kategori === 'personel') {
        if ($sube !== '') return 0.0;
        $st = $db->prepare("SELECT COALESCE(SUM(COALESCE(toplam_maas,0)+COALESCE(mesai,0)+COALESCE(prim,0)),0) FROM finans WHERE ay=?");
        $st->execute([$ayStr]);
        return (float)$st->fetchColumn();
    }
    if (in_array($kategori, $giderKategorileri, true)) {
        if ($sube === '') {
            $st = $db->prepare("SELECT COALESCE(SUM(tutar),0) FROM giderler WHERE kategori=? AND substr(tarih,1,7)=?");
            $st->execute([$kategori, $ayStr]);
            return (float)$st->fetchColumn();
        }
        $st = $db->prepare("
            SELECT COALESCE(SUM(g.tutar),0) FROM giderler g
            LEFT JOIN araclar a ON g.makine_tur='arac' AND a.id=g.makine_id
            LEFT JOIN manliftler m ON g.makine_tur='manlift' AND m.id=g.makine_id
            WHERE g.kategori=? AND substr(g.tarih,1,7)=? AND COALESCE(a.sube, m.sube)=?
        ");
        $st->execute([$kategori, $ayStr, $sube]);
        return (float)$st->fetchColumn();
    }
    return 0.0;
}

switch ($method) {

    case 'GET':
        if (($_GET['action'] ?? '') === 'karsilastirma') {
            $yil = (int)($_GET['yil'] ?? date('Y'));
            $ay  = (int)($_GET['ay'] ?? date('n'));
            $st = $db->prepare("SELECT * FROM butceler WHERE yil=? AND ay=? ORDER BY kategori, sube");
            $st->execute([$yil, $ay]);
            $rows = $st->fetchAll();
            foreach ($rows as &$r) {
                $r['gerceklesen'] = round(_butceGerceklesen($db, $yil, $ay, (string)($r['sube'] ?? ''), (string)$r['kategori'], $GIDER_KATEGORILERI), 2);
                $r['fark'] = round((float)$r['hedef_tutar'] - $r['gerceklesen'], 2);
                $r['kullanim_yuzde'] = (float)$r['hedef_tutar'] > 0 ? round($r['gerceklesen'] / (float)$r['hedef_tutar'] * 100, 1) : null;
            }
            unset($r);
            jsonOk($rows);
        }

        $where = []; $params = [];
        if (($yil = (int)($_GET['yil'] ?? 0)) > 0) { $where[] = "yil=?"; $params[] = $yil; }
        if (($ay = (int)($_GET['ay'] ?? 0)) > 0) { $where[] = "ay=?"; $params[] = $ay; }
        $sql = "SELECT * FROM butceler";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY yil DESC, ay DESC, kategori, sube";
        $st = $db->prepare($sql); $st->execute($params);
        jsonOk($st->fetchAll());
        break;

    case 'POST':
        verifyCsrf();
        $body = govdeOku();
        $yil = (int)($body['yil'] ?? 0);
        $ay  = (int)($body['ay'] ?? 0);
        if ($yil < 2020 || $yil > 2100) jsonError('Geçerli bir yıl girin.');
        if ($ay < 1 || $ay > 12) jsonError('Geçerli bir ay girin (1-12).');
        $kategori = _bKategori($body['kategori'] ?? '', $KATEGORILER);
        $sube = trim(metin($body['sube'] ?? ''));
        $hedef = max(0, (float)($body['hedef_tutar'] ?? 0));

        try {
            $db->prepare("
                INSERT INTO butceler (yil, ay, sube, kategori, hedef_tutar, notlar)
                VALUES (?,?,?,?,?,?)
                ON CONFLICT(yil, ay, sube, kategori) DO UPDATE SET hedef_tutar=excluded.hedef_tutar, notlar=excluded.notlar, updated_at=datetime('now','localtime')
            ")->execute([$yil, $ay, $sube ?: null, $kategori, $hedef, trim(metin($body['notlar'] ?? '')) ?: null]);
        } catch (\PDOException $e) {
            jsonError('Bütçe kaydedilemedi.', 500);
        }
        logla($db, 'butceler', null, 'ekle', null, null,
            'Bütçe hedefi: ' . $yil . '/' . str_pad((string)$ay, 2, '0', STR_PAD_LEFT)
            . ' · ' . $kategori . ($sube !== '' ? ' · ' . $sube : '')
            . ' · ' . number_format($hedef, 2, ',', '.') . ' ₺');
        jsonOk(null, 'Bütçe kaydedildi.');

    case 'PUT':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $body = govdeOku();
        $hedef = max(0, (float)($body['hedef_tutar'] ?? 0));
        $_bEski = logKayitAl($db, 'butceler', $id);
        $db->prepare("UPDATE butceler SET hedef_tutar=?, notlar=?, updated_at=datetime('now','localtime') WHERE id=?")
           ->execute([$hedef, trim(metin($body['notlar'] ?? '')) ?: null, $id]);
        logla($db, 'butceler', $id, 'guncelle', $_bEski, logKayitAl($db, 'butceler', $id),
            'Bütçe hedefi güncellendi: ' . number_format($hedef, 2, ',', '.') . ' ₺');
        jsonOk(null, 'Bütçe güncellendi.');

    case 'DELETE':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $_bEski = logKayitAl($db, 'butceler', $id);
        $db->prepare("DELETE FROM butceler WHERE id=?")->execute([$id]);
        logla($db, 'butceler', $id, 'sil', $_bEski, null, 'Bütçe hedefi silindi');
        jsonOk(null, 'Bütçe silindi.');

    default:
        jsonError('Method Not Allowed', 405);
}
