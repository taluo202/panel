<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/paket-denetim-lib.php';


if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
if (($_SESSION['rol'] ?? '') !== 'admin') jsonError('Bu kontrolü yalnızca yönetici çalıştırabilir.', 403);

$BOS_SAHA_FOTO_SART = "kategori=? AND belge_adi LIKE ? AND (dosya_yolu IS NULL OR dosya_yolu='')";
$BOS_SAHA_FOTO_DEGER = ['Saha Fotoğrafı', 'QR ile eklendi — %'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'bos_saha_foto_temizle') {
    verifyCsrf();
    $db = getDb();
    $silinen = 0;
    $db->beginTransaction();
    try {
        foreach (['arac' => 'arac_belgeler', 'manlift' => 'manlift_belgeler'] as $tur => $tablo) {
            $st = $db->prepare("SELECT * FROM $tablo WHERE $BOS_SAHA_FOTO_SART");
            $st->execute($BOS_SAHA_FOTO_DEGER);
            foreach ($st->fetchAll() as $r) {
                $db->prepare("DELETE FROM $tablo WHERE id=?")->execute([(int)$r['id']]);
                $db->prepare("DELETE FROM share_tokens WHERE belge_id=? AND tur=?")->execute([(int)$r['id'], $tur]);
                logla($db, $tablo, (int)$r['id'], 'sil', $r, null,
                    'Eski QR hatasından kalan boş Saha Fotoğrafı satırı silindi (dosyası yoktu): ' . ($r['belge_adi'] ?? '')
                    . ' (' . $tur . ' #' . (int)($r['arac_id'] ?? $r['manlift_id'] ?? 0) . ')');
                $silinen++;
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    jsonOk(['silinen' => $silinen], $silinen ? $silinen . ' boş satır silindi.' : 'Silinecek boş satır yok.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonError('Method Not Allowed', 405);

$kontroller = [];
$ek = function (string $baslik, string $durum, string $mesaj, string $cozum = '', string $eylem = '') use (&$kontroller) {
    $kontroller[] = ['baslik' => $baslik, 'durum' => $durum, 'mesaj' => $mesaj, 'cozum' => $cozum] + ($eylem !== '' ? ['eylem' => $eylem] : []);
};

$dosyaSaglam = function (string $yol, int $asgariBayt = 32): array {
    if (!is_file($yol)) return ['durum' => 'yok', 'bayt' => 0];
    $bayt = (int)@filesize($yol);
    if ($bayt < $asgariBayt) return ['durum' => 'bos', 'bayt' => $bayt];
    $bas = (string)@file_get_contents($yol, false, null, 0, 200);
    if ($bas !== '' && preg_match('/^\s*(<!DOCTYPE|<html|<\?xml)/i', $bas)) {
        return ['durum' => 'html', 'bayt' => $bayt];
    }
    return ['durum' => 'tamam', 'bayt' => $bayt];
};

try {
    $_phpTarih = date('Y-m-d');
    $_dbTarih  = (string)getDb()->query("SELECT date('now','localtime')")->fetchColumn();
    $_phpSaat  = date('H:i');
    $_dbSaat   = (string)getDb()->query("SELECT strftime('%H:%M','now','localtime')")->fetchColumn();
    if ($_phpTarih !== $_dbTarih) {
        $ek('Saat dilimi', 'hata',
            "PHP ve veritabanı FARKLI GÜN görüyor (PHP: $_phpTarih $_phpSaat · veritabanı: $_dbTarih $_dbSaat).",
            'Sunucunun saat dilimini Türkiye (UTC+3) yapın. Aksi hâlde bugün başlayan bakım/servis '
            . '"gelecek tarihli" sayılır ve makine aynı güne ikinci kez kiralanabilir; vade ve iade '
            . 'tarihleri de bir gün kayar.');
    } elseif ($_phpSaat !== $_dbSaat) {
        $ek('Saat dilimi', 'uyari',
            "PHP ve veritabanı saati farklı (PHP: $_phpSaat · veritabanı: $_dbSaat). Gün aynı, ancak "
            . 'gece yarısına yakın saatlerde ayrışabilirler.',
            'Sunucunun saat dilimini Türkiye (UTC+3) yapın.');
    } else {
        $ek('Saat dilimi', 'tamam', "PHP ve veritabanı aynı saati görüyor ($_phpTarih $_phpSaat, "
            . date_default_timezone_get() . ').');
    }
} catch (\Throwable $e) {
    $ek('Saat dilimi', 'uyari', 'Saat karşılaştırması yapılamadı: ' . $e->getMessage());
}

$host       = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')) ?? '');
$yerelMi    = $host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false;
$httpsVar   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
              || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;

if (BASE_DOMAIN !== '') {
    $ek('Alan adı', 'tamam', 'Çok kiracılı mod açık (BASE_DOMAIN = ' . BASE_DOMAIN . '). E-posta linkleri bu alan adına sabitlenir.');
} elseif (TRUSTED_HOST !== '') {
    $ek('Alan adı', 'tamam', 'TRUSTED_HOST ayarlı: ' . TRUSTED_HOST);
} elseif ($yerelMi) {
    $ek('Alan adı', 'uyari', 'TRUSTED_HOST boş — yerel geliştirmede sorun değil, ancak sunucuya çıkmadan önce doldurulmalı.',
        'dbstore/kurulum.json içinde: {"TRUSTED_HOST": "panel.alanadiniz.com"}');
} else {
    $ek('Alan adı', 'hata', 'TRUSTED_HOST boş. Şifre sıfırlama e-postasındaki link, isteği yapanın Host başlığından kurulur; '
        . 'kötü niyetli biri sahte Host göndererek kurbanın e-postasına kendi adresine giden geçerli bir sıfırlama linki yollatabilir.',
        'dbstore/kurulum.json içinde: {"TRUSTED_HOST": "' . $host . '"}');
}

$kurulum = kurulumAyarlari();
if ($kurulum['_kaynak'] === 'bozuk') {
    $ek('Kurulum ayar dosyası', 'hata', 'dbstore/kurulum.json okunamıyor (geçerli JSON değil); koddaki varsayılan ayarlar kullanılıyor.',
        'Dosyayı düzeltin. Örnek: {"TRUSTED_HOST": "' . ($host !== '' ? $host : 'panel.alanadiniz.com') . '", "BASE_DOMAIN": "", "GUVENILIR_PROXYLER": []}');
} elseif ($kurulum['_gecersiz']) {
    $ek('Kurulum ayar dosyası', 'hata', 'dbstore/kurulum.json içinde geçersiz değer: ' . implode(', ', $kurulum['_gecersiz']) . '. Bu ayarlar için koddaki varsayılan kullanılıyor.',
        'Alan adları yalnız harf, rakam, nokta ve tire içermeli; GUVENILIR_PROXYLER IP adresi listesi olmalı.');
} elseif ($kurulum['_kaynak'] === 'dosya') {
    $ek('Kurulum ayar dosyası', 'tamam', 'Kuruluma özel ayarlar dbstore/kurulum.json dosyasından okunuyor; site dosyaları yeniden yüklense de değişmez.');
} else {
    $ek('Kurulum ayar dosyası', 'tamam', 'Ayar dosyası yok; koddaki varsayılan ayarlar kullanılıyor (TRUSTED_HOST = ' . (TRUSTED_HOST !== '' ? TRUSTED_HOST : 'boş') . '). '
        . 'Alan adı değişirse kodu düzenlemek yerine dbstore/kurulum.json dosyası oluşturulabilir.');
}

if ($httpsVar)      $ek('HTTPS', 'tamam', 'Bağlantı şifreli.');
elseif ($yerelMi)   $ek('HTTPS', 'uyari', 'Yerel geliştirmede HTTP normaldir.');
else                $ek('HTTPS', 'hata', 'Site HTTP üzerinden sunuluyor. Oturum çerezi ve şifreler ağda açık gider.',
                        'Hosting panelinden SSL sertifikası (Let\'s Encrypt) etkinleştirin ve HTTP→HTTPS yönlendirmesi ekleyin.');

$dbFile   = _resolveDbPath();
$siteKok  = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
$dbGercek = realpath($dbFile) ?: $dbFile;
$dbIcerde = strncmp(str_replace('\\', '/', $dbGercek), str_replace('\\', '/', $siteKok), strlen($siteKok)) === 0;

if (!is_writable($dbFile)) {
    $ek('Veritabanı', 'hata', 'Veritabanı dosyası yazılabilir değil: ' . $dbFile,
        'Dosyaya ve bulunduğu klasöre yazma izni verin (genelde 0644 dosya / 0755 klasör, sahibi web kullanıcısı).');
} elseif ($dbIcerde) {
    $htaVar = is_file(dirname($dbFile) . '/.htaccess');
    $ek('Veritabanı', $htaVar ? 'uyari' : 'hata',
        'Veritabanı web kök dizininin İÇİNDE: ' . $dbFile . ($htaVar ? ' (.htaccess ile korunuyor)' : ' — koruyucu .htaccess YOK!'),
        $htaVar ? 'Mümkünse dbstore klasörünü site klasörünün dışına taşıyın.'
                : 'ACİL: bu klasöre "Require all denied" içeren bir .htaccess koyun veya klasörü web kökünün dışına taşıyın.');
} else {
    $ek('Veritabanı', 'tamam', 'Web kök dizininin dışında ve yazılabilir.');
}

$upl = rtrim(UPLOAD_PATH, '/\\');
if (!is_dir($upl)) {
    $ek('Yükleme klasörü', 'uyari', 'uploads klasörü henüz yok: ' . $upl . ' (ilk yüklemede oluşturulmaya çalışılacak).',
        'Klasörü elle oluşturup web kullanıcısına yazma izni vermek daha güvenlidir.');
} elseif (!is_writable($upl)) {
    $ek('Yükleme klasörü', 'hata', 'uploads klasörü yazılabilir değil: ' . $upl,
        'Klasöre yazma izni verin (0755, sahibi web kullanıcısı) — aksi hâlde evrak/fotoğraf yüklenemez.');
} else {
    $ek('Yükleme klasörü', 'tamam', 'Mevcut ve yazılabilir.');
}

try {
    $db = getDb();
    $st = $db->query("SELECT username, password FROM users WHERE rol='admin'");
    $zayif = [];
    foreach ($st->fetchAll() as $u) {
        if (password_verify('Admin@2024', (string)$u['password'])) $zayif[] = $u['username'];
    }
    if ($zayif) {
        $ek('Yönetici şifresi', 'hata', 'Şu yönetici hesapları hâlâ KURULUM ŞİFRESİNİ kullanıyor: ' . implode(', ', $zayif),
            'Ayarlar → Şifre Değiştir bölümünden hemen değiştirin.');
    } else {
        $ek('Yönetici şifresi', 'tamam', 'Kurulum şifresi kullanılmıyor.');
    }
} catch (Throwable $e) {
    $ek('Yönetici şifresi', 'uyari', 'Kontrol edilemedi.');
}
if (is_file(ilkYoneticiSifreDosyasi())) {
    $ek('İlk şifre dosyası', 'uyari', 'Veritabanı klasöründe ILK-YONETICI-SIFRESI.txt hâlâ duruyor.',
        'Şifreyi değiştirdikten sonra bu dosyayı silin.');
}

try {
    $semaUv = (int)getDb()->query('PRAGMA user_version')->fetchColumn();
    if ($semaUv === semaIzi()) {
        $ek('Veritabanı şeması', 'tamam', 'Şema güncel (iz ' . $semaUv . '). Şema kodu değişmedikçe istekler kurulum yapmıyor.');
    } else {
        $ek('Veritabanı şeması', 'hata', 'Şema kurulumu tamamlanmamış (veritabanı izi ' . $semaUv . ', kod izi ' . semaIzi() . ').',
            'Sunucu hata günlüğünde "sema." ile başlayan satırlara bakın; sorun giderilince sayfayı yenileyin, kurulum kendiliğinden yeniden dener.');
    }
} catch (Throwable $e) {
    $ek('Veritabanı şeması', 'uyari', 'Kontrol edilemedi.');
}

try {
    require_once __DIR__ . '/veri-sagligi-lib.php';
    $vsT = veriSagligiTara(getDb());
    $vsSatir = veriSagligiSatirlari($vsT, null);
    if (!$vsT['butunluk']['tamam']) {
        $ek('Veri sağlığı', 'hata', 'Veritabanı bütünlük denetiminden geçmedi: ' . implode(' / ', array_slice($vsT['butunluk']['mesajlar'], 0, 3)),
            'Hemen bir yedek indirin ve son sağlam yedeği hazır tutun; veritabanı dosyası zarar görmüş olabilir.');
    } elseif ($vsSatir) {
        $ek('Veri sağlığı', 'uyari', 'Veritabanı dosyası sağlam, ancak üst kaydı silinmiş ' . ($vsT['sorun']) . ' sahipsiz kayıt var. '
            . implode('; ', array_slice($vsSatir, 0, 8)) . (count($vsSatir) > 8 ? ' …' : '') . '.',
            'Bu kayıtlar ekranlarda görünmez ama yer tutar ve toplamları şaşırtabilir. Kendiliğinden silinmez; temizlik için destek isteyin.');
    } else {
        $ek('Veri sağlığı', 'tamam', 'Veritabanı bütünlüğü sağlam, sahipsiz kayıt yok.');
    }
    if ($vsT['okunamayan']) $ek('Veri sağlığı (eksik denetim)', 'uyari', 'Denetlenemeyen bağlar: ' . implode(', ', $vsT['okunamayan']) . '.');

    $vsP = yedekProvasi(getDb());
    if ($vsP['tamam']) {
        $ek('Yedek provası', 'tamam', 'Yedek anlık görüntüsü alındı, açıldı ve doğrulandı: ' . $vsP['tablo'] . ' tablo, ' . number_format($vsP['kayit'], 0, ',', '.')
            . ' kayıt, ' . number_format($vsP['boyut'] / 1048576, 1, ',', '.') . ' MB. Kayıt sayıları canlı veriyle aynı.');
    } else {
        $ek('Yedek provası', 'hata', 'Yedek doğrulanamadı: ' . implode(' / ', array_slice($vsP['sorunlar'], 0, 3)),
            'Bu durumda indirilen yedek geri yüklenemeyebilir. Sunucu disk alanını kontrol edin; sürerse destek isteyin.');
    }

    $vsSon = veriSagligiSon();
    $cronVar = strlen((string)(getDb()->query("SELECT deger FROM ayarlar WHERE anahtar='cron_anahtar'")->fetchColumn() ?: '')) >= 20;
    if ($vsSon === null) {
        $ek('Gecelik veri denetimi', 'uyari', 'Gecelik denetim henüz hiç çalışmamış.' . ($cronVar ? '' : ' Zamanlanmış görev anahtarı tanımlı değil.'),
            'Ayarlar > E-posta Gönderimi > Günlük Özet E-postası bölümündeki zamanlanmış görev adresini hosting panelinde günde bir kez çalışacak şekilde tanımlayın.');
    } else {
        $vsGun = (int)floor((time() - (int)strtotime((string)$vsSon['tarih'])) / 86400);
        if ($vsGun > 2) {
            $ek('Gecelik veri denetimi', 'uyari', 'Gecelik denetim ' . $vsGun . ' gündür çalışmıyor (son: ' . $vsSon['tarih'] . ').',
                'Hosting panelindeki zamanlanmış görevin hâlâ çalıştığını kontrol edin.');
        } else {
            $ek('Gecelik veri denetimi', 'tamam', 'Son gecelik denetim: ' . $vsSon['tarih'] . '.');
        }
    }
} catch (Throwable $e) {
    hataGunlugu('onkontrol.veri_sagligi', $e);
    $ek('Veri sağlığı', 'uyari', 'Kontrol edilemedi.');
}

try {
    $itSemaK = (string)(getDb()->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='iade_tutanaklari'")->fetchColumn() ?: '');
    if (preg_match('/FOREIGN KEY\s*\(personel_id\)\s*REFERENCES\s+personel/i', $itSemaK)) {
        $ek('Tutanak tablosu güncellemesi', 'hata', 'iade_tutanaklari tablosu hâlâ eski personel kısıtını taşıyor; dış şoför ve kâğıt tutanakları kaydedilemez.',
            'Sunucu hata günlüğünde "iade_tutanaklari K2 yeniden kurulumu" satırına bakın; sayfayı yenileyip tekrar deneyin.');
    } else {
        $ek('Tutanak tablosu güncellemesi', 'tamam', 'Tutanak tablosu güncel.');
    }
} catch (Throwable $e) {
    $ek('Tutanak tablosu güncellemesi', 'uyari', 'Kontrol edilemedi.');
}

try {
    $bosSaha = 0;
    foreach (['arac_belgeler', 'manlift_belgeler'] as $tablo) {
        $st = getDb()->prepare("SELECT COUNT(*) FROM $tablo WHERE $BOS_SAHA_FOTO_SART");
        $st->execute($BOS_SAHA_FOTO_DEGER);
        $bosSaha += (int)$st->fetchColumn();
    }
    if ($bosSaha > 0) {
        $ek('Boş saha fotoğrafı satırları', 'uyari',
            $bosSaha . ' boş "Saha Fotoğrafı" satırı var: eski QR hatası yüzünden fotoğraf yüklenmemiş, araç/makine belge listesinde yalnız başlık kalmış. Gerçek fotoğraflar ve diğer belgeler bu sayıya dahil değil.',
            '', 'bos_saha_foto');
    } else {
        $ek('Boş saha fotoğrafı satırları', 'tamam', 'Eski QR hatasından kalmış boş satır yok.');
    }
} catch (Throwable $e) {
    $ek('Boş saha fotoğrafı satırları', 'uyari', 'Kontrol edilemedi.');
}

$eksikUzanti = array_values(array_filter(['pdo_sqlite', 'mbstring', 'fileinfo'], fn($x) => !extension_loaded($x)));
if ($eksikUzanti) {
    $ek('PHP eklentileri', 'hata', 'Eksik eklenti: ' . implode(', ', $eksikUzanti),
        'Hosting panelinden bu PHP eklentilerini etkinleştirin.');
} else {
    $ek('PHP eklentileri', 'tamam', 'PHP ' . PHP_VERSION . ' — gerekli eklentiler yüklü.');
}

$hataGoster = strtolower((string)ini_get('display_errors'));
if (!$yerelMi && in_array($hataGoster, ['1', 'on', 'true'], true)) {
    $ek('Hata gösterimi', 'hata', 'display_errors AÇIK — bir hata anında dosya yolları ve sorgu ayrıntıları ziyaretçiye görünür.',
        'php.ini veya .htaccess içinde display_errors Off yapın.');
} else {
    $ek('Hata gösterimi', 'tamam', 'Hatalar ziyaretçiye gösterilmiyor.');
}

$gerekli = ['logo.svg', 'turkiye.svg'];
$eksikGorsel = array_values(array_filter($gerekli, fn($f) => !is_file(__DIR__ . '/../img/' . $f)));
if ($eksikGorsel) {
    $ek('Görseller', 'hata', 'img/ klasöründe eksik dosya: ' . implode(', ', $eksikGorsel)
        . ' (turkiye.svg yoksa Filo Analizi haritası hiç açılmaz).',
        'Eksik dosyaları site/img/ klasörüne yükleyin — FTP aktarımlarında sık atlanır.');
} else {
    $ek('Görseller', 'tamam', 'Gerekli görseller (logo, Türkiye haritası) yerinde.');
}

$twDurum = $dosyaSaglam(__DIR__ . '/../css/tailwind.css', 1024);
if ($twDurum['durum'] === 'yok') {
    $ek('Stiller', 'hata', 'css/tailwind.css sunucuda YOK — bütün sayfalar stilsiz (bembeyaz) açılır.',
        'site/css/tailwind.css dosyasını yükleyin. Kaybolduysa: site/build içinde "npm install && npm run build".');
} elseif ($twDurum['durum'] === 'bos') {
    $ek('Stiller', 'hata', 'css/tailwind.css sunucuda VAR ama içi neredeyse boş (' . $twDurum['bayt'] . ' bayt) — bütün sayfalar stilsiz açılır.',
        'Güvenlik duvarı (mod_security) dosyayı kırpmış olabilir: ZIP\'leyip yükleyin ve konumunu değiştirerek yerine taşıyın.');
} elseif ($twDurum['durum'] === 'html') {
    $ek('Stiller', 'hata', 'css/tailwind.css yerine bir HTML sayfası (büyük ihtimalle 403 hata sayfası) yazılmış.',
        'Güvenlik duvarı yüklemeyi engellemiş: ZIP\'leyip yükleyin ve konumunu değiştirerek yerine taşıyın.');
} else {
    $ek('Stiller', 'tamam', 'Derlenmiş stil dosyası yerinde (' . round($twDurum['bayt'] / 1024) . ' KB).');
}

$veriDosyalari = ['data/tutanak-kurallari.json','data/tutanak-belge-metni.json'];
$veriSorun = [];
foreach ($veriDosyalari as $vd) {
    $d = $dosyaSaglam(__DIR__ . '/../' . $vd, 16);
    if ($d['durum'] !== 'tamam') $veriSorun[] = $vd . ' (' . ($d['durum'] === 'yok' ? 'YOK' : $d['bayt'] . ' bayt') . ')';
}
if ($veriSorun) {
    $ek('Veri dosyaları', 'hata', 'Eksik/bozuk: ' . implode(', ', $veriSorun)
        . ' — Teslim/İade tutanağı ekranı makine seçilince "Sunucu hatası" verir.',
        'site/data/ klasörünü olduğu gibi sunucuya yükleyin (yeni klasörler FTP aktarımlarında sık atlanır).');
} else {
    $ek('Veri dosyaları', 'tamam', 'Tutanak kural dosyası yerinde.');
}

$pwaEksik = [];
$pwaBozuk  = [];
foreach (['manifest.json', 'sw.js', 'js/pwa.js', 'img/icon-192.png', 'img/icon-512.png'] as $f) {
    $d = $dosyaSaglam(__DIR__ . '/../' . $f);
    if ($d['durum'] === 'yok') $pwaEksik[] = $f;
    elseif ($d['durum'] !== 'tamam') $pwaBozuk[] = $f . ' (' . $d['bayt'] . ' bayt)';
}
if ($pwaBozuk) {
    $ek('Uygulama kurulumu (PWA)', 'hata', 'Sunucuda VAR ama içi boş/bozuk: ' . implode(', ', $pwaBozuk)
        . ' — dosya yüklenmiş görünür ama çalışmaz.',
        'Güvenlik duvarı kırpmış olabilir: ZIP\'leyip yükleyin ve konumunu değiştirerek yerine taşıyın.');
} elseif ($pwaEksik) {
    $ek('Uygulama kurulumu (PWA)', 'uyari', 'Eksik dosya: ' . implode(', ', $pwaEksik)
        . ' — "ana ekrana ekle" ve çevrimdışı açılış çalışmaz (site normal çalışmaya devam eder).',
        'Eksik dosyaları yükleyin; sw.js mutlaka KÖK dizinde olmalı.');
} elseif (!$httpsVar && !$yerelMi) {
    $ek('Uygulama kurulumu (PWA)', 'uyari', 'Dosyalar yerinde ama site HTTPS değil; service worker yalnızca güvenli bağlantıda çalışır.',
        'SSL sertifikası etkinleştirin.');
} else {
    $ek('Uygulama kurulumu (PWA)', 'tamam', 'Telefona kurulabilir (manifest, service worker ve simgeler yerinde).');
}

$htaDurum = $dosyaSaglam(__DIR__ . '/../.htaccess', 64);
$htaKok   = $htaDurum['durum'] === 'tamam';
if ($htaDurum['durum'] === 'bos' || $htaDurum['durum'] === 'html') {
    $ek('Güvenlik başlıkları', 'hata', 'site/.htaccess VAR ama içi boş/bozuk (' . $htaDurum['bayt'] . ' bayt) — sayfa kapısı, CSP ve klasör engelleri UYGULANMIYOR.',
        'Güvenlik duvarı kırpmış olabilir: ZIP\'leyip yükleyin ve konumunu değiştirerek yerine taşıyın. Yükledikten sonra bu sayfayı tekrar açın.');
} elseif (!$htaKok) {
    $ek('Güvenlik başlıkları', 'hata', 'site/.htaccess dosyası sunucuda YOK — CSP ve güvenlik başlıkları uygulanmıyor.',
        'Dosya gizli olduğu için FTP aktarımlarında atlanır; "gizli dosyaları göster" seçeneğiyle yeniden yükleyin.');
} elseif (!sunucuHtaccessOkur((string)($_SERVER['SERVER_SOFTWARE'] ?? '')) && !$yerelMi) {
    $ek('Güvenlik başlıkları', 'uyari', 'Sunucu Apache ya da LiteSpeed görünmüyor (' . ($_SERVER['SERVER_SOFTWARE'] ?? 'bilinmiyor')
        . '); .htaccess yalnızca Apache uyumlu sunucularda okunur.',
        'nginx kullanıyorsanız .htaccess içindeki başlıkları nginx yapılandırmasına taşıyın.');
} else {
    $ek('Güvenlik başlıkları', 'tamam', '.htaccess yerinde' . (stripos((string)($_SERVER['SERVER_SOFTWARE'] ?? ''), 'litespeed') !== false ? ' (LiteSpeed .htaccess dosyasını okur).' : '.'));
}

if ($htaKok) {
    $htaMetin = (string)@file_get_contents(__DIR__ . '/../.htaccess');
    if (str_contains($htaMetin, 'kapi.php?j=$1')) {
        $ek('Kod kapısı', 'tamam', 'js/ dosyaları kapıdan geçiyor; giriş sayfalarının kodu dışındakiler oturum ister.');
    } else {
        $ek('Kod kapısı', 'uyari', '.htaccess eski: js/ dosyaları oturumsuz da indirilebiliyor.',
            'Paketteki site/.htaccess dosyasını yeniden yükleyin.');
    }
    if (preg_match('/^\s*RewriteRule \^\(login\|sifre-sifirla\|/m', $htaMetin)) {
        $ek('Açık sayfa kapısı', 'hata', '.htaccess eski: girişsiz sayfalar (saha teslim, iade tutanağı, check-in) kapıyı atlıyor; kodları yüklenmez.',
            'Paketteki site/.htaccess dosyasını kapi.php ile BİRLİKTE yükleyin.');
    }
    if (!str_contains($htaMetin, 'zip|tar|gz') || !preg_match('/RewriteRule \^\([^)]*\|data\|/', $htaMetin)) {
        $ek('Arşiv ve veri klasörü', 'uyari', '.htaccess eski: sunucuda kalan .zip / editör yedeği dosyaları ve data/ klasörü (iş kuralları) web\'den indirilebilir.',
            'Paketteki site/.htaccess dosyasını yeniden yükleyin.');
    }
}

$artik = [];
foreach (['', 'api/', 'js/', 'css/'] as $klasor) {
    foreach (glob(__DIR__ . '/../' . $klasor . '*') ?: [] as $f) {
        if (is_file($f) && preg_match('/(~|\.(zip|tar|gz|tgz|bz2|7z|rar|orig|old|swp|swo|save|dist|tmp|bak|yedek))$/i', $f)) $artik[] = $klasor . basename($f);
    }
}
if ($artik) {
    $ek('Unutulmuş arşiv / yedek', 'hata', 'Sunucuda kaynak kodu içerebilecek dosya: ' . implode(', ', array_slice($artik, 0, 10)) . (count($artik) > 10 ? ' …' : '') . '.',
        'Bu dosyaları sunucudan SİLİN (yükleme ZIP\'i açıldıktan sonra hemen silinmeli).');
} else {
    $ek('Unutulmuş arşiv / yedek', 'tamam', 'Kökte, api/, js/ ve css/ içinde arşiv ya da editör yedeği yok.');
}

$gelistirici = [];
foreach (['seed_demo.php', 'kilavuz.html', 'tests', 'build', 'docs', 'dogrula.sh'] as $g) {
    if (file_exists(__DIR__ . '/../' . $g)) $gelistirici[] = $g . (is_dir(__DIR__ . '/../' . $g) ? '/' : '');
}
if ($gelistirici) {
    $ek('Geliştirici dosyaları', 'hata', 'Sunucuda pakete ait olmayan geliştirici dosyası: ' . implode(', ', $gelistirici) . '.',
        'seed_demo.php canlı veriyi SİLEBİLİR; bu dosya ve klasörleri sunucudan kaldırın (paket onları içermez).');
} else {
    $ek('Geliştirici dosyaları', 'tamam', 'seed_demo.php, tests/, build/, docs/ sunucuda yok.');
}

$_paket = paketManifestOku(__DIR__ . '/../db/surum-manifest.json');
if ($_paket === null) {
    $ek('Yüklü dosyalar', $yerelMi ? 'tamam' : 'uyari', 'Paket sürüm listesi (db/surum-manifest.json) yok; yüklü dosyalar karşılaştırılamadı.',
        $yerelMi ? '' : 'Paketi tests/paket-hazirla.sh ile üretip db/ klasörüyle birlikte yükleyin.');
} else {
    $_pk = paketKarsilastir(dirname(__DIR__), $_paket['dosyalar']);
    $_ilk = fn(array $l): string => implode(', ', array_slice($l, 0, 10)) . (count($l) > 10 ? ' … (+' . (count($l) - 10) . ')' : '');
    $_sur = (string)($_paket['surum'] ?? '?');
    if ($_pk['eksik'] || $_pk['farkli']) {
        $ek('Yüklü dosyalar', 'hata', "Paket $_sur ile sunucu uyuşmuyor."
            . ($_pk['eksik'] ? ' Eksik: ' . $_ilk($_pk['eksik']) . '.' : '') . ($_pk['farkli'] ? ' Farklı ya da yarım: ' . $_ilk($_pk['farkli']) . '.' : ''),
            'Bu dosyaları paketten yeniden yükleyin; 0 baytsa güvenlik duvarı engellemiştir: ZIP\'leyip yükleyin ve konumunu değiştirerek taşıyın.');
    } elseif ($_pk['artik']) {
        $ek('Yüklü dosyalar', 'uyari', "Paket $_sur ile aynı; ama pakette olmayan eski dosya var: " . $_ilk($_pk['artik']) . '.',
            'Bunlar eski sürümden kalmış (kaldırılmış sayfa, eski betik, arşiv); sunucudan silin.');
    } else {
        $ek('Yüklü dosyalar', 'tamam', "Sunucudaki dosyalar paket $_sur ile birebir aynı (" . count($_paket['dosyalar']) . ' dosya).');
    }
}

$sayac = ['tamam' => 0, 'uyari' => 0, 'hata' => 0];
foreach ($kontroller as $k) $sayac[$k['durum']]++;

jsonOk([
    'kontroller' => $kontroller,
    'sayac'      => $sayac,
    'ortam'      => ['host' => $host, 'https' => $httpsVar, 'yerel' => $yerelMi, 'php' => PHP_VERSION],
]);
