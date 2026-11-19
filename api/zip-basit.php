<?php
declare(strict_types=1);


const ZIP_YONTEM_STORE   = 0;
const ZIP_YONTEM_DEFLATE = 8;
const ZIP_TEK_DOSYA_SINIRI = 256 * 1024 * 1024;
const ZIP_TOPLAM_SINIRI    = 4 * 1024 * 1024 * 1024;

function zipToplamAcikBoyut(array $icindekiler): int {
    $t = 0;
    foreach ($icindekiler as $g) $t += max(0, (int)($g['usize'] ?? 0));
    return $t;
}

function _zipDosDamga(int $ts): array {
    $t = getdate($ts);
    $yil = max(1980, $t['year']);
    return [
        (($t['hours'] << 11) | ($t['minutes'] << 5) | (int)($t['seconds'] / 2)) & 0xFFFF,
        ((($yil - 1980) << 9) | ($t['mon'] << 5) | $t['mday']) & 0xFFFF,
    ];
}

function zipYaz($cikti, array $girdiler): void {
    $merkez = '';
    $ofset  = 0;
    $adet   = 0;

    foreach ($girdiler as $g) {
        $ad = str_replace(chr(92), '/', (string)$g['ad']);
        if ($ad === '' || str_contains($ad, '..')) continue;

        $buyukEsik = 8 * 1024 * 1024;
        if (!isset($g['veri']) && is_file((string)$g['yol']) && filesize((string)$g['yol']) > $buyukEsik) {
            $yol   = (string)$g['yol'];
            $usize = (int)filesize($yol);
            $crc   = (int)hexdec(hash_file('crc32b', $yol));
            [$dosSaat, $dosTarih] = _zipDosDamga((int)filemtime($yol));
            $yerel = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, ZIP_YONTEM_STORE, $dosSaat, $dosTarih,
                          $crc, $usize, $usize, strlen($ad), 0) . $ad;
            fwrite($cikti, $yerel);
            $gh = fopen($yol, 'rb');
            while ($gh && !feof($gh)) fwrite($cikti, (string)fread($gh, 1 << 20));
            if ($gh) fclose($gh);
            $merkez .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, ZIP_YONTEM_STORE, $dosSaat, $dosTarih,
                            $crc, $usize, $usize, strlen($ad), 0, 0, 0, 0, 0x81a4 << 16, $ofset) . $ad;
            $ofset += strlen($yerel) + $usize;
            $adet++;
            continue;
        }

        $veri = isset($g['veri']) ? (string)$g['veri'] : @file_get_contents((string)$g['yol']);
        if ($veri === false) continue;

        $ts   = isset($g['yol']) && is_file($g['yol']) ? (int)filemtime($g['yol']) : time();
        [$dosSaat, $dosTarih] = _zipDosDamga($ts);

        $usize = strlen($veri);
        $crc   = crc32($veri);

        $sikisik = (bool)preg_match('/\.(jpe?g|png|gif|webp|pdf|zip|gz)$/i', $ad);
        if (!$sikisik && function_exists('gzdeflate') && $usize > 0) {
            $paket  = gzdeflate($veri, 6);
            $yontem = ($paket !== false && strlen($paket) < $usize) ? ZIP_YONTEM_DEFLATE : ZIP_YONTEM_STORE;
            if ($yontem === ZIP_YONTEM_STORE) $paket = $veri;
        } else {
            $yontem = ZIP_YONTEM_STORE;
            $paket  = $veri;
        }
        unset($veri);
        $csize = strlen($paket);

        $yerel = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, $yontem, $dosSaat, $dosTarih,
                      $crc, $csize, $usize, strlen($ad), 0) . $ad;
        fwrite($cikti, $yerel);
        fwrite($cikti, $paket);
        unset($paket);

        $merkez .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, $yontem, $dosSaat, $dosTarih,
                        $crc, $csize, $usize, strlen($ad), 0, 0, 0, 0, 0x81a4 << 16, $ofset) . $ad;

        $ofset += strlen($yerel) + $csize;
        $adet++;
    }

    $merkezOfset = $ofset;
    fwrite($cikti, $merkez);
    fwrite($cikti, pack('VvvvvVVv', 0x06054b50, 0, 0, $adet, $adet, strlen($merkez), $merkezOfset, 0));
}

function zipIcindekiler(string $dosya): array {
    $fh = @fopen($dosya, 'rb');
    if (!$fh) return [];
    try {
        $boy = filesize($dosya) ?: 0;
        if ($boy < 22) return [];
        $tara = min($boy, 65557);
        fseek($fh, $boy - $tara);
        $kuyruk = (string)fread($fh, $tara);
        $p = strrpos($kuyruk, "PK\x05\x06");
        if ($p === false) return [];
        $eocd = unpack('Vsig/vdisk/vcdDisk/vbuAdet/vadet/VcdBoy/VcdOfset/vyorum', substr($kuyruk, $p, 22));
        if (!$eocd || $eocd['adet'] < 1) return [];

        fseek($fh, $eocd['cdOfset']);
        $cd = (string)fread($fh, $eocd['cdBoy']);
        $out = [];
        $i = 0;
        for ($n = 0; $n < $eocd['adet']; $n++) {
            if (substr($cd, $i, 4) !== "PK\x01\x02") break;
            $h = unpack('Vsig/vyapan/vgerek/vbayrak/vyontem/vsaat/vtarih/Vcrc/Vcsize/Vusize/vfnlen/vexlen/vcmlen/vdisk/vic/Vdis/Vofset', substr($cd, $i, 46));
            $ad = substr($cd, $i + 46, $h['fnlen']);
            $out[$ad] = [
                'yontem' => $h['yontem'], 'csize' => $h['csize'], 'usize' => $h['usize'],
                'crc' => $h['crc'], 'yerel_ofset' => $h['ofset'],
            ];
            $i += 46 + $h['fnlen'] + $h['exlen'] + $h['cmlen'];
        }
        return $out;
    } finally {
        fclose($fh);
    }
}

function zipCikar(string $dosya, string $ad, ?array $icindekiler = null, int $sinir = ZIP_TEK_DOSYA_SINIRI): ?string {
    $ic = $icindekiler ?? zipIcindekiler($dosya);
    if (!isset($ic[$ad])) return null;
    $g  = $ic[$ad];
    if ($g['usize'] > $sinir || $g['csize'] > $sinir) return null;
    if ($g['yontem'] === ZIP_YONTEM_STORE && $g['csize'] !== $g['usize']) return null;
    $fh = @fopen($dosya, 'rb');
    if (!$fh) return null;
    try {
        fseek($fh, $g['yerel_ofset']);
        $bas = (string)fread($fh, 30);
        if (substr($bas, 0, 4) !== "PK\x03\x04") return null;
        $h = unpack('Vsig/vgerek/vbayrak/vyontem/vsaat/vtarih/Vcrc/Vcsize/Vusize/vfnlen/vexlen', $bas);
        fseek($fh, $g['yerel_ofset'] + 30 + $h['fnlen'] + $h['exlen']);
        $paket = $g['csize'] > 0 ? (string)fread($fh, $g['csize']) : '';
        $veri  = $g['yontem'] === ZIP_YONTEM_DEFLATE ? @gzinflate($paket, max(1, $g['usize'])) : $paket;
        if ($veri === false || strlen($veri) !== $g['usize']) return null;
        if (crc32($veri) !== $g['crc']) return null;
        return $veri;
    } finally {
        fclose($fh);
    }
}
