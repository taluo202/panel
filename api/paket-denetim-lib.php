<?php
declare(strict_types=1);



const PAKET_UYGULAMA_KLASORLERI = ['uploads', 'db', 'dbstore', '.well-known', 'cgi-bin'];
const PAKET_SUNUCU_DOSYALARI = ['error_log', '.user.ini', 'php.ini'];

function paketManifestOku(string $yol): ?array {
    if (!is_file($yol)) return null;
    $m = json_decode((string)file_get_contents($yol), true);
    return is_array($m) && is_array($m['dosyalar'] ?? null) ? $m : null;
}

function paketKarsilastir(string $kok, array $dosyalar): array {
    $eksik = []; $farkli = []; $artik = []; $ust = [];
    foreach ($dosyalar as $yol => $iz) {
        $ust[explode('/', $yol)[0]] = true;
        $tam = $kok . '/' . $yol;
        if (!is_file($tam)) $eksik[] = $yol;
        elseif (hash_file('sha256', $tam) !== $iz) $farkli[] = $yol;
    }
    foreach (array_diff(scandir($kok) ?: [], ['.', '..']) as $ad) {
        if (in_array($ad, PAKET_SUNUCU_DOSYALARI, true)) continue;
        $tam = $kok . '/' . $ad;
        if (!is_dir($tam)) {
            if (!isset($dosyalar[$ad])) $artik[] = $ad;
            continue;
        }
        if (in_array($ad, PAKET_UYGULAMA_KLASORLERI, true)) continue;
        if (!isset($ust[$ad])) { $artik[] = $ad . '/'; continue; }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tam, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || in_array($f->getFilename(), PAKET_SUNUCU_DOSYALARI, true)) continue;
            $rel = $ad . '/' . str_replace('\\', '/', substr($f->getPathname(), strlen($tam) + 1));
            if (!isset($dosyalar[$rel])) $artik[] = $rel;
        }
    }
    sort($eksik); sort($farkli); sort($artik);
    return ['eksik' => $eksik, 'farkli' => $farkli, 'artik' => $artik];
}
