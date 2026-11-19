<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$db     = getDb();

if ($method === 'GET' && $action === 'liste') {
    requireAuth();
    requirePermission('personel');
    $st = $db->query("SELECT id, ad_soyad, pozisyon FROM personel WHERE durum='aktif' ORDER BY ad_soyad");
    jsonOk($st->fetchAll());
}

switch ($method) {

    case 'GET':
        requireAuth();
        requirePermission('personel');
        $where = []; $params = [];
        if (($pid = (int)($_GET['personel_id'] ?? 0)) > 0) { $where[] = "c.personel_id=?"; $params[] = $pid; }
        if (($gun = trim(metin($_GET['gun'] ?? ''))) !== '') { $where[] = "substr(c.tarih_saat,1,10)=?"; $params[] = $gun; }
        $sql = "SELECT c.*, p.ad_soyad, p.pozisyon FROM personel_checkin c JOIN personel p ON p.id=c.personel_id";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY c.tarih_saat DESC LIMIT 200";
        $st = $db->prepare($sql); $st->execute($params);
        jsonOk($st->fetchAll());
        break;

    case 'POST':
        requireAuth();
        verifyCsrf();
        $body = govdeOku();
        $tip = in_array($body['tip'] ?? '', ['giris','cikis'], true) ? $body['tip'] : 'giris';
        $uSt = $db->prepare("SELECT p.id FROM users u JOIN personel p ON p.id = u.personel_id
                             WHERE u.id = ? AND COALESCE(p.durum,'aktif') = 'aktif'");
        $uSt->execute([(int)$_SESSION['user_id']]);
        $personelId = (int)($uSt->fetchColumn() ?: 0);
        if ($personelId < 1) jsonError('Hesabınız aktif bir personel kaydına bağlı değil. Yöneticiniz Ayarlar → Kullanıcılar ekranından bağlamalı.', 403);

        $enlem  = isset($body['enlem'])  && $body['enlem']  !== '' ? (float)$body['enlem']  : null;
        $boylam = isset($body['boylam']) && $body['boylam'] !== '' ? (float)$body['boylam'] : null;
        $dogruluk = isset($body['konum_dogruluk']) && $body['konum_dogruluk'] !== '' ? (float)$body['konum_dogruluk'] : null;

        $_anahtar = istemciAnahtari($body);
        if (($_varolan = mukerrerKayitId($db, 'personel_checkin', $_anahtar)) !== null) {
            jsonOk(['id' => $_varolan, 'mukerrer' => true], 'Bu kayıt zaten alınmıştı.');
        }

        $db->prepare("
            INSERT INTO personel_checkin (personel_id, tip, enlem, boylam, konum_dogruluk, lokasyon_adi, istemci_anahtari)
            VALUES (?,?,?,?,?,?,?)
        ")->execute([
            $personelId, $tip, $enlem, $boylam, $dogruluk,
            trim(metin($body['lokasyon_adi'] ?? '')) ?: null,
            $_anahtar,
        ]);
        jsonOk(['id' => (int)$db->lastInsertId()], $tip === 'giris' ? 'Giriş kaydedildi.' : 'Çıkış kaydedildi.');

    case 'DELETE':
        requireAuth();
        requirePermission('personel');
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $db->prepare("DELETE FROM personel_checkin WHERE id=?")->execute([$id]);
        jsonOk(null, 'Kayıt silindi.');

    default:
        jsonError('Method Not Allowed', 405);
}
