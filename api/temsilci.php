<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();

$rows = getDb()->query("SELECT id, ad_soyad, username FROM users ORDER BY ad_soyad COLLATE NOCASE, username")->fetchAll();
$out = array_map(fn($r) => ['id' => (int)$r['id'], 'ad' => trim((string)($r['ad_soyad'] ?: $r['username']))], $rows);
jsonOk($out);
