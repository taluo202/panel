<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

function _firmaBilgi(): array {
    $vars = [
        'firma_unvan' => '', 'firma_vergi_dairesi' => '', 'firma_vergi_no' => '', 'firma_mersis' => '',
        'firma_telefon' => '', 'firma_eposta' => '', 'firma_web' => '', 'firma_adres' => '', 'firma_logo' => '',
        'firma_banka' => '', 'firma_iban' => '',
    ];
    try {
        $db = getDb();
        $rows = $db->query("SELECT anahtar, deger FROM ayarlar WHERE anahtar LIKE 'firma_%'")->fetchAll();
        foreach ($rows as $r) $vars[$r['anahtar']] = $r['deger'];
        $vars['subeler'] = $db->query("SELECT ad, il, adres, telefon, eposta FROM subeler
            WHERE aktif=1 AND COALESCE(belgede_goster,1)=1 ORDER BY COALESCE(sira,0), id")->fetchAll();
    } catch (\Throwable $e) {}
    return $vars;
}
$uploadBase = 'uploads/' . TENANT_SEG;

if (empty($_SESSION['user_id']) || !oturumGecerli()) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_f = _firmaBilgi();
    if (empty($_SESSION['portal_kontak_id'])) {
        $_f = array_intersect_key($_f, array_flip(['firma_unvan', 'firma_logo', 'firma_telefon']));
    }
    jsonOk(['logged_in' => false, 'firma' => $_f, 'upload_base' => $uploadBase, 'tenant' => TENANT_SLUG, 'csrf_token' => csrfToken()]);
}

$_email = ''; $_sube = ''; $_personelId = null; $_personelBilgi = null;
try {
    $eStmt = getDb()->prepare("SELECT email, sube, personel_id FROM users WHERE id=?");
    $eStmt->execute([$_SESSION['user_id']]);
    $_row = $eStmt->fetch();
    $_email = (string)($_row['email'] ?? '');
    $_sube  = (string)($_row['sube'] ?? '');
    $_personelId = ((int)($_row['personel_id'] ?? 0)) ?: null;
    if ($_personelId) {
        $pStmt = getDb()->prepare("
            SELECT p.id, p.ad_soyad, p.pozisyon, p.sube AS personel_sube,
                   (SELECT ad_soyad FROM personel WHERE id = p.amir_id) AS amir_ad
            FROM personel p WHERE p.id = ?
        ");
        $pStmt->execute([$_personelId]);
        $_personelBilgi = $pStmt->fetch() ?: null;
    }
} catch (\Throwable $e) {}

jsonOk([
    'logged_in'  => true,
    'user'       => [
        'username' => $_SESSION['username'],
        'ad_soyad' => $_SESSION['ad_soyad'],
        'rol'      => $_SESSION['rol'],
        'yetkiler' => $_SESSION['yetkiler'] ?? '',
        'email'    => $_email,
        'sube'     => $_sube,
        'personel_id' => $_personelId,
        'personel'    => $_personelBilgi,
    ],
    'menu'        => navMenusu([
        'id'       => (int)($_SESSION['user_id'] ?? 0),
        'rol'      => $_SESSION['rol'] ?? '',
        'yetkiler' => $_SESSION['yetkiler'] ?? '',
    ]),
    'sayfa_izinli' => navSayfaIzinli([
        'id'       => (int)($_SESSION['user_id'] ?? 0),
        'rol'      => $_SESSION['rol'] ?? '',
        'yetkiler' => $_SESSION['yetkiler'] ?? '',
    ], preg_match('/^[A-Za-z0-9._-]+\.html$/', (string)($_GET['sayfa'] ?? '')) ? (string)$_GET['sayfa'] : 'index.html'),
    'alt_nav'     => navAltCubuk([
        'id'       => (int)($_SESSION['user_id'] ?? 0),
        'rol'      => $_SESSION['rol'] ?? '',
        'yetkiler' => $_SESSION['yetkiler'] ?? '',
    ]),
    'nav_harita'  => navHaritasi([
        'rol'      => $_SESSION['rol'] ?? '',
        'yetkiler' => $_SESSION['yetkiler'] ?? '',
    ]),
    'firma'       => _firmaBilgi(),
    'upload_base' => $uploadBase,
    'tenant'      => TENANT_SLUG,
    'csrf_token'  => csrfToken(),
    'sifre_degistir_zorunlu' => !empty($_SESSION['sifre_degistir_zorunlu']),
]);
