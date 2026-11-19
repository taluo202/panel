<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/zincir-lib.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('kiralama_takip');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$db     = getDb();

function _hKiralamaDogrula(PDO $db, ?int $kiralamaId, int $musteriId): void {
    if (!$kiralamaId) return;
    $st = $db->prepare("SELECT musteri_id, sube FROM kiralamalar WHERE id=?");
    $st->execute([$kiralamaId]);
    $k = $st->fetch();
    if (!$k) jsonError('Kiralama bulunamadı.', 404);
    if ((int)$k['musteri_id'] !== $musteriId) jsonError('Bu kiralama başka bir müşteriye ait; hakediş ona bağlanamaz.');
    subeErisimDogrula($k['sube'] ?? null);
}

function _hDurum($v): string { return in_array($v, ['beklemede','faturalandi','tahsil_edildi'], true) ? $v : 'beklemede'; }

function _hTahsilDogrula(PDO $db, int $id, string $yeniDurum): void {
    if ($yeniDurum !== 'tahsil_edildi' || $id < 1) return;
    $st = $db->prepare("SELECT genel_toplam FROM hakedisler WHERE id=?");
    $st->execute([$id]);
    $toplam = (float)($st->fetchColumn() ?: 0);
    if ($toplam <= 0) return;
    $odenen = hakedisOdenen($db, $id);
    if ($odenen + 0.01 < $toplam) {
        jsonError(sprintf(
            'Tahsil edildi işaretlenemez: girilmiş tahsilat %s ₺, hakediş tutarı %s ₺. Önce tahsilatı girin.',
            sayiTR($odenen), sayiTR($toplam)));
    }
}

function _hTevkifatOraniGecerli($s): ?string { return hakedisTevkifatOraniGecerli($s); }
function _hHesapla(float $tutar, float $kdvOran, bool $tevkifatVar, $tevkifatOraniGirdi): array {
    return hakedisTutarHesapla($tutar, $kdvOran, $tevkifatVar, $tevkifatOraniGirdi);
}

if ($method === 'GET' && $action === 'oneri') {
    $kid = (int)($_GET['kiralama_id'] ?? 0);
    if ($kid < 1) jsonError('Kiralama seçilmedi.');
    $st = $db->prepare("SELECT k.*, m.unvan AS musteri_unvan FROM kiralamalar k JOIN musteriler m ON m.id=k.musteri_id WHERE k.id=?");
    $st->execute([$kid]);
    $k = $st->fetch();
    if (!$k) jsonError('Kiralama bulunamadı.', 404);
    subeErisimDogrula($k['sube'] ?? null);
    $h = kiralamaTutar($k);
    jsonOk([
        'musteri_id'  => (int)$k['musteri_id'],
        'musteri_unvan' => $k['musteri_unvan'],
        'donem_bas'   => $k['baslangic'],
        'donem_bit'   => $k['gercek_bitis'] ?: ($k['bitis'] ?: date('Y-m-d')),
        'aciklama'    => $k['ekipman_adi'] . ' kira bedeli',
        'tutar'       => $h['ara_toplam'],
        'kdv_oran'    => (float)$k['kdv_oran'],
        'para_birimi' => dovizPara($k['doviz'] ?? 'TL'),
    ]);
}

if ($method === 'POST' && $action === 'toplu_uret') {
    verifyCsrf();
    $body = govdeOku();
    $ay   = trim(metin($body['ay'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $ay)) jsonError('Geçerli bir ay (YYYY-MM) seçin.');

    $ayBas = $ay . '-01';
    $ayBit = date('Y-m-t', strtotime($ayBas));
    $unitDays = KIRA_BIRIM_GUN;

    $_tw = []; $_tp = [];
    subeKosulEkle($_tw, $_tp, 'sube');
    $st = $db->prepare("SELECT * FROM kiralamalar WHERE durum IN ('aktif','tamamlandi')" . ($_tw ? ' AND ' . implode(' AND ', $_tw) : ''));
    $st->execute($_tp);
    $olusturulan = 0; $atlanan = 0;

    $ins = $db->prepare("
        INSERT INTO hakedisler
          (kiralama_id, musteri_id, donem_bas, donem_bit, aciklama, tutar, kdv_oran, kdv_tutar, genel_toplam, fatura_tarihi, vade_tarihi, sube, temsilci, durum)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, 'beklemede')
    ");

    $uyarilar = [];
    $db->beginTransaction();
    try {
    foreach ($st->fetchAll() as $k) {
        $kesisim = kiralamaDonemKesisimi($k, $ayBas, $ayBit);
        if ($kesisim === null) continue;

        $bosluklar = hakedisBosAraliklar($db, (int)$k['id'], $kesisim['dBas'], $kesisim['dBit']);
        if (!$bosluklar) { $atlanan++; continue; }

        foreach ($bosluklar as [$dBas, $dBit, $gun]) {
        $hesap = hakedisKalemleriHesapla($db, $k, $dBas, $dBit);
        $tutar = $hesap['tutar'];
        if ($tutar <= 0) continue;

        $kdvOran = (float)$k['kdv_oran'];
        $dvz = hakedisDovizHesap($db, $k, $tutar, $kdvOran, min($ayBit, date('Y-m-d')));
        $vade  = date('Y-m-d', strtotime($ayBit . ' +15 days'));

        $ins->execute([
            (int)$k['id'], (int)$k['musteri_id'], $dBas, $dBit,
            $k['ekipman_adi'] . ' — ' . $ay . ' dönemi',
            $dvz['tutar'], $kdvOran, $dvz['kdv_tutar'], $dvz['genel_toplam'], null, $vade,
            $k['sube'] ?? null, $k['temsilci'] ?? null,
        ]);
        $hid = (int)$db->lastInsertId();
        hakedisDovizYaz($db, $hid, $dvz);
        if ($dvz['uyari']) $uyarilar[] = ($k['kod'] ?? ('#' . $k['id'])) . ': ' . $dvz['uyari'];
        hakedisKodUret($db, $hid, $ayBit);
        hakedisKalemleriYaz($db, $hid, $hesap);
        foreach ($hesap['uyarilar'] as $u) $uyarilar[] = ($k['kod'] ?? ('#' . $k['id'])) . ': ' . $u;
        $olusturulan++;
        }
    }
    $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    jsonOk(['olusturulan'=>$olusturulan, 'atlanan'=>$atlanan, 'uyarilar'=>$uyarilar],
        $olusturulan . ' hakediş oluşturuldu' . ($atlanan ? " ($atlanan zaten vardı, atlandı)" : '') . '.'
        . ($uyarilar ? ' ' . count($uyarilar) . ' uyarı var.' : ''));
}

switch ($method) {

    case 'GET':
        if (isset($_GET['id'])) {
            $id = (int)$_GET['id'];
            $stmt = $db->prepare("
                SELECT h.*, m.unvan AS musteri_unvan, m.yetkili, m.adres AS musteri_adres,
                       m.vergi_dairesi, m.vergi_no, k.kod AS kiralama_kod, k.ekipman_adi
                FROM hakedisler h
                JOIN musteriler m ON m.id = h.musteri_id
                LEFT JOIN kiralamalar k ON k.id = h.kiralama_id
                WHERE h.id=?
            ");
            $stmt->execute([$id]);
            $h = $stmt->fetch();
            if (!$h) jsonError('Hakediş bulunamadı.', 404);
            subeErisimDogrula(hakedisEtkinSube($db, $id));
            $odenen = hakedisOdenenToplu($db, [$id])[$id] ?? 0.0;
            $h['odenen'] = round((float)$odenen, 2);
            $h['kalan']  = round((float)($h['genel_toplam'] ?? 0) - (float)$odenen, 2);
            $tSt = $db->prepare("SELECT id, tarih, tutar, yontem, aciklama FROM tahsilatlar WHERE hakedis_id=? ORDER BY tarih DESC, id DESC");
            $tSt->execute([$id]);
            $h['tahsilatlar'] = $tSt->fetchAll();
            $kSt = $db->prepare("SELECT id, tur, aciklama, miktar, birim, tutar, nakliye_id, uyari FROM hakedis_kalemleri WHERE hakedis_id=? ORDER BY id");
            $kSt->execute([$id]);
            $h['kalemler'] = $kSt->fetchAll();
            $h['makine_kunye'] = hakedisMakineKunye($db, $h['kiralama_id'] ?? 0);
            if (!empty($_GET['belge'])) {
                require_once __DIR__ . '/tutanak-yaz.php';
                $h['firma_imza'] = firmaImzasi($db);
            }
            jsonOk($h);
        }

        $where = []; $params = [];
        if (($kid = (int)($_GET['kiralama_id'] ?? 0)) > 0) { $where[] = "h.kiralama_id=?"; $params[] = $kid; }
        if (($mid = (int)($_GET['musteri_id'] ?? 0)) > 0) { $where[] = "h.musteri_id=?"; $params[] = $mid; }
        $durumSuz = trim(metin($_GET['durum'] ?? ''));
        if (($sb = trim(metin($_GET['sube'] ?? ''))) !== '')     { $where[] = "h.sube=?";     $params[] = $sb; }
        subeKosulEkle($where, $params, hakedisSubeSql('h', 'm'));
        if (($tm = trim(metin($_GET['temsilci'] ?? ''))) !== '') { $where[] = "h.temsilci=?"; $params[] = $tm; }
        if (($q = trim(metin($_GET['q'] ?? ''))) !== '') {
            $where[] = "(" . trAramaSql('h.kod') . " LIKE ? OR " . trAramaSql('h.fatura_no') . " LIKE ? OR " . trAramaSql('m.unvan') . " LIKE ?)"; $like='%' . trArama($q) . '%'; array_push($params,$like,$like,$like);
        }
        $sql = "SELECT h.*, m.unvan AS musteri_unvan, k.kod AS kiralama_kod
                FROM hakedisler h
                JOIN musteriler m ON m.id = h.musteri_id
                LEFT JOIN kiralamalar k ON k.id = h.kiralama_id";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY h.fatura_tarihi DESC, h.id DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $today = date('Y-m-d');
        $odenenMap = hakedisOdenenToplu($db, array_map('intval', array_column($rows, 'id')));
        $ozet = ['beklemede'=>0,'faturalandi'=>0,'tahsil_edildi'=>0,'toplam'=>0.0,'geciken_adet'=>0,'geciken_tutar'=>0.0,
                 'fatura_bekleyen_adet'=>0,'fatura_bekleyen_tutar'=>0.0];
        $_fifoKalan = alacakOzeti($db)['hakedis_kalan'];
        foreach ($rows as &$r) {
            $odenen = $odenenMap[(int)$r['id']] ?? 0.0;
            $r['odenen'] = $odenen;
            $r['kalan']  = round((float)$r['genel_toplam'] - $odenen, 2);
            $vadeGecti = !empty($r['vade_tarihi']) && $r['vade_tarihi'] < $today;
            $faturali  = in_array($r['durum'], ['faturalandi', 'tahsil_edildi'], true) || trim((string)($r['fatura_no'] ?? '')) !== '';
            $r['geciken'] = ($faturali && $vadeGecti && isset($_fifoKalan[(int)$r['id']])) ? 1 : 0;
            $r['fatura_bekliyor'] = (!$faturali && $vadeGecti && (float)$r['genel_toplam'] > 0) ? 1 : 0;
            $ozet[$r['durum']] = ($ozet[$r['durum']] ?? 0) + 1;
            $ozet['toplam'] += (float)$r['genel_toplam'];
            if ($r['geciken']) { $ozet['geciken_adet']++; $ozet['geciken_tutar'] += $_fifoKalan[(int)$r['id']]; }
            if ($r['fatura_bekliyor']) { $ozet['fatura_bekleyen_adet']++; $ozet['fatura_bekleyen_tutar'] += (float)$r['genel_toplam']; }
        }
        unset($r);
        $ozet['toplam'] = round($ozet['toplam'], 2);
        $ozet['geciken_tutar'] = round($ozet['geciken_tutar'], 2);
        $ozet['fatura_bekleyen_tutar'] = round($ozet['fatura_bekleyen_tutar'], 2);

        if ($durumSuz !== '' && $durumSuz !== 'hepsi') {
            $rows = array_values(array_filter($rows, fn($r0) =>
                $durumSuz === 'geciken' ? (int)$r0['geciken'] === 1 : $r0['durum'] === $durumSuz));
        }

        $sayfalama = null;
        if (isset($_GET['page'])) {
            $perPage = min(200, max(10, (int)($_GET['per_page'] ?? 50)));
            $sayfaSayisi = max(1, (int)ceil(count($rows) / $perPage));
            $sayfa = min(sayfaNo(), $sayfaSayisi);
            $sayfalama = ['sayfa' => $sayfa, 'sayfa_sayisi' => $sayfaSayisi, 'toplam' => count($rows), 'per_page' => $perPage];
            $rows = array_slice($rows, ($sayfa - 1) * $perPage, $perPage);
        }
        jsonOk(['list' => $rows, 'ozet' => $ozet, 'sayfalama' => $sayfalama]);
        break;

    case 'POST':
        verifyCsrf();
        $body      = govdeOku();
        $musteriId = (int)($body['musteri_id'] ?? 0);
        finansMusteriDogrula($db, $musteriId);
        $tutar     = max(0, (float)($body['tutar'] ?? 0));
        $kdvOran   = max(0, (float)($body['kdv_oran'] ?? 20));
        if ($musteriId < 1) jsonError('Müşteri zorunludur.');
        if ($tutar <= 0)    jsonError('Tutar 0\'dan büyük olmalıdır.');
        if ($_dh = hakedisDonemHatasi($body)) jsonError($_dh);
        _hKiralamaDogrula($db, ((int)($body['kiralama_id'] ?? 0)) ?: null, $musteriId);
        if (_hDurum($body['durum'] ?? 'beklemede') === 'tahsil_edildi') {
            jsonError('Yeni hakediş doğrudan "tahsil edildi" olarak açılamaz: önce kaydı oluşturup tahsilatı girin, durum kendiliğinden kapanır.');
        }

        $mChk = $db->prepare("SELECT COUNT(*) FROM musteriler WHERE id=?");
        $mChk->execute([$musteriId]);
        if ((int)$mChk->fetchColumn() === 0) jsonError('Müşteri bulunamadı.');

        $h = _hHesapla($tutar, $kdvOran, !empty($body['tevkifat_var']), $body['tevkifat_orani'] ?? null);
        $faturaTarihi = dateStr('fatura_tarihi', $body) ?: date('Y-m-d');
        $mt = musteriSubeTemsilci($db, $musteriId);
        $_dvzK = null;
        if (($_pk = ((int)($body['kiralama_id'] ?? 0))) > 0) {
            $_pq = $db->prepare('SELECT * FROM kiralamalar WHERE id=?'); $_pq->execute([$_pk]);
            $_dvzK = $_pq->fetch() ?: null;
            if ($_dvzK && dovizPara($_dvzK['doviz'] ?? 'TL') === 'TL') $_dvzK = null;
        }
        $_yeniFaturali = _hDurum($body['durum'] ?? 'beklemede') !== 'beklemede' || trim(metin($body['fatura_no'] ?? '')) !== '';
        $_dvzUyari = '';

        $tutanakId = (int)($body['tutanak_id'] ?? 0);
        if ($tutanakId > 0) {
            $tChk = $db->prepare("SELECT COUNT(*) FROM iade_tutanaklari WHERE id=?");
            $tChk->execute([$tutanakId]);
            if ((int)$tChk->fetchColumn() === 0) $tutanakId = 0;
        }

        [$id, $kod] = islemIcinde($db, function (PDO $db) use ($body, $musteriId, $tutar, $kdvOran, $h, $faturaTarihi, $mt, $tutanakId, $_dvzK, $_yeniFaturali, &$_dvzUyari): array {
        $db->prepare("
            INSERT INTO hakedisler
              (kiralama_id, musteri_id, donem_bas, donem_bit, aciklama, tutar, kdv_oran, kdv_tutar, genel_toplam, fatura_no, fatura_tarihi, vade_tarihi, durum, sube, temsilci,
               tevkifat_var, tevkifat_orani, tevkifat_tutari, musteriden_tahsil_kdv, tutanak_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, ?,?,?,?,?)
        ")->execute([
            ((int)($body['kiralama_id'] ?? 0)) ?: null, $musteriId,
            dateStr('donem_bas', $body), dateStr('donem_bit', $body),
            trim(metin($body['aciklama'] ?? '')) ?: null,
            $tutar, $kdvOran, $h['kdv_tutar'], $h['genel_toplam'],
            trim(metin($body['fatura_no'] ?? '')) ?: null, $faturaTarihi,
            dateStr('vade_tarihi', $body),
            _hDurum($body['durum'] ?? 'beklemede'),
            $mt['sube'], $mt['temsilci'],
            $h['tevkifat_var'], $h['tevkifat_orani'], $h['tevkifat_tutari'], $h['musteriden_tahsil_kdv'],
            $tutanakId ?: null,
        ]);
        $id  = (int)$db->lastInsertId();
        if ($_dvzK) {
            hakedisDovizYaz($db, $id, hakedisDovizHesap($db, $_dvzK, $tutar, $kdvOran, min($faturaTarihi, date('Y-m-d'))));
            if ($_yeniFaturali) { try { hakedisFaturaKuruUygula($db, $id, $faturaTarihi); } catch (DomainException $e) { $_dvzUyari = ' Dikkat: ' . $e->getMessage() . ' Kur girilene kadar TL karşılığı 0 ₺ görünür.'; } }
        }
        logla($db, 'hakedisler', $id, 'ekle', null, logKayitAl($db, 'hakedisler', $id),
              'Hakediş eklendi · ' . number_format($h['genel_toplam'] ?? 0, 2, ',', '.') . ($_dvzK ? ' ' . dovizPara($_dvzK['doviz']) : ' ₺'));
        $kod = hakedisKodUret($db, $id, $faturaTarihi);
        return [$id, $kod];
        });
        jsonOk(['id' => $id, 'kod' => $kod], 'Hakediş kaydedildi.' . $_dvzUyari);

    case 'PUT':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $body = govdeOku();
        $cur = $db->prepare("SELECT * FROM hakedisler WHERE id=?");
        $cur->execute([$id]);
        $mevcut = $cur->fetch();
        if (!$mevcut) jsonError('Hakediş bulunamadı.', 404);
        subeErisimDogrula(hakedisEtkinSube($db, $id));
        if (isset($body['musteri_id'])) finansMusteriDogrula($db, (int)$body['musteri_id']);
        if (!(count($body) === 1 && isset($body['durum']))) {
            $_yk = ((int)($body['kiralama_id'] ?? 0)) ?: null;
            if ($_yk !== (((int)($mevcut['kiralama_id'] ?? 0)) ?: null)) _hKiralamaDogrula($db, $_yk, (int)$mevcut['musteri_id']);
        }

        $_dvzMi = hakedisDovizMi($mevcut);
        $_eskiFaturali = in_array($mevcut['durum'], ['faturalandi', 'tahsil_edildi'], true) || trim((string)($mevcut['fatura_no'] ?? '')) !== '';
        $_dvzUygula = function (bool $yeniFaturali, string $ft) use ($db, $id, $mevcut, $_dvzMi, $_eskiFaturali): string {
            if (!$_dvzMi) return '';
            if (!$yeniFaturali) { if ($_eskiFaturali) $db->prepare('UPDATE hakedisler SET kur_kesin=0 WHERE id=?')->execute([$id]); return ''; }
            if ($_eskiFaturali && (int)($mevcut['kur_kesin'] ?? 0) === 1 && (string)($mevcut['kur_tarihi'] ?? '') === $ft) return '';
            $db->prepare('UPDATE hakedisler SET kur_kesin=0 WHERE id=?')->execute([$id]);
            try { hakedisFaturaKuruUygula($db, $id, $ft); return ''; }
            catch (DomainException $e) { return ' Dikkat: ' . $e->getMessage() . ' Kur girilene kadar TL karşılığı 0 ₺ görünür.'; }
        };

        if (count($body) === 1 && isset($body['durum'])) {
            _hTahsilDogrula($db, $id, _hDurum($body['durum']));
            $_yf = _hDurum($body['durum']) !== 'beklemede' || trim((string)($mevcut['fatura_no'] ?? '')) !== '';
            $_ft = (string)($mevcut['fatura_tarihi'] ?: date('Y-m-d'));
            $_eski = logKayitAl($db, 'hakedisler', $id);
            $db->prepare("UPDATE hakedisler SET durum=?, fatura_tarihi=? WHERE id=?")->execute([_hDurum($body['durum']), $_yf ? $_ft : $mevcut['fatura_tarihi'], $id]);
            $_dvzUyari = $_dvzUygula($_yf, $_ft);
            logla($db, 'hakedisler', $id, 'guncelle', $_eski, logKayitAl($db, 'hakedisler', $id),
                  'Hakediş durumu: ' . ($_eski['durum'] ?? '?') . ' → ' . _hDurum($body['durum']));
            jsonOk(null, 'Durum güncellendi.' . $_dvzUyari);
        }

        $tutar   = $_dvzMi ? (float)$mevcut['tutar'] : max(0, (float)($body['tutar'] ?? 0));
        $kdvOran = max(0, (float)($body['kdv_oran'] ?? 20));
        if ($tutar <= 0 && !$_dvzMi) jsonError('Tutar 0\'dan büyük olmalıdır.');
        if ($_dh = hakedisDonemHatasi($body)) jsonError($_dh);
        $h = _hHesapla($tutar, $kdvOran, !empty($body['tevkifat_var']), $body['tevkifat_orani'] ?? null);

        $revizyonNedeni = trim(metin($body['revizyon_nedeni'] ?? ''));
        $tutarDegisti = abs((float)$mevcut['tutar'] - $tutar) > 0.005 || abs((float)$mevcut['kdv_oran'] - $kdvOran) > 0.005;
        if (!empty($mevcut['fatura_no']) && $tutarDegisti && $revizyonNedeni === '') {
            jsonError('Faturalanmış (fatura no girilmiş) hakedişin tutarı ancak revizyon gerekçesiyle değiştirilebilir.');
        }

        if (_hDurum($body['durum'] ?? 'beklemede') === 'tahsil_edildi'
            && hakedisOdenen($db, $id) + 0.01 < (float)$h['genel_toplam']) {
            jsonError(sprintf(
                'Tahsil edildi işaretlenemez: girilmiş tahsilat %s ₺, hakediş tutarı %s ₺. Önce tahsilatı girin.',
                sayiTR(hakedisOdenen($db, $id)), sayiTR((float)$h['genel_toplam'])));
        }

        $_yf = _hDurum($body['durum'] ?? 'beklemede') !== 'beklemede' || trim(metin($body['fatura_no'] ?? '')) !== '';
        $_ft = dateStr('fatura_tarihi', $body) ?: date('Y-m-d');
        $_eski = logKayitAl($db, 'hakedisler', $id);

        $db->prepare("
            UPDATE hakedisler SET kiralama_id=?, donem_bas=?, donem_bit=?, aciklama=?,
                tutar=?, kdv_oran=?, kdv_tutar=?, genel_toplam=?, fatura_no=?, fatura_tarihi=?, vade_tarihi=?, durum=?,
                tevkifat_var=?, tevkifat_orani=?, tevkifat_tutari=?, musteriden_tahsil_kdv=?,
                revizyon_nedeni=?, revizyon_tarihi=?
            WHERE id=?
        ")->execute([
            ((int)($body['kiralama_id'] ?? 0)) ?: null,
            dateStr('donem_bas', $body), dateStr('donem_bit', $body),
            trim(metin($body['aciklama'] ?? '')) ?: null,
            $tutar, $kdvOran, $h['kdv_tutar'], $h['genel_toplam'],
            trim(metin($body['fatura_no'] ?? '')) ?: null,
            dateStr('fatura_tarihi', $body) ?: date('Y-m-d'),
            dateStr('vade_tarihi', $body),
            _hDurum($body['durum'] ?? 'beklemede'),
            $h['tevkifat_var'], $h['tevkifat_orani'], $h['tevkifat_tutari'], $h['musteriden_tahsil_kdv'],
            $tutarDegisti && $revizyonNedeni !== '' ? $revizyonNedeni : ($mevcut['revizyon_nedeni'] ?? null),
            $tutarDegisti && $revizyonNedeni !== '' ? date('Y-m-d') : ($mevcut['revizyon_tarihi'] ?? null),
            $id,
        ]);
        $_dvzUyari = $_dvzUygula($_yf, $_ft);
        logla($db, 'hakedisler', $id, 'guncelle', $_eski, logKayitAl($db, 'hakedisler', $id),
              'Hakediş ' . ($_eski['kod'] ?? ('#' . $id)) . ' güncellendi'
              . ($revizyonNedeni !== '' ? ' · REVİZYON: ' . $revizyonNedeni : ''));
        jsonOk(null, 'Hakediş güncellendi.' . $_dvzUyari);

    case 'DELETE':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $_eski = logKayitAl($db, 'hakedisler', $id);
        if (!$_eski) jsonError('Hakediş bulunamadı.', 404);
        subeErisimDogrula(hakedisEtkinSube($db, $id));
        require_once __DIR__ . '/hasar-fiyat-lib.php';
        require_once __DIR__ . '/ariza-karar-lib.php';
        require_once __DIR__ . '/servis-maliyet-lib.php';
        islemYanit($db, function (PDO $db) use ($id): void {
            $_eski = logKayitAl($db, 'hakedisler', $id);
            if (!$_eski) throw new DomainException('Hakediş bulunamadı.', 404);
            $faturaNo = trim((string)($_eski['fatura_no'] ?? ''));
            if (in_array($_eski['durum'] ?? '', ['faturalandi', 'tahsil_edildi'], true) || $faturaNo !== '') {
                throw new DomainException('Bu hakediş faturalandı' . ($faturaNo !== '' ? ' (fatura no ' . $faturaNo . ')' : '') . '; silinemez. Önce faturalandırmayı geri alın.', 409);
            }
            $tq = $db->prepare("SELECT COUNT(*) FROM tahsilatlar WHERE hakedis_id=?");
            $tq->execute([$id]);
            if (($tn = (int)$tq->fetchColumn()) > 0) {
                throw new DomainException('Bu hakedişe bağlı ' . $tn . ' tahsilat var; silinemez. Önce tahsilatları silin ya da başka hakedişe bağlayın.', 409);
            }
            hfHakedisSilindi($db, $id);
            akHakedisSilindi($db, $id);
            smHakedisSilindi($db, $id);
            $db->prepare("UPDATE nakliyeler SET hakedis_id=NULL WHERE hakedis_id=?")->execute([$id]);
            $db->prepare("DELETE FROM hakedis_kalemleri WHERE hakedis_id=?")->execute([$id]);
            $db->prepare("DELETE FROM hakedisler WHERE id=?")->execute([$id]);
            logla($db, 'hakedisler', $id, 'sil', $_eski, null,
                  'Hakediş ' . ($_eski['kod'] ?? ('#' . $id)) . ' SİLİNDİ · ' . number_format((float)($_eski['genel_toplam'] ?? 0), 2, ',', '.') . ' ₺');
        });
        jsonOk(null, 'Hakediş silindi.');

    default:
        jsonError('Method Not Allowed', 405);
}
