<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/operasyon-adim.php';

if (session_status() === PHP_SESSION_NONE) session_start();
subeMerkezi('qr');


$db     = getDb();
requireAuth();
$girisli = true;

function _qrMakineOkur(string $tur): bool {
    return hasPermission($tur, 'oku')
        || hasPermission('kiralama_takip', 'oku')
        || hasPermission('teknik_servis', 'oku');
}

function _qrSahaEylemi(PDO $db, string $tur, int $id): ?array {
    $s = opSahaEkrani($db, $tur, $id);
    return $s ? ['ad' => $s['islem'] === 'teslim' ? 'Sahada Teslim' : 'Sahada Teslim Alma', 'ikon' => 'assignment_turned_in', 'vurgu' => true,
                 'href' => 'saha-teslim.html?kiralama=' . $s['kiralama_id'] . '&islem=' . $s['islem']] : null;
}

function _qrSoforEylemi(PDO $db, string $tur, int $id): array {
    $yok = ['eylem' => null, 'bilgi' => null];
    if (!opOturumYetkisi($db, 'saha')) return $yok;
    $u = sfUygunArac($db, $tur, $id);
    if (!$u) return $yok;
    $kisit = subeKisitiHam();
    if ($kisit !== null && trim((string)($u['sube'] ?? '')) !== $kisit) return $yok;
    $pid = (int)($_SESSION['personel_id'] ?? 0);
    if ($u['sofor_personel_id'] === null) {
        if (!sfUstlenmeTarihiUygun($u['tarih'])) {
            return ['eylem' => null, 'bilgi' => $pid > 0 ? 'İş tarihi ' . date('d.m.Y', strtotime((string)$u['tarih'])) . '; bir gün önceden üstlenilebilir.' : null];
        }
        return ['eylem' => $pid > 0 ? ['ad' => 'Bu işi ben götürüyorum', 'ikon' => 'local_shipping', 'islem' => 'sofor_ustlen',
                                       'nakliye_id' => (int)$u['nakliye_id']] : null, 'bilgi' => null];
    }
    return ['eylem' => $pid > 0 && (int)$u['sofor_personel_id'] === $pid ? ['ad' => 'İş detayı', 'ikon' => 'info', 'href' => 'saha-teslim.html#planli'] : null,
            'bilgi' => 'Şoför: ' . (string)$u['sofor_ad']];
}

const QR_DEPO_IS = [
    'hazirlik' => ['Çıkış Kontrolünü Aç', 'Çıkış kontrolü bekliyor', 'Makine çıkış kontrolü bekliyor: Teknik Servis → Makine Kontrolleri → Çıkış kontrolü.'],
    'yukleme'  => ['Yüklemeyi Aç', 'Yükleme bekliyor', 'Sevk hazırlığı tamam; makine araca yüklenip yola çıkınca teslim sahada imzayla alınır: Yükleme / Toplama → Yüklenecekler.'],
    'inceleme' => ['Dönüş Kontrolünü Aç', 'Dönüş kontrolü bekliyor', 'Makine sahadan teslim alındı; dönüş kontrolü yapılmadan yeniden kiralanamaz: Teknik Servis → Makine Kontrolleri → Dönüş kontrolü.'],
];

function _qrDepoEylemi(PDO $db, string $tur, int $id, bool $girisli): ?array {
    $d = opDepoIsi($db, $tur, $id);
    if (!$d) return null;
    [$dugme, $baslik, $metin] = QR_DEPO_IS[$d['is']];
    if ($girisli && (hasPermission('kiralama_takip', 'oku') || opOturumYetkisi($db, 'depo') || opOturumYetkisi($db, 'yukleme') || opOturumYetkisi($db, 'dagitim')))
        return ['ad' => $dugme, 'ikon' => 'warehouse', 'vurgu' => true, 'href' => opIsAdresi($d['is'], ['kiralama' => $d['kiralama_id']])];
    if ($d['is'] !== 'inceleme') {
        require_once __DIR__ . '/hazirlik-akis.php';
        if ($e = hzEngel($db, $d['kiralama'])) return ['ad' => 'Çıkış kontrolü bekliyor', 'ikon' => 'warehouse', 'bilgi' => $e];
    }
    return ['ad' => $baslik, 'ikon' => 'warehouse', 'bilgi' => $metin];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'sayac') {
    requireAuth();
    verifyCsrf();
    $body = govdeOku();
    $tur  = (string)($body['tur'] ?? '');
    $id   = (int)($body['id'] ?? 0);
    $saat = (float)($body['saat'] ?? -1);

    if (!in_array($tur, ['manlift', 'arac'], true) || $id < 1) jsonError('Geçersiz makine.');
    if ($saat < 0) jsonError('Sayaç değeri geçersiz.');
    if (!hasPermission($tur, 'yaz') && !hasPermission('teknik_servis', 'yaz')) {
        jsonError('Sayaç girme yetkiniz yok.', 403);
    }

    $tablo = $tur === 'manlift' ? 'manliftler' : 'araclar';
    subeKayitDogrula($db, $tablo, $id);

    $st = $db->prepare("SELECT calisma_saati FROM $tablo WHERE id = ?");
    $st->execute([$id]);
    $eski = $st->fetch(PDO::FETCH_NUM);
    if ($eski === false) jsonError('Makine bulunamadı.', 404);
    $eskiSaat = (float)($eski[0] ?? 0);

    if ($saat < $eskiSaat) {
        jsonError(sprintf('Girilen sayaç (%s) mevcut değerden (%s) küçük. Yanlış makine ya da yanlış okuma olabilir.',
            sayiTR($saat), sayiTR($eskiSaat)));
    }

    $db->prepare("UPDATE $tablo SET calisma_saati = ? WHERE id = ?")->execute([$saat, $id]);
    logla($db, $tablo, $id, 'guncelle', ['calisma_saati' => $eskiSaat], ['calisma_saati' => $saat],
          'Sayaç QR ile girildi');
    jsonOk(['calisma_saati' => $saat, 'onceki' => $eskiSaat], 'Sayaç kaydedildi.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonError('Method Not Allowed', 405);

$payload = trim((string)($_GET['p'] ?? ''));
if ($payload !== '') {
    if (!preg_match('/^PDKS:(\d+):([a-f0-9]{32})$/', $payload, $m)) {
        jsonError('Rozet okunamadı.', 400);
    }
    $pid = (int)$m[1];
    $st = $db->prepare("SELECT id, ad_soyad, pozisyon, sube, durum, qr_token FROM personel WHERE id = ?");
    $st->execute([$pid]);
    $p = $st->fetch();
    if (!$p || empty($p['qr_token']) || !hash_equals((string)$p['qr_token'], $m[2])) {
        jsonError('Rozet geçersiz veya iptal edilmiş.', 403);
    }

    $eylemler = [];
    if ($girisli && hasPermission('personel', 'oku')) {
        subeErisimDogrula((string)($p['sube'] ?? ''));
        $eylemler[] = ['ad' => 'Evraklar',  'ikon' => 'folder_shared', 'href' => 'personel-detay.html?id=' . $pid];
        $eylemler[] = ['ad' => 'Puantaj / Mesai', 'ikon' => 'schedule',
                       'href' => 'mesai.html?ara=' . rawurlencode((string)$p['ad_soyad'])];
        $eylemler[] = ['ad' => 'Görev Ver', 'ikon' => 'add_task', 'href' => 'gorevler.html?yeni=1'];
    }
    $_uPid = $db->prepare('SELECT personel_id FROM users WHERE id=?');
    $_uPid->execute([(int)($_SESSION['user_id'] ?? 0)]);
    if ((int)($_uPid->fetchColumn() ?: 0) === $pid) {
        $eylemler[] = ['ad' => 'Saha Girişi', 'ikon' => 'where_to_vote', 'href' => 'checkin.html'];
    }

    jsonOk([
        'tip'      => 'personel',
        'id'       => $pid,
        'baslik'   => (string)$p['ad_soyad'],
        'altbilgi' => $girisli ? trim((string)($p['pozisyon'] ?? '')) : '',
        'rozet'    => ($p['durum'] ?? '') === 'aktif' ? ['metin' => 'Aktif', 'renk' => 'yesil']
                                                     : ['metin' => (string)($p['durum'] ?? '—'), 'renk' => 'gri'],
        'satirlar' => $girisli ? array_values(array_filter([
                          $p['sube'] ? ['etiket' => 'Şube', 'deger' => (string)$p['sube']] : null,
                      ])) : [],
        'eylemler' => $eylemler,
        'girisli'  => $girisli,
    ]);
}

$tur = (string)($_GET['tur'] ?? '');
$id  = (int)($_GET['id'] ?? 0);
if (!in_array($tur, ['manlift', 'arac'], true) || $id < 1) jsonError('Geçersiz QR.', 400);

$tablo = $tur === 'manlift' ? 'manliftler' : 'araclar';
$alan  = $tur === 'manlift' ? 'kod' : 'plaka';
$st = $db->prepare("SELECT id, $alan AS kimlik, marka, model, sube, aktif, durum, calisma_saati,
                           son_bakim_saat, bakim_araligi_saat, makine_kodu
                    FROM $tablo WHERE id = ?");
$st->execute([$id]);
$mk = $st->fetch();
if (!$mk) jsonError('Makine bulunamadı.', 404);

$ad = trim((string)$mk['kimlik'] . ' — ' . (string)$mk['marka'] . ' ' . (string)$mk['model']);

if (!_qrMakineOkur($tur)) {
    if (!(opOturumYetkisi($db, 'saha') || opOturumYetkisi($db, 'depo') || opOturumYetkisi($db, 'yukleme')))
        jsonError('Bu makineyi görüntüleme yetkiniz yok.', 403);
    $_qrSofor = _qrSoforEylemi($db, $tur, $id);
    jsonOk([
        'tip'      => 'makine',
        'tur'      => $tur,
        'id'       => $id,
        'baslik'   => (string)$mk['kimlik'],
        'altbilgi' => trim((string)$mk['marka'] . ' ' . (string)$mk['model']),
        'rozet'    => null,
        'satirlar' => [],
        'eylemler' => array_values(array_filter([
            _qrSahaEylemi($db, $tur, $id) ?? _qrDepoEylemi($db, $tur, $id, true) ?? ['ad' => 'Teslim / İade Tutanağı', 'ikon' => 'assignment_turned_in',
             'href' => 'iade-tutanagi.html?tur=' . $tur . '&id=' . $id],
            $_qrSofor['eylem'],
        ])),
        'sofor_bilgi' => $_qrSofor['bilgi'],
        'girisli'  => true,
        'not'      => 'Makinenin ayrıntıları için makine ya da kiralama yetkisi gerekir.',
    ]);
}

subeErisimDogrula((string)($mk['sube'] ?? ''));

$durum = makineDurumu($db, $tur, $id) ?? (string)($mk['durum'] ?? '');

$satirlar = [];
$kirada   = false;

$kSt = $db->prepare("SELECT id, operatorlu, operator_id FROM kiralamalar
                     WHERE makine_tur = ? AND makine_id = ? AND durum = 'aktif' LIMIT 1");
$kSt->execute([$tur, $id]);
$aktifKiralama = $kSt->fetch() ?: null;
$kirada = (bool)$aktifKiralama;

$_oturumPersonelId = (int)($_SESSION['personel_id'] ?? 0);
$_kfKendiKiralamasiMi = $aktifKiralama && !empty($aktifKiralama['operatorlu'])
    && $_oturumPersonelId > 0 && (int)$aktifKiralama['operator_id'] === $_oturumPersonelId;

$_kisitHam = subeKisitiHam();
$_ticariGorunur = $_kisitHam === null || trim((string)($mk['sube'] ?? '')) === $_kisitHam;
if ($_ticariGorunur && hasPermission('kiralama_takip', 'oku')) {
    $ks = $db->prepare("SELECT k.id, k.bitis, k.lokasyon, k.il, m.unvan
                        FROM kiralamalar k LEFT JOIN musteriler m ON m.id = k.musteri_id
                        WHERE k.makine_tur = ? AND k.makine_id = ? AND k.durum = 'aktif'
                        ORDER BY k.id DESC LIMIT 1");
    $ks->execute([$tur, $id]);
    if ($kr = $ks->fetch()) {
        if ($kr['unvan'])        $satirlar[] = ['etiket' => 'Müşteri', 'deger' => (string)$kr['unvan']];
        $yer = trim((string)($kr['lokasyon'] ?? '') . (($kr['lokasyon'] && $kr['il']) ? ' / ' : '') . (string)($kr['il'] ?? ''));
        if ($yer !== '')     $satirlar[] = ['etiket' => 'Yer',     'deger' => $yer];
        if ($kr['bitis']) {
            $_b = DateTime::createFromFormat('Y-m-d', substr((string)$kr['bitis'], 0, 10));
            $satirlar[] = ['etiket' => 'İade', 'deger' => $_b ? $_b->format('d.m.Y') : (string)$kr['bitis']];
        }
    }
}
if ($mk['sube']) $satirlar[] = ['etiket' => 'Şube', 'deger' => (string)$mk['sube']];
if ($mk['calisma_saati'] !== null) {
    $satirlar[] = ['etiket' => 'Sayaç', 'deger' => sayiTR((float)$mk['calisma_saati']) . ' saat'];
}

$eylemler = [];
$_sahaPid = (int)($_SESSION['personel_id'] ?? 0);
if ($girisli && $_sahaPid > 0 && hasPermission('teknik_servis', 'yaz')) {
    $_sq = $db->prepare("SELECT id FROM teknik_servis WHERE yer='saha' AND makine_tur=? AND makine_id=? AND teknisyen_id=?
                         AND saha_durum IN ('atandi','yolda','sahada') ORDER BY id DESC LIMIT 1");
    $_sq->execute([$tur, $id, $_sahaPid]);
    if ($_sid = (int)$_sq->fetchColumn()) {
        $eylemler[] = ['ad' => 'Servis İşini Aç', 'ikon' => 'engineering', 'vurgu' => true,
                       'href' => 'saha-servis.html?id=' . $_sid . '&qr=1&mt=' . $tur . '&mi=' . $id];
    }
}
$_kiralamaEngel = null;
if (hasPermission('kiralama_takip', 'yaz')) {
    if ($_kisitHam !== null && trim((string)($mk['sube'] ?? '')) !== $_kisitHam) $_kiralamaEngel = 'Bu makine kiralanamaz: başka şubeye ait';
    elseif ($durum === 'satildi')                                                $_kiralamaEngel = 'Bu makine kiralanamaz: satıldı';
    elseif ((int)$mk['aktif'] !== 1 || in_array($durum, KIRALANAMAZ_DURUMLAR, true)) $_kiralamaEngel = 'Bu makine kiralanamaz: pasif';
    else $eylemler[] = ['ad' => 'Yeni Kiralama', 'ikon' => 'add_shopping_cart', 'vurgu' => true,
                        'href' => 'kiralama-takip.html?yeni=1&makine=' . $tur . ':' . $id . '&kaynak=qr'];
}
if ($durum === 'bakim_bekliyor' && hasPermission('teknik_servis', 'yaz')) {
    $eylemler[] = ['ad' => 'Bakıma Başla', 'ikon' => 'build_circle', 'vurgu' => true, 'islem' => 'bakima_basla'];
}
if (hasPermission('teknik_servis', 'yaz')) {
    $eylemler[] = ['ad' => 'Arıza Bildir', 'ikon' => 'build', 'vurgu' => $durum !== 'bakim_bekliyor',
        'href' => 'teknik-servis.html?yeni_ariza=1&makine_tur=' . $tur . '&makine_id=' . $id
                . '&makine_ad=' . rawurlencode($ad)];
}
if (hasPermission($tur, 'yaz') || hasPermission('teknik_servis', 'yaz')) {
    $eylemler[] = ['ad' => 'Sayaç Gir', 'ikon' => 'speed', 'islem' => 'sayac'];
}
if (hasPermission($tur, 'yaz')) {
    $eylemler[] = ['ad' => 'Fotoğraf Ekle', 'ikon' => 'add_a_photo', 'islem' => 'foto'];
}
if ($aktifKiralama && (hasPermission('kiralama_takip', 'yaz') || $_kfKendiKiralamasiMi)) {
    $eylemler[] = ['ad' => 'Çıkış Fotoğrafı Ekle', 'ikon' => 'photo_camera', 'islem' => 'kfoto_gidis',
        'kiralama_id' => (int)$aktifKiralama['id']];
    $eylemler[] = ['ad' => 'Dönüş Fotoğrafı Ekle', 'ikon' => 'assignment_return', 'islem' => 'kfoto_donus',
        'kiralama_id' => (int)$aktifKiralama['id']];
}
if ($durum === 'nakliyede' && $aktifKiralama && !opYeniZincir($db, $aktifKiralama) && (hasPermission('kiralama_takip', 'yaz') || $_kfKendiKiralamasiMi)) {
    $eylemler[] = ['ad' => 'Sahaya Teslim Edildi', 'ikon' => 'local_shipping', 'vurgu' => true,
        'islem' => 'sahaya_teslim', 'kiralama_id' => (int)$aktifKiralama['id']];
}
$_qrSofor = _qrSoforEylemi($db, $tur, $id);
if ($_qrSofor['eylem']) $eylemler[] = $_qrSofor['eylem'];
if ($_qrSaha = _qrSahaEylemi($db, $tur, $id)) {
    $eylemler[] = $_qrSaha;
} elseif ($_qrDepo = _qrDepoEylemi($db, $tur, $id, true)) {
    $eylemler[] = $_qrDepo;
} elseif (hasPermission('kiralama_takip', 'yaz')) {
    $eylemler[] = ['ad' => 'Teslim / İade Tutanağı', 'ikon' => 'assignment_turned_in',
        'href' => 'iade-tutanagi.html?tur=' . $tur . '&id=' . $id];
}
if (hasPermission($tur, 'oku')) {
    $eylemler[] = ['ad' => 'Makine Detay', 'ikon' => 'inventory_2',
        'href' => ($tur === 'manlift' ? 'manlift-detay.html?id=' : 'arac-detay.html?id=') . $id];
}

$durum = opGorunenDurumKarar((string)$durum, $kirada, $kirada ? opGorunenDurum($db, $tur, $id) : null);

$renk = match ($durum) {
    'musait'                => 'yesil',
    'kirada', 'nakliyede', 'kiradan_gelecek' => 'mavi',
    'serviste'              => 'kirmizi',
    'kiraya_gidecek', 'bakim_bekliyor', 'inceleme_bekliyor', 'bakimda' => 'sari',
    default                 => 'gri',
};
$etiket = match ($durum) {
    'musait' => 'Müsait', 'kirada' => 'Kirada', 'serviste' => 'Serviste', 'satildi' => 'Satıldı', 'pasif' => 'Pasif',
    'nakliyede' => 'Teslimde / Nakliyede', 'kiraya_gidecek' => 'Kiraya Gidecek', 'kiradan_gelecek' => 'Kiradan Gelecek',
    'bakim_bekliyor' => 'Bakım Bekliyor', 'inceleme_bekliyor' => 'Dönüş kontrolü bekliyor', 'bakimda' => 'Bakımda',
    default => $durum ?: '—',
};

$_yanit = [
    'tip'      => 'makine',
    'tur'      => $tur,
    'id'       => $id,
    'baslik'   => (string)$mk['kimlik'],
    'altbilgi' => trim((string)$mk['marka'] . ' ' . (string)$mk['model']),
    'makine_ad' => $ad,
    'rozet'    => ['metin' => $etiket, 'renk' => $renk],
    'satirlar' => $satirlar,
    'eylemler' => $eylemler,
    'sofor_bilgi' => $_qrSofor['bilgi'],
    'girisli'  => true,
    'sayac'    => $mk['calisma_saati'] !== null ? (float)$mk['calisma_saati'] : null,
];
if ($_kiralamaEngel !== null) $_yanit['kiralama_engel'] = $_kiralamaEngel;
jsonOk($_yanit);
