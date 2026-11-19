<?php
declare(strict_types=1);



function varlikDamgasi(string $kok, string $yol): ?string {
    $st = @stat($kok . '/' . $yol);
    if ($st === false) return null;
    return hash('crc32b', $st['mtime'] . ':' . $st['size']);
}



function varlikSurumu(string $kok): string {
    $sw = @file_get_contents($kok . '/sw.js');
    return ($sw !== false && preg_match("/const SURUM = '([A-Za-z0-9._-]+)'/", $sw, $m)) ? $m[1] : '';
}

function varliklariDamgala(string $html, string $kok): string {
    $surum = varlikSurumu($kok);
    if ($surum !== '' && !str_contains($html, 'data-surum=')) {
        $html = (string)preg_replace('/<html\b/i', '<html data-surum="' . $surum . '"', $html, 1);
    }
    return (string)preg_replace_callback(
        '#((?:src|href)=")((?:js|css)/[A-Za-z0-9._/-]+\.(?:js|css))(?:\?v=[^"]*)?(")#',
        function (array $m) use ($kok): string {
            if (str_contains($m[2], '..')) return $m[0];
            $d = varlikDamgasi($kok, $m[2]);
            return $m[1] . $m[2] . ($d !== null ? '?v=' . $d : '') . $m[3];
        },
        $html
    );
}
