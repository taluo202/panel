<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();

$yil = (int)($_GET['yil'] ?? date('Y'));
if ($yil < 2000 || $yil > 2100) $yil = (int)date('Y');
jsonOk(resmiTatiller($yil));
