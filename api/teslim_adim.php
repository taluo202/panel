<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/belge-zinciri-lib.php';
requireAuth(); yazmaIstegiCsrf();
requirePermission('kiralama_takip', $_SERVER['REQUEST_METHOD'] === 'GET' ? 'oku' : 'yaz');
$db = getDb();
header('Cache-Control: no-store');
$body = $_SERVER['REQUEST_METHOD'] === 'POST' ? (govdeOku()) : [];
$kid = (int)($_GET['kiralama_id'] ?? $body['kiralama_id'] ?? 0);
$s = $db->prepare('SELECT * FROM kiralamalar WHERE id=?');
$s->execute([$kid]);
$k = $s->fetch();
if (!$k) jsonError('Kiralama bulunamadı.', 404);
subeErisimDogrula($k['sube'] ?? null);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $g = $db->prepare('SELECT adim, sonuc, yapan_ad, kaynak, gerekce, zaman FROM operasyon_adimlari WHERE kiralama_id=? ORDER BY id');
    $g->execute([$kid]);
    $adimlar = array_map(fn($r) => $r + ['etiket' => OP_ADIMLAR[$r['adim']] ?? $r['adim']], $g->fetchAll());
    $sira = opSiradakiAdim($db, $k);
    jsonOk(['yeni_zincir' => opYeniZincir($db, $k), 'siradaki' => $sira, 'siradaki_etiket' => $sira ? OP_ADIMLAR[$sira] : null,
            'adimlar' => $adimlar, 'atlayabilir' => opOturumYetkisi($db, 'atlama')]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'atla') {
    verifyCsrf();
    if (!opYeniZincir($db, $k)) jsonError('Bu kiralama eski düzende; adım atlama yeni kiralamalarda kullanılır.', 409);
    if (!opOturumYetkisi($db, 'atlama')) jsonError('Adım atlama yetkiniz yok.', 403);
    $adim = (string)($body['adim'] ?? '');
    $gerekce = trim((string)($body['gerekce'] ?? ''));
    if (mb_strlen($gerekce) < 5) jsonError('Atlama gerekçesi zorunludur (en az 5 karakter).');
    if (!in_array($adim, OP_ATLANABILIR, true)) jsonError('Bu adım atlanamaz. Teslim alma için iade tutanağı ya da tutanaksız tamamlama kullanılır.');
    $it = null;
    $db->beginTransaction();
    try {
        $s->execute([$kid]);
        $k = $s->fetch();
        if (opSiradakiAdim($db, $k) !== $adim) throw new DomainException('Yalnız sıradaki adım atlanabilir. Sayfayı yenileyin.');
        $etki = ['yukleme'     => ['nakliye',    ['kiraya_gidecek']],
                 'saha_teslim' => ['teslim',     ['kiraya_gidecek', 'nakliyede']],
                 'toplaniyor'  => ['toplaniyor', ['musait', 'kirada']],
                 'depo_inceleme' => ['inceleme_bitti', ['inceleme_bekliyor']]][$adim] ?? null;
        if ($etki && ($adim === 'depo_inceleme' ? opSonDonenKiralamaMi($db, $k) : operasyonKiralamaGuncelMi($db, $k))) {
            $md = $db->prepare('SELECT durum FROM ' . ($k['makine_tur'] === 'arac' ? 'araclar' : 'manliftler') . ' WHERE id=?');
            $md->execute([(int)$k['makine_id']]);
            if (in_array((string)$md->fetchColumn(), $etki[1], true)) kiralamaMakineDurumu($db, $k['makine_tur'], (int)$k['makine_id'], $etki[0]);
        }
        opAdimYaz($db, $kid, $adim, 'atlandi', opOturumYapan($db), 'yonetici', $gerekce);
        if ($adim === 'depo_inceleme') $it = bzIncelemeAtlandi($db, $k, $gerekce);
        $db->commit();
    } catch (DomainException $e) { $db->rollBack(); jsonError($e->getMessage(), 409); }
    catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); error_log($e->getMessage()); jsonError('Adım atlanamadı.', 500); }
    $sira = opSiradakiAdim($db, $k);
    jsonOk(['siradaki' => $sira, 'siradaki_etiket' => $sira ? OP_ADIMLAR[$sira] : null, 'iade_tutanagi' => $it],
           OP_ADIMLAR[$adim] . ' atlandı.' . ($it ? ' İade tutanağı ' . $it['belge_no'] . ' oluştu.' : ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'geri_al') {
    verifyCsrf();
    if (!opYeniZincir($db, $k)) jsonError('Bu kiralama eski düzende; adım günlüğü yeni kiralamalarda kullanılır.', 409);
    if (!opOturumYetkisi($db, 'atlama')) jsonError('Adım geri almak için "Adım atlama" yetkisi gerekir.', 403);
    $adim = (string)($body['adim'] ?? '');
    $gerekce = trim((string)($body['gerekce'] ?? ''));
    if (!isset(OP_ADIMLAR[$adim])) jsonError('Geçersiz adım.');
    if (mb_strlen($gerekce) < 5) jsonError('Geri alma gerekçesi zorunludur (en az 5 karakter).');
    $db->beginTransaction();
    try {
        opGeriAlKontrol($db, $kid, $adim);
        opAdimYaz($db, $kid, $adim, 'geri_alindi', opOturumYapan($db), 'yonetici', $gerekce);
        $db->commit();
    } catch (DomainException $e) { $db->rollBack(); jsonError($e->getMessage(), 409); }
    catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); error_log($e->getMessage()); jsonError('Adım geri alınamadı.', 500); }
    $sira = opSiradakiAdim($db, $k);
    jsonOk(['siradaki' => $sira, 'siradaki_etiket' => $sira ? OP_ADIMLAR[$sira] : null], OP_ADIMLAR[$adim] . ' geri alındı.');
}

jsonError('Method Not Allowed', 405);
