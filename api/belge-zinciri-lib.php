<?php
declare(strict_types=1);

const BZ_SONUCLAR = ['sorunsuz', 'hasarli', 'yapilmadi'];
const BZ_BULGU_AD = ['hasar' => 'Hasar', 'eksik' => 'Eksik', 'ariza' => 'Arıza'];

const BZ_BILESEN_AD = ['tekerlek' => 'Tekerlek', 'korkuluk' => 'Korkuluklar', 'joystick' => 'Joystick', 'boya' => 'Boya',
                       'aku' => 'Akü', 'kaporta' => 'Kaporta', 'destek_ayaklari' => 'Destek Ayakları'];
const BZ_BILESEN_DURUM = ['hasarsiz' => 'Hasarsız', 'az_hasarli' => 'Az hasarlı', 'cok_hasarli' => 'Çok hasarlı'];

function bzBilesenTanim(): array { return ['ad' => BZ_BILESEN_AD, 'durum' => BZ_BILESEN_DURUM]; }

function bzBilesenler($ham, ?array $taban = null): array {
    if ($ham !== null && !is_array($ham)) throw new DomainException('Bileşen tablosu okunamadı.');
    $out = [];
    foreach (BZ_BILESEN_AD as $k => $ad) {
        $d = $ham[$k] ?? null;
        if ($d === null) { $t = $taban[$k] ?? null; $d = is_string($t) && isset(BZ_BILESEN_DURUM[$t]) ? $t : 'hasarsiz'; }
        if (!is_string($d) || !isset(BZ_BILESEN_DURUM[$d])) throw new DomainException($ad . ': bileşen durumu geçersiz.');
        $out[$k] = $d;
    }
    return $out;
}

function bzBilesenKotulesen(?array $gidis, array $donus): array {
    if (!$gidis) return [];
    $sira = array_keys(BZ_BILESEN_DURUM);
    $out = [];
    foreach (array_keys(BZ_BILESEN_AD) as $k) {
        $g = array_search($gidis[$k] ?? null, $sira, true);
        $d = array_search($donus[$k] ?? null, $sira, true);
        if ($g !== false && $d !== false && $d > $g) $out[] = $k;
    }
    return $out;
}

function bzBilesenHatasi(?array $gidis, array $donus, array $bulgular, string $islem): ?string {
    $kot = bzBilesenKotulesen($gidis, $donus);
    if ($islem === 'sorunsuz') return $kot ? BZ_BILESEN_AD[$kot[0]] . ' gidişe göre kötüleşti; "Sorun var" seçin.' : null;
    if ($islem !== 'sorunlu') return null;
    $bagli = array_column($bulgular, 'bilesen');
    foreach ($kot as $k) if (!in_array($k, $bagli, true)) return BZ_BILESEN_AD[$k] . ' gidişe göre kötüleşti: bir bulgu satırında bu bileşeni seçip fotoğraflayın.';
    foreach (array_values($bulgular) as $i => $b) {
        $k = (string)($b['bilesen'] ?? '');
        if (($b['tur'] ?? '') === 'hasar' && isset(BZ_BILESEN_AD[$k]) && ($donus[$k] ?? 'hasarsiz') === 'hasarsiz')
            return ($i + 1) . '. bulgu: ' . BZ_BILESEN_AD[$k] . ' bileşen tablosunda hasarsız; tabloyu düzeltin.';
    }
    return null;
}

function bzYakitTuru(?string $kategori, ?string $guc = null): ?string {
    if ($guc !== null && $guc !== '') return $guc;
    $t = trArama($kategori);
    $a = str_contains($t, 'akulu');
    $d = str_contains($t, 'dizel');
    return $a === $d ? null : ($a ? 'akulu' : 'dizel');
}

function bzNakliyeSofor(?array $n): ?string {
    if (!$n) return null;
    $p = [];
    if (($sofor = trim((string)($n['sofor_adi'] ?? ''))) !== '') $p[] = 'Şoför: ' . $sofor;
    if (($s = trim((string)($n['dis_sofor_ad'] ?? ''))) !== '') $p[] = 'Dış şoför: ' . $s;
    $e = array_values(array_filter(array_map(fn($x) => trim((string)$x), (array)($n['ekip'] ?? [])), fn($x) => $x !== '' && $x !== $sofor));
    if ($e) $p[] = 'Ekip: ' . implode(', ', $e);
    return $p ? mb_substr(implode(' · ', $p), 0, 160) : null;
}

function bzSahaAlim(PDO $db, int $kid): ?array {
    $s = $db->prepare("SELECT * FROM iade_tutanaklari WHERE kiralama_id=? AND islem='iade' AND COALESCE(fiili_teslim,1)=1
                       AND inceleme_sonucu IS NULL ORDER BY tarih DESC, id DESC LIMIT 1");
    $s->execute([$kid]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function bzIadeTutanagi(PDO $db, int $kid): ?array {
    $s = $db->prepare('SELECT * FROM iade_tutanaklari WHERE kiralama_id=? AND inceleme_sonucu IS NOT NULL LIMIT 1');
    $s->execute([$kid]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function bzIadeTutanagiKontrol(PDO $db, int $kontrolId): ?array {
    $s = $db->prepare('SELECT * FROM iade_tutanaklari WHERE depo_kontrol_id=? AND inceleme_sonucu IS NOT NULL LIMIT 1');
    $s->execute([$kontrolId]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function bzNakliye(PDO $db, int $kid, string $yon): ?array {
    [$zaman, $yonler] = $yon === 'donus' ? ['toplama_zaman', "('donus','gidis_donus')"] : ['yuklendi_zaman', "('gidis','gidis_donus','tek')"];
    $q = $db->prepare("SELECT n.id, n.tip, n.plaka, n.sofor_adi, n.sofor_personel_id, n.dis_sofor_ad, n.dis_sofor_tel, n.dis_sofor_donus_ad, n.dis_sofor_donus_tel
                       FROM nakliye_makineleri nm JOIN nakliyeler n ON n.id=nm.nakliye_id
                       WHERE nm.kiralama_id=? AND nm.$zaman IS NOT NULL AND COALESCE(n.yon,'gidis') IN $yonler ORDER BY nm.$zaman DESC, n.id DESC LIMIT 1");
    $q->execute([$kid]);
    $n = $q->fetch(PDO::FETCH_ASSOC);
    if (!$n) return null;
    $bYon = $yon === 'donus' ? 'donus' : 'gidis';
    $sAd = $bYon === 'gidis' && $n['sofor_personel_id'] !== null ? trim((string)_sfPersonelAd($db, (int)$n['sofor_personel_id'])) : '';
    if ($sAd !== '') $n['sofor_adi'] = $sAd;
    $e = $db->prepare('SELECT p.ad_soyad FROM nakliye_ekip e JOIN personel p ON p.id=e.personel_id WHERE e.nakliye_id=? ORDER BY p.ad_soyad');
    $e->execute([(int)$n['id']]);
    $ekip = $e->fetchAll(PDO::FETCH_COLUMN);
    $b = $db->prepare('SELECT COUNT(*) FROM teslim_baglantilari WHERE nakliye_id=? AND yon=?');
    $b->execute([(int)$n['id'], $bYon]);
    $icEkip = ($n['tip'] ?? 'dis') !== 'avk' && ($sAd !== '' || $ekip) && (int)$b->fetchColumn() === 0;
    unset($n['sofor_personel_id'], $n['tip']);
    $k = $db->prepare("SELECT alici_ad FROM teslim_baglantilari WHERE nakliye_id=? AND yon=? AND TRIM(COALESCE(alici_ad,''))<>''
                       ORDER BY kullanildi DESC, id DESC LIMIT 1");
    $k->execute([(int)$n['id'], $bYon]);
    $kopya = $k->fetchColumn();
    $n['dis_sofor_ad'] = $icEkip ? null : ($kopya !== false ? trim((string)$kopya) : (sfDisCift($n, $bYon)['ad'] ?: null));
    unset($n['dis_sofor_tel'], $n['dis_sofor_donus_ad'], $n['dis_sofor_donus_tel']);
    $n['ekip'] = $ekip;
    return $n;
}

function bzOperator(PDO $db, array $k): ?array {
    if (empty($k['operatorlu']) || empty($k['operator_id'])) return null;
    $q = $db->prepare('SELECT ad_soyad, telefon FROM personel WHERE id=?');
    $q->execute([(int)$k['operator_id']]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

function bzMakineKunye(PDO $db, string $tur, int $id): ?array {
    if ($id < 1) return null;
    $q = $db->prepare($tur === 'arac' ? 'SELECT plaka AS kod, marka, model, NULL AS seri_no, metraj, kategori, ozellikler, makine_kodu FROM araclar WHERE id=?'
                                      : 'SELECT kod, marka, model, seri_no, metraj, kategori, ozellikler, makine_kodu FROM manliftler WHERE id=?');
    $q->execute([$id]);
    $m = $q->fetch(PDO::FETCH_ASSOC);
    if (!$m) return null;
    return ['kod' => $m['kod'], 'marka' => $m['marka'], 'model' => $m['model'], 'seri_no' => $m['seri_no'],
            'kategori' => ($kat = makineKategoriAdi($db, $m)), 'metraj' => metrajSayi($m['metraj'] ?? null),
            'guc' => mkGuc($m['ozellikler'] ?? null), 'olcu_birim' => mkBirim($db, $kat), 'makine_kodu' => ($m['makine_kodu'] ?? null) ?: null];
}

function bzZincirKayitlari(PDO $db, array $k): array {
    $kid = (int)$k['id'];
    $adim = opAdimDurumu($db, $kid)['depo_hazirlik'] ?? null;
    $hazir = ($adim['sonuc'] ?? null) === 'tamam';
    $h = $hazir ? hzGet($db, $kid, 'gidis') : null;
    $t = $db->prepare("SELECT * FROM iade_tutanaklari WHERE kiralama_id=? AND islem='teslim' AND COALESCE(fiili_teslim,1)=1 ORDER BY tarih DESC, id DESC LIMIT 1");
    $t->execute([$kid]);
    return ['hazirlik' => $h['veri'] ?? null, 'ekspertiz' => $hazir ? ($adim['yapan_ad'] ?? null) : null,
            'yukleme' => bzNakliye($db, $kid, 'gidis'), 'toplama' => bzNakliye($db, $kid, 'donus'),
            'makine' => bzMakineKunye($db, (string)($k['makine_tur'] ?? ''), (int)($k['makine_id'] ?? 0)), 'operator' => bzOperator($db, $k),
            'teslim' => $t->fetch(PDO::FETCH_ASSOC) ?: null];
}

function bzTeslimAlanlari(array $z, string $tarih, ?string $saat): array {
    $y = $z['yukleme'] ?? null;
    $o = $z['operator'] ?? null;
    return ['nakliye_plaka' => trim((string)($y['plaka'] ?? '')) ?: null, 'nakliye_sofor' => bzNakliyeSofor($y),
            'ekspertiz_eden' => $z['ekspertiz'] ?? null, 'yakit_turu' => bzYakitTuru($z['makine']['kategori'] ?? null, $z['makine']['guc'] ?? null),
            'is_basi_tarih' => $tarih, 'is_basi_saat' => $saat,
            'operator_ad' => $o['ad_soyad'] ?? null, 'operator_iletisim' => trim((string)($o['telefon'] ?? '')) ?: null];
}

function bzTeslimAlmaAlanlari(array $z, string $tarih, ?string $saat): array {
    $y = $z['toplama'] ?? null;
    $o = $z['operator'] ?? null;
    $t = $z['teslim'] ?? null;
    return ['nakliye_plaka' => trim((string)($y['plaka'] ?? '')) ?: null, 'nakliye_sofor' => bzNakliyeSofor($y),
            'yakit_turu' => bzYakitTuru($z['makine']['kategori'] ?? null, $z['makine']['guc'] ?? null),
            'is_basi_tarih' => $t ? (($t['is_basi_tarih'] ?? null) ?: ($t['tarih'] ?? null)) : null, 'is_basi_saat' => $t['is_basi_saat'] ?? null,
            'is_sonu_tarih' => $tarih, 'is_sonu_saat' => $saat,
            'operator_ad' => $o['ad_soyad'] ?? null, 'operator_iletisim' => trim((string)($o['telefon'] ?? '')) ?: null];
}

function bzSahaGovdesi(PDO $db, array $k, array $body, string $islem, string $kural, string $tarih): array {
    $saat = ($kural === 'kagit' || $tarih !== date('Y-m-d')) ? null : date('H:i');
    $z = bzZincirKayitlari($db, $k);
    $a = $islem === 'teslim' ? bzTeslimAlanlari($z, $tarih, $saat) : bzTeslimAlmaAlanlari($z, $tarih, $saat);
    $bosMu = fn($x) => $x === null || $x === '' || $x === [];
    $norm = fn($x) => mb_strtoupper((string)preg_replace('/\s+/u', '', (string)$x), 'UTF-8');
    $es = ['is_basi_saat' => 'is_basi_tarih', 'is_sonu_saat' => 'is_sonu_tarih', 'nakliye_sofor' => 'nakliye_plaka'];
    $ilk = $body;
    foreach ($a as $f => $v) {
        if ($v === null || !$bosMu($ilk[$f] ?? null)) continue;
        if (isset($es[$f])) {
            $ana = $ilk[$es[$f]] ?? null;
            $ayni = $f === 'nakliye_sofor' ? $norm($ana) === $norm($a[$es[$f]] ?? null) : trim((string)$ana) === (string)($a[$es[$f]] ?? '');
            if (!$bosMu($ana) && !$ayni) continue;
        }
        $body[$f] = $v;
    }
    return $body;
}

function bzIadeAlanlari(array $k, ?array $saha, string $sonuc, ?array $kontrol, ?string $gerekce, string $firma, array $z = []): array {
    if (!in_array($sonuc, BZ_SONUCLAR, true)) throw new InvalidArgumentException('Geçersiz inceleme sonucu.');
    $alan = hzSayacAlani((string)$k['makine_tur']);
    $musteri = (string)($k['musteri_unvan'] ?? '');
    $y = $z['toplama'] ?? null;
    $o = $z['operator'] ?? null;
    $t = $z['teslim'] ?? null;
    $a = ['kiralama_id' => (int)$k['id'], 'saha_alim_id' => $saha ? (int)$saha['id'] : null,
          'depo_kontrol_id' => $kontrol ? (int)$kontrol['id'] : null, 'inceleme_sonucu' => $sonuc, 'inceleme_gerekce' => null,
          'tarih' => date('Y-m-d'),
          'teslim_eden_firma' => trim((string)($saha['teslim_eden_firma'] ?? '')) ?: $musteri,
          'teslim_alan_firma' => trim((string)($saha['teslim_alan_firma'] ?? '')) ?: $firma,
          'calisma_yeri' => trim((string)($saha['calisma_yeri'] ?? '')) ?: (trim((string)($k['lokasyon'] ?? '')) ?: $musteri),
          'musteri_temsilci_ad' => $saha['musteri_temsilci_ad'] ?? null,
          'kabul_sonucu' => $saha['kabul_sonucu'] ?? null, 'kabul_notu' => $saha['kabul_notu'] ?? null,
          'km' => null, 'calisma_saati' => null, 'genel_durum' => 'saglam', 'hasar_notu' => null, 'genel_not' => null,
          'imza_personel' => null, 'imza_personel_ad' => null,
          'nakliye_plaka' => trim((string)($y['plaka'] ?? '')) ?: null, 'nakliye_sofor' => bzNakliyeSofor($y),
          'yakit_turu' => bzYakitTuru($z['makine']['kategori'] ?? null, $z['makine']['guc'] ?? null), 'ekspertiz_eden' => null,
          'is_basi_tarih' => $t ? (($t['is_basi_tarih'] ?? null) ?: ($t['tarih'] ?? null)) : null, 'is_basi_saat' => $t['is_basi_saat'] ?? null,
          'is_sonu_tarih' => $saha ? (($saha['is_sonu_tarih'] ?? null) ?: ($saha['tarih'] ?? null)) : (($k['gercek_bitis'] ?? null) ?: null),
          'is_sonu_saat' => $saha['is_sonu_saat'] ?? null,
          'operator_ad' => $o['ad_soyad'] ?? null, 'operator_iletisim' => trim((string)($o['telefon'] ?? '')) ?: null];
    if ($sonuc === 'yapilmadi') {
        $a['inceleme_gerekce'] = trim((string)$gerekce) ?: null;
        $a[$alan] = isset($saha[$alan]) && is_numeric($saha[$alan]) ? (float)$saha[$alan] : null;
        $a['genel_durum'] = $saha['genel_durum'] ?? 'saglam';
        $a['hasar_notu'] = $saha['hasar_notu'] ?? null;
        $a['genel_not'] = 'Dönüş kontrolü yapılmadı: ' . ($a['inceleme_gerekce'] ?? 'gerekçe yazılmadı') . '.';
        return $a;
    }
    $v = $kontrol['veri'] ?? [];
    $a[$alan] = is_numeric($v['sayac'] ?? null) ? (float)$v['sayac'] : null;
    if ($sonuc === 'hasarli') {
        $bulgular = is_array($v['bulgular'] ?? null) ? $v['bulgular'] : [];
        $a['genel_durum'] = array_values(array_unique(array_column($bulgular, 'tur'))) === ['eksik'] ? 'eksik_aksesuar' : 'hasarli';
        $a['hasar_notu'] = implode('; ', array_map(fn($x) => (BZ_BULGU_AD[$x['tur'] ?? ''] ?? (string)($x['tur'] ?? '')) . ': ' . ($x['aciklama'] ?? ''), $bulgular)) ?: null;
    }
    $a['genel_not'] = $sonuc === 'sorunsuz' ? 'Dönüş kontrolü: sorunsuz.' : 'Dönüş kontrolü: hasarlı — onarım için servis kaydı açıldı; bedel varsa ayrıca Servis Bedeli belgesiyle bildirilir.';
    $imza = is_array($v['imza'] ?? null) ? $v['imza'] : [];
    $a['imza_personel'] = $imza['veri'] ?? null;
    $a['imza_personel_ad'] = $imza['ad'] ?? ($kontrol['yapan'] ?? null);
    $a['ekspertiz_eden'] = $a['imza_personel_ad'];
    return $a;
}

function bzIadeOlustur(PDO $db, int $kid, string $sonuc, ?int $kontrolId, ?string $gerekce): array {
    require_once __DIR__ . '/tutanak-yaz.php';
    $q = $db->prepare('SELECT k.*, m.unvan AS musteri_unvan FROM kiralamalar k LEFT JOIN musteriler m ON m.id=k.musteri_id WHERE k.id=?');
    $q->execute([$kid]);
    $k = $q->fetch(PDO::FETCH_ASSOC);
    if (!$k) throw new DomainException('Kiralama bulunamadı.');
    $kontrol = null;
    if ($kontrolId) {
        $c = $db->prepare("SELECT * FROM depo_kontrolleri WHERE id=? AND kiralama_id=? AND yon='donus'");
        $c->execute([$kontrolId, $kid]);
        $kontrol = $c->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$kontrol) throw new DomainException('Dönüş kontrolü bulunamadı.');
        $kontrol['veri'] = json_decode((string)$kontrol['veri'], true) ?: [];
    }
    $firma = trim((string)(_hbAyar($db, ['firma_unvan'])['firma_unvan'] ?? '')) ?: 'Taloo';
    return itIadeTutanagiYaz($db, bzIadeAlanlari($k, bzSahaAlim($db, $kid), $sonuc, $kontrol, $gerekce, $firma, bzZincirKayitlari($db, $k)), opOturumYapan($db));
}

function bzSahaBolumu(PDO $db, array $t): array {
    $kid = (int)$t['kiralama_id'];
    $adim = function (string $sonuc) use ($db, $kid): array {
        $q = $db->prepare("SELECT yapan_ad, gerekce, zaman FROM operasyon_adimlari WHERE kiralama_id=? AND adim='saha_teslim_alma' AND sonuc=? ORDER BY id DESC LIMIT 1");
        $q->execute([$kid, $sonuc]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: [];
    };
    $s = null;
    if (!empty($t['saha_alim_id'])) {
        $q = $db->prepare('SELECT s.*, k.makine_tur, COALESCE(p.ad_soyad, s.imza_personel_ad) AS alan_ad FROM iade_tutanaklari s
                           JOIN kiralamalar k ON k.id=s.kiralama_id LEFT JOIN personel p ON p.id=s.personel_id WHERE s.id=?');
        $q->execute([(int)$t['saha_alim_id']]);
        $s = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$s) { $a = $adim('atlandi'); return ['var' => false, 'neden' => $a['gerekce'] ?? null]; }
    $a = $adim('tamam');
    $f = $db->prepare('SELECT aci_tipi, dosya_yolu, aciklama FROM iade_tutanak_fotograflari WHERE tutanak_id=? ORDER BY id');
    $f->execute([(int)$s['id']]);
    return ['var' => true, 'id' => (int)$s['id'], 'tarih' => $s['tarih'], 'zaman' => $a['zaman'] ?? null,
            'alan' => $s['alan_ad'] ?: ($a['yapan_ad'] ?? null), 'musteri_temsilci_ad' => $s['musteri_temsilci_ad'],
            'imza_musteri' => trim((string)$s['imza_musteri']) !== '' ? (string)$s['imza_musteri'] : null,
            'kabul_sonucu' => $s['kabul_sonucu'], 'kagit' => ($s['teslim_eden_tur'] ?? '') === 'ofis_kagit',
            'sayac' => $s[hzSayacAlani((string)$s['makine_tur'])] ?? null, 'genel_durum' => $s['genel_durum'],
            'hasar_notu' => $s['hasar_notu'], 'genel_not' => $s['genel_not'], 'fotograflar' => $f->fetchAll(PDO::FETCH_ASSOC)];
}

function bzDepoBolumu(PDO $db, array $t): array {
    if (($t['inceleme_sonucu'] ?? '') === 'yapilmadi' || empty($t['depo_kontrol_id'])) {
        $q = $db->prepare("SELECT yapan_ad, zaman FROM operasyon_adimlari WHERE kiralama_id=? AND adim='depo_inceleme' AND sonuc='atlandi' ORDER BY id DESC LIMIT 1");
        $q->execute([(int)$t['kiralama_id']]);
        $a = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['yapildi' => false, 'gerekce' => $t['inceleme_gerekce'] ?? null, 'atlayan' => $a['yapan_ad'] ?? null, 'zaman' => $a['zaman'] ?? null];
    }
    $q = $db->prepare("SELECT * FROM depo_kontrolleri WHERE id=? AND yon='donus'");
    $q->execute([(int)$t['depo_kontrol_id']]);
    $dk = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    $v = json_decode((string)($dk['veri'] ?? ''), true) ?: [];
    $gidis = $v['sayac_gidis'] ?? null; $donus = $v['sayac'] ?? null;
    $imza = is_array($v['imza'] ?? null) ? $v['imza'] : [];
    return ['yapildi' => true, 'sonuc' => $t['inceleme_sonucu'], 'inceleyen' => $imza['ad'] ?? ($dk['yapan'] ?? null),
            'zaman' => $imza['zaman'] ?? ($dk['updated_at'] ?? null),
            'sayac' => ['gidis' => $gidis, 'donus' => $donus,
                        'fark' => (is_numeric($gidis) && is_numeric($donus)) ? round((float)$donus - (float)$gidis, 1) : null],
            'kontroller' => array_values(array_map(fn($r) => ['ad' => (string)($r['ad'] ?? ''), 'sonuc' => (string)($r['sonuc'] ?? ''), 'not' => (string)($r['not'] ?? '')],
                                                   is_array($v['kontroller'] ?? null) ? $v['kontroller'] : [])),
            'aksesuar_farklar' => array_map(fn($x) => ['ad' => $x['ad'] ?? '', 'verilen' => $x['verilen'] ?? null, 'alinan' => $x['alinan'] ?? null,
                                                      'fark' => $x['fark'] ?? null, 'not' => $x['not'] ?? ''], $v['aksesuar_farklar'] ?? []),
            'bulgular' => array_map(fn($b) => ['tur' => $b['tur'] ?? '', 'aciklama' => $b['aciklama'] ?? '', 'bilesen' => $b['bilesen'] ?? null,
                                               'foto_yollar' => _hbFotoYollari($db, $b['foto_ids'] ?? [])], $v['bulgular'] ?? []),
            'bilesen_gidis' => is_array($v['bilesen_gidis'] ?? null) ? $v['bilesen_gidis'] : null,
            'bilesenler' => is_array($v['bilesenler'] ?? null) ? $v['bilesenler'] : null,
            'fotolar' => hbFotoCiftleri($db, (int)$t['kiralama_id'])];
}

function bzHazirlikBolumu(PDO $db, int $kid): array {
    $adim = opAdimDurumu($db, $kid)['depo_hazirlik'] ?? null;
    if (($adim['sonuc'] ?? null) !== 'tamam') {
        $q = $db->prepare("SELECT yapan_ad, gerekce FROM operasyon_adimlari WHERE kiralama_id=? AND adim='depo_hazirlik' AND sonuc='atlandi' ORDER BY id DESC LIMIT 1");
        $q->execute([$kid]);
        $a = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['var' => false, 'gerekce' => $a['gerekce'] ?? null, 'atlayan' => $a['yapan_ad'] ?? null];
    }
    $v = hzGet($db, $kid, 'gidis')['veri'] ?? [];
    return ['var' => true, 'ekspertiz_eden' => $adim['yapan_ad'] ?? null, 'zaman' => $adim['zaman'] ?? null, 'sablon_ad' => $v['sablon_ad'] ?? null,
            'kontroller' => array_values(array_map(fn($r) => ['ad' => (string)($r['ad'] ?? ''), 'sonuc' => (string)($r['sonuc'] ?? ''), 'not' => (string)($r['not'] ?? '')],
                                                   is_array($v['kontroller'] ?? null) ? $v['kontroller'] : [])),
            'sayac' => $v['sayac'] ?? null,
            'aksesuarlar' => array_values(array_map(fn($r) => ['ad' => (string)($r['ad'] ?? ''), 'seri' => (string)($r['seri'] ?? ''), 'adet' => $r['adet'] ?? null],
                                                    is_array($v['aksesuarlar'] ?? null) ? $v['aksesuarlar'] : [])),
            'bilesenler' => is_array($v['bilesenler'] ?? null) ? bzBilesenler($v['bilesenler']) : null];
}

function bzIncelemeAtlandi(PDO $db, array $k, string $gerekce): ?array {
    if (empty($k['makine_id']) || !opYeniZincir($db, $k)) return null;
    if ($v = bzIadeTutanagi($db, (int)$k['id'])) return ['id' => (int)$v['id'], 'belge_no' => (string)$v['belge_no']];
    return bzIadeOlustur($db, (int)$k['id'], 'yapilmadi', null, $gerekce);
}

function bzSayilanIadeSql(string $t = 't'): string {
    return "NOT ($t.islem='iade' AND $t.inceleme_sonucu IS NULL AND EXISTS (SELECT 1 FROM iade_tutanaklari bz_it WHERE bz_it.kiralama_id=$t.kiralama_id AND bz_it.inceleme_sonucu IS NOT NULL))";
}

function bzListeBayraklari(array $t, bool $itVar): array {
    $saha = ($t['asama'] ?? 'tutanak') === 'saha_alim';
    $sonuc = $t['inceleme_sonucu'] ?? null;
    $yerine = ($t['islem'] ?? '') === 'iade' && $sonuc === null && $itVar;
    return ['sayimda' => !$yerine,
            'iade_sayilir' => ($t['islem'] ?? '') === 'iade' && !$saha && !$yerine,
            'zincir_ek_ucret' => !$saha && ($sonuc === 'yapilmadi' || ($sonuc === null && !$yerine))];
}

function bzPortalGorunurSql(string $t = 't'): string {
    $fiyatli = "'" . implode("','", HB_FIYATLI) . "'";
    return "NOT (COALESCE($t.inceleme_sonucu,'')='hasarli' AND EXISTS (SELECT 1 FROM hasar_degerlendirmeleri bz_h
              WHERE bz_h.depo_kontrol_id=$t.depo_kontrol_id AND COALESCE(bz_h.durum,'')<>'tasindi'
                AND NOT EXISTS (SELECT 1 FROM hasar_belgeleri bz_b WHERE bz_b.tur='HBB' AND bz_b.kaynak_id=bz_h.id AND bz_h.fiyat_durum IN ($fiyatli))))";
}

function bzZincirKur(array $k, array $tutanaklar, array $adim, ?array $hasar, bool $yeni, ?array $servis = null): array {
    $tarih = fn($z) => $z ? date('d.m.Y H:i', strtotime((string)$z)) : null;
    $out = ['kiralama' => $k, 'eski' => !$yeni, 'adimlar' => []];
    usort($tutanaklar, fn($a, $b) => [$a['tarih'], $a['id']] <=> [$b['tarih'], $b['id']]);
    if (!$yeni) {
        foreach ($tutanaklar as $t) $out['adimlar'][] = ['ad' => $t['islem'] === 'teslim' ? 'teslim' : 'iade', 'durum' => 'tamam', 'tutanak_id' => (int)$t['id'],
            'belge_no' => $t['belge_no'], 'tarih' => $t['tarih'], 'alt' => null, 'eski' => true];
        return $out;
    }
    $son = fn(callable $f) => array_values(array_filter($tutanaklar, $f))[count(array_filter($tutanaklar, $f)) - 1] ?? null;
    $fiili = fn($t) => (int)($t['fiili_teslim'] ?? 1) === 1;
    $h = $adim['depo_hazirlik'] ?? null;
    $out['adimlar'][] = ($h['sonuc'] ?? null) === 'tamam' ? ['ad' => 'hazirlik', 'durum' => 'tamam', 'alt' => trim(($h['yapan_ad'] ?? '') . ' · ' . $tarih($h['zaman'] ?? null), ' ·')]
        : (($h['sonuc'] ?? null) === 'atlandi' ? ['ad' => 'hazirlik', 'durum' => 'atlandi', 'alt' => $h['gerekce'] ?? null] : ['ad' => 'hazirlik', 'durum' => 'bekliyor', 'alt' => null]);
    $t = $son(fn($x) => $x['islem'] === 'teslim' && $fiili($x));
    $st = $adim['saha_teslim'] ?? null;
    $out['adimlar'][] = $t ? ['ad' => 'teslim', 'durum' => 'tamam', 'tutanak_id' => (int)$t['id'], 'belge_no' => $t['belge_no'], 'tarih' => $t['tarih'], 'alt' => null]
        : (($st['sonuc'] ?? null) === 'atlandi' ? ['ad' => 'teslim', 'durum' => 'atlandi', 'alt' => $st['gerekce'] ?? null] : ['ad' => 'teslim', 'durum' => 'bekliyor', 'alt' => null]);
    $s = $son(fn($x) => $x['islem'] === 'iade' && $x['inceleme_sonucu'] === null && $fiili($x));
    $sa = $adim['saha_teslim_alma'] ?? null;
    $out['adimlar'][] = $s ? ['ad' => 'teslim_alma', 'durum' => 'tamam', 'tutanak_id' => (int)$s['id'], 'tarih' => $s['tarih'], 'alt' => $tarih($sa['zaman'] ?? null)]
        : (($sa['sonuc'] ?? null) === 'atlandi' ? ['ad' => 'teslim_alma', 'durum' => 'atlandi', 'alt' => $sa['gerekce'] ?? null] : ['ad' => 'teslim_alma', 'durum' => 'bekliyor', 'alt' => null]);
    $i = $son(fn($x) => $x['inceleme_sonucu'] !== null);
    $out['adimlar'][] = $i ? ['ad' => 'iade', 'durum' => 'tamam', 'tutanak_id' => (int)$i['id'], 'belge_no' => $i['belge_no'], 'tarih' => $i['tarih'], 'sonuc' => $i['inceleme_sonucu'],
                                      'alt' => $i['inceleme_sonucu'] === 'yapilmadi' ? (trim((string)($i['inceleme_gerekce'] ?? '')) ?: 'gerekçe yazılmadı') : null]
        : ((($adim['depo_inceleme']['sonuc'] ?? null) === 'atlandi') ? ['ad' => 'iade', 'durum' => 'atlandi', 'alt' => $adim['depo_inceleme']['gerekce'] ?? null]
                                                                    : ['ad' => 'iade', 'durum' => 'bekliyor', 'alt' => null]);
    if ($i && $i['inceleme_sonucu'] === 'hasarli') {
        if ($hasar) {
            if (!in_array($hasar['fiyat_durum'], HB_FIYATLI, true)) $out['adimlar'][] = ['ad' => 'hasar', 'durum' => 'bekliyor', 'degerlendirme_id' => (int)$hasar['id'], 'alt' => 'fiyat bekliyor'];
            elseif ($hasar['fiyat_durum'] === 'iptal' && !$hasar['hbb']) $out['adimlar'][] = ['ad' => 'hasar', 'durum' => 'atlandi', 'degerlendirme_id' => (int)$hasar['id'], 'alt' => 'iptal edildi'];
            else $out['adimlar'][] = ['ad' => 'hasar', 'durum' => $hasar['hbb'] ? 'tamam' : 'bekliyor', 'degerlendirme_id' => (int)$hasar['id'], 'alt' => $hasar['hbb'] ? null : 'bildirim bekliyor'];
            return $out;
        }
        $gonderildi = (bool)($servis['tespit_gonderildi'] ?? false);
        $out['adimlar'][] = ['ad' => 'tespit', 'durum' => $gonderildi ? 'tamam' : 'bekliyor', 'tutanak_id' => (int)$i['id'], 'belge_no' => $i['belge_no'], 'alt' => $gonderildi ? null : 'gönderilmedi'];
        if (!$servis) { $out['adimlar'][] = ['ad' => 'servis', 'durum' => 'bekliyor', 'alt' => 'servis kaydı yok']; return $out; }
        $sid = (int)$servis['id'];
        $tamam = ($servis['durum'] ?? '') === 'tamamlandi';
        $out['adimlar'][] = ['ad' => 'servis', 'durum' => $tamam ? 'tamam' : 'bekliyor', 'servis_id' => $sid, 'alt' => $tamam ? null : (!empty($servis['baska_iste']) ? 'makine başka işte' : 'serviste')];
        $fd = (string)($servis['fiyat_durum'] ?? '');
        if ($fd === 'ucretsiz') { $out['adimlar'][] = ['ad' => 'bedel', 'durum' => 'atlandi', 'servis_id' => $sid, 'alt' => 'firma karşıladı']; return $out; }
        $out['adimlar'][] = ['ad' => 'bedel', 'durum' => $fd === 'fiyatlandi' ? 'tamam' : 'bekliyor', 'servis_id' => $sid, 'alt' => $fd === 'fiyatlandi' ? null : 'fiyat bekliyor'];
        if ($fd !== 'fiyatlandi') return $out;
        if (($servis['fatura_yontemi'] ?? '') === 'hakedis') {
            $kod = (string)($servis['hakedis_kod'] ?? '');
            $out['adimlar'][] = ['ad' => 'fatura', 'durum' => !empty($servis['hakedis_faturali']) ? 'tamam' : 'bekliyor', 'servis_id' => $sid, 'hakedis_kod' => $kod ?: null,
                                 'alt' => !empty($servis['hakedis_faturali']) ? null : 'hakedişte' . ($kod !== '' ? ' (' . $kod . ')' : '')];
        } else {
            $out['adimlar'][] = ['ad' => 'fatura', 'durum' => (int)($servis['faturalandi'] ?? 0) === 1 ? 'tamam' : 'bekliyor', 'servis_id' => $sid, 'alt' => (int)($servis['faturalandi'] ?? 0) === 1 ? null : 'servis faturası bekliyor'];
        }
    }
    return $out;
}

function bzZincirListe(PDO $db, array $f): array {
    $sinir = (int)$db->query('SELECT son_eski_kiralama FROM operasyon_gecis WHERE id=1')->fetchColumn();
    $w = ['(EXISTS (SELECT 1 FROM iade_tutanaklari t WHERE t.kiralama_id=k.id) OR (k.id > ' . $sinir . ' AND EXISTS (SELECT 1 FROM operasyon_adimlari a WHERE a.kiralama_id=k.id)))'];
    $p = [];
    if (($kid = (int)($f['kiralama_id'] ?? 0)) > 0) { $w[] = 'k.id=?'; $p[] = $kid; }
    if (($q = trim((string)($f['q'] ?? ''))) !== '') {
        $w[] = '(' . trAramaSql('k.kod') . ' LIKE ? OR ' . trAramaSql('m.unvan') . ' LIKE ? OR ' . trAramaSql('k.ekipman_adi') . ' LIKE ?'
             . " OR (k.makine_tur='manlift' AND EXISTS (SELECT 1 FROM manliftler x WHERE x.id=k.makine_id AND (" . trAramaSql('x.kod') . ' LIKE ? OR ' . trAramaSql('x.makine_kodu') . ' LIKE ?)))'
             . " OR (k.makine_tur='arac' AND EXISTS (SELECT 1 FROM araclar x WHERE x.id=k.makine_id AND (" . trAramaSql('x.plaka') . ' LIKE ? OR ' . trAramaSql('x.makine_kodu') . ' LIKE ?))))';
        $l = '%' . trArama($q) . '%'; array_push($p, $l, $l, $l, $l, $l, $l, $l);
    }
    $dz = (string)($f['durum'] ?? '');
    if ($dz === 'hasarli') $w[] = "EXISTS (SELECT 1 FROM iade_tutanaklari t WHERE t.kiralama_id=k.id AND (t.inceleme_sonucu='hasarli' OR t.genel_durum='hasarli'))";
    elseif ($dz === 'iade_bekleyen') {
        $sonAtlandi = fn(string $adim) => "EXISTS (SELECT 1 FROM operasyon_adimlari a WHERE a.kiralama_id=k.id AND a.adim='$adim' AND a.sonuc='atlandi'
            AND a.id=(SELECT MAX(b.id) FROM operasyon_adimlari b WHERE b.kiralama_id=k.id AND b.adim='$adim'))";
        $w[] = 'k.id > ' . $sinir . ' AND NOT EXISTS (SELECT 1 FROM iade_tutanaklari t WHERE t.kiralama_id=k.id AND t.inceleme_sonucu IS NOT NULL)'
             . " AND (EXISTS (SELECT 1 FROM iade_tutanaklari t WHERE t.kiralama_id=k.id AND t.islem='iade' AND t.inceleme_sonucu IS NULL AND COALESCE(t.fiili_teslim,1)=1) OR "
             . $sonAtlandi('saha_teslim_alma') . ') AND NOT ' . $sonAtlandi('depo_inceleme');
    }
    if (($tb = (string)($f['tarih_bas'] ?? '')) !== '') { $w[] = "COALESCE(k.gercek_bitis, k.bitis, '9999-12-31') >= ?"; $p[] = $tb; }
    if (($te = (string)($f['tarih_bit'] ?? '')) !== '') { $w[] = 'k.baslangic <= ?'; $p[] = $te; }
    subeKosulEkle($w, $p, 'k.sube');
    $s = $db->prepare('SELECT k.id, k.kod, k.ekipman_adi, k.lokasyon, k.baslangic, k.bitis, k.gercek_bitis, k.durum, k.makine_tur, k.makine_id, m.unvan AS musteri_unvan
                       FROM kiralamalar k JOIN musteriler m ON m.id=k.musteri_id WHERE ' . implode(' AND ', $w) . ' ORDER BY k.id DESC LIMIT ' . max(1, min(300, (int)($f['limit'] ?? 300))));
    $s->execute($p);
    $ks = $s->fetchAll(PDO::FETCH_ASSOC);
    if (!$ks) return [];
    $ids = array_map(fn($k) => (int)$k['id'], $ks);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $tq = $db->prepare("SELECT id, kiralama_id, islem, asama, belge_no, tarih, inceleme_sonucu, genel_durum, fiili_teslim, depo_kontrol_id, inceleme_gerekce FROM iade_tutanaklari WHERE kiralama_id IN ($in)");
    $tq->execute($ids);
    $tut = [];
    foreach ($tq->fetchAll(PDO::FETCH_ASSOC) as $t) $tut[(int)$t['kiralama_id']][] = $t;
    $adim = opAdimDurumuToplu($db, $ks);
    $gq = $db->prepare("SELECT kiralama_id, adim, gerekce FROM operasyon_adimlari WHERE sonuc='atlandi' AND kiralama_id IN ($in) ORDER BY id");
    $gq->execute($ids);
    foreach ($gq->fetchAll(PDO::FETCH_ASSOC) as $g) if (isset($adim[(int)$g['kiralama_id']][$g['adim']])) $adim[(int)$g['kiralama_id']][$g['adim']]['gerekce'] = $g['gerekce'];
    $kontrol = [];
    foreach ($tut as $kid => $rows) foreach ($rows as $t) if ($t['inceleme_sonucu'] !== null && $t['depo_kontrol_id']) $kontrol[(int)$t['depo_kontrol_id']] = $kid;
    $hasar = [];
    if ($kontrol) {
        $ci = implode(',', array_fill(0, count($kontrol), '?'));
        $hq = $db->prepare("SELECT h.id, h.depo_kontrol_id, h.fiyat_durum, EXISTS (SELECT 1 FROM hasar_belgeleri b WHERE b.tur='HBB' AND b.kaynak_id=h.id) AS hbb
                            FROM hasar_degerlendirmeleri h WHERE h.depo_kontrol_id IN ($ci) AND COALESCE(h.durum,'')<>'tasindi' ORDER BY h.id");
        $hq->execute(array_keys($kontrol));
        foreach ($hq->fetchAll(PDO::FETCH_ASSOC) as $h) $hasar[$kontrol[(int)$h['depo_kontrol_id']]] = ['id' => (int)$h['id'], 'fiyat_durum' => $h['fiyat_durum'], 'hbb' => (bool)$h['hbb']];
    }
    $servisler = [];
    if ($kontrol) {
        $ci = implode(',', array_fill(0, count($kontrol), '?'));
        $sq = $db->prepare("SELECT s.id, s.donus_kontrol_id, s.durum, s.fiyat_durum, s.fatura_yontemi, s.faturalandi, s.makine_baska_iste, hk.kod AS hakedis_kod, hk.fatura_no AS hk_fatura_no, hk.durum AS hk_durum,
                                   (SELECT COUNT(*) FROM tahsilatlar t WHERE t.hakedis_id=hk.id) AS hk_tahsilat
                            FROM teknik_servis s LEFT JOIN hakedisler hk ON hk.id=s.hakedis_id WHERE s.donus_kontrol_id IN ($ci) ORDER BY s.id");
        $sq->execute(array_keys($kontrol));
        require_once __DIR__ . '/hasar-fiyat-lib.php';
        foreach ($sq->fetchAll(PDO::FETCH_ASSOC) as $s) $servisler[$kontrol[(int)$s['donus_kontrol_id']]] = [
            'id' => (int)$s['id'], 'durum' => $s['durum'], 'fiyat_durum' => $s['fiyat_durum'], 'fatura_yontemi' => $s['fatura_yontemi'], 'faturalandi' => (int)$s['faturalandi'],
            'hakedis_kod' => $s['hakedis_kod'], 'hakedis_faturali' => $s['hakedis_kod'] !== null && hfHakedisFaturali(['durum' => $s['hk_durum'], 'fatura_no' => $s['hk_fatura_no'], 'tahsilat' => $s['hk_tahsilat']]),
            'baska_iste' => (int)$s['makine_baska_iste'] === 1, 'tespit_gonderildi' => false];
        try {
            $gq2 = $db->prepare("SELECT DISTINCT t.depo_kontrol_id FROM tutanak_gonderimleri g JOIN iade_tutanaklari t ON t.id=g.tutanak_id WHERE g.iptal=0 AND g.kanal<>'gorev' AND t.depo_kontrol_id IN ($ci)");
            $gq2->execute(array_keys($kontrol));
            foreach ($gq2->fetchAll(PDO::FETCH_COLUMN) as $dk) if (isset($servisler[$kontrol[(int)$dk]])) $servisler[$kontrol[(int)$dk]]['tespit_gonderildi'] = true;
        } catch (\Throwable $e) { hataGunlugu('belge_zinciri.tespit', $e, true); }
    }
    $bilgi = makineBilgiToplu($db, array_map(fn($k) => ['tur' => $k['makine_tur'], 'id' => (int)$k['makine_id']], $ks));
    $durum = (string)($f['durum'] ?? '');
    $out = [];
    foreach ($ks as $k) {
        $id = (int)$k['id'];
        $kart = ['id' => $id, 'kod' => $k['kod'], 'musteri_unvan' => $k['musteri_unvan'], 'ekipman_adi' => $k['ekipman_adi'], 'lokasyon' => $k['lokasyon'],
                 'baslangic' => $k['baslangic'], 'bitis' => $k['gercek_bitis'] ?: $k['bitis'], 'durum' => $k['durum'],
                 'makine_kod' => $bilgi[$k['makine_tur'] . ':' . (int)$k['makine_id']]['kod'] ?? null];
        $z = bzZincirKur($kart, $tut[$id] ?? [], $adim[$id] ?? [], $hasar[$id] ?? null, $id > $sinir, $servisler[$id] ?? null);
        $a = array_column($z['adimlar'], null, 'ad');
        if ($durum === 'iade_bekleyen' && !(!$z['eski'] && in_array($a['teslim_alma']['durum'] ?? '', ['tamam', 'atlandi'], true) && ($a['iade']['durum'] ?? '') === 'bekliyor')) continue;
        if ($durum === 'hasarli' && !(($a['iade']['sonuc'] ?? null) === 'hasarli' || in_array('hasarli', array_column($tut[$id] ?? [], 'genel_durum'), true))) continue;
        $out[] = $z;
    }
    return $out;
}
