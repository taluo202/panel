<?php
declare(strict_types=1);



require_once __DIR__ . '/yedek-lib.php';



const VERI_SAGLIGI_BAGLAR = [
    ['tahsilatlar', 'hakedis_id', 'hakedisler'],
    ['cek_senet', 'tahsilat_id', 'tahsilatlar'],
    ['teklifler', 'kiralama_id', 'kiralamalar'],
    ['kiralama_uzatmalari', 'hakedis_id', 'hakedisler'],
    ['kiralama_fotograflari', 'kiralama_id', 'kiralamalar'],
    ['kiralama_dis_maliyetleri', 'dis_kiralama_id', 'dis_kiralama_kayitlari'],
    ['kiralama_protokolleri', 'musteri_id', 'musteriler'],
    ['kiralama_protokol_gonderimleri', 'protokol_id', 'kiralama_protokolleri'],
    ['portal_kiralama_talepleri', 'musteri_id', 'musteriler'],
    ['musteri_talepleri', 'musteri_id', 'musteriler'],
    ['musteri_makineleri', 'musteri_id', 'musteriler'],
    ['santiyeler', 'musteri_id', 'musteriler'],
    ['teknik_servis', 'kiralama_id', 'kiralamalar'],
    ['teknik_servis', 'musteri_id', 'musteriler'],
    ['teknik_servis_fotograflari', 'servis_id', 'teknik_servis'],
    ['servis_fiyat_kalemleri', 'servis_id', 'teknik_servis'],
    ['servis_ziyaretleri', 'servis_id', 'teknik_servis'],
    ['hasar_degerlendirmeleri', 'kiralama_id', 'kiralamalar'],
    ['hasar_faturalari', 'kiralama_id', 'kiralamalar'],
    ['hasar_faturalari', 'musteri_id', 'musteriler'],
    ['hasar_belgeleri', 'degerlendirme_id', 'hasar_degerlendirmeleri'],
    ['tutanak_gonderimleri', 'tutanak_id', 'iade_tutanaklari'],
    ['satinalma_faturalari', 'tedarikci_id', 'tedarikciler'],
    ['dis_kiralama_kayitlari', 'tedarikci_id', 'tedarikciler'],
];

const YEDEK_PROVA_ZORUNLU_TABLOLAR = ['users', 'ayarlar', 'musteriler', 'kiralamalar', 'personel'];

function veriSagligiDosyasi(): string {
    $ozel = getenv('TALOO_VERI_SAGLIGI');
    return is_string($ozel) && $ozel !== '' ? $ozel : dirname(DB_PATH) . '/veri-sagligi.json';
}

function vsTablolar(PDO $db): array {
    return $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
}

function vsSutunVar(PDO $db, string $tablo, string $sutun): bool {
    foreach ($db->query('PRAGMA table_info("' . $tablo . '")')->fetchAll(PDO::FETCH_ASSOC) as $s) {
        if (($s['name'] ?? '') === $sutun) return true;
    }
    return false;
}

function veriSagligiTara(PDO $db): array {
    $bas = microtime(true);
    $sonuc = ['butunluk' => ['tamam' => false, 'mesajlar' => []], 'yabanci_anahtar' => [], 'oksuz' => [], 'okunamayan' => []];

    try {
        $satirlar = $db->query('PRAGMA integrity_check(20)')->fetchAll(PDO::FETCH_COLUMN);
        $sonuc['butunluk']['tamam'] = ($satirlar === ['ok']);
        if (!$sonuc['butunluk']['tamam']) $sonuc['butunluk']['mesajlar'] = array_map(fn($s) => mb_substr((string)$s, 0, 200), array_slice($satirlar, 0, 20));
    } catch (\Throwable $e) {
        hataGunlugu('veri_sagligi.butunluk', $e);
        $sonuc['butunluk']['mesajlar'] = ['Bütünlük denetimi çalıştırılamadı.'];
    }

    try {
        $grup = [];
        foreach ($db->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_NUM) as $r) {
            $k = $r[0] . '|' . $r[2];
            $grup[$k] = ($grup[$k] ?? 0) + 1;
        }
        arsort($grup);
        foreach ($grup as $k => $adet) {
            [$tablo, $ust] = explode('|', $k, 2);
            $sonuc['yabanci_anahtar'][] = ['tablo' => $tablo, 'ust' => $ust, 'adet' => $adet];
        }
    } catch (\Throwable $e) {
        hataGunlugu('veri_sagligi.yabanci_anahtar', $e);
        $sonuc['okunamayan'][] = 'yabancı anahtar denetimi';
    }

    $var = array_flip(vsTablolar($db));
    foreach (VERI_SAGLIGI_BAGLAR as [$tablo, $sutun, $ust]) {
        if (!isset($var[$tablo], $var[$ust])) continue;
        try {
            if (!vsSutunVar($db, $tablo, $sutun)) continue;
            $adet = (int)$db->query("SELECT COUNT(*) FROM \"$tablo\" t WHERE t.\"$sutun\" IS NOT NULL AND t.\"$sutun\" <> '' AND t.\"$sutun\" <> 0
                                     AND NOT EXISTS (SELECT 1 FROM \"$ust\" u WHERE u.id = t.\"$sutun\")")->fetchColumn();
            if ($adet > 0) $sonuc['oksuz'][] = ['tablo' => $tablo, 'sutun' => $sutun, 'ust' => $ust, 'adet' => $adet];
        } catch (\Throwable $e) {
            hataGunlugu('veri_sagligi.oksuz', $e);
            $sonuc['okunamayan'][] = $tablo . '.' . $sutun;
        }
    }

    $sonuc['sorun'] = ($sonuc['butunluk']['tamam'] ? 0 : 1) + array_sum(array_column($sonuc['yabanci_anahtar'], 'adet'))
                    + array_sum(array_column($sonuc['oksuz'], 'adet'));
    $sonuc['sure_ms'] = (int)round((microtime(true) - $bas) * 1000);
    return $sonuc;
}

function vsSayimlar(PDO $db, array $tablolar): array {
    $s = [];
    foreach ($tablolar as $t) $s[$t] = (int)$db->query('SELECT COUNT(*) FROM "' . $t . '"')->fetchColumn();
    return $s;
}

function vsProvaDizini(PDO $db): string {
    foreach ($db->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) as $d) {
        if (($d['name'] ?? '') === 'main' && ($d['file'] ?? '') !== '') return dirname((string)$d['file']);
    }
    throw new \RuntimeException('Veritabanı dosyasının klasörü bulunamadı.');
}



function yedekProvasi(PDO $db): array {
    $bas = microtime(true);
    $sonuc = ['tamam' => false, 'tablo' => 0, 'kayit' => 0, 'boyut' => 0, 'sorunlar' => []];
    
    
    $gecici = '';
    $kopya = null;
    $eskiMaske = umask(0077);
    try {
        $gecici = ozelGeciciDosya('prova', '.sqlite', vsProvaDizini($db));
        @unlink($gecici);
        $tablolar = vsTablolar($db);
        $once = vsSayimlar($db, $tablolar);
        $db->exec('VACUUM INTO ' . $db->quote($gecici));
        $sonra = vsSayimlar($db, $tablolar);
        clearstatcache(true, $gecici);
        $sonuc['boyut'] = (int)@filesize($gecici);
        if ($sonuc['boyut'] <= 0) throw new \RuntimeException('Anlık görüntü dosyası boş.');

        $kopya = new PDO('sqlite:' . $gecici);
        $kopya->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $kopyaTablolar = vsTablolar($kopya);
        $sonuc['tablo'] = count($kopyaTablolar);
        foreach (array_diff($tablolar, $kopyaTablolar) as $eksik) $sonuc['sorunlar'][] = 'Yedekte tablo eksik: ' . $eksik;
        foreach (YEDEK_PROVA_ZORUNLU_TABLOLAR as $z) {
            if (!in_array($z, $kopyaTablolar, true)) $sonuc['sorunlar'][] = 'Yedekte zorunlu tablo yok: ' . $z;
        }
        $kopyaSayim = vsSayimlar($kopya, $kopyaTablolar);
        $sonuc['kayit'] = array_sum($kopyaSayim);
        foreach ($kopyaSayim as $t => $n) {
            if (!isset($once[$t])) continue;
            $alt = min($once[$t], $sonra[$t] ?? $once[$t]);
            $ust = max($once[$t], $sonra[$t] ?? $once[$t]);
            if ($n < $alt || $n > $ust) $sonuc['sorunlar'][] = "Kayıt sayısı tutmuyor: $t (canlıda $alt, yedekte $n)";
        }
        $b = $kopya->query('PRAGMA integrity_check(5)')->fetchAll(PDO::FETCH_COLUMN);
        if ($b !== ['ok']) $sonuc['sorunlar'][] = 'Yedek kopyası bütünlük denetiminden geçmedi: ' . mb_substr((string)($b[0] ?? '?'), 0, 160);
        yedekSirTemizle($kopya, false);
        $sonuc['tamam'] = !$sonuc['sorunlar'];
    } catch (\Throwable $e) {
        hataGunlugu('veri_sagligi.yedek_prova', $e);
        $sonuc['sorunlar'][] = 'Yedek provası tamamlanamadı: ' . hataMetniTemizle($e->getMessage());
    } finally {
        $kopya = null;
        if ($gecici !== '') @unlink($gecici);
        umask($eskiMaske);
    }
    $sonuc['sure_ms'] = (int)round((microtime(true) - $bas) * 1000);
    return $sonuc;
}

function veriSagligiSatirlari(array $tarama, ?array $prova): array {
    $s = [];
    if (!($tarama['butunluk']['tamam'] ?? false)) {
        $s[] = 'VERİTABANI BÜTÜNLÜĞÜ BOZUK: ' . implode(' / ', array_slice($tarama['butunluk']['mesajlar'] ?? [], 0, 3));
    }
    foreach ($tarama['yabanci_anahtar'] ?? [] as $y) $s[] = "Sahipsiz kayıt: {$y['tablo']} → {$y['ust']} ({$y['adet']} kayıt)";
    foreach ($tarama['oksuz'] ?? [] as $o) $s[] = "Sahipsiz kayıt: {$o['tablo']}.{$o['sutun']} → {$o['ust']} ({$o['adet']} kayıt)";
    if ($prova !== null && !($prova['tamam'] ?? false)) {
        foreach (array_slice($prova['sorunlar'] ?? ['Yedek provası başarısız.'], 0, 5) as $p) $s[] = 'YEDEK PROVASI: ' . $p;
    }
    return $s;
}

function veriSagligiGecelik(PDO $db): array {
    $kayit = ['tarih' => date('Y-m-d H:i:s'), 'tarama' => veriSagligiTara($db), 'prova' => yedekProvasi($db)];
    $dosya = veriSagligiDosyasi();
    if (@is_dir(dirname($dosya)) && @is_writable(dirname($dosya))) {
        @file_put_contents($dosya, json_encode($kayit, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }
    return $kayit;
}

function veriSagligiSon(): ?array {
    $dosya = veriSagligiDosyasi();
    if (!@is_file($dosya)) return null;
    $v = json_decode((string)@file_get_contents($dosya), true);
    return is_array($v) && isset($v['tarih'], $v['tarama']) ? $v : null;
}
