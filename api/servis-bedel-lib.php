<?php
declare(strict_types=1);
require_once __DIR__ . '/servis-maliyet-lib.php';
require_once __DIR__ . '/musteri-erisim-lib.php';
require_once __DIR__ . '/belge-pdf-lib.php';

const SB_FIYATLI = ['fiyatlandi', 'ucretsiz'];
const SB_TIP = ['ariza' => 'Arıza', 'bakim' => 'Bakım', 'expertiz' => 'Expertiz', 'eksik_parca' => 'Eksik parça'];
const SB_YER = ['atolye' => 'Atölye', 'saha' => 'Saha (müşteri adresi)'];
const SB_DEVIR_ETIKET = 'Servis bedeli';
const SB_UCRETSIZ_NOT = 'Ücretsiz (garanti / iyi niyet)';

function sbSema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS servis_bedel_gonderimleri (
        id INTEGER PRIMARY KEY AUTOINCREMENT, servis_id INTEGER NOT NULL, musteri_id INTEGER NOT NULL, belge_no TEXT NOT NULL,
        kanal TEXT NOT NULL, alici TEXT, bicim TEXT NOT NULL, genel_toplam REAL, icerik TEXT NOT NULL, gonderen TEXT, created_at TEXT NOT NULL);
      CREATE INDEX IF NOT EXISTS idx_sbg_servis ON servis_bedel_gonderimleri(servis_id);");
}

function sbBelgeNo(array $s): string {
    $kaynak = trim((string)($s['created_at'] ?? '')) !== '' ? (string)$s['created_at'] : (string)($s['tarih'] ?? '');
    $yil = preg_match('/^(\d{4})/', $kaynak, $m) ? $m[1] : date('Y');
    return sprintf('SBD-%s-%05d', $yil, (int)$s['id']);
}

function sbKayit(PDO $db, int $sid): array {
    $q = $db->prepare("SELECT s.id, s.tarih, s.tamamlanma, s.created_at, s.tip, s.durum, s.ariza, s.yapilan, s.makine_tur, s.makine_id, s.makine_ad,
                              s.musteri_makine_id, s.kiralama_id, s.musteri_id AS servis_musteri_id, s.fiyat_durum, s.kaynak, s.musteriye_yansit,
                              s.yer, s.saha_adres, s.saha_il, s.saha_ilce, s.faturalandi, s.fatura_no, s.fatura_yontemi, s.hakedis_id, hk.kod AS hakedis_kod, p.ad_soyad AS teknisyen_ad, sn.ad AS santiye_ad,
                              k.kod AS kiralama_kod, k.siparis_no, k.lokasyon, k.baslangic AS kira_baslangic, COALESCE(k.gercek_bitis, k.bitis) AS kira_bitis,
                              k.musteri_id AS kiralama_musteri_id,
                              m.id AS musteri_id, m.unvan, m.adres, m.vergi_dairesi, m.vergi_no, m.telefon AS musteri_telefon, m.email AS musteri_email,
                              CASE WHEN k.id IS NOT NULL AND (s.musteri_id IS NULL OR s.musteri_id = k.musteri_id) THEN COALESCE(k.sube, m.sube) ELSE m.sube END AS sube,
                              ml.kod AS ml_kod, ml.marka AS ml_marka, ml.model AS ml_model, ml.seri_no AS ml_seri, ml.kategori AS ml_kategori, ml.metraj AS ml_metraj, ml.makine_kodu AS ml_stok,
                              a.plaka AS a_plaka, a.marka AS a_marka, a.model AS a_model, a.ruhsat_no AS a_ruhsat, a.kategori AS a_kategori, a.metraj AS a_metraj, a.makine_kodu AS a_stok,
                              mm.marka AS mm_marka, mm.model AS mm_model, mm.seri_no AS mm_seri, mm.kategori AS mm_kategori
                       FROM teknik_servis s
                       LEFT JOIN personel p ON p.id = s.teknisyen_id
                       LEFT JOIN santiyeler sn ON sn.id = s.santiye_id
                       LEFT JOIN kiralamalar k ON k.id = s.kiralama_id
                       LEFT JOIN musteriler m ON m.id = COALESCE(s.musteri_id, k.musteri_id)
                       LEFT JOIN manliftler ml ON s.makine_tur = 'manlift' AND ml.id = s.makine_id
                       LEFT JOIN araclar a ON s.makine_tur = 'arac' AND a.id = s.makine_id
                       LEFT JOIN musteri_makineleri mm ON mm.id = s.musteri_makine_id
                       LEFT JOIN hakedisler hk ON hk.id = s.hakedis_id
                       WHERE s.id = ?");
    $q->execute([$sid]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new DomainException('Servis kaydı bulunamadı.', 404);
    return $r;
}

function sbErisimDogrula(PDO $db, array $r): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!empty($_SESSION['user_id'])) {
        if (!smFiyatErisimi($db, (int)$r['id']))
            throw new DomainException('Servis bedeli dökümünü kiralama yazma yetkisi olan ve servisin şubesine erişebilen kullanıcı açar.', 403);
        return;
    }
    if (empty($r['musteri_id']) || !meBelgeGorur($db, (int)$r['musteri_id'], $r['sube'] !== null ? (string)$r['sube'] : null))
        throw new DomainException('Belge bulunamadı.', 404);
}

function sbEngel(PDO $db, array $r): ?string {
    if (!in_array((string)($r['fiyat_durum'] ?? ''), SB_FIYATLI, true))
        return 'Servis henüz fiyatlandırılmadı; servis bedeli dökümü müşteri fiyatı kaydedilince hazırlanır.';
    $akis = smAkis($db, ['id' => (int)$r['id'], 'kaynak' => $r['kaynak'] ?? null, 'musteri_makine_id' => $r['musteri_makine_id'] ?? null, 'musteriye_yansit' => $r['musteriye_yansit'] ?? 0]);
    if ($akis === 'hasar') return 'Bu servisin müşteri bedeli hasar dosyasıyla bildirilir; servis bedeli dökümü hazırlanmaz.';
    if ($akis === 'ic') return 'Servis müşteriye yansıtılmıyor; servis bedeli dökümü yalnız müşteriye yansıtılan serviste hazırlanır.';
    if (empty($r['musteri_id'])) return 'Servise müşteri bağlı değil; servis bedeli dökümü müşteriye düzenlenir.';
    return null;
}

function _sbMetin(mixed $v): string { return is_scalar($v) ? trim((string)$v) : ''; }

function sbMakine(PDO $db, array $r): array {
    $t = '_sbMetin';
    $musteri = !empty($r['musteri_makine_id']);
    $arac = !$musteri && ($r['makine_tur'] ?? '') === 'arac';
    if ($musteri) {
        [$kod, $marka, $model, $seri, $kat, $metraj] = ['', $t($r['mm_marka']), $t($r['mm_model']), $t($r['mm_seri']), $t($r['mm_kategori']), null];
    } elseif ($arac) {
        [$kod, $marka, $model, $seri, $kat, $metraj] = [$t($r['a_plaka']), $t($r['a_marka']), $t($r['a_model']), $t($r['a_ruhsat']), $t($r['a_kategori']) ?: 'Araç', metrajSayi($r['a_metraj'])];
    } else {
        [$kod, $marka, $model, $seri, $kat, $metraj] = [$t($r['ml_kod']), $t($r['ml_marka']), $t($r['ml_model']), $t($r['ml_seri']), $t($r['ml_kategori']), metrajSayi($r['ml_metraj'])];
        if ($kat === '' && ($r['makine_tur'] ?? '') === 'manlift' && ($marka !== '' || $model !== ''))
            $kat = (string)(makineKategoriAdi($db, ['kategori' => null, 'marka' => $marka, 'model' => $model]) ?? '');
    }
    return ['ad' => $t($r['makine_ad']), 'kod' => $kod, 'kod_etiket' => $arac ? 'Plaka' : 'Makine Kodu', 'marka_model' => trim($marka . ' ' . $model),
            'kategori' => $kat, 'seri_no' => $seri, 'seri_etiket' => $arac ? 'Ruhsat No' : 'Seri No',
            'metraj' => $metraj !== null ? trim(bpSayi($metraj) . ' ' . mkBirim($db, $kat !== '' ? $kat : null)) : '',
            'sahip' => $musteri ? 'Müşterinin makinesi' : '', 'stok_kodu' => $musteri ? '' : $t($arac ? ($r['a_stok'] ?? '') : ($r['ml_stok'] ?? ''))];
}

function _sbSatirAdi(string $a): string { return $a === SM_DEVIR_ACIKLAMA ? SB_DEVIR_ETIKET : $a; }

function sbBelge(PDO $db, array $r): array {
    if ($e = sbEngel($db, $r)) throw new DomainException($e, 409);
    $t = '_sbMetin';
    $sid = (int)$r['id'];
    $ucretsiz = $r['fiyat_durum'] === 'ucretsiz';
    $g = smMusteriGorunumu($db, $sid);
    $satirlar = $ucretsiz
        ? array_map(fn($x) => ['aciklama' => _sbSatirAdi((string)$x['aciklama']), 'miktar' => (float)$x['miktar'], 'birim' => (string)($x['birim'] ?? '')], smFiyatSatirlari($db, $sid))
        : array_map(fn($x) => ['aciklama' => _sbSatirAdi((string)$x['aciklama'])] + $x, $g['satirlar']);
    $kiralama = null;
    if (!empty($r['kiralama_id']) && (empty($r['servis_musteri_id']) || (int)$r['servis_musteri_id'] === (int)$r['kiralama_musteri_id'])) {
        $bas = bpVar($r['kira_baslangic'] ?? null) ? bpTarih($r['kira_baslangic']) : '';
        $bit = bpVar($r['kira_bitis'] ?? null) ? bpTarih($r['kira_bitis']) : '';
        $kiralama = ['kod' => $t($r['kiralama_kod']), 'siparis_no' => $t($r['siparis_no']), 'santiye' => $t($r['lokasyon']),
                     'donem' => trim($bas . ($bit !== '' ? ' – ' . $bit : ($bas !== '' ? ' – devam ediyor' : '')))];
    }
    $yer = $t($r['yer']) ?: 'atolye';
    $fq = $db->prepare('SELECT rapor_no FROM servis_ziyaretleri WHERE servis_id=? AND rapor_no IS NOT NULL ORDER BY id');
    $fq->execute([$sid]);
    $formlar = implode(', ', $fq->fetchAll(PDO::FETCH_COLUMN));
    return [
        'no' => sbBelgeNo($r), 'servis_id' => $sid, 'servis_no' => 'SERVIS-' . $sid, 'musteri_id' => (int)$r['musteri_id'],
        'sube' => $r['sube'] !== null ? (string)$r['sube'] : null, 'fiyat_durum' => (string)$r['fiyat_durum'], 'ucretsiz' => $ucretsiz, 'para_birimi' => 'TL',
        'musteri' => ['unvan' => $t($r['unvan']), 'adres' => $t($r['adres']), 'vergi_dairesi' => $t($r['vergi_dairesi']), 'vergi_no' => $t($r['vergi_no'])],
        'makine' => sbMakine($db, $r),
        'kiralama' => $kiralama,
        'servis' => ['tip' => SB_TIP[$t($r['tip'])] ?? $t($r['tip']), 'tarih' => $t($r['tarih']), 'tamamlanma' => $t($r['tamamlanma']), 'teknisyen' => $t($r['teknisyen_ad']),
                     'yer' => SB_YER[$yer] ?? $yer,
                     'adres' => $yer === 'saha' ? trim($t($r['saha_adres']) . ' ' . implode(' / ', array_filter([$t($r['saha_ilce']), $t($r['saha_il'])]))) : '',
                     'santiye' => $t($r['santiye_ad']), 'ariza' => $t($r['ariza']), 'yapilan' => $t($r['yapilan']),
                     'fatura_no' => ($r['fatura_yontemi'] ?? '') === 'hakedis' && $t($r['hakedis_kod'] ?? '') !== '' ? 'Hakediş ' . $t($r['hakedis_kod']) . ' ile faturalanır' : ((int)($r['faturalandi'] ?? 0) === 1 ? $t($r['fatura_no']) : ''), 'formlar' => $formlar],
        'satirlar' => $satirlar,
        'toplam' => $g['toplam'],
        'iskonto' => $ucretsiz ? null : $g['iskonto'],
        'not' => $ucretsiz ? SB_UCRETSIZ_NOT : null,
    ];
}

function sbPdf(PDO $db, array $b): array {
    $c = new BelgePdf($db, []);
    $c->servisBedel($b);
    return ['ad' => bpDosyaAdi((string)$b['no']), 'tur' => 'application/pdf', 'veri' => $c->cikti()];
}

function sbPortalLink(int $sid): string {
    $sema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || TRUSTED_HOST !== '' ? 'https' : 'http';
    return $sema . '://' . guvenliHost() . siteKokYolu() . '/portal-giris.html?donus=' . rawurlencode('portal.html?servis=' . $sid);
}

function sbMesaj(array $b, ?string $link): string {
    $m = $b['makine'];
    $makine = trim(implode(' ', array_filter([$m['kod'], $m['marka_model']]))) ?: ($m['ad'] ?: 'makine');
    $bas = 'Sayın ' . $b['musteri']['unvan'] . ', ' . $makine . ' için yapılan servisin bedel dökümü (' . $b['no'] . ')';
    $tutar = $b['ucretsiz'] ? ' Servis ücretsiz yapılmıştır (garanti / iyi niyet).' : ' Genel toplam: ' . bpPara($b['toplam']['genel'], $b['para_birimi']) . ' (KDV dahil).';
    return $link === null ? $bas . ' ekte PDF olarak gönderilmiştir.' . $tutar : $bas . ' müşteri portalınızdadır.' . $tutar . ' Bağlantı: ' . $link;
}

function sbGonderimler(PDO $db, int $sid): array {
    sbSema($db);
    $q = $db->prepare('SELECT id, belge_no, kanal, alici, bicim, genel_toplam, gonderen, created_at FROM servis_bedel_gonderimleri WHERE servis_id=? ORDER BY id DESC');
    $q->execute([$sid]);
    return array_map(fn($g) => ['id' => (int)$g['id'], 'genel_toplam' => $g['genel_toplam'] !== null ? (float)$g['genel_toplam'] : null] + $g, $q->fetchAll(PDO::FETCH_ASSOC));
}

function sbBilgi(PDO $db, array $r): array {
    $engel = sbEngel($db, $r);
    $b = $engel === null ? sbBelge($db, $r) : null;
    $mid = (int)($r['musteri_id'] ?? 0);
    $kon = [];
    if ($mid) {
        $k = $db->prepare("SELECT telefon, email FROM musteri_kontaklari WHERE musteri_id=? AND durum='aktif' ORDER BY (sifre_hash IS NULL), id LIMIT 1");
        $k->execute([$mid]);
        $kon = $k->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    return [
        'engel' => $engel,
        'belge' => $b ? ['no' => $b['no'], 'servis_id' => $b['servis_id'], 'musteri' => $b['musteri']['unvan'], 'ucretsiz' => $b['ucretsiz'],
                         'genel_toplam' => (float)$b['toplam']['genel'], 'para_birimi' => $b['para_birimi']] : null,
        'bicim' => $mid ? meTeslimBicimi($db, $mid) : null,
        'portal' => $mid ? mePortalDurum($db, $mid) : 'yok',
        'alici_oneri' => ['telefon' => _sbMetin($r['musteri_telefon'] ?? '') ?: (_sbMetin($kon['telefon'] ?? '') ?: null),
                          'eposta' => _sbMetin($kon['email'] ?? '') ?: (_sbMetin($r['musteri_email'] ?? '') ?: null)],
        'gonderimler' => sbGonderimler($db, (int)$r['id']),
    ];
}

function sbGonder(PDO $db, array $r, string $kanal, string $alici, string $gonderen): array {
    sbSema($db);
    if (!in_array($kanal, ['whatsapp', 'eposta'], true)) throw new DomainException('Geçersiz kanal.', 400);
    if ($e = sbEngel($db, $r)) throw new DomainException($e, 409);
    $alici = mb_substr(trim($alici), 0, 200);
    $tel = null;
    if ($kanal === 'whatsapp') {
        require_once __DIR__ . '/whatsapp.php';
        if (!($tel = waTelefon($alici))) throw new DomainException('Geçerli bir telefon numarası girin.', 400);
    } elseif (!filter_var($alici, FILTER_VALIDATE_EMAIL)) throw new DomainException('Geçerli bir e-posta adresi girin.', 400);
    $b = sbBelge($db, $r);
    $sid = $b['servis_id'];
    $bicim = meTeslimBicimi($db, $b['musteri_id']);
    $link = null;
    if ($bicim === 'pdf') {
        $erisim = meOtoDavet($db, $b['musteri_id'], 'portal.html?servis=' . $sid);
        $metin = sbMesaj($b, null) . mePdfNotu($erisim);
    } else {
        $erisim = ['durum' => 'aktif', 'link' => null];
        $link = sbPortalLink($sid);
        $metin = sbMesaj($b, $link) . meMesajNotu($erisim);
    }
    if ($kanal === 'eposta') {
        require_once __DIR__ . '/eposta.php';
        $konu = 'Servis bedeli dökümü — ' . $b['no'];
        $sonuc = $bicim === 'pdf' ? epostaEkliGonder($db, $alici, $konu, $metin, [sbPdf($db, $b)], 'servis_bedel:' . $sid)
                                  : epostaGonder($db, $alici, $konu, $metin, 'servis_bedel:' . $sid);
        if (empty($sonuc['basarili'])) throw new DomainException('E-posta gönderilemedi: ' . ($sonuc['hata'] ?? 'bilinmeyen hata'), 400);
    }
    $genel = round((float)$b['toplam']['genel'], 2);
    $db->prepare('INSERT INTO servis_bedel_gonderimleri(servis_id, musteri_id, belge_no, kanal, alici, bicim, genel_toplam, icerik, gonderen, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
       ->execute([$sid, $b['musteri_id'], $b['no'], $kanal, $alici, $bicim, $genel, json_encode($b, JSON_UNESCAPED_UNICODE), mb_substr($gonderen, 0, 120), date('Y-m-d H:i:s')]);
    $gid = (int)$db->lastInsertId();
    $out = ['gonderim_id' => $gid, 'servis_id' => $sid, 'belge_no' => $b['no'], 'kanal' => $kanal, 'bicim' => $bicim, 'portal' => $erisim['durum'], 'metin' => $metin,
            'denetim' => ['yeni' => ['servis_bedel_gonderim' => $gid, 'belge_no' => $b['no'], 'kanal' => $kanal, 'bicim' => $bicim, 'alici' => $alici, 'genel_toplam' => $genel],
                          'ozet' => 'Servis bedeli dökümü ' . $b['no'] . ' müşteriye ' . ($bicim === 'pdf' ? 'PDF olarak' : 'portal bağlantısıyla') . ' gönderildi ('
                                    . ($kanal === 'whatsapp' ? 'WhatsApp' : 'e-posta') . ' → ' . $alici . ') · genel toplam ' . bpPara($genel, $b['para_birimi'])]];
    if ($link !== null) $out['link'] = $link;
    if ($kanal === 'whatsapp') {
        $out['wa_url'] = 'https://wa.me/' . $tel . '?text=' . rawurlencode($metin);
        if ($bicim === 'pdf') $out += ['pdf_url' => 'api/servis_bedel.php?gonderim=' . $gid, 'dosya_adi' => bpDosyaAdi($b['no']), 'mesaj' => "PDF hazır — WhatsApp'ta dosya olarak paylaşın."];
        else $out['mesaj'] = 'WhatsApp mesajı hazır — açılan pencereden gönderin.';
    } else $out['mesaj'] = $bicim === 'pdf' ? 'E-postayla PDF olarak gönderildi.' : 'E-postayla gönderildi; belge müşterinin portal hesabında.';
    return $out;
}

function sbGonderimGetir(PDO $db, int $gid): array {
    sbSema($db);
    $q = $db->prepare('SELECT * FROM servis_bedel_gonderimleri WHERE id=?');
    $q->execute([$gid]);
    $g = $q->fetch(PDO::FETCH_ASSOC);
    if (!$g) throw new DomainException('Gönderim bulunamadı.', 404);
    return $g;
}

function sbGonderimPdf(PDO $db, int $gid): array {
    $b = json_decode((string)sbGonderimGetir($db, $gid)['icerik'], true);
    if (!is_array($b) || empty($b['no'])) throw new DomainException('Gönderilen belge okunamadı.', 404);
    return sbPdf($db, $b);
}

function sbPortalListe(PDO $db, int $musteriId): array {
    $q = $db->prepare("SELECT s.id, s.tarih, s.tamamlanma, s.created_at, s.makine_ad, s.kiralama_id, s.musteri_id AS servis_musteri_id, k.musteri_id AS kiralama_musteri_id,
                              k.kod AS kiralama_kod, s.fiyat_durum, s.musteri_genel_toplam, s.kaynak, s.musteri_makine_id, s.musteriye_yansit
                       FROM teknik_servis s LEFT JOIN kiralamalar k ON k.id = s.kiralama_id
                       WHERE (s.musteri_id = ? OR (s.musteri_id IS NULL AND k.musteri_id = ?)) AND s.fiyat_durum IN ('fiyatlandi', 'ucretsiz')
                       ORDER BY COALESCE(s.tamamlanma, s.tarih) DESC, s.id DESC");
    $q->execute([$musteriId, $musteriId]);
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!in_array(smAkis($db, $r), ['hizmet', 'yansit', 'donus'], true)) continue;
        $kirada = !empty($r['kiralama_id']) && (empty($r['servis_musteri_id']) || (int)$r['servis_musteri_id'] === (int)$r['kiralama_musteri_id']);
        $ucretsiz = $r['fiyat_durum'] === 'ucretsiz';
        $out[] = ['id' => (int)$r['id'], 'no' => sbBelgeNo($r), 'tarih' => $r['tamamlanma'] ?: $r['tarih'], 'makine' => (string)($r['makine_ad'] ?? ''),
                  'kiralama_id' => $kirada ? (int)$r['kiralama_id'] : null, 'kiralama_kod' => $kirada ? $r['kiralama_kod'] : null,
                  'ucretsiz' => $ucretsiz, 'genel_toplam' => $ucretsiz ? 0.0 : round((float)$r['musteri_genel_toplam'], 2), 'para_birimi' => 'TL'];
    }
    return $out;
}
