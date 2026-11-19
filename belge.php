<?php
declare(strict_types=1);
require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/musteri-erisim-lib.php';
require_once __DIR__ . '/api/kod-kapisi-lib.php';
require_once __DIR__ . '/api/varlik-damga-lib.php';
ob_start(fn(string $s): string => varliklariDamgala($s, __DIR__));

$token   = preg_replace('/[^a-f0-9]/', '', $_GET['t'] ?? '');
$row     = null;
$suresiDoldu = false;

if ($token !== '' && strlen($token) >= 32) {
    $db = getDb();
    meSema($db);
    $st = $db->prepare("SELECT id, baslik, html, created_at, expires_at, iptal, musteri_id FROM form_paylasim WHERE token = ?");
    $st->execute([$token]);
    $row = $st->fetch();

    if ($row) {
        $bitis = (string)($row['expires_at'] ?? '');
        if ((int)($row['iptal'] ?? 0) === 1 || ($bitis !== '' && $bitis < date('Y-m-d H:i:s'))) {
            $suresiDoldu = true;
            $row = null;
        } else {
            if (session_status() === PHP_SESSION_NONE) session_start();
            $_bm = (int)($row['musteri_id'] ?? 0);
            if (!($_bm > 0 ? meBaglantiIzinli($db, 'form', $token) : (!empty($_SESSION['user_id']) && hasPermission('formlar', 'oku')))) {
                meErisimReddi($db, 'belge.php?t=' . $token, $_bm > 0);
            }
            $_SESSION['kk_acik'] = kkIzinVer((array)($_SESSION['kk_acik'] ?? []), ['belge.js', 'eylem.js'], time());
            try {
                $db->prepare("UPDATE form_paylasim SET goruntulenme = COALESCE(goruntulenme,0) + 1 WHERE id = ?")
                   ->execute([$row['id']]);
            } catch (\Throwable $e) { hataGunlugu('belge.goruntulenme', $e); }
        }
    }
}

header('Content-Type: text/html; charset=utf-8');
$baslik = $row ? ($row['baslik'] ?: 'Belge')
                : ($suresiDoldu ? 'Bağlantının Süresi Doldu' : 'Belge Bulunamadı');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<meta name="robots" content="noindex,nofollow"/>
<title><?= htmlspecialchars($baslik, ENT_QUOTES, 'UTF-8') ?></title>
<style>
  *{box-sizing:border-box}
  body{margin:0;background:#eef2f9;font-family:Arial,sans-serif;color:#1a1a1a}
  .toolbar{position:sticky;top:0;z-index:10;background:#00236f;color:#fff;display:flex;justify-content:space-between;align-items:center;padding:10px 16px;box-shadow:0 2px 8px rgba(0,0,0,.15)}
  .toolbar .ttl{font-size:14px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .toolbar button{background:#fff;color:#00236f;border:none;border-radius:8px;padding:8px 16px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
  .toolbar button:hover{opacity:.9}
  .sheet{max-width:820px;margin:18px auto;background:#fff;padding:24px;border-radius:8px;box-shadow:0 4px 24px rgba(0,0,0,.08)}
  .notfound{max-width:480px;margin:80px auto;text-align:center;color:#64748b}
  .notfound h1{color:#00236f}

  /* ── Print document styles (formlar.html ile eşleşir) ── */
  .p-doc{max-width:780px;margin:0 auto;font-family:Arial,sans-serif;color:#1a1a1a}
  .p-header{display:flex;justify-content:space-between;align-items:flex-start;padding-bottom:10px;border-bottom:2px solid #00236f;margin-bottom:14px}
  .p-logo{height:50px}
  .p-title-block{text-align:right}
  .p-title{font-size:16pt;font-weight:700;color:#00236f;margin:0}
  .p-subtitle{font-size:9pt;color:#555;margin-top:2px}
  .p-section{margin-bottom:12px}
  .p-section-title{font-size:9pt;font-weight:700;text-transform:uppercase;color:#00236f;letter-spacing:.05em;border-bottom:1px solid #dce1ff;padding-bottom:2px;margin-bottom:6px}
  .p-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:4px 16px}
  .p-field label{font-size:8pt;color:#666;display:block}
  .p-field span{font-size:10pt;font-weight:600;display:block}
  .p-table{width:100%;border-collapse:collapse;font-size:9.5pt}
  .p-table th{background:#00236f;color:#fff;padding:5px 8px;text-align:left;font-size:8pt;font-weight:600}
  .p-table td{padding:4px 8px;border-bottom:1px solid #e8eaf0}
  .p-table tr:nth-child(even) td{background:#f7f9ff}
  .p-totals{margin-top:8px;text-align:right}
  .p-totals table{margin-left:auto;border-collapse:collapse}
  .p-totals td{padding:3px 10px;font-size:10pt}
  .p-totals .grand{font-weight:700;font-size:12pt;color:#00236f;border-top:2px solid #00236f}
  .p-notes{font-size:9pt;color:#444;margin-top:8px;border-top:1px dashed #ccc;padding-top:6px}
  .p-sig{display:grid;grid-template-columns:1fr 1fr;gap:30px;margin-top:30px}
  .p-sig-box{border-top:1px solid #333;padding-top:6px;text-align:center;font-size:9pt}
  .p-bank{font-size:9pt}
  .p-bank td{padding:2px 8px}
  .p-footer{margin-top:10px;font-size:8pt;color:#888;border-top:1px solid #e2e8f0;padding-top:6px;text-align:center}

  @media print {
    .toolbar{display:none!important}
    body{background:#fff}
    .sheet{max-width:100%;margin:0;padding:0;box-shadow:none;border-radius:0}
    @page{margin:10mm;size:A4}
  }
</style>
<link rel="stylesheet" href="css/belge.css"/>
<script src="js/eylem.js"></script>
<script src="js/belge.js" defer></script>
</head>
<?php
$_belgeSubeler = [];
try { $_belgeSubeler = getDb()->query("SELECT ad, il, adres, telefon, eposta FROM subeler WHERE aktif=1 AND COALESCE(belgede_goster,1)=1 ORDER BY COALESCE(sira,0), id")->fetchAll(PDO::FETCH_ASSOC); } catch (\Throwable $e) { hataGunlugu('belge.subeler', $e); }
?>
<body data-belge-firma="<?= htmlspecialchars(json_encode(['subeler' => $_belgeSubeler], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">
<?php if ($row): ?>
  <div class="toolbar">
    <span class="ttl"><?= htmlspecialchars($baslik, ENT_QUOTES, 'UTF-8') ?></span>
    <button type="button" data-on-click="AvkBelge.document" data-args="<?= htmlspecialchars(json_encode(['belgeSayfasi', ['title' => $baslik]], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">🖨️ Yazdır / PDF</button>
  </div>
  <div class="sheet" id="belgeSayfasi">
    <?= $row['html']  ?>
  </div>
<?php else: ?>
  <div class="notfound">
<?php if ($suresiDoldu): ?>
    <h1>Bağlantının süresi doldu</h1>
    <p>Bu belge bağlantısı artık geçerli değil.<br/>Belgeyi size gönderen kişiden yeni bir bağlantı isteyin.</p>
<?php else: ?>
    <h1>Belge bulunamadı</h1>
    <p>Bu paylaşım bağlantısı geçersiz veya kaldırılmış olabilir.</p>
<?php endif; ?>
  </div>
<?php endif; ?>
</body>
</html>
