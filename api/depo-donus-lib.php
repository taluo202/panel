<?php
declare(strict_types=1);
require_once __DIR__ . '/depo-isleri-lib.php';
require_once __DIR__ . '/hazirlik-akis.php';
require_once __DIR__ . '/hasar-akis.php';
require_once __DIR__ . '/belge-zinciri-lib.php';
require_once __DIR__ . '/servis-kalem-lib.php';

const DD_DONUS_YONLER = ['donus', 'gidis_donus'];

function _ddAraclar(PDO $db, ?string $siparis, int $kid): array {
    $q = ($siparis ?? '') !== ''
        ? $db->prepare("SELECT * FROM nakliyeler WHERE siparis_no=? AND yon IN ('donus','gidis_donus') ORDER BY id")
        : $db->prepare("SELECT * FROM nakliyeler WHERE kiralama_id=? AND yon IN ('donus','gidis_donus') ORDER BY id");
    $q->execute([($siparis ?? '') !== '' ? $siparis : $kid]);
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $n) {
        $mk = $db->prepare('SELECT kiralama_id FROM nakliye_makineleri WHERE nakliye_id=? AND toplama_zaman IS NOT NULL'); $mk->execute([$n['id']]);
        $ek = $db->prepare('SELECT p.ad_soyad FROM nakliye_ekip e JOIN personel p ON p.id=e.personel_id WHERE e.nakliye_id=?'); $ek->execute([$n['id']]);
        $out[] = ['id' => (int)$n['id'], 'plaka' => $n['plaka'], 'nakliyeci' => $n['nakliyeci'], 'sofor_adi' => $n['sofor_adi'] ?? null,
                  'tip' => $n['tip'] ?? null, 'yon' => $n['yon'], 'dis_sofor_ad' => $n['dis_sofor_ad'] ?? null,
                  'dis_sofor_tel' => $n['dis_sofor_tel'] ?? null,
                  'dis_sofor_donus_ad' => $n['dis_sofor_donus_ad'] ?? null, 'dis_sofor_donus_tel' => $n['dis_sofor_donus_tel'] ?? null,
                  'makineler' => array_map('intval', $mk->fetchAll(PDO::FETCH_COLUMN)), 'ekip' => $ek->fetchAll(PDO::FETCH_COLUMN)];
    }
    return $out;
}

function ddToplamaListe(PDO $db, string $gun, string $siparis, int $kiralamaId, int $benId): array {
    $bugun = date('Y-m-d');
    [$bas, $bit] = diGunAraligi($gun, $bugun);
    $portal = "(SELECT MIN(pt.onaylanan_tarih) FROM portal_kiralama_talepleri pt WHERE pt.kiralama_id=k.id AND pt.tur='iade' AND pt.durum='onaylandi')";
    $tarih = "COALESCE($portal, k.bitis, '9999-12-31')";
    $w = ["k.durum='aktif'", 'k.gercek_bitis IS NULL', 'k.makine_id IS NOT NULL', 'k.id > (SELECT son_eski_kiralama FROM operasyon_gecis WHERE id=1)'];
    $p = [];
    if ($kiralamaId > 0) {
        $w[] = "(k.id=? OR (COALESCE(k.siparis_no,'')<>'' AND k.siparis_no=(SELECT siparis_no FROM kiralamalar WHERE id=?)))";
        array_push($p, $kiralamaId, $kiralamaId);
    } elseif ($siparis !== '') { $w[] = 'k.siparis_no=?'; $p[] = $siparis; }
    else { $w[] = "$tarih BETWEEN ? AND ?"; array_push($p, $bas, $bit); }
    subeKosulEkle($w, $p, 'k.sube');
    $s = $db->prepare("SELECT k.id, k.kod, k.siparis_no, k.musteri_id, k.lokasyon, k.baslangic, k.bitis, k.ekipman_adi, k.makine_tur, k.makine_id,
                              k.durum, k.gercek_bitis, k.sube, m.unvan AS musteri_unvan, $portal AS portal_toplama
                       FROM kiralamalar k JOIN musteriler m ON m.id=k.musteri_id
                       WHERE " . implode(' AND ', $w) . " ORDER BY $tarih, k.siparis_no, k.id");
    $s->execute($p);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    $sira = opSiradakiToplu($db, $rows);
    $bilgi = makineBilgiToplu($db, array_map(fn($k) => ['tur' => $k['makine_tur'], 'id' => (int)$k['makine_id']], $rows));
    $atama = []; $plan = [];
    if ($ids = array_map(fn($k) => (int)$k['id'], $rows)) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $q = $db->prepare("SELECT * FROM depo_atamalari WHERE adim='toplaniyor' AND kiralama_id IN ($in)"); $q->execute($ids);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $a) $atama[(int)$a['kiralama_id']] = $a;
        $q = $db->prepare("SELECT nm.kiralama_id, nm.nakliye_id FROM nakliye_makineleri nm JOIN nakliyeler n ON n.id=nm.nakliye_id
                           WHERE nm.kiralama_id IN ($in) AND n.yon IN ('donus','gidis_donus') ORDER BY nm.nakliye_id"); $q->execute($ids);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $plan[(int)$r['kiralama_id']] ??= (int)$r['nakliye_id'];
    }
    $kisi = fn($u) => $u ? ['id' => (int)$u, 'ad' => _diKullaniciAd($db, (int)$u)] : null;
    $gruplar = [];
    foreach ($rows as $k) {
        $id = (int)$k['id']; $sn = $sira[$id] ?? null;
        if (!in_array($sn, ['toplaniyor', 'saha_teslim_alma'], true)) continue;
        $gk = ($k['siparis_no'] ?? '') !== '' ? (string)$k['siparis_no'] : 'K' . $id;
        $b = $bilgi[$k['makine_tur'] . ':' . (int)$k['makine_id']] ?? [];
        $gruplar[$gk] ??= ['gk' => $gk, 'siparis_no' => $k['siparis_no'], 'kod' => $k['kod'], 'musteri_unvan' => $k['musteri_unvan'],
                           'lokasyon' => $k['lokasyon'], 'bitis' => $k['bitis'], 'makineler' => [],
                           'araclar' => _ddAraclar($db, $k['siparis_no'], $id)];
        $gruplar[$gk]['makineler'][] = [
            'kiralama_id' => $id, 'kod' => $k['kod'], 'makine_kod' => $b['kod'] ?? null,
            'marka_model' => trim(($b['marka'] ?? '') . ' ' . ($b['model'] ?? '')), 'metraj' => $b['metraj'] ?? null,
            'kategori' => $b['kategori'] ?? null, 'olcu_birim' => $b['olcu_birim'] ?? null, 'gorsel' => refKartGorsel($db, $b['marka'] ?? null, $b['model'] ?? null, $b['kategori'] ?? null),
            'ekipman_adi' => $k['ekipman_adi'], 'bitis' => $k['bitis'],
            'toplama_tarihi' => $k['portal_toplama'] ?: $k['bitis'], 'portal_plan' => !empty($k['portal_toplama']),
            'siradaki' => $sn, 'toplandi' => $sn === 'saha_teslim_alma',
            'atanan' => $kisi($atama[$id]['atanan_kullanici_id'] ?? null), 'ustlenen' => $kisi($atama[$id]['ustlenen_kullanici_id'] ?? null),
            'nakliye_planli' => $plan[$id] ?? null,
        ] + diKunyeAlanlari($b, $k);
    }
    return ['gun' => $gun, 'bugun' => $bugun, 'ben' => $benId, 'gruplar' => array_values($gruplar),
            'kisiler' => _diKisiler($db), 'personeller' => _diPersoneller($db)];
}

function ddTopla(PDO $db, array $b): array {
    islemKilitAl($db);
    $ids = _diIdler($b['kiralama_ids'] ?? []);
    $nid = (int)($b['nakliye_id'] ?? 0);
    $nak = null;
    if ($nid > 0) {
        $s = $db->prepare('SELECT * FROM nakliyeler WHERE id=?'); $s->execute([$nid]);
        $nak = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$nak) throw new DomainException('Nakliye aracı bulunamadı.');
        subeErisimDogrula($nak['sube'] ?? null);
        if (!in_array($nak['yon'] ?? 'gidis', DD_DONUS_YONLER, true)) throw new DomainException('Seçilen araç bir dönüş aracı değil.');
    }
    [$ekip, $dis] = _diEkipDis($db, $b, $nak, $nid);
    $yapan = opOturumYapan($db);
    $plan = []; $onceden = 0;
    $zaten = $db->prepare('SELECT 1 FROM nakliye_makineleri WHERE nakliye_id=? AND kiralama_id=? AND toplama_zaman IS NOT NULL');
    foreach ($ids as $kid) {
        $k = _diKiralama($db, $kid);
        $kod = $k['kod'] ?: '#' . $kid;
        $sira = opSiradakiAdim($db, $k);
        if ($sira === 'saha_teslim_alma' && $nid > 0) {
            $zaten->execute([$nid, $kid]);
            if ($zaten->fetchColumn()) { $onceden++; continue; }
        }
        if ($sira !== 'toplaniyor') throw new DomainException($kod . ': toplama sırası değil (sıradaki: ' . ($sira ? OP_ADIMLAR[$sira] : 'yok') . ').');
        if ($nak) {
            $sp = (string)($k['siparis_no'] ?? '');
            $pl = $db->prepare('SELECT 1 FROM nakliye_makineleri WHERE nakliye_id=? AND kiralama_id=?'); $pl->execute([$nid, $kid]);
            $uygun = $sp !== '' ? ($nak['siparis_no'] ?? '') === $sp : ((int)($nak['kiralama_id'] ?? 0) === $kid || (bool)$pl->fetchColumn());
            if (!$uygun) throw new DomainException($kod . ': seçilen araç bu kiralamanın/siparişin aracı değil.');
        }
        $plan[] = $k;
    }
    if (!$plan) return ['toplanan' => 0, 'tekrar' => true, 'onceden' => $onceden, 'kayitlar' => [], 'nakliye' => $nak, 'dis_sofor' => null, 'baglanti' => null];
    foreach ($plan as $k) {
        $kid = (int)$k['id'];
        if ($nid > 0) {
            $db->prepare('INSERT OR IGNORE INTO nakliye_makineleri(nakliye_id,kiralama_id) VALUES(?,?)')->execute([$nid, $kid]);
            $db->prepare("UPDATE nakliye_makineleri SET toplama_zaman=?, toplayan=? WHERE nakliye_id=? AND kiralama_id=?")
               ->execute([date('Y-m-d H:i:s'), $yapan['ad'], $nid, $kid]);
        }
        if (operasyonKiralamaGuncelMi($db, $k)) kiralamaMakineDurumu($db, (string)$k['makine_tur'], (int)$k['makine_id'], 'toplaniyor');
        opAdimYaz($db, $kid, 'toplaniyor', 'tamam', $yapan, 'yetki', null, ['nakliye_id' => $nid ?: null]);
    }
    foreach ($ekip as $pid) {
        $e = $db->prepare('INSERT OR IGNORE INTO nakliye_ekip(nakliye_id,personel_id) VALUES(?,?)'); $e->execute([$nid, $pid]);
        if ($e->rowCount()) logla($db, 'nakliyeler', $nid, 'guncelle', null, ['ekip_personel' => $pid], 'Toplama ekibine personel eklendi');
    }
    $baglanti = $dis !== null ? _diBaglantiUret($db, $nid, $nak, $dis, 'donus', $yapan) : null;
    return ['toplanan' => count($plan), 'tekrar' => false, 'onceden' => $onceden, 'kayitlar' => $plan, 'nakliye' => $nak, 'dis_sofor' => $dis, 'baglanti' => $baglanti];
}

const DD_BULGU_TURLERI = BZ_BULGU_AD;
const DD_INCELEME_MESAJ = [
    'taslak'   => [1 => 'Taslak kaydedildi.'],
    'sorunsuz' => ['yeni_is' => 'İnceleme kaydedildi. Makine durumu değişmedi: makine bu işten sonra başka bir işe çıktı; durumunu o iş belirler.',
                   'durum'   => 'İnceleme kaydedildi. Makine durumu değişmedi: makine dönüş kontrolü beklemiyordu.',
                   1 => 'İnceleme tamam; makine müsait.'],
    'sorunlu'  => ['yeni_is' => 'Servis kaydı açıldı. Makine bu işten sonra başka bir işe çıktı; geri dönünce serviste başlar.',
                   'durum'   => 'Servis kaydı açıldı. Makine dönüş kontrolü beklemiyordu; durumu servis kaydından izlenir.',
                   1 => 'Servis kaydı açıldı; makine serviste.',
                   'donmus' => 'Servis kaydı açıldı. Makine bu işten sonra başka bir işe çıkıp döndü; servis şimdi başlar.',
                   'eksik' => 'Eksik parça kaydı açıldı (Fiyatlandırılacaklar); makine müsait.'],
];
const DD_ACIK_KIRALAMA = 'kiralama hâlâ açık (teslim alma imzasız işaretlendi, kira kapanmadı); inceleme kaydedilmedi. '
    . 'Önce kiralamayı iade tarihiyle «Tamamlandı» yapın ya da «Sahada teslim alma» adımını geri alıp teslim almayı Sahada Teslim ekranından imzalı kaydedin.';

function ddKiralamaAcik(array $k): bool {
    return ($k['durum'] ?? '') === 'aktif' && empty($k['gercek_bitis']);
}

function ddBeklemeGun(string $gelis, string $bugun): int {
    return max(0, (int)floor((strtotime($bugun) - strtotime(substr($gelis, 0, 10))) / 86400));
}

function ddIncelemeListe(PDO $db, int $benId): array {
    $w = ['k.id > (SELECT son_eski_kiralama FROM operasyon_gecis WHERE id=1)', 'k.makine_id IS NOT NULL', "k.durum<>'iptal'",
          "k.id IN (SELECT kiralama_id FROM operasyon_adimlari WHERE adim='saha_teslim_alma')"];
    $p = [];
    subeKosulEkle($w, $p, 'k.sube');
    $s = $db->prepare("SELECT k.id, k.kod, k.lokasyon, k.ekipman_adi, k.makine_tur, k.makine_id, k.durum, k.gercek_bitis, k.sube, m.unvan AS musteri_unvan,
                              (SELECT MAX(zaman) FROM operasyon_adimlari a WHERE a.kiralama_id=k.id AND a.adim='saha_teslim_alma') AS gelis
                       FROM kiralamalar k JOIN musteriler m ON m.id=k.musteri_id WHERE " . implode(' AND ', $w) . " ORDER BY gelis, k.id LIMIT 300");
    $s->execute($p);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    $sira = opSiradakiToplu($db, $rows);
    $rows = array_values(array_filter($rows, fn($k) => ($sira[(int)$k['id']] ?? null) === 'depo_inceleme'));
    $bilgi = makineBilgiToplu($db, array_map(fn($k) => ['tur' => $k['makine_tur'], 'id' => (int)$k['makine_id']], $rows));
    $bugun = date('Y-m-d');
    $kisi = fn($u) => $u ? ['id' => (int)$u, 'ad' => _diKullaniciAd($db, (int)$u)] : null;
    $t = $db->prepare("SELECT durum FROM depo_kontrolleri WHERE kiralama_id=? AND yon='donus'");
    $out = [];
    foreach ($rows as $k) {
        $id = (int)$k['id'];
        $a = _diAtama($db, $id, 'depo_inceleme');
        $t->execute([$id]);
        $b = $bilgi[$k['makine_tur'] . ':' . (int)$k['makine_id']] ?? [];
        $out[] = ['kiralama_id' => $id, 'kod' => $k['kod'], 'makine_kod' => $b['kod'] ?? null,
                  'marka_model' => trim(($b['marka'] ?? '') . ' ' . ($b['model'] ?? '')), 'metraj' => $b['metraj'] ?? null,
                  'kategori' => $b['kategori'] ?? null, 'olcu_birim' => $b['olcu_birim'] ?? null, 'gorsel' => refKartGorsel($db, $b['marka'] ?? null, $b['model'] ?? null, $b['kategori'] ?? null),
                  'ekipman_adi' => $k['ekipman_adi'], 'musteri_unvan' => $k['musteri_unvan'],
                  'lokasyon' => $k['lokasyon'], 'gelis' => $k['gelis'], 'bekleme_gun' => ddBeklemeGun((string)$k['gelis'], $bugun),
                  'taslak' => $t->fetchColumn() === 'taslak',
                  'kiralama_acik' => ddKiralamaAcik($k),
                  'atanan' => $kisi($a['atanan_kullanici_id'] ?? null), 'ustlenen' => $kisi($a['ustlenen_kullanici_id'] ?? null)]
                 + diKunyeAlanlari($b, $k);
    }
    return ['bugun' => $bugun, 'ben' => $benId, 'makineler' => $out, 'kisiler' => _diKisiler($db)];
}

function ddGidisSayac(PDO $db, array $k): ?float {
    $alan = hzSayacAlani((string)$k['makine_tur']);
    $s = $db->prepare("SELECT $alan FROM iade_tutanaklari WHERE kiralama_id=? AND islem='teslim' AND COALESCE(fiili_teslim,1)=1 ORDER BY tarih DESC, id DESC LIMIT 1");
    $s->execute([(int)$k['id']]);
    $v = $s->fetchColumn();
    if ($v !== false && $v !== null && is_numeric($v)) return (float)$v;
    $h = hzGet($db, (int)$k['id'], 'gidis');
    return is_numeric($h['veri']['sayac'] ?? null) ? (float)$h['veri']['sayac'] : null;
}

function ddGidisBilesen(PDO $db, array $k): ?array {
    $s = $db->prepare("SELECT bilesen_hasar FROM iade_tutanaklari WHERE kiralama_id=? AND islem='teslim' AND COALESCE(fiili_teslim,1)=1 ORDER BY tarih DESC, id DESC LIMIT 1");
    $s->execute([(int)$k['id']]);
    $t = json_decode((string)$s->fetchColumn(), true);
    if (is_array($t) && $t) return bzBilesenler(array_filter($t, fn($d) => is_string($d) && isset(BZ_BILESEN_DURUM[$d])));
    if ((opAdimDurumu($db, (int)$k['id'])['depo_hazirlik']['sonuc'] ?? null) !== 'tamam') return null;
    $h = hzGet($db, (int)$k['id'], 'gidis');
    return is_array($h['veri']['bilesenler'] ?? null) ? bzBilesenler($h['veri']['bilesenler']) : null;
}

function ddTeslimAlmaOzet(PDO $db, int $kid): ?array {
    $t = bzSahaAlim($db, $kid);
    if (!$t) return null;
    $mt = $db->prepare('SELECT makine_tur FROM kiralamalar WHERE id=?');
    $mt->execute([$kid]);
    $t['_makine_tur'] = (string)$mt->fetchColumn();
    $f = $db->prepare('SELECT id, aci_tipi, dosya_yolu FROM iade_tutanak_fotograflari WHERE tutanak_id=? ORDER BY id');
    $f->execute([(int)$t['id']]);
    return ['tutanak_id' => (int)$t['id'], 'belge_no' => $t['belge_no'] ?? null, 'tarih' => $t['tarih'], 'genel_durum' => $t['genel_durum'] ?? null,
            'hasar_notu' => $t['hasar_notu'] ?? null, 'genel_not' => $t['genel_not'] ?? null, 'musteri_temsilci_ad' => $t['musteri_temsilci_ad'] ?? null,
            'sayac' => $t[hzSayacAlani((string)$t['_makine_tur'])] ?? null, 'fotograflar' => $f->fetchAll(PDO::FETCH_ASSOC)];
}

function ddAksesuarRef(PDO $db, int $kid): array {
    $r = hzReferans($db, $kid, 'iade');
    return $r['anahtar'] !== 'yok' ? $r : hzReferans($db, $kid, 'teslim');
}

function ddIncelemeVeri(PDO $db, int $kid): array {
    $k = _diKiralama($db, $kid);
    $gidis = hzGet($db, $kid, 'gidis');
    $donus = hzGet($db, $kid, 'donus');
    $f = $db->prepare("SELECT id, aci_tipi, aciklama, dosya_yolu, created_at FROM kiralama_fotograflari WHERE kiralama_id=? AND COALESCE(yon,'gidis')=? ORDER BY id");
    $f->execute([$kid, 'gidis']); $fg = $f->fetchAll(PDO::FETCH_ASSOC);
    $f->execute([$kid, 'donus']); $fd = $f->fetchAll(PDO::FETCH_ASSOC);
    $b = makineBilgiToplu($db, [['tur' => $k['makine_tur'], 'id' => (int)$k['makine_id']]])[$k['makine_tur'] . ':' . (int)$k['makine_id']] ?? [];
    return [
        'kiralama' => ['id' => (int)$k['id'], 'kod' => $k['kod'], 'ekipman_adi' => $k['ekipman_adi'], 'makine_tur' => $k['makine_tur'],
                       'makine_id' => (int)$k['makine_id'], 'musteri_unvan' => $k['musteri_unvan'], 'lokasyon' => $k['lokasyon'],
                       'makine_kod' => $b['kod'] ?? null, 'marka_model' => trim(($b['marka'] ?? '') . ' ' . ($b['model'] ?? ''))],
        'sablonlar' => hzSablonlar(),
        'sablon' => $donus['veri']['sablon'] ?? ($gidis['veri']['sablon'] ?? hzSablonTahmin($b['kategori'] ?? null, $k['ekipman_adi'] ?? null, (string)$k['makine_tur'])),
        'zorunlu_acilar' => hzZorunluAcilar((string)$k['makine_tur']), 'sayac_alani' => hzSayacAlani((string)$k['makine_tur']), 'sayac_zorunlu' => hzSayacZorunlu((string)$k['makine_tur']),
        'fotograflar_gidis' => $fg, 'fotograflar_donus' => $fd,
        'teslim_tutanak' => ($tt = $db->query("SELECT id, belge_no FROM iade_tutanaklari WHERE kiralama_id=" . (int)$kid . " AND islem='teslim' AND COALESCE(fiili_teslim,1)=1 ORDER BY tarih DESC, id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC)) ? ['id' => (int)$tt['id'], 'belge_no' => $tt['belge_no']] : null,
        'sayac_gidis' => ddGidisSayac($db, $k), 'saha_teslim_alma' => ddTeslimAlmaOzet($db, $kid),
        'aksesuar_referans' => ddAksesuarRef($db, $kid), 'inceleme' => $donus, 'bulgu_turleri' => DD_BULGU_TURLERI,
        'tamamlandi' => opTekKayitEngeli($db, $k, 'depo_inceleme'),
        'kiralama_acik' => ddKiralamaAcik($k), 'acik_uyari' => ddKiralamaAcik($k) ? ($k['kod'] ?: '#' . $kid) . ': ' . DD_ACIK_KIRALAMA : null,
        'teslim_alma_adim' => ($ta = $db->query("SELECT sonuc, kaynak, gerekce FROM operasyon_adimlari WHERE kiralama_id=" . (int)$kid
                                                . " AND adim='saha_teslim_alma' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC)) ? $ta : null,
        'referans' => refBilgi($db, (string)$k['makine_tur'], (int)$k['makine_id']),
        'bilesen_gidis' => ddGidisBilesen($db, $k), 'bilesen_tanim' => bzBilesenTanim(),
    ];
}

function ddBulgular(PDO $db, int $kid, $rows, bool $tam = true): array {
    if (!is_array($rows) || count($rows) > 30) throw new DomainException('En fazla 30 bulgu satırı girin.');
    $fs = $db->prepare("SELECT 1 FROM kiralama_fotograflari WHERE id=? AND kiralama_id=? AND yon='donus'");
    $out = [];
    foreach (array_values($rows) as $i => $r) {
        $n = ($i + 1) . '. bulgu: ';
        $tur = is_array($r) ? (string)($r['tur'] ?? '') : '';
        $ac = is_array($r) ? trim((string)($r['aciklama'] ?? '')) : '';
        $fotolar = array_values(array_unique(array_filter(array_map('intval', is_array($r['foto_ids'] ?? null) ? $r['foto_ids'] : []))));
        $bil = is_array($r) ? trim((string)($r['bilesen'] ?? '')) : '';
        if (!$tam) {
            if ($tur === '' && $ac === '' && !$fotolar) continue;
            if ($tur !== '' && !isset(DD_BULGU_TURLERI[$tur])) throw new DomainException($n . 'türünü seçin (hasar, eksik, arıza).');
            if (count($fotolar) > 20) throw new DomainException($n . 'en fazla 20 fotoğraf.');
            foreach ($fotolar as $fid) { $fs->execute([$fid, $kid]); if (!$fs->fetchColumn()) throw new DomainException($n . 'fotoğraf bu makinenin dönüş fotoğrafı değil.'); }
            if ($bil !== '' && $bil !== 'diger' && !isset(BZ_BILESEN_AD[$bil])) throw new DomainException($n . 'bileşen geçersiz.');
            $out[] = ['tur' => $tur, 'aciklama' => mb_substr($ac, 0, 1000), 'foto_ids' => $fotolar, 'bilesen' => $bil];
            continue;
        }
        if (!isset(DD_BULGU_TURLERI[$tur])) throw new DomainException($n . 'türünü seçin (hasar, eksik, arıza).');
        if (mb_strlen($ac) < 3) throw new DomainException($n . 'açıklama yazın.');
        if (!$fotolar) throw new DomainException($n . 'en az bir fotoğraf ekleyin.');
        if (count($fotolar) > 20) throw new DomainException($n . 'en fazla 20 fotoğraf.');
        foreach ($fotolar as $fid) { $fs->execute([$fid, $kid]); if (!$fs->fetchColumn()) throw new DomainException($n . 'fotoğraf bu makinenin dönüş fotoğrafı değil.'); }
        if ($bil !== '' && $bil !== 'diger' && !isset(BZ_BILESEN_AD[$bil])) throw new DomainException($n . 'bileşen geçersiz.');
        $out[] = ['tur' => $tur, 'aciklama' => mb_substr($ac, 0, 1000), 'foto_ids' => $fotolar, 'bilesen' => $bil === '' ? 'diger' : $bil];
    }
    return $out;
}

function ddVeriDogrula(PDO $db, array $k, array $ham, string $islem, array $ref): array {
    $v = array_intersect_key(hzVeri($ham), array_flip(['sablon', 'sablon_ad', 'sablon_surumu', 'kontroller', 'sayac', 'aksesuarlar']));
    $v['bulgular'] = ddBulgular($db, (int)$k['id'], $ham['bulgular'] ?? [], $islem === 'sorunlu');
    $v['bilesen_gidis'] = ddGidisBilesen($db, $k);
    $v['bilesenler'] = bzBilesenler($ham['bilesenler'] ?? null, $v['bilesen_gidis']);
    $imza = (string)($ham['imza'] ?? '');
    if ($imza !== '' && (!preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $imza) || strlen($imza) > 300000)) throw new DomainException('İmza okunamadı. Yeniden imzalayın.');
    $v['imza'] = $imza === '' ? null : ['veri' => $imza, 'ad' => opOturumYapan($db)['ad'], 'zaman' => date('Y-m-d H:i:s')];
    $v['aksesuar_referans'] = ['anahtar' => $ref['anahtar'], 'kaynak' => $ref['kaynak'] ?? null];
    $v['aksesuar_farklar'] = array_values(array_filter(hzFarklar($ref['kalemler'], $v['aksesuarlar']),
        fn($r) => $r['fark'] !== 0 || $r['durum'] !== 'saglam' || !$r['kimlik_uyum']));
    $v['sayac_gidis'] = ddGidisSayac($db, $k);
    if ($islem === 'taslak') return $v;
    foreach ($v['kontroller'] as $r) if ($r['sonuc'] === 'bekliyor') throw new DomainException($r['ad'] . ': kontrol tamamlanmadı.');
    if ($v['sayac'] === '' && hzSayacZorunlu((string)($k['makine_tur'] ?? ''))) throw new DomainException('Kilometre sayacını girin.');
    $say = array_column($v['aksesuarlar'], null, 'kod');
    foreach ($ref['kalemler'] as $r) {
        if (!isset($say[$r['kod']])) throw new DomainException('Verilen her aksesuarı sayın; yoksa 0 yazın.');
        if ($say[$r['kod']]['adet'] !== $r['adet'] && trim((string)$say[$r['kod']]['not']) === '') throw new DomainException($r['ad'] . ': adet farkının açıklaması gerekli.');
    }
    $var = [];
    $q = $db->prepare("SELECT aci_tipi FROM kiralama_fotograflari WHERE kiralama_id=? AND yon='donus'");
    $q->execute([(int)$k['id']]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $a) $var[hzAciAnahtar((string)$a)] = true;
    $eksik = array_values(array_filter(hzZorunluAcilar((string)$k['makine_tur']), fn($a) => !isset($var[hzAciAnahtar($a)])));
    if ($eksik) throw new DomainException('Eksik zorunlu fotoğraf: ' . implode(', ', $eksik) . '.');
    if (!$v['imza']) throw new DomainException('İncelemeyi yapan ekranda imzalamalı.');
    if ($islem === 'sorunsuz') {
        foreach ($v['kontroller'] as $r) if ($r['sonuc'] === 'uygun_degil') throw new DomainException($r['ad'] . ' uygun değil; "Sorun var, servise gönder" seçin.');
        if ($v['aksesuar_farklar']) throw new DomainException('Aksesuar farkı var; "Sorun var, servise gönder" seçin.');
        if ($v['sayac_gidis'] !== null && $v['sayac'] !== '' && (float)$v['sayac'] < $v['sayac_gidis'])
            throw new DomainException('Sayaç gidişten düşük (gidiş ' . $v['sayac_gidis'] . ', dönüş ' . $v['sayac'] . '); "Sorun var" seçin.');
        if ($v['bulgular']) throw new DomainException('Bulgu yazılmış; "Sorun var" seçin ya da bulguları silin.');
    } elseif (!$v['bulgular']) {
        throw new DomainException('Sorun var: en az bir bulgu satırı (tür, açıklama, fotoğraf) girin.');
    }
    if ($h = bzBilesenHatasi($v['bilesen_gidis'], $v['bilesenler'], $v['bulgular'], $islem)) throw new DomainException($h);
    return $v;
}

function _ddEtkiEngeli(PDO $db, array $k): ?string {
    $md = $db->prepare('SELECT durum FROM ' . ($k['makine_tur'] === 'arac' ? 'araclar' : 'manliftler') . ' WHERE id=?');
    $md->execute([(int)$k['makine_id']]);
    if ($md->fetchColumn() !== 'inceleme_bekliyor') return 'durum';
    return opSonDonenKiralamaMi($db, $k) || operasyonKiralamaGuncelMi($db, $k, true) ? null : 'yeni_is';
}
function _ddMakineEtkisi(PDO $db, array $k): bool {
    return _ddEtkiEngeli($db, $k) === null;
}

function ddIncelemeKaydet(PDO $db, array $b): array {
    islemKilitAl($db);
    $kid = (int)($b['kiralama_id'] ?? 0);
    $islem = (string)($b['islem'] ?? '');
    if (!in_array($islem, ['taslak', 'sorunsuz', 'sorunlu'], true)) throw new DomainException('Geçersiz inceleme işlemi.');
    $k = _diKiralama($db, $kid);
    $kod = $k['kod'] ?: '#' . $kid;
    if ($eng = opTekKayitEngeli($db, $k, 'depo_inceleme')) throw new DomainException($kod . ': ' . $eng);
    if (opSiradakiAdim($db, $k) !== 'depo_inceleme') throw new DomainException($kod . ': inceleme sırası değil (makine henüz teslim alınmadı).');
    if (ddKiralamaAcik($k)) throw new DomainException($kod . ': ' . DD_ACIK_KIRALAMA);
    $eski = hzGet($db, $kid, 'donus');
    $n = (int)($eski['surum'] ?? 0);
    if ((int)($b['temel_surum'] ?? -1) !== $n) throw new DomainException('İnceleme başka biri tarafından değiştirildi. Paneli yeniden açın.');
    $v = ddVeriDogrula($db, $k, is_array($b['veri'] ?? null) ? $b['veri'] : [], $islem, ddAksesuarRef($db, $kid));
    $v['saha_teslim_alma'] = ddTeslimAlmaOzet($db, $kid);
    $v['sonuc'] = $islem === 'taslak' ? null : $islem;
    $yapan = opOturumYapan($db);
    $simdi = date('Y-m-d H:i:s');
    $json = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    $db->prepare("INSERT INTO depo_kontrolleri(kiralama_id,yon,surum,durum,makine_bag,veri,makine_tur,makine_id,yapan,created_at,updated_at)
                  VALUES(?,'donus',?,?,?,?,?,?,?,?,?) ON CONFLICT(kiralama_id,yon) DO UPDATE SET
                  surum=excluded.surum,durum=excluded.durum,makine_bag=excluded.makine_bag,veri=excluded.veri,
                  makine_tur=excluded.makine_tur,makine_id=excluded.makine_id,yapan=excluded.yapan,updated_at=excluded.updated_at")
       ->execute([$kid, $n + 1, $islem, hzBag($k), $json, $k['makine_tur'], (int)$k['makine_id'], $yapan['ad'], $simdi, $simdi]);
    $q = $db->prepare("SELECT id FROM depo_kontrolleri WHERE kiralama_id=? AND yon='donus'"); $q->execute([$kid]);
    $kontrolId = (int)$q->fetchColumn();
    $engel = $islem === 'taslak' ? null : _ddEtkiEngeli($db, $k);
    $etki = $islem !== 'taslak' && $engel === null;
    $et = DD_INCELEME_MESAJ[$islem][$engel ?? 1];
    dkGecmisYaz($db, $kid, 'donus', $n + 1, $islem, 'Dönüş kontrolü: ' . $et, $v);
    logla($db, 'depo_kontrolleri', $kontrolId, $n === 0 ? 'ekle' : 'guncelle', null, ['yon' => 'donus', 'durum' => $islem, 'surum' => $n + 1],
          'Dönüş kontrolü ' . $et . ' — ' . $kod);
    $out = ['kontrol_id' => $kontrolId, 'surum' => $n + 1, 'sonuc' => $islem, 'servis_id' => null, 'servis_tip' => null, 'eksik_kalem' => 0,
            'gorev_id' => null, 'makine_etkisi' => $etki, 'mesaj' => $et];
    if ($islem === 'taslak') return $out;
    opAdimYaz($db, $kid, 'depo_inceleme', 'tamam', $yapan, 'yetki', null, ['kontrol_id' => $kontrolId, 'sonuc' => $islem]);
    if ($islem === 'sorunsuz') {
        if ($etki) kiralamaMakineDurumu($db, (string)$k['makine_tur'], (int)$k['makine_id'], 'inceleme_bitti');
        return _ddBelge($db, $out, $kid);
    }
    $sv = ddServisAc($db, $k, $kontrolId, $v);
    $out['servis_id'] = $sv['servis_id']; $out['servis_tip'] = $sv['tip']; $out['eksik_kalem'] = $sv['eksik']; $out['gorev_id'] = $sv['gorev_id'];
    if ($engel === 'yeni_is' && !$sv['baska_iste']) $out['mesaj'] = DD_INCELEME_MESAJ['sorunlu']['donmus'];
    if ($sv['tip'] === 'eksik_parca') {
        $out['mesaj'] = DD_INCELEME_MESAJ['sorunlu']['eksik'];
        if ($etki) kiralamaMakineDurumu($db, (string)$k['makine_tur'], (int)$k['makine_id'], 'inceleme_bitti');
    }
    return _ddBelge($db, $out, $kid);
}

function ddEksikler(array $v): array {
    $out = [];
    foreach ($v['aksesuar_farklar'] ?? [] as $f) {
        if (is_int($f['fark'] ?? null) && $f['fark'] > 0) $out[] = ['ad' => (string)$f['ad'], 'adet' => (float)$f['fark'], 'bulgu_index' => null];
    }
    if ($out) return $out;
    foreach (array_values($v['bulgular'] ?? []) as $i => $x) if (($x['tur'] ?? '') === 'eksik') $out[] = ['ad' => (string)$x['aciklama'], 'adet' => 1.0, 'bulgu_index' => $i];
    return $out;
}

function ddYalnizEksik(array $v): bool {
    $turler = array_values(array_unique(array_column($v['bulgular'] ?? [], 'tur')));
    if ($turler !== ['eksik']) return false;
    foreach ($v['kontroller'] ?? [] as $r) if (($r['sonuc'] ?? '') === 'uygun_degil') return false;
    return !bzBilesenKotulesen($v['bilesen_gidis'] ?? null, $v['bilesenler'] ?? []);
}

function _ddBelge(PDO $db, array $out, int $kid): array {
    $t = bzIadeOlustur($db, $kid, $out['sonuc'] === 'sorunsuz' ? 'sorunsuz' : 'hasarli', (int)$out['kontrol_id'], null);
    $out['belge_no'] = $t['belge_no'];
    $out['tutanak_id'] = $t['id'];
    return $out;
}

function ddServisAc(PDO $db, array $k, int $kontrolId, array $v): array {
    $tur = (string)$k['makine_tur']; $mid = (int)$k['makine_id']; $kid = (int)$k['id']; $kod = $k['kod'] ?: '#' . $kid;
    $metin = 'Dönüş kontrolü (' . $kod . ') bulguları:' . "\n• " . implode("\n• ", array_map(fn($x) => DD_BULGU_TURLERI[$x['tur']] . ': ' . $x['aciklama'], $v['bulgular']));
    $simdi = date('Y-m-d H:i:s');
    $yalnizEksik = ddYalnizEksik($v);
    $engel = _ddEtkiEngeli($db, $k);
    $baskaIste = !$yalnizEksik && $engel !== null && makineKiradaMi($db, $tur, $mid);
    $acik = null;
    if (!$yalnizEksik) {
        $q = $db->prepare("SELECT id FROM teknik_servis WHERE makine_tur=? AND makine_id=? AND kiralama_id=? AND durum IN ('acik','devam') ORDER BY id DESC LIMIT 1");
        $q->execute([$tur, $mid, $kid]);
        $acik = $q->fetchColumn() ?: null;
    }
    if ($acik) {
        $sid = (int)$acik;
        $db->prepare("UPDATE teknik_servis SET notlar=TRIM(COALESCE(notlar,'') || char(10) || ?), donus_kontrol_id=COALESCE(donus_kontrol_id,?), musteriye_yansit=1, updated_at=? WHERE id=?")
           ->execute(['[' . date('d.m.Y H:i') . '] ' . $metin, $kontrolId, $simdi, $sid]);
        logla($db, 'teknik_servis', $sid, 'guncelle', null, ['donus_kontrol_id' => $kontrolId], 'Dönüş kontrolü bulguları açık servise eklendi — ' . $kod);
    } else {
        $tip = $yalnizEksik ? 'eksik_parca' : 'ariza';
        $db->prepare("INSERT INTO teknik_servis(makine_tur,makine_id,makine_ad,tip,tarih,durum,oncelik,ariza,kiralama_id,kaynak,musteriye_yansit,maliyet_kaynak,donus_kontrol_id,makine_baska_iste,tamamlanma,created_at,updated_at)
                      VALUES(?,?,?,?,?,?,'normal',?,?,'depo_inceleme',1,'kalem',?,?,?,?,?)")
           ->execute([$tur, $mid, $k['ekipman_adi'], $tip, date('Y-m-d'), $yalnizEksik ? 'tamamlandi' : 'acik', $metin, $kid, $kontrolId, (int)$baskaIste,
                      $yalnizEksik ? date('Y-m-d') : null, $simdi, $simdi]);
        $sid = (int)$db->lastInsertId();
    }
    $n = 0;
    foreach ($v['bulgular'] as $x) {
        tsBulguYaz($db, $sid, 'donus_kontrolu', ['tur' => $x['tur'], 'aciklama' => $x['aciklama'], 'foto_ids' => $x['foto_ids'] ?? [], 'depo_kontrol_id' => $kontrolId]);
        $n++;
    }
    $bulguIds = array_column(tsBulgular($db, $sid), 'id');
    $m = 0;
    foreach (ddEksikler($v) as $e) {
        tsEksikKalemYaz($db, $sid, $e['ad'], $e['adet'], $e['bulgu_index'] !== null ? ($bulguIds[$e['bulgu_index']] ?? null) : null);
        $m++;
    }
    $gid = null;
    if ($yalnizEksik) {
        require_once __DIR__ . '/servis-kalem-lib.php';
        tsTamamlandi($db, $sid);
    } elseif (!$acik) {
        if (!$baskaIste) {
            teknikServisMakineDurumSenkron($db, $tur, $mid, 'acik', 'ariza');
            $db->prepare('UPDATE ' . ($tur === 'arac' ? 'araclar' : 'manliftler') . " SET durum='serviste' WHERE id=? AND durum='inceleme_bekliyor'")->execute([$mid]);
        }
        $b = makineBilgiToplu($db, [['tur' => $tur, 'id' => $mid]])[$tur . ':' . $mid] ?? [];
        $mk = $b['kod'] ?? ($k['ekipman_adi'] ?: '#' . $mid);
        $gid = gorevOlustur($db, $baskaIste ? 'Makine ' . $mk . ' geri dönünce onarılmalı — servis #' . $sid : 'Servis: ' . ($k['ekipman_adi'] ?: 'Makine') . ' (dönüş kontrolü)',
                            $metin, 'teknik_servis', $sid);
    } elseif (!$baskaIste) {
        $db->prepare('UPDATE ' . ($tur === 'arac' ? 'araclar' : 'manliftler') . " SET durum='serviste' WHERE id=? AND durum='inceleme_bekliyor'")->execute([$mid]);
    }
    logla($db, 'teknik_servis', $sid, $acik ? 'guncelle' : 'ekle', null, ['kaynak' => 'depo_inceleme', 'donus_kontrol_id' => $kontrolId, 'bulgu' => $n, 'eksik' => $m, 'baska_iste' => (int)$baskaIste],
          'Dönüş kontrolünden servis açıldı — ' . $kod);
    return ['servis_id' => $sid, 'tip' => $acik ? 'ariza' : ($yalnizEksik ? 'eksik_parca' : 'ariza'), 'eksik' => $m, 'baska_iste' => $baskaIste, 'gorev_id' => $baskaIste ? $gid : null];
}
