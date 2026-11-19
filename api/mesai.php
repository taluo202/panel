<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
$_oturumPersonelId = (int)($_SESSION['personel_id'] ?? 0);
subeMerkezi('mesai');


function _mesaiResmiTatilMi(string $tarih): bool
{
    $yil = (int)substr($tarih, 0, 4);
    if ($yil < 2000) return false;
    return in_array($tarih, resmiTatiller($yil), true);
}

function _mesaiIzinHakkiAc(PDO $db, array $mesai): ?int
{
    $pid   = (int)$mesai['personel_id'];
    $tarih = (string)$mesai['tarih'];

    $v = $db->prepare("SELECT id FROM izin_talepleri
                       WHERE personel_id=? AND baslangic=? AND kaynak='resmi_tatil_mesai' LIMIT 1");
    $v->execute([$pid, $tarih]);
    if ($varolan = $v->fetchColumn()) return (int)$varolan;

    $db->prepare("INSERT INTO izin_talepleri
                    (personel_id, baslangic, bitis, gun_sayisi, izin_turu, durum, notlar, kaynak, kaynak_mesai_id)
                  VALUES (?,?,?,?,?,?,?,?,?)")
       ->execute([
        $pid, $tarih, $tarih, 1, 'yillik', 'bekliyor',
        'Resmi tatilde (' . $tarih . ') çalışıldığı için hak edilen 1 gün izin. Ödemeye yansıtılmaz.',
        'resmi_tatil_mesai', (int)$mesai['id'],
       ]);
    return (int)$db->lastInsertId();
}

$db     = getDb();
$method = $_SERVER['REQUEST_METHOD'];

function _mesaiPersonelDogrula(PDO $db, int $personelId): array
{
    $st = $db->prepare("SELECT id, ad_soyad, pozisyon, sube, durum FROM personel WHERE id = ?");
    $st->execute([$personelId]);
    $p = $st->fetch();
    if (!$p) jsonError('Personel bulunamadı.', 404);
    return $p;
}

function _mesaiKaydiDogrula(PDO $db, int $id): array
{
    $st = $db->prepare("SELECT m.*, p.ad_soyad, p.sube FROM mesailer m
                        JOIN personel p ON p.id = m.personel_id WHERE m.id = ?");
    $st->execute([$id]);
    $m = $st->fetch();
    if (!$m) jsonError('Mesai kaydı bulunamadı.', 404);
    return $m;
}

function _mesaiAy(): string
{
    $yil = (int)($_GET['yil'] ?? 0);
    $ay  = (int)($_GET['ay']  ?? 0);
    if ($yil >= 2000 && $yil <= 2100 && $ay >= 1 && $ay <= 12) {
        return sprintf('%04d-%02d', $yil, $ay);
    }
    return date('Y-m');
}

if ($method === 'POST' && in_array($_GET['action'] ?? '', ['finansa_ilet', 'finanstan_geri_al'], true)) {
    verifyCsrf();
    requirePermission('mesai');
    if (($_SESSION['rol'] ?? '') !== 'admin') {
        jsonError('Finansa iletme yetkisi yalnızca sistem yöneticisindedir.', 403);
    }
    $geriAl = ($_GET['action'] === 'finanstan_geri_al');
    $body   = govdeOku();
    $donem  = trim(metin($body['ay'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $donem)) jsonError('Ay (YYYY-MM) zorunludur.');

    $bas = $donem . '-01';
    $bit = date('Y-m-t', strtotime($bas));

    if ($geriAl) {
        $st = $db->prepare("UPDATE mesailer SET durum='onaylandi', finans_ay=NULL, finans_tarih=NULL,
                            updated_at=datetime('now','localtime')
                            WHERE durum='finansa_iletildi' AND finans_ay=?");
        $st->execute([$donem]);
        $adet = $st->rowCount();
        logla($db, 'mesailer', null, 'guncelle', null, null, 'Mesai aktarımı geri alındı (' . $donem . '), ' . $adet . ' kayıt');
        jsonOk(['adet' => $adet], $adet . ' mesai kaydı yeniden düzenlenebilir.');
    }

    try {
    [$topla, $aktarilan] = islemIcinde($db, function (PDO $db) use ($bas, $bit, $donem): array {
    $idq = $db->prepare("SELECT id FROM mesailer WHERE durum='onaylandi' AND COALESCE(izin_hakki,0)=0 AND tarih BETWEEN ? AND ?");
    $idq->execute([$bas, $bit]);
    $okunanIdler = array_map('intval', $idq->fetchAll(PDO::FETCH_COLUMN));
    $st = $db->prepare("SELECT personel_id,
                            COALESCE(SUM(CASE WHEN tip IN ('pazar','konaklama') THEN 0 ELSE saat END),0) AS normal_toplam,
                            COUNT(DISTINCT CASE WHEN tip='pazar' THEN tarih END) AS pazar_gun
                        FROM mesailer
                        WHERE durum='onaylandi' AND COALESCE(izin_hakki,0)=0 AND tarih BETWEEN ? AND ?
                        GROUP BY personel_id");
    $st->execute([$bas, $bit]);
    $satirlar = $st->fetchAll();
    if (!$satirlar) throw new DomainException('Bu ayda Finans’a iletilecek ONAYLANMIŞ mesai yok.', 400);

    $topla = [];
    foreach ($satirlar as $r) {
        $topla[(int)$r['personel_id']] = ['normal' => (float)$r['normal_toplam'], 'pazar' => (int)$r['pazar_gun'], 'konaklama' => 0];
    }
    $kq = $db->prepare("SELECT personel_id, tarih, bitis_tarih FROM mesailer
                        WHERE durum='onaylandi' AND COALESCE(izin_hakki,0)=0 AND tip='konaklama' AND tarih BETWEEN ? AND ?");
    $kq->execute([$bas, $bit]);
    $kGunler = [];
    foreach ($kq->fetchAll() as $r)
        foreach (mesaiKonaklamaGunleri((string)$r['tarih'], $r['bitis_tarih']) as $g) $kGunler[(int)$r['personel_id']][$g] = true;
    foreach ($topla as $pid => $s) $topla[$pid]['konaklama'] = count($kGunler[$pid] ?? []);

        foreach ($topla as $pid => $s) {
            $f = $db->prepare("SELECT * FROM finans WHERE personel_id=? AND ay=?");
            $f->execute([$pid, $donem]);
            $mevcut = $f->fetch();

            if ($mevcut) {
                $nUcret = (float)$mevcut['normal_mesai_ucret'];
                $pUcret = (float)$mevcut['pazar_mesai_ucret'];
            } else {
                $o = $db->prepare("SELECT normal_mesai_ucret, pazar_mesai_ucret FROM finans
                                   WHERE personel_id=? AND ay<? ORDER BY ay DESC LIMIT 1");
                $o->execute([$pid, $donem]);
                $onceki = $o->fetch();
                $pk = $db->prepare("SELECT normal_mesai_ucret, pazar_mesai_ucret FROM personel WHERE id=?");
                $pk->execute([$pid]);
                $kart = $pk->fetch() ?: [];
                $nUcret = (float)($onceki['normal_mesai_ucret'] ?? $kart['normal_mesai_ucret'] ?? 0);
                $pUcret = (float)($onceki['pazar_mesai_ucret']  ?? $kart['pazar_mesai_ucret']  ?? 0);
            }

            $maas = $mevcut ? (float)$mevcut['toplam_maas'] : 0.0;
            if ($maas <= 0) {
                $om = $db->prepare("SELECT toplam_maas FROM finans WHERE personel_id=? AND ay<? AND toplam_maas>0 ORDER BY ay DESC LIMIT 1");
                $om->execute([$pid, $donem]);
                $maas = (float)$om->fetchColumn();
            }

            $mesaiTutar = finansMesaiTutar($s['normal'], $nUcret, $s['pazar'], $pUcret, $s['konaklama'], $maas);

            if ($mevcut) {
                $db->prepare("UPDATE finans SET normal_mesai_saat=?, pazar_mesai_gun=?, konaklama_gun=?,
                                normal_mesai_ucret=?, pazar_mesai_ucret=?, mesai=?
                              WHERE id=?")
                   ->execute([$s['normal'], $s['pazar'], $s['konaklama'], $nUcret, $pUcret, $mesaiTutar, (int)$mevcut['id']]);
                $fid = (int)$mevcut['id'];
            } else {
                $db->prepare("INSERT INTO finans (personel_id, ay, normal_mesai_saat, pazar_mesai_gun, konaklama_gun,
                                normal_mesai_ucret, pazar_mesai_ucret, mesai)
                              VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$pid, $donem, $s['normal'], $s['pazar'], $s['konaklama'], $nUcret, $pUcret, $mesaiTutar]);
                $fid = (int)$db->lastInsertId();
            }

            $g = $db->prepare("SELECT * FROM finans WHERE id=?");
            $g->execute([$fid]);
            $son = $g->fetch();
            $devir = finansDevir($db, $pid, $donem);
            $elden = ((float)$son['toplam_maas'] + (float)$son['mesai'] + (float)($son['prim'] ?? 0))
                   + $devir - (float)$son['haciz_kesinti'] - (float)$son['avans']
                   - (float)$son['bes'] - (float)$son['bankaya_yatan'];
            $db->prepare("UPDATE finans SET elden_kalan=?, devir=? WHERE id=?")
               ->execute([$elden, $devir, $fid]);
        }

        $aktarilan = 0;
        $u = $db->prepare("UPDATE mesailer SET durum='finansa_iletildi', finans_ay=?,
                             finans_tarih=datetime('now','localtime'), updated_at=datetime('now','localtime')
                           WHERE id=? AND durum='onaylandi'");
        foreach ($okunanIdler as $mid) { $u->execute([$donem, $mid]); $aktarilan += $u->rowCount(); }
        logla($db, 'finans', null, 'guncelle', null, null,
              'Mesai Finans’a iletildi (' . $donem . '): ' . count($topla) . ' personel, ' . $aktarilan . ' kayıt');
        return [$topla, $aktarilan];
    });
    } catch (\Throwable $e) {
        istisnaYanit($e, 'mesai finans aktarimi');
    }

    jsonOk(['personel' => count($topla), 'kayit' => $aktarilan],
           count($topla) . ' personelin mesaisi Finans’a iletildi.');
}

if ($method === 'POST' && ($_GET['action'] ?? '') === 'belge') {
    verifyCsrf();
    $mid = (int)($_GET['mesai_id'] ?? 0);
    if ($mid < 1) jsonError('Mesai seçilmedi.');
    if (!hasPermission('mesai')) {
        $_kb = $db->prepare("SELECT personel_id, durum FROM mesailer WHERE id = ?");
        $_kb->execute([$mid]);
        $_kendi = $_kb->fetch();
        if (!$_kendi || $_oturumPersonelId < 1 || (int)$_kendi['personel_id'] !== $_oturumPersonelId || $_kendi['durum'] !== 'taslak') {
            requirePermission('mesai');
        }
    }
    $mesai = _mesaiKaydiDogrula($db, $mid);

    $body  = govdeOku();
    $gelen = $body['belgeler'] ?? [];
    if (!is_array($gelen) || !$gelen) jsonError('Belge gönderilmedi.');
    if (count($gelen) > 10) jsonError('Tek seferde en fazla 10 belge eklenebilir.');

    $dir = UPLOAD_PATH . 'mesai/' . $mid . '/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) jsonError('Klasör oluşturulamadı.', 500);

    $belgeNo  = belgeNoAl($body);
    $belgeTar = dateStr('belge_tarihi', $body);
    $ins = $db->prepare("INSERT INTO mesai_belgeler (mesai_id, belge_adi, belge_no, belge_tarihi, dosya_yolu) VALUES (?,?,?,?,?)");
    $eklenen = 0; $atlanan = 0;
    foreach ($gelen as $b) {
        if (!is_array($b) || empty($b['veri'])) { $atlanan++; continue; }
        if (!preg_match('/^data:(image\/(jpe?g|png|webp)|application\/pdf);base64,([A-Za-z0-9+\/=]+)$/', (string)$b['veri'], $m)) {
            $atlanan++; continue;
        }
        $ikili = base64_decode($m[3], true);
        if ($ikili === false || strlen($ikili) < 100 || strlen($ikili) > MAX_FILE_SIZE) { $atlanan++; continue; }
        $pdfMi = str_starts_with($m[1], 'application/');
        if ($pdfMi) { if (substr($ikili, 0, 4) !== '%PDF') { $atlanan++; continue; } }
        else        { if (!getimagesizefromstring($ikili)) { $atlanan++; continue; } }

        $uz = $pdfMi ? 'pdf' : ($m[2] === 'jpg' ? 'jpeg' : $m[2]);
        $ad = bin2hex(random_bytes(16)) . '.' . $uz;
        if (file_put_contents($dir . $ad, $ikili) === false) { $atlanan++; continue; }
        @chmod($dir . $ad, 0644);
        $ins->execute([$mid, mb_substr(trim(metin($b['ad'] ?? '')), 0, 200) ?: ('Mesai evrakı.' . $uz), $belgeNo, $belgeTar, 'mesai/' . $mid . '/' . $ad]);
        $eklenen++;
    }
    if ($eklenen === 0) jsonError('Hiçbir belge kaydedilemedi. Dosyalar bozuk ya da desteklenmeyen biçimde (JPG/PNG/WEBP/PDF).');

    logla($db, 'mesailer', $mid, 'guncelle', null, null,
          'Mesai evrakı eklendi · ' . $eklenen . ' adet' . ($belgeNo !== null ? ' · belge no ' . $belgeNo : '') . ($atlanan ? ' (' . $atlanan . ' atlandı)' : ''));
    jsonOk(['eklenen' => $eklenen, 'atlanan' => $atlanan],
           $eklenen . ' belge eklendi.' . ($atlanan ? ' ' . $atlanan . ' dosya okunamadı.' : ''));
}

if ($method === 'GET' && ($_GET['action'] ?? '') === 'belgeler') {
    requirePermission('mesai');
    $mid = (int)($_GET['mesai_id'] ?? 0);
    if ($mid < 1) jsonError('Mesai seçilmedi.');
    _mesaiKaydiDogrula($db, $mid);
    $st = $db->prepare("SELECT id, belge_adi, belge_no, belge_tarihi, dosya_yolu, created_at FROM mesai_belgeler WHERE mesai_id=? ORDER BY id");
    $st->execute([$mid]);
    jsonOk($st->fetchAll());
}

if ($method === 'DELETE' && ($_GET['action'] ?? '') === 'belge') {
    verifyCsrf();
    requirePermission('mesai');
    $bid = (int)($_GET['id'] ?? 0);
    if ($bid < 1) jsonError('Geçersiz ID.');
    $st = $db->prepare("SELECT * FROM mesai_belgeler WHERE id=?");
    $st->execute([$bid]);
    $b = $st->fetch();
    if (!$b) jsonError('Belge bulunamadı.', 404);
    _mesaiKaydiDogrula($db, (int)$b['mesai_id']);
    $tam = UPLOAD_PATH . $b['dosya_yolu'];
    if (is_file($tam)) @unlink($tam);
    $db->prepare("DELETE FROM mesai_belgeler WHERE id=?")->execute([$bid]);
    logla($db, 'mesailer', (int)$b['mesai_id'], 'guncelle', null, null, 'Mesai evrakı silindi.');
    jsonOk(null, 'Belge silindi.');
}

if ($method === 'PUT' && ($_GET['action'] ?? '') === 'belge') {
    verifyCsrf();
    requirePermission('mesai');
    $bid = (int)($_GET['id'] ?? 0);
    if ($bid < 1) jsonError('Geçersiz ID.');
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) jsonError('Geçersiz istek gövdesi.');
    alanlarZorunlu($body, ['belge_adi', 'belge_no', 'belge_tarihi']);
    $eski = logKayitAl($db, 'mesai_belgeler', $bid);
    if (!$eski) jsonError('Belge bulunamadı.', 404);
    _mesaiKaydiDogrula($db, (int)$eski['mesai_id']);
    $belgeAdi = belgeAdiAl($body);
    if ($belgeAdi === '') jsonError('Belge adı boş bırakılamaz.');
    $db->prepare("UPDATE mesai_belgeler SET belge_adi=?, belge_no=?, belge_tarihi=? WHERE id=?")
       ->execute([$belgeAdi, belgeNoAl($body), dateStr('belge_tarihi', $body), $bid]);
    logla($db, 'mesai_belgeler', $bid, 'guncelle', $eski, logKayitAl($db, 'mesai_belgeler', $bid),
          'Mesai evrakı düzenlendi: ' . $belgeAdi . ' (mesai #' . (int)$eski['mesai_id'] . ')');
    jsonOk(['id' => $bid], 'Mesai evrakı güncellendi.');
}

if ($method === 'GET' && ($_GET['action'] ?? '') === 'personeller') {
    requirePermission('mesai');
    $where  = ["durum != 'pasif'"];
    $params = [];
    $st = $db->prepare("SELECT id, ad_soyad, pozisyon, sube FROM personel
                        WHERE " . implode(' AND ', $where) . " ORDER BY ad_soyad");
    $st->execute($params);
    jsonOk($st->fetchAll());
}

if ($method === 'GET') {
    $pid = (int)($_GET['personel_id'] ?? 0);
    if (!($pid > 0 && $pid === $_oturumPersonelId)) requirePermission('mesai');

    $donem = _mesaiAy();
    $bas   = $donem . '-01';
    $bit   = date('Y-m-t', strtotime($bas));

    $where  = ['m.tarih BETWEEN ? AND ?'];
    $params = [$bas, $bit];

    if ($pid > 0) { $where[] = 'm.personel_id = ?'; $params[] = $pid; }

    $q = trim(metin($_GET['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(' . trAramaSql('p.ad_soyad') . ' LIKE ? OR ' . trAramaSql('p.pozisyon') . ' LIKE ?'
                 . ' OR ' . trAramaSql('m.calisma_yeri') . ' LIKE ?)';
        $like = '%' . trArama($q) . '%';
        array_push($params, $like, $like, $like);
    }

    $st = $db->prepare("SELECT m.*, p.ad_soyad, p.pozisyon, p.sube,
                          (SELECT COUNT(*) FROM mesai_belgeler mb WHERE mb.mesai_id = m.id) AS belge_adet
                        FROM mesailer m JOIN personel p ON p.id = m.personel_id
                        WHERE " . implode(' AND ', $where) . "
                        ORDER BY m.tarih, p.ad_soyad, m.baslangic_saat");
    $st->execute($params);
    $liste = $st->fetchAll();

    $ozet = [];
    foreach ($liste as $r) {
        $k = (int)$r['personel_id'];
        if (!isset($ozet[$k])) {
            $ozet[$k] = ['personel_id' => $k, 'ad_soyad' => $r['ad_soyad'], 'pozisyon' => $r['pozisyon'],
                         'sube' => $r['sube'], 'normal_saat' => 0.0, 'pazar_gun' => 0,
                         '_pazar_gunleri' => [], 'konaklama_gun' => 0, '_konaklama_gunleri' => [],
                         'izin_gun' => 0, 'izin_saat' => 0.0, '_izin_gunleri' => [],
                         'kayit' => 0, 'taslak' => 0, 'onaylandi' => 0, 'finansa_iletildi' => 0];
        }
        if ($r['durum'] === 'reddedildi') {
            $ozet[$k]['reddedildi'] = ($ozet[$k]['reddedildi'] ?? 0) + 1;
            continue;
        }
        if (!empty($r['izin_hakki'])) {
            $ozet[$k]['_izin_gunleri'][$r['tarih']] = true;
            $ozet[$k]['izin_saat'] = round(($ozet[$k]['izin_saat'] ?? 0) + (float)$r['saat'], 2);
        } elseif ($r['tip'] === 'pazar') {
            $ozet[$k]['_pazar_gunleri'][$r['tarih']] = true;
        } elseif ($r['tip'] === 'konaklama') {
            foreach (mesaiKonaklamaGunleri((string)$r['tarih'], $r['bitis_tarih']) as $g) $ozet[$k]['_konaklama_gunleri'][$g] = true;
        } else {
            $ozet[$k]['normal_saat'] += (float)$r['saat'];
        }
        $ozet[$k]['kayit']++;
        $ozet[$k][$r['durum']] = ($ozet[$k][$r['durum']] ?? 0) + 1;
    }
    foreach ($ozet as &$o) {
        $o['izin_gun'] = count($o['_izin_gunleri'] ?? []);
        unset($o['_izin_gunleri']);
        $o['pazar_gun'] = count($o['_pazar_gunleri'] ?? []);
        unset($o['_pazar_gunleri']);
        $o['konaklama_gun'] = count($o['_konaklama_gunleri'] ?? []);
        unset($o['_konaklama_gunleri']);
        $o['normal_saat'] = round($o['normal_saat'], 2);
    }
    unset($o);
    usort($ozet, fn($a, $b) => strcoll($a['ad_soyad'], $b['ad_soyad']));

    jsonOk([
        'ay'      => $donem,
        'baslangic' => $bas,
        'bitis'   => $bit,
        'liste'   => $liste,
        'ozet'    => array_values($ozet),
        'toplam'  => [
            'normal' => round(array_sum(array_column($ozet, 'normal_saat')), 2),
            'pazar'  => array_sum(array_column($ozet, 'pazar_gun')),
            'konaklama' => array_sum(array_column($ozet, 'konaklama_gun')),
            'kayit'  => count($liste),
            'onay_bekleyen' => count(array_filter($liste, fn($r) => $r['durum'] === 'taslak')),
            'iletilebilir'  => count(array_filter($liste, fn($r) => $r['durum'] === 'onaylandi' && empty($r['izin_hakki']))),
            'izin_gun'      => array_sum(array_column($ozet, 'izin_gun')),
        ],
        'yetki'   => ['admin' => (($_SESSION['rol'] ?? '') === 'admin'), 'sube' => subeKisitiHam()],
    ]);
}

if ($method === 'POST') {
    verifyCsrf();
    $body = govdeOku();
    $pid  = (int)($body['personel_id'] ?? 0);

    $_kendiMesaisi = ($pid > 0 && $pid === $_oturumPersonelId);
    if (!$_kendiMesaisi) requirePermission('mesai');

    if ($pid < 1) jsonError('Personel seçilmedi.');
    $p = _mesaiPersonelDogrula($db, $pid);

    $tarih = dateStr('tarih', $body);
    if (!$tarih) jsonError('Tarih zorunludur.');

    $tip = mesaiTipi($body['tip'] ?? 'normal');

    if ($tip === 'konaklama') {
        $sonT = dateStr('bitis_tarih', $body) ?: $tarih;
        if ($sonT < $tarih) jsonError('Konaklama bitiş tarihi başlangıçtan önce olamaz.');
        $gunSay = count(mesaiKonaklamaGunleri($tarih, $sonT));
        if ($gunSay > 92) jsonError('Bir konaklama kaydı en fazla 92 gün olabilir.');
        $kDurum = (!$_kendiMesaisi || hasPermission('mesai')) ? mesaiDurumu($body['durum'] ?? 'taslak') : 'taslak';
        $ins = $db->prepare("INSERT INTO mesailer
            (personel_id, tarih, bitis_tarih, tip, baslangic_saat, bitis_saat, saat, calisma_yeri, makine_turu, aciklama, durum, olusturan)
            VALUES (?,?,?,'konaklama',NULL,NULL,?,?,?,?,?,?)");
        $ids = [];
        $db->beginTransaction();
        try {
            foreach (mesaiAyParcalari($tarih, $sonT) as [$pb, $pe]) {
                $ins->execute([$pid, $pb, $pe, count(mesaiKonaklamaGunleri($pb, $pe)),
                    trim(metin($body['calisma_yeri'] ?? '')) ?: null, trim(metin($body['makine_turu'] ?? '')) ?: null,
                    trim(metin($body['aciklama'] ?? '')) ?: null, $kDurum, $_SESSION['username'] ?? null]);
                $kid = (int)$db->lastInsertId();
                $ids[] = $kid;
                logla($db, 'mesailer', $kid, 'ekle', null, logKayitAl($db, 'mesailer', $kid),
                      'Konaklama eklendi: ' . $p['ad_soyad'] . ' · ' . $pb . ' – ' . $pe);
            }
            $db->commit();
        } catch (\Throwable $e) { $db->rollBack(); throw $e; }
        jsonOk(['id' => $ids[0], 'ids' => $ids, 'kayit' => count($ids), 'gun' => $gunSay, 'saat' => $gunSay, 'resmi_tatil' => false],
               'Konaklama kaydedildi (' . $gunSay . ' gün' . (count($ids) > 1 ? ', ' . count($ids) . ' aya bölündü' : '') . ').');
    }

    if ($tip === 'pazar') {
        $bas = null; $bit = null; $saat = 1.0;
    } else {
        $bas = trim(metin($body['baslangic_saat'] ?? ''));
        $bit = trim(metin($body['bitis_saat'] ?? ''));
        $saat = mesaiSaatHesapla($bas ?: null, $bit ?: null);
        if ($saat <= 0) $saat = max(0, (float)($body['saat'] ?? 0));
        if ($saat <= 0) jsonError('Mesai süresi sıfır. Başlangıç/bitiş saatini ya da süreyi girin.');
        if ($saat > 24) jsonError('Bir günde 24 saatten fazla mesai girilemez.');
        $bas = $bas ?: null; $bit = $bit ?: null;
    }

    $durum = (!$_kendiMesaisi || hasPermission('mesai')) ? mesaiDurumu($body['durum'] ?? 'taslak') : 'taslak';

    [$id, $izinId] = islemIcinde($db, function (PDO $db) use ($pid, $tarih, $tip, $bas, $bit, $saat, $body, $durum, $p): array {
    $st = $db->prepare("INSERT INTO mesailer
        (personel_id, tarih, tip, baslangic_saat, bitis_saat, saat, calisma_yeri, makine_turu, aciklama, durum, olusturan)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $st->execute([
        $pid, $tarih, $tip,
        $bas, $bit, $saat,
        trim(metin($body['calisma_yeri'] ?? '')) ?: null,
        trim(metin($body['makine_turu'] ?? '')) ?: null,
        trim(metin($body['aciklama'] ?? '')) ?: null,
        $durum,
        $_SESSION['username'] ?? null,
    ]);
    $id = (int)$db->lastInsertId();

    $izinId = null;
    if (_mesaiResmiTatilMi($tarih)) {
        $st2 = $db->prepare("SELECT * FROM mesailer WHERE id=?");
        $st2->execute([$id]);
        $kayit = $st2->fetch();
        $izinId = _mesaiIzinHakkiAc($db, $kayit);
        $db->prepare("UPDATE mesailer SET izin_hakki=1, izin_talep_id=? WHERE id=?")->execute([$izinId, $id]);
    }

    logla($db, 'mesailer', $id, 'ekle', null, logKayitAl($db, 'mesailer', $id),
          'Mesai eklendi: ' . $p['ad_soyad'] . ' · ' . $tarih . ' · '
          . ($tip === 'pazar' ? '1 gün (Pazar)' : $saat . ' saat')
          . ($izinId ? ' (resmi tatil → 1 gün izin hakkı, onay bekliyor)' : ''));
    return [$id, $izinId];
    });
    jsonOk(['id' => $id, 'saat' => $saat, 'resmi_tatil' => $izinId !== null, 'izin_talep_id' => $izinId],
           $izinId
             ? 'Mesai kaydedildi. Resmi tatil olduğu için 1 gün izin hakkı oluşturuldu (onay bekliyor).'
             : 'Mesai kaydedildi.');
}

if ($method === 'PUT') {
    verifyCsrf();
    requirePermission('mesai');
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) jsonError('Geçersiz ID.');
    $mevcut = _mesaiKaydiDogrula($db, $id);
    $body   = govdeOku();
    $eski   = logKayitAl($db, 'mesailer', $id);

    if (isset($body['durum']) && !array_diff(array_keys($body), ['durum', 'gerekce'])) {
        $yeni = mesaiDurumu($body['durum']);
        if ($mevcut['durum'] === 'finansa_iletildi') {
            jsonError('Bu kayıt Finans’a iletilmiş. Önce "Finanstan geri al" yapılmalı.', 409);
        }
        if ($yeni === 'finansa_iletildi') {
            jsonError('Finansa iletme toplu işlemle yapılır.', 400);
        }
        $gerekce = retGerekcesi($body, $yeni);
        $db->prepare("UPDATE mesailer SET durum=?, ret_gerekcesi=?, updated_at=datetime('now','localtime') WHERE id=?")
           ->execute([$yeni, $gerekce, $id]);
        logla($db, 'mesailer', $id, 'guncelle', $eski, logKayitAl($db, 'mesailer', $id),
              'Mesai durumu: ' . $yeni . ($gerekce !== null ? ' · gerekçe: ' . $gerekce : ''));
        jsonOk(null, 'Durum güncellendi.');
    }

    if ($mevcut['durum'] === 'finansa_iletildi') {
        jsonError('Finans’a iletilmiş mesai düzenlenemez. Önce "Finanstan geri al" yapın.', 409);
    }

    $pid = (int)($body['personel_id'] ?? $mevcut['personel_id']);
    $p   = _mesaiPersonelDogrula($db, $pid);

    $tarih = dateStr('tarih', $body) ?: $mevcut['tarih'];
    $tip   = mesaiTipi($body['tip'] ?? $mevcut['tip']);

    $sonT = null;
    if ($tip === 'konaklama') {
        $sonT = dateStr('bitis_tarih', $body) ?: ($mevcut['bitis_tarih'] ?: $tarih);
        if ($sonT < $tarih) jsonError('Konaklama bitiş tarihi başlangıçtan önce olamaz.');
        if (substr($sonT, 0, 7) !== substr($tarih, 0, 7))
            jsonError('Konaklama düzenlenirken ay sonunu aşamaz; sonraki ayın günlerini ayrı kayıt olarak girin.');
        $bas = null; $bit = null; $saat = (float)count(mesaiKonaklamaGunleri($tarih, $sonT));
    } elseif ($tip === 'pazar') {
        $bas = null; $bit = null; $saat = 1.0;
    } else {
        $bas  = trim(metin($body['baslangic_saat'] ?? ''));
        $bit  = trim(metin($body['bitis_saat'] ?? ''));
        $saat = mesaiSaatHesapla($bas ?: null, $bit ?: null);
        if ($saat <= 0) $saat = max(0, (float)($body['saat'] ?? 0));
        if ($saat <= 0) jsonError('Mesai süresi sıfır. Başlangıç/bitiş saatini ya da süreyi girin.');
        if ($saat > 24) jsonError('Bir günde 24 saatten fazla mesai girilemez.');
        $bas = $bas ?: null; $bit = $bit ?: null;
    }

    $db->prepare("UPDATE mesailer SET personel_id=?, tarih=?, bitis_tarih=?, tip=?, baslangic_saat=?, bitis_saat=?,
                    saat=?, calisma_yeri=?, makine_turu=?, aciklama=?, durum=?,
                    updated_at=datetime('now','localtime') WHERE id=?")
       ->execute([
        $pid, $tarih, $sonT, $tip,
        $bas, $bit, $saat,
        trim(metin($body['calisma_yeri'] ?? '')) ?: null,
        trim(metin($body['makine_turu'] ?? '')) ?: null,
        trim(metin($body['aciklama'] ?? '')) ?: null,
        mesaiDurumu($body['durum'] ?? $mevcut['durum']),
        $id,
       ]);
    logla($db, 'mesailer', $id, 'guncelle', $eski, logKayitAl($db, 'mesailer', $id),
          'Mesai güncellendi: ' . $p['ad_soyad'] . ' · ' . $tarih);
    jsonOk(['saat' => $saat, 'gun' => $tip === 'konaklama' ? (int)$saat : null], 'Mesai güncellendi.');
}

if ($method === 'DELETE') {
    verifyCsrf();
    requirePermission('mesai');
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) jsonError('Geçersiz ID.');
    $mevcut = _mesaiKaydiDogrula($db, $id);
    if ($mevcut['durum'] === 'finansa_iletildi') {
        jsonError('Finans’a iletilmiş mesai silinemez. Önce "Finanstan geri al" yapın.', 409);
    }
    $eski = logKayitAl($db, 'mesailer', $id);
    $db->prepare("DELETE FROM mesailer WHERE id=?")->execute([$id]);
    logla($db, 'mesailer', $id, 'sil', $eski, null, 'Mesai silindi: ' . $mevcut['ad_soyad'] . ' · ' . $mevcut['tarih']);
    jsonOk(null, 'Mesai silindi.');
}

jsonError('Method Not Allowed', 405);
