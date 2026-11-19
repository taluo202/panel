<?php
declare(strict_types=1);


const KK_IZIN_SURE  = 12 * 3600;
const KK_PENCERE    = 600;
const KK_ESIK       = 20;
const KK_ENGEL_SURE = 3600;
const KK_SAKLA_SN   = 7 * 86400;

const KK_BAGLANTI_SAYFALARI = [
    'tutanak-goster.html'       => ['t', 'tutanak_gonderimleri'],
    'hasar-dosyasi-goster.html' => ['t', 'hasar_dosya_gonderimleri'],
    'saha-teslim.html'          => ['b', 'teslim_baglantilari'],
];

function kkSema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS kapi_ret (
        id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, dosya TEXT NOT NULL, zaman INTEGER NOT NULL)");
    $db->exec("CREATE INDEX IF NOT EXISTS ix_kapi_ret_ip ON kapi_ret(ip, zaman)");
    $db->exec("CREATE INDEX IF NOT EXISTS ix_kapi_ret_zaman ON kapi_ret(zaman)");
    $db->exec("CREATE TABLE IF NOT EXISTS kapi_engel (ip TEXT PRIMARY KEY, bitis INTEGER NOT NULL, alarm_zaman INTEGER NOT NULL)");
    $db->exec("CREATE TABLE IF NOT EXISTS kapi_iz (
        iz TEXT PRIMARY KEY, ip TEXT, sayfa TEXT, zaman INTEGER NOT NULL)");
}

function kkSayfaKodlari(string $html, array $acikJs): array {
    preg_match_all('#src="js/([A-Za-z0-9._-]+\.js)#', $html, $m);
    return array_values(array_unique(array_filter($m[1], fn($j) => in_array($j, $acikJs, true))));
}

function kkIzinVer(array $mevcut, array $dosyalar, int $simdi, int $sure = KK_IZIN_SURE): array {
    $mevcut = array_filter($mevcut, fn($b) => is_int($b) && $b > $simdi);
    foreach ($dosyalar as $d) $mevcut[$d] = $simdi + $sure;
    return $mevcut;
}

function kkIzinli(array $izin, string $dosya, int $simdi): bool {
    return isset($izin[$dosya]) && is_int($izin[$dosya]) && $izin[$dosya] > $simdi;
}

function kkBaglantiParam(string $sayfa): ?string {
    return KK_BAGLANTI_SAYFALARI[$sayfa][0] ?? null;
}

function kkBaglantiGecerli(PDO $db, string $sayfa, string $belirtec, string $simdiTarih): bool {
    if (!isset(KK_BAGLANTI_SAYFALARI[$sayfa])) return true;
    $tablo = KK_BAGLANTI_SAYFALARI[$sayfa][1];
    try {
        if ($tablo === 'teslim_baglantilari') {
            if (!preg_match('/^[a-f0-9]{48}$/', $belirtec)) return false;
            $q = $db->prepare("SELECT 1 FROM teslim_baglantilari WHERE belirtec_ozet=? AND yon IN ('gidis','donus')
                               AND kullanildi=0 AND gecerlilik > ?");
            $q->execute([tokenOzet($belirtec), $simdiTarih]);
            return (bool)$q->fetchColumn();
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $belirtec)) return false;
        $q = $db->prepare("SELECT * FROM $tablo WHERE token_hash=?");
        $q->execute([tokenOzet($belirtec)]);
        $g = $q->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return false;
    }
    if (!$g || (int)($g['iptal'] ?? 0) === 1 || ($g['kanal'] ?? '') === 'hata') return false;
    return (string)($g['son_gecerlilik'] ?? '') >= $simdiTarih;
}

function kkRetKaydet(PDO $db, string $ip, string $dosya, int $simdi): array {
    $db->prepare('INSERT INTO kapi_ret(ip, dosya, zaman) VALUES (?,?,?)')->execute([$ip, $dosya, $simdi]);
    $db->prepare('DELETE FROM kapi_ret WHERE zaman < ?')->execute([$simdi - KK_SAKLA_SN]);
    $q = $db->prepare('SELECT COUNT(DISTINCT dosya) FROM kapi_ret WHERE ip=? AND zaman > ?');
    $q->execute([$ip, $simdi - KK_PENCERE]);
    $farkli = (int)$q->fetchColumn();
    if ($farkli < KK_ESIK || kkYerel($ip) || kkEngelli($db, $ip, $simdi)) return ['alarm' => false, 'farkli' => $farkli];
    $db->prepare('INSERT OR REPLACE INTO kapi_engel(ip, bitis, alarm_zaman) VALUES (?,?,?)')
       ->execute([$ip, $simdi + KK_ENGEL_SURE, $simdi]);
    return ['alarm' => true, 'farkli' => $farkli];
}

function kkYerel(string $ip): bool {
    return in_array($ip, ['127.0.0.1', '::1'], true);
}

function kkEngelli(PDO $db, string $ip, int $simdi): bool {
    if (kkYerel($ip)) return false;
    $q = $db->prepare('SELECT bitis FROM kapi_engel WHERE ip=?');
    $q->execute([$ip]);
    $b = $q->fetchColumn();
    return $b !== false && (int)$b > $simdi;
}

function kkIzAnahtar(PDO $db): string {
    $db->prepare("INSERT OR IGNORE INTO ayarlar(anahtar, deger) VALUES ('kod_iz_anahtar', ?)")
       ->execute([bin2hex(random_bytes(32))]);
    return (string)$db->query("SELECT deger FROM ayarlar WHERE anahtar='kod_iz_anahtar'")->fetchColumn();
}

function kkIz(string $anahtar, string $kimlik, string $gun): string {
    return 'tz' . substr(hash_hmac('sha256', $kimlik . '|' . $gun, $anahtar), 0, 14);
}

function kkIzli(string $kod, string $iz, int $yil): string {
    return "/*! Taloo (c) $yil. Lisanslı yazılımdır; kopyalanması, tersine mühendisliği ve dağıtımı yasaktır. İz: $iz */\n"
         . $kod
         . "\n;(self.__tlz=self.__tlz||[]).push('$iz');\n";
}

function kkIzKaydet(PDO $db, string $iz, string $ip, string $sayfa, int $simdi): void {
    $db->prepare('INSERT OR IGNORE INTO kapi_iz(iz, ip, sayfa, zaman) VALUES (?,?,?,?)')->execute([$iz, $ip, $sayfa, $simdi]);
}

function kkIzCoz(PDO $db, string $iz, int $simdi, int $gun = 400): ?array {
    if (!preg_match('/^tz[a-z0-9]{14}$/', $iz)) return null;
    $q = $db->prepare('SELECT ip, sayfa, zaman FROM kapi_iz WHERE iz=?');
    $q->execute([$iz]);
    if ($r = $q->fetch(PDO::FETCH_ASSOC)) {
        return ['tur' => 'girissiz', 'ip' => $r['ip'], 'sayfa' => $r['sayfa'], 'tarih' => date('Y-m-d', (int)$r['zaman'])];
    }
    $anahtar = kkIzAnahtar($db);
    $kullanicilar = $db->query('SELECT id, username FROM users')->fetchAll(PDO::FETCH_ASSOC);
    for ($i = 0; $i <= $gun; $i++) {
        $g = date('Y-m-d', $simdi - $i * 86400);
        foreach ($kullanicilar as $u) {
            if (hash_equals(kkIz($anahtar, 'u:' . $u['id'], $g), $iz)) {
                return ['tur' => 'kullanici', 'kullanici_id' => (int)$u['id'], 'kullanici' => $u['username'], 'tarih' => $g];
            }
        }
    }
    return null;
}
