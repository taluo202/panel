<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/teklif-cesit-lib.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('kiralama_takip');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$db     = getDb();

function _tTip($v): string { return in_array($v, kiraTipleri(), true) ? $v : 'gunluk'; }
function _tPortalGorunurMu(?array $t): bool {
    return $t && ($t['durum'] ?? '') === 'gonderildi' && in_array($t['onay_durumu'] ?? 'gerekmiyor', ['gerekmiyor', 'onaylandi'], true);
}
function _tPortalBildir(PDO $db, ?array $eski, int $id): void {
    $st = $db->prepare("SELECT id, kod, musteri_id, durum, onay_durumu, gecerlilik, kdv_oran, iskonto_oran FROM teklifler WHERE id=?");
    $st->execute([$id]);
    $t = $st->fetch();
    if (!_tPortalGorunurMu($t) || _tPortalGorunurMu($eski)) return;
    require_once __DIR__ . '/whatsapp.php';
    $k = $db->prepare("SELECT miktar, sure, birim_ucret FROM teklif_kalemleri WHERE teklif_id=?");
    $k->execute([$id]);
    $h = teklifToplam($k->fetchAll(), (float)$t['kdv_oran'], (float)($t['iskonto_oran'] ?? 0));
    waMusteriBildir($db, (int)$t['musteri_id'], null, 'teklif_gonderildi',
        'Size ' . ($t['kod'] ?: 'yeni') . ' numaralı teklifimiz iletildi (genel toplam ' . number_format((float)$h['genel_toplam'], 2, ',', '.') . ' ₺, KDV dahil'
        . ($t['gecerlilik'] ? ', ' . tarihGoster($t['gecerlilik']) . ' tarihine kadar geçerli' : '') . '). Portaldan inceleyip onaylayabilirsiniz: ' . waPortalLink('teklif'));
}

function _tDurum($v): string { return in_array($v, ['taslak','gonderildi','onaylandi','reddedildi','donustu'], true) ? $v : 'taslak'; }


function _kalemler(PDO $db, int $teklifId): array {
    $st = $db->prepare("SELECT * FROM teklif_kalemleri WHERE teklif_id=? ORDER BY id");
    $st->execute([$teklifId]);
    return $st->fetchAll();
}

function _tKalemleriHazirla(PDO $db, array $kalemler): array {
    $hazir = [];
    foreach ($kalemler as $k) {
        if (!is_array($k)) continue;
        if (($k['kalem_turu'] ?? 'serbest') !== 'cesit' && trim((string)($k['aciklama'] ?? '')) === '') continue;
        try { $hazir[] = tcKalemNormalize($db, $k); }
        catch (DomainException $e) { jsonError($e->getMessage()); }
    }
    return $hazir;
}
function _tKalemleriYaz(PDO $db, int $teklifId, array $hazir): void {
    $ki = $db->prepare("INSERT INTO teklif_kalemleri (teklif_id, aciklama, kira_tipi, miktar, sure, birim_ucret, kalem_turu, kategori, metraj) VALUES (?,?,?,?,?,?,?,?,?)");
    foreach ($hazir as $h) {
        $ki->execute([$teklifId, $h['aciklama'], $h['kira_tipi'], $h['miktar'], $h['sure'], $h['birim_ucret'], $h['kalem_turu'], $h['kategori'], $h['metraj']]);
    }
}

if ($method === 'POST' && $action === 'donustur') {
    verifyCsrf();
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) jsonError('Geçersiz ID.');
    $tStmt = $db->prepare("SELECT * FROM teklifler WHERE id=?");
    $tStmt->execute([$id]);
    $teklif = $tStmt->fetch();
    if (!$teklif) jsonError('Teklif bulunamadı.', 404);
    subeErisimDogrula($teklif['sube'] ?? null);
    if ($teklif['durum'] === 'donustu') jsonError('Bu teklif zaten kiralamaya dönüştürülmüş.');
    if (($teklif['onay_durumu'] ?? 'gerekmiyor') === 'bekliyor') jsonError('Bu teklifin iskonto onayı bekleniyor. Dönüştürmeden önce onaylanması gerekir.');
    if (($teklif['onay_durumu'] ?? 'gerekmiyor') === 'reddedildi') jsonError('Bu teklifin iskonto onayı reddedildi. Dönüştürülemez.');

    $kalemler = _kalemler($db, $id);
    if (!$kalemler) jsonError('Teklifte kalem yok, dönüştürülemez.');

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) $body = [];
    $bas      = tcTeklifBaslangic($teklif);
    $bugun    = date('Y-m-d');
    $ids      = [];

    $cozum = tcAtamalariCoz($body['atamalar'] ?? null, $kalemler);
    if ($cozum['hata'] !== null) jsonError($cozum['hata']);
    $refler = [];
    foreach ($cozum['atamalar'] as $liste) foreach ($liste as $a) $refler[] = ['tur' => $a['tur'], 'id' => $a['id']];
    $bilgi = $refler ? makineBilgiToplu($db, $refler) : [];
    $satirlar = []; $cakismalar = []; $eksikler = []; $atananSayisi = 0;
    foreach ($kalemler as $k) {
        $tip = _tTip($k['kira_tipi']);
        $ar = tcAralik($bas, (float)$k['sure'], $tip);
        $ortak = ['kalem' => $k, 'tip' => $tip, 'bas' => $ar['bas'], 'bit' => $ar['bit'], 'tur' => null, 'mid' => null, 'ekipman' => $k['aciklama']];
        if (($k['kalem_turu'] ?? 'serbest') !== 'cesit') {
            $satirlar[] = array_merge($ortak, ['miktar' => max(1, (int)round((float)$k['miktar']))]);
            continue;
        }
        $istenen = max(1, (int)round((float)$k['miktar']));
        $atanan  = $cozum['atamalar'][(int)$k['id']] ?? [];
        if (count($atanan) > $istenen) jsonError('"' . $k['aciklama'] . '" kalemine istenen adetten (' . $istenen . ') fazla makine atandı.');
        foreach ($atanan as $a) {
            $b = $bilgi[$a['tur'] . ':' . $a['id']] ?? null;
            if (!$b) jsonError('Atanan makine bulunamadı (' . $a['tur'] . ' #' . $a['id'] . ').', 404);
            $etiket = (string)($b['kod'] ?: ($a['tur'] . ' #' . $a['id']));
            $kontrol = kiralamaKaydiKontrol($db, $a['tur'], $a['id'], $ar['bas'], $ar['bit']);
            if ($kontrol['engel'] !== null) jsonError($etiket . ': ' . $kontrol['engel'], $kontrol['engel_kod']);
            foreach ($kontrol['cakisma'] as $c) $cakismalar[] = ['makine' => $etiket] + $c;
            $ekipman = makineEkipmanAdi($db, $a['tur'], $a['id']) ?: $k['aciklama'];
            $satirlar[] = array_merge($ortak, ['miktar' => 1, 'tur' => $a['tur'], 'mid' => $a['id'], 'ekipman' => $ekipman, 'etiket' => $etiket]);
            $atananSayisi++;
        }
        $eksik = $istenen - count($atanan);
        if ($eksik > 0) {
            $eksikler[] = ['kalem_id' => (int)$k['id'], 'aciklama' => $k['aciklama'], 'istenen' => $istenen, 'atanan' => count($atanan), 'eksik' => $eksik];
            for ($i = 0; $i < $eksik; $i++) $satirlar[] = array_merge($ortak, ['miktar' => 1, 'ekipman' => ($k['kategori'] ?: $k['aciklama'])]);
        }
    }
    require_once __DIR__ . '/hizli-kiralama-lib.php';
    $cakismalar = hkCakismaMaskele($cakismalar, subeKisiti());
    if ($cakismalar && empty($body['cakisma_onaylandi'])) {
        $ilk = $cakismalar[0];
        jsonError(count($cakismalar) . ' makine seçilen tarihlerde başka bir kiralamada: ' . $ilk['makine'] . ' — ' . ($ilk['kod'] ?: '#') . ' · ' . $ilk['musteri_unvan']
            . ' (' . $ilk['baslangic'] . ' → ' . (!empty($ilk['iade_gecikti']) ? 'planlı iade ' . $ilk['planli_bitis'] . ', makine henüz iade edilmedi' : ($ilk['bitis'] ?: 'açık uç')) . ')'
            . (count($cakismalar) > 1 ? ' ve ' . (count($cakismalar) - 1) . ' kayıt daha' : '') . '. Yine de dönüştürmek için onaylayın.',
            409, ['onaylanabilir' => true, 'cakisma' => $cakismalar]);
    }
    if ($eksikler && empty($body['eksik_onaylandi'])) {
        $eksikToplam = array_sum(array_column($eksikler, 'eksik'));
        jsonError($eksikToplam . ' adede makine atanmadı (' . implode('; ', array_map(fn($e) => $e['aciklama'] . ': ' . $e['atanan'] . '/' . $e['istenen'], $eksikler))
            . '). Onaylarsanız eksik adetler makinesiz kiralama satırı olarak açılır; makine sonra Kiralama Takip\'ten seçilir ya da dış kiralamayla karşılanır.',
            409, ['eksik_onaylanabilir' => true, 'eksik' => $eksikler]);
    }

    $db->beginTransaction();
    try {
        $ins = $db->prepare("
            INSERT INTO kiralamalar
              (musteri_id, ekipman_adi, baslangic, bitis, kira_tipi, birim_ucret, miktar, kdv_oran, sube, temsilci, durum, notlar, iskonto_oran, onay_durumu, onaylayan, onay_tarihi,
               makine_tur, makine_id, manlift_id, metraj, seri_no, makine_kodu)
            VALUES (?,?,?,?,?,?,?,?,?,?, 'aktif', ?, ?, ?, ?, ?, ?,?,?,?,?,?)
        ");
        require_once __DIR__ . '/dis-kiralama-kurallari.php';
        foreach ($satirlar as $s) {
            $k = $s['kalem'];
            $kunye = $s['tur'] ? tcMakineKunye($db, $s['tur'], (int)$s['mid']) : ['metraj' => null, 'seri_no' => null, 'makine_kodu' => null];
            if ($kunye['metraj'] === null && ($k['kalem_turu'] ?? '') === 'cesit' && is_numeric($k['metraj'])) $kunye['metraj'] = (float)$k['metraj'];
            $ins->execute([
                (int)$teklif['musteri_id'], $s['ekipman'], $s['bas'], $s['bit'], $s['tip'],
                (float)$k['birim_ucret'], $s['miktar'],
                (float)$teklif['kdv_oran'],
                $teklif['sube'] ?? null, $teklif['temsilci'] ?? null,
                'Teklif ' . ($teklif['kod'] ?? ('#'.$id)) . ' dönüşümü',
                max(0, min(100, (float)($teklif['iskonto_oran'] ?? 0))),
                $teklif['onay_durumu'] ?? 'gerekmiyor',
                $teklif['onaylayan'] ?? null,
                $teklif['onay_tarihi'] ?? null,
                $s['tur'], $s['mid'], $s['tur'] === 'manlift' ? $s['mid'] : null,
                $kunye['metraj'], $kunye['seri_no'], $kunye['makine_kodu'],
            ]);
            $kid = (int)$db->lastInsertId();
            $kod = 'KR-' . substr($s['bas'],0,4) . '-' . str_pad((string)$kid, 4, '0', STR_PAD_LEFT);
            $db->prepare("UPDATE kiralamalar SET kod=?, kaynak_teklif_id=? WHERE id=?")->execute([$kod, $id, $kid]);
            dkMaliyetKaydet($db, $kid);
            if ($s['tur'] && $s['bas'] >= $bugun) kiralamaMakineDurumu($db, $s['tur'], (int)$s['mid'], 'siparis');
            logla($db, 'kiralamalar', $kid, 'ekle', null, logKayitAl($db, 'kiralamalar', $kid),
                  'Kiralama ' . $kod . ' teklif ' . ($teklif['kod'] ?? ('#'.$id)) . ' dönüşümüyle açıldı'
                  . ($s['tur'] ? ' · makine ' . ($s['etiket'] ?? $s['mid']) : (($k['kalem_turu'] ?? '') === 'cesit' ? ' · MAKİNE ATANMADI' : '')));
            $ids[] = $kid;
        }
        $siparisNo = null;
        if (count($ids) > 1) {
            $siparisNo = 'SP-' . substr($bas, 0, 4) . '-' . str_pad((string)$ids[0], 4, '0', STR_PAD_LEFT);
            $db->prepare("UPDATE kiralamalar SET siparis_no=? WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")")
               ->execute(array_merge([$siparisNo], $ids));
        }
        $db->prepare("UPDATE teklifler SET durum='donustu', kiralama_id=?, updated_at=datetime('now','localtime') WHERE id=?")
           ->execute([$ids[0], $id]);
        $db->prepare("UPDATE gorevler SET durum='tamamlandi', tamamlanma=?, updated_at=datetime('now','localtime')
                      WHERE kaynak_modul='teklif_karar' AND kaynak_id=? AND kaynak='otomatik' AND durum='acik'")
           ->execute([date('Y-m-d'), $id]);
        $eksikToplam = array_sum(array_column($eksikler, 'eksik'));
        logla($db, 'teklifler', $id, 'guncelle', ['durum' => $teklif['durum']], ['durum' => 'donustu'],
              'Teklif ' . ($teklif['kod'] ?? ('#'.$id)) . ' kiralamaya dönüştü: ' . count($ids) . ' satır'
              . ($atananSayisi ? ', ' . $atananSayisi . ' makine atandı' : '') . ($eksikToplam ? ', ' . $eksikToplam . ' adet makinesiz (onaylı)' : '')
              . ($cakismalar ? ', çakışma onaylandı' : ''));
        $db->commit();
    } catch (\Exception $e) {
        $db->rollBack();
        error_log('Taloo: teklif #' . $id . ' donusturulemedi: ' . $e->getMessage());
        jsonError('Dönüştürme sırasında hata oluştu.', 500);
    }
    $eksikToplam = array_sum(array_column($eksikler, 'eksik'));
    jsonOk(['kiralama_ids' => $ids, 'kiralama_sayisi' => count($ids), 'siparis_no' => $siparisNo, 'atanan' => $atananSayisi, 'eksik' => $eksikToplam],
           count($ids) . ' kiralama oluşturuldu' . ($atananSayisi ? ', ' . $atananSayisi . ' makine atandı' : '')
           . ($eksikToplam ? '; ' . $eksikToplam . ' satır makinesiz açıldı (Kiralama Takip → Makine seç)' : '') . '.');
}

if ($method === 'GET' && $action === 'cesit_secenekler') {
    $kategoriler = array_map(fn($k) => ['ad' => $k['ad'], 'sablon' => $k['sablon'], 'birim' => MK_SABLONLAR[$k['sablon']]['ana']['birim'] ?? '',
                                        'olcu_etiket' => MK_SABLONLAR[$k['sablon']]['ana']['etiket'] ?? 'Ölçü'], mkListe($db));
    $olculer = [];
    foreach (tcFilo($db, null, null, subeKisiti()) as $m) {
        $kat = trim((string)($m['katalog_kategori'] ?? ''));
        if ($kat === '' || !is_numeric($m['metraj'] ?? null)) continue;
        $olculer[$kat][(string)(float)$m['metraj']] = (float)$m['metraj'];
    }
    foreach ($olculer as &$o) { $o = array_values($o); sort($o); }
    unset($o);
    jsonOk(['kategoriler' => $kategoriler, 'olculer' => $olculer]);
}

if ($method === 'GET' && ($action === 'musait_adet' || $action === 'musait_makineler')) {
    $kategori = trim(metin($_GET['kategori'] ?? ''));
    if ($kategori === '') jsonError('Kategori seçilmelidir.');
    $metraj = metrajSayi($_GET['metraj'] ?? null);
    $bas = dateStr('baslangic', $_GET) ?: date('Y-m-d');
    $bit = dateStr('bitis', $_GET);
    if (!$bit) {
        $sure = (float)($_GET['sure'] ?? 1);
        if ($sure <= 0) $sure = 1;
        $bit = tcAralik($bas, $sure, _tTip($_GET['kira_tipi'] ?? 'gunluk'))['bit'];
    }
    if ($bit < $bas) jsonError('Bitiş tarihi başlangıçtan önce olamaz.');
    $sonuc = tcMusaitAdet($db, $kategori, $metraj, $bas, $bit, (int)($_GET['haric_teklif_id'] ?? 0), subeKisiti());
    if ($action === 'musait_adet') unset($sonuc['makineler']);
    jsonOk($sonuc);
}

if ($method === 'GET' && $action === 'eksik_yukseklikler') {
    $bas = dateStr('baslangic', $_GET) ?: date('Y-m-d');
    $bit = dateStr('bitis', $_GET) ?: (new DateTime($bas))->modify('+29 day')->format('Y-m-d');
    if ($bit < $bas) jsonError('Bitiş tarihi başlangıçtan önce olamaz.');
    if ((new DateTime($bas))->diff(new DateTime($bit))->days > 366) jsonError('Aralık en fazla bir yıl olabilir.');
    jsonOk(tcEksikYukseklikler($db, $bas, $bit, subeKisiti()));
}

if ($method === 'POST' && ($action === 'onayla' || $action === 'reddet')) {
    verifyCsrf();
    if (($_SESSION['rol'] ?? '') !== 'admin') jsonError('Bu işlem için yönetici yetkisi gerekir.', 403);
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) jsonError('Geçersiz ID.');
    $tStmt = $db->prepare("SELECT id, durum, onay_durumu FROM teklifler WHERE id=?");
    $tStmt->execute([$id]);
    $teklif = $tStmt->fetch();
    if (!$teklif) jsonError('Teklif bulunamadı.', 404);
    if (($teklif['onay_durumu'] ?? '') !== 'bekliyor') jsonError('Bu teklif onay beklemiyor.');

    $yeniDurum = $action === 'onayla' ? 'onaylandi' : 'reddedildi';
    $db->prepare("UPDATE teklifler SET onay_durumu=?, onaylayan=?, onay_tarihi=datetime('now','localtime'), updated_at=datetime('now','localtime') WHERE id=?")
       ->execute([$yeniDurum, $_SESSION['username'] ?? 'admin', $id]);
    logla($db, 'teklifler', $id, 'guncelle', ['onay_durumu' => $teklif['onay_durumu'] ?? null],
          logKayitAl($db, 'teklifler', $id),
          'İskonto ' . ($action === 'onayla' ? 'ONAYLANDI' : 'REDDEDİLDİ'));
    if ($action === 'onayla') _tPortalBildir($db, ['durum' => $teklif['durum'] ?? '', 'onay_durumu' => 'bekliyor'], $id);
    jsonOk(null, $action === 'onayla' ? 'İskonto onaylandı.' : 'İskonto reddedildi.');
}

switch ($method) {

    case 'GET':
        if (isset($_GET['id'])) {
            $id = (int)$_GET['id'];
            $stmt = $db->prepare("
                SELECT t.*, m.unvan AS musteri_unvan, m.yetkili, m.telefon AS musteri_telefon,
                       m.adres AS musteri_adres, m.vergi_dairesi, m.vergi_no
                FROM teklifler t JOIN musteriler m ON m.id = t.musteri_id WHERE t.id=?
            ");
            $stmt->execute([$id]);
            $t = $stmt->fetch();
            if (!$t) jsonError('Teklif bulunamadı.', 404);
            subeErisimDogrula($t['sube'] ?? null);
            $t['kalemler'] = _kalemler($db, $id);
            $t['hesap']    = teklifToplam($t['kalemler'], (float)$t['kdv_oran'], (float)($t['iskonto_oran'] ?? 0));
            if (!empty($_GET['belge'])) {
                require_once __DIR__ . '/tutanak-yaz.php';
                $t['firma_imza'] = firmaImzasi($db);
            }
            jsonOk($t);
        }

        $where = []; $params = [];
        if (($mid = (int)($_GET['musteri_id'] ?? 0)) > 0) { $where[] = "t.musteri_id=?"; $params[] = $mid; }
        if (($q = trim(metin($_GET['q'] ?? ''))) !== '') {
            $where[] = "(" . trAramaSql('t.kod') . " LIKE ? OR " . trAramaSql('m.unvan') . " LIKE ?)"; $like = '%' . trArama($q) . '%'; array_push($params, $like, $like);
        }
        if (($sb = trim(metin($_GET['sube'] ?? ''))) !== '')     { $where[] = "t.sube=?";     $params[] = $sb; }
        subeKosulEkle($where, $params, 't.sube');
        if (($tm = trim(metin($_GET['temsilci'] ?? ''))) !== '') { $where[] = "t.temsilci=?"; $params[] = $tm; }
        $sql = "SELECT t.*, m.unvan AS musteri_unvan FROM teklifler t JOIN musteriler m ON m.id = t.musteri_id";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY t.tarih DESC, t.id DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $sayfalama = null;
        if (isset($_GET['page'])) {
            $perPage = min(200, max(10, (int)($_GET['per_page'] ?? 50)));
            $sayfaSayisi = max(1, (int)ceil(count($rows) / $perPage));
            $sayfa = min(sayfaNo(), $sayfaSayisi);
            $sayfalama = ['sayfa' => $sayfa, 'sayfa_sayisi' => $sayfaSayisi, 'toplam' => count($rows), 'per_page' => $perPage];
            $rows = array_slice($rows, ($sayfa - 1) * $perPage, $perPage);
        }

        $kalemlerMap = [];
        if ($rows) {
            $ids = array_column($rows, 'id');
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $kSt = $db->prepare("SELECT * FROM teklif_kalemleri WHERE teklif_id IN ($ph) ORDER BY id");
            $kSt->execute($ids);
            foreach ($kSt->fetchAll() as $k) $kalemlerMap[(int)$k['teklif_id']][] = $k;
        }
        foreach ($rows as &$r) {
            $r['hesap'] = teklifToplam($kalemlerMap[(int)$r['id']] ?? [], (float)$r['kdv_oran'], (float)($r['iskonto_oran'] ?? 0));
        }
        unset($r);
        jsonOk($sayfalama === null ? $rows : ['list' => $rows, 'sayfalama' => $sayfalama]);
        break;

    case 'POST':
        verifyCsrf();
        $body      = govdeOku();
        $musteriId = (int)($body['musteri_id'] ?? 0);
        $kalemler  = is_array($body['kalemler'] ?? null) ? $body['kalemler'] : [];
        if ($musteriId < 1) jsonError('Müşteri seçilmelidir.');
        if (!$kalemler)     jsonError('En az bir teklif kalemi ekleyin.');

        $mChk = $db->prepare("SELECT COUNT(*) FROM musteriler WHERE id=?");
        $mChk->execute([$musteriId]);
        if ((int)$mChk->fetchColumn() === 0) jsonError('Müşteri bulunamadı.');

        $tarih = dateStr('tarih', $body) ?: date('Y-m-d');
        $mt = musteriSubeTemsilci($db, $musteriId);
        $iskonto = max(0, min(100, (float)($body['iskonto_oran'] ?? 0)));
        $onayDurumu = iskontoOnayDurumuHesapla($db, $iskonto, 'gerekmiyor');
        $hazirKalemler = _tKalemleriHazirla($db, $kalemler);
        if (!$hazirKalemler) jsonError('En az bir teklif kalemi ekleyin.');
        try { $sartlar = tcSartlar($body); } catch (DomainException $e) { jsonError($e->getMessage()); }
        $db->beginTransaction();
        try {
            $db->prepare("
                INSERT INTO teklifler (musteri_id, tarih, gecerlilik, durum, kdv_oran, notlar, sube, temsilci, iskonto_oran, onay_durumu, planlanan_baslangic, sartlar)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $musteriId, $tarih, dateStr('gecerlilik', $body),
                _tDurum($body['durum'] ?? 'taslak'),
                max(0, (float)($body['kdv_oran'] ?? 20)),
                trim(metin($body['notlar'] ?? '')) ?: null,
                kayitSubesi(null, $mt['sube']), $mt['temsilci'],
                $iskonto, $onayDurumu,
                dateStr('planlanan_baslangic', $body),
                $sartlar,
            ]);
            $id  = (int)$db->lastInsertId();
            $kod = 'TKF-' . substr($tarih,0,4) . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
            $db->prepare("UPDATE teklifler SET kod=? WHERE id=?")->execute([$kod, $id]);
            _tKalemleriYaz($db, $id, $hazirKalemler);
            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            jsonError('Teklif kaydedilemedi.', 500);
        }
        logla($db, 'teklifler', $id, 'ekle', null, logKayitAl($db, 'teklifler', $id),
              'Teklif ' . $kod . ' oluşturuldu' . ($iskonto > 0 ? ' · iskonto %' . $iskonto : ''));
        _tPortalBildir($db, null, $id);
        jsonOk(['id' => $id, 'kod' => $kod], 'Teklif kaydedildi.');

    case 'PUT':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $body = govdeOku();

        $cur = $db->prepare("SELECT * FROM teklifler WHERE id=?");
        $cur->execute([$id]);
        $mevcut = $cur->fetch();
        if (!$mevcut) jsonError('Teklif bulunamadı.', 404);
        subeErisimDogrula($mevcut['sube'] ?? null);

        if (count($body) === 1 && isset($body['durum'])) {
            $db->prepare("UPDATE teklifler SET durum=?, updated_at=datetime('now','localtime') WHERE id=?")
               ->execute([_tDurum($body['durum']), $id]);
            _tPortalBildir($db, $mevcut, $id);
            jsonOk(null, 'Durum güncellendi.');
        }

        $musteriId = (int)($body['musteri_id'] ?? 0);
        $kalemler  = is_array($body['kalemler'] ?? null) ? $body['kalemler'] : [];
        if ($musteriId < 1) jsonError('Müşteri seçilmelidir.');
        if (!$kalemler)     jsonError('En az bir teklif kalemi ekleyin.');

        $tarih = dateStr('tarih', $body) ?: date('Y-m-d');
        $mt = musteriSubeTemsilci($db, $musteriId);
        $iskonto = max(0, min(100, (float)($body['iskonto_oran'] ?? 0)));
        $onayDurumu = iskontoOnayDurumuHesapla($db, $iskonto, (string)($mevcut['onay_durumu'] ?? 'gerekmiyor'));
        $hazirKalemler = _tKalemleriHazirla($db, $kalemler);
        if (!$hazirKalemler) jsonError('En az bir teklif kalemi ekleyin.');
        try { $sartlar = tcSartlar($body, $mevcut['sartlar'] ?? null); } catch (DomainException $e) { jsonError($e->getMessage()); }
        $_logEski = logKayitAl($db, 'teklifler', $id);
        $db->beginTransaction();
        try {
            $db->prepare("
                UPDATE teklifler SET musteri_id=?, tarih=?, gecerlilik=?, durum=?, kdv_oran=?, notlar=?, sube=?, temsilci=?,
                    iskonto_oran=?, onay_durumu=?, planlanan_baslangic=?, sartlar=?,
                    updated_at=datetime('now','localtime') WHERE id=?
            ")->execute([
                $musteriId, $tarih, dateStr('gecerlilik', $body),
                _tDurum($body['durum'] ?? 'taslak'),
                max(0, (float)($body['kdv_oran'] ?? 20)),
                trim(metin($body['notlar'] ?? '')) ?: null,
                kayitSubesi(null, ($mevcut['sube'] ?? '') ?: $mt['sube']), $mt['temsilci'],
                $iskonto, $onayDurumu,
                dateStr('planlanan_baslangic', $body),
                $sartlar,
                $id,
            ]);
            $db->prepare("DELETE FROM teklif_kalemleri WHERE teklif_id=?")->execute([$id]);
            _tKalemleriYaz($db, $id, $hazirKalemler);
            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            jsonError('Teklif güncellenemedi.', 500);
        }
        logla($db, 'teklifler', $id, 'guncelle', $_logEski, logKayitAl($db, 'teklifler', $id),
              'Teklif ' . ($_logEski['kod'] ?? ('#' . $id)) . ' güncellendi');
        _tPortalBildir($db, $mevcut, $id);
        jsonOk(null, 'Teklif güncellendi.');

    case 'DELETE':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        subeKayitDogrula($db, 'teklifler', $id);
        $_logEski = logKayitAl($db, 'teklifler', $id);
        $db->prepare("DELETE FROM teklifler WHERE id=?")->execute([$id]);
        logla($db, 'teklifler', $id, 'sil', $_logEski, null, 'Teklif ' . ($_logEski['kod'] ?? ('#' . $id)) . ' SİLİNDİ');
        jsonOk(null, 'Teklif silindi.');

    default:
        jsonError('Method Not Allowed', 405);
}
