<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/dis-kiralama-kurallari.php';
require_once __DIR__ . '/kiralama-akis.php';
require_once __DIR__ . '/tutanak_kurallari.php';
require_once __DIR__ . '/akis-onay.php';
require_once __DIR__ . '/kiralama-operasyon-lib.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('kiralama_takip');

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDb();
function _kkMakineKunye(PDO $db, ?string $tur, ?int $id, array $body): array {
    $kunye = [
        'metraj'      => metrajSayi($body['metraj'] ?? null),
        'seri_no'     => trim(metin($body['seri_no'] ?? '')) ?: null,
        'makine_kodu' => trim(metin($body['makine_kodu'] ?? '')) ?: null,
    ];
    if (!$tur || !$id || !in_array($tur, ['arac', 'manlift'], true)) return $kunye;

    $tablo   = $tur === 'arac' ? 'araclar' : 'manliftler';
    $sutunlar = $tur === 'arac' ? ['makine_kodu'] : ['metraj', 'seri_no', 'makine_kodu'];
    $st = $db->prepare('SELECT ' . implode(', ', $sutunlar) . " FROM $tablo WHERE id = ?");
    $st->execute([$id]);
    $m = $st->fetch();
    if (!$m) return $kunye;

    $ms = metrajSayi($m['metraj'] ?? null);
    if ($kunye['metraj'] === null && $ms !== null) $kunye['metraj'] = $ms;
    if ($kunye['seri_no'] === null)     $kunye['seri_no']     = ($m['seri_no'] ?? null) ?: null;
    if ($kunye['makine_kodu'] === null) $kunye['makine_kodu'] = $m['makine_kodu'] ?: null;
    return $kunye;
}

function _kkEkipmanAdiVarsayilan(PDO $db, ?string $tur, ?int $id): string {
    return makineEkipmanAdi($db, $tur, $id);
}



function _kkNakTip($v): string { return $v === 'avk' ? 'avk' : 'dis'; }
function _kkNakYon($v): string { return in_array($v, ['gidis','donus','gidis_donus'], true) ? $v : 'gidis_donus'; }

function _kiraTip($v): string { return in_array($v, kiraTipleri(), true) ? $v : 'gunluk'; }
function _kiraDurum($v): string { return in_array($v, ['aktif','tamamlandi','iptal'], true) ? $v : 'aktif'; }

function _kkRiskUyari(PDO $db, int $musteriId): ?string {
    $rlStmt = $db->prepare("SELECT unvan, risk_limit, sube FROM musteriler WHERE id=?");
    $rlStmt->execute([$musteriId]);
    $mRow = $rlStmt->fetch();
    if (!$mRow || (float)($mRow['risk_limit'] ?? 0) <= 0) return null;
    $bk   = musteriBakiye($db, $musteriId);
    $risk = $bk['risk_toplam'];
    if ($risk < (float)$mRow['risk_limit']) return null;
    $kisit = subeKisiti();
    if ($kisit !== null && trim((string)($mRow['sube'] ?? '')) !== $kisit) {
        return $mRow['unvan'] . ' için cari risk limiti aşıldı (tutarlar müşterinin şubesinde: '
             . (trim((string)($mRow['sube'] ?? '')) ?: 'şubesiz') . ').';
    }
    $ek = $bk['faturalanmamis'] > 0
        ? ' (' . number_format($bk['bakiye'], 2, ',', '.') . ' ₺ faturalanmış + ' . number_format($bk['faturalanmamis'], 2, ',', '.') . ' ₺ faturalanmamış)'
        : '';
    return $mRow['unvan'] . ' için cari risk (' . number_format($risk, 2, ',', '.') . ' ₺)' . $ek . ' risk limitini (' . number_format((float)$mRow['risk_limit'], 2, ',', '.') . ' ₺) aştı.';
}

function _kkMakineSonHareket(PDO $db, array $rows, string $bugun): array {
    $idler = ['manlift' => [], 'arac' => []];
    foreach ($rows as $r) {
        $tur = (string)($r['makine_tur'] ?? '');
        if (isset($idler[$tur]) && (int)($r['makine_id'] ?? 0) > 0) $idler[$tur][(int)$r['makine_id']] = true;
    }
    $kosul = []; $p = [$bugun];
    foreach ($idler as $tur => $set) {
        if (!$set) continue;
        $kosul[] = "(makine_tur=? AND makine_id IN (" . implode(',', array_fill(0, count($set), '?')) . "))";
        array_push($p, $tur, ...array_keys($set));
    }
    if (!$kosul) return [];
    $st = $db->prepare("SELECT id, makine_tur, makine_id, baslangic FROM kiralamalar
                        WHERE durum<>'iptal' AND baslangic<=? AND (" . implode(' OR ', $kosul) . ")");
    $st->execute($p);
    $son = [];
    foreach ($st->fetchAll() as $x) {
        $a = $x['makine_tur'] . ':' . (int)$x['makine_id'];
        $b = (string)$x['baslangic']; $i = (int)$x['id'];
        $o = $son[$a] ?? null;
        if (!$o || strcmp($b, $o['b']) > 0 || ($b === $o['b'] && $i > $o['i'])) $son[$a] = ['b' => $b, 'i' => $i];
    }
    return $son;
}


function _kkNakliyeEkipmanOzet(array $etiketler): ?string {
    $etiketler = array_values(array_unique(array_filter(array_map('trim', $etiketler), fn($e) => $e !== '')));
    if (!$etiketler) return null;
    $s = implode(', ', $etiketler);
    return mb_strlen($s) > 250 ? mb_substr($s, 0, 249) . '…' : $s;
}

const KK_DIS_SOFOR_ALANLARI = ['dis_sofor_ad', 'dis_sofor_tel', 'dis_sofor_donus_ad', 'dis_sofor_donus_tel'];

function _kkNakliyeGirdi(array $a): array {
    $tip = _kkNakTip($a['tip'] ?? null);
    $nakliyeci = trim(metin($a['nakliyeci'] ?? ''));
    if ($tip === 'avk' && ($nakliyeci === '' || $nakliyeci === 'AVK Nakliye')) $nakliyeci = NAKLIYE_KENDI_AD;
    $ds = fn(string $k, int $n): ?string => $tip === 'avk' ? null : (mb_substr(trim(metin($a[$k] ?? '')), 0, $n) ?: null);
    return [
        'dis_sofor_ad'        => $ds('dis_sofor_ad', 120),
        'dis_sofor_tel'       => $ds('dis_sofor_tel', 40),
        'dis_sofor_donus_ad'  => $ds('dis_sofor_donus_ad', 120),
        'dis_sofor_donus_tel' => $ds('dis_sofor_donus_tel', 40),
        'tip'        => $tip,
        'yon'        => _kkNakYon($a['yon'] ?? null),
        'nakliyeci'  => $nakliyeci ?: null,
        'arac_id'    => $tip === 'avk' ? (((int)($a['arac_id'] ?? 0)) ?: null) : null,
        'plaka'      => trim(metin($a['plaka'] ?? '')) ?: null,
        'sofor_adi'  => trim(metin($a['sofor_adi'] ?? '')) ?: null,
        'aciklama'   => trim(metin($a['aciklama'] ?? '')) ?: null,
        'tarih'      => $a ? dateStr('tarih', $a) : null,
        'yansitilan' => max(0, (float)($a['yansitilan'] ?? 0)),
        'odenen'     => max(0, (float)($a['odenen'] ?? 0)),
        'fiyat_bekliyor' => !empty($a['fiyat_bekliyor']) ? 1 : 0,
    ];
}

function _kkNakliyeAraci(PDO $db, array $g): array {
    if (empty($g['arac_id'])) return $g;
    $st = $db->prepare("SELECT plaka FROM araclar WHERE id=?");
    $st->execute([(int)$g['arac_id']]);
    $plaka = $st->fetchColumn();
    if ($plaka === false) { $g['arac_id'] = null; return $g; }
    if ($g['plaka'] === null) $g['plaka'] = (string)$plaka;
    return $g;
}

function _kkAracAnaKiralama(PDO $db, array $kiralamaIdler): int {
    $ids = array_values(array_unique(array_map('intval', $kiralamaIdler)));
    $st = $db->prepare("SELECT id FROM kiralamalar WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
                        ORDER BY (durum='iptal'), id LIMIT 1");
    $st->execute($ids);
    $id = $st->fetchColumn();
    return $id === false ? min($ids) : (int)$id;
}

function _kkSiparisNakliyeEkle(PDO $db, string $siparisNo, array $ortak, array $g, array $kiralamaIdler, ?string $ekipman): int {
    $h = nakliyeHesap($g['yansitilan'], $g['odenen'], (float)$ortak['kdv_oran'], null, 0, $g['tarih']);
    $db->prepare("
        INSERT INTO nakliyeler (tarih, planlanan_tarih, yon, tip, kiralama_id, musteri_id, ekipman, nakliyeci, plaka, sofor_adi,
                                lokasyon, odenen, yansitilan, sube, temsilci, aciklama,
                                odeme_turu, vade_gun, vade_tarihi, kdv_oran, tevkifat_var, tevkifat_orani, tevkifat_tutari, siparis_no, fiyat_bekliyor,
                                dis_sofor_ad, dis_sofor_tel, dis_sofor_donus_ad, dis_sofor_donus_tel, arac_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ")->execute([
        $g['tarih'], $g['tarih'], $g['yon'], $g['tip'], _kkAracAnaKiralama($db, $kiralamaIdler), $ortak['musteri_id'], $ekipman,
        $g['nakliyeci'], $g['plaka'], $g['sofor_adi'],
        $ortak['lokasyon'], $h['odenen'], $h['yansitilan'], $ortak['sube'], $ortak['temsilci'], $g['aciklama'],
        null, 0, null, $h['kdv_oran'], $h['tevkifat_var'], $h['tevkifat_orani'], $h['tevkifat_tutari'], $siparisNo,
        $g['fiyat_bekliyor'] ?? 0,
        $g['dis_sofor_ad'] ?? null, $g['dis_sofor_tel'] ?? null, $g['dis_sofor_donus_ad'] ?? null, $g['dis_sofor_donus_tel'] ?? null,
        $g['arac_id'] ?? null,
    ]);
    $nid = (int)$db->lastInsertId();
    _kkNakliyeBagla($db, $nid, $kiralamaIdler);
    return $nid;
}

function _kkNakliyeBagla(PDO $db, int $nakliyeId, array $kiralamaIdler): void {
    $db->prepare("DELETE FROM nakliye_makineleri WHERE nakliye_id=? AND yuklendi_zaman IS NULL AND COALESCE(plan_disi,0)=0")->execute([$nakliyeId]);
    $ins = $db->prepare("INSERT OR IGNORE INTO nakliye_makineleri (nakliye_id, kiralama_id) VALUES (?,?)");
    foreach ($kiralamaIdler as $kid) $ins->execute([$nakliyeId, (int)$kid]);
}

function _kkSiparisKiralamalari(PDO $db, string $siparisNo): array {
    $st = $db->prepare("SELECT id, kod, ekipman_adi, durum, makine_tur, makine_id, metraj, baslangic, bitis,
                               musteri_id, lokasyon, sube, temsilci, kdv_oran
                        FROM kiralamalar WHERE siparis_no=? ORDER BY id");
    $st->execute([$siparisNo]);
    $rows = $st->fetchAll();
    if (!$rows) return [];
    $bilgi = makineBilgiToplu($db, array_map(fn($r) => ['tur' => $r['makine_tur'], 'id' => (int)$r['makine_id']], $rows));
    foreach ($rows as &$r) {
        $b = $bilgi[$r['makine_tur'] . ':' . (int)$r['makine_id']] ?? null;
        $r['makine_kod'] = $b['kod'] ?? null;
        $r['metraj'] = metrajSayi($r['metraj']) ?? ($b['metraj'] ?? null);
        $r['etiket'] = (string)($r['makine_kod'] ?: ($r['ekipman_adi'] ?: ('#' . $r['id'])));
    }
    unset($r);
    return $rows;
}

function _kkSiparisDetay(PDO $db, string $siparisNo, ?array $kiralamalar = null): ?array {
    $kiralamalar = $kiralamalar ?? _kkSiparisKiralamalari($db, $siparisNo);
    if (!$kiralamalar) return null;
    $nSt = $db->prepare("SELECT n.id, n.tarih, n.yon, n.tip, n.nakliyeci, n.plaka, n.arac_id, n.sofor_adi, n.yansitilan, n.odenen, n.aciklama,
                                COALESCE(n.fiyat_bekliyor,0) AS fiyat_bekliyor, n.hakedis_id,
                                n.dis_sofor_ad, n.dis_sofor_tel, n.dis_sofor_donus_ad, n.dis_sofor_donus_tel, p.ad_soyad AS sofor_ad
                         FROM nakliyeler n LEFT JOIN personel p ON p.id=n.sofor_personel_id
                         WHERE n.siparis_no=? ORDER BY n.tarih, n.id");
    $nSt->execute([$siparisNo]);
    $nakliyeler = $nSt->fetchAll();
    $bag = [];
    if ($nakliyeler) {
        $ids = array_map('intval', array_column($nakliyeler, 'id'));
        $bSt = $db->prepare("SELECT nakliye_id, kiralama_id FROM nakliye_makineleri WHERE nakliye_id IN ("
                            . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY kiralama_id");
        $bSt->execute($ids);
        foreach ($bSt->fetchAll() as $b) $bag[(int)$b['nakliye_id']][] = (int)$b['kiralama_id'];
    }
    foreach ($nakliyeler as &$n) $n['makineler'] = $bag[(int)$n['id']] ?? [];
    unset($n);
    return [
        'siparis_no'  => $siparisNo,
        'tasiyici_id' => siparisTasiyici($db, $siparisNo),
        'kiralamalar' => array_map(fn($k) => [
            'id' => (int)$k['id'], 'kod' => $k['kod'], 'ekipman_adi' => $k['ekipman_adi'], 'durum' => $k['durum'],
            'makine_tur' => $k['makine_tur'], 'makine_id' => $k['makine_id'] !== null ? (int)$k['makine_id'] : null,
            'makine_kod' => $k['makine_kod'], 'metraj' => $k['metraj'],
            'baslangic' => $k['baslangic'], 'bitis' => $k['bitis'],
        ], $kiralamalar),
        'nakliyeler'  => $nakliyeler,
    ];
}

if ($method === 'GET' && ($_GET['action'] ?? '') === 'musaitlik') {
    $makineTur = trim(metin($_GET['makine_tur'] ?? ''));
    $makineId  = (int)($_GET['makine_id'] ?? (int)($_GET['manlift_id'] ?? 0));
    if ($makineTur === '' && $makineId > 0) $makineTur = 'manlift';
    $bas       = dateStr('baslangic', $_GET);
    $bit       = dateStr('bitis', $_GET);
    $haricId   = (int)($_GET['haric_id'] ?? 0);
    if ($makineId < 1 || $makineTur === '' || !$bas) jsonOk(['cakisma' => []]);
    if (!in_array($makineTur, ['arac','manlift'], true)) jsonError('Geçersiz makine türü.');
    subeKayitDogrula($db, $makineTur === 'arac' ? 'araclar' : 'manliftler', $makineId);

    $cakisma = kiralamaCakismalari($db, $makineTur, $makineId, $bas, $bit, $haricId);

    $mDurum = makineDurumu($db, $makineTur, $makineId);
    if ($mDurum === null) jsonError('Makine bulunamadı.', 404);
    if (subeKisiti() !== null) foreach ($cakisma as &$c) {
        $c['kod'] = null;
        $c['musteri_unvan'] = 'Kayıtlı rezervasyon';
    }
    unset($c);
    $cakisma = array_map(fn($c) => array_diff_key($c, ['sube' => true]), $cakisma);
    $bugun = date('Y-m-d');
    $donusEngel = ($haricId === 0 && $bas <= $bugun && (!$bit || $bit >= $bugun)) ? makineDonusEngeli($db, $makineTur, $makineId) : null;
    $dkEngel = dkDonemKontrol($db, $makineTur, $makineId, $bas, $bit)['engel'];
    $durumUyari = in_array($mDurum, ['serviste', 'nakliyede', 'inceleme_bekliyor', 'satildi', 'pasif'], true) ? $mDurum : null;

    jsonOk([
        'cakisma'     => $cakisma,
        'durum_uyari' => $durumUyari,
        'engel'         => in_array($mDurum, KIRALANAMAZ_DURUMLAR, true) || $donusEngel !== null || $dkEngel !== null,
        'engel_mesaj'   => $donusEngel ?? $dkEngel,
        'dk_engel'      => $dkEngel,
        'onay_gerekli'  => (bool)$cakisma,
    ]);
}

if ($method === 'POST' && (($_GET['action'] ?? '') === 'onayla' || ($_GET['action'] ?? '') === 'reddet')) {
    verifyCsrf();
    if (($_SESSION['rol'] ?? '') !== 'admin') jsonError('Bu işlem için yönetici yetkisi gerekir.', 403);
    $action = $_GET['action'];
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) jsonError('Geçersiz ID.');
    $kStmt = $db->prepare("SELECT id, onay_durumu FROM kiralamalar WHERE id=?");
    $kStmt->execute([$id]);
    $kiralama = $kStmt->fetch();
    if (!$kiralama) jsonError('Kiralama bulunamadı.', 404);
    if (($kiralama['onay_durumu'] ?? '') !== 'bekliyor') jsonError('Bu kiralama onay beklemiyor.');

    $yeniDurum = $action === 'onayla' ? 'onaylandi' : 'reddedildi';
    $db->prepare("UPDATE kiralamalar SET onay_durumu=?, onaylayan=?, onay_tarihi=datetime('now','localtime'), updated_at=datetime('now','localtime') WHERE id=?")
       ->execute([$yeniDurum, $_SESSION['username'] ?? 'admin', $id]);
    jsonOk(null, $action === 'onayla' ? 'İskonto onaylandı.' : 'İskonto reddedildi.');
}

if ($method === 'POST' && ($_GET['action'] ?? '') === 'makine_degistir') {
    verifyCsrf();
    $body = govdeOku();
    $eskiId = (int)($body['id'] ?? 0);
    if ($eskiId < 1) jsonError('Geçersiz kiralama.');

    subeKayitDogrula($db, 'kiralamalar', $eskiId);
    $eSt = $db->prepare("SELECT * FROM kiralamalar WHERE id=?");
    $eSt->execute([$eskiId]);
    $eski = $eSt->fetch();
    if (!$eski) jsonError('Kiralama bulunamadı.', 404);
    if ($eski['durum'] !== 'aktif') jsonError('Sadece aktif bir kiralamanın makinesi değiştirilebilir.');
    if (!$eski['makine_tur'] || !$eski['makine_id']) jsonError('Bu kiralamaya bağlı bir makine yok.');

    $yeniTur = trim(metin($body['yeni_makine_tur'] ?? ''));
    $yeniId  = (int)($body['yeni_makine_id'] ?? 0);
    if ($yeniTur !== (string)$eski['makine_tur']) {
        jsonError('Makine değişimi aynı türde olmalıdır (araç ↔ araç, platform ↔ platform).');
    }
    if ($yeniId < 1) jsonError('Yeni makine seçilmedi.');
    if ($yeniId === (int)$eski['makine_id']) jsonError('Seçilen makine mevcut makineyle aynı.');

    $ikameNot = trim(metin($body['ikame_not'] ?? ''));
    if ($ikameNot === '') jsonError('Değişim nedeni zorunludur.');

    $degisimTarihi = dateStr('degisim_tarihi', $body) ?: date('Y-m-d');
    if ($degisimTarihi < (string)$eski['baslangic']) {
        jsonError('Değişim tarihi kiralamanın başlangıcından önce olamaz.');
    }
    if (!empty($eski['bitis']) && $degisimTarihi > $eski['bitis']) {
        jsonError('Değişim tarihi planlanan bitişten sonra. Önce kiralamanın bitiş tarihini uzatın; ardından makineyi değiştirin.');
    }

    kiralamaKaydiDogrula($db, $yeniTur, $yeniId, $degisimTarihi, $eski['bitis'], !empty($body['cakisma_onaylandi']));

    $eskiYeni = opYeniZincir($db, $eski);
    $eskiGuncel = $eskiYeni && operasyonKiralamaGuncelMi($db, $eski);

    $db->beginTransaction();
    try {
        $yeniEkipman = _kkEkipmanAdiVarsayilan($db, $yeniTur, $yeniId) ?: $eski['ekipman_adi'];
        $yeniManliftId = $yeniTur === 'manlift' ? $yeniId : null;
        $kunyeYeni = _kkMakineKunye($db, $yeniTur, $yeniId, []);

        $ins = $db->prepare("
            INSERT INTO kiralamalar
              (musteri_id, manlift_id, makine_tur, makine_id, ekipman_adi, baslangic, bitis, gercek_bitis,
               kira_tipi, birim_ucret, miktar, nakliye_ucret, nakliye_maliyet, nakliye_tip, nakliye_yon, nakliyeci,
               lokasyon, sube, temsilci, kdv_oran, durum, notlar,
               operatorlu, operator_id, operator_ucret, operator_ucret_tipi,
               otomatik_yenileme, iskonto_oran, onay_durumu, il, doviz,
               metraj, seri_no, makine_kodu, odeme_turu, vade_gun, siparis_no,
               kaynak, saha_irtibat_ad, saha_irtibat_tel, saha_konum, olusturan_id, ilce, santiye_adres)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $ins->execute([
            $eski['musteri_id'], $yeniManliftId, $yeniTur, $yeniId, $yeniEkipman, $degisimTarihi, $eski['bitis'], null,
            $eski['kira_tipi'], $eski['birim_ucret'], $eski['miktar'],
            0, 0,
            $eski['nakliye_tip'], $eski['nakliye_yon'], null,
            $eski['lokasyon'], $eski['sube'], $eski['temsilci'], $eski['kdv_oran'], 'aktif',
            'Makine değişimi: ' . $ikameNot,
            $eski['operatorlu'], $eski['operator_id'], $eski['operator_ucret'], $eski['operator_ucret_tipi'],
            $eski['otomatik_yenileme'], $eski['iskonto_oran'], $eski['onay_durumu'], $eski['il'], $eski['doviz'],
            $kunyeYeni['metraj'], $kunyeYeni['seri_no'], $kunyeYeni['makine_kodu'],
            $eski['odeme_turu'], $eski['vade_gun'],
            $eski['siparis_no'] ?? null,
            $eski['kaynak'] ?? null, $eski['saha_irtibat_ad'] ?? null, $eski['saha_irtibat_tel'] ?? null,
            $eski['saha_konum'] ?? null, $eski['olusturan_id'] ?? null,
            $eski['ilce'] ?? null, $eski['santiye_adres'] ?? null,
        ]);
        $yeniId2 = (int)$db->lastInsertId();
        dkMaliyetKaydet($db,$yeniId2);

        $yil = substr($degisimTarihi, 0, 4);
        $yeniKod = 'KR-' . $yil . '-' . str_pad((string)$yeniId2, 4, '0', STR_PAD_LEFT);
        $eskiSozNo = trim((string)($eski['sozlesme_no'] ?? ''));
        $yeniSozNo = (!empty($eski['siparis_no']) && $eskiSozNo !== '')
            ? $eskiSozNo
            : 'SZ-' . $yil . '-' . str_pad((string)$yeniId2, 4, '0', STR_PAD_LEFT);
        $db->prepare("UPDATE kiralamalar SET kod=?, sozlesme_no=? WHERE id=?")->execute([$yeniKod, $yeniSozNo, $yeniId2]);

        kiralamaMakineDurumu($db, $yeniTur, $yeniId, 'siparis');

        $db->prepare("
            UPDATE kiralamalar SET durum='tamamlandi', gercek_bitis=?, ikame_makine_id=?, ikame_not=?,
                   updated_at=datetime('now','localtime')
            WHERE id=?
        ")->execute([
            $degisimTarihi, $yeniId, $ikameNot . ' (yeni kiralama: ' . $yeniKod . ')', $eskiId,
        ]);
        if ($eskiYeni) {
            if ($eskiGuncel) kiralamaMakineDurumu($db, (string)$eski['makine_tur'], (int)$eski['makine_id'], opIadeOlayi($db, $eski));
            opDonusKapandi($db, $eski, $eskiGuncel, 'Makine değişimi: ' . $ikameNot);
        } else {
            kiralamaMakineDurumu($db, (string)$eski['makine_tur'], (int)$eski['makine_id'], 'iade');
        }
        if (!empty($eski['siparis_no'])) siparisNakliyeSenkron($db, (string)$eski['siparis_no']);

        logla($db, 'kiralamalar', $eskiId, 'guncelle', null, null, 'Makine değişimi: ' . $ikameNot . ' → ' . $yeniKod);
        logla($db, 'kiralamalar', $yeniId2, 'ekle', null, null, 'Makine değişiminden açıldı (önceki: ' . ($eski['kod'] ?? ('#' . $eskiId)) . ')');
        require_once __DIR__ . '/ariza-karar-lib.php';
        akMakineDegisti($db, $eskiId, $yeniKod);

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    require_once __DIR__ . '/zincir-lib.php';
    jsonOk(['id' => $yeniId2, 'kod' => $yeniKod, 'eski_id' => $eskiId, 'eski_inceleme' => $eskiGuncel],
           ($eskiGuncel ? 'Makine değiştirildi; çıkan makine dönüş kontrolüne alındı (Teknik Servis → Makine Kontrolleri → Dönüş kontrolü).' : 'Makine değiştirildi.') . kiralamaKapanisHakedisUyarisi($db, $eskiId));
}

function _kkSahaAlanlari(array $body, ?array $mevcut = null): array {
    require_once __DIR__ . '/hizli-kiralama-lib.php';
    $out = [];
    foreach (['saha_irtibat_ad' => 'yetkili', 'saha_irtibat_tel' => 'telefon'] as $alan => $sinirAlani) {
        if ($mevcut !== null && !array_key_exists($alan, $body)) { $out[$alan] = $mevcut[$alan] ?? null; continue; }
        $v = trim(metin($body[$alan] ?? ''));
        if (($h = hkIrtibatSiniri($sinirAlani, $v)) !== null) jsonError($h);
        $out[$alan] = $v !== '' ? $v : null;
    }
    if ($mevcut !== null && !array_key_exists('saha_konum', $body)) {
        $out['saha_konum'] = $mevcut['saha_konum'] ?? null;
    } else {
        try { $out['saha_konum'] = hkKonumDogrula(metin($body['saha_konum'] ?? '')); }
        catch (DomainException $e) { jsonError($e->getMessage()); }
    }
    return $out;
}

function _kkSiparisTekrarYanit(array $ilk): void {
    $ilk['tekrar'] = true;
    jsonOk($ilk, 'Bu sipariş zaten kaydedildi (' . ($ilk['siparis_no'] ?? '') . ')');
}

function _kkIstekTekrari(PDO $db, ?string $anahtar, int $kullaniciId): void {
    if ($anahtar === null) return;
    try { $ilk = hkIstekBul($db, $anahtar, $kullaniciId); }
    catch (DomainException $e) { jsonError($e->getMessage(), 409); }
    if ($ilk !== null) _kkSiparisTekrarYanit($ilk);
}

if ($method === 'POST' && ($_GET['action'] ?? '') === 'siparis') {
    verifyCsrf();
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) $body = [];

    require_once __DIR__ . '/hizli-kiralama-lib.php';
    $istekAnahtari = trim(metin($body['istemci_anahtari'] ?? '')) ?: null;
    if ($istekAnahtari !== null && !hkIstekAnahtariGecerli($istekAnahtari))
        jsonError('Sipariş istek anahtarı geçersiz. Pencereyi kapatıp yeniden açın.');
    $istekKullanici = (int)($_SESSION['user_id'] ?? 0);
    _kkIstekTekrari($db, $istekAnahtari, $istekKullanici);

    $musteriId = (int)($body['musteri_id'] ?? 0);
    if ($musteriId < 1) jsonError('Müşteri seçilmelidir.');
    $mChk = $db->prepare("SELECT COUNT(*) FROM musteriler WHERE id=?");
    $mChk->execute([$musteriId]);
    if ((int)$mChk->fetchColumn() === 0) jsonError('Seçilen müşteri bulunamadı.');

    $girisSatir = is_array($body['makineler'] ?? null) ? array_values($body['makineler']) : [];
    if (!$girisSatir) jsonError('Siparişe en az bir makine ekleyin.');
    if (count($girisSatir) > 100) jsonError('Bir siparişte en fazla 100 makine olabilir; siparişi ikiye bölerek kaydedin.');
    $girisArac = is_array($body['nakliyeler'] ?? null) ? array_values($body['nakliyeler']) : [];
    if (count($girisArac) > 30) jsonError('Bir siparişte en fazla 30 nakliye aracı olabilir.');

    $gercekBit   = dateStr('gercek_bitis', $body);
    $durum       = _kiraDurum($body['durum'] ?? 'aktif');
    $kdvOran     = max(0, (float)($body['kdv_oran'] ?? 20));
    $lokasyon    = trim(metin($body['lokasyon'] ?? '')) ?: null;
    $sube        = kayitSubesi(metin($body['sube'] ?? ''));
    $temsilci    = trim(metin($body['temsilci'] ?? '')) ?: null;
    $iskontoOran = max(0, min(100, (float)($body['iskonto_oran'] ?? 0)));
    $onayDurumu  = iskontoOnayDurumuHesapla($db, $iskontoOran, 'gerekmiyor');
    $kaynak      = ($body['kaynak'] ?? null) === 'qr' ? 'qr' : null;
    $saha        = _kkSahaAlanlari($body);
    $olusturanId = ((int)($_SESSION['user_id'] ?? 0)) ?: null;

    $satirlar = [];
    foreach ($girisSatir as $i => $r) {
        $n = $i + 1;
        if (!is_array($r)) $r = [];
        $tur = in_array($r['makine_tur'] ?? '', ['manlift', 'arac'], true) ? $r['makine_tur'] : null;
        $mid = ((int)($r['makine_id'] ?? 0)) ?: null;
        if (!$tur || !$mid) { $tur = null; $mid = null; }
        $ekipman = trim(metin($r['ekipman_adi'] ?? ''));
        if (!$tur && $ekipman === '') jsonError("$n. satırda makine veya ekipman adı gerekli.");
        $bas = $r ? dateStr('baslangic', $r) : null;
        if (!$bas) jsonError("$n. makinenin başlangıç tarihi zorunludur.");
        $bit = dateStr('bitis', $r);
        if ($bit && $bit < $bas) jsonError("$n. makine: bitiş tarihi başlangıçtan önce olamaz.");
        kiralamaTarihDogrula($bas, $bit, $gercekBit);

        $operatorlu = !empty($r['operatorlu']) ? 1 : 0;
        $satirlar[] = [
            'sira' => $n, 'tur' => $tur, 'mid' => $mid, 'ekipman' => $ekipman, 'bas' => $bas, 'bit' => $bit,
            'kira_tipi'   => _kiraTip($r['kira_tipi'] ?? 'gunluk'),
            'birim_ucret' => max(0, (float)($r['birim_ucret'] ?? 0)),
            'miktar'      => max(1, (int)($r['miktar'] ?? 1)),
            'operatorlu'  => $operatorlu,
            'operator_id' => $operatorlu ? (((int)($r['operator_id'] ?? 0)) ?: null) : null,
            'operator_ucret' => $operatorlu ? max(0, (float)($r['operator_ucret'] ?? 0)) : 0,
            'operator_tip'   => in_array($r['operator_ucret_tipi'] ?? '', ['gunluk', 'aylik', 'sabit'], true) ? $r['operator_ucret_tipi'] : 'gunluk',
            'girdi' => $r,
        ];
    }

    foreach ($satirlar as $a) {
        foreach ($satirlar as $b) {
            if ($b['sira'] <= $a['sira'] || !$a['tur'] || $a['tur'] !== $b['tur'] || $a['mid'] !== $b['mid']) continue;
            if (tarihCakisir($a['bas'], $a['bit'], $b['bas'], $b['bit'])) {
                jsonError($a['sira'] . '. ve ' . $b['sira'] . '. satırda aynı makine seçilmiş — bir makine aynı tarihlerde iki kez kiralanamaz.');
            }
        }
    }

    $bilgi = makineBilgiToplu($db, array_map(fn($s) => ['tur' => $s['tur'], 'id' => (int)$s['mid']], $satirlar));
    $cakismalar = [];
    foreach ($satirlar as $k => $s) {
        $etiket = ($s['tur'] ? ($bilgi[$s['tur'] . ':' . $s['mid']]['kod'] ?? null) : null) ?: ($s['ekipman'] ?: ('#' . $s['sira']));
        $satirlar[$k]['etiket'] = $etiket;
        if (!$s['tur']) continue;
        $kontrol = kiralamaKaydiKontrol($db, $s['tur'], $s['mid'], $s['bas'], $s['bit']);
        if ($kontrol['engel'] !== null) {
            _kkIstekTekrari($db, $istekAnahtari, $istekKullanici);
            jsonError($s['sira'] . '. makine: ' . $kontrol['engel'], $kontrol['engel_kod']);
        }
        foreach ($kontrol['cakisma'] as $c) $cakismalar[] = ['sira' => $s['sira'], 'makine' => $etiket] + $c;
    }

    $cakismalar = hkCakismaMaskele($cakismalar, subeKisiti());
    if ($cakismalar && empty($body['cakisma_onaylandi'])) {
        _kkIstekTekrari($db, $istekAnahtari, $istekKullanici);
        $ilk = $cakismalar[0];
        $makineSay = count(array_unique(array_column($cakismalar, 'sira')));
        jsonError($makineSay . ' makine seçilen tarihlerde başka bir kiralamada: '
            . $ilk['sira'] . '. satır ' . $ilk['makine'] . ' — ' . ($ilk['kod'] ?: '#') . ' · ' . $ilk['musteri_unvan']
            . ' (' . $ilk['baslangic'] . ' → ' . (!empty($ilk['iade_gecikti'])
                ? 'planlı iade ' . $ilk['planli_bitis'] . ', makine henüz iade edilmedi'
                : ($ilk['bitis'] ?: 'açık uç')) . ')'
            . (count($cakismalar) > 1 ? ' ve ' . (count($cakismalar) - 1) . ' kayıt daha' : '')
            . '. Yine de kaydetmek için onaylayın.',
            409, ['onaylanabilir' => true, 'cakisma' => $cakismalar]);
    }

    $araclar = [];
    foreach ($girisArac as $a) {
        if (!is_array($a)) $a = [];
        $idx = [];
        foreach ((array)($a['makineler'] ?? []) as $x) {
            if (is_int($x) || (is_string($x) && ctype_digit($x))) {
                $x = (int)$x;
                if (isset($satirlar[$x])) $idx[$x] = true;
            }
        }
        $idx = $idx ? array_keys($idx) : array_keys($satirlar);
        sort($idx);
        $g = _kkNakliyeAraci($db, _kkNakliyeGirdi($a));
        if (!$g['tarih']) $g['tarih'] = min(array_map(fn($i) => $satirlar[$i]['bas'], $idx));
        $araclar[] = ['g' => $g, 'idx' => $idx];
    }

    $nakSube = $sube; $nakTemsilci = $temsilci;
    if (!$nakSube || !$nakTemsilci) {
        $mt = musteriSubeTemsilci($db, $musteriId);
        $nakSube = $nakSube ?: $mt['sube'];
        $nakTemsilci = $nakTemsilci ?: $mt['temsilci'];
    }
    $bugun = date('Y-m-d');
    $nakliyeIds = [];
    $db->beginTransaction();
    try {
        $ins = $db->prepare("
            INSERT INTO kiralamalar
              (musteri_id, manlift_id, makine_tur, makine_id, ekipman_adi, baslangic, bitis, gercek_bitis,
               kira_tipi, birim_ucret, miktar, nakliye_ucret, nakliye_maliyet, nakliye_tip, nakliye_yon, nakliyeci, lokasyon, sube, temsilci, kdv_oran, durum, notlar,
               operatorlu, operator_id, operator_ucret, operator_ucret_tipi,
               sozlesme_no, sozlesme_imza_tarihi, otomatik_yenileme, iskonto_oran, onay_durumu, il, doviz,
               metraj, seri_no, makine_kodu, odeme_turu, vade_gun,
               kaynak, saha_irtibat_ad, saha_irtibat_tel, saha_konum, olusturan_id, ilce, santiye_adres)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        if ($istekAnahtari !== null) hkIstekKaydet($db, $istekAnahtari, '', [], (int)$olusturanId);
        foreach ($satirlar as $k => $s) {
            $ekipman = $s['ekipman'] !== '' ? $s['ekipman'] : _kkEkipmanAdiVarsayilan($db, $s['tur'], $s['mid']);
            $kunye = _kkMakineKunye($db, $s['tur'], $s['mid'], $s['girdi']);
            $ins->execute([
                $musteriId, $s['tur'] === 'manlift' ? $s['mid'] : null, $s['tur'], $s['mid'], $ekipman,
                $s['bas'], $s['bit'], $gercekBit,
                $s['kira_tipi'], $s['birim_ucret'], $s['miktar'],
                0, 0, 'dis', 'gidis_donus', null,
                $lokasyon, $sube, $temsilci, $kdvOran, $durum,
                trim(metin($body['notlar'] ?? '')) ?: null,
                $s['operatorlu'], $s['operator_id'], $s['operator_ucret'], $s['operator_tip'],
                null,
                dateStr('sozlesme_imza_tarihi', $body),
                !empty($body['otomatik_yenileme']) ? 1 : 0,
                $iskontoOran, $onayDurumu,
                ilYazim(metin($body['il'] ?? '')) ?: null,
                in_array($body['doviz'] ?? '', ['TL', 'EUR', 'USD'], true) ? $body['doviz'] : 'TL',
                $kunye['metraj'], $kunye['seri_no'], $kunye['makine_kodu'],
                nakliyeOdemeTuru($body['odeme_turu'] ?? null),
                max(0, min(NAKLIYE_VADE_MAX, (int)($body['vade_gun'] ?? 0))),
                $kaynak, $saha['saha_irtibat_ad'], $saha['saha_irtibat_tel'], $saha['saha_konum'], $olusturanId,
                kiralamaMetinAlan($body, 'ilce', 80), kiralamaMetinAlan($body, 'santiye_adres', 500),
            ]);
            $satirlar[$k]['id'] = (int)$db->lastInsertId();
            dkMaliyetKaydet($db,$satirlar[$k]['id']);
            $satirlar[$k]['ekipman'] = $ekipman;
        }

        $ilkPad    = str_pad((string)$satirlar[0]['id'], 4, '0', STR_PAD_LEFT);
        $ilkYil    = substr($satirlar[0]['bas'], 0, 4);
        $siparisNo = 'SP-' . $ilkYil . '-' . $ilkPad;
        $sozlesmeNo = trim(metin($body['sozlesme_no'] ?? '')) ?: ('SZ-' . $ilkYil . '-' . $ilkPad);
        $upd = $db->prepare("UPDATE kiralamalar SET kod=?, sozlesme_no=?, siparis_no=? WHERE id=?");
        foreach ($satirlar as $k => $s) {
            $satirlar[$k]['kod'] = 'KR-' . substr($s['bas'], 0, 4) . '-' . str_pad((string)$s['id'], 4, '0', STR_PAD_LEFT);
            $upd->execute([$satirlar[$k]['kod'], $sozlesmeNo, $siparisNo, $s['id']]);
        }

        $ortak = ['musteri_id' => $musteriId, 'lokasyon' => $lokasyon, 'sube' => $nakSube,
                  'temsilci' => $nakTemsilci, 'kdv_oran' => $kdvOran];
        foreach ($araclar as $a) {
            $kids = array_map(fn($i) => $satirlar[$i]['id'], $a['idx']);
            $ozet = _kkNakliyeEkipmanOzet(array_map(fn($i) => $satirlar[$i]['etiket'] ?: $satirlar[$i]['ekipman'], $a['idx']));
            $nid  = _kkSiparisNakliyeEkle($db, $siparisNo, $ortak, $a['g'], $kids, $ozet);
            $nakliyeIds[] = $nid;
            logla($db, 'nakliyeler', $nid, 'ekle', null, logKayitAl($db, 'nakliyeler', $nid),
                  'Sipariş ' . $siparisNo . ' nakliyesi: ' . ($a['g']['nakliyeci'] ?? 'nakliyeci belirtilmedi') . ' · ' . count($kids) . ' makine');
        }
        siparisNakliyeSenkron($db, $siparisNo);

        foreach ($satirlar as $s) {
            if ($s['bas'] >= $bugun) kiralamaMakineDurumu($db, $s['tur'], $s['mid'], 'siparis');
            logla($db, 'kiralamalar', $s['id'], 'ekle', null, logKayitAl($db, 'kiralamalar', $s['id']),
                  'Kiralama ' . $s['kod'] . ' açıldı (sipariş ' . $siparisNo . ')' . ($iskontoOran > 0 ? ' · iskonto %' . $iskontoOran : ''));
        }
        $yanit = [
            'siparis_no'  => $siparisNo,
            'ids'         => array_column($satirlar, 'id'),
            'kodlar'      => array_column($satirlar, 'kod'),
            'nakliye_ids' => $nakliyeIds,
            'risk_uyari'  => null,
            'onay_durumu' => $onayDurumu,
        ];
        if ($istekAnahtari !== null) {
            $db->prepare('UPDATE siparis_istekleri SET siparis_no=?, yanit=? WHERE anahtar=?')
               ->execute([$siparisNo, json_encode($yanit, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $istekAnahtari]);
        }
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($e instanceof PDOException) _kkIstekTekrari($db, $istekAnahtari, $istekKullanici);
        throw $e;
    }

    $riskUyari = null;
    try {
        $riskUyari = _kkRiskUyari($db, $musteriId);
    } catch (\Throwable $e) {
        error_log('Taloo: siparis ' . $siparisNo . ' risk uyarisi hesaplanamadi: ' . $e->getMessage());
    }

    $yanit['risk_uyari'] = $riskUyari;
    jsonOk($yanit, count($satirlar) . ' makine kiralandı (' . $siparisNo . ').');
}

if ($method === 'GET' && ($_GET['action'] ?? '') === 'siparis_detay') {
    $siparisNo = trim(metin($_GET['siparis_no'] ?? ''));
    if ($siparisNo === '') jsonError('Sipariş numarası gerekli.');
    $kiralamalar = _kkSiparisKiralamalari($db, $siparisNo);
    if (!$kiralamalar) jsonError('Sipariş bulunamadı.', 404);
    $tasiyici = siparisTasiyici($db, $siparisNo);
    $tSube = null;
    foreach ($kiralamalar as $k) if ((int)$k['id'] === $tasiyici) $tSube = $k['sube'];
    subeErisimDogrula($tSube);
    jsonOk(_kkSiparisDetay($db, $siparisNo, $kiralamalar));
}

if ($method === 'GET' && ($_GET['action'] ?? '') === 'nakliyeci_mesaj') {
    header('Cache-Control: no-store');
    $yon = (string)($_GET['yon'] ?? 'gidis');
    if (!in_array($yon, ['gidis', 'donus'], true)) jsonError('Geçersiz yön.', 400);
    $nSt = $db->prepare('SELECT * FROM nakliyeler WHERE id=?');
    $nSt->execute([(int)($_GET['nakliye_id'] ?? 0)]);
    $nak = $nSt->fetch(PDO::FETCH_ASSOC);
    if (!$nak) jsonError('Nakliye aracı bulunamadı.', 404);
    subeErisimDogrula(_sfAracSubesi($db, $nak));
    try {
        $mesaj = hkNakliyeciMetni($db, (int)$nak['id'], $yon, null);
    } catch (DomainException $e) {
        jsonError($e->getMessage(), 409);
    }
    logla($db, 'nakliyeler', (int)$nak['id'], 'sorgu', null, ['yon' => $yon],
          'Nakliyeci iş detayı hazırlandı (' . ($yon === 'donus' ? 'dönüş' : 'gidiş') . '): ' . ($mesaj['ad'] ?? 'alıcı adı yok'));
    jsonOk($mesaj);
}

if ($method === 'POST' && ($_GET['action'] ?? '') === 'siparis_nakliye') {
    verifyCsrf();
    $siparisNo = trim(metin($_GET['siparis_no'] ?? ''));
    if ($siparisNo === '') jsonError('Sipariş numarası gerekli.');
    $kiralamalar = _kkSiparisKiralamalari($db, $siparisNo);
    if (!$kiralamalar) jsonError('Sipariş bulunamadı.', 404);
    $tasiyiciId = (int)siparisTasiyici($db, $siparisNo);
    subeKayitDogrula($db, 'kiralamalar', $tasiyiciId);

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || !is_array($body['nakliyeler'] ?? null)) jsonError('Nakliye listesi gönderilmedi.');
    $liste = array_values($body['nakliyeler']);
    if (count($liste) > 30) jsonError('Bir siparişte en fazla 30 nakliye aracı olabilir.');

    $kira = [];
    foreach ($kiralamalar as $k) $kira[(int)$k['id']] = $k;
    $tasiyici = $kira[$tasiyiciId];
    $mSt = $db->prepare("SELECT * FROM nakliyeler WHERE siparis_no=?");
    $mSt->execute([$siparisNo]);
    $mevcutArac = [];
    foreach ($mSt->fetchAll() as $n) $mevcutArac[(int)$n['id']] = $n;

    $varsayilanKids = array_keys(array_filter($kira, fn($k) => $k['durum'] !== 'iptal')) ?: array_keys($kira);

    $islemler = [];
    foreach ($liste as $j => $a) {
        if (!is_array($a)) $a = [];
        $nid = (int)($a['id'] ?? 0);
        if ($nid > 0 && !isset($mevcutArac[$nid])) {
            jsonError(($j + 1) . '. araç (#' . $nid . ') bu siparişe ait değil. Sayfayı yenileyip tekrar deneyin.');
        }
        $kids = [];
        foreach ((array)($a['makineler'] ?? []) as $x) {
            if ((is_int($x) || (is_string($x) && ctype_digit($x))) && isset($kira[(int)$x])) $kids[(int)$x] = true;
        }
        $kids = $kids ? array_keys($kids) : $varsayilanKids;
        sort($kids);
        $g = _kkNakliyeGirdi($a);
        if ($nid > 0 && $g['tip'] !== 'avk' && !array_intersect_key($a, array_flip(KK_DIS_SOFOR_ALANLARI))) {
            foreach (KK_DIS_SOFOR_ALANLARI as $alan) $g[$alan] = $mevcutArac[$nid][$alan] ?? null;
        }
        if ($nid > 0 && ($g['tip'] !== 'avk' || !array_key_exists('arac_id', $a))) {
            $g['arac_id'] = ((int)($mevcutArac[$nid]['arac_id'] ?? 0)) ?: null;
        }
        $g = _kkNakliyeAraci($db, $g);
        if (!$g['tarih']) {
            $g['tarih'] = $nid > 0 ? (string)$mevcutArac[$nid]['tarih'] : min(array_map(fn($id) => (string)$kira[$id]['baslangic'], $kids));
        }
        if ($nid > 0 && !empty($mevcutArac[$nid]['hakedis_id'])
            && (round($g['yansitilan'], 2) !== round((float)$mevcutArac[$nid]['yansitilan'], 2) || $g['fiyat_bekliyor'])) {
            jsonError(($j + 1) . '. araç hakediş #' . (int)$mevcutArac[$nid]['hakedis_id'] . ' ile faturalandı; müşteri tutarı değiştirilemez.', 409);
        }
        $islemler[] = ['id' => $nid, 'g' => $g, 'kids' => $kids,
                       'ozet' => _kkNakliyeEkipmanOzet(array_map(fn($id) => $kira[$id]['etiket'], $kids))];
    }

    $listedeki = array_flip(array_filter(array_map(fn($x) => (int)$x['id'], $islemler)));
    foreach ($mevcutArac as $nid => $eskiArac) {
        if (!isset($listedeki[$nid]) && !empty($eskiArac['hakedis_id'])) {
            jsonError('Faturalanmış nakliye aracı (hakediş #' . (int)$eskiArac['hakedis_id'] . ') siparişten çıkarılamaz.', 409);
        }
    }

    $mt = musteriSubeTemsilci($db, (int)$tasiyici['musteri_id']);
    $ortak = ['musteri_id' => (int)$tasiyici['musteri_id'], 'lokasyon' => $tasiyici['lokasyon'],
              'sube' => $tasiyici['sube'] ?: $mt['sube'], 'temsilci' => $tasiyici['temsilci'] ?: $mt['temsilci'],
              'kdv_oran' => (float)($tasiyici['kdv_oran'] ?? 20)];

    $db->beginTransaction();
    try {
        $kalan = [];
        $upd = $db->prepare("
            UPDATE nakliyeler SET tip=?, nakliyeci=?, plaka=?, sofor_adi=?, yon=?, tarih=?, planlanan_tarih=?, yansitilan=?, odenen=?, aciklama=?,
                   ekipman=?, kiralama_id=?, vade_tarihi=?, tevkifat_var=?, tevkifat_orani=?, tevkifat_tutari=?, fiyat_bekliyor=?,
                   dis_sofor_ad=?, dis_sofor_tel=?, dis_sofor_donus_ad=?, dis_sofor_donus_tel=?, arac_id=?
            WHERE id=?
        ");
        foreach ($islemler as $is) {
            $g = $is['g'];
            if ($is['id'] > 0) {
                $eski = $mevcutArac[$is['id']];
                $h = nakliyeHesap($g['yansitilan'], $g['odenen'], (float)($eski['kdv_oran'] ?? 20),
                                  $eski['odeme_turu'] ?? null, (int)($eski['vade_gun'] ?? 0), $g['tarih']);
                $planlanan = (($eski['planlanan_tarih'] ?? null) === ($eski['tarih'] ?? null))
                    ? $g['tarih'] : ($eski['planlanan_tarih'] ?? null);
                $upd->execute([
                    $g['tip'], $g['nakliyeci'], $g['plaka'], $g['sofor_adi'], $g['yon'], $g['tarih'], $planlanan,
                    $h['yansitilan'], $h['odenen'], $g['aciklama'], $is['ozet'], _kkAracAnaKiralama($db, $is['kids']),
                    $h['vade_tarihi'], $h['tevkifat_var'], $h['tevkifat_orani'], $h['tevkifat_tutari'],
                    $g['fiyat_bekliyor'],
                    $g['dis_sofor_ad'], $g['dis_sofor_tel'], $g['dis_sofor_donus_ad'], $g['dis_sofor_donus_tel'],
                    $g['arac_id'] ?? null,
                    $is['id'],
                ]);
                _kkNakliyeBagla($db, $is['id'], $is['kids']);
                sfDegisirseTemizle($db, $eski, $is['id'], 'sipariş ' . $siparisNo . ' aracının kiralaması, çeşidi ya da yönü değişti', (int)($_SESSION['user_id'] ?? 0));
                $kalan[$is['id']] = true;
                logla($db, 'nakliyeler', $is['id'], 'guncelle', $eski, logKayitAl($db, 'nakliyeler', $is['id']),
                      'Sipariş ' . $siparisNo . ' nakliyesi güncellendi (kiralama ekranından)');
            } else {
                $nid = _kkSiparisNakliyeEkle($db, $siparisNo, $ortak, $g, $is['kids'], $is['ozet']);
                $kalan[$nid] = true;
                logla($db, 'nakliyeler', $nid, 'ekle', null, logKayitAl($db, 'nakliyeler', $nid),
                      'Sipariş ' . $siparisNo . ' nakliyesine araç eklendi (kiralama ekranından)');
            }
        }
        $sil = $db->prepare("DELETE FROM nakliyeler WHERE id=?");
        $silBag = $db->prepare("DELETE FROM nakliye_makineleri WHERE nakliye_id=?");
        $yuklu = $db->prepare("SELECT 1 FROM nakliye_makineleri WHERE nakliye_id=? AND (yuklendi_zaman IS NOT NULL OR COALESCE(plan_disi,0)=1) LIMIT 1");
        foreach ($mevcutArac as $nid => $eski) {
            if (isset($kalan[$nid])) continue;
            $yuklu->execute([$nid]);
            if ($yuklu->fetchColumn()) { $db->rollBack(); jsonError('Yüklenmiş araç (' . ($eski['plaka'] ?? '') . ') siparişten çıkarılamaz.', 409); }
            $silBag->execute([$nid]);
            $sil->execute([$nid]);
            logla($db, 'nakliyeler', $nid, 'sil', $eski, null, 'Sipariş ' . $siparisNo . ' nakliyesinden araç çıkarıldı (kiralama ekranından)');
        }
        siparisNakliyeSenkron($db, $siparisNo);
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    jsonOk(_kkSiparisDetay($db, $siparisNo), 'Siparişin nakliyesi güncellendi.');
}

if ($method === 'GET' && ($_GET['action'] ?? '') === 'nakliyeciler') {
    $nk = $db->query("SELECT nakliyeci, COUNT(*) adet, MAX(tarih) son
                      FROM nakliyeler WHERE nakliyeci IS NOT NULL AND TRIM(nakliyeci) <> ''
                      GROUP BY nakliyeci ORDER BY adet DESC, son DESC LIMIT 100")->fetchAll();
    jsonOk(array_column($nk, 'nakliyeci'));
}

function _koKiralama(PDO $db, int $id): array {
    if ($id < 1) jsonError('Geçersiz kiralama.');
    subeKayitDogrula($db, 'kiralamalar', $id);
    $st = $db->prepare("SELECT * FROM kiralamalar WHERE id=?");
    $st->execute([$id]);
    $k = $st->fetch();
    if (!$k) jsonError('Kiralama bulunamadı.', 404);
    return $k;
}
function _koTutarGorur(): bool { return hasPermission('kiralama_takip', 'yaz') || hasPermission('nakliye', 'oku'); }
function _koIslem(PDO $db, callable $fn): array {
    try { return islemIcinde($db, fn() => $fn()); }
    catch (DomainException $e) { jsonError($e->getMessage(), 409); }
}

if ($method === 'GET' && in_array($_GET['action'] ?? '', ['geri_al_bilgi', 'uzat_bilgi', 'tasi_bilgi'], true)) {
    header('Cache-Control: no-store');
    $k = _koKiralama($db, (int)($_GET['id'] ?? 0));
    $gorur = _koTutarGorur();
    if ($_GET['action'] === 'geri_al_bilgi') {
        $ozet = koHakedisOzet($db, (int)$k['id']);
        unset($ozet['bekleyen']);
        jsonOk(['id' => (int)$k['id'], 'kod' => $k['kod'], 'durum' => $k['durum'], 'secenekler' => koGeriAlSecenekleri($db, $k),
                'hakedis' => $ozet, 'sebep_min' => KO_SEBEP_MIN]);
    }
    if ($_GET['action'] === 'uzat_bilgi') jsonOk(koUzatBilgi($db, $k, hasPermission('kiralama_takip', 'yaz')));
    jsonOk(koTasiBilgi($db, $k, $gorur));
}

if ($method === 'POST' && in_array($_GET['action'] ?? '', ['geri_al', 'uzat', 'tarih_tasi'], true)) {
    verifyCsrf();
    requirePermission('kiralama_takip', 'yaz');
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) $body = [];
    $k = _koKiralama($db, (int)($body['id'] ?? ($_GET['id'] ?? 0)));
    if ($_GET['action'] === 'geri_al') {
        $r = _koIslem($db, fn() => koGeriAl($db, $k, (string)($body['hedef'] ?? ''), (string)($body['sebep'] ?? '')));
        jsonOk($r, $r['etiket'] . ' geri alındı → ' . $r['sonra'] . '.');
    }
    if ($_GET['action'] === 'uzat') {
        if (($k['durum'] ?? '') !== 'aktif' || !empty($k['gercek_bitis'])) jsonError('Yalnız devam eden (aktif, iade edilmemiş) kiralama uzatılabilir.', 409);
        if (empty($k['bitis'])) jsonError('Bu kiralama süresiz (bitiş tarihi yok); uzatma gerekmiyor. Bitiş vermek için Düzenle.', 409);
        try { $yeniBitis = koUzatYeniBitis((string)$k['bitis'], $body); } catch (DomainException $e) { jsonError($e->getMessage()); }
        kiralamaKaydiDogrula($db, $k['makine_tur'] ?: null, ((int)$k['makine_id']) ?: null, (string)$k['baslangic'], $yeniBitis, !empty($body['cakisma_onaylandi']), (int)$k['id']);
        $r = _koIslem($db, fn() => koUzat($db, $k, $body));
        jsonOk($r, 'Kiralama uzatıldı: yeni bitiş ' . tarihGoster($r['yeni_bitis']) . ' (' . $r['gun'] . ' gün, ' . KO_UCRET_SEKILLERI[$r['ucret_sekli']] . ').');
    }
    $alan = (string)($body['alan'] ?? '');
    $yeni = dateStr('yeni_tarih', $body);
    if (in_array($alan, ['baslangic', 'bitis'], true) && $yeni) {
        $bas = $alan === 'baslangic' ? $yeni : (string)$k['baslangic'];
        $bit = $alan === 'bitis' ? $yeni : ($k['bitis'] ?: null);
        if (!$bit || $bit >= $bas) kiralamaKaydiDogrula($db, $k['makine_tur'] ?: null, ((int)$k['makine_id']) ?: null, $bas, $bit, !empty($body['cakisma_onaylandi']), (int)$k['id']);
    }
    $r = _koIslem($db, fn() => koTarihTasi($db, $k, $body));
    jsonOk($r, $r['mesaj']);
}

function _kkSiralama(string $s, string $bugun): ?array {
    $kova = "(CASE WHEN k.durum='iptal' THEN 4 WHEN k.durum='tamamlandi' OR k.gercek_bitis IS NOT NULL THEN 3"
          . " WHEN k.baslangic > $bugun THEN 2 WHEN COALESCE(k.bitis,'')='' THEN 1 ELSE 0 END)";
    $gun  = "(CASE WHEN k.baslangic > $bugun THEN julianday(k.baslangic) - julianday($bugun)"
          . " WHEN COALESCE(k.bitis,'')<>'' THEN julianday(k.bitis) - julianday($bugun) ELSE 0 END)";
    $tanim = [
        'musteri'    => [trAramaSql('m.unvan'), 'ASC'],       'musteri_ters'    => [trAramaSql('m.unvan'), 'DESC'],
        'makine'     => [trAramaSql('k.ekipman_adi'), 'ASC'], 'makine_ters'     => [trAramaSql('k.ekipman_adi'), 'DESC'],
        'kod'        => ['k.kod', 'ASC'],                     'kod_ters'        => ['k.kod', 'DESC'],
        'kalan'      => ["($kova * 1000000 + $gun)", 'ASC'],  'kalan_ters'      => ["($kova * 1000000 - $gun)", 'ASC'],
        'baslangic'  => ['k.baslangic', 'ASC'],               'baslangic_ters'  => ['k.baslangic', 'DESC'],
    ];
    return $tanim[$s] ?? null;
}

function _kkSilHedef(PDO $db): array {
    if (($_SESSION['rol'] ?? '') !== 'admin') jsonError('Kiralamayı yalnız yönetici silebilir. Yanlış açılan kaydı gerekçeli İptal ile kapatın.', 403);
    $sp = trim(metin($_GET['siparis'] ?? ''));
    if ($sp !== '') {
        $q = $db->prepare('SELECT id FROM kiralamalar WHERE siparis_no=? ORDER BY id'); $q->execute([$sp]);
        $ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    } else {
        $ids = (int)($_GET['id'] ?? 0) > 0 ? [(int)$_GET['id']] : [];
    }
    if ($ids) { $v = $db->prepare('SELECT COUNT(*) FROM kiralamalar WHERE id IN (' . implode(',', $ids) . ')'); $v->execute(); if ((int)$v->fetchColumn() !== count($ids)) $ids = []; }
    if (!$ids) jsonError('Silinecek kiralama bulunamadı.', 404);
    foreach ($ids as $i) subeKayitDogrula($db, 'kiralamalar', $i);
    return [$ids, $sp];
}

function _kkSilCalistir(PDO $db, array $ids): array {
    $sonuc = []; $toplam = [];
    foreach ($ids as $i) {
        $mevcut = logKayitAl($db, 'kiralamalar', $i);
        if (!$mevcut) continue;
        $guncel = ($mevcut['durum'] ?? '') === 'aktif' && operasyonKiralamaGuncelMi($db, $mevcut);
        $r = kiralamaTamSil($db, $i, $ids);
        if ($guncel) kiralamaMakineDurumu($db, $mevcut['makine_tur'] ?? null, (int)($mevcut['makine_id'] ?? 0) ?: null, 'teslim');
        foreach ($r['sayim'] as $t => $n) $toplam[$t] = ($toplam[$t] ?? 0) + $n;
        $sonuc[] = $r;
    }
    foreach (array_unique(array_filter(array_map(fn($r) => (string)($r['kiralama']['siparis_no'] ?? ''), $sonuc))) as $sp) siparisNakliyeSenkron($db, $sp);
    return [$sonuc, $toplam];
}

if ($method === 'GET' && ($_GET['action'] ?? '') === 'sil_onizle') {
    [$silIdler, $silSp] = _kkSilHedef($db);
    $db->beginTransaction();
    try {
        [$silSonuc, $silToplam] = _kkSilCalistir($db, $silIdler);
        $para = ['hakedis' => 0.0, 'tahsilat' => 0.0];
        foreach ($silSonuc as $r) {
            foreach ($r['yedek']['hakedisler'] ?? [] as $h) $para['hakedis'] += (float)($h['genel_toplam'] ?? 0);
            foreach ($r['yedek']['tahsilatlar'] ?? [] as $t) $para['tahsilat'] += (float)($t['tutar'] ?? 0);
        }
        $kodlar = array_map(fn($r) => (string)($r['kiralama']['kod'] ?? ('#' . $r['kiralama']['id'])), $silSonuc);
    } catch (DomainException $e) {
        if ($db->inTransaction()) $db->rollBack();
        jsonError($e->getMessage(), 409);
    } finally {
        if ($db->inTransaction()) $db->rollBack();
    }
    jsonOk(['adet' => count($silIdler), 'siparis_no' => $silSp ?: null, 'kodlar' => $kodlar, 'ozet' => kiralamaSilOzet($silToplam),
            'hakedis_toplam' => round($para['hakedis'], 2), 'tahsilat_toplam' => round($para['tahsilat'], 2)]);
}

switch ($method) {

    case 'GET':
        if (isset($_GET['id'])) {
            $id = (int)$_GET['id'];
            $stmt = $db->prepare("
                SELECT k.*, m.unvan AS musteri_unvan, m.telefon AS musteri_telefon, m.adres AS musteri_adres, m.yetkili AS musteri_yetkili,
                       m.vergi_no, m.vergi_dairesi, ml.kod AS manlift_kod,
                       p.ad_soyad AS operator_ad
                FROM kiralamalar k
                JOIN musteriler m ON m.id = k.musteri_id
                LEFT JOIN manliftler ml ON ml.id = k.manlift_id
                LEFT JOIN personel p ON p.id = k.operator_id
                WHERE k.id=?
            ");
            $stmt->execute([$id]);
            $k = $stmt->fetch();
            if (!$k) jsonError('Kiralama bulunamadı.', 404);
            subeErisimDogrula($k['sube'] ?? null);
            $k['hesap'] = kiralamaTutar($k);
            $k['makine_kunye'] = kiralamaMakineKunye($db, $k);
            $k['sozlesme_sartlari'] = sozlesmeSartlari($db);
            if (!empty($_GET['belge'])) {
                require_once __DIR__ . '/tutanak-yaz.php';
                $k['firma_imza'] = firmaImzasi($db, $id);
                require_once __DIR__ . '/kiralama-protokol-lib.php';
                $k['kiraci_imza'] = kpSozlesmeImzasi($db, $id);
            }

            $altSube = subeKisiti();
            $altKayitFiltre = fn(array $r): bool => $altSube === null || ($r['sube'] ?? '') === $altSube;
            $tStmt = $db->prepare("SELECT * FROM tahsilatlar WHERE kiralama_id=? OR hakedis_id IN (SELECT id FROM hakedisler WHERE kiralama_id=?) ORDER BY tarih DESC, id DESC");
            $tStmt->execute([$id, $id]);
            $k['tahsilatlar'] = array_values(array_filter($tStmt->fetchAll(), $altKayitFiltre));

            $hStmt = $db->prepare("SELECT id, kod, donem_bas, donem_bit, aciklama, tutar, kdv_tutar, genel_toplam, durum, fatura_no, fatura_tarihi, sube FROM hakedisler WHERE kiralama_id=? ORDER BY donem_bas DESC, id DESC");
            $hStmt->execute([$id]);
            $k['hakedisler'] = array_values(array_filter($hStmt->fetchAll(), $altKayitFiltre));

            $itStmt = $db->prepare("
                SELECT t.id, t.islem, t.tarih, t.genel_durum, t.hasar_notu, t.km, t.calisma_saati, t.yakit_seviyesi, t.asama, t.inceleme_sonucu,
                       t.musteri_temsilci_ad, COALESCE(p.ad_soyad, t.imza_personel_ad) AS personel_ad,
                       (SELECT COUNT(*) FROM iade_tutanak_fotograflari f WHERE f.tutanak_id = t.id) AS tutanak_foto_adet,
                       (SELECT COUNT(*) FROM kiralama_fotograflari kf WHERE kf.kiralama_id = t.kiralama_id
                          AND COALESCE(kf.yon, 'gidis') = CASE WHEN t.islem = 'teslim' THEN 'gidis' ELSE 'donus' END) AS depo_foto_adet
                FROM iade_tutanaklari t LEFT JOIN personel p ON p.id = t.personel_id
                WHERE t.kiralama_id=? ORDER BY t.tarih DESC, t.id DESC
            ");
            $itStmt->execute([$id]);
            $k['tutanaklar'] = array_map(function (array $t): array {
                $kendi = (int)$t['tutanak_foto_adet']; $depo = (int)$t['depo_foto_adet'];
                $t['foto_adet'] = $t['islem'] === 'teslim' ? ($kendi > 0 ? $kendi : $depo) : $kendi + $depo;
                return $t;
            }, $itStmt->fetchAll());

            $pStmt = $db->prepare("
                SELECT COALESCE(SUM(CASE durum WHEN 'tam' THEN 1 WHEN 'yarim' THEN 0.5 ELSE 0 END),0) AS calisma_gun,
                       COALESCE(SUM(saat),0) AS toplam_saat, COALESCE(SUM(mesai),0) AS toplam_mesai, COUNT(*) AS kayit_adet
                FROM kiralama_puantaj WHERE kiralama_id=?
            ");
            $pStmt->execute([$id]);
            $k['puantaj_ozet'] = $pStmt->fetch() ?: null;

            try {
                $odenenGorur    = hasPermission('nakliye', 'oku');
                $yansitilanGorur = $odenenGorur || hasPermission('kiralama_takip', 'yaz');
                $sipNo = trim((string)($k['siparis_no'] ?? ''));
                $nSt = $db->prepare("SELECT n.id, n.tarih, n.planlanan_tarih, n.yon, n.tip, n.aciklama, n.odenen, n.yansitilan, n.plaka, n.arac_id, n.nakliyeci,
                                            n.ekipman, n.lokasyon, n.sube, n.siparis_no, n.kiralama_id, n.sofor_adi,
                                            n.dis_sofor_ad, n.dis_sofor_donus_ad, p.ad_soyad AS sofor_ad
                                     FROM nakliyeler n LEFT JOIN personel p ON p.id = n.sofor_personel_id
                                     WHERE n.kiralama_id=? OR n.id IN (SELECT nakliye_id FROM nakliye_makineleri WHERE kiralama_id=?)
                                        OR (? <> '' AND n.siparis_no = ?)
                                     ORDER BY n.tarih DESC, n.id DESC");
                $nSt->execute([$id, $id, $sipNo, $sipNo]);
                $nakListe = array_values(array_filter($nSt->fetchAll(), $altKayitFiltre));
                $nakBag = [];
                if ($nakListe) {
                    $nIds = array_map('intval', array_column($nakListe, 'id'));
                    $bSt = $db->prepare("SELECT nakliye_id, kiralama_id FROM nakliye_makineleri WHERE nakliye_id IN ("
                                        . implode(',', array_fill(0, count($nIds), '?')) . ")");
                    $bSt->execute($nIds);
                    foreach ($bSt->fetchAll() as $b) $nakBag[(int)$b['nakliye_id']][] = (int)$b['kiralama_id'];
                }
                foreach ($nakListe as &$n) {
                    $n['makineler'] = $nakBag[(int)$n['id']] ?? [];
                    if (!$odenenGorur)     unset($n['odenen']);
                    if (!$yansitilanGorur) unset($n['yansitilan']);
                }
                unset($n);
                $k['nakliyeler'] = $nakListe;
                $k['nakliye_tutar'] = ['odenen' => $odenenGorur, 'yansitilan' => $yansitilanGorur];
            } catch (\Exception $e) { $k['nakliyeler'] = []; $k['nakliye_tutar'] = ['odenen' => false, 'yansitilan' => false]; }

            if (!empty($k['makine_tur']) && !empty($k['makine_id'])) {
                $tbl = $k['makine_tur'] === 'manlift' ? 'manliftler' : 'araclar';
                $adKol = $k['makine_tur'] === 'manlift' ? 'kod' : 'plaka';
                $mStmt = $db->prepare("SELECT id, $adKol AS ad, marka, model, sube, durum FROM $tbl WHERE id=?");
                $mStmt->execute([(int)$k['makine_id']]);
                $k['makine'] = $mStmt->fetch() ?: null;
                if ($k['makine']) {
                    $mBilgi = makineBilgiToplu($db, [['tur' => (string)$k['makine_tur'], 'id' => (int)$k['makine_id']]]);
                    $k['makine']['durum_ham'] = $k['makine']['durum'];
                    $k['makine']['durum'] = $mBilgi[$k['makine_tur'] . ':' . (int)$k['makine_id']]['durum'] ?? $k['makine']['durum'];
                }
            }
            $k['akis'] = kiralamaAkis($db, $k, hasPermission('nakliye', 'oku'), subeKisiti(), date('Y-m-d'));
            $opD1 = opYeniZincir($db, $k) ? opAdimDurumu($db, (int)$k['id']) : null;
            $dsId = $opD1 !== null ? (opDepoServisAcikToplu($db, [(int)$k['id']])[(int)$k['id']] ?? null) : null;
            $k['gorunen_durum'] = opKiralamaGorunenDurum($k, $opD1, $dsId !== null);
            $k['op_servis_id']  = $k['gorunen_durum'] === 'serviste' ? $dsId : null;
            $k += koGecmis($db, $id, _koTutarGorur(), hasPermission('kiralama_takip', 'yaz'));
            jsonOk($k);
        }

        $whereBase = []; $paramsBase = [];
        if (($sb = trim(metin($_GET['sube'] ?? ''))) !== '') { $whereBase[] = "k.sube=?"; $paramsBase[] = $sb; }
        subeKosulEkle($whereBase, $paramsBase, 'k.sube');
        if (($tm = trim(metin($_GET['temsilci'] ?? ''))) !== '') { $whereBase[] = "k.temsilci=?"; $paramsBase[] = $tm; }
        if (($mid = (int)($_GET['musteri_id'] ?? 0)) > 0) { $whereBase[] = "k.musteri_id=?"; $paramsBase[] = $mid; }
        if (($dk = trim(metin($_GET['dis_kiralama'] ?? ''))) !== '' && in_array($dk, ['0','1'], true)) { $whereBase[] = $dk === '1' ? "dk.id IS NOT NULL" : "dk.id IS NULL"; }
        if (($q = trim(metin($_GET['q'] ?? ''))) !== '') {
            $araKosul = array_map(fn($a) => trAramaSql($a) . " LIKE ?",
                ['k.ekipman_adi', 'k.kod', 'm.unvan', 'k.lokasyon', 'k.siparis_no', 'k.seri_no', 'k.makine_kodu']);
            $araKosul[] = "EXISTS (SELECT 1 FROM manliftler x WHERE k.makine_tur='manlift' AND x.id=k.makine_id AND " . trAramaSql('x.kod') . " LIKE ?)";
            $araKosul[] = "EXISTS (SELECT 1 FROM araclar x WHERE k.makine_tur='arac' AND x.id=k.makine_id AND " . trAramaSql('x.plaka') . " LIKE ?)";
            $whereBase[] = "(" . implode(' OR ', $araKosul) . ")";
            array_push($paramsBase, ...array_fill(0, count($araKosul), '%' . trArama($q) . '%'));
        }
        $fromSql = " FROM kiralamalar k JOIN musteriler m ON m.id = k.musteri_id LEFT JOIN dis_kiralama_kayitlari dk ON dk.id=(SELECT d.id FROM dis_kiralama_kayitlari d WHERE d.makine_tur=k.makine_tur AND d.makine_id=k.makine_id AND d.temin_baslangic<=k.baslangic AND (d.temin_bitis IS NULL OR d.temin_bitis>=k.baslangic) ORDER BY d.id DESC LIMIT 1)";
        $baseSql = $fromSql . ($whereBase ? " WHERE " . implode(' AND ', $whereBase) : "");

        $ozet = ['aktif' => 0, 'tamamlandi' => 0, 'iptal' => 0, 'bakimda' => 0, 'serviste' => 0, 'aktif_tutar' => 0.0];
        $bakimdaSekme = opBakimdaSekmesiSql('k');
        $sSt = $db->prepare("SELECT " . opGorunenDurumSql('k') . " AS d, COUNT(*) n" . $baseSql . " GROUP BY d");
        $sSt->execute($paramsBase);
        foreach ($sSt->fetchAll() as $x) $ozet[$x['d']] = (int)$x['n'];
        $aSt = $db->prepare("SELECT k.*" . $baseSql . ($whereBase ? " AND" : " WHERE") . " k.durum='aktif'");
        $aSt->execute($paramsBase);
        foreach ($aSt->fetchAll() as $a) $ozet['aktif_tutar'] += kiralamaTutar($a)['genel_toplam'];
        $ozet['aktif_tutar'] = round($ozet['aktif_tutar'], 2);

        $where = $whereBase; $params = $paramsBase;
        $d = trim(metin($_GET['durum'] ?? ''));
        if ($d === 'bakimda') $where[] = $bakimdaSekme;
        elseif ($d === 'tamamlandi') { $where[] = "k.durum='tamamlandi'"; $where[] = "NOT $bakimdaSekme"; }
        elseif ($d !== '' && $d !== 'hepsi') { $where[] = "k.durum=?"; $params[] = $d; }
        $sql = "
            SELECT k.*, m.unvan AS musteri_unvan, m.telefon AS musteri_telefon, ml.kod AS manlift_kod,
                   CASE WHEN dk.id IS NULL THEN 0 ELSE 1 END AS dis_kiralama,
                   dk.id AS dis_kiralama_id, dk.tedarikci_id AS dis_tedarikci_id,
                   dk.maliyet_tipi AS dis_maliyet_tipi, dk.maliyet_birim_tutari AS dis_maliyet_birim_tutari,
                   dk.maliyet_para_birimi AS dis_maliyet_para_birimi, td.unvan AS dis_tedarikci_unvan"
            . $fromSql . "
            LEFT JOIN manliftler ml ON ml.id = k.manlift_id
            LEFT JOIN tedarikciler td ON td.id = dk.tedarikci_id
        ";
        $whereSql = $where ? " WHERE " . implode(' AND ', $where) : "";
        $ustte = isset($_GET['page']) ? "(k.durum='aktif' OR $bakimdaSekme)" : "(k.durum='aktif')";
        $sira = " ORDER BY $ustte DESC, k.baslangic DESC, k.id DESC";
        $grupSira = "a DESC, b DESC, i DESC";
        $grupSiraSec = '';
        if ($siraTanim = _kkSiralama((string)($_GET['siralama'] ?? ''), $db->quote(date('Y-m-d')))) {
            [$sIfade, $sYon] = $siraTanim;
            $sira = " ORDER BY $sIfade $sYon, k.id DESC";
            $grupSiraSec = ', ' . ($sYon === 'DESC' ? 'MAX' : 'MIN') . "($sIfade) AS s";
            $grupSira = "s $sYon, i DESC";
        }

        $sayfalama = null; $gruplar = null;
        $grupla = isset($_GET['page']) && (string)($_GET['grupla'] ?? '') === '1';
        if ($grupla) {
            $gk = "COALESCE(NULLIF(k.siparis_no,''), 'K' || k.id)";
            $gSql = "SELECT $gk AS gk, MAX($ustte) AS a, MAX(k.baslangic) AS b, MAX(k.id) AS i$grupSiraSec"
                  . $fromSql . $whereSql . " GROUP BY $gk";
            $sayfa   = sayfaNo();
            $perPage = min(200, max(10, (int)($_GET['per_page'] ?? 50)));
            [$toplam, $sayfaSayisi] = sayfalamaBilgisi($db, $gSql, $params, $perPage);
            $sayfa = min($sayfa, max(1, $sayfaSayisi));
            $gSt = $db->prepare($gSql . " ORDER BY $grupSira LIMIT ? OFFSET ?");
            $gSt->execute(array_merge($params, [$perPage, ($sayfa - 1) * $perPage]));
            $gruplar = array_map('strval', $gSt->fetchAll(PDO::FETCH_COLUMN));
            $kSt = $db->prepare("SELECT COUNT(*)" . $fromSql . $whereSql);
            $kSt->execute($params);
            $sayfalama = ['sayfa' => $sayfa, 'sayfa_sayisi' => $sayfaSayisi, 'toplam' => $toplam, 'per_page' => $perPage,
                          'grup' => true, 'kiralama_toplam' => (int)$kSt->fetchColumn()];
            $rows = [];
            if ($gruplar) {
                $stmt = $db->prepare($sql . ($whereSql ? $whereSql . " AND " : " WHERE ")
                    . "$gk IN (" . implode(',', array_fill(0, count($gruplar), '?')) . ")" . $sira);
                $stmt->execute(array_merge($params, $gruplar));
                $rows = $stmt->fetchAll();
            }
        } else {
            $sql .= $whereSql . $sira;
            if (isset($_GET['page'])) {
                $sayfa   = sayfaNo();
                $perPage = min(200, max(10, (int)($_GET['per_page'] ?? 50)));
                [$toplam, $sayfaSayisi] = sayfalamaBilgisi($db, $sql, $params, $perPage);
                $sayfa = min($sayfa, max(1, $sayfaSayisi));
                $sql .= " LIMIT ? OFFSET ?";
                $params[] = $perPage; $params[] = ($sayfa - 1) * $perPage;
                $sayfalama = ['sayfa' => $sayfa, 'sayfa_sayisi' => $sayfaSayisi, 'toplam' => $toplam, 'per_page' => $perPage];
            }
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
        }

        $tutanakVar = []; $hasarVar = []; $fotoAdet = [];
        if ($rows) {
            $ids = array_column($rows, 'id');
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $fSt = $db->prepare("SELECT kiralama_id, COUNT(*) n FROM kiralama_fotograflari WHERE kiralama_id IN ($ph) GROUP BY kiralama_id");
            $fSt->execute($ids);
            foreach ($fSt->fetchAll() as $f) $fotoAdet[(int)$f['kiralama_id']] = (int)$f['n'];
            $tSt = $db->prepare("SELECT kiralama_id, MAX(genel_durum='hasarli') hasar FROM iade_tutanaklari WHERE kiralama_id IN ($ph) GROUP BY kiralama_id");
            $tSt->execute($ids);
            foreach ($tSt->fetchAll() as $t) {
                $tutanakVar[(int)$t['kiralama_id']] = true;
                if ((int)$t['hasar'] === 1) $hasarVar[(int)$t['kiralama_id']] = true;
            }
        }

        $bilgi = $rows ? makineBilgiToplu($db, array_map(fn($r) => ['tur' => $r['makine_tur'], 'id' => (int)$r['makine_id']], $rows)) : [];
        $sonHareket = $rows ? _kkMakineSonHareket($db, $rows, date('Y-m-d')) : [];
        $disMaliyetGizli = !hasPermission('dis_kiralama', 'oku');
        $opDurum = opAdimDurumuToplu($db, $rows);
        $servisAcik = opDepoServisAcikToplu($db, array_keys($opDurum));

        foreach ($rows as &$r) {
            if ($disMaliyetGizli) unset($r['dis_maliyet_tipi'], $r['dis_maliyet_birim_tutari'], $r['dis_maliyet_para_birimi']);
            $spNo = (string)($r['siparis_no'] ?? '');
            $r['gk'] = $spNo !== '' ? $spNo : 'K' . (int)$r['id'];
            $r['hesap'] = kiralamaTutar($r);
            $r['tutanak_var'] = isset($tutanakVar[(int)$r['id']]);
            $r['hasar_var']   = isset($hasarVar[(int)$r['id']]);
            $r['foto_adet']   = $fotoAdet[(int)$r['id']] ?? 0;
            $mAnahtar = (in_array($r['makine_tur'] ?? '', ['manlift', 'arac'], true) && (int)($r['makine_id'] ?? 0) > 0)
                ? $r['makine_tur'] . ':' . (int)$r['makine_id'] : null;
            $b = $mAnahtar ? ($bilgi[$mAnahtar] ?? null) : null;
            $r['makine_kod']      = $b['kod'] ?? null;
            $r['makine_marka']    = $b['marka'] ?? null;
            $r['makine_model']    = $b['model'] ?? null;
            $r['makine_kategori'] = $b['kategori'] ?? null;
            $r['makine_olcu_birim'] = $b['olcu_birim'] ?? null;
            $r['makine_durum']    = $b['durum'] ?? null;
            if ($b) {
                foreach (['metraj', 'seri_no', 'makine_kodu'] as $alan) {
                    if (($r[$alan] ?? null) === null || $r[$alan] === '') $r[$alan] = $b[$alan];
                }
            }
            $son = $mAnahtar ? ($sonHareket[$mAnahtar] ?? null) : null;
            $rb  = (string)$r['baslangic'];
            $r['hareket_guncel'] = $mAnahtar !== null && ($r['durum'] ?? '') !== 'iptal'
                && (!$son || !(strcmp($son['b'], $rb) > 0 || ($son['b'] === $rb && $son['i'] > (int)$r['id'])));
            $opD = $opDurum[(int)$r['id']] ?? null;
            $r['op_yeni'] = $opD !== null;
            $r['op_siradaki'] = $opD !== null ? opSiradakiHesapla($opD, $r) : null;
            $r['op_hazirlik_atlandi'] = ($opD['depo_hazirlik']['sonuc'] ?? null) === 'atlandi';
            $r['op_siradaki_etiket'] = $r['op_siradaki'] ? OP_ADIMLAR[$r['op_siradaki']] : null;
            $dsId = $servisAcik[(int)$r['id']] ?? null;
            $r['gorunen_durum'] = opKiralamaGorunenDurum($r, $opD, $dsId !== null);
            $r['op_servis_id']  = $r['gorunen_durum'] === 'serviste' ? $dsId : null;
        }
        unset($r);
        $yanit = ['list' => $rows, 'ozet' => $ozet, 'sayfalama' => $sayfalama];
        if ($gruplar !== null) $yanit['gruplar'] = $gruplar;
        $yanit['op_yetki'] = ['depo' => opOturumYetkisi($db, 'depo'), 'yukleme' => opOturumYetkisi($db, 'yukleme'),
                              'saha' => opOturumYetkisi($db, 'saha'), 'atlama' => opOturumYetkisi($db, 'atlama')];
        jsonOk($yanit);
        break;

    case 'POST':
        verifyCsrf();
        $body       = govdeOku();
        $musteriId  = (int)($body['musteri_id'] ?? 0);
        $ekipman    = trim(metin($body['ekipman_adi'] ?? ''));
        $baslangic  = dateStr('baslangic', $body);

        if ($musteriId < 1) jsonError('Müşteri seçilmelidir.');
        if (!$baslangic)     jsonError('Başlangıç tarihi zorunludur.');

        $mChk = $db->prepare("SELECT COUNT(*) FROM musteriler WHERE id=?");
        $mChk->execute([$musteriId]);
        if ((int)$mChk->fetchColumn() === 0) jsonError('Seçilen müşteri bulunamadı.');

        $bitis      = dateStr('bitis', $body);
        $gercekBit  = dateStr('gercek_bitis', $body);
        kiralamaTarihDogrula($baslangic, $bitis, $gercekBit);

        $makineTur = in_array($body['makine_tur'] ?? '', ['manlift','arac'], true) ? $body['makine_tur'] : null;
        $makineId  = ((int)($body['makine_id'] ?? 0)) ?: null;
        if (!$makineTur || !$makineId) { $makineTur = null; $makineId = null; }
        $manliftId = ($makineTur === 'manlift') ? $makineId : (((int)($body['manlift_id'] ?? 0)) ?: null);
        $durum     = _kiraDurum($body['durum'] ?? 'aktif');
        if ($ekipman === '') $ekipman = _kkEkipmanAdiVarsayilan($db, $makineTur, $makineId);

        $operatorlu = !empty($body['operatorlu']) ? 1 : 0;
        $operatorId = $operatorlu ? (((int)($body['operator_id'] ?? 0)) ?: null) : null;
        $operatorUcret = $operatorlu ? max(0, (float)($body['operator_ucret'] ?? 0)) : 0;
        $operatorTip = in_array($body['operator_ucret_tipi'] ?? '', ['gunluk','aylik','sabit'], true) ? $body['operator_ucret_tipi'] : 'gunluk';

        $iskontoOran = max(0, min(100, (float)($body['iskonto_oran'] ?? 0)));
        $onayDurumu  = iskontoOnayDurumuHesapla($db, $iskontoOran, 'gerekmiyor');

        kiralamaKaydiDogrula($db, $makineTur, $makineId, $baslangic, $bitis, !empty($body['cakisma_onaylandi']));

        $db->beginTransaction();
        try {
        $stmt = $db->prepare("
            INSERT INTO kiralamalar
              (musteri_id, manlift_id, makine_tur, makine_id, ekipman_adi, baslangic, bitis, gercek_bitis,
               kira_tipi, birim_ucret, miktar, nakliye_ucret, nakliye_maliyet, nakliye_tip, nakliye_yon, nakliyeci, lokasyon, sube, temsilci, kdv_oran, durum, notlar,
               operatorlu, operator_id, operator_ucret, operator_ucret_tipi,
               sozlesme_no, sozlesme_imza_tarihi, otomatik_yenileme, iskonto_oran, onay_durumu, il, doviz,
               metraj, seri_no, makine_kodu, odeme_turu, vade_gun, olusturan_id, ilce, santiye_adres)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $kunye = _kkMakineKunye($db, $makineTur, $makineId, $body);
        $stmt->execute([
            $musteriId, $manliftId, $makineTur, $makineId, $ekipman, $baslangic, $bitis, $gercekBit,
            _kiraTip($body['kira_tipi'] ?? 'gunluk'),
            max(0, (float)($body['birim_ucret'] ?? 0)),
            max(1, (int)($body['miktar'] ?? 1)),
            max(0, (float)($body['nakliye_ucret'] ?? 0)),
            max(0, (float)($body['nakliye_maliyet'] ?? 0)),
            _kkNakTip($body['nakliye_tip'] ?? null),
            _kkNakYon($body['nakliye_yon'] ?? null),
            trim(metin($body['nakliyeci'] ?? '')) ?: null,
            trim(metin($body['lokasyon'] ?? '')) ?: null,
            kayitSubesi(metin($body['sube'] ?? '')),
            trim(metin($body['temsilci'] ?? '')) ?: null,
            max(0, (float)($body['kdv_oran'] ?? 20)),
            $durum,
            trim(metin($body['notlar'] ?? '')) ?: null,
            $operatorlu, $operatorId, $operatorUcret, $operatorTip,
            trim(metin($body['sozlesme_no'] ?? '')) ?: null,
            dateStr('sozlesme_imza_tarihi', $body),
            !empty($body['otomatik_yenileme']) ? 1 : 0,
            $iskontoOran, $onayDurumu,
            ilYazim(metin($body['il'] ?? '')) ?: null,
            in_array($body['doviz'] ?? '', ['TL','EUR','USD'], true) ? $body['doviz'] : 'TL',
            $kunye['metraj'], $kunye['seri_no'], $kunye['makine_kodu'],
            nakliyeOdemeTuru($body['odeme_turu'] ?? null),
            max(0, min(NAKLIYE_VADE_MAX, (int)($body['vade_gun'] ?? 0))),
            ((int)($_SESSION['user_id'] ?? 0)) ?: null,
            kiralamaMetinAlan($body, 'ilce', 80), kiralamaMetinAlan($body, 'santiye_adres', 500),
        ]);
        $id = (int)$db->lastInsertId();

        dkMaliyetKaydet($db,$id);

        $yil = substr($baslangic, 0, 4);
        $kod = 'KR-' . $yil . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
        $sozlesmeNo = trim(metin($body['sozlesme_no'] ?? '')) ?: ('SZ-' . $yil . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT));
        $db->prepare("UPDATE kiralamalar SET kod=?, sozlesme_no=? WHERE id=?")->execute([$kod, $sozlesmeNo, $id]);

        if ($baslangic >= date('Y-m-d')) {
            kiralamaMakineDurumu($db, $makineTur, $makineId ?: null, 'siparis');
        }

        $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        $riskUyari = _kkRiskUyari($db, $musteriId);

        logla($db, 'kiralamalar', $id, 'ekle', null, logKayitAl($db, 'kiralamalar', $id),
              'Kiralama ' . $kod . ' açıldı' . ($iskontoOran > 0 ? ' · iskonto %' . $iskontoOran : ''));
        jsonOk(['id' => $id, 'kod' => $kod, 'risk_uyari' => $riskUyari, 'onay_durumu' => $onayDurumu], 'Kiralama kaydedildi.');

    case 'PUT':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $body = govdeOku();

        subeKayitDogrula($db, 'kiralamalar', $id);
        $cur = $db->prepare("SELECT * FROM kiralamalar WHERE id=?");
        $cur->execute([$id]);
        $mevcut = $cur->fetch();
        if (!$mevcut) jsonError('Kiralama bulunamadı.', 404);
        $hareketGuncel = operasyonKiralamaGuncelMi($db, $mevcut);

        if (isset($body['makine_olay'])) {
            $olay = (string)$body['makine_olay'];
            if (!in_array($olay, ['siparis','teslim','nakliye','toplaniyor','iade','bakima','bakim_bitti'], true)) {
                jsonError('Geçersiz makine olayı.');
            }
            if (!operasyonKiralamaGuncelMi($db, $mevcut, in_array($olay, ['bakima','bakim_bitti'], true)))
                jsonError('Bu kiralama makinenin güncel işi değil. Hareketi güncel kayıttan yapın.', 409);
            $opYeni = opYeniZincir($db, $mevcut);
            $opAdim = ['nakliye' => 'yukleme', 'teslim' => 'saha_teslim', 'toplaniyor' => 'toplaniyor', 'iade' => 'saha_teslim_alma'][$olay] ?? null;
            if ($opYeni && in_array($olay, ['teslim', 'iade'], true))
                jsonError('Yeni kiralamalarda sahada ' . ($olay === 'teslim' ? 'teslim' : 'teslim alma')
                        . ' imzalı kayıtla yapılır; menüden imzasız işaretlenemez. Sahada Teslim ekranından kaydedin.', 409,
                          ['saha_ekrani' => 'saha-teslim.html?kiralama=' . $id . '&islem=' . ($olay === 'teslim' ? 'teslim' : 'iade')]);
            if ($opYeni && $opAdim && ($opY = OP_ADIM_YETKI[$opAdim]) && !opOturumYetkisi($db, $opY))
                jsonError('Bu adım için "' . OP_YETKILER[$opY] . '" yetkiniz yok.', 403);
            if(in_array($olay,['nakliye','teslim'],true)&&($e=hzMakineEngel($db,(string)$mevcut['makine_tur'],(int)$mevcut['makine_id'])))jsonError($e,409,['hazirlik_gerekli'=>true]);
            if($olay==='bakim_bitti'){
                require_once __DIR__.'/bakim-onay.php';
                $sonuc=bakimDogrudanBitir($db,$mevcut,($body['bakim_atlama_onayi']??false)===true);
                if(!empty($sonuc['onay_gerekli']))jsonError('Makine bakım bekliyor. Bakıma alındı aşamasını atlayarak bakımın bittiğini ve makinenin yeniden kiralamaya hazır olduğunu onaylıyor musunuz? Onayınız kullanıcı adınız ve tarih-saat ile kaydedilecek.',409,['onay_kodu'=>'BAKIM_ASAMA_ATLAMA']);
                if(!empty($sonuc['engel']))jsonError($sonuc['engel'],409);
                if($sonuc['handled'])jsonOk(['makine_durum'=>makineDurumu($db,(string)$mevcut['makine_tur'],(int)$mevcut['makine_id'])],'Bakım tamamlandı; aşama atlama onayı denetim kaydına işlendi.');
            }
            $tb=$mevcut['makine_tur']==='arac'?'araclar':'manliftler';
            $q=$db->prepare("SELECT durum FROM $tb WHERE id=?");$q->execute([$mevcut['makine_id']]);$once=$q->fetchColumn();
            if(!$once)jsonError('Makine bulunamadı.',404);
            if(in_array($once,['satildi','pasif'],true)||makineAcikServisVarMi($db,$mevcut['makine_tur'],(int)$mevcut['makine_id']))jsonError('Makine pasif, satılmış veya açık serviste; bu geçiş yapılamaz.',409);
            if($once==='inceleme_bekliyor'&&$olay!=='iade')jsonError(makineDonusEngeli($db,(string)$mevcut['makine_tur'],(int)$mevcut['makine_id']),409);
            $uyari=akisHareketUyarisi($once,$olay);
            if ($uyari && $opYeni && $opAdim && !opOturumYetkisi($db, 'atlama'))
                jsonError('Adım atlama yetkiniz yok; sıradaki adımı yapın ya da yöneticinize başvurun.', 403);
            if($uyari)akisOnayIste($body,[$id,$mevcut['makine_id'],$once,$olay],$uyari);
            $db->beginTransaction();
            try {
                $q->execute([$mevcut['makine_id']]);if($q->fetchColumn()!==$once)throw new DomainException('Makine durumu değişti. Listeyi yenileyin.');
                kiralamaMakineDurumu($db,$mevcut['makine_tur'],(int)$mevcut['makine_id'],$olay==='iade'?opIadeOlayi($db,$mevcut):$olay);
                $q->execute([$mevcut['makine_id']]);$sonra=$q->fetchColumn();
                $yeniDurum=makineDurumu($db,$mevcut['makine_tur'],(int)$mevcut['makine_id']);
                akisDenetimYaz($db,$mevcut,['durum'=>$once],['durum'=>$sonra,'olay'=>$olay,'uyari_onaylandi'=>(bool)$uyari],($uyari?'Uyarı onaylandı: ':'').'Makine hareketi '.$olay);
                if ($opYeni && $opAdim && (opAdimDurumu($db, $id)[$opAdim]['sonuc'] ?? null) !== 'tamam')
                    opAdimYaz($db, $id, $opAdim, 'tamam', opOturumYapan($db), 'menu', $uyari ?: null);
                $db->commit();
            }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
            jsonOk(['makine_durum' => $yeniDurum], 'Makine durumu güncellendi.');
        }

        $akisNot=akisDurumOnayi($db,$mevcut,$body);
        if (!array_diff(array_keys($body),['durum','gercek_bitis','iptal_nedeni','akis_onay_token','musteri_kaynakli','sefer_ucreti_al','sefer_tutar']) && isset($body['durum'])) {
            $durum = _kiraDurum($body['durum']);
            $iptalNedeni = trim(metin($body['iptal_nedeni'] ?? ''));
            if ($durum === 'iptal' && $iptalNedeni === '') {
                jsonError('İptal gerekçesi zorunludur.');
            }
            $gercekBitGirilen = dateStr('gercek_bitis', $body);
            $gercekBit = $durum==='aktif'&&$akisNot ? null : ($gercekBitGirilen ?: ($mevcut['gercek_bitis'] ?: null));
            if ($durum === 'tamamlandi' && !$gercekBit) {
                $gercekBit = max(date('Y-m-d'), (string)($mevcut['baslangic'] ?? ''));
            }
            kiralamaTarihDogrula($mevcut['baslangic'] ?? null, null, $gercekBitGirilen);
            $db->beginTransaction();
            try {
            $db->prepare("UPDATE kiralamalar SET durum=?, gercek_bitis=?, iptal_nedeni=?, iptal_tarihi=?, updated_at=datetime('now','localtime') WHERE id=?")
               ->execute([
                   $durum, $gercekBit,
                   $durum === 'iptal' ? $iptalNedeni : ($mevcut['iptal_nedeni'] ?? null),
                   $durum === 'iptal' ? date('Y-m-d') : ($mevcut['iptal_tarihi'] ?? null),
                   $id,
               ]);
            $seferHid = null;
            if ($durum === 'iptal' && ($mevcut['durum'] ?? '') !== 'iptal') {
                try { $seferHid = koIptalSeferi($db, $mevcut, $body, $iptalNedeni); }
                catch (DomainException $e) { $db->rollBack(); jsonError($e->getMessage()); }
            }
            if ($hareketGuncel && $durum === 'tamamlandi') {
                kiralamaMakineDurumu($db, $mevcut['makine_tur'] ?? null,
                    (int)($mevcut['makine_id'] ?? 0) ?: null, opIadeOlayi($db, $mevcut));
            }
            if ($durum === 'tamamlandi') opDonusKapandi($db, $mevcut, $hareketGuncel);
            if ($hareketGuncel && $durum === 'iptal') {
                kiralamaMakineDurumu($db, $mevcut['makine_tur'] ?? null,
                    (int)($mevcut['makine_id'] ?? 0) ?: null, 'teslim');
            }
            if (!empty($mevcut['siparis_no'])) {
                siparisNakliyeSenkron($db, (string)$mevcut['siparis_no']);
            }
            akisDenetimYaz($db,$mevcut,['durum'=>$mevcut['durum'],'gercek_bitis'=>$mevcut['gercek_bitis']],['durum'=>$durum,'gercek_bitis'=>$gercekBit],$akisNot?:'Kiralama durumu değiştirildi');
            if ($durum === 'aktif' && $akisNot) akisYenidenAcAdimlari($db, $mevcut, $akisNot);
            $db->commit();
            }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
            require_once __DIR__ . '/zincir-lib.php';
            jsonOk(['sefer_hakedis_id' => $seferHid], 'Durum güncellendi.' . ($seferHid ? ' Boşa giden sefer ücreti için bekleyen hakediş açıldı.' : '') . ($durum === 'tamamlandi' ? kiralamaKapanisHakedisUyarisi($db, $id) : ''));
        }

        $musteriId = (int)($body['musteri_id'] ?? $mevcut['musteri_id']);
        $ekipman   = trim(metin($body['ekipman_adi'] ?? ''));
        $baslangic = dateStr('baslangic', $body);
        if (!$baslangic)     jsonError('Başlangıç tarihi zorunludur.');
        $bitis     = dateStr('bitis', $body);
        $gercekBitGirilenTam = dateStr('gercek_bitis', $body);
        $gercekBit = $gercekBitGirilenTam;
        kiralamaTarihDogrula($baslangic, $bitis, $gercekBitGirilenTam);
        $saha = _kkSahaAlanlari($body, $mevcut);

        $makineTur = in_array($body['makine_tur'] ?? '', ['manlift','arac'], true) ? $body['makine_tur'] : null;
        $makineId  = ((int)($body['makine_id'] ?? 0)) ?: null;
        if (!$makineTur || !$makineId) { $makineTur = null; $makineId = null; }
        $manliftId = ($makineTur === 'manlift') ? $makineId : (((int)($body['manlift_id'] ?? 0)) ?: null);
        if ($ekipman === '') $ekipman = _kkEkipmanAdiVarsayilan($db, $makineTur, $makineId);

        $operatorlu = !empty($body['operatorlu']) ? 1 : 0;
        $operatorId = $operatorlu ? (((int)($body['operator_id'] ?? 0)) ?: null) : null;
        $operatorUcret = $operatorlu ? max(0, (float)($body['operator_ucret'] ?? 0)) : 0;
        $operatorTip = in_array($body['operator_ucret_tipi'] ?? '', ['gunluk','aylik','sabit'], true) ? $body['operator_ucret_tipi'] : 'gunluk';

        $iskontoOran = max(0, min(100, (float)($body['iskonto_oran'] ?? 0)));
        $onayDurumu  = iskontoOnayDurumuHesapla($db, $iskontoOran, (string)($mevcut['onay_durumu'] ?? 'gerekmiyor'));

        $yeniDurum   = _kiraDurum($body['durum'] ?? $mevcut['durum']);
        $iptalNedeni = trim(metin($body['iptal_nedeni'] ?? ''));
        $iptalGecisi = $yeniDurum === 'iptal' && ($mevcut['durum'] ?? '') !== 'iptal';
        if ($iptalGecisi && $iptalNedeni === '') {
            jsonError('İptal gerekçesi zorunludur.');
        }
        $iptalNedeniYaz = ($yeniDurum === 'iptal' && $iptalNedeni !== '') ? $iptalNedeni : ($mevcut['iptal_nedeni'] ?? null);
        $iptalTarihiYaz = $iptalGecisi ? date('Y-m-d') : ($mevcut['iptal_tarihi'] ?? null);

        if ($yeniDurum === 'tamamlandi' && !$gercekBit) {
            $gercekBit = max(date('Y-m-d'), (string)($mevcut['baslangic'] ?? ''));
        }

        kiralamaKaydiDogrula($db, $makineTur, $makineId, $baslangic, $bitis, !empty($body['cakisma_onaylandi']), $id);

        $_logEski = logKayitAl($db, 'kiralamalar', $id);

        $siparisNo = trim((string)($mevcut['siparis_no'] ?? '')) ?: null;
        $nak = $siparisNo ? [
            'ucret' => (float)($mevcut['nakliye_ucret'] ?? 0), 'maliyet' => (float)($mevcut['nakliye_maliyet'] ?? 0),
            'tip' => $mevcut['nakliye_tip'], 'yon' => $mevcut['nakliye_yon'], 'nakliyeci' => $mevcut['nakliyeci'],
        ] : [
            'ucret' => max(0, (float)($body['nakliye_ucret'] ?? 0)),
            'maliyet' => max(0, (float)($body['nakliye_maliyet'] ?? 0)),
            'tip' => _kkNakTip($body['nakliye_tip'] ?? null),
            'yon' => _kkNakYon($body['nakliye_yon'] ?? null),
            'nakliyeci' => trim(metin($body['nakliyeci'] ?? '')) ?: null,
        ];

        $stmt = $db->prepare("
            UPDATE kiralamalar SET
              musteri_id=?, manlift_id=?, makine_tur=?, makine_id=?, ekipman_adi=?, baslangic=?, bitis=?, gercek_bitis=?,
              kira_tipi=?, birim_ucret=?, miktar=?, nakliye_ucret=?, nakliye_maliyet=?, nakliye_tip=?, nakliye_yon=?, nakliyeci=?, lokasyon=?, sube=?, temsilci=?, kdv_oran=?, durum=?, notlar=?,
              operatorlu=?, operator_id=?, operator_ucret=?, operator_ucret_tipi=?,
              sozlesme_no=?, sozlesme_imza_tarihi=?, otomatik_yenileme=?, iskonto_oran=?, onay_durumu=?, il=?, doviz=?,
              metraj=?, seri_no=?, makine_kodu=?, odeme_turu=?, vade_gun=?, iptal_nedeni=?, iptal_tarihi=?,
              saha_irtibat_ad=?, saha_irtibat_tel=?, saha_konum=?, ilce=?, santiye_adres=?,
              updated_at=datetime('now','localtime')
            WHERE id=?
        ");
        $kunye = _kkMakineKunye($db, $makineTur, $makineId, $body);
        $db->beginTransaction();
        try {
        $stmt->execute([
            $musteriId,
            $manliftId, $makineTur, $makineId,
            $ekipman, $baslangic, $bitis, $gercekBit,
            _kiraTip($body['kira_tipi'] ?? 'gunluk'),
            max(0, (float)($body['birim_ucret'] ?? 0)),
            max(1, (int)($body['miktar'] ?? 1)),
            $nak['ucret'], $nak['maliyet'], $nak['tip'], $nak['yon'], $nak['nakliyeci'],
            trim(metin($body['lokasyon'] ?? '')) ?: null,
            kayitSubesi(pasifSubeKoru($db, 'kiralamalar', $id, metin($body['sube'] ?? ''))),
            trim(metin($body['temsilci'] ?? '')) ?: null,
            max(0, (float)($body['kdv_oran'] ?? 20)),
            $yeniDurum,
            trim(metin($body['notlar'] ?? '')) ?: null,
            $operatorlu, $operatorId, $operatorUcret, $operatorTip,
            trim(metin($body['sozlesme_no'] ?? '')) ?: null,
            dateStr('sozlesme_imza_tarihi', $body),
            !empty($body['otomatik_yenileme']) ? 1 : 0,
            $iskontoOran, $onayDurumu,
            ilYazim(metin($body['il'] ?? '')) ?: null,
            in_array($body['doviz'] ?? '', ['TL','EUR','USD'], true) ? $body['doviz'] : 'TL',
            $kunye['metraj'], $kunye['seri_no'], $kunye['makine_kodu'],
            nakliyeOdemeTuru($body['odeme_turu'] ?? null),
            max(0, min(NAKLIYE_VADE_MAX, (int)($body['vade_gun'] ?? 0))),
            $iptalNedeniYaz, $iptalTarihiYaz,
            $saha['saha_irtibat_ad'], $saha['saha_irtibat_tel'], $saha['saha_konum'],
            array_key_exists('ilce', $body) ? kiralamaMetinAlan($body, 'ilce', 80) : ($mevcut['ilce'] ?? null),
            array_key_exists('santiye_adres', $body) ? kiralamaMetinAlan($body, 'santiye_adres', 500) : ($mevcut['santiye_adres'] ?? null),
            $id,
        ]);
            dkMaliyetKaydet($db, $id);
            if($akisNot)akisDenetimYaz($db,$mevcut,['durum'=>$mevcut['durum'],'gercek_bitis'=>$mevcut['gercek_bitis']],['durum'=>$yeniDurum,'gercek_bitis'=>$gercekBit],$akisNot);
            if ($yeniDurum === 'aktif' && $akisNot) akisYenidenAcAdimlari($db, $mevcut, $akisNot);
        if ($hareketGuncel && $mevcut['durum'] !== 'iptal' && $yeniDurum === 'iptal') {
            kiralamaMakineDurumu($db, $mevcut['makine_tur'] ?? null,
                (int)($mevcut['makine_id'] ?? 0) ?: null, 'teslim');
        }
        if ($hareketGuncel && $mevcut['durum'] !== 'tamamlandi' && $yeniDurum === 'tamamlandi') {
            kiralamaMakineDurumu($db, $mevcut['makine_tur'] ?? null,
                (int)($mevcut['makine_id'] ?? 0) ?: null, opIadeOlayi($db, $mevcut));
        }
        if ($mevcut['durum'] !== 'tamamlandi' && $yeniDurum === 'tamamlandi') opDonusKapandi($db, $mevcut, $hareketGuncel);
        if ($siparisNo) siparisNakliyeSenkron($db, $siparisNo);
        if ($siparisNo) {
            $sahaDegisti = false;
            foreach (['saha_irtibat_ad', 'saha_irtibat_tel', 'saha_konum'] as $sa) {
                if ((string)($mevcut[$sa] ?? '') !== (string)($saha[$sa] ?? '')) $sahaDegisti = true;
            }
            if ($sahaDegisti) {
                $kosul = 'siparis_no=? AND id<>?';
                $parametre = [$siparisNo, $id];
                if (($kisit = subeKisiti()) !== null) { $kosul .= ' AND sube=?'; $parametre[] = $kisit; }
                $kardes = $db->prepare("SELECT id FROM kiralamalar WHERE $kosul ORDER BY id");
                $kardes->execute($parametre);
                $kardesYaz = $db->prepare("UPDATE kiralamalar SET saha_irtibat_ad=?, saha_irtibat_tel=?, saha_konum=?,
                                           updated_at=datetime('now','localtime') WHERE id=?");
                foreach (array_map('intval', $kardes->fetchAll(PDO::FETCH_COLUMN)) as $kid) {
                    $kEski = logKayitAl($db, 'kiralamalar', $kid);
                    $kardesYaz->execute([$saha['saha_irtibat_ad'], $saha['saha_irtibat_tel'], $saha['saha_konum'], $kid]);
                    logla($db, 'kiralamalar', $kid, 'guncelle', $kEski, logKayitAl($db, 'kiralamalar', $kid),
                          'Sipariş ' . $siparisNo . ' irtibatı ' . ($_logEski['kod'] ?? ('#' . $id)) . ' satırından güncellendi');
                }
            }
        }
        logla($db, 'kiralamalar', $id, 'guncelle', $_logEski, logKayitAl($db, 'kiralamalar', $id),
              'Kiralama ' . ($_logEski['kod'] ?? ('#' . $id)) . ' güncellendi');
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            if ($e instanceof DomainException) jsonError($e->getMessage(), 409);
            throw $e;
        }
        require_once __DIR__ . '/zincir-lib.php';
        jsonOk(null, 'Kiralama güncellendi.' . ($yeniDurum === 'tamamlandi' ? kiralamaKapanisHakedisUyarisi($db, $id) : ''));

    case 'DELETE':
        verifyCsrf();
        [$silIdler, $silSp] = _kkSilHedef($db);
        $db->beginTransaction();
        try {
            [$silSonuc, $silToplam] = _kkSilCalistir($db, $silIdler);
            foreach ($silSonuc as $r) {
                logla($db, 'kiralamalar', (int)$r['kiralama']['id'], 'sil',
                      $r['kiralama'] + ['bagli_kayitlar' => json_encode($r['yedek'], JSON_UNESCAPED_UNICODE)], null,
                      'Kiralama ' . ($r['kiralama']['kod'] ?? ('#' . $r['kiralama']['id'])) . ' BAĞLI KAYITLARIYLA SİLİNDİ'
                      . (($o = kiralamaSilOzet($r['sayim'])) ? ' · ' . implode(', ', $o) : ''));
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            if ($e instanceof DomainException) jsonError($e->getMessage(), 409);
            error_log('kiralama sil: ' . $e->getMessage());
            jsonError('Kiralama silinemedi; hiçbir kayıt değişmedi.', 500);
        }
        jsonOk(['silinen' => count($silIdler), 'ozet' => kiralamaSilOzet($silToplam)],
               ($silSp !== '' ? 'Sipariş ' . $silSp . ' (' . count($silIdler) . ' kiralama)' : 'Kiralama') . ' bağlı kayıtlarıyla silindi.');

    default:
        jsonError('Method Not Allowed', 405);
}
