<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';


const WA_VARSAYILAN_SURUM = 'v23.0';

function waAyar(PDO $db): array {
    $a = [];
    foreach ($db->query("SELECT anahtar, deger FROM ayarlar WHERE anahtar LIKE 'wa\\_%' ESCAPE '\\' OR anahtar LIKE 'bildirim\\_wp\\_%' ESCAPE '\\'")->fetchAll() as $r) {
        $a[$r['anahtar']] = trim((string)$r['deger']);
    }
    $surum = $a['wa_api_surum'] ?? '';
    return [
        'aktif'      => ($a['wa_aktif'] ?? '0') === '1' && ($a['wa_token'] ?? '') !== '' && ($a['wa_phone_id'] ?? '') !== '',
        'token'      => $a['wa_token'] ?? '',
        'phone_id'   => $a['wa_phone_id'] ?? '',
        'sablon'     => $a['wa_sablon'] ?? '',
        'dil'        => ($a['wa_sablon_dil'] ?? '') ?: 'tr',
        'surum'      => preg_match('/^v\d{1,2}\.\d$/', $surum) ? $surum : WA_VARSAYILAN_SURUM,
        'ofis_aktif' => ($a['wa_ofis_aktif'] ?? '0') === '1',
        'ofis_no'    => $a['wa_ofis_no'] ?? '',
        'eski_aktif' => ($a['bildirim_wp_aktif'] ?? '0') === '1' && ($a['bildirim_wp_no'] ?? '') !== '' && ($a['bildirim_wp_apikey'] ?? '') !== '',
    ];
}

function waTelefon(string $t): ?string {
    $d = preg_replace('/\D/', '', $t) ?? '';
    if (str_starts_with($d, '00')) $d = substr($d, 2);
    if (strlen($d) === 11 && str_starts_with($d, '05')) $d = '9' . $d;
    elseif (strlen($d) === 10 && str_starts_with($d, '5')) $d = '90' . $d;
    if (strlen($d) < 10 || strlen($d) > 15 || str_starts_with($d, '0')) return null;
    return $d;
}

function _waParam(string $s, int $max = 900): string {
    $s = preg_replace('/[\r\n\t]+/u', ' · ', $s) ?? '';
    $s = preg_replace('/ {2,}/u', ' ', $s) ?? '';
    return mb_substr(trim($s), 0, $max);
}

function waBulutGonder(array $ay, string $tel, string $ad, string $mesaj): array {
    if ($ay['token'] === '' || $ay['phone_id'] === '') return ['ok' => false, 'kod' => 0, 'hata' => 'WhatsApp Business ayarları eksik.', 'mesaj_id' => null];
    $taban = rtrim(getenv('TALOO_WA_URL') ?: ('https://graph.facebook.com/' . $ay['surum']), '/');
    $url = $taban . '/' . rawurlencode($ay['phone_id']) . '/messages';
    $govde = $ay['sablon'] !== ''
        ? ['messaging_product' => 'whatsapp', 'to' => $tel, 'type' => 'template', 'template' => [
              'name' => $ay['sablon'], 'language' => ['code' => $ay['dil']],
              'components' => [['type' => 'body', 'parameters' => [
                  ['type' => 'text', 'text' => _waParam($ad, 60) ?: 'Değerli müşterimiz'],
                  ['type' => 'text', 'text' => _waParam($mesaj)],
              ]]]]]
        : ['messaging_product' => 'whatsapp', 'to' => $tel, 'type' => 'text',
           'text' => ['preview_url' => false, 'body' => 'Merhaba ' . ($ad ?: 'Değerli müşterimiz') . ",\n" . $mesaj]];
    try {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $ay['token'], 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($govde, JSON_UNESCAPED_UNICODE),
        ]);
        $yanit = (string)curl_exec($ch);
        $kod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cHata = curl_error($ch);
        curl_close($ch);
    } catch (\Throwable $e) {
        return ['ok' => false, 'kod' => 0, 'hata' => $e->getMessage(), 'mesaj_id' => null];
    }
    $j = json_decode($yanit, true);
    $id = is_array($j) ? ($j['messages'][0]['id'] ?? null) : null;
    if ($kod >= 200 && $kod < 300 && $id) return ['ok' => true, 'kod' => $kod, 'hata' => '', 'mesaj_id' => (string)$id];
    $hata = is_array($j) && isset($j['error']) ? trim(($j['error']['message'] ?? '') . ' (' . ($j['error']['code'] ?? '') . ')') : ($cHata ?: 'HTTP ' . $kod);
    return ['ok' => false, 'kod' => $kod, 'hata' => mb_substr($hata, 0, 500), 'mesaj_id' => null];
}

function _waKuyruk(?array $is = null, bool $al = false): array {
    static $kuyruk = [], $kayitli = false;
    if ($is !== null) {
        $kuyruk[] = $is;
        if (!$kayitli) {
            $kayitli = true;
            register_shutdown_function('_waKuyrukIsle');
        }
    }
    if ($al) { $d = $kuyruk; $kuyruk = []; return $d; }
    return $kuyruk;
}

function _waSiraya(PDO $db, array $is): void {
    islemSonrasi($db, fn() => _waKuyruk($is));
}

function _waKuyrukIsle(): void {
    $isler = _waKuyruk(null, true);
    if (!$isler) return;
    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
    foreach ($isler as $is) {
        try {
            $db = $is['db'];
            if ($db->inTransaction()) continue;
            $lq = $db->prepare("SELECT durum FROM whatsapp_log WHERE id=?");
            $lq->execute([(int)$is['log_id']]);
            if ($lq->fetchColumn() !== 'bekliyor') continue;
            $u = $db->prepare("UPDATE whatsapp_log SET durum=?, hata=?, mesaj_id=?, gonderim_tarihi=datetime('now','localtime') WHERE id=?");
            if ($is['kanal'] === 'callmebot') {
                $r = whatsappGonder($is['mesaj']);
                $u->execute([$r['ok'] ? 'gonderildi' : 'hata', $r['ok'] ? null : mb_substr((string)($r['hata'] ?: 'HTTP ' . $r['kod']), 0, 500), null, $is['log_id']]);
                continue;
            }
            $r = waBulutGonder(waAyar($db), $is['telefon'], $is['ad'], $is['mesaj']);
            $u->execute([$r['ok'] ? 'gonderildi' : 'hata', $r['ok'] ? null : $r['hata'], $r['mesaj_id'], $is['log_id']]);
        } catch (\Throwable $e) {
            error_log('whatsapp kuyrugu islenemedi: ' . $e->getMessage());
        }
    }
}

function _waLogla(PDO $db, array $k): int {
    $db->prepare("INSERT INTO whatsapp_log (olay, alici, kanal, musteri_id, kontak_id, telefon, mesaj, durum, hata) VALUES (?,?,?,?,?,?,?,?,?)")
       ->execute([$k['olay'], $k['alici'], $k['kanal'], $k['musteri_id'] ?? null, $k['kontak_id'] ?? null, $k['telefon'] ?? null,
                  mb_substr($k['mesaj'], 0, 2000), $k['durum'] ?? 'bekliyor', $k['hata'] ?? null]);
    return (int)$db->lastInsertId();
}

function waMusteriBildir(PDO $db, int $musteriId, ?int $kontakId, string $olay, string $mesaj): int {
    try {
        $ay = waAyar($db);
        if (!$ay['aktif']) return 0;
        $sql = "SELECT id, ad_soyad, telefon FROM musteri_kontaklari WHERE musteri_id=? AND durum='aktif' AND wa_izin=1";
        $p = [$musteriId];
        if ($kontakId) { $sql .= " AND id=?"; $p[] = $kontakId; }
        $st = $db->prepare($sql);
        $st->execute($p);
        $n = 0;
        foreach ($st->fetchAll() as $k) {
            $tel = waTelefon((string)$k['telefon']);
            $kayit = ['olay' => $olay, 'alici' => 'musteri', 'kanal' => 'cloud', 'musteri_id' => $musteriId, 'kontak_id' => (int)$k['id'],
                      'telefon' => $tel ?? (string)$k['telefon'], 'mesaj' => $mesaj];
            if ($tel === null) { _waLogla($db, $kayit + ['durum' => 'atlandi', 'hata' => 'Telefon numarası geçersiz']); continue; }
            $logId = _waLogla($db, $kayit);
            _waSiraya($db, ['db' => $db, 'log_id' => $logId, 'kanal' => 'cloud', 'telefon' => $tel, 'ad' => (string)$k['ad_soyad'], 'mesaj' => $mesaj]);
            $n++;
        }
        return $n;
    } catch (\Throwable $e) {
        error_log('waMusteriBildir: ' . $e->getMessage());
        return 0;
    }
}

function waOfisBildir(PDO $db, string $olay, string $mesaj): int {
    try {
        $ay = waAyar($db);
        $n = 0;
        if ($ay['aktif'] && $ay['ofis_aktif'] && $ay['ofis_no'] !== '') {
            foreach (preg_split('/[,;\r\n]+/', $ay['ofis_no']) ?: [] as $ham) {
                if (trim($ham) === '' || ($tel = waTelefon($ham)) === null) continue;
                $logId = _waLogla($db, ['olay' => $olay, 'alici' => 'ofis', 'kanal' => 'cloud', 'telefon' => $tel, 'mesaj' => $mesaj]);
                _waSiraya($db, ['db' => $db, 'log_id' => $logId, 'kanal' => 'cloud', 'telefon' => $tel, 'ad' => 'Ekip', 'mesaj' => $mesaj]);
                $n++;
            }
            return $n;
        }
        if ($ay['eski_aktif']) {
            $logId = _waLogla($db, ['olay' => $olay, 'alici' => 'ofis', 'kanal' => 'callmebot', 'mesaj' => $mesaj]);
            _waSiraya($db, ['db' => $db, 'log_id' => $logId, 'kanal' => 'callmebot', 'telefon' => '', 'ad' => '', 'mesaj' => '📣 ' . $mesaj]);
            $n++;
        }
        return $n;
    } catch (\Throwable $e) {
        error_log('waOfisBildir: ' . $e->getMessage());
        return 0;
    }
}

function waPortalLink(string $sekme = ''): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $site = siteKokYolu();
    return $scheme . '://' . guvenliHost() . $site . '/portal.html' . ($sekme !== '' ? '#' . $sekme : '');
}

function waPanelLink(string $sayfa): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $site = siteKokYolu();
    return $scheme . '://' . guvenliHost() . $site . '/' . ltrim($sayfa, '/');
}
