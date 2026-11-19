<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
$sess = requireAuth();
$db = getDb();
if (!_opAdminMi($db, (int)$sess['user_id'])) jsonError('Bu bölüm yalnız yöneticiye açıktır.', 403);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $acik = [];
    foreach ($db->query('SELECT kisi_tur, kisi_id, yetki, izin FROM adim_yetkileri')->fetchAll() as $r)
        $acik[$r['kisi_tur'] . ':' . $r['kisi_id'] . ':' . $r['yetki']] = (int)$r['izin'] === 1;
    $kisiler = [];
    $hucre = function (string $tur, int $id) use ($db, $acik): array {
        $o = [];
        foreach (array_keys(OP_YETKILER) as $y) {
            $o[$y] = ['deger' => opYetkiVar($db, $tur, $id, $y), 'acik' => array_key_exists("$tur:$id:$y", $acik)];
        }
        return $o;
    };
    $u = $db->query("SELECT u.id, u.username, u.ad_soyad, u.rol, p.ad_soyad AS personel_ad
                     FROM users u LEFT JOIN personel p ON p.id = u.personel_id ORDER BY u.rol <> 'admin', COALESCE(u.ad_soyad, u.username)")->fetchAll();
    foreach ($u as $r) {
        $kisiler[] = ['tur' => 'kullanici', 'id' => (int)$r['id'], 'ad' => $r['ad_soyad'] ?: $r['username'],
                      'alt' => $r['username'], 'personel' => $r['personel_ad'], 'admin' => $r['rol'] === 'admin',
                      'izin' => $hucre('kullanici', (int)$r['id'])];
    }
    $p = $db->query("SELECT p.id, p.ad_soyad
                     FROM personel p
                     WHERE COALESCE(p.durum,'aktif')='aktif' AND NOT EXISTS (SELECT 1 FROM users u WHERE u.personel_id = p.id)
                     ORDER BY p.ad_soyad")->fetchAll();
    foreach ($p as $r) {
        $kisiler[] = ['tur' => 'personel', 'id' => (int)$r['id'], 'ad' => $r['ad_soyad'],
                      'alt' => 'Kullanıcı hesabı yok — giriş yapamaz', 'admin' => false,
                      'izin' => $hucre('personel', (int)$r['id'])];
    }
    jsonOk(['yetkiler' => OP_YETKILER, 'kisiler' => $kisiler]);
}

if ($method === 'PUT') {
    verifyCsrf();
    $b = json_decode(file_get_contents('php://input'), true);
    $tur = (string)($b['kisi_tur'] ?? '');
    $id  = (int)($b['kisi_id'] ?? 0);
    $y   = (string)($b['yetki'] ?? '');
    if (!in_array($tur, ['kullanici', 'personel'], true) || $id < 1 || !isset(OP_YETKILER[$y])) jsonError('Geçersiz kişi ya da yetki.');
    $var = $db->prepare($tur === 'kullanici' ? 'SELECT 1 FROM users WHERE id=?' : 'SELECT 1 FROM personel WHERE id=?');
    $var->execute([$id]);
    if (!$var->fetchColumn()) jsonError('Kişi bulunamadı.', 404);
    $izin = array_key_exists('izin', $b) && $b['izin'] !== null ? ((int)$b['izin'] === 1 ? 1 : 0) : null;
    $eski = opYetkiVar($db, $tur, $id, $y);
    if ($izin === null) {
        $db->prepare('DELETE FROM adim_yetkileri WHERE kisi_tur=? AND kisi_id=? AND yetki=?')->execute([$tur, $id, $y]);
    } else {
        $db->prepare('INSERT INTO adim_yetkileri(kisi_tur,kisi_id,yetki,izin,guncelleyen,zaman) VALUES(?,?,?,?,?,datetime(\'now\',\'localtime\'))
                      ON CONFLICT(kisi_tur,kisi_id,yetki) DO UPDATE SET izin=excluded.izin, guncelleyen=excluded.guncelleyen, zaman=excluded.zaman')
           ->execute([$tur, $id, $y, $izin, (string)($_SESSION['username'] ?? '')]);
    }
    $yeni = opYetkiVar($db, $tur, $id, $y);
    logla($db, 'adim_yetkileri', $id, 'guncelle', ['kisi' => "$tur:$id", 'yetki' => $y, 'izin' => $eski],
          ['kisi' => "$tur:$id", 'yetki' => $y, 'izin' => $yeni, 'acik_satir' => $izin],
          'Teslim adımı yetkisi: ' . OP_YETKILER[$y] . ' ' . ($izin === null ? 'varsayılana döndü' : ($izin ? 'verildi' : 'kaldırıldı')) . " ($tur #$id)");
    jsonOk(['deger' => $yeni, 'acik' => $izin !== null]);
}

jsonError('Method Not Allowed', 405);
