<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

const CSP_RAPOR_AZAMI_BAYT  = 8192;
const CSP_RAPOR_IP_SINIRI   = 20;
const CSP_RAPOR_GENEL_SINIR = 500;

function cspYanit(int $kod): never {
    http_response_code($kod);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    exit;
}

function cspAdres(mixed $u): string {
    $u = is_string($u) ? $u : '';
    if ($u === '' || preg_match('/^[a-z-]+$/', $u)) return mb_substr($u, 0, 40);
    $p = parse_url($u);
    if (!is_array($p)) return '?';
    $yol = (string)($p['path'] ?? '');
    $kok = isset($p['host']) ? ((string)($p['scheme'] ?? 'http') . '://' . $p['host']) : '';
    return mb_substr(preg_replace('/[^\w.\/:\-@%]/u', '_', $kok . $yol) ?? '', 0, 160);
}

function cspKisa(mixed $v, int $n): string {
    $v = is_scalar($v) ? (string)$v : '';
    return mb_substr(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v) ?? '', 0, $n);
}

function cspAd(mixed $u, bool $alanAdi = false): string {
    $u = is_string($u) ? $u : '';
    if ($u === '' || preg_match('/^[a-z-]+$/', $u)) return $u === '' ? '-' : mb_substr($u, 0, 40);
    $p = parse_url(cspAdres($u));
    $ad = preg_replace('/\.(html|js)$/', '', basename((string)($p['path'] ?? ''))) ?? '';
    if (str_starts_with($ad, 's-')) $ad = substr($ad, 2) . ' betiği';
    return trim(($alanAdi && isset($p['host']) ? $p['host'] . ' ' : '') . $ad) ?: '-';
}

function cspOzet(array $r): string {
    return cspKisa($r['yonerge'], 40) . ' | engellenen: ' . cspAd($r['engellenen'], true)
        . ' | sayfa: ' . cspAd($r['sayfa']) . ' | kaynak: ' . cspAd($r['kaynak']) . ' satır ' . (int)$r['satir']
        . ' | örnek: ' . cspKisa($r['ornek'], 80);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    cspYanit(405);
}

$ham = (string)file_get_contents('php://input', false, null, 0, CSP_RAPOR_AZAMI_BAYT + 1);
if (strlen($ham) > CSP_RAPOR_AZAMI_BAYT) cspYanit(413);
$veri = json_decode($ham, true);
if (!is_array($veri)) cspYanit(400);

$raporlar = [];
if (isset($veri['csp-report']) && is_array($veri['csp-report'])) {
    $c = $veri['csp-report'];
    $raporlar[] = ['yonerge' => $c['effective-directive'] ?? $c['violated-directive'] ?? '', 'engellenen' => $c['blocked-uri'] ?? '',
                   'sayfa' => $c['document-uri'] ?? '', 'kaynak' => $c['source-file'] ?? '', 'satir' => $c['line-number'] ?? 0,
                   'ornek' => $c['script-sample'] ?? ''];
} elseif (array_is_list($veri)) {
    foreach (array_slice($veri, 0, 10) as $r) {
        if (!is_array($r) || ($r['type'] ?? '') !== 'csp-violation' || !is_array($r['body'] ?? null)) continue;
        $b = $r['body'];
        $raporlar[] = ['yonerge' => $b['effectiveDirective'] ?? '', 'engellenen' => $b['blockedURL'] ?? '',
                       'sayfa' => $b['documentURL'] ?? ($r['url'] ?? ''), 'kaynak' => $b['sourceFile'] ?? '', 'satir' => $b['lineNumber'] ?? 0,
                       'ornek' => $b['sample'] ?? ''];
    }
}
if (!$raporlar) cspYanit(400);

try {
    $db = getDb();
    $ip = istekIp();
    $s = $db->prepare("SELECT COUNT(*) FROM checkin_attempts WHERE kaynak='csp_rapor' AND ip=? AND attempted_at >= datetime('now','-10 minutes','localtime')");
    $s->execute([$ip]);
    if ((int)$s->fetchColumn() >= CSP_RAPOR_IP_SINIRI) cspYanit(429);
    $g = $db->query("SELECT COUNT(*) FROM checkin_attempts WHERE kaynak='csp_rapor' AND attempted_at >= datetime('now','-1 hour','localtime')");
    if ((int)$g->fetchColumn() >= CSP_RAPOR_GENEL_SINIR) cspYanit(429);
    $db->prepare("INSERT INTO checkin_attempts(personel_id, ip, success, kaynak) VALUES(0, ?, 1, 'csp_rapor')")->execute([$ip]);
    if (random_int(1, 50) === 1) $db->exec("DELETE FROM checkin_attempts WHERE kaynak='csp_rapor' AND attempted_at < datetime('now','-1 day','localtime')");
} catch (\Throwable $e) {
    hataGunlugu('csp_rapor', $e);
    cspYanit(503);
}

foreach ($raporlar as $r) hataGunlugu('csp_ihlal', new \RuntimeException(cspOzet($r)));
cspYanit(204);
