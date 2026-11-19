<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('kiralama_takip');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonError('Method Not Allowed', 405);

require_once __DIR__ . '/filo-takvim.php';
jsonOk(filoTakvimVerisi(getDb(), $_GET, subeKisiti(), hasPermission('teknik_servis', 'oku')));
