<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$db     = getDb();

if ($method === 'GET' && $action === 'gorsel') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) { http_response_code(400); exit; }
    $st = $db->prepare("SELECT gorsel_yerel FROM kiralama_urunler WHERE id=?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row || !$row['gorsel_yerel']) { http_response_code(404); exit; }

    $rel  = $row['gorsel_yerel'];
    if (!preg_match('#^kiralama_gorsel/\d+/[a-f0-9]{32}\.[a-z0-9]{2,5}$#', $rel)) {
        http_response_code(403); exit;
    }
    $full = realpath(UPLOAD_PATH . $rel);
    $base = realpath(UPLOAD_PATH);
    if (!$full || !$base || substr($full, 0, strlen($base)) !== $base || !file_exists($full)) {
        http_response_code(404); exit;
    }
    $ext     = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    $mimeMap = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];
    header('Content-Type: ' . ($mimeMap[$ext] ?? 'image/jpeg'));
    header('Cache-Control: public, max-age=86400');
    readfile($full);
    exit;
}

if ($method === 'GET' && $action === 'katalog_indir') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) { http_response_code(400); exit('Geçersiz istek.'); }

    $st = $db->prepare("SELECT dosya_adi, dosya_yolu FROM kiralama_kataloglar WHERE id=?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) { http_response_code(404); exit('Katalog bulunamadı.'); }

    $rel  = $row['dosya_yolu'];
    if (!preg_match('#^kiralama[_a-z]*/\d+/[a-f0-9]{32}\.[a-z0-9]{2,5}$#', $rel)) {
        http_response_code(403); exit;
    }
    $full = realpath(UPLOAD_PATH . $rel);
    $base = realpath(UPLOAD_PATH);
    if (!$full || !$base || substr($full, 0, strlen($base)) !== $base || !file_exists($full)) {
        http_response_code(404); exit('Dosya bulunamadı.');
    }

    $ext     = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    $mimeMap = ['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];
    $mime    = $mimeMap[$ext] ?? 'application/octet-stream';
    $safe    = preg_replace('/[^a-zA-Z0-9_\-\.ÇçĞğİıÖöŞşÜü ]/', '_', $row['dosya_adi']) . '.' . $ext;

    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $safe . '"');
    header('Content-Length: ' . filesize($full));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    readfile($full);
    exit;
}

if ($method === 'GET' && $action === 'urun_katalog_indir') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) { http_response_code(400); exit; }
    $st = $db->prepare("SELECT dosya_adi, dosya_yolu FROM kiralama_urun_kataloglar WHERE id=?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) { http_response_code(404); exit('Katalog bulunamadı.'); }
    $rel  = $row['dosya_yolu'];
    if (!preg_match('#^kiralama[_a-z]*/\d+/[a-f0-9]{32}\.[a-z0-9]{2,5}$#', $rel)) {
        http_response_code(403); exit;
    }
    $full = realpath(UPLOAD_PATH . $rel);
    $base = realpath(UPLOAD_PATH);
    if (!$full || !$base || substr($full, 0, strlen($base)) !== $base || !file_exists($full)) {
        http_response_code(404); exit('Dosya bulunamadı.');
    }
    $ext     = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    $mimeMap = ['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];
    $mime    = $mimeMap[$ext] ?? 'application/octet-stream';
    $safe    = preg_replace('/[^a-zA-Z0-9_\-\.ÇçĞğİıÖöŞşÜü ]/', '_', $row['dosya_adi']) . '.' . $ext;
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $safe . '"');
    header('Content-Length: ' . filesize($full));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    readfile($full);
    exit;
}

if ($method === 'GET' && $action === 'kategoriler') {
    $katSirasi = [
        'Genel E-Kataloglar',
        'Akülü Makaslı Platformlar',
        '4X4 Arazi Tipi Akülü Makaslı Platformlar',
        '4x4 Arazi Tipi Dizel Makaslı Platformlar',
        'Arazi Tipi Paletli Akülü Makaslı Platform',
        'Akülü Eklemli Platformlar',
        'Akülü Teleskopik Platformlar',
        'Dikey Direk Platformlar',
        'Dizel Forkliftler',
        'Elle Çekilebilir Makaslı Platformlar',
        'Çift Direk Dikey Platform',
        'Örümcek Platformlar',
        'Araç Üstü Sepetli Platformlar',
        'Araç Üstü Kırmalı Platformlar',
        'Araç Üstü Yük Vinçleri',
        'Araç Üstü Kırmasız Taşımalı Vinç',
        'Araç Üstü Kırmalı Vinç',
    ];

    $sayilar = [];
    $rows = $db->query("SELECT kategori, COUNT(*) AS n FROM kiralama_urunler GROUP BY kategori")->fetchAll();
    foreach ($rows as $r) $sayilar[$r['kategori']] = (int)$r['n'];

    $kataloglar = [];
    $kRows = $db->query("SELECT id, kategori, dosya_adi FROM kiralama_kataloglar ORDER BY created_at DESC")->fetchAll();
    foreach ($kRows as $r) {
        if (!isset($kataloglar[$r['kategori']])) $kataloglar[$r['kategori']] = [];
        $kataloglar[$r['kategori']][] = ['id' => $r['id'], 'ad' => $r['dosya_adi']];
    }

    $liste = [];
    foreach ($katSirasi as $kat) {
        $liste[] = [
            'ad'         => $kat,
            'urun_say'   => $sayilar[$kat] ?? 0,
            'kataloglar' => $kataloglar[$kat] ?? [],
        ];
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>true,'data'=>$liste], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'GET' && $action === 'urun') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) { http_response_code(400); exit; }
    $st = $db->prepare("SELECT id, isim, kategori, gorsel, gorsel_yerel, aciklama, sira FROM kiralama_urunler WHERE id=?");
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) { http_response_code(404); header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>'Ürün bulunamadı.']); exit; }
    $u['gorsel_url'] = $u['gorsel_yerel']
        ? 'api/kiralama.php?action=gorsel&id=' . $u['id']
        : ($u['gorsel'] ?? null);
    unset($u['gorsel_yerel']);

    $gSt = $db->prepare("SELECT id, sira FROM kiralama_urun_gorseller WHERE urun_id=? ORDER BY sira, id");
    $gSt->execute([$id]);
    $galeri = $gSt->fetchAll();
    foreach ($galeri as &$g) {
        $g['gorsel_url'] = 'api/kiralama.php?action=galeri_gorsel&id=' . $g['id'];
    }
    unset($g);
    $u['galeri'] = $galeri;

    $kSt = $db->prepare("SELECT id, dosya_adi FROM kiralama_kataloglar WHERE kategori=? ORDER BY created_at DESC");
    $kSt->execute([$u['kategori']]);
    $u['kataloglar'] = $kSt->fetchAll();

    $ukSt = $db->prepare("SELECT id, dosya_adi FROM kiralama_urun_kataloglar WHERE urun_id=? ORDER BY created_at DESC");
    $ukSt->execute([$id]);
    $u['urun_kataloglar'] = $ukSt->fetchAll();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>true,'data'=>$u], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'GET' && $action === 'galeri_gorsel') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) { http_response_code(400); exit; }
    $st = $db->prepare("SELECT gorsel_yolu FROM kiralama_urun_gorseller WHERE id=?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row || !$row['gorsel_yolu']) { http_response_code(404); exit; }
    $rel  = $row['gorsel_yolu'];
    if (!preg_match('#^kiralama[_a-z]*/\d+/[a-f0-9]{32}\.[a-z0-9]{2,5}$#', $rel)) {
        http_response_code(403); exit;
    }
    $full = realpath(UPLOAD_PATH . $rel);
    $base = realpath(UPLOAD_PATH);
    if (!$full || !$base || substr($full, 0, strlen($base)) !== $base || !file_exists($full)) {
        http_response_code(404); exit;
    }
    $ext     = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    $mimeMap = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];
    header('Content-Type: ' . ($mimeMap[$ext] ?? 'image/jpeg'));
    header('Cache-Control: public, max-age=86400');
    readfile($full);
    exit;
}

if ($method === 'GET' && $action === 'urunler') {
    $kategori = trim(metin($_GET['kategori'] ?? ''));
    if ($kategori === '') { http_response_code(400); exit('Kategori gerekli.'); }
    $st = $db->prepare("SELECT id, isim, gorsel, gorsel_yerel, aciklama FROM kiralama_urunler WHERE kategori=? ORDER BY sira, isim");
    $st->execute([$kategori]);
    $urunler = $st->fetchAll();
    foreach ($urunler as &$u) {
        $u['gorsel_url'] = $u['gorsel_yerel']
            ? 'api/kiralama.php?action=gorsel&id=' . $u['id']
            : ($u['gorsel'] ?? null);
        unset($u['gorsel_yerel']);
    }
    unset($u);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>true,'data'=>$urunler], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo 'Geçersiz istek.';
