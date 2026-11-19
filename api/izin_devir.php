<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('personel');

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDb();

if ($method === 'GET') {
    $yil = (int)($_GET['yil'] ?? (date('Y') - 1));
    if ($yil < 2020 || $yil > (int)date('Y')) jsonError('Geçersiz yıl.');

    $personeller = $db->query(
        "SELECT id, ad_soyad, ise_giris, ekstra_izin_hak FROM personel WHERE durum='aktif' ORDER BY ad_soyad"
    )->fetchAll();

    $liste = [];
    foreach ($personeller as $p) {
        $pid = (int)$p['id'];

        $_izin     = yillikIzinHakki($p['ise_giris'] ?? null, "$yil-12-31", 0, 14);
        $hak       = $_izin['taban'];
        $toplamHak = $hak + (int)$p['ekstra_izin_hak'];

        $stmt = $db->prepare("
            SELECT COALESCE(SUM(gun_sayisi),0)
            FROM izin_talepleri
            WHERE personel_id=?
              AND izin_turu IN ('yillik','ekstra')
              AND durum IN ('onaylandi','bekliyor')
              AND baslangic LIKE ?
        ");
        $stmt->execute([$pid, "$yil-%"]);
        $kullanilan = (int)$stmt->fetchColumn();

        $devir = max(0, $toplamHak - $kullanilan);

        $logStmt = $db->prepare("SELECT devir_gun FROM izin_devir_log WHERE yil=? AND personel_id=?");
        $logStmt->execute([$yil, $pid]);
        $log = $logStmt->fetch();

        $liste[] = [
            'id'          => $pid,
            'ad_soyad'    => $p['ad_soyad'],
            'toplam_hak'  => $toplamHak,
            'kullanilan'  => $kullanilan,
            'devir'       => $devir,
            'islendi'     => (bool)$log,
            'onceki_devir'=> $log ? (int)$log['devir_gun'] : null,
        ];
    }

    $islendi = $db->prepare("SELECT COUNT(*) FROM izin_devir_log WHERE yil=?");
    $islendi->execute([$yil]);
    $zatenIslendi = (int)$islendi->fetchColumn() > 0;

    jsonOk(['yil' => $yil, 'zaten_islendi' => $zatenIslendi, 'liste' => $liste]);
}

if ($method === 'POST') {
    verifyCsrf();

    $body = govdeOku();
    $yil  = (int)($body['yil'] ?? (date('Y') - 1));

    if ($yil < 2020 || $yil > (int)date('Y')) jsonError('Geçersiz yıl.');

    $check = $db->prepare("SELECT COUNT(*) FROM izin_devir_log WHERE yil=?");
    $check->execute([$yil]);
    if ((int)$check->fetchColumn() > 0) jsonError("$yil yılı devri zaten uygulandı.");

    $personeller = $db->query(
        "SELECT id, ad_soyad, ise_giris, ekstra_izin_hak FROM personel WHERE durum='aktif'"
    )->fetchAll();

    $db->beginTransaction();
    try {
        $toplamDevir = 0;
        $detay = [];
        foreach ($personeller as $p) {
            $pid = (int)$p['id'];

            $hak       = yillikIzinHakki($p['ise_giris'] ?? null, "$yil-12-31", 0, 14)['taban'];
            $toplamHak = $hak + (int)$p['ekstra_izin_hak'];

            $stmt = $db->prepare("
                SELECT COALESCE(SUM(gun_sayisi),0)
                FROM izin_talepleri
                WHERE personel_id=?
                  AND izin_turu IN ('yillik','ekstra')
                  AND durum IN ('onaylandi','bekliyor')
                  AND baslangic LIKE ?
            ");
            $stmt->execute([$pid, "$yil-%"]);
            $kullanilan = (int)$stmt->fetchColumn();

            $devir = max(0, $toplamHak - $kullanilan);

            if ($devir > 0) {
                $db->prepare("UPDATE personel SET ekstra_izin_hak = ? WHERE id=?")
                   ->execute([$devir, $pid]);
                $toplamDevir += $devir;
            }

            $db->prepare("INSERT OR IGNORE INTO izin_devir_log (yil, personel_id, devir_gun) VALUES (?,?,?)")
               ->execute([$yil, $pid, $devir]);

            $detay[] = ['ad_soyad' => $p['ad_soyad'], 'devir' => $devir];
        }
        $db->commit();
        logla($db, 'personel', null, 'guncelle', null, null,
              sprintf('%s yılı izin devri: %d personel, toplam %s gün devredildi',
                      $yil, count($detay), $toplamDevir));

        jsonOk(['yil' => $yil, 'toplam_devir' => $toplamDevir, 'detay' => $detay], "$yil yılı izin devri tamamlandı. Toplam $toplamDevir gün devredildi.");
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        istisnaYanit($e, 'izin devri');
    }
}

http_response_code(405);
echo 'Method Not Allowed';
