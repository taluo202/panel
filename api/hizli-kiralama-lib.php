<?php
declare(strict_types=1);

const HK_KONUM_HOSTLAR = ['www.google.com', 'google.com', 'www.google.com.tr', 'maps.google.com', 'maps.app.goo.gl', 'goo.gl'];
const HK_KONUM_HATA = 'Konum yalnız Google Haritalar bağlantısı ya da "enlem, boylam" olabilir.';
const HK_KONUM_AZAMI = 500;
const HK_HARITA_ARAMA = 'https://www.google.com/maps/search/?api=1&query=';
const HK_MASKE_UNVAN = 'Kayıtlı rezervasyon';

function _hkSutunEkle(PDO $db, string $tablo, array $sutunlar): void {
    $var = array_flip(array_column($db->query("PRAGMA table_info($tablo)")->fetchAll(PDO::FETCH_ASSOC), 'name'));
    foreach ($sutunlar as $ad => $tanim) {
        if (!isset($var[$ad])) $db->exec("ALTER TABLE $tablo ADD COLUMN $ad $tanim");
    }
}

function hkSema(PDO $db): void {
    _hkSutunEkle($db, 'kiralamalar', [
        'kaynak' => 'TEXT', 'saha_irtibat_ad' => 'TEXT', 'saha_irtibat_tel' => 'TEXT', 'saha_konum' => 'TEXT', 'olusturan_id' => 'INTEGER',
    ]);
    _hkSutunEkle($db, 'santiyeler', ['konum' => 'TEXT']);
    _hkSutunEkle($db, 'nakliyeler', [
        'dis_sofor_donus_ad' => 'TEXT', 'dis_sofor_donus_tel' => 'TEXT',
        'sofor_personel_id' => 'INTEGER', 'sofor_zaman' => 'TEXT', 'sofor_yapan' => 'INTEGER', 'sofor_kaynak' => 'TEXT', 'sofor_siparis_no' => 'TEXT',
    ]);
    _hkSutunEkle($db, 'teslim_baglantilari', ['alici_ad' => 'TEXT', 'alici_tel' => 'TEXT', 'alici_eposta' => 'TEXT', 'not_metni' => 'TEXT', 'gecersiz_zaman' => 'TEXT']);
    $db->exec("CREATE TABLE IF NOT EXISTS siparis_istekleri (anahtar TEXT PRIMARY KEY, siparis_no TEXT NOT NULL, yanit TEXT NOT NULL,
        kullanici_id INTEGER, created_at TEXT DEFAULT (datetime('now','localtime')))");
}

function hkKonumDogrula(?string $girdi): ?string {
    $s = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', (string)$girdi);
    if ($s === null) throw new DomainException(HK_KONUM_HATA);
    if ($s === '') return null;

    if (preg_match('/^([+-]?\d{1,2}(?:\.\d{1,15})?)\s*,\s*([+-]?\d{1,3}(?:\.\d{1,15})?)\z/', $s, $m)) {
        if (abs((float)$m[1]) > 90 || abs((float)$m[2]) > 180) throw new DomainException(HK_KONUM_HATA);
        return HK_HARITA_ARAMA . ltrim($m[1], '+') . ',' . ltrim($m[2], '+');
    }

    if (preg_match('/[\s\p{Z}\p{C}\\\\]/u', $s) !== 0) throw new DomainException(HK_KONUM_HATA);
    if (!preg_match('#^https://([^/?\#]*)(.*)\z#is', $s, $m)) throw new DomainException(HK_KONUM_HATA);
    $host = strtolower($m[1]);
    $kalan = $m[2];
    if (!in_array($host, HK_KONUM_HOSTLAR, true)) throw new DomainException(HK_KONUM_HATA);
    $yol = substr($kalan, 0, strcspn($kalan, '?#'));
    if ($host !== 'maps.app.goo.gl' && !preg_match('#^/maps(/|\z)#', $yol)) throw new DomainException(HK_KONUM_HATA);
    foreach (explode('/', str_ireplace('%2e', '.', $yol)) as $bolum) {
        if ($bolum === '.' || $bolum === '..') throw new DomainException(HK_KONUM_HATA);
    }
    $p = parse_url($s);
    if (!is_array($p) || strtolower((string)($p['scheme'] ?? '')) !== 'https' || strtolower((string)($p['host'] ?? '')) !== $host
        || isset($p['user']) || isset($p['pass']) || isset($p['port'])) throw new DomainException(HK_KONUM_HATA);

    $kanonik = 'https://' . $host . preg_replace_callback('/[\x80-\xFF"\'<>`]/', fn(array $b): string => '%' . strtoupper(bin2hex($b[0])), $kalan);
    if (strlen($kanonik) > HK_KONUM_AZAMI) throw new DomainException(HK_KONUM_HATA);
    return $kanonik;
}

function hkHaritaLinki(?string $konum, ?string $lokasyon, ?string $il): ?string {
    $k = hkKonumDogrula($konum);
    if ($k !== null) return $k;
    $sorgu = trim(trim((string)$lokasyon) . ' ' . trim((string)$il));
    return $sorgu !== '' ? HK_HARITA_ARAMA . rawurlencode($sorgu) : null;
}

function hkTelLinki(?string $tel): ?string {
    $t = trim((string)$tel);
    $rakam = (string)preg_replace('/\D+/', '', $t);
    if ($rakam === '') return null;
    if (str_starts_with($t, '+')) return 'tel:+' . $rakam;
    if (strlen($rakam) === 11 && $rakam[0] === '0') return 'tel:+90' . substr($rakam, 1);
    if (strlen($rakam) === 10) return 'tel:+90' . $rakam;
    return 'tel:' . $rakam;
}

function hkIrtibat(array $r): array {
    $al = function (string $k) use ($r): ?string {
        $v = trim((string)($r[$k] ?? ''));
        return $v !== '' ? $v : null;
    };
    if ($al('saha_irtibat_tel') !== null) {
        [$ad, $tel, $kaynak] = [$al('saha_irtibat_ad'), $al('saha_irtibat_tel'), 'santiye'];
    } elseif ($al('musteri_yetkili') !== null || $al('musteri_telefon') !== null) {
        [$ad, $tel, $kaynak] = [$al('musteri_yetkili'), $al('musteri_telefon'), 'musteri'];
    } else {
        [$ad, $tel, $kaynak] = [null, null, null];
    }
    return ['ad' => $ad, 'tel' => $tel, 'tel_link' => hkTelLinki($tel),
            'harita' => hkHaritaLinki($al('saha_konum'), $al('lokasyon'), $al('il')), 'kaynak' => $kaynak];
}

function hkCakismaMaskele(array $cakismalar, ?string $kisit): array {
    return array_map(function (array $c) use ($kisit): array {
        if ($kisit !== null && trim((string)($c['sube'] ?? '')) !== $kisit) {
            $c['kod'] = null;
            $c['musteri_unvan'] = HK_MASKE_UNVAN;
        }
        unset($c['sube']);
        return $c;
    }, $cakismalar);
}

function hkIrtibatSiniri(string $alan, string $deger): ?string {
    $sinir = ['yetkili' => [120, 'Şantiye yetkilisi'], 'telefon' => [40, 'Yetkili telefonu']][$alan] ?? null;
    if ($sinir === null) throw new InvalidArgumentException('Bilinmeyen irtibat alanı: ' . $alan);
    [$azami, $etiket] = $sinir;
    return mb_strlen($deger, 'UTF-8') > $azami ? $etiket . ' en çok ' . $azami . ' karakter olabilir.' : null;
}

function hkIstekAnahtariGecerli(?string $a): bool {
    return $a !== null && preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $a) === 1;
}

function hkIstekBul(PDO $db, string $anahtar, int $kullaniciId): ?array {
    $q = $db->prepare('SELECT yanit, kullanici_id FROM siparis_istekleri WHERE anahtar=?');
    $q->execute([$anahtar]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if ($r === false) return null;
    if ($kullaniciId < 1 || (int)$r['kullanici_id'] !== $kullaniciId) throw new DomainException('Geçersiz istek anahtarı.');
    $v = json_decode((string)$r['yanit'], true, 512, JSON_THROW_ON_ERROR);
    return is_array($v) ? $v : [];
}

function hkIstekKaydet(PDO $db, string $anahtar, string $siparisNo, array $yanit, int $kullaniciId): void {
    if (!hkIstekAnahtariGecerli($anahtar)) throw new InvalidArgumentException('Geçersiz sipariş istek anahtarı.');
    $db->prepare('INSERT INTO siparis_istekleri (anahtar, siparis_no, yanit, kullanici_id) VALUES (?,?,?,?)')
       ->execute([$anahtar, $siparisNo, json_encode($yanit, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $kullaniciId]);
}

function _hkFirma(PDO $db): string {
    $s = $db->query("SELECT deger FROM ayarlar WHERE anahtar='firma_unvan'");
    return trim((string)($s ? $s->fetchColumn() : '')) ?: 'Taloo';
}

function hkNakliyeciMetni(PDO $db, int $nakliyeId, string $yon, ?string $baglanti): array {
    if (!in_array($yon, ['gidis', 'donus'], true)) throw new DomainException('Geçersiz yön.');
    $s = $db->prepare('SELECT * FROM nakliyeler WHERE id=?');
    $s->execute([$nakliyeId]);
    $n = $s->fetch(PDO::FETCH_ASSOC);
    if (!$n) throw new DomainException('Nakliye aracı bulunamadı.');
    if (($n['tip'] ?? '') === 'avk') throw new DomainException('Bu araç bizim aracımız; iş detayı yalnız dış nakliyeciye gönderilir.');
    $aracYon = (string)(($n['yon'] ?? '') ?: 'gidis');
    $uygun = $yon === 'donus' ? in_array($aracYon, ['donus', 'gidis_donus'], true) : in_array($aracYon, ['gidis', 'gidis_donus', 'tek'], true);
    if (!$uygun) throw new DomainException($yon === 'donus' ? 'Bu araç bir dönüş aracı değil.' : 'Bu araç bir gidiş aracı değil.');

    $ks = _sfAracKiralamalari($db, $n);
    if ($baglanti !== null) {
        $sut = $yon === 'donus' ? 'toplama_zaman' : 'yuklendi_zaman';
        $y = $db->prepare("SELECT kiralama_id FROM nakliye_makineleri WHERE nakliye_id=? AND $sut IS NOT NULL");
        $y->execute([$nakliyeId]);
        $yuk = array_flip(array_map('intval', $y->fetchAll(PDO::FETCH_COLUMN)));
        if ($yuk) $ks = array_values(array_filter($ks, fn(array $k): bool => isset($yuk[(int)$k['id']])));
    }
    if (!$ks) throw new DomainException('Bu araçta taşınacak açık kiralama yok.');

    $c = sfDisCift($n, $yon);
    $ad = $c['ad'] !== '' ? $c['ad'] : null;
    $tel = $c['tel'] !== '' ? $c['tel'] : null;
    $il = sfIletisim($db, (int)$ks[0]['id']);
    $tarih = $yon === 'donus'
        ? (($aracYon === 'donus' ? trim((string)($n['tarih'] ?? '')) : '') ?: trim((string)($ks[0]['bitis'] ?? '')) ?: trim((string)($n['tarih'] ?? '')))
        : (string)(_sfIsTarihi($n, $ks[0]) ?? '');
    $tarihYazi = preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $tarih, $t) ? $t[3] . '.' . $t[2] . '.' . $t[1] : 'tarih belirtilmedi';

    $satir = ['Merhaba' . ($ad !== null ? ' ' . $ad : '') . ',', _hkFirma($db) . ' adına iş: ' . $tarihYazi, 'Müşteri: ' . $il['musteri_unvan']];
    $yer = implode(' / ', array_filter([trim($il['lokasyon']), trim($il['il'])], fn($v) => $v !== ''));
    if ($yer !== '') $satir[] = 'Şantiye: ' . $yer;
    if ($il['ad'] !== null || $il['tel'] !== null) $satir[] = 'İrtibat: ' . implode(' ', array_filter([$il['ad'], $il['tel']], fn($v) => $v !== null));
    if ($il['harita'] !== null) $satir[] = 'Konum: ' . $il['harita'];
    $satir[] = 'Makineler:';
    $bilgi = makineBilgiToplu($db, array_map(fn(array $k): array => ['tur' => $k['makine_tur'], 'id' => (int)$k['makine_id']], $ks));
    foreach ($ks as $k) {
        $b = $bilgi[$k['makine_tur'] . ':' . (int)$k['makine_id']] ?? [];
        $metraj = $b['metraj'] ?? null;
        $metrajYazi = $metraj !== null ? rtrim(rtrim(number_format((float)$metraj, 2, ',', ''), '0'), ',') . (trim((string)($b['olcu_birim'] ?? '')) !== '' ? ' ' . trim((string)$b['olcu_birim']) : '') : '';
        $parca = array_filter([trim(trim((string)($b['marka'] ?? '')) . ' ' . trim((string)($b['model'] ?? ''))), trim((string)($b['kategori'] ?? '')), $metrajYazi], fn($v) => $v !== '');
        $satir[] = '• ' . ((string)($b['kod'] ?? '') ?: (string)($k['kod'] ?: '#' . $k['id'])) . ($parca ? ' — ' . implode(', ', $parca) : '');
    }
    $satir[] = $baglanti !== null ? 'İmza bağlantınız (48 saat geçerli): ' . $baglanti : 'İmza bağlantınız yüklemede ayrıca gelecek.';
    $metin = implode("\n", $satir);

    require_once __DIR__ . '/whatsapp.php';
    $wa = $tel !== null ? waTelefon($tel) : null;
    return ['ad' => $ad, 'tel' => $tel, 'metin' => $metin, 'wa' => $wa !== null ? 'https://wa.me/' . $wa . '?text=' . rawurlencode($metin) : null];
}
