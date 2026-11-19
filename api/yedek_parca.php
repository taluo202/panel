<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('yedek_parca');
subeMerkezi('yedek_parca');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$db     = getDb();

if ($method === 'GET' && $action === 'subeler_arasi') {
    $q = trim(metin($_GET['q'] ?? ''));
    $where = []; $params = [];
    if ($q !== '') {
        $where[] = "(" . trAramaSql('ad') . " LIKE ? OR " . trAramaSql('parca_no') . " LIKE ? OR " . trAramaSql('uyumlu_makine') . " LIKE ? OR " . trAramaSql('seri_no') . " LIKE ? OR " . trAramaSql('uretim_no') . " LIKE ?)";
        $like = '%' . trArama($q) . '%'; array_push($params, $like, $like, $like, $like, $like);
    }
    $sql = "SELECT * FROM yedek_parca";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $st = $db->prepare($sql); $st->execute($params);

    $subeler = $db->query("SELECT ad FROM subeler ORDER BY ad")->fetchAll(PDO::FETCH_COLUMN);
    $gruplar = [];
    foreach ($st->fetchAll() as $r) {
        $key = trim((string)($r['parca_no'] ?: $r['ad']));
        if (!isset($gruplar[$key])) {
            $gruplar[$key] = [
                'ad' => $r['ad'], 'parca_no' => $r['parca_no'],
                'uyumlu_makine' => $r['uyumlu_makine'],
                'subeler' => [], 'toplam_stok' => 0,
            ];
        }
        $sb = trim((string)($r['sube'] ?: '—'));
        $gruplar[$key]['subeler'][$sb] = ($gruplar[$key]['subeler'][$sb] ?? 0) + (int)$r['stok'];
        $gruplar[$key]['toplam_stok'] += (int)$r['stok'];
        if (!$gruplar[$key]['ad']) $gruplar[$key]['ad'] = $r['ad'];
    }
    jsonOk(['subeler' => $subeler, 'gruplar' => array_values($gruplar)]);
}

if ($method === 'GET' && $action === 'barkod_bul') {
    $kod = trim(metin($_GET['kod'] ?? ''));
    if ($kod === '') jsonError('Kod boş olamaz.');
    $st = $db->prepare("SELECT * FROM yedek_parca WHERE barkod=? COLLATE NOCASE OR parca_no=? COLLATE NOCASE ORDER BY (barkod=? COLLATE NOCASE) DESC LIMIT 1");
    $st->execute([$kod, $kod, $kod]);
    $r = $st->fetch();
    if (!$r) jsonError('Bu koda ait parça bulunamadı.', 404);
    jsonOk($r);
}

if ($method === 'GET' && $action === 'fiyat_gecmisi') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) jsonError('Geçersiz ID.');
    $st = $db->prepare("SELECT f.fatura_tarihi, f.fatura_no, t.unvan AS tedarikci_unvan, s.kod AS siparis_kod,
                               fk.miktar, fk.birim_fiyat, fk.kdv_oran, fk.toplam
                        FROM satinalma_fatura_kalemleri fk
                        JOIN satinalma_faturalari f ON f.id = fk.fatura_id
                        JOIN satinalma_kalemleri k ON k.id = fk.kalem_id
                        LEFT JOIN satinalma_siparisleri s ON s.id = f.siparis_id
                        LEFT JOIN tedarikciler t ON t.id = f.tedarikci_id
                        WHERE k.parca_id = ?
                        ORDER BY f.fatura_tarihi DESC, f.id DESC, fk.id DESC");
    $st->execute([$id]);
    jsonOk(array_map(fn($r) => ['fatura_tarihi' => $r['fatura_tarihi'], 'fatura_no' => $r['fatura_no'], 'tedarikci_unvan' => $r['tedarikci_unvan'],
        'siparis_kod' => $r['siparis_kod'], 'miktar' => (float)$r['miktar'], 'birim_fiyat' => (float)$r['birim_fiyat'],
        'kdv_oran' => (float)$r['kdv_oran'], 'toplam' => (float)$r['toplam']], $st->fetchAll()));
}

switch ($method) {

    case 'GET':
        if (isset($_GET['id'])) {
            $st = $db->prepare("SELECT p.*, t.unvan AS son_tedarikci_unvan FROM yedek_parca p LEFT JOIN tedarikciler t ON t.id = p.son_tedarikci_id WHERE p.id=?");
            $st->execute([(int)$_GET['id']]);
            $r = $st->fetch();
            if (!$r) jsonError('Parça bulunamadı.', 404);
            jsonOk($r);
        }

        $where = []; $params = [];
        if (($sube = trim(metin($_GET['sube'] ?? ''))) !== '') { $where[] = "sube=?"; $params[] = $sube; }
        subeKosulEkle($where, $params, 'sube');
        if (($q = trim(metin($_GET['q'] ?? ''))) !== '') {
            $where[] = "(" . trAramaSql('ad') . " LIKE ? OR " . trAramaSql('parca_no') . " LIKE ? OR " . trAramaSql('uyumlu_makine') . " LIKE ? OR " . trAramaSql('seri_no') . " LIKE ? OR " . trAramaSql('uretim_no') . " LIKE ? OR " . trAramaSql('aciklama') . " LIKE ?)";
            $like = '%' . trArama($q) . '%'; array_push($params, $like, $like, $like, $like, $like, $like);
        }
        if (($_GET['dusuk'] ?? '') === '1') { $where[] = "stok <= kritik_stok AND kritik_stok > 0"; }
        if (($_GET['aktif'] ?? '') !== 'hepsi') { $where[] = "COALESCE(aktif,1)=1"; }

        $sql = "SELECT * FROM yedek_parca";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY ad COLLATE NOCASE, sube";

        $sayfalama = null;
        if (isset($_GET['page'])) {
            $sayfa = sayfaNo();
            $perPage = min(200, max(10, (int)($_GET['per_page'] ?? 50)));
            [$toplam, $sayfaSayisi] = sayfalamaBilgisi($db, $sql, $params, $perPage);
            $sayfa = min($sayfa, $sayfaSayisi);
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = $perPage; $params[] = ($sayfa - 1) * $perPage;
            $sayfalama = ['sayfa' => $sayfa, 'sayfa_sayisi' => $sayfaSayisi, 'toplam' => $toplam, 'per_page' => $perPage];
        }

        $st = $db->prepare($sql); $st->execute($params);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            $r['dusuk'] = ((int)$r['kritik_stok'] > 0 && (int)$r['stok'] <= (int)$r['kritik_stok']) ? 1 : 0;
            $r['toplam_deger'] = round((int)$r['stok'] * (float)$r['fiyat'], 2);
        }
        unset($r);

        $ozet = $db->query("SELECT COUNT(*) cesit, COALESCE(SUM(stok),0) stok, COALESCE(SUM(stok*fiyat),0) deger,
                                   COALESCE(SUM(CASE WHEN kritik_stok>0 AND stok<=kritik_stok THEN 1 ELSE 0 END),0) kritik
                            FROM yedek_parca")->fetch();
        $sonuc = ['list' => $rows, 'ozet' => $ozet];
        if ($sayfalama) $sonuc['sayfalama'] = $sayfalama;
        jsonOk($sonuc);
        break;

    case 'POST':
        verifyCsrf();
        $body = govdeOku();
        $ad   = trim(metin($body['ad'] ?? ''));
        if ($ad === '') jsonError('Parça adı zorunludur.');
        $db->prepare("
            INSERT INTO yedek_parca (ad, parca_no, aciklama, sube, stok, kritik_stok, fiyat, uyumlu_makine, seri_no, uretim_no, garanti_bitis, barkod)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $ad,
            trim(metin($body['parca_no'] ?? '')) ?: null,
            trim(metin($body['aciklama'] ?? '')) ?: null,
            trim(metin($body['sube'] ?? '')) ?: null,
            max(0, (int)($body['stok'] ?? 0)),
            max(0, (int)($body['kritik_stok'] ?? 0)),
            max(0, (float)($body['fiyat'] ?? 0)),
            trim(metin($body['uyumlu_makine'] ?? '')) ?: null,
            trim(metin($body['seri_no'] ?? '')) ?: null,
            trim(metin($body['uretim_no'] ?? '')) ?: null,
            dateStr('garanti_bitis', $body),
            trim(metin($body['barkod'] ?? '')) ?: null,
        ]);
        jsonOk(['id' => (int)$db->lastInsertId()], 'Yedek parça eklendi.');

    case 'PUT':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        if ($id > 0) subeKayitDogrula($db, 'yedek_parca', $id);
        $body = govdeOku();

        if (count($body) <= 2 && isset($body['stok']) && !isset($body['ad'])) {
            $db->prepare("UPDATE yedek_parca SET stok=?, updated_at=datetime('now','localtime') WHERE id=?")
               ->execute([max(0, (int)$body['stok']), $id]);
            jsonOk(null, 'Stok güncellendi.');
        }

        if (count($body) <= 2 && isset($body['aktif']) && !isset($body['ad'])) {
            $yeni = ((int)$body['aktif'] === 1) ? 1 : 0;
            $_aLogEski = logKayitAl($db, 'yedek_parca', $id);
            $db->prepare("UPDATE yedek_parca SET aktif=?, updated_at=datetime('now','localtime') WHERE id=?")
               ->execute([$yeni, $id]);
            logla($db, 'yedek_parca', $id, 'guncelle', $_aLogEski, logKayitAl($db, 'yedek_parca', $id),
                ($yeni ? 'Yedek parça yeniden kullanıma alındı: ' : 'Yedek parça kullanım dışı bırakıldı: ')
                . ($_aLogEski['ad'] ?? ('#' . $id)));
            jsonOk(null, $yeni ? 'Parça yeniden kullanıma alındı.' : 'Parça kullanım dışı bırakıldı.');
        }

        $ad = trim(metin($body['ad'] ?? ''));
        if ($ad === '') jsonError('Parça adı zorunludur.');
        $db->prepare("
            UPDATE yedek_parca SET ad=?, parca_no=?, aciklama=?, sube=?, stok=?, kritik_stok=?, fiyat=?,
                uyumlu_makine=?, seri_no=?, uretim_no=?, garanti_bitis=?, barkod=?, updated_at=datetime('now','localtime')
            WHERE id=?
        ")->execute([
            $ad,
            trim(metin($body['parca_no'] ?? '')) ?: null,
            trim(metin($body['aciklama'] ?? '')) ?: null,
            pasifSubeKoru($db, 'yedek_parca', $id, metin($body['sube'] ?? '')),
            max(0, (int)($body['stok'] ?? 0)),
            max(0, (int)($body['kritik_stok'] ?? 0)),
            max(0, (float)($body['fiyat'] ?? 0)),
            trim(metin($body['uyumlu_makine'] ?? '')) ?: null,
            trim(metin($body['seri_no'] ?? '')) ?: null,
            trim(metin($body['uretim_no'] ?? '')) ?: null,
            dateStr('garanti_bitis', $body),
            trim(metin($body['barkod'] ?? '')) ?: null,
            $id,
        ]);
        jsonOk(null, 'Yedek parça güncellendi.');

    case 'DELETE':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        if ($id > 0) subeKayitDogrula($db, 'yedek_parca', $id);
        $hSt = $db->prepare("SELECT COUNT(*) FROM stok_hareket WHERE parca_id=?");
        $hSt->execute([$id]);
        $hareketSayisi = (int)$hSt->fetchColumn();
        if ($hareketSayisi > 0) {
            jsonError('Bu parçanın ' . $hareketSayisi . ' stok hareketi var (servis sarfı/satış/giriş) — silinirse bu geçmiş ve bağlı servis maliyetlerinin dayanağı yok olur. Bunun yerine satırdaki "Kullanım dışı bırak" düğmesini kullanın: parça listelerden çıkar, geçmişi durur.', 409, ['hareket_sayisi' => $hareketSayisi, 'alternatif' => 'kullanim_disi']);
        }
        $_pLogEski = logKayitAl($db, 'yedek_parca', $id);
        $db->prepare("DELETE FROM yedek_parca WHERE id=?")->execute([$id]);
        logla($db, 'yedek_parca', $id, 'sil', $_pLogEski, null,
            'Yedek parça silindi: ' . ($_pLogEski['ad'] ?? ('#' . $id)));
        jsonOk(null, 'Yedek parça silindi.');

    default:
        jsonError('Method Not Allowed', 405);
}
