<?php
declare(strict_types=1);




const KOPRU_SURUM = 1;
const KOPRU_EN_COK_KAYIT = 500;
const KOPRU_EN_COK_GOVDE = 4194304;
const KOPRU_KIM = 'Wolvox köprüsü';
const KOPRU_DENEME_SINIRI = 5;
const KOPRU_HATA_SINIRI = 50;
const KOPRU_ALAN_UZUNLUK = ['unvan' => 300, 'telefon' => 100, 'email' => 200, 'vergi_dairesi' => 150, 'vergi_no' => 50, 'adres' => 1000, 'il' => 60];
const KOPRU_SAYAC = ['guncellenen' => 0, 'pasife_alinan' => 0, 'aktife_alinan' => 0, 'eklenen' => 0,
                     'degismeyen' => 0, 'taloo_da_yok' => 0, 'atlanan' => 0];

function kopruAnahtarUret(): string {
    return bin2hex(random_bytes(32));
}

function kopruAnahtarGecerli(string $kayitliOzet, string $gelen): bool {
    if ($kayitliOzet === '' || $gelen === '') return false;
    return hash_equals($kayitliOzet, tokenOzet($gelen));
}



function kopruDenemeAdi(string $ip): string {
    return '[wolvox_kopru:' . $ip . ']';
}

function kopruCokDeneme(PDO $db, string $ip): bool {
    $st = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE username=? AND success=0
                        AND attempted_at > datetime('now','-10 minutes','localtime')");
    $st->execute([kopruDenemeAdi($ip)]);
    return (int)$st->fetchColumn() >= KOPRU_DENEME_SINIRI;
}

function kopruAyarYaz(PDO $db, string $anahtar, string $deger): void {
    $db->prepare("INSERT INTO ayarlar (anahtar, deger) VALUES (?, ?)
                  ON CONFLICT(anahtar) DO UPDATE SET deger=excluded.deger")->execute([$anahtar, $deger]);
}

function kopruAyarOku(PDO $db, string $anahtar): string {
    $st = $db->prepare("SELECT deger FROM ayarlar WHERE anahtar = ?");
    $st->execute([$anahtar]);
    $v = $st->fetchColumn();
    return ($v === false || $v === null) ? '' : (string)$v;
}

function kopruDurum(PDO $db): array {
    $sonuc = json_decode(kopruAyarOku($db, 'wolvox_kopru_son_sonuc'), true);
    return [
        'anahtar_var'     => kopruAyarOku($db, 'wolvox_kopru_ozet') !== '',
        'anahtar_tarihi'  => kopruAyarOku($db, 'wolvox_kopru_anahtar_tarihi') ?: null,
        'son_temas'       => kopruAyarOku($db, 'wolvox_kopru_son_temas') ?: null,
        'yeni_cari_ekle'  => kopruAyarOku($db, 'wolvox_kopru_yeni_cari_ekle') === '1',
        'son_gonderim'    => is_array($sonuc) ? $sonuc : null,
    ];
}


function kopruIstekHatasi(array $govde): ?array {
    if (($govde['surum'] ?? null) !== KOPRU_SURUM) {
        return [400, 'Desteklenmeyen köprü sürümü. Beklenen: ' . KOPRU_SURUM . '.'];
    }
    if (($govde['tur'] ?? null) !== 'cari') return [400, 'Geçersiz tür. Beklenen: cari.'];
    $c = $govde['cariler'] ?? null;
    if (!is_array($c) || !array_is_list($c)) return [400, 'cariler bir liste olmalı.'];
    if (count($c) > KOPRU_EN_COK_KAYIT) return [413, 'Tek istekte en çok ' . KOPRU_EN_COK_KAYIT . ' cari gönderilebilir.'];
    return null;
}

function _kopruMetin($v, int $uzunluk): string {
    if (!is_string($v) && !is_int($v) && !is_float($v)) return '';
    $s = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$v) ?? '');
    return mb_substr($s, 0, $uzunluk);
}

function _kopruBayrak($v): ?bool {
    if ($v === null || $v === '') return null;
    if (is_bool($v)) return $v;
    if (is_int($v) || is_float($v) || (is_string($v) && is_numeric(trim($v)))) return (int)$v !== 0;
    return null;
}



function kopruCariCevir(array $c): array {
    $a = [];
    foreach (['unvan' => 'unvan', 'telefon' => 'telefon', 'eposta' => 'email', 'vergi_dairesi' => 'vergi_dairesi', 'adres' => 'adres'] as $gelen => $alan) {
        $a[$alan] = _kopruMetin($c[$gelen] ?? '', KOPRU_ALAN_UZUNLUK[$alan]);
    }
    $vn = _kopruMetin($c['vergi_no'] ?? '', KOPRU_ALAN_UZUNLUK['vergi_no']);
    $a['vergi_no'] = $vn !== '' ? $vn : _kopruMetin($c['tc'] ?? '', KOPRU_ALAN_UZUNLUK['vergi_no']);
    $tur = is_string($c['tur'] ?? null) ? trim($c['tur']) : '';
    $a['tur'] = in_array($tur, ['kurumsal', 'bireysel'], true) ? $tur : '';
    
    $il = ilYazim(_kopruMetin($c['il'] ?? '', KOPRU_ALAN_UZUNLUK['il']));
    $a['il'] = ($il !== '' && in_array($il, trIlListesi(), true)) ? $il : '';
    $aktif = _kopruBayrak($c['aktif'] ?? null);
    $silindi = _kopruBayrak($c['silindi'] ?? null);
    $a['durum'] = ($aktif === null && $silindi === null) ? null : ((($aktif ?? true) && !($silindi ?? false)) ? 'aktif' : 'pasif');
    return $a;
}

function kopruGrupEklenmez(string $grup): bool {
    $g = mb_strtoupper(trim($grup), 'UTF-8');
    return str_starts_with($g, 'TEDAR') || str_starts_with($g, 'PERSONEL');
}



function kopruCariUygula(PDO $db, array $cariler, bool $yeniEkle): array {
    require_once __DIR__ . '/il-veri.php';
    $s = KOPRU_SAYAC;
    $hatalar = [];
    $hata = function (string $m) use (&$hatalar): void { if (count($hatalar) < KOPRU_HATA_SINIRI) $hatalar[] = $m; };

    $harita = [];
    $q = $db->query("SELECT id, TRIM(wolvox_cari_kodu) AS kod, tur, unvan, telefon, email, vergi_dairesi, vergi_no, adres, il, durum, wolvox_senkron_durum
                     FROM musteriler WHERE TRIM(COALESCE(wolvox_cari_kodu,'')) <> '' ORDER BY id");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $m) $harita[$m['kod']][] = $m;

    $kendiIslemi = !$db->inTransaction();
    if ($kendiIslemi) $db->beginTransaction();
    try {
        foreach ($cariler as $i => $c) {
            $sira = (int)$i + 1;
            if (!is_array($c)) { $s['atlanan']++; $hata("$sira. kayıt: biçim geçersiz."); continue; }
            $kod = _kopruMetin($c['carikodu'] ?? '', 60);
            if ($kod === '') { $s['atlanan']++; $hata("$sira. kayıt: cari kodu boş."); continue; }
            $a = kopruCariCevir($c);
            if ($a['unvan'] === '') { $s['atlanan']++; $hata("$kod: ünvan boş, atlandı."); continue; }

            $eslesen = $harita[$kod] ?? [];
            if (count($eslesen) > 1) {
                $s['atlanan']++;
                $hata("$kod: Taloo'da bu cari koduyla " . count($eslesen) . ' müşteri var, atlandı.');
                continue;
            }

            if (!$eslesen) {
                $eklenebilir = $yeniEkle && $a['durum'] === 'aktif' && !kopruGrupEklenmez(_kopruMetin($c['grup'] ?? '', 100));
                if (!$eklenebilir) { $s['taloo_da_yok']++; continue; }
                $db->prepare("INSERT INTO musteriler (tur, unvan, telefon, email, vergi_dairesi, vergi_no, adres, il, durum,
                                  wolvox_cari_kodu, wolvox_senkron_durum, wolvox_senkron_tarihi)
                              VALUES (?,?,?,?,?,?,?,?,'aktif',?,'senkron',datetime('now','localtime'))")
                   ->execute([$a['tur'] ?: 'kurumsal', $a['unvan'], $a['telefon'] ?: null, $a['email'] ?: null, $a['vergi_dairesi'] ?: null,
                              $a['vergi_no'] ?: null, $a['adres'] ?: null, $a['il'] ?: null, $kod]);
                $yeniId = (int)$db->lastInsertId();
                $yeni = logKayitAl($db, 'musteriler', $yeniId) ?? [];
                $harita[$kod] = [['id' => $yeniId, 'kod' => $kod] + $yeni];
                logla($db, 'musteriler', $yeniId, 'ekle', null, $yeni, "Wolvox'tan yeni müşteri eklendi ($kod)", KOPRU_KIM);
                $s['eklenen']++;
                continue;
            }

            $m = $eslesen[0];
            $eski = []; $yeni = [];
            foreach (['unvan', 'tur', 'vergi_no', 'vergi_dairesi', 'telefon', 'email', 'adres', 'il', 'durum'] as $alan) {
                $v = $a[$alan];
                if ($v === null || $v === '') continue;
                if ((string)($m[$alan] ?? '') === $v) continue;
                $eski[$alan] = $m[$alan];
                $yeni[$alan] = $v;
            }
            if (!$yeni) {
                
                if ((string)($m['wolvox_senkron_durum'] ?? '') !== 'senkron') {
                    $db->prepare("UPDATE musteriler SET wolvox_senkron_durum='senkron', wolvox_senkron_tarihi=datetime('now','localtime') WHERE id=?")
                       ->execute([(int)$m['id']]);
                    $harita[$kod][0]['wolvox_senkron_durum'] = 'senkron';
                }
                $s['degismeyen']++;
                continue;
            }
            $set = implode(', ', array_map(fn($k) => "$k=?", array_keys($yeni)));
            $db->prepare("UPDATE musteriler SET $set, wolvox_senkron_durum='senkron', wolvox_senkron_tarihi=datetime('now','localtime'),
                              updated_at=datetime('now','localtime') WHERE id=?")
               ->execute([...array_values($yeni), (int)$m['id']]);
            $harita[$kod][0] = array_merge($m, $yeni, ['wolvox_senkron_durum' => 'senkron']);
            $durumDegisti = $yeni['durum'] ?? null;
            $ozet = $durumDegisti === 'pasif' ? "Wolvox'ta pasif/silinmiş: müşteri pasife alındı ($kod)"
                  : ($durumDegisti === 'aktif' ? "Wolvox'ta yeniden aktif: müşteri aktife alındı ($kod)"
                  : "Wolvox'tan güncellendi ($kod): " . implode(', ', array_keys($yeni)));
            logla($db, 'musteriler', (int)$m['id'], 'guncelle', $eski, $yeni, $ozet, KOPRU_KIM);
            $s[$durumDegisti === 'pasif' ? 'pasife_alinan' : ($durumDegisti === 'aktif' ? 'aktife_alinan' : 'guncellenen')]++;
        }
        if ($kendiIslemi) $db->commit();
    } catch (\Throwable $e) {
        if ($kendiIslemi && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return $s + ['hatalar' => $hatalar];
}
