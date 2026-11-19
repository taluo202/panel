<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

const PAYLASIM_TURLERI = [
    'personel' => [
        'tablo' => 'personel_belgeler', 'klasor' => 'personel/\d+', 'modul' => ['personel'],
        'sorgu' => "SELECT belge_adi AS ad, dosya_yolu AS yol FROM personel_belgeler WHERE id=?",
    ],
    'arac' => [
        'tablo' => 'arac_belgeler', 'klasor' => 'arac/\d+', 'modul' => ['arac'],
        'sorgu' => "SELECT belge_adi AS ad, dosya_yolu AS yol FROM arac_belgeler WHERE id=?",
    ],
    'manlift' => [
        'tablo' => 'manlift_belgeler', 'klasor' => 'manlift/\d+', 'modul' => ['manlift'],
        'sorgu' => "SELECT belge_adi AS ad, dosya_yolu AS yol FROM manlift_belgeler WHERE id=?",
    ],
    'izin' => [
        'tablo' => 'izin_talepleri', 'klasor' => 'izin/\d+', 'modul' => ['personel'],
        'sorgu' => "SELECT COALESCE(NULLIF(belge_adi,''),'İzin evrakı') AS ad, belge_yolu AS yol FROM izin_talepleri WHERE id=?",
    ],
    'kiralama' => [
        'tablo' => 'kiralama_belgeler', 'klasor' => 'kiralama_belge/\d+', 'modul' => ['kiralama_takip'],
        'sorgu' => "SELECT b.belge_adi AS ad, b.dosya_yolu AS yol, k.sube AS sube FROM kiralama_belgeler b LEFT JOIN kiralamalar k ON k.id=b.kiralama_id WHERE b.id=?",
        'subeli' => true,
    ],
    'dis_kiralama' => [
        'tablo' => 'dis_kiralama_belgeler', 'klasor' => 'dis_kiralama/\d+', 'modul' => ['dis_kiralama'],
        'sorgu' => "SELECT b.belge_adi AS ad, b.dosya_yolu AS yol, m.sube AS sube FROM dis_kiralama_belgeler b LEFT JOIN dis_kiralama_kayitlari d ON d.id=b.dis_kiralama_id LEFT JOIN (SELECT id,sube,'arac' AS tur FROM araclar UNION ALL SELECT id,sube,'manlift' AS tur FROM manliftler) m ON m.id=d.makine_id AND m.tur=d.makine_tur WHERE b.id=?",
        'subeli' => true,
    ],
    'tutanak' => [
        'tablo' => 'tutanaklar', 'klasor' => 'tutanak/(?:personel|arac|manlift)/\d+',
        'modul_haritasi' => ['arac' => 'arac', 'manlift' => 'manlift', '*' => 'personel'],
        'sorgu' => "SELECT COALESCE(NULLIF(baslik,''),'Tutanak') AS ad, dosya_yolu AS yol, tur AS mtur FROM tutanaklar WHERE id=?",
    ],
    'nakliye' => [
        'tablo' => 'nakliyeler', 'klasor' => 'nakliye/\d+', 'modul' => ['nakliye'],
        'sorgu' => "SELECT 'Teslim kanıtı ' || COALESCE(teslim_kanit_no,'') AS ad, teslim_kanit_dosya AS yol, sube FROM nakliyeler WHERE id=?",
        'subeli' => true,
    ],
    'masraf' => [
        'tablo' => 'personel_masraf', 'klasor' => 'masraf/\d+', 'modul' => ['personel'],
        'sorgu' => "SELECT COALESCE(NULLIF(aciklama,''),'Masraf fişi') AS ad, fis_dosya AS yol FROM personel_masraf WHERE id=?",
    ],
    'mesai' => [
        'tablo' => 'mesai_belgeler', 'klasor' => 'mesai/\d+', 'modul' => ['mesai'],
        'sorgu' => "SELECT belge_adi AS ad, dosya_yolu AS yol FROM mesai_belgeler WHERE id=?",
    ],
    'genel_servis' => [
        'tablo' => 'genel_servis_belgeler', 'klasor' => 'genel_servis/\d+',
        'modul_haritasi' => ['manlift' => 'manlift', '*' => 'arac'],
        'sorgu' => "SELECT b.belge_adi AS ad, b.dosya_yolu AS yol, g.makine_tur AS mtur FROM genel_servis_belgeler b LEFT JOIN genel_servis g ON g.id=b.servis_id WHERE b.id=?",
    ],
];

function paylasimBelge(PDO $db, string $tur, int $id): ?array {
    $t = PAYLASIM_TURLERI[$tur] ?? null;
    if ($t === null || $id < 1) return null;
    $st = $db->prepare($t['sorgu']);
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function paylasimDosyasi(string $tur, ?string $rel): ?string {
    $t = PAYLASIM_TURLERI[$tur] ?? null;
    $rel = (string)$rel;
    if ($t === null || $rel === '') return null;
    if (!preg_match('#^' . $t['klasor'] . '/[a-f0-9]{32}\.[a-z0-9]{2,5}$#', $rel)) return null;
    $kok = realpath(rtrim(UPLOAD_PATH, '/\\'));
    $tam = realpath(UPLOAD_PATH . $rel);
    if ($kok === false || $tam === false || !is_file($tam) || !str_starts_with($tam, $kok . DIRECTORY_SEPARATOR)) return null;
    return $tam;
}

function paylasimModulleri(string $tur, array $belge): array {
    $t = PAYLASIM_TURLERI[$tur];
    if (isset($t['modul'])) return $t['modul'];
    $h = $t['modul_haritasi'];
    return [$h[(string)($belge['mtur'] ?? '')] ?? $h['*']];
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    $token = preg_replace('/[^a-f0-9]/', '', metin($_GET['token'] ?? ''));
    if (strlen($token) < 32) { http_response_code(400); echo 'Geçersiz token.'; exit; }

    $db = getDb();
    $tokenOz = tokenOzet($token);
    $st = $db->prepare("SELECT belge_id, tur, expires_at, indirim_sayisi, max_indirim FROM share_tokens WHERE token=?");
    $st->execute([$tokenOz]);
    $row = $st->fetch();

    if (!$row) { http_response_code(404); echo 'Bağlantı bulunamadı.'; exit; }
    if ($row['expires_at'] < date('Y-m-d H:i:s')) { http_response_code(410); echo 'Bağlantının süresi dolmuş.'; exit; }
    if ((int)$row['indirim_sayisi'] >= (int)$row['max_indirim']) { http_response_code(410); echo 'İndirme limiti aşıldı.'; exit; }

    $tur = (string)$row['tur'];
    if (!isset(PAYLASIM_TURLERI[$tur])) { http_response_code(400); exit; }
    $belge = paylasimBelge($db, $tur, (int)$row['belge_id']);
    $fullPath = $belge ? paylasimDosyasi($tur, $belge['yol'] ?? null) : null;
    if ($fullPath === null) { http_response_code(404); echo 'Dosya bulunamadı.'; exit; }

    $ext     = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    $mimeMap = ['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];
    $mime    = $mimeMap[$ext] ?? 'application/octet-stream';
    $safe    = preg_replace('/[^a-zA-Z0-9_\-\.ÇçĞğİıÖöŞşÜü ]/u', '_', (string)($belge['ad'] ?? 'evrak')) . '.' . $ext;

    $inc = $db->prepare("UPDATE share_tokens SET indirim_sayisi = indirim_sayisi + 1 WHERE token=? AND indirim_sayisi < max_indirim");
    $inc->execute([$tokenOz]);
    if ($inc->rowCount() === 0) { http_response_code(410); echo 'İndirme limiti aşıldı.'; exit; }

    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . $safe . '"');
    header('Content-Length: ' . filesize($fullPath));
    readfile($fullPath);
    exit;
}

if ($method === 'POST') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    requireAuth();
    verifyCsrf();

    $body    = govdeOku();
    $belgeId = (int)($body['belge_id'] ?? 0);
    $tur     = trim(metin($body['tur'] ?? ''));

    if ($belgeId < 1 || !isset(PAYLASIM_TURLERI[$tur])) {
        jsonError('Geçersiz istek.');
    }
    $izinliMi = function (array $moduller): bool {
        foreach ($moduller as $m) {
            if (hasPermission($m, 'yaz')) return true;
        }
        return false;
    };
    if (isset(PAYLASIM_TURLERI[$tur]['modul']) && !$izinliMi(PAYLASIM_TURLERI[$tur]['modul'])) {
        jsonError('Bu evrakı paylaşma yetkiniz yok.', 403);
    }
    $db    = getDb();
    $belge = paylasimBelge($db, $tur, $belgeId);
    if ($belge === null) jsonError('Evrak bulunamadı.', 404);
    if (!$izinliMi(paylasimModulleri($tur, $belge))) jsonError('Bu evrakı paylaşma yetkiniz yok.', 403);

    if (!empty(PAYLASIM_TURLERI[$tur]['subeli'])) {
        $kisit = subeKisitiHam();
        if ($kisit !== null && trim((string)($belge['sube'] ?? '')) !== $kisit) {
            jsonError('Bu evrak başka bir şubeye ait; paylaşma yetkiniz yok.', 403);
        }
    }

    if (paylasimDosyasi($tur, $belge['yol'] ?? null) === null) jsonError('Evrakın dosyası bulunamadı.', 404);

    $token   = bin2hex(random_bytes(24));
    $expires = date('Y-m-d H:i:s', strtotime('+7 days'));

    $db->prepare("DELETE FROM share_tokens WHERE belge_id=? AND tur=?")->execute([$belgeId, $tur]);
    $db->prepare("INSERT INTO share_tokens (token, belge_id, tur, expires_at, indirim_sayisi, max_indirim) VALUES (?,?,?,?,0,20)")
       ->execute([tokenOzet($token), $belgeId, $tur, $expires]);

    $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
          . '://' . guvenliHost() . siteKokYolu();
    $url  = $base . '/api/share.php?token=' . $token;

    logla($db, PAYLASIM_TURLERI[$tur]['tablo'], $belgeId, 'paylas', null, ['tur' => $tur, 'son_gecerlilik' => $expires],
          'Evrak paylaşım bağlantısı oluşturuldu: ' . mb_substr((string)($belge['ad'] ?? ''), 0, 120));
    guvenlikLog('paylasim_olusturuldu', (string)($_SESSION['username'] ?? '?'),
                (int)($_SESSION['user_id'] ?? 0), ['tur' => $tur, 'belge_id' => $belgeId]);
    jsonOk(['url' => $url, 'token' => $token, 'son_gecerlilik' => $expires], 'Paylaşım bağlantısı oluşturuldu.');
}

http_response_code(405);
echo 'Method Not Allowed';
