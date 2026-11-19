<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/operasyon-liste.php';
requireAuth();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonError('Method Not Allowed', 405);
header('Cache-Control: no-store');
jsonOk(operasyonListe(getDb(), date('Y-m-d'), fn(string $modul): bool => hasPermission($modul, 'oku'), subeKisiti()));
