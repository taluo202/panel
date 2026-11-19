<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('expose_php', '0');

set_exception_handler(function (\Throwable $e) {
    error_log('Uncaught: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $fk = $e instanceof \PDOException && (string)$e->getCode() === '23000' && stripos($e->getMessage(), 'FOREIGN KEY') !== false;
    if (!headers_sent()) {
        http_response_code($fk ? 409 : 500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['success' => false, 'error' => $fk ? 'İlişkili kayıt bulunamadı.' : 'Sunucu hatası oluştu.'], JSON_UNESCAPED_UNICODE);
    exit(PHP_SAPI === 'cli' ? 255 : 0);
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('Fatal: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Sunucu hatası oluştu.'], JSON_UNESCAPED_UNICODE);
        }
    }
});

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

date_default_timezone_set('Europe/Istanbul');
if (function_exists('putenv') && getenv('TZ') !== 'TRT-3') @putenv('TZ=TRT-3');

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', '1');
ini_set('session.gc_maxlifetime', '7200');
$_isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || (($_SERVER['SERVER_PORT'] ?? '') == 443);
if ($_isHttps) ini_set('session.cookie_secure', '1');
session_name('PANEL_SESS');




const KURULUM_VARSAYILAN = ['TRUSTED_HOST' => 'avkbilgimerkezi.com', 'BASE_DOMAIN' => '', 'GUVENILIR_PROXYLER' => []];

function kurulumDosyasi(): string {
    $ozel = getenv('TALOO_KURULUM');
    return is_string($ozel) && $ozel !== '' ? $ozel : dirname(__DIR__, 2) . '/dbstore/kurulum.json';
}

function kurulumAyarlari(): array {
    static $a = null;
    if ($a !== null) return $a;
    $a = KURULUM_VARSAYILAN + ['_kaynak' => 'varsayilan', '_gecersiz' => []];
    $dosya = kurulumDosyasi();
    if (!@is_file($dosya)) return $a;
    $v = json_decode((string)@file_get_contents($dosya), true);
    if (!is_array($v)) { $a['_kaynak'] = 'bozuk'; return $a; }
    $a['_kaynak'] = 'dosya';
    $alanAdi = fn($x) => is_string($x) && ($x === '' || preg_match('/^[a-z0-9]([a-z0-9.\-]{0,251}[a-z0-9])?(:\d{1,5})?$/i', $x));
    foreach (['TRUSTED_HOST', 'BASE_DOMAIN'] as $k) {
        if (!array_key_exists($k, $v)) continue;
        if ($alanAdi($v[$k])) $a[$k] = strtolower($v[$k]); else $a['_gecersiz'][] = $k;
    }
    if (array_key_exists('GUVENILIR_PROXYLER', $v)) {
        $p = $v['GUVENILIR_PROXYLER'];
        if (is_array($p) && array_is_list($p) && !array_filter($p, fn($ip) => !is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP))) $a['GUVENILIR_PROXYLER'] = $p;
        else $a['_gecersiz'][] = 'GUVENILIR_PROXYLER';
    }
    return $a;
}

define('BASE_DOMAIN', kurulumAyarlari()['BASE_DOMAIN']);
define('TRUSTED_HOST', kurulumAyarlari()['TRUSTED_HOST']);
define('GUVENILIR_PROXYLER', kurulumAyarlari()['GUVENILIR_PROXYLER']);

function _tenantSlug(): string {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host);
    if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) return '';
    $base = BASE_DOMAIN;
    if ($base === '') return '';
    $suffix = '.' . $base;
    if (substr($host, -strlen($suffix)) === $suffix) {
        $sub = trim(substr($host, 0, -strlen($suffix)), '.');
        if ($sub !== '' && $sub !== 'www') return preg_replace('/[^a-z0-9_-]/', '', $sub);
    }
    return '';
}
define('TENANT_SLUG', _tenantSlug());
define('TENANT_SEG',  TENANT_SLUG !== '' ? TENANT_SLUG . '/' : '');

function siteKokYolu(): string {
    $kok = dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/x.php'));
    return rtrim(str_replace('\\', '/', $kok), '/');
}

function guvenliHost(): string {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $hostNoPort = preg_replace('/:\d+$/', '', $host);
    if (BASE_DOMAIN !== '') {
        $suffix = '.' . BASE_DOMAIN;
        if ($hostNoPort === BASE_DOMAIN || substr($hostNoPort, -strlen($suffix)) === $suffix) {
            return $host;
        }
        return BASE_DOMAIN;
    }
    if (TRUSTED_HOST !== '') return TRUSTED_HOST;
    return $host ?: 'localhost';
}

define('DB_PATH',      dirname(__DIR__, 2) . '/dbstore/' . TENANT_SEG . 'panel.sqlite');
define('UPLOAD_PATH',  __DIR__ . '/../uploads/' . TENANT_SEG);
define('MAX_FILE_SIZE', 20 * 1024 * 1024);
define('ALLOWED_MIME', ['application/pdf', 'image/jpeg', 'image/png', 'image/webp']);
define('ALLOWED_EXT',  ['pdf', 'jpg', 'jpeg', 'png', 'webp']);
define('LOGIN_RATE_LIMIT', 5);

function _resolveDbPath(): string {
    $primary = DB_PATH;
    $dir = dirname($primary);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (is_dir($dir) && is_writable($dir)) return $primary;

    $fbBase = __DIR__ . '/../dbstore';
    if (!is_dir($fbBase)) @mkdir($fbBase, 0775, true);
    if (is_dir($fbBase) && !file_exists($fbBase . '/.htaccess')) {
        @file_put_contents($fbBase . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    $fb = $fbBase . '/' . TENANT_SEG;
    if (!is_dir($fb)) @mkdir($fb, 0775, true);
    return $fb . 'panel.sqlite';
}

function getDb(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dbFile = _resolveDbPath();
        try {
            $pdo = new PDO('sqlite:' . $dbFile);
        } catch (\Throwable $e) {
            error_log('Taloo: veritabani acilamadi (' . $dbFile . '): ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error'   => 'Veritabanı açılamadı. dbstore klasörü yazılabilir mi ve pdo_sqlite eklentisi açık mı?',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        semaKur($pdo);
    }
    return $pdo;
}

const SEMA_DOSYALARI = ['config.php', 'filo-tarihce.php', 'hasar-akis.php', 'zincir-lib.php', 'ariza-karar-lib.php',
    'servis-kalem-lib.php', 'hasar-belge-lib.php', 'tutanak-surum.php', 'hazirlik-akis.php', 'saha-servis-lib.php',
    'servis-kaynak-lib.php', 'servis-maliyet-lib.php', 'operasyon-adim.php', 'hizli-kiralama-lib.php', 'sofor-lib.php',
    'kiralama-operasyon-lib.php', 'servis-talep-lib.php', 'makine-kategori-lib.php'];

function ilYazim(?string $il): string {
    $il = trim((string)$il);
    if ($il === '') return '';
    require_once __DIR__ . '/il-veri.php';
    return ilKanonik($il);
}

function semaIzi(): int {
    $s = '';
    foreach (SEMA_DOSYALARI as $f) {
        $y = __DIR__ . '/' . $f;
        $s .= $f . ':' . (int)@filesize($y) . ':' . (int)@filemtime($y) . ';';
    }
    return (crc32($s) & 0x7fffffff) ?: 1;
}

function semaKur(PDO $pdo): void {
    foreach (SEMA_DOSYALARI as $f) require_once __DIR__ . '/' . $f;
    $iz = semaIzi();
    if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() === $iz) return;
    initDb($pdo);
    ftSema($pdo);
    $pdo->exec('PRAGMA user_version = ' . $iz);
}

function initDb(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            username     TEXT    UNIQUE NOT NULL,
            password     TEXT    NOT NULL,
            ad_soyad     TEXT,
            rol          TEXT    DEFAULT 'admin',
            sube         TEXT,                          -- atanmışsa dashboard'da önce kendi şubesinin özetini görür
            created_at   TEXT    DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS kayitli_filtreler (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            kullanici_id INTEGER NOT NULL,
            sayfa        TEXT NOT NULL,                 -- ör. 'yonetim' — hangi sayfaya ait filtre
            ad           TEXT NOT NULL,                 -- kullanıcının verdiği isim
            filtre_json  TEXT NOT NULL,                 -- filtre alanları JSON olarak
            created_at   TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (kullanici_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS bildirim_gorulen (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            kullanici_id   INTEGER NOT NULL,
            oge_anahtari   TEXT NOT NULL,                -- ör. 'arac:5:trafik:2026-09-15' — tip+id+alan+tetikleyen tarih.
                                                          -- Tarih anahtara dahil edilir ki belge yenilenip tarih değişince
                                                          -- (aynı öge yeni bir tarihle tekrar risk oluşturunca) bildirim
                                                          -- eski 'görüldü' kaydına takılıp sonsuza dek gizli kalmasın.
            gorulme_tarihi TEXT DEFAULT (datetime('now','localtime')),
            UNIQUE(kullanici_id, oge_anahtari),
            FOREIGN KEY (kullanici_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS login_attempts (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            username     TEXT NOT NULL,
            ip           TEXT NOT NULL,
            success      INTEGER DEFAULT 0,
            attempted_at TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS personel (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            ad_soyad     TEXT NOT NULL,
            tc           TEXT,
            email        TEXT,
            telefon      TEXT,
            ise_giris    TEXT,
            pozisyon     TEXT,
            durum        TEXT DEFAULT 'aktif',
            adres        TEXT,
            notlar       TEXT,
            created_at   TEXT DEFAULT (datetime('now','localtime')),
            updated_at   TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS personel_belgeler (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            personel_id  INTEGER NOT NULL,
            kategori     TEXT NOT NULL,
            belge_adi    TEXT NOT NULL,
            dosya_yolu   TEXT,
            bitis_tarihi TEXT,
            created_at   TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (personel_id) REFERENCES personel(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS izin_talepleri (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            personel_id  INTEGER NOT NULL,
            baslangic    TEXT NOT NULL,
            bitis        TEXT NOT NULL,
            gun_sayisi   INTEGER NOT NULL,
            izin_turu    TEXT DEFAULT 'yillik',
            durum        TEXT DEFAULT 'bekliyor',
            notlar       TEXT,
            created_at   TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (personel_id) REFERENCES personel(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS araclar (
            id                       INTEGER PRIMARY KEY AUTOINCREMENT,
            plaka                    TEXT UNIQUE NOT NULL,
            ruhsat_no                TEXT,
            marka                    TEXT,
            model                    TEXT,
            tescil_tarihi            TEXT,
            trafik_baslangic         TEXT,
            trafik_bitis             TEXT,
            trafik_sigorta_tutar     REAL DEFAULT 0,
            kasko_baslangic          TEXT,
            kasko_bitis              TEXT,
            kasko_tutar              REAL DEFAULT 0,
            makine_kirilma_baslangic TEXT,
            makine_kirilma_bitis     TEXT,
            tuv_muayene_bitis        TEXT,
            periyodik_kontrol_bitis  TEXT,
            notlar                   TEXT,
            created_at               TEXT DEFAULT (datetime('now','localtime')),
            updated_at               TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS arac_belgeler (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            arac_id      INTEGER NOT NULL,
            kategori     TEXT NOT NULL,
            belge_adi    TEXT NOT NULL,
            dosya_yolu   TEXT,
            created_at   TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (arac_id) REFERENCES araclar(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS manliftler (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            kod              TEXT UNIQUE NOT NULL,
            seri_no          TEXT,
            model            TEXT,
            marka            TEXT,
            imal_yili        INTEGER,
            periyodik_bitis  TEXT,
            notlar           TEXT,
            created_at       TEXT DEFAULT (datetime('now','localtime')),
            updated_at       TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS manlift_belgeler (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            manlift_id   INTEGER NOT NULL,
            kategori     TEXT NOT NULL,
            belge_adi    TEXT NOT NULL,
            dosya_yolu   TEXT,
            created_at   TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (manlift_id) REFERENCES manliftler(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS finans (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            personel_id     INTEGER NOT NULL,
            ay              TEXT NOT NULL,
            toplam_maas     REAL DEFAULT 0,
            mesai           REAL DEFAULT 0,
            haciz_kesinti   REAL DEFAULT 0,
            avans           REAL DEFAULT 0,
            bankaya_yatan   REAL DEFAULT 0,
            elden_kalan     REAL DEFAULT 0,
            bes             REAL DEFAULT 0,
            notlar          TEXT,
            created_at      TEXT DEFAULT (datetime('now','localtime')),
            UNIQUE(personel_id, ay),
            FOREIGN KEY (personel_id) REFERENCES personel(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS ayarlar (
            anahtar TEXT PRIMARY KEY,
            deger   TEXT NOT NULL DEFAULT ''
        );

        CREATE TABLE IF NOT EXISTS share_tokens (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            token          TEXT UNIQUE NOT NULL,
            belge_id       INTEGER NOT NULL,
            tur            TEXT NOT NULL,
            expires_at     TEXT NOT NULL,
            indirim_sayisi INTEGER DEFAULT 0,
            max_indirim    INTEGER DEFAULT 20,
            created_at     TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS izin_devir_log (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            yil         INTEGER NOT NULL,
            personel_id INTEGER NOT NULL,
            devir_gun   INTEGER NOT NULL DEFAULT 0,
            created_at  TEXT DEFAULT (datetime('now','localtime')),
            UNIQUE(yil, personel_id)
        );

        CREATE TABLE IF NOT EXISTS kiralama_urunler (
            id        INTEGER PRIMARY KEY,
            isim      TEXT NOT NULL,
            kategori  TEXT NOT NULL,
            gorsel    TEXT,
            sira      INTEGER DEFAULT 0
        );

        CREATE TABLE IF NOT EXISTS kiralama_kataloglar (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            kategori    TEXT NOT NULL,
            dosya_adi   TEXT NOT NULL,
            dosya_yolu  TEXT NOT NULL,
            created_at  TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS kiralama_urun_gorseller (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            urun_id     INTEGER NOT NULL,
            gorsel_yolu TEXT NOT NULL,
            sira        INTEGER DEFAULT 0,
            created_at  TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (urun_id) REFERENCES kiralama_urunler(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS kiralama_urun_kataloglar (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            urun_id     INTEGER NOT NULL,
            dosya_adi   TEXT NOT NULL,
            dosya_yolu  TEXT NOT NULL,
            created_at  TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (urun_id) REFERENCES kiralama_urunler(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS galeri_dosyalar (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            baslik      TEXT NOT NULL,
            kategori    TEXT DEFAULT 'Genel',
            dosya_yolu  TEXT NOT NULL,
            created_at  TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS form_kayitlari (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            tur          TEXT NOT NULL,
            ad           TEXT NOT NULL,
            musteri      TEXT,
            personel_id  INTEGER,
            tarih        TEXT,
            form_verisi  TEXT NOT NULL DEFAULT '{}',
            created_at   TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS imzalar (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            ad_soyad    TEXT NOT NULL,
            unvan       TEXT,
            rol         TEXT NOT NULL DEFAULT 'operatör',
            img_yolu    TEXT,
            personel_id INTEGER,
            created_at  TEXT DEFAULT (datetime('now','localtime'))
        );

        -- ── Kiralama Takip (operasyon) ──────────────────────────────────────
        CREATE TABLE IF NOT EXISTS musteriler (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            tur            TEXT DEFAULT 'kurumsal',   -- kurumsal | bireysel
            unvan          TEXT NOT NULL,             -- firma adı / kişi adı
            yetkili        TEXT,                      -- yetkili kişi
            telefon        TEXT,
            email          TEXT,
            vergi_dairesi  TEXT,
            vergi_no       TEXT,                      -- vergi no veya TC
            adres          TEXT,
            notlar         TEXT,
            durum          TEXT DEFAULT 'aktif',      -- aktif | pasif
            created_at     TEXT DEFAULT (datetime('now','localtime')),
            updated_at     TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS kiralamalar (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            kod            TEXT,                      -- KR-2026-0001
            musteri_id     INTEGER NOT NULL,
            manlift_id     INTEGER,                   -- opsiyonel envanter bağlantısı
            ekipman_adi    TEXT NOT NULL,             -- kiralanan ekipman
            baslangic      TEXT NOT NULL,
            bitis          TEXT,                      -- planlanan bitiş (null = açık)
            gercek_bitis   TEXT,                      -- gerçek teslim alış
            kira_tipi      TEXT DEFAULT 'gunluk',     -- gunluk | haftalik | aylik
            birim_ucret    REAL DEFAULT 0,
            miktar         INTEGER DEFAULT 1,
            nakliye_ucret  REAL DEFAULT 0,
            lokasyon       TEXT,                      -- şantiye / iş yeri
            kdv_oran       REAL DEFAULT 20,
            durum          TEXT DEFAULT 'aktif',      -- aktif | tamamlandi | iptal
            notlar         TEXT,
            created_at     TEXT DEFAULT (datetime('now','localtime')),
            updated_at     TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (musteri_id) REFERENCES musteriler(id) ON DELETE RESTRICT,
            FOREIGN KEY (manlift_id) REFERENCES manliftler(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS tahsilatlar (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            musteri_id     INTEGER NOT NULL,
            kiralama_id    INTEGER,                   -- opsiyonel: hangi kiralamaya karşılık
            tarih          TEXT NOT NULL,
            tutar          REAL DEFAULT 0,
            yontem         TEXT DEFAULT 'havale',     -- nakit | havale | cek | kredi_karti
            aciklama       TEXT,
            created_at     TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (musteri_id)  REFERENCES musteriler(id)  ON DELETE CASCADE,
            FOREIGN KEY (kiralama_id) REFERENCES kiralamalar(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS teklifler (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            kod          TEXT,                        -- TKF-2026-0001
            musteri_id   INTEGER NOT NULL,
            tarih        TEXT,
            gecerlilik   TEXT,                        -- geçerlilik tarihi
            durum        TEXT DEFAULT 'taslak',       -- taslak | gonderildi | onaylandi | reddedildi | donustu
            kdv_oran     REAL DEFAULT 20,
            notlar       TEXT,
            kiralama_id  INTEGER,                     -- dönüştüğü kiralama (ilk kalem)
            created_at   TEXT DEFAULT (datetime('now','localtime')),
            updated_at   TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (musteri_id) REFERENCES musteriler(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS teklif_kalemleri (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            teklif_id    INTEGER NOT NULL,
            aciklama     TEXT NOT NULL,
            kira_tipi    TEXT DEFAULT 'gunluk',       -- gunluk | haftalik | aylik
            miktar       REAL DEFAULT 1,
            sure         REAL DEFAULT 1,
            birim_ucret  REAL DEFAULT 0,
            FOREIGN KEY (teklif_id) REFERENCES teklifler(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS hakedisler (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            kod           TEXT,                       -- HKD-2026-0001
            kiralama_id   INTEGER,
            musteri_id    INTEGER NOT NULL,
            donem_bas     TEXT,
            donem_bit     TEXT,
            aciklama      TEXT,
            tutar         REAL DEFAULT 0,             -- KDV hariç ara toplam
            kdv_oran      REAL DEFAULT 20,
            kdv_tutar     REAL DEFAULT 0,
            genel_toplam  REAL DEFAULT 0,
            fatura_no     TEXT,
            fatura_tarihi TEXT,
            durum         TEXT DEFAULT 'beklemede',   -- beklemede | faturalandi | tahsil_edildi
            created_at    TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (musteri_id)  REFERENCES musteriler(id)  ON DELETE CASCADE,
            FOREIGN KEY (kiralama_id) REFERENCES kiralamalar(id) ON DELETE SET NULL
        );

        -- ── Şubeler ve Yedek Parça ──────────────────────────────────────────
        CREATE TABLE IF NOT EXISTS subeler (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            ad         TEXT UNIQUE NOT NULL,
            aktif      INTEGER DEFAULT 1,
            created_at TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS giderler (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            makine_tur  TEXT NOT NULL,               -- manlift | arac
            makine_id   INTEGER NOT NULL,
            tarih       TEXT NOT NULL,
            kategori    TEXT DEFAULT 'diger',         -- yakit | bakim | sigorta | lastik | onarim | diger
            tutar       REAL DEFAULT 0,
            aciklama    TEXT,
            created_at  TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS nakliyeler (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            tarih         TEXT NOT NULL,
            yon           TEXT DEFAULT 'gidis',      -- gidis | donus | tek
            kiralama_id   INTEGER,                   -- opsiyonel bağlantı
            musteri_id    INTEGER,                   -- opsiyonel
            ekipman       TEXT,                      -- taşınan makine/ekipman
            nakliyeci     TEXT,                      -- ödeme yapılan nakliye firması/araç
            plaka         TEXT,
            lokasyon      TEXT,
            odenen        REAL DEFAULT 0,            -- bize maliyet (nakliyeciye ödenen)
            yansitilan    REAL DEFAULT 0,            -- müşteriye yansıtılan
            sube          TEXT,
            temsilci      TEXT,
            aciklama      TEXT,
            created_at    TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (musteri_id)  REFERENCES musteriler(id)  ON DELETE SET NULL,
            FOREIGN KEY (kiralama_id) REFERENCES kiralamalar(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS yedek_parca (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            ad            TEXT NOT NULL,             -- yedek parça adı
            parca_no      TEXT,                      -- parça numarası
            aciklama      TEXT,
            sube          TEXT,                      -- bulunduğu şube
            stok          INTEGER DEFAULT 0,         -- stok adedi
            kritik_stok   INTEGER DEFAULT 0,         -- kritik stok eşiği (uyarı)
            fiyat         REAL DEFAULT 0,            -- birim fiyat
            uyumlu_makine TEXT,                      -- uyumlu makine(ler)
            seri_no       TEXT,
            uretim_no     TEXT,                      -- üretim no
            created_at    TEXT DEFAULT (datetime('now','localtime')),
            updated_at    TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS stok_hareket (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            parca_id    INTEGER NOT NULL,
            tarih       TEXT NOT NULL,
            tip         TEXT DEFAULT 'cikis',         -- giris | cikis | sayim
            miktar      INTEGER DEFAULT 0,            -- her zaman pozitif
            kaynak      TEXT DEFAULT 'manuel',        -- manuel | servis
            servis_id   INTEGER,                       -- teknik_servis bağlantısı (sarf)
            birim_fiyat REAL DEFAULT 0,
            aciklama    TEXT,
            created_at  TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (parca_id) REFERENCES yedek_parca(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS gorevler (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            baslik      TEXT NOT NULL,
            aciklama    TEXT,
            atanan_id   INTEGER,                      -- personel
            son_tarih   TEXT,
            oncelik     TEXT DEFAULT 'normal',        -- dusuk | normal | yuksek
            durum       TEXT DEFAULT 'acik',          -- acik | tamamlandi
            tamamlanma  TEXT,
            kaynak      TEXT DEFAULT 'manuel',        -- manuel | bildirim
            created_at  TEXT DEFAULT (datetime('now','localtime')),
            updated_at  TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS cek_senet (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            tip         TEXT DEFAULT 'cek',          -- cek | senet
            yon         TEXT DEFAULT 'alinan',       -- alinan (müşteriden) | verilen (tedarikçiye)
            musteri_id  INTEGER,                      -- ilişkili müşteri (ops.)
            kisi        TEXT,                         -- keşideci / lehtar serbest metin
            banka       TEXT,
            belge_no    TEXT,                         -- çek/senet no
            tutar       REAL DEFAULT 0,
            vade_tarihi TEXT NOT NULL,
            durum       TEXT DEFAULT 'portfoyde',     -- portfoyde | tahsil | odendi | karsiliksiz | iade
            aciklama    TEXT,
            created_at  TEXT DEFAULT (datetime('now','localtime')),
            updated_at  TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (musteri_id) REFERENCES musteriler(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS teknik_servis (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            makine_tur  TEXT,                       -- manlift | arac
            makine_id   INTEGER,
            makine_ad   TEXT,                        -- anlık görüntü (silinse de kalsın)
            tip         TEXT DEFAULT 'ariza',        -- ariza | bakim | expertiz
            tarih       TEXT NOT NULL,
            durum       TEXT DEFAULT 'acik',         -- acik | devam | tamamlandi
            oncelik     TEXT DEFAULT 'normal',       -- dusuk | normal | yuksek | acil
            ariza       TEXT,                        -- arıza/talep açıklaması
            yapilan     TEXT,                        -- yapılan işlem
            teknisyen_id INTEGER,                    -- personel
            maliyet     REAL DEFAULT 0,
            tamamlanma  TEXT,                         -- tamamlanma tarihi
            notlar      TEXT,
            created_at  TEXT DEFAULT (datetime('now','localtime')),
            updated_at  TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS kiralama_puantaj (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            kiralama_id INTEGER NOT NULL,
            tarih       TEXT NOT NULL,
            durum       TEXT DEFAULT 'tam',        -- tam | yarim | yok
            saat        REAL DEFAULT 0,            -- çalışılan saat (ops.)
            mesai       REAL DEFAULT 0,            -- mesai saati (ops.)
            aciklama    TEXT,
            created_at  TEXT DEFAULT (datetime('now','localtime')),
            UNIQUE(kiralama_id, tarih),
            FOREIGN KEY (kiralama_id) REFERENCES kiralamalar(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS tahsilat_hedefleri (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            ay         TEXT NOT NULL,             -- 'YYYY-MM'
            sube       TEXT NOT NULL DEFAULT '',  -- '' = genel/tüm şubeler
            hedef      REAL DEFAULT 0,
            created_at TEXT DEFAULT (datetime('now','localtime')),
            updated_at TEXT DEFAULT (datetime('now','localtime')),
            UNIQUE(ay, sube)
        );

        CREATE TABLE IF NOT EXISTS form_paylasim (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            token      TEXT UNIQUE NOT NULL,      -- public erişim anahtarı
            form_id    INTEGER,                   -- form_kayitlari.id (referans)
            baslik     TEXT,                      -- belge başlığı (paylaşım metni için)
            html       TEXT NOT NULL,             -- yazdırılabilir HTML anlık görüntüsü
            created_at TEXT DEFAULT (datetime('now','localtime'))
        );
    ");

    $migrations = [
        "ALTER TABLE araclar RENAME COLUMN firma TO marka",
        "ALTER TABLE araclar ADD COLUMN model TEXT",
        "ALTER TABLE araclar ADD COLUMN trafik_sigorta_tutar REAL DEFAULT 0",
        "ALTER TABLE araclar ADD COLUMN kasko_tutar REAL DEFAULT 0",
        "ALTER TABLE finans ADD COLUMN bes REAL DEFAULT 0",
        "ALTER TABLE manliftler ADD COLUMN metraj TEXT",
        "ALTER TABLE finans ADD COLUMN prim REAL DEFAULT 0",
        "ALTER TABLE personel ADD COLUMN ekstra_izin_hak INTEGER DEFAULT 0",
        "ALTER TABLE users ADD COLUMN yetkiler TEXT DEFAULT 'tum'",
        "ALTER TABLE izin_talepleri ADD COLUMN belge_yolu TEXT",
        "ALTER TABLE izin_talepleri ADD COLUMN belge_adi TEXT",
        "ALTER TABLE share_tokens ADD COLUMN indirim_sayisi INTEGER DEFAULT 0",
        "ALTER TABLE share_tokens ADD COLUMN max_indirim INTEGER DEFAULT 20",
        "ALTER TABLE kiralama_urunler ADD COLUMN aciklama TEXT",
        "ALTER TABLE kiralama_urunler ADD COLUMN gorsel_yerel TEXT",
        "ALTER TABLE manlift_belgeler ADD COLUMN bitis_tarihi TEXT",
        "ALTER TABLE arac_belgeler ADD COLUMN bitis_tarihi TEXT",
        "ALTER TABLE personel_belgeler ADD COLUMN arsiv INTEGER DEFAULT 0",
        "ALTER TABLE arac_belgeler ADD COLUMN arsiv INTEGER DEFAULT 0",
        "ALTER TABLE manlift_belgeler ADD COLUMN arsiv INTEGER DEFAULT 0",
        "ALTER TABLE manliftler ADD COLUMN sube TEXT",
        "ALTER TABLE finans ADD COLUMN normal_mesai_saat REAL DEFAULT 0",
        "ALTER TABLE finans ADD COLUMN pazar_mesai_saat REAL DEFAULT 0",
        "INSERT OR IGNORE INTO ayarlar (anahtar, deger) VALUES ('normal_mesai_ucret','0')",
        "INSERT OR IGNORE INTO ayarlar (anahtar, deger) VALUES ('pazar_mesai_ucret','0')",
        "ALTER TABLE personel ADD COLUMN normal_mesai_ucret REAL DEFAULT 0",
        "ALTER TABLE personel ADD COLUMN pazar_mesai_ucret REAL DEFAULT 0",
        "ALTER TABLE finans ADD COLUMN normal_mesai_ucret REAL DEFAULT 0",
        "ALTER TABLE finans ADD COLUMN pazar_mesai_ucret REAL DEFAULT 0",
        "ALTER TABLE hakedisler ADD COLUMN vade_tarihi TEXT",
        "ALTER TABLE tahsilatlar ADD COLUMN hakedis_id INTEGER",
        "ALTER TABLE finans ADD COLUMN devir REAL DEFAULT 0",
        "ALTER TABLE kiralamalar ADD COLUMN sube TEXT",
        "ALTER TABLE musteriler ADD COLUMN sube TEXT",
        "ALTER TABLE kiralamalar ADD COLUMN temsilci TEXT",
        "ALTER TABLE musteriler ADD COLUMN temsilci TEXT",
        "ALTER TABLE teklifler ADD COLUMN sube TEXT",
        "ALTER TABLE teklifler ADD COLUMN temsilci TEXT",
        "ALTER TABLE hakedisler ADD COLUMN sube TEXT",
        "ALTER TABLE tahsilatlar ADD COLUMN sube TEXT",
        "ALTER TABLE hakedisler ADD COLUMN temsilci TEXT",
        "ALTER TABLE tahsilatlar ADD COLUMN temsilci TEXT",
        "ALTER TABLE araclar ADD COLUMN sube TEXT",
        "ALTER TABLE kiralamalar ADD COLUMN makine_tur TEXT",
        "ALTER TABLE kiralamalar ADD COLUMN makine_id INTEGER",
        "ALTER TABLE manliftler ADD COLUMN alis_bedeli REAL DEFAULT 0",
        "ALTER TABLE manliftler ADD COLUMN faydali_omur_yil INTEGER DEFAULT 0",
        "ALTER TABLE araclar ADD COLUMN alis_bedeli REAL DEFAULT 0",
        "ALTER TABLE araclar ADD COLUMN faydali_omur_yil INTEGER DEFAULT 0",
        "ALTER TABLE araclar ADD COLUMN aktif INTEGER DEFAULT 1",
        "ALTER TABLE manliftler ADD COLUMN aktif INTEGER DEFAULT 1",
        "ALTER TABLE kiralamalar ADD COLUMN operatorlu INTEGER DEFAULT 0",
        "ALTER TABLE kiralamalar ADD COLUMN operator_id INTEGER",
        "ALTER TABLE kiralamalar ADD COLUMN operator_ucret REAL DEFAULT 0",
        "ALTER TABLE kiralamalar ADD COLUMN operator_ucret_tipi TEXT DEFAULT 'gunluk'",
        "ALTER TABLE kiralamalar ADD COLUMN faturalandi INTEGER DEFAULT 0",
        "ALTER TABLE kiralamalar ADD COLUMN fatura_no TEXT",
        "ALTER TABLE kiralamalar ADD COLUMN fatura_tarihi TEXT",
        "ALTER TABLE cek_senet ADD COLUMN tahsilat_id INTEGER",
        "ALTER TABLE cek_senet ADD COLUMN sube TEXT",
        "ALTER TABLE cek_senet ADD COLUMN sube_no TEXT",
        "ALTER TABLE cek_senet ADD COLUMN hesap_no TEXT",
        "ALTER TABLE cek_senet ADD COLUMN iban TEXT",
        "ALTER TABLE cek_senet ADD COLUMN teslim_tarihi TEXT",
        "ALTER TABLE cek_senet ADD COLUMN durum_tarihi TEXT",
        "ALTER TABLE cek_senet ADD COLUMN ciro_alici TEXT",
        "CREATE TABLE IF NOT EXISTS cek_senet_gecmis (
            id INTEGER PRIMARY KEY AUTOINCREMENT, cek_id INTEGER NOT NULL,
            eylem TEXT NOT NULL, islem_tarihi TEXT, gerekce TEXT,
            eski TEXT, yeni TEXT, kullanici_id INTEGER, kullanici_ad TEXT,
            kayit_tarihi TEXT DEFAULT (datetime('now','localtime')))",
        "CREATE INDEX IF NOT EXISTS idx_cek_gecmis ON cek_senet_gecmis(cek_id,id)",
        "ALTER TABLE users ADD COLUMN email TEXT",
        "ALTER TABLE musteriler ADD COLUMN risk_limit REAL DEFAULT 0",
        "CREATE TABLE IF NOT EXISTS password_resets (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL,
            token      TEXT UNIQUE NOT NULL,
            expires_at TEXT NOT NULL,
            used       INTEGER DEFAULT 0,
            created_at TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS tutanaklar (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            tur         TEXT NOT NULL,            -- personel | arac | manlift
            entity_id   INTEGER NOT NULL,
            tarih       TEXT,
            kategori    TEXT,
            baslik      TEXT NOT NULL,
            aciklama    TEXT,
            dosya_yolu  TEXT,
            olusturan   TEXT,
            created_at  TEXT DEFAULT (datetime('now','localtime'))
        )",
        "CREATE INDEX IF NOT EXISTS idx_tutanak_entity ON tutanaklar(tur, entity_id)",
        "ALTER TABLE manliftler ADD COLUMN calisma_saati REAL DEFAULT 0",
        "ALTER TABLE manliftler ADD COLUMN son_bakim_saat REAL DEFAULT 0",
        "ALTER TABLE manliftler ADD COLUMN bakim_araligi_saat REAL DEFAULT 0",
        "ALTER TABLE araclar ADD COLUMN calisma_saati REAL DEFAULT 0",
        "ALTER TABLE araclar ADD COLUMN son_bakim_saat REAL DEFAULT 0",
        "ALTER TABLE araclar ADD COLUMN bakim_araligi_saat REAL DEFAULT 0",
        "ALTER TABLE manliftler ADD COLUMN ruhsat_no TEXT",
        "ALTER TABLE manliftler ADD COLUMN tescil_tarihi TEXT",
        "ALTER TABLE musteriler ADD COLUMN iskonto_oran REAL DEFAULT 0",
        "CREATE TABLE IF NOT EXISTS firsatlar (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            unvan       TEXT NOT NULL,
            kisi        TEXT,
            telefon     TEXT,
            email       TEXT,
            kaynak      TEXT,                         -- referans | web | sosyal_medya | soguk_arama | diger
            durum       TEXT DEFAULT 'yeni',           -- yeni | iletisimde | teklif_bekliyor | kazanildi | kaybedildi
            temsilci    TEXT,
            sube        TEXT,
            tahmini_tutar REAL DEFAULT 0,
            notlar      TEXT,
            musteri_id  INTEGER,                       -- 'kazanıldı' olunca dönüştüğü müşteri
            created_at  TEXT DEFAULT (datetime('now','localtime')),
            updated_at  TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (musteri_id) REFERENCES musteriler(id) ON DELETE SET NULL
        )",
        "ALTER TABLE araclar ADD COLUMN durum TEXT DEFAULT 'musait'",
        "ALTER TABLE manliftler ADD COLUMN durum TEXT DEFAULT 'musait'",
        "CREATE TABLE IF NOT EXISTS ekipman_satis (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            makine_tur    TEXT NOT NULL,               -- arac | manlift
            makine_id     INTEGER NOT NULL,
            makine_ad     TEXT,                         -- anlık görüntü (silinse de kalsın)
            tarih         TEXT NOT NULL,
            alici_unvan   TEXT,
            alici_vergi_no TEXT,
            satis_tutari  REAL DEFAULT 0,               -- KDV hariç
            kdv_oran      REAL DEFAULT 20,
            kdv_tutari    REAL DEFAULT 0,
            genel_toplam  REAL DEFAULT 0,
            notlar        TEXT,
            created_at    TEXT DEFAULT (datetime('now','localtime'))
        )",
        "ALTER TABLE stok_hareket ADD COLUMN musteri_id INTEGER",
        "ALTER TABLE stok_hareket ADD COLUMN satis_fiyat REAL DEFAULT 0",
        "ALTER TABLE hakedisler ADD COLUMN tevkifat_var INTEGER DEFAULT 0",
        "ALTER TABLE hakedisler ADD COLUMN tevkifat_orani TEXT",
        "ALTER TABLE hakedisler ADD COLUMN tevkifat_tutari REAL DEFAULT 0",
        "ALTER TABLE hakedisler ADD COLUMN musteriden_tahsil_kdv REAL DEFAULT 0",
        "ALTER TABLE araclar ADD COLUMN garanti_bitis TEXT",
        "ALTER TABLE manliftler ADD COLUMN garanti_bitis TEXT",
        "ALTER TABLE yedek_parca ADD COLUMN garanti_bitis TEXT",
        "CREATE TABLE IF NOT EXISTS ekipman_transfer (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            makine_tur    TEXT NOT NULL,               -- arac | manlift
            makine_id     INTEGER NOT NULL,
            makine_ad     TEXT,                         -- anlık görüntü (silinse de kalsın)
            tarih         TEXT NOT NULL,
            eski_sube     TEXT,
            yeni_sube     TEXT,
            aciklama      TEXT,
            created_at    TEXT DEFAULT (datetime('now','localtime'))
        )",
        "CREATE INDEX IF NOT EXISTS idx_ekipman_transfer_makine ON ekipman_transfer(makine_tur, makine_id)",
        "CREATE TABLE IF NOT EXISTS tedarikciler (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            unvan         TEXT NOT NULL,
            yetkili       TEXT,
            telefon       TEXT,
            email         TEXT,
            vergi_dairesi TEXT,
            vergi_no      TEXT,
            adres         TEXT,
            notlar        TEXT,
            aktif         INTEGER DEFAULT 1,
            created_at    TEXT DEFAULT (datetime('now','localtime')),
            updated_at    TEXT DEFAULT (datetime('now','localtime'))
        )",
        "CREATE TABLE IF NOT EXISTS satinalma_siparisleri (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            kod           TEXT,                        -- SA-2026-0001
            tedarikci_id  INTEGER NOT NULL,
            tarih         TEXT NOT NULL,
            durum         TEXT DEFAULT 'taslak',        -- taslak | gonderildi | kismi_teslim | tamamlandi | iptal
            notlar        TEXT,
            created_at    TEXT DEFAULT (datetime('now','localtime')),
            updated_at    TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (tedarikci_id) REFERENCES tedarikciler(id) ON DELETE RESTRICT
        )",
        "CREATE TABLE IF NOT EXISTS satinalma_kalemleri (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            siparis_id     INTEGER NOT NULL,
            parca_id       INTEGER,                     -- opsiyonel: yedek_parca ile eşleşiyorsa stok otomatik güncellenir
            aciklama       TEXT NOT NULL,
            miktar         REAL DEFAULT 1,
            birim_fiyat    REAL DEFAULT 0,
            teslim_alinan  REAL DEFAULT 0,
            FOREIGN KEY (siparis_id) REFERENCES satinalma_siparisleri(id) ON DELETE CASCADE,
            FOREIGN KEY (parca_id) REFERENCES yedek_parca(id) ON DELETE SET NULL
        )",
        "CREATE INDEX IF NOT EXISTS idx_satinalma_kalem_siparis ON satinalma_kalemleri(siparis_id)",
        "ALTER TABLE kiralamalar ADD COLUMN sozlesme_no TEXT",
        "ALTER TABLE kiralamalar ADD COLUMN sozlesme_imza_tarihi TEXT",
        "ALTER TABLE kiralamalar ADD COLUMN otomatik_yenileme INTEGER DEFAULT 0",
        "ALTER TABLE teklifler ADD COLUMN iskonto_oran REAL DEFAULT 0",
        "ALTER TABLE teklifler ADD COLUMN onay_durumu TEXT DEFAULT 'gerekmiyor'",
        "ALTER TABLE teklifler ADD COLUMN onaylayan TEXT",
        "ALTER TABLE teklifler ADD COLUMN onay_tarihi TEXT",
        "CREATE TABLE IF NOT EXISTS stok_transfer (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            parca_ad      TEXT NOT NULL,               -- eşleştirme anahtarı (ad, parça_no yerine ad kullanılır)
            parca_no      TEXT,
            tarih         TEXT NOT NULL,
            kaynak_sube   TEXT NOT NULL,
            hedef_sube    TEXT NOT NULL,
            miktar        INTEGER NOT NULL,
            aciklama      TEXT,
            created_at    TEXT DEFAULT (datetime('now','localtime'))
        )",
        "CREATE TABLE IF NOT EXISTS butceler (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            yil           INTEGER NOT NULL,
            ay            INTEGER NOT NULL,             -- 1-12
            sube          TEXT,                          -- boş = tüm şubeler / genel
            kategori      TEXT NOT NULL,                 -- personel | yakit | bakim | sigorta | lastik | onarim | diger | genel
            hedef_tutar   REAL DEFAULT 0,
            notlar        TEXT,
            created_at    TEXT DEFAULT (datetime('now','localtime')),
            updated_at    TEXT DEFAULT (datetime('now','localtime')),
            UNIQUE(yil, ay, sube, kategori)
        )",
        "CREATE TABLE IF NOT EXISTS personel_masraf (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            avans_id      INTEGER,                        -- artık kullanılmıyor (bkz. yukarıdaki not)
            personel_id   INTEGER NOT NULL,
            tarih         TEXT NOT NULL,
            kategori      TEXT DEFAULT 'diger',            -- yakit | yemek | yol | diger
            tutar         REAL DEFAULT 0,
            aciklama      TEXT,
            fis_dosya     TEXT,                            -- fiş fotoğrafı/PDF yolu
            durum         TEXT DEFAULT 'bekliyor',         -- bekliyor | onaylandi | reddedildi
            onaylayan     TEXT,
            created_at    TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (personel_id) REFERENCES personel(id) ON DELETE CASCADE
        )",
        "CREATE INDEX IF NOT EXISTS idx_personel_masraf_avans ON personel_masraf(avans_id)",
        "ALTER TABLE gorevler ADD COLUMN kaynak TEXT DEFAULT 'manuel'",
        "ALTER TABLE users ADD COLUMN sube TEXT",
        "ALTER TABLE kiralamalar ADD COLUMN son_hatirlatma_tarih TEXT",
        "CREATE INDEX IF NOT EXISTS idx_kiralamalar_musteri ON kiralamalar(musteri_id)",
        "CREATE INDEX IF NOT EXISTS idx_kiralamalar_makine ON kiralamalar(makine_tur, makine_id)",
        "CREATE INDEX IF NOT EXISTS idx_tahsilatlar_musteri ON tahsilatlar(musteri_id)",
        "CREATE INDEX IF NOT EXISTS idx_tahsilatlar_hakedis ON tahsilatlar(hakedis_id)",
        "CREATE INDEX IF NOT EXISTS idx_hakedisler_musteri ON hakedisler(musteri_id)",
        "CREATE INDEX IF NOT EXISTS idx_hakedisler_kiralama ON hakedisler(kiralama_id)",
        "CREATE INDEX IF NOT EXISTS idx_teklif_kalemleri_teklif ON teklif_kalemleri(teklif_id)",
        "CREATE INDEX IF NOT EXISTS idx_personel_masraf_personel ON personel_masraf(personel_id)",
        "CREATE INDEX IF NOT EXISTS idx_personel_belgeler_personel ON personel_belgeler(personel_id)",
        "CREATE INDEX IF NOT EXISTS idx_arac_belgeler_arac ON arac_belgeler(arac_id)",
        "CREATE INDEX IF NOT EXISTS idx_manlift_belgeler_manlift ON manlift_belgeler(manlift_id)",
        "CREATE INDEX IF NOT EXISTS idx_izin_talepleri_personel ON izin_talepleri(personel_id)",
        "CREATE INDEX IF NOT EXISTS idx_stok_hareket_parca ON stok_hareket(parca_id)",
        "CREATE INDEX IF NOT EXISTS idx_giderler_makine ON giderler(makine_tur, makine_id)",
        "ALTER TABLE yedek_parca ADD COLUMN barkod TEXT",
        "CREATE INDEX IF NOT EXISTS idx_yedek_parca_barkod ON yedek_parca(barkod)",
        "ALTER TABLE yedek_parca ADD COLUMN aktif INTEGER DEFAULT 1",
        "CREATE TABLE IF NOT EXISTS personel_checkin (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            personel_id     INTEGER NOT NULL,
            tip             TEXT NOT NULL,               -- giris | cikis
            tarih_saat      TEXT DEFAULT (datetime('now','localtime')),
            enlem           REAL,
            boylam          REAL,
            konum_dogruluk  REAL,                         -- tarayıcının bildirdiği GPS doğruluğu (metre)
            lokasyon_adi    TEXT,                          -- opsiyonel, personelin elle girdiği şantiye adı
            created_at      TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (personel_id) REFERENCES personel(id) ON DELETE CASCADE
        )",
        "CREATE INDEX IF NOT EXISTS idx_personel_checkin_personel ON personel_checkin(personel_id, tarih_saat)",
        "ALTER TABLE kiralamalar ADD COLUMN iskonto_oran REAL DEFAULT 0",
        "ALTER TABLE personel ADD COLUMN pin_kod TEXT",
        "ALTER TABLE personel ADD COLUMN qr_token TEXT",
        "CREATE INDEX IF NOT EXISTS idx_personel_qr_token ON personel(qr_token)",
        "CREATE TABLE IF NOT EXISTS checkin_attempts (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            personel_id  INTEGER NOT NULL,
            ip           TEXT NOT NULL,
            success      INTEGER DEFAULT 0,
            attempted_at TEXT DEFAULT (datetime('now','localtime'))
        )",
        "CREATE INDEX IF NOT EXISTS idx_checkin_attempts_lookup ON checkin_attempts(personel_id, ip, attempted_at)",
        "ALTER TABLE checkin_attempts ADD COLUMN kaynak TEXT DEFAULT 'checkin'",
        "ALTER TABLE kiralamalar ADD COLUMN onay_durumu TEXT DEFAULT 'gerekmiyor'",
        "ALTER TABLE kiralamalar ADD COLUMN onaylayan TEXT",
        "ALTER TABLE kiralamalar ADD COLUMN onay_tarihi TEXT",
        "CREATE TABLE IF NOT EXISTS iade_tutanaklari (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            kiralama_id         INTEGER NOT NULL,
            personel_id         INTEGER NOT NULL,
            islem               TEXT NOT NULL DEFAULT 'iade',
            tarih               TEXT NOT NULL,
            km                  REAL,
            calisma_saati       REAL,
            yakit_seviyesi      TEXT,
            genel_durum         TEXT NOT NULL DEFAULT 'saglam',
            aksesuarlar         TEXT,
            hasar_notu          TEXT,
            genel_not           TEXT,
            imza_personel       TEXT,
            imza_musteri        TEXT,
            musteri_temsilci_ad TEXT,
            enlem               REAL,
            boylam              REAL,
            created_at          TEXT DEFAULT (datetime('now','localtime')),
            -- personel_id FK'sı yok: dış şoför / kâğıt tutanağı personel_id=0 (karar K2)
            FOREIGN KEY (kiralama_id) REFERENCES kiralamalar(id) ON DELETE CASCADE
        )",
        "CREATE INDEX IF NOT EXISTS idx_iade_tutanak_kiralama ON iade_tutanaklari(kiralama_id)",
        "ALTER TABLE hakedisler ADD COLUMN tutanak_id INTEGER REFERENCES iade_tutanaklari(id) ON DELETE SET NULL",
        "CREATE TABLE IF NOT EXISTS iade_tutanak_fotograflari (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            tutanak_id  INTEGER NOT NULL,
            aci_tipi    TEXT,
            dosya_yolu  TEXT NOT NULL,
            created_at  TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (tutanak_id) REFERENCES iade_tutanaklari(id) ON DELETE CASCADE
        )",
        "CREATE INDEX IF NOT EXISTS idx_iade_tutanak_foto_tutanak ON iade_tutanak_fotograflari(tutanak_id)",
        "ALTER TABLE iade_tutanak_fotograflari ADD COLUMN aciklama TEXT",
        "ALTER TABLE iade_tutanaklari ADD COLUMN ai_analiz_sonucu TEXT",
        "ALTER TABLE iade_tutanaklari ADD COLUMN ai_analiz_durum TEXT",
        "ALTER TABLE iade_tutanaklari ADD COLUMN ai_analiz_tarihi TEXT",
        "ALTER TABLE iade_tutanaklari ADD COLUMN ai_analiz_json TEXT",
        "ALTER TABLE araclar ADD COLUMN yakit_sarfiyat_100km REAL DEFAULT 0",
        "ALTER TABLE araclar ADD COLUMN rolanti_birim_ucret_saat REAL DEFAULT 0",
        "ALTER TABLE araclar ADD COLUMN maliyet_amortisman_gun REAL DEFAULT 0",
        "ALTER TABLE araclar ADD COLUMN maliyet_bakim_gun REAL DEFAULT 0",
        "ALTER TABLE araclar ADD COLUMN maliyet_sigorta_gun REAL DEFAULT 0",
        "ALTER TABLE araclar ADD COLUMN maliyet_mtv_gun REAL DEFAULT 0",
        "CREATE TABLE IF NOT EXISTS arac_maliyet_hesap (
            id                      INTEGER PRIMARY KEY AUTOINCREMENT,
            makine_id               INTEGER NOT NULL,
            makine_tur              TEXT DEFAULT 'arac',
            musteri_id              INTEGER,
            baslik                  TEXT,
            tarih                   TEXT NOT NULL,
            yol_km                  REAL DEFAULT 0,
            calisma_saati           REAL DEFAULT 0,
            gun_sayisi              REAL DEFAULT 1,
            operator_sayisi         INTEGER DEFAULT 1,
            mazot_fiyati            REAL DEFAULT 0,
            yakit_sarfiyat_100km    REAL DEFAULT 0,
            rolanti_birim_ucret_saat REAL DEFAULT 0,
            amortisman_gun          REAL DEFAULT 0,
            bakim_gun               REAL DEFAULT 0,
            sigorta_gun             REAL DEFAULT 0,
            mtv_gun                 REAL DEFAULT 0,
            operator_maas_gun       REAL DEFAULT 0,
            operator_sgk_gun        REAL DEFAULT 0,
            operator_yemek_gun      REAL DEFAULT 0,
            operator_tazminat_gun   REAL DEFAULT 0,
            vergi_orani             REAL DEFAULT 0,
            kar_marji_orani         REAL DEFAULT 0,
            yol_yakit_maliyeti      REAL DEFAULT 0,
            rolanti_yakit_maliyeti  REAL DEFAULT 0,
            arac_gunluk_toplam      REAL DEFAULT 0,
            operator_gunluk_toplam  REAL DEFAULT 0,
            ara_toplam              REAL DEFAULT 0,
            vergi_tutari            REAL DEFAULT 0,
            toplam_maliyet          REAL DEFAULT 0,
            kar_tutari              REAL DEFAULT 0,
            onerilen_fiyat          REAL DEFAULT 0,
            notlar                  TEXT,
            created_at              TEXT DEFAULT (datetime('now','localtime')),
            updated_at              TEXT DEFAULT (datetime('now','localtime'))
        )",
        "CREATE INDEX IF NOT EXISTS idx_arac_maliyet_hesap_arac ON arac_maliyet_hesap(makine_id)",
        "CREATE INDEX IF NOT EXISTS idx_arac_maliyet_hesap_tarih ON arac_maliyet_hesap(tarih)",
        "ALTER TABLE arac_maliyet_hesap ADD COLUMN teklif_id INTEGER",
        "ALTER TABLE manliftler ADD COLUMN yakit_sarfiyat_100km REAL DEFAULT 0",
        "ALTER TABLE manliftler ADD COLUMN rolanti_birim_ucret_saat REAL DEFAULT 0",
        "ALTER TABLE manliftler ADD COLUMN maliyet_amortisman_gun REAL DEFAULT 0",
        "ALTER TABLE manliftler ADD COLUMN maliyet_bakim_gun REAL DEFAULT 0",
        "ALTER TABLE manliftler ADD COLUMN maliyet_sigorta_gun REAL DEFAULT 0",
        "ALTER TABLE manliftler ADD COLUMN maliyet_mtv_gun REAL DEFAULT 0",
        "ALTER TABLE arac_maliyet_hesap ADD COLUMN makine_tur TEXT DEFAULT 'arac'",
        "ALTER TABLE arac_maliyet_hesap RENAME COLUMN arac_id TO makine_id",
        "DROP TABLE IF EXISTS personel_avans",
        "ALTER TABLE araclar ADD COLUMN rolanti_litre_saat REAL DEFAULT 0",
        "ALTER TABLE manliftler ADD COLUMN rolanti_litre_saat REAL DEFAULT 0",
        "ALTER TABLE arac_maliyet_hesap ADD COLUMN rolanti_litre_saat REAL DEFAULT 0",
        "ALTER TABLE araclar ADD COLUMN mtv_yillik REAL DEFAULT 0",
        "ALTER TABLE manliftler ADD COLUMN mtv_yillik REAL DEFAULT 0",
        "ALTER TABLE manliftler ADD COLUMN sigorta_yillik REAL DEFAULT 0",
        "ALTER TABLE iade_tutanaklari ADD COLUMN kaynak TEXT DEFAULT 'saha'",
        "ALTER TABLE iade_tutanaklari ADD COLUMN kaydeden TEXT",
        "CREATE TABLE IF NOT EXISTS ariza_kodlari (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            marka        TEXT NOT NULL,
            model        TEXT DEFAULT '',      -- boş = markanın TÜM modelleri için geçerli
            kategori_kod TEXT DEFAULT '',      -- F01, SYS, BAT…
            kategori     TEXT DEFAULT '',      -- VARYATÖR, SİSTEM ARIZALARI…
            kod          TEXT NOT NULL,        -- F01.01, 10, 02…
            aciklama     TEXT NOT NULL,
            aktif        INTEGER DEFAULT 1,
            created_at   TEXT DEFAULT (datetime('now','localtime'))
        )",
        "CREATE INDEX IF NOT EXISTS idx_ariza_kodlari_marka ON ariza_kodlari(marka, model)",
        "ALTER TABLE ariza_kodlari ADD COLUMN arama TEXT",
        "ALTER TABLE iade_tutanaklari ADD COLUMN ariza_kodlari TEXT",

        "ALTER TABLE iade_tutanaklari ADD COLUMN belge_no TEXT",
        "ALTER TABLE iade_tutanaklari ADD COLUMN teslim_alan_firma TEXT",
        "ALTER TABLE iade_tutanaklari ADD COLUMN teslim_eden_firma TEXT",
        "ALTER TABLE iade_tutanaklari ADD COLUMN calisma_yeri TEXT",
        "ALTER TABLE iade_tutanaklari ADD COLUMN nakliye_plaka TEXT",
        "ALTER TABLE iade_tutanaklari ADD COLUMN ekspertiz_eden TEXT",
        "ALTER TABLE iade_tutanaklari ADD COLUMN yakit_turu TEXT",
        "ALTER TABLE iade_tutanaklari ADD COLUMN is_basi_tarih TEXT",
        "ALTER TABLE iade_tutanaklari ADD COLUMN is_basi_saat TEXT",
        "ALTER TABLE iade_tutanaklari ADD COLUMN is_sonu_tarih TEXT",
        "ALTER TABLE iade_tutanaklari ADD COLUMN is_sonu_saat TEXT",
        "ALTER TABLE iade_tutanaklari ADD COLUMN bilesen_hasar TEXT",
        "ALTER TABLE iade_tutanaklari ADD COLUMN operator_ad TEXT",
        "ALTER TABLE iade_tutanaklari ADD COLUMN operator_iletisim TEXT",
        "ALTER TABLE iade_tutanaklari ADD COLUMN operator_plaka TEXT",
        "ALTER TABLE iade_tutanaklari ADD COLUMN yetkili_iletisim TEXT",
        "ALTER TABLE iade_tutanaklari ADD COLUMN yetkili_plaka TEXT",
        "ALTER TABLE araclar ADD COLUMN temin_sekli TEXT DEFAULT 'stok'",
        "ALTER TABLE manliftler ADD COLUMN temin_sekli TEXT DEFAULT 'stok'",
        "ALTER TABLE araclar ADD COLUMN dis_kira_bedeli REAL DEFAULT 0",
        "ALTER TABLE manliftler ADD COLUMN dis_kira_bedeli REAL DEFAULT 0",
        "ALTER TABLE araclar ADD COLUMN tedarikci_id INTEGER",
        "ALTER TABLE manliftler ADD COLUMN tedarikci_id INTEGER",
        "CREATE TABLE IF NOT EXISTS dis_kiralama_kayitlari (id INTEGER PRIMARY KEY AUTOINCREMENT, makine_tur TEXT NOT NULL, makine_id INTEGER NOT NULL, tedarikci_id INTEGER NOT NULL, tedarikci_makine_kodu TEXT, tedarik_sozlesme_no TEXT, temin_baslangic TEXT NOT NULL, temin_bitis TEXT, maliyet_tipi TEXT NOT NULL DEFAULT 'aylik', maliyet_birim_tutari REAL NOT NULL DEFAULT 0, maliyet_para_birimi TEXT NOT NULL DEFAULT 'TL', depozito REAL DEFAULT 0, kdv_orani REAL DEFAULT 20, nakliye_gelis REAL DEFAULT 0, nakliye_iade REAL DEFAULT 0, min_kiralama_suresi REAL DEFAULT 0, avk_liste_fiyati REAL DEFAULT 0, avk_min_fiyati REAL DEFAULT 0, durum TEXT NOT NULL DEFAULT 'musait', sorumluluk_notu TEXT, notlar TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT DEFAULT (datetime('now','localtime')), updated_at TEXT DEFAULT (datetime('now','localtime')))",
        "CREATE TABLE IF NOT EXISTS analiz_sube_giderleri (id INTEGER PRIMARY KEY AUTOINCREMENT, ay TEXT NOT NULL, sube TEXT NOT NULL, kategori TEXT NOT NULL, tutar REAL NOT NULL, aciklama TEXT NOT NULL, created_by INTEGER, created_at TEXT DEFAULT (datetime('now','localtime')))",
        "ALTER TABLE analiz_sube_giderleri ADD COLUMN iptal_at TEXT",
        "CREATE INDEX IF NOT EXISTS idx_dis_kiralama_makine ON dis_kiralama_kayitlari(makine_tur, makine_id)",
        "CREATE INDEX IF NOT EXISTS idx_dis_kiralama_tarih ON dis_kiralama_kayitlari(temin_baslangic, temin_bitis)",
        "CREATE TABLE IF NOT EXISTS dis_kiralama_belgeler (id INTEGER PRIMARY KEY AUTOINCREMENT, dis_kiralama_id INTEGER NOT NULL, belge_turu TEXT NOT NULL DEFAULT 'tedarik_sozlesmesi', belge_adi TEXT NOT NULL, dosya_yolu TEXT NOT NULL, notlar TEXT, yukleyen INTEGER, created_at TEXT DEFAULT (datetime('now','localtime')), FOREIGN KEY(dis_kiralama_id) REFERENCES dis_kiralama_kayitlari(id) ON DELETE CASCADE)",
        "CREATE INDEX IF NOT EXISTS idx_dis_kiralama_belge ON dis_kiralama_belgeler(dis_kiralama_id)",
        "CREATE TABLE IF NOT EXISTS kiralama_dis_maliyetleri (id INTEGER PRIMARY KEY AUTOINCREMENT, kiralama_id INTEGER NOT NULL UNIQUE, dis_kiralama_id INTEGER NOT NULL, maliyet_tipi TEXT NOT NULL, maliyet_tutari REAL NOT NULL DEFAULT 0, para_birimi TEXT NOT NULL DEFAULT 'TL', kur REAL NOT NULL DEFAULT 1, nakliye_maliyeti REAL NOT NULL DEFAULT 0, snapshot_at TEXT DEFAULT (datetime('now','localtime')), FOREIGN KEY(kiralama_id) REFERENCES kiralamalar(id) ON DELETE CASCADE)",
        "CREATE TABLE IF NOT EXISTS sube_prim_kapanislari (id INTEGER PRIMARY KEY AUTOINCREMENT, ay TEXT NOT NULL, surum INTEGER NOT NULL DEFAULT 1, oran REAL NOT NULL DEFAULT 0.025, veri_json TEXT NOT NULL, toplam_prim REAL NOT NULL DEFAULT 0, gerekce TEXT, created_by INTEGER, created_at TEXT DEFAULT (datetime('now','localtime')), UNIQUE(ay,surum))",
        "CREATE INDEX IF NOT EXISTS idx_sube_prim_kapanis_ay ON sube_prim_kapanislari(ay)",
        "ALTER TABLE araclar ADD COLUMN makine_kodu TEXT",
        "ALTER TABLE manliftler ADD COLUMN makine_kodu TEXT",
        "ALTER TABLE araclar ADD COLUMN liste_fiyat_aylik REAL DEFAULT 0",
        "ALTER TABLE manliftler ADD COLUMN liste_fiyat_aylik REAL DEFAULT 0",

        "ALTER TABLE araclar ADD COLUMN alis_tarihi TEXT",
        "ALTER TABLE manliftler ADD COLUMN alis_tarihi TEXT",
            "ALTER TABLE manliftler ADD COLUMN kategori TEXT",
            "ALTER TABLE manliftler ADD COLUMN ozellikler TEXT",
            "ALTER TABLE araclar ADD COLUMN kategori TEXT",
            "ALTER TABLE araclar ADD COLUMN ozellikler TEXT",
            "ALTER TABLE araclar ADD COLUMN metraj TEXT",
    
        "ALTER TABLE kiralamalar ADD COLUMN metraj REAL",
        "ALTER TABLE kiralamalar ADD COLUMN seri_no TEXT",
        "ALTER TABLE kiralamalar ADD COLUMN makine_kodu TEXT",
        "ALTER TABLE kiralamalar ADD COLUMN odeme_turu TEXT",
        "ALTER TABLE kiralamalar ADD COLUMN vade_gun INTEGER DEFAULT 0",
        "ALTER TABLE kiralamalar ADD COLUMN il TEXT",
        "ALTER TABLE kiralamalar ADD COLUMN doviz TEXT DEFAULT 'TL'",
        "ALTER TABLE kiralamalar ADD COLUMN ikame_makine_id INTEGER",
        "ALTER TABLE kiralamalar ADD COLUMN ikame_not TEXT",

        "CREATE TABLE IF NOT EXISTS islem_log (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            tarih         TEXT    DEFAULT (datetime('now','localtime')),
            kullanici_id  INTEGER,
            kullanici_ad  TEXT,
            tablo         TEXT    NOT NULL,
            kayit_id      INTEGER,
            eylem         TEXT    NOT NULL,          -- ekle | guncelle | sil
            ozet          TEXT,                      -- insan-okunur tek satır
            degisenler    TEXT,                      -- JSON: {alan: [eski, yeni]}
            ip            TEXT
        )",
        "CREATE INDEX IF NOT EXISTS idx_islem_log_kayit ON islem_log(tablo, kayit_id)",
        "CREATE INDEX IF NOT EXISTS idx_islem_log_tarih ON islem_log(tarih DESC)",
        "CREATE INDEX IF NOT EXISTS idx_islem_log_kullanici ON islem_log(kullanici_id, tarih DESC)",
        "CREATE TABLE IF NOT EXISTS islem_kilidi (k INTEGER)",

        "CREATE TABLE IF NOT EXISTS eposta_log (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            tarih     TEXT    DEFAULT (datetime('now','localtime')),
            alici     TEXT,
            konu      TEXT,
            yontem    TEXT,                       -- smtp | mail | yok
            basarili  INTEGER DEFAULT 0,
            hata      TEXT,
            baglam    TEXT                        -- sifre_sirlama | bildirim | musteri_hatirlatma | gunluk_ozet | test
        )",
        "CREATE INDEX IF NOT EXISTS idx_eposta_log_tarih ON eposta_log(tarih DESC)",

        "ALTER TABLE personel_checkin ADD COLUMN istemci_anahtari TEXT",
        "CREATE UNIQUE INDEX IF NOT EXISTS idx_checkin_istemci ON personel_checkin(istemci_anahtari) WHERE istemci_anahtari IS NOT NULL",
        "ALTER TABLE iade_tutanaklari ADD COLUMN istemci_anahtari TEXT",
        "CREATE UNIQUE INDEX IF NOT EXISTS idx_tutanak_istemci ON iade_tutanaklari(istemci_anahtari) WHERE istemci_anahtari IS NOT NULL",

        "ALTER TABLE teknik_servis ADD COLUMN tutanak_id INTEGER",
        "ALTER TABLE teknik_servis ADD COLUMN kiralama_id INTEGER",

        "ALTER TABLE musteriler ADD COLUMN wolvox_cari_kodu TEXT",
        "ALTER TABLE nakliyeler ADD COLUMN tip TEXT DEFAULT 'dis'",
        "ALTER TABLE nakliyeler ADD COLUMN odeme_turu TEXT",
        "ALTER TABLE nakliyeler ADD COLUMN vade_gun INTEGER DEFAULT 0",
        "ALTER TABLE nakliyeler ADD COLUMN vade_tarihi TEXT",
        "ALTER TABLE nakliyeler ADD COLUMN kdv_oran REAL DEFAULT 20",
        "ALTER TABLE nakliyeler ADD COLUMN tevkifat_var INTEGER DEFAULT 0",
        "ALTER TABLE nakliyeler ADD COLUMN tevkifat_orani TEXT",
        "ALTER TABLE nakliyeler ADD COLUMN tevkifat_tutari REAL DEFAULT 0",
        "ALTER TABLE kiralamalar ADD COLUMN nakliye_tip TEXT DEFAULT 'dis'",
        "ALTER TABLE kiralamalar ADD COLUMN nakliye_yon TEXT DEFAULT 'gidis_donus'",
        "ALTER TABLE kiralamalar ADD COLUMN nakliyeci TEXT",
        "ALTER TABLE kiralamalar ADD COLUMN fatura_tipi TEXT DEFAULT 'resmi'",
        "ALTER TABLE teknik_servis ADD COLUMN fatura_tipi TEXT DEFAULT 'resmi'",
        "ALTER TABLE teknik_servis ADD COLUMN faturalandi INTEGER DEFAULT 0",
        "ALTER TABLE teknik_servis ADD COLUMN fatura_no TEXT",
        "ALTER TABLE teknik_servis ADD COLUMN fatura_tarihi TEXT",
        "ALTER TABLE teknik_servis ADD COLUMN musteriye_yansit INTEGER DEFAULT 0",
        "CREATE TABLE IF NOT EXISTS santiyeler (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            musteri_id  INTEGER,
            ad          TEXT NOT NULL,
            adres       TEXT,
            il          TEXT,
            ilce        TEXT,
            yetkili     TEXT,
            telefon     TEXT,
            notlar      TEXT,
            aktif       INTEGER DEFAULT 1,
            kullanim    INTEGER DEFAULT 0,
            son_kullanim TEXT,
            created_at  TEXT DEFAULT (datetime('now','localtime'))
        )",
        "CREATE INDEX IF NOT EXISTS idx_santiye_musteri ON santiyeler(musteri_id, aktif)",
        "CREATE TABLE IF NOT EXISTS kiralama_fotograflari (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            kiralama_id INTEGER NOT NULL,
            aci_tipi    TEXT,
            aciklama    TEXT,
            dosya_yolu  TEXT NOT NULL,
            created_at  TEXT DEFAULT (datetime('now','localtime'))
        )",
        "CREATE INDEX IF NOT EXISTS idx_kiralama_foto ON kiralama_fotograflari(kiralama_id)",
        "ALTER TABLE musteriler ADD COLUMN acilis_bakiye REAL DEFAULT 0",
        "ALTER TABLE musteriler ADD COLUMN acilis_tarihi TEXT",
        "CREATE INDEX IF NOT EXISTS idx_teknik_servis_tutanak ON teknik_servis(tutanak_id)",

        "ALTER TABLE personel ADD COLUMN sube TEXT",
        "CREATE TABLE IF NOT EXISTS mesailer (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            personel_id    INTEGER NOT NULL,
            tarih          TEXT NOT NULL,
            tip            TEXT NOT NULL DEFAULT 'normal',
            baslangic_saat TEXT,
            bitis_saat     TEXT,
            saat           REAL DEFAULT 0,
            calisma_yeri   TEXT,
            makine_turu    TEXT,
            aciklama       TEXT,
            durum          TEXT DEFAULT 'taslak',
            finans_ay      TEXT,
            finans_tarih   TEXT,
            olusturan      TEXT,
            created_at     TEXT DEFAULT (datetime('now','localtime')),
            updated_at     TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (personel_id) REFERENCES personel(id) ON DELETE CASCADE
        )",
        "CREATE INDEX IF NOT EXISTS idx_mesai_donem ON mesailer(tarih, personel_id)",
        "CREATE INDEX IF NOT EXISTS idx_mesai_durum ON mesailer(durum)",
        "CREATE TABLE IF NOT EXISTS mesai_belgeler (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            mesai_id   INTEGER NOT NULL,
            belge_adi  TEXT NOT NULL,
            dosya_yolu TEXT NOT NULL,
            created_at TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (mesai_id) REFERENCES mesailer(id) ON DELETE CASCADE
        )",
        "CREATE INDEX IF NOT EXISTS idx_mesai_belge ON mesai_belgeler(mesai_id)",
        "ALTER TABLE mesailer ADD COLUMN izin_hakki INTEGER DEFAULT 0",
        "ALTER TABLE mesailer ADD COLUMN izin_talep_id INTEGER",
        "ALTER TABLE mesailer ADD COLUMN bitis_tarih TEXT",
        "ALTER TABLE finans ADD COLUMN konaklama_gun REAL DEFAULT 0",
        "ALTER TABLE izin_talepleri ADD COLUMN kaynak TEXT",
        "ALTER TABLE izin_talepleri ADD COLUMN kaynak_mesai_id INTEGER",

        "CREATE TABLE IF NOT EXISTS kiralama_belgeler (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            kiralama_id  INTEGER NOT NULL,
            belge_turu   TEXT NOT NULL DEFAULT 'kira_formu',
            form_no      TEXT,
            form_tarihi  TEXT,
            belge_adi    TEXT NOT NULL,
            dosya_yolu   TEXT NOT NULL,
            notlar       TEXT,
            yukleyen     TEXT,
            created_at   TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (kiralama_id) REFERENCES kiralamalar(id) ON DELETE CASCADE
        )",
        "CREATE INDEX IF NOT EXISTS idx_kiralama_belge ON kiralama_belgeler(kiralama_id)",

        "CREATE TABLE IF NOT EXISTS genel_servis (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            makine_tur    TEXT NOT NULL,
            makine_id     INTEGER NOT NULL,
            tarih         TEXT NOT NULL,
            tur           TEXT NOT NULL DEFAULT 'genel_bakim',
            yapilan       TEXT,
            km_saat       REAL,
            maliyet       REAL DEFAULT 0,
            servis_firma  TEXT,
            sonraki_tarih TEXT,
            sonraki_km    REAL,
            hatirlat_gun  INTEGER DEFAULT 0,
            notlar        TEXT,
            olusturan     TEXT,
            created_at    TEXT DEFAULT (datetime('now','localtime')),
            updated_at    TEXT DEFAULT (datetime('now','localtime'))
        )",
        "CREATE INDEX IF NOT EXISTS idx_genel_servis_makine ON genel_servis(makine_tur, makine_id, tarih)",
        "CREATE INDEX IF NOT EXISTS idx_genel_servis_sonraki ON genel_servis(sonraki_tarih)",
        "CREATE TABLE IF NOT EXISTS genel_servis_belgeler (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            servis_id  INTEGER NOT NULL,
            belge_adi  TEXT NOT NULL,
            dosya_yolu TEXT NOT NULL,
            created_at TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (servis_id) REFERENCES genel_servis(id) ON DELETE CASCADE
        )",
        "CREATE INDEX IF NOT EXISTS idx_genel_servis_belge ON genel_servis_belgeler(servis_id)",
        "CREATE TABLE IF NOT EXISTS genel_servis_kalemleri (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            servis_id  INTEGER NOT NULL,
            ad         TEXT NOT NULL,
            kod        TEXT,
            marka      TEXT,
            model      TEXT,
            tutar      REAL DEFAULT 0,
            created_at TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (servis_id) REFERENCES genel_servis(id) ON DELETE CASCADE
        )",
        "CREATE INDEX IF NOT EXISTS idx_genel_servis_kalem ON genel_servis_kalemleri(servis_id)",

        "ALTER TABLE users ADD COLUMN totp_secret TEXT",
        "ALTER TABLE users ADD COLUMN totp_aktif INTEGER DEFAULT 0",
        "ALTER TABLE users ADD COLUMN totp_yedek TEXT",
        "ALTER TABLE users ADD COLUMN totp_kuruldu TEXT",
        "ALTER TABLE users ADD COLUMN sifre_degisti TEXT",
        "ALTER TABLE users ADD COLUMN son_giris TEXT",
        "ALTER TABLE users ADD COLUMN son_giris_ip TEXT",
        "ALTER TABLE users ADD COLUMN totp_son_adim INTEGER",
        "ALTER TABLE form_paylasim ADD COLUMN expires_at TEXT",
        "ALTER TABLE form_paylasim ADD COLUMN goruntulenme INTEGER DEFAULT 0",
        "ALTER TABLE form_paylasim ADD COLUMN iptal INTEGER DEFAULT 0",
        "ALTER TABLE form_paylasim ADD COLUMN olusturan TEXT",
        "ALTER TABLE kiralamalar ADD COLUMN kaynak_teklif_id INTEGER REFERENCES teklifler(id) ON DELETE SET NULL",
        "CREATE INDEX IF NOT EXISTS idx_kiralama_kaynak_teklif ON kiralamalar(kaynak_teklif_id)",
        "UPDATE users SET yetkiler = yetkiler || ',mesai'
            WHERE yetkiler IS NOT NULL AND yetkiler <> '' AND yetkiler <> 'tum'
              AND (',' || yetkiler || ',') LIKE '%,finans,%'
              AND (',' || yetkiler || ',') NOT LIKE '%,mesai,%'
              AND NOT EXISTS (SELECT 1 FROM ayarlar WHERE anahtar='gecis_mesai_yetkisi')",
        "UPDATE users SET yetkiler = yetkiler || ',mesai:r'
            WHERE yetkiler IS NOT NULL AND yetkiler <> '' AND yetkiler <> 'tum'
              AND (',' || yetkiler || ',') LIKE '%,finans:r,%'
              AND (',' || yetkiler || ',') NOT LIKE '%,mesai%'
              AND NOT EXISTS (SELECT 1 FROM ayarlar WHERE anahtar='gecis_mesai_yetkisi')",
        "INSERT OR IGNORE INTO ayarlar (anahtar, deger) VALUES ('gecis_mesai_yetkisi','1')",

        "ALTER TABLE finans RENAME COLUMN pazar_mesai_saat TO pazar_mesai_gun",

        "ALTER TABLE users ADD COLUMN personel_id INTEGER REFERENCES personel(id) ON DELETE SET NULL",
        "CREATE INDEX IF NOT EXISTS idx_users_personel ON users(personel_id)",
        "ALTER TABLE personel ADD COLUMN amir_id INTEGER REFERENCES personel(id) ON DELETE SET NULL",
        "CREATE INDEX IF NOT EXISTS idx_personel_amir ON personel(amir_id)",

        "ALTER TABLE kiralama_puantaj ADD COLUMN akis_durumu TEXT",
        "ALTER TABLE kiralama_puantaj ADD COLUMN gorulme_zamani TEXT",
        "ALTER TABLE kiralama_puantaj ADD COLUMN yola_cikis_zamani TEXT",
        "ALTER TABLE kiralama_puantaj ADD COLUMN sahaya_varis_zamani TEXT",
        "ALTER TABLE kiralama_puantaj ADD COLUMN calisma_baslangic TEXT",
        "ALTER TABLE kiralama_puantaj ADD COLUMN calisma_bitis TEXT",
        "ALTER TABLE kiralama_puantaj ADD COLUMN on_kontrol_json TEXT",
        "ALTER TABLE kiralama_puantaj ADD COLUMN on_kontrol_tamam INTEGER DEFAULT 0",
        "ALTER TABLE kiralama_puantaj ADD COLUMN bekleme_saat REAL DEFAULT 0",
        "ALTER TABLE kiralama_puantaj ADD COLUMN bekleme_nedeni TEXT",
        "ALTER TABLE kiralama_puantaj ADD COLUMN bekleme_aciklama TEXT",
        "ALTER TABLE kiralama_puantaj ADD COLUMN is_aciklamasi TEXT",
        "ALTER TABLE kiralama_puantaj ADD COLUMN sonuc_notu TEXT",
        "ALTER TABLE kiralama_puantaj ADD COLUMN ariza_bildirimi INTEGER DEFAULT 0",
        "ALTER TABLE kiralama_puantaj ADD COLUMN ariza_aciklama TEXT",
        "ALTER TABLE kiralama_puantaj ADD COLUMN devir_notu TEXT",
        "ALTER TABLE kiralama_puantaj ADD COLUMN istemci_anahtari TEXT",
        "CREATE INDEX IF NOT EXISTS idx_puantaj_istemci_anahtari ON kiralama_puantaj(istemci_anahtari)",
        "CREATE INDEX IF NOT EXISTS idx_puantaj_akis ON kiralama_puantaj(akis_durumu, tarih)",

        "CREATE TABLE IF NOT EXISTS kiralama_puantaj_foto (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            puantaj_id  INTEGER NOT NULL,
            etiket      TEXT,
            dosya_yolu  TEXT NOT NULL,
            created_at  TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (puantaj_id) REFERENCES kiralama_puantaj(id) ON DELETE CASCADE
        )",
        "CREATE INDEX IF NOT EXISTS idx_puantaj_foto_puantaj ON kiralama_puantaj_foto(puantaj_id)",

        "ALTER TABLE teknik_servis ADD COLUMN kaynak TEXT DEFAULT 'manuel'",
        "ALTER TABLE kiralama_puantaj ADD COLUMN ariza_servis_id INTEGER",
        "ALTER TABLE satinalma_kalemleri ADD COLUMN teknik_servis_id INTEGER",
        "CREATE INDEX IF NOT EXISTS idx_satinalma_kalem_teknik_servis ON satinalma_kalemleri(teknik_servis_id)",
        "ALTER TABLE satinalma_kalemleri ADD COLUMN sira INTEGER",
        "ALTER TABLE satinalma_kalemleri ADD COLUMN stok_kodu TEXT",
        "ALTER TABLE satinalma_kalemleri ADD COLUMN birim TEXT DEFAULT 'adet'",
        "ALTER TABLE satinalma_kalemleri ADD COLUMN notlar TEXT",
        "ALTER TABLE satinalma_kalemleri ADD COLUMN stok_disi INTEGER DEFAULT 0",
        "ALTER TABLE satinalma_kalemleri ADD COLUMN faturalanan REAL DEFAULT 0",
        "ALTER TABLE satinalma_kalemleri ADD COLUMN son_birim_fiyat REAL",
        "ALTER TABLE satinalma_kalemleri ADD COLUMN son_kdv_oran REAL",
        "ALTER TABLE satinalma_kalemleri ADD COLUMN aktarildi INTEGER DEFAULT 0",
        "CREATE TABLE IF NOT EXISTS satinalma_faturalari (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            siparis_id    INTEGER NOT NULL,
            tedarikci_id  INTEGER,
            fatura_no     TEXT,
            fatura_tarihi TEXT,
            ara_toplam    REAL DEFAULT 0,
            kdv_toplam    REAL DEFAULT 0,
            genel_toplam  REAL DEFAULT 0,
            notlar        TEXT,
            created_at    TEXT DEFAULT (datetime('now','localtime')),
            yapan         TEXT,
            FOREIGN KEY (siparis_id) REFERENCES satinalma_siparisleri(id) ON DELETE CASCADE
        )",
        "CREATE INDEX IF NOT EXISTS idx_satinalma_fatura_siparis ON satinalma_faturalari(siparis_id)",
        "CREATE TABLE IF NOT EXISTS satinalma_fatura_kalemleri (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            fatura_id        INTEGER NOT NULL,
            kalem_id         INTEGER NOT NULL,
            miktar           REAL DEFAULT 0,
            birim_fiyat      REAL DEFAULT 0,
            kdv_oran         REAL DEFAULT 0,
            kdv_tutar        REAL DEFAULT 0,
            mal_hizmet_tutar REAL DEFAULT 0,
            toplam           REAL DEFAULT 0,
            FOREIGN KEY (fatura_id) REFERENCES satinalma_faturalari(id) ON DELETE CASCADE,
            FOREIGN KEY (kalem_id) REFERENCES satinalma_kalemleri(id) ON DELETE CASCADE
        )",
        "CREATE INDEX IF NOT EXISTS idx_satinalma_fk_fatura ON satinalma_fatura_kalemleri(fatura_id)",
        "CREATE INDEX IF NOT EXISTS idx_satinalma_fk_kalem ON satinalma_fatura_kalemleri(kalem_id)",
        "CREATE TABLE IF NOT EXISTS satinalma_gonderimleri (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            siparis_id  INTEGER NOT NULL,
            kanal       TEXT NOT NULL,
            alici       TEXT,
            zaman       TEXT DEFAULT (datetime('now','localtime')),
            yapan       TEXT,
            FOREIGN KEY (siparis_id) REFERENCES satinalma_siparisleri(id) ON DELETE CASCADE
        )",
        "CREATE INDEX IF NOT EXISTS idx_satinalma_gonderim_siparis ON satinalma_gonderimleri(siparis_id)",
        "ALTER TABLE yedek_parca ADD COLUMN alis_fiyati REAL",
        "ALTER TABLE yedek_parca ADD COLUMN son_tedarikci_id INTEGER",
        "ALTER TABLE yedek_parca ADD COLUMN son_alis_tarihi TEXT",
        "ALTER TABLE yedek_parca ADD COLUMN birim TEXT",

        "ALTER TABLE kiralama_fotograflari ADD COLUMN yon TEXT DEFAULT 'gidis'",
        "ALTER TABLE kiralamalar ADD COLUMN nakliye_maliyet REAL DEFAULT 0",

        "ALTER TABLE kiralamalar ADD COLUMN iptal_nedeni TEXT",
        "ALTER TABLE kiralamalar ADD COLUMN iptal_tarihi TEXT",

        "ALTER TABLE gorevler ADD COLUMN kaynak_modul TEXT",
        "ALTER TABLE gorevler ADD COLUMN kaynak_id INTEGER",
        "CREATE INDEX IF NOT EXISTS idx_gorev_kaynak ON gorevler(kaynak_modul, kaynak_id)",

        "ALTER TABLE hakedisler ADD COLUMN revizyon_nedeni TEXT",
        "ALTER TABLE hakedisler ADD COLUMN revizyon_tarihi TEXT",
        "ALTER TABLE hakedisler ADD COLUMN fatura_kaynak TEXT",
        "ALTER TABLE gorevler ADD COLUMN kapanis TEXT",
        "ALTER TABLE hakedisler ADD COLUMN para_birimi TEXT DEFAULT 'TL'",
        "ALTER TABLE hakedisler ADD COLUMN doviz_tutar REAL",
        "ALTER TABLE hakedisler ADD COLUMN doviz_kdv REAL",
        "ALTER TABLE hakedisler ADD COLUMN doviz_genel REAL",
        "ALTER TABLE hakedisler ADD COLUMN kur REAL",
        "ALTER TABLE hakedisler ADD COLUMN kur_tarihi TEXT",
        "ALTER TABLE hakedisler ADD COLUMN kur_kesin INTEGER DEFAULT 0",
        "ALTER TABLE kiralamalar ADD COLUMN fatura_durum TEXT",
        "ALTER TABLE teknik_servis ADD COLUMN fatura_durum TEXT",

        "CREATE TABLE IF NOT EXISTS musteri_kontaklari (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            musteri_id  INTEGER NOT NULL REFERENCES musteriler(id) ON DELETE CASCADE,
            ad_soyad    TEXT NOT NULL,
            email       TEXT NOT NULL UNIQUE,
            telefon     TEXT DEFAULT '',
            sifre_hash  TEXT DEFAULT NULL,
            durum       TEXT NOT NULL DEFAULT 'davetli',
            son_giris   TEXT DEFAULT NULL,
            created_at  TEXT DEFAULT (datetime('now','localtime')),
            updated_at  TEXT DEFAULT (datetime('now','localtime'))
        )",
        "CREATE TABLE IF NOT EXISTS musteri_kontak_token (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            kontak_id  INTEGER NOT NULL REFERENCES musteri_kontaklari(id) ON DELETE CASCADE,
            amac       TEXT NOT NULL DEFAULT 'davet',
            token      TEXT UNIQUE NOT NULL,
            expires_at TEXT NOT NULL,
            used       INTEGER DEFAULT 0,
            created_at TEXT DEFAULT (datetime('now','localtime'))
        )",
        "CREATE TABLE IF NOT EXISTS musteri_talepleri (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            musteri_id   INTEGER NOT NULL,
            kontak_id    INTEGER NOT NULL,
            ihtiyac_json TEXT NOT NULL,
            notlar       TEXT DEFAULT '',
            created_at   TEXT DEFAULT (datetime('now','localtime'))
        )",
        "CREATE TABLE IF NOT EXISTS portal_login_attempts (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            email        TEXT NOT NULL,
            ip           TEXT NOT NULL,
            success      INTEGER DEFAULT 0,
            attempted_at TEXT DEFAULT (datetime('now','localtime'))
        )",

        "ALTER TABLE nakliyeler ADD COLUMN sofor_adi TEXT",
        "ALTER TABLE nakliyeler ADD COLUMN arac_id INTEGER REFERENCES araclar(id) ON DELETE SET NULL",
        "ALTER TABLE nakliyeler ADD COLUMN planlanan_tarih TEXT",
        "ALTER TABLE nakliyeler ADD COLUMN gerceklesen_tarih TEXT",
        "ALTER TABLE nakliyeler ADD COLUMN km_baslangic REAL",
        "ALTER TABLE nakliyeler ADD COLUMN km_bitis REAL",
        "ALTER TABLE nakliyeler ADD COLUMN teslim_kanit_dosya TEXT",

        "CREATE TABLE IF NOT EXISTS servis_kontrol_sablonlari (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            ad         TEXT NOT NULL,
            makine_tur TEXT,                          -- arac | manlift | NULL (her ikisi)
            aktif      INTEGER DEFAULT 1,
            created_at TEXT DEFAULT (datetime('now','localtime')),
            updated_at TEXT DEFAULT (datetime('now','localtime'))
        )",
        "CREATE TABLE IF NOT EXISTS servis_kontrol_sablon_kalemleri (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            sablon_id INTEGER NOT NULL REFERENCES servis_kontrol_sablonlari(id) ON DELETE CASCADE,
            sira      INTEGER DEFAULT 0,
            kategori  TEXT DEFAULT '',                -- ör. Motor, Hidrolik, Elektrik (opsiyonel gruplama)
            madde     TEXT NOT NULL,
            created_at TEXT DEFAULT (datetime('now','localtime'))
        )",
        "CREATE INDEX IF NOT EXISTS idx_skk_sablon ON servis_kontrol_sablon_kalemleri(sablon_id, sira)",
        "ALTER TABLE teknik_servis ADD COLUMN kontrol_sablon_id INTEGER",
        "ALTER TABLE teknik_servis ADD COLUMN kontrol_sablon_adi TEXT",
        "CREATE TABLE IF NOT EXISTS servis_kontrol_sonuclari (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            teknik_servis_id INTEGER NOT NULL REFERENCES teknik_servis(id) ON DELETE CASCADE,
            kalem_id       INTEGER NOT NULL,
            madde          TEXT NOT NULL,              -- anlık görüntü (kalem sonradan değişse/silinse de kayıt bozulmasın)
            kategori       TEXT DEFAULT '',
            durum          TEXT NOT NULL DEFAULT 'nk',  -- uygun | uygun_degil | nk (uygulanamaz/kontrol edilmedi)
            not_metin      TEXT DEFAULT '',
            created_at     TEXT DEFAULT (datetime('now','localtime'))
        )",
        "CREATE INDEX IF NOT EXISTS idx_sks_servis ON servis_kontrol_sonuclari(teknik_servis_id)",

        "ALTER TABLE musteriler ADD COLUMN vergi_turu TEXT",
        "ALTER TABLE musteriler ADD COLUMN wolvox_senkron_durum TEXT DEFAULT 'kontrol_edilmedi'",
        "ALTER TABLE musteriler ADD COLUMN wolvox_senkron_tarihi TEXT",
        "ALTER TABLE musteriler ADD COLUMN il TEXT",
        "ALTER TABLE kiralamalar ADD COLUMN fatura_hata_notu TEXT",
        "ALTER TABLE teknik_servis ADD COLUMN fatura_hata_notu TEXT",

        "ALTER TABLE personel_belgeler ADD COLUMN onay_durumu TEXT DEFAULT 'onaylandi'",
        "ALTER TABLE personel ADD COLUMN meslek TEXT",

        "ALTER TABLE kiralamalar ADD COLUMN siparis_no TEXT",
        "CREATE INDEX IF NOT EXISTS idx_kiralamalar_siparis_no ON kiralamalar(siparis_no)",
        "ALTER TABLE nakliyeler ADD COLUMN siparis_no TEXT",
        "CREATE INDEX IF NOT EXISTS idx_nakliyeler_siparis_no ON nakliyeler(siparis_no)",
        "CREATE TABLE IF NOT EXISTS nakliye_makineleri (
            nakliye_id  INTEGER NOT NULL REFERENCES nakliyeler(id) ON DELETE CASCADE,
            kiralama_id INTEGER NOT NULL REFERENCES kiralamalar(id) ON DELETE CASCADE,
            PRIMARY KEY (nakliye_id, kiralama_id)
        )",
        "CREATE INDEX IF NOT EXISTS idx_nakliye_makineleri_kiralama ON nakliye_makineleri(kiralama_id)",
        "CREATE TABLE IF NOT EXISTS hakedis_kalemleri (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            hakedis_id  INTEGER NOT NULL REFERENCES hakedisler(id) ON DELETE CASCADE,
            tur         TEXT NOT NULL,              -- kira | operator | nakliye | diger
            aciklama    TEXT,
            miktar      REAL DEFAULT 1,
            birim       REAL DEFAULT 0,
            tutar       REAL NOT NULL DEFAULT 0,    -- KDV hariç, hakedişin para biriminde
            nakliye_id  INTEGER REFERENCES nakliyeler(id) ON DELETE SET NULL,
            uyari       TEXT
        )",
        "CREATE INDEX IF NOT EXISTS idx_hakedis_kalem ON hakedis_kalemleri(hakedis_id)",
        "ALTER TABLE nakliyeler ADD COLUMN hakedis_id INTEGER REFERENCES hakedisler(id) ON DELETE SET NULL",
        "ALTER TABLE nakliyeler ADD COLUMN fiyat_bekliyor INTEGER DEFAULT 0",
        "CREATE TABLE IF NOT EXISTS ai_kullanim (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            tarih          TEXT NOT NULL,
            user_id        INTEGER,
            islem          TEXT NOT NULL,
            model          TEXT,
            girdi_token    INTEGER DEFAULT 0,
            cikti_token    INTEGER DEFAULT 0,
            onbellek_token INTEGER DEFAULT 0,
            durum          TEXT DEFAULT 'tamam'
        )",
        "CREATE INDEX IF NOT EXISTS idx_ai_kullanim_user_tarih ON ai_kullanim(user_id, tarih)",
        "ALTER TABLE subeler ADD COLUMN il TEXT",
        "ALTER TABLE subeler ADD COLUMN adres TEXT",
        "ALTER TABLE subeler ADD COLUMN telefon TEXT",
        "ALTER TABLE subeler ADD COLUMN eposta TEXT",
        "ALTER TABLE subeler ADD COLUMN belgede_goster INTEGER DEFAULT 1",
        "ALTER TABLE subeler ADD COLUMN sira INTEGER DEFAULT 0",
        "CREATE TABLE IF NOT EXISTS portal_kiralama_talepleri (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            kiralama_id   INTEGER NOT NULL,
            musteri_id    INTEGER NOT NULL,
            kontak_id     INTEGER,
            tur           TEXT NOT NULL,
            istenen_tarih TEXT NOT NULL,
            eski_bitis    TEXT,
            onaylanan_tarih TEXT,
            adres         TEXT,
            aciklama      TEXT,
            durum         TEXT NOT NULL DEFAULT 'bekliyor',
            karar_veren   TEXT,
            karar_notu    TEXT,
            karar_tarihi  TEXT,
            created_at    TEXT DEFAULT (datetime('now','localtime'))
        )",
        "CREATE INDEX IF NOT EXISTS idx_pkt_kiralama ON portal_kiralama_talepleri(kiralama_id, durum)",
        "CREATE INDEX IF NOT EXISTS idx_pkt_musteri ON portal_kiralama_talepleri(musteri_id, created_at)",
        "CREATE TABLE IF NOT EXISTS teknik_servis_fotograflari (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            servis_id   INTEGER NOT NULL,
            dosya_yolu  TEXT NOT NULL,
            kaynak      TEXT NOT NULL DEFAULT 'portal',
            created_at  TEXT DEFAULT (datetime('now','localtime'))
        )",
        "CREATE INDEX IF NOT EXISTS idx_tsf_servis ON teknik_servis_fotograflari(servis_id)",
        "ALTER TABLE teklifler ADD COLUMN musteri_karar TEXT",
        "ALTER TABLE teklifler ADD COLUMN musteri_karar_kisi TEXT",
        "ALTER TABLE teklifler ADD COLUMN musteri_karar_tarihi TEXT",
        "ALTER TABLE teklifler ADD COLUMN musteri_karar_notu TEXT",
        "ALTER TABLE musteri_kontaklari ADD COLUMN sifre_degisti TEXT",
        "ALTER TABLE musteri_kontaklari ADD COLUMN wa_izin INTEGER DEFAULT 0",
        "ALTER TABLE musteri_kontaklari ADD COLUMN wa_izin_tarihi TEXT",
        "ALTER TABLE teknik_servis ADD COLUMN portal_kontak_id INTEGER",
        "CREATE TABLE IF NOT EXISTS whatsapp_log (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            olay            TEXT NOT NULL,
            alici           TEXT NOT NULL,
            kanal           TEXT NOT NULL DEFAULT 'cloud',
            musteri_id      INTEGER,
            kontak_id       INTEGER,
            telefon         TEXT,
            mesaj           TEXT,
            durum           TEXT NOT NULL DEFAULT 'bekliyor',
            hata            TEXT,
            mesaj_id        TEXT,
            created_at      TEXT DEFAULT (datetime('now','localtime')),
            gonderim_tarihi TEXT
        )",
        "CREATE INDEX IF NOT EXISTS idx_walog_tarih ON whatsapp_log(created_at)",
        "UPDATE hakedisler SET kod='HKD-' || substr(COALESCE(NULLIF(fatura_tarihi,''), NULLIF(donem_bit,''), created_at, date('now')),1,4) || '-' || printf('%04d', id) WHERE kod IS NULL OR kod=''",
        "ALTER TABLE teklif_kalemleri ADD COLUMN kalem_turu TEXT DEFAULT 'serbest'",
        "ALTER TABLE teklif_kalemleri ADD COLUMN kategori TEXT",
        "ALTER TABLE teklif_kalemleri ADD COLUMN metraj REAL",
        "ALTER TABLE teklifler ADD COLUMN planlanan_baslangic TEXT",
        "ALTER TABLE araclar ADD COLUMN nakliye_araci INTEGER DEFAULT 0",
        "ALTER TABLE teklifler ADD COLUMN sartlar TEXT",
        "ALTER TABLE personel_belgeler ADD COLUMN belge_no TEXT",
        "ALTER TABLE personel_belgeler ADD COLUMN belge_tarihi TEXT",
        "ALTER TABLE arac_belgeler ADD COLUMN belge_no TEXT",
        "ALTER TABLE arac_belgeler ADD COLUMN belge_tarihi TEXT",
        "ALTER TABLE manlift_belgeler ADD COLUMN belge_no TEXT",
        "ALTER TABLE manlift_belgeler ADD COLUMN belge_tarihi TEXT",
        "ALTER TABLE tutanaklar ADD COLUMN belge_no TEXT",
        "ALTER TABLE izin_talepleri ADD COLUMN belge_no TEXT",
        "ALTER TABLE izin_talepleri ADD COLUMN belge_tarihi TEXT",
        "ALTER TABLE genel_servis_belgeler ADD COLUMN belge_no TEXT",
        "ALTER TABLE genel_servis_belgeler ADD COLUMN belge_tarihi TEXT",
        "ALTER TABLE mesai_belgeler ADD COLUMN belge_no TEXT",
        "ALTER TABLE mesai_belgeler ADD COLUMN belge_tarihi TEXT",
        "ALTER TABLE dis_kiralama_belgeler ADD COLUMN belge_no TEXT",
        "ALTER TABLE dis_kiralama_belgeler ADD COLUMN belge_tarihi TEXT",
        "ALTER TABLE personel_masraf ADD COLUMN belge_no TEXT",
        "ALTER TABLE nakliyeler ADD COLUMN teslim_kanit_no TEXT",
        "ALTER TABLE araclar ADD COLUMN yakit_tipi TEXT",
        "ALTER TABLE manliftler ADD COLUMN yakit_tipi TEXT",
        "ALTER TABLE araclar ADD COLUMN liste_fiyat_gunluk REAL DEFAULT 0",
        "ALTER TABLE manliftler ADD COLUMN liste_fiyat_gunluk REAL DEFAULT 0",
        "ALTER TABLE araclar ADD COLUMN liste_fiyat_haftalik REAL DEFAULT 0",
        "ALTER TABLE manliftler ADD COLUMN liste_fiyat_haftalik REAL DEFAULT 0",
        "ALTER TABLE kiralamalar ADD COLUMN ilce TEXT",
        "ALTER TABLE kiralamalar ADD COLUMN santiye_adres TEXT",
        "ALTER TABLE musteriler ADD COLUMN odeme_tercihi TEXT",
    ];
    foreach ($migrations as $sql) {
        try { $pdo->exec($sql); } catch (\Exception $e) {
            $m = $e->getMessage();
            
            $uygulanmis = str_contains($m, 'duplicate column') || (str_contains($sql, 'RENAME COLUMN') && str_contains($m, 'no such column'));
            if (!$uygulanmis) hataGunlugu('sema.alter', $e);
        }
    }
    try {
        $nkGoc = $pdo->query("SELECT deger FROM ayarlar WHERE anahtar='nakliye_kendi_ad_goc'");
        if (!$nkGoc || $nkGoc->fetchColumn() !== '1') {
            $pdo->exec("UPDATE nakliyeler SET nakliyeci='Kendi nakliyemiz' WHERE tip='avk' AND nakliyeci='AVK Nakliye'");
            $pdo->exec("UPDATE kiralamalar SET nakliyeci='Kendi nakliyemiz' WHERE nakliye_tip='avk' AND nakliyeci='AVK Nakliye'");
            $pdo->exec("INSERT OR REPLACE INTO ayarlar(anahtar, deger) VALUES('nakliye_kendi_ad_goc', '1')");
        }
    } catch (\Throwable $e) { hataGunlugu('sema.nakliye_ad_goc', $e); }
    require_once __DIR__ . '/hasar-akis.php';
    hdSema($pdo);
    require_once __DIR__ . '/zincir-lib.php';
    dovizHakedisGoc($pdo);
    require_once __DIR__ . '/ariza-karar-lib.php';
    akSema($pdo);
    require_once __DIR__ . '/servis-kalem-lib.php';
    tsKalemSema($pdo);
    require_once __DIR__ . '/hasar-belge-lib.php';
    hbSema($pdo);
    require_once __DIR__ . '/tutanak-surum.php';
    tsSema($pdo);
    mkSema($pdo); mkGoc($pdo);
    require_once __DIR__.'/hazirlik-akis.php';
    hzSema($pdo);
    require_once __DIR__.'/saha-servis-lib.php';
    ssSema($pdo);
    require_once __DIR__.'/servis-kaynak-lib.php';
    skSema($pdo);
    require_once __DIR__.'/servis-maliyet-lib.php';
    smSema($pdo);
    require_once __DIR__.'/operasyon-adim.php';
    try {
        $itSema = (string)($pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='iade_tutanaklari'")->fetchColumn() ?: '');
        if (preg_match('/,\s*FOREIGN KEY\s*\(personel_id\)\s*REFERENCES\s+personel\s*\(id\)[^,)]*/i', $itSema)) {
            $yeniSema = preg_replace('/,\s*FOREIGN KEY\s*\(personel_id\)\s*REFERENCES\s+personel\s*\(id\)[^,)]*/i', '', $itSema);
            $yeniSema = preg_replace('/^CREATE TABLE\s+("?)iade_tutanaklari\1/i', 'CREATE TABLE iade_tutanaklari_k2', $yeniSema);
            $kolonlar = implode(',', array_map(fn($c) => '"' . $c['name'] . '"', $pdo->query('PRAGMA table_info(iade_tutanaklari)')->fetchAll(PDO::FETCH_ASSOC)));
            $indeksler = $pdo->query("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='iade_tutanaklari' AND sql IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
            $pdo->exec('PRAGMA foreign_keys = OFF');
            try {
                $pdo->beginTransaction();
                islemKilitAl($pdo);
                $guncelSema = (string)($pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='iade_tutanaklari'")->fetchColumn() ?: '');
                if ($guncelSema !== $itSema) throw new \RuntimeException('__zaten__');
                $pdo->exec('DROP TABLE IF EXISTS iade_tutanaklari_k2');
                $pdo->exec($yeniSema);
                $pdo->exec("INSERT INTO iade_tutanaklari_k2 ($kolonlar) SELECT $kolonlar FROM iade_tutanaklari");
                $pdo->exec('DROP TABLE iade_tutanaklari');
                $pdo->exec('ALTER TABLE iade_tutanaklari_k2 RENAME TO iade_tutanaklari');
                foreach ($indeksler as $ix) $pdo->exec($ix);
                $pdo->commit();
            } catch (\Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($e->getMessage() !== '__zaten__') error_log('iade_tutanaklari K2 yeniden kurulumu: ' . $e->getMessage());
            } finally {
                $pdo->exec('PRAGMA foreign_keys = ON');
            }
        }
    } catch (\Exception $e) { hataGunlugu('sema.iade_k2', $e); }

    opSema($pdo);
    require_once __DIR__.'/hizli-kiralama-lib.php';
    require_once __DIR__.'/sofor-lib.php';
    sfSema($pdo);
    hkSema($pdo);
    require_once __DIR__.'/kiralama-operasyon-lib.php';
    koSema($pdo);
    require_once __DIR__.'/servis-kalem-lib.php';
    tsHasarSema($pdo);
    tsHasarGoc($pdo);
    require_once __DIR__.'/servis-talep-lib.php';
    stSema($pdo);

    try {
        $mt = $pdo->query("SELECT id, metraj FROM manliftler WHERE metraj IS NOT NULL AND metraj GLOB '*[^0-9.]*'")->fetchAll(PDO::FETCH_ASSOC);
        $mu = $pdo->prepare("UPDATE manliftler SET metraj=? WHERE id=?");
        foreach ($mt as $r) { $n = metrajNormal($r['metraj']); if ($n !== $r['metraj']) $mu->execute([$n, $r['id']]); }
    } catch (\Exception $e) { hataGunlugu('sema.metraj_goc', $e); }

    try {
        $eskiSema = (string)($pdo->query("SELECT sql FROM sqlite_master WHERE name='personel_masraf'")->fetchColumn() ?: '');
        if (str_contains($eskiSema, 'personel_avans')) {
            $pdo->beginTransaction();
            $pdo->exec("ALTER TABLE personel_masraf RENAME TO personel_masraf_fk_eski");
            $pdo->exec("
                CREATE TABLE personel_masraf (
                    id            INTEGER PRIMARY KEY AUTOINCREMENT,
                    avans_id      INTEGER,
                    personel_id   INTEGER NOT NULL,
                    tarih         TEXT NOT NULL,
                    kategori      TEXT DEFAULT 'diger',
                    tutar         REAL DEFAULT 0,
                    aciklama      TEXT,
                    fis_dosya     TEXT,
                    durum         TEXT DEFAULT 'bekliyor',
                    onaylayan     TEXT,
                    created_at    TEXT DEFAULT (datetime('now','localtime')),
                    FOREIGN KEY (personel_id) REFERENCES personel(id) ON DELETE CASCADE
                )
            ");
            $pdo->exec("
                INSERT INTO personel_masraf (id, avans_id, personel_id, tarih, kategori, tutar, aciklama, fis_dosya, durum, onaylayan, created_at)
                SELECT id, avans_id, personel_id, tarih, kategori, tutar, aciklama, fis_dosya, durum, onaylayan, created_at FROM personel_masraf_fk_eski
            ");
            $pdo->exec("DROP TABLE personel_masraf_fk_eski");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_personel_masraf_avans ON personel_masraf(avans_id)");
            $pdo->commit();
        }
    } catch (\Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        hataGunlugu('sema.personel_masraf_fk', $e);
    }

    foreach (['izin_talepleri', 'mesailer', 'personel_masraf', 'personel_belgeler'] as $_retTablo) {
        try { $pdo->exec("ALTER TABLE $_retTablo ADD COLUMN ret_gerekcesi TEXT"); } catch (\Exception $e) { if (!str_contains($e->getMessage(), 'duplicate column')) hataGunlugu('sema.ret_gerekcesi', $e); }
    }

    try {
        if ((int)$pdo->query("SELECT COUNT(*) FROM ariza_kodlari")->fetchColumn() === 0) {
            $seedYol = __DIR__ . '/ariza-kodlari-seed.json';
            if (is_file($seedYol)) {
                $seed = json_decode((string)file_get_contents($seedYol), true);
                if (is_array($seed)) {
                    $ins = $pdo->prepare("INSERT INTO ariza_kodlari (marka, model, kategori_kod, kategori, kod, aciklama, arama) VALUES (?,?,?,?,?,?,?)");
                    $pdo->beginTransaction();
                    foreach ($seed as $r) {
                        if (empty($r['marka']) || empty($r['kod'])) continue;
                        $ins->execute([$r['marka'], $r['model'] ?? '', $r['kategori_kod'] ?? '', $r['kategori'] ?? '', $r['kod'], $r['aciklama'] ?? '',
                            trArama(($r['kod'] ?? '') . ' ' . ($r['kategori'] ?? '') . ' ' . ($r['aciklama'] ?? '') . ' ' . ($r['model'] ?? ''))]);
                    }
                    $pdo->commit();
                }
            }
        }
    } catch (\Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); }

    try {
        $eksik = (int)$pdo->query("SELECT COUNT(*) FROM ariza_kodlari WHERE arama IS NULL")->fetchColumn();
        if ($eksik > 0) {
            $upd = $pdo->prepare("UPDATE ariza_kodlari SET arama=? WHERE id=?");
            $pdo->beginTransaction();
            foreach ($pdo->query("SELECT id, kod, kategori, aciklama, model FROM ariza_kodlari WHERE arama IS NULL")->fetchAll() as $r) {
                $upd->execute([trArama($r['kod'] . ' ' . $r['kategori'] . ' ' . $r['aciklama'] . ' ' . $r['model']), $r['id']]);
            }
            $pdo->commit();
        }
    } catch (\Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); }

    $kiraUrunler = [
        [6507,'Zoomlion ZS0407DC 6 Metre','Akülü Makaslı Platformlar','https://aydinvinc.com/wp-content/uploads/2025/04/ZS0407DC举升姿态.249-1-scaled.png',1],
        [8288,'Zoomlion ZS0607HD 8 Metre','Akülü Makaslı Platformlar','https://aydinvinc.com/wp-content/uploads/2025/04/ZS0607HD-Li-工作姿态-左视图-scaled-e1745089709993.png',2],
        [8289,'Zoomlion ZS0808HD 10 Metre','Akülü Makaslı Platformlar','https://aydinvinc.com/wp-content/uploads/2025/04/ZS0808HD-Li-工作姿态-左视图-scaled-e1745088907532.png',3],
        [8290,'Zoomlion ZS1012HD 12 Metre','Akülü Makaslı Platformlar','https://aydinvinc.com/wp-content/uploads/2025/04/ZS1012HD-Li-工作姿态-左视图-scaled-e1745088721460.png',4],
        [8292,'Zoomlion ZS1212HD 14 Metre','Akülü Makaslı Platformlar','https://aydinvinc.com/wp-content/uploads/2025/04/ZS1212HD-Li-工作姿态-左视图-scaled-e1745088189823.png',5],
        [8296,'Zoomlion ZS1414HD 16 Metre','Akülü Makaslı Platformlar','https://aydinvinc.com/wp-content/uploads/2025/04/ZS1414HD-Li-工作姿态-左视图-1-scaled-e1745088356169.png',6],
        [6358,'Zoomlion 14 Metre ZS1218ERT','4X4 Arazi Tipi Akülü Makaslı Platformlar','https://aydinvinc.com/wp-content/uploads/2025/04/ZS1218RT剪叉举升-左视图-scaled.png',1],
        [6375,'Zoomlion 22 Metre ZS2023ERT','4X4 Arazi Tipi Akülü Makaslı Platformlar','https://aydinvinc.com/wp-content/uploads/2025/04/ZS2023ERT.17-scaled.png',2],
        [6346,'Zoomlion 12 Metre ZS1023RT','4x4 Arazi Tipi Dizel Makaslı Platformlar','https://aydinvinc.com/wp-content/uploads/2025/04/ZS1023RT举升姿态4-1-scaled.png',1],
        [6384,'Zoomlion 18 Metre ZS1623RT','4x4 Arazi Tipi Dizel Makaslı Platformlar','https://aydinvinc.com/wp-content/uploads/2025/04/zs2023ert.16-scaled.png',2],
        [6391,'Haulotte 12 Metre Compact 12DX','4x4 Arazi Tipi Dizel Makaslı Platformlar','https://aydinvinc.com/wp-content/uploads/2025/04/Haulotte-compact-10-12dx-scissor-lift.jpg',3],
        [6518,'Dingli 22 Metre JCPT2223RTB','4x4 Arazi Tipi Dizel Makaslı Platformlar','https://aydinvinc.com/wp-content/uploads/2025/04/Dingli-JCPT-2223-RTA-Diesel-Scissor-travel.png',4],
        [6529,'Zoomlion 14 Metre ZS1216C','Arazi Tipi Paletli Akülü Makaslı Platform','https://aydinvinc.com/wp-content/uploads/2025/04/ZS1216C-2-scaled.png',1],
        [6229,'Dingli 20 Metre Akülü Eklemli Platform GTBZ20AE','Akülü Eklemli Platformlar','https://aydinvinc.com/wp-content/uploads/2025/04/f_1554202389204757.png',1],
        [6236,'Haulotte Akülü Eklemli Platform HA15 IP','Akülü Eklemli Platformlar','https://aydinvinc.com/wp-content/uploads/2025/04/Haulotte-HA15IP.png',2],
        [6247,'Zoomlion 12 Metre ZA10RJE','Akülü Eklemli Platformlar','https://aydinvinc.com/wp-content/uploads/2025/04/ZA10RJE-右后45度-scaled.png',3],
        [6285,'Zoomlion 16 Metre ZA14NJE','Akülü Eklemli Platformlar','https://aydinvinc.com/wp-content/uploads/2025/04/ZA14NJE-工作姿态-右前45视图-scaled.png',4],
        [6274,'Zoomlion 18 Metre ZA16JERT','Akülü Eklemli Platformlar','https://aydinvinc.com/wp-content/uploads/2025/04/ZA16JERT-Li-1-scaled-e1745092374534.png',5],
        [6265,'Zoomlion 22 Metre ZA20JERT','Akülü Eklemli Platformlar','https://aydinvinc.com/wp-content/uploads/2025/04/ZA20JERT-1-scaled.png',6],
        [6295,'Zoomlion 24 Metre ZT22JE','Akülü Teleskopik Platformlar','https://aydinvinc.com/wp-content/uploads/2025/04/ZT22JE-scaled.png',1],
        [6302,'Zoomlion 28 Metre ZT26JE','Akülü Teleskopik Platformlar','https://aydinvinc.com/wp-content/uploads/2025/04/ZT26JE-1-scaled.png',2],
        [6305,'Zoomlion 34 Metre ZT32JE','Akülü Teleskopik Platformlar','https://aydinvinc.com/wp-content/uploads/2025/04/ZT32JE-V半伸姿态-右后视图-1-scaled.png',3],
        [6321,'Zoomlion 40 Metre ZT38JE','Akülü Teleskopik Platformlar','https://aydinvinc.com/wp-content/uploads/2025/04/ZT38JE-工作姿态-右视图-scaled.png',4],
        [7662,'12 Metre Tek Direk Dikey Platform ZMP09J','Dikey Direk Platformlar','https://aydinvinc.com/wp-content/uploads/2025/05/zmp09j---3-1-scaled.png',1],
        [7295,'Dizel Forklift FD30Z','Dizel Forkliftler','https://aydinvinc.com/wp-content/uploads/2025/05/fd30z-ana-resim.png',1],
        [7300,'Dizel Forklift FD50 Mini','Dizel Forkliftler','https://aydinvinc.com/wp-content/uploads/2025/05/d3c9c93c8d7549239c15a6b2f3dbbebe.png',2],
        [7302,'Dizel Forklift FD70','Dizel Forkliftler','https://aydinvinc.com/wp-content/uploads/2025/05/a389d3ea52a84efabb8d11b9def4a5e6.png',3],
        [6999,'5.5 Metre ELS LİFT EL 5.5 JUNİOR','Elle Çekilebilir Makaslı Platformlar','https://aydinvinc.com/wp-content/uploads/2025/05/1.png',1],
        [6399,'Dingli GTWY 16-2000 16 Metre','Çift Direk Dikey Platform','https://aydinvinc.com/wp-content/uploads/2025/04/8mciftmastli.jpg',1],
        [6409,'TEUPEN LEO 36 T','Örümcek Platformlar','https://aydinvinc.com/wp-content/uploads/2025/04/TR36.png',1],
        [6418,'IMER IM R19','Örümcek Platformlar','https://aydinvinc.com/wp-content/uploads/2025/04/im-r-19_1_orig.png',2],
        [6436,'Omme 42 Metre 4200 RJ','Örümcek Platformlar','https://aydinvinc.com/wp-content/uploads/2025/04/Adsiz-tasarim-4-2.png',3],
        [7007,'Araç Üstü Sepetli Platform 15 Metre','Araç Üstü Sepetli Platformlar','https://aydinvinc.com/wp-content/uploads/2025/05/15-metre-png.png',1],
        [7019,'Araç Üstü Sepetli Platform 20 Metre','Araç Üstü Sepetli Platformlar','https://aydinvinc.com/wp-content/uploads/2025/05/45-20250503-013623-0000.png',2],
        [7029,'Araç Üstü Sepetli Platform 22 Metre','Araç Üstü Sepetli Platformlar','https://aydinvinc.com/wp-content/uploads/2025/05/22-metre-png.png',3],
        [7042,'Araç Üstü Sepetli Platform 26 Metre','Araç Üstü Sepetli Platformlar','https://aydinvinc.com/wp-content/uploads/2025/05/26-metre-png.png',4],
        [7054,'Araç Üstü Sepetli Platform 27 Metre','Araç Üstü Sepetli Platformlar','https://aydinvinc.com/wp-content/uploads/2025/05/27-metre-png.png',5],
        [7072,'Araç Üstü Sepetli Platform 28 Metre','Araç Üstü Sepetli Platformlar','https://aydinvinc.com/wp-content/uploads/2025/05/28-metre-png.png',6],
        [7071,'Araç Üstü Sepetli Platform 32 Metre','Araç Üstü Sepetli Platformlar','https://aydinvinc.com/wp-content/uploads/2025/05/32-metre-png.png',7],
        [7092,'Araç Üstü Sepetli Platform 37 Metre','Araç Üstü Sepetli Platformlar','https://aydinvinc.com/wp-content/uploads/2025/05/37-metre-png.png',8],
        [7095,'Araç Üstü Sepetli Platform 45 Metre','Araç Üstü Sepetli Platformlar','https://aydinvinc.com/wp-content/uploads/2025/05/45-metre-png.png',9],
        [7336,'54 Metre Kırmalı Sepetli','Araç Üstü Kırmalı Platformlar','https://aydinvinc.com/wp-content/uploads/2025/05/png-katalog-fotograf-1.png',1],
        [7349,'63 Metre Kırmalı Sepetli','Araç Üstü Kırmalı Platformlar','https://aydinvinc.com/wp-content/uploads/2025/05/png-ana-fotograf.png',2],
        [7363,'75 Metre Kırmalı Sepetli','Araç Üstü Kırmalı Platformlar','https://aydinvinc.com/wp-content/uploads/2025/05/png-ana-fotograf-2.png',3],
        [7161,'15 Ton Araç Üstü Yük Vinç — HIYAP DH 50 YK5','Araç Üstü Kırmasız Taşımalı Vinç','https://aydinvinc.com/wp-content/uploads/2025/05/6-scaled.png',1],
        [7169,'20 Ton Araç Üstü Yük Vinç — DİSAN DH 60 K4','Araç Üstü Kırmasız Taşımalı Vinç','https://aydinvinc.com/wp-content/uploads/2025/05/4-scaled.png',2],
        [7176,'5 Ton Araç Üstü Yük Vinç — DİSAN DH 15 K4','Araç Üstü Kırmasız Taşımalı Vinç','https://aydinvinc.com/wp-content/uploads/2025/05/5-scaled.png',3],
        [7191,'80 Ton Araç Üstü Yük Vinç — HİDROKON HK 240 K7/L6 (Kırmasız)','Araç Üstü Kırmasız Taşımalı Vinç','https://aydinvinc.com/wp-content/uploads/2025/05/2-1-scaled.png',4],
        [7213,'30 Ton Araç Üstü Yük Vinç — HİDROKON HK 90 YK6/L4 (Kırmasız)','Araç Üstü Kırmasız Taşımalı Vinç','https://aydinvinc.com/wp-content/uploads/2025/05/3-1-scaled.png',5],
        [7181,'80 Ton Araç Üstü Yük Vinç — HİDROKON HK 240 K7/L6 (Kırmalı)','Araç Üstü Kırmalı Vinç','https://aydinvinc.com/wp-content/uploads/2025/05/2-1-scaled.png',1],
        [7192,'30 Ton Araç Üstü Yük Vinç — HİDROKON HK 90 YK6/L4 (Kırmalı)','Araç Üstü Kırmalı Vinç','https://aydinvinc.com/wp-content/uploads/2025/05/3-1-scaled.png',2],
    ];
    if ((int)$pdo->query("SELECT COUNT(*) FROM kiralama_urunler")->fetchColumn() === 0) {
        $kiraStmt = $pdo->prepare("INSERT OR IGNORE INTO kiralama_urunler (id,isim,kategori,gorsel,sira) VALUES (?,?,?,?,?)");
        foreach ($kiraUrunler as $u) $kiraStmt->execute($u);
    }

    if ((int)$pdo->query("SELECT COUNT(*) FROM subeler")->fetchColumn() === 0) {
        $adlar = ['Merkez'];
        try {
            foreach ($pdo->query("SELECT DISTINCT sube FROM manliftler WHERE sube IS NOT NULL AND sube<>''")->fetchAll(PDO::FETCH_COLUMN) as $s) {
                $s = trim((string)$s);
                if ($s !== '' && !in_array($s, $adlar, true)) $adlar[] = $s;
            }
        } catch (\Exception $e) { hataGunlugu('sema.sube_tohum', $e); }
        $ss = $pdo->prepare("INSERT OR IGNORE INTO subeler (ad) VALUES (?)");
        foreach ($adlar as $a) $ss->execute([$a]);
    }

    try {
        $pdo->beginTransaction();
        $bayrak = $pdo->prepare("INSERT OR IGNORE INTO ayarlar (anahtar, deger) VALUES ('gecis_token_ozet','1')");
        $bayrak->execute();
        if ($bayrak->rowCount() === 1) {
            foreach (['password_resets', 'musteri_kontak_token'] as $t) {
                $upd = $pdo->prepare("UPDATE $t SET token=? WHERE id=?");
                foreach ($pdo->query("SELECT id, token FROM $t")->fetchAll() as $r) {
                    $upd->execute([tokenOzet((string)$r['token']), $r['id']]);
                }
            }
        }
        $pdo->commit();
    } catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); }

    try {
        $pdo->beginTransaction();
        $bayrak = $pdo->prepare("INSERT OR IGNORE INTO ayarlar (anahtar, deger) VALUES ('gecis_share_token_ozet','1')");
        $bayrak->execute();
        if ($bayrak->rowCount() === 1) {
            $upd = $pdo->prepare("UPDATE share_tokens SET token=? WHERE id=?");
            foreach ($pdo->query("SELECT id, token FROM share_tokens")->fetchAll() as $r) {
                if (preg_match('/^[a-f0-9]{64}$/', (string)$r['token'])) continue;
                $upd->execute([tokenOzet((string)$r['token']), $r['id']]);
            }
        }
        $pdo->commit();
    } catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); }

    
    try {
        $pdo->beginTransaction();
        $bayrak = $pdo->prepare("INSERT OR IGNORE INTO ayarlar (anahtar, deger) VALUES ('gecis_il_yazim','1')");
        $bayrak->execute();
        if ($bayrak->rowCount() === 1) {
            $yazim = ['Adiyaman' => 'Adıyaman', 'Agri' => 'Ağrı', 'Aydin' => 'Aydın', 'Balikesir' => 'Balıkesir', 'Diyarbakir' => 'Diyarbakır',
                'Elazig' => 'Elazığ', 'Eskisehir' => 'Eskişehir', 'Gümüshane' => 'Gümüşhane', 'Istanbul' => 'İstanbul', 'Izmir' => 'İzmir',
                'Kirklareli' => 'Kırklareli', 'Kirsehir' => 'Kırşehir', 'Mugla' => 'Muğla', 'Mus' => 'Muş', 'Nevsehir' => 'Nevşehir',
                'Nigde' => 'Niğde', 'Sanliurfa' => 'Şanlıurfa', 'Sirnak' => 'Şırnak', 'Tekirdag' => 'Tekirdağ', 'Usak' => 'Uşak', 'Çankiri' => 'Çankırı'];
            foreach (['kiralamalar', 'santiyeler', 'musteriler', 'subeler'] as $t) {
                $upd = $pdo->prepare("UPDATE $t SET il=? WHERE il=?");
                foreach ($yazim as $eski => $yeni) $upd->execute([$yeni, $eski]);
            }
        }
        $pdo->commit();
    } catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); hataGunlugu('sema.il_yazim', $e); }

    $count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ((int)$count === 0) {
        $ilkSifre = getenv('TALOO_ILK_ADMIN_SIFRESI');
        $uretildi = !is_string($ilkSifre) || $ilkSifre === '';
        if ($uretildi) {
            $ilkSifre = rtrim(strtr(base64_encode(random_bytes(12)), '+/', 'Kx'), '=') . random_int(10, 99);
        }
        $hash = password_hash($ilkSifre, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare("INSERT INTO users (username, password, ad_soyad, rol) VALUES (?, ?, ?, ?)")
            ->execute(['admin', $hash, 'Sistem Yöneticisi', 'admin']);
        $dosyaliVt = (string)($pdo->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC)['file'] ?? '') !== '';
        if ($uretildi && $dosyaliVt) {
            $dosya = ilkYoneticiSifreDosyasi();
            $yazildi = @file_put_contents($dosya,
                "Kullanıcı adı: admin\nİlk şifre: {$ilkSifre}\n\n"
                . "Giriş yaptıktan sonra şifrenizi değiştirin ve BU DOSYAYI SİLİN.\n");
            if ($yazildi !== false) @chmod($dosya, 0600);
            error_log('Taloo: ilk yonetici hesabi olusturuldu; sifre ' . ($yazildi !== false ? $dosya . ' dosyasinda.' : 'dosyaya YAZILAMADI.'));
        }
    }
}

const YEDEK_SIR_AYARLAR = ['smtp_sifre', 'cron_anahtar', 'ai_api_key', 'bildirim_wp_apikey', 'claude_api_key', 'wa_token', 'wolvox_kopru_ozet'];
const YEDEK_DISI_TABLOLAR = ['password_resets', 'login_attempts', 'checkin_attempts', 'share_tokens', 'form_paylasim', 'bildirim_gorulen', 'musteri_kontak_token'];

function tokenOzet(string $token): string {
    return hash('sha256', $token);
}

function ilkYoneticiSifreDosyasi(): string {
    return dirname(_resolveDbPath()) . '/ILK-YONETICI-SIFRESI.txt';
}

function jsonOk($data = null, string $message = 'OK'): void {
    header('Content-Type: application/json; charset=utf-8');
    $yanit = ['success' => true, 'message' => $message, 'data' => $data];
    if (!empty($GLOBALS['_eksik_kaynaklar'])) $yanit['eksik'] = array_keys($GLOBALS['_eksik_kaynaklar']);
    echo json_encode($yanit, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(string $msg, int $code = 400, array $ek = []): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $msg] + $ek, JSON_UNESCAPED_UNICODE);
    exit;
}

final class Islem {
    public static array $durum = [];
    public static int $sayac = 0;
    public static bool $kapanisKayitli = false;
    public static array $kapanista = [];
}

function &_islemDurum(PDO $db): array {
    $k = spl_object_id($db);
    if (!isset(Islem::$durum[$k])) Islem::$durum[$k] = ['seviyeler' => [], 'sahip' => false];
    return Islem::$durum[$k];
}

function islemKilitAl(PDO $db): void {
    if (!$db->inTransaction()) {
        $d = &_islemDurum($db);
        if (!$d['seviyeler']) return;
    }
    $d = &_islemDurum($db);
    if ($d['sahip']) return;
    try { $db->exec('DELETE FROM islem_kilidi WHERE 0'); }
    catch (PDOException $e) {
        if (stripos($e->getMessage(), 'no such table') === false) throw $e;
        $db->exec('CREATE TABLE IF NOT EXISTS islem_kilidi (k INTEGER)');
    }
}

function _islemKapanistaCalistir(PDO $db, array $isler): void {
    if (!$isler) return;
    Islem::$kapanista[] = [$db, $isler];
    if (Islem::$kapanisKayitli) return;
    Islem::$kapanisKayitli = true;
    register_shutdown_function(function () {
        $liste = Islem::$kapanista; Islem::$kapanista = [];
        foreach ($liste as [$p, $fs]) {
            if ($p->inTransaction()) continue;
            _islemKancalar($fs);
        }
    });
}

function _islemKancalar(array $isler): void {
    foreach ($isler as $f) {
        try { $f(); } catch (\Throwable $e) { error_log('islem kancasi: ' . $e->getMessage()); }
    }
}

function islemIcinde(PDO $db, callable $fn) {
    $d = &_islemDurum($db);
    $sahip = false;
    if (!$d['seviyeler'] && !$db->inTransaction()) {
        $hamAcik = false;
        try { $db->beginTransaction(); }
        catch (PDOException $e) {
            if (stripos($e->getMessage(), 'within a transaction') === false) throw $e;
            $hamAcik = true;
        }
        if (!$hamAcik) {
            try { $db->exec('COMMIT'); $db->exec('BEGIN IMMEDIATE'); }
            catch (\Throwable $e) {
                try { $db->exec('BEGIN'); } catch (\Throwable $x) { error_log('islem baslatma onarimi: ' . $x->getMessage()); }
                try { $db->rollBack(); } catch (\Throwable $x) { error_log('islem baslatma geri alma: ' . $x->getMessage()); }
                throw $e;
            }
            $sahip = true;
            $d['sahip'] = true;
        }
    }
    $ad = null;
    if (!$sahip) {
        $ad = 'islem_sp_' . (++Islem::$sayac);
        $db->exec('SAVEPOINT ' . $ad);
        if (!$d['seviyeler']) {
            try { islemKilitAl($db); }
            catch (\Throwable $e) { try { $db->exec('ROLLBACK TO ' . $ad); $db->exec('RELEASE ' . $ad); } catch (\Throwable $x) { error_log('islem kilit geri alma: ' . $x->getMessage()); } throw $e; }
        }
    }
    $d['seviyeler'][] = ['ad' => $ad, 'sonra' => [], 'geri' => []];
    try {
        $r = $fn($db);
    } catch (\Throwable $e) {
        $sv = array_pop($d['seviyeler']);
        if ($sahip) {
            $d['sahip'] = false;
            try { if ($db->inTransaction()) $db->rollBack(); } catch (\Throwable $x) { error_log('islem geri alma: ' . $x->getMessage()); }
        } else {
            try { $db->exec('ROLLBACK TO ' . $ad); $db->exec('RELEASE ' . $ad); } catch (\Throwable $x) { error_log('islem savepoint geri alma: ' . $x->getMessage()); }
        }
        _islemKancalar(array_reverse($sv['geri']));
        throw $e;
    }
    $sv = array_pop($d['seviyeler']);
    if ($sahip) {
        $d['sahip'] = false;
        try { $db->commit(); }
        catch (\Throwable $e) {
            try { if ($db->inTransaction()) $db->rollBack(); } catch (\Throwable $x) { error_log('islem commit geri alma: ' . $x->getMessage()); }
            _islemKancalar(array_reverse($sv['geri']));
            throw $e;
        }
        _islemKancalar($sv['sonra']);
        return $r;
    }
    try { $db->exec('RELEASE ' . $ad); }
    catch (\Throwable $e) {
        _islemKancalar(array_reverse($sv['geri']));
        throw $e;
    }
    if ($d['seviyeler']) {
        $ust = count($d['seviyeler']) - 1;
        array_push($d['seviyeler'][$ust]['sonra'], ...$sv['sonra']);
        array_push($d['seviyeler'][$ust]['geri'], ...$sv['geri']);
    } else {
        _islemKapanistaCalistir($db, $sv['sonra']);
    }
    return $r;
}

function islemSonrasi(PDO $db, callable $f): void {
    $d = &_islemDurum($db);
    if ($d['seviyeler']) { $d['seviyeler'][count($d['seviyeler']) - 1]['sonra'][] = $f; return; }
    if ($db->inTransaction()) { _islemKapanistaCalistir($db, [$f]); return; }
    _islemKancalar([$f]);
}

function islemGeriAlinirsa(PDO $db, callable $f): void {
    $d = &_islemDurum($db);
    if ($d['seviyeler']) $d['seviyeler'][count($d['seviyeler']) - 1]['geri'][] = $f;
}

function islemYanit(PDO $db, callable $fn) {
    try { return islemIcinde($db, $fn); }
    catch (DomainException $e) {
        $kod = (int)$e->getCode();
        jsonError($e->getMessage(), $kod >= 400 && $kod < 500 ? $kod : 409);
    }
}

function sunucuHtaccessOkur(string $yazilim): bool {
    return (bool)preg_match('/apache|litespeed/i', $yazilim);
}

function trArama(?string $s): string {
    $s = str_replace(
        ['İ','I','ı','i','Ş','ş','Ğ','ğ','Ü','ü','Ö','ö','Ç','ç','Â','â','Î','î','Û','û'],
        ['i','i','i','i','s','s','g','g','u','u','o','o','c','c','a','a','i','i','u','u'],
        (string)$s
    );
    return mb_strtolower($s, 'UTF-8');
}

function trAramaSql(string $kolon): string {
    static $ciftler = [
        ['İ','i'], ['I','i'], ['ı','i'], ['Ş','s'], ['ş','s'], ['Ğ','g'], ['ğ','g'],
        ['Ü','u'], ['ü','u'], ['Ö','o'], ['ö','o'], ['Ç','c'], ['ç','c'], ['Â','a'], ['â','a'],
    ];
    $ifade = "COALESCE($kolon,'')";
    foreach ($ciftler as [$buyuk, $kucuk]) {
        $ifade = "REPLACE($ifade,'$buyuk','$kucuk')";
    }
    return "LOWER($ifade)";
}

function csrfToken(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verifyCsrf(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        jsonError('Geçersiz güvenlik tokeni.', 403);
    }
}



function ozelGeciciSupur(string $dizin, int $saniye = 7200): int {
    $silinen = 0;
    foreach (@glob($dizin . '/*-*.sqlite*') ?: [] as $d) {
        if (!preg_match('/^(?:yedek|prova|geri)-[0-9a-f]{32}\.sqlite(?:-journal|-wal|-shm)?$/', basename($d))) continue;
        $z = @filemtime($d);
        if ($z !== false && $z < time() - $saniye && @unlink($d)) $silinen++;
    }
    return $silinen;
}

function ozelGeciciDosya(string $onEk, string $uzanti = '.sqlite', ?string $dizin = null): string {
    $dizin = $dizin ?? dirname(DB_PATH);
    if (!@is_dir($dizin)) @mkdir($dizin, 0700, true);
    if (!@is_dir($dizin) || !@is_writable($dizin)) throw new \RuntimeException('Geçici dosya klasörü yazılamıyor.');
    ozelGeciciSupur($dizin);
    $yol = $dizin . '/' . preg_replace('/[^a-z0-9\-]/', '', $onEk) . '-' . bin2hex(random_bytes(16)) . $uzanti;
    $eski = umask(0077);
    $ac = @fopen($yol, 'x');
    umask($eski);
    if ($ac === false) throw new \RuntimeException('Geçici dosya açılamadı.');
    fclose($ac);
    @chmod($yol, 0600);
    return $yol;
}


function yazmaIstegiCsrf(): void {
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD', 'OPTIONS'], true)) verifyCsrf();
}

const OTURUM_ASIM_VARSAYILAN = 240;

function _oturumuSil(): void {
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
}

function oturumGecerli(?string &$neden = null, ?array &$kullanici = null): bool {
    $neden = null;
    $kullanici = null;
    if (empty($_SESSION['user_id'])) {
        $neden = 'Oturum bulunamadı. Lütfen giriş yapın.';
        return false;
    }

    $asim = (int)ayarOku('oturum_asim_dk', (string)OTURUM_ASIM_VARSAYILAN);
    if ($asim > 0) {
        $son = (int)($_SESSION['son_islem'] ?? 0);
        if ($son > 0 && (time() - $son) > $asim * 60) {
            _oturumuSil();
            $neden = 'Uzun süre işlem yapılmadığı için oturumunuz kapatıldı.';
            return false;
        }
        $_SESSION['son_islem'] = time();
    }

    try {
        $st = getDb()->prepare("SELECT rol, yetkiler, sube, sifre_degisti, totp_aktif, personel_id FROM users WHERE id = ?");
        $st->execute([$_SESSION['user_id']]);
        $u = $st->fetch();
    } catch (\Throwable $e) {
        error_log('Taloo: oturum dogrulanamadi: ' . $e->getMessage());
        $neden = 'Oturum doğrulanamadı. Lütfen biraz sonra yeniden deneyin.';
        return false;
    }
    if (!$u) {
        _oturumuSil();
        $neden = 'Hesabınız artık mevcut değil. Lütfen tekrar giriş yapın.';
        return false;
    }
    if (array_key_exists('sifre_damga', $_SESSION)
        && (string)($u['sifre_degisti'] ?? '') !== (string)$_SESSION['sifre_damga']) {
        _oturumuSil();
        $neden = 'Şifreniz değiştirildiği için oturumunuz kapatıldı. Yeniden giriş yapın.';
        return false;
    }
    $_SESSION['rol']      = $u['rol'];
    $_SESSION['yetkiler'] = $u['yetkiler'] ?? '';
    $_SESSION['sube']     = $u['sube'] ?? '';
    $_SESSION['personel_id'] = ((int)($u['personel_id'] ?? 0)) ?: null;
    $kullanici = $u;
    return true;
}

function oturumKapiEngeli(array $u): ?array {
    $dosya = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $sifreKapisi = !empty($_SESSION['sifre_degistir_zorunlu']);
    if ($sifreKapisi && !str_starts_with($dosya, 'hesap_')
        && !in_array($dosya, ['session.php', 'sifre.php', 'logout.php', 'login.php', 'iki_asamali.php'], true)) {
        return ['Kurulum şifresini değiştirmeden devam edemezsiniz.', ['sifre_degistir_zorunlu' => true]];
    }
    $ikiAsamaAcik = ['iki_asamali.php', 'session.php', 'logout.php'];
    if ($sifreKapisi) $ikiAsamaAcik[] = 'sifre.php';
    if (ikiAsamaliZorunlu((string)$u['rol']) && (int)($u['totp_aktif'] ?? 0) !== 1
        && !in_array($dosya, $ikiAsamaAcik, true)) {
        return ['İki aşamalı doğrulama zorunlu ama henüz kurulmadı. Hesabım sayfasından kurulumu tamamlayın.',
            ['iki_asamali_kurulmali' => true]];
    }
    return null;
}

const OTURUMA_YAZAN_UCLAR = ['session.php', 'iki_asamali.php', 'sifre.php', 'portal_auth.php'];

function saltOkuyanIstekseOturumuBirak(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) return;
    if (in_array(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), OTURUMA_YAZAN_UCLAR, true)) return;
    session_write_close();
}

function requireAuth(): array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!oturumGecerli($neden, $u)) jsonError((string)$neden, 401);
    $engel = oturumKapiEngeli($u);
    if ($engel !== null) jsonError($engel[0], 403, $engel[1]);
    saltOkuyanIstekseOturumuBirak();
    return $_SESSION;
}

function kurulumSifresiMi(array $user, string $sifre): bool {
    if ((string)($user['rol'] ?? '') !== 'admin' || $sifre === '') return false;
    if (PHP_SAPI === 'cli-server' && getenv('TALOO_KURULUM_SIFRESI_ZORLA') !== '1') return false;
    $adaylar = ['Admin@2024'];
    $ortam = getenv('TALOO_ILK_ADMIN_SIFRESI');
    if (is_string($ortam) && $ortam !== '') $adaylar[] = $ortam;
    $dosya = ilkYoneticiSifreDosyasi();
    if (is_file($dosya) && preg_match('/İlk şifre: (\S+)/u', (string)@file_get_contents($dosya), $m)) $adaylar[] = $m[1];
    foreach ($adaylar as $a) {
        if (hash_equals($a, $sifre)) return true;
    }
    return false;
}

const YENIDEN_DOGRULAMA_SINIR = 5;

function yenidenDogrula(PDO $db, int $uid, string $sifre): bool {
    $anahtar = '[yeniden_dogrulama:' . $uid . ']';
    $c = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE username=? AND attempted_at >= datetime('now','-10 minutes','localtime')");
    $c->execute([$anahtar]);
    if ((int)$c->fetchColumn() >= YENIDEN_DOGRULAMA_SINIR) {
        jsonError('Çok fazla hatalı şifre denemesi. 10 dakika bekleyip yeniden deneyin.', 429);
    }
    $st = $db->prepare("SELECT password FROM users WHERE id = ?");
    $st->execute([$uid]);
    $h = $st->fetchColumn();
    if ($h && $sifre !== '' && password_verify($sifre, (string)$h)) return true;
    logAttempt($anahtar, istekIp(), true);
    return false;
}

function istisnaYanit(\Throwable $e, string $baglam): void {
    if ($e instanceof \DomainException) {
        $kod = (int)$e->getCode();
        jsonError($e->getMessage(), in_array($kod, [400, 403, 404, 409, 422], true) ? $kod : 400);
    }
    error_log('Taloo ' . $baglam . ': ' . $e->getMessage());
    jsonError('İşlem tamamlanamadı.', 500);
}

function hataMetniTemizle(string $m, bool $dosyaAdiKoru = false): string {
    $m = preg_replace('/[\r\n\t]+/', ' ', $m) ?? '';
    $m = preg_replace('/\b(pass(?:word)?|sifre|şifre|parola|token|secret|api[_-]?key|apikey|anahtar|key|hash|pin|totp)\b(\s*[=:]\s*)("[^"]*"|\'[^\']*\'|\S+)/iu', '$1$2[gizli]', $m) ?? '';
    $m = preg_replace_callback('/[A-Za-z0-9_\-+\/=.]{24,}/', fn($e) => $dosyaAdiKoru
        && preg_match('/^[a-z][a-z0-9_\-]*(?:\.[a-z0-9_\-]+)*\.(?:js|mjs|html|css)$/', $e[0]) ? $e[0] : '[gizli]', $m) ?? '';
    return mb_substr($m, 0, 600);
}

function hataGunluguDosyasi(): string {
    $ozel = getenv('TALOO_HATA_GUNLUGU');
    return is_string($ozel) && $ozel !== '' ? $ozel : dirname(DB_PATH) . '/hata-gunlugu.log';
}

function hataGunlugu(string $baglam, \Throwable $e, bool $eksikVeri = false): void {
    static $sayac = [];
    $baglam = mb_substr(preg_replace('/[^\w.\-:]/u', '_', $baglam) ?? 'bilinmeyen', 0, 80);
    if ($eksikVeri) $GLOBALS['_eksik_kaynaklar'][$baglam] = true;
    $sayac[$baglam] = ($sayac[$baglam] ?? 0) + 1;
    if ($sayac[$baglam] > 3) return;
    $satir = date('Y-m-d H:i:s') . ' [' . $baglam . '] ' . get_class($e) . ': ' . hataMetniTemizle($e->getMessage())
           . ' @' . basename($e->getFile()) . ':' . $e->getLine();
    error_log('Taloo ' . $satir);
    $dosya = hataGunluguDosyasi();
    $dizin = dirname($dosya);
    if (!@is_dir($dizin) || !@is_writable($dizin)) return;
    clearstatcache(true, $dosya);
    if (@is_file($dosya) && (int)@filesize($dosya) > 512 * 1024) {
        @unlink($dosya . '.1');
        @rename($dosya, $dosya . '.1');
    }
    @file_put_contents($dosya, $satir . "\n", FILE_APPEND | LOCK_EX);
}

function portalOturumBaslat(array $kontak): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    session_regenerate_id(true);
    $_SESSION['portal_kontak_id']  = (int)$kontak['id'];
    $_SESSION['portal_musteri_id'] = (int)$kontak['musteri_id'];
    $_SESSION['portal_sifre_damga'] = (string)($kontak['sifre_degisti'] ?? '');
}

function portalSifreDamgaGuncelle(PDO $db, int $kontakId): string {
    $damga = date('Y-m-d H:i:s') . '.' . random_int(1000, 9999);
    $db->prepare("UPDATE musteri_kontaklari SET sifre_degisti=? WHERE id=?")->execute([$damga, $kontakId]);
    return $damga;
}

function portalOturumKapat(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    unset($_SESSION['portal_kontak_id'], $_SESSION['portal_musteri_id'], $_SESSION['portal_sifre_damga']);
}

function portalRequireAuth(): array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['portal_kontak_id'])) {
        jsonError('Oturum bulunamadı. Lütfen giriş yapın.', 403);
    }
    $st = getDb()->prepare("SELECT id, musteri_id, durum, sifre_degisti FROM musteri_kontaklari WHERE id=?");
    $st->execute([$_SESSION['portal_kontak_id']]);
    $k = $st->fetch();
    if (!$k || $k['durum'] !== 'aktif') {
        portalOturumKapat();
        jsonError('Erişiminiz artık geçerli değil. Lütfen tekrar giriş yapın.', 403);
    }
    if ((string)($k['sifre_degisti'] ?? '') !== (string)($_SESSION['portal_sifre_damga'] ?? '')) {
        portalOturumKapat();
        jsonError('Şifreniz değiştirildi. Lütfen yeni şifrenizle tekrar giriş yapın.', 403);
    }
    saltOkuyanIstekseOturumuBirak();
    return ['kontak_id' => (int)$k['id'], 'musteri_id' => (int)$k['musteri_id']];
}

const PORTAL_KT_TURLER = ['uzatma' => 'Süre uzatma', 'iade' => 'İade / toplama'];
const PORTAL_KT_GOREV  = ['uzatma' => 'portal_uzatma', 'iade' => 'portal_iade'];

function portalTalepKararYetkisi(string $tur, ?string $kiralamaSube): bool {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (($_SESSION['rol'] ?? '') === 'admin') return true;
    if (!hasPermission('kiralama_takip', 'yaz')) return false;
    $kullaniciSube = subeKisitiHam();
    $kiralamaSube  = trim((string)$kiralamaSube);
    if ($tur === 'iade') return $kullaniciSube === null || $kullaniciSube === $kiralamaSube;
    return $kullaniciSube !== null && $kiralamaSube !== '' && $kullaniciSube === $kiralamaSube;
}

function portalTalepGorevKapat(PDO $db, string $tur, int $talepId): void {
    $modul = PORTAL_KT_GOREV[$tur] ?? null;
    if ($modul === null) return;
    $db->prepare("UPDATE gorevler SET durum='tamamlandi', tamamlanma=?, updated_at=datetime('now','localtime')
                  WHERE kaynak_modul=? AND kaynak_id=? AND durum='acik'")
       ->execute([date('Y-m-d'), $modul, $talepId]);
}

function portalCheckRateLimit(string $email, string $ip): void {
    $db = getDb();
    $st = $db->prepare("
        SELECT COUNT(*) FROM portal_login_attempts
        WHERE (email=? OR ip=?) AND success=0
          AND attempted_at > datetime('now','-10 minutes','localtime')
    ");
    $st->execute([$email, $ip]);
    if ((int)$st->fetchColumn() >= LOGIN_RATE_LIMIT) {
        jsonError('Çok fazla başarısız giriş denemesi. 10 dakika bekleyin.', 429);
    }
}

function portalLogAttempt(string $email, string $ip, bool $success): void {
    $db = getDb();
    $db->prepare("INSERT INTO portal_login_attempts (email, ip, success) VALUES (?, ?, ?)")
       ->execute([$email, $ip, $success ? 1 : 0]);
    $db->exec("DELETE FROM portal_login_attempts WHERE attempted_at < datetime('now','-1 day','localtime')");
}

function _yetkiEylem(?string $eylem): string {
    if ($eylem !== null) return $eylem;
    $m = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    return in_array($m, ['GET', 'HEAD', 'OPTIONS'], true) ? 'oku' : 'yaz';
}

function hasPermission(string $modul, ?string $eylem = null): bool {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $rol      = $_SESSION['rol']      ?? '';
    $yetkiler = $_SESSION['yetkiler'] ?? '';
    if ($rol === 'admin' || $yetkiler === 'tum') return true;

    $eylem = _yetkiEylem($eylem);
    return yetkiDizesiIzin($yetkiler, $modul, $eylem);
}

function yetkiDizesiIzin(string $yetkiler, string $modul, string $eylem): bool {
    if ($yetkiler === 'tum') return true;
    foreach (explode(',', $yetkiler) as $parca) {
        $parca = trim($parca);
        if ($parca === '') continue;
        [$ad, $mod] = array_pad(explode(':', $parca, 2), 2, 'rw');
        if (trim($ad) !== $modul) continue;
        if ($eylem === 'oku') return true;
        return in_array(strtolower(trim($mod)), ['rw', 'w'], true);
    }
    return false;
}

function saltOkunurMu(string $modul): bool {
    return hasPermission($modul, 'oku') && !hasPermission($modul, 'yaz');
}

function requirePermission(string $modul, ?string $eylem = null): void {
    if (hasPermission($modul, $eylem)) return;
    if (_yetkiEylem($eylem) === 'yaz' && hasPermission($modul, 'oku')) {
        jsonError('Bu modülde salt okunur yetkiniz var; değişiklik yapamazsınız.', 403);
    }
    jsonError('Bu modül için yetkiniz yok.', 403);
}

const YETKI_MODULLERI = [
    'personel', 'arac', 'manlift', 'kiralama_takip', 'nakliye', 'yedek_parca',
    'makine', 'teknik_servis', 'satinalma', 'cek_senet', 'yonetim', 'finans',
    'mesai', 'rapor', 'arac_maliyet', 'formlar', 'ayarlar', 'dis_kiralama',
];

function yetkiDizesiTemizle(string $ham): string {
    $ham = trim($ham);
    if ($ham === 'tum') return 'tum';
    if ($ham === '') return '';
    $cikti = [];
    foreach (explode(',', $ham) as $parca) {
        $parca = trim($parca);
        if ($parca === '') continue;
        [$ad, $mod] = array_pad(explode(':', $parca, 2), 2, 'rw');
        $ad  = trim($ad);
        $mod = strtolower(trim($mod));
        if (!in_array($ad, YETKI_MODULLERI, true)) continue;
        if (!in_array($mod, ['r', 'rw', 'w'], true)) $mod = 'rw';
        $cikti[$ad] = $mod;
    }
    if (!$cikti) return '';
    $liste = [];
    foreach ($cikti as $ad => $mod) $liste[] = $mod === 'rw' ? $ad : $ad . ':' . $mod;
    return implode(',', $liste);
}

const PERSONEL_MESLEKLERI = [
    'operator'   => 'Operatör',
    'sofor'      => 'Şoför',
    'teknisyen'  => 'Teknisyen',
    'elektrikci' => 'Elektrikçi',
    'saha'       => 'Saha Personeli',
    'idari'      => 'İdari / Ofis',
];
function personelMeslekGecerli($v): ?string {
    return (is_string($v) && array_key_exists($v, PERSONEL_MESLEKLERI)) ? $v : null;
}

function istemciAnahtari(array $body): ?string {
    $a = trim((string)($body['istemci_anahtari'] ?? ''));
    return preg_match('/^[A-Za-z0-9_-]{8,64}$/', $a) ? $a : null;
}

function mukerrerKayitId(PDO $db, string $tablo, ?string $anahtar): ?int {
    if ($anahtar === null) return null;
    try {
        $st = $db->prepare('SELECT id FROM "' . $tablo . '" WHERE istemci_anahtari=? LIMIT 1');
        $st->execute([$anahtar]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int)$id;
    } catch (\Throwable $e) { return null; }
}


function belgeNoAl(array $kaynak, string $alan = 'belge_no'): ?string {
    $no = mb_substr(trim(metin($kaynak[$alan] ?? '')), 0, 100);
    return $no !== '' ? $no : null;
}

function belgeAdiAl(array $kaynak, string $alan = 'belge_adi'): string {
    return mb_substr(trim(metin($kaynak[$alan] ?? '')), 0, 200);
}

function alanlarZorunlu(array $kaynak, array $alanlar): void {
    foreach ($alanlar as $_alan) {
        if (!array_key_exists($_alan, $kaynak)) jsonError('Eksik alan: ' . $_alan . '. Düzenleme formu bütün alanları göndermelidir.');
    }
}

function belgeDosyasiSil(?string $rel): void {
    if (!$rel || !preg_match('#^[a-z]+/\d+/[a-f0-9]{32}\.[a-z0-9]{2,5}$#', $rel)) return;
    $full = realpath(UPLOAD_PATH . $rel);
    $uploadBase = realpath(UPLOAD_PATH);
    if ($full && $uploadBase && substr($full, 0, strlen($uploadBase)) === $uploadBase && file_exists($full)) {
        @unlink($full);
    }
}

function izinKaydiSil(PDO $db, int $id): array {
    return islemIcinde($db, function (PDO $db) use ($id): array {
        $chk = $db->prepare("SELECT * FROM izin_talepleri WHERE id=?");
        $chk->execute([$id]);
        $izin = $chk->fetch();
        if (!$izin) throw new DomainException('Kayıt bulunamadı.', 404);
        $sil = $db->prepare("DELETE FROM izin_talepleri WHERE id=?");
        $sil->execute([$id]);
        if ($sil->rowCount() !== 1) throw new DomainException('Kayıt bulunamadı.', 404);
        $tur = (string)($izin['izin_turu'] ?? '');
        $pid = (int)$izin['personel_id'];
        $gun = (int)$izin['gun_sayisi'];
        if ($tur === 'ekstra_hak' || $tur === 'izin_azaltma') {
            $db->prepare("UPDATE personel SET ekstra_izin_hak = COALESCE(ekstra_izin_hak,0) + ? WHERE id=?")
               ->execute([$tur === 'ekstra_hak' ? -$gun : $gun, $pid]);
        }
        if ($tur === 'ucretsiz' && $izin['baslangic']) {
            $fq = $db->prepare("SELECT id, toplam_maas FROM finans WHERE personel_id=? AND ay=?");
            $fq->execute([$pid, substr((string)$izin['baslangic'], 0, 7)]);
            $fr = $fq->fetch();
            if ($fr) {
                $iade = round($gun / 30 * (float)$fr['toplam_maas'], 2);
                $db->prepare("UPDATE finans SET haciz_kesinti = MAX(0, haciz_kesinti - ?) WHERE id=?")
                   ->execute([$iade, $fr['id']]);
            }
        }
        logla($db, 'izin_talepleri', $id, 'sil', $izin, null,
            'İzin kaydı silindi: ' . ($tur ?: '?') . ' · ' . $gun . ' gün · personel #' . $pid
            . (in_array($tur, ['ekstra_hak', 'izin_azaltma', 'ucretsiz'], true) ? ' (izin hakkı/kesinti geri alındı)' : ''));
        $yol = $izin['belge_yolu'] ?? null;
        if ($yol) islemSonrasi($db, fn() => belgeDosyasiSil($yol));
        return $izin;
    });
}

function izinHakSayaci(PDO $db, int $personelId, int $fark): void {
    if ($fark === 0) return;
    $u = $db->prepare("UPDATE personel SET ekstra_izin_hak = COALESCE(ekstra_izin_hak,0) + ? WHERE id=?");
    $u->execute([$fark, $personelId]);
    if ($u->rowCount() !== 1) throw new DomainException('Personel bulunamadı.', 404);
}

function logKayitAl(PDO $db, string $tablo, ?int $id): ?array {
    if (!$id) return null;
    try {
        $st = $db->prepare('SELECT * FROM "' . $tablo . '" WHERE id=?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (\Throwable $e) { return null; }
}


function logla(PDO $db, string $tablo, ?int $kayitId, string $eylem, ?array $eski = null, ?array $yeni = null, ?string $ozet = null, ?string $kim = null): void {
    try {
        $degisenler = null;
        if ($eski !== null || $yeni !== null) {
            $fark = [];
            foreach (array_keys(($eski ?? []) + ($yeni ?? [])) as $alan) {
                if (in_array($alan, ['updated_at', 'created_at'], true)) continue;
                $e = $eski[$alan] ?? null;
                $y = $yeni[$alan] ?? null;
                $es = is_array($e) ? json_encode($e, JSON_UNESCAPED_UNICODE) : (string)$e;
                $ys = is_array($y) ? json_encode($y, JSON_UNESCAPED_UNICODE) : (string)$y;
                if ($es !== $ys) $fark[$alan] = [$e, $y];
            }
            if ($eylem === 'guncelle' && !$fark) return;
            $degisenler = json_encode($fark, JSON_UNESCAPED_UNICODE);
        }
        if ($kim === null && session_status() === PHP_SESSION_NONE) session_start();
        $db->prepare("INSERT INTO islem_log (kullanici_id, kullanici_ad, tablo, kayit_id, eylem, ozet, degisenler, ip)
                      VALUES (?,?,?,?,?,?,?,?)")
           ->execute([
               $kim !== null ? null : ($_SESSION['user_id'] ?? null),
               $kim ?? ($_SESSION['ad_soyad'] ?? ($_SESSION['username'] ?? null)),
               $tablo, $kayitId, $eylem, $ozet, $degisenler,
               substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
           ]);
    } catch (\Throwable $e) {
        error_log('islem_log yazilamadi: ' . $e->getMessage());
    }
}

function hakedisTevkifatOraniGecerli($s): ?string {
    return (is_string($s) && preg_match('/^([1-9])\/(10)$/', $s)) ? $s : null;
}
function hakedisTutarHesapla(float $tutar, float $kdvOran, bool $tevkifatVar, $tevkifatOraniGirdi): array {
    $kdvTutar = round($tutar * $kdvOran / 100, 2);
    $oraniGecerli = $tevkifatVar ? hakedisTevkifatOraniGecerli($tevkifatOraniGirdi) : null;
    $tevkifatTutari = 0.0;
    $musteridenTahsilKdv = $kdvTutar;
    if ($oraniGecerli) {
        [$pay, $payda] = array_map('intval', explode('/', $oraniGecerli));
        $tevkifatTutari = round($kdvTutar * $pay / $payda, 2);
        $musteridenTahsilKdv = round($kdvTutar - $tevkifatTutari, 2);
    }
    return [
        'kdv_tutar' => $kdvTutar,
        'tevkifat_var' => $oraniGecerli ? 1 : 0,
        'tevkifat_orani' => $oraniGecerli,
        'tevkifat_tutari' => $tevkifatTutari,
        'musteriden_tahsil_kdv' => $musteridenTahsilKdv,
        'genel_toplam' => round($tutar + $musteridenTahsilKdv, 2),
    ];
}

function gorevOlustur(PDO $db, string $baslik, ?string $aciklama, string $kaynakModul, int $kaynakId, string $oncelik = 'normal'): int {
    $st = $db->prepare("SELECT id FROM gorevler WHERE kaynak_modul=? AND kaynak_id=? AND durum='acik'");
    $st->execute([$kaynakModul, $kaynakId]);
    $mevcut = $st->fetchColumn();
    if ($mevcut) return (int)$mevcut;

    $ins = $db->prepare("INSERT INTO gorevler (baslik, aciklama, oncelik, kaynak, kaynak_modul, kaynak_id) VALUES (?,?,?,'otomatik',?,?)");
    $ins->execute([
        $baslik,
        $aciklama,
        in_array($oncelik, ['dusuk', 'normal', 'yuksek'], true) ? $oncelik : 'normal',
        $kaynakModul,
        $kaynakId,
    ]);
    return (int)$db->lastInsertId();
}

function gorevOtomatikKapat(PDO $db, string $kaynakModul, array $gecerliIds): int {
    $st = $db->prepare("SELECT id, kaynak_id FROM gorevler WHERE kaynak_modul=? AND kaynak='otomatik' AND durum='acik'");
    $st->execute([$kaynakModul]);
    $gecerliSet = array_flip(array_map('intval', $gecerliIds));
    $kapatilan = 0;
    foreach ($st->fetchAll() as $r) {
        if (isset($gecerliSet[(int)$r['kaynak_id']])) continue;
        $db->prepare("UPDATE gorevler SET durum='tamamlandi', tamamlanma=?, kapanis='otomatik', updated_at=datetime('now','localtime') WHERE id=?")
           ->execute([date('Y-m-d'), (int)$r['id']]);
        $kapatilan++;
    }
    return $kapatilan;
}

function istisnaTara(PDO $db): int {
    $bugun = date('Y-m-d');
    $acilan = 0;

    $st = $db->prepare("
        SELECT k.id, k.kod, k.ekipman_adi FROM kiralamalar k
        WHERE k.operatorlu=1 AND k.operator_id IS NULL AND k.durum='aktif'
          AND k.baslangic <= date(?, '+1 day')
    ");
    $st->execute([$bugun]);
    $opBekleyenler = $st->fetchAll();
    gorevOtomatikKapat($db, 'operator_bekliyor', array_column($opBekleyenler, 'id'));
    foreach ($opBekleyenler as $r) {
        gorevOlustur($db, 'Operatör atanmamış: ' . ($r['ekipman_adi'] ?: ('#' . $r['id'])),
            'Teslimat yaklaşıyor, operatörlü kiralamaya henüz operatör atanmadı — ' . ($r['kod'] ?: ('#' . $r['id'])),
            'operator_bekliyor', (int)$r['id'], 'yuksek');
        $acilan++;
    }

    require_once __DIR__ . '/zincir-lib.php';
    $st = $db->prepare("
        SELECT * FROM kiralamalar
        WHERE durum='aktif' AND gercek_bitis IS NULL AND bitis IS NOT NULL AND bitis < ?
    ");
    $st->execute([$bugun]);
    $toplamaGecikenler = $st->fetchAll();
    gorevOtomatikKapat($db, 'toplama_gecikti', array_column($toplamaGecikenler, 'id'));
    foreach ($toplamaGecikenler as $r) {
        $asimBas = date('Y-m-d', strtotime($r['bitis'] . ' +1 day'));
        $asimGun = (int)(new DateTime($asimBas))->diff(new DateTime($bugun))->days + 1;
        $asimTutar = kiralamaTutarPencereli(array_merge($r, ['gercek_bitis' => $bugun]), $asimBas, $bugun) ?? 0.0;
        zincirGorevYaz($db, 'toplama_gecikti', (int)$r['id'], 'Toplama gecikti: ' . ($r['ekipman_adi'] ?: ('#' . $r['id'])),
            'Planlanan bitiş tarihi geçti ama toplama/iade işaretlenmedi — ' . ($r['kod'] ?: ('#' . $r['id']))
            . '. Planın ' . $asimGun . ' gün dışında; bu günler hakedişe girmiyor (yaklaşık ' . number_format($asimTutar, 2, ',', '.') . ' ' . (_zPara($r) === 'TL' ? '₺' : _zPara($r)) . ', KDV hariç). Bitiş tarihini uzatın ya da iadeyi işaretleyin.',
            'yuksek', null);
        $acilan++;
    }

    $acilan += zincirTara($db, $bugun, alacakOzeti($db));

    $yakinSinir = date('Y-m-d', strtotime($bugun . ' +7 days'));
    $bakimdaki = [];
    $stTs = $db->query("SELECT DISTINCT makine_tur, makine_id FROM teknik_servis WHERE durum IN ('acik','devam') AND tip='bakim' AND makine_tur IS NOT NULL AND makine_id IS NOT NULL");
    foreach ($stTs->fetchAll() as $r) $bakimdaki[$r['makine_tur'] . ':' . (int)$r['makine_id']] = true;
    foreach (['araclar' => 'arac', 'manliftler' => 'manlift'] as $tablo => $tur) {
        foreach ($db->query("SELECT id FROM $tablo WHERE durum IN ('bakim_bekliyor','inceleme_bekliyor')")->fetchAll() as $r) {
            $bakimdaki[$tur . ':' . (int)$r['id']] = true;
        }
    }
    $cakisanIdler = [];
    foreach (array_keys($bakimdaki) as $anahtar) {
        [$tur, $makineId] = explode(':', $anahtar, 2);
        $makineId = (int)$makineId;
        $st3 = $db->prepare("
            SELECT id, kod FROM kiralamalar
            WHERE makine_tur=? AND makine_id=? AND durum='aktif' AND baslangic BETWEEN ? AND ?
        ");
        $st3->execute([$tur, $makineId, $bugun, $yakinSinir]);
        foreach ($st3->fetchAll() as $r) {
            $cakisanIdler[] = (int)$r['id'];
            $adSt = $db->prepare("SELECT " . ($tur === 'arac' ? 'plaka' : 'kod') . " AS ad FROM " . ($tur === 'arac' ? 'araclar' : 'manliftler') . " WHERE id=?");
            $adSt->execute([$makineId]);
            $makineAd = $adSt->fetchColumn() ?: ($tur . '#' . $makineId);
            gorevOlustur($db, 'Bakım çakışması: ' . $makineAd,
                'Makine bakımda/bakım bekliyor ama ' . ($r['kod'] ?: ('#' . $r['id'])) . ' kiralaması yaklaşıyor',
                'bakim_cakismasi', (int)$r['id'], 'yuksek');
            $acilan++;
        }
    }
    gorevOtomatikKapat($db, 'bakim_cakismasi', $cakisanIdler);

    return $acilan;
}

function mesaiSaatHesapla(?string $bas, ?string $bit): float {
    if (!$bas || !$bit) return 0.0;
    if (!preg_match('/^([01]\\d|2[0-3]):([0-5]\\d)$/', $bas, $b)) return 0.0;
    if (!preg_match('/^([01]\\d|2[0-3]):([0-5]\\d)$/', $bit, $e)) return 0.0;
    $d1 = (int)$b[1] * 60 + (int)$b[2];
    $d2 = (int)$e[1] * 60 + (int)$e[2];
    if ($d2 < $d1) $d2 += 24 * 60;
    return round(($d2 - $d1) / 60, 2);
}

function mesaiTipi($v): string {
    return in_array($v, ['normal', 'pazar', 'konaklama'], true) ? $v : 'normal';
}

function mesaiKonaklamaGunleri(string $bas, ?string $bit): array {
    $gunler = [];
    $d = new DateTime($bas);
    $son = new DateTime($bit ?: $bas);
    for ($i = 0; $d <= $son && $i < 400; $i++, $d->modify('+1 day')) $gunler[] = $d->format('Y-m-d');
    return $gunler;
}

function mesaiAyParcalari(string $bas, string $bit): array {
    $out = [];
    while ($bas <= $bit) {
        $aySonu = date('Y-m-t', strtotime($bas));
        $out[] = [$bas, min($aySonu, $bit)];
        $bas = date('Y-m-d', strtotime($aySonu . ' +1 day'));
    }
    return $out;
}

function finansMesaiTutar(float $nSaat, float $nUcret, float $pGun, float $pUcret, float $kGun, float $maas): float {
    return round($nSaat * $nUcret + $pGun * $pUcret + $kGun * $maas / 60, 2);
}

function mesaiDurumu($v): string {
    return in_array($v, ['taslak', 'onaylandi', 'reddedildi', 'finansa_iletildi'], true) ? $v : 'taslak';
}

function retGerekcesi(array $body, string $durum): ?string {
    if ($durum !== 'reddedildi') return null;
    $g = trim(metin($body['gerekce'] ?? ''));
    return $g !== '' ? mb_substr($g, 0, 1000) : null;
}


const NAKLIYE_TEVKIFAT_ESIK = 10000.0;
const NAKLIYE_TEVKIFAT_ORAN = '2/10';

const NAKLIYE_VADE_MAX = 90;
const NAKLIYE_KENDI_AD = 'Kendi nakliyemiz';

function nakliyeHesap(float $yansitilan, float $odenen, float $kdvOran = 20.0, ?string $odemeTuru = null, int $vadeGun = 0, ?string $tarih = null): array {
    $yansitilan = round(max(0, $yansitilan), 2);
    $odenen     = round(max(0, $odenen), 2);
    $kar        = round($yansitilan - $odenen, 2);

    $kdvTutar   = round($yansitilan * $kdvOran / 100, 2);
    $tevkifatVar = $yansitilan >= NAKLIYE_TEVKIFAT_ESIK;
    $tevkifatTutari = 0.0;
    $musteridenKdv  = $kdvTutar;
    if ($tevkifatVar) {
        [$pay, $payda] = array_map('intval', explode('/', NAKLIYE_TEVKIFAT_ORAN));
        $tevkifatTutari = round($kdvTutar * $pay / $payda, 2);
        $musteridenKdv  = round($kdvTutar - $tevkifatTutari, 2);
    }

    $vadeGun = max(0, min(NAKLIYE_VADE_MAX, $vadeGun));
    $vadeTarihi = null;
    if ($tarih && $vadeGun > 0) {
        $vadeTarihi = date('Y-m-d', strtotime($tarih . ' +' . $vadeGun . ' days'));
    }

    return [
        'yansitilan'      => $yansitilan,
        'odenen'          => $odenen,
        'kar'             => $kar,
        'kar_orani'       => $yansitilan > 0 ? round($kar / $yansitilan * 100, 1) : 0.0,
        'kdv_oran'        => $kdvOran,
        'kdv_tutar'       => $kdvTutar,
        'tevkifat_var'    => $tevkifatVar ? 1 : 0,
        'tevkifat_orani'  => $tevkifatVar ? NAKLIYE_TEVKIFAT_ORAN : null,
        'tevkifat_tutari' => $tevkifatTutari,
        'musteriden_kdv'  => $musteridenKdv,
        'musteri_toplam'  => round($yansitilan + $musteridenKdv, 2),
        'odeme_turu'      => $odemeTuru ?: null,
        'vade_gun'        => $vadeGun,
        'vade_tarihi'     => $vadeTarihi,
    ];
}

function nakliyeOdemeTuru($v): ?string {
    $g = ['pesin' => 1, 'havale' => 1, 'fatura' => 1, 'cek' => 1, 'senet' => 1, 'kredi_karti' => 1];
    $v = trim(metin($v));
    return isset($g[$v]) ? $v : null;
}

const NAV_GRUPLARI = [
    [
        'baslik' => 'Genel',
        'items' => [
            [
                'href' => 'index.html',
                'icon' => 'dashboard',
                'text' => 'Dashboard',
                'kisa' => 'Panel',
                'match' => [
                    'index.html',
                    ''
                ]
            ],
            [
                'href' => 'gorevler.html',
                'icon' => 'checklist',
                'text' => 'Görevler',
                'match' => [
                    'gorevler.html'
                ]
            ],
            [
                'href' => 'istisnalar.html',
                'icon' => 'report_problem',
                'text' => 'Dikkat Gerekenler',
                'kisa' => 'Dikkat',
                'match' => [
                    'istisnalar.html'
                ]
            ]
        ]
    ],
    [
        'baslik' => 'Kiralama & Müşteri',
        'items' => [
            [
                'href' => 'firsatlar.html',
                'icon' => 'trending_up',
                'text' => 'Fırsatlar',
                'modul' => 'kiralama_takip',
                'match' => [
                    'firsatlar.html'
                ]
            ],
            [
                'href' => 'musteriler.html',
                'icon' => 'groups',
                'text' => 'Müşteriler',
                'modul' => 'kiralama_takip',
                'match' => [
                    'musteriler.html',
                    'musteri-detay.html',
                    'cari-ekstre.html'
                ]
            ],
            [
                'href' => 'teklifler.html',
                'icon' => 'request_quote',
                'text' => 'Teklifler',
                'modul' => 'kiralama_takip',
                'match' => [
                    'teklifler.html'
                ]
            ],
            [
                'href' => 'kiralama-takip.html',
                'icon' => 'assignment',
                'text' => 'Kiralama Takip',
                'kisa' => 'Kiralama',
                'modul' => 'kiralama_takip',
                'match' => [
                    'kiralama-takip.html'
                ]
            ],
            [
                'href' => 'kiralama-talepleri.html',
                'icon' => 'event_repeat',
                'text' => 'Portal Talepleri',
                'kisa' => 'Talepler',
                'modul' => ['kiralama_takip', 'personel', 'mesai'],
                'match' => [
                    'kiralama-talepleri.html'
                ]
            ],
            [
                'href' => 'dis-kiralama.html',
                'icon' => 'swap_horiz',
                'text' => 'Dış Kiralama',
                'kisa' => 'Dış Kira',
                'modul' => 'dis_kiralama',
                'match' => ['dis-kiralama.html']
            ],
            [
                'href' => 'musaitlik.html',
                'icon' => 'calendar_month',
                'text' => 'Müsaitlik Takvimi',
                'kisa' => 'Müsaitlik',
                'modul' => 'kiralama_takip',
                'match' => [
                    'musaitlik.html'
                ]
            ],
            [
                'href' => 'il-doldur.html',
                'icon' => 'edit_location_alt',
                'text' => 'İl Toplu Doldurma',
                'kisa' => 'İl Doldur',
                'modul' => 'kiralama_takip',
                'match' => [
                    'il-doldur.html'
                ]
            ],
            [
                'href' => 'hakedisler.html',
                'icon' => 'receipt',
                'text' => 'Hakedişler',
                'modul' => 'kiralama_takip',
                'match' => [
                    'hakedisler.html'
                ]
            ],
            [
                'href' => 'iade-tutanagi.html',
                'icon' => 'assignment_add',
                'text' => 'Tutanak',
                'kisa' => 'Tutanak',
                'adim_yetki' => ['saha'], 'adim_modul' => 'kiralama_takip',
                'match' => [
                    'iade-tutanagi.html'
                ]
            ],
            [
                'href' => 'saha-teslim.html', 'icon' => 'local_shipping', 'text' => 'Sahada Teslim', 'kisa' => 'Saha Teslim',
                'adim_yetki' => ['saha'], 'adim_modul' => 'kiralama_takip', 'match' => ['saha-teslim.html']
            ],
            [
                'href' => 'depo-isleri.html', 'icon' => 'local_shipping', 'text' => 'Yükleme / Toplama', 'kisa' => 'Sevkiyat',
                'adim_yetki' => ['yukleme', 'dagitim'], 'adim_modul' => 'kiralama_takip', 'match' => ['depo-isleri.html']
            ],
            [
                'href' => 'iade-tutanaklari.html',
                'icon' => 'fact_check',
                'text' => 'İade Tutanakları',
                'kisa' => 'Tutanaklar',
                'modul' => 'kiralama_takip',
                'match' => [
                    'iade-tutanaklari.html',
                    'tutanak-belge.html'
                ]
            ],
            [
                'href' => 'faturalandirma.html',
                'icon' => 'receipt_long',
                'text' => 'Faturalandırma',
                'kisa' => 'Fatura',
                'modul' => 'kiralama_takip',
                'match' => [
                    'faturalandirma.html'
                ]
            ],
            [
                'href' => 'nakliye.html',
                'icon' => 'local_shipping',
                'text' => 'Nakliye',
                'modul' => 'nakliye',
                'match' => [
                    'nakliye.html'
                ]
            ],
            [
                'href' => 'cek-senet.html',
                'icon' => 'payments',
                'text' => 'Çek / Senet',
                'kisa' => 'Çek/Senet',
                'modul' => 'cek_senet',
                'match' => [
                    'cek-senet.html'
                ]
            ]
        ]
    ],
    [
        'baslik' => 'Varlık & Bakım',
        'items' => [
            [
                'href' => 'arac.html',
                'icon' => 'directions_car',
                'text' => 'Araç Yönetimi',
                'kisa' => 'Araçlar',
                'modul' => 'arac',
                'match' => [
                    'arac.html',
                    'arac-detay.html',
                    'arac-ekle.html'
                ]
            ],
            [
                'href' => 'manlift.html',
                'icon' => 'precision_manufacturing',
                'text' => 'Makine Yönetimi',
                'kisa' => 'Makineler',
                'modul' => 'manlift',
                'match' => [
                    'manlift.html',
                    'manlift-detay.html'
                ]
            ],
            [
                'href' => 'yedek-parca.html',
                'icon' => 'build',
                'text' => 'Yedek Parça',
                'kisa' => 'Parça',
                'modul' => 'yedek_parca',
                'match' => [
                    'yedek-parca.html'
                ]
            ],
            [
                'href' => 'teknik-servis.html',
                'icon' => 'build_circle',
                'text' => 'Teknik Servis',
                'kisa' => 'Servis',
                'modul' => 'teknik_servis',
                'adim_yetki' => ['depo'], 'adim_modul' => 'teknik_servis',
                'adim_yetki' => ['depo'], 'adim_modul' => 'teknik_servis',
                'adim_yetki' => ['depo'], 'adim_modul' => 'teknik_servis',
                'match' => [
                    'teknik-servis.html'
                ]
            ],
            [
                'href' => 'tedarikciler.html',
                'icon' => 'local_shipping',
                'text' => 'Tedarikçiler',
                'modul' => 'satinalma',
                'match' => [
                    'tedarikciler.html'
                ]
            ],
            [
                'href' => 'satinalma.html',
                'icon' => 'shopping_cart',
                'text' => 'Satınalma',
                'modul' => 'satinalma',
                'match' => [
                    'satinalma.html'
                ]
            ]
        ]
    ],
    [
        'baslik' => 'Personel',
        'items' => [
            [
                'href' => 'personel.html',
                'icon' => 'badge',
                'text' => 'Personel Yönetimi',
                'kisa' => 'Personel',
                'modul' => 'personel',
                'match' => [
                    'personel.html',
                    'personel-detay.html',
                    'personel-ekle.html'
                ]
            ],
            [
                'href' => 'checkin.html',
                'icon' => 'location_on',
                'text' => 'Saha Check-in',
                'kisa' => 'Check-in',
                'match' => [
                    'checkin.html'
                ]
            ],
            [
                'href' => 'avans-masraf.html',
                'icon' => 'payments',
                'text' => 'Personel Masrafları',
                'kisa' => 'Masraf',
                'modul' => 'personel',
                'match' => [
                    'avans-masraf.html'
                ]
            ],
            [
                'href' => 'finans.html',
                'icon' => 'account_balance_wallet',
                'text' => 'Finans',
                'modul' => 'finans',
                'match' => [
                    'finans.html'
                ]
            ],
            [
                'href' => 'mesai.html',
                'icon' => 'more_time',
                'text' => 'Mesailer',
                'modul' => 'mesai',
                'match' => [
                    'mesai.html'
                ]
            ]
        ]
    ],
    [
        'baslik' => 'Rapor & Analiz',
        'items' => [
            [
                'href' => 'rapor.html',
                'icon' => 'summarize',
                'text' => 'Raporlar',
                'modul' => 'rapor',
                'match' => [
                    'rapor.html'
                ]
            ],
            [
                'href' => 'analiz.html',
                'icon' => 'auto_awesome',
                'text' => 'Analiz & Asistan',
                'kisa' => 'Analiz',
                'modul' => 'rapor',
                'match' => [
                    'analiz.html'
                ]
            ],
            [
                'href' => 'makine-rapor.html',
                'icon' => 'monitoring',
                'text' => 'Makine Raporu',
                'kisa' => 'Makine',
                'modul' => 'makine',
                'match' => [
                    'makine-rapor.html'
                ]
            ],
            [
                'href'=>'kasa-defteri.html','icon'=>'account_balance','text'=>'Kasa Defteri','modul'=>'yonetim','match'=>['kasa-defteri.html']
            ],[
                'href'=>'aylik-durum.html','icon'=>'table_chart','text'=>'Aylık Genel Durum','modul'=>'yonetim','match'=>['aylik-durum.html']
            ],
            [
                'href'=>'sube-prim.html','icon'=>'workspace_premium','text'=>'Şube Primleri','modul'=>'yonetim','match'=>['sube-prim.html']
            ],
            [
                'href'=>'ciro-analiz.html','icon'=>'bar_chart','text'=>'Ciro Analizi','modul'=>'yonetim','match'=>['ciro-analiz.html']
            ],
            [
                'href' => 'yonetim.html',
                'icon' => 'leaderboard',
                'text' => 'Yönetim Paneli',
                'kisa' => 'Yönetim',
                'modul' => 'yonetim',
                'match' => [
                    'yonetim.html'
                ]
            ],
            [
                'href' => 'liderlik.html',
                'icon' => 'military_tech',
                'text' => 'Liderlik Tablosu',
                'kisa' => 'Liderlik',
                'modul' => 'rapor',
                'match' => [
                    'liderlik.html'
                ]
            ],
            [
                'href' => 'grafik-merkezi.html',
                'icon' => 'insights',
                'text' => 'Grafik Merkezi',
                'kisa' => 'Grafik',
                'modul' => 'rapor',
                'match' => [
                    'grafik-merkezi.html'
                ]
            ],
            [
                'href' => 'filo-analiz.html',
                'icon' => 'map',
                'text' => 'Filo Analizi',
                'kisa' => 'Filo',
                'modul' => 'rapor',
                'match' => [
                    'filo-analiz.html', 'filo-referans.html'
                ]
            ],
            [
                'href' => 'arac-maliyet-hesabi.html',
                'icon' => 'local_gas_station',
                'text' => 'Araç Maliyet Hesabı',
                'kisa' => 'Maliyet',
                'modul' => 'arac_maliyet',
                'match' => [
                    'arac-maliyet-hesabi.html'
                ]
            ]
        ]
    ],
    [
        'baslik' => 'İçerik',
        'items' => [
            [
                'href' => 'kiralama.html',
                'icon' => 'menu_book',
                'text' => 'Kataloglar',
                'match' => [
                    'kiralama.html'
                ]
            ],
            [
                'href' => 'formlar.html',
                'icon' => 'receipt_long',
                'text' => 'Formlar',
                'modul' => 'formlar',
                'match' => [
                    'formlar.html'
                ]
            ]
        ]
    ],
    [
        'baslik' => 'Sistem',
        'items' => [
            [
                'href' => 'ayarlar.html',
                'icon' => 'settings',
                'text' => 'Ayarlar',
                'modul' => 'ayarlar',
                'match' => [
                    'ayarlar.html'
                ]
            ],
            [
                'href' => 'qr.html',
                'text' => 'QR Okut',
                'kisa' => 'QR',
                'icon' => 'qr_code_scanner',
                'match' => [
                    'qr.html'
                ]
            ],
            [
                'href' => 'hesabim.html',
                'text' => 'Hesabım',
                'kisa' => 'Hesabım',
                'icon' => 'account_circle',
                'match' => [
                    'hesabim.html'
                ]
            ]
        ]
    ]
];

const NAV_MODUL_HARITA = [
    'personel' => [
        'personel.html',
        'personel-detay.html',
        'personel-ekle.html',
        'avans-masraf.html',
        'kiralama-talepleri.html'
    ],
    'arac' => [
        'arac.html',
        'arac-detay.html',
        'arac-ekle.html'
    ],
    'manlift' => [
        'manlift.html',
        'manlift-detay.html'
    ],
    'kiralama_takip' => [
        'kiralama-takip.html',
        'kiralama-talepleri.html',
        'musaitlik.html',
        'musteriler.html',
        'musteri-detay.html',
        'cari-ekstre.html',
        'teklifler.html',
        'hakedisler.html',
        'faturalandirma.html',
        'firsatlar.html',
        'iade-tutanaklari.html',
        'tutanak-belge.html',
        'hasar-dosyasi.html',
        'hasar-degerlendirme.html',
        'sevk-hazirlik.html',
        'il-doldur.html'
    ],
    'dis_kiralama' => [
        'dis-kiralama.html'
    ],
    'yedek_parca' => [
        'yedek-parca.html'
    ],
    'satinalma' => [
        'tedarikciler.html',
        'satinalma.html'
    ],
    'nakliye' => [
        'nakliye.html'
    ],
    'makine' => [
        'makine-rapor.html'
    ],
    'teknik_servis' => [
        'teknik-servis.html',
        'hasar-degerlendirme.html'
    ],
    'cek_senet' => [
        'cek-senet.html'
    ],
    'yonetim' => [
        'aylik-durum.html',
        'kasa-defteri.html',
        'yonetim.html',
        'sube-prim.html',
        'ciro-analiz.html'
    ],
    'finans' => [
        'finans.html'
    ],
    'mesai' => [
        'mesai.html',
        'kiralama-talepleri.html'
    ],
    'rapor' => [
        'rapor.html',
        'analiz.html',
        'liderlik.html',
        'grafik-merkezi.html',
        'filo-analiz.html', 'filo-referans.html'
    ],
    'arac_maliyet' => [
        'arac-maliyet-hesabi.html'
    ],
    'formlar' => [
        'formlar.html'
    ],
    'ayarlar' => [
        'ayarlar.html'
    ]
];

function navMenusu(?array $kullanici): array {
    $rol   = $kullanici['rol'] ?? '';
    $yetki = trim((string)($kullanici['yetkiler'] ?? ''));
    $hepsi = ($rol === 'admin' || $yetki === 'tum');
    $izin = $hepsi ? null : array_filter(array_map(
        fn($p) => trim(explode(':', $p)[0]), explode(',', $yetki)));

    $out = [];
    foreach (NAV_GRUPLARI as $g) {
        $ogeler = [];
        foreach ($g['items'] as $it) {
            $m = $it['modul'] ?? null;
            if (is_array($m)) {
                $uygun = $izin === null ? $m : array_values(array_intersect($m, $izin));
                if (!$uygun) continue;
                $it['modul'] = $uygun[0];
            } elseif ($m !== null && $izin !== null && !in_array($m, $izin, true) && empty($it['adim_yetki'])) continue;
            $ay = $it['adim_yetki'] ?? null;
            $am = $it['adim_modul'] ?? null;
            if ($ay !== null && !$hepsi && !($am !== null && $izin !== null && in_array($am, $izin, true))) {
                $uid = (int)($kullanici['id'] ?? 0);
                if ($uid < 1) continue;
                $db = getDb();
                if (!array_filter($ay, fn($y) => opYetkiVar($db, 'kullanici', $uid, $y))) continue;
            }
            $ogeler[] = $it;
        }
        if ($ogeler) $out[] = ['baslik' => $g['baslik'], 'items' => $ogeler];
    }
    return $out;
}

function navSayfaIzinli(?array $kullanici, string $sayfa): bool {
    $rol   = $kullanici['rol'] ?? '';
    $yetki = trim((string)($kullanici['yetkiler'] ?? ''));
    if ($rol === 'admin' || $yetki === 'tum') return true;
    $izin = array_filter(array_map(fn($p) => trim(explode(':', $p)[0]), explode(',', $yetki)));
    $uid = (int)($kullanici['id'] ?? 0);
    if ($sayfa === 'teknik-servis.html' && $uid >= 1 && !in_array('teknik_servis', $izin, true) && function_exists('opYetkiVar') && opYetkiVar(getDb(), 'kullanici', $uid, 'depo')) return true;
    $bagli = false;
    foreach (NAV_MODUL_HARITA as $modul => $sayfalar) {
        if (!in_array($sayfa, $sayfalar, true)) continue;
        if (in_array($modul, $izin, true)) return true;
        $bagli = true;
    }
    return !$bagli;
}

const NAV_ALT_BASLANGIC = ['index.html', 'kiralama-takip.html', 'musteriler.html', 'gorevler.html'];
const NAV_ALT_DISARIDA  = ['ayarlar.html', 'il-doldur.html'];

function navAltCubuk(?array $kullanici): array {
    $gorunen = [];
    foreach (navMenusu($kullanici) as $g) foreach ($g['items'] as $it) $gorunen[] = $it['href'];
    return [
        'baslangic' => array_values(array_intersect(NAV_ALT_BASLANGIC, $gorunen)),
        'disarida'  => array_values(array_intersect(NAV_ALT_DISARIDA, $gorunen)),
    ];
}

function navHaritasi(?array $kullanici): array {
    $rol   = $kullanici['rol'] ?? '';
    $yetki = trim((string)($kullanici['yetkiler'] ?? ''));
    if ($rol === 'admin' || $yetki === 'tum') return NAV_MODUL_HARITA;
    $izin = array_filter(array_map(fn($p) => trim(explode(':', $p)[0]), explode(',', $yetki)));
    return array_intersect_key(NAV_MODUL_HARITA, array_flip($izin));
}


const TOTP_ADIM     = 30;
const TOTP_HANE     = 6;
const TOTP_PENCERE  = 1;

function base32Coz(string $b32): string {
    $harfler = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32) ?? '');
    $bit = '';
    for ($i = 0; $i < strlen($b32); $i++) {
        $p = strpos($harfler, $b32[$i]);
        if ($p === false) continue;
        $bit .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bit, 8) as $bayt) {
        if (strlen($bayt) === 8) $out .= chr((int)bindec($bayt));
    }
    return $out;
}

function totpAnahtarUret(): string {
    $harfler = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $s = '';
    for ($i = 0; $i < 32; $i++) $s .= $harfler[random_int(0, 31)];
    return $s;
}

function totpKod(string $anahtar, int $adim): string {
    $bin  = base32Coz($anahtar);
    if ($bin === '') return '';
    $veri = pack('N*', 0) . pack('N*', $adim);
    $hash = hash_hmac('sha1', $veri, $bin, true);
    $ofs  = ord($hash[19]) & 0x0f;
    $par  = ((ord($hash[$ofs]) & 0x7f) << 24)
          | ((ord($hash[$ofs + 1]) & 0xff) << 16)
          | ((ord($hash[$ofs + 2]) & 0xff) << 8)
          | (ord($hash[$ofs + 3]) & 0xff);
    return str_pad((string)($par % (10 ** TOTP_HANE)), TOTP_HANE, '0', STR_PAD_LEFT);
}

function totpDogrula(string $anahtar, string $kod, ?int $sonAdim = null): ?int {
    $kod = preg_replace('/\D/', '', $kod) ?? '';
    if (strlen($kod) !== TOTP_HANE) return null;
    $simdi = intdiv(time(), TOTP_ADIM);
    for ($d = -TOTP_PENCERE; $d <= TOTP_PENCERE; $d++) {
        $adim = $simdi + $d;
        if ($sonAdim !== null && $adim <= $sonAdim) continue;
        if (hash_equals(totpKod($anahtar, $adim), $kod)) return $adim;
    }
    return null;
}

function totpYedekKodUret(int $adet = 8): array {
    $kodlar = [];
    for ($i = 0; $i < $adet; $i++) {
        $k = strtoupper(bin2hex(random_bytes(4)));
        $kodlar[] = substr($k, 0, 4) . '-' . substr($k, 4, 4);
    }
    return $kodlar;
}

function ayarOku(string $anahtar, string $varsayilan = ''): string {
    try {
        $st = getDb()->prepare("SELECT deger FROM ayarlar WHERE anahtar = ?");
        $st->execute([$anahtar]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null || $v === '') ? $varsayilan : (string)$v;
    } catch (\Throwable $e) { return $varsayilan; }
}

function ikiAsamaliZorunlu(?string $rol): bool {
    $mod = ayarOku('iki_asamali', 'kapali');
    if ($mod === 'herkes') return true;
    if ($mod === 'admin')  return ($rol === 'admin');
    return false;
}

const PAROLA_MIN = 10;

const PAYLASIM_VARSAYILAN_GUN = 30;
const PAYLASIM_MAX_GUN        = 90;

function parolaZayifMi(string $p, string $kullanici = ''): ?string {
    $d = mb_strtolower($p);
    if (mb_strlen($p) < PAROLA_MIN) return 'Şifre en az ' . PAROLA_MIN . ' karakter olmalıdır.';
    if (preg_match('/^\\d+$/', $p))  return 'Şifre yalnızca rakamlardan oluşamaz.';
    if (!preg_match('/[a-zçğıöşü]/iu', $p) || !preg_match('/\\d/', $p)) {
        return 'Şifre en az bir harf ve bir rakam içermelidir.';
    }
    if ($kullanici !== '' && str_contains($d, mb_strtolower($kullanici))) {
        return 'Şifre kullanıcı adınızı içeremez.';
    }
    $yaygin = ['123456', '1234567890', 'password', 'sifre', 'parola', 'qwerty', 'qwe123',
               'admin', 'yonetici', '111111', '000000', 'abc123', 'iloveyou', 'welcome',
               'letmein', 'monkey', 'dragon', 'passw0rd', 'sifre123', 'parola123',
               'admin123', 'test1234', 'deneme123', 'ankara06', 'istanbul34', 'fener1907',
               'galatasaray', 'besiktas', 'trabzon61'];
    foreach ($yaygin as $y) {
        if ($d === $y || $d === $y . '!' || str_replace(['0','1','3','4','5','7'], ['o','i','e','a','s','t'], $d) === $y) {
            return 'Bu şifre çok yaygın; tahmin edilmesi kolay. Başka bir şifre seçin.';
        }
    }
    if (preg_match('/^(.)\\1+$/u', $p)) return 'Şifre aynı karakterin tekrarı olamaz.';
    return null;
}

function parolaDogrula(string $p, string $kullanici = ''): void {
    $hata = parolaZayifMi($p, $kullanici);
    if ($hata !== null) jsonError($hata);
}

function sifreDamgaGuncelle(PDO $db, int $userId): void {
    $db->prepare("UPDATE users SET sifre_degisti = ? WHERE id = ?")
       ->execute([date('Y-m-d H:i:s') . '.' . random_int(1000, 9999), $userId]);
}

function parolaYazVeDamgala(PDO $db, int $userId, string $hash): bool {
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hash, $userId]);
        sifreDamgaGuncelle($db, $userId);
        $db->commit();
        return true;
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        hataGunlugu('parola.damga', $e);
        guvenlikLog('sifre_degistirilemedi', null, $userId);
        return false;
    }
}

function guvenlikLog(string $olay, ?string $kullaniciAd, ?int $kullaniciId = null, array $ek = []): void {
    try {
        $db = getDb();
        $ek['tarayici'] = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200);
        $db->prepare("INSERT INTO islem_log (kullanici_id, kullanici_ad, tablo, kayit_id, eylem, ozet, degisenler, ip)
                      VALUES (?,?,'guvenlik',?,?,?,?,?)")
           ->execute([
               $kullaniciId,
               $kullaniciAd,
               $kullaniciId,
               $olay,
               guvenlikOlayMetni($olay, $kullaniciAd),
               json_encode($ek, JSON_UNESCAPED_UNICODE),
               istekIp(),
           ]);
    } catch (\Throwable $e) {
        error_log('guvenlik_log yazilamadi: ' . $e->getMessage());
    }
}

function istekIp(): string {
    $uzak = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    if (GUVENILIR_PROXYLER && in_array($uzak, GUVENILIR_PROXYLER, true)) {
        $vekil = (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
        if ($vekil === '') $vekil = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
        if (filter_var($vekil, FILTER_VALIDATE_IP)) return $vekil;
    }
    return substr($uzak, 0, 45);
}

function guvenlikOlayMetni(string $olay, ?string $ad): string {
    $k = $ad ?: 'bilinmeyen';
    return match ($olay) {
        'giris'            => "Giriş yapıldı: $k",
        'giris_basarisiz'  => "BAŞARISIZ giriş denemesi: $k",
        '2fa_basarisiz'    => "BAŞARISIZ doğrulama kodu: $k",
        '2fa_kurtarma'     => "Kurtarma kodu ile giriş: $k",
        '2fa_acildi'       => "İki aşamalı doğrulama açıldı: $k",
        '2fa_kapatildi'    => "İki aşamalı doğrulama KAPATILDI: $k",
        '2fa_sifirlandi'   => "İki aşamalı doğrulama yönetici tarafından sıfırlandı: $k",
        'sifre_degisti'    => "Şifre değiştirildi: $k",
        'sifre_sifirlandi' => "Şifre sıfırlama bağlantısıyla değiştirildi: $k",
        'sifre_degistirilemedi' => "Şifre DEĞİŞTİRİLEMEDİ (oturum damgası yazılamadı, eski şifre geçerli): $k",
        'yedek_indirildi'  => "TAM SİSTEM YEDEĞİ indirildi: $k",
        'yedek_anlik'      => "Gecelik yedek alındı",
        'cikis'            => "Çıkış yapıldı: $k",
        'tum_cihazlar_cikis' => "Tüm cihazlardaki oturumlar kapatıldı: $k",
        'gizli_anahtar_degisti' => "Entegrasyon anahtarı değiştirildi: $k",
        'paylasim_olusturuldu'  => "Girişsiz paylaşım bağlantısı oluşturuldu: $k",
        'personel_pin_yenilendi' => "Personel PIN'i yenilendi: $k",
        'personel_qr_yenilendi'  => "Personel QR rozeti yenilendi: $k",
        'eposta_degisti'         => "Hesap e-postası değiştirildi (şifre sıfırlama adresi): $k",
        'kopru_hatali_anahtar'   => "Wolvox köprüsü: HATALI anahtarla istek geldi",
        'kopru_anahtar_uretildi' => "Wolvox köprüsü anahtarı üretildi/yenilendi: $k",
        'kod_kazima'             => "KOD KAZIMA GİRİŞİMİ: bir IP kısa sürede çok sayıda kod dosyası istedi, 1 saat engellendi",
        default            => "$olay: $k",
    };
}

function whatsappGonder(string $mesaj): array {
    try {
        $db = getDb();
        $ay = [];
        foreach ($db->query("SELECT anahtar, deger FROM ayarlar")->fetchAll() as $r) {
            $ay[$r['anahtar']] = $r['deger'];
        }
        if (empty($ay['bildirim_wp_aktif']) || empty($ay['bildirim_wp_no']) || empty($ay['bildirim_wp_apikey'])) {
            return ['ok' => false, 'kod' => 0, 'hata' => 'WhatsApp bildirimi yapılandırılmamış.'];
        }
        $url = 'https://api.callmebot.com/whatsapp.php?' . http_build_query([
            'phone'  => preg_replace('/\\D/', '', $ay['bildirim_wp_no']),
            'text'   => $mesaj,
            'apikey' => trim($ay['bildirim_wp_apikey']),
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        curl_exec($ch);
        $kod  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hata = curl_error($ch);
        curl_close($ch);
        return ['ok' => $kod === 200, 'kod' => $kod, 'hata' => $hata];
    } catch (\Throwable $e) {
        return ['ok' => false, 'kod' => 0, 'hata' => $e->getMessage()];
    }
}

function guvenlikAlarmi(string $baslik, array $satirlar = []): void {
    try {
        $m = "🔴 GÜVENLİK UYARISI\n" . $baslik;
        foreach ($satirlar as $k => $v) $m .= "\n" . $k . ': ' . $v;
        $m .= "\nTarih: " . date('d.m.Y H:i');
        whatsappGonder($m);
    } catch (\Throwable $e) { hataGunlugu('guvenlik.alarm', $e); }
}

const YEDEK_SIFRE_BASLIK = "TALOO-ENC1\n";

function yedekSifrele(string $veri, string $parola): string {
    $tuz = random_bytes(16);
    $iv  = random_bytes(16);
    $anahtar = hash_pbkdf2('sha256', $parola, $tuz, 200000, 32, true);
    $sifreli = openssl_encrypt($veri, 'aes-256-cbc', $anahtar, OPENSSL_RAW_DATA, $iv);
    if ($sifreli === false) throw new RuntimeException('Şifreleme başarısız.');
    $hmac = hash_hmac('sha256', $sifreli, $anahtar, true);
    return YEDEK_SIFRE_BASLIK . base64_encode($tuz) . "\n" . base64_encode($iv) . "\n"
         . base64_encode($hmac) . "\n" . $sifreli;
}

function yedekSifreliMi(string $veri): bool {
    return str_starts_with($veri, YEDEK_SIFRE_BASLIK);
}

function yedekCoz(string $veri, string $parola): ?string {
    if (!yedekSifreliMi($veri)) return $veri;
    $p = explode("\n", $veri, 5);
    if (count($p) < 5) return null;
    $tuz  = base64_decode($p[1], true);
    $iv   = base64_decode($p[2], true);
    $hmac = base64_decode($p[3], true);
    if ($tuz === false || $iv === false || $hmac === false) return null;
    $anahtar = hash_pbkdf2('sha256', $parola, $tuz, 200000, 32, true);
    if (!hash_equals(hash_hmac('sha256', $p[4], $anahtar, true), $hmac)) return null;
    $cozulmus = openssl_decrypt($p[4], 'aes-256-cbc', $anahtar, OPENSSL_RAW_DATA, $iv);
    return $cozulmus === false ? null : $cozulmus;
}

function finansMusteriDogrula(PDO $db, int $musteriId, ?int $hakedisId = null): void {
    if ($musteriId < 1) return;

    subeKayitDogrula($db, 'musteriler', $musteriId);

    if ($hakedisId === null || $hakedisId < 1) return;

    $st = $db->prepare("SELECT musteri_id FROM hakedisler WHERE id = ?");
    $st->execute([$hakedisId]);
    $hm = $st->fetch(PDO::FETCH_NUM);
    if ($hm === false) jsonError('Hakediş bulunamadı.', 404);

    $hMus = (int)($hm[0] ?? 0);
    if ($hMus > 0 && $hMus !== $musteriId) {
        jsonError('Bu hakediş başka bir müşteriye ait; tahsilat farklı müşteriye bağlanamaz.');
    }
    if (subeKisiti() !== null) subeErisimDogrula(hakedisEtkinSube($db, $hakedisId));
}

function kiralamaTarihDogrula(?string $baslangic, ?string $bitis, ?string $gercekBitis): void {
    if (!$baslangic) return;
    if ($bitis && $bitis < $baslangic)  jsonError('Bitiş tarihi başlangıçtan önce olamaz.');
    if ($gercekBitis && $gercekBitis < $baslangic) {
        jsonError('Gerçek iade tarihi (' . tarihGoster($gercekBitis) . ') kiralamanın başlangıcından ('
                  . tarihGoster($baslangic) . ') önce olamaz.');
    }
}

function tarihGoster(?string $t): string {
    if (!$t) return '—';
    try { return (new DateTime($t))->format('d.m.Y'); } catch (\Exception $e) { return $t; }
}

const HAKEDIS_DONEM_MAX_GUN = 1500;
const HAKEDIS_PENCERE_MAX_GUN = 3700;

function hakedisTarihNesne($t): ?DateTime {
    $t = metin($t);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $t)) return null;
    $d = DateTime::createFromFormat('!Y-m-d', substr($t, 0, 10));
    if (!$d || $d->format('Y-m-d') !== substr($t, 0, 10)) return null;
    try { return new DateTime($t); } catch (\Exception $e) { return null; }
}

function hakedisDonemHatasi(array $body): ?string {
    $ad = ['donem_bas' => 'Dönem başlangıcı', 'donem_bit' => 'Dönem bitişi'];
    $t = [];
    foreach ($ad as $k => $etiket) {
        $ham = $body[$k] ?? null;
        if ($ham === null || $ham === '' || $ham === '0000-00-00') { $t[$k] = null; continue; }
        $t[$k] = is_string($ham) ? dateStr($k, $body) : null;
        if ($t[$k] === null) return $etiket . ' geçerli bir tarih değil (YYYY-AA-GG).';
    }
    if ($t['donem_bas'] === null || $t['donem_bit'] === null) return null;
    if ($t['donem_bit'] < $t['donem_bas']) return 'Dönem bitişi başlangıçtan önce olamaz.';
    $gun = (int)(new DateTime($t['donem_bas']))->diff(new DateTime($t['donem_bit']))->days;
    if ($gun > HAKEDIS_DONEM_MAX_GUN) return 'Hakediş dönemi en fazla ' . HAKEDIS_DONEM_MAX_GUN . ' gün olabilir.';
    return null;
}

function hakedisBosAraliklar(PDO $db, int $kiralamaId, string $pencereBas, string $pencereBit): array {
    $g = hakedisTarihNesne($pencereBas); $bitis = hakedisTarihNesne($pencereBit);
    if (!$g || !$bitis || $bitis < $g) return [];
    $tavan = (clone $g)->modify('+' . HAKEDIS_PENCERE_MAX_GUN . ' days');
    if ($bitis > $tavan) $bitis = $tavan;
    $ust = (clone $bitis)->modify('+1 day');
    $gGun = $g->format('Y-m-d'); $ustGun = $ust->format('Y-m-d');
    $st = $db->prepare("SELECT donem_bas, donem_bit FROM hakedisler
                        WHERE kiralama_id = ? AND COALESCE(donem_bas,'') <> ''
                          AND COALESCE(donem_bit,'') <> ''");
    $st->execute([$kiralamaId]);
    $kapsanan = [];
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$hBas, $hBit]) {
        $d = hakedisTarihNesne($hBas); $son = hakedisTarihNesne($hBit);
        if (!$d || !$son || $son < $d || $son->format('Y-m-d') < $gGun || $d->format('Y-m-d') > $ustGun) continue;
        if ($d < $g) { $atla = (int)$d->diff($g)->days; if ($atla > 0) $d->modify('+' . $atla . ' days'); }
        while ($d <= $son && $d <= $ust) { $kapsanan[$d->format('Y-m-d')] = true; $d->modify('+1 day'); }
    }
    $araliklar = []; $acik = null; $onceki = null;
    while ($g <= $bitis) {
        $gun = $g->format('Y-m-d');
        if (isset($kapsanan[$gun])) {
            if ($acik !== null) { $araliklar[] = [$acik, $onceki]; $acik = null; }
        } else {
            if ($acik === null) $acik = $gun;
            $onceki = $gun;
        }
        $g->modify('+1 day');
    }
    if ($acik !== null) $araliklar[] = [$acik, $onceki];
    $out = [];
    foreach ($araliklar as [$a, $b]) {
        $out[] = [$a, $b, (int)(new DateTime($a))->diff(new DateTime($b))->days + 1];
    }
    return $out;
}

function hakedisKodUret(PDO $db, int $id, ?string $tarih = null): string {
    $yil = preg_match('/^\d{4}/', (string)$tarih, $m) ? $m[0] : date('Y');
    $kod = 'HKD-' . $yil . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
    $db->prepare("UPDATE hakedisler SET kod=? WHERE id=?")->execute([$kod, $id]);
    return $kod;
}

function tutanaksizKiralamaKosulu(string $k = 'k'): string {
    return "$k.durum='tamamlandi'"
        . " AND $k.id NOT IN (SELECT kiralama_id FROM iade_tutanaklari WHERE islem='iade' AND inceleme_sonucu IS NULL)"
        . " AND $k.ikame_makine_id IS NULL";
}

function hakedisKalemleriHesapla(PDO $db, array $k, string $dBas, string $dBit, bool $final = false): array {
    $kalemler = []; $uyarilar = []; $nakliyeIdler = [];
    $kid = (int)$k['id'];
    $gun = (int)(new DateTime($dBas))->diff(new DateTime($dBit))->days + 1;
    $ad  = trim((string)($k['ekipman_adi'] ?? '')) ?: 'Kiralama';
    $dT  = date('d.m.Y', strtotime($dBas)) . ' – ' . date('d.m.Y', strtotime($dBit));
    $oncekiKalem = function (string $tur, bool $nakliyeSatirsiz = false) use ($db, $kid): bool {
        $st = $db->prepare("SELECT COUNT(*) FROM hakedis_kalemleri hk JOIN hakedisler h ON h.id = hk.hakedis_id
                            WHERE h.kiralama_id = ? AND hk.tur = ?" . ($nakliyeSatirsiz ? " AND hk.nakliye_id IS NULL" : ''));
        $st->execute([$kid, $tur]);
        return (int)$st->fetchColumn() > 0;
    };

    $tip   = $k['kira_tipi'] ?: 'gunluk';
    $sure  = kiraSuresi((float)$gun, $tip);
    $mik   = max(1, (int)($k['miktar'] ?? 1));
    $birim = (float)($k['birim_ucret'] ?? 0);
    $brut  = round($birim * $mik * $sure, 2);
    $isk   = kiralamaGecerliIskonto($k);
    $kira  = round($brut - round($brut * $isk / 100, 2), 2);
    if ($kira > 0) {
        $kalemler[] = ['tur' => 'kira', 'aciklama' => $ad . ' kira bedeli ' . $dT . ' (' . $gun . ' gün)'
                        . ($isk > 0 ? ' · %' . rtrim(rtrim(number_format($isk, 2, ',', ''), '0'), ',') . ' indirim' : ''),
                       'miktar' => round($sure * $mik, 4), 'birim' => $birim, 'tutar' => $kira, 'nakliye_id' => null, 'uyari' => null];
    }

    if (!empty($k['operatorlu']) && (float)($k['operator_ucret'] ?? 0) > 0) {
        $opU = (float)$k['operator_ucret'];
        $opTip = $k['operator_ucret_tipi'] ?? 'gunluk';
        if ($opTip === 'sabit') {
            if (!$oncekiKalem('operator')) {
                $kalemler[] = ['tur' => 'operator', 'aciklama' => 'Operatör hizmeti (sabit)', 'miktar' => 1, 'birim' => $opU,
                               'tutar' => round($opU, 2), 'nakliye_id' => null, 'uyari' => null];
            }
        } elseif ($opTip === 'aylik') {
            $ay = kiraSuresi((float)$gun, 'aylik');
            $kalemler[] = ['tur' => 'operator', 'aciklama' => 'Operatör hizmeti ' . $dT . ' (aylık)', 'miktar' => round($ay, 4),
                           'birim' => $opU, 'tutar' => round($opU * $ay, 2), 'nakliye_id' => null, 'uyari' => null];
        } else {
            $p = $db->prepare("SELECT
                    SUM(CASE WHEN akis_durumu IS NULL OR akis_durumu NOT IN ('bekliyor','gorundu') THEN 1 ELSE 0 END) AS n,
                    COALESCE(SUM(CASE
                        WHEN akis_durumu IN ('bekliyor','gorundu','yolda','ertelendi','saha_girilemedi') THEN 0
                        WHEN akis_durumu = 'kismen' THEN 0.5
                        WHEN durum = 'tam' THEN 1 WHEN durum = 'yarim' THEN 0.5 ELSE 0 END), 0) AS g
                FROM kiralama_puantaj WHERE kiralama_id = ? AND tarih BETWEEN ? AND ?");
            $p->execute([$kid, $dBas, $dBit]);
            $pr = $p->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'g' => 0];
            $uyari = null;
            if ((int)$pr['n'] === 0) {
                $calisan = (float)$gun;
                $uyari = 'Bu dönem için operatör puantajı girilmemiş; kira günü (' . $gun . ') sayıldı.';
                $uyarilar[] = $uyari;
            } else {
                $calisan = (float)$pr['g'];
            }
            if ($calisan > 0) {
                $kalemler[] = ['tur' => 'operator', 'aciklama' => 'Operatör hizmeti ' . $dT . ' (' . rtrim(rtrim(number_format($calisan, 1, ',', ''), '0'), ',') . ' gün)',
                               'miktar' => $calisan, 'birim' => $opU, 'tutar' => round($opU * $calisan, 2), 'nakliye_id' => null, 'uyari' => $uyari];
            }
        }
    }

    $sip = trim((string)($k['siparis_no'] ?? ''));
    if ($sip !== '') {
        $kosul = 'siparis_no = ?'; $par = [$sip];
        $tasiyiciMi = siparisTasiyici($db, $sip) === $kid;
    } else {
        $kosul = "kiralama_id = ? AND COALESCE(siparis_no,'') = ''"; $par = [$kid];
        $tasiyiciMi = true;
    }
    $var = $db->prepare("SELECT COUNT(*) FROM nakliyeler WHERE $kosul");
    $var->execute($par);
    $satirVar = (int)$var->fetchColumn() > 0;
    if ($satirVar && $tasiyiciMi) {
        $st = $db->prepare("SELECT id, tarih, yon, plaka, nakliyeci, yansitilan, COALESCE(fiyat_bekliyor,0) AS fiyat_bekliyor
                            FROM nakliyeler WHERE $kosul AND hakedis_id IS NULL ORDER BY tarih, id");
        $st->execute($par);
        $yonAd = ['gidis' => 'gidiş', 'donus' => 'dönüş', 'gidis_donus' => 'gidiş + dönüş'];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $n) {
            $etiket = 'Nakliye ' . ($yonAd[$n['yon']] ?? $n['yon']) . ($n['plaka'] ? ' · ' . $n['plaka'] : '')
                    . ($n['tarih'] ? ' (' . date('d.m.Y', strtotime($n['tarih'])) . ')' : '');
            if ((int)$n['fiyat_bekliyor'] === 1) {
                if ($final) $uyarilar[] = $etiket . ': nakliyeci fiyatı henüz girilmedi — fiyat girilince ayrı hakedişle faturalanmalı.';
                continue;
            }
            if ((float)$n['yansitilan'] <= 0) continue;
            if (!$final && !empty($n['tarih']) && $n['tarih'] > $dBit) continue;
            $kalemler[] = ['tur' => 'nakliye', 'aciklama' => $etiket, 'miktar' => 1, 'birim' => (float)$n['yansitilan'],
                           'tutar' => round((float)$n['yansitilan'], 2), 'nakliye_id' => (int)$n['id'], 'uyari' => null];
            $nakliyeIdler[] = (int)$n['id'];
        }
    } elseif (!$satirVar && (float)($k['nakliye_ucret'] ?? 0) > 0 && !$oncekiKalem('nakliye', true)) {
        $nu = (float)$k['nakliye_ucret'];
        $kalemler[] = ['tur' => 'nakliye', 'aciklama' => 'Nakliye', 'miktar' => 1, 'birim' => $nu, 'tutar' => round($nu, 2), 'nakliye_id' => null, 'uyari' => null];
    }

    $toplam = round(array_sum(array_column($kalemler, 'tutar')), 2);
    return ['kalemler' => $kalemler, 'tutar' => $toplam, 'uyarilar' => $uyarilar, 'nakliye_idler' => $nakliyeIdler];
}

function hakedisKalemleriYaz(PDO $db, int $hakedisId, array $hesap): void {
    $ins = $db->prepare("INSERT INTO hakedis_kalemleri (hakedis_id, tur, aciklama, miktar, birim, tutar, nakliye_id, uyari) VALUES (?,?,?,?,?,?,?,?)");
    foreach ($hesap['kalemler'] as $kl) {
        $ins->execute([$hakedisId, $kl['tur'], $kl['aciklama'], $kl['miktar'], $kl['birim'], $kl['tutar'], $kl['nakliye_id'], $kl['uyari']]);
    }
    if ($hesap['nakliye_idler']) {
        $bag = $db->prepare("UPDATE nakliyeler SET hakedis_id = ? WHERE id = ? AND hakedis_id IS NULL");
        foreach ($hesap['nakliye_idler'] as $nid) {
            $bag->execute([$hakedisId, $nid]);
            if ($bag->rowCount() !== 1) throw new RuntimeException('Nakliye satırı #' . $nid . ' başka bir hakedişe bağlanmış; işlem geri alındı.');
        }
    }
}

const SUBE_MERKEZI_MODULLER = ['arac', 'manlift', 'personel', 'yedek_parca',
                               'makine', 'teknik_servis', 'genel_servis', 'qr', 'mesai'];

function subeMerkezi(string $modul): void {
    if (!in_array($modul, SUBE_MERKEZI_MODULLER, true)) {
        throw new RuntimeException("subeMerkezi(): '$modul' merkezi modul listesinde yok.");
    }
    $GLOBALS['_sube_merkezi'] = $modul;
}

function subeKisitiHam(): ?string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (($_SESSION['rol'] ?? '') === 'admin') return null;
    $s = trim((string)($_SESSION['sube'] ?? ''));
    return $s !== '' ? $s : null;
}

function subeKisiti(): ?string {
    if (isset($GLOBALS['_sube_merkezi'])) return null;
    return subeKisitiHam();
}

function kayitSubesi(?string $istenen, ?string $varsayilan = null): ?string {
    $k = subeKisiti();
    if ($k !== null) return $k;
    $istenen = trim((string)$istenen);
    if ($istenen !== '') return $istenen;
    $varsayilan = trim((string)$varsayilan);
    return $varsayilan !== '' ? $varsayilan : null;
}

function pasifSubeKoru(PDO $db, string $tablo, int $id, ?string $istenen): ?string {
    $istenen = trim((string)$istenen);
    if ($istenen !== '' || $id < 1) return $istenen !== '' ? $istenen : null;
    if (!in_array($tablo, ['araclar', 'manliftler', 'yedek_parca', 'musteriler', 'kiralamalar'], true)) return null;
    $st = $db->prepare("SELECT t.sube, (SELECT COUNT(*) FROM subeler s WHERE s.ad = t.sube AND COALESCE(s.aktif,1)=1) AS aktif_mi FROM \"$tablo\" t WHERE t.id=?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $eski = trim((string)($r['sube'] ?? ''));
    return ($eski !== '' && (int)($r['aktif_mi'] ?? 0) === 0) ? $eski : null;
}

function subeKosulEkle(array &$where, array &$params, string $sutun = 'sube'): void {
    $k = subeKisiti();
    if ($k === null) return;
    $where[]  = "$sutun = ?";
    $params[] = $k;
}

function subeKayitDogrula(PDO $db, string $tablo, int $id, string $sutun = 'sube'): void {
    if (subeKisiti() === null) return;
    $st = $db->prepare("SELECT $sutun FROM $tablo WHERE id = ?");
    $st->execute([$id]);
    $v = $st->fetch(PDO::FETCH_NUM);
    if ($v === false) return;
    subeErisimDogrula($v[0]);
}

function hakedisSubeSql(string $h = 'h', string $m = 'm'): string {
    return "COALESCE(NULLIF(TRIM($h.sube), ''), NULLIF(TRIM($m.sube), ''), '')";
}

function hakedisEtkinSube(PDO $db, int $hakedisId): ?string {
    $st = $db->prepare("SELECT " . hakedisSubeSql('h', 'm') . " FROM hakedisler h LEFT JOIN musteriler m ON m.id = h.musteri_id WHERE h.id = ?");
    $st->execute([$hakedisId]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

function servisSubeSql(string $s = 's', string $k = 'k', string $ms = 'ms', string $mk = 'mk'): string {
    $m = "(CASE WHEN $s.musteri_id IS NOT NULL THEN NULLIF(TRIM($ms.sube), '') ELSE NULLIF(TRIM($mk.sube), '') END)";
    return "TRIM(COALESCE(CASE WHEN $k.id IS NOT NULL AND ($s.musteri_id IS NULL OR $s.musteri_id = $k.musteri_id) THEN COALESCE(NULLIF(TRIM($k.sube), ''), $m) ELSE $m END, ''))";
}

function subeErisimDogrula(?string $kayitSube): void {
    $k = subeKisiti();
    if ($k === null) return;
    if (trim((string)$kayitSube) !== $k) {
        jsonError('Bu kayıt başka bir şubeye ait; erişim yetkiniz yok.', 403);
    }
}

function sayfaNo(): int {
    return max(1, min(1000000, (int)metin($_GET['page'] ?? 1)));
}

function sayfalamaBilgisi(PDO $db, string $sqlNoLimit, array $params, int $perPage): array {
    $cnt = $db->prepare("SELECT COUNT(*) FROM ($sqlNoLimit) t");
    $cnt->execute($params);
    $toplam = (int)$cnt->fetchColumn();
    return [$toplam, max(1, (int)ceil($toplam / max(1, $perPage)))];
}

function str(string $key, array $src = []): string {
    $src = $src ?: $_POST;
    return trim((string)($src[$key] ?? ''));
}

function metin($v): string {
    if ($v === null || is_array($v) || is_object($v) || is_resource($v)) return '';
    if (is_bool($v)) return $v ? '1' : '';
    return (string)$v;
}

const GORSEL_MAX_PIKSEL = 24000000;

function gorselPikselUygun(string $ikili): bool {
    $b = $ikili !== '' ? @getimagesizefromstring($ikili) : false;
    return $b && $b[0] >= 1 && $b[1] >= 1 && $b[0] * $b[1] <= GORSEL_MAX_PIKSEL;
}

function gorselBoyutHatasi(string $ikili): ?string {
    $b = $ikili !== '' ? @getimagesizefromstring($ikili) : false;
    if (!$b || $b[0] * $b[1] <= GORSEL_MAX_PIKSEL) return null;
    return 'Fotoğraf çözünürlüğü çok yüksek (' . $b[0] . '×' . $b[1] . '). En fazla ' . (GORSEL_MAX_PIKSEL / 1000000) . ' megapiksel kabul edilir; fotoğrafı küçültüp yeniden yükleyin.';
}

function gorselVeriHatasi($dataUrl): ?string {
    if (!is_string($dataUrl) || !preg_match('~^data:image/[a-z]+;base64,([A-Za-z0-9+/=\s]+)$~', $dataUrl, $m)) return null;
    $ikili = base64_decode($m[1], true);
    return $ikili === false ? null : gorselBoyutHatasi($ikili);
}

function gorselListeHatasi($liste, string $alan = 'veri'): ?string {
    if (!is_array($liste)) return null;
    foreach (array_values($liste) as $i => $f) {
        $h = gorselVeriHatasi(is_array($f) ? ($f[$alan] ?? null) : $f);
        if ($h !== null) return ($i + 1) . '. fotoğraf: ' . $h;
    }
    return null;
}

function gorselBellekYeterli(int $w, int $h, bool $dondur = false, int $tur = IMAGETYPE_JPEG): bool {
    $sinir = trim((string)ini_get('memory_limit'));
    if ($sinir === '' || $sinir === '-1') return true;
    $sayi = (int)$sinir;
    $birim = strtolower(substr($sinir, -1));
    $bayt = $birim === 'g' ? $sayi * 1073741824 : ($birim === 'm' ? $sayi * 1048576 : ($birim === 'k' ? $sayi * 1024 : $sayi));
    $pikselBayt = $tur === IMAGETYPE_JPEG ? 4.2 : 7.5;
    if ($dondur) $pikselBayt = max($pikselBayt, 8.4);
    return memory_get_usage(true) + (int)ceil($w * $h * $pikselBayt) + 8 * 1048576 < $bayt;
}

function govdeCoz(string $ham, string $tip = ''): ?array {
    if (trim($ham) === '') return [];
    try {
        $v = json_decode($ham, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        return (stripos($tip, 'json') === false && !preg_match('/^\s*[\[{"]/', $ham)) ? [] : null;
    }
    if ($v === null) return [];
    return is_array($v) ? $v : null;
}

function govdeOku(): array {
    $g = govdeCoz((string)file_get_contents('php://input'), (string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if ($g === null) jsonError('Geçersiz istek gövdesi.', 400);
    return $g;
}

function dateStr(string $key, $src = []): ?string {
    if (!is_array($src)) $src = [];
    $src = $src ?: $_POST;
    $val = trim(metin($src[$key] ?? ''));
    if ($val === '' || $val === '0000-00-00' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) return null;
    $d = DateTime::createFromFormat('Y-m-d', $val);
    return ($d && $d->format('Y-m-d') === $val) ? $val : null;
}

function yillikIzinHakki(?string $iseGiris, string $referansTarih, int $ekstra = 0, int $bilinmeyenGirisHakki = 0): array {
    if (!$iseGiris) {
        return ['kidem' => 0, 'taban' => $bilinmeyenGirisHakki, 'toplam' => $bilinmeyenGirisHakki + $ekstra];
    }
    try {
        $kidem = (int)(new DateTime($iseGiris))->diff(new DateTime($referansTarih))->y;
    } catch (\Exception $e) {
        return ['kidem' => 0, 'taban' => $bilinmeyenGirisHakki, 'toplam' => $bilinmeyenGirisHakki + $ekstra];
    }
    $taban = $kidem < 1 ? 0 : ($kidem <= 5 ? 14 : ($kidem < 15 ? 20 : 26));
    return ['kidem' => $kidem, 'taban' => $taban, 'toplam' => $taban + $ekstra];
}

const BAKIM_YAKLASMA_ORANI = 0.9;

function saatBakimDurumu(array $row): ?array {
    $aralik = (float)($row['bakim_araligi_saat'] ?? 0);
    if ($aralik <= 0) return null;
    $calisma  = (float)($row['calisma_saati'] ?? 0);
    $sonBakim = (float)($row['son_bakim_saat'] ?? 0);
    $gecen    = max(0.0, $calisma - $sonBakim);
    if ($gecen >= $aralik)                          $durum = 'gecti';
    elseif ($gecen >= $aralik * BAKIM_YAKLASMA_ORANI) $durum = 'yaklasan';
    else                                             $durum = 'normal';
    return [
        'durum'       => $durum,
        'gecen_saat'  => round($gecen, 1),
        'kalan_saat'  => round($aralik - $gecen, 1),
        'aralik_saat' => round($aralik, 1),
        'calisma_saati' => $calisma,
    ];
}

function saatBakimSqlKosul(): string {
    return '(COALESCE(bakim_araligi_saat,0) > 0 AND '
         . '(COALESCE(calisma_saati,0) - COALESCE(son_bakim_saat,0)) >= COALESCE(bakim_araligi_saat,0) * '
         . BAKIM_YAKLASMA_ORANI . ')';
}

function iskontoOnayDurumuHesapla(PDO $db, float $iskonto, string $oncekiOnayDurumu): string {
    $esikStr = $db->query("SELECT deger FROM ayarlar WHERE anahtar='teklif_onay_esik_iskonto'")->fetchColumn();
    $esik = ($esikStr !== false && $esikStr !== '') ? (float)$esikStr : 10.0;
    if ($iskonto <= $esik) return 'gerekmiyor';
    if (in_array($oncekiOnayDurumu, ['onaylandi','reddedildi','bekliyor'], true)) return $oncekiOnayDurumu;
    return 'bekliyor';
}

function kiralamaGecerliIskonto(array $r): float {
    $oran = max(0, min(100, (float)($r['iskonto_oran'] ?? 0)));
    $onay = $r['onay_durumu'] ?? 'gerekmiyor';
    if (in_array($onay, ['bekliyor', 'reddedildi'], true)) return 0.0;
    return $oran;
}

const KIRA_BIRIM_GUN = ['gunluk' => 1, 'haftalik' => 7, 'aylik' => 30, 'yillik' => 365];

const MAKINE_YAKIT_TIPLERI = ['dizel' => 'Dizel', 'elektrik' => 'Elektrik', 'hibrit' => 'Hibrit', 'lpg' => 'LPG', 'benzin' => 'Benzin', 'diger' => 'Diğer'];

function makineYakitTipi($v): ?string {
    $v = is_string($v) ? trim($v) : '';
    return isset(MAKINE_YAKIT_TIPLERI[$v]) ? $v : null;
}

const MUSTERI_ODEME_TERCIHLERI = ['havale' => 'Havale / EFT', 'nakit' => 'Nakit', 'cek' => 'Çek', 'kredi_karti' => 'Kredi kartı', 'vadeli' => 'Vadeli (cari)'];

function musteriOdemeTercihi($v): ?string {
    $v = is_string($v) ? trim($v) : '';
    return isset(MUSTERI_ODEME_TERCIHLERI[$v]) ? $v : null;
}

function kiralamaMetinAlan(array $body, string $alan, int $max): ?string {
    $v = trim(preg_replace('/\s+/u', ' ', metin($body[$alan] ?? '')) ?? '');
    return $v !== '' ? mb_substr($v, 0, $max) : null;
}

function kiraListeFiyatAylik(array $m, ?string $tip): array {
    $tip = (string)($tip ?: 'gunluk');
    $alan = ['gunluk' => 'liste_fiyat_gunluk', 'haftalik' => 'liste_fiyat_haftalik', 'aylik' => 'liste_fiyat_aylik'][$tip] ?? null;
    $f = $alan ? (float)($m[$alan] ?? 0) : 0.0;
    if ($f > 0) return ['aylik' => round($f * 30 / kiraBirimGun($tip), 2), 'kaynak' => $tip];
    $a = (float)($m['liste_fiyat_aylik'] ?? 0);
    return ['aylik' => $a > 0 ? round($a, 2) : 0.0, 'kaynak' => $a > 0 ? 'aylik' : null];
}

function kiraBirimGun($tip): int {
    return KIRA_BIRIM_GUN[metin($tip)] ?? 1;
}

function kiraSuresi(float $gun, ?string $tip): float {
    return $gun / kiraBirimGun($tip);
}

function kiraTipAdi($tip): string {
    return ['gunluk' => 'GÜN', 'haftalik' => 'HAFTA', 'aylik' => 'AY', 'yillik' => 'YIL'][metin($tip)] ?? 'GÜN';
}

function kiraTipleri(): array {
    return array_keys(KIRA_BIRIM_GUN);
}

function kiralamaTutar(array $r): array {
    $bas = $r['baslangic'] ?? null;
    $bit = $r['gercek_bitis'] ?? null;
    if (!$bit) $bit = $r['bitis'] ?? null;
    if (($r['durum'] ?? '') === 'iptal') {
        return ['gun'=>0,'sure'=>0,'kira_bedeli'=>0,'nakliye'=>0,'ara_toplam'=>0,'kdv'=>0,'genel_toplam'=>0];
    }
    if (!$bit) $bit = date('Y-m-d');

    $gun = 1;
    if ($bas) {
        try {
            $d1 = new DateTime($bas);
            $d2 = new DateTime($bit);
            if ($d2 < $d1) $d2 = $d1;
            $gun = max(1, (int)$d1->diff($d2)->days + 1);
        } catch (\Exception $e) { $gun = 1; }
    }
    $gun = max(1, $gun);

    $tip   = $r['kira_tipi'] ?? 'gunluk';
    $sure  = kiraSuresi((float)$gun, $tip);
    $birim = (float)($r['birim_ucret'] ?? 0);
    $mik   = max(1, (int)($r['miktar'] ?? 1));
    $nak   = (float)($r['nakliye_ucret'] ?? 0);
    $kdvO  = (float)($r['kdv_oran'] ?? 20);

    $kira  = round($birim * $mik * $sure, 2);

    $iskontoOran  = kiralamaGecerliIskonto($r);
    $iskontoTutar = round($kira * $iskontoOran / 100, 2);
    $kira = round($kira - $iskontoTutar, 2);

    $opBedeli = 0.0;
    if (!empty($r['operatorlu']) && (float)($r['operator_ucret'] ?? 0) > 0) {
        $opU   = (float)$r['operator_ucret'];
        $opTip = $r['operator_ucret_tipi'] ?? 'gunluk';
        if ($opTip === 'sabit')      $opBedeli = $opU;
        elseif ($opTip === 'aylik')  $opBedeli = $opU * kiraSuresi((float)$gun, 'aylik');
        else                         $opBedeli = $opU * $gun;
        $opBedeli = round($opBedeli, 2);
    }

    $ara   = round($kira + $nak + $opBedeli, 2);
    $kdv   = round($ara * $kdvO / 100, 2);
    return [
        'gun'             => $gun,
        'sure'            => round($sure, 2),
        'kira_bedeli'     => $kira,
        'iskonto_oran'    => $iskontoOran,
        'iskonto_tutar'   => $iskontoTutar,
        'operator_bedeli' => $opBedeli,
        'nakliye'         => round($nak, 2),
        'ara_toplam'      => $ara,
        'kdv'             => $kdv,
        'genel_toplam'    => round($ara + $kdv, 2),
    ];
}

function kiralamaDonemKesisimi(array $k, string $donemBas, string $donemBit): ?array {
    $gercekSonGun = $k['gercek_bitis'] ?: ($k['bitis'] ?? null);
    $rEnd = $gercekSonGun ?: $donemBit;
    $dBas = max($k['baslangic'], $donemBas);
    $dBit = min($rEnd, $donemBit);
    if ($dBas > $dBit) return null;
    $gunSinir = date('Y-m-d', strtotime($dBit . ' +1 day'));
    $gun = max(0, (int)((new DateTime($dBas))->diff(new DateTime($gunSinir))->days));
    return ['dBas' => $dBas, 'dBit' => $dBit, 'gun' => $gun];
}

function makineServisMaliyetleri(PDO $db, string $bas, string $bit): array {
    $sonuc = [];
    try {
        $st = $db->prepare("SELECT makine_tur, makine_id, COALESCE(SUM(maliyet),0) t
                            FROM teknik_servis
                            WHERE tarih >= ? AND tarih <= ? AND makine_id IS NOT NULL
                            GROUP BY makine_tur, makine_id");
        $st->execute([$bas, $bit]);
        foreach ($st->fetchAll() as $r) {
            $sonuc[$r['makine_tur'] . ':' . $r['makine_id']] = round((float)$r['t'], 2);
        }
    } catch (\Exception $e) { hataGunlugu('maliyet.servis', $e, true); }
    return $sonuc;
}

function makineNakliyeMaliyetleri(PDO $db, string $bas, string $bit): array {
    $sonuc = [];
    $baglananKiralamaIds = [];
    try {
        $st = $db->prepare("
            SELECT k.makine_tur, k.makine_id, COALESCE(SUM(n.odenen),0) t, GROUP_CONCAT(DISTINCT n.kiralama_id) kids
            FROM nakliyeler n
            JOIN kiralamalar k ON k.id = n.kiralama_id
            WHERE n.kiralama_id IS NOT NULL AND n.tarih >= ? AND n.tarih <= ?
              AND k.makine_tur IS NOT NULL AND k.makine_id IS NOT NULL
            GROUP BY k.makine_tur, k.makine_id
        ");
        $st->execute([$bas, $bit]);
        foreach ($st->fetchAll() as $r) {
            $sonuc[$r['makine_tur'] . ':' . $r['makine_id']] = round((float)$r['t'], 2);
            foreach (explode(',', (string)$r['kids']) as $kid) if ($kid !== '') $baglananKiralamaIds[(int)$kid] = true;
        }

        $haric = $baglananKiralamaIds
            ? ' AND id NOT IN (' . implode(',', array_fill(0, count($baglananKiralamaIds), '?')) . ')'
            : '';
        $st2 = $db->prepare("
            SELECT makine_tur, makine_id, COALESCE(SUM(nakliye_maliyet),0) t
            FROM kiralamalar
            WHERE makine_tur IS NOT NULL AND makine_id IS NOT NULL AND nakliye_maliyet > 0
              AND baslangic >= ? AND baslangic <= ?" . $haric . "
              AND (siparis_no IS NULL OR siparis_no NOT IN (SELECT DISTINCT siparis_no FROM nakliyeler WHERE siparis_no IS NOT NULL))
            GROUP BY makine_tur, makine_id
        ");
        $st2->execute(array_merge([$bas, $bit], array_keys($baglananKiralamaIds)));
        foreach ($st2->fetchAll() as $r) {
            $key = $r['makine_tur'] . ':' . $r['makine_id'];
            $sonuc[$key] = round(($sonuc[$key] ?? 0) + (float)$r['t'], 2);
        }
    } catch (\Exception $e) { hataGunlugu('maliyet.nakliye', $e, true); }
    return $sonuc;
}

function siparisTasiyici(PDO $db, string $siparisNo): ?int {
    $st = $db->prepare("SELECT id FROM kiralamalar WHERE siparis_no=? ORDER BY (durum='iptal'), id LIMIT 1");
    $st->execute([$siparisNo]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int)$id;
}

function siparisNakliyeSenkron(PDO $db, ?string $siparisNo): void {
    if (!$siparisNo) return;
    islemIcinde($db, fn(PDO $db) => _siparisNakliyeSenkronYaz($db, $siparisNo));
}

function _siparisNakliyeSenkronYaz(PDO $db, string $siparisNo): void {
    $tasiyici = siparisTasiyici($db, $siparisNo);
    if (!$tasiyici) return;
    $st = $db->prepare("SELECT COALESCE(SUM(yansitilan),0) y, COALESCE(SUM(odenen),0) o FROM nakliyeler WHERE siparis_no=?");
    $st->execute([$siparisNo]); $t = $st->fetch();
    $ilk = $db->prepare("SELECT tip, yon, nakliyeci FROM nakliyeler WHERE siparis_no=? ORDER BY tarih, id LIMIT 1");
    $ilk->execute([$siparisNo]);
    $n = $ilk->fetch() ?: ['tip' => 'dis', 'yon' => 'gidis_donus', 'nakliyeci' => null];
    $db->prepare("UPDATE kiralamalar SET nakliye_ucret=0, nakliye_maliyet=0 WHERE siparis_no=? AND id<>?")->execute([$siparisNo, $tasiyici]);
    $db->prepare("UPDATE kiralamalar SET nakliye_ucret=?, nakliye_maliyet=?, nakliye_tip=?, nakliye_yon=?, nakliyeci=? WHERE id=?")
       ->execute([round((float)$t['y'], 2), round((float)$t['o'], 2), $n['tip'] === 'avk' ? 'avk' : 'dis',
                  in_array($n['yon'], ['gidis','donus','gidis_donus'], true) ? $n['yon'] : 'gidis_donus', $n['nakliyeci'], $tasiyici]);
}

function tarihTR($ham): ?string {
    $v = trim((string)$ham);
    if ($v === '' || $v === '0000-00-00') return null;
    $v = explode(' ', $v)[0];
    foreach (['Y-m-d', 'd.m.Y', 'j.n.Y', 'd/m/Y', 'j/n/Y', 'd-m-Y', 'j-n-Y', 'Y/m/d'] as $bicim) {
        $d = DateTime::createFromFormat($bicim, $v);
        if ($d && $d->format($bicim) === $v) return $d->format('Y-m-d');
    }
    return null;
}

function sayiTR($ham): float {
    if (is_int($ham) || is_float($ham)) return (float)$ham;
    $s = trim((string)$ham);
    if ($s === '') return 0.0;
    $eksi = str_starts_with($s, '-');
    $s = preg_replace('/[^0-9.,]/', '', $s) ?? '';
    if ($s === '') return 0.0;

    $sonNokta  = strrpos($s, '.');
    $sonVirgul = strrpos($s, ',');

    if ($sonNokta !== false && $sonVirgul !== false) {
        if ($sonVirgul > $sonNokta) $s = str_replace('.', '', $s);
        else                        $s = str_replace(',', '', $s);
        $s = str_replace(',', '.', $s);
    } elseif ($sonVirgul !== false) {
        $s = str_replace(',', '.', $s);
    } elseif ($sonNokta !== false) {
        if (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) $s = str_replace('.', '', $s);
    }

    $d = (float)$s;
    return $eksi ? -$d : $d;
}

function cekSenetSubeEfektif(PDO $db, array $c): ?string {
    $s = trim((string)($c['sube'] ?? ''));
    if ($s !== '') return $s;
    if (!empty($c['musteri_id'])) {
        $st = $db->prepare('SELECT sube FROM musteriler WHERE id=?');
        $st->execute([(int)$c['musteri_id']]);
        $ms = trim((string)($st->fetchColumn() ?: ''));
        if ($ms !== '') return $ms;
    }
    return null;
}
function cekSenetSubeIfade(): string {
    return "(CASE WHEN TRIM(COALESCE(c.sube,'')) != '' THEN TRIM(c.sube) ELSE TRIM(COALESCE(m.sube,'')) END)";
}

function cariBorcKalemleri(PDO $db, ?int $mid = null, ?string $bitSiniri = null): array {
    $sonuc = [];
    $ekle = function (int $m) use (&$sonuc) {
        if (!isset($sonuc[$m])) $sonuc[$m] = ['borclar' => [], 'faturalanmamis' => []];
    };

    $sqlD = "SELECT id, acilis_bakiye, acilis_tarihi FROM musteriler
             WHERE COALESCE(acilis_bakiye,0) <> 0" . ($mid ? " AND id=?" : "");
    $stD  = $db->prepare($sqlD);
    $stD->execute($mid ? [$mid] : []);
    foreach ($stD->fetchAll() as $d) {
        $m = (int)$d['id'];
        $ekle($m);
        $tarih = trim((string)($d['acilis_tarihi'] ?? '')) ?: '1900-01-01';
        if ($bitSiniri && $tarih > $bitSiniri) continue;
        $sonuc[$m]['borclar'][] = [
            'tarih'    => $tarih,
            'tutar'    => round((float)$d['acilis_bakiye'], 2),
            'aciklama' => 'Devir bakiyesi (önceki programdan aktarılan)',
        ];
    }

    $sql = "SELECT * FROM hakedisler" . ($mid ? " WHERE musteri_id=?" : "") . " ORDER BY musteri_id, donem_bas, id";
    $st  = $db->prepare($sql);
    $st->execute($mid ? [$mid] : []);

    $kiralamaKesilen = [];
    foreach ($st->fetchAll() as $h) {
        $m = (int)$h['musteri_id'];
        $ekle($m);
        $dovizli = hakedisDovizMi($h);
        $tlGenel = hakedisTlGenel($db, $h);
        $tutar = $tlGenel ?? 0.0;
        $kid   = (int)($h['kiralama_id'] ?? 0);
        if ($kid > 0) $kiralamaKesilen[$kid] = ($kiralamaKesilen[$kid] ?? 0) + ($dovizli ? (float)$h['doviz_genel'] : $tutar);
        if ($dovizli && $tlGenel === null) {
            $sonuc[$m]['faturalanmamis'][] = [
                'tarih'    => $h['donem_bit'] ?: ($h['donem_bas'] ?: substr((string)$h['created_at'], 0, 10)),
                'tutar'    => 0.0,
                'aciklama' => 'Hakediş ' . ($h['kod'] ?: '—') . ' — ' . number_format((float)$h['doviz_genel'], 2, ',', '.') . ' ' . $h['para_birimi'] . ', kur yok: TL karşılığı hesaplanamadı',
                'tip'      => 'hakedis',
            ];
            continue;
        }
        if ($tutar <= 0) continue;

        $faturali = in_array($h['durum'] ?? '', ['faturalandi', 'tahsil_edildi'], true)
                    || trim((string)($h['fatura_no'] ?? '')) !== '';

        $donemTarih = $h['donem_bit'] ?: ($h['donem_bas'] ?: substr((string)$h['created_at'], 0, 10));
        $tarih = $faturali ? ($h['fatura_tarihi'] ?: $donemTarih) : $donemTarih;
        if (!$tarih) continue;
        if ($bitSiniri && $tarih > $bitSiniri) continue;

        if ($faturali) {
            $et = 'Hakediş ' . ($h['kod'] ?: '')
                . ($h['fatura_no'] ? ' — Fatura ' . $h['fatura_no'] : '')
                . ($h['aciklama'] ? ' (' . $h['aciklama'] . ')' : '');
            $sonuc[$m]['borclar'][] = [
                'tarih'    => $tarih,
                'tutar'    => $tutar,
                'aciklama' => trim(preg_replace('/\s+/', ' ', $et) ?? ''),
                'vade'       => $h['vade_tarihi'] ?: null,
                'hakedis_id' => (int)$h['id'],
            ];
        } else {
            $sonuc[$m]['faturalanmamis'][] = [
                'tarih'    => $tarih,
                'tutar'    => round($tutar, 2),
                'aciklama' => 'Hakediş ' . ($h['kod'] ?: '—') . ' — henüz faturalanmadı',
                'tip'      => 'hakedis',
            ];
        }
    }

    $sqlH = "SELECT * FROM hasar_faturalari" . ($mid ? " WHERE musteri_id=?" : "") . " ORDER BY id";
    $stH  = $db->prepare($sqlH);
    $stH->execute($mid ? [$mid] : []);
    foreach ($stH->fetchAll() as $hf) {
        $m = (int)$hf['musteri_id'];
        $ekle($m);
        if ((int)$hf['faturalandi'] === 1) {
            $tarih = (string)$hf['fatura_tarihi'];
            if ($bitSiniri && $tarih > $bitSiniri) continue;
            $sonuc[$m]['borclar'][] = [
                'tarih'      => $tarih,
                'tutar'      => round((float)$hf['tl_genel'], 2),
                'aciklama'   => 'Hasar bedeli ' . $hf['ref'] . ($hf['fatura_no'] ? ' — Fatura ' . $hf['fatura_no'] : ''),
                'vade'       => $hf['vade_tarihi'] ?: null,
                'hakedis_id' => null,
                'kaynak'     => 'hasar:' . (int)$hf['id'],
            ];
        } elseif ($hf['para_birimi'] === 'TL') {
            $tarih = substr((string)$hf['created_at'], 0, 10);
            if ($bitSiniri && $tarih > $bitSiniri) continue;
            $sonuc[$m]['faturalanmamis'][] = [
                'tarih'    => $tarih,
                'tutar'    => round((float)$hf['genel_toplam'], 2),
                'aciklama' => 'Hasar bedeli ' . $hf['ref'] . ' — henüz faturalanmadı',
                'tip'      => 'hasar',
            ];
        }
    }

    require_once __DIR__ . '/servis-bedel-lib.php';
    $eskiServis = "COALESCE(s.fiyat_durum,'')='' AND COALESCE(s.maliyet_kaynak,'')<>'kalem' AND COALESCE(s.maliyet,0)>0 AND COALESCE(s.musteriye_yansit,0)=1 AND COALESCE(s.fatura_yontemi,'')<>'hakedis'";
    $sqlS = "SELECT s.id, s.tarih, s.created_at, s.makine_ad, s.faturalandi, s.fatura_no, s.fatura_tarihi,
                    CASE WHEN s.fiyat_durum='fiyatlandi' THEN s.musteri_genel_toplam ELSE s.maliyet END AS musteri_genel_toplam, COALESCE(s.musteri_id, k.musteri_id) AS mid
             FROM teknik_servis s LEFT JOIN kiralamalar k ON k.id=s.kiralama_id
             WHERE ((s.fatura_yontemi='ayri' AND s.fiyat_durum='fiyatlandi')
                    OR (COALESCE(s.faturalandi,0)=1 AND $eskiServis)
                    OR (COALESCE(s.faturalandi,0)=0 AND $eskiServis AND " . SM_FATURALANABILIR_SQL . "))
               AND COALESCE(s.musteri_id, k.musteri_id) IS NOT NULL" . ($mid ? " AND (s.musteri_id=? OR (s.musteri_id IS NULL AND k.musteri_id=?))" : "") . " ORDER BY s.id";
    $stS  = $db->prepare($sqlS);
    $stS->execute($mid ? [$mid, $mid] : []);
    foreach ($stS->fetchAll() as $sv) {
        $m = (int)$sv['mid'];
        $ekle($m);
        $no = sbBelgeNo($sv);
        if ((int)$sv['faturalandi'] === 1) {
            $tarih = (string)($sv['fatura_tarihi'] ?: substr((string)$sv['created_at'], 0, 10));
            if ($bitSiniri && $tarih > $bitSiniri) continue;
            $sonuc[$m]['borclar'][] = ['tarih' => $tarih, 'tutar' => round((float)$sv['musteri_genel_toplam'], 2),
                                       'aciklama' => 'Servis bedeli ' . $no . ($sv['fatura_no'] ? ' — Fatura ' . $sv['fatura_no'] : ''), 'vade' => null, 'hakedis_id' => null, 'kaynak' => 'servis:' . (int)$sv['id']];
        } else {
            $tarih = substr((string)($sv['created_at'] ?: $sv['tarih']), 0, 10);
            if ($bitSiniri && $tarih > $bitSiniri) continue;
            $sonuc[$m]['faturalanmamis'][] = ['tarih' => $tarih, 'tutar' => round((float)$sv['musteri_genel_toplam'], 2), 'aciklama' => 'Servis bedeli ' . $no . ' — henüz faturalanmadı', 'tip' => 'servis'];
        }
    }

    $sql2 = "SELECT * FROM kiralamalar WHERE durum<>'iptal'" . ($mid ? " AND musteri_id=?" : "") . " ORDER BY musteri_id, baslangic, id";
    $st2  = $db->prepare($sql2);
    $st2->execute($mid ? [$mid] : []);
    foreach ($st2->fetchAll() as $k) {
        $m = (int)$k['musteri_id'];
        $ekle($m);
        if ($bitSiniri && $k['baslangic'] > $bitSiniri) continue;

        $kEf = $k;
        if ($bitSiniri) {
            $son = $k['gercek_bitis'] ?: ($k['bitis'] ?: null);
            if (!$son || $son > $bitSiniri) $kEf['gercek_bitis'] = $bitSiniri;
        }
        $tut = kiralamaTutar($kEf)['genel_toplam'];
        if ($tut <= 0) continue;

        $kesilen = $kiralamaKesilen[(int)$k['id']] ?? 0;
        $kalan   = $tut - $kesilen;
        if ($kalan <= 0.5) continue;
        $kPara = dovizPara($k['doviz'] ?? 'TL');
        $dovizNot = '';
        if ($kPara !== 'TL') {
            $sk = dovizSonKur($db, $kPara, $bitSiniri ?: date('Y-m-d'), false);
            $dovizNot = ' · ' . number_format($kalan, 2, ',', '.') . ' ' . $kPara . ($sk ? ' × ' . $sk['kur'] . ' (geçici kur, ' . $sk['tarih'] . ')' : ', kur yok: TL karşılığı hesaplanamadı');
            $kalan = $sk ? round($kalan * $sk['kur'], 2) : 0.0;
        }

        $sonuc[$m]['faturalanmamis'][] = [
            'tarih'    => $k['baslangic'],
            'tutar'    => round($kalan, 2),
            'aciklama' => 'Kiralama ' . ($k['kod'] ? $k['kod'] . ' — ' : '') . ($k['ekipman_adi'] ?? '')
                          . ($kesilen > 0 ? ' (kısmen faturalandı)' : ' — hakediş kesilmedi') . $dovizNot,
            'tip'      => 'kiralama',
        ];
    }

    foreach ($sonuc as &$x) {
        usort($x['borclar'],        fn($a, $b) => strcmp((string)$a['tarih'], (string)$b['tarih']));
        usort($x['faturalanmamis'], fn($a, $b) => strcmp((string)$a['tarih'], (string)$b['tarih']));
    }
    unset($x);

    return $sonuc;
}

function alacakOzeti(PDO $db, ?array $musteriIdler = null, ?string $bugun = null, ?string $bitSiniri = null): array {
    $bugun = $bugun ?: date('Y-m-d');
    $filtre = $musteriIdler === null ? null : array_flip(array_map('intval', $musteriIdler));
    $tahsil = []; $bagli = [];
    $ts=$db->prepare("SELECT musteri_id, hakedis_id, COALESCE(SUM(tutar),0) t FROM tahsilatlar WHERE musteri_id IS NOT NULL".($bitSiniri!==null?" AND tarih<=?":"")." GROUP BY musteri_id, hakedis_id");
    $ts->execute($bitSiniri!==null?[$bitSiniri]:[]);
    foreach ($ts->fetchAll() as $r) {
        $tahsil[(int)$r['musteri_id']] = ($tahsil[(int)$r['musteri_id']] ?? 0.0) + (float)$r['t'];
        if ((int)$r['hakedis_id'] > 0) $bagli[(int)$r['musteri_id']][(int)$r['hakedis_id']] = (float)$r['t'];
    }
    require_once __DIR__.'/cek-cari-kurallari.php';
    $cekCari=crVeri($db,$bitSiniri);foreach($cekCari['mahsup'] as $mid=>$amount)$tahsil[$mid]=($tahsil[$mid]??0)+$amount;
    $out = ['acik' => 0.0, 'geciken' => 0.0, 'geciken_adet' => 0, 'faturalanmamis' => 0.0, 'musteri' => [], 'hakedis_kalan' => [], 'kalem_kalan' => []];
    foreach (cariBorcKalemleri($db, null, $bitSiniri) as $mid => $x) {
        if ($filtre !== null && !isset($filtre[(int)$mid])) continue;
        $out['faturalanmamis'] += array_sum(array_column($x['faturalanmamis'], 'tutar'));
        $kalemler = array_values(array_filter($x['borclar'], fn($b) => (float)$b['tutar'] > 0));
        usort($kalemler, fn($a, $b) => strcmp((string)$a['tarih'], (string)$b['tarih']));
        $devirAlacak = -array_sum(array_map(fn($b)=>min(0.0,(float)$b['tutar']),$x['borclar']));
        $havuz = ($tahsil[(int)$mid] ?? 0.0) + $devirAlacak;
        $kalanlar = [];
        foreach ($kalemler as $i => $b) {
            $kalanlar[$i] = (float)$b['tutar'];
            $hid = (int)($b['hakedis_id'] ?? 0);
            if ($hid && isset($bagli[(int)$mid][$hid])) {
                $dus = min($bagli[(int)$mid][$hid], $kalanlar[$i]);
                $kalanlar[$i] -= $dus; $havuz -= $dus;
            }
        }
        $acik = 0.0;
        foreach ($kalemler as $i => $b) {
            $kalan = $kalanlar[$i];
            $dus = min(max(0.0, $havuz), $kalan); $kalan -= $dus; $havuz -= $dus;
            if ($kalan <= 0.009) continue;
            $acik += $kalan;
            if (!empty($b['hakedis_id'])) $out['hakedis_kalan'][(int)$b['hakedis_id']] = round($kalan, 2);
            if (!empty($b['kaynak'])) $out['kalem_kalan'][(string)$b['kaynak']] = round($kalan, 2);
            if (!empty($b['vade']) && $b['vade'] < $bugun) { $out['geciken'] += $kalan; $out['geciken_adet']++; }
        }
        if ($acik > 0.009) { $out['musteri'][(int)$mid] = round($acik, 2); $out['acik'] += $acik; }
    }
    $out['cek_risk']=['bekliyor'=>0.0,'avukatlik'=>0.0,'batik'=>0.0];foreach($cekCari['risk'] as $mid=>$risk){if($filtre!==null&&!isset($filtre[(int)$mid]))continue;foreach($risk as $kind=>$amount)$out['cek_risk'][$kind]+=$amount;}
    $out['cek_uyarilari']=$filtre===null?$cekCari['uyarilar']:($cekCari['uyarilar']?['Çek tarih/geçmiş bilgilerini kontrol edin.']:[]);
    foreach (['acik', 'geciken', 'faturalanmamis'] as $k) $out[$k] = round($out[$k], 2);
    return $out;
}

function kiralamaTutarPencereli(array $k, string $bas, string $bit): ?float {
    if (($k['durum'] ?? '') === 'iptal') return null;
    $kesisim = kiralamaDonemKesisimi($k, $bas, $bit);
    if ($kesisim === null) return null;
    $unit  = KIRA_BIRIM_GUN;
    $tip   = $k['kira_tipi'] ?: 'gunluk';
    $gelir = (float)($k['birim_ucret'] ?? 0) * max(1, (int)($k['miktar'] ?? 1)) * ($kesisim['gun'] / ($unit[$tip] ?? 1));
    $iskontoOran = kiralamaGecerliIskonto($k);
    return round($gelir - round($gelir * $iskontoOran / 100, 2), 2);
}

function hakedisOdenen(PDO $db, int $hakedisId): float {
    $st = $db->prepare("SELECT COALESCE(SUM(tutar),0) FROM tahsilatlar WHERE hakedis_id=?");
    $st->execute([$hakedisId]);
    return round((float)$st->fetchColumn(), 2);
}

function hakedisOdenenToplu(PDO $db, array $hakedisIds): array {
    $out = array_fill_keys($hakedisIds, 0.0);
    if (!$hakedisIds) return $out;
    $ph = implode(',', array_fill(0, count($hakedisIds), '?'));
    $st = $db->prepare("SELECT hakedis_id, COALESCE(SUM(tutar),0) t FROM tahsilatlar WHERE hakedis_id IN ($ph) GROUP BY hakedis_id");
    $st->execute($hakedisIds);
    foreach ($st->fetchAll() as $r) $out[(int)$r['hakedis_id']] = round((float)$r['t'], 2);
    return $out;
}

function tarihCakisir(?string $b1, ?string $e1, ?string $b2, ?string $e2): bool {
    $b1 = $b1 ?: '0000-01-01'; $e1 = $e1 ?: '9999-12-31';
    $b2 = $b2 ?: '0000-01-01'; $e2 = $e2 ?: '9999-12-31';
    return $b1 < $e2 && $e1 > $b2;
}

function kiralamaCakismaBitisi(array $k, ?string $bugun = null): ?string {
    $gercek = trim((string)($k['gercek_bitis'] ?? ''));
    if ($gercek !== '') return $gercek;
    $plan = trim((string)($k['bitis'] ?? ''));
    if ($plan === '' || kiralamaIadeGecikti($k, $bugun)) return null;
    return $plan;
}

function kiralamaIadeGecikti(array $k, ?string $bugun = null): bool {
    $plan = trim((string)($k['bitis'] ?? ''));
    return ($k['durum'] ?? '') === 'aktif' && trim((string)($k['gercek_bitis'] ?? '')) === ''
        && $plan !== '' && $plan < ($bugun ?? date('Y-m-d'));
}

function kiralamaCakismalari(PDO $db, string $makineTur, int $makineId, ?string $bas, ?string $bit, int $haricId = 0, ?string $bugun = null): array {
    if ($makineId < 1 || $makineTur === '' || !$bas) return [];
    $sql = "SELECT k.id, k.kod, k.ekipman_adi, k.baslangic, k.bitis, k.gercek_bitis, k.durum, k.sube, m.unvan AS musteri_unvan
            FROM kiralamalar k JOIN musteriler m ON m.id=k.musteri_id
            WHERE k.makine_tur=? AND k.makine_id=? AND k.durum='aktif'" . ($haricId ? " AND k.id<>?" : "");
    $params = [$makineTur, $makineId];
    if ($haricId) $params[] = $haricId;
    $st = $db->prepare($sql);
    $st->execute($params);

    $cakisma = [];
    foreach ($st->fetchAll() as $r) {
        $rBit = kiralamaCakismaBitisi($r, $bugun);
        if (tarihCakisir($bas, $bit, $r['baslangic'], $rBit)) {
            $gecikti = kiralamaIadeGecikti($r, $bugun);
            $cakisma[] = ['kod' => $r['kod'], 'musteri_unvan' => $r['musteri_unvan'],
                          'baslangic' => $r['baslangic'], 'bitis' => $rBit,
                          'planli_bitis' => $gecikti ? $r['bitis'] : null, 'iade_gecikti' => $gecikti,
                          'sube' => $r['sube']];
        }
    }
    return $cakisma;
}

function makineDurumu(PDO $db, string $makineTur, int $makineId): ?string {
    $tablo = $makineTur === 'arac' ? 'araclar' : ($makineTur === 'manlift' ? 'manliftler' : null);
    if (!$tablo || $makineId < 1) return null;
    $st = $db->prepare("SELECT durum FROM $tablo WHERE id=?");
    $st->execute([$makineId]);
    $d = $st->fetchColumn();
    if ($d === false) return null;
    $durum = (string)$d;

    if (!in_array($durum, ['satildi', 'pasif', 'nakliyede', 'serviste'], true)
        && makineAcikServisVarMi($db, $makineTur, $makineId)) {
        return 'serviste';
    }
    if ($durum === 'musait' && makineKiradaMi($db, $makineTur, $makineId)) {
        return 'kirada';
    }
    return $durum;
}

function kiralamaMakineDurumu(PDO $db, ?string $tur, ?int $id, string $olay): void {
    if (!in_array($tur, ['arac','manlift'], true) || !$id) return;
    $tablo = $tur === 'arac' ? 'araclar' : 'manliftler';
    if ($olay === 'inceleme') $db->prepare("UPDATE teknik_servis SET makine_baska_iste=0 WHERE makine_tur=? AND makine_id=? AND makine_baska_iste=1 AND durum<>'tamamlandi'")->execute([$tur, $id]);

    $st = $db->prepare("SELECT durum FROM $tablo WHERE id=?");
    $st->execute([$id]);
    $mevcut = (string)($st->fetchColumn() ?: 'musait');

    if (in_array($mevcut, ['satildi','pasif'], true)) return;
    if ($mevcut === 'inceleme_bekliyor' && $olay !== 'inceleme_bitti') return;
    if (!in_array($olay, ['inceleme', 'inceleme_bitti'], true) && makineAcikServisVarMi($db, $tur, $id)) return;

    $yeni = null;
    switch ($olay) {
        case 'siparis':   $yeni = 'kiraya_gidecek'; break;
        case 'teslim':    $yeni = 'musait'; break;
        case 'nakliye':   $yeni = 'nakliyede'; break;
        case 'toplaniyor':$yeni = 'kiradan_gelecek'; break;
        case 'iade':      $yeni = 'bakim_bekliyor'; break;
        case 'bakima':    $yeni = 'bakimda'; break;
        case 'bakim_bitti': $yeni = 'musait'; break;
        case 'inceleme':      $yeni = 'inceleme_bekliyor'; break;
        case 'inceleme_bitti':$yeni = 'musait'; break;
    }
    if ($yeni === null || $yeni === $mevcut) return;
    $db->prepare("UPDATE $tablo SET durum=? WHERE id=?")->execute([$yeni, $id]);
}

function makineAcikServisVarMi(PDO $db, string $makineTur, int $makineId): bool {
    if (!in_array($makineTur, ['arac', 'manlift'], true) || $makineId < 1) return false;
    $st = $db->prepare("SELECT 1 FROM teknik_servis WHERE makine_tur=? AND makine_id=? AND durum<>'tamamlandi' AND COALESCE(makine_baska_iste,0)=0 LIMIT 1");
    $st->execute([$makineTur, $makineId]);
    return (bool)$st->fetchColumn();
}

function makineDonusEngeli(PDO $db, string $tur, int $id): ?string {
    if (!in_array($tur, ['arac', 'manlift'], true) || $id < 1) return null;
    $h = $db->prepare('SELECT durum FROM ' . ($tur === 'arac' ? 'araclar' : 'manliftler') . ' WHERE id=?');
    $h->execute([$id]);
    if ((string)$h->fetchColumn() === 'inceleme_bekliyor') return 'Bu makine dönüş kontrolü bekliyor; kontrol yapılmadan kiraya verilemez (Teknik Servis → Makine Kontrolleri → Dönüş kontrolü).';
    $d = makineDurumu($db, $tur, $id);
    if ($d === 'inceleme_bekliyor') return 'Bu makine dönüş kontrolü bekliyor; kontrol yapılmadan kiraya verilemez (Teknik Servis → Makine Kontrolleri → Dönüş kontrolü).';
    if ($d === 'serviste') {
        $s = $db->prepare("SELECT 1 FROM teknik_servis WHERE makine_tur=? AND makine_id=? AND durum<>'tamamlandi' AND (kaynak IN ('depo_inceleme','donus_kontrolu') OR donus_kontrol_id IS NOT NULL) LIMIT 1");
        $s->execute([$tur, $id]);
        if ($s->fetchColumn()) return 'Bu makine dönüş kontrolünden servise gönderildi (Serviste – bakımda); servis kapanmadan kiraya verilemez.';
        $s = $db->prepare("SELECT 1 FROM hasar_degerlendirmeleri h JOIN teknik_servis t ON t.id=h.servis_id
                           WHERE h.depo_kontrol_id IS NOT NULL AND t.makine_tur=? AND t.makine_id=? AND t.durum<>'tamamlandi' LIMIT 1");
        $s->execute([$tur, $id]);
        if ($s->fetchColumn()) return 'Bu makine dönüş kontrolünden servise gönderildi (Serviste – bakımda); servis kapanmadan kiraya verilemez.';
    }
    $k = $db->prepare("SELECT * FROM kiralamalar WHERE makine_tur=? AND makine_id=? AND durum<>'iptal'
                       AND (durum<>'aktif' OR gercek_bitis IS NOT NULL) ORDER BY id DESC LIMIT 1");
    $k->execute([$tur, $id]);
    $son = $k->fetch(PDO::FETCH_ASSOC);
    require_once __DIR__ . '/operasyon-adim.php';
    if ($son && opSiradakiAdim($db, $son) === 'depo_inceleme')
        return 'Bu makine dönüş kontrolü bekliyor; kontrol yapılmadan kiraya verilemez (Teknik Servis → Makine Kontrolleri → Dönüş kontrolü).';
    return null;
}

const KIRALANAMAZ_DURUMLAR = ['satildi', 'pasif'];

function kiralamaKaydiKontrol(PDO $db, ?string $makineTur, ?int $makineId, ?string $bas, ?string $bit, int $haricId = 0): array {
    $bos = ['engel' => null, 'engel_kod' => 0, 'cakisma' => []];
    if (!$makineTur || !$makineId) return $bos;

    $kisit = subeKisiti();
    if ($kisit !== null) {
        $tablo = $makineTur === 'arac' ? 'araclar' : ($makineTur === 'manlift' ? 'manliftler' : null);
        if ($tablo) {
            $ms = $db->prepare("SELECT sube FROM $tablo WHERE id=?");
            $ms->execute([$makineId]);
            $mSube = $ms->fetchColumn();
            if ($mSube !== false && trim((string)$mSube) !== $kisit) {
                return ['engel' => 'Bu makine başka bir şubeye ait (' . (trim((string)$mSube) ?: 'şubesiz')
                    . '); kendi şubenizin makinelerini kiralayabilirsiniz.', 'engel_kod' => 403, 'cakisma' => []];
            }
        }
    }

    $durum = makineDurumu($db, $makineTur, $makineId);
    if ($durum !== null && in_array($durum, KIRALANAMAZ_DURUMLAR, true)) {
        $etiket = $durum === 'satildi' ? 'satılmış' : 'pasif';
        return ['engel' => "Bu ekipman \"$etiket\" durumda; kiraya verilemez. Kiralamak için önce ekipmanın durumunu güncelleyin.",
                 'engel_kod' => 400, 'cakisma' => []];
    }
    $bugun = date('Y-m-d');
    $yeniMakine = $haricId === 0;
    if (!$yeniMakine) {
        $km = $db->prepare('SELECT makine_tur, makine_id FROM kiralamalar WHERE id=?');
        $km->execute([$haricId]);
        $eskiM = $km->fetch(PDO::FETCH_ASSOC);
        $yeniMakine = $eskiM && ((string)$eskiM['makine_tur'] !== $makineTur || (int)$eskiM['makine_id'] !== $makineId);
    }
    if ($yeniMakine && $bas && $bas <= $bugun && (!$bit || $bit >= $bugun) && ($m = makineDonusEngeli($db, $makineTur, $makineId)))
        return ['engel' => $m, 'engel_kod' => 409, 'cakisma' => []];

    require_once __DIR__ . '/dis-kiralama-kurallari.php';
    if($haricId>0){
        $snapshot=$db->prepare('SELECT d.makine_tur,d.makine_id,d.id FROM kiralama_dis_maliyetleri s JOIN dis_kiralama_kayitlari d ON d.id=s.dis_kiralama_id WHERE s.kiralama_id=?');
        $snapshot->execute([$haricId]);$saved=$snapshot->fetch(PDO::FETCH_ASSOC);
        if($saved && ($saved['makine_tur']!==$makineTur||(int)$saved['makine_id']!==$makineId))return ['engel'=>'DK maliyeti kaydedilmiş kiralamada Makine Değişimi işlemini kullanın.','engel_kod'=>409,'cakisma'=>[]];
    }
    $dk=dkDonemKontrol($db,$makineTur,$makineId,$bas,$bit);
    if($dk['engel']!==null)return ['engel'=>$dk['engel'],'engel_kod'=>409,'cakisma'=>[]];
    return ['engel' => null, 'engel_kod' => 0, 'cakisma' => kiralamaCakismalari($db, $makineTur, $makineId, $bas, $bit, $haricId)];
}

function kiralamaKaydiDogrula(PDO $db, ?string $makineTur, ?int $makineId, ?string $bas, ?string $bit, bool $cakismaOnaylandi, int $haricId = 0): void {
    $r = kiralamaKaydiKontrol($db, $makineTur, $makineId, $bas, $bit, $haricId);
    if ($r['engel'] !== null) jsonError($r['engel'], $r['engel_kod']);

    if ($cakismaOnaylandi) return;
    require_once __DIR__ . '/hizli-kiralama-lib.php';
    $cakisma = hkCakismaMaskele($r['cakisma'], subeKisiti());
    if (!$cakisma) return;
    $ilk = $cakisma[0];
    jsonError('Bu ekipman seçilen tarihlerde zaten kirada: '
        . ($ilk['kod'] ?: '#') . ' — ' . $ilk['musteri_unvan']
        . ' (' . $ilk['baslangic'] . ' → ' . (!empty($ilk['iade_gecikti'])
            ? 'planlı iade ' . $ilk['planli_bitis'] . ', makine henüz iade edilmedi'
            : ($ilk['bitis'] ?: 'açık uç')) . ')'
        . (count($cakisma) > 1 ? ' ve ' . (count($cakisma) - 1) . ' kayıt daha.' : '.'),
        409, ['onaylanabilir' => true, 'cakisma' => $cakisma]);
}

function _katalogNrm(?string $s): string {
    $s = mb_strtolower((string)$s, 'UTF-8');
    $s = strtr($s, ['ı'=>'i','İ'=>'i','ş'=>'s','ğ'=>'g','ü'=>'u','ö'=>'o','ç'=>'c','â'=>'a']);
    return preg_replace('/[^a-z0-9]/', '', $s) ?? '';
}

function katalogKategorileri(PDO $db): array {
    static $onbellek = null;
    if ($onbellek !== null) return $onbellek;
    $onbellek = [];
    try {
        foreach ($db->query("SELECT id, isim, kategori, gorsel, gorsel_yerel FROM kiralama_urunler")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $onbellek[] = ['nrm' => _katalogNrm($r['isim']), 'kategori' => $r['kategori'], 'id' => (int)$r['id'], 'isim' => (string)$r['isim'],
                           'gorsel_url' => $r['gorsel_yerel'] ? 'api/kiralama.php?action=gorsel&id=' . (int)$r['id']
                                         : ((string)($r['gorsel'] ?? '') !== '' ? (string)$r['gorsel'] : null)];
        }
    } catch (\Throwable $e) { hataGunlugu('katalog.kategoriler', $e); }
    return $onbellek;
}

function makineKatalogUrunu(PDO $db, ?string $marka, ?string $model): ?array {
    $katalog = katalogKategorileri($db);
    if (!$katalog) return null;

    $m = _katalogNrm($model);
    if (strlen($m) >= 4) {
        foreach ($katalog as $k) if ($k['nrm'] !== '' && str_contains($k['nrm'], $m)) return $k;
    }
    $mm = _katalogNrm(((string)$marka) . ((string)$model));
    if (strlen($mm) >= 6) {
        foreach ($katalog as $k) if ($k['nrm'] !== '' && str_contains($k['nrm'], $mm)) return $k;
    }
    return null;
}

function makineKategorisi(PDO $db, ?string $marka, ?string $model): ?string {
    return makineKatalogUrunu($db, $marka, $model)['kategori'] ?? null;
}

function metrajSayi($v): ?float {
    if ($v === null) return null;
    if (is_int($v) || is_float($v)) return $v > 0 ? (float)$v : null;
    $s = trim((string)$v);
    if ($s === '' || !preg_match('/^(\d+(?:[.,]\d+)?)\s*(?:m|mt|metre|meter|ton|t)?\.?$/iu', $s, $m)) return null;
    $f = (float)str_replace(',', '.', $m[1]);
    return $f > 0 ? $f : null;
}
function metrajNormal($v): ?string {
    $f = metrajSayi($v);
    if ($f !== null) return rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.');
    $s = trim((string)($v ?? ''));
    return $s === '' ? null : $s;
}

function _makineDurumTuret(string $ham, bool $kirada, bool $serviste): string {
    if (!in_array($ham, ['satildi', 'pasif', 'nakliyede', 'serviste'], true) && $serviste) {
        return 'serviste';
    }
    if ($ham === 'musait' && $kirada) {
        return 'kirada';
    }
    return $ham;
}

function makineListesi(PDO $db): array {
    $kiradaManlift = array_flip(makineKiradaSeti($db, 'manlift'));
    $servisManlift = array_flip(makineAcikServisSeti($db, 'manlift'));
    $kiradaArac    = array_flip(makineKiradaSeti($db, 'arac'));
    $servisArac    = array_flip(makineAcikServisSeti($db, 'arac'));

    $out = [];
    foreach ($db->query("SELECT id, kod, marka, model, seri_no, sube, alis_bedeli, faydali_omur_yil, aktif, metraj, durum, makine_kodu, temin_sekli, kategori FROM manliftler ORDER BY kod")->fetchAll() as $m) {
        $id = (int)$m['id'];
        $kat = makineKategoriAdi($db, $m);
        $out[] = [
            'tur' => 'manlift', 'id' => $id, 'temin_sekli' => $m['temin_sekli'] ?? 'stok',
            'ad'  => trim($m['kod'] . ($m['marka'] ? ' — ' . $m['marka'] : '')),
            'kategori' => 'Makine', 'sube' => $m['sube'], 'marka' => $m['marka'],
            'katalog_kategori' => $kat, 'olcu_birim' => mkBirim($db, $kat),
            'kod' => $m['kod'], 'model' => $m['model'] ?? null, 'seri_no' => $m['seri_no'] ?? null,
            'metraj' => metrajSayi($m['metraj'] ?? null),
            'makine_kodu' => $m['makine_kodu'] ?? null,
            'alis_bedeli' => (float)$m['alis_bedeli'], 'faydali_omur_yil' => (int)$m['faydali_omur_yil'],
            'aktif' => isset($m['aktif']) ? (int)$m['aktif'] : 1,
            'durum' => _makineDurumTuret((string)($m['durum'] ?: 'musait'), isset($kiradaManlift[$id]), isset($servisManlift[$id])),
            'durum_ham' => (string)($m['durum'] ?: 'musait'),
        ];
    }
    foreach ($db->query("SELECT id, plaka, marka, model, sube, alis_bedeli, faydali_omur_yil, aktif, durum, makine_kodu, temin_sekli, metraj, kategori FROM araclar ORDER BY plaka")->fetchAll() as $a) {
        $id = (int)$a['id'];
        $kat = makineKategoriAdi($db, $a);
        $out[] = [
            'tur' => 'arac', 'id' => $id, 'temin_sekli' => $a['temin_sekli'] ?? 'stok',
            'ad'  => trim($a['plaka'] . ($a['marka'] ? ' — ' . $a['marka'] : '')),
            'kategori' => 'Araç', 'sube' => $a['sube'], 'marka' => $a['marka'], 'metraj' => metrajSayi($a['metraj'] ?? null),
            'katalog_kategori' => $kat, 'olcu_birim' => mkBirim($db, $kat),
            'kod' => $a['plaka'], 'model' => $a['model'] ?? null, 'seri_no' => null,
            'makine_kodu' => $a['makine_kodu'] ?? null,
            'alis_bedeli' => (float)$a['alis_bedeli'], 'faydali_omur_yil' => (int)$a['faydali_omur_yil'],
            'aktif' => isset($a['aktif']) ? (int)$a['aktif'] : 1,
            'durum' => _makineDurumTuret((string)($a['durum'] ?: 'musait'), isset($kiradaArac[$id]), isset($servisArac[$id])),
            'durum_ham' => (string)($a['durum'] ?: 'musait'),
        ];
    }
    $dk = dkMakineOzeti($db);
    foreach ($out as &$m) {
        if (($m['temin_sekli'] ?? 'stok') === 'dis') $m['dk'] = $dk[$m['tur'] . ':' . $m['id']] ?? ['donemler' => [], 'aktif' => false];
    }
    unset($m);
    return $out;
}

function dkMakineOzeti(PDO $db): array {
    $bugun = date('Y-m-d');
    $fiyatGor = hasPermission('dis_kiralama', 'oku');
    $out = [];
    try {
        $rows = $db->query("SELECT d.makine_tur, d.makine_id, d.temin_baslangic, d.temin_bitis, d.durum,
                                   d.maliyet_tipi, d.maliyet_birim_tutari, d.maliyet_para_birimi,
                                   d.avk_liste_fiyati, d.avk_min_fiyati, d.nakliye_gelis, d.nakliye_iade, t.unvan AS tedarikci
                            FROM dis_kiralama_kayitlari d LEFT JOIN tedarikciler t ON t.id = d.tedarikci_id
                            ORDER BY d.temin_baslangic, d.id")->fetchAll();
    } catch (\Throwable $e) { return []; }
    foreach ($rows as $r) {
        $k = $r['makine_tur'] . ':' . (int)$r['makine_id'];
        $o = $out[$k] ?? ['donemler' => [], 'aktif' => false];
        if (in_array($r['durum'], ['musait', 'rezerve', 'musteride'], true)) {
            $o['donemler'][] = [$r['temin_baslangic'], $r['temin_bitis'] ?: null];
            if (!$r['temin_bitis'] || $r['temin_bitis'] >= $bugun) $o['aktif'] = true;
        }
        if ($fiyatGor) {
            $o['fiyat'] = [
                'tedarikci' => $r['tedarikci'], 'maliyet_tipi' => $r['maliyet_tipi'],
                'maliyet' => (float)$r['maliyet_birim_tutari'], 'para_birimi' => $r['maliyet_para_birimi'],
                'liste' => (float)$r['avk_liste_fiyati'], 'min' => (float)$r['avk_min_fiyati'],
                'nakliye_gelis' => (float)$r['nakliye_gelis'], 'nakliye_iade' => (float)$r['nakliye_iade'],
            ];
        }
        $out[$k] = $o;
    }
    return $out;
}

function makineBilgiToplu(PDO $db, array $refler): array {
    $idler = ['manlift' => [], 'arac' => []];
    foreach ($refler as $r) {
        $tur = $r['tur'] ?? null;
        $id  = (int)($r['id'] ?? 0);
        if (!in_array($tur, ['manlift', 'arac'], true) || $id < 1) continue;
        $idler[$tur][$id] = true;
    }

    $out = [];
    foreach (['manlift', 'arac'] as $tur) {
        $ids = array_keys($idler[$tur]);
        if (!$ids) continue;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $kirada   = array_flip(makineKiradaSeti($db, $tur));
        $serviste = array_flip(makineAcikServisSeti($db, $tur));

        if ($tur === 'manlift') {
            $st = $db->prepare("SELECT id, kod, marka, model, metraj, seri_no, makine_kodu, durum, kategori, sube FROM manliftler WHERE id IN ($ph)");
            $st->execute($ids);
            foreach ($st->fetchAll() as $m) {
                $id = (int)$m['id'];
                $out["manlift:$id"] = [
                    'kod' => $m['kod'], 'marka' => $m['marka'], 'model' => $m['model'],
                    'kategori' => ($mkKat = makineKategoriAdi($db, $m)), 'olcu_birim' => mkBirim($db, $mkKat),
                    'metraj' => metrajSayi($m['metraj'] ?? null),
                    'seri_no' => $m['seri_no'] ?? null,
                    'makine_kodu' => $m['makine_kodu'] ?? null,
                    'sube' => $m['sube'] ?? null,
                    'durum' => _makineDurumTuret((string)($m['durum'] ?: 'musait'), isset($kirada[$id]), isset($serviste[$id])),
                ];
            }
        } else {
            $st = $db->prepare("SELECT id, plaka, marka, model, makine_kodu, durum, kategori, metraj, sube FROM araclar WHERE id IN ($ph)");
            $st->execute($ids);
            foreach ($st->fetchAll() as $a) {
                $id = (int)$a['id'];
                $out["arac:$id"] = [
                    'kod' => $a['plaka'], 'marka' => $a['marka'], 'model' => $a['model'],
                    'kategori' => ($mkKat = makineKategoriAdi($db, $a)), 'olcu_birim' => mkBirim($db, $mkKat),
                    'metraj' => metrajSayi($a['metraj'] ?? null), 'seri_no' => null,
                    'makine_kodu' => $a['makine_kodu'] ?? null,
                    'sube' => $a['sube'] ?? null,
                    'durum' => _makineDurumTuret((string)($a['durum'] ?: 'musait'), isset($kirada[$id]), isset($serviste[$id])),
                ];
            }
        }
    }
    return $out;
}

function kiralamaMakineKunye(PDO $db, array $k): ?array {
    $tur = (string)($k['makine_tur'] ?? '');
    $id = (int)($k['makine_id'] ?? 0);
    $b = $tur !== '' && $id > 0 ? (makineBilgiToplu($db, [['tur' => $tur, 'id' => $id]])["$tur:$id"] ?? null) : null;
    $metraj = metrajSayi($k['metraj'] ?? null) ?? ($b['metraj'] ?? null);
    $seri = trim((string)($k['seri_no'] ?? '')) ?: ($b['seri_no'] ?? null);
    $stok = trim((string)($k['makine_kodu'] ?? '')) ?: ($b['makine_kodu'] ?? null);
    if (!$b && $metraj === null && !$seri && !$stok) return null;
    return ['kod' => $b['kod'] ?? null, 'marka' => $b['marka'] ?? null, 'model' => $b['model'] ?? null, 'kategori' => $b['kategori'] ?? null,
            'olcu_birim' => $b['olcu_birim'] ?? null, 'metraj' => $metraj, 'seri_no' => $seri ?: null, 'makine_kodu' => $stok ?: null];
}

function hakedisMakineKunye(PDO $db, $kiralamaId): ?array {
    $id = (int)$kiralamaId;
    if ($id < 1) return null;
    $st = $db->prepare("SELECT makine_tur, makine_id, metraj, seri_no, makine_kodu FROM kiralamalar WHERE id=?");
    $st->execute([$id]);
    $k = $st->fetch(PDO::FETCH_ASSOC);
    return $k ? kiralamaMakineKunye($db, $k) : null;
}

const BELGE_KABUL_CUMLESI = 'Aşağıdaki maddeler bu belgenin ayrılmaz parçasıdır; taraflar okuyup kabul etmiştir.';

function sozlesmeSartlari(PDO $db): ?string {
    $sira = ['kiralama_sozlesme_sartlari', 'kiralama_protokol_sartlari'];
    try {
        $q = $db->prepare("SELECT anahtar, deger FROM ayarlar WHERE anahtar IN ('kiralama_sozlesme_sartlari', 'kiralama_protokol_sartlari')");
        $q->execute();
        $a = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $a[$r['anahtar']] = trim((string)$r['deger']);
    } catch (\Throwable $e) { return null; }
    foreach ($sira as $k) if (($a[$k] ?? '') !== '') return $a[$k];
    return null;
}

function makineKiradaMi(PDO $db, string $tur, int $id): bool {
    $st = $db->prepare("SELECT 1 FROM kiralamalar WHERE makine_tur=? AND makine_id=? AND durum='aktif' AND gercek_bitis IS NULL LIMIT 1");
    $st->execute([$tur, $id]);
    return (bool)$st->fetchColumn();
}

function makineKiradaSeti(PDO $db, string $tur): array {
    $st = $db->prepare("SELECT DISTINCT makine_id FROM kiralamalar WHERE makine_tur=? AND durum='aktif' AND gercek_bitis IS NULL AND makine_id IS NOT NULL");
    $st->execute([$tur]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

function makineAcikServisSeti(PDO $db, string $tur): array {
    $st = $db->prepare("SELECT DISTINCT makine_id FROM teknik_servis WHERE makine_tur=? AND durum<>'tamamlandi' AND makine_id IS NOT NULL AND COALESCE(makine_baska_iste,0)=0");
    $st->execute([$tur]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

function makineDurumServisSonrasiGuncelle(PDO $db, string $tur, int $id): void {
    if (!in_array($tur, ['arac', 'manlift'], true)) return;
    $acikVarMi = $db->prepare("SELECT 1 FROM teknik_servis WHERE makine_tur=? AND makine_id=? AND durum<>'tamamlandi' AND COALESCE(makine_baska_iste,0)=0 LIMIT 1");
    $acikVarMi->execute([$tur, $id]);
    if ($acikVarMi->fetchColumn()) return;
    $tablo = $tur === 'arac' ? 'araclar' : 'manliftler';
    $db->prepare("UPDATE $tablo SET durum='musait' WHERE id=? AND durum IN ('serviste','bakimda','bakim_bekliyor')")->execute([$id]);
}

function teknikServisMakineDurumSenkron(PDO $db, ?string $tur, ?int $id, string $servisDurum, ?string $servisTipi = null, ?string $baslangic = null, ?string $bitis = null): void {
    if (!$tur || !$id || !in_array($tur, ['arac', 'manlift'], true)) return;
    if ($servisDurum === 'tamamlandi') {
        makineDurumServisSonrasiGuncelle($db, $tur, $id);
        return;
    }
    $bugun = date('Y-m-d');
    if ($baslangic !== null && $bitis !== null && $baslangic > $bugun && $bitis >= $baslangic) return;
    $yeni = ($servisTipi === 'bakim') ? 'bakimda' : 'serviste';
    $tablo = $tur === 'arac' ? 'araclar' : 'manliftler';
    $db->prepare("UPDATE $tablo SET durum=? WHERE id=? AND durum NOT IN ('satildi','nakliyede','pasif','inceleme_bekliyor')")
       ->execute([$yeni, $id]);
}

function teknikServisAcikKaydiBul(PDO $db, ?string $tur, ?int $id, ?int $haric = null): ?array {
    if (!$tur || !$id || !in_array($tur, ['arac', 'manlift'], true)) return null;
    $sql = "SELECT id, ariza, notlar FROM teknik_servis WHERE makine_tur=? AND makine_id=? AND durum IN ('acik','devam')";
    $params = [$tur, $id];
    if ($haric !== null) { $sql .= " AND id<>?"; $params[] = $haric; }
    $sql .= " ORDER BY id DESC LIMIT 1";
    $st = $db->prepare($sql);
    $st->execute($params);
    $r = $st->fetch();
    return $r ?: null;
}

function teknikServisOperatorBildirimIsle(PDO $db, int $kiralamaId, string $aciklama, ?int $personelId): array {
    $k = $db->prepare("SELECT makine_tur, makine_id, ekipman_adi FROM kiralamalar WHERE id=?");
    $k->execute([$kiralamaId]);
    $kira = $k->fetch();
    if (!$kira || !$kira['makine_tur'] || !$kira['makine_id']) return ['durum' => 'makinesiz'];

    $tur = $kira['makine_tur']; $id = (int)$kira['makine_id'];
    $mevcut = teknikServisAcikKaydiBul($db, $tur, $id);
    if ($mevcut) {
        $zamanli = '[' . date('d.m.Y H:i') . '] Sahadan yeni bildirim: ' . ($aciklama ?: '(açıklama girilmedi)');
        $yeniNot = trim((string)($mevcut['notlar'] ?? '')) !== '' ? $mevcut['notlar'] . "\n" . $zamanli : $zamanli;
        $db->prepare("UPDATE teknik_servis SET notlar=?, updated_at=datetime('now','localtime') WHERE id=?")
           ->execute([$yeniNot, (int)$mevcut['id']]);
        return ['durum' => 'eklendi', 'id' => (int)$mevcut['id']];
    }

    $db->prepare("
        INSERT INTO teknik_servis (makine_tur, makine_id, makine_ad, tip, tarih, durum, oncelik, ariza, kaynak, kiralama_id)
        VALUES (?,?,?,'ariza',?, 'acik', 'normal', ?, 'operator', ?)
    ")->execute([$tur, $id, $kira['ekipman_adi'] ?: null, date('Y-m-d'), $aciklama ?: 'Operatör sahadan arıza bildirdi.', $kiralamaId]);
    $yeniId = (int)$db->lastInsertId();
    teknikServisMakineDurumSenkron($db, $tur, $id, 'acik');
    logla($db, 'teknik_servis', $yeniId, 'ekle', null, logKayitAl($db, 'teknik_servis', $yeniId),
        'Operatör sahadan arıza bildirdi (personel #' . ($personelId ?? '?') . ')');
    gorevOlustur($db, 'Arıza servis kaydı: ' . ($kira['ekipman_adi'] ?: 'makine'),
        $aciklama, 'teknik_servis', $yeniId);
    return ['durum' => 'acildi', 'id' => $yeniId];
}

function makineGecmis(PDO $db, string $tur, int $id): array {
    $kiralamalar = []; $servisler = [];
    $ozet = ['kiralama_adet' => 0, 'toplam_gun' => 0, 'toplam_gelir' => 0.0, 'servis_acik' => 0, 'servis_maliyet' => 0.0];
    require_once __DIR__ . '/servis-maliyet-lib.php';
    $kisit = subeKisitiHam();
    $kiraGor = hasPermission('kiralama_takip', 'oku');
    $fiyatGor = smFiyatYetkisi($db);
    $sade = ['id', 'kod', 'makine_tur', 'makine_id', 'ekipman_adi', 'baslangic', 'bitis', 'gercek_bitis', 'durum', 'sube'];

    try {
        $ks = $db->prepare("SELECT k.*, m.unvan AS musteri_unvan FROM kiralamalar k LEFT JOIN musteriler m ON m.id=k.musteri_id
            WHERE k.makine_tur=? AND k.makine_id=? ORDER BY k.baslangic DESC, k.id DESC");
        $ks->execute([$tur, $id]);
        foreach ($ks->fetchAll() as $k) {
            $h = kiralamaTutar($k);
            $baska = $kisit !== null && trim((string)($k['sube'] ?? '')) !== $kisit;
            if (($k['durum'] ?? '') !== 'iptal') {
                $ozet['kiralama_adet']++;
                $ozet['toplam_gun'] += $h['gun'];
                if (!$baska && $kiraGor) $ozet['toplam_gelir'] += $h['genel_toplam'];
            }
            if ($baska || !$kiraGor) {
                $s = array_intersect_key($k, array_flip($sade));
                $s['musteri_id'] = $baska ? null : ($k['musteri_id'] ?? null);
                $s['musteri_unvan'] = $baska ? null : ($k['musteri_unvan'] ?? null);
                $s['hesap'] = ['gun' => $h['gun'], 'genel_toplam' => null];
                $s['detay_gizli'] = $baska ? 1 : 0;
                $kiralamalar[] = $s;
            } else {
                $k['hesap'] = $h;
                $kiralamalar[] = $k;
            }
        }
    } catch (\Exception $e) { hataGunlugu('makine_detay.kiralamalar', $e, true); }

    try {
        $ss = $db->prepare("SELECT s.*, p.ad_soyad AS teknisyen_ad, " . servisSubeSql('s', 'k', 'ms', 'mk') . " AS servis_sube FROM teknik_servis s LEFT JOIN personel p ON p.id=s.teknisyen_id
            LEFT JOIN kiralamalar k ON k.id=s.kiralama_id LEFT JOIN musteriler ms ON ms.id=s.musteri_id LEFT JOIN musteriler mk ON mk.id=k.musteri_id
            WHERE s.makine_tur=? AND s.makine_id=? ORDER BY s.tarih DESC, s.id DESC");
        $ss->execute([$tur, $id]);
        foreach ($ss->fetchAll() as $s) {
            $sb = (string)($s['servis_sube'] ?? '');
            unset($s['servis_sube']);
            $baska = $kisit !== null && $sb !== '' && $sb !== $kisit;
            if (($s['durum'] ?? '') !== 'tamamlandi') $ozet['servis_acik']++;
            if ($fiyatGor && !$baska) $ozet['servis_maliyet'] += (float)$s['maliyet'];
            if (!$fiyatGor || $baska) {
                foreach (array_merge(SM_FIYAT_SUTUNLARI, SM_MALIYET_ALANLARI) as $a) unset($s[$a]);
            }
            if ($baska) {
                foreach (['irtibat_ad', 'irtibat_tel', 'saha_adres', 'saha_il', 'saha_ilce', 'saha_kabul_ad', 'musteri_id', 'kiralama_id', 'notlar'] as $a) if (array_key_exists($a, $s)) $s[$a] = null;
                $s['detay_gizli'] = 1;
            }
            $servisler[] = $s;
        }
    } catch (\Exception $e) { hataGunlugu('makine_detay.servisler', $e, true); }

    $ozet['toplam_gelir']   = $kiraGor ? round($ozet['toplam_gelir'], 2) : null;
    $ozet['servis_maliyet'] = $fiyatGor ? round($ozet['servis_maliyet'], 2) : null;
    return ['kiralamalar' => $kiralamalar, 'servisler' => $servisler, 'ozet' => $ozet];
}

function aylikAmortisman(float $alisBedeli, int $omurYil): float {
    if ($omurYil < 1 || $alisBedeli <= 0) return 0.0;
    return round($alisBedeli / ($omurYil * 12), 2);
}

function musteriSubeTemsilci(PDO $db, int $musteriId): array {
    $st = $db->prepare("SELECT sube, temsilci FROM musteriler WHERE id=?");
    $st->execute([$musteriId]);
    $r = $st->fetch();
    return [
        'sube'     => $r['sube'] ?? null ?: null,
        'temsilci' => $r['temsilci'] ?? null ?: null,
    ];
}

function teklifToplam(array $kalemler, float $kdvOran, float $iskontoOran = 0): array {
    $araBrut = 0.0;
    foreach ($kalemler as $k) {
        $araBrut += (float)($k['miktar'] ?? 1) * (float)($k['sure'] ?? 1) * (float)($k['birim_ucret'] ?? 0);
    }
    $araBrut = round($araBrut, 2);
    $iskontoOran  = max(0, min(100, $iskontoOran));
    $iskontoTutar = round($araBrut * $iskontoOran / 100, 2);
    $ara = round($araBrut - $iskontoTutar, 2);
    $kdv = round($ara * $kdvOran / 100, 2);
    return [
        'ara_toplam_brut' => $araBrut,
        'iskonto_oran'    => $iskontoOran,
        'iskonto_tutar'   => $iskontoTutar,
        'ara_toplam'      => $ara,
        'kdv'             => $kdv,
        'genel_toplam'    => round($ara + $kdv, 2),
    ];
}

function musteriBakiye(PDO $db, int $musteriId): array {
    $kalem = cariBorcKalemleri($db, $musteriId)[$musteriId] ?? ['borclar' => [], 'faturalanmamis' => []];
    $borc  = array_sum(array_column($kalem['borclar'], 'tutar'));
    $fatsz = array_sum(array_column($kalem['faturalanmamis'], 'tutar'));

    $st2 = $db->prepare("SELECT COALESCE(SUM(tutar),0) FROM tahsilatlar WHERE musteri_id=?");
    $st2->execute([$musteriId]);
    $tahsil = (float)$st2->fetchColumn();

    require_once __DIR__.'/cek-cari-kurallari.php';$cekCari=crVeri($db,null,$musteriId);$mahsup=$cekCari['mahsup'][$musteriId]??0;$cekRisk=$cekCari['risk'][$musteriId]??['bekliyor'=>0.0,'avukatlik'=>0.0,'batik'=>0.0];
    $bakiye = $borc - $tahsil - $mahsup;
    return [
        'toplam_borc'    => round($borc, 2),
        'toplam_tahsil'  => round($tahsil, 2),
        'bakiye'         => round($bakiye, 2),
        'faturalanmamis' => round($fatsz, 2),
        'risk_toplam'    => round($bakiye + $fatsz + array_sum($cekRisk), 2),
        'cek_mahsup'=>round($mahsup,2),'cek_risk'=>$cekRisk,'cek_uyarilari'=>$cekCari['uyarilar'],
    ];
}

function finansDevir(PDO $db, int $personelId, string $ay): float {
    $st = $db->prepare("SELECT elden_kalan FROM finans WHERE personel_id=? AND ay<? ORDER BY ay DESC LIMIT 1");
    $st->execute([$personelId, $ay]);
    $prev = $st->fetchColumn();
    if ($prev === false) return 0.0;
    $prev = (float)$prev;
    return $prev < 0 ? round($prev, 2) : 0.0;
}

function diniBayramHesapla(int $yil): array {
    if (!class_exists('IntlCalendar')) return [];
    try {
        $tz = new DateTimeZone('Europe/Istanbul');
        $mk = function (int $hy, int $hm, int $hd) use ($tz): DateTime {
            $cal = IntlCalendar::createInstance($tz, 'en_US@calendar=islamic-umalqura');
            $cal->clear();
            $cal->set($hy, $hm, $hd);
            $d = new DateTime('@' . (int)round($cal->getTime() / 1000));
            $d->setTimezone($tz);
            return $d;
        };
        $probe = IntlCalendar::createInstance($tz, 'en_US@calendar=islamic-umalqura');
        $probe->setTime((new DateTime("$yil-06-15", $tz))->getTimestamp() * 1000);
        $hMid = $probe->get(IntlCalendar::FIELD_YEAR);

        $out = [];
        foreach ([$hMid - 1, $hMid, $hMid + 1] as $hy) {
            $fitr = $mk($hy, 9, 1);
            for ($i = 0; $i < 3; $i++) { $d = (clone $fitr)->modify("+$i day"); if ((int)$d->format('Y') === $yil) $out[] = $d->format('Y-m-d'); }
            $adha = $mk($hy, 11, 10);
            for ($i = 0; $i < 4; $i++) { $d = (clone $adha)->modify("+$i day"); if ((int)$d->format('Y') === $yil) $out[] = $d->format('Y-m-d'); }
        }
        sort($out);
        return array_values(array_unique($out));
    } catch (\Throwable $e) { return []; }
}

function resmiTatiller(int $yil): array {
    $sabit = [
        "$yil-01-01",
        "$yil-04-23",
        "$yil-05-01",
        "$yil-05-19",
        "$yil-07-15",
        "$yil-08-30",
        "$yil-10-29",
    ];
    $dini = [
        2016 => ['2016-07-05','2016-07-06','2016-07-07', '2016-09-12','2016-09-13','2016-09-14','2016-09-15'],
        2017 => ['2017-06-25','2017-06-26','2017-06-27', '2017-09-01','2017-09-02','2017-09-03','2017-09-04'],
        2018 => ['2018-06-15','2018-06-16','2018-06-17', '2018-08-21','2018-08-22','2018-08-23','2018-08-24'],
        2019 => ['2019-06-04','2019-06-05','2019-06-06', '2019-08-11','2019-08-12','2019-08-13','2019-08-14'],
        2020 => ['2020-05-24','2020-05-25','2020-05-26', '2020-07-31','2020-08-01','2020-08-02','2020-08-03'],
        2021 => ['2021-05-13','2021-05-14','2021-05-15', '2021-07-20','2021-07-21','2021-07-22','2021-07-23'],
        2022 => ['2022-05-02','2022-05-03','2022-05-04', '2022-07-09','2022-07-10','2022-07-11','2022-07-12'],
        2023 => ['2023-04-21','2023-04-22','2023-04-23', '2023-06-28','2023-06-29','2023-06-30','2023-07-01'],
        2024 => ['2024-04-10','2024-04-11','2024-04-12', '2024-06-16','2024-06-17','2024-06-18','2024-06-19'],
        2025 => ['2025-03-30','2025-03-31','2025-04-01', '2025-06-06','2025-06-07','2025-06-08','2025-06-09'],
        2026 => ['2026-03-20','2026-03-21','2026-03-22', '2026-05-27','2026-05-28','2026-05-29','2026-05-30'],
        2027 => ['2027-03-10','2027-03-11','2027-03-12', '2027-05-16','2027-05-17','2027-05-18','2027-05-19'],
        2028 => ['2028-02-26','2028-02-27','2028-02-28', '2028-05-05','2028-05-06','2028-05-07','2028-05-08'],
    ];
    $list = array_merge($sabit, $dini[$yil] ?? diniBayramHesapla($yil));

    try {
        $ek = getDb()->query("SELECT deger FROM ayarlar WHERE anahtar='ek_tatiller'")->fetchColumn();
        if ($ek) foreach (explode(',', (string)$ek) as $d) {
            $d = trim($d);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && substr($d, 0, 4) === (string)$yil) $list[] = $d;
        }
    } catch (\Exception $e) { hataGunlugu('tatil.ek_tatiller', $e); }

    return array_values(array_unique($list));
}

function isGunSayisi(?string $bas, ?string $bit): int {
    if (!$bas || !$bit || $bit < $bas) return 0;
    $tatil = [];
    for ($y = (int)substr($bas, 0, 4); $y <= (int)substr($bit, 0, 4); $y++) {
        foreach (resmiTatiller($y) as $t) $tatil[$t] = true;
    }
    $gun = 0;
    $cur = new DateTime($bas);
    $end = new DateTime($bit);
    while ($cur <= $end) {
        $d = $cur->format('Y-m-d');
        if ((int)$cur->format('N') !== 7 && !isset($tatil[$d])) $gun++;
        $cur->modify('+1 day');
    }
    return $gun;
}

function checkRateLimit(string $username, string $ip): void {
    $db   = getDb();
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM login_attempts
        WHERE (username=? OR ip=?) AND success=0
          AND attempted_at > datetime('now','-10 minutes','localtime')
    ");
    $stmt->execute([$username, $ip]);
    $count = (int)$stmt->fetchColumn();

    if ($count >= LOGIN_RATE_LIMIT) {
        jsonError('Çok fazla başarısız giriş denemesi. 10 dakika bekleyin.', 429);
    }
}

function sahaPinRateLimit(PDO $db, int $personelId, string $ip, string $kaynak): void {
    $st = $db->prepare("
        SELECT COUNT(*) FROM checkin_attempts
        WHERE personel_id=? AND kaynak=? AND success=0
          AND attempted_at >= datetime('now','-10 minutes','localtime')
    ");
    $st->execute([$personelId, $kaynak]);
    if ((int)$st->fetchColumn() >= LOGIN_RATE_LIMIT) {
        jsonError('Çok fazla başarısız deneme. Lütfen birkaç dakika sonra tekrar deneyin.', 429);
    }

    $ipSt = $db->prepare("
        SELECT COUNT(*) FROM checkin_attempts
        WHERE ip=? AND kaynak=? AND success=0
          AND attempted_at >= datetime('now','-10 minutes','localtime')
    ");
    $ipSt->execute([$ip, $kaynak]);
    if ((int)$ipSt->fetchColumn() >= LOGIN_RATE_LIMIT * 4) {
        jsonError('Bu cihazdan çok fazla başarısız deneme yapıldı. Lütfen birkaç dakika sonra tekrar deneyin.', 429);
    }
}

function logAttempt(string $username, string $ip, bool $success): void {
    $db = getDb();
    $db->prepare("INSERT INTO login_attempts (username, ip, success) VALUES (?, ?, ?)")
       ->execute([$username, $ip, $success ? 1 : 0]);
    $db->exec("DELETE FROM login_attempts WHERE attempted_at < datetime('now','-1 day','localtime')");
}
require_once __DIR__ . '/makine-kategori-lib.php';
