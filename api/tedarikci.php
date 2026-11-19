<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('satinalma');

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDb();

switch ($method) {

    case 'GET':
        if (isset($_GET['id'])) {
            $id = (int)$_GET['id'];
            $st = $db->prepare("SELECT * FROM tedarikciler WHERE id=?");
            $st->execute([$id]);
            $t = $st->fetch();
            if (!$t) jsonError('Tedarikçi bulunamadı.', 404);

            $sSt = $db->prepare("SELECT id, kod, tarih, durum FROM satinalma_siparisleri WHERE tedarikci_id=? ORDER BY tarih DESC, id DESC");
            $sSt->execute([$id]);
            $t['siparisler'] = $sSt->fetchAll();
            jsonOk($t);
        }

        $where = []; $params = [];
        if (($q = trim(metin($_GET['q'] ?? ''))) !== '') {
            $where[] = "(" . trAramaSql('unvan') . " LIKE ? OR " . trAramaSql('yetkili') . " LIKE ? OR " . trAramaSql('telefon') . " LIKE ? OR " . trAramaSql('vergi_no') . " LIKE ?)";
            $like = '%' . trArama($q) . '%'; array_push($params, $like, $like, $like, $like);
        }
        if (($_GET['aktif'] ?? '') === '1') $where[] = "aktif=1";
        $sql = "SELECT * FROM tedarikciler";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY unvan COLLATE NOCASE";
        $st = $db->prepare($sql); $st->execute($params);
        jsonOk($st->fetchAll());
        break;

    case 'POST':
        verifyCsrf();
        $body = govdeOku();
        $unvan = trim(metin($body['unvan'] ?? ''));
        if ($unvan === '') jsonError('Ünvan zorunludur.');
        $db->prepare("
            INSERT INTO tedarikciler (unvan, yetkili, telefon, email, vergi_dairesi, vergi_no, adres, notlar, aktif)
            VALUES (?,?,?,?,?,?,?,?,?)
        ")->execute([
            $unvan,
            trim(metin($body['yetkili'] ?? '')) ?: null,
            trim(metin($body['telefon'] ?? '')) ?: null,
            trim(metin($body['email'] ?? '')) ?: null,
            trim(metin($body['vergi_dairesi'] ?? '')) ?: null,
            trim(metin($body['vergi_no'] ?? '')) ?: null,
            trim(metin($body['adres'] ?? '')) ?: null,
            trim(metin($body['notlar'] ?? '')) ?: null,
            isset($body['aktif']) ? (int)(bool)$body['aktif'] : 1,
        ]);
        $tedarikciId = (int)$db->lastInsertId();
        logla($db, 'tedarikciler', $tedarikciId, 'ekle', null, logKayitAl($db, 'tedarikciler', $tedarikciId),
            'Tedarikçi eklendi: ' . $unvan);
        jsonOk(['id' => $tedarikciId], 'Tedarikçi eklendi.');

    case 'PUT':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $body = govdeOku();
        $unvan = trim(metin($body['unvan'] ?? ''));
        if ($unvan === '') jsonError('Ünvan zorunludur.');
        $_tEski = logKayitAl($db, 'tedarikciler', $id);
        $db->prepare("
            UPDATE tedarikciler SET unvan=?, yetkili=?, telefon=?, email=?, vergi_dairesi=?, vergi_no=?, adres=?, notlar=?, aktif=?,
                updated_at=datetime('now','localtime')
            WHERE id=?
        ")->execute([
            $unvan,
            trim(metin($body['yetkili'] ?? '')) ?: null,
            trim(metin($body['telefon'] ?? '')) ?: null,
            trim(metin($body['email'] ?? '')) ?: null,
            trim(metin($body['vergi_dairesi'] ?? '')) ?: null,
            trim(metin($body['vergi_no'] ?? '')) ?: null,
            trim(metin($body['adres'] ?? '')) ?: null,
            trim(metin($body['notlar'] ?? '')) ?: null,
            isset($body['aktif']) ? (int)(bool)$body['aktif'] : 1,
            $id,
        ]);
        logla($db, 'tedarikciler', $id, 'guncelle', $_tEski, logKayitAl($db, 'tedarikciler', $id),
            'Tedarikçi güncellendi: ' . $unvan);
        jsonOk(null, 'Tedarikçi güncellendi.');

    case 'DELETE':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $chk = $db->prepare("SELECT COUNT(*) FROM satinalma_siparisleri WHERE tedarikci_id=?");
        $chk->execute([$id]);
        if ((int)$chk->fetchColumn() > 0) jsonError('Bu tedarikçiye ait satınalma siparişleri var. Silmek yerine pasife alın.');
        $_tEski = logKayitAl($db, 'tedarikciler', $id);
        $db->prepare("DELETE FROM tedarikciler WHERE id=?")->execute([$id]);
        logla($db, 'tedarikciler', $id, 'sil', $_tEski, null,
            'Tedarikçi silindi: ' . ($_tEski['unvan'] ?? ('#' . $id)));
        jsonOk(null, 'Tedarikçi silindi.');

    default:
        jsonError('Method Not Allowed', 405);
}
