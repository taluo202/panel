<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth(); yazmaIstegiCsrf();
$_oturumPersonelId = (int)($_SESSION['personel_id'] ?? 0);
subeMerkezi('personel');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$db     = getDb();
function _personelSube(array $body): ?string {
    $kisit = subeKisiti();
    if ($kisit !== null) return $kisit;
    return trim(metin($body['sube'] ?? '')) ?: null;
}



if ($method === 'POST' && $action === 'pin_yenile') {
    jsonError('PIN kaldırıldı: saha personeli check-in, sahada teslim ve tutanak işlerini kendi kullanıcı hesabıyla yapar. '
            . 'Ayarlar → Kullanıcı Yönetimi → Kullanıcı Ekle\'de "Bağlı Personel" olarak bu kişiyi seçin.', 410);
}

if ($method === 'POST' && $action === 'belge_onay') {
    verifyCsrf();
    requirePermission('personel');
    $bid  = (int)($_GET['id'] ?? 0);
    if ($bid < 1) jsonError('Geçersiz belge ID.');
    $body = govdeOku();
    $onay = in_array($body['onay'] ?? '', ['onaylandi', 'reddedildi'], true) ? $body['onay'] : null;
    if (!$onay) jsonError('Geçersiz onay durumu.');
    $st = $db->prepare("SELECT personel_id, belge_adi FROM personel_belgeler WHERE id=?");
    $st->execute([$bid]);
    $belge = $st->fetch();
    if (!$belge) jsonError('Belge bulunamadı.', 404);
    subeKayitDogrula($db, 'personel', (int)$belge['personel_id']);
    $gerekce = retGerekcesi($body, $onay);
    $db->prepare("UPDATE personel_belgeler SET onay_durumu=?, ret_gerekcesi=? WHERE id=?")->execute([$onay, $gerekce, $bid]);
    logla($db, 'personel_belgeler', $bid, 'guncelle', null, null,
          'Belge ' . ($onay === 'onaylandi' ? 'onaylandı' : 'reddedildi') . ': ' . $belge['belge_adi']
          . ($gerekce !== null ? ' · gerekçe: ' . $gerekce : ''));
    jsonOk(null, $onay === 'onaylandi' ? 'Belge onaylandı.' : 'Belge reddedildi.');
}

if ($method === 'GET' && $action === 'qr_bilgi') {
    requirePermission('personel');
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) jsonError('Geçersiz ID.');
    if ($id > 0) subeKayitDogrula($db, 'personel', $id);
    $st = $db->prepare("SELECT qr_token FROM personel WHERE id=?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) jsonError('Personel bulunamadı.', 404);
    $token = $row['qr_token'];
    if (!$token) {
        $token = bin2hex(random_bytes(16));
        $db->prepare("UPDATE personel SET qr_token=? WHERE id=?")->execute([$token, $id]);
    }
    jsonOk(['qr_payload' => 'PDKS:' . $id . ':' . $token]);
}

if ($method === 'POST' && $action === 'qr_yenile') {
    verifyCsrf();
    requirePermission('personel');
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) jsonError('Geçersiz ID.');
    if ($id > 0) subeKayitDogrula($db, 'personel', $id);
    $chk = $db->prepare("SELECT COUNT(*) FROM personel WHERE id=?");
    $chk->execute([$id]);
    if ((int)$chk->fetchColumn() === 0) jsonError('Personel bulunamadı.', 404);
    $token = bin2hex(random_bytes(16));
    $db->prepare("UPDATE personel SET qr_token=? WHERE id=?")->execute([$token, $id]);
    guvenlikLog('personel_qr_yenilendi', (string)($_SESSION['username'] ?? '?'),
                (int)($_SESSION['user_id'] ?? 0), ['personel_id' => $id]);
    jsonOk(['qr_payload' => 'PDKS:' . $id . ':' . $token], 'QR rozet yenilendi — eski rozet artık geçersiz.');
}

if ($method === 'GET' && $action === 'secici') {
    $st = $db->query("SELECT id, ad_soyad, pozisyon, meslek, sube FROM personel WHERE durum='aktif' ORDER BY ad_soyad COLLATE NOCASE");
    jsonOk($st->fetchAll());
}

switch ($method) {

    case 'GET':
        if (isset($_GET['id'])) {
            $id   = (int)$_GET['id'];
            if ($id !== $_oturumPersonelId) requirePermission('personel');
            if ($id > 0) subeKayitDogrula($db, 'personel', $id);
            $stmt = $db->prepare("
                SELECT p.*, (SELECT ad_soyad FROM personel WHERE id = p.amir_id) AS amir_ad
                FROM personel p WHERE p.id = ?
            ");
            $stmt->execute([$id]);
            $p = $stmt->fetch();
            if (!$p) jsonError('Personel bulunamadı.', 404);
            unset($p['pin_kod'], $p['qr_token']);
            $hq = $db->prepare('SELECT username FROM users WHERE personel_id=? ORDER BY id');
            $hq->execute([$id]);
            $p['bagli_hesaplar'] = $hq->fetchAll(PDO::FETCH_COLUMN);

            $bStmt = $db->prepare("SELECT * FROM personel_belgeler WHERE personel_id = ? ORDER BY kategori, created_at DESC");
            $bStmt->execute([$id]);
            $p['belgeler'] = $bStmt->fetchAll();

            $iStmt = $db->prepare("SELECT * FROM izin_talepleri WHERE personel_id = ? ORDER BY baslangic DESC");
            $iStmt->execute([$id]);
            $p['izin_talepleri'] = $iStmt->fetchAll();

            if ($p['ise_giris']) {
                $_izin    = yillikIzinHakki($p['ise_giris'], date('Y-m-d'));
                $kidem    = $_izin['kidem'];
                $hak      = $_izin['taban'];
                $ekstra   = (int)($p['ekstra_izin_hak'] ?? 0);
                $kullanilan = array_sum(array_column(
                    array_filter($p['izin_talepleri'], fn($i) =>
                        $i['durum'] === 'onaylandi' &&
                        substr($i['baslangic'], 0, 4) === date('Y') &&
                        in_array($i['izin_turu'], ['yillik', 'ekstra'], true)
                    ),
                    'gun_sayisi'
                ));
                $p['kidem']           = $kidem;
                $p['ekstra_hak']      = $ekstra;
                $p['yillik_hak']      = $hak + $ekstra;
                $p['kullanilan_izin'] = $kullanilan;
                $p['kalan_izin']      = max(0, $hak + $ekstra - $kullanilan);
            }

            $opKiralamalar = []; $puGun = 0.0; $puSaat = 0.0;
            try {
                $ok = $db->prepare("SELECT k.id, k.kod, k.ekipman_adi, k.baslangic, k.bitis, k.gercek_bitis, k.durum, k.operator_ucret, k.operator_ucret_tipi, m.unvan AS musteri_unvan, k.sube
                    FROM kiralamalar k LEFT JOIN musteriler m ON m.id=k.musteri_id
                    WHERE k.operatorlu=1 AND k.operator_id=? ORDER BY k.baslangic DESC, k.id DESC");
                $ok->execute([$id]);
                $_pk = subeKisitiHam();
                foreach ($ok->fetchAll() as $_k) {
                    if ($_pk !== null && trim((string)($_k['sube'] ?? '')) !== $_pk) { $_k['musteri_unvan'] = null; $_k['operator_ucret'] = null; $_k['operator_ucret_tipi'] = null; $_k['detay_gizli'] = 1; }
                    unset($_k['sube']);
                    $opKiralamalar[] = $_k;
                }
                $pq = $db->prepare("SELECT COALESCE(SUM(CASE WHEN durum='tam' THEN 1 WHEN durum='yarim' THEN 0.5 ELSE 0 END),0) gun, COALESCE(SUM(saat),0) saat
                    FROM kiralama_puantaj WHERE kiralama_id IN (SELECT id FROM kiralamalar WHERE operatorlu=1 AND operator_id=?)");
                $pq->execute([$id]);
                $pr = $pq->fetch();
                $puGun = (float)($pr['gun'] ?? 0); $puSaat = (float)($pr['saat'] ?? 0);
            } catch (\Exception $e) { hataGunlugu('personel.puantaj', $e, true); }
            $p['operator_kiralamalar'] = $opKiralamalar;
            $p['puantaj_ozet'] = ['calisma_gun' => $puGun, 'toplam_saat' => round($puSaat, 1), 'kiralama_adet' => count($opKiralamalar)];

            $teknisyenServisler = [];
            try {
                require_once __DIR__ . '/servis-maliyet-lib.php';
                $_fy = smFiyatYetkisi($db); $_pk = subeKisitiHam();
                $ts = $db->prepare("SELECT s.id, s.makine_ad, s.tip, s.tarih, s.durum, s.ariza, s.maliyet, " . servisSubeSql('s', 'k', 'ms', 'mk') . " AS servis_sube FROM teknik_servis s
                    LEFT JOIN kiralamalar k ON k.id=s.kiralama_id LEFT JOIN musteriler ms ON ms.id=s.musteri_id LEFT JOIN musteriler mk ON mk.id=k.musteri_id
                    WHERE s.teknisyen_id=? ORDER BY s.tarih DESC, s.id DESC");
                $ts->execute([$id]);
                foreach ($ts->fetchAll() as $_s) {
                    $_sb = (string)($_s['servis_sube'] ?? '');
                    unset($_s['servis_sube']);
                    if (!$_fy || ($_pk !== null && $_sb !== '' && $_sb !== $_pk)) unset($_s['maliyet']);
                    $teknisyenServisler[] = $_s;
                }
            } catch (\Exception $e) { hataGunlugu('personel.detay', $e, true); }
            $p['teknisyen_servisler'] = $teknisyenServisler;

            jsonOk($p);
        }

        requirePermission('personel');
        $search = trim(metin($_GET['q'] ?? ''));
        $durum  = trim(metin($_GET['durum'] ?? ''));
        $params = [];
        $where  = [];
        if ($search !== '') {
            $where[]  = "(" . trAramaSql('ad_soyad') . " LIKE ? OR " . trAramaSql('tc') . " LIKE ? OR " . trAramaSql('pozisyon') . " LIKE ?)";
            $like     = '%' . trArama($search) . '%';
            $params   = array_merge($params, [$like, $like, $like]);
        }
        if ($durum !== '') {
            $where[]  = "durum = ?";
            $params[] = $durum;
        }
        if (($meslek = personelMeslekGecerli($_GET['meslek'] ?? null)) !== null) {
            $where[]  = "meslek = ?";
            $params[] = $meslek;
        }
        $sql  = "SELECT p.id, p.ad_soyad, p.tc, p.pozisyon, p.meslek, p.durum, p.ise_giris, p.email, p.telefon,
            COALESCE(p.ekstra_izin_hak, 0) AS ekstra_izin_hak,
            COALESCE((SELECT SUM(it.gun_sayisi) FROM izin_talepleri it
                WHERE it.personel_id = p.id AND it.durum = 'onaylandi'
                  AND it.izin_turu IN ('yillik','ekstra')
                  AND strftime('%Y', it.baslangic) = strftime('%Y', 'now')), 0) AS kullanilan_izin,
            COALESCE((SELECT COUNT(*) FROM personel_belgeler pb
                WHERE pb.personel_id = p.id AND COALESCE(p.durum,'aktif') <> 'pasif'
                  AND pb.bitis_tarihi IS NOT NULL
                  AND (pb.arsiv IS NULL OR pb.arsiv=0)
                  AND pb.bitis_tarihi <= date('now','+30 days')), 0) AS belge_uyari,
            (SELECT MIN(pb2.bitis_tarihi) FROM personel_belgeler pb2
                WHERE pb2.personel_id = p.id AND COALESCE(p.durum,'aktif') <> 'pasif'
                  AND pb2.bitis_tarihi IS NOT NULL
                  AND (pb2.arsiv IS NULL OR pb2.arsiv=0)
                  AND pb2.bitis_tarihi <= date('now','+30 days')) AS belge_uyari_tarih
        FROM personel p";
        if ($where) {
            $where = array_map(fn($w) => str_replace(['ad_soyad','tc','pozisyon','durum'], ['p.ad_soyad','p.tc','p.pozisyon','p.durum'], $w), $where);
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        $sql .= " ORDER BY p.ad_soyad";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $list = $stmt->fetchAll();
        $alerts = $db->prepare("SELECT pb.id,pb.personel_id,pb.belge_adi,pb.kategori,pb.bitis_tarihi
            FROM personel_belgeler pb JOIN personel p ON p.id=pb.personel_id
            WHERE COALESCE(p.durum,'aktif') <> 'pasif' AND COALESCE(pb.arsiv,0)=0
              AND pb.bitis_tarihi IS NOT NULL AND pb.bitis_tarihi<=date('now','+30 days')
            ORDER BY pb.bitis_tarihi,pb.id");
        $alerts->execute(); $byPerson=[];
        foreach($alerts->fetchAll() as $a) $byPerson[$a['personel_id']][]=$a;
        foreach ($list as &$row) {
            $row['belge_uyari_detay']=$byPerson[$row['id']]??[];
            $row['belge_uyari']=count($row['belge_uyari_detay']);
            $row['belge_uyari_tarih']=$row['belge_uyari_detay'][0]['bitis_tarihi']??null;
            $_izin = yillikIzinHakki($row['ise_giris'] ?? null, date('Y-m-d'));
            $kidem = $_izin['kidem'];
            $hak   = $_izin['taban'];
            $ekstra = (int)($row['ekstra_izin_hak'] ?? 0);
            $row['yillik_hak'] = $hak + $ekstra;
            $row['kalan_izin'] = max(0, $hak + $ekstra - (int)$row['kullanilan_izin']);
        }
        unset($row);
        jsonOk($list);
        break;

    case 'POST':
        verifyCsrf();
        requirePermission('personel');
        $body     = govdeOku();
        $ad_soyad = trim(metin($body['ad_soyad'] ?? ''));
        if ($ad_soyad === '') jsonError('Ad Soyad zorunludur.');

        $tc      = preg_replace('/\D/', '', metin($body['tc'] ?? ''));
        if ($tc !== '' && strlen($tc) !== 11) jsonError('T.C. Kimlik No 11 haneli olmalıdır.');

        $qrToken = bin2hex(random_bytes(16));

        $amirId = ((int)($body['amir_id'] ?? 0)) ?: null;

        $stmt = $db->prepare("
            INSERT INTO personel (ad_soyad, tc, email, telefon, ise_giris, pozisyon, meslek, durum, adres, notlar, sube, amir_id, qr_token)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $ad_soyad,
            $tc ?: null,
            filter_var($body['email'] ?? '', FILTER_SANITIZE_EMAIL) ?: null,
            trim(metin($body['telefon'] ?? '')) ?: null,
            dateStr('ise_giris', $body),
            trim(metin($body['pozisyon'] ?? '')) ?: null,
            personelMeslekGecerli($body['meslek'] ?? null),
            in_array($body['durum'] ?? '', ['aktif','izinli','raporlu','pasif']) ? $body['durum'] : 'aktif',
            trim(metin($body['adres'] ?? '')) ?: null,
            trim(metin($body['notlar'] ?? '')) ?: null,
            _personelSube($body),
            $amirId,
            $qrToken,
        ]);
        $newId = (int)$db->lastInsertId();
        jsonOk(['id' => $newId, 'qr_payload' => 'PDKS:' . $newId . ':' . $qrToken], 'Personel eklendi.');

    case 'PUT':
        verifyCsrf();
        requirePermission('personel');
        $id   = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        if ($id > 0) subeKayitDogrula($db, 'personel', $id);
        $body = govdeOku();

        $tc = preg_replace('/\D/', '', metin($body['tc'] ?? ''));
        if ($tc !== '' && strlen($tc) !== 11) jsonError('T.C. Kimlik No 11 haneli olmalıdır.');

        $amirId = ((int)($body['amir_id'] ?? 0)) ?: null;
        if ($amirId !== null && $amirId === $id) jsonError('Bir personel kendi kendisinin yöneticisi olamaz.');

        $stmt = $db->prepare("
            UPDATE personel SET
                ad_soyad=?, tc=?, email=?, telefon=?, ise_giris=?,
                pozisyon=?, meslek=?, durum=?, adres=?, notlar=?, sube=?, amir_id=?,
                updated_at=datetime('now','localtime')
            WHERE id=?
        ");
        $stmt->execute([
            trim(metin($body['ad_soyad'] ?? '')) ?: jsonError('Ad Soyad zorunludur.'),
            $tc ?: null,
            filter_var($body['email'] ?? '', FILTER_SANITIZE_EMAIL) ?: null,
            trim(metin($body['telefon'] ?? '')) ?: null,
            dateStr('ise_giris', $body),
            trim(metin($body['pozisyon'] ?? '')) ?: null,
            personelMeslekGecerli($body['meslek'] ?? null),
            in_array($body['durum'] ?? '', ['aktif','izinli','raporlu','pasif']) ? $body['durum'] : 'aktif',
            trim(metin($body['adres'] ?? '')) ?: null,
            trim(metin($body['notlar'] ?? '')) ?: null,
            _personelSube($body),
            $amirId,
            $id,
        ]);
        jsonOk(null, 'Personel güncellendi.');

    case 'PATCH':
        verifyCsrf();
        requirePermission('personel');
        $id   = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        if ($id > 0) subeKayitDogrula($db, 'personel', $id);
        $body = govdeOku();
        $ekstra = (int)($body['ekstra_izin_hak'] ?? 0);
        $db->prepare("UPDATE personel SET ekstra_izin_hak=? WHERE id=?")->execute([$ekstra, $id]);
        jsonOk(null, 'Ekstra izin hakkı güncellendi.');

    case 'DELETE':
        verifyCsrf();
        requirePermission('personel');
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        if ($id > 0) subeKayitDogrula($db, 'personel', $id);
        $bStmt = $db->prepare("SELECT dosya_yolu FROM personel_belgeler WHERE personel_id = ? AND dosya_yolu IS NOT NULL");
        $bStmt->execute([$id]);
        $uploadBase = realpath(UPLOAD_PATH);
        foreach ($bStmt->fetchAll(PDO::FETCH_COLUMN) as $rel) {
            if (preg_match('#^[a-z]+/\d+/[a-f0-9]{32}\.[a-z0-9]{2,5}$#', $rel)) {
                $full = realpath(UPLOAD_PATH . $rel);
                if ($full && $uploadBase && strncmp($full, $uploadBase, strlen($uploadBase)) === 0 && file_exists($full)) {
                    unlink($full);
                }
            }
        }
        $tStmt = $db->prepare("SELECT dosya_yolu FROM tutanaklar WHERE tur='personel' AND entity_id=? AND dosya_yolu IS NOT NULL");
        $tStmt->execute([$id]);
        foreach ($tStmt->fetchAll(PDO::FETCH_COLUMN) as $rel) {
            if (preg_match('#^tutanak/[a-z]+/\d+/[a-f0-9]{32}\.[a-z0-9]{2,5}$#', $rel)) {
                $full = realpath(UPLOAD_PATH . $rel);
                if ($full && $uploadBase && strncmp($full, $uploadBase, strlen($uploadBase)) === 0 && file_exists($full)) {
                    unlink($full);
                }
            }
        }
        $db->prepare("DELETE FROM tutanaklar WHERE tur='personel' AND entity_id=?")->execute([$id]);
        $db->prepare("DELETE FROM personel WHERE id=?")->execute([$id]);
        jsonOk(null, 'Personel silindi.');

    default:
        jsonError('Method Not Allowed', 405);
}
