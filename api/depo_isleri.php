<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/eposta.php';
require_once __DIR__ . '/whatsapp.php';
require_once __DIR__ . '/depo-isleri-lib.php';
require_once __DIR__ . '/depo-donus-lib.php';
function diNakliyeciMesaji(PDO $db, array $r, string $yon): ?array {
    if (empty($r['baglanti']) || empty($r['dis_sofor']) || empty($r['nakliye']['id'])) return null;
    try {
        return hkNakliyeciMetni($db, (int)$r['nakliye']['id'], $yon, (string)$r['baglanti']);
    } catch (Throwable $e) {
        error_log('depo_isleri nakliyeci mesajı: ' . $e->getMessage());
        return null;
    }
}
$sess = requireAuth();
$db = getDb();
header('Cache-Control: no-store');
$uid = (int)$sess['user_id'];
$y = ['depo' => opOturumYetkisi($db, 'depo'), 'yukleme' => opOturumYetkisi($db, 'yukleme'),
      'dagitim' => opOturumYetkisi($db, 'dagitim'), 'atlama' => opOturumYetkisi($db, 'atlama')];
if (!$y['depo'] && !$y['yukleme'] && !$y['dagitim'] && !hasPermission('kiralama_takip', 'oku')) jsonError('Bu ekran için yetkiniz yok.', 403);
$method = $_SERVER['REQUEST_METHOD'];
$action = (string)($_GET['action'] ?? '');
try {
    if ($method === 'GET') {
        if (($_GET['action'] ?? '') === 'inceleme') {
            if (!$y['depo']) jsonError('İnceleme için "Makine kontrolü" yetkisi gerekir.', 403);
            jsonOk(ddIncelemeVeri($db, (int)($_GET['kiralama'] ?? 0)));
        }
        if (($_GET['is'] ?? '') === 'inceleme') {
            if (!$y['depo'] && !hasPermission('kiralama_takip', 'oku')) jsonError('İncelenecekler için "Makine kontrolü" yetkisi gerekir.', 403);
            jsonOk(ddIncelemeListe($db, $uid) + ['yetki' => $y]);
        }
        $is = (string)($_GET['is'] ?? '');
        $gun = in_array($_GET['gun'] ?? '', DI_GUNLER, true) ? $_GET['gun'] : 'bugun';
        if ($is === 'toplama')
            jsonOk(ddToplamaListe($db, $gun, trim((string)($_GET['siparis'] ?? '')), (int)($_GET['kiralama'] ?? 0), $uid) + ['yetki' => $y]);
        jsonOk(diListe($db, $gun, trim((string)($_GET['siparis'] ?? '')), (int)($_GET['kiralama'] ?? 0), $uid) + ['yetki' => $y]);
    }
    if ($method !== 'POST') jsonError('Method Not Allowed', 405);
    verifyCsrf();
    $b = json_decode(file_get_contents('php://input'), true);
    if (!is_array($b)) throw new DomainException('İstek okunamadı.');
    $adim = (string)($b['adim'] ?? '');
    if (in_array($action, ['ustlen', 'birak', 'dagit'], true) && !in_array($adim, DI_ADIMLAR, true)) throw new DomainException('Geçersiz adım.');
    $ay = OP_ADIM_YETKI[$adim] ?? 'depo';
    switch ($action) {
        case 'ustlen':
            if (!$y[$ay]) jsonError('Bu iş için "' . OP_YETKILER[$ay] . '" yetkiniz yok.', 403);
            $db->beginTransaction(); $n = diUstlen($db, $b['kiralama_ids'] ?? [], $adim, $uid); $db->commit();
            jsonOk(['adet' => $n], $n . ' makine üstlenildi.');
        case 'birak':
            if (!$y[$ay] && !$y['dagitim']) jsonError('Bu iş için yetkiniz yok.', 403);
            $db->beginTransaction(); $n = diBirak($db, $b['kiralama_ids'] ?? [], $adim, $uid, $y['dagitim']); $db->commit();
            jsonOk(['adet' => $n], $n . ' makine bırakıldı.');
        case 'dagit':
            if (!$y['dagitim']) jsonError('Atama için "İş dağıtımı" yetkisi gerekir.', 403);
            $db->beginTransaction(); $n = diDagit($db, $b['kiralama_ids'] ?? [], $adim, (int)($b['kullanici_id'] ?? 0)); $db->commit();
            jsonOk(['adet' => $n], $n . ' makine atandı.');
        case 'yukle':
            if (!$y['yukleme']) jsonError('Yükleme için "Yükleme / toplama" yetkisi gerekir.', 403);
            $db->beginTransaction(); $r = diYukle($db, $b); $db->commit();
            try { $bil = diBildir($db, $r['kayitlar'], $r['nakliye'], $r['dis_sofor']); }
            catch (Throwable $e) { error_log('depo_isleri bildirim: ' . $e->getMessage()); $bil = [['kanal' => 'hata']]; }
            jsonOk(['yuklenen' => $r['yuklenen'], 'baglanti' => $r['baglanti'], 'mesaj' => diNakliyeciMesaji($db, $r, 'gidis'), 'bildirimler' => $bil],
                   $r['yuklenen'] . ' makine yola çıktı.');
        case 'topla':
            if (!$y['yukleme']) jsonError('Toplama için "Yükleme / toplama" yetkisi gerekir.', 403);
            $db->beginTransaction(); $r = ddTopla($db, $b); $db->commit();
            $bil = [];
            try { $bil = diBildir($db, $r['kayitlar'], $r['nakliye'], $r['dis_sofor'], 'toplaniyor'); }
            catch (Throwable $e) { error_log('depo_isleri toplama bildirim: ' . $e->getMessage()); $bil = [['kanal' => 'hata']]; }
            if ($r['tekrar']) jsonOk(['toplanan' => 0, 'tekrar' => true, 'baglanti' => null, 'bildirimler' => []], 'Bu makineler bu araçla zaten toplamaya çıkmıştı.');
            jsonOk(['toplanan' => $r['toplanan'], 'tekrar' => false, 'baglanti' => $r['baglanti'], 'mesaj' => diNakliyeciMesaji($db, $r, 'donus'), 'bildirimler' => $bil],
                   $r['toplanan'] . ' makine için toplamaya çıkıldı.');
        case 'sofor_ata':
            if (!$y['dagitim']) jsonError('Şoför atamak için "İş dağıtımı" yetkisi gerekir.', 403);
            $pid = $b['personel_id'] ?? null;
            $pid = $pid === null || $pid === '' || $pid === 0 || $pid === '0' ? null : (int)$pid;
            if ($pid !== null && $pid < 1) throw new DomainException('Geçersiz personel.');
            $db->beginTransaction(); $r = sfAta($db, (int)($b['nakliye_id'] ?? 0), $pid, ['kullanici_id' => $uid, 'personel_id' => 0, 'kisit' => subeKisiti()]); $db->commit();
            jsonOk($r, $r['sofor_ad'] !== null ? 'Şoför atandı: ' . $r['sofor_ad'] . '.' : 'Şoför kaldırıldı.');
        case 'incele':
            if (!$y['depo']) jsonError('İnceleme için "Makine kontrolü" yetkisi gerekir.', 403);
            $db->beginTransaction(); $r = ddIncelemeKaydet($db, $b); $db->commit();
            jsonOk($r, $r['mesaj']);
    }
    jsonError('Geçersiz işlem.', 400);
} catch (SfYetkiHatasi $e) {
    if ($db->inTransaction()) $db->rollBack();
    jsonError($e->getMessage(), 403);
} catch (DomainException $e) {
    if ($db->inTransaction()) $db->rollBack();
    jsonError($e->getMessage(), 409);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('depo_isleri: ' . $e->getMessage());
    if ($e instanceof PDOException && stripos($e->getMessage(), 'locked') !== false) jsonError('Başka biri aynı anda işlem yaptı. Listeyi yenileyip tekrar deneyin.', 409);
    jsonError('İşlem tamamlanamadı.', 500);
}
