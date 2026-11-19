<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/eposta.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
if (($_SESSION['rol'] ?? '') !== 'admin') jsonError('E-posta ayarları yalnızca yöneticiye açıktır.', 403);

$db     = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

const SMTP_ALANLAR = ['yontem', 'host', 'port', 'kullanici', 'guvenlik', 'gonderen', 'gonderen_ad'];

if ($method === 'GET' && $action === 'log') {
    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 25)));
    $sayfa   = sayfaNo();
    $sql     = 'SELECT id, tarih, alici, konu, yontem, basarili, hata, baglam FROM eposta_log ORDER BY id DESC';
    [$toplam, $sayfaSayisi] = sayfalamaBilgisi($db, $sql, [], $perPage);
    $st = $db->prepare($sql . ' LIMIT ? OFFSET ?');
    $st->execute([$perPage, ($sayfa - 1) * $perPage]);

    $son = $db->query("SELECT COUNT(*) toplam, SUM(basarili) basarili FROM eposta_log
                       WHERE tarih >= datetime('now','localtime','-30 days')")->fetch();
    jsonOk([
        'list'      => $st->fetchAll(),
        'sayfalama' => ['sayfa' => $sayfa, 'sayfa_sayisi' => $sayfaSayisi, 'toplam' => $toplam, 'per_page' => $perPage],
        'ozet_30gun'=> ['toplam' => (int)($son['toplam'] ?? 0), 'basarili' => (int)($son['basarili'] ?? 0)],
    ]);
}

if ($method === 'GET') {
    $a = epostaAyarlari($db);
    $cikti = [];
    foreach (SMTP_ALANLAR as $k) $cikti[$k] = $a[$k];
    $cikti['sifre_kayitli'] = ($a['sifre'] ?? '') !== '';
    $ck = (string)($db->query("SELECT deger FROM ayarlar WHERE anahtar='cron_anahtar'")->fetchColumn() ?: '');
    $cikti['cron_adres'] = $ck !== '' ? ('https://' . guvenliHost() . '/api/cron.php?anahtar=' . $ck) : '';
    jsonOk($cikti);
}

if ($method === 'POST') {
    verifyCsrf();
    $body = govdeOku();

    if ($action === 'test') {
        $alici = trim(metin($body['alici'] ?? ''));
        if ($alici === '') jsonError('Test e-postasının gideceği adresi girin.');
        if (!filter_var($alici, FILTER_VALIDATE_EMAIL)) jsonError('Test e-postası için geçerli bir e-posta adresi girin.');
        $snc = epostaGonder(
            $db, $alici, 'Taloo test e-postası',
            "Bu bir test mesajıdır.\n\nBunu okuyabiliyorsanız e-posta ayarlarınız çalışıyor demektir.\n"
            . 'Gönderim zamanı: ' . date('d.m.Y H:i') . "\n",
            'test'
        );
        if (!$snc['basarili']) jsonError('Test e-postası gönderilemedi: ' . ($snc['hata'] ?? 'bilinmeyen hata'), 422);
        jsonOk(['yontem' => $snc['yontem']], 'Test e-postası gönderildi (' . $snc['yontem'] . '). Gelen kutunuzu ve spam klasörünü kontrol edin.');
    }

    if ($action === 'cron_anahtar') {
        $yeni = bin2hex(random_bytes(24));
        $db->prepare("INSERT INTO ayarlar (anahtar, deger) VALUES ('cron_anahtar', ?)
                      ON CONFLICT(anahtar) DO UPDATE SET deger=excluded.deger")->execute([$yeni]);
        logla($db, 'ayarlar', null, 'guncelle', null, null, 'Cron anahtarı yeniden üretildi');
        jsonOk(['anahtar' => $yeni, 'adres' => 'https://' . guvenliHost() . '/api/cron.php?anahtar=' . $yeni],
               'Yeni anahtar üretildi. Eski adres artık çalışmaz.');
    }

    $ins = $db->prepare("INSERT INTO ayarlar (anahtar, deger) VALUES (?,?)
                         ON CONFLICT(anahtar) DO UPDATE SET deger=excluded.deger");
    foreach (SMTP_ALANLAR as $k) {
        if (!array_key_exists($k, $body)) continue;
        $v = trim((string)$body[$k]);
        if ($k === 'yontem'   && !in_array($v, ['mail', 'smtp'], true))       $v = 'mail';
        if ($k === 'guvenlik' && !in_array($v, ['tls', 'ssl', 'yok'], true))  $v = 'tls';
        if ($k === 'port')    $v = (string)(max(1, min(65535, (int)$v)) ?: 587);
        if ($k === 'gonderen' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
            jsonError('Gönderen adresi geçerli bir e-posta olmalıdır.');
        }
        $ins->execute(['smtp_' . $k, $v]);
    }
    if (array_key_exists('sifre', $body) && trim((string)$body['sifre']) !== '') {
        $ins->execute(['smtp_sifre', trim((string)$body['sifre'])]);
    }
    logla($db, 'ayarlar', null, 'guncelle', null, null, 'E-posta (SMTP) ayarları güncellendi');
    jsonOk(null, 'E-posta ayarları kaydedildi.');
}

jsonError('Method Not Allowed', 405);
