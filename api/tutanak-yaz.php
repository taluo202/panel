<?php
declare(strict_types=1);
require_once __DIR__ . '/tutanak_kurallari.php';
require_once __DIR__ . '/belge-zinciri-lib.php';

const IT_EIMZA_METNI = 'T.C. e-imza ile imzalanmıştır.';
const IT_CEKINCE = 'Makine sahada gözle kontrol edilmiştir; ayrıntılı muayene dönüş kontrolünde yapılacak, tespit edilen hasar ayrıca bildirilecektir.';

function itCekinceliNot(?string $not): string {
    $not = trim((string)$not);
    if (str_contains($not, IT_CEKINCE)) return $not;
    return $not === '' ? IT_CEKINCE : IT_CEKINCE . "\n" . $not;
}

function itYakitTuru($v): ?string {
    return in_array($v, ['akulu', 'dizel', 'lpg', 'hibrit', 'elektrik', 'benzinli'], true) ? $v : null;
}
function itBelgeNo(int $id, string $tarih): string {
    return 'TUT-' . date('Y', strtotime($tarih)) . '-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
}

function itKiralayanBul(PDO $db, int $kiralamaId): array {
    $q = $db->prepare('SELECT * FROM kiralamalar WHERE id=?');
    $q->execute([$kiralamaId]);
    $uid = (int)(($q->fetch(PDO::FETCH_ASSOC) ?: [])['olusturan_id'] ?? 0);
    $log = function () use ($db, $kiralamaId): array {
        $q = $db->prepare("SELECT kullanici_id, kullanici_ad FROM islem_log WHERE tablo='kiralamalar' AND kayit_id=? AND eylem='ekle' ORDER BY id LIMIT 1");
        $q->execute([$kiralamaId]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: [];
    };
    $l = $uid < 1 ? $log() : null;
    if ($l !== null) $uid = (int)($l['kullanici_id'] ?? 0);
    $u = [];
    if ($uid > 0) {
        $q = $db->prepare('SELECT u.personel_id, u.ad_soyad, p.ad_soyad AS personel_ad FROM users u LEFT JOIN personel p ON p.id=u.personel_id WHERE u.id=?');
        $q->execute([$uid]);
        $u = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    $ad = trim((string)($u['personel_ad'] ?? '')) ?: trim((string)($u['ad_soyad'] ?? ''));
    if ($ad === '') $ad = trim((string)(($l ?? $log())['kullanici_ad'] ?? ''));
    return ['ad' => $ad, 'personel_id' => (int)($u['personel_id'] ?? 0)];
}

function itImzaGorseli(string $yol): ?string {
    if (!preg_match('~^signatures/[A-Za-z0-9._-]+$~', $yol) || str_contains($yol, '..')) return null;
    $kok = realpath(UPLOAD_PATH . 'signatures');
    $tam = realpath(UPLOAD_PATH . $yol);
    if ($kok === false || $tam === false || !str_starts_with($tam, $kok . DIRECTORY_SEPARATOR) || !is_file($tam) || filesize($tam) > 5 * 1024 * 1024) return null;
    $ham = @file_get_contents($tam);
    $b = $ham ? @getimagesizefromstring($ham) : false;
    if (!$b || $b[0] < 1 || $b[1] < 1 || $b[0] * $b[1] > GORSEL_MAX_PIKSEL) return null;
    $mime = (string)($b['mime'] ?? '');
    if (function_exists('imagecreatefromstring') && ($mime === 'image/webp' || $b[0] > 600 || $b[1] > 300)) {
        if (!gorselBellekYeterli((int)$b[0], (int)$b[1], false, (int)$b[2])) return null;
        $im = @imagecreatefromstring($ham);
        if (!$im) return null;
        $o = min(1.0, 600 / $b[0], 300 / $b[1]);
        $w = max(1, (int)round($b[0] * $o)); $h = max(1, (int)round($b[1] * $o));
        $hedef = imagecreatetruecolor($w, $h);
        imagealphablending($hedef, false);
        imagesavealpha($hedef, true);
        imagefill($hedef, 0, 0, imagecolorallocatealpha($hedef, 255, 255, 255, 127));
        imagecopyresampled($hedef, $im, 0, 0, 0, 0, $w, $h, $b[0], $b[1]);
        ob_start();
        imagepng($hedef);
        $ham = (string)ob_get_clean();
        $mime = 'image/png';
    }
    return $ham !== '' && in_array($mime, ['image/png', 'image/jpeg'], true) ? 'data:' . $mime . ';base64,' . base64_encode($ham) : null;
}

function itKiralayanImza(PDO $db, int $kiralamaId): array {
    $k = ['ad' => '', 'personel_id' => 0];
    $imza = null; $sahip = null; $yonetici = [];
    try {
        $k = itKiralayanBul($db, $kiralamaId);
        $adaylar = [];
        if ($k['personel_id'] > 0) {
            $q = $db->prepare("SELECT ad_soyad, unvan, img_yolu, 'personel' AS kaynak FROM imzalar WHERE personel_id=? AND COALESCE(img_yolu,'')<>'' ORDER BY id DESC");
            $q->execute([$k['personel_id']]);
            $adaylar = $q->fetchAll(PDO::FETCH_ASSOC);
        }
        $yonetici = $db->query("SELECT ad_soyad, unvan, img_yolu, 'yonetici' AS kaynak FROM imzalar WHERE rol='yonetici' ORDER BY (COALESCE(img_yolu,'')<>'') DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
        foreach (array_merge($adaylar, $yonetici) as $s) if (($imza = itImzaGorseli((string)($s['img_yolu'] ?? ''))) !== null) { $sahip = $s; break; }
    } catch (\Throwable $e) {
        error_log('Taloo: kiralayan imzasi cozulemedi: ' . $e->getMessage());
        $imza = null; $sahip = null;
    }
    $metin = null;
    if ($imza === null) {
        try {
            $q = $db->prepare("SELECT deger FROM ayarlar WHERE anahtar='tutanak_eimza_metni'");
            $q->execute();
            $metin = trim((string)($q->fetchColumn() ?: ''));
        } catch (\Throwable $e) { $metin = ''; }
        $metin = $metin !== '' ? mb_substr($metin, 0, 200) : IT_EIMZA_METNI;
        $imzaAd = $k['ad'] !== '' ? $k['ad'] : trim((string)($yonetici[0]['ad_soyad'] ?? ''));
        $unvan = '';
    } else {
        $imzaAd = trim((string)($sahip['ad_soyad'] ?? '')) ?: ($sahip['kaynak'] === 'personel' ? $k['ad'] : '');
        $unvan = trim((string)($sahip['unvan'] ?? ''));
    }
    return ['kiralayan_ad' => $k['ad'] !== '' ? $k['ad'] : null, 'kiralayan_imza_ad' => $imzaAd !== '' ? $imzaAd : null,
            'kiralayan_imza_unvan' => $unvan !== '' ? $unvan : null, 'kiralayan_imza' => $imza, 'kiralayan_imza_metni' => $metin,
            'kiralayan_imza_kaynak' => $imza === null ? 'eimza' : (string)$sahip['kaynak']];
}

function _imzaSahibi(array $adaylar, string $yedekAd = ''): array {
    foreach ($adaylar as $s) {
        if (($imza = itImzaGorseli((string)($s['img_yolu'] ?? ''))) === null) continue;
        $ad = trim((string)($s['ad_soyad'] ?? '')) ?: (($s['kaynak'] ?? '') === 'personel' ? trim($yedekAd) : '');
        $unvan = trim((string)($s['unvan'] ?? ''));
        return ['imza' => $imza, 'ad' => $ad !== '' ? $ad : null, 'unvan' => $unvan !== '' ? $unvan : null, 'kaynak' => (string)($s['kaynak'] ?? '')];
    }
    return ['imza' => null, 'ad' => null, 'unvan' => null, 'kaynak' => null];
}

function personelImzasi(PDO $db, int $personelId): array {
    if ($personelId < 1) return _imzaSahibi([]);
    try {
        $q = $db->prepare("SELECT ad_soyad, unvan, img_yolu, 'personel' AS kaynak FROM imzalar WHERE personel_id=? AND COALESCE(img_yolu,'')<>'' ORDER BY id DESC");
        $q->execute([$personelId]);
        return _imzaSahibi($q->fetchAll(PDO::FETCH_ASSOC));
    } catch (\Throwable $e) {
        error_log('Taloo: personel imzasi cozulemedi: ' . $e->getMessage());
        return _imzaSahibi([]);
    }
}

function firmaImzasi(PDO $db, int $kiralamaId = 0): array {
    try {
        $k = $kiralamaId > 0 ? itKiralayanBul($db, $kiralamaId) : ['ad' => '', 'personel_id' => 0];
        $adaylar = [];
        if ($k['personel_id'] > 0) {
            $q = $db->prepare("SELECT ad_soyad, unvan, img_yolu, 'personel' AS kaynak FROM imzalar WHERE personel_id=? AND COALESCE(img_yolu,'')<>'' ORDER BY id DESC");
            $q->execute([$k['personel_id']]);
            $adaylar = $q->fetchAll(PDO::FETCH_ASSOC);
        }
        $yonetici = $db->query("SELECT ad_soyad, unvan, img_yolu, 'yonetici' AS kaynak FROM imzalar WHERE rol='yonetici' AND COALESCE(img_yolu,'')<>'' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        return _imzaSahibi(array_merge($adaylar, $yonetici), $k['ad']);
    } catch (\Throwable $e) {
        error_log('Taloo: firma imzasi cozulemedi: ' . $e->getMessage());
        return _imzaSahibi([]);
    }
}

function itKiralayanSabitle(PDO $db, int $tutanakId, int $kiralamaId): void {
    $v = itKiralayanImza($db, $kiralamaId);
    $db->prepare('UPDATE iade_tutanaklari SET kiralayan_ad=?, kiralayan_imza_ad=?, kiralayan_imza_unvan=?, kiralayan_imza=?, kiralayan_imza_metni=?, kiralayan_imza_kaynak=? WHERE id=?')
       ->execute([$v['kiralayan_ad'], $v['kiralayan_imza_ad'], $v['kiralayan_imza_unvan'], $v['kiralayan_imza'], $v['kiralayan_imza_metni'], $v['kiralayan_imza_kaynak'], $tutanakId]);
}

function itImzaVerisiGecerli(string $v): bool {
    return preg_match('~^data:image/(png|jpeg);base64,([A-Za-z0-9+/=]+)$~', $v, $m) === 1
        && ($ham = base64_decode($m[2], true)) !== false && @getimagesizefromstring($ham) !== false;
}

function itKiralayanEksikseSabitle(PDO $db, int $tutanakId): void {
    $q = $db->prepare("SELECT kiralama_id FROM iade_tutanaklari WHERE id=? AND COALESCE(kiralayan_imza,'')='' AND COALESCE(kiralayan_imza_metni,'')=''");
    $q->execute([$tutanakId]);
    if (($kid = (int)$q->fetchColumn()) > 0) itKiralayanSabitle($db, $tutanakId, $kid);
}

function itYenidenKabulImzaYaz(PDO $db, int $tutanakId, string $imzaMusteri, string $temsilci, ?string $imzaPersonel, string $imzalayanAd): void {
    $db->prepare('UPDATE iade_tutanaklari SET imza_musteri=?, musteri_temsilci_ad=? WHERE id=?')->execute([$imzaMusteri, mb_substr(trim($temsilci), 0, 120), $tutanakId]);
    if ($imzaPersonel !== null)
        $db->prepare('UPDATE iade_tutanaklari SET imza_personel=?, imza_personel_ad=? WHERE id=?')->execute([$imzaPersonel, $imzalayanAd, $tutanakId]);
}

function itGerekliAcilar(PDO $db, array $k, string $islem, string $kural): array {
    return ($kural === 'saha' && $islem === 'teslim') ? hzEksikAcilar($db, $k) : [];
}

function itFotoHatasi(array $fotolar, string $tur, string $kural, array $gerekli, bool $hasar): ?string {
    if ($kural === 'tam') {
        if ($h = tutanakFotoHatasi($fotolar, $tur)) return $h;
        return $fotolar ? null : 'En az bir fotoğraf eklemelisiniz.';
    }
    if (count($fotolar) > 20) return 'Bir tutanakta en fazla 20 güncel fotoğraf eklenebilir.';
    $var = [];
    foreach ($fotolar as $i => $f) {
        if (is_array($f) && ($_gh = gorselVeriHatasi($f['veri'] ?? null))) return ((int)$i + 1) . '. fotoğraf: ' . $_gh;
        if (!is_array($f) || !is_string($f['veri'] ?? null) || tutanakFotoBinary($f['veri']) === null)
            return ((int)$i + 1) . '. fotoğraf okunamadı. Yeniden seçip gönderin.';
        $aci = trim((string)($f['aci'] ?? ''));
        if ($aci === '') return ((int)$i + 1) . '. fotoğrafın açısını seçin.';
        $var[hzAciAnahtar($aci)] = true;
    }
    if ($kural === 'kagit' && !isset($var[hzAciAnahtar('Kâğıt tutanak')])) return 'Kâğıt tutanağın fotoğrafını ekleyin.';
    $eksik = array_values(array_filter($gerekli, fn($a) => !isset($var[hzAciAnahtar((string)$a)])));
    if ($eksik) return 'Zorunlu güncel fotoğraflar eksik: ' . implode(', ', $eksik);
    if ($hasar && !$fotolar) return 'Hasar bildirildiğinde en az bir fotoğraf ekleyin.';
    return null;
}

function itTutanakYaz(PDO $db, array $body, array $kim, array $sec, array &$yazilanFotolar): array {
    islemKilitAl($db);
    $kural = (string)($sec['foto_kurali'] ?? 'tam');
    if (!in_array($kural, ['tam', 'saha', 'kagit'], true)) throw new InvalidArgumentException('Geçersiz fotoğraf kuralı.');
    $kiralamaId = (int)($body['kiralama_id'] ?? 0);
    $kStmt = $db->prepare('SELECT * FROM kiralamalar WHERE id=?');
    $kStmt->execute([$kiralamaId]);
    $kiralama = $kStmt->fetch(PDO::FETCH_ASSOC);
    if (!$kiralama) throw new DomainException('Kiralama bulunamadı.');
    if (isset($sec['beklenen_makine']) && [(string)$kiralama['makine_tur'], (int)$kiralama['makine_id']] !== $sec['beklenen_makine'])
        throw new DomainException('Kiralama değişti; yeniden yükleyin.');
    if ($hata = tutanakAlanHatasi($body)) throw new InvalidArgumentException($hata);
    if (isset($body['makine_id']) && ((int)$body['makine_id'] !== (int)$kiralama['makine_id']
        || ($body['makine_tur'] ?? '') !== $kiralama['makine_tur']))
        throw new DomainException('Kiralamaya bağlı makine değişmiş. Formu güncel makineyle yeniden açın.');
    $islem = in_array($body['islem'] ?? '', ['teslim', 'iade'], true) ? $body['islem'] : 'iade';
    $genelDurum = in_array($body['genel_durum'] ?? '', ['saglam', 'hasarli', 'eksik_aksesuar'], true) ? $body['genel_durum'] : 'saglam';
    $fotograflarGiris = is_array($body['fotograflar'] ?? null) ? array_values($body['fotograflar']) : [];
    if ($hata = itFotoHatasi($fotograflarGiris, (string)$kiralama['makine_tur'], $kural,
                             itGerekliAcilar($db, $kiralama, $islem, $kural), $genelDurum !== 'saglam'))
        throw new InvalidArgumentException($hata);
    $personelImza = trim(metin($body['imza_personel'] ?? ''));
    if ($personelImza !== '' && ($kural === 'kagit' || !itImzaVerisiGecerli($personelImza)))
        throw new InvalidArgumentException($kural === 'kagit' ? 'Kâğıt tutanakta dijital personel imzası gönderilmez.' : 'Personel imzası okunamadı. İmzayı temizleyip yeniden atın ya da boş bırakın.');
    try { $kabulVeri = tsKabul($body, $kural === 'kagit'); } catch (DomainException $e) { throw new InvalidArgumentException($e->getMessage()); }
    $tarih = dateStr('tarih', $body) ?: date('Y-m-d');
    if ($islem === 'iade' && !empty($kiralama['baslangic']) && $tarih < $kiralama['baslangic'])
        throw new InvalidArgumentException('Gerçek iade tarihi (' . tarihGoster($tarih) . ') kiralamanın başlangıcından ('
            . tarihGoster((string)$kiralama['baslangic']) . ') önce olamaz.');
    if ((int)$kabulVeri['fiili_teslim'] === 1 && opYeniZincir($db, $kiralama)) $body = bzSahaGovdesi($db, $kiralama, $body, $islem, $kural, $tarih);
    foreach (['km', 'calisma_saati'] as $alan) {
        $v = $body[$alan] ?? null;
        if ($v !== null && $v !== '' && (!is_numeric($v) || !is_finite((float)$v) || (float)$v < 0))
            throw new InvalidArgumentException('Sayaç değeri sıfır veya pozitif bir sayı olmalıdır.');
    }
    $aksesuarlar = is_array($body['aksesuarlar'] ?? null) ? json_encode(array_values($body['aksesuarlar']), JSON_UNESCAPED_UNICODE) : null;
    $enlem  = isset($body['enlem'])  && $body['enlem']  !== '' ? (float)$body['enlem']  : null;
    $boylam = isset($body['boylam']) && $body['boylam'] !== '' ? (float)$body['boylam'] : null;
    $yakitTuru = itYakitTuru($body['yakit_turu'] ?? null);
    $bilesenHasar = null;
    if (is_array($body['bilesen_hasar'] ?? null)) {
        $temiz = [];
        foreach ($body['bilesen_hasar'] as $bk => $bv) if (isset(BZ_BILESEN_AD[$bk]) && is_string($bv) && isset(BZ_BILESEN_DURUM[$bv])) $temiz[$bk] = $bv;
        if ($temiz) $bilesenHasar = json_encode($temiz, JSON_UNESCAPED_UNICODE);
    }
    $kisa = fn($a, $n = 120) => mb_substr(trim((string)($body[$a] ?? '')), 0, $n) ?: null;
    $genelNot = trim(metin($body['genel_not'] ?? '')) ?: null;
    if ($islem === 'iade' && (int)$kabulVeri['fiili_teslim'] === 1 && opYeniZincir($db, $kiralama)) $genelNot = itCekinceliNot($genelNot);

    if ($islem === 'teslim' && (int)$kabulVeri['fiili_teslim'] === 1 && operasyonKiralamaGuncelMi($db, $kiralama)
        && ($eng = hzEngel($db, $kiralama))) throw new DomainException($eng);
    $opAdim = $islem === 'teslim' ? 'saha_teslim' : 'saha_teslim_alma';
    $asama = ($islem === 'iade' && opYeniZincir($db, $kiralama)) ? 'saha_alim' : 'tutanak';
    if ((int)$kabulVeri['fiili_teslim'] === 1 && ($eng = opTekKayitEngeli($db, $kiralama, $opAdim))) throw new DomainException($eng);
    $sayimAcik = (bool)($sec['aksesuar_sayimi'] ?? true);
    $sayim    = $sayimAcik ? hzSayim($db, $kiralama, array_merge($body, ['islem' => $islem, 'tarih' => $tarih])) : null;
    $sayimRef = $sayimAcik ? hzReferans($db, $kiralamaId, $islem, $tarih) : null;
    $db->prepare("
        INSERT INTO iade_tutanaklari
          (kiralama_id, personel_id, islem, tarih, km, calisma_saati, yakit_seviyesi, genel_durum,
           aksesuarlar, hasar_notu, genel_not, imza_personel, imza_musteri, musteri_temsilci_ad, enlem, boylam, kaynak, kaydeden, ariza_kodlari,
           teslim_alan_firma, teslim_eden_firma, calisma_yeri, nakliye_plaka, ekspertiz_eden, yakit_turu,
           is_basi_tarih, is_basi_saat, is_sonu_tarih, is_sonu_saat, bilesen_hasar,
           operator_ad, operator_iletisim, operator_plaka, yetkili_iletisim, yetkili_plaka, istemci_anahtari, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, ?,?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,?, ?, ?)
    ")->execute([
        $kiralamaId, (int)($kim['personel_id'] ?? 0), $islem, $tarih,
        isset($body['km']) && $body['km'] !== '' ? (float)$body['km'] : null,
        isset($body['calisma_saati']) && $body['calisma_saati'] !== '' ? (float)$body['calisma_saati'] : null,
        trim(metin($body['yakit_seviyesi'] ?? '')) ?: null,
        $genelDurum, $aksesuarlar,
        trim(metin($body['hasar_notu'] ?? '')) ?: null,
        $genelNot,
        $personelImza ?: null,
        trim(metin($body['imza_musteri'] ?? '')) ?: null,
        trim(metin($body['musteri_temsilci_ad'] ?? '')) ?: null,
        $enlem, $boylam, (string)($kim['tutanak_kaynak'] ?? 'saha'), $kim['kaydeden'] ?? null,
        array_key_exists('ariza_kodlari', $body) ? json_encode(array_values($body['ariza_kodlari']), JSON_UNESCAPED_UNICODE) : null,
        $kisa('teslim_alan_firma', 160), $kisa('teslim_eden_firma', 160),
        $kisa('calisma_yeri', 160), $kisa('nakliye_plaka', 40), $kisa('ekspertiz_eden', 120),
        $yakitTuru,
        dateStr('is_basi_tarih', $body), $kisa('is_basi_saat', 8),
        dateStr('is_sonu_tarih', $body), $kisa('is_sonu_saat', 8),
        $bilesenHasar,
        $kisa('operator_ad', 120), $kisa('operator_iletisim', 60), $kisa('operator_plaka', 40),
        $kisa('yetkili_iletisim', 60), $kisa('yetkili_plaka', 40),
        $sec['istemci_anahtari'] ?? null,
        date('Y-m-d H:i:s'),
    ]);
    $tutanakId = (int)$db->lastInsertId();
    tsKabulYaz($db, $tutanakId, $kabulVeri);
    $db->prepare('UPDATE iade_tutanaklari SET aksesuar_kalemleri=?,aksesuar_referans_veri=? WHERE id=?')
       ->execute([$sayim === null ? null : json_encode($sayim, JSON_UNESCAPED_UNICODE), $sayim === null ? null : json_encode($sayimRef, JSON_UNESCAPED_UNICODE), $tutanakId]);
    $toplu = $sec['toplu'] ?? null;
    $db->prepare('UPDATE iade_tutanaklari SET teslim_eden_tur=?, toplu_teslim_id=?, toplu_adet=?, birlikte_bulunanlar=?,
                  imza_personel_ad=COALESCE(?, imza_personel_ad), asama=?, nakliye_sofor=? WHERE id=?')
       ->execute([(string)($kim['tur'] ?? 'personel'), $toplu['id'] ?? null, isset($toplu['adet']) ? (int)$toplu['adet'] : null,
                  !empty($sec['birlikte']) ? json_encode(array_values($sec['birlikte']), JSON_UNESCAPED_UNICODE) : null,
                  $sec['imza_personel_ad'] ?? null, $asama, $kisa('nakliye_sofor', 160), $tutanakId]);

    $fotoIns = $db->prepare("INSERT INTO iade_tutanak_fotograflari (tutanak_id, aci_tipi, dosya_yolu, aciklama, created_at) VALUES (?,?,?,?,?)");
    $kaydedilenFoto = 0;
    foreach ($fotograflarGiris as $f) {
        if (!is_array($f) || empty($f['veri'])) continue;
        $yol = _itFotoKaydet((string)$f['veri'], $tutanakId);
        if ($yol === null) throw new \RuntimeException('Fotoğraf diske yazılamadı.');
        $yazilanFotolar[] = $yol;
        $fotoIns->execute([$tutanakId, trim((string)($f['aci'] ?? '')) ?: null, $yol, mb_substr(trim((string)($f['aciklama'] ?? '')), 0, 500) ?: null, date('Y-m-d H:i:s')]);
        $kaydedilenFoto++;
    }
    $cikisAktar = !array_key_exists('cikis_fotograflari', $body) || !empty($body['cikis_fotograflari']);
    if ($cikisAktar && $kiralamaId > 0) $kaydedilenFoto += _itCikisFotolariniAktar($db, $tutanakId, $kiralamaId, $yazilanFotolar);
    if ($kural === 'tam' && $kaydedilenFoto < 1) throw new \RuntimeException('Geçerli fotoğraf yok.');

    if ($asama === 'tutanak')
        $db->prepare("UPDATE iade_tutanaklari SET belge_no=? WHERE id=?")->execute([itBelgeNo($tutanakId, $tarih), $tutanakId]);

    $hakedisId = null; $hakedisIdler = [];
    $iadeUyarilar = [];
    $kiralamaAcik = ($kiralama['durum'] ?? '') === 'aktif' && empty($kiralama['gercek_bitis']);
    if ($kabulVeri['fiili_teslim']) operasyonTutanakHareketi($db, $kiralama, $islem);
    if ($kabulVeri['fiili_teslim'] && opYeniZincir($db, $kiralama)) {
        opAdimYaz($db, $kiralamaId, $opAdim, 'tamam',
                  ['tur' => (string)($kim['tur'] ?? 'personel'), 'id' => $kim['yapan_id'] ?? null, 'ad' => (string)($kim['ad'] ?? '')],
                  (string)($kim['adim_kaynak'] ?? 'tutanak'), null, ['tutanak_id' => $tutanakId] + ($sec['adim_veri'] ?? []));
    }
    if ($islem === 'iade' && $kiralamaAcik && $kabulVeri['fiili_teslim']) {
        $db->prepare("UPDATE kiralamalar SET durum='tamamlandi', gercek_bitis=?, updated_at=datetime('now','localtime') WHERE id=?")
           ->execute([$tarih, $kiralamaId]);
        $bosAraliklar = hakedisBosAraliklar($db, $kiralamaId, (string)$kiralama['baslangic'], $tarih);
        foreach ($bosAraliklar as $bi => [$donemBas, $donemBit, $gun]) {
            if ($gun <= 0) continue;
            $final = ($bi === count($bosAraliklar) - 1);
            $hesap = hakedisKalemleriHesapla($db, $kiralama, $donemBas, $donemBit, $final);
            $tutar = $hesap['tutar'];
            $iadeUyarilar = array_merge($iadeUyarilar, $hesap['uyarilar']);
            if ($tutar > 0) {
                $kdvOran  = (float)($kiralama['kdv_oran'] ?? 20);
                require_once __DIR__ . '/zincir-lib.php';
                $dvz      = hakedisDovizHesap($db, $kiralama, $tutar, $kdvOran, min($tarih, date('Y-m-d')));
                $kdvTutar = $dvz['kdv_tutar'];
                $genel    = $dvz['genel_toplam'];
                if ($dvz['uyari']) $iadeUyarilar[] = $dvz['uyari'];
                $vade     = date('Y-m-d', strtotime($tarih . ' +15 days'));
                $db->prepare("
                    INSERT INTO hakedisler
                      (kiralama_id, musteri_id, donem_bas, donem_bit, aciklama, tutar, kdv_oran, kdv_tutar, genel_toplam, fatura_tarihi, vade_tarihi, sube, temsilci, durum)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, 'beklemede')
                ")->execute([
                    $kiralamaId, $kiralama['musteri_id'], $donemBas, $donemBit,
                    ($kiralama['ekipman_adi'] ?: 'Kiralama') . ' — iade dönemi (' . $donemBas . ' – ' . $donemBit . ')',
                    $dvz['tutar'], $kdvOran, $kdvTutar, $genel, null, $vade,
                    $kiralama['sube'], $kiralama['temsilci'],
                ]);
                $hakedisId = (int)$db->lastInsertId(); $hakedisIdler[] = $hakedisId;
                hakedisDovizYaz($db, $hakedisId, $dvz);
                hakedisKodUret($db, $hakedisId, $tarih);
                hakedisKalemleriYaz($db, $hakedisId, $hesap);
            }
        }
        if ($iadeUyarilar) {
            gorevOlustur($db, 'Hakediş kontrolü: ' . ($kiralama['kod'] ?? ('#' . $kiralamaId)) . ' kapandı',
                "Kiralama iade ile kapandı; son hakedişte kontrol edilmesi gerekenler:\n• " . implode("\n• ", $iadeUyarilar),
                'hakedis_kontrol', $kiralamaId, 'yuksek');
        }
    }
    itKiralayanSabitle($db, $tutanakId, $kiralamaId);
    tsKaydet($db, $tutanakId, 'Sahada/ofiste ilk kayıt.', 'ilk');
    tsKabulGorevi($db, $tutanakId);
    $servisId = hdTutanakServisSenkron($db, $tutanakId);
    $kapatildi = $islem === 'iade' && $kiralamaAcik && (int)$kabulVeri['fiili_teslim'] === 1;
    logla($db, 'iade_tutanaklari', $tutanakId, 'ekle', null, null,
          ($asama === 'saha_alim' ? 'Teslim alma kaydı' : ($islem === 'iade' ? 'İade tutanağı' : 'Teslim tutanağı')) . ' kaydedildi'
          . ($hakedisIdler ? ' · hakediş #' . implode(', #', $hakedisIdler) : '')
          . ($kapatildi ? ' · kiralama KAPATILDI' : '')
          . (($kim['tutanak_kaynak'] ?? 'saha') !== 'saha' ? ' · kaynak: ' . $kim['tutanak_kaynak'] : ''));
    if ($asama === 'tutanak' && $islem === 'teslim' && (int)$kabulVeri['fiili_teslim'] === 1 && opYeniZincir($db, $kiralama)) {
        require_once __DIR__ . '/tutanak-gonderim-lib.php';
        tgSirala($db, $tutanakId);
    }
    return ['id' => $tutanakId, 'islem' => $islem, 'asama' => $asama, 'fiili_teslim' => (int)$kabulVeri['fiili_teslim'], 'hakedis_id' => $hakedisId, 'hakedis_idler' => $hakedisIdler,
            'hakedis_uyarilar' => $iadeUyarilar, 'servis_id' => $servisId,
            'kiralama_acikti' => $kiralamaAcik, 'kiralama_kapatildi' => $kapatildi];
}

function itIadeTutanagiYaz(PDO $db, array $a, array $kim): array {
    islemKilitAl($db);
    if ($v = bzIadeTutanagi($db, (int)$a['kiralama_id']))
        throw new DomainException('Bu kiralamanın iade tutanağı zaten oluştu (' . ($v['belge_no'] ?: '#' . $v['id']) . '); ikinci kez oluşturulamaz.');
    $db->prepare("INSERT INTO iade_tutanaklari
          (kiralama_id, personel_id, islem, asama, tarih, km, calisma_saati, genel_durum, hasar_notu, genel_not,
           imza_personel, imza_personel_ad, musteri_temsilci_ad, teslim_eden_firma, teslim_alan_firma, calisma_yeri,
           kabul_sonucu, kabul_notu, fiili_teslim, saha_alim_id, depo_kontrol_id, inceleme_sonucu, inceleme_gerekce, kaynak, kaydeden,
           nakliye_plaka, nakliye_sofor, ekspertiz_eden, yakit_turu, is_basi_tarih, is_basi_saat, is_sonu_tarih, is_sonu_saat, operator_ad, operator_iletisim, created_at)
        VALUES (?,?,'iade','tutanak',?,?,?,?,?,?, ?,?,?,?,?,?, ?,?,1,?,?,?,?,'depo',?, ?,?,?,?,?,?,?,?,?,?, ?)")
       ->execute([(int)$a['kiralama_id'], (int)($_SESSION['personel_id'] ?? 0), $a['tarih'], $a['km'], $a['calisma_saati'],
                  $a['genel_durum'], $a['hasar_notu'], $a['genel_not'],
                  $a['imza_personel'], $a['imza_personel_ad'] ?: ($kim['ad'] ?? null), $a['musteri_temsilci_ad'],
                  $a['teslim_eden_firma'], $a['teslim_alan_firma'], $a['calisma_yeri'],
                  $a['kabul_sonucu'], $a['kabul_notu'], $a['saha_alim_id'], $a['depo_kontrol_id'], $a['inceleme_sonucu'],
                  $a['inceleme_gerekce'], ((string)($_SESSION['username'] ?? '')) ?: null,
                  $a['nakliye_plaka'] ?? null, $a['nakliye_sofor'] ?? null, $a['ekspertiz_eden'] ?? null, $a['yakit_turu'] ?? null,
                  $a['is_basi_tarih'] ?? null, $a['is_basi_saat'] ?? null, $a['is_sonu_tarih'] ?? null, $a['is_sonu_saat'] ?? null,
                  $a['operator_ad'] ?? null, $a['operator_iletisim'] ?? null, date('Y-m-d H:i:s')]);
    $id = (int)$db->lastInsertId();
    $no = itBelgeNo($id, (string)$a['tarih']);
    $db->prepare('UPDATE iade_tutanaklari SET belge_no=? WHERE id=?')->execute([$no, $id]);
    itKiralayanSabitle($db, $id, (int)$a['kiralama_id']);
    tsKaydet($db, $id, $a['inceleme_sonucu'] === 'yapilmadi' ? 'Dönüş kontrolü yapılmadan oluştu.' : 'Dönüş kontrolü tamamlandı.', 'ilk');
    logla($db, 'iade_tutanaklari', $id, 'ekle', null, ['inceleme_sonucu' => $a['inceleme_sonucu'], 'depo_kontrol_id' => $a['depo_kontrol_id']],
          'İade tutanağı ' . $no . ' oluştu (dönüş kontrolü · ' . ['sorunsuz' => 'sorunsuz', 'hasarli' => 'hasarlı', 'yapilmadi' => 'inceleme yapılmadı'][$a['inceleme_sonucu']] . ')');
    require_once __DIR__ . '/tutanak-gonderim-lib.php';
    tgSirala($db, $id);
    return ['id' => $id, 'belge_no' => $no];
}

function _itCikisFotolariniAktar(PDO $db, int $tutanakId, int $kiralamaId, array &$yazilan = []): int
{
    $st = $db->prepare("SELECT aci_tipi, aciklama, dosya_yolu FROM kiralama_fotograflari
                        WHERE kiralama_id = ? AND COALESCE(yon, 'gidis') = 'gidis' ORDER BY id");
    $st->execute([$kiralamaId]);
    $liste = $st->fetchAll();
    if (!$liste) return 0;

    $dir = UPLOAD_PATH . 'iade_tutanak/' . $tutanakId . '/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return 0;

    $ins = $db->prepare("INSERT INTO iade_tutanak_fotograflari (tutanak_id, aci_tipi, dosya_yolu, aciklama, created_at) VALUES (?,?,?,?,?)");
    $adet = 0;
    foreach ($liste as $f) {
        $kaynak = UPLOAD_PATH . $f['dosya_yolu'];
        if (!is_file($kaynak)) continue;
        $uzanti = pathinfo($f['dosya_yolu'], PATHINFO_EXTENSION) ?: 'jpg';
        $ad = bin2hex(random_bytes(16)) . '.' . $uzanti;
        if (!@copy($kaynak, $dir . $ad)) continue;
        $yazilan[] = 'iade_tutanak/' . $tutanakId . '/' . $ad;
        @chmod($dir . $ad, 0644);
        $aci = trim((string)($f['aci_tipi'] ?? '')) ?: 'genel';
        $ins->execute([$tutanakId, 'çıkış · ' . $aci, 'iade_tutanak/' . $tutanakId . '/' . $ad, mb_substr(trim((string)($f['aciklama'] ?? '')), 0, 500) ?: null, date('Y-m-d H:i:s')]);
        $adet++;
    }
    return $adet;
}

function _itFotoKaydet(string $dataUrl, int $tutanakId): ?string {
    if (!preg_match('/^data:image\/(jpe?g|png|webp);base64,([a-zA-Z0-9+\/=]+)$/', $dataUrl, $m)) return null;
    $binary = base64_decode($m[2], true);
    if ($binary === false || strlen($binary) < 100 || strlen($binary) > MAX_FILE_SIZE) return null;
    if (!gorselPikselUygun($binary)) return null;

    $ext = $m[1] === 'jpg' ? 'jpeg' : $m[1];
    $dir = UPLOAD_PATH . 'iade_tutanak/' . $tutanakId . '/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return null;
    $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
    if (file_put_contents($dir . $safeName, $binary) === false) return null;
    @chmod($dir . $safeName, 0644);
    return 'iade_tutanak/' . $tutanakId . '/' . $safeName;
}
