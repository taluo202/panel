<?php
declare(strict_types=1);
require_once __DIR__ . '/hasar-akis.php';

function hfFiyatGoreviAc(PDO $db, int $hid): ?int {
    $r = hdGet($db, $hid);
    if (!$r || ($r['fiyat_durum'] ?? '') !== 'bekliyor' || $r['durum'] === 'iptal') return null;
    $gid = gorevOlustur($db, 'Fiyatlandır: ' . mb_substr((string)$r['ozet'], 0, 120),
        'Servis işi tamamlandı. Hasar kalemlerini fiyatlandırın ve fatura yöntemini seçin (ayrı fatura / hakedişe ekle).',
        'hasar_fiyat', $hid, $r['oncelik'] === 'yuksek' ? 'yuksek' : 'normal');
    if ((int)($r['fiyat_gorev_id'] ?? 0) !== $gid) {
        $db->prepare('UPDATE hasar_degerlendirmeleri SET fiyat_gorev_id=? WHERE id=?')->execute([$gid, $hid]);
        hdGecmis($db, $hid, 'fiyat_gorevi', 'Ofise "Fiyatlandır" görevi açıldı (görev #' . $gid . ').', ['gorev_id' => $gid]);
    }
    return $gid;
}
const HF_KDV_ORANLARI = [0, 1, 10, 20];

function hfSonuclandir(PDO $db, int $hid, string $aciklama): void {
    $r = hdGet($db, $hid);
    if (!$r || in_array($r['durum'], ['sonuclandi', 'iptal'], true)) return;
    if (!empty($r['servis_id']) && _hfServisDurum($db, $r) !== 'tamamlandi') return;
    $simdi = date('Y-m-d H:i:s');
    $db->prepare("UPDATE hasar_degerlendirmeleri SET durum='sonuclandi', karar=COALESCE(karar,'onarim'), sonuclanma=?, updated_at=? WHERE id=?")
       ->execute([$simdi, $simdi, $hid]);
    $db->prepare("UPDATE gorevler SET durum='tamamlandi', tamamlanma=?, updated_at=? WHERE kaynak_modul='hasar_degerlendirme' AND kaynak_id=? AND durum='acik'")
       ->execute([date('Y-m-d'), $simdi, $hid]);
    hdGorevSenkron($db, hdGet($db, $hid));
    hdGecmis($db, $hid, 'sonuclandi', $aciklama);
}

function hfPara(array $k): string {
    $p = strtoupper(trim((string)($k['doviz'] ?? '')));
    return ($p === '' || $p === 'TRY') ? 'TL' : $p;
}
function hfKdvVarsayilan(array $k): float {
    $o = (float)($k['kdv_oran'] ?? 20);
    return in_array($o, array_map('floatval', HF_KDV_ORANLARI), true) ? $o : 20.0;
}
function _hfTutar($v): float {
    if (!is_numeric($v) || !is_finite((float)$v) || (float)$v < 0 || (float)$v > 1e9) throw new DomainException('Tutar 0 ya da pozitif bir sayı olmalı.');
    return round((float)$v, 2);
}
function _hfKdv($v): float {
    if (!is_numeric($v) || !in_array((float)$v, array_map('floatval', HF_KDV_ORANLARI), true)) throw new DomainException('KDV oranı 0, 1, 10 ya da 20 olmalı.');
    return (float)$v;
}

function hfHesap(array $satirlar, float $kur = 1.0): array {
    $out = []; $ara = 0.0; $kdv = 0.0;
    foreach ($satirlar as $x) {
        $net = round(round((float)$x['tutar'], 2) * $kur, 2);
        $v = round($net * (float)$x['kdv_oran'] / 100, 2);
        $out[] = ['net' => $net, 'kdv' => $v] + $x;
        $ara += $net; $kdv += $v;
    }
    return ['satirlar' => $out, 'ara' => round($ara, 2), 'kdv' => round($kdv, 2), 'genel' => round($ara + $kdv, 2)];
}

function hfArizaKaydiMi(array $r): bool {
    return empty($r['tutanak_id']) && empty($r['depo_kontrol_id']) && ($r['bulgu_turu'] ?? '') === 'ariza';
}

function hfKayit(PDO $db, int $hid): array {
    $r = hdGet($db, $hid);
    if (!$r) throw new DomainException('Hasar kaydı bulunamadı.');
    if (hfArizaKaydiMi($r) && empty($r['fiyat_durum'])) throw new DomainException('Bu arıza kaydında müşteriye yansıtılacak bedel yok (kusur sahibi "müşteri" seçilince onarım bedeli fiyatlanır).');
    if (empty($r['depo_kontrol_id']) && !hfArizaKaydiMi($r)) throw new DomainException('Bu kayıt teslim alma tutanağından açıldı; ücret Teknik Servis faturasıyla ("Müşteriye yansıt") yansıtılır.');
    $s = $db->prepare('SELECT k.*, m.unvan AS musteri FROM kiralamalar k LEFT JOIN musteriler m ON m.id=k.musteri_id WHERE k.id=?');
    $s->execute([(int)$r['kiralama_id']]);
    $k = $s->fetch(PDO::FETCH_ASSOC);
    if (!$k) throw new DomainException('Hasarın kiralaması bulunamadı.');
    return [$r, $k];
}
function _hfServisDurum(PDO $db, array $r): ?string {
    if (empty($r['servis_id'])) return null;
    $s = $db->prepare('SELECT durum FROM teknik_servis WHERE id=?'); $s->execute([(int)$r['servis_id']]);
    return $s->fetchColumn() ?: null;
}
function hfEngel(array $r): ?string {
    if (($r['durum'] ?? '') === 'iptal' || ($r['fiyat_durum'] ?? '') === 'iptal') return 'İptal edilmiş hasar fiyatlandırılamaz.';
    if (!empty($r['servis_id']) && ($r['servis_durum'] ?? null) !== 'tamamlandi') return 'Servis işi tamamlanmadan fiyatlandırılamaz (servis #' . (int)$r['servis_id'] . ').';
    return null;
}
function hfHakedisFaturali(array $h): bool {
    return in_array($h['durum'] ?? '', ['faturalandi', 'tahsil_edildi'], true) || trim((string)($h['fatura_no'] ?? '')) !== '' || (int)($h['tahsilat'] ?? 0) > 0;
}
function hfFaturaKesildi(PDO $db, array $r): bool {
    if (($r['fatura_yontemi'] ?? '') === 'ayri' && !empty($r['fatura_kalem_id'])) {
        $s = $db->prepare('SELECT faturalandi FROM hasar_faturalari WHERE id=?'); $s->execute([(int)$r['fatura_kalem_id']]);
        return (int)$s->fetchColumn() === 1;
    }
    if (($r['fatura_yontemi'] ?? '') === 'hakedis' && !empty($r['hakedis_id'])) {
        $s = $db->prepare('SELECT h.durum, h.fatura_no, (SELECT COUNT(*) FROM tahsilatlar t WHERE t.hakedis_id=h.id) AS tahsilat FROM hakedisler h WHERE h.id=?'); $s->execute([(int)$r['hakedis_id']]);
        $h = $s->fetch(PDO::FETCH_ASSOC);
        return $h ? hfHakedisFaturali($h) : false;
    }
    return false;
}
function hfRef(array $r, array $k): string { return 'Hasar #' . (int)$r['id'] . ' · ' . ($k['kod'] ?: 'Kiralama #' . (int)$k['id']); }

function hfUygunHakedisler(PDO $db, array $k): array {
    $sip = trim((string)($k['siparis_no'] ?? ''));
    $s = $db->prepare("SELECT h.id, h.kod, h.donem_bas, h.donem_bit, h.durum, h.fatura_no, h.kdv_oran, kk.doviz,
                              (SELECT COUNT(*) FROM tahsilatlar t WHERE t.hakedis_id=h.id) AS tahsilat
                       FROM hakedisler h LEFT JOIN kiralamalar kk ON kk.id=h.kiralama_id
                       WHERE h.musteri_id=? AND (h.kiralama_id=? OR (?<>'' AND kk.siparis_no=?)) ORDER BY h.donem_bas DESC, h.id DESC");
    $s->execute([(int)$k['musteri_id'], (int)$k['id'], $sip, $sip]);
    return array_map(fn($h) => ['id' => (int)$h['id'], 'kod' => $h['kod'], 'donem_bas' => $h['donem_bas'], 'donem_bit' => $h['donem_bit'],
                                'durum' => $h['durum'], 'fatura_no' => $h['fatura_no'], 'kdv_oran' => (float)$h['kdv_oran'],
                                'para_birimi' => hfPara($h), 'faturali' => hfHakedisFaturali($h)], $s->fetchAll(PDO::FETCH_ASSOC));
}

function hfVeri(PDO $db, int $hid): array {
    [$r, $k] = hfKayit($db, $hid);
    $r['servis_durum'] = _hfServisDurum($db, $r);
    $s = $db->prepare('SELECT id, tur, aciklama, foto_ids, tutar, kdv_oran FROM hasar_kalemleri WHERE degerlendirme_id=? ORDER BY id'); $s->execute([$hid]);
    $kalemler = $s->fetchAll(PDO::FETCH_ASSOC);
    $servis = [];
    if (!empty($r['servis_id'])) {
        $s = $db->prepare('SELECT k.tur, k.aciklama, k.miktar, k.birim, k.saat, k.kisi, k.hasar_kalem_id, p.parca_no, p.fiyat AS stok_birim_fiyat
                           FROM teknik_servis_kalemleri k LEFT JOIN yedek_parca p ON p.id=k.parca_id WHERE k.servis_id=? ORDER BY k.id');
        $s->execute([(int)$r['servis_id']]); $servis = $s->fetchAll(PDO::FETCH_ASSOC);
    }
    $fatura = null;
    if (!empty($r['fatura_kalem_id'])) { $s = $db->prepare('SELECT * FROM hasar_faturalari WHERE id=?'); $s->execute([(int)$r['fatura_kalem_id']]); $fatura = $s->fetch(PDO::FETCH_ASSOC) ?: null; }
    $hakedis = null;
    if (!empty($r['hakedis_id'])) { $s = $db->prepare('SELECT id, kod, durum, fatura_no FROM hakedisler WHERE id=?'); $s->execute([(int)$r['hakedis_id']]); $hakedis = $s->fetch(PDO::FETCH_ASSOC) ?: null; }
    require_once __DIR__ . '/ariza-karar-lib.php';
    $onarim = ($r['kusur_sahibi'] ?? '') === 'musteri' ? akOnarimOneri($db, $r['servis_id'] ? (int)$r['servis_id'] : null) : null;
    return ['id' => (int)$r['id'], 'surum' => (int)$r['surum'], 'ozet' => $r['ozet'], 'kiralama_kod' => $k['kod'], 'musteri' => $k['musteri'],
            'para_birimi' => hfPara($k), 'kdv_varsayilan' => hfKdvVarsayilan($k), 'fiyat_durum' => $r['fiyat_durum'],
            'fatura_yontemi' => $r['fatura_yontemi'], 'servis_id' => $r['servis_id'] ? (int)$r['servis_id'] : null, 'servis_durum' => $r['servis_durum'],
            'engel' => hfEngel($r), 'kilitli' => hfFaturaKesildi($db, $r), 'kalemler' => $kalemler, 'servis_kalemleri' => $servis,
            'fatura' => $fatura, 'hakedis' => $hakedis, 'uygun_hakedisler' => hfUygunHakedisler($db, $k),
            'kusur_sahibi' => $r['kusur_sahibi'] ?? null, 'onarim_kalem_id' => $r['onarim_kalem_id'] ?? null, 'onarim_oneri' => $onarim];
}

function hfListe(PDO $db): array {
    $sube = subeKisitiHam();
    $rows = $db->query("SELECT h.id, h.ozet, h.kiralama_id, h.sube, h.durum, h.fiyat_durum, h.fatura_yontemi, h.servis_id, s.durum AS servis_durum,
                               h.created_at, k.kod AS kiralama_kod, m.unvan AS musteri
                        FROM hasar_degerlendirmeleri h JOIN kiralamalar k ON k.id=h.kiralama_id
                        LEFT JOIN musteriler m ON m.id=k.musteri_id LEFT JOIN teknik_servis s ON s.id=h.servis_id
                        WHERE (h.depo_kontrol_id IS NOT NULL OR (h.tutanak_id IS NULL AND h.bulgu_turu='ariza' AND h.fiyat_durum IS NOT NULL))
                          AND COALESCE(h.fiyat_durum,'')<>'iptal' AND h.durum<>'iptal' ORDER BY h.id")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        if ($sube !== null && $sube !== ($r['sube'] ?? '')) continue;
        $r['engel'] = hfEngel($r);
        $out[] = $r;
    }
    return $out;
}

function hfHakedisTutarDegis(PDO $db, int $hakedisId, float $fark, string $neden): void {
    $s = $db->prepare('SELECT h.*, (SELECT COUNT(*) FROM tahsilatlar t WHERE t.hakedis_id=h.id) AS tahsilat FROM hakedisler h WHERE h.id=?'); $s->execute([$hakedisId]); $h = $s->fetch(PDO::FETCH_ASSOC);
    if (!$h) throw new DomainException('Hakediş bulunamadı.');
    if (hfHakedisFaturali($h)) throw new DomainException('Hakediş ' . ($h['kod'] ?: '#' . $hakedisId) . ' faturası kesilmiş ya da tahsilat almış; değişmez. "Ayrı fatura" seçin.');
    $eski = logKayitAl($db, 'hakedisler', $hakedisId);
    $tutar = round((float)$h['tutar'] + $fark, 2);
    $c = hakedisTutarHesapla($tutar, (float)$h['kdv_oran'], (bool)$h['tevkifat_var'], $h['tevkifat_orani']);
    $db->prepare('UPDATE hakedisler SET tutar=?, kdv_tutar=?, tevkifat_var=?, tevkifat_orani=?, tevkifat_tutari=?, musteriden_tahsil_kdv=?, genel_toplam=? WHERE id=?')
       ->execute([$tutar, $c['kdv_tutar'], $c['tevkifat_var'], $c['tevkifat_orani'], $c['tevkifat_tutari'], $c['musteriden_tahsil_kdv'], $c['genel_toplam'], $hakedisId]);
    logla($db, 'hakedisler', $hakedisId, 'guncelle', $eski, logKayitAl($db, 'hakedisler', $hakedisId), 'Hakediş ' . ($h['kod'] ?: '#' . $hakedisId) . ': ' . $neden);
}

function hfBagKaldir(PDO $db, array $r): void {
    if (($r['fatura_yontemi'] ?? '') === 'hakedis' && !empty($r['hakedis_kalem_id'])) {
        $s = $db->prepare('SELECT id, hakedis_id, tutar FROM hakedis_kalemleri WHERE id=?'); $s->execute([(int)$r['hakedis_kalem_id']]);
        if ($kl = $s->fetch(PDO::FETCH_ASSOC)) {
            $db->prepare('DELETE FROM hakedis_kalemleri WHERE id=?')->execute([(int)$kl['id']]);
            hfHakedisTutarDegis($db, (int)$kl['hakedis_id'], -(float)$kl['tutar'], 'hasar bedeli satırı çıkarıldı (Hasar #' . (int)$r['id'] . ')');
        }
    }
    if (($r['fatura_yontemi'] ?? '') === 'ayri' && !empty($r['fatura_kalem_id'])) {
        $db->prepare('DELETE FROM hasar_faturalari WHERE id=? AND faturalandi=0')->execute([(int)$r['fatura_kalem_id']]);
    }
}

function hfFiyatKaydet(PDO $db, int $hid, array $b): array {
    islemKilitAl($db);
    [$r, $k] = hfKayit($db, $hid);
    if (hfFaturaKesildi($db, $r)) throw new DomainException('Bu hasarın faturası kesildi; fiyat değiştirilemez. Düzeltme için önce Faturalandırma → Hasar bedeli\'nde fatura işaretini kaldırın.');
    if ((int)($b['surum'] ?? -1) !== (int)$r['surum']) throw new DomainException('Bu kayıt başka bir işlemle değişti. Yenileyip tekrar fiyatlandırın.');
    if ($e = hfEngel($r + ['servis_durum' => _hfServisDurum($db, $r)])) throw new DomainException($e);
    $yontem = (string)($b['yontem'] ?? '');
    if (!in_array($yontem, ['ayri', 'hakedis', 'yok'], true)) throw new DomainException('Fatura yöntemini seçin: ayrı fatura, hakedişe ekle ya da ücret yansıtılmaz.');
    $para = hfPara($k);
    $girdi = [];
    foreach ((array)($b['kalemler'] ?? []) as $x) $girdi[(int)($x['id'] ?? 0)] = $x;
    $s = $db->prepare('SELECT id, aciklama FROM hasar_kalemleri WHERE degerlendirme_id=? ORDER BY id'); $s->execute([$hid]);
    $satir = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $x = $girdi[(int)$m['id']] ?? null;
        if (!$x) throw new DomainException('Her bulgu kalemine tutar girin (0 olabilir): "' . $m['aciklama'] . '".');
        $satir[] = ['kalem_id' => (int)$m['id'], 'aciklama' => $m['aciklama'], 'tutar' => _hfTutar($x['tutar'] ?? null), 'kdv_oran' => _hfKdv($x['kdv_oran'] ?? null)];
        unset($girdi[(int)$m['id']]);
    }
    if ($girdi) throw new DomainException('Bu kayda ait olmayan kalem gönderildi.');
    $h = hfHesap($satir);
    $gerekce = mb_substr(trim((string)($b['gerekce'] ?? '')), 0, 1000);
    if ($yontem === 'yok') {
        if ($h['ara'] > 0) throw new DomainException('Ücret yansıtılmayacaksa bütün tutarlar 0 olmalı.');
        if (mb_strlen($gerekce) < 5) throw new DomainException('Ücret yansıtılmama gerekçesini yazın (en az 5 karakter).');
    } elseif ($h['ara'] <= 0) throw new DomainException('Toplam tutar 0; fatura kesilemez. "Ücret yansıtılmaz" seçin.');
    hfBagKaldir($db, $r);
    $up = $db->prepare('UPDATE hasar_kalemleri SET tutar=?, kdv_oran=?, para_birimi=? WHERE id=?');
    foreach ($satir as $x) $up->execute([$x['tutar'], $x['kdv_oran'], $para, $x['kalem_id']]);
    $ref = hfRef($r, $k); $simdi = date('Y-m-d H:i:s');
    $hakedisId = null; $hkId = null; $fkId = null;
    if ($yontem === 'ayri') {
        $db->prepare('INSERT INTO hasar_faturalari(degerlendirme_id,kiralama_id,musteri_id,sube,ref,para_birimi,kalemler,ara_toplam,kdv_tutar,genel_toplam,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$hid, (int)$k['id'], (int)$k['musteri_id'], $k['sube'] ?? null, $ref, $para, json_encode($h['satirlar'], JSON_UNESCAPED_UNICODE), $h['ara'], $h['kdv'], $h['genel'], $simdi, $simdi]);
        $fkId = (int)$db->lastInsertId();
    }
    if ($yontem === 'hakedis') {
        $hakedisId = (int)($b['hakedis_id'] ?? 0);
        $uygun = array_column(hfUygunHakedisler($db, $k), null, 'id');
        $hk = $uygun[$hakedisId] ?? null;
        if (!$hk) throw new DomainException('Seçilen hakediş bu kiralamaya (ya da siparişine) ait değil.');
        if ($hk['faturali']) throw new DomainException('Hakediş ' . ($hk['kod'] ?: '#' . $hakedisId) . ' faturası kesilmiş; kesilmiş fatura değişmez. "Ayrı fatura" seçin.');
        if ($hk['para_birimi'] !== $para) throw new DomainException('Hakediş ' . $hk['para_birimi'] . ', hasar ' . $para . ' cinsinden; ayrı fatura seçin.');
        foreach ($satir as $x) if ($x['tutar'] > 0 && abs($x['kdv_oran'] - $hk['kdv_oran']) > 0.001)
            throw new DomainException('Hakedişin KDV oranı %' . (float)$hk['kdv_oran'] . '; "' . $x['aciklama'] . '" %' . (float)$x['kdv_oran'] . ' — farklı oranlı kalem hakedişe eklenemez; ayrı fatura seçin.');
        $db->prepare("INSERT INTO hakedis_kalemleri(hakedis_id,tur,aciklama,miktar,birim,tutar) VALUES(?,'hasar',?,1,?,?)")
           ->execute([$hakedisId, 'Hasar bedeli – ' . $ref, $h['ara'], $h['ara']]);
        $hkId = (int)$db->lastInsertId();
        hfHakedisTutarDegis($db, $hakedisId, $h['ara'], 'hasar bedeli eklendi (Hasar #' . $hid . ')');
    }
    $durum = $yontem === 'yok' ? 'ucretsiz' : 'fiyatlandi';
    $u = $db->prepare('UPDATE hasar_degerlendirmeleri SET fiyat_durum=?, fatura_yontemi=?, hakedis_id=?, hakedis_kalem_id=?, fatura_kalem_id=?, fiyat_zaman=?, fiyatlayan=?, surum=surum+1, updated_at=? WHERE id=? AND surum=?');
    $u->execute([$durum, $yontem, $hakedisId, $hkId, $fkId, $simdi, (string)($_SESSION['username'] ?? 'Sistem'), $simdi, $hid, (int)$r['surum']]);
    if ($u->rowCount() !== 1) throw new DomainException('Bu kayıt başka bir işlemle değişti. Yenileyip tekrar fiyatlandırın.');
    if (!empty($r['fiyat_gorev_id'])) $db->prepare("UPDATE gorevler SET durum='tamamlandi', tamamlanma=?, updated_at=? WHERE id=? AND durum='acik'")->execute([date('Y-m-d'), $simdi, (int)$r['fiyat_gorev_id']]);
    $yAd = ['ayri' => 'ayrı fatura', 'hakedis' => 'hakedişe eklendi', 'yok' => 'ücret yansıtılmadı'][$yontem];
    hdGecmis($db, $hid, 'fiyat', 'Fiyatlandırıldı: ' . $yAd . ($gerekce !== '' ? ' — ' . $gerekce : '') . '.', ['yontem' => $yontem, 'hakedis_id' => $hakedisId, 'fatura_kalem_id' => $fkId]);
    hfSonuclandir($db, $hid, 'Servis tamamlandı, hasar fiyatlandı (' . $yAd . '); değerlendirme sonuçlandı.');
    logla($db, 'hasar_degerlendirmeleri', $hid, 'guncelle', null, ['kalemler' => $h['satirlar'], 'ara' => $h['ara'], 'kdv' => $h['kdv'], 'genel' => $h['genel'], 'para_birimi' => $para, 'yontem' => $yontem, 'hakedis_id' => $hakedisId],
          'Hasar fiyatlandırıldı (' . $yAd . ') — ' . $ref);
    require_once __DIR__ . '/hasar-belge-lib.php';
    hbHbb($db, $hid);
    return ['id' => $hid, 'fiyat_durum' => $durum, 'fatura_yontemi' => $yontem, 'hakedis_id' => $hakedisId, 'fatura_kalem_id' => $fkId,
            'surum' => (int)$r['surum'] + 1, 'mesaj' => 'Hasar fiyatlandı: ' . $yAd . '.'];
}

function hfFaturaIsaretle(PDO $db, array $ids, array $b): int {
    islemKilitAl($db);
    require_once __DIR__ . '/filo-referans-lib.php';
    frSema($db);
    $ids = array_values(array_filter(array_map('intval', $ids), fn($x) => $x > 0));
    if (!$ids) throw new DomainException('Kayıt seçilmedi.');
    $ft = dateStr('fatura_tarihi', $b) ?: date('Y-m-d');
    $fno = trim(metin($b['fatura_no'] ?? '')) ?: null;
    $ftip = ($b['fatura_tipi'] ?? 'resmi') === 'elden' ? 'elden' : 'resmi';
    $where = ['id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', 'faturalandi=0']; $params = $ids;
    subeKosulEkle($where, $params, 'sube');
    $s = $db->prepare('SELECT * FROM hasar_faturalari WHERE ' . implode(' AND ', $where)); $s->execute($params);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) throw new DomainException('Seçilen kayıtlarda faturalanacak hasar bedeli bulunamadı.');
    $refs = frVeri($db); $simdi = date('Y-m-d H:i:s');
    $elleKur = null;
    if (isset($b['kur']) && trim((string)$b['kur']) !== '') {
        $kv = str_replace(',', '.', trim((string)$b['kur']));
        if (!is_numeric($kv) || (float)$kv <= 0 || (float)$kv > 100000) throw new DomainException('Kur pozitif bir sayı olmalı (ör. 38,5).');
        if (!hasPermission('finans', 'yaz') || subeKisiti() !== null) throw new DomainException('Kuru elle girmek için merkez finans yazma yetkisi gerekir. Finans yetkilisi kuru buradan ya da Filo Referans → Tarihli döviz kurları bölümünden girebilir.');
        $elleKur = round((float)$kv, 6);
    }
    foreach ($rows as $f) {
        $kur = frKur($refs, $ft, $f['para_birimi']);
        if (($kur === null || $kur <= 0) && $elleKur !== null && $f['para_birimi'] !== 'TL') {
            $db->prepare('INSERT INTO filo_kur_gecmisi(tarih,para,kur,kaynak,kullanici,kayit_zamani) VALUES(?,?,?,?,?,?)')
               ->execute([$ft, $f['para_birimi'], $elleKur, 'elle (hasar faturası)', (string)($_SESSION['username'] ?? 'Sistem'), $simdi]);
            logla($db, 'filo_kur_gecmisi', (int)$db->lastInsertId(), 'ekle', null, ['tarih' => $ft, 'para' => $f['para_birimi'], 'kur' => $elleKur], 'Döviz kuru elle girildi (hasar faturası) — ' . $f['para_birimi'] . ' ' . $ft . ' = ' . $elleKur);
            $refs = frVeri($db); $kur = $elleKur;
        }
        if ($kur === null || $kur <= 0) throw new DomainException($f['ref'] . ': fatura günü ' . $ft . ' için ' . $f['para_birimi'] . ' TCMB satış kuru kayıtlı değil (Filo Referans → Tarihli döviz kurları). Kuru bu ekranda elle de girebilirsiniz.');
        $h = hfHesap(json_decode((string)$f['kalemler'], true) ?: [], $kur);
        $db->prepare("UPDATE hasar_faturalari SET faturalandi=1, fatura_no=?, fatura_tarihi=?, fatura_tipi=?, fatura_durum='kesildi', fatura_hata_notu=NULL,
                      kur=?, kur_tarihi=?, tl_ara=?, tl_kdv=?, tl_genel=?, kalemler=?, updated_at=? WHERE id=?")
           ->execute([$fno, $ft, $ftip, $kur, $ft, $h['ara'], $h['kdv'], $h['genel'], json_encode($h['satirlar'], JSON_UNESCAPED_UNICODE), $simdi, (int)$f['id']]);
        $db->prepare("UPDATE hasar_degerlendirmeleri SET fiyat_durum='faturalandi', surum=surum+1, updated_at=? WHERE id=?")->execute([$simdi, (int)$f['degerlendirme_id']]);
        hdGecmis($db, (int)$f['degerlendirme_id'], 'faturalandi', 'Ayrı hasar faturası kesildi' . ($fno ? ' (' . $fno . ')' : '') . '.', ['fatura_kalem_id' => (int)$f['id']]);
        hfSonuclandir($db, (int)$f['degerlendirme_id'], 'Ayrı hasar faturası kesildi; değerlendirme sonuçlandı.');
        logla($db, 'hasar_faturalari', (int)$f['id'], 'guncelle', $f, ['faturalandi' => 1, 'fatura_no' => $fno, 'fatura_tarihi' => $ft, 'kur' => $kur, 'tl_genel' => $h['genel']],
              'Hasar bedeli FATURALANDI — ' . $f['ref'] . ' · ' . $f['para_birimi'] . ($f['para_birimi'] !== 'TL' ? ' · kur ' . $kur : '') . ' · TL ' . number_format($h['genel'], 2, ',', '.'));
    }
    return count($rows);
}

function hfFaturaKaldir(PDO $db, array $ids): int {
    islemKilitAl($db);
    $ids = array_values(array_filter(array_map('intval', $ids), fn($x) => $x > 0));
    if (!$ids) throw new DomainException('Kayıt seçilmedi.');
    $where = ['id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', 'faturalandi=1']; $params = $ids;
    subeKosulEkle($where, $params, 'sube');
    $s = $db->prepare('SELECT * FROM hasar_faturalari WHERE ' . implode(' AND ', $where)); $s->execute($params);
    $simdi = date('Y-m-d H:i:s'); $n = 0;
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $db->prepare('UPDATE hasar_faturalari SET faturalandi=0, fatura_no=NULL, fatura_tarihi=NULL, fatura_tipi=NULL, fatura_durum=NULL, fatura_hata_notu=NULL,
                      kur=NULL, kur_tarihi=NULL, tl_ara=NULL, tl_kdv=NULL, tl_genel=NULL, updated_at=? WHERE id=?')->execute([$simdi, (int)$f['id']]);
        $db->prepare("UPDATE hasar_degerlendirmeleri SET fiyat_durum='fiyatlandi', surum=surum+1, updated_at=? WHERE id=?")->execute([$simdi, (int)$f['degerlendirme_id']]);
        hdGecmis($db, (int)$f['degerlendirme_id'], 'fatura_kaldirildi', 'Ayrı hasar faturasının işareti kaldırıldı.', ['fatura_kalem_id' => (int)$f['id']]);
        logla($db, 'hasar_faturalari', (int)$f['id'], 'guncelle', $f, ['faturalandi' => 0], 'Hasar bedeli FATURA İŞARETİ KALDIRILDI (cari borç düşer) — ' . $f['ref']);
        $n++;
    }
    return $n;
}

function hfIptal(PDO $db, array $r): void {
    if (empty($r['depo_kontrol_id'])) return;
    islemKilitAl($db);
    if (hfFaturaKesildi($db, $r)) throw new DomainException('Bu hasarın faturası kesildi; iptal edilemez. Önce fatura işaretini kaldırın (hakedişteyse hakediş faturası değişmez).');
    hfBagKaldir($db, $r);
    $simdi = date('Y-m-d H:i:s');
    $db->prepare("UPDATE hasar_degerlendirmeleri SET fiyat_durum='iptal', fatura_yontemi=NULL, hakedis_id=NULL, hakedis_kalem_id=NULL, fatura_kalem_id=NULL, updated_at=? WHERE id=?")->execute([$simdi, (int)$r['id']]);
    if (!empty($r['fiyat_gorev_id'])) $db->prepare("UPDATE gorevler SET durum='tamamlandi', tamamlanma=?, updated_at=? WHERE id=? AND durum='acik'")->execute([date('Y-m-d'), $simdi, (int)$r['fiyat_gorev_id']]);
    logla($db, 'hasar_degerlendirmeleri', (int)$r['id'], 'guncelle', null, ['fiyat_durum' => 'iptal'], 'Hasar iptal edildi; bekleyen fiyat/fatura bağı kaldırıldı — Hasar #' . (int)$r['id']);
    require_once __DIR__ . '/hasar-belge-lib.php';
    hbHbb($db, (int)$r['id']);
}

function hfHakedisSilindi(PDO $db, int $hakedisId): void {
    $s = $db->prepare("SELECT id FROM hasar_degerlendirmeleri WHERE hakedis_id=? AND fatura_yontemi='hakedis'"); $s->execute([$hakedisId]);
    $simdi = date('Y-m-d H:i:s');
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $hid) {
        $db->prepare("UPDATE hasar_degerlendirmeleri SET fiyat_durum='bekliyor', fatura_yontemi=NULL, hakedis_id=NULL, hakedis_kalem_id=NULL, surum=surum+1, updated_at=? WHERE id=?")->execute([$simdi, (int)$hid]);
        hdGecmis($db, (int)$hid, 'hakedis_silindi', 'Bağlı hakediş silindi; hasar yeniden fiyatlandırılmalı.', ['hakedis_id' => $hakedisId]);
        hfFiyatGoreviAc($db, (int)$hid);
    }
}
