<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
$db = getDb();
$okur = hasPermission('manlift') || hasPermission('arac') || hasPermission('dis_kiralama');
if (!$okur) jsonError('Bu işlem için yetkiniz yok.', 403);
if ($_SERVER['REQUEST_METHOD'] === 'GET') jsonOk(['kategoriler' => mkListe($db), 'sablonlar' => MK_SABLONLAR]);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method Not Allowed', 405);
verifyCsrf();
if (!hasPermission('manlift', 'yaz') && !hasPermission('arac', 'yaz') && !hasPermission('dis_kiralama', 'yaz')) jsonError('Kategori eklemek için makine yazma yetkisi gerekir.', 403);
$b = govdeOku();
if (($b['action'] ?? '') === 'ana_grup') {
    try { $r = mkAnaGrupYaz($db, is_string($b['ad'] ?? null) ? $b['ad'] : '', is_string($b['ana_grup'] ?? null) ? $b['ana_grup'] : ''); }
    catch (DomainException $e) { jsonError($e->getMessage()); }
    logla($db, 'makine_kategorileri', 0, 'guncelle', $r['eski'], $r['yeni'], 'Kategori ana grubu: ' . $r['yeni']['ad'] . ' → ' . ($r['yeni']['ana_grup'] !== '' ? $r['yeni']['ana_grup'] : 'boş'));
    jsonOk(['kategoriler' => mkListe($db)], 'Ana grup kaydedildi.');
}
try { $r = mkEkle($db, is_string($b['ad'] ?? null) ? $b['ad'] : '', is_string($b['sablon'] ?? null) ? $b['sablon'] : ''); }
catch (DomainException $e) { jsonError($e->getMessage()); }
if ($r['yeni']) logla($db, 'makine_kategorileri', 0, 'ekle', null, $r, 'Makine kategorisi eklendi: ' . $r['ad']);
jsonOk($r, $r['yeni'] ? 'Kategori eklendi.' : 'Bu kategori zaten var; seçildi.');
