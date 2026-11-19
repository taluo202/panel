<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('kiralama_takip');


$db     = getDb();
$method = $_SERVER['REQUEST_METHOD'];

function _santiyeErisimDogrula(PDO $db, int $id): array {
    $st = $db->prepare("SELECT s.*, m.sube AS musteri_sube
                        FROM santiyeler s LEFT JOIN musteriler m ON m.id = s.musteri_id
                        WHERE s.id = ?");
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) jsonError('Şantiye bulunamadı.', 404);
    if ($r['musteri_id'] !== null) subeErisimDogrula($r['musteri_sube'] ?? null);
    return $r;
}

function _santiyeIrtibatSiniri(array $body): void {
    require_once __DIR__ . '/hizli-kiralama-lib.php';
    foreach (['yetkili', 'telefon'] as $alan) {
        if (($h = hkIrtibatSiniri($alan, trim(metin($body[$alan] ?? '')))) !== null) jsonError($h);
    }
}

function _santiyeAyni(PDO $db, ?int $musteriId, string $ad): ?array {
    $st = $db->prepare("SELECT * FROM santiyeler
                        WHERE aktif=1 AND " . trAramaSql('ad') . " = ?
                          AND (musteri_id IS ? OR musteri_id = ?)");
    $st->execute([trArama($ad), $musteriId, $musteriId]);
    $r = $st->fetch();
    return $r ?: null;
}

if ($method === 'GET') {
    $mid = (int)($_GET['musteri_id'] ?? 0);
    $q   = trim(metin($_GET['q'] ?? ''));

    $where = ['s.aktif=1']; $params = [];
    if ($mid > 0) {
        $where[] = '(s.musteri_id = ? OR s.musteri_id IS NULL)';
        $params[] = $mid;
    }
    if ($q !== '') {
        $where[] = '(' . trAramaSql('s.ad') . ' LIKE ? OR ' . trAramaSql('s.adres') . ' LIKE ? OR ' . trAramaSql('s.il') . ' LIKE ?)';
        $like = '%' . trArama($q) . '%';
        array_push($params, $like, $like, $like);
    }
    $st = $db->prepare("SELECT s.* FROM santiyeler s LEFT JOIN musteriler m ON m.id = s.musteri_id
                        WHERE " . implode(' AND ', $where)
                     . " ORDER BY s.kullanim DESC, s.son_kullanim DESC, s.ad LIMIT 200");
    $st->execute($params);
    jsonOk($st->fetchAll());
}

if ($method === 'POST') {
    verifyCsrf();
    $body = govdeOku();
    $ad   = trim(metin($body['ad'] ?? ''));
    if ($ad === '') jsonError('Şantiye adı zorunludur.');
    require_once __DIR__ . '/hizli-kiralama-lib.php';
    try { $konum = hkKonumDogrula(metin($body['konum'] ?? '')); }
    catch (DomainException $e) { jsonError($e->getMessage()); }
    _santiyeIrtibatSiniri($body);
    $mid  = (int)($body['musteri_id'] ?? 0) ?: null;
    if ($mid !== null) {
        $ms = $db->prepare("SELECT sube FROM musteriler WHERE id=?");
        $ms->execute([$mid]);
        $msRow = $ms->fetch();
        if (!$msRow) jsonError('Müşteri bulunamadı.', 404);
    }

    $var = _santiyeAyni($db, $mid, $ad);
    if ($var) {
        $kisit    = subeKisiti();
        $adresYaz = $mid === null || $kisit === null || trim((string)($msRow['sube'] ?? '')) === $kisit;
        if ($adresYaz) {
            $degisen = is_array($body['degisen'] ?? null) ? array_map('metin', $body['degisen']) : [];
            $irtibatSet = [];
            foreach (['yetkili' => trim(metin($body['yetkili'] ?? '')), 'telefon' => trim(metin($body['telefon'] ?? '')),
                      'konum' => (string)$konum] as $alan => $yeniDeger) {
                $simdiki = trim((string)($var[$alan] ?? ''));
                $elle = in_array($alan, $degisen, true);
                if ($yeniDeger === '') {
                    if ($elle && $simdiki !== '') $irtibatSet[$alan] = null;
                    continue;
                }
                if ($simdiki === '' || ($elle && $simdiki !== $yeniDeger)) $irtibatSet[$alan] = $yeniDeger;
            }
            $izli = ['adres', 'il', 'ilce', 'yetkili', 'telefon', 'konum'];
            $sql = "UPDATE santiyeler SET kullanim = COALESCE(kullanim,0) + 1,
                            son_kullanim = datetime('now','localtime'),
                            adres = COALESCE(NULLIF(?,''), adres), il = COALESCE(NULLIF(?,''), il),
                            ilce = COALESCE(NULLIF(?,''), ilce)";
            foreach (array_keys($irtibatSet) as $alan) $sql .= ", $alan = ?";
            $db->prepare($sql . " WHERE id = ?")
               ->execute(array_merge([trim(metin($body['adres'] ?? '')), ilYazim(metin($body['il'] ?? '')),
                          trim(metin($body['ilce'] ?? ''))], array_values($irtibatSet), [(int)$var['id']]));
            $son = $db->prepare('SELECT * FROM santiyeler WHERE id=?');
            $son->execute([(int)$var['id']]);
            $sonRow = $son->fetch() ?: [];
            logla($db, 'santiyeler', (int)$var['id'], 'guncelle',
                  array_intersect_key($var, array_flip($izli)), array_intersect_key($sonRow, array_flip($izli)),
                  'Şantiye bilgisi kiralama kaydından güncellendi: ' . $ad);
        } else {
            $db->prepare("UPDATE santiyeler SET kullanim = COALESCE(kullanim,0) + 1,
                            son_kullanim = datetime('now','localtime') WHERE id = ?")
               ->execute([(int)$var['id']]);
        }
        jsonOk(['id' => (int)$var['id'], 'yeni' => false], 'Şantiye zaten kayıtlı.');
    }

    $st = $db->prepare("INSERT INTO santiyeler (musteri_id, ad, adres, il, ilce, yetkili, telefon, notlar, konum, kullanim, son_kullanim)
                        VALUES (?,?,?,?,?,?,?,?,?,1,datetime('now','localtime'))");
    $st->execute([
        $mid, $ad,
        trim(metin($body['adres'] ?? '')) ?: null,
        ilYazim(metin($body['il'] ?? '')) ?: null,
        trim(metin($body['ilce'] ?? '')) ?: null,
        trim(metin($body['yetkili'] ?? '')) ?: null,
        trim(metin($body['telefon'] ?? '')) ?: null,
        trim(metin($body['notlar'] ?? '')) ?: null,
        $konum,
    ]);
    $id = (int)$db->lastInsertId();
    logla($db, 'santiyeler', $id, 'ekle', null, null, 'Şantiye eklendi: ' . $ad);
    jsonOk(['id' => $id, 'yeni' => true], 'Şantiye kaydedildi.');
}

if ($method === 'PUT') {
    verifyCsrf();
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) jsonError('Geçersiz ID.');
    $body = govdeOku();
    $ad   = trim(metin($body['ad'] ?? ''));
    if ($ad === '') jsonError('Şantiye adı zorunludur.');
    _santiyeErisimDogrula($db, $id);
    _santiyeIrtibatSiniri($body);
    $yeniMid = (int)($body['musteri_id'] ?? 0) ?: null;
    if ($yeniMid !== null) {
        $ms = $db->prepare("SELECT sube FROM musteriler WHERE id=?");
        $ms->execute([$yeniMid]);
        $msRow = $ms->fetch();
        if (!$msRow) jsonError('Müşteri bulunamadı.', 404);
        subeErisimDogrula($msRow['sube'] ?? null);
    }
    $eski = logKayitAl($db, 'santiyeler', $id);
    $db->prepare("UPDATE santiyeler SET musteri_id=?, ad=?, adres=?, il=?, ilce=?, yetkili=?, telefon=?, notlar=? WHERE id=?")
       ->execute([
        $yeniMid, $ad,
        trim(metin($body['adres'] ?? '')) ?: null,
        ilYazim(metin($body['il'] ?? '')) ?: null,
        trim(metin($body['ilce'] ?? '')) ?: null,
        trim(metin($body['yetkili'] ?? '')) ?: null,
        trim(metin($body['telefon'] ?? '')) ?: null,
        trim(metin($body['notlar'] ?? '')) ?: null,
        $id,
       ]);
    logla($db, 'santiyeler', $id, 'guncelle', $eski, logKayitAl($db, 'santiyeler', $id));
    jsonOk(null, 'Şantiye güncellendi.');
}

if ($method === 'DELETE') {
    verifyCsrf();
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) jsonError('Geçersiz ID.');
    _santiyeErisimDogrula($db, $id);
    $eski = logKayitAl($db, 'santiyeler', $id);
    $db->prepare("UPDATE santiyeler SET aktif=0 WHERE id=?")->execute([$id]);
    logla($db, 'santiyeler', $id, 'sil', $eski, null, 'Şantiye pasife alındı.');
    jsonOk(null, 'Şantiye listeden kaldırıldı.');
}

jsonError('Method Not Allowed', 405);
