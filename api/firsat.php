<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('kiralama_takip');

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDb();

function _firsatDurum($v): string {
    return in_array($v, ['yeni','iletisimde','teklif_bekliyor','kazanildi','kaybedildi'], true) ? $v : 'yeni';
}

function _firsatKaynak($v): ?string {
    $k = trim(metin($v ?? ''));
    if ($k === '') return null;
    if (!in_array($k, ['referans','web','sosyal_medya','soguk_arama','diger'], true)) jsonError('Geçersiz kaynak.');
    return $k;
}

switch ($method) {

    case 'GET':
        if (isset($_GET['id'])) {
            $stmt = $db->prepare("SELECT * FROM firsatlar WHERE id=?");
            $stmt->execute([(int)$_GET['id']]);
            $f = $stmt->fetch();
            if (!$f) jsonError('Fırsat bulunamadı.', 404);
            subeErisimDogrula($f['sube'] ?? null);
            jsonOk($f);
        }

        $where = []; $params = [];
        if (($d = trim(metin($_GET['durum'] ?? ''))) !== '' && $d !== 'hepsi') { $where[] = "durum=?"; $params[] = $d; }
        if (($q = trim(metin($_GET['q'] ?? ''))) !== '') {
            $where[] = "(" . trAramaSql('unvan') . " LIKE ? OR " . trAramaSql('kisi') . " LIKE ? OR " . trAramaSql('telefon') . " LIKE ?)";
            $like = '%' . trArama($q) . '%'; array_push($params, $like, $like, $like);
        }
        subeKosulEkle($where, $params, 'sube');
        $sql = "SELECT * FROM firsatlar";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY (durum IN ('kazanildi','kaybedildi')) ASC, created_at DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $ozet = ['yeni'=>0,'iletisimde'=>0,'teklif_bekliyor'=>0,'kazanildi'=>0,'kaybedildi'=>0,'acik_tutar'=>0.0];
        foreach ($rows as $r) {
            $ozet[$r['durum']] = ($ozet[$r['durum']] ?? 0) + 1;
            if (!in_array($r['durum'], ['kazanildi','kaybedildi'], true)) $ozet['acik_tutar'] += (float)$r['tahmini_tutar'];
        }
        $ozet['acik_tutar'] = round($ozet['acik_tutar'], 2);
        jsonOk(['list' => $rows, 'ozet' => $ozet]);
        break;

    case 'POST':
        verifyCsrf();
        $body  = govdeOku();
        $unvan = trim(metin($body['unvan'] ?? ''));
        if ($unvan === '') jsonError('Ünvan / Ad zorunludur.');

        $stmt = $db->prepare("
            INSERT INTO firsatlar (unvan, kisi, telefon, email, kaynak, durum, temsilci, sube, tahmini_tutar, notlar)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $unvan,
            trim(metin($body['kisi'] ?? '')) ?: null,
            trim(metin($body['telefon'] ?? '')) ?: null,
            trim(metin($body['email'] ?? '')) ?: null,
            _firsatKaynak($body['kaynak'] ?? ''),
            _firsatDurum($body['durum'] ?? 'yeni'),
            trim(metin($body['temsilci'] ?? '')) ?: null,
            kayitSubesi(metin($body['sube'] ?? '')),
            max(0, (float)($body['tahmini_tutar'] ?? 0)),
            trim(metin($body['notlar'] ?? '')) ?: null,
        ]);
        $_fid = (int)$db->lastInsertId();
        logla($db, 'firsatlar', $_fid, 'ekle', null, logKayitAl($db, 'firsatlar', $_fid), 'Fırsat eklendi: ' . $unvan);
        jsonOk(['id' => $_fid], 'Fırsat eklendi.');

    case 'PUT':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $body  = govdeOku();

        $_logEski = logKayitAl($db, 'firsatlar', $id);
        if (!$_logEski) jsonError('Fırsat bulunamadı.', 404);
        subeErisimDogrula($_logEski['sube'] ?? null);
        if (count($body) <= 1 && isset($body['durum'])) {
            $db->prepare("UPDATE firsatlar SET durum=?, updated_at=datetime('now','localtime') WHERE id=?")
               ->execute([_firsatDurum($body['durum']), $id]);
            logla($db, 'firsatlar', $id, 'guncelle', $_logEski, logKayitAl($db, 'firsatlar', $id), 'Fırsat durumu değişti');
            jsonOk(null, 'Durum güncellendi.');
        }

        $unvan = trim(metin($body['unvan'] ?? ''));
        if ($unvan === '') jsonError('Ünvan / Ad zorunludur.');
        $stmt = $db->prepare("
            UPDATE firsatlar SET unvan=?, kisi=?, telefon=?, email=?, kaynak=?, durum=?, temsilci=?, sube=?, tahmini_tutar=?, notlar=?,
                updated_at=datetime('now','localtime')
            WHERE id=?
        ");
        $stmt->execute([
            $unvan,
            trim(metin($body['kisi'] ?? '')) ?: null,
            trim(metin($body['telefon'] ?? '')) ?: null,
            trim(metin($body['email'] ?? '')) ?: null,
            _firsatKaynak($body['kaynak'] ?? ''),
            _firsatDurum($body['durum'] ?? 'yeni'),
            trim(metin($body['temsilci'] ?? '')) ?: null,
            kayitSubesi(metin($body['sube'] ?? '')),
            max(0, (float)($body['tahmini_tutar'] ?? 0)),
            trim(metin($body['notlar'] ?? '')) ?: null,
            $id,
        ]);
        logla($db, 'firsatlar', $id, 'guncelle', $_logEski, logKayitAl($db, 'firsatlar', $id), 'Fırsat güncellendi');
        jsonOk(null, 'Fırsat güncellendi.');

    case 'PATCH':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $action = trim(metin($_GET['action'] ?? ''));
        if ($action !== 'donustur') jsonError('Geçersiz işlem.');

        $stmt = $db->prepare("SELECT * FROM firsatlar WHERE id=?");
        $stmt->execute([$id]);
        $f = $stmt->fetch();
        if ($f) subeErisimDogrula($f['sube'] ?? null);
        if (!$f) jsonError('Fırsat bulunamadı.', 404);
        if ($f['musteri_id']) jsonError('Bu fırsat zaten bir müşteriye dönüştürülmüş.');

        $ins = $db->prepare("
            INSERT INTO musteriler (tur, unvan, yetkili, telefon, email, sube, temsilci, durum)
            VALUES ('kurumsal', ?, ?, ?, ?, ?, ?, 'aktif')
        ");
        $ins->execute([$f['unvan'], $f['kisi'], $f['telefon'], $f['email'], $f['sube'], $f['temsilci']]);
        $musteriId = (int)$db->lastInsertId();

        $db->prepare("UPDATE firsatlar SET durum='kazanildi', musteri_id=?, updated_at=datetime('now','localtime') WHERE id=?")
           ->execute([$musteriId, $id]);

        logla($db, 'musteriler', $musteriId, 'ekle', null, logKayitAl($db, 'musteriler', $musteriId), 'Fırsattan müşteri oluşturuldu (fırsat #' . $id . ')');
        logla($db, 'firsatlar', $id, 'guncelle', $f, logKayitAl($db, 'firsatlar', $id), 'Fırsat müşteriye dönüştürüldü');
        jsonOk(['musteri_id' => $musteriId], 'Müşteriye dönüştürüldü.');

    case 'DELETE':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $_logEski = logKayitAl($db, 'firsatlar', $id);
        if (!$_logEski) jsonError('Fırsat bulunamadı.', 404);
        subeErisimDogrula($_logEski['sube'] ?? null);
        $db->prepare("DELETE FROM firsatlar WHERE id=?")->execute([$id]);
        if ($_logEski) logla($db, 'firsatlar', $id, 'sil', $_logEski, null, 'Fırsat silindi: ' . $_logEski['unvan']);
        jsonOk(null, 'Fırsat silindi.');

    default:
        jsonError('Method Not Allowed', 405);
}
