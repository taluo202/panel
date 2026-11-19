<?php
declare(strict_types=1);


function meSema(PDO $db): void {
    $kolon = fn(string $t, string $k): bool => (bool)array_filter($db->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), fn($c) => $c['name'] === $k);
    $var = fn(string $t): bool => (bool)$db->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC);
    if ($var('musteriler') && !$kolon('musteriler', 'portal_oto_davet')) $db->exec("ALTER TABLE musteriler ADD COLUMN portal_oto_davet TEXT");
    if ($var('form_paylasim') && !$kolon('form_paylasim', 'musteri_id')) $db->exec("ALTER TABLE form_paylasim ADD COLUMN musteri_id INTEGER");
}

function mePortalMusteri(PDO $db): ?int {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $kid = (int)($_SESSION['portal_kontak_id'] ?? 0);
    if ($kid < 1) return null;
    $s = $db->prepare("SELECT musteri_id, durum, sifre_degisti FROM musteri_kontaklari WHERE id=?");
    $s->execute([$kid]);
    $k = $s->fetch(PDO::FETCH_ASSOC);
    if (!$k || $k['durum'] !== 'aktif' || (string)($k['sifre_degisti'] ?? '') !== (string)($_SESSION['portal_sifre_damga'] ?? '')) return null;
    return (int)$k['musteri_id'];
}

function meBelgeGorur(PDO $db, int $musteriId, ?string $sube): bool {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!empty($_SESSION['user_id'])) {
        if (!hasPermission('kiralama_takip', 'oku')) return false;
        $k = subeKisitiHam();
        if ($k === null) return true;
        $s = trim((string)$sube);
        if ($s === '' && $musteriId > 0) {
            $q = $db->prepare('SELECT sube FROM musteriler WHERE id=?');
            $q->execute([$musteriId]);
            $s = trim((string)$q->fetchColumn());
        }
        return $s === '' || $s === $k;
    }
    return $musteriId > 0 && mePortalMusteri($db) === $musteriId;
}

function meBaglantiSahibi(PDO $db, string $tur, string $belirtec): ?array {
    try {
        if ($tur === 'form') {
            if (!preg_match('/^[a-f0-9]{32,}$/', $belirtec)) return null;
            $s = $db->prepare('SELECT f.musteri_id, m.sube FROM form_paylasim f LEFT JOIN musteriler m ON m.id = f.musteri_id WHERE f.token=?');
            $s->execute([$belirtec]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            return $r ? ['musteri_id' => (int)$r['musteri_id'], 'sube' => $r['sube'] !== null ? (string)$r['sube'] : null] : null;
        }
        if ($tur === 'protokol') {
            if (!preg_match('/^[a-f0-9]{64}$/', $belirtec)) return null;
            $s = $db->prepare('SELECT p.musteri_id, p.sube FROM kiralama_protokol_gonderimleri g JOIN kiralama_protokolleri p ON p.id = g.protokol_id WHERE g.token_hash=?');
            $s->execute([tokenOzet($belirtec)]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            return $r ? ['musteri_id' => (int)$r['musteri_id'], 'sube' => $r['sube'] !== null ? (string)$r['sube'] : null] : null;
        }
        $tablo = ['tutanak' => 'tutanak_gonderimleri', 'hasar' => 'hasar_dosya_gonderimleri'][$tur] ?? null;
        if ($tablo === null || !preg_match('/^[a-f0-9]{64}$/', $belirtec)) return null;
        $s = $db->prepare("SELECT k.musteri_id, k.sube FROM $tablo g JOIN kiralamalar k ON k.id = g.kiralama_id WHERE g.token_hash=?");
        $s->execute([tokenOzet($belirtec)]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ? ['musteri_id' => (int)$r['musteri_id'], 'sube' => $r['sube'] !== null ? (string)$r['sube'] : null] : null;
    } catch (\Throwable $e) {
        return null;
    }
}

function meBaglantiIzinli(PDO $db, string $tur, string $belirtec): bool {
    $s = meBaglantiSahibi($db, $tur, $belirtec);
    return $s !== null ? meBelgeGorur($db, $s['musteri_id'], $s['sube']) : meBelgeGorur($db, 0, null);
}

function meTeslimBicimi(PDO $db, int $musteriId): string {
    return mePortalDurum($db, $musteriId) === 'aktif' ? 'baglanti' : 'pdf';
}

function mePdfNotu(array $erisim): string {
    return ($erisim['durum'] ?? '') === 'davet'
        ? ' Belgelerinizi müşteri portalımızdan da takip edebilirsiniz; hesap davetiniz e-posta adresinize ayrıca gönderildi.' : '';
}

function mePortalDurum(PDO $db, int $musteriId): string {
    $s = $db->prepare("SELECT
        SUM(CASE WHEN durum='aktif' AND sifre_hash IS NOT NULL THEN 1 ELSE 0 END) AS aktif,
        SUM(CASE WHEN sifre_hash IS NULL AND durum <> 'pasif' THEN 1 ELSE 0 END) AS bekleyen
        FROM musteri_kontaklari WHERE musteri_id=?");
    $s->execute([$musteriId]);
    $r = $s->fetch(PDO::FETCH_ASSOC) ?: [];
    if ((int)($r['aktif'] ?? 0) > 0) return 'aktif';
    return (int)($r['bekleyen'] ?? 0) > 0 ? 'bekliyor' : 'yok';
}

function meDavetLink(PDO $db, array $kontak, ?string $donus = null, ?bool &$epostaGitti = null): string {
    $db->prepare("UPDATE musteri_kontak_token SET used=1 WHERE kontak_id=? AND amac='davet' AND used=0")->execute([(int)$kontak['id']]);
    $token = bin2hex(random_bytes(32));
    $db->prepare("INSERT INTO musteri_kontak_token (kontak_id, amac, token, expires_at) VALUES (?,'davet',?,?)")
       ->execute([(int)$kontak['id'], tokenOzet($token), date('Y-m-d H:i:s', strtotime('+7 days'))]);
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $link = $scheme . '://' . guvenliHost() . siteKokYolu() . '/portal-davet-kabul.html?token=' . $token
          . ($donus !== null && meDonusGecerli($donus) ? '&donus=' . rawurlencode($donus) : '');
    $metin = "Merhaba " . $kontak['ad_soyad'] . ",\n\n"
           . "Müşteri portalımıza davet edildiniz. Kiralamalarınızı, tutanaklarınızı ve belgelerinizi buradan takip edebilirsiniz.\n"
           . "Aşağıdaki bağlantıya tıklayarak hesabınızı oluşturabilirsiniz (7 gün geçerlidir):\n\n" . $link;
    $epostaGitti = false;
    try {
        require_once __DIR__ . '/eposta.php';
        $sonuc = epostaGonder($db, (string)$kontak['email'], 'Müşteri Portalı Davetiniz', $metin, 'portal_davet');
        $epostaGitti = !empty($sonuc['basarili']);
    } catch (\Throwable $e) { error_log('portal daveti e-postasi gonderilemedi: ' . $e->getMessage()); }
    return $link;
}

function meDonusGecerli(string $d): bool {
    return (bool)preg_match('/^[A-Za-z0-9._-]+\.(html|php)(\?[A-Za-z0-9._~%&=+:-]*)?$/', $d) && !str_contains($d, '..');
}

function meOtoDavet(PDO $db, int $musteriId, ?string $donus): array {
    meSema($db);
    if (mePortalDurum($db, $musteriId) === 'aktif') return ['durum' => 'aktif', 'link' => null];
    $m = $db->prepare("SELECT id, unvan, yetkili, email, portal_oto_davet FROM musteriler WHERE id=?");
    $m->execute([$musteriId]);
    $mus = $m->fetch(PDO::FETCH_ASSOC);
    if (!$mus) return ['durum' => 'eposta_yok', 'link' => null];
    if (!empty($mus['portal_oto_davet'])) return ['durum' => 'bekliyor', 'link' => null];
    $k = $db->prepare("SELECT id, ad_soyad, email FROM musteri_kontaklari WHERE musteri_id=? AND sifre_hash IS NULL AND durum <> 'pasif' ORDER BY id DESC LIMIT 1");
    $k->execute([$musteriId]);
    $kontak = $k->fetch(PDO::FETCH_ASSOC);
    if (!$kontak) {
        $email = trim((string)($mus['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return ['durum' => 'eposta_yok', 'link' => null];
        $var = $db->prepare("SELECT 1 FROM musteri_kontaklari WHERE email = ? COLLATE NOCASE");
        $var->execute([$email]);
        if ($var->fetchColumn()) return ['durum' => 'eposta_yok', 'link' => null];
        $ad = trim((string)($mus['yetkili'] ?? '')) ?: (string)$mus['unvan'];
        $db->prepare("INSERT INTO musteri_kontaklari (musteri_id, ad_soyad, email) VALUES (?,?,?)")->execute([$musteriId, $ad, $email]);
        $kontak = ['id' => (int)$db->lastInsertId(), 'ad_soyad' => $ad, 'email' => $email];
        logla($db, 'musteri_kontaklari', (int)$kontak['id'], 'ekle', null, ['ad_soyad' => $ad, 'email' => $email],
              'Portal kişisi müşteri kartından açıldı (belge gönderiminde otomatik davet): ' . $mus['unvan']);
    }
    $link = meDavetLink($db, $kontak, $donus);
    $db->prepare("UPDATE musteriler SET portal_oto_davet=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $musteriId]);
    return ['durum' => 'davet', 'link' => $link, 'kontak' => $kontak];
}

function meErisimReddi(PDO $db, string $donus, bool $musteriBelgesi): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $personel = !empty($_SESSION['user_id']);
    if ($personel || mePortalMusteri($db) !== null) {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        $geri = $personel ? 'index.html' : 'portal.html';
        exit('<!doctype html><html lang="tr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<title>Belge görüntülenemiyor</title><body style="font-family:system-ui,sans-serif;max-width:32rem;margin:15vh auto;padding:0 1rem;color:#1f2937">'
           . '<h1 style="font-size:1.25rem">Bu belge hesabınıza ait değil</h1>'
           . '<p>Belge, giriş yaptığınız hesapla görüntülenemiyor. Belgenin gönderildiği hesapla giriş yapın ya da belgeyi size gönderen firmayla iletişime geçin.</p>'
           . '<p><a href="' . $geri . '">' . ($personel ? 'Panele dön' : 'Portalıma dön') . '</a></p></body></html>');
    }
    $giris = $musteriBelgesi ? 'portal-giris.html' : 'login.html';
    header('Location: ' . $giris . '?donus=' . rawurlencode($donus), true, 302);
    exit;
}

function meMesajNotu(array $erisim): string {
    return match ($erisim['durum'] ?? '') {
        'aktif'      => ' Belge yalnız müşteri portalı hesabınızla (e-posta ve şifrenizle) açılır.',
        'davet'      => ' Belgeyi görmek için önce müşteri portalı hesabınızı oluşturun: ' . $erisim['link'] . ' (7 gün geçerli; davet e-postanıza da gönderildi).',
        'bekliyor'   => ' Belge müşteri portalı hesabınızla açılır; hesabınızı size gönderilen davetle oluşturabilir, yeni davet için bizi arayabilirsiniz.',
        default      => ' Belge müşteri portalı hesabıyla açılır; hesap açabilmemiz için lütfen e-posta adresinizi bize iletin.',
    };
}
