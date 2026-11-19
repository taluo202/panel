<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$sess = requireAuth();
$db     = getDb();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {

    case 'GET':
        $sayfa = trim(metin($_GET['sayfa'] ?? ''));
        if ($sayfa === '') jsonError('Sayfa parametresi zorunludur.');
        $st = $db->prepare("SELECT id, ad, filtre_json, created_at FROM kayitli_filtreler WHERE kullanici_id=? AND sayfa=? ORDER BY id DESC");
        $st->execute([$sess['user_id'], $sayfa]);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) { $r['filtre'] = json_decode($r['filtre_json'], true) ?: []; unset($r['filtre_json']); }
        unset($r);
        jsonOk($rows);
        break;

    case 'POST':
        verifyCsrf();
        $body  = govdeOku();
        $sayfa = trim(metin($body['sayfa'] ?? ''));
        $ad    = trim(metin($body['ad'] ?? ''));
        $filtre = $body['filtre'] ?? null;
        if ($sayfa === '' || $ad === '') jsonError('Sayfa ve isim zorunludur.');
        if (!is_array($filtre)) jsonError('Filtre verisi geçersiz.');
        $db->prepare("INSERT INTO kayitli_filtreler (kullanici_id, sayfa, ad, filtre_json) VALUES (?,?,?,?)")
           ->execute([$sess['user_id'], $sayfa, $ad, json_encode($filtre, JSON_UNESCAPED_UNICODE)]);
        jsonOk(['id' => (int)$db->lastInsertId()], 'Filtre kaydedildi.');

    case 'DELETE':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $db->prepare("DELETE FROM kayitli_filtreler WHERE id=? AND kullanici_id=?")->execute([$id, $sess['user_id']]);
        jsonOk(null, 'Filtre silindi.');

    default:
        jsonError('Method Not Allowed', 405);
}
