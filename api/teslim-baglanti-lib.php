<?php
declare(strict_types=1);
require_once __DIR__ . '/depo-isleri-lib.php';
require_once __DIR__ . '/saha-teslim-lib.php';
require_once __DIR__ . '/belge-pdf-lib.php';
require_once __DIR__ . '/whatsapp.php';
require_once __DIR__ . '/eposta.php';

const TB_SORUNLU = ['yetkili_yok', 'imza_vermedi', 'teslim_edilemedi'];

class TbYok extends DomainException {}

function tbYonler(array $n): array {
    $y = (string)(($n['yon'] ?? '') ?: 'gidis');
    $out = [];
    if (in_array($y, ['gidis', 'gidis_donus', 'tek'], true)) $out[] = 'gidis';
    if (in_array($y, ['donus', 'gidis_donus'], true)) $out[] = 'donus';
    return $out;
}

function tbIslem(string $yon): string {
    return $yon === 'donus' ? 'iade' : 'teslim';
}

function tbNakliye(PDO $db, int $nid): array {
    $s = $db->prepare('SELECT * FROM nakliyeler WHERE id=?');
    $s->execute([$nid]);
    $n = $s->fetch(PDO::FETCH_ASSOC);
    if (!$n) throw new TbYok('Nakliye kaydı bulunamadı.');
    subeErisimDogrula($n['sube'] ?? null);
    return $n;
}

function tbTelMaske(?string $tel): string {
    $t = trim((string)$tel);
    $r = (string)preg_replace('/\D+/', '', $t);
    if ($t === '') return '';
    return strlen($r) >= 8 ? substr($r, 0, 4) . ' *** ** ' . substr($r, -2) : '***';
}

function tbEpostaMaske(?string $e): string {
    $e = trim((string)$e);
    $at = strpos($e, '@');
    if ($e === '' || $at === false) return $e === '' ? '' : '***';
    return mb_substr($e, 0, 1) . '***' . substr($e, $at);
}

function tbKabulEtiket(?string $k): string {
    return BP_KABUL[(string)$k] ?? (string)$k;
}

function tbTutanaklar(PDO $db, int $nid, string $yon, bool $yalnizSorunlu = false): array {
    $sut = $yon === 'donus' ? 'toplama_zaman' : 'yuklendi_zaman';
    $s = $db->prepare("SELECT t.id, t.kiralama_id, t.kabul_sonucu, t.kabul_notu, COALESCE(t.fiili_teslim,0) AS fiili_teslim, t.kaynak, t.created_at
                       FROM nakliye_makineleri nm JOIN iade_tutanaklari t ON t.kiralama_id=nm.kiralama_id AND t.islem=? AND t.created_at >= nm.$sut
                       WHERE nm.nakliye_id=? AND nm.$sut IS NOT NULL"
                     . ($yalnizSorunlu ? " AND COALESCE(t.fiili_teslim,0)=0 AND t.kabul_sonucu IN ('" . implode("','", TB_SORUNLU) . "')" : '')
                     . ' ORDER BY t.created_at DESC, t.id DESC');
    $s->execute([tbIslem($yon), $nid]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

function tbGecmis(PDO $db, int $nid, bool $tam): array {
    $s = $db->prepare("SELECT id, yon, gecerlilik, kullanildi, olusturan, zaman, alici_ad, alici_tel, alici_eposta, not_metni, gecersiz_zaman,
                              CASE WHEN gecerlilik > datetime('now','localtime') THEN 1 ELSE 0 END AS gecerli
                       FROM teslim_baglantilari WHERE nakliye_id=? AND yon IN ('gidis','donus') ORDER BY id");
    $s->execute([$nid]);
    $yonde = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $yonde[$r['yon']][] = $r;
    $out = [];
    foreach ($yonde as $yon => $liste) {
        $tut = tbTutanaklar($db, $nid, $yon);
        foreach ($liste as $i => $r) {
            $sonraki = $liste[$i + 1]['zaman'] ?? null;
            $sayim = [];
            foreach ($tut as $t) {
                if ($t['created_at'] < $r['zaman'] || ($sonraki !== null && $t['created_at'] >= $sonraki)) continue;
                $k = (string)$t['kabul_sonucu'];
                $sayim[$k] ??= ['kod' => $k, 'etiket' => tbKabulEtiket($k), 'adet' => 0, 'not' => (string)($t['kabul_notu'] ?? '')];
                $sayim[$k]['adet']++;
            }
            if ((int)$r['kullanildi'] === 1) $durum = 'kullanildi';
            elseif ($r['gecersiz_zaman'] !== null) $durum = 'gecersiz';
            elseif ((int)$r['gecerli'] === 1) $durum = 'bekliyor';
            elseif ($sonraki !== null && abs(strtotime((string)$sonraki) - strtotime((string)$r['gecerlilik'])) <= 5) $durum = 'gecersiz';
            else $durum = 'suresi_doldu';
            $out[] = ['id' => (int)$r['id'], 'yon' => $yon, 'zaman' => (string)$r['zaman'], 'gecerlilik' => (string)$r['gecerlilik'],
                      'olusturan' => (string)($r['olusturan'] ?? ''), 'alici_ad' => (string)($r['alici_ad'] ?? ''),
                      'alici_tel' => $tam ? (string)($r['alici_tel'] ?? '') : tbTelMaske($r['alici_tel']),
                      'alici_eposta' => $tam ? (string)($r['alici_eposta'] ?? '') : tbEpostaMaske($r['alici_eposta']),
                      'not' => (string)($r['not_metni'] ?? ''), 'durum' => $durum, 'sonuclar' => array_values($sayim)];
        }
    }
    usort($out, fn(array $a, array $b): int => [$b['zaman'], $b['id']] <=> [$a['zaman'], $a['id']]);
    return $out;
}

function _tbSonBaglanti(PDO $db, int $nid, string $yon): ?array {
    $s = $db->prepare('SELECT * FROM teslim_baglantilari WHERE nakliye_id=? AND yon=? ORDER BY id DESC LIMIT 1');
    $s->execute([$nid, $yon]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function tbDurum(PDO $db, int $nid, bool $yonetici): array {
    $n = tbNakliye($db, $nid);
    $yonler = [];
    if (($n['tip'] ?? 'dis') !== 'avk') {
        foreach (tbYonler($n) as $yon) {
            $bek = stAracMakineleri($db, $nid, tbIslem($yon));
            $son = _tbSonBaglanti($db, $nid, $yon);
            $c = $son !== null && trim((string)($son['alici_ad'] ?? '')) !== ''
                ? ['ad' => trim((string)$son['alici_ad']), 'tel' => trim((string)($son['alici_tel'] ?? ''))] : sfDisCift($n, $yon);
            $deneme = tbTutanaklar($db, $nid, $yon, true)[0] ?? null;
            if ($deneme !== null && $son !== null && (string)$son['zaman'] > (string)$deneme['created_at']) $deneme = null;
            $saha = $bek ? sfIletisim($db, (int)$bek[0]['kiralama_id']) : null;
            $ep = trim((string)($son['alici_eposta'] ?? ''));
            $yonler[] = [
                'yon' => $yon,
                'bekleyen' => array_map(fn(array $m): array => ['kiralama_id' => $m['kiralama_id'], 'kod' => $m['kod'], 'ekipman_adi' => $m['ekipman_adi'],
                    'makine_ad' => $m['makine_ad'], 'marka' => $m['marka'], 'model' => $m['model'], 'musteri_unvan' => $m['musteri_unvan'],
                    'lokasyon' => $m['lokasyon']], $bek),
                'alici' => ['ad' => $c['ad'], 'tel' => $yonetici ? $c['tel'] : tbTelMaske($c['tel']), 'eposta' => $yonetici ? $ep : tbEpostaMaske($ep)],
                'saha' => $saha ? ['ad' => (string)($saha['ad'] ?? ''), 'tel' => $yonetici ? (string)($saha['tel'] ?? '') : tbTelMaske((string)($saha['tel'] ?? '')), 'kaynak' => $saha['kaynak']] : null,
                'son_deneme' => $deneme ? ['kabul' => (string)$deneme['kabul_sonucu'], 'etiket' => tbKabulEtiket($deneme['kabul_sonucu']),
                                           'not' => (string)($deneme['kabul_notu'] ?? ''), 'zaman' => (string)$deneme['created_at']] : null,
            ];
        }
    }
    return ['nakliye' => ['id' => (int)$n['id'], 'plaka' => (string)($n['plaka'] ?? ''), 'nakliyeci' => (string)($n['nakliyeci'] ?? ''),
                          'tip' => (string)(($n['tip'] ?? '') ?: 'dis'), 'yon' => (string)(($n['yon'] ?? '') ?: 'gidis'), 'tarih' => (string)($n['tarih'] ?? '')],
            'yonetici' => $yonetici, 'yonler' => $yonler, 'gecmis' => tbGecmis($db, $nid, $yonetici)];
}

function tbYenidenGonder(PDO $db, array $b, array $yapan): array {
    $nid = (int)($b['nakliye_id'] ?? 0);
    if ($nid < 1) throw new InvalidArgumentException('Nakliye seçilmedi.');
    $yon = (string)($b['yon'] ?? '');
    if (!in_array($yon, ['gidis', 'donus'], true)) throw new InvalidArgumentException('Yönü seçin: gidişte teslim ya da dönüşte teslim alma.');
    $al = fn(string $k, int $max): string => mb_substr(trim(metin($b[$k] ?? '')), 0, $max);
    $ad = $al('alici_ad', 120); $tel = $al('alici_tel', 40); $ep = $al('alici_eposta', 160); $not = $al('not', 500);
    $sAd = trim(metin($b['saha_yetkili_ad'] ?? '')); $sTel = trim(metin($b['saha_yetkili_tel'] ?? ''));
    if ($ad === '') throw new InvalidArgumentException('Bağlantıyı alacak kişinin adını yazın.');
    if ($tel === '' && $ep === '') throw new InvalidArgumentException('Bağlantının gideceği telefonu ya da e-postayı yazın.');
    if ($tel !== '' && waTelefon($tel) === null) throw new InvalidArgumentException('Telefon numarası geçersiz (ör. 0532 123 45 67).');
    if ($ep !== '' && !filter_var($ep, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('E-posta adresi geçersiz.');
    foreach ([['yetkili', $sAd], ['telefon', $sTel]] as [$alan, $v]) if ($h = hkIrtibatSiniri($alan, $v)) throw new InvalidArgumentException($h);
    if ($sTel !== '' && strlen((string)preg_replace('/\D+/', '', $sTel)) < 10) throw new InvalidArgumentException('Şantiye yetkilisinin telefonu geçersiz.');

    $n = tbNakliye($db, $nid);
    if (($n['tip'] ?? 'dis') === 'avk') throw new DomainException('Bu nakliye kendi aracımızla yapılıyor; teslim bağlantısı yalnız dış nakliyeye gönderilir.');
    if (!in_array($yon, tbYonler($n), true)) throw new DomainException($yon === 'donus' ? 'Bu araç bir dönüş aracı değil.' : 'Bu araç bir gidiş aracı değil.');
    $bek = stAracMakineleri($db, $nid, tbIslem($yon));
    if (!$bek) throw new DomainException('Bu araçta ' . ($yon === 'donus' ? 'sahada teslim alınmayı' : 'sahada teslim edilmeyi')
        . ' bekleyen makine yok (araç ' . ($yon === 'donus' ? 'toplamaya çıkmamış' : 'yüklenmemiş') . ' ya da iş tamamlanmış); yeni bağlantı gerekmez.');

    $sahaSay = 0;
    if ($sAd !== '' || $sTel !== '') {
        $q = $db->prepare('SELECT saha_irtibat_ad, saha_irtibat_tel, siparis_no FROM kiralamalar WHERE id=?');
        $u = $db->prepare('UPDATE kiralamalar SET saha_irtibat_ad=?, saha_irtibat_tel=? WHERE id=?');
        $q->execute([(int)$bek[0]['kiralama_id']]);
        $hedefSiparis = trim((string)(($q->fetch(PDO::FETCH_ASSOC) ?: [])['siparis_no'] ?? ''));
        foreach (array_unique(array_map('intval', array_column($bek, 'kiralama_id'))) as $kid) {
            $q->execute([$kid]);
            $k = $q->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($kid !== (int)$bek[0]['kiralama_id'] && ($hedefSiparis === '' || trim((string)($k['siparis_no'] ?? '')) !== $hedefSiparis)) continue;
            $eskiAd = $k['saha_irtibat_ad'] ?? null; $eskiTel = $k['saha_irtibat_tel'] ?? null;
            $yeniAd = $sAd !== '' ? $sAd : $eskiAd; $yeniTel = $sTel !== '' ? $sTel : $eskiTel;
            if ($yeniAd === $eskiAd && $yeniTel === $eskiTel) continue;
            $u->execute([$yeniAd, $yeniTel, $kid]);
            logla($db, 'kiralamalar', $kid, 'guncelle', ['saha_irtibat_ad' => $eskiAd, 'saha_irtibat_tel' => $eskiTel],
                  ['saha_irtibat_ad' => $yeniAd, 'saha_irtibat_tel' => $yeniTel],
                  'Şantiye yetkilisi güncellendi (teslim bağlantısı yeniden gönderilirken): ' . trim((string)$yeniAd . ' ' . (string)$yeniTel));
            $sahaSay++;
        }
    }

    $say = $db->prepare("SELECT COUNT(*) AS toplam, SUM(CASE WHEN kullanildi=0 AND gecerlilik > datetime('now','localtime') THEN 1 ELSE 0 END) AS acik
                         FROM teslim_baglantilari WHERE nakliye_id=? AND yon=?");
    $say->execute([$nid, $yon]);
    $sy = $say->fetch(PDO::FETCH_ASSOC) ?: [];
    $acik = (int)($sy['acik'] ?? 0); $toplam = (int)($sy['toplam'] ?? 0);
    $ozet = ($yon === 'donus' ? 'Teslim alma' : 'Teslim') . ' bağlantısı yeniden gönderildi: ' . $ad . ($tel !== '' ? ' (' . $tel . ')' : '')
          . ($ep !== '' ? ' <' . $ep . '>' : '') . ' — '
          . ($acik > 0 ? 'önceki bağlantı geçersiz' : ($toplam > 0 ? 'önceki bağlantının süresi dolmuştu' : 'önceki bağlantı yoktu'))
          . ($not !== '' ? ' · Not: ' . $not : '');
    $url = _diBaglantiUret($db, $nid, $n, ['ad' => $ad, 'tel' => $tel, 'eposta' => $ep, 'not' => $not], $yon, $yapan, $ozet, false);
    return ['baglanti' => $url, 'yon' => $yon, 'nakliye' => $n, 'alici' => ['ad' => $ad, 'tel' => $tel, 'eposta' => $ep], 'not' => $not,
            'onceki_gecersiz' => $acik, 'saha_guncellenen' => $sahaSay, 'makine_adet' => count($bek)];
}

function tbMesaj(PDO $db, int $nid, string $yon, string $url, string $not, array $alici): array {
    try {
        $m = hkNakliyeciMetni($db, $nid, $yon, $url);
        $satirlar = explode("\n", $m['metin']);
        $satirlar[0] = 'Merhaba ' . $alici['ad'] . ',';
        $m = ['ad' => $alici['ad'], 'tel' => $alici['tel'] !== '' ? $alici['tel'] : null, 'metin' => implode("\n", $satirlar)];
    } catch (DomainException $e) {
        $m = ['ad' => $alici['ad'], 'tel' => $alici['tel'] !== '' ? $alici['tel'] : null,
              'metin' => 'Merhaba ' . $alici['ad'] . ",\n" . ($yon === 'donus' ? 'Teslim alma' : 'Teslim') . ' bağlantınız (48 saat geçerli): ' . $url];
    }
    if ($not !== '') $m['metin'] .= "\nNot: " . $not;
    $wa = $m['tel'] !== null ? waTelefon((string)$m['tel']) : null;
    $m['wa'] = $wa !== null ? 'https://wa.me/' . $wa . '?text=' . rawurlencode($m['metin']) : null;
    return $m;
}

function tbEposta(PDO $db, int $nid, string $ep, string $konu, string $metin): ?array {
    if ($ep === '') return null;
    $a = epostaAyarlari($db);
    if (($a['yontem'] ?? '') !== 'smtp' || trim((string)($a['host'] ?? '')) === '')
        return ['durum' => 'smtp_yok', 'mesaj' => 'E-posta sunucusu (SMTP) ayarlı değil; e-posta otomatik gönderilmedi. "E-posta ile gönder" düğmesiyle kendi e-posta programınızdan gönderin.'];
    $r = epostaGonder($db, $ep, $konu, $metin, 'teslim_baglanti');
    $ok = !empty($r['basarili']);
    logla($db, 'nakliyeler', $nid, 'guncelle', null, ['teslim_baglantisi_eposta' => $ep, 'basarili' => $ok],
          $ok ? 'Teslim bağlantısı e-postayla gönderildi: ' . $ep : 'Teslim bağlantısı e-postası gönderilemedi: ' . $ep);
    return $ok ? ['durum' => 'gonderildi', 'mesaj' => 'Bağlantı e-postayla gönderildi: ' . $ep]
               : ['durum' => 'hata', 'mesaj' => 'E-posta gönderilemedi: ' . (string)($r['hata'] ?? 'bilinmeyen hata')];
}

function tbTeslimUyarilari(PDO $db, array $rows): array {
    $ids = [];
    foreach ($rows as $r) if ((($r['tip'] ?? '') ?: 'dis') !== 'avk') $ids[] = (int)$r['id'];
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $sb = $db->prepare('SELECT MAX(zaman) FROM teslim_baglantilari WHERE nakliye_id=? AND yon=?');
    $out = [];
    foreach (['gidis' => 'yuklendi_zaman', 'donus' => 'toplama_zaman'] as $yon => $sut) {
        $q = $db->prepare("SELECT nm.nakliye_id, t.kabul_sonucu, t.created_at FROM nakliye_makineleri nm
                           JOIN iade_tutanaklari t ON t.kiralama_id=nm.kiralama_id AND t.islem=? AND t.created_at >= nm.$sut
                           WHERE nm.nakliye_id IN ($in) AND nm.$sut IS NOT NULL AND COALESCE(t.fiili_teslim,0)=0
                             AND t.kabul_sonucu IN ('" . implode("','", TB_SORUNLU) . "')
                           ORDER BY t.created_at DESC, t.id DESC");
        $q->execute(array_merge([tbIslem($yon)], $ids));
        $aday = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $t) $aday[(int)$t['nakliye_id']] ??= $t;
        foreach ($aday as $nid => $t) {
            if (isset($out[$nid])) continue;
            $sb->execute([$nid, $yon]);
            $son = $sb->fetchColumn();
            if ($son !== false && $son !== null && (string)$son > (string)$t['created_at']) continue;
            if (!stAracMakineleri($db, $nid, tbIslem($yon))) continue;
            $out[$nid] = ['yon' => $yon, 'kabul' => (string)$t['kabul_sonucu'], 'etiket' => tbKabulEtiket($t['kabul_sonucu']), 'zaman' => (string)$t['created_at']];
        }
    }
    return $out;
}
