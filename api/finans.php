<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('finans');

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDb();

switch ($method) {

    case 'GET':
        $ay = trim(metin($_GET['ay'] ?? date('Y-m')));
        if (!preg_match('/^\d{4}-\d{2}$/', $ay)) $ay = date('Y-m');

        $stmt = $db->prepare("
            SELECT p.id as personel_id, p.ad_soyad, p.ise_giris, p.durum, p.pozisyon,
                   f.id as finans_id,
                   COALESCE(f.toplam_maas,
                       (SELECT f2.toplam_maas FROM finans f2
                        WHERE f2.personel_id = p.id AND f2.ay < ?
                        ORDER BY f2.ay DESC LIMIT 1),
                       0) as toplam_maas,
                   COALESCE(f.normal_mesai_ucret,
                       (SELECT f2.normal_mesai_ucret FROM finans f2
                        WHERE f2.personel_id = p.id AND f2.ay < ?
                        ORDER BY f2.ay DESC LIMIT 1),
                       0) as normal_mesai_ucret,
                   COALESCE(f.pazar_mesai_ucret,
                       (SELECT f2.pazar_mesai_ucret FROM finans f2
                        WHERE f2.personel_id = p.id AND f2.ay < ?
                        ORDER BY f2.ay DESC LIMIT 1),
                       0) as pazar_mesai_ucret,
                   COALESCE(f.mesai, 0) as mesai,
                   COALESCE(f.normal_mesai_saat, 0) as normal_mesai_saat,
                   COALESCE(f.pazar_mesai_gun,  0) as pazar_mesai_gun,
                   COALESCE(f.konaklama_gun,    0) as konaklama_gun,
                   COALESCE(f.haciz_kesinti, 0) as haciz_kesinti,
                   COALESCE(f.avans, 0) as avans,
                   COALESCE(f.prim, 0) as prim,
                   COALESCE(f.bankaya_yatan, 0) as bankaya_yatan,
                   COALESCE(f.elden_kalan, 0) as elden_kalan,
                   COALESCE(f.bes, 0) as bes,
                   f.notlar
            FROM personel p
            LEFT JOIN finans f ON f.personel_id = p.id AND f.ay = ?
            WHERE p.durum != 'pasif'
            ORDER BY p.ad_soyad
        ");
        $stmt->execute([$ay, $ay, $ay, $ay]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['mesaili_toplam'] = (float)$row['toplam_maas'] + (float)$row['mesai'] + (float)$row['prim'];
            $row['devir'] = finansDevir($db, (int)$row['personel_id'], $ay);
            $row['elden_kalan'] = $row['mesaili_toplam'] + $row['devir']
                - (float)$row['haciz_kesinti'] - (float)$row['avans'] - (float)$row['bes'] - (float)$row['bankaya_yatan'];
        }
        unset($row);

        $totals = [
            'toplam_maas'   => 0, 'mesai'         => 0,
            'haciz_kesinti' => 0, 'prim'           => 0,
            'avans'         => 0, 'bankaya_yatan'  => 0,
            'elden_kalan'   => 0, 'bes'            => 0,
            'mesaili_toplam'=> 0, 'devir'          => 0,
        ];
        foreach ($rows as $r) {
            foreach ($totals as $k => $_) $totals[$k] += (float)$r[$k];
        }

        jsonOk(['ay' => $ay, 'rows' => $rows, 'totals' => $totals]);
        break;

    case 'POST':
    case 'PUT':
        verifyCsrf();
        $body       = govdeOku();
        $personelId = (int)($body['personel_id'] ?? 0);
        $ay         = trim(metin($body['ay'] ?? ''));
        $_logEski = null;
        if ($personelId > 0 && preg_match('/^[0-9]{4}-[0-9]{2}$/', $ay)) {
            $_q = $db->prepare("SELECT * FROM finans WHERE personel_id=? AND ay=?");
            $_q->execute([$personelId, $ay]);
            $_logEski = $_q->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($personelId < 1 || !preg_match('/^\d{4}-\d{2}$/', $ay)) {
            jsonError('Personel ID ve ay (YYYY-MM) zorunludur.');
        }

        $normalUcret = max(0, (float)($body['normal_mesai_ucret'] ?? 0));
        $pazarUcret  = max(0, (float)($body['pazar_mesai_ucret']  ?? 0));
        $normalSaat  = max(0, (float)($body['normal_mesai_saat']  ?? 0));
        $pazarGun    = max(0, (float)($body['pazar_mesai_gun']    ?? 0));
        $konaklamaGun = max(0, (float)($body['konaklama_gun'] ?? 0));

        $maas  = max(0, (float)($body['toplam_maas']   ?? 0));
        $mesai = finansMesaiTutar($normalSaat, $normalUcret, $pazarGun, $pazarUcret, $konaklamaGun, $maas);
        $prim  = max(0, (float)($body['prim']          ?? 0));
        $haciz = max(0, (float)($body['haciz_kesinti'] ?? 0));
        $avans = max(0, (float)($body['avans']         ?? 0));
        $bes   = max(0, (float)($body['bes']           ?? 0));
        $banka = max(0, (float)($body['bankaya_yatan'] ?? 0));
        $devir = finansDevir($db, $personelId, $ay);
        $elden = ($maas + $mesai + $prim) + $devir - $haciz - $avans - $bes - $banka;

        $_fid = islemIcinde($db, function (PDO $db) use ($personelId, $ay, $maas, $mesai, $normalUcret, $pazarUcret, $normalSaat, $pazarGun, $konaklamaGun, $haciz, $avans, $prim, $banka, $elden, $bes, $devir, $body, $_logEski): int {
        $stmt = $db->prepare("
            INSERT OR REPLACE INTO finans
                (personel_id, ay, toplam_maas, mesai,
                 normal_mesai_ucret, pazar_mesai_ucret,
                 normal_mesai_saat,  pazar_mesai_gun, konaklama_gun,
                 haciz_kesinti, avans, prim, bankaya_yatan, elden_kalan, bes, devir, notlar)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $personelId, $ay,
            $maas, $mesai,
            $normalUcret, $pazarUcret,
            $normalSaat,  $pazarGun, $konaklamaGun,
            $haciz, $avans,
            $prim,
            $banka, $elden, $bes, $devir,
            trim(metin($body['notlar'] ?? '')) ?: null,
        ]);
        $_fid = (int)$db->query("SELECT id FROM finans WHERE personel_id=" . (int)$personelId . " AND ay=" . $db->quote($ay))->fetchColumn();
        logla($db, 'finans', $_fid ?: null, $_logEski ? 'guncelle' : 'ekle', $_logEski, logKayitAl($db, 'finans', $_fid ?: null),
              'Maaş kaydı (' . $ay . ')');
        return $_fid;
        });
        jsonOk(['id' => $_fid], 'Kaydedildi.');

    default:
        jsonError('Method Not Allowed', 405);
}
