<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('ayarlar');

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDb();

const SIR_ALANLAR = ['ai_api_key', 'claude_api_key', 'bildirim_wp_apikey', 'smtp_sifre', 'cron_anahtar', 'wa_token', 'kod_iz_anahtar'];
const YONETICI_AYARLAR = ['firma_iban', 'firma_banka', 'bildirim_wp_no', 'bildirim_wp_apikey', 'bildirim_wp_aktif', 'wa_ofis_no', 'wa_ofis_aktif', 'wa_token', 'wa_phone_id', 'wa_aktif', 'ai_api_key', 'claude_api_key', 'smtp_sifre', 'cron_anahtar'];
const SIR_MASKE   = '••••••••';


if (in_array($_GET['action'] ?? '', ['kopru', 'kopru_anahtar', 'kopru_ayar'], true)) {
    yazmaIstegiCsrf();
    if (($_SESSION['rol'] ?? '') !== 'admin') jsonError('Wolvox köprüsü ayarları yalnız yöneticiye açıktır.', 403);
    require_once __DIR__ . '/wolvox-kopru-lib.php';
    $_ka = (string)$_GET['action'];
    if ($method === 'GET' && $_ka === 'kopru') jsonOk(kopruDurum($db));
    if ($method !== 'POST' || $_ka === 'kopru') jsonError('Method Not Allowed', 405);
    if ($_ka === 'kopru_anahtar') {
        $_vardi = kopruAyarOku($db, 'wolvox_kopru_ozet') !== '';
        $_yeni = kopruAnahtarUret();
        kopruAyarYaz($db, 'wolvox_kopru_ozet', tokenOzet($_yeni));
        kopruAyarYaz($db, 'wolvox_kopru_anahtar_tarihi', date('Y-m-d H:i:s'));
        logla($db, 'ayarlar', null, 'guncelle', null, null, 'Wolvox köprüsü anahtarı ' . ($_vardi ? 'yenilendi (eski anahtar geçersiz)' : 'üretildi'));
        guvenlikLog('kopru_anahtar_uretildi', (string)($_SESSION['username'] ?? '?'), (int)($_SESSION['user_id'] ?? 0));
        jsonOk(['anahtar' => $_yeni] + kopruDurum($db),
               $_vardi ? 'Yeni anahtar üretildi. Eski anahtar artık çalışmaz.' : 'Anahtar üretildi.');
    }
    $_kb = govdeOku();
    $_ac = in_array($_kb['yeni_cari_ekle'] ?? null, [true, 1, '1'], true);
    $_onceki = kopruAyarOku($db, 'wolvox_kopru_yeni_cari_ekle') === '1';
    kopruAyarYaz($db, 'wolvox_kopru_yeni_cari_ekle', $_ac ? '1' : '0');
    if ($_ac !== $_onceki) {
        logla($db, 'ayarlar', null, 'guncelle', ['wolvox_kopru_yeni_cari_ekle' => $_onceki ? '1' : '0'], ['wolvox_kopru_yeni_cari_ekle' => $_ac ? '1' : '0'],
              "Wolvox köprüsü: Taloo'da olmayan cariyi yeni müşteri olarak ekleme " . ($_ac ? 'AÇILDI' : 'kapatıldı'));
    }
    jsonOk(kopruDurum($db), $_ac ? "Açıldı: Taloo'da olmayan cariler yeni müşteri olarak eklenecek." : "Kapatıldı: Taloo'da olmayan cariler eklenmeyecek.");
}

if ($method === 'GET') {
    $rows = $db->query("SELECT anahtar, deger FROM ayarlar")->fetchAll();
    $out  = [];
    foreach ($rows as $r) {
        if (str_starts_with((string)$r['anahtar'], 'wolvox_kopru_')) continue;
        $out[$r['anahtar']] = $r['deger'];
    }
    foreach (SIR_ALANLAR as $k) {
        $out[$k . '_kayitli'] = isset($out[$k]) && $out[$k] !== '';
        if (!empty($out[$k])) $out[$k] = SIR_MASKE;
    }
    $out['sube_prim_orani_yazabilir'] = (($_SESSION['rol'] ?? '') === 'admin');
    jsonOk($out);
}

if ($method === 'POST') {
    verifyCsrf();
    $body = govdeOku();

    $izinli = [
        'bildirim_email', 'bildirim_email_aktif',
        'bildirim_wp_no', 'bildirim_wp_apikey', 'bildirim_wp_aktif',
        'bildirim_gun_esigi',
        'firma_unvan', 'firma_vergi_dairesi', 'firma_vergi_no', 'firma_mersis',
        'firma_telefon', 'firma_eposta', 'firma_web', 'firma_adres', 'firma_logo',
        'firma_banka', 'firma_iban',
        'servis_saat_ucreti', 'servis_yol_ucreti',
        'sefer_ucreti_varsayilan',
        'ariza_indirim_esik_saat', 'ariza_indirim_oran', 'ariza_indirim_aktif',
        'firma_teklif_sartlari', 'firma_teklif_sartlari_kiralama', 'firma_teklif_sartlari_satis', 'firma_teklif_sartlari_servis',
        'kiralama_protokol_sartlari', 'kiralama_sozlesme_sartlari',
        'teklif_onay_esik_iskonto',
        'ai_saglayici', 'ai_api_key', 'ai_model',
        'claude_api_key', 'claude_model', 'claude_asistan_aktif',
        'wa_aktif', 'wa_token', 'wa_phone_id', 'wa_sablon', 'wa_sablon_dil', 'wa_api_surum', 'wa_ofis_aktif', 'wa_ofis_no',
        'ek_tatiller',
        'maliyet_mazot_fiyati', 'maliyet_operator_maas_gun', 'maliyet_operator_sgk_gun',
        'wolvox_trsver', 'wolvox_dbfilename', 'wolvox_dbname', 'wolvox_persuser',
        'wolvox_sube_kodu', 'wolvox_depo_adi', 'wolvox_stok_adi', 'wolvox_sayac_tanimi',
        'maliyet_operator_yemek_gun', 'maliyet_operator_tazminat_gun', 'maliyet_vergi_orani',
        'sube_prim_orani', 'tutanak_eimza_metni',
    ];

    $_waBicim = [
        'wa_phone_id'   => ['/^\d{6,20}$/', 'Telefon numarası kimliği (Phone number ID) yalnız rakamlardan oluşur.'],
        'wa_sablon'     => ['/^[a-z0-9_]{1,512}$/', 'Şablon adı küçük harf, rakam ve alt çizgiden oluşur (ör. portal_bildirim).'],
        'wa_sablon_dil' => ['/^[a-z]{2,3}(_[A-Z]{2})?$/', 'Şablon dili ör. tr ya da en_US biçiminde olmalı.'],
        'wa_api_surum'  => ['/^v\d{1,2}\.\d$/', 'API sürümü ör. v23.0 biçiminde olmalı.'],
    ];
    foreach ($_waBicim as $_k => [$_desen, $_mesaj]) {
        if (isset($body[$_k]) && trim((string)$body[$_k]) !== '' && !preg_match($_desen, trim((string)$body[$_k]))) jsonError($_mesaj);
    }
    if (array_key_exists('ariza_indirim_aktif', $body)) $body['ariza_indirim_aktif'] = in_array((string)$body['ariza_indirim_aktif'], ['1', 'true', 'on'], true) ? '1' : '0';
    foreach (['ariza_indirim_esik_saat' => [0, 8760, 'Arıza indirim eşiği 0 ile 8760 saat arasında olmalı.'],
              'ariza_indirim_oran' => [0, 100, 'Arıza indirim oranı 0 ile 100 arasında bir yüzde olmalı.'],
              'sefer_ucreti_varsayilan' => [0, 1000000000, 'Boşa giden sefer ücreti sayısal olmalı (0 ile 1.000.000.000 arasında).']] as $_k => [$_min, $_max, $_mesaj]) {
        if (!array_key_exists($_k, $body)) continue;
        $_v = str_replace(',', '.', trim((string)$body[$_k]));
        if ($_v === '') continue;
        if (!is_numeric($_v) || (float)$_v < $_min || (float)$_v > $_max) jsonError($_mesaj);
        $body[$_k] = (string)(float)$_v;
    }

    if (array_key_exists('tutanak_eimza_metni', $body) && mb_strlen(trim((string)$body['tutanak_eimza_metni'])) > 200)
        jsonError('İmza yedek cümlesi en fazla 200 karakter olabilir.');
    $_primLog = null;
    if (array_key_exists('sube_prim_orani', $body)) {
        $_po = str_replace(',', '.', trim((string)$body['sube_prim_orani']));
        $_eski = ayarOku('sube_prim_orani', '2.5');
        if ($_po === '' || (is_numeric($_po) && (float)$_po === (float)str_replace(',', '.', $_eski))) {
            unset($body['sube_prim_orani']);
        } else {
            if (($_SESSION['rol'] ?? '') !== 'admin') jsonError('Şube prim oranını yalnız yönetici değiştirebilir.', 403);
            if (!is_numeric($_po) || (float)$_po < 0 || (float)$_po > 100) jsonError('Şube prim oranı 0 ile 100 arasında bir yüzde olmalı.');
            $body['sube_prim_orani'] = (string)(float)$_po;
            $_primLog = [$_eski, $body['sube_prim_orani']];
        }
    }

    if (($_SESSION['rol'] ?? '') !== 'admin') {
        foreach (YONETICI_AYARLAR as $_ya) {
            if (!array_key_exists($_ya, $body)) continue;
            $_yeni = trim((string)$body[$_ya]);
            if (in_array($_ya, SIR_ALANLAR, true) && ($_yeni === SIR_MASKE || str_starts_with($_yeni, '•'))) { unset($body[$_ya]); continue; }
            $_eskiDeger = ayarOku($_ya, '');
            if ($_yeni === $_eskiDeger) { unset($body[$_ya]); continue; }
            if (str_ends_with($_ya, '_aktif') && in_array($_yeni, ['', '0'], true) && in_array($_eskiDeger, ['', '0'], true)) { unset($body[$_ya]); continue; }
            jsonError('Banka/IBAN, güvenlik alarmı ve API anahtarı ayarlarını yalnız yönetici değiştirebilir.', 403);
        }
    }
    $stmt = $db->prepare("INSERT OR REPLACE INTO ayarlar (anahtar, deger) VALUES (?, ?)");
    foreach ($izinli as $k) {
        if (!array_key_exists($k, $body)) continue;
        $deger = trim((string)$body[$k]);
        if (in_array($k, SIR_ALANLAR, true) && ($deger === SIR_MASKE || str_starts_with($deger, '•'))) continue;
        $stmt->execute([$k, $deger]);
    }
    if ($_primLog) logla($db, 'ayarlar', null, 'guncelle', ['sube_prim_orani' => $_primLog[0]], ['sube_prim_orani' => $_primLog[1]],
          'Şube prim oranı %' . $_primLog[0] . ' → %' . $_primLog[1] . ' (önceki kapanışlar kendi oranını korur)');
    $_degisen = array_values(array_filter($izinli, fn($k) => array_key_exists($k, $body)));
    if ($_degisen) {
        $_gorunur = array_map(fn($k) => in_array($k, SIR_ALANLAR, true) ? $k . ' (gizli)' : $k, $_degisen);
        logla($db, 'ayarlar', null, 'guncelle', null, null,
              'Ayar değişikliği: ' . implode(', ', $_gorunur));
        foreach (SIR_ALANLAR as $_s) {
            if (in_array($_s, $_degisen, true)) {
                guvenlikLog('gizli_anahtar_degisti', (string)($_SESSION['username'] ?? '?'),
                            (int)($_SESSION['user_id'] ?? 0), ['alan' => $_s]);
            }
        }
    }
    jsonOk(null, 'Ayarlar kaydedildi.');
}

jsonError('Method Not Allowed', 405);
