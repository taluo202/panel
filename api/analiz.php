<?php
declare(strict_types=1);
require_once __DIR__ . '/analiz_motor.php';
require_once __DIR__ . '/claude.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonError('Method Not Allowed', 405);

$db = getDb();
$action = $_GET['action'] ?? '';

function analizKarlilikTurleri(): array {
    if (!hasPermission('rapor', 'oku') || !hasPermission('kiralama_takip', 'oku')) return [];
    return array_values(array_filter(['arac', 'manlift'], fn($t) => hasPermission($t, 'oku')));
}

if ($action === 'karlilik') {
    $turler = analizKarlilikTurleri();
    if (!$turler) jsonError('Makine kârlılığı için Raporlar, Kiralama Takip ve Araç veya Makine okuma yetkisi gerekir.', 403);
    $bas = _anTarih($_GET['bas'] ?? null, date('Y-01-01'));
    $bit = _anTarih($_GET['bit'] ?? null, date('Y-m-d'));
    if ($bas > $bit) [$bas, $bit] = [$bit, $bas];
    $sube = subeKisiti() ?? (trim(metin($_GET['sube'] ?? '')) ?: null);
    $sonuc = analizMakineKarlilik($db, $bas, $bit, $sube, $turler);
    $sonuc['ozet']['sube'] = $sube;
    jsonOk($sonuc);
}

if ($action === 'risk') {
    requirePermission('kiralama_takip', 'oku');
    $kapsam = analizMusteriKapsami($db, subeKisiti());
    $mid = (int)($_GET['musteri_id'] ?? 0);
    if ($mid > 0) {
        if ($kapsam !== null && !in_array($mid, $kapsam, true)) jsonError('Müşteri bulunamadı.', 404);
        $r = analizMusteriRisk($db, [$mid]);
        jsonOk($r['musteriler'][0] ?? null);
    }
    jsonOk(analizMusteriRisk($db, $kapsam));
}

if ($action === 'durum') {
    $ayar = claudeAyar($db);
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $admin = ($_SESSION['rol'] ?? '') === 'admin';
    $st = $db->prepare("SELECT COUNT(*) n, COALESCE(SUM(girdi_token),0) g, COALESCE(SUM(cikti_token),0) c
                        FROM ai_kullanim WHERE tarih >= ? " . ($admin ? '' : 'AND user_id = ?'));
    $st->execute($admin ? [date('Y-m-01')] : [date('Y-m-01'), $uid]);
    $k = $st->fetch() ?: ['n' => 0, 'g' => 0, 'c' => 0];
    jsonOk([
        'ai_aktif' => $ayar['aktif'],
        'model' => $ayar['aktif'] ? $ayar['model'] : null,
        'yetki' => [
            'karlilik' => (bool)analizKarlilikTurleri(),
            'risk' => hasPermission('kiralama_takip', 'oku'),
            'yorum' => hasPermission('yonetim', 'oku') && hasPermission('kiralama_takip', 'oku') && hasPermission('arac', 'oku') && hasPermission('manlift', 'oku'),
        ],
        'kullanim_bu_ay' => ['istek' => (int)$k['n'], 'girdi_token' => (int)$k['g'], 'cikti_token' => (int)$k['c'], 'kapsam' => $admin ? 'tum' : 'kendi'],
        'saatlik_limit' => CLAUDE_SAATLIK_LIMIT,
    ]);
}

jsonError('Geçersiz işlem.');
