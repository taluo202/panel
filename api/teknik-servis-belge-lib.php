<?php
declare(strict_types=1);
require_once __DIR__ . '/belge-pdf-lib.php';


const TS_TIP_ETIKET     = ['ariza' => 'Arıza', 'bakim' => 'Bakım', 'expertiz' => 'Expertiz'];
const TS_DURUM_ETIKET   = ['acik' => 'Açık', 'devam' => 'Devam ediyor', 'tamamlandi' => 'Tamamlandı'];
const TS_ONCELIK_ETIKET = ['dusuk' => 'Düşük', 'normal' => 'Normal', 'yuksek' => 'Yüksek', 'acil' => 'Acil'];
const TS_KAYNAK_ETIKET  = ['manuel' => 'Elle açıldı', 'operator' => 'Operatör bildirimi', 'donus_kontrolu' => 'Dönüş kontrolü',
    'planli_bakim' => 'Planlı bakım', 'qr' => 'QR ile açıldı', 'portal' => 'Müşteri portalı', 'depo_inceleme' => 'Dönüş kontrolü'];
const TS_YER_ETIKET     = ['atolye' => 'Atölye', 'saha' => 'Saha (müşteri adresi)'];
const TS_SAHA_DURUM_ETIKET = ['atanmadi' => 'Teknisyen atanmadı', 'atandi' => 'Atandı', 'yolda' => 'Yolda', 'sahada' => 'Sahada',
    'rapor_gonderildi' => 'Rapor gönderildi', 'iptal' => 'İptal'];
const TS_SAHA_SONUC_ETIKET = ['yerinde_cozuldu' => 'Yerinde çözüldü', 'subeye_gelecek' => 'Şubeye gelecek', 'parca_bekliyor' => 'Parça bekleniyor',
    'tekrar_ziyaret' => 'Tekrar ziyaret', 'yapilamadi' => 'İş yapılamadı'];
const TS_FIYAT_DURUM_ETIKET = ['bekliyor' => 'Fiyat bekliyor', 'fiyatlandi' => 'Fiyatlandı', 'ucretsiz' => 'Ücretsiz (garanti / iyi niyet)'];
const TS_KONTROL_ETIKET = ['uygun' => 'Uygun', 'uygun_degil' => 'Uygun değil', 'nk' => 'Değerlendirilmedi'];
const TS_SIPARIS_DURUM_ETIKET = ['taslak' => 'Taslak', 'gonderildi' => 'Gönderildi', 'kismi_teslim' => 'Kısmi teslim',
    'teslim_alindi' => 'Teslim alındı', 'tamamlandi' => 'Tamamlandı', 'iptal' => 'İptal'];
const TS_MAKINE_DURUM_ETIKET = ['musait' => 'Müsait', 'kirada' => 'Kirada', 'serviste' => 'Serviste', 'bakim_bekliyor' => 'Bakım bekliyor',
    'nakliyede' => 'Nakliyede', 'satildi' => 'Satıldı', 'pasif' => 'Pasif'];
const TS_FOTO_KAYNAK_ETIKET = ['portal' => 'Müşteri fotoğrafı', 'tamir_sonrasi' => 'Tamir sonrası', 'saha' => 'Saha'];
const TS_PDF_FOTO_EN_COK = 6;

function tsPara(mixed $n): string { return number_format((float)$n, 2, ',', '.') . ' TL'; }

function tsKunyeCiftleri(array $k): array {
    $m = fn(string $a): string => bpMetin($k[$a] ?? '');
    $arac = ($k['makine_tur'] ?? '') === 'arac';
    $metraj = bpVar($k['makine_metraj'] ?? null) ? trim(bpSayi((float)$k['makine_metraj']) . ' ' . $m('makine_olcu_birim')) : '';
    $saat = bpVar($k['makine_calisma_saati'] ?? null) ? bpSayi((float)$k['makine_calisma_saati']) . ($arac ? '' : ' sa') : '';
    $durum = $m('makine_durum') !== '' ? (TS_MAKINE_DURUM_ETIKET[$m('makine_durum')] ?? $m('makine_durum')) : '';
    if (array_key_exists('makine_aktif', $k) && $k['makine_aktif'] !== null && (int)$k['makine_aktif'] === 0) $durum = trim($durum . ' (kayıt pasif)');
    $ciftler = [
        ['Makine', $m('makine_ad')],
        [$arac ? 'Plaka' : 'Makine Kodu', $m('makine_kod')], ['Kategori', $m('makine_kategori')],
        ['Marka', $m('makine_marka')], ['Model', $m('makine_model')],
        [$arac ? 'Ruhsat No' : 'Seri No', $m('makine_seri_no')], ['Stok Kodu', $m('makine_stok_kodu')],
        ['Metraj', $metraj], ['Şube', $m('makine_sube')],
        [$arac ? 'Çalışma Saati / km' : 'Çalışma Saati', $saat], ['İmal Yılı', $m('makine_imal_yili')],
        ['Makine Durumu', $durum],
    ];
    if (($k['makine_sahip'] ?? '') === 'musteri') $ciftler[] = ['Sahibi', 'Müşterinin makinesi (hizmet servisi)'];
    return array_values(array_filter($ciftler, fn($c) => $c[1] !== ''));
}

function tsKiralamaCiftleri(array $k): array {
    $m = fn(string $a): string => bpMetin($k[$a] ?? '');
    if ((int)($k['kiralama_baska_sube'] ?? 0) === 1) return [['Kiralama', 'Başka şubenin kiralaması']];
    if (!empty($k['musteri_id']) && !empty($k['kiralama_musteri_id']) && (int)$k['musteri_id'] !== (int)$k['kiralama_musteri_id']) return [];
    if (empty($k['kiralama_id']) || ($m('kiralama_kod') === '' && $m('kiralama_musteri') === '')) return [];
    $bas = bpVar($k['kiralama_baslangic'] ?? null) ? bpTarih($k['kiralama_baslangic']) : '';
    $bit = bpVar($k['kiralama_bitis'] ?? null) ? bpTarih($k['kiralama_bitis']) : '';
    $durum = ['aktif' => 'Aktif', 'tamamlandi' => 'Tamamlandı', 'iptal' => 'İptal'][$m('kiralama_durum')] ?? $m('kiralama_durum');
    $ciftler = [
        ['Kiralama', $m('kiralama_kod')], ['Sipariş No', $m('kiralama_siparis_no')],
        ['Müşteri', $m('kiralama_musteri')], ['Şantiye / Lokasyon', $m('kiralama_lokasyon')],
        ['Kira Tarihleri', trim($bas . ($bit !== '' ? ' – ' . $bit : ($bas !== '' ? ' – devam ediyor' : '')))], ['Kiralama Durumu', $durum],
    ];
    return array_values(array_filter($ciftler, fn($c) => $c[1] !== ''));
}

function tsKayitCiftleri(array $k, bool $tutarGoster): array {
    $s = fn(string $a): string => bpMetin($k[$a] ?? '');
    $c = [
        ['Tip', TS_TIP_ETIKET[$s('tip')] ?? $s('tip')], ['Tarih', bpTarih($k['tarih'] ?? null)],
        ['Öncelik', TS_ONCELIK_ETIKET[$s('oncelik')] ?? $s('oncelik')], ['Durum', TS_DURUM_ETIKET[$s('durum')] ?? $s('durum')],
        ['Teknisyen', $s('teknisyen_ad')], ['Tamamlanma', bpVar($k['tamamlanma'] ?? null) ? bpTarih($k['tamamlanma']) : ''],
        ['Kaynak', TS_KAYNAK_ETIKET[$s('kaynak')] ?? $s('kaynak')], ['Yer', TS_YER_ETIKET[$s('yer') !== '' ? $s('yer') : 'atolye'] ?? $s('yer')],
        ['Müşteri', $s('musteri_unvan')],
    ];
    if ($s('yer') === 'saha') {
        $c[] = ['Saha Durumu', TS_SAHA_DURUM_ETIKET[$s('saha_durum')] ?? $s('saha_durum')];
        $c[] = ['Saha Sonucu', TS_SAHA_SONUC_ETIKET[$s('saha_sonuc')] ?? $s('saha_sonuc')];
        $c[] = ['Servis Adresi', trim(implode(' ', array_filter([$s('saha_adres'), implode(' / ', array_filter([$s('saha_ilce'), $s('saha_il')]))])))];
        $c[] = ['Sahadaki İrtibat', trim(implode(' · ', array_filter([$s('irtibat_ad'), $s('irtibat_tel')])))];
        $c[] = ['Planlanan Tarih', bpVar($k['planlanan_tarih'] ?? null) ? bpTarih($k['planlanan_tarih']) : ''];
    }
    $c[] = ['Kontrol Şablonu', $s('kontrol_sablon_adi')];
    if ($tutarGoster) $c[] = ['Maliyet (bize, iç kullanım)', bpVar($k['maliyet'] ?? null) ? tsPara($k['maliyet']) : ''];
    $c[] = ['Müşteriye Yansıt', (int)($k['musteriye_yansit'] ?? 0) === 1 ? 'Evet (faturalandırılacak)' : ''];
    if ($tutarGoster && array_key_exists('musteri_tutar', $k) && $k['musteri_tutar'] !== null && $k['musteri_tutar'] !== '')
        $c[] = ['Müşteri Tutarı', ($k['musteri_genel_toplam'] ?? null) !== null
            ? tsPara($k['musteri_genel_toplam']) . ' KDV dahil (KDV hariç ' . tsPara($k['musteri_tutar']) . ')' : tsPara($k['musteri_tutar'])];
    if ($s('fiyat_durum') !== '') $c[] = ['Fiyatlandırma', TS_FIYAT_DURUM_ETIKET[$s('fiyat_durum')] ?? $s('fiyat_durum')];
    $c[] = ['Kayıt Açılış', bpTarihSaat($k['created_at'] ?? null, '')];
    return array_values(array_filter($c, fn($x) => $x[1] !== ''));
}

const TS_KALEM_TUR_ETIKET = ['malzeme' => 'Malzeme', 'iscilik' => 'İşçilik', 'yol' => 'Yol / ulaşım', 'diger' => 'Diğer'];

function tsAciklamaParcaNo(array $x): string {
    $a = bpMetin($x['aciklama'] ?? ''); $no = bpMetin($x['parca_no'] ?? '');
    return trim($a . ($no !== '' && !str_contains($a, $no) ? ' (' . $no . ')' : ''));
}

function tsKalemOlcu(array $x): string {
    if (($x['tur'] ?? '') === 'iscilik') return bpSayi((float)($x['saat'] ?? 0)) . ' saat × ' . bpSayi((float)($x['kisi'] ?? 0) ?: 1.0) . ' kişi';
    return bpSayi((float)($x['miktar'] ?? 0)) . ' ' . (bpMetin($x['birim'] ?? '') ?: 'adet') . (!empty($x['parca_id']) ? ' · stoktan' : '');
}

function tsArizaKodlari(mixed $ham): array {
    $v = bpJson($ham);
    if (!is_array($v)) return [];
    $out = [];
    foreach ($v as $a) {
        if (is_array($a)) $out[] = trim(bpMetin($a['kod'] ?? '') . ' — ' . bpMetin($a['aciklama'] ?? '') . (bpMetin($a['kategori'] ?? '') !== '' ? ' (' . bpMetin($a['kategori']) . ')' : ''), ' —');
        elseif (is_scalar($a) && bpMetin($a) !== '') $out[] = bpMetin($a);
    }
    return array_values(array_filter($out, fn($x) => $x !== ''));
}

function tsPdfFotolar(array $k): array {
    $out = [];
    foreach (is_array($k['fotograflar'] ?? null) ? $k['fotograflar'] : [] as $x)
        if (is_array($x)) $out[] = ['yol' => (string)($x['dosya_yolu'] ?? ''), 'etiket' => TS_FOTO_KAYNAK_ETIKET[bpMetin($x['kaynak'] ?? '')] ?? bpMetin($x['kaynak'] ?? '')];
    $t = is_array($k['tutanak'] ?? null) ? $k['tutanak'] : [];
    foreach (is_array($t['fotograflar'] ?? null) ? $t['fotograflar'] : [] as $x)
        if (is_array($x)) $out[] = ['yol' => (string)($x['dosya_yolu'] ?? ''), 'etiket' => trim('İade · ' . bpMetin($x['aci_tipi'] ?? ''), ' ·')];
    return array_slice(array_values(array_filter($out, fn($f) => $f['yol'] !== '' && !str_contains($f['yol'], '..'))), 0, TS_PDF_FOTO_EN_COK);
}

function tsPdf(PDO $db, array $k, bool $tutarGoster): array {
    $id = (int)($k['id'] ?? 0);
    $no = 'SERVIS-' . $id;
    $baslik = 'Teknik Servis Kaydı';
    $raporlar = array_values(array_unique(array_filter(array_map(fn($z) => is_array($z) ? bpMetin($z['rapor_no'] ?? '') : '', is_array($k['ziyaretler'] ?? null) ? $k['ziyaretler'] : []))));
    $bl = [];
    $arac = ($k['makine_tur'] ?? '') === 'arac';
    $kimlikEt = ['Makine'];
    $kunye = tsKunyeCiftleri($k);
    $bl[] = ['bolum' => 'Makine', 'kimlik_bos' => 'Kayda makine bağlanmamış', 'kimlik' => [
        'kod' => $k['makine_kod'] ?? null, 'marka' => $k['makine_marka'] ?? null, 'model' => $k['makine_model'] ?? null, 'ad' => $k['makine_ad'] ?? null,
        'kategori' => $k['makine_kategori'] ?? null, 'seri_no' => $arac ? null : ($k['makine_seri_no'] ?? null), 'makine_kodu' => $k['makine_stok_kodu'] ?? null,
        'metraj' => bpVar($k['makine_metraj'] ?? null) ? bpSayi((float)$k['makine_metraj']) : null, 'olcu_birim' => bpMetin($k['makine_olcu_birim'] ?? '')],
        'bilgi' => array_values(array_filter($kunye, fn($c) => !in_array($c[0], $kimlikEt, true)))];
    if ($c = tsKiralamaCiftleri($k)) $bl[] = ['bolum' => 'Kaynak Kiralama', 'bilgi' => $c];
    if ($c = tsKayitCiftleri($k, $tutarGoster)) $bl[] = ['bolum' => 'Kayıt', 'bilgi' => $c];
    foreach ([['ariza', 'Arıza / Talep'], ['yapilan', 'Yapılan İşlem'], ['notlar', 'Notlar']] as [$alan, $bas])
        if (bpMetin($k[$alan] ?? '') !== '') $bl[] = ['bolum' => $bas, 'metin' => bpMetin($k[$alan])];

    $kalemler = array_values(array_filter(is_array($k['kalemler'] ?? null) ? $k['kalemler'] : [], 'is_array'));
    if ($kalemler) $bl[] = ['bolum' => 'Yapılan İş Kalemleri', 'tablo' => [['Tür', 'Açıklama', 'Miktar / Süre', 'Bağlı Bulgu', 'Yapan'], array_map(fn($x) => [
        TS_KALEM_TUR_ETIKET[$x['tur'] ?? ''] ?? 'Malzeme', tsAciklamaParcaNo($x), tsKalemOlcu($x), bpMetin($x['bulgu'] ?? ''), bpMetin($x['yapan'] ?? '')], $kalemler), [1.3, 4.2, 2.3, 2.6, 1.8]]];

    $masraf = array_values(array_filter(is_array($k['masraf'] ?? null) ? $k['masraf'] : [], 'is_array'));
    $devir = !empty($k['masraf_eski_kayit']) ? (float)($k['masraf_devir'] ?? 0) : 0.0;
    if ($tutarGoster && ($masraf || $devir > 0)) {
        $toplam = 0.0; $satir = [];
        foreach ($masraf as $x) {
            $toplam += (float)($x['maliyet_tutar'] ?? 0);
            $ek = implode(' · ', array_filter([bpMetin($x['stok_kodu'] ?? ''), bpMetin($x['tedarikci'] ?? '')]));
            $satir[] = [bpMetin($x['tip_etiket'] ?? ''), trim(tsAciklamaParcaNo($x) . ($ek !== '' ? ' (' . $ek . ')' : '')),
                        bpSayi((float)($x['miktar'] ?? 0)) . ' ' . bpMetin($x['birim'] ?? ''),
                        ($x['birim_maliyet'] ?? null) !== null ? tsPara($x['birim_maliyet']) : 'girilmedi', tsPara($x['maliyet_tutar'] ?? 0)];
        }
        if ($devir > 0) { $toplam += $devir; $satir[] = ['Önceden elle girilen maliyet', 'Eski kayıt (satırsız tutar)', '1 adet', tsPara($devir), tsPara($devir)]; }
        $bl[] = ['bolum' => 'Masraf — İÇ KULLANIM (müşteriye verilmez)', 'tablo' => [['Tür', 'Açıklama', 'Miktar', 'Birim Maliyet', 'Maliyet'], $satir, [2.2, 4.6, 1.6, 2, 2], [2, 3, 4]],
                 'toplam' => [['Toplam maliyet (bize, KDV hariç)', tsPara($toplam), true]]];
    }
    $fy = $tutarGoster && is_array($k['fiyat'] ?? null) ? $k['fiyat'] : null;
    if ($fy && is_array($fy['toplam'] ?? null)) {
        $t = $fy['toplam'];
        $satir = array_map(fn($x) => [bpMetin($x['aciklama'] ?? ''), bpSayi((float)($x['miktar'] ?? 0)) . ' ' . bpMetin($x['birim'] ?? ''), tsPara($x['birim_fiyat'] ?? 0),
                                       '%' . bpSayi((float)($x['kdv_oran'] ?? 0)), tsPara($x['tutar'] ?? 0)], array_values(array_filter(is_array($fy['satirlar'] ?? null) ? $fy['satirlar'] : [], 'is_array')));
        $top = [['Ara toplam (KDV hariç)', tsPara($t['ara'] ?? 0)]];
        if ((float)($t['iskonto'] ?? 0) > 0) $top[] = ['İndirim', '− ' . tsPara($t['iskonto'])];
        if (($t['kdv'] ?? null) !== null) $top[] = ['KDV', tsPara($t['kdv'])];
        $top[] = ['Müşteri genel toplam', tsPara($t['genel'] ?? 0), true];
        $kar = round((float)($t['matrah'] ?? 0) - (float)($k['maliyet'] ?? 0), 2);
        $top[] = ['Kâr (iç, KDV hariç)', tsPara($kar), false, $kar < 0 ? '#b42318' : '#0d1c2e'];
        $bl[] = ['bolum' => 'Müşteri Fiyatı (müşteriye gösterilen)' . (bpMetin($fy['not'] ?? '') !== '' ? ' — ' . bpMetin($fy['not']) : ''),
                 'tablo' => [['Açıklama', 'Miktar', 'Birim Fiyat', 'KDV', 'Tutar (KDV hariç)'], $satir, [4.8, 1.6, 2, 1.1, 2.3], [1, 2, 3, 4]], 'toplam' => $top];
    }

    $parcalar = array_values(array_filter(is_array($k['parcalar'] ?? null) ? $k['parcalar'] : [], 'is_array'));
    if ($tutarGoster && $parcalar && !$masraf) {
        $toplam = 0.0; $satir = [];
        foreach ($parcalar as $x) {
            $tutar = (float)($x['miktar'] ?? 0) * (float)($x['birim_fiyat'] ?? 0); $toplam += $tutar;
            $satir[] = [bpMetin($x['parca_ad'] ?? ''), bpMetin($x['parca_no'] ?? ''), bpSayi((float)($x['miktar'] ?? 0)) . ' adet', tsPara($x['birim_fiyat'] ?? 0), tsPara($tutar)];
        }
        $bl[] = ['bolum' => 'Kullanılan Parçalar (stoktan)', 'tablo' => [['Parça', 'Parça No', 'Miktar', 'Birim Fiyat', 'Tutar'], $satir, [4, 2, 1.5, 2, 2], [2, 3, 4]],
                 'toplam' => [['Toplam', tsPara($toplam), true]]];
    }

    $talepler = array_values(array_filter(is_array($k['parca_talepleri'] ?? null) ? $k['parca_talepleri'] : [], 'is_array'));
    if ($talepler) $bl[] = ['bolum' => 'Parça Talepleri (satınalma)', 'tablo' => [['Sipariş', 'Tedarikçi', 'Malzeme', 'Miktar', 'Teslim Alınan', 'Sipariş Durumu'], array_map(fn($x) => [
        bpMetin($x['siparis_kod'] ?? ''), bpMetin($x['tedarikci_unvan'] ?? ''), bpMetin($x['aciklama'] ?? ''), bpSayi((float)($x['miktar'] ?? 0)), bpSayi((float)($x['teslim_alinan'] ?? 0)),
        TS_SIPARIS_DURUM_ETIKET[bpMetin($x['siparis_durum'] ?? '')] ?? bpMetin($x['siparis_durum'] ?? '')], $talepler), [2, 2.6, 3.4, 1.2, 1.6, 2.2], [3, 4]]];

    $kontrol = array_values(array_filter(is_array($k['kontrol_sonuclari'] ?? null) ? $k['kontrol_sonuclari'] : [], 'is_array'));
    if ($kontrol) $bl[] = ['bolum' => 'Kontrol Listesi' . (bpMetin($k['kontrol_sablon_adi'] ?? '') !== '' ? ' — ' . bpMetin($k['kontrol_sablon_adi']) : ''),
        'tablo' => [['Kategori', 'Madde', 'Sonuç', 'Not'], array_map(function ($x) {
            $d = bpMetin($x['durum'] ?? '');
            $durum = TS_KONTROL_ETIKET[$d] ?? $d;
            return [bpMetin($x['kategori'] ?? ''), bpMetin($x['madde'] ?? ''), $d === 'uygun_degil' ? ['metin' => $durum, 'kalin' => true, 'renk' => '#b42318'] : $durum, bpMetin($x['not_metin'] ?? '')];
        }, $kontrol), [2.2, 4.6, 2, 3.2]]];

    $ziyaretler = array_values(array_filter(is_array($k['ziyaretler'] ?? null) ? $k['ziyaretler'] : [], 'is_array'));
    if ($ziyaretler) $bl[] = ['bolum' => 'Saha Servis Raporları', 'tablo' => [['Rapor No', 'Sonuç', 'Teknisyen', 'Ayrılış'], array_map(fn($z) => [bpMetin($z['rapor_no'] ?? ''),
        TS_SAHA_SONUC_ETIKET[bpMetin($z['sonuc'] ?? '')] ?? bpMetin($z['sonuc'] ?? ''), bpMetin($z['teknisyen_ad'] ?? ''), bpTarihSaat($z['ayrilis'] ?? null, '')], $ziyaretler), [2.6, 3, 3, 2.4]]];

    $t = is_array($k['tutanak'] ?? null) ? $k['tutanak'] : null;
    if ($t) {
        $tc = array_values(array_filter([
            ['Belge No', bpMetin($t['belge_no'] ?? '')], ['İade Tarihi', bpVar($t['tarih'] ?? null) ? bpTarih($t['tarih']) : ''],
            ['Kiralama', bpMetin($t['kiralama_kod'] ?? '')], ['Müşteri', bpMetin($t['musteri'] ?? '')], ['Ekipman', bpMetin($t['ekipman_adi'] ?? '')],
            ['Genel Durum', BP_GENEL_DURUM[bpMetin($t['genel_durum'] ?? '')] ?? bpMetin($t['genel_durum'] ?? '')],
            ['Kilometre', bpVar($t['km'] ?? null) ? bpMetin($t['km']) : ''], ['Çalışma Saati', bpVar($t['calisma_saati'] ?? null) ? bpMetin($t['calisma_saati']) . ' sa' : ''],
        ], fn($c) => $c[1] !== ''));
        $bl[] = ['bolum' => 'Hasarlı İade Tutanağı', 'bilgi' => $tc ?: [['Tutanak', '#' . (int)($t['id'] ?? 0)]]];
        if (bpMetin($t['hasar_notu'] ?? '') !== '') $bl[] = ['bolum' => 'Hasar / Eksik Notu', 'metin' => bpMetin($t['hasar_notu'])];
        if ($kodlar = tsArizaKodlari($t['ariza_kodlari'] ?? null)) $bl[] = ['bolum' => 'Arıza Kodları', 'metin' => implode("\n", $kodlar)];
        $bh = bpJson($t['bilesen_hasar'] ?? null);
        if (is_array($bh)) {
            $satir = [];
            foreach (BP_BILESEN as $anahtar => $ad) {
                $d = bpMetin($bh[$anahtar] ?? '');
                if ($d !== '' && $d !== 'hasarsiz') $satir[] = [$ad, ['metin' => BP_BILESEN_DURUM[$d] ?? $d, 'kalin' => $d === 'cok_hasarli', 'renk' => $d === 'cok_hasarli' ? '#b42318' : '#0d1c2e']];
            }
            if ($satir) $bl[] = ['bolum' => 'Bileşen Bazlı Hasar (iadede)', 'tablo' => [['Bileşen', 'Durum'], $satir, [3, 3]]];
        }
    }

    $fotolar = tsPdfFotolar($k);
    if ($fotolar) $bl[] = ['bolum' => 'Fotoğraflar' . (count($fotolar) === TS_PDF_FOTO_EN_COK ? ' (ilk ' . TS_PDF_FOTO_EN_COK . ')' : ''), 'foto' => $fotolar];
    $bl[] = ['metin' => 'Bu belge Teknik Servis kaydının çıktı anındaki hâlidir ve İÇ KULLANIM içindir: maliyet tutarları (bize) müşteriye verilmez; müşteriye yalnız müşteri fiyatı, KDV ve indirim bildirilir.', 'pt' => 8.0];

    $c = new BelgePdf($db, array_map(fn($f) => $f['yol'], $fotolar));
    $c->teknikServis(['pdf_baslik' => $baslik . ' ' . $no, 'baslik' => 'TEKNİK SERVİS KAYDI', 'no' => $no, 'alt_ek' => $baslik . ' ' . $no,
        'alt' => implode(' · ', array_filter([$raporlar ? 'Saha raporu: ' . implode(', ', $raporlar) : '', 'Tarih: ' . bpTarih($k['tarih'] ?? null)])), 'bloklar' => $bl]);
    return ['ad' => bpDosyaAdi($no), 'tur' => 'application/pdf', 'veri' => $c->cikti()];
}
