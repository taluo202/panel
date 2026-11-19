<?php
declare(strict_types=1);


require_once __DIR__ . '/config.php';

function epostaAyarlari(PDO $db): array {
    static $ayar = null;
    if ($ayar !== null) return $ayar;
    $ayar = [
        'yontem'   => 'mail',
        'host'     => '', 'port' => 587, 'kullanici' => '', 'sifre' => '',
        'guvenlik' => 'tls',
        'gonderen' => '', 'gonderen_ad' => '',
    ];
    try {
        $st = $db->query("SELECT anahtar, deger FROM ayarlar WHERE anahtar LIKE 'smtp_%'");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = substr($r['anahtar'], 5);
            if (array_key_exists($k, $ayar)) $ayar[$k] = $r['deger'];
        }
    } catch (\Throwable $e) { hataGunlugu('eposta.ayar_okuma', $e); }
    $ayar['port'] = (int)$ayar['port'] ?: 587;
    return $ayar;
}

function _epostaLogla(PDO $db, string $alici, string $konu, string $yontem, bool $basarili, ?string $hata, ?string $baglam): void {
    try {
        $db->prepare("INSERT INTO eposta_log (alici, konu, yontem, basarili, hata, baglam) VALUES (?,?,?,?,?,?)")
           ->execute([$alici, $konu, $yontem, $basarili ? 1 : 0, $hata, $baglam]);
    } catch (\Throwable $e) {
        error_log('eposta_log yazilamadi: ' . $e->getMessage());
    }
}

function _epostaTemizBaslik(string $v): string {
    return trim(str_replace(["\r", "\n", "\0"], '', $v));
}

function epostaGonder(PDO $db, string $alici, string $konu, string $govde, ?string $baglam = null, bool $html = false, array $ekler = []): array {
    $alici = _epostaTemizBaslik($alici);
    $konu  = _epostaTemizBaslik($konu);

    if ($alici === '' || !filter_var($alici, FILTER_VALIDATE_EMAIL)) {
        _epostaLogla($db, $alici, $konu, 'yok', false, 'Geçersiz alıcı adresi', $baglam);
        return ['basarili' => false, 'hata' => 'Geçersiz alıcı adresi.', 'yontem' => 'yok'];
    }

    $a = epostaAyarlari($db);
    $gonderen   = _epostaTemizBaslik($a['gonderen'] !== '' ? $a['gonderen'] : ('taloo@' . guvenliHost()));
    $gonderenAd = _epostaTemizBaslik((string)$a['gonderen_ad']);

    if ($a['yontem'] === 'smtp' && $a['host'] !== '') {
        [$ok, $hata] = _smtpGonder($a, $gonderen, $gonderenAd, $alici, $konu, $govde, $html, $ekler);
        _epostaLogla($db, $alici, $konu, 'smtp', $ok, $hata, $baglam);
        return ['basarili' => $ok, 'hata' => $hata, 'yontem' => 'smtp'];
    }

    [$turBaslik, $mimeGovde] = $ekler ? _epostaMime($govde, $html, $ekler) : ['Content-Type: text/' . ($html ? 'html' : 'plain') . '; charset=UTF-8', $govde];
    $baslik = "MIME-Version: 1.0\r\n" . $turBaslik . "\r\n"
        . 'From: ' . ($gonderenAd !== '' ? _epostaMimeAd($gonderenAd) . ' <' . $gonderen . '>' : $gonderen) . "\r\n";
    $ok   = @mail($alici, _epostaMimeAd($konu), $mimeGovde, $baslik);
    $hata = $ok ? null : 'Sunucunun mail() işlevi gönderemedi (SMTP ayarlamanız önerilir).';
    _epostaLogla($db, $alici, $konu, 'mail', $ok, $hata, $baglam);
    return ['basarili' => $ok, 'hata' => $hata, 'yontem' => 'mail'];
}

function epostaEkliGonder(PDO $db, string $alici, string $konu, string $govde, array $ekler, ?string $baglam = null, int $sinir = 15728640): array {
    $paketler = []; $paket = []; $boy = 0;
    foreach ($ekler as $e) {
        $b = strlen((string)($e['veri'] ?? ''));
        if ($paket && $boy + $b > $sinir) { $paketler[] = $paket; $paket = []; $boy = 0; }
        $paket[] = $e; $boy += $b;
    }
    $paketler[] = $paket;
    $n = count($paketler);
    foreach ($paketler as $i => $p) {
        $r = epostaGonder($db, $alici, $n > 1 ? $konu . ' (' . ($i + 1) . '/' . $n . ')' : $konu, $govde, $baglam, false, $p);
        if (empty($r['basarili'])) return $r;
    }
    return $r;
}

function _epostaMime(string $govde, bool $html, array $ekler): array {
    $metin = 'Content-Type: text/' . ($html ? 'html' : 'plain') . "; charset=UTF-8\r\nContent-Transfer-Encoding: base64";
    $b64 = fn(string $v): string => chunk_split(base64_encode($v), 76, "\r\n");
    if (!$ekler) return [$metin, $b64($govde)];
    $sinir = '=_taloo_' . bin2hex(random_bytes(12));
    $g = "--$sinir\r\n$metin\r\n\r\n" . $b64($govde);
    foreach ($ekler as $e) {
        $ad = trim((string)preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)($e['ad'] ?? 'ek')), '-') ?: 'ek';
        $tur = preg_match('~^[a-z]+/[a-z0-9.+-]+$~', (string)($e['tur'] ?? '')) ? $e['tur'] : 'application/octet-stream';
        $g .= "--$sinir\r\nContent-Type: $tur; name=\"$ad\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$ad\"\r\n\r\n"
            . $b64((string)($e['veri'] ?? ''));
    }
    return ['Content-Type: multipart/mixed; boundary="' . $sinir . '"', $g . "--$sinir--\r\n"];
}

function _epostaMimeAd(string $s): string {
    return preg_match('/[\x80-\xFF]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

function _smtpGonder(array $a, string $gonderen, string $gonderenAd, string $alici, string $konu, string $govde, bool $html, array $ekler = []): array {
    $sema = ($a['guvenlik'] === 'ssl') ? 'ssl://' : '';
    $ctx  = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client($sema . $a['host'] . ':' . $a['port'], $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return [false, 'SMTP sunucusuna bağlanılamadı (' . $a['host'] . ':' . $a['port'] . '): ' . ($errstr ?: 'zaman aşımı')];
    stream_set_timeout($fp, 8);

    $oku = function () use ($fp): array {
        $satirlar = '';
        while (($s = fgets($fp, 515)) !== false) {
            $satirlar .= $s;
            if (strlen($s) < 4 || $s[3] !== '-') break;
        }
        return [(int)substr($satirlar, 0, 3), rtrim($satirlar)];
    };
    $yaz = function (string $s) use ($fp): void {
        $s .= "\r\n";
        for ($o = 0, $n = strlen($s); $o < $n; $o += $w) {
            $w = fwrite($fp, substr($s, $o, 65536));
            if (!$w) throw new RuntimeException('SMTP sunucusuna yazılamadı (bağlantı koptu).');
        }
    };

    try {
        [$kod] = $oku();
        if ($kod !== 220) return [false, 'SMTP karşılama başarısız (' . $kod . ').'];

        $yaz('EHLO ' . guvenliHost());
        [$kod, $yanit] = $oku();
        if ($kod !== 250) return [false, 'EHLO reddedildi (' . $kod . ').'];

        if ($a['guvenlik'] === 'tls') {
            $yaz('STARTTLS');
            [$kod] = $oku();
            if ($kod !== 220) return [false, 'STARTTLS reddedildi (' . $kod . ').'];
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                return [false, 'TLS el sıkışması başarısız.'];
            }
            $yaz('EHLO ' . guvenliHost());
            [$kod, $yanit] = $oku();
            if ($kod !== 250) return [false, 'TLS sonrası EHLO reddedildi (' . $kod . ').'];
        }

        if ($a['kullanici'] !== '') {
            $yaz('AUTH LOGIN');
            [$kod] = $oku();
            if ($kod !== 334) return [false, 'Sunucu AUTH LOGIN kabul etmedi (' . $kod . ').'];
            $yaz(base64_encode($a['kullanici']));
            [$kod] = $oku();
            if ($kod !== 334) return [false, 'Kullanıcı adı reddedildi (' . $kod . ').'];
            $yaz(base64_encode($a['sifre']));
            [$kod] = $oku();
            if ($kod !== 235) return [false, 'SMTP kimlik doğrulama başarısız — kullanıcı adı/şifre hatalı olabilir (' . $kod . ').'];
        }

        $yaz('MAIL FROM:<' . $gonderen . '>');
        [$kod] = $oku();
        if ($kod !== 250) return [false, 'Gönderen adresi reddedildi (' . $kod . ').'];

        $yaz('RCPT TO:<' . $alici . '>');
        [$kod] = $oku();
        if ($kod !== 250 && $kod !== 251) return [false, 'Alıcı adresi reddedildi (' . $kod . ').'];

        $yaz('DATA');
        [$kod] = $oku();
        if ($kod !== 354) return [false, 'DATA reddedildi (' . $kod . ').'];

        [$turBaslik, $mimeGovde] = _epostaMime($govde, $html, $ekler);
        $basliklar = [
            'From: ' . ($gonderenAd !== '' ? _epostaMimeAd($gonderenAd) . ' <' . $gonderen . '>' : '<' . $gonderen . '>'),
            'To: <' . $alici . '>',
            'Subject: ' . _epostaMimeAd($konu),
            'Date: ' . date('r'),
            'MIME-Version: 1.0',
            $turBaslik,
        ];
        $yaz(implode("\r\n", $basliklar) . "\r\n\r\n" . $mimeGovde . '.');
        if ($ekler) stream_set_timeout($fp, 60);
        [$kod, $yanit] = $oku();
        if ($kod !== 250) return [false, 'Sunucu mesajı kabul etmedi (' . $kod . '): ' . $yanit];

        $yaz('QUIT');
        return [true, null];
    } catch (\Throwable $e) {
        return [false, $e->getMessage()];
    } finally {
        @fclose($fp);
    }
}
