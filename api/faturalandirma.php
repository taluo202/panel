<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/zincir-lib.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('kiralama_takip');

$db     = getDb();
$method = $_SERVER['REQUEST_METHOD'];

if (($_GET['tur'] ?? '') === 'hasar' && in_array($method, ['GET', 'POST'], true)) {
    require_once __DIR__ . '/hasar-fiyat-lib.php';
    if ($method === 'GET' && ($_GET['action'] ?? '') === 'sayi') {
        $w = ['f.faturalandi=0']; $p = [];
        subeKosulEkle($w, $p, 'f.sube');
        $st = $db->prepare('SELECT COUNT(*) FROM hasar_faturalari f WHERE ' . implode(' AND ', $w)); $st->execute($p);
        jsonOk(['bekleyen' => (int)$st->fetchColumn()]);
    }
    if ($method === 'GET') {
        $where = [($_GET['durum'] ?? 'bekleyen') === 'faturalandi' ? 'f.faturalandi=1' : 'f.faturalandi=0']; $params = [];
        if (($q = trim(metin($_GET['q'] ?? ''))) !== '') {
            $where[] = '(' . trAramaSql('m.unvan') . ' LIKE ? OR ' . trAramaSql('f.ref') . ' LIKE ?)';
            $like = '%' . trArama($q) . '%'; array_push($params, $like, $like);
        }
        if (($sb = trim(metin($_GET['sube'] ?? ''))) !== '') { $where[] = 'f.sube=?'; $params[] = $sb; }
        subeKosulEkle($where, $params, 'f.sube');
        $st = $db->prepare('SELECT f.id, f.degerlendirme_id, f.ref, f.para_birimi, f.ara_toplam, f.kdv_tutar, f.genel_toplam, f.kur, f.tl_genel,
                                   f.faturalandi, f.fatura_no, f.fatura_tarihi, f.fatura_durum, f.fatura_hata_notu, f.created_at,
                                   m.unvan AS musteri_unvan, k.kod AS kiralama_kod, k.sube
                            FROM hasar_faturalari f JOIN musteriler m ON m.id=f.musteri_id LEFT JOIN kiralamalar k ON k.id=f.kiralama_id
                            WHERE ' . implode(' AND ', $where) . ' ORDER BY f.id DESC');
        $st->execute($params);
        $liste = $st->fetchAll();
        jsonOk(['list' => $liste, 'toplam_tl' => round(array_sum(array_map(fn($x) => (float)($x['tl_genel'] ?? ($x['para_birimi'] === 'TL' ? $x['genel_toplam'] : 0)), $liste)), 2)]);
    }
    if (!hasPermission('kiralama_takip', 'yaz')) jsonError('Fatura işareti için kiralama yazma yetkiniz yok.', 403);
    verifyCsrf();
    $body = govdeOku();
    $db->beginTransaction();
    try {
        $n = !empty($body['faturalandi']) ? hfFaturaIsaretle($db, (array)($body['ids'] ?? []), $body) : hfFaturaKaldir($db, (array)($body['ids'] ?? []));
        $db->commit();
        jsonOk(['adet' => $n], $n . (!empty($body['faturalandi']) ? ' hasar bedeli faturalandı olarak işaretlendi.' : ' kaydın fatura işareti kaldırıldı (cari borç düştü).'));
    } catch (DomainException $e) { $db->rollBack(); jsonError($e->getMessage(), 409); }
}

if ($method === 'GET' && ($_GET['tur'] ?? '') === 'servis' && isset($_GET['id'])) {
    $sid = (int)$_GET['id'];
    [$_svKosul, $_svPar] = servisFaturaSubeKosulu('s.id=? AND (COALESCE(s.faturalandi,0)=1 OR (' . SM_FATURALANABILIR_SQL . '))');
    $q = $db->prepare($_svKosul);
    $q->execute(array_merge([$sid], $_svPar));
    if (!$q->fetchColumn()) jsonError('Faturalanacak servis bulunamadı.', 404);
    jsonOk(smMusteriGorunumu($db, $sid));
}

if ($method === 'GET' && ($_GET['tur'] ?? '') === 'servis') {
    $durum  = trim(metin($_GET['durum'] ?? 'bekleyen'));
    $fatKos = $durum === 'faturalandi' ? 's.faturalandi=1' : '(s.faturalandi IS NULL OR s.faturalandi=0)';

    $where = [$fatKos, $durum === 'faturalandi' ? '1=1' : SM_FATURALANABILIR_SQL];
    $params = [];
    if (($q = trim(metin($_GET['q'] ?? ''))) !== '') {
        $where[] = '(' . trAramaSql('s.makine_ad') . ' LIKE ? OR ' . trAramaSql('s.ariza') . ' LIKE ? OR ' . trAramaSql('m.unvan') . ' LIKE ?)';
        $like = '%' . trArama($q) . '%'; array_push($params, $like, $like, $like);
    }
    $_svSube = servisSubeSql('s', 'k', 'ms', 'mk');
    if (($sb = trim(metin($_GET['sube'] ?? ''))) !== '') { $where[] = "$_svSube=?"; $params[] = $sb; }
    subeKosulEkle($where, $params, $_svSube);

    $st = $db->prepare("
        SELECT s.id, s.tarih, s.tip, s.makine_tur, s.makine_id, s.makine_ad, s.ariza, s.maliyet,
               s.faturalandi, s.fatura_no, s.fatura_tarihi, s.fatura_tipi, s.fatura_durum, s.fatura_hata_notu, s.kiralama_id,
               k.kod AS kiralama_kod, $_svSube AS sube, m.id AS musteri_id, m.unvan AS musteri_unvan,
               s.fiyat_durum, s.musteri_tutar, s.musteri_ara_toplam, s.musteri_iskonto, s.musteri_kdv, s.musteri_genel_toplam,
               CASE WHEN s.fiyat_durum = 'fiyatlandi' THEN COALESCE(s.musteri_genel_toplam, s.musteri_tutar) ELSE s.maliyet END AS tutar,
               CASE WHEN s.fiyat_durum = 'fiyatlandi' THEN s.musteri_tutar ELSE s.maliyet END AS tutar_kdv_haric
        FROM teknik_servis s
        LEFT JOIN kiralamalar k ON k.id = s.kiralama_id
        LEFT JOIN musteriler m ON m.id = COALESCE(s.musteri_id, k.musteri_id)
        LEFT JOIN musteriler ms ON ms.id = s.musteri_id
        LEFT JOIN musteriler mk ON mk.id = k.musteri_id
        WHERE " . implode(' AND ', $where) . " ORDER BY s.tarih DESC, s.id DESC");
    $st->execute($params);
    $liste = $st->fetchAll();
    jsonOk(['list' => $liste, 'toplam' => round(array_sum(array_column($liste, 'tutar')), 2),
            'toplam_kdv_haric' => round(array_sum(array_column($liste, 'tutar_kdv_haric')), 2)]);
}

if ($method === 'POST' && ($_GET['tur'] ?? '') === 'servis') {
    verifyCsrf();
    $body = govdeOku();
    $ids  = array_values(array_filter(array_map('intval', $body['ids'] ?? []), fn($x) => $x > 0));
    if (!$ids) jsonError('Kayıt seçilmedi.');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $faturalandi = isset($body['faturalandi']) ? (int)(bool)$body['faturalandi'] : 1;
    [$_svKosul, $_svPar] = servisFaturaSubeKosulu($faturalandi ? SM_FATURALANABILIR_SQL : 'COALESCE(s.faturalandi,0)=1');

    $db->beginTransaction();
    try {
        if ($faturalandi) {
            $fno = trim(metin($body['fatura_no'] ?? '')) ?: null;
            $ft  = dateStr('fatura_tarihi', $body) ?: date('Y-m-d');
            $ftip = ($body['fatura_tipi'] ?? 'resmi') === 'elden' ? 'elden' : 'resmi';
            $st = $db->prepare("UPDATE teknik_servis SET faturalandi=1, fatura_no=?, fatura_tarihi=?, fatura_tipi=?, fatura_durum='kesildi', updated_at=datetime('now','localtime')
                                WHERE id IN ($ph) AND id IN ($_svKosul)");
            $st->execute(array_merge([$fno, $ft, $ftip], $ids, $_svPar));
        } else {
            $st = $db->prepare("UPDATE teknik_servis SET faturalandi=0, fatura_no=NULL, fatura_tarihi=NULL, fatura_durum=NULL, updated_at=datetime('now','localtime') WHERE id IN ($ph) AND id IN ($_svKosul)");
            $st->execute(array_merge($ids, $_svPar));
        }
        $n = $st->rowCount();
        if ($n === 0) { $db->rollBack(); jsonError('Seçilen kayıtlar bulunamadı ya da başka şubeye ait.', 404); }
        logla($db, 'teknik_servis', null, 'guncelle', null, null,
              $n . ($faturalandi ? ' servis kaydı FATURALANDI işaretlendi' : ' servis kaydının fatura işareti KALDIRILDI')
              . (($fno ?? null) ? ' · fatura no: ' . $fno : ''));
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    jsonOk(['adet' => $n], $n . ($faturalandi ? ' servis kaydı faturalandı olarak işaretlendi.' : ' kaydın fatura işareti kaldırıldı.'));
}

if ($method === 'POST' && ($_GET['action'] ?? '') === 'aktarim_durumu') {
    verifyCsrf();
    $body = govdeOku();
    $tablo = ['servis' => 'teknik_servis', 'hasar' => 'hasar_faturalari'][$body['tur'] ?? ''] ?? 'kiralamalar';
    $ids   = array_values(array_filter(array_map('intval', $body['ids'] ?? []), fn($x) => $x > 0));
    $durum = in_array($body['durum'] ?? '', ['aktarim_bekliyor', 'aktarildi', 'hata'], true) ? $body['durum'] : null;
    if (!$ids) jsonError('Kayıt seçilmedi.');
    if (!$durum) jsonError('Geçersiz aktarım durumu.');
    $hataNotu = trim(metin($body['hata_notu'] ?? ''));
    if ($durum === 'hata' && $hataNotu === '') jsonError('Hata notu zorunludur.');

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $where = ["id IN ($ph)", 'faturalandi=1'];
    $params = $ids;
    if ($tablo !== 'teknik_servis') subeKosulEkle($where, $params, 'sube');
    else {
        [$_svKosul, $_svPar] = servisFaturaSubeKosulu('1=1');
        $where[] = "id IN ($_svKosul)"; $params = array_merge($params, $_svPar);
    }
    $st = $db->prepare("UPDATE $tablo SET fatura_durum=?, fatura_hata_notu=?, updated_at=datetime('now','localtime') WHERE " . implode(' AND ', $where));
    $st->execute(array_merge([$durum, $durum === 'hata' ? $hataNotu : null], $params));
    $n = $st->rowCount();
    if ($n === 0) jsonError('Seçilen kayıtlarda faturalandı işaretli (fatura kesilmiş) kayıt bulunamadı.');
    logla($db, $tablo, null, 'guncelle', null, null, $n . ' kaydın fatura aktarım durumu: ' . $durum . ($hataNotu ? " ($hataNotu)" : ''));
    jsonOk(['adet' => $n], $n . ' kayıt güncellendi.');
}

if ($method === 'GET') {
    $durum = trim(metin($_GET['durum'] ?? 'bekleyen'));
    $fatKos = $durum === 'faturalandi' ? 'k.faturalandi=1' : '(k.faturalandi IS NULL OR k.faturalandi=0)';

    $where = [$fatKos, "k.durum<>'iptal'"];
    $params = [];
    if (($q = trim(metin($_GET['q'] ?? ''))) !== '') {
        $where[] = "(" . trAramaSql('m.unvan') . " LIKE ? OR " . trAramaSql('k.kod') . " LIKE ? OR " . trAramaSql('k.ekipman_adi') . " LIKE ?)";
        $like = '%' . trArama($q) . '%'; array_push($params, $like, $like, $like);
    }
    if (($sb = trim(metin($_GET['sube'] ?? ''))) !== '') { $where[] = "k.sube=?"; $params[] = $sb; }
    subeKosulEkle($where, $params, 'k.sube');
    if (!empty($_GET['sadece_tamamlanan'])) $where[] = "k.durum='tamamlandi'";

    $sql = "SELECT k.*, m.unvan AS musteri_unvan, m.vergi_dairesi, m.vergi_no, m.telefon AS musteri_telefon
            FROM kiralamalar k JOIN musteriler m ON m.id=k.musteri_id
            WHERE " . implode(' AND ', $where) . " ORDER BY k.faturalandi, COALESCE(k.gercek_bitis,k.bitis,k.baslangic) DESC, k.id DESC";
    if (!empty($_GET['ids_only'])) {
        $iSt = $db->prepare("SELECT k.id FROM kiralamalar k JOIN musteriler m ON m.id=k.musteri_id WHERE "
                            . implode(' AND ', $where));
        $iSt->execute($params);
        jsonOk(['ids' => array_map('intval', array_column($iSt->fetchAll(), 'id'))]);
    }

    $st = $db->prepare($sql); $st->execute($params);
    $rows = $st->fetchAll();

    $toplam = ['ara' => 0.0, 'kdv' => 0.0, 'genel' => 0.0, 'adet' => 0, 'doviz' => []];
    foreach ($rows as $r0) {
        $h = kiralamaTutar($r0);
        $_p = dovizPara($r0['doviz'] ?? 'TL');
        if ($_p !== 'TL') { $toplam['doviz'][$_p] = round(($toplam['doviz'][$_p] ?? 0) + $h['genel_toplam'], 2); $toplam['adet']++; continue; }
        $toplam['ara'] += $h['ara_toplam']; $toplam['kdv'] += $h['kdv']; $toplam['genel'] += $h['genel_toplam']; $toplam['adet']++;
    }
    foreach (['ara','kdv','genel'] as $k) $toplam[$k] = round($toplam[$k], 2);

    $sayfalama = null;
    if (isset($_GET['page'])) {
        $perPage = min(200, max(10, (int)($_GET['per_page'] ?? 50)));
        $sayfaSayisi = max(1, (int)ceil(count($rows) / $perPage));
        $sayfa = min(sayfaNo(), $sayfaSayisi);
        $sayfalama = ['sayfa' => $sayfa, 'sayfa_sayisi' => $sayfaSayisi, 'toplam' => count($rows), 'per_page' => $perPage];
        $rows = array_slice($rows, ($sayfa - 1) * $perPage, $perPage);
    }

    $_ayri = kiralamaAyriFaturaliHakedisler($db, array_column($rows, 'id'));
    foreach ($rows as &$r) { $r['hesap'] = kiralamaTutar($r) + ['para_birimi' => dovizPara($r['doviz'] ?? 'TL')]; $r['ayri_faturali_hakedis'] = $_ayri[(int)$r['id']] ?? []; }
    unset($r);
    jsonOk(['list' => $rows, 'toplam' => $toplam, 'sayfalama' => $sayfalama]);
}

elseif ($method === 'POST') {
    verifyCsrf();
    $body = govdeOku();
    $ids  = $body['ids'] ?? [];
    if (!is_array($ids) || !$ids) jsonError('Kayıt seçilmedi.');
    $ids = array_values(array_filter(array_map('intval', $ids), fn($x) => $x > 0));
    if (!$ids) jsonError('Geçersiz kayıtlar.');

    $faturalandi = isset($body['faturalandi']) ? (int)(bool)$body['faturalandi'] : 1;
    $ph = implode(',', array_fill(0, count($ids), '?'));

    $where  = ["id IN ($ph)"];
    $params = $ids;
    subeKosulEkle($where, $params, 'sube');
    $kosul = implode(' AND ', $where);

    $db->beginTransaction();
    try {
        $sec = $db->prepare("SELECT id, kod, fatura_no, doviz FROM kiralamalar WHERE $kosul");
        $sec->execute($params);
        $kiralar = $sec->fetchAll();
        $kIds = array_map('intval', array_column($kiralar, 'id'));

        if ($faturalandi) {
            $fno = trim(metin($body['fatura_no'] ?? '')) ?: null;
            $ft  = dateStr('fatura_tarihi', $body) ?: date('Y-m-d');
            $ftip = ($body['fatura_tipi'] ?? 'resmi') === 'elden' ? 'elden' : 'resmi';
            foreach ($kiralar as $_kr) {
                $_p = dovizPara($_kr['doviz'] ?? 'TL');
                if ($_p !== 'TL' && dovizKuru($db, $_p, $ft) === null) { $db->rollBack(); jsonError(($_kr['kod'] ?: '#' . $_kr['id']) . ': ' . $_p . ' kiralama; fatura günü ' . tarihGoster($ft) . ' TCMB döviz satış kuru bulunamadı. Kuru Filo Referans › Tarihli döviz kurları bölümüne girip yeniden deneyin; döviz iş kursuz faturalanmaz.', 409); }
            }
            $st = $db->prepare("UPDATE kiralamalar SET faturalandi=1, fatura_no=?, fatura_tarihi=?, fatura_tipi=?, fatura_durum='kesildi', updated_at=datetime('now','localtime') WHERE $kosul");
            $st->execute(array_merge([$fno, $ft, $ftip], $params));
            $n = $st->rowCount();
            $hn = 0;
            if ($kIds) {
                $kPh = implode(',', array_fill(0, count($kIds), '?'));
                $_hs = $db->prepare("SELECT id FROM hakedisler WHERE kiralama_id IN ($kPh) AND durum='beklemede' AND COALESCE(fatura_no,'')=''");
                $_hs->execute($kIds);
                $_hIds = array_map('intval', $_hs->fetchAll(PDO::FETCH_COLUMN));
                $hSt = $db->prepare("UPDATE hakedisler SET durum='faturalandi', fatura_no=?, fatura_tarihi=?, fatura_kaynak='kiralama'
                                     WHERE kiralama_id IN ($kPh) AND durum='beklemede' AND COALESCE(fatura_no,'')=''");
                $hSt->execute(array_merge([$fno, $ft], $kIds));
                $hn = $hSt->rowCount();
                foreach ($_hIds as $_hid) hakedisFaturaKuruUygula($db, $_hid, $ft);
            }
            logla($db, 'kiralamalar', null, 'guncelle', null, null,
                  $n . ' kiralama FATURALANDI işaretlendi' . ($hn ? ' · ' . $hn . ' hakediş faturalandı (cari borç doğdu)' : '')
                  . ($fno ? ' · fatura no: ' . $fno : ''));
            $db->commit();
            jsonOk(['adet' => $n, 'hakedis_adet' => $hn], $n . ' kiralama, ' . $hn . ' hakediş faturalandı olarak işaretlendi.');
        }
        $hn = 0;
        if ($kIds) {
            $hSt = $db->prepare("UPDATE hakedisler SET durum='beklemede', fatura_no=NULL, fatura_kaynak=NULL, kur_kesin=0
                                 WHERE id IN (SELECT h.id FROM hakedisler h JOIN kiralamalar k ON k.id=h.kiralama_id
                                              WHERE k.id=? AND h.durum='faturalandi' AND " . hakedisKiralamaFaturasindanSql('h', 'k') . ")");
            foreach ($kIds as $kid) { $hSt->execute([$kid]); $hn += $hSt->rowCount(); }
        }
        $st = $db->prepare("UPDATE kiralamalar SET faturalandi=0, fatura_no=NULL, fatura_tarihi=NULL, fatura_durum=NULL, updated_at=datetime('now','localtime') WHERE $kosul");
        $st->execute($params);
        $n = $st->rowCount();
        logla($db, 'kiralamalar', null, 'guncelle', null, null,
              $n . ' kiralamanın FATURA İŞARETİ KALDIRILDI (cari borç düşer)' . ($hn ? ' · ' . $hn . ' hakediş beklemeye döndü' : ''));
        $db->commit();
        jsonOk(['adet' => $n, 'hakedis_adet' => $hn], $n . ' kiralamanın, ' . $hn . ' hakedişin fatura işareti kaldırıldı.');
    } catch (DomainException $e) {
        if ($db->inTransaction()) $db->rollBack();
        jsonError($e->getMessage(), 409);
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

else jsonError('Method Not Allowed', 405);
