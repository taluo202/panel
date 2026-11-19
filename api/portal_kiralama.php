<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$oturum = portalRequireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonError('Desteklenmeyen metod.', 405);

$db = getDb();

$musteriId = $oturum['musteri_id'];

$st = $db->prepare("
    SELECT id, kod, ekipman_adi, makine_tur, makine_id, baslangic, bitis, gercek_bitis,
           durum, operatorlu
    FROM kiralamalar
    WHERE musteri_id = ?
    ORDER BY baslangic DESC
");
$st->execute([$musteriId]);
$liste = $st->fetchAll();

if ($liste) {
    $aracIds    = array_values(array_unique(array_map('intval', array_column(array_filter($liste, fn($k) => $k['makine_tur'] === 'arac'), 'makine_id'))));
    $manliftIds = array_values(array_unique(array_map('intval', array_column(array_filter($liste, fn($k) => $k['makine_tur'] === 'manlift'), 'makine_id'))));
    $adMap = [];
    if ($aracIds) {
        $ph = implode(',', array_fill(0, count($aracIds), '?'));
        $s = $db->prepare("SELECT id, plaka AS ad, marka, model FROM araclar WHERE id IN ($ph)");
        $s->execute($aracIds);
        foreach ($s->fetchAll() as $r) $adMap['arac:' . $r['id']] = trim($r['ad'] . ($r['marka'] ? ' — ' . $r['marka'] : ''));
    }
    if ($manliftIds) {
        $ph = implode(',', array_fill(0, count($manliftIds), '?'));
        $s = $db->prepare("SELECT id, kod AS ad, marka, model FROM manliftler WHERE id IN ($ph)");
        $s->execute($manliftIds);
        foreach ($s->fetchAll() as $r) $adMap['manlift:' . $r['id']] = trim($r['ad'] . ($r['marka'] ? ' — ' . $r['marka'] : ''));
    }

    foreach ($liste as &$k) {
        $k['makine_ad'] = $adMap[$k['makine_tur'] . ':' . $k['makine_id']] ?? $k['ekipman_adi'];
        $k['servis_gecmisi'] = [];
        if (!$k['makine_tur'] || !$k['makine_id']) continue;
        $bitisSiniri = $k['gercek_bitis'] ?: ($k['bitis'] ?: '9999-12-31');
        $sSt = $db->prepare("
            SELECT id, tarih, tip, durum, ariza, tamamlanma FROM teknik_servis s
            WHERE (kiralama_id = ? AND (s.kaynak IN ('portal','operator','donus_kontrolu','depo_inceleme') OR s.tutanak_id IS NOT NULL OR s.tarih BETWEEN ? AND ?))
               OR (
                    kiralama_id IS NULL AND makine_tur = ? AND makine_id = ? AND tarih BETWEEN ? AND ?
                    AND NOT EXISTS (
                        SELECT 1 FROM kiralamalar k2
                        WHERE k2.makine_tur = s.makine_tur AND k2.makine_id = s.makine_id
                          AND k2.musteri_id <> ?
                          AND s.tarih BETWEEN k2.baslangic AND COALESCE(k2.gercek_bitis, k2.bitis, '9999-12-31')
                    )
                  )
            ORDER BY tarih DESC
        ");
        $sSt->execute([$k['id'], $k['baslangic'], $bitisSiniri, $k['makine_tur'], $k['makine_id'], $k['baslangic'], $bitisSiniri, $musteriId]);
        $k['servis_gecmisi'] = $sSt->fetchAll();
    }
    unset($k);
}

jsonOk($liste);
