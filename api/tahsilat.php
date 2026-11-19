<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('kiralama_takip');

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDb();

function _tahsilatYontem($v): string {
    return in_array($v, ['nakit','havale','cek','kredi_karti'], true) ? $v : 'havale';
}

function _tahsilatKiralamaDogrula(PDO $db, ?int $kiralamaId, int $musteriId): void {
    if (!$kiralamaId) return;
    $st = $db->prepare("SELECT musteri_id, sube FROM kiralamalar WHERE id=?");
    $st->execute([$kiralamaId]);
    $k = $st->fetch();
    if (!$k) jsonError('Kiralama bulunamadı.', 404);
    if ((int)$k['musteri_id'] !== $musteriId) jsonError('Bu kiralama başka bir müşteriye ait; tahsilat ona bağlanamaz.');
    subeErisimDogrula($k['sube'] ?? null);
}

function _hakedisDurumYenile(PDO $db, ?int $hakedisId): void {
    if (!$hakedisId) return;
    $st = $db->prepare("SELECT genel_toplam, durum FROM hakedisler WHERE id=?");
    $st->execute([$hakedisId]);
    $h = $st->fetch();
    if (!$h) return;
    $odenen = hakedisOdenen($db, $hakedisId);
    if ($odenen + 0.01 >= (float)$h['genel_toplam']) {
        if ($h['durum'] !== 'tahsil_edildi')
            $db->prepare("UPDATE hakedisler SET durum='tahsil_edildi' WHERE id=?")->execute([$hakedisId]);
    } else {
        if ($h['durum'] === 'tahsil_edildi')
            $db->prepare("UPDATE hakedisler SET durum='faturalandi' WHERE id=?")->execute([$hakedisId]);
    }
}

switch ($method) {

    case 'GET':
        $where = []; $params = [];
        if (($mid = (int)($_GET['musteri_id'] ?? 0)) > 0) { $where[] = "t.musteri_id=?"; $params[] = $mid; }
        if (($kid = (int)($_GET['kiralama_id'] ?? 0)) > 0) { $where[] = "t.kiralama_id=?"; $params[] = $kid; }
        subeKosulEkle($where, $params, 't.sube');
        $sql = "SELECT t.*, m.unvan AS musteri_unvan, k.kod AS kiralama_kod
                FROM tahsilatlar t
                JOIN musteriler m ON m.id = t.musteri_id
                LEFT JOIN kiralamalar k ON k.id = t.kiralama_id";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY t.tarih DESC, t.id DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        jsonOk($stmt->fetchAll());
        break;

    case 'POST':
        verifyCsrf();
        $body      = govdeOku();
        $musteriId = (int)($body['musteri_id'] ?? 0);
        finansMusteriDogrula($db, $musteriId, (int)($body['hakedis_id'] ?? 0) ?: null);
        $tarih     = dateStr('tarih', $body) ?: date('Y-m-d');
        $tutar     = (float)($body['tutar'] ?? 0);

        if ($musteriId < 1) jsonError('Müşteri zorunludur.');
        if ($tutar <= 0)    jsonError('Tahsilat tutarı 0\'dan büyük olmalıdır.');

        $mChk = $db->prepare("SELECT COUNT(*) FROM musteriler WHERE id=?");
        $mChk->execute([$musteriId]);
        if ((int)$mChk->fetchColumn() === 0) jsonError('Müşteri bulunamadı.');

        $kiralamaId = ((int)($body['kiralama_id'] ?? 0)) ?: null;
        _tahsilatKiralamaDogrula($db, $kiralamaId, $musteriId);
        $hakedisId  = ((int)($body['hakedis_id'] ?? 0)) ?: null;
        $mt = musteriSubeTemsilci($db, $musteriId);

        $_yeniId = islemIcinde($db, function (PDO $db) use ($musteriId, $kiralamaId, $hakedisId, $tarih, $tutar, $body, $mt): int {
            $stmt = $db->prepare("
                INSERT INTO tahsilatlar (musteri_id, kiralama_id, hakedis_id, tarih, tutar, yontem, aciklama, sube, temsilci)
                VALUES (?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([
                $musteriId, $kiralamaId, $hakedisId, $tarih, round($tutar, 2),
                _tahsilatYontem($body['yontem'] ?? 'havale'),
                trim(metin($body['aciklama'] ?? '')) ?: null,
                $mt['sube'], $mt['temsilci'],
            ]);
            $yeniId = (int)$db->lastInsertId();
            _hakedisDurumYenile($db, $hakedisId);
            logla($db, 'tahsilatlar', $yeniId, 'ekle', null, logKayitAl($db, 'tahsilatlar', $yeniId),
                  'Tahsilat eklendi · ' . number_format(round($tutar, 2), 2, ',', '.') . ' ₺');
            return $yeniId;
        });
        jsonOk(['id' => $_yeniId], 'Tahsilat eklendi.');

    case 'PUT':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $body  = govdeOku();
        $tutar = (float)($body['tutar'] ?? 0);
        if ($tutar <= 0) jsonError('Tahsilat tutarı 0\'dan büyük olmalıdır.');
        $tarih = dateStr('tarih', $body) ?: date('Y-m-d');
        $eski = $db->prepare("SELECT musteri_id, hakedis_id, kiralama_id FROM tahsilatlar WHERE id=?");
        $eski->execute([$id]);
        $eskiKayit = $eski->fetch();
        if (!$eskiKayit) jsonError('Tahsilat bulunamadı.', 404);
        subeKayitDogrula($db, 'tahsilatlar', $id);
        $kayitMusteri = (int)$eskiKayit['musteri_id'];
        finansMusteriDogrula($db, $kayitMusteri, (int)($body['hakedis_id'] ?? 0) ?: null);
        $yeniKiralama = ((int)($body['kiralama_id'] ?? 0)) ?: null;
        if ($yeniKiralama !== (((int)($eskiKayit['kiralama_id'] ?? 0)) ?: null)) _tahsilatKiralamaDogrula($db, $yeniKiralama, $kayitMusteri);

        $_logEski = logKayitAl($db, 'tahsilatlar', $id);

        $eskiHakedis = (int)($eskiKayit['hakedis_id'] ?: 0) ?: null;
        $hakedisId   = ((int)($body['hakedis_id'] ?? 0)) ?: null;

        islemIcinde($db, function (PDO $db) use ($id, $tarih, $tutar, $body, $yeniKiralama, $hakedisId, $eskiHakedis, $_logEski): void {
            $stmt = $db->prepare("
                UPDATE tahsilatlar SET tarih=?, tutar=?, yontem=?, aciklama=?, kiralama_id=?, hakedis_id=?
                WHERE id=?
            ");
            $stmt->execute([
                $tarih, round($tutar, 2),
                _tahsilatYontem($body['yontem'] ?? 'havale'),
                trim(metin($body['aciklama'] ?? '')) ?: null,
                $yeniKiralama,
                $hakedisId,
                $id,
            ]);
            _hakedisDurumYenile($db, $eskiHakedis);
            if ($hakedisId !== $eskiHakedis) _hakedisDurumYenile($db, $hakedisId);
            logla($db, 'tahsilatlar', $id, 'guncelle', $_logEski, logKayitAl($db, 'tahsilatlar', $id),
                  'Tahsilat güncellendi · ' . number_format(round($tutar, 2), 2, ',', '.') . ' ₺');
        });
        jsonOk(null, 'Tahsilat güncellendi.');

    case 'DELETE':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $h = $db->prepare("SELECT hakedis_id FROM tahsilatlar WHERE id=?");
        $h->execute([$id]);
        $hRow = $h->fetch();
        if (!$hRow) jsonError('Tahsilat bulunamadı.', 404);
        subeKayitDogrula($db, 'tahsilatlar', $id);
        islemYanit($db, function (PDO $db) use ($id): void {
            $_logEski = logKayitAl($db, 'tahsilatlar', $id);
            if (!$_logEski) throw new DomainException('Tahsilat bulunamadı.', 404);
            $cq = $db->prepare("SELECT id FROM cek_senet WHERE tahsilat_id=? LIMIT 1");
            $cq->execute([$id]);
            if ($cid = $cq->fetchColumn()) {
                throw new DomainException('Bu tahsilat çek/senet kaydından (#' . (int)$cid . ') geliyor; buradan silinemez. Çek/Senet ekranında durumunu değiştirin.', 409);
            }
            $hakedisId = (int)($_logEski['hakedis_id'] ?: 0) ?: null;
            $db->prepare("DELETE FROM tahsilatlar WHERE id=?")->execute([$id]);
            _hakedisDurumYenile($db, $hakedisId);
            logla($db, 'tahsilatlar', $id, 'sil', $_logEski, null,
                  'Tahsilat SİLİNDİ · ' . number_format((float)($_logEski['tutar'] ?? 0), 2, ',', '.') . ' ₺');
        });
        jsonOk(null, 'Tahsilat silindi.');

    default:
        jsonError('Method Not Allowed', 405);
}
