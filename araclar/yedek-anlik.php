<?php
declare(strict_types=1);


if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Bu betik yalnızca sunucu terminalinden (cron) çalışır.\n");
}

require __DIR__ . '/../api/config.php';

const SAKLANACAK_GUN = 14;

$hedefDizin = dirname(DB_PATH) . '/yedek';
if (!is_dir($hedefDizin) && !mkdir($hedefDizin, 0700, true)) {
    fwrite(STDERR, "Yedek klasörü oluşturulamadı: $hedefDizin\n");
    exit(1);
}

$damga = date('Y-m-d-His');
$hedef = $hedefDizin . '/panel-' . $damga . '.sqlite';

try {
    $db = getDb();
    $db->exec("VACUUM INTO " . $db->quote($hedef));
} catch (\Throwable $e) {
    fwrite(STDERR, "Yedek alınamadı: " . $e->getMessage() . "\n");
    exit(1);
}
@chmod($hedef, 0600);

$boyut = filesize($hedef) ?: 0;
echo "Yedek alındı: $hedef (" . round($boyut / 1048576, 2) . " MB)\n";

$uploadKaynak = rtrim(UPLOAD_PATH, '/\\');
$uploadHedef  = $hedefDizin . '/dosyalar';
if (is_dir($uploadKaynak)) {
    $kopyalanan = 0;
    $yineleyici = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadKaynak, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($yineleyici as $yol => $bilgi) {
        $rel = substr($yol, strlen($uploadKaynak) + 1);
        $hed = $uploadHedef . '/' . $rel;
        if ($bilgi->isDir()) {
            if (!is_dir($hed)) @mkdir($hed, 0700, true);
            continue;
        }
        if (is_file($hed) && filesize($hed) === $bilgi->getSize() && filemtime($hed) >= $bilgi->getMTime()) continue;
        if (!is_dir(dirname($hed))) @mkdir(dirname($hed), 0700, true);
        if (@copy($yol, $hed)) $kopyalanan++;
    }
    echo "Dosyalar eşitlendi: $kopyalanan yeni/değişmiş dosya\n";
}

$silinen = 0;
foreach (glob($hedefDizin . '/panel-*.sqlite') ?: [] as $eski) {
    if (filemtime($eski) < strtotime('-' . SAKLANACAK_GUN . ' days')) {
        @unlink($eski) && $silinen++;
    }
}
if ($silinen) echo "$silinen eski kopya silindi (saklama: " . SAKLANACAK_GUN . " gün)\n";

guvenlikLog('yedek_anlik', 'sistem (cron)', null, [
    'dosya'  => basename($hedef),
    'boyut'  => $boyut,
]);

echo "Tamam.\n";
