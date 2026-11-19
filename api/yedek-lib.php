<?php
declare(strict_types=1);

function yedekSirTemizle(PDO $k, bool $sifreli): void {
    $var = array_flip($k->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN));
    foreach (YEDEK_DISI_TABLOLAR as $t) {
        if (isset($var[$t])) $k->exec('DELETE FROM "' . $t . '"');
    }
    $sirAyar = "'" . implode("','", YEDEK_SIR_AYARLAR) . "'";
    $k->exec("UPDATE users SET password='', totp_secret=NULL, totp_yedek=NULL");
    $k->exec("DELETE FROM ayarlar WHERE anahtar IN ($sirAyar)");
    $k->exec("UPDATE personel SET pin_kod=NULL, qr_token=NULL");
    if (!$sifreli) {
        $k->exec("UPDATE musteri_kontaklari SET sifre_hash=NULL");
        $k->exec("DELETE FROM ayarlar WHERE anahtar='kod_iz_anahtar'");
    }
    yedekSirDogrula($k, $sifreli);
}

function yedekSirDogrula(PDO $k, bool $sifreli): void {
    $var = array_flip($k->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN));
    $kontrol = [
        'kullanıcı parola özeti' => "SELECT COUNT(*) FROM users WHERE COALESCE(password,'')<>''",
        '2FA sırrı'              => "SELECT COUNT(*) FROM users WHERE COALESCE(totp_secret,'')<>'' OR COALESCE(totp_yedek,'')<>''",
        'rozet PIN/QR'           => "SELECT COUNT(*) FROM personel WHERE COALESCE(pin_kod,'')<>'' OR COALESCE(qr_token,'')<>''",
        'sır ayarlar'            => "SELECT COUNT(*) FROM ayarlar WHERE anahtar IN ('" . implode("','", YEDEK_SIR_AYARLAR) . "')",
    ];
    if (!$sifreli) {
        $kontrol['portal şifre özeti'] = "SELECT COUNT(*) FROM musteri_kontaklari WHERE COALESCE(sifre_hash,'')<>''";
        $kontrol['kopya izi anahtarı'] = "SELECT COUNT(*) FROM ayarlar WHERE anahtar='kod_iz_anahtar'";
    }
    foreach (YEDEK_DISI_TABLOLAR as $t) {
        if (isset($var[$t])) $kontrol['tablo ' . $t] = 'SELECT COUNT(*) FROM "' . $t . '"';
    }
    foreach ($kontrol as $ad => $sql) {
        if ((int)$k->query($sql)->fetchColumn() !== 0) throw new \RuntimeException('Yedek sır temizliği doğrulanamadı: ' . $ad);
    }
}

function geriYukleMevcutSirlar(PDO $db): array {
    $m = ['sifreler' => [], 'totp' => [], 'totp_yedek' => [], 'rozet' => [], 'sirlar' => [], 'portal' => [], 'kod_iz' => ''];
    foreach ($db->query('SELECT username, password, totp_secret, totp_yedek FROM users')->fetchAll(PDO::FETCH_ASSOC) as $u) {
        if (($u['password'] ?? '') !== '') $m['sifreler'][$u['username']] = $u['password'];
        if (($u['totp_secret'] ?? '') !== '') $m['totp'][$u['username']] = $u['totp_secret'];
        if (($u['totp_yedek'] ?? '') !== '') $m['totp_yedek'][$u['username']] = $u['totp_yedek'];
    }
    foreach ($db->query("SELECT id, pin_kod, qr_token FROM personel WHERE COALESCE(pin_kod,'') <> '' OR COALESCE(qr_token,'') <> ''")->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $m['rozet'][] = [$p['pin_kod'] ?: null, $p['qr_token'] ?: null, (int)$p['id']];
    }
    foreach ($db->query("SELECT anahtar, deger FROM ayarlar WHERE anahtar IN ('" . implode("','", YEDEK_SIR_AYARLAR) . "')")->fetchAll(PDO::FETCH_ASSOC) as $a) {
        if (($a['deger'] ?? '') !== '') $m['sirlar'][$a['anahtar']] = $a['deger'];
    }
    foreach ($db->query("SELECT id, email, sifre_hash FROM musteri_kontaklari WHERE COALESCE(sifre_hash,'') <> ''")->fetchAll(PDO::FETCH_ASSOC) as $k) {
        $m['portal'][] = [(string)$k['sifre_hash'], (int)$k['id'], (string)$k['email']];
    }
    $m['kod_iz'] = (string)$db->query("SELECT deger FROM ayarlar WHERE anahtar='kod_iz_anahtar'")->fetchColumn();
    return $m;
}
