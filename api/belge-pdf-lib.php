<?php
declare(strict_types=1);
require_once __DIR__ . '/pdf-lib.php';
require_once __DIR__ . '/hasar-belge-lib.php';


const BP_KABUL = ['teslim_aldi' => 'İmzalı kabul', 'cekinceli' => 'Çekinceli kabul', 'yetkili_yok' => 'Yetkili bulunamadı',
    'imza_vermedi' => 'İmza verilmedi', 'teslim_edilemedi' => 'Teslim gerçekleşmedi', 'yeniden_onay' => 'Yeniden imza/kabul bekliyor',
    'eski_istemci' => 'Eski istemci — kabul ayrıca kayıtlı değil'];
const BP_ARSIV_TURU = ['ilk' => 'İlk kayıt', 'gecis' => 'Geçiş arşivi', 'duzeltme' => 'Düzeltme — yeni imza gerekir', 'kabul' => 'Güncel kabul'];
const BP_GENEL_DURUM = ['saglam' => 'Sağlam / Eksiksiz', 'hasarli' => 'Hasarlı', 'eksik_aksesuar' => 'Eksik Aksesuar'];
const BP_BILESEN = ['tekerlek' => 'Tekerlek', 'korkuluk' => 'Korkuluklar', 'joystick' => 'Joystick', 'boya' => 'Boya', 'aku' => 'Akü',
    'kaporta' => 'Kaporta', 'destek_ayaklari' => 'Destek Ayakları'];
const BP_BILESEN_DURUM = ['hasarsiz' => 'Hasarsız', 'az_hasarli' => 'Az Hasarlı', 'cok_hasarli' => 'Çok Hasarlı'];
const BP_SONUC = ['sorunsuz' => 'Sorunsuz — dönüş kontrolünde hasar ve eksik yok.',
    'hasarli' => 'Hasarlı — dönüş kontrolünde tespit edilen hasar / eksik ayrıca bildirilir.', 'yapilmadi' => 'Dönüş kontrolü yapılmadı.'];
const BP_BULGU = ['hasar' => 'Hasar', 'eksik' => 'Eksik', 'ariza' => 'Arıza'];
const BP_KONTROL = ['uygun' => 'Uygun', 'uygun_degil' => 'Uygun değil', 'uygulanamaz' => 'Uygulanamaz', 'bekliyor' => 'Bekliyor'];
const BP_EIMZA_METNI = 'T.C. e-imza ile imzalanmıştır.';
const BP_SAHA_IMZA_NOTU = 'Şantiyede teslim alma anında atılan imza';
const BP_TESLIM_EDEN = ['dis_sofor' => 'Dış nakliye şoförü', 'kullanici' => 'Ofis kullanıcısı', 'ofis_kagit' => 'Ofis — kâğıt tutanaktan aktarıldı'];
const BP_YAKIT = ['akulu' => 'Akülü', 'dizel' => 'Dizel', 'lpg' => 'LPG', 'hibrit' => 'Hibrit', 'elektrik' => 'Elektrik', 'benzinli' => 'Benzinli'];
const BP_SAYIM_DURUM = ['saglam' => 'Sağlam', 'hasarli' => 'Hasarlı', 'eksik' => 'Eksik', 'fazla' => 'Fazla'];
const BP_HASAR_AD = ['IT' => 'İade Tutanağı', 'DHT' => 'Dönüş Hasar Tespit Tutanağı', 'DDK' => 'Dönüş Kontrol Belgesi',
    'TSR' => 'Teknik Servis Raporu', 'HBB' => 'Hasar Bedeli Bildirimi'];
const BP_HASAR_SIRA = ['IT' => 1, 'DHT' => 1, 'DDK' => 1, 'TSR' => 2, 'HBB' => 3];
const BP_YONTEM = ['ayri' => 'Ayrı fatura', 'yok' => 'Ücret yansıtılmamıştır'];


function bpTutanakPdf(PDO $db, array $belge): string {
    $c = new BelgePdf($db, hbFotoListesi([$belge]));
    $c->tutanak(is_array($belge['icerik'] ?? null) ? $belge['icerik'] : []);
    return $c->cikti();
}

function bpHasarDosyasiPdf(PDO $db, array $belgeler): string {
    usort($belgeler, fn($a, $b) => (BP_HASAR_SIRA[$a['tur'] ?? ''] ?? 9) <=> (BP_HASAR_SIRA[$b['tur'] ?? ''] ?? 9));
    $c = new BelgePdf($db, hbFotoListesi($belgeler));
    foreach ($belgeler as $b) $c->hasarBelge($b);
    return $c->cikti('Hasar dosyası ' . implode(', ', array_filter(array_map(fn($b) => bpMetin($b['no'] ?? ''), $belgeler))));
}

function bpIndir(array $ek): void {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . bpDosyaAdi(preg_replace('/\.pdf$/i', '', (string)$ek['ad'])) . '"');
    header('Content-Length: ' . strlen((string)$ek['veri']));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo $ek['veri'];
    exit;
}

function bpDosyaAdi(string $ad): string {
    $s = strtr($ad, ['ğ' => 'g', 'Ğ' => 'G', 'ü' => 'u', 'Ü' => 'U', 'ş' => 's', 'Ş' => 'S', 'ı' => 'i', 'İ' => 'I', 'ö' => 'o', 'Ö' => 'O', 'ç' => 'c', 'Ç' => 'C']);
    $s = trim((string)preg_replace('/[^A-Za-z0-9._-]+/', '-', $s), '-.');
    return ($s !== '' ? substr($s, 0, 80) : 'belge') . '.pdf';
}


function bpVar(mixed $v): bool { return !($v === null || $v === false || $v === '' || $v === 0 || $v === 0.0); }
function bpMetin(mixed $v): string { return is_scalar($v) ? trim((string)$v) : ''; }
function bpTarih(mixed $d): string {
    $s = bpMetin($d);
    if ($s === '') return '—';
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m) ? "$m[3].$m[2].$m[1]" : $s;
}
function bpTarihSaat(mixed $d, string $bos = '—'): string {
    $s = bpMetin($d);
    if ($s === '') return $bos;
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2})(?::\d{2})?)?$/', $s, $m) ? "$m[3].$m[2].$m[1]" . (($m[4] ?? '') !== '' ? " $m[4]:$m[5]" : '') : $s;
}
function bpSayi(float $n): string { return rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ','); }
function bpPara(mixed $n, mixed $pb): string { return number_format((float)$n, 2, ',', '.') . ' ' . (bpMetin($pb) ?: 'TL'); }
function bpSayiOku(mixed $v): ?float {
    if (is_int($v) || is_float($v)) return (float)$v;
    return preg_match('/^[-+]?(\d+\.?\d*|\.\d+)(e[-+]?\d+)?/i', bpMetin($v), $m) ? (float)$m[0] : null;
}
function bpSayiMetin(mixed $v): string {
    $s = bpMetin($v);
    return ($s !== '' && preg_match('/^[-+]?\d*[.,]?\d+$/', $s)) ? bpSayi((float)str_replace(',', '.', $s)) : $s;
}
function bpJson(mixed $v): mixed {
    if (is_array($v)) return $v;
    if (!is_string($v) || trim($v) === '') return null;
    try { return json_decode($v, true, 64, JSON_THROW_ON_ERROR); } catch (\JsonException $e) { return null; }
}
function bpTrNorm(mixed $v): string {
    return mb_strtolower(trim(strtr(bpMetin($v), ['İ' => 'i', 'I' => 'i', 'ı' => 'i', 'Ş' => 's', 'ş' => 's', 'Ğ' => 'g', 'ğ' => 'g', 'Ü' => 'u', 'ü' => 'u',
        'Ö' => 'o', 'ö' => 'o', 'Ç' => 'c', 'ç' => 'c', 'Â' => 'a', 'â' => 'a', 'Î' => 'i', 'î' => 'i', 'Û' => 'u', 'û' => 'u'])));
}
function bpImzaGecerli(mixed $v): bool { return is_string($v) && (bool)preg_match('~^data:image/(png|jpeg);base64,[A-Za-z0-9+/=\s]+$~', $v); }
function bpKiralayanKurali(array $t): bool { return bpMetin($t['kiralayan_imza'] ?? '') !== '' || bpMetin($t['kiralayan_imza_metni'] ?? '') !== ''; }

function bpFotoOku(string $rel, array $izinli): ?array {
    if (!in_array($rel, $izinli, true) || str_contains($rel, '..') || str_contains($rel, '\\')) return null;
    $gercek = realpath(UPLOAD_PATH . $rel);
    $kok = realpath(rtrim(UPLOAD_PATH, '/\\'));
    if ($gercek === false || $kok === false || !str_starts_with($gercek, $kok . DIRECTORY_SEPARATOR) || filesize($gercek) > 25 * 1024 * 1024) return null;
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($gercek) ?: '';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return null;
    $yon = 1;
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) $yon = (int)((@exif_read_data($gercek) ?: [])['Orientation'] ?? 1);
    $b = file_get_contents($gercek);
    return $b === false ? null : [$b, $yon];
}

function bpJpeg(string $ham, int $max, int $yon = 1): ?string {
    $b = @getimagesizefromstring($ham);
    if (!$b || $b[0] < 1 || $b[1] < 1 || $b[0] * $b[1] > GORSEL_MAX_PIKSEL) return null;
    if (!function_exists('imagecreatefromstring'))
        return $b[2] === IMAGETYPE_JPEG && in_array($b['channels'] ?? 3, [1, 3], true) && $yon === 1 ? $ham : null;
    if (!gorselBellekYeterli((int)$b[0], (int)$b[1], in_array($yon, [3, 5, 6, 7, 8], true), (int)$b[2])) return null;
    $im = @imagecreatefromstring($ham);
    if (!$im) return null;
    if (!imageistruecolor($im)) imagepalettetotruecolor($im);
    if ($yon > 1 && $yon <= 8) {
        if (in_array($yon, [2, 7], true)) imageflip($im, IMG_FLIP_HORIZONTAL);
        if (in_array($yon, [4, 5], true)) imageflip($im, IMG_FLIP_VERTICAL);
        $aci = [3 => 180, 5 => -90, 6 => -90, 7 => -90, 8 => 90][$yon] ?? 0;
        if ($aci) $im = imagerotate($im, $aci, 0) ?: $im;
    }
    $w = imagesx($im); $h = imagesy($im);
    $o = min(1.0, $max / max($w, $h));
    $nw = max(1, (int)round($w * $o)); $nh = max(1, (int)round($h * $o));
    $hedef = imagecreatetruecolor($nw, $nh);
    imagefill($hedef, 0, 0, imagecolorallocate($hedef, 255, 255, 255));
    imagecopyresampled($hedef, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    ob_start();
    imagejpeg($hedef, null, 80);
    $jpeg = (string)ob_get_clean();
    return $jpeg !== '' ? $jpeg : null;
}

function bpFirmaAyar(PDO $db): array {
    $k = ['firma_unvan', 'firma_adres', 'firma_vergi_dairesi', 'firma_vergi_no', 'firma_mersis', 'firma_telefon', 'firma_eposta', 'firma_web', 'firma_logo'];
    $out = array_fill_keys($k, '');
    try {
        $q = $db->prepare('SELECT anahtar, deger FROM ayarlar WHERE anahtar IN (' . implode(',', array_fill(0, count($k), '?')) . ')');
        $q->execute($k);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['anahtar']] = trim((string)$r['deger']);
    } catch (\Throwable $e) { hataGunlugu('belge_pdf.ayar', $e); }
    return $out;
}

function bpSubeler(PDO $db): array {
    try {
        $s = $db->query("SELECT ad, il, adres, telefon, eposta FROM subeler WHERE aktif=1 AND COALESCE(belgede_goster,1)=1 ORDER BY COALESCE(sira,0), id")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) { return []; }
    $s = array_values(array_filter($s, fn($x) => bpMetin($x['ad'] ?? '') !== ''));
    if (!array_filter($s, fn($x) => bpMetin($x['adres'] ?? '') . bpMetin($x['telefon'] ?? '') . bpMetin($x['eposta'] ?? '') !== '')) return [];
    return array_map(fn($x) => ['baslik' => bpMetin($x['ad']) . (bpMetin($x['il'] ?? '') !== '' && !str_contains(bpTrNorm($x['ad']), bpTrNorm($x['il'])) ? ' · ' . bpMetin($x['il']) : ''),
                                'adres' => bpMetin($x['adres'] ?? ''), 'telefon' => bpMetin($x['telefon'] ?? ''), 'eposta' => bpMetin($x['eposta'] ?? '')], $s);
}

final class BelgePdf {
    private const ANA = '#00236f', ANA_ACIK = '#dce1ff', METIN = '#0d1c2e', SOLUK = '#4a5263', ETIKET = '#1f2f4a', CIZGI = '#8f9ab0', BASLIK_CIZGI = '#8f9ab0',
                  ZEMIN = '#eff4ff', KENAR = '#8f9ab0', SATIR = '#f5f8fa', FOTO_KENAR = '#c5c9d6', KOTU = '#b42318', IYI = '#067647', UYARI = '#7a2020';
    public PdfBelge $p;
    private array $firma;
    private array $subeler;
    private array $izinli;
    private array $onbellek = [];
    private string $baslik = '';
    private ?string $bekleyen = null;
    private string $bekleyenSag = '';
    private bool $bitisik = false;

    public function __construct(PDO $db, array $izinliYollar) {
        $this->p = new PdfBelge();
        $this->p->sol = 11; $this->p->sag = 11; $this->p->ust = 11; $this->p->alt = 17;
        $this->firma = bpFirmaAyar($db);
        $this->subeler = bpSubeler($db);
        $logo = $this->firma['firma_logo'];
        $this->izinli = array_merge($izinliYollar, preg_match('~^firma/logo\.(png|jpe?g|webp)$~i', $logo) ? [$logo] : []);
    }

    public function cikti(string $baslik = ''): string {
        if ($this->p->sayfaSayisi() > 0 && $this->bekleyen !== null) $this->yerAyir(0);
        return $this->p->cikti($baslik !== '' ? $baslik : $this->baslik);
    }


    public function tutanak(array $t): void {
        $s = fn(string $k): string => bpMetin($t[$k] ?? '');
        $surum = (int)($t['belge_surumu'] ?? 0) ?: 1;
        $baslik = $s('asama') === 'saha_alim' ? 'TESLİM ALMA KAYDI' : ($s('islem') === 'iade' ? 'İADE' : 'TESLİM') . ' TUTANAĞI';
        if ($this->baslik === '') $this->baslik = trim(mb_convert_case($baslik, MB_CASE_TITLE, 'UTF-8') . ' ' . $s('belge_no'));
        $this->baslat($baslik, $s('belge_no'), $s('kiralama_kod') . ' · ' . bpTarih($t['tarih'] ?? null), is_array($t['firma_arsiv'] ?? null) ? $t['firma_arsiv'] : [],
                      trim($s('belge_no') . ' · Sürüm ' . $surum, ' ·'));
        $inceleme = $s('inceleme_sonucu');
        $sahaVar = is_array($t['saha_alim'] ?? null) && bpVar($t['saha_alim']['var'] ?? null);

        $this->bolum(trim('Belge sürümü ' . $surum . ' · ' . (BP_ARSIV_TURU[$s('arsiv_turu')] ?? ''), ' ·'), trim($s('belge_no') . ' · Arşiv sürümü ' . $surum, ' ·'));
        $durum = [($inceleme !== '' ? 'Teslim alma anındaki kabul' : 'Kabul') . ': '
            . ($inceleme !== '' && !$sahaVar ? 'Teslim alma kaydı yok' : (BP_KABUL[$s('kabul_sonucu')] ?? 'Eski kayıtta ayrı kabul sonucu bulunmuyor'))];
        if ($inceleme === '') {
            $ft = $t['fiili_teslim'] ?? null;
            $durum[] = 'Fiili teslim / geri alma: ' . ($ft === null ? 'Eski kayıtta ayrıca belirtilmemiş' : (bpVar($ft) ? 'Gerçekleşti' : 'Gerçekleşmedi'));
        }
        $durum[] = $s('kabul_notu');
        $durum[] = $s('cekince_parca');
        $durum[] = trim($s('bulundugu_yer') . ' ' . $s('sonraki_plan'));
        if ($s('arsiv_turu') === 'gecis') $durum[] = 'Bu görüntü geçiş anında arşivlendi; geçmişte hiç değişmediği iddia edilmez.';
        $this->metinKutusu(implode(' · ', array_filter($durum, fn($x) => trim($x) !== '')));

        foreach ($this->kunyeGruplari($t) as [$grup, $ciftler]) { $this->bolum($grup); $this->bilgi($ciftler); }
        if ($inceleme !== '') $this->zincir($t);
        elseif ($s('islem') === 'teslim') $this->hazirlik($t);

        $kiyas = $this->kiyasVeri($t);
        if (!$kiyas || !$kiyas['satirlar']) $this->bilesenHasar($t);
        if ($kiyas) $this->kiyasCiz($kiyas, bpTarih($t['tarih'] ?? null));

        $aks = bpJson($t['aksesuarlar'] ?? null);
        if (is_array($aks) && $aks) { $this->bolum('Teslim Edilen Aksesuarlar'); $this->metinKutusu(implode(', ', array_map('bpMetin', $aks))); }
        $ariza = bpJson($t['ariza_kodlari'] ?? null);
        if (is_array($ariza) && $ariza) {
            $this->bolum('Arıza Kodları');
            $this->metinKutusu(implode("\n", array_map(fn($a) => bpMetin($a['kod'] ?? '') . ' — ' . bpMetin($a['aciklama'] ?? '')
                . (bpMetin($a['kategori'] ?? '') !== '' ? ' (' . bpMetin($a['kategori']) . ')' : ''), array_filter($ariza, 'is_array'))));
        }
        $acilar = $kiyas['acilar'] ?? [];
        $kalan = array_values(array_filter(is_array($t['fotograflar'] ?? null) ? $t['fotograflar'] : [],
            fn($f) => is_array($f) && !in_array(bpMetin($f['aci_tipi'] ?? ''), $acilar, true)));
        if ($kalan) {
            $this->bolum($acilar ? 'Diğer Makine Fotoğrafları' : 'Makine Fotoğrafları');
            $this->fotoIzgara(array_map(fn($f) => ['yol' => (string)($f['dosya_yolu'] ?? ''), 'etiket' => bpMetin($f['aci_tipi'] ?? ''), 'not' => bpMetin($f['aciklama'] ?? '')], $kalan));
        }
        $son = array_values(array_filter([$this->sayimBlok($t['aksesuar_kalemleri'] ?? null),
            bpVar($t['hasar_notu'] ?? null) ? ['baslik' => 'Hasar / Eksik Notu', 'metin' => $s('hasar_notu')] : null,
            bpVar($t['genel_not'] ?? null) ? ['baslik' => 'Genel Notlar', 'metin' => $s('genel_not')] : null,
            bpVar($t['enlem'] ?? null) ? ['baslik' => 'Konum', 'metin' => $s('enlem') . ', ' . $s('boylam')] : null]));
        $beyan = bpVar($t['belge_beyani'] ?? null) ? $s('belge_beyani') : '';
        $kutular = $this->tutanakImzaKutulari($t, $inceleme, $sahaVar);
        foreach ($son as $i => $b) {
            if ($i === count($son) - 1) $this->birlikteTut($this->blokYuk($b) + ($beyan !== '' ? 1.6 + $this->kabulYuk() : 0.0) + $this->imzaYuk($kutular) + 2.4);
            $this->bolum($b['baslik']);
            if (isset($b['tablo'])) $this->tablo(...$b['tablo']); else $this->metinKutusu($b['metin'], $b['pt'] ?? 8.6);
        }
        $this->kabulVeImzalar($beyan !== '', $kutular);
        if ($beyan !== '') { $this->bolum('Belge açıklaması'); $this->metinKutusu($beyan, 7.4); }
    }

    private function birlikteTut(float $h): void {
        $p = $this->p;
        if ($p->y + $h > $p->altSinir() && $h <= $p->altSinir() - $p->ust - 10) { $p->sayfaEkle(); $this->bitisik = false; }
    }

    private function blokYuk(array $b): float {
        $h = 1.8 + $this->barYuk((string)$b['baslik']);
        if (isset($b['tablo'])) return $h + $this->tabloYuk(...$b['tablo']);
        return $h + 1.8 + $this->p->metinYuk((string)$b['metin'], $this->p->icerikEn() - 4, (float)($b['pt'] ?? 8.6), false, 1.32);
    }

    private function kunyeGruplari(array $t): array {
        $s = fn(string $k): string => bpMetin($t[$k] ?? '');
        $m = is_array($t['makine'] ?? null) ? $t['makine'] : [];
        $iade = $s('islem') === 'iade';
        $metraj = '';
        if (bpVar($m['metraj'] ?? null)) {
            $metraj = array_key_exists('olcu_birim', $m) && $m['olcu_birim'] !== null
                ? trim(bpMetin($m['metraj']) . ' ' . bpMetin($m['olcu_birim']))
                : bpMetin($m['metraj']) . (str_contains(bpTrNorm($m['kategori'] ?? ''), 'forklift') ? ' ton' : ' m');
        }
        $musteri = [['Müşteri', $s('musteri_unvan')], ['Telefon', $s('musteri_telefon') ?: '—'],
                    ['Kiralama Dönemi', bpTarih($t['baslangic'] ?? null) . (bpVar($t['bitis'] ?? null) ? ' – ' . bpTarih($t['bitis']) : '')]];
        foreach ([['musteri_temsilci_ad', 'Müşteri Temsilcisi'], ['teslim_eden_firma', 'Teslim Eden Firma'], ['teslim_alan_firma', 'Teslim Alan Firma'],
                  ['calisma_yeri', 'Makinenin Çalışma Yeri']] as [$k, $et]) if (bpVar($t[$k] ?? null)) $musteri[] = [$et, $s($k)];
        if (bpVar($t['toplu_teslim_id'] ?? null)) $musteri[] = [$iade ? 'Toplu Teslim Alma' : 'Toplu Teslim',
            ((int)($t['toplu_adet'] ?? 0) ?: 1) . ' makinelik toplu ' . ($iade ? 'teslim almanın' : 'teslimin') . ' parçası'];
        $makine = [['Ekipman', $s('ekipman_adi')]];
        foreach ([['Makine Kodu', bpMetin($m['kod'] ?? '')], ['Marka / Model', trim(bpMetin($m['marka'] ?? '') . ' ' . bpMetin($m['model'] ?? ''))],
                  ['Seri No', bpMetin($m['seri_no'] ?? '')], ['Kategori', bpMetin($m['kategori'] ?? '')], ['Metraj', $metraj], ['Stok Kodu', bpMetin($m['makine_kodu'] ?? '')]] as $x)
            if ($x[1] !== '') $makine[] = $x;
        $makine[] = ['Genel Durum', BP_GENEL_DURUM[$s('genel_durum')] ?? $s('genel_durum')];
        foreach ([['km', 'Kilometre'], ['calisma_saati', 'Çalışma Saati'], ['yakit_seviyesi', 'Yakıt Durumu']] as [$k, $et])
            if (bpVar($t[$k] ?? null)) $makine[] = [$et, $k === 'yakit_seviyesi' ? $s($k) : bpSayiMetin($t[$k])];
        if (bpVar($t['yakit_turu'] ?? null)) $makine[] = ['Yakıt Türü', BP_YAKIT[$s('yakit_turu')] ?? $s('yakit_turu')];
        $teslim = [['Tutanağı Dolduran', $s('personel_ad') . ($s('personel_pozisyon') !== '' ? ' — ' . $s('personel_pozisyon') : '')]];
        $yeni = bpKiralayanKurali($t);
        $tur = bpVar($t['teslim_eden_tur'] ?? null) && $s('teslim_eden_tur') !== 'personel' ? (BP_TESLIM_EDEN[$s('teslim_eden_tur')] ?? $s('teslim_eden_tur')) : '';
        $teslimEt = $iade ? 'Teslim Alan' : 'Teslim Eden';
        if ($yeni) {
            $teslim[] = ['Kiralamayı Açan', $s('kiralayan_ad') ?: '—'];
            if ($s('inceleme_sonucu') === '') {
                $kisi = $this->imzalayan($t) ?: $s('personel_ad');
                $deger = implode(' · ', array_filter([$kisi, $tur !== '' && !str_contains($kisi, $tur) ? $tur : '']));
                if ($deger !== '') $teslim[] = [$teslimEt, $deger];
            }
        }
        if ($tur !== '' && !($yeni && $s('inceleme_sonucu') === '')) $teslim[] = [$teslimEt, $tur];
        if (bpVar($t['operator_ad'] ?? null)) $teslim[] = ['Operatör', $s('operator_ad') . ($s('operator_iletisim') !== '' ? ' · ' . $s('operator_iletisim') : '')
            . ($yeni && $s('operator_plaka') !== '' ? ' · Araç: ' . $s('operator_plaka') : '')];
        foreach ([['ekspertiz_eden', 'Makineyi Ekspertiz Eden'], ['nakliye_plaka', 'Nakliye Araç Plakası'], ['nakliye_sofor', 'Nakliye (Şoför / Ekip)']] as [$k, $et])
            if (bpVar($t[$k] ?? null)) $teslim[] = [$et, $s($k)];
        if (bpVar($t['is_basi_tarih'] ?? null)) $teslim[] = ['İş Başı', bpTarih($t['is_basi_tarih']) . ($s('is_basi_saat') !== '' ? ' ' . $s('is_basi_saat') : '')];
        if (bpVar($t['is_sonu_tarih'] ?? null)) $teslim[] = ['İş Sonu', bpTarih($t['is_sonu_tarih']) . ($s('is_sonu_saat') !== '' ? ' ' . $s('is_sonu_saat') : '')];
        return [['Müşteri ve Kiralama', $musteri], ['Makine', $makine], [$iade ? 'Teslim Alma Bilgileri' : 'Teslim Bilgileri', $teslim]];
    }

    private function zincir(array $t): void {
        $s = is_array($t['saha_alim'] ?? null) ? $t['saha_alim'] : [];
        $d = is_array($t['depo'] ?? null) ? $t['depo'] : [];
        $sayac = is_array($d['sayac'] ?? null) ? $d['sayac'] : [];
        $deger = fn($x): string => ($x === null || $x === '') ? '—' : bpMetin($x);
        $sayiDeger = fn($x): string => ($x === null || $x === '') ? '—' : bpSayiMetin($x);
        $saat = fn($z): string => bpMetin($z) !== '' ? substr(bpMetin($z), 11, 5) : '';
        $this->bolum('Şantiyede teslim alma');
        if (bpVar($s['var'] ?? null)) {
            $this->paragraf(bpTarih($s['tarih'] ?? null) . (bpMetin($s['zaman'] ?? '') !== '' ? ' ' . $saat($s['zaman']) : '') . ' · Teslim alan: ' . $deger($s['alan'] ?? null)
                . ' · Müşteri yetkilisi: ' . $deger($s['musteri_temsilci_ad'] ?? null) . (bpVar($s['kagit'] ?? null) ? ' · Kâğıt tutanak (fotoğrafı ekte)' : ''));
            if (($s['sayac'] ?? null) !== null && ($s['sayac'] ?? '') !== '') $this->paragraf('Sayaç (sahada): ' . bpSayiMetin($s['sayac']));
            $this->paragraf(bpMetin($s['hasar_notu'] ?? ''));
            $this->paragraf(bpMetin($s['genel_not'] ?? ''));
            if (bpImzaGecerli($s['imza_musteri'] ?? null)) $this->imzaTek((string)$s['imza_musteri'], 'Teslim alma imzası — ' . $deger($s['musteri_temsilci_ad'] ?? null));
            if (is_array($s['fotograflar'] ?? null) && $s['fotograflar'])
                $this->kucukIzgara(array_map(fn($f) => ['yol' => (string)($f['dosya_yolu'] ?? ''), 'etiket' => bpMetin($f['aci_tipi'] ?? '')], array_filter($s['fotograflar'], 'is_array')));
        } else $this->metinKutusu('Teslim alma kaydı yok' . (bpMetin($s['neden'] ?? '') !== '' ? ' (' . bpMetin($s['neden']) . ')' : '') . '.');

        $this->bolum('Dönüş kontrolü');
        if (bpVar($d['yapildi'] ?? null)) {
            $z = bpMetin($d['zaman'] ?? '');
            $this->paragraf('Ekspertiz eden (inceleyen): ' . $deger($d['inceleyen'] ?? null) . ($z !== '' ? ' · ' . bpTarih(substr($z, 0, 10)) . ' ' . $saat($z) : ''));
            $this->tablo(['Sayaç gidiş', 'Dönüş', 'Fark'], [[$sayiDeger($sayac['gidis'] ?? null), $sayiDeger($sayac['donus'] ?? null), $sayiDeger($sayac['fark'] ?? null)]]);
            $this->kontroller($d['kontroller'] ?? null);
            if (is_array($d['aksesuar_farklar'] ?? null) && $d['aksesuar_farklar'])
                $this->tablo(['Aksesuar', 'Verilen', 'Dönen', 'Fark', 'Not'], array_map(fn($a) => [bpMetin($a['ad'] ?? ''), $deger($a['verilen'] ?? null), $deger($a['alinan'] ?? null),
                    $deger($a['fark'] ?? null), bpMetin($a['not'] ?? '')], array_filter($d['aksesuar_farklar'], 'is_array')), [4, 1.6, 1.6, 1.6, 3.2]);
            $this->bilesenKiyas(is_array($d['bilesen_gidis'] ?? null) ? $d['bilesen_gidis'] : null, is_array($d['bilesenler'] ?? null) ? $d['bilesenler'] : null);
            if (is_array($d['bulgular'] ?? null) && $d['bulgular']) $this->bulgular($d['bulgular']);
            else $this->paragraf('Bulgu yok.');
            if (is_array($d['fotolar'] ?? null) && $d['fotolar'])
                $this->tablo(['Açı', 'Gidiş', 'Dönüş'], array_map(fn($f) => [bpMetin($f['aci'] ?? ''), $this->fotoVeyaTire($f['gidis_yol'] ?? null), $this->fotoVeyaTire($f['donus_yol'] ?? null)],
                    array_filter($d['fotolar'], 'is_array')), [2, 3, 3]);
        } else {
            $this->metinKutusu('Dönüş kontrolü yapılmadı: ' . (bpMetin($d['gerekce'] ?? '') ?: (bpMetin($t['inceleme_gerekce'] ?? '') ?: 'gerekçe yazılmadı'))
                . (bpMetin($d['atlayan'] ?? '') !== '' ? "\nAtlayan: " . bpMetin($d['atlayan']) : ''), 8.6, true);
        }
        $this->bolum('Sonuç');
        $this->metinKutusu(BP_SONUC[bpMetin($t['inceleme_sonucu'] ?? '')] ?? bpMetin($t['inceleme_sonucu'] ?? ''), 8.6, true);
    }

    private function hazirlik(array $t): void {
        $h = $t['hazirlik'] ?? null;
        if (!is_array($h)) return;
        $this->bolum('Çıkış kontrolü');
        if (!bpVar($h['var'] ?? null)) {
            $this->metinKutusu('Çıkış kontrolü yapılmadı' . (bpMetin($h['gerekce'] ?? '') !== '' ? ': ' . bpMetin($h['gerekce']) : '')
                . (bpMetin($h['atlayan'] ?? '') !== '' ? ' · Atlayan: ' . bpMetin($h['atlayan']) : ''), 8.6, true);
            return;
        }
        $z = bpMetin($h['zaman'] ?? '');
        $this->paragraf('Makineyi ekspertiz eden: ' . (bpMetin($h['ekspertiz_eden'] ?? '') ?: '—') . ($z !== '' ? ' · ' . bpTarih(substr($z, 0, 10)) . ' ' . substr($z, 11, 5) : '')
            . (bpMetin($h['sablon_ad'] ?? '') !== '' ? ' · ' . bpMetin($h['sablon_ad']) : ''));
        $this->kontroller($h['kontroller'] ?? null);
        if (($h['sayac'] ?? null) !== null && ($h['sayac'] ?? '') !== '') $this->paragraf('Çıkış sayacı: ' . bpSayiMetin($h['sayac']));
        if (is_array($h['aksesuarlar'] ?? null) && $h['aksesuarlar'])
            $this->tablo(['Aksesuar', 'Seri', 'Adet'], array_map(fn($a) => [bpMetin($a['ad'] ?? ''), bpMetin($a['seri'] ?? ''), bpMetin($a['adet'] ?? '')],
                array_filter($h['aksesuarlar'], 'is_array')), [5, 3, 1.5]);
        if (is_array($h['bilesenler'] ?? null) && $h['bilesenler']) {
            $this->bolum('Hasar Durumu (Bileşen Bazlı) — çıkış kontrolü hâli');
            $this->isaretTablosu($h['bilesenler']);
        }
    }

    private function kontroller(mixed $liste): void {
        if (!is_array($liste) || !$liste) return;
        $this->tablo(['Kontrol', 'Sonuç', 'Not'], array_map(fn($k) => [bpMetin($k['ad'] ?? ''), BP_KONTROL[bpMetin($k['sonuc'] ?? '')] ?? bpMetin($k['sonuc'] ?? ''),
            bpMetin($k['not'] ?? '')], array_filter($liste, 'is_array')), [4, 2, 4]);
    }

    private function isaretTablosu(array $veri): void {
        $satir = [];
        foreach (BP_BILESEN as $k => $ad) {
            $d = bpMetin($veri[$k] ?? '');
            if ($d !== '') $satir[] = [$ad, ['tik' => $d === 'hasarsiz'], ['tik' => $d === 'az_hasarli'], ['tik' => $d === 'cok_hasarli']];
        }
        $this->tablo(['Bileşen', 'Hasarsız', 'Az Hasarlı', 'Çok Hasarlı'], $satir, [4, 2, 2, 2], [1, 2, 3]);
    }

    private function bilesenHasar(array $t): void {
        $veri = bpJson($t['bilesen_hasar'] ?? null);
        if (!is_array($veri) || !array_filter(array_keys(BP_BILESEN), fn($k) => bpMetin($veri[$k] ?? '') !== '')) return;
        $this->bolum('Hasar Durumu (Bileşen Bazlı)');
        $this->isaretTablosu($veri);
    }

    private function bilesenKiyas(?array $gidis, ?array $donus): void {
        if ($donus === null) return;
        $sira = ['hasarsiz', 'az_hasarli', 'cok_hasarli'];
        $yer = fn($v): int => ($i = array_search(bpMetin($v), $sira, true)) === false ? -1 : (int)$i;
        $satir = [];
        foreach (BP_BILESEN as $k => $ad) {
            $a = $gidis[$k] ?? null; $z = $donus[$k] ?? null;
            $fark = !bpVar($a) ? 'Gidiş kaydı yok' : ($yer($z) > $yer($a) ? 'KÖTÜLEŞTİ' : ($yer($z) < $yer($a) ? 'İyileşti / onarıldı' : 'Değişmedi'));
            $satir[] = [$ad, BP_BILESEN_DURUM[bpMetin($a)] ?? '—', BP_BILESEN_DURUM[bpMetin($z)] ?? '—', $fark === 'KÖTÜLEŞTİ' ? ['metin' => $fark, 'kalin' => true, 'renk' => self::KOTU] : $fark];
        }
        $this->bolum('Bileşen durumu');
        $this->tablo(['Bileşen', 'Gidiş (çıkış kontrolü)', 'Dönüş (dönüş kontrolü)', 'Değişim'], $satir, [3, 3, 3, 3]);
    }

    private function bulgular(array $bulgular): void {
        $bulgular = array_values(array_filter($bulgular, 'is_array'));
        $bs = (bool)array_filter($bulgular, fn($b) => bpVar($b['bilesen'] ?? null));
        $ad = fn(string $k): string => $k === 'diger' ? 'Diğer' : (BP_BILESEN[$k] ?? '—');
        $satir = array_map(fn($b) => array_merge([BP_BULGU[bpMetin($b['tur'] ?? '')] ?? bpMetin($b['tur'] ?? '')], $bs ? [$ad(bpMetin($b['bilesen'] ?? ''))] : [],
            [bpMetin($b['aciklama'] ?? ''), ['foto' => array_values(array_filter(is_array($b['foto_yollar'] ?? null) ? $b['foto_yollar'] : [], 'is_string'))]]), $bulgular);
        $this->tablo(array_merge(['Bulgu'], $bs ? ['Bileşen'] : [], ['Açıklama', 'Fotoğraf']), $satir, $bs ? [1.6, 2, 4, 4.4] : [1.6, 5, 5.4]);
    }

    private function kiyasVeri(array $t): ?array {
        $k = $t['karsi'] ?? null;
        if (!is_array($k)) return null;
        $tarihK = bpTarih($k['tarih'] ?? null);
        $no = bpMetin($k['belge_no'] ?? '') !== '' ? ' · ' . bpMetin($k['belge_no']) : '';
        $kaynak = bpMetin($k['kaynak'] ?? '') === 'kiralama'
            ? 'Aynı kiralamanın (' . bpMetin($k['kaynak_kod'] ?? '') . ') teslim tutanağı · ' . $tarihK . $no
            : 'Farklı bir kiralamaya (' . bpMetin($k['kaynak_kod'] ?? '') . ') ait önceki tutanak · ' . $tarihK . $no
              . ' — bu kiralamanın teslim tutanağı bulunmadığı için makinenin en son bilinen hâli gösteriliyor.';
        $kullanim = [];
        foreach ([['km', 'Kilometre', 'km'], ['calisma_saati', 'Çalışma Saati', 'saat']] as [$a, $ad, $birim]) {
            $once = bpSayiOku($k[$a] ?? null); $simdi = bpSayiOku($t[$a] ?? null);
            if ($once === null || $simdi === null) continue;
            $kullanim[] = [$ad, bpSayi($once) . ' – ' . bpSayi($simdi) . ' (' . ($simdi >= $once ? '+' : '') . bpSayi($simdi - $once) . ' ' . $birim
                . ($simdi < $once ? ' · İnceleme gerekli' : '') . ')'];
        }
        $onceB = bpJson($k['bilesen_hasar'] ?? null); $onceB = is_array($onceB) ? $onceB : [];
        $simdiB = bpJson($t['bilesen_hasar'] ?? null); $simdiB = is_array($simdiB) ? $simdiB : [];
        $agirlik = ['hasarsiz' => 0, 'az_hasarli' => 1, 'cok_hasarli' => 2];
        $satirlar = []; $kotu = 0;
        if (bpMetin($t['inceleme_sonucu'] ?? '') === '') foreach (BP_BILESEN as $b => $ad) {
            $a = bpMetin($onceB[$b] ?? ''); $z = bpMetin($simdiB[$b] ?? '');
            if ($a === '' && $z === '') continue;
            $deg = '—'; $renk = null;
            if ($a !== '' && $z !== '' && $a !== $z) {
                if (($agirlik[$z] ?? 0) - ($agirlik[$a] ?? 0) > 0) { $deg = 'KÖTÜLEŞTİ'; $renk = self::KOTU; $kotu++; }
                else { $deg = 'İyileşti / onarıldı'; $renk = self::IYI; }
            } elseif ($a === '' && $z !== '' && $z !== 'hasarsiz') $deg = 'Öncesi kayıtsız';
            elseif ($a !== '' && $a === $z) $deg = 'Değişmedi';
            $satirlar[] = [$ad, BP_BILESEN_DURUM[$a] ?? '— kayıt yok —', BP_BILESEN_DURUM[$z] ?? '— kayıt yok —', $renk ? ['metin' => $deg, 'kalin' => true, 'renk' => $renk] : $deg];
        }
        $oncekiAci = [];
        foreach (is_array($k['fotograflar'] ?? null) ? $k['fotograflar'] : [] as $f) {
            $aci = bpMetin($f['aci_tipi'] ?? ''); $yol = (string)($f['dosya_yolu'] ?? '');
            if ($aci !== '' && $yol !== '' && !isset($oncekiAci[$aci])) $oncekiAci[$aci] = $yol;
        }
        $ciftler = []; $eslesmeyen = 0;
        foreach (is_array($t['fotograflar'] ?? null) ? $t['fotograflar'] : [] as $f) {
            $aci = bpMetin($f['aci_tipi'] ?? '');
            if ($aci !== '' && isset($oncekiAci[$aci])) $ciftler[] = ['aci' => $aci, 'once' => $oncekiAci[$aci], 'sonra' => (string)($f['dosya_yolu'] ?? ''), 'not' => bpMetin($f['aciklama'] ?? '')];
            else $eslesmeyen++;
        }
        return ['kaynak' => $kaynak, 'kullanim' => $kullanim, 'satirlar' => $satirlar, 'kotu' => $kotu, 'karsiTarih' => $tarihK, 'ciftler' => $ciftler,
                'eslesmeyen' => $eslesmeyen, 'acilar' => array_values(array_unique(array_column($ciftler, 'aci')))];
    }

    private function kiyasCiz(array $k, string $tarihT): void {
        $this->bolum('Öncesi / Sonrası Karşılaştırması');
        $this->paragraf($k['kaynak']);
        if ($k['kullanim']) $this->bilgi($k['kullanim']);
        if ($k['kotu']) $this->uyari($k['kotu'] . ' bileşende durum kötüleşti.', 'Aşağıdaki değişiklikler bu kiralama süresi içinde oluşmuştur; ek ücret talebinin dayanağıdır.');
        if ($k['satirlar']) $this->tablo(['Bileşen', 'Teslimde (' . $k['karsiTarih'] . ')', 'İadede (' . $tarihT . ')', 'Değişim'], $k['satirlar'], [3, 3, 3, 3]);
        foreach ($k['ciftler'] as $c) $this->fotoCift($c['aci'], $c['once'], $c['sonra'], $c['not']);
        if ($k['eslesmeyen']) $this->paragraf($k['eslesmeyen'] . ' fotoğrafın karşılığı önceki tutanakta bulunmadığı için kıyaslanamadı; aşağıdaki "Diğer Makine Fotoğrafları" bölümünde yer alıyor.',
            8.5, false, self::SOLUK);
    }

    private function sayimBlok(mixed $ham): ?array {
        $r = is_string($ham) ? bpJson($ham) : $ham;
        if (!is_array($r) || array_values($r) !== $r) return ['baslik' => 'Adetli Aksesuar Sayımı', 'metin' => 'Adetli sayım kaydı yok.'];
        $r = array_values(array_filter($r, 'is_array'));
        return $r ? ['baslik' => 'Adetli Aksesuar Sayımı', 'tablo' => [['Aksesuar', 'Seri', 'Adet', 'Durum', 'Not'], array_map(fn($x) => [bpMetin($x['ad'] ?? ''), bpMetin($x['seri'] ?? ''),
            bpMetin($x['adet'] ?? ''), BP_SAYIM_DURUM[bpMetin($x['durum'] ?? '')] ?? bpMetin($x['durum'] ?? ''), bpMetin($x['not'] ?? '')], $r), [4, 2.5, 1.2, 1.8, 3]]] : null;
    }

    private function imzalayan(array $t): string {
        if (bpMetin($t['imza_personel_ad'] ?? '') !== '') return bpMetin($t['imza_personel_ad']);
        $sahadan = bpMetin($t['asama'] ?? '') === 'saha_alim' || in_array(bpMetin($t['kaynak'] ?? ''), ['saha', 'dis_baglanti', 'ofis_kagit'], true);
        return $sahadan ? bpMetin($t['personel_ad'] ?? '') : '';
    }

    private function kiralayanKutusu(array $t): array {
        $s = fn(string $k): string => bpMetin($t[$k] ?? '');
        $resim = bpImzaGecerli($t['kiralayan_imza'] ?? null) && $this->resimVeri((string)$t['kiralayan_imza'], 600) ? (string)$t['kiralayan_imza'] : null;
        if ($resim === null) return ['resim' => null, 'satirlar' => [[$s('kiralayan_imza_metni') ?: BP_EIMZA_METNI, true],
            ['Kiraya Veren Firma — Kiralayan Personel', false], [$s('kiralayan_ad') ?: $s('kiralayan_imza_ad'), true]]];
        return ['resim' => $resim, 'satirlar' => [['Kiraya Veren Firma — ' . ($s('kiralayan_imza_kaynak') === 'yonetici' ? 'Yetkili' : 'Kiralayan Personel'), false],
            [$s('kiralayan_imza_ad'), true], [$s('kiralayan_imza_unvan'), false]]];
    }

    private function tutanakImzaKutulari(array $t, string $inceleme, bool $sahaVar): array {
        $s = fn(string $k): string => bpMetin($t[$k] ?? '');
        $imza = fn(string $k): ?string => bpImzaGecerli($t[$k] ?? null) ? (string)$t[$k] : null;
        $yeni = bpKiralayanKurali($t);
        if ($inceleme !== '') {
            $saha = is_array($t['saha_alim'] ?? null) ? $t['saha_alim'] : [];
            $sahaImza = $sahaVar && bpImzaGecerli($saha['imza_musteri'] ?? null) ? (string)$saha['imza_musteri'] : null;
            return [
                $yeni ? $this->kiralayanKutusu($t)
                      : ['resim' => $imza('imza_personel'), 'satirlar' => [['Kiraya Veren Firma — ' . ($inceleme === 'yapilmadi' ? 'Düzenleyen' : 'Ekspertiz eden'), false], [$s('imza_personel_ad'), true]]],
                ['resim' => $sahaImza, 'satirlar' => [['Kiracı — Yetkili', false], [$s('musteri_temsilci_ad') ?: bpMetin($saha['musteri_temsilci_ad'] ?? ''), true],
                    [$sahaImza !== null ? BP_SAHA_IMZA_NOTU : ($sahaVar ? 'İmza: şantiyedeki teslim alma kaydında (yukarıda)' : 'Teslim alma imzası yok'), false]]],
            ];
        }
        $kim = $this->imzalayan($t);
        $sol = [['Kiraya Veren Firma — ' . ($kim !== '' ? ($s('islem') === 'iade' ? 'Teslim Alan' : 'Teslim Eden') : 'Operatör'), false],
                [$kim !== '' ? $kim : ($s('operator_ad') ?: $s('personel_ad')), true]];
        if ($kim === '' && $s('operator_iletisim') !== '') $sol[] = [$s('operator_iletisim'), false];
        if ($kim === '' && $s('operator_plaka') !== '') $sol[] = ['Araç: ' . $s('operator_plaka'), false];
        $sag = [['Kiracı — Yetkili', false], [$s('musteri_temsilci_ad'), true]];
        if ($s('yetkili_iletisim') !== '') $sag[] = [$s('yetkili_iletisim'), false];
        if ($s('yetkili_plaka') !== '') $sag[] = ['Araç: ' . $s('yetkili_plaka'), false];
        return [$yeni ? $this->kiralayanKutusu($t) : ['resim' => $imza('imza_personel'), 'satirlar' => $sol], ['resim' => $imza('imza_musteri'), 'satirlar' => $sag]];
    }


    public function hasarBelge(array $b): void {
        $tur = bpMetin($b['tur'] ?? '');
        $i = is_array($b['icerik'] ?? null) ? $b['icerik'] : [];
        if ($tur === 'IT') { $this->tutanak($i); return; }
        $ad = BP_HASAR_AD[$tur] ?? $tur;
        $no = bpMetin($b['no'] ?? '');
        $surum = (int)($b['surum'] ?? 0) ?: 1;
        $this->baslat($ad, $no, 'Sürüm ' . $surum . ' · ' . bpTarihSaat($b['created_at'] ?? null, ''), [], $ad . ' ' . $no . ' · Sürüm ' . $surum);
        $this->hasarKunye($i);
        match ($tur) { 'TSR' => $this->tsr($i), 'HBB' => $this->hbb($i), default => $this->dht($i) };
    }

    public function protokol(array $b): void {
        $ic = is_array($b['icerik'] ?? null) ? $b['icerik'] : [];
        $v = is_array($ic['veri'] ?? null) ? $ic['veri'] : [];
        $no = bpMetin($b['belge_no'] ?? '');
        $surum = (int)($b['surum'] ?? 0) ?: 1;
        $imzali = ($b['durum'] ?? '') === 'imzalandi';
        $this->baslik = 'Kiralama protokolü ' . $no;
        $arsiv = [];
        foreach ((is_array($ic['firma'] ?? null) ? $ic['firma'] : []) as $k => $d) if (is_string($k) && is_string($d)) $arsiv['firma_' . $k] = $d;
        $this->baslat('KİRALAMA PROTOKOLÜ', $no, 'Sürüm ' . $surum . (bpMetin($v['siparis_no'] ?? '') !== '' ? ' · Sipariş ' . bpMetin($v['siparis_no']) : ''), $arsiv,
                      'Kiralama protokolü ' . $no . ' · Sürüm ' . $surum);
        if (!$imzali) $this->uyari(bpMetin($b['durum_etiket'] ?? '') ?: 'İmza bekliyor', 'Bu çıktı imzasız sürümdür; müşteri imzaladığında imzalı sürüm kayda geçer.');
        foreach ((array)($b['bilgiler'] ?? []) as $baslik => $ciftler) {
            $this->bolum((string)$baslik);
            $this->bilgi(array_map(fn($c) => [bpMetin($c[0] ?? ''), bpMetin($c[1] ?? '')], (array)$ciftler));
        }
        $satirlar = (array)($b['satirlar'] ?? []);
        if ($satirlar) {
            $hucre = fn(string $ust, string $alt, bool $kalin = false): array => ['parca' => array_values(array_filter([[$ust, 8.3, $kalin, self::METIN], [$alt, 7.3, false, self::SOLUK]], fn($x) => $x[0] !== ''))];
            $this->bolum('Makineler ve Ücretler');
            $this->tablo(['Makine', 'Birim fiyat', 'Süre', 'Tutar'], array_map(fn($s) => [
                ['parca' => array_values(array_filter([[bpMetin($s['ust'] ?? ''), 8.3, true, self::METIN], [bpMetin($s['alt'] ?? ''), 7.3, false, self::SOLUK], [bpMetin($s['ek'] ?? ''), 7.3, false, self::SOLUK]], fn($x) => $x[0] !== ''))],
                $hucre(bpMetin($s['birim'] ?? ''), bpMetin($s['birim_alt'] ?? '')), $hucre(bpMetin($s['sure'] ?? ''), bpMetin($s['sure_alt'] ?? '')),
                bpMetin($s['tutar'] ?? '')], $satirlar), [9, 4.2, 4.4, 3.4], [3]);
        }
        $imza = (string)($b['imza'] ?? '');
        $kv = is_array($b['kv'] ?? null) ? $b['kv'] : [];
        $kvResim = bpImzaGecerli($kv['imza'] ?? null) ? (string)$kv['imza'] : '';
        $kutular = [
            ['resim' => $kvResim, 'satirlar' => [['Kiraya Veren', false], [bpMetin($ic['firma']['unvan'] ?? ''), true],
                [$kvResim !== '' ? implode(' · ', array_filter([bpMetin($kv['ad'] ?? ''), bpMetin($kv['unvan'] ?? '')])) : '', false]]],
            ['resim' => $imzali && bpImzaGecerli($imza) ? $imza : '', 'satirlar' => [['Kiracı (Müşteri)', false], [bpMetin($ic['musteri']['unvan'] ?? ''), true],
                [$imzali ? bpMetin($b['imzalayan'] ?? '') : 'İmza bekliyor', false], [bpMetin($b['imza_bilgi'] ?? ''), false]]],
        ];
        $parcalar = is_array($b['sartlar'] ?? null) ? $b['sartlar']
            : array_map(fn($s) => [trim($s), false], array_values(array_filter(preg_split('/\r?\n/', bpMetin($ic['sartlar'] ?? '')) ?: [], fn($s) => trim($s) !== '')));
        $acik = !empty($v['acik_var']) ? 'Bitiş tarihi belli olmayan (açık uçlu) makinelerin kira bedeli dönem sonunda birim fiyat üzerinden hesaplanır; yukarıdaki toplamlara yalnız nakliyesi dahildir.' : '';
        $imzaH = ($parcalar ? 1.6 + $this->kabulYuk() : 0.0) + 2.4 + $this->imzaYuk($kutular)
            + ($acik !== '' ? 1.4 + $this->p->metinYuk($acik, $this->p->icerikEn() - 4, 8.2, false, 1.32) + 1.8 : 0.0);
        $toplamlar = (array)($v['toplamlar'] ?? []);
        $tek = count($toplamlar) <= 1;
        $sonPb = array_key_last($toplamlar);
        foreach ($toplamlar as $pb => $t) {
            $ek = $tek ? '' : ' (' . $pb . ')';
            $satir = [['Ara Toplam' . $ek, bpPara($t['ara_toplam'] ?? 0, $pb)], ['KDV' . $ek, bpPara($t['kdv'] ?? 0, $pb)], ['GENEL TOPLAM' . $ek, bpPara($t['genel_toplam'] ?? 0, $pb)]];
            if ($pb === $sonPb) $this->birlikteTut(count($satir) * (PdfBelge::satirYuk(9.4, 1.3) + 1.6) + $imzaH);
            $this->protokolToplam($satir);
        }
        if (!$toplamlar) $this->birlikteTut($imzaH);
        if ($acik !== '') $this->metinKutusu($acik, 8.2);
        $this->kabulVeImzalar((bool)$parcalar, $kutular);
        $ozet = 'Belge özeti (SHA-256): ' . bpMetin($b['ozet'] ?? '');
        if ($parcalar) { $this->bolum('Kiralama Şartları'); $this->protokolSartlar($parcalar, 1.0 + $this->p->metinYuk($ozet, $this->p->icerikEn() - 0.8, 7.5, false, 1.32) + 0.4); }
        $this->paragraf($ozet, 7.5, false, self::SOLUK);
    }

    private function protokolToplam(array $satirlar): void {
        $p = $this->p; $en = max(82.0, $p->icerikEn() * 0.54); $x = $p->sol + $p->icerikEn() - $en; $px = 2.0; $py = 0.8;
        $h = PdfBelge::satirYuk(9.4, 1.3) + 2 * $py;
        $this->yerAyir(count($satirlar) * $h, 0);
        $p->y -= 0.2;
        foreach ($satirlar as $i => [$et, $de]) {
            $grand = $i === count($satirlar) - 1;
            $pt = $grand ? 9.4 : 8.6;
            $p->kutu($x, $p->y, $en / 2, $h, $grand ? self::ANA_ACIK : self::ZEMIN, self::KENAR, 0.2);
            $p->kutu($x + $en / 2, $p->y, $en / 2, $h, $grand ? self::ANA_ACIK : '#ffffff', self::KENAR, 0.2);
            $p->metin($x + $px, $p->y + $py, $et, $en / 2 - 2 * $px, $pt, true, $grand ? self::ANA : self::ETIKET, 'L', 1.3);
            $p->metin($x + $en / 2 + $px, $p->y + $py, $de, $en / 2 - 2 * $px, $pt, true, $grand ? self::ANA : self::METIN, 'R', 1.3);
            $p->y += $h;
        }
        $this->bitisik = false;
    }

    private function protokolSatirBol(string $s, float $en, float $pt, bool $kalin): array {
        $out = []; $satir = '';
        foreach (preg_split('/\s+/u', trim($s)) ?: [] as $soz) {
            $aday = $satir === '' ? $soz : $satir . ' ' . $soz;
            if ($satir === '' || $this->p->genislik($aday, $pt, $kalin) <= $en) { $satir = $aday; continue; }
            $out[] = $satir; $satir = $soz;
        }
        if ($satir !== '') $out[] = $satir;
        return $out;
    }

    private function protokolKolon(array $og, int $bas, int $bit, ?float $yuk): array {
        $top = 0.0;
        for ($i = $bas; $i < $bit; $i++) $top += $og[$i]['h'];
        $en = [$bas, PHP_FLOAT_MAX];
        if ($yuk === null) {
            $sol = 0.0;
            for ($k = $bas; $k <= $bit; $k++) {
                $m = max($sol, $top - $sol);
                if ($m < $en[1]) $en = [$k, $m];
                if ($k < $bit) $sol += $og[$k]['h'];
            }
            $k = $en[0];
            if ($k > $bas + 1 && $k < $bit && $og[$k - 1]['bas']) $k--;
            $sol = 0.0;
            for ($i = $bas; $i < $k; $i++) $sol += $og[$i]['h'];
            return [$k, $bit, max($sol, $top - $sol)];
        }
        $k = $bas; $sol = 0.0;
        while ($k < $bit && $sol + $og[$k]['h'] <= $yuk) $sol += $og[$k++]['h'];
        if ($k > $bas + 1 && $k < $bit && $og[$k - 1]['bas']) { $k--; $sol -= $og[$k]['h']; }
        $s = $k; $sag = 0.0;
        while ($s < $bit && $sag + $og[$s]['h'] <= $yuk) $sag += $og[$s++]['h'];
        if ($s > $k + 1 && $s < $bit && $og[$s - 1]['bas']) { $s--; $sag -= $og[$s]['h']; }
        return [$k, $s, max($sol, $sag)];
    }

    private function protokolSartlar(array $parcalar, float $sonra): void {
        $p = $this->p; $en = $p->icerikEn(); $ara = 5.0; $px = 1.6; $py = 0.7;
        $kol = ($en - $ara - 2 * $px) / 2;
        $og = [];
        foreach (array_values($parcalar) as $i => $x) {
            $bas = !empty($x[1]); $pt = $bas ? 7.6 : 7.5;
            $sat = $this->protokolSatirBol(bpMetin($x[0] ?? ''), $kol, $pt, $bas);
            foreach ($sat as $j => $m) {
                $ust = $bas && $j === 0 && $i > 0 ? 1.0 : 0.0;
                $og[] = ['m' => $m, 'pt' => $pt, 'bas' => $bas, 'ust' => $ust, 'h' => PdfBelge::satirYuk($pt, 1.3) + $ust + (!$bas && $j === count($sat) - 1 ? 0.7 : 0.0)];
            }
        }
        if (!$og) return;
        $n = count($og);
        if ($n <= 3) $this->birlikteTut($this->barYuk('Kiralama Şartları') + 1.4 + array_sum(array_column($og, 'h')) + 2 * $py + $sonra);
        $this->yerAyir($og[0]['h'] + 2 * $py, 1.4);
        $i = 0;
        while ($i < $n) {
            $y0 = $p->y; $yuk = $p->altSinir() - $y0 - 2 * $py;
            [$k, $s, $h] = $this->protokolKolon($og, $i, $n, null);
            if ($h <= $yuk) {
                $sigmaz = $y0 + $h + 2 * $py + $sonra > $p->altSinir() && $sonra + 20 <= $p->altSinir() - $p->ust - 10;
                if ($sigmaz) {
                    $bit = $n; $kuyruk = 0.0;
                    while ($bit > $i + 1 && $kuyruk < 8.0) $kuyruk += $og[--$bit]['h'];
                    if ($bit < $n && $og[$bit - 1]['bas'] && $bit > $i + 1) $bit--;
                    [$k, $s, $h] = $this->protokolKolon($og, $i, $bit, null);
                }
            } else [$k, $s, $h] = $this->protokolKolon($og, $i, $n, $yuk);
            if ($s <= $i) { $p->sayfaEkle(); $this->bitisik = false; continue; }
            $p->kutu($p->sol, $y0, $en, $h + 2 * $py, null, self::KENAR, 0.2);
            $orta = $p->sol + $px + $kol + $ara / 2;
            if ($s > $k) $p->cizgi($orta, $y0 + $py, $orta, $y0 + $py + $h, self::FOTO_KENAR, 0.2);
            foreach ([[$i, $k, $p->sol + $px], [$k, $s, $p->sol + $px + $kol + $ara]] as [$a, $z, $x]) {
                $y = $y0 + $py;
                for ($j = $a; $j < $z; $j++) {
                    $o = $og[$j];
                    $p->metin($x, $y + ($j > $a ? $o['ust'] : 0.0), $o['m'], $kol, $o['pt'], $o['bas'], $o['bas'] ? self::ANA : self::METIN, 'L', 1.3);
                    $y += $o['h'] - ($j > $a ? 0.0 : $o['ust']);
                }
            }
            $p->y = $y0 + $h + 2 * $py;
            $this->bitisik = false;
            $i = $s;
            if ($i < $n) { $p->sayfaEkle(); }
        }
    }

    public function servisBedel(array $b): void {
        $al = fn(string $k): array => is_array($b[$k] ?? null) ? $b[$k] : [];
        $v = fn(array $a, string $k): string => bpMetin($a[$k] ?? '');
        [$sv, $mu, $mk, $kr, $t] = [$al('servis'), $al('musteri'), $al('makine'), $al('kiralama'), $al('toplam')];
        $no = bpMetin($b['no'] ?? '');
        $pb = bpMetin($b['para_birimi'] ?? '') ?: 'TL';
        $ucretsiz = !empty($b['ucretsiz']);
        $tarih = fn(string $k): string => bpVar($sv[$k] ?? null) ? bpTarih($sv[$k]) : '';
        $this->baslik = 'Servis bedeli dökümü ' . $no;
        $this->baslat('SERVİS BEDELİ DÖKÜMÜ', $no, trim(bpMetin($b['servis_no'] ?? '') . ' · ' . $tarih('tarih'), ' ·'), [], 'Servis bedeli dökümü ' . $no);
        $this->bolum('Müşteri');
        $this->bilgi([['Müşteri', $v($mu, 'unvan')], ['Vergi Dairesi / No', implode(' / ', array_filter([$v($mu, 'vergi_dairesi'), $v($mu, 'vergi_no')]))], ['Adres', $v($mu, 'adres')]]);
        $this->bolum('Makine');
        $arac = $v($mk, 'kod_etiket') === 'Plaka';
        [$ust, $alt] = $this->makineKimlik(['kod' => $v($mk, 'kod'), 'marka' => $v($mk, 'marka_model'), 'ad' => $v($mk, 'ad'), 'kategori' => $v($mk, 'kategori'),
            'seri_no' => $arac ? '' : $v($mk, 'seri_no'), 'metraj' => $v($mk, 'metraj'), 'olcu_birim' => '', 'makine_kodu' => $v($mk, 'stok_kodu')]);
        $this->kimlikSatiri('Makine', $ust, $alt);
        $this->bilgi([[$v($mk, 'kod_etiket') ?: 'Makine Kodu', $v($mk, 'kod')], ['Marka / Model', $v($mk, 'marka_model')], ['Kategori', $v($mk, 'kategori')],
                      [$v($mk, 'seri_etiket') ?: 'Seri No', $v($mk, 'seri_no')], ['Metraj', $v($mk, 'metraj')], ['Stok Kodu', $v($mk, 'stok_kodu')], ['Sahibi', $v($mk, 'sahip')]]);
        $this->bolum($kr ? 'Servis ve kiralama' : 'Servis');
        $this->bilgi([['Servis No', bpMetin($b['servis_no'] ?? '')], ['Teknik Servis Formu', $v($sv, 'formlar')], ['Tip', $v($sv, 'tip')], ['Servis Tarihi', $tarih('tarih')], ['Tamamlanma', $tarih('tamamlanma')],
                ['Teknisyen', $v($sv, 'teknisyen')], ['Servis Yeri', $v($sv, 'yer')], ['Servis Adresi', $v($sv, 'adres')], ['Şantiye', $v($sv, 'santiye')],
                ['Kiralama', $v($kr, 'kod')], ['Sipariş No', $v($kr, 'siparis_no')], ['Şantiye / Lokasyon', $v($kr, 'santiye')], ['Kira Dönemi', $v($kr, 'donem')],
                ['Fatura No', $v($sv, 'fatura_no')]]);
        if ($v($sv, 'ariza') !== '' || $v($sv, 'yapilan') !== '') { $this->bolum('Arıza ve yapılan işlem'); $this->bilgi([['Arıza / Talep', $v($sv, 'ariza')], ['Yapılan İşlem', $v($sv, 'yapilan')]]); }
        $this->bolum('Servis bedeli');
        $satirlar = array_values(array_filter(is_array($b['satirlar'] ?? null) ? $b['satirlar'] : [], 'is_array'));
        $miktar = fn(array $x): string => bpSayi((float)($x['miktar'] ?? 0));
        if ($ucretsiz) {
            $this->metinKutusu((bpMetin($b['not'] ?? '') ?: 'Ücretsiz') . ': bu servis için size yansıyan bir bedel yoktur.', 8.6, true, self::IYI);
            $this->tablo(['Açıklama', 'Miktar', 'Birim', 'Bedel'], array_map(fn($x) => [bpMetin($x['aciklama'] ?? ''), $miktar($x), bpMetin($x['birim'] ?? ''), 'Ücretsiz'], $satirlar), [6, 1.4, 1.6, 2], [1, 3]);
        } else {
            $this->tablo(['Açıklama', 'Miktar', 'Birim', 'Birim Fiyat', 'KDV', 'Tutar (KDV hariç)'], array_map(fn($x) => [bpMetin($x['aciklama'] ?? ''), $miktar($x), bpMetin($x['birim'] ?? ''),
                bpPara($x['birim_fiyat'] ?? 0, $pb), '%' . bpSayi((float)($x['kdv_oran'] ?? 0)), bpPara($x['tutar'] ?? 0, $pb)], $satirlar), [4.6, 1.2, 1.3, 2, 1, 2.2], [1, 3, 4, 5]);
        }
        $ozet = [['Ara toplam (KDV hariç)', bpPara($t['ara'] ?? 0, $pb)]];
        if ((float)($t['iskonto'] ?? 0) > 0) {
            $isk = $al('iskonto');
            $ozet[] = ['İndirim' . (bpMetin($isk['tur'] ?? '') === 'oran' ? ' (%' . bpSayi((float)($isk['deger'] ?? 0)) . ')' : ''), '− ' . bpPara($t['iskonto'], $pb)];
            $ozet[] = ['İndirimli tutar (KDV hariç)', bpPara($t['matrah'] ?? 0, $pb)];
        }
        $dokum = array_values(array_filter(is_array($t['kdv_dokum'] ?? null) ? $t['kdv_dokum'] : [], 'is_array'));
        $ozet[] = [match (true) {
            count($dokum) === 1 => 'KDV (%' . bpSayi((float)($dokum[0]['oran'] ?? 0)) . ')',
            count($dokum) > 1 => 'KDV — ' . implode(' · ', array_map(fn($d) => '%' . bpSayi((float)($d['oran'] ?? 0)) . ': ' . bpPara($d['kdv'] ?? 0, $pb)
                . ' (matrah ' . bpPara($d['matrah'] ?? 0, $pb) . ')', $dokum)),
            default => 'KDV',
        }, bpPara($t['kdv'] ?? 0, $pb)];
        $ozet[] = ['Genel toplam (KDV dahil)', bpPara($t['genel'] ?? 0, $pb), true];
        $this->toplamBlok($ozet);
        $iletisim = implode(' / ', array_filter([$this->firma['firma_telefon'], $this->firma['firma_eposta']]));
        $this->metinKutusu('Tutarlar ' . $pb . ' cinsindendir. ' . ($ucretsiz ? 'Ücretsiz serviste fatura düzenlenmez.' : 'Bu döküm servis bedelinin bilgisidir; resmî fatura ayrıca düzenlenir.')
            . ($iletisim !== '' ? ' Sorularınız için: ' . $iletisim . '.' : ''), 8.2);
    }

    private function hasarKunye(array $i): void {
        $k = is_array($i['kiralama'] ?? null) ? $i['kiralama'] : [];
        $m = is_array($i['makine'] ?? null) ? $i['makine'] : [];
        $bos = fn($v): string => bpMetin($v) === '' ? '—' : bpMetin($v);
        $this->bolum('Müşteri ve Kiralama');
        $this->bilgi([['Müşteri', $bos($k['musteri'] ?? null)], ['Kiralama', $bos($k['kod'] ?? null) . (bpMetin($k['sozlesme_no'] ?? '') !== '' ? ' / ' . bpMetin($k['sozlesme_no']) : '')],
                      ['Gidiş', bpTarihSaat($k['baslangic'] ?? null)], ['Teslim alma', bpTarihSaat($k['gercek_bitis'] ?? null)]]);
        $this->bolum('Makine');
        [$ust, $alt] = $this->makineKimlik($m);
        $this->kimlikSatiri('Makine', $ust, $alt);
    }

    private function tsr(array $i): void {
        $sv = is_array($i['servis'] ?? null) ? $i['servis'] : [];
        $bos = fn($v): string => bpMetin($v) === '' ? '—' : bpMetin($v);
        $this->bolum('Servis');
        $this->bilgi([['Bağlı tutanak', $bos(($i['iade_no'] ?? null) ?: ($i['dht_no'] ?? null))], ['Teknisyen', $bos($sv['teknisyen'] ?? null)],
                      ['Tamamlanma', bpTarihSaat($sv['tamamlanma'] ?? null)]]);
        $imzaT = bpImzaGecerli($sv['teknisyen_imza'] ?? null) ? (string)$sv['teknisyen_imza'] : null;
        $kutu = [['resim' => $imzaT, 'satirlar' => [['Teknisyen imzası', false], [$bos($sv['teknisyen'] ?? null), true],
            [$imzaT !== null ? bpTarihSaat($sv['tamamlanma'] ?? null) : 'Ad soyad · Tarih · İmza', false]]]];
        $imzaH = $this->imzaYuk($kutu) + 2.4;
        $tamir = array_values(array_filter(is_array($i['tamir_fotolari'] ?? null) ? $i['tamir_fotolari'] : [], 'is_string'));
        $this->bolum('Yapılan işler');
        $this->tablo(['Tür', 'Açıklama', 'Miktar / süre'], array_map(fn($k) => [($k['tur'] ?? '') === 'iscilik' ? 'İşçilik' : 'Malzeme', bpMetin($k['aciklama'] ?? ''),
            ($k['tur'] ?? '') === 'iscilik' ? bpSayi((float)($k['saat'] ?? 0)) . ' saat × ' . bpSayi((float)($k['kisi'] ?? 0) ?: 1.0) . ' kişi'
                : bpSayi((float)($k['miktar'] ?? 0)) . ' ' . (bpMetin($k['birim'] ?? '') ?: 'adet')], array_values(array_filter(is_array($i['kalemler'] ?? null) ? $i['kalemler'] : [], 'is_array'))),
            [1.6, 5, 2.4], [], $tamir ? 0.0 : $imzaH);
        if ($tamir) {
            $this->bolum('Tamir sonrası');
            $this->fotoIzgara(array_map(fn($y, $n) => ['yol' => $y, 'etiket' => 'Tamir sonrası ' . ($n + 1)], $tamir, array_keys($tamir)), $imzaH);
        }
        $this->imzalar($kutu);
    }

    private function hbb(array $i): void {
        $pb = $i['para_birimi'] ?? null;
        $bos = fn($v): string => bpMetin($v) === '' ? '—' : bpMetin($v);
        if (bpVar($i['iptal'] ?? null)) $this->uyari('Bu bildirim iptal edilmiştir.', '');
        $yontem = bpMetin($i['yontem'] ?? '') === 'hakedis' ? 'Hakedişe eklendi (' . bpMetin($i['hakedis_kod'] ?? '') . ')' : (BP_YONTEM[bpMetin($i['yontem'] ?? '')] ?? '—');
        $bagli = (bpMetin(($i['iade_no'] ?? null) ?: ($i['dht_no'] ?? null)) ?: '—') . (bpMetin($i['tsr_no'] ?? '') !== '' ? ', ' . bpMetin($i['tsr_no']) : '');
        $bilgi = [['Bağlı belgeler', $bagli], ['Faturalama', $yontem]];
        $ak = is_array($i['ariza_karar'] ?? null) ? $i['ariza_karar'] : null;
        if ($ak) {
            $bilgi[] = ['Karar', $bos($ak['karar'] ?? null) . (bpMetin($ak['sebep'] ?? '') !== '' ? ' — ' . bpMetin($ak['sebep']) : '')];
            $bilgi[] = ['Kusur sahibi', $bos($ak['kusur'] ?? null) . (bpMetin($ak['kusur_sebep'] ?? '') !== '' ? ' — ' . bpMetin($ak['kusur_sebep']) : '')];
        }
        $this->bolum('Bildirim');
        $this->bilgi($bilgi);
        $satir = array_map(fn($k) => [bpMetin($k['aciklama'] ?? ''), bpPara($k['net'] ?? ($k['tutar'] ?? 0), $pb), bpMetin($k['kdv_oran'] ?? '') === '' ? '—' : bpMetin($k['kdv_oran']),
            bpPara($k['kdv'] ?? 0, $pb)], array_values(array_filter(is_array($i['kalemler'] ?? null) ? $i['kalemler'] : [], 'is_array')));
        $toplam = [['Ara toplam', bpPara($i['ara'] ?? 0, $pb)], ['KDV', bpPara($i['kdv'] ?? 0, $pb)], ['Genel toplam', bpPara($i['genel'] ?? 0, $pb), true]];
        $this->bolum('Hasar bedeli');
        $this->tablo(['Kalem', 'Tutar', 'KDV %', 'KDV'], $satir, [5, 2.2, 1.2, 2.2], [1, 2, 3], $this->toplamYuk($toplam));
        $this->toplamBlok($toplam);
        $od = is_array($i['odeme'] ?? null) ? $i['odeme'] : [];
        if (bpMetin($od['iban'] ?? '') !== '' || bpMetin($od['banka'] ?? '') !== '') { $this->bolum('Ödeme'); $this->bilgi([['Banka', $bos($od['banka'] ?? null)], ['IBAN', $bos($od['iban'] ?? null)]]); }
        $it = is_array($i['itiraz'] ?? null) ? $i['itiraz'] : [];
        $this->metinKutusu('Bu bildirime itirazınızı ' . (implode(' / ', array_filter([bpMetin($it['telefon'] ?? ''), bpMetin($it['eposta'] ?? '')])) ?: 'firmamıza')
            . " üzerinden iletebilirsiniz. Döviz tutarlar fatura günü TCMB satış kuruyla TL'ye çevrilir.", 8.2);
    }

    private function dht(array $i): void {
        $s = is_array($i['sayac'] ?? null) ? $i['sayac'] : [];
        $ta = is_array($i['teslim_alma'] ?? null) ? $i['teslim_alma'] : null;
        $im = is_array($i['imza'] ?? null) ? $i['imza'] : [];
        $bos = fn($v): string => ($v === null || $v === '') ? '—' : bpMetin($v);
        $resim = preg_match('~^data:image/png;base64,[A-Za-z0-9+/=]+$~', (string)($im['veri'] ?? '')) ? (string)$im['veri'] : null;
        $kutu = [['resim' => $resim, 'satirlar' => [['İnceleyen', false], [$bos(($im['ad'] ?? null) ?: ($i['inceleyen'] ?? null)), true],
            [bpTarihSaat(($im['zaman'] ?? null) ?: ($i['zaman'] ?? null)), false]]]];
        $imzaH = $this->imzaYuk($kutu) + 2.4;
        if ($ta) {
            $this->bolum('Sahada teslim alma');
            $this->bilgi([['Tutanak', $bos($ta['belge_no'] ?? null)], ['Tarih', bpTarihSaat($ta['tarih'] ?? null)], ['Teslim alan', $bos($ta['alan'] ?? null)],
                          ['Müşteri temsilcisi', $bos($ta['musteri_temsilci'] ?? null) . (bpVar($ta['musteri_imza_var'] ?? null) ? ' (müşteri imzalı)' : '')]]);
            $this->metinKutusu(bpMetin($ta['not'] ?? ''));
        }
        $this->bolum('Sayaç');
        $this->tablo(['Gidiş', 'Dönüş', 'Fark'], [[$bos($s['gidis'] ?? null), $bos($s['donus'] ?? null), $bos($s['fark'] ?? null)]]);
        if (is_array($i['aksesuar_farklar'] ?? null) && $i['aksesuar_farklar']) {
            $this->bolum('Aksesuar farkları');
            $this->tablo(['Aksesuar', 'Verilen', 'Alınan', 'Fark', 'Not'], array_map(fn($a) => [bpMetin($a['ad'] ?? ''), $bos($a['verilen'] ?? null), $bos($a['alinan'] ?? null),
                $bos($a['fark'] ?? null), bpMetin($a['not'] ?? '')], array_values(array_filter($i['aksesuar_farklar'], 'is_array'))), [4, 1.6, 1.6, 1.6, 3.2]);
        }
        $n = 0; $satir = []; $bulguFoto = [];
        foreach (array_filter(is_array($i['bulgular'] ?? null) ? $i['bulgular'] : [], 'is_array') as $b) {
            $tur = BP_BULGU[bpMetin($b['tur'] ?? '')] ?? bpMetin($b['tur'] ?? '');
            $nolar = [];
            foreach (array_filter(is_array($b['foto_yollar'] ?? null) ? $b['foto_yollar'] : [], fn($y) => is_string($y) && $y !== '') as $y) {
                $nolar[] = ++$n;
                $bulguFoto[] = ['yol' => $y, 'etiket' => 'Foto ' . $n . ' · ' . $tur, 'not' => bpMetin($b['aciklama'] ?? '')];
            }
            $satir[] = [$tur, bpMetin($b['aciklama'] ?? ''), $nolar ? 'Foto ' . implode(', ', $nolar) : '—'];
        }
        $cift = array_values(array_filter(is_array($i['fotolar'] ?? null) ? $i['fotolar'] : [], 'is_array'));
        if ($satir) {
            $this->bolum('Bulgular');
            $this->tablo(['Tür', 'Açıklama', 'Fotoğraf'], $satir, [1.6, 6.4, 2], [], $bulguFoto || $cift ? 0.0 : $imzaH);
        } else {
            $yok = 'Bulgu yok — makine sorunsuz teslim alındı.';
            if (!$cift) $this->birlikteTut($this->blokYuk(['baslik' => 'Bulgular', 'metin' => $yok]) + $imzaH);
            $this->bolum('Bulgular');
            $this->metinKutusu($yok);
        }
        if ($bulguFoto) { $this->bolum('Bulgu fotoğrafları'); $this->fotoIzgara($bulguFoto, $cift ? 0.0 : $imzaH); }
        if ($cift) {
            $this->bolum('Gidiş ↔ dönüş fotoğrafları');
            foreach ($cift as $j => $f) $this->fotoCift(bpMetin($f['aci'] ?? '') . ' · ' . bpTarihSaat($f['tarih'] ?? null), (string)($f['gidis_yol'] ?? ''), (string)($f['donus_yol'] ?? ''), '',
                'Gidiş', 'Dönüş', $j === count($cift) - 1 ? $imzaH : 0.0);
        }
        $this->imzalar($kutu);
    }


    private function baslat(string $baslik, string $no, string $altSatir, array $arsiv, string $altEk): void {
        $f = $this->firma;
        $this->p->altbilgi = [implode(' · ', array_filter([$f['firma_unvan'], $f['firma_telefon'], $f['firma_web']])),
                              trim($altEk . ' · Çıktı: ' . date('d.m.Y H:i'), ' ·'), $this->subeler];
        $unvan = bpMetin($arsiv['firma_unvan'] ?? '') ?: $f['firma_unvan'];
        $this->bekleyen = null;
        $this->p->devamBasligi = null;
        $this->p->sayfaEkle();
        $this->p->devamBasligi = function (PdfBelge $p) use ($baslik, $no, $unvan): void {
            $en = $p->icerikEn();
            $p->metin($p->sol, $p->y, $unvan, $en * 0.5, 7.2, true, self::ANA);
            $p->metin($p->sol + $en * 0.5, $p->y, trim($baslik . ' · ' . $no, ' ·'), $en * 0.5, 7.2, false, self::SOLUK, 'R');
            $p->y += 3.6;
            $p->cizgi($p->sol, $p->y, $p->sol + $en, $p->y, self::ANA, 0.3);
            $p->y += 2.6;
        };
        $this->antet($baslik, $no, $altSatir, $arsiv);
    }

    private function antet(string $baslik, string $no, string $altSatir, array $arsiv): void {
        $p = $this->p;
        $f = $this->firma;
        foreach ($arsiv as $k => $v) if (is_string($k) && bpMetin($v) !== '') $f[$k] = bpMetin($v);
        $en = $p->icerikEn(); $x = $p->sol; $y0 = $p->y;
        $sagEn = $en * 0.5; $solEn = $en - $sagEn - 6;
        $mx = $x; $logoAlt = $y0;
        if ($logo = $this->resimYol((string)($f['firma_logo'] ?? ''), 600)) {
            $a = $logo['en'] / $logo['boy'];
            [$w, $h] = $a > 34 / 12 ? [34.0, 34 / $a] : [12 * $a, 12.0];
            $p->resim($logo, $x, $y0, $w, $h);
            $mx = $x + $w + 3.5; $logoAlt = $y0 + $h;
        }
        $mEn = max(20.0, $solEn - ($mx - $x));
        $y = $y0;
        if ($f['firma_unvan'] !== '') $y += $p->metin($mx, $y, $f['firma_unvan'], $mEn, 8.8, true, self::ANA, 'L', 1.25) + 0.3;
        $kimlik = implode('  ·  ', array_filter([
            $f['firma_vergi_dairesi'] !== '' || $f['firma_vergi_no'] !== '' ? 'V.D. ' . implode(' / ', array_filter([$f['firma_vergi_dairesi'], $f['firma_vergi_no']])) : '',
            $f['firma_mersis'] !== '' ? 'Mersis: ' . $f['firma_mersis'] : '']));
        $iletisim = implode('  ·  ', array_filter([$f['firma_telefon'], $f['firma_eposta'], $f['firma_web']]));
        foreach ([$f['firma_adres'], $kimlik, $iletisim] as $satir) if ($satir !== '') $y += $p->metin($mx, $y, $satir, $mEn, 6.8, false, self::SOLUK, 'L', 1.3);
        $sx = $x + $en - $sagEn; $sy = $y0;
        $sy += $p->metin($sx, $sy, $baslik, $sagEn, 14, true, self::ANA, 'R', 1.15) + 0.6;
        if ($no !== '') $sy += $p->metin($sx, $sy, $no, $sagEn, 9.5, true, self::ANA, 'R', 1.3);
        if (trim($altSatir, ' ·') !== '') $sy += $p->metin($sx, $sy, $altSatir, $sagEn, 7.8, false, self::SOLUK, 'R', 1.3);
        $p->y = max($y, $sy, $logoAlt) + 1.6;
        $p->cizgi($x, $p->y, $x + $en, $p->y, self::ANA, 0.42);
        $p->y += 2.4;
        $this->bitisik = false;
    }

    private function bolum(string $baslik, string $sag = ''): void {
        if ($this->bekleyen !== null) $this->yerAyir(0);
        $this->bekleyen = $baslik;
        $this->bekleyenSag = $sag;
    }

    private function barYuk(string $baslik): float { return $this->p->metinYuk($baslik, $this->p->icerikEn() - 4, 7.6, true, 1.25) + 1.8; }

    private function yerAyir(float $h, float $bosluk = 1.4): void {
        $p = $this->p;
        if ($this->bekleyen === null) {
            if (!$this->bitisik) $p->y += $bosluk;
            $p->yer($h);
            $this->bitisik = false;
            return;
        }
        $b = $this->bekleyen; $sag = $this->bekleyenSag; $this->bekleyen = null; $this->bekleyenSag = '';
        $bh = $this->barYuk($b);
        $p->y += 1.4;
        $p->yer($bh + $h);
        $p->kutu($p->sol, $p->y, $p->icerikEn(), $bh, self::ANA_ACIK, self::KENAR, 0.2);
        $p->metin($p->sol + 2, $p->y + 0.9, $b, $p->icerikEn() - 4, 7.6, true, self::ANA, 'L', 1.25);
        if ($sag !== '') $p->metin($p->sol + 2, $p->y + 0.9, $sag, $p->icerikEn() - 4, 7.3, false, self::SOLUK, 'R', 1.25);
        $p->y += $bh;
        $this->bitisik = true;
    }

    private function paragraf(string $s, float $pt = 8.6, bool $kalin = false, string $renk = self::METIN): void {
        if (trim($s) === '') return;
        $h = PdfBelge::satirYuk($pt, 1.32);
        $ust = $this->bitisik || $this->bekleyen !== null ? 1.0 : 0.4;
        $this->yerAyir($h + $ust, 0);
        $this->p->y += $ust;
        $this->p->akisMetin($s, $pt, $kalin, $renk, $this->p->sol + 0.4, $this->p->icerikEn() - 0.8, 1.32);
        $this->p->y += 0.4;
    }

    private function metinKutusu(string $s, float $pt = 8.6, bool $kalin = false, string $renk = self::METIN): void {
        if (trim($s) === '') return;
        $p = $this->p; $en = $p->icerikEn(); $px = 2.0; $py = 0.9;
        $h = $p->metinYuk($s, $en - 2 * $px, $pt, $kalin, 1.32) + 2 * $py;
        if ($h > $p->altSinir() - $p->ust - 24) { $this->paragraf($s, $pt, $kalin, $renk); return; }
        $this->yerAyir($h, 1.4);
        $p->kutu($p->sol, $p->y, $en, $h, null, self::KENAR, 0.2);
        $p->metin($p->sol + $px, $p->y + $py, $s, $en - 2 * $px, $pt, $kalin, $renk, 'L', 1.32);
        $p->y += $h;
        $this->bitisik = false;
    }

    private function bilgi(array $ciftler): void {
        $c = array_values(array_filter($ciftler, fn($x) => bpMetin($x[1] ?? '') !== ''));
        if (!$c) return;
        $p = $this->p; $en = $p->icerikEn(); $px = 1.8; $py = 0.6; $yarim = $en / 2;
        $etEn = min(40.0, max(26.0, max(array_map(fn($x) => $p->genislik((string)$x[0], 7.3, true), $c)) + 2 * $px + 0.6));
        $etH = fn(string $m, float $w): float => $p->metinYuk($m, $w - 2 * $px, 7.3, true, 1.2);
        $deH = fn(string $m, float $w): float => $p->metinYuk($m, $w - 2 * $px, 8.6, false, 1.3);
        $tekSatir = PdfBelge::satirYuk(8.6, 1.3) * 1.01;
        $satirlar = []; $bekleyen = null;
        foreach ($c as [$et, $de]) {
            $de = bpMetin($de);
            $gen = str_contains($de, "\n") || $deH($de, $yarim - $etEn) > $tekSatir;
            if ($gen) {
                if ($bekleyen) { $satirlar[] = [$bekleyen]; $bekleyen = null; }
                $satirlar[] = [[(string)$et, $de]];
            } elseif ($bekleyen) { $satirlar[] = [$bekleyen, [(string)$et, $de]]; $bekleyen = null; }
            else $bekleyen = [(string)$et, $de];
        }
        if ($bekleyen) $satirlar[] = [$bekleyen];
        foreach ($satirlar as $i => $s) {
            $hw = count($s) === 1 ? $en : $yarim;
            $h = 0.0;
            foreach ($s as [$et, $de]) $h = max($h, $etH($et, $etEn), $deH($de, $hw - $etEn));
            $h += 2 * $py;
            if ($i === 0) $this->yerAyir($h, 1.4);
            elseif ($p->yer($h)) $this->bitisik = false;
            foreach ($s as $j => [$et, $de]) {
                $x = $p->sol + $j * $yarim;
                $p->kutu($x, $p->y, $etEn, $h, self::ZEMIN, self::KENAR, 0.2);
                $p->kutu($x + $etEn, $p->y, $hw - $etEn, $h, '#ffffff', self::KENAR, 0.2);
                $p->metin($x + $px, $p->y + $py + 0.25, $et, $etEn - 2 * $px, 7.3, true, self::ETIKET, 'L', 1.2);
                $p->metin($x + $etEn + $px, $p->y + $py, $de, $hw - $etEn - 2 * $px, 8.6, false, self::METIN, 'L', 1.3);
            }
            $p->y += $h;
        }
        $this->bitisik = false;
    }

    private function tabloOlc(?array $kolonlar, array $satirlar, array $oran): array {
        $p = $this->p; $en = $p->icerikEn(); $px = 1.6; $py = 0.7; $pt = 8.3;
        $n = count($kolonlar ?? reset($satirlar));
        $oran = $oran ?: array_fill(0, $n, 1);
        $w = array_map(fn($o) => $en * $o / array_sum($oran), $oran);
        $baslikH = 0.0;
        foreach ($kolonlar ?? [] as $i => $k) $baslikH = max($baslikH, $p->metinYuk($k, $w[$i] - 2 * $px, 7.3, true, 1.25) + 2 * $py);
        $yuk = [];
        foreach ($satirlar as $r => $hucreler) {
            $h = 0.0;
            foreach (array_values($hucreler) as $i => $c) $h = max($h, $this->hucreYuk($c, $w[$i] - 2 * $px, $pt));
            $yuk[$r] = $h + 2 * $py;
        }
        return [$w, $baslikH, $yuk];
    }

    private function tabloYuk(?array $kolonlar, array $satirlar, array $oran = [], array $sag = []): float {
        if (!$satirlar) return 0.0;
        [, $baslikH, $yuk] = $this->tabloOlc($kolonlar, $satirlar, $oran);
        return 1.4 + $baslikH + array_sum($yuk);
    }

    private function tablo(?array $kolonlar, array $satirlar, array $oran = [], array $sag = [], float $sonEk = 0.0): void {
        if (!$satirlar) return;
        $p = $this->p;
        $px = 1.6; $py = 0.7; $pt = 8.3;
        $hiza = fn(int $i): string => in_array($i, $sag, true) ? 'R' : 'L';
        [$w, $baslikH, $yuk] = $this->tabloOlc($kolonlar, $satirlar, $oran);
        $baslik = function () use ($p, $kolonlar, $w, $px, $py, $baslikH, $hiza): void {
            if ($kolonlar === null) return;
            $x = $p->sol;
            foreach ($kolonlar as $i => $k) {
                $p->kutu($x, $p->y, $w[$i], $baslikH, self::ANA_ACIK, self::KENAR, 0.2);
                $p->metin($x + $px, $p->y + $py, $k, $w[$i] - 2 * $px, 7.3, true, self::ANA, $hiza($i), 1.25);
                $x += $w[$i];
            }
            $p->y += $baslikH;
        };
        $son = array_key_last($satirlar);
        $this->yerAyir($baslikH + reset($yuk) + (count($satirlar) === 1 ? $sonEk : 0.0), 1.4);
        $baslik();
        foreach ($satirlar as $r => $hucreler) {
            if ($p->yer($yuk[$r] + ($r === $son ? $sonEk : 0.0))) $baslik();
            $x = $p->sol;
            foreach (array_values($hucreler) as $i => $c) {
                $p->kutu($x, $p->y, $w[$i], $yuk[$r], is_array($c) && isset($c['zemin']) ? (string)$c['zemin'] : null, self::KENAR, 0.2);
                $this->hucreCiz($c, $x + $px, $p->y + $py, $w[$i] - 2 * $px, $pt, $hiza($i));
                $x += $w[$i];
            }
            $p->y += $yuk[$r];
        }
        $this->bitisik = false;
    }

    private function hucreYuk(mixed $c, float $en, float $pt): float {
        if (is_array($c) && isset($c['parca'])) return array_sum(array_map(fn($x) => $this->p->metinYuk((string)$x[0], $en, (float)$x[1], (bool)$x[2], 1.3), $c['parca'])) ?: PdfBelge::satirYuk($pt);
        if (is_array($c) && isset($c['foto'])) return max(PdfBelge::satirYuk($pt), $this->fotoHucre($c['foto'], 0, 0, $en, false));
        if (is_array($c) && array_key_exists('tik', $c)) return PdfBelge::satirYuk($pt);
        $m = is_array($c) ? bpMetin($c['metin'] ?? '') : bpMetin($c);
        return $m === '' ? PdfBelge::satirYuk($pt) : $this->p->metinYuk($m, $en, $pt, is_array($c) && !empty($c['kalin']), 1.3);
    }

    private function hucreCiz(mixed $c, float $x, float $y, float $en, float $pt, string $hiza): void {
        if (is_array($c) && isset($c['parca'])) { foreach ($c['parca'] as $s) $y += $this->p->metin($x, $y, (string)$s[0], $en, (float)$s[1], (bool)$s[2], (string)$s[3], $hiza, 1.3); return; }
        if (is_array($c) && isset($c['foto'])) { $this->fotoHucre($c['foto'], $x, $y, $en, true); return; }
        if (is_array($c) && array_key_exists('tik', $c)) { if ($c['tik']) $this->p->tik($hiza === 'R' ? $x + $en - 3.4 : $x, $y, $pt + 1); return; }
        $m = is_array($c) ? bpMetin($c['metin'] ?? '') : bpMetin($c);
        if ($m !== '') $this->p->metin($x, $y, $m, $en, $pt, is_array($c) && !empty($c['kalin']), is_array($c) ? (string)($c['renk'] ?? self::METIN) : self::METIN, $hiza, 1.3);
    }

    private function fotoVeyaTire(mixed $yol): mixed { return is_string($yol) && $yol !== '' ? ['foto' => [$yol]] : '—'; }

    private function fotoHucre(array $yollar, float $x, float $y, float $en, bool $ciz): float {
        $resimler = array_values(array_filter(array_map(fn($yol) => is_string($yol) && $yol !== '' ? $this->resimYol($yol, 500) : null, $yollar)));
        if (!$resimler) {
            if ($ciz && $yollar) $this->p->metin($x, $y, '(fotoğraf eklenemedi)', $en, 7.5, false, self::SOLUK);
            return $yollar ? PdfBelge::satirYuk(7.5) : 0.0;
        }
        $fw = min(48.0, $en); $fh = $fw * 0.75; $ara = 1.6;
        $sutun = max(1, (int)floor(($en + $ara) / ($fw + $ara)));
        if ($ciz) foreach ($resimler as $i => $r) {
            $cx = $x + ($i % $sutun) * ($fw + $ara); $cy = $y + intdiv($i, $sutun) * ($fh + $ara);
            $this->p->kutu($cx, $cy, $fw, $fh, '#f2f4f8', self::FOTO_KENAR, 0.2);
            $this->p->resim($r, $cx + 0.3, $cy + 0.3, $fw - 0.6, $fh - 0.6);
        }
        $satir = (int)ceil(count($resimler) / $sutun);
        return $satir * $fh + ($satir - 1) * $ara;
    }

    private function fotoIzgara(array $fotolar, float $sonEk = 0.0): void {
        $p = $this->p; $en = $p->icerikEn(); $ara = 2.0; $sutun = 3; $kol = ($en - ($sutun - 1) * $ara) / $sutun; $fh = 40.0;
        $parca = array_chunk($fotolar, $sutun); $son = count($parca) - 1;
        foreach ($parca as $i => $sira) {
            $alt = 0.0;
            foreach ($sira as $f) $alt = max($alt, $this->altYazi($f, $kol - 1, false));
            $h = $fh + $alt; $ek = $i === $son ? $sonEk : 0.0;
            if ($i === 0) { $bit = $this->bekleyen !== null || $this->bitisik; $this->yerAyir($h + $ek + ($bit ? $ara : 0), 1.4); if ($bit) $p->y += $ara; }
            else { $p->y += $ara; $p->yer($h + $ek); }
            foreach ($sira as $j => $f) {
                $x = $p->sol + $j * ($kol + $ara);
                $p->kutu($x, $p->y, $kol, $h, '#ffffff', self::FOTO_KENAR, 0.2);
                $p->kutu($x, $p->y, $kol, $fh, '#f2f4f8', null);
                if ($r = $this->resimYol((string)($f['yol'] ?? ''), 900)) $p->resim($r, $x + 0.3, $p->y + 0.3, $kol - 0.6, $fh - 0.6);
                else $p->metin($x, $p->y + $fh / 2 - 2, '(fotoğraf eklenemedi)', $kol, 7.5, false, self::SOLUK, 'C');
                $this->altYazi($f, $kol - 1, true, $x + 0.5, $p->y + $fh + 0.5);
            }
            $p->y += $h;
        }
        $this->bitisik = false;
    }

    private function fotoCift(string $aci, string $once, string $sonra, string $not, string $solEt = 'Teslimde', string $sagEt = 'İadede', float $sonEk = 0.0): void {
        $p = $this->p; $en = $p->icerikEn(); $ara = 1.6; $kol = ($en - $ara) / 2; $fh = 40;
        $alt = max($this->altYazi(['etiket' => $solEt], $kol, false), $this->altYazi(['etiket' => $sagEt, 'not' => $not], $kol, false));
        $bas = PdfBelge::satirYuk(8.4, 1.3);
        $this->yerAyir($bas + $fh + $alt + 1.5 + $sonEk, 1.6);
        $p->metin($p->sol, $p->y, $aci, $en, 8.4, true, self::METIN, 'L', 1.3);
        $p->y += $bas + 0.4;
        foreach ([[$once, $solEt, ''], [$sonra, $sagEt, $not]] as $j => [$yol, $et, $nt]) {
            $x = $p->sol + $j * ($kol + $ara);
            $p->kutu($x, $p->y, $kol, $fh, '#f2f4f8', self::FOTO_KENAR, 0.2);
            if ($r = $this->resimYol($yol, 1000)) $p->resim($r, $x + 0.4, $p->y + 0.4, $kol - 0.8, $fh - 0.8);
            elseif ($yol === '') $p->metin($x, $p->y + $fh / 2 - 2, '—', $kol, 9, false, self::SOLUK, 'C');
            $this->altYazi(['etiket' => $et, 'not' => $nt], $kol, true, $x, $p->y + $fh + 0.6);
        }
        $p->y += $fh + $alt + 0.6;
        $this->bitisik = false;
    }

    private function altYazi(array $f, float $en, bool $ciz, float $x = 0, float $y = 0): float {
        $h = 0.0;
        foreach ([[bpMetin($f['etiket'] ?? ''), 7, 'C', self::METIN], [bpMetin($f['not'] ?? ''), 6.8, 'L', self::SOLUK]] as [$m, $pt, $hiza, $renk]) {
            if ($m === '') continue;
            $h += $ciz ? $this->p->metin($x, $y + $h, $m, $en, $pt, false, $renk, $hiza, 1.25) : $this->p->metinYuk($m, $en, $pt, false, 1.25);
        }
        return $h + 1;
    }

    private function kucukIzgara(array $fotolar): void {
        $p = $this->p; $en = $p->icerikEn(); $ara = 2.0; $sutun = 3; $fw = ($en - ($sutun - 1) * $ara) / $sutun; $fh = 40.0;
        foreach (array_chunk(array_values($fotolar), $sutun) as $i => $sira) {
            $alt = 0.0;
            foreach ($sira as $f) if (bpMetin($f['etiket'] ?? '') !== '') $alt = max($alt, $p->metinYuk($f['etiket'], $fw, 7, false, 1.25) + 0.6);
            if ($i === 0) { $bit = $this->bekleyen !== null || $this->bitisik; $this->yerAyir($fh + $alt + ($bit ? $ara : 0), 1.4); if ($bit) $p->y += $ara; }
            else { $p->y += $ara; $p->yer($fh + $alt); }
            foreach ($sira as $j => $f) {
                $x = $p->sol + $j * ($fw + $ara);
                $p->kutu($x, $p->y, $fw, $fh, '#f2f4f8', self::FOTO_KENAR, 0.2);
                if ($r = $this->resimYol((string)($f['yol'] ?? ''), 500)) $p->resim($r, $x + 0.3, $p->y + 0.3, $fw - 0.6, $fh - 0.6);
                if (bpMetin($f['etiket'] ?? '') !== '') $p->metin($x, $p->y + $fh + 0.6, $f['etiket'], $fw, 7, false, self::SOLUK, 'C', 1.25);
            }
            $p->y += $fh + $alt;
        }
        $this->bitisik = false;
    }

    private function imzaYuk(array $kutular): float {
        $p = $this->p; $kol = $p->icerikEn() / 2; $px = 2.0;
        $resimYeri = array_filter($kutular, fn($k) => $k['resim']) ? 14.0 : 0.0;
        $yuk = 22.0;
        foreach ($kutular as $k) {
            $h = 3.6 + max($resimYeri, 12.0);
            foreach ($k['satirlar'] as [$m, $kalin]) if (bpMetin($m) !== '') $h += $p->metinYuk($m, $kol - 2 * $px, 8, $kalin, 1.3);
            $yuk = max($yuk, $h);
        }
        return $yuk;
    }

    private function imzalar(array $kutular): void {
        $p = $this->p; $en = $p->icerikEn(); $kol = $en / 2; $px = 2.0;
        $yuk = $this->imzaYuk($kutular);
        $this->yerAyir($yuk, 2.4);
        foreach (array_values($kutular) as $j => $k) {
            $x = $p->sol + $j * $kol; $y = $p->y;
            $p->kutu($x, $y, $kol, $yuk, null, self::KENAR, 0.2);
            $metinH = 0.0;
            foreach ($k['satirlar'] as [$m, $kalin]) if (bpMetin($m) !== '') $metinH += $p->metinYuk($m, $kol - 2 * $px, 8, $kalin, 1.3);
            $ty = $y + $yuk - 1.6 - $metinH;
            $p->cizgi($x + $px, $ty - 1, $x + $kol - $px, $ty - 1, self::FOTO_KENAR, 0.2);
            if ($k['resim'] && ($r = $this->resimVeri($k['resim'], 600))) {
                $rh = min(13.0, max(6.0, $ty - 2.5 - ($y + 1.5)));
                $p->resim($r, $x + ($kol - 52) / 2, $ty - 1.8 - $rh, 52, $rh);
            }
            foreach ($k['satirlar'] as [$m, $kalin]) if (bpMetin($m) !== '') $ty += $p->metin($x + $px, $ty, $m, $kol - 2 * $px, 8, $kalin, self::METIN, 'C', 1.3);
        }
        $p->y += $yuk;
        $this->bitisik = false;
    }

    private function kabulYuk(): float { return $this->p->metinYuk(BELGE_KABUL_CUMLESI, $this->p->icerikEn() - 0.8, 8, true, 1.32) + 0.4; }

    private function kabulVeImzalar(bool $kabul, array $kutular): void {
        if ($kabul) {
            $p = $this->p;
            $h = $this->kabulYuk();
            $this->birlikteTut(1.6 + $h + 2.4 + $this->imzaYuk($kutular));
            $this->yerAyir($h, 1.6);
            $p->metin($p->sol + 0.4, $p->y, BELGE_KABUL_CUMLESI, $p->icerikEn() - 0.8, 8, true, self::ANA, 'L', 1.32);
            $p->y += $h;
            $this->bitisik = false;
        }
        $this->imzalar($kutular);
    }

    private function imzaTek(string $veri, string $yazi): void {
        $p = $this->p;
        $this->yerAyir(17 + ($yazi !== '' ? PdfBelge::satirYuk(8.2) : 0), 1.4);
        if ($r = $this->resimVeri($veri, 600)) {
            $a = $r['en'] / $r['boy'];
            [$w, $h] = $a > 45 / 16 ? [45.0, 45 / $a] : [16 * $a, 16.0];
            $p->resim($r, $p->sol, $p->y, $w, $h);
        }
        $p->y += 16.5;
        if ($yazi !== '') { $p->metin($p->sol, $p->y, $yazi, $p->icerikEn(), 8.2, false, self::SOLUK); $p->y += PdfBelge::satirYuk(8.2); }
        $this->bitisik = false;
    }

    private function uyari(string $kalin, string $metin): void {
        $p = $this->p; $en = $p->icerikEn(); $pad = 2.0;
        $h = $p->metinYuk($kalin, $en - 2 * $pad, 8.6, true, 1.32) + ($metin !== '' ? $p->metinYuk($metin, $en - 2 * $pad, 8.6, false, 1.32) : 0) + 2 * 1.2;
        $this->yerAyir($h, 1.6);
        $p->kutu($p->sol, $p->y, $en, $h, '#fef3f2', '#f3b8b2', 0.25);
        $y = $p->y + 1.2;
        $y += $p->metin($p->sol + $pad, $y, $kalin, $en - 2 * $pad, 8.6, true, self::UYARI, 'L', 1.32);
        if ($metin !== '') $p->metin($p->sol + $pad, $y, $metin, $en - 2 * $pad, 8.6, false, self::UYARI, 'L', 1.32);
        $p->y += $h;
        $this->bitisik = false;
    }

    private function resimYol(string $yol, int $max): ?array {
        $a = $yol . '|' . $max;
        if (array_key_exists($a, $this->onbellek)) return $this->onbellek[$a];
        $r = null;
        if ($yol !== '' && ($ham = bpFotoOku($yol, $this->izinli)) && ($j = bpJpeg($ham[0], $max, $ham[1]))) $r = $this->p->resimKaydet($j);
        return $this->onbellek[$a] = $r;
    }

    private function resimVeri(string $veri, int $max): ?array {
        if (!preg_match('~^data:image/(png|jpeg);base64,([A-Za-z0-9+/=\s]+)$~', $veri, $m)) return null;
        $a = sha1($veri) . '|' . $max;
        if (array_key_exists($a, $this->onbellek)) return $this->onbellek[$a];
        $ham = base64_decode((string)preg_replace('/\s+/', '', $m[2]), true);
        return $this->onbellek[$a] = ($ham !== false && ($j = bpJpeg($ham, $max)) ? $this->p->resimKaydet($j) : null);
    }

    private function makineKimlik(array $m): array {
        $marka = implode(' ', array_filter([bpMetin($m['marka'] ?? ''), bpMetin($m['model'] ?? '')], fn($x) => $x !== ''));
        $ust = implode(' · ', array_filter([bpMetin($m['kod'] ?? ''), $marka], fn($x) => $x !== ''));
        $ad = bpMetin($m['ad'] ?? '');
        $sn = bpMetin($m['seri_no'] ?? '');
        $metraj = '';
        if (bpMetin($m['metraj'] ?? '') !== '') $metraj = array_key_exists('olcu_birim', $m) && $m['olcu_birim'] !== null
            ? trim(bpMetin($m['metraj']) . ' ' . bpMetin($m['olcu_birim'])) : bpMetin($m['metraj']) . (str_contains(bpTrNorm($m['kategori'] ?? ''), 'forklift') ? ' ton' : ' m');
        $alt = array_filter([$ust !== '' && $ad !== $ust ? $ad : '', bpMetin($m['kategori'] ?? ''), $sn !== '' ? (preg_match('~^sn~i', $sn) ? $sn : 'SN ' . $sn) : '', $metraj,
            bpMetin($m['makine_kodu'] ?? '') !== '' ? 'Stok kodu ' . bpMetin($m['makine_kodu']) : ''], fn($x) => $x !== '');
        return [$ust !== '' ? $ust : $ad, implode(' · ', $alt)];
    }

    private function kimlikSatiri(string $etiket, string $ust, string $alt): void {
        $p = $this->p; $en = $p->icerikEn(); $px = 1.8; $py = 0.6;
        if ($ust === '' && $alt === '') $ust = '—';
        $etEn = min(40.0, max(26.0, $p->genislik($etiket, 7.3, true) + 2 * $px + 0.6));
        $w = $en - $etEn - 2 * $px;
        $ustH = $ust !== '' ? $p->metinYuk($ust, $w, 8.6, true, 1.3) : 0.0;
        $h = $ustH + ($alt !== '' ? $p->metinYuk($alt, $w, 7.3, false, 1.3) + 0.2 : 0.0) + 2 * $py;
        $this->yerAyir($h, 1.4);
        $p->kutu($p->sol, $p->y, $etEn, $h, self::ZEMIN, self::KENAR, 0.2);
        $p->kutu($p->sol + $etEn, $p->y, $en - $etEn, $h, '#ffffff', self::KENAR, 0.2);
        $p->metin($p->sol + $px, $p->y + $py + 0.25, $etiket, $etEn - 2 * $px, 7.3, true, self::ETIKET, 'L', 1.2);
        if ($ust !== '') $p->metin($p->sol + $etEn + $px, $p->y + $py, $ust, $w, 8.6, true, self::METIN, 'L', 1.3);
        if ($alt !== '') $p->metin($p->sol + $etEn + $px, $p->y + $py + $ustH + 0.2, $alt, $w, 7.3, false, self::SOLUK, 'L', 1.3);
        $p->y += $h;
        $this->bitisik = false;
    }

    private function toplamOlc(array $satirlar): array {
        $p = $this->p; $en = $p->icerikEn(); $px = 2.0; $py = 0.8;
        $lab = max(array_map(fn($s) => $p->genislik((string)$s[0], !empty($s[2]) ? 9.4 : 8.6, true), $satirlar ?: [['']])) + 2 * $px + 1;
        $val = max(array_map(fn($s) => $p->genislik((string)$s[1], !empty($s[2]) ? 9.4 : 8.6, true), $satirlar ?: [['', '']])) + 2 * $px + 1;
        $w = min($en, max(82.0, $en * 0.54, $lab + $val)); $etW = max($w * 0.58, min($lab, $w - $val));
        $hs = array_map(fn($s) => max($p->metinYuk((string)$s[0], $etW - 2 * $px, !empty($s[2]) ? 9.4 : 8.6, true, 1.3),
            $p->metinYuk((string)$s[1], $w - $etW - 2 * $px, !empty($s[2]) ? 9.4 : 8.6, true, 1.3)) + 2 * $py, $satirlar);
        return [$w, $etW, $px, $py, $hs];
    }

    private function toplamYuk(array $satirlar): float { return array_sum($this->toplamOlc($satirlar)[4]); }

    private function toplamBlok(array $satirlar): void {
        $p = $this->p;
        [$w, $etW, $px, $py, $hs] = $this->toplamOlc($satirlar);
        $this->yerAyir(array_sum($hs), 0);
        $x = $p->sol + $p->icerikEn() - $w;
        foreach (array_values($satirlar) as $i => $s) {
            $g = !empty($s[2]); $pt = $g ? 9.4 : 8.6;
            $p->kutu($x, $p->y, $etW, $hs[$i], $g ? self::ANA_ACIK : self::ZEMIN, self::KENAR, 0.2);
            $p->kutu($x + $etW, $p->y, $w - $etW, $hs[$i], $g ? self::ANA_ACIK : '#ffffff', self::KENAR, 0.2);
            $p->metin($x + $px, $p->y + $py, (string)$s[0], $etW - 2 * $px, $pt, true, $g ? self::ANA : self::ETIKET, 'L', 1.3);
            $p->metin($x + $etW + $px, $p->y + $py, (string)$s[1], $w - $etW - 2 * $px, $pt, true, $g ? self::ANA : (string)($s[3] ?? self::METIN), 'R', 1.3);
            $p->y += $hs[$i];
        }
        $this->bitisik = false;
    }

    public function servisFormu(array $r): void {
        $al = fn(string $k): array => is_array($r[$k] ?? null) ? $r[$k] : [];
        $v = fn(array $a, string $k): string => bpMetin($a[$k] ?? '');
        [$mu, $mk, $cs, $is, $yol] = [$al('musteri'), $al('makine'), $al('calisma'), $al('iscilik'), $al('yol')];
        $no = bpMetin($r['rapor_no'] ?? '');
        $saat = fn($t): string => bpVar($t) ? substr(bpMetin($t), 11, 5) : '';
        $sayi = fn($x): string => $x === null || $x === '' ? '' : bpSayi((float)$x);
        $eh = fn(bool $b): string => $b ? 'Evet' : 'Hayır';
        $this->baslik = 'Teknik servis formu ' . $no;
        $this->baslat('TEKNİK SERVİS FORMU', $no, 'Tarih: ' . bpTarih($r['tarih'] ?? null), [], 'Teknik servis formu ' . $no);
        $this->bolum('Müşteri ve servis');
        $this->bilgi([['Sıra No', $no], ['Tarih', bpTarih($r['tarih'] ?? null)], ['Müşteri', $v($mu, 'unvan')], ['Servis adresi', $v($mu, 'adres')],
                      ['Servis gerekçesi', bpMetin($r['gerekce_etiket'] ?? '')], ['Teknisyen', bpMetin($r['teknisyen_ad'] ?? '')],
                      ['İşi kabul', bpTarihSaat($r['kabul'] ?? null, '')], ['Yola çıkış', bpTarihSaat($r['yola_cikis'] ?? null, '')],
                      ['Varış', bpTarihSaat($r['varis'] ?? null, '')], ['Ayrılış', bpTarihSaat($r['ayrilis'] ?? null, '')]]);
        $this->bolum('Makine bilgileri');
        [$ust, $alt] = $this->makineKimlik(['kod' => $v($mk, 'kod'), 'marka' => $v($mk, 'marka'), 'model' => $v($mk, 'model'), 'ad' => $v($mk, 'ad'), 'kategori' => $v($mk, 'kategori'),
            'seri_no' => $v($mk, 'seri_no'), 'metraj' => $v($mk, 'metraj'), 'olcu_birim' => $mk['olcu_birim'] ?? null, 'makine_kodu' => $v($mk, 'makine_kodu')]);
        $this->kimlikSatiri('Makine', $ust !== '' ? $ust : 'Makine bilgisi yok', $alt);
        $calisma = implode(' · ', array_filter(['Elektrik ' . $sayi($cs['elektrik'] ?? null), 'Dizel ' . $sayi($cs['dizel'] ?? null), 'Toplam ' . $sayi($cs['toplam'] ?? null)],
            fn($x) => !preg_match('/ $/', $x)));
        $this->bilgi([['Marka', $v($mk, 'marka')], ['Model', $v($mk, 'model')], ['Seri No', $v($mk, 'seri_no')], ['Üretim yılı', $v($mk, 'yil')],
                      ['Makine kodu', $v($mk, 'kod') ?: $v($mk, 'makine_kodu')], ['Çalışma saati', $calisma],
                      ['Arıza sebebi', bpMetin($r['ariza_kaynak_etiket'] ?? '')], ['Sahibi', $v($mk, 'sahip') === 'musteri' ? 'Müşterinin makinesi' : '']]);
        $this->bolum('Makinenin arızalı yerlerinin ekspertizi');
        $bil = array_values(array_filter(is_array($r['bilesenler'] ?? null) ? $r['bilesenler'] : [], 'is_array'));
        if ($bil) $this->tablo(['Arızalı / hasarlı yer', 'Durum'], array_map(fn($b) => [bpMetin($b['ad'] ?? ''), ['metin' => bpMetin($b['durum_ad'] ?? ''), 'kalin' => ($b['durum'] ?? '') === 'cok_hasarli',
            'renk' => ($b['durum'] ?? '') === 'cok_hasarli' ? '#b42318' : self::METIN]], $bil), [5, 3]);
        else $this->metinKutusu('Arızalı yer işaretlenmedi.', 8.2);
        $this->bolum('Müşteri talebi / şikâyet');
        $this->metinKutusu(bpMetin($r['sikayet'] ?? '') ?: '—');
        if (bpMetin($r['tespit'] ?? '') !== '') { $this->bolum('Tespit'); $this->metinKutusu(bpMetin($r['tespit'])); }
        $this->bolum('Kullanılan yedek parçalar');
        $par = array_values(array_filter(is_array($r['parcalar'] ?? null) ? $r['parcalar'] : [], 'is_array'));
        if ($par) $this->tablo(['Yedek parça kodu', 'Yedek parça adı', 'Miktarı'], array_map(fn($x) => [bpMetin($x['kod'] ?? '') ?: '—', bpMetin($x['ad'] ?? ''),
            trim(bpMetin($x['miktar'] ?? '') . ' ' . bpMetin($x['birim'] ?? ''))], $par), [2.4, 6, 1.8], [2]);
        else $this->metinKutusu('Yedek parça kullanılmadı.', 8.2);
        $this->bolum('Yapılan işlemler');
        $this->metinKutusu(bpMetin($r['yapilan'] ?? '') ?: '—');
        $this->bolum('İş durumu');
        $this->bilgi([['İş tamamlandı mı?', $eh(!empty($r['is_tamam']))], ['Teklif isteniyor', $eh(!empty($r['teklif_isteniyor']))],
                      ['Sonuç', bpMetin($r['sonuc_etiket'] ?? '')], ['Not', bpMetin($r['sonuc_not'] ?? '')]]);
        $dk = (int)($is['sure_dk'] ?? 0);
        $this->bolum('İşçilik bilgileri');
        $this->bilgi([['İşe başlangıç saati', $v($is, 'baslangic')], ['İşin bitiş saati', $v($is, 'bitis')],
                      ['Toplam işçilik süresi', ($is['sure_dk'] ?? null) !== null ? intdiv($dk, 60) . ' sa ' . ($dk % 60) . ' dk' : '']]);
        $this->bolum('Yol bilgileri');
        $this->bilgi([['Çıkış km', $sayi($yol['cikis_km'] ?? null)], ['Dönüş km', $sayi($yol['donus_km'] ?? null)], ['Toplam km', $sayi($yol['toplam_km'] ?? null)],
                      ['Araç plaka no', $v($yol, 'plaka')]]);
        $fotolar = array_values(array_filter(is_array($r['foto_pdf'] ?? null) ? $r['foto_pdf'] : [], 'is_array'));
        $kutu = [['resim' => is_string($r['imza_musteri_veri'] ?? null) ? $r['imza_musteri_veri'] : null,
                  'satirlar' => [['Müşteri yetkilisi', false], [bpMetin($r['musteri_ad'] ?? '') ?: '—', true], [bpMetin($r['musteri_tel'] ?? '') !== '' ? 'Cep: ' . bpMetin($r['musteri_tel']) : 'Ad soyad · İmza', false]]],
                 ['resim' => is_string($r['imza_teknisyen_veri'] ?? null) ? $r['imza_teknisyen_veri'] : null,
                  'satirlar' => [['Servis yetkilisi', false], [bpMetin($r['servis_yetkili_ad'] ?? '') ?: '—', true], ['Ad soyad · İmza', false]]]];
        if ($fotolar) { $this->bolum('Fotoğraflar'); $this->fotoIzgara($fotolar, $this->imzaYuk($kutu) + 2.4); }
        $this->imzalar($kutu);
    }

    public function teknikServis(array $d): void { $this->blokBelge($d); }

    public function satinalma(array $d): void { $this->blokBelge($d); }

    private function blokBelge(array $d): void {
        $this->baslik = bpMetin($d['pdf_baslik'] ?? '');
        $this->baslat(bpMetin($d['baslik'] ?? ''), bpMetin($d['no'] ?? ''), bpMetin($d['alt'] ?? ''), [], bpMetin($d['alt_ek'] ?? ''));
        foreach (array_values(array_filter((array)($d['bloklar'] ?? []), 'is_array')) as $b) {
            if (isset($b['bolum'])) $this->bolum(bpMetin($b['bolum']));
            if (isset($b['kimlik'])) {
                [$ust, $alt] = $this->makineKimlik((array)$b['kimlik']);
                if ($ust === '' && $alt === '') $ust = bpMetin($b['kimlik_bos'] ?? '');
                $this->kimlikSatiri('Makine', $ust, $alt);
            }
            if (isset($b['bilgi'])) $this->bilgi(array_map(fn($c) => [bpMetin($c[0] ?? ''), bpMetin($c[1] ?? '')], (array)$b['bilgi']));
            if (isset($b['metin'])) $this->metinKutusu(bpMetin($b['metin']), (float)($b['pt'] ?? 8.6));
            if (isset($b['tablo'])) {
                $t = (array)$b['tablo'];
                $toplam = isset($b['toplam']) ? (array)$b['toplam'] : [];
                $this->tablo($t[0] ?? null, (array)($t[1] ?? []), (array)($t[2] ?? []), (array)($t[3] ?? []), $toplam ? $this->toplamYuk($toplam) : 0.0);
                if ($toplam) $this->toplamBlok($toplam);
            }
            if (isset($b['foto'])) $this->fotoIzgara(array_values(array_filter((array)$b['foto'], 'is_array')));
        }
    }
}
