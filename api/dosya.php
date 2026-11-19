<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();


$rel = (string)($_GET['y'] ?? '');

if ($rel === '' || !preg_match('#^[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-.]+)+$#', $rel)
    || str_contains($rel, '..') || str_contains($rel, '\\')) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Geçersiz dosya adresi.');
}

$klasor = strtolower(explode('/', $rel)[0]);

const DOSYA_MODUL = [
    'personel'       => 'personel',
    'izin'           => 'personel',
    'mesai'          => 'personel',
    'masraf'         => 'personel',
    'signatures'     => 'ayarlar',
    'arac'           => 'arac',
    'manlift'        => 'manlift',
    'genel_servis'   => 'arac',
    'kiralama_belge' => 'kiralama_takip',
    'kiralama'       => 'kiralama_takip',
    'nakliye'        => 'nakliye',
    'dis_kiralama'   => 'dis_kiralama',
    'servis'         => 'teknik_servis',
    'iade_tutanak'   => 'kiralama_takip',
    'tutanak'        => 'kiralama_takip',
    'kiralama_puantaj' => 'kiralama_takip',
    'servis-talep'   => 'teknik_servis',
];

if (!isset(DOSYA_MODUL[$klasor])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Bulunamadı.');
}

if (empty($_SESSION['user_id']) || !oturumGecerli($_neden, $_kullanici)) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Bu belgeyi görüntülemek için giriş yapmalısınız.');
}
if (($_engel = oturumKapiEngeli($_kullanici)) !== null) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit($_engel[0]);
}
const DOSYA_ZINCIR_FOTO = ['kiralama', 'iade_tutanak', 'tutanak'];
$_zincirFotoGorur = function () use ($klasor, $rel): bool {
    if ($klasor === 'servis') {
        if (!hasPermission('kiralama_takip', 'yaz')) return false;
        $sid = (int)(explode('/', $rel)[1] ?? 0);
        if ($sid < 1) return false;
        try {
            $db = getDb();
            $st = $db->prepare("SELECT s.tutanak_id, s.kiralama_id, COALESCE(t.kiralama_id, s.kiralama_id) AS bagli_kiralama
                                FROM teknik_servis s LEFT JOIN iade_tutanaklari t ON t.id = s.tutanak_id WHERE s.id = ?");
            $st->execute([$sid]);
            $sv = $st->fetch();
            if (!$sv) return false;
            $kid = (int)($sv['bagli_kiralama'] ?? 0);
            if ($kid > 0) {
                $kisit = subeKisiti();
                if ($kisit === null) return true;
                $ks = $db->prepare('SELECT sube FROM kiralamalar WHERE id = ?');
                $ks->execute([$kid]);
                $sube = $ks->fetchColumn();
                return $sube !== false && (string)$sube === (string)$kisit;
            }
            $ms = $db->prepare('SELECT m.sube FROM teknik_servis s JOIN musteriler m ON m.id = s.musteri_id WHERE s.id = ?');
            $ms->execute([$sid]);
            $m = $ms->fetch();
            if (!$m) return false;
            $kisit = subeKisiti();
            return $kisit === null || trim((string)$m['sube']) === $kisit;
        } catch (\Throwable $e) {
            return false;
        }
    }
    if (!in_array($klasor, DOSYA_ZINCIR_FOTO, true)) return false;
    if (hasPermission('teknik_servis', 'oku')) return true;
    require_once __DIR__ . '/operasyon-adim.php';
    $db = getDb();
    foreach (['depo', 'yukleme', 'saha'] as $adimYetkisi) {
        if (opOturumYetkisi($db, $adimYetkisi)) return true;
    }
    return false;
};
const DOSYA_EK_MODUL = ['mesai' => 'mesai'];
$_kendiDosyasi = function () use ($klasor, $rel): bool {
    $pid = (int)($_SESSION['personel_id'] ?? 0);
    $no  = (int)(explode('/', $rel)[1] ?? 0);
    if ($pid < 1 || $no < 1) return false;
    if (in_array($klasor, ['personel', 'masraf'], true)) return $no === $pid;
    $tablo = ['izin' => 'izin_talepleri', 'mesai' => 'mesailer'][$klasor] ?? null;
    if ($tablo === null) return false;
    try {
        $st = getDb()->prepare("SELECT personel_id FROM $tablo WHERE id = ?");
        $st->execute([$no]);
        return (int)$st->fetchColumn() === $pid;
    } catch (\Throwable $e) {
        return false;
    }
};
if (!hasPermission(DOSYA_MODUL[$klasor], 'oku')
    && !(isset(DOSYA_EK_MODUL[$klasor]) && hasPermission(DOSYA_EK_MODUL[$klasor], 'oku'))
    && !$_kendiDosyasi() && !$_zincirFotoGorur()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Bu belgeyi görüntüleme yetkiniz yok.');
}

if ($klasor === 'servis' && subeKisitiHam() !== null) {
    $_imzaSid = 0;
    try {
        $_iq = getDb()->prepare('SELECT servis_id FROM servis_ziyaretleri WHERE imza_musteri = ? OR imza_teknisyen = ? LIMIT 1');
        $_iq->execute([$rel, $rel]);
        $_imzaSid = (int)$_iq->fetchColumn();
    } catch (\Throwable $e) { $_imzaSid = 0; }
    if ($_imzaSid > 0) {
        require_once __DIR__ . '/saha-servis-lib.php';
        try { ssOkumaErisim(getDb(), $_imzaSid, (int)($_SESSION['personel_id'] ?? 0)); }
        catch (\DomainException $e) { http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); exit('Bu belge başka bir şubeye ait; erişim yetkiniz yok.'); }
    }
}

if ($klasor === 'servis-talep') {
    require_once __DIR__ . '/servis-talep-lib.php';
    $tid = (int)(explode('/', $rel)[1] ?? 0);
    $talep = $tid > 0 ? stTalep(getDb(), $tid) : null;
    if (!$talep) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('Bulunamadı.'); }
    if (!stSubeIzinli($talep)) { http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); exit('Bu belge başka bir şubeye ait; erişim yetkiniz yok.'); }
}

if (in_array($klasor, ['kiralama', 'kiralama_belge'], true)) {
    $parcalar = explode('/', $rel);
    $kid = (int)($parcalar[1] ?? 0);
    $kisit = subeKisiti();
    if ($kid < 1) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('Bulunamadı.'); }
    if ($kisit !== null) {
        try {
            $st = getDb()->prepare('SELECT sube FROM kiralamalar WHERE id = ?');
            $st->execute([$kid]);
            $sb = $st->fetchColumn();
        } catch (\Throwable $e) { $sb = false; }
        if ($sb === false || trim((string)$sb) !== $kisit) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit('Bu belge başka bir şubeye ait; erişim yetkiniz yok.');
        }
    }
}

if ($klasor === 'iade_tutanak') {
    $tid = (int)(explode('/', $rel)[1] ?? 0);
    if ($tid < 1) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('Bulunamadı.'); }
    $kisit = subeKisiti();
    if ($kisit !== null) {
        try {
            $st = getDb()->prepare('SELECT k.sube FROM iade_tutanaklari t JOIN kiralamalar k ON k.id = t.kiralama_id WHERE t.id = ?');
            $st->execute([$tid]);
            $sb = $st->fetchColumn();
        } catch (\Throwable $e) { $sb = false; }
        if ($sb === false || trim((string)$sb) !== $kisit) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit('Bu belge başka bir şubeye ait; erişim yetkiniz yok.');
        }
    }
}

if (in_array($klasor, ['nakliye', 'kiralama_puantaj'], true)) {
    $oid = (int)(explode('/', $rel)[1] ?? 0);
    if ($oid < 1) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('Bulunamadı.'); }
    $kisit = subeKisiti();
    if ($kisit !== null) {
        try {
            $st = getDb()->prepare($klasor === 'nakliye' ? 'SELECT sube FROM nakliyeler WHERE id = ?' : 'SELECT k.sube FROM kiralama_puantaj p JOIN kiralamalar k ON k.id = p.kiralama_id WHERE p.id = ?');
            $st->execute([$oid]);
            $sb = $st->fetchColumn();
        } catch (\Throwable $e) { $sb = false; }
        if ($sb === false || trim((string)$sb) !== $kisit) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit('Bu belge başka bir şubeye ait; erişim yetkiniz yok.');
        }
    }
}

if ($klasor === 'dis_kiralama') {
    $st = getDb()->prepare("SELECT m.sube FROM dis_kiralama_belgeler b JOIN dis_kiralama_kayitlari d ON d.id=b.dis_kiralama_id JOIN (SELECT id,sube,'arac' AS tur FROM araclar UNION ALL SELECT id,sube,'manlift' AS tur FROM manliftler) m ON m.id=d.makine_id AND m.tur=d.makine_tur WHERE b.dosya_yolu=?");
    $st->execute([$rel]);
    $row=$st->fetch();
    if (!$row) { http_response_code(404); exit('Belge bulunamadı.'); }
    subeErisimDogrula($row['sube']);
}
$tam = UPLOAD_PATH . $rel;
$gercek = realpath($tam);
$kok    = realpath(rtrim(UPLOAD_PATH, '/\\'));
if ($gercek === false || $kok === false || !str_starts_with($gercek, $kok . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Bulunamadı.');
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($gercek) ?: 'application/octet-stream';
header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($gercek));
header('Cache-Control: private, max-age=300');
header('Content-Disposition: inline; filename="' . basename($gercek) . '"');

while (ob_get_level() > 0) ob_end_clean();
readfile($gercek);
