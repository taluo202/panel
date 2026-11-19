<?php
declare(strict_types=1);
require_once __DIR__ . '/referans-lib.php';

const DI_ADIMLAR = ['depo_hazirlik', 'yukleme', 'toplaniyor', 'depo_inceleme'];

const DI_GUNLER = ['bugun', 'yarin', 'sonraki', 'hepsi'];

function diGunAraligi(string $gun, string $bugun): array {
    $g = fn(int $n) => date('Y-m-d', strtotime($bugun . " +$n day"));
    return match ($gun) {
        'yarin'   => [$g(1), $g(1)],
        'sonraki' => [$g(2), '9999-12-31'],
        'hepsi'   => ['0000-01-01', '9999-12-31'],
        default   => ['0000-01-01', $bugun],
    };
}

function diKunyeAlanlari(array $b, array $k): array {
    return ['seri_no' => $b['seri_no'] ?? null, 'stok_kodu' => $b['makine_kodu'] ?? null,
            'sube' => ($b['sube'] ?? null) ?: ($k['sube'] ?? null)];
}

function diHazirlikIlerleme(?array $veri, array $fotoAnahtarlari, string $tur, ?string $kategori = null, ?string $ekipman = null): array {
    $k = is_array($veri['kontroller'] ?? null) ? $veri['kontroller'] : [];
    $uygun = count(array_filter($k, fn($x) => in_array((string)(is_array($x) ? ($x['sonuc'] ?? '') : ''), ['uygun', 'uygulanamaz'], true)));
    $zorunlu = hzHazirlikAcilar($tur, $kategori, $ekipman);
    $tamam = count(array_filter($zorunlu, fn($a) => isset($fotoAnahtarlari[hzAciAnahtar($a)])));
    return ['kontrol' => [$uygun, count($k)], 'foto' => [$tamam, count($zorunlu)],
            'sayac' => trim((string)($veri['sayac'] ?? '')) !== ''];
}

function _diKiralama(PDO $db, int $kid): array {
    $s = $db->prepare('SELECT k.*, m.unvan AS musteri_unvan, m.email AS musteri_email FROM kiralamalar k JOIN musteriler m ON m.id=k.musteri_id WHERE k.id=?');
    $s->execute([$kid]);
    $k = $s->fetch(PDO::FETCH_ASSOC);
    if (!$k) throw new DomainException('Kiralama bulunamadı.');
    subeErisimDogrula($k['sube'] ?? null);
    if (!opYeniZincir($db, $k)) throw new DomainException(($k['kod'] ?: '#' . $kid) . ': eski düzendeki kiralama; yükleme / kontrol adımları yeni kiralamalar içindir.');
    return $k;
}

function _diIdler($v): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', is_array($v) ? $v : []))));
    if (!$ids) throw new DomainException('Makine seçin.');
    if (count($ids) > 100) throw new DomainException('Tek seferde en fazla 100 makine.');
    return $ids;
}

function _diAtama(PDO $db, int $kid, string $adim): array {
    $s = $db->prepare('SELECT * FROM depo_atamalari WHERE kiralama_id=? AND adim=?');
    $s->execute([$kid, $adim]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: [];
}

function _diAtamaYaz(PDO $db, int $kid, string $adim, array $alan): void {
    $kim = (string)($_SESSION['username'] ?? '');
    $db->prepare('INSERT INTO depo_atamalari(kiralama_id,adim,guncelleyen) VALUES(?,?,?) ON CONFLICT(kiralama_id,adim) DO NOTHING')->execute([$kid, $adim, $kim]);
    foreach ($alan as $sutun => $deger) {
        if (!in_array($sutun, ['atanan_kullanici_id', 'ustlenen_kullanici_id', 'gorev_id'], true)) continue;
        $db->prepare("UPDATE depo_atamalari SET $sutun=?, guncelleyen=?, zaman=datetime('now','localtime') WHERE kiralama_id=? AND adim=?")->execute([$deger, $kim, $kid, $adim]);
    }
}

function _diKullaniciAd(PDO $db, ?int $uid): string {
    if (!$uid) return '';
    $s = $db->prepare('SELECT COALESCE(NULLIF(ad_soyad,\'\'), username) FROM users WHERE id=?');
    $s->execute([$uid]);
    return (string)($s->fetchColumn() ?: ('#' . $uid));
}

function _diSiradaMi(PDO $db, array $k, string $adim): void {
    if (opSiradakiAdim($db, $k) !== $adim) throw new DomainException(($k['kod'] ?: '#' . $k['id']) . ': bu iş şu an sırada değil. Listeyi yenileyin.');
}

function diUstlen(PDO $db, $idler, string $adim, int $uid): int {
    $n = 0;
    foreach (_diIdler($idler) as $kid) {
        $k = _diKiralama($db, $kid);
        _diSiradaMi($db, $k, $adim);
        $kim = (string)($_SESSION['username'] ?? '');
        $db->prepare('INSERT INTO depo_atamalari(kiralama_id,adim,guncelleyen) VALUES(?,?,?) ON CONFLICT(kiralama_id,adim) DO NOTHING')->execute([$kid, $adim, $kim]);
        $u = $db->prepare("UPDATE depo_atamalari SET ustlenen_kullanici_id=?, guncelleyen=?, zaman=datetime('now','localtime')
                           WHERE kiralama_id=? AND adim=? AND (ustlenen_kullanici_id IS NULL OR ustlenen_kullanici_id=?)");
        $u->execute([$uid, $kim, $kid, $adim, $uid]);
        if ($u->rowCount() === 0) {
            $a = _diAtama($db, $kid, $adim);
            throw new DomainException(($k['kod'] ?: '#' . $kid) . ': ' . _diKullaniciAd($db, (int)($a['ustlenen_kullanici_id'] ?? 0)) . ' bu işi üstlendi.');
        }
        logla($db, 'kiralamalar', $kid, 'guncelle', null, ['depo_adim' => $adim, 'ustlenen' => $uid], 'İş üstlenildi: ' . OP_ADIMLAR[$adim]);
        $n++;
    }
    return $n;
}

function diBirak(PDO $db, $idler, string $adim, int $uid, bool $dagitimYetkili): int {
    $n = 0;
    foreach (_diIdler($idler) as $kid) {
        $k = _diKiralama($db, $kid);
        $a = _diAtama($db, $kid, $adim);
        if (empty($a['ustlenen_kullanici_id'])) continue;
        if ((int)$a['ustlenen_kullanici_id'] !== $uid && !$dagitimYetkili) throw new DomainException(($k['kod'] ?: '#' . $kid) . ': yalnız üstlenen kişi ya da dağıtım yetkilisi bırakabilir.');
        _diAtamaYaz($db, $kid, $adim, ['ustlenen_kullanici_id' => null]);
        logla($db, 'kiralamalar', $kid, 'guncelle', ['ustlenen' => (int)$a['ustlenen_kullanici_id']], ['ustlenen' => null], 'İş bırakıldı: ' . OP_ADIMLAR[$adim]);
        $n++;
    }
    return $n;
}

function diDagit(PDO $db, $idler, string $adim, int $hedef): int {
    $gerek = OP_ADIM_YETKI[$adim];
    if ($hedef < 1 || !opYetkiVar($db, 'kullanici', $hedef, $gerek)) throw new DomainException('Seçilen kişinin "' . OP_YETKILER[$gerek] . '" yetkisi yok.');
    $ad = _diKullaniciAd($db, $hedef);
    $p = $db->prepare('SELECT personel_id FROM users WHERE id=?');
    $p->execute([$hedef]);
    $personelId = $p->fetchColumn() ?: null;
    $n = 0;
    foreach (_diIdler($idler) as $kid) {
        $k = _diKiralama($db, $kid);
        _diSiradaMi($db, $k, $adim);
        $is = ['depo_hazirlik' => 'hazirlik', 'yukleme' => 'yukleme', 'toplaniyor' => 'toplama', 'depo_inceleme' => 'inceleme'][$adim];
        $gid = gorevOlustur($db, OP_ADIMLAR[$adim] . ' — ' . ($k['kod'] ?: '#' . $kid) . ' (' . $k['musteri_unvan'] . ')',
                            'İş ekranı: ' . opIsAdresi($is, ['kiralama' => $kid]), 'depo_' . $adim, $kid);
        $db->prepare("UPDATE gorevler SET atanan_id=?, updated_at=datetime('now','localtime') WHERE id=?")->execute([$personelId, $gid]);
        _diAtamaYaz($db, $kid, $adim, ['atanan_kullanici_id' => $hedef, 'gorev_id' => $gid]);
        logla($db, 'kiralamalar', $kid, 'guncelle', null, ['depo_adim' => $adim, 'atanan' => $hedef], 'İş atandı: ' . OP_ADIMLAR[$adim] . ' → ' . $ad);
        $n++;
    }
    return $n;
}

function _diKisiler(PDO $db): array {
    if (!opOturumYetkisi($db, 'dagitim')) return [];
    $out = [];
    foreach ($db->query("SELECT id, COALESCE(NULLIF(ad_soyad,''), username) AS ad FROM users ORDER BY ad")->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $d = opYetkiVar($db, 'kullanici', (int)$u['id'], 'depo'); $y = opYetkiVar($db, 'kullanici', (int)$u['id'], 'yukleme');
        if ($d || $y) $out[] = ['id' => (int)$u['id'], 'ad' => $u['ad'], 'depo' => $d, 'yukleme' => $y];
    }
    return $out;
}

function _diPersoneller(PDO $db): array {
    if (!opOturumYetkisi($db, 'yukleme') && !opOturumYetkisi($db, 'dagitim')) return [];
    $out = [];
    foreach ($db->query("SELECT id, ad_soyad FROM personel WHERE COALESCE(durum,'aktif')='aktif' ORDER BY ad_soyad")->fetchAll(PDO::FETCH_ASSOC) as $pr)
        if (opYetkiVar($db, 'personel', (int)$pr['id'], 'saha')) $out[] = ['id' => (int)$pr['id'], 'ad' => $pr['ad_soyad']];
    return $out;
}

function _diEkipDis(PDO $db, array $b, ?array $nak, int $nid): array {
    $ekip = array_values(array_unique(array_filter(array_map('intval', (array)($b['ekip'] ?? [])))));
    $dis = is_array($b['dis_sofor'] ?? null) ? ['ad' => mb_substr(trim((string)($b['dis_sofor']['ad'] ?? '')), 0, 120),
                                                    'tel' => mb_substr(trim((string)($b['dis_sofor']['tel'] ?? '')), 0, 40)] : null;
    if ($dis !== null) {
        if ($dis['ad'] === '' || $dis['tel'] === '') throw new DomainException('Dış şoförün adı ve telefonu gerekli.');
        if (!$nak) throw new DomainException('Dış şoför bağlantısı için nakliye aracını seçin.');
    }
    if ($ekip && $nid <= 0) throw new DomainException('Ekip için nakliye aracını seçin.');
    foreach ($ekip as $pid) {
        if (!opYetkiVar($db, 'personel', $pid, 'saha')) throw new DomainException('Seçilen personelin "Sahada teslim" yetkisi yok.');
        $ps = $db->prepare("SELECT durum FROM personel WHERE id=?"); $ps->execute([$pid]);
        if ($ps->fetchColumn() !== 'aktif') throw new DomainException('Seçilen personel aktif değil.');
    }
    return [$ekip, $dis];
}

function diAracSoforAd(PDO $db, ?array $nak, string $yon): string {
    if (!$nak) return '';
    if ($yon !== 'donus' && !empty($nak['id'])) {
        $s = $db->prepare('SELECT sofor_personel_id FROM nakliyeler WHERE id=?'); $s->execute([(int)$nak['id']]);
        $pid = $s->fetchColumn();
        if ($pid !== false && $pid !== null && ($ad = trim((string)_sfPersonelAd($db, (int)$pid))) !== '') return $ad;
    }
    if (!empty($nak['ic_ekip'])) return '';
    if (($nak['tip'] ?? 'dis') !== 'avk' && ($ad = sfDisCift($nak, $yon)['ad']) !== '') return $ad;
    return trim((string)($nak['sofor_adi'] ?? ''));
}

function _diBaglantiUret(PDO $db, int $nid, array $nak, array $dis, string $yon, array $yapan, ?string $ozet = null, bool $soforYaz = true): string {
    if (!in_array($yon, ['gidis', 'donus'], true)) throw new DomainException('Geçersiz bağlantı yönü.');
    [$sAd, $sTel] = $yon === 'donus' ? ['dis_sofor_donus_ad', 'dis_sofor_donus_tel'] : ['dis_sofor_ad', 'dis_sofor_tel'];
    $simdi = date('Y-m-d H:i:s');
    $on = $db->prepare('SELECT MAX(id) FROM teslim_baglantilari WHERE nakliye_id=? AND yon=?');
    $on->execute([$nid, $yon]);
    $oncekiId = $on->fetchColumn();
    $db->prepare("UPDATE teslim_baglantilari SET gecerlilik=datetime('now','localtime','-1 second'), gecersiz_zaman=?
                  WHERE nakliye_id=? AND yon=? AND kullanildi=0 AND gecerlilik > datetime('now','localtime')")->execute([$simdi, $nid, $yon]);
    if ($soforYaz) $db->prepare("UPDATE nakliyeler SET $sAd=?, $sTel=? WHERE id=?")->execute([$dis['ad'], $dis['tel'], $nid]);
    $belirtec = bin2hex(random_bytes(24));
    $db->prepare("INSERT INTO teslim_baglantilari(nakliye_id,yon,belirtec_ozet,gecerlilik,olusturan,alici_ad,alici_tel,alici_eposta,not_metni,zaman)
                  VALUES(?,?,?,datetime('now','localtime','+48 hours'),?,?,?,?,?,?)")
       ->execute([$nid, $yon, tokenOzet($belirtec), $yapan['ad'], $dis['ad'], $dis['tel'],
                  ($dis['eposta'] ?? '') !== '' ? $dis['eposta'] : null, ($dis['not'] ?? '') !== '' ? $dis['not'] : null, $simdi]);
    $yeniId = (int)$db->lastInsertId();
    $oncekiBaglanti = $oncekiId !== false && $oncekiId !== null ? (int)$oncekiId : null;
    logla($db, 'nakliyeler', $nid, 'guncelle',
          $soforYaz ? ['dis_sofor' => $nak[$sAd] ?? null, 'dis_sofor_tel' => $nak[$sTel] ?? null, 'yon' => $yon, 'baglanti_id' => $oncekiBaglanti]
                    : ['yon' => $yon, 'baglanti_id' => $oncekiBaglanti],
          ($soforYaz ? ['dis_sofor' => $dis['ad'], 'dis_sofor_tel' => $dis['tel']] : ['alici_ad' => $dis['ad'], 'alici_tel' => $dis['tel']])
          + ['yon' => $yon, 'baglanti_id' => $yeniId]
          + (($dis['eposta'] ?? '') !== '' ? ['eposta' => $dis['eposta']] : []),
          $ozet ?? ('Dış şoför ' . ($yon === 'donus' ? 'teslim alma' : 'teslim') . ' bağlantısı üretildi (48 saat): ' . $dis['ad']));
    return diTeslimBaglantisi($belirtec);
}

function diListe(PDO $db, string $gun, string $siparis, int $kiralamaId, int $benId): array {
    $bugun = date('Y-m-d');
    [$bas, $bit] = diGunAraligi($gun, $bugun);
    $w = ["k.durum='aktif'", 'k.gercek_bitis IS NULL', 'k.makine_id IS NOT NULL', 'k.id > (SELECT son_eski_kiralama FROM operasyon_gecis WHERE id=1)'];
    $p = [];
    if ($kiralamaId > 0) {
        $w[] = "(k.id=? OR (COALESCE(k.siparis_no,'')<>'' AND k.siparis_no=(SELECT siparis_no FROM kiralamalar WHERE id=?)))";
        array_push($p, $kiralamaId, $kiralamaId);
    } elseif ($siparis !== '') { $w[] = 'k.siparis_no=?'; $p[] = $siparis; }
    else { $w[] = 'k.baslangic BETWEEN ? AND ?'; array_push($p, $bas, $bit); }
    subeKosulEkle($w, $p, 'k.sube');
    $s = $db->prepare("SELECT k.id, k.kod, k.siparis_no, k.musteri_id, k.lokasyon, k.baslangic, k.ekipman_adi, k.makine_tur, k.makine_id,
                              k.durum, k.gercek_bitis, k.kaynak, k.sube, m.unvan AS musteri_unvan
                       FROM kiralamalar k JOIN musteriler m ON m.id=k.musteri_id
                       WHERE " . implode(' AND ', $w) . " ORDER BY k.baslangic, k.siparis_no, k.id");
    $s->execute($p);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    $sira = opSiradakiToplu($db, $rows);
    $bilgi = makineBilgiToplu($db, array_map(fn($k) => ['tur' => $k['makine_tur'], 'id' => (int)$k['makine_id']], $rows));
    $ids = array_map(fn($k) => (int)$k['id'], $rows);
    $atama = []; $hz = []; $hzVeri = []; $foto = []; $plan = []; $yuk = []; $uids = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $q = $db->prepare("SELECT * FROM depo_atamalari WHERE kiralama_id IN ($in)"); $q->execute($ids);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $atama[(int)$a['kiralama_id']][$a['adim']] = $a;
            foreach (['atanan_kullanici_id', 'ustlenen_kullanici_id'] as $c) if ($a[$c]) $uids[(int)$a[$c]] = true;
        }
        $q = $db->prepare("SELECT kiralama_id, durum, veri FROM depo_kontrolleri WHERE yon='gidis' AND kiralama_id IN ($in)"); $q->execute($ids);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $hz[(int)$h['kiralama_id']] = $h['durum'];
            $v = json_decode((string)$h['veri'], true);
            $hzVeri[(int)$h['kiralama_id']] = is_array($v) ? $v : null;
        }
        $q = $db->prepare("SELECT kiralama_id, aci_tipi FROM kiralama_fotograflari WHERE COALESCE(yon,'gidis')='gidis' AND kiralama_id IN ($in)"); $q->execute($ids);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $f) $foto[(int)$f['kiralama_id']][hzAciAnahtar((string)$f['aci_tipi'])] = true;
        $q = $db->prepare("SELECT nm.kiralama_id, nm.nakliye_id, nm.yuklendi_zaman FROM nakliye_makineleri nm JOIN nakliyeler n ON n.id=nm.nakliye_id
                           WHERE nm.kiralama_id IN ($in) AND COALESCE(n.yon,'gidis') IN ('gidis','gidis_donus','tek') ORDER BY nm.nakliye_id");
        $q->execute($ids);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $plan[(int)$r['kiralama_id']] ??= (int)$r['nakliye_id'];
            if ($r['yuklendi_zaman']) $yuk[(int)$r['kiralama_id']] = true;
        }
    }
    $adlar = [];
    foreach (array_keys($uids) as $u) $adlar[$u] = _diKullaniciAd($db, $u);
    $kisi = fn($u) => $u ? ['id' => (int)$u, 'ad' => $adlar[(int)$u] ?? ('#' . $u)] : null;
    $hzEt = ['taslak' => 'Hazırlık taslak', 'hazir' => 'Yüklemeye hazır', 'onaylandi' => 'Yüklemeye hazır', 'yola_cikti' => 'Yola çıktı'];
    $depoAdim = ['depo_hazirlik', 'yukleme'];
    $gruplar = [];
    foreach ($rows as $k) {
        $id = (int)$k['id'];
        $gk = ($k['siparis_no'] ?? '') !== '' ? (string)$k['siparis_no'] : 'K' . $id;
        $b = $bilgi[$k['makine_tur'] . ':' . (int)$k['makine_id']] ?? [];
        $sn = $sira[$id] ?? null;
        $at = in_array($sn, $depoAdim, true) ? ($atama[$id][$sn] ?? []) : [];
        $gruplar[$gk] ??= ['gk' => $gk, 'siparis_no' => $k['siparis_no'], 'kod' => $k['kod'], 'musteri_id' => (int)$k['musteri_id'],
                           'musteri_unvan' => $k['musteri_unvan'], 'lokasyon' => $k['lokasyon'], 'baslangic' => $k['baslangic'],
                           'makineler' => [], 'araclar' => [], 'kaynak' => null];
        if (($k['kaynak'] ?? '') === 'qr') $gruplar[$gk]['kaynak'] = 'qr';
        $gruplar[$gk]['makineler'][] = [
            'kiralama_id' => $id, 'kod' => $k['kod'], 'makine_kod' => $b['kod'] ?? null,
            'marka_model' => trim(($b['marka'] ?? '') . ' ' . ($b['model'] ?? '')), 'metraj' => $b['metraj'] ?? null,
            'kategori' => $b['kategori'] ?? null, 'olcu_birim' => $b['olcu_birim'] ?? null, 'gorsel' => refKartGorsel($db, $b['marka'] ?? null, $b['model'] ?? null, $b['kategori'] ?? null),
            'ekipman_adi' => $k['ekipman_adi'], 'siradaki' => $sn,
            'hazirlik_durum' => isset($hz[$id]) ? ($hzEt[$hz[$id]] ?? $hz[$id]) : null,
            'ilerleme' => diHazirlikIlerleme($hzVeri[$id] ?? null, $foto[$id] ?? [], (string)$k['makine_tur'], $b['kategori'] ?? null, $k['ekipman_adi'] ?? null),
            'atanan' => $kisi($at['atanan_kullanici_id'] ?? null), 'ustlenen' => $kisi($at['ustlenen_kullanici_id'] ?? null),
            'nakliye_planli' => $plan[$id] ?? null, 'yuklendi' => isset($yuk[$id]),
        ] + diKunyeAlanlari($b, $k);
    }
    $gruplar = array_values(array_filter($gruplar, fn($g) => $kiralamaId > 0
        || array_filter($g['makineler'], fn($m) => in_array($m['siradaki'], $depoAdim, true))));
    foreach ($gruplar as &$g) {
        $q = ($g['siparis_no'] ?? '') !== ''
            ? $db->prepare("SELECT * FROM nakliyeler WHERE siparis_no=? AND COALESCE(yon,'gidis') IN ('gidis','gidis_donus','tek') ORDER BY id")
            : $db->prepare("SELECT * FROM nakliyeler WHERE kiralama_id=? AND COALESCE(yon,'gidis') IN ('gidis','gidis_donus','tek') ORDER BY id");
        $q->execute([($g['siparis_no'] ?? '') !== '' ? $g['siparis_no'] : (int)$g['makineler'][0]['kiralama_id']]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $n) {
            $mk = $db->prepare('SELECT kiralama_id, yuklendi_zaman FROM nakliye_makineleri WHERE nakliye_id=?'); $mk->execute([$n['id']]);
            $mkSatir = $mk->fetchAll(PDO::FETCH_ASSOC);
            $ek = $db->prepare('SELECT p.id, p.ad_soyad FROM nakliye_ekip e JOIN personel p ON p.id=e.personel_id WHERE e.nakliye_id=?'); $ek->execute([$n['id']]);
            $ekip = $ek->fetchAll(PDO::FETCH_ASSOC);
            $sid = $n['sofor_personel_id'] !== null ? (int)$n['sofor_personel_id'] : null;
            $bid = ($n['sofor_birakan_id'] ?? null) !== null ? (int)$n['sofor_birakan_id'] : null;
            $g['araclar'][] = ['id' => (int)$n['id'], 'plaka' => $n['plaka'], 'arac_id' => isset($n['arac_id']) ? (int)$n['arac_id'] : null,
                               'nakliyeci' => $n['nakliyeci'], 'sofor_adi' => $n['sofor_adi'] ?? null,
                               'tarih' => $n['tarih'] ?? null, 'planlanan_tarih' => $n['planlanan_tarih'] ?? null,
                               'tip' => $n['tip'] ?? null, 'yon' => $n['yon'], 'dis_sofor_ad' => $n['dis_sofor_ad'] ?? null,
                               'dis_sofor_tel' => $n['dis_sofor_tel'] ?? null,
                               'dis_sofor_donus_ad' => $n['dis_sofor_donus_ad'] ?? null, 'dis_sofor_donus_tel' => $n['dis_sofor_donus_tel'] ?? null,
                               'makineler' => array_map('intval', array_column($mkSatir, 'kiralama_id')), 'ekip' => array_column($ekip, 'ad_soyad'),
                               'ekip_idler' => array_map('intval', array_column($ekip, 'id')),
                               'yuklendi' => (bool)array_filter(array_column($mkSatir, 'yuklendi_zaman')),
                               'sofor_personel_id' => $sid, 'sofor_ad' => _sfPersonelAd($db, $sid),
                               'sofor_kaynak' => $n['sofor_kaynak'] ?? null, 'sofor_zaman' => $n['sofor_zaman'] ?? null,
                               'sofor_birakan_ad' => _sfPersonelAd($db, $bid), 'sofor_birak_zaman' => $bid !== null ? ($n['sofor_birak_zaman'] ?? null) : null];
        }
    }
    unset($g);
    $kisiler = _diKisiler($db); $ilerleme = [];
    if (opOturumYetkisi($db, 'dagitim')) {
        $sirada = array_flip(array_keys(OP_ADIMLAR));
        foreach ($atama as $kid => $adimlar) foreach ($adimlar as $adim => $a) {
            $u = (int)($a['ustlenen_kullanici_id'] ?: $a['atanan_kullanici_id']);
            if (!$u || !array_key_exists($kid, $sira)) continue;
            $ad = $adlar[$u] ?? _diKullaniciAd($db, $u);
            $ilerleme[$ad] ??= ['ad' => $ad, 'toplam' => 0, 'biten' => 0];
            $ilerleme[$ad]['toplam']++;
            $sn = $sira[$kid];
            if ($sn === null || $sirada[$sn] > $sirada[$adim]) $ilerleme[$ad]['biten']++;
        }
    }
    $personeller = _diPersoneller($db);
    return ['gun' => $gun, 'bugun' => $bugun, 'ben' => $benId, 'gruplar' => $gruplar, 'kisiler' => $kisiler,
            'personeller' => $personeller, 'kisi_ilerleme' => array_values($ilerleme)];
}

function diTeslimBaglantisi(string $belirtec): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . guvenliHost() . siteKokYolu() . '/saha-teslim.html?b=' . $belirtec;
}

function diYukle(PDO $db, array $b): array {
    islemKilitAl($db);
    $ids = _diIdler($b['kiralama_ids'] ?? []);
    $nid = (int)($b['nakliye_id'] ?? 0);
    $gerekce = trim((string)($b['atlama_gerekce'] ?? ''));
    $nak = null;
    if ($nid > 0) {
        $s = $db->prepare('SELECT * FROM nakliyeler WHERE id=?'); $s->execute([$nid]);
        $nak = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$nak) throw new DomainException('Nakliye aracı bulunamadı.');
        subeErisimDogrula($nak['sube'] ?? null);
    }
    [$ekip, $dis] = _diEkipDis($db, $b, $nak, $nid);
    if ($nak && !in_array($nak['yon'] ?? 'gidis', ['gidis', 'gidis_donus', 'tek'], true))
        throw new DomainException('Seçilen araç bir gidiş aracı değil.');
    $yapan = opOturumYapan($db);
    $plan = [];
    foreach ($ids as $kid) {
        $k = _diKiralama($db, $kid);
        $kod = $k['kod'] ?: '#' . $kid;
        $sira = opSiradakiAdim($db, $k);
        $atla = false;
        if ($sira === 'depo_hazirlik') {
            if (!opOturumYetkisi($db, 'atlama') || mb_strlen($gerekce) < 5)
                throw new DomainException($kod . ': çıkış kontrolü bitmedi. Hazırlığı tamamlayın ya da "Adım atlama" yetkisiyle gerekçe yazın.');
            $atla = true;
        } elseif ($sira !== 'yukleme') {
            throw new DomainException($kod . ': yükleme sırası değil (sıradaki: ' . ($sira ? OP_ADIMLAR[$sira] : 'yok') . ').');
        }
        $pl = $db->prepare("SELECT nm.nakliye_id FROM nakliye_makineleri nm JOIN nakliyeler n ON n.id=nm.nakliye_id
                            WHERE nm.kiralama_id=? AND COALESCE(n.yon,'gidis') IN ('gidis','gidis_donus','tek')");
        $pl->execute([$kid]);
        $planli = array_map('intval', $pl->fetchAll(PDO::FETCH_COLUMN));
        if ($nak) {
            $sp = (string)($k['siparis_no'] ?? '');
            $uygun = $sp !== '' ? ($nak['siparis_no'] ?? '') === $sp
                                : ((int)($nak['kiralama_id'] ?? 0) === $kid || in_array($nid, $planli, true));
            if (!$uygun) throw new DomainException($kod . ': seçilen araç bu kiralamanın/siparişin aracı değil.');
        }
        $plan[] = [$k, $kod, $atla, $planli, $nid > 0 && !in_array($nid, $planli, true)];
    }
    $kayitlar = [];
    foreach ($plan as [$k, $kod, $atla, $planli, $planDisi]) {
        $kid = (int)$k['id'];
        if ($atla) opAdimYaz($db, $kid, 'depo_hazirlik', 'atlandi', $yapan, 'yonetici', $gerekce);
        if ($nid > 0) {
            $db->prepare('INSERT OR IGNORE INTO nakliye_makineleri(nakliye_id,kiralama_id,plan_disi) VALUES(?,?,?)')->execute([$nid, $kid, $planDisi ? 1 : 0]);
            $db->prepare("UPDATE nakliye_makineleri SET yuklendi_zaman=?, yukleyen=? WHERE nakliye_id=? AND kiralama_id=?")
               ->execute([date('Y-m-d H:i:s'), $yapan['ad'], $nid, $kid]);
        }
        if (operasyonKiralamaGuncelMi($db, $k)) kiralamaMakineDurumu($db, (string)$k['makine_tur'], (int)$k['makine_id'], 'nakliye');
        if ($h = hzGet($db, $kid)) {
            $db->prepare("UPDATE depo_kontrolleri SET durum='yola_cikti', surum=surum+1, yapan=?, updated_at=? WHERE kiralama_id=? AND yon='gidis'")->execute([$yapan['ad'], date('Y-m-d H:i:s'), $kid]);
            dkGecmisYaz($db, $kid, 'gidis', (int)$h['surum'] + 1, 'yola_cik', 'Yükleme / Toplama: araca yüklendi', ['makine_bag' => $h['makine_bag'], 'veri' => $h['veri']]);
        }
        opAdimYaz($db, $kid, 'yukleme', 'tamam', $yapan, 'yetki', null, ['nakliye_id' => $nid ?: null, 'plan_disi' => $planDisi, 'planli' => $planli]);
        if ($planDisi && $planli)
            gorevOlustur($db, 'Plan dışı yükleme: ' . $kod . ' — nakliye ücretini kontrol edin',
                         $kod . ' planlanan araç yerine ' . ($nak['plaka'] ?: '#' . $nid) . ' ile gönderildi.', 'nakliye_plan_disi', $kid);
        $kayitlar[] = $k;
    }
    foreach ($ekip as $pid) {
        $e = $db->prepare('INSERT OR IGNORE INTO nakliye_ekip(nakliye_id,personel_id) VALUES(?,?)'); $e->execute([$nid, $pid]);
        if ($e->rowCount()) logla($db, 'nakliyeler', $nid, 'guncelle', null, ['ekip_personel' => $pid], 'Teslim ekibine personel eklendi (yükleme)');
    }
    if ($nid > 0) sfYuklemeEsitle($db, $nid, $ekip, $dis !== null, (int)($yapan['id'] ?? 0));
    sfBaskaAracaYuklendi($db, $kayitlar, $nid, (int)($yapan['id'] ?? 0));
    $baglanti = $dis !== null ? _diBaglantiUret($db, $nid, $nak, $dis, 'gidis', $yapan) : null;
    if ($nak && ($nak['tip'] ?? 'dis') !== 'avk' && $dis === null) $nak['ic_ekip'] = true;
    return ['yuklenen' => count($kayitlar), 'kayitlar' => $kayitlar, 'nakliye' => $nak, 'dis_sofor' => $dis, 'baglanti' => $baglanti];
}

function diBildir(PDO $db, array $kayitlar, ?array $nak, ?array $dis, string $olay = 'yola_cikti'): array {
    $t = $olay === 'toplaniyor'
        ? ['govde' => ' kodlu makineleriniz için toplama ekibimiz yola çıktı.', 'konu' => 'Makineleriniz toplanıyor',
           'gorev' => 'Müşteriyi arayın: makineler toplanıyor — ', 'kaynak' => 'toplama_bildirim', 'log' => 'Toplama bildirimi: ']
        : ['govde' => ' kodlu makineleriniz yola çıktı.', 'konu' => 'Makineleriniz yola çıktı',
           'gorev' => 'Müşteriyi arayın: makineler yola çıktı — ', 'kaynak' => 'yola_cikti_bildirim', 'log' => 'Yola çıktı bildirimi: '];
    $musteri = [];
    foreach ($kayitlar as $k) $musteri[(int)$k['musteri_id']][] = $k;
    $out = [];
    foreach ($musteri as $mid => $ks) {
        $kodlar = implode(', ', array_map(fn($k) => (string)($k['kod'] ?: '#' . $k['id']), $ks));
        $sofor = $dis['ad'] ?? diAracSoforAd($db, $nak, $olay === 'toplaniyor' ? 'donus' : 'gidis');
        try {
        $mesaj = 'Sayın ' . $ks[0]['musteri_unvan'] . ', ' . $kodlar . $t['govde']
               . (!empty($nak['plaka']) ? ' Araç: ' . $nak['plaka'] . '.' : '') . ($sofor !== '' ? ' Şoför: ' . $sofor . '.' : '')
               . ' İyi çalışmalar dileriz.';
        $kanal = 'gorev';
        if (waMusteriBildir($db, $mid, null, $olay, $mesaj) > 0) {
            $kanal = 'whatsapp';
        } else {
            $mail = trim((string)($ks[0]['musteri_email'] ?? ''));
            if ($mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL) && !empty(epostaGonder($db, $mail, $t['konu'], $mesaj, $olay)['basarili'])) {
                $kanal = 'eposta';
            } else {
                gorevOlustur($db, $t['gorev'] . $ks[0]['musteri_unvan'], $mesaj, $t['kaynak'], (int)$ks[0]['id'], 'yuksek');
            }
        }
        logla($db, 'kiralamalar', (int)$ks[0]['id'], 'guncelle', null, ['bildirim' => $kanal],
              $t['log'] . ['whatsapp' => 'WhatsApp', 'eposta' => 'e-posta', 'gorev' => 'gönderilemedi, ofise görev'][$kanal]);
        $out[] = ['musteri_id' => $mid, 'kanal' => $kanal];
        } catch (Throwable $e) {
            error_log('diBildir: ' . $e->getMessage());
            $out[] = ['musteri_id' => $mid, 'kanal' => 'hata'];
        }
    }
    return $out;
}
