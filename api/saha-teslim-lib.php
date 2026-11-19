<?php
declare(strict_types=1);
require_once __DIR__ . '/tutanak-yaz.php';
require_once __DIR__ . '/referans-lib.php';

const ST_BAGLANTI_IP_SINIRI = 20;
const ST_TOPLU_EN_FAZLA = 20;

function stSiraHatasi(PDO $db, array $k, string $islem): ?string {
    $kod = (string)($k['kod'] ?: '#' . $k['id']);
    if (!opYeniZincir($db, $k)) return $kod . ': eski düzendeki kiralama; teslim/iade tutanağı ekranını kullanın.';
    $adim = $islem === 'teslim' ? 'saha_teslim' : 'saha_teslim_alma';
    if ($eng = opTekKayitEngeli($db, $k, $adim)) return $kod . ': ' . $eng;
    $sira = opSiradakiAdim($db, $k);
    $uygun = $islem === 'teslim' ? ['saha_teslim'] : ['toplaniyor', 'saha_teslim_alma'];
    if (in_array($sira, $uygun, true)) return null;
    return $kod . ': ' . ($islem === 'teslim' ? 'sahada teslim' : 'sahada teslim alma') . ' sırası değil (sıradaki: '
         . ($sira ? OP_ADIMLAR[$sira] : 'yok') . ').';
}

function _stMakine(PDO $db, array $k, string $islem): array {
    $arac = ($k['makine_tur'] ?? '') === 'arac';
    $s = $db->prepare('SELECT ' . ($arac ? 'plaka' : 'kod') . ' AS ad, marka, model FROM ' . ($arac ? 'araclar' : 'manliftler') . ' WHERE id=?');
    $s->execute([(int)$k['makine_id']]);
    $m = $s->fetch(PDO::FETCH_ASSOC) ?: ['ad' => '', 'marka' => '', 'model' => ''];
    return ['kiralama_id' => (int)$k['id'], 'kod' => (string)($k['kod'] ?? ''), 'ekipman_adi' => (string)($k['ekipman_adi'] ?? ''),
            'makine_tur' => (string)$k['makine_tur'], 'makine_ad' => (string)$m['ad'], 'marka' => (string)($m['marka'] ?? ''),
            'model' => (string)($m['model'] ?? ''), 'musteri_id' => (int)$k['musteri_id'], 'musteri_unvan' => (string)($k['musteri_unvan'] ?? ''),
            'lokasyon' => (string)($k['lokasyon'] ?? ''), 'sayac_alani' => hzSayacAlani((string)$k['makine_tur']),
            'eksik_acilar' => $islem === 'teslim' ? hzEksikAcilar($db, $k) : [],
            'referans' => refBilgi($db, (string)$k['makine_tur'], (int)$k['makine_id'])];
}

function stAractaMi(PDO $db, int $nakliyeId, int $kiralamaId, string $yon = 'gidis'): bool {
    $sut = $yon === 'donus' ? 'toplama_zaman' : 'yuklendi_zaman';
    $s = $db->prepare("SELECT 1 FROM nakliye_makineleri WHERE nakliye_id=? AND kiralama_id=? AND $sut IS NOT NULL");
    $s->execute([$nakliyeId, $kiralamaId]);
    return (bool)$s->fetchColumn();
}

function stAracMakineleri(PDO $db, int $nakliyeId, string $islem = 'teslim'): array {
    $sut = $islem === 'iade' ? 'toplama_zaman' : 'yuklendi_zaman';
    $s = $db->prepare("SELECT k.*, m.unvan AS musteri_unvan FROM nakliye_makineleri nm JOIN kiralamalar k ON k.id=nm.kiralama_id
                       JOIN musteriler m ON m.id=k.musteri_id WHERE nm.nakliye_id=? AND nm." . $sut . " IS NOT NULL ORDER BY k.kod, k.id");
    $s->execute([$nakliyeId]);
    $out = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $k) if (stSiraHatasi($db, $k, $islem) === null) $out[] = _stMakine($db, $k, $islem);
    return $out;
}

function stAracEkibi(PDO $db, int $nakliyeId): array {
    $s = $db->prepare('SELECT p.id, p.ad_soyad FROM nakliye_ekip e JOIN personel p ON p.id=e.personel_id WHERE e.nakliye_id=? ORDER BY p.ad_soyad');
    $s->execute([$nakliyeId]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'ad' => (string)$r['ad_soyad']], $s->fetchAll(PDO::FETCH_ASSOC));
}

function stTasinanlar(PDO $db, int $personelId, string $islem = 'teslim'): array {
    $s = $db->prepare('SELECT n.id, n.plaka, n.tarih FROM nakliye_ekip e JOIN nakliyeler n ON n.id=e.nakliye_id
                       WHERE e.personel_id=? ORDER BY n.tarih DESC, n.id DESC LIMIT 30');
    $s->execute([$personelId]);
    $out = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $n) {
        $mk = stAracMakineleri($db, (int)$n['id'], $islem);
        if (!$mk) continue;
        $out[] = ['nakliye_id' => (int)$n['id'], 'plaka' => (string)$n['plaka'], 'tarih' => (string)$n['tarih'],
                  'ekip' => stAracEkibi($db, (int)$n['id']), 'iletisim' => sfIletisim($db, (int)$mk[0]['kiralama_id']), 'makineler' => $mk];
    }
    return $out;
}

function stBaglantiIletisim(PDO $db, array $makineler): ?array {
    return $makineler ? sfIletisim($db, (int)$makineler[0]['kiralama_id']) : null;
}

function stAra(PDO $db, string $q, string $islem, int $personelId = 0): array {
    $q = trim($q);
    if (mb_strlen($q) < 2) throw new InvalidArgumentException('Aramak için en az 2 karakter yazın.');
    $sinir = (int)$db->query('SELECT son_eski_kiralama FROM operasyon_gecis WHERE id=1')->fetchColumn();
    $duz = trArama($q);
    if (mb_strlen(trim(str_replace(['%', '_', '!'], '', $duz))) < 2) throw new InvalidArgumentException('Aramak için en az 2 karakter yazın.');
    $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $duz) . '%';
    $sube = subeKisiti();
    $subeSql = $sube !== null ? " AND TRIM(COALESCE(k.sube,''))=?" : '';
    $s = $db->prepare("SELECT k.*, mu.unvan AS musteri_unvan FROM kiralamalar k JOIN musteriler mu ON mu.id=k.musteri_id
        LEFT JOIN manliftler ml ON k.makine_tur='manlift' AND ml.id=k.makine_id
        LEFT JOIN araclar ar ON k.makine_tur='arac' AND ar.id=k.makine_id
        WHERE k.id>? AND k.durum='aktif' AND k.gercek_bitis IS NULL AND k.makine_id IS NOT NULL" . $subeSql . "
          AND (" . trAramaSql('k.kod') . " LIKE ? ESCAPE '!' OR " . trAramaSql('ml.kod') . " LIKE ? ESCAPE '!' OR "
              . trAramaSql('ar.plaka') . " LIKE ? ESCAPE '!' OR " . trAramaSql('mu.unvan') . " LIKE ? ESCAPE '!')
        ORDER BY k.baslangic DESC, k.id DESC LIMIT 60");
    $s->execute($sube !== null ? [$sinir, $sube, $like, $like, $like, $like] : [$sinir, $like, $like, $like, $like]);
    $out = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $k) {
        if ($sube !== null && trim((string)$k['sube']) !== $sube) continue;
        if (stSiraHatasi($db, $k, $islem) !== null) continue;
        $out[] = _stEngelli($db, _stMakine($db, $k, $islem), $islem, $personelId);
        if (count($out) >= 20) break;
    }
    return $out;
}

function stKiralamaSatiri(PDO $db, int $kiralamaId, string $islem, int $personelId = 0): array {
    $s = $db->prepare("SELECT k.*, mu.unvan AS musteri_unvan FROM kiralamalar k JOIN musteriler mu ON mu.id=k.musteri_id
        WHERE k.id=? AND k.durum='aktif' AND k.gercek_bitis IS NULL AND k.makine_id IS NOT NULL");
    $s->execute([$kiralamaId]);
    $k = $s->fetch(PDO::FETCH_ASSOC);
    $sube = subeKisiti();
    if (!$k || ($sube !== null && trim((string)$k['sube']) !== $sube))
        throw new DomainException('Kiralama bulunamadı ya da açık değil. Listeden ya da "Başka makine ara" ile seçin.');
    if ($h = stSiraHatasi($db, $k, $islem)) throw new DomainException($h);
    return _stEngelli($db, _stMakine($db, $k, $islem), $islem, $personelId);
}

function stProtokolKiralamalari(PDO $db, array $ham): array {
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ham), fn($i) => $i > 0))), 0, ST_TOPLU_EN_FAZLA);
    foreach ($ids as $kid) stKiralamaSatiri($db, $kid, 'teslim');
    return $ids;
}

function _stEngelli(PDO $db, array $satir, string $islem, int $personelId): array {
    if ($islem === 'teslim' && $personelId > 0 && ($e = sfTeslimEngeli($db, (int)$satir['kiralama_id'], $personelId))) $satir['engel'] = $e;
    return $satir;
}

function stOturumImzaAd(PDO $db, int $userId, string $yedek): string {
    $s = $db->prepare("SELECT p.ad_soyad FROM users u JOIN personel p ON p.id = u.personel_id WHERE u.id = ?");
    $s->execute([$userId]);
    $ad = trim((string)($s->fetchColumn() ?: ''));
    return $ad !== '' ? $ad : $yedek;
}

function stBaglantiCoz(PDO $db, string $belirtec): ?array {
    if (!preg_match('/^[a-f0-9]{48}$/', $belirtec)) return null;
    $s = $db->prepare("SELECT tb.id, tb.nakliye_id, tb.yon, tb.gecerlilik, tb.alici_ad AS kopya_ad, tb.alici_tel AS kopya_tel,
                              n.plaka, n.sube, n.dis_sofor_ad, n.dis_sofor_tel, n.dis_sofor_donus_ad, n.dis_sofor_donus_tel
                       FROM teslim_baglantilari tb JOIN nakliyeler n ON n.id=tb.nakliye_id
                       WHERE tb.belirtec_ozet=? AND tb.yon IN ('gidis','donus') AND tb.kullanildi=0 AND tb.gecerlilik > datetime('now','localtime')");
    $s->execute([tokenOzet($belirtec)]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $kopya = trim((string)($r['kopya_ad'] ?? ''));
    $c = $kopya !== '' ? ['ad' => $kopya, 'tel' => trim((string)($r['kopya_tel'] ?? ''))] : sfDisCift($r, (string)$r['yon']);
    $r['alici_ad'] = $c['ad']; $r['alici_tel'] = $c['tel'];
    unset($r['kopya_ad'], $r['kopya_tel']);
    return $r;
}

function stKapaliBaglantiMukerrer(PDO $db, string $belirtec, string $anahtar): ?array {
    if (!preg_match('/^[a-f0-9]{48}$/', $belirtec) || !preg_match('/^[A-Za-z0-9_-]{16,40}$/', $anahtar)) return null;
    $s = $db->prepare("SELECT id, nakliye_id, yon FROM teslim_baglantilari WHERE belirtec_ozet=? AND yon IN ('gidis','donus') AND kullanildi=1");
    $s->execute([tokenOzet($belirtec)]);
    $bg = $s->fetch(PDO::FETCH_ASSOC);
    if (!$bg) return null;
    $q = $db->prepare('SELECT id, kiralama_id, belge_no, islem, personel_id, teslim_eden_tur FROM iade_tutanaklari WHERE toplu_teslim_id=? ORDER BY id');
    $q->execute([$anahtar]);
    $onceki = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$onceki) return null;
    foreach ($onceki as $r) {
        if ($r['islem'] !== ($bg['yon'] === 'donus' ? 'iade' : 'teslim') || $r['teslim_eden_tur'] !== 'dis_sofor' || (int)$r['personel_id'] !== 0
            || !stAractaMi($db, (int)$bg['nakliye_id'], (int)$r['kiralama_id'], (string)$bg['yon'])) return null;
    }
    return ['toplu_teslim_id' => $anahtar, 'mukerrer' => true, 'baglanti_kapandi' => true,
            'tutanaklar' => array_map(fn($r) => ['id' => (int)$r['id'], 'kiralama_id' => (int)$r['kiralama_id'], 'belge_no' => (string)$r['belge_no']], $onceki)];
}

function stBaglantiSinirdaMi(PDO $db, string $ip): bool {
    $s = $db->prepare("SELECT COUNT(*) FROM checkin_attempts WHERE ip=? AND kaynak='teslim_baglanti' AND success=0
                       AND attempted_at >= datetime('now','-10 minutes','localtime')");
    $s->execute([$ip]);
    return (int)$s->fetchColumn() >= ST_BAGLANTI_IP_SINIRI;
}

function stBaglantiDenemesi(PDO $db, string $ip, bool $basarili): void {
    $db->prepare("INSERT INTO checkin_attempts(personel_id, ip, success, kaynak) VALUES(0,?,?,'teslim_baglanti')")->execute([$ip, $basarili ? 1 : 0]);
}

function stBirlikte(PDO $db, $idler, int $ben): array {
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', is_array($idler) ? $idler : []),
                                                              fn($i) => $i > 0 && $i !== $ben))), 0, 10);
    if (!$ids) return [];
    $s = $db->prepare("SELECT id, ad_soyad FROM personel WHERE COALESCE(durum,'aktif')='aktif' AND id IN ("
                      . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY ad_soyad');
    $s->execute($ids);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'ad' => (string)$r['ad_soyad']], $s->fetchAll(PDO::FETCH_ASSOC));
}

function stFiili(array $b): int {
    $kabul = (string)($b['kabul_sonucu'] ?? '');
    if (in_array($kabul, ['teslim_aldi', 'cekinceli'], true)) return 1;
    if ($kabul === 'teslim_edilemedi') return 0;
    return !empty($b['birakildi']) ? 1 : 0;
}

function stSatirHatasi(array $satir, array $k, int $fiili): ?string {
    $kod = (string)($k['kod'] ?: '#' . $k['id']);
    if ($fiili === 1 && trim((string)($satir['sayac'] ?? '')) === '' && hzSayacZorunlu((string)($k['makine_tur'] ?? ''))) return $kod . ': kilometreyi girin.';
    if (!empty($satir['hasar']) && trim((string)($satir['hasar_notu'] ?? '')) === '') return $kod . ': hasarı kısaca yazın.';
    return null;
}

function stSatirCekinceli(array $b, array $satir): bool {
    if (($b['kabul_sonucu'] ?? '') !== 'cekinceli') return false;
    if (array_key_exists('cekince', $satir)) return !empty($satir['cekince']);
    $satirlar = is_array($b['makineler'] ?? null) ? $b['makineler'] : [];
    $hasarli = array_filter($satirlar, fn($s) => is_array($s) && !empty($s['hasar']));
    return !$hasarli || !empty($satir['hasar']);
}

function stCekinceHatasi(array $b, array $satirlar): ?string {
    if (($b['kabul_sonucu'] ?? '') !== 'cekinceli' || !$satirlar) return null;
    foreach ($satirlar as $s) if (is_array($s) && stSatirCekinceli($b, $s)) return null;
    return 'Çekince hangi makine için? En az bir makineyi işaretleyin ya da sonucu "İmzaladı" yapın.';
}

function stTutanakGovdesi(array $b, array $satir, array $k, string $islem, string $firma): array {
    $hasar = !empty($satir['hasar']);
    $musteri = (string)($k['musteri_unvan'] ?? '');
    $cekinceDisi = ($b['kabul_sonucu'] ?? '') === 'cekinceli' && !stSatirCekinceli($b, $satir);
    return [
        'kiralama_id' => (int)$k['id'], 'islem' => $islem, 'tarih' => date('Y-m-d'),
        'makine_tur' => (string)$k['makine_tur'], 'makine_id' => (int)$k['makine_id'],
        'teslim_eden_firma' => $islem === 'teslim' ? $firma : $musteri,
        'teslim_alan_firma' => $islem === 'teslim' ? $musteri : $firma,
        'calisma_yeri' => trim((string)($k['lokasyon'] ?? '')) ?: $musteri,
        'genel_durum' => $hasar ? 'hasarli' : 'saglam',
        'hasar_notu' => $hasar ? ($islem === 'teslim' ? 'Nakliyede hasar: ' : 'Sahada görünür hasar: ') . trim((string)($satir['hasar_notu'] ?? '')) : '',
        hzSayacAlani((string)$k['makine_tur']) => trim((string)($satir['sayac'] ?? '')),
        'fotograflar' => is_array($satir['fotograflar'] ?? null) ? array_values($satir['fotograflar']) : [],
        'musteri_temsilci_ad' => trim((string)($b['musteri_temsilci_ad'] ?? '')),
        'imza_musteri' => (string)($b['imza_musteri'] ?? ''), 'imza_personel' => (string)($b['imza_personel'] ?? ''),
        'kabul_sonucu' => $cekinceDisi ? 'teslim_aldi' : (string)($b['kabul_sonucu'] ?? ''), 'kabul_notu' => $cekinceDisi ? '' : (string)($b['kabul_notu'] ?? ''),
        'fiili_teslim' => stFiili($b),
        'bulundugu_yer' => (string)($b['bulundugu_yer'] ?? ''), 'sonraki_plan' => (string)($b['sonraki_plan'] ?? ''),
        'cekince_parca' => $cekinceDisi ? '' : (string)($b['cekince_parca'] ?? ''),
        'enlem' => $b['enlem'] ?? '', 'boylam' => $b['boylam'] ?? '',
        'cikis_fotograflari' => $islem === 'teslim',
    ];
}

function _stFirma(PDO $db): string {
    $s = $db->query("SELECT deger FROM ayarlar WHERE anahtar='firma_unvan'");
    return trim((string)($s ? $s->fetchColumn() : '')) ?: 'Taloo';
}

function stTopluKaydet(PDO $db, array $b, array $kim, array $kapsam, array &$yazilanFotolar): array {
    islemKilitAl($db);
    $anahtar = (string)($b['toplu_anahtar'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{16,40}$/', $anahtar)) throw new InvalidArgumentException('Teslim anahtarı geçersiz. Sayfayı yenileyip yeniden deneyin.');
    $islem = ($b['islem'] ?? '') === 'iade' ? 'iade' : 'teslim';
    $baglanti = $kapsam['baglanti'] ?? null;
    $bYon = (string)($baglanti['yon'] ?? 'gidis');
    $kagit = !empty($kapsam['kagit']);
    $pid = (int)($kapsam['personel_id'] ?? 0);
    $sube = subeKisiti();
    $q = $db->prepare('SELECT t.id, t.kiralama_id, t.belge_no, t.islem, t.personel_id, t.teslim_eden_tur, t.kaydeden, k.sube
                       FROM iade_tutanaklari t JOIN kiralamalar k ON k.id=t.kiralama_id WHERE t.toplu_teslim_id=? ORDER BY t.id');
    $q->execute([$anahtar]);
    if ($onceki = $q->fetchAll(PDO::FETCH_ASSOC)) {
        $sahip = true;
        foreach ($onceki as $r) {
            if ($r['islem'] !== $islem || $r['teslim_eden_tur'] !== (string)($kim['tur'] ?? 'personel')) $sahip = false;
            if ((int)$r['personel_id'] !== (int)($kim['personel_id'] ?? 0)) $sahip = false;
            if ((string)($r['kaydeden'] ?? '') !== (string)($kim['kaydeden'] ?? '')) $sahip = false;
            if ($sube !== null && trim((string)$r['sube']) !== $sube) $sahip = false;
            if ($baglanti && !stAractaMi($db, (int)$baglanti['nakliye_id'], (int)$r['kiralama_id'], $bYon)) $sahip = false;
        }
        if (!$sahip) throw new DomainException('Bu teslim anahtarı başka bir kayda ait.');
        $kapandi = false;
        if ($baglanti) {
            $bq = $db->prepare('SELECT kullanildi FROM teslim_baglantilari WHERE id=?');
            $bq->execute([(int)$baglanti['id']]);
            $kapandi = (int)$bq->fetchColumn() === 1;
        }
        return ['toplu_teslim_id' => $anahtar, 'mukerrer' => true, 'baglanti_kapandi' => $kapandi,
                'tutanaklar' => array_map(fn($r) => ['id' => (int)$r['id'], 'kiralama_id' => (int)$r['kiralama_id'], 'belge_no' => (string)$r['belge_no']], $onceki)];
    }
    $bIslem = $bYon === 'donus' ? 'iade' : 'teslim';
    if ($baglanti && $islem !== $bIslem) throw new DomainException('Bu bağlantı yalnız ' . ($bIslem === 'iade' ? 'teslim alma' : 'teslim') . ' içindir.');
    $satirlar = is_array($b['makineler'] ?? null) ? array_values($b['makineler']) : [];
    if (!$satirlar) throw new InvalidArgumentException('Kaydedilecek makineleri seçin.');
    if (count($satirlar) > ST_TOPLU_EN_FAZLA) throw new InvalidArgumentException('Tek seferde en fazla ' . ST_TOPLU_EN_FAZLA . ' makine kaydedilir.');
    if ($h = stCekinceHatasi($b, $satirlar)) throw new InvalidArgumentException($h);
    $fiili = stFiili($b);
    $benim = [];
    if ($pid > 0 && !$baglanti) foreach (stTasinanlar($db, $pid, $islem) as $a) foreach ($a['makineler'] as $m) $benim[$m['kiralama_id']] = true;
    $plan = []; $musteri = null; $gorulen = [];
    $kq = $db->prepare('SELECT k.*, m.unvan AS musteri_unvan FROM kiralamalar k JOIN musteriler m ON m.id=k.musteri_id WHERE k.id=?');
    foreach ($satirlar as $i => $s) {
        $kid = is_array($s) ? (int)($s['kiralama_id'] ?? 0) : 0;
        if ($kid < 1 || isset($gorulen[$kid])) throw new InvalidArgumentException(($i + 1) . '. satırdaki makine seçimi geçersiz.');
        $gorulen[$kid] = true;
        if ($baglanti && !stAractaMi($db, (int)$baglanti['nakliye_id'], $kid, $bYon)) throw new DomainException('#' . $kid . ': bu makine bağlantının aracında değil.');
        $kq->execute([$kid]);
        $k = $kq->fetch(PDO::FETCH_ASSOC);
        if (!$k) throw new DomainException('Kiralama bulunamadı.');
        $kod = (string)($k['kod'] ?: '#' . $kid);
        $mAd = _stMakine($db, $k, '')['makine_ad'];
        if ($mAd !== '') $kod .= ' (' . $mAd . ')';
        if ($sube !== null && trim((string)$k['sube']) !== $sube) throw new DomainException($kod . ': başka şubenin kiralaması.');
        if ($h = stSiraHatasi($db, $k, $islem)) throw new DomainException($h);
        if ($islem === 'teslim' && $pid > 0 && !$baglanti && !$kagit && ($h = sfTeslimEngeli($db, $kid, $pid))) throw new SfYetkiHatasi($kod . ': ' . $h);
        if ($musteri !== null && $musteri !== (int)$k['musteri_id'])
            throw new InvalidArgumentException('Tek imzayla yalnız aynı müşterinin makineleri kaydedilir. Diğer müşteri için ayrı teslim yapın.');
        $musteri = (int)$k['musteri_id'];
        if ($h = stSatirHatasi($s, $k, $fiili)) throw new InvalidArgumentException($h);
        $plan[] = [$k, $s, $kod];
    }
    foreach ($plan as [$k, $s, $kod]) {
        $g = stTutanakGovdesi($b, $s, $k, $islem, '');
        if ($kagit) $g = stKagitGovdesi($g, $b);
        $fk = $kagit ? 'kagit' : 'saha';
        if ($h = itFotoHatasi($g['fotograflar'], (string)$k['makine_tur'], $fk, itGerekliAcilar($db, $k, $islem, $fk), $g['genel_durum'] !== 'saglam'))
            throw new InvalidArgumentException($kod . ': ' . $h);
    }
    $firma = _stFirma($db);
    $adet = count($plan);
    $out = [];
    foreach ($plan as [$k, $s, $kod]) {
        $govde = stTutanakGovdesi($b, $s, $k, $islem, $firma);
        if ($kagit) $govde = stKagitGovdesi($govde, $b);
        $planDisi = $pid > 0 && !$baglanti && !isset($benim[(int)$k['id']]);
        try {
            $r = itTutanakYaz($db, $govde, $kim, [
                'foto_kurali' => $kagit ? 'kagit' : 'saha', 'aksesuar_sayimi' => false,
                'toplu' => ['id' => $anahtar, 'adet' => $adet], 'birlikte' => $kapsam['birlikte'] ?? [],
                'imza_personel_ad' => $kim['imza_ad'] ?? null, 'istemci_anahtari' => $anahtar . '-' . (int)$k['id'],
                'adim_veri' => ['toplu_teslim_id' => $anahtar, 'plan_disi_teslim' => $planDisi],
            ], $yazilanFotolar);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException($kod . ': ' . $e->getMessage());
        } catch (DomainException $e) {
            throw new DomainException(str_contains($e->getMessage(), $kod) ? $e->getMessage() : $kod . ': ' . $e->getMessage());
        }
        $bn = $db->prepare('SELECT belge_no FROM iade_tutanaklari WHERE id=?');
        $bn->execute([$r['id']]);
        $out[] = ['id' => $r['id'], 'kiralama_id' => (int)$k['id'], 'kod' => (string)($k['kod'] ?: '#' . $k['id']), 'belge_no' => (string)$bn->fetchColumn(), 'asama' => $r['asama'],
                  'hakedis_id' => $r['hakedis_id'], 'kiralama_kapatildi' => $r['kiralama_kapatildi']];
    }
    logla($db, 'iade_tutanaklari', $out[0]['id'], 'ekle', null,
          ['toplu_teslim_id' => $anahtar, 'adet' => $adet, 'tutanaklar' => array_column($out, 'id')],
          'Toplu ' . ($islem === 'teslim' ? 'teslim' : 'teslim alma') . ': ' . $adet . ' makine, tek müşteri imzası — ' . (string)($kim['ad'] ?? ''));
    $kapandi = false;
    if ($baglanti && !stAracMakineleri($db, (int)$baglanti['nakliye_id'], $islem)) {
        $db->prepare('UPDATE teslim_baglantilari SET kullanildi=1 WHERE id=?')->execute([(int)$baglanti['id']]);
        logla($db, 'nakliyeler', (int)$baglanti['nakliye_id'], 'guncelle', null, ['teslim_baglantisi' => 'kullanildi'],
              'Dış şoför bağlantısı kapandı: ' . ($islem === 'iade' ? 'araçtaki bütün makineler teslim alındı' : 'araçtaki bütün makineler teslim edildi'));
        $kapandi = true;
    }
    return ['toplu_teslim_id' => $anahtar, 'mukerrer' => false, 'baglanti_kapandi' => $kapandi, 'tutanaklar' => $out];
}

function stKagitGovdesi(array $govde, array $b): array {
    if (!in_array($b['kabul_sonucu'] ?? null, ['teslim_aldi', 'cekinceli', 'teslim_edilemedi'], true))
        throw new InvalidArgumentException('Kâğıttaki kabul sonucunu seçin (teslim aldı, çekinceli ya da teslim edilemedi).');
    $tarih = (string)($b['tarih'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih) || $tarih > date('Y-m-d'))
        throw new InvalidArgumentException('Kâğıttaki teslim tarihini girin (bugün ya da önce).');
    $kagit = array_map(fn($f) => ['aci' => 'Kâğıt tutanak', 'veri' => is_array($f) ? (string)($f['veri'] ?? '') : '', 'aciklama' => 'Ofise getirilen kâğıt tutanak'],
                       is_array($b['kagit_fotograflari'] ?? null) ? array_values($b['kagit_fotograflari']) : []);
    return array_merge($govde, ['tarih' => $tarih, 'fotograflar' => array_merge($kagit, $govde['fotograflar'] ?? []),
                                'imza_musteri' => '', 'imza_personel' => '']);
}
