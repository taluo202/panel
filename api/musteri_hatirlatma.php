<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/eposta.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('kiralama_takip');

$db     = getDb();
$method = $_SERVER['REQUEST_METHOD'];

function _hatirlatmaMesaj(array $k): string {
    $firma = trim((string)($k['firma_unvan'] ?? '')) ?: 'firmamız';
    $bitisTs = $k['bitis'] ? strtotime((string)$k['bitis']) : false;
    $bitis = $bitisTs !== false ? date('d.m.Y', $bitisTs) : '—';
    return "Sayın {$k['musteri']}, {$k['ekipman_adi']} kiralamanızın (kod: " . ($k['kod'] ?: '—') . ") bitiş tarihi {$bitis}. "
         . "İade veya süre uzatımı için lütfen {$firma} ile iletişime geçin. İyi çalışmalar dileriz.";
}

switch ($method) {

    case 'GET':
        $warn7 = date('Y-m-d', strtotime('+7 days'));
        $today = date('Y-m-d');
        $kisit = subeKisiti();
        $st = $db->prepare("
            SELECT k.id, k.kod, k.ekipman_adi, k.bitis, k.lokasyon, k.sube, k.son_hatirlatma_tarih,
                   m.id AS musteri_id, m.unvan AS musteri, m.email, m.telefon
            FROM kiralamalar k LEFT JOIN musteriler m ON m.id = k.musteri_id
            WHERE k.durum='aktif' AND k.gercek_bitis IS NULL AND k.bitis IS NOT NULL AND k.bitis <= ?
                  AND (k.son_hatirlatma_tarih IS NULL OR date(k.son_hatirlatma_tarih) < ?)
                  " . ($kisit !== null ? 'AND k.sube = ?' : '') . "
            ORDER BY k.bitis
        ");
        $st->execute($kisit !== null ? [$warn7, $today, $kisit] : [$warn7, $today]);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) $r['gecikti'] = $r['bitis'] < $today;
        unset($r);
        jsonOk($rows);
        break;

    case 'POST':
        verifyCsrf();
        $body = govdeOku();
        $id   = (int)($body['kiralama_id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz kiralama.');

        $st = $db->prepare("
            SELECT k.id, k.kod, k.ekipman_adi, k.bitis, k.sube, m.unvan AS musteri, m.email
            FROM kiralamalar k LEFT JOIN musteriler m ON m.id = k.musteri_id
            WHERE k.id=?
        ");
        $st->execute([$id]);
        $k = $st->fetch();
        if (!$k) jsonError('Kiralama bulunamadı.', 404);
        subeErisimDogrula($k['sube'] ?? null);
        if (!$k['email']) jsonError('Bu müşterinin kayıtlı e-posta adresi yok.');

        $ayar = [];
        foreach ($db->query("SELECT anahtar, deger FROM ayarlar WHERE anahtar LIKE 'firma_%'")->fetchAll() as $r) $ayar[$r['anahtar']] = $r['deger'];
        $k['firma_unvan'] = $ayar['firma_unvan'] ?? '';

        $konu   = "Kiralama İade Hatırlatması — " . ($k['kod'] ?: $k['ekipman_adi']);
        $mesaj  = _hatirlatmaMesaj($k);
        $_snc = epostaGonder($db, (string)$k['email'], $konu, $mesaj, 'musteri_hatirlatma');
        if (!$_snc['basarili']) jsonError('E-posta gönderilemedi: ' . ($_snc['hata'] ?? 'bilinmeyen hata'), 500);

        $db->prepare("UPDATE kiralamalar SET son_hatirlatma_tarih=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $id]);
        jsonOk(null, 'Hatırlatma e-postası gönderildi.');

    case 'PUT':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        subeKayitDogrula($db, 'kiralamalar', $id);
        $db->prepare("UPDATE kiralamalar SET son_hatirlatma_tarih=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $id]);
        jsonOk(null, 'Hatırlatıldı olarak işaretlendi.');

    default:
        jsonError('Method Not Allowed', 405);
}
