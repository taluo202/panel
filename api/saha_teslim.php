<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/operasyon-adim.php';
require_once __DIR__ . '/saha-teslim-lib.php';
require_once __DIR__ . '/kiralama-protokol-lib.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$db = getDb();
header('Cache-Control: no-store');
$method = $_SERVER['REQUEST_METHOD'];
$action = (string)($_GET['action'] ?? '');
$ip = istekIp();

function stBaglantiIstek(PDO $db, string $ip, string $belirtec): array {
    if (stBaglantiSinirdaMi($db, $ip)) jsonError('Bu cihazdan çok fazla geçersiz bağlantı denendi. Birkaç dakika sonra tekrar deneyin.', 429);
    $bg = stBaglantiCoz($db, $belirtec);
    if (!$bg) {
        stBaglantiDenemesi($db, $ip, false);
        jsonError('Bağlantı geçersiz, kullanılmış ya da süresi dolmuş. Ofisi arayın.', 403);
    }
    return $bg;
}

function stKimlikCoz(PDO $db, string $ip, array $b): array {
    $metin = fn(string $k): string => is_string($b[$k] ?? null) ? trim($b[$k]) : '';
    $belirtec = $metin('b');
    if ($belirtec !== '') {
        $bg = stBaglantiIstek($db, $ip, $belirtec);
        $ad = (string)$bg['alici_ad']; $tel = (string)$bg['alici_tel'];
        $etiket = 'Dış nakliye şoförü: ' . $ad . ($tel !== '' ? ' (' . $tel . ')' : '');
        return ['kim' => ['tur' => 'dis_sofor', 'personel_id' => 0, 'yapan_id' => null, 'ad' => $etiket, 'tutanak_kaynak' => 'dis_baglanti',
                          'kaydeden' => null, 'adim_kaynak' => 'dis_baglanti', 'imza_ad' => $etiket],
                'baglanti' => $bg, 'personel_id' => 0];
    }
    if (!empty($_SESSION['user_id'])) {
        requireAuth();
        if (!opOturumYetkisi($db, 'saha')) jsonError('Sahada teslim yetkiniz yok.', 403);
        $oy = opOturumYapan($db);
        $u = $db->prepare('SELECT personel_id FROM users WHERE id=?');
        $u->execute([(int)$_SESSION['user_id']]);
        $upid = (int)($u->fetchColumn() ?: 0);
        $imzaAd = stOturumImzaAd($db, (int)$_SESSION['user_id'], (string)$oy['ad']);
        return ['kim' => ['tur' => $upid ? 'personel' : 'kullanici', 'personel_id' => $upid, 'yapan_id' => $upid ?: $oy['id'], 'ad' => $imzaAd, 'tutanak_kaynak' => $upid ? 'saha' : 'ofis',
                          'kaydeden' => (string)($_SESSION['username'] ?? ''), 'adim_kaynak' => 'tutanak', 'imza_ad' => $imzaAd],
                'baglanti' => null, 'personel_id' => $upid];
    }
    requireAuth();
    jsonError('Bu ekran için panele kendi kullanıcı adınız ve şifrenizle giriş yapın.', 403);
}

function stKaydetVeYanitla(PDO $db, array $b, array $kim, array $kapsam): void {
    $yazilan = [];
    $db->beginTransaction();
    try {
        $r = stTopluKaydet($db, $b, $kim, $kapsam, $yazilan);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        foreach ($yazilan as $yol) @unlink(UPLOAD_PATH . $yol);
        throw $e;
    }
    $sahaAlim = $r['tutanaklar'] && !array_filter($r['tutanaklar'], fn($t) => ($t['asama'] ?? 'tutanak') !== 'saha_alim');
    $kpEk = stProtokolImzasi($db, $b, $r, $kim, !empty($kapsam['kagit']));
    jsonOk($r + ['protokol' => $kpEk], ($r['mukerrer'] ? 'Bu teslim zaten kaydedilmişti.'
        : ($sahaAlim ? count($r['tutanaklar']) . ' makine teslim alındı. İade tutanağı dönüş kontrolünden sonra oluşacak.'
                     : count($r['tutanaklar']) . ' makine için ayrı tutanak oluşturuldu.'))
        . ($kpEk && $kpEk['imzalanan'] ? ' Kiralama protokolü imzalandı (' . implode(', ', $kpEk['imzalanan']) . ').' : '')
        . ($kpEk && $kpEk['uyarilar'] ? ' ' . implode(' ', $kpEk['uyarilar']) : ''));
}

function stProtokolImzasi(PDO $db, array $b, array $r, array $kim, bool $kagit): ?array {
    if ($kagit || !empty($r['mukerrer']) || ($b['islem'] ?? '') === 'iade') return null;
    $kidler = array_column($r['tutanaklar'], 'kiralama_id');
    $disSofor = ($kim['tur'] ?? '') === 'dis_sofor';
    $sonuc = ['imzalanan' => [], 'uyarilar' => []];
    try {
        if (!$disSofor && !empty($b['protokol_onay']) && in_array($b['kabul_sonucu'] ?? '', ['teslim_aldi', 'cekinceli'], true))
            $sonuc = kpSahadaImzala($db, $kidler, $b['protokol_onay'], is_string($b['imza_musteri'] ?? null) ? $b['imza_musteri'] : '',
                is_string($b['musteri_temsilci_ad'] ?? null) ? $b['musteri_temsilci_ad'] : '', is_string($b['protokol_unvan'] ?? null) ? $b['protokol_unvan'] : '',
                'saha:' . (string)($r['toplu_teslim_id'] ?? '') . ' · ' . (string)($kim['ad'] ?? ''), (string)($kim['ad'] ?? 'Saha'));
        $sonuc['gorevler'] = stFiili($b) !== 1 ? [] : kpImzasizGorev($db, $kidler, $disSofor
            ? 'Makine dış nakliye şoförüyle teslim edildi; kiralama protokolü sahada imzalatılmaz (şoför üçüncü taraf).'
            : 'Makine sahada teslim edildi ama müşteri kiralama protokolünü imzalamadı.');
    } catch (Throwable $e) {
        error_log('saha_teslim protokol: ' . $e->getMessage());
        $sonuc['uyarilar'][] = 'Kiralama protokolü imzalanamadı; ofis protokolü müşteriye bağlantıyla imzalatabilir.';
    }
    return $sonuc;
}

try {
    if ($method === 'GET' && $action === 'baglanti') {
        $bg = stBaglantiIstek($db, $ip, is_string($_GET['b'] ?? null) ? $_GET['b'] : '');
        $bIslem = ($bg['yon'] ?? 'gidis') === 'donus' ? 'iade' : 'teslim';
        $bMak = stAracMakineleri($db, (int)$bg['nakliye_id'], $bIslem);
        jsonOk(['plaka' => (string)$bg['plaka'], 'sofor' => (string)$bg['alici_ad'], 'gecerlilik' => (string)$bg['gecerlilik'],
                'islem' => $bIslem, 'iletisim' => stBaglantiIletisim($db, $bMak), 'makineler' => $bMak]);
    }
    if ($method === 'GET' && $action === 'durum') {
        $oturum = !empty($_SESSION['user_id']);
        if ($oturum) requireAuth();
        $saha = $oturum && opOturumYetkisi($db, 'saha');
        jsonOk(['oturum' => $oturum, 'saha' => $saha, 'kagit' => $saha && hasPermission('kiralama_takip', 'yaz')]);
    }
    if ($method !== 'POST') jsonError('Method Not Allowed', 405);
    verifyCsrf();
    $b = json_decode(file_get_contents('php://input'), true);
    if (!is_array($b)) jsonError('İstek okunamadı.');
    if ($action === 'kagit') {
        if (empty($_SESSION['user_id'])) jsonError('Kâğıt tutanak yüklemek için panele giriş yapın.', 403);
        requireAuth();
        if (!hasPermission('kiralama_takip', 'yaz') || !opOturumYetkisi($db, 'saha'))
            jsonError('Kâğıt tutanak için kiralama yazma ve "Sahada teslim" yetkisi gerekir.', 403);
        $oy = opOturumYapan($db);
        stKaydetVeYanitla($db, $b, ['tur' => 'ofis_kagit', 'personel_id' => 0, 'yapan_id' => $oy['id'], 'ad' => $oy['ad'],
            'tutanak_kaynak' => 'ofis_kagit', 'kaydeden' => (string)($_SESSION['username'] ?? ''), 'adim_kaynak' => 'tutanak',
            'imza_ad' => $oy['ad'] . ' (kâğıttan aktaran)'], ['baglanti' => null, 'personel_id' => 0, 'birlikte' => [], 'kagit' => true]);
    }
    if ($action === 'kaydet' && is_string($b['b'] ?? null) && is_string($b['toplu_anahtar'] ?? null)
        && !stBaglantiSinirdaMi($db, $ip)
        && ($m = stKapaliBaglantiMukerrer($db, trim($b['b']), $b['toplu_anahtar'])))
        jsonOk($m, 'Bu teslim zaten kaydedilmişti.');
    $kimlik = stKimlikCoz($db, $ip, $b);
    if ($action === 'liste') {
        jsonOk(['ad' => (string)$kimlik['kim']['ad'], 'personel_id' => (int)$kimlik['personel_id'],
                'araclar' => $kimlik['personel_id'] ? stTasinanlar($db, $kimlik['personel_id']) : [],
                'araclar_donus' => $kimlik['personel_id'] ? stTasinanlar($db, $kimlik['personel_id'], 'iade') : []]);
    }
    if ($action === 'ara') {
        if ($kimlik['baglanti']) jsonError('Bağlantıyla yalnız aracın makineleri işlenir.', 403);
        jsonOk(stAra($db, (string)($b['q'] ?? ''), ($b['islem'] ?? '') === 'iade' ? 'iade' : 'teslim', (int)$kimlik['personel_id']));
    }
    if ($action === 'protokol') {
        if ($kimlik['baglanti']) jsonOk(['protokoller' => [], 'bilgi' => 'Kiralama protokolü müşteriye portal üzerinden ayrıca gönderilir.']);
        $ids = stProtokolKiralamalari($db, is_array($b['kiralama_idler'] ?? null) ? $b['kiralama_idler'] : []);
        jsonOk(['protokoller' => $ids ? kpSahaOnizleme($db, $ids) : []]);
    }
    if ($action === 'kiralama') {
        if ($kimlik['baglanti']) jsonError('Bağlantıyla yalnız aracın makineleri işlenir.', 403);
        jsonOk(stKiralamaSatiri($db, (int)($b['kiralama_id'] ?? 0), ($b['islem'] ?? '') === 'iade' ? 'iade' : 'teslim', (int)$kimlik['personel_id']));
    }
    if (in_array($action, ['sofor_ustlen', 'sofor_birak', 'planli'], true)) {
        if ($kimlik['baglanti']) jsonError('Bağlantıyla bu işlem yapılamaz.', 403);
        $sfKim = ['kullanici_id' => (int)$_SESSION['user_id'], 'personel_id' => (int)$kimlik['personel_id'], 'kisit' => subeKisiti()];
        $nid = is_int($b['nakliye_id'] ?? null) || (is_string($b['nakliye_id'] ?? null) && ctype_digit($b['nakliye_id'])) ? (int)$b['nakliye_id'] : 0;
        if ($action === 'planli') {
            $isler = sfPlanliIsler($db, $sfKim['personel_id'], $sfKim['kisit']);
            if ($nid > 0) {
                $isler = array_values(array_filter($isler, fn($i) => $i['nakliye_id'] === $nid));
                if (!$isler) jsonError('Bu işin şoförü değilsiniz ya da iş yüklendi.', 403);
            }
            if ($isler) logla($db, 'nakliyeler', $nid ?: null, 'sorgu', null, ['isler' => array_column($isler, 'nakliye_id')],
                              'Şoför iş ekranı (yüklemeden önce) görüntülendi: ' . count($isler) . ' iş — ' . (string)$kimlik['kim']['ad']);
            jsonOk(['isler' => $isler]);
        }
        if ($nid < 1) jsonError('Nakliye aracı seçilmedi.');
        $db->beginTransaction();
        try {
            if ($action === 'sofor_ustlen') { $r = sfUstlen($db, $nid, $sfKim); $msj = 'Bu işi siz götürüyorsunuz.'; }
            else { sfBirak($db, $nid, $sfKim); $r = ['nakliye_id' => $nid, 'sofor_personel_id' => null, 'sofor_ad' => null]; $msj = 'Şoförlük bırakıldı.'; }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        jsonOk($r, $msj);
    }
    if ($action === 'kaydet') {
        stKaydetVeYanitla($db, $b, $kimlik['kim'], ['baglanti' => $kimlik['baglanti'], 'personel_id' => $kimlik['personel_id'],
            'birlikte' => $kimlik['baglanti'] ? [] : stBirlikte($db, $b['birlikte'] ?? [], $kimlik['personel_id'])]);
    }
    jsonError('Geçersiz işlem.', 400);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 400);
} catch (SfYetkiHatasi $e) {
    jsonError($e->getMessage(), 403);
} catch (DomainException $e) {
    jsonError($e->getMessage(), 409);
} catch (Throwable $e) {
    error_log('saha_teslim: ' . $e->getMessage());
    jsonError('Teslim kaydedilemedi.', 500);
}
