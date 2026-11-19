<?php
declare(strict_types=1);

final class PdfBelge {
    public const EN = 210.0, BOY = 297.0;
    private const K = 72 / 25.4;

    public float $y = 0;
    public float $sol = 12, $sag = 12, $ust = 14, $alt = 16;
    public ?\Closure $devamBasligi = null;
    public array $altbilgi = ['', '', []];

    private array $sayfalar = [];
    private array $altlar = [];
    private array $resimler = [];
    private int $no = -1;

    public function sayfaEkle(): void {
        $this->sayfalar[] = '';
        $this->altlar[] = $this->altbilgi;
        $this->no = count($this->sayfalar) - 1;
        $this->y = $this->ust;
        if ($this->no > 0 && $this->devamBasligi) ($this->devamBasligi)($this);
    }

    public function sayfaSayisi(): int { return count($this->sayfalar); }

    public function icerikEn(): float { return self::EN - $this->sol - $this->sag; }

    public function altSinir(): float { return self::BOY - $this->alt - (($this->altlar[$this->no][2] ?? []) ? 11.0 : 0.0); }

    public function yer(float $h): bool {
        if ($this->no >= 0 && $this->y + $h <= $this->altSinir()) return false;
        $this->sayfaEkle();
        return true;
    }


    public static function kodla(string $s): string {
        static $ozel = [
            0x011E => "\xD0", 0x0130 => "\xDD", 0x015E => "\xDE", 0x011F => "\xF0", 0x0131 => "\xFD", 0x015F => "\xFE",
            0x20AC => "\x80", 0x201A => "\x82", 0x0192 => "\x83", 0x201E => "\x84", 0x2026 => "\x85", 0x2020 => "\x86",
            0x2021 => "\x87", 0x02C6 => "\x88", 0x2030 => "\x89", 0x0160 => "\x8A", 0x2039 => "\x8B", 0x0152 => "\x8C",
            0x2018 => "\x91", 0x2019 => "\x92", 0x201C => "\x93", 0x201D => "\x94", 0x2022 => "\x95", 0x2013 => "\x96",
            0x2014 => "\x97", 0x02DC => "\x98", 0x2122 => "\x99", 0x0161 => "\x9A", 0x203A => "\x9B", 0x0153 => "\x9C",
            0x0178 => "\x9F", 0x00A0 => ' ', 0x20BA => 'TL', 0x2192 => '->', 0x2190 => '<-', 0x2194 => '/',
            0x2713 => 'X', 0x2714 => 'X', 0x2212 => '-', 0x2011 => '-', 0x2010 => '-', 0x2032 => "'", 0x2033 => '"',
        ];
        $out = '';
        foreach (mb_str_split(mb_scrub($s, 'UTF-8'), 1, 'UTF-8') as $c) {
            $k = mb_ord($c, 'UTF-8');
            if ($k === 10) { $out .= "\n"; continue; }
            if ($k === 9) { $out .= ' '; continue; }
            if ($k === false || $k < 32 || $k === 127) continue;
            if ($k < 127) { $out .= $c; continue; }
            if (isset($ozel[$k])) { $out .= $ozel[$k]; continue; }
            if ($k >= 0xA0 && $k <= 0xFF && !in_array($k, [0xD0, 0xDD, 0xDE, 0xF0, 0xFD, 0xFE], true)) { $out .= chr($k); continue; }
            $out .= '?';
        }
        return $out;
    }

    public function genislik(string $metin, float $pt, bool $kalin = false): float { return $this->_gen(self::kodla($metin), $pt, $kalin); }

    public static function satirYuk(float $pt, float $aralik = 1.35): float { return $pt / self::K * $aralik; }

    public function metinYuk(string $metin, float $en, float $pt = 9, bool $kalin = false, float $aralik = 1.35): float {
        return count($this->_bol(self::kodla($metin), $en, $pt, $kalin)) * self::satirYuk($pt, $aralik);
    }

    public function metin(float $x, float $y, string $metin, float $en, float $pt = 9, bool $kalin = false, string $renk = '#172b40',
                          string $hiza = 'L', float $aralik = 1.35): float {
        $h = self::satirYuk($pt, $aralik);
        $satirlar = $this->_bol(self::kodla($metin), $en, $pt, $kalin);
        foreach ($satirlar as $i => $s) $this->_satir($this->_hizala($x, $en, $s, $pt, $kalin, $hiza), $y + $i * $h, $s, $pt, $kalin, $renk);
        return count($satirlar) * $h;
    }

    public function akisMetin(string $metin, float $pt = 9, bool $kalin = false, string $renk = '#172b40', ?float $x = null, ?float $en = null,
                              float $aralik = 1.35): void {
        $x ??= $this->sol;
        $en ??= $this->icerikEn();
        $h = self::satirYuk($pt, $aralik);
        foreach ($this->_bol(self::kodla($metin), $en, $pt, $kalin) as $s) {
            $this->yer($h);
            $this->_satir($x, $this->y, $s, $pt, $kalin, $renk);
            $this->y += $h;
        }
    }

    public function tik(float $x, float $y, float $pt = 9, string $renk = '#183f61'): void {
        $this->sayfalar[$this->no] .= sprintf("BT /F3 %.2F Tf %s rg %.2F %.2F Td (4) Tj ET\n", $pt, self::_renk($renk),
            $x * self::K, (self::BOY - $y - $pt / self::K * 0.8) * self::K);
    }


    public function cizgi(float $x1, float $y1, float $x2, float $y2, string $renk = '#dce4ec', float $kalinlik = 0.2): void {
        $this->sayfalar[$this->no] .= sprintf("q %s RG %.2F w %.2F %.2F m %.2F %.2F l S Q\n", self::_renk($renk), $kalinlik * self::K,
            $x1 * self::K, (self::BOY - $y1) * self::K, $x2 * self::K, (self::BOY - $y2) * self::K);
    }

    public function kutu(float $x, float $y, float $w, float $h, ?string $dolgu, ?string $cerceve = null, float $kalinlik = 0.2): void {
        if ($dolgu === null && $cerceve === null) return;
        $op = $dolgu !== null && $cerceve !== null ? 'B' : ($dolgu !== null ? 'f' : 'S');
        $this->sayfalar[$this->no] .= 'q ' . ($dolgu !== null ? self::_renk($dolgu) . ' rg ' : '')
            . ($cerceve !== null ? self::_renk($cerceve) . ' RG ' . sprintf('%.2F w ', $kalinlik * self::K) : '')
            . sprintf("%.2F %.2F %.2F %.2F re %s Q\n", $x * self::K, (self::BOY - $y - $h) * self::K, $w * self::K, $h * self::K, $op);
    }

    public function resimKaydet(string $jpeg): ?array {
        $b = @getimagesizefromstring($jpeg);
        if (!$b || ($b[2] ?? 0) !== IMAGETYPE_JPEG || $b[0] < 1 || $b[1] < 1 || !in_array($b['channels'] ?? 3, [1, 3], true)) return null;
        $k = sha1($jpeg);
        $this->resimler[$k] ??= ['ad' => 'I' . (count($this->resimler) + 1), 'veri' => $jpeg, 'en' => (int)$b[0], 'boy' => (int)$b[1],
                                 'gri' => ($b['channels'] ?? 3) === 1];
        return $this->resimler[$k];
    }

    public function resim(array $r, float $x, float $y, float $w, float $h): void {
        $o = min($w / $r['en'], $h / $r['boy']);
        $gw = $r['en'] * $o; $gh = $r['boy'] * $o;
        $this->sayfalar[$this->no] .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $gw * self::K, $gh * self::K,
            ($x + ($w - $gw) / 2) * self::K, (self::BOY - $y - ($h - $gh) / 2 - $gh) * self::K, $r['ad']);
    }


    public function cikti(string $baslik = ''): string {
        if ($this->no < 0) $this->sayfaEkle();
        $toplam = count($this->sayfalar);
        foreach (array_keys($this->sayfalar) as $i) $this->_altbilgiCiz($i, $toplam);
        $n = [];
        $n[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding 6 0 R >>';
        $n[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding 6 0 R >>';
        $n[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /ZapfDingbats >>';
        $n[6] = '<< /Type /Encoding /BaseEncoding /WinAnsiEncoding /Differences [208 /Gbreve 221 /Idotaccent 222 /Scedilla 240 /gbreve 253 /dotlessi 254 /scedilla] >>';
        $no = 8; $xo = '';
        foreach ($this->resimler as $r) {
            $n[$no] = ['<< /Type /XObject /Subtype /Image /Width ' . $r['en'] . ' /Height ' . $r['boy'] . ' /ColorSpace /'
                       . ($r['gri'] ? 'DeviceGray' : 'DeviceRGB') . ' /BitsPerComponent 8 /Filter /DCTDecode', $r['veri']];
            $xo .= '/' . $r['ad'] . ' ' . $no . ' 0 R ';
            $no++;
        }
        $n[7] = '<< /ProcSet [/PDF /Text /ImageB /ImageC] /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >>' . ($xo !== '' ? ' /XObject << ' . $xo . '>>' : '') . ' >>';
        $kids = [];
        foreach ($this->sayfalar as $icerik) {
            $sayfa = $no++; $akis = $no++;
            $kids[] = $sayfa . ' 0 R';
            $n[$sayfa] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources 7 0 R /Contents %d 0 R >>', self::EN * self::K, self::BOY * self::K, $akis);
            $n[$akis] = ['<< /Filter /FlateDecode', (string)gzcompress($icerik, 6)];
        }
        $n[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $n[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        $bilgi = $no;
        $n[$bilgi] = '<< /Producer ' . self::_utf16('Taloo') . ($baslik !== '' ? ' /Title ' . self::_utf16($baslik) : '') . ' /CreationDate (D:' . date('YmdHis') . ') >>';
        ksort($n);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $ofs = [];
        foreach ($n as $id => $g) {
            $ofs[$id] = strlen($pdf);
            $pdf .= is_array($g)
                ? $id . " 0 obj\n" . $g[0] . ' /Length ' . strlen($g[1]) . " >>\nstream\n" . $g[1] . "\nendstream\nendobj\n"
                : $id . " 0 obj\n" . $g . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . ($bilgi + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $bilgi; $i++) $pdf .= sprintf("%010d 00000 n \n", $ofs[$i]);
        return $pdf . "trailer\n<< /Size " . ($bilgi + 1) . " /Root 1 0 R /Info $bilgi 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }


    private function _altbilgiCiz(int $i, int $toplam): void {
        [$solMetin, $sagMetin, $subeler] = $this->altlar[$i] + ['', '', []];
        $eski = $this->no;
        $this->no = $i;
        $en = $this->icerikEn();
        $cizgiY = self::BOY - $this->alt + 4;
        if ($subeler) {
            $sayi = min(count($subeler), 5);
            $kolon = $en / $sayi;
            foreach (array_slice($subeler, 0, 5) as $j => $s) {
                $x = $this->sol + $j * $kolon;
                $yy = $cizgiY - 11;
                $yy += $this->metin($x, $yy, (string)($s['baslik'] ?? ''), $kolon - 2, 6.5, true, '#183f61', 'L', 1.25);
                $this->metin($x, $yy, implode("\n", array_filter([$s['adres'] ?? '', ($s['telefon'] ?? '') !== '' ? 'T: ' . $s['telefon'] : '', $s['eposta'] ?? ''])),
                             $kolon - 2, 6, false, '#52697f', 'L', 1.2);
            }
        }
        $this->cizgi($this->sol, $cizgiY, $this->sol + $en, $cizgiY, '#cad6e1', 0.25);
        $sag = trim($sagMetin . ' · Sayfa ' . ($i + 1) . '/' . $toplam, ' ·');
        $sagEn = min($en * 0.55, $this->genislik($sag, 7) + 1);
        $this->metin($this->sol, $cizgiY + 1.6, $solMetin, $en - $sagEn - 3, 7, false, '#52697f');
        $this->metin($this->sol + $en - $sagEn, $cizgiY + 1.6, $sag, $sagEn, 7, false, '#52697f', 'R');
        $this->no = $eski;
    }

    private function _satir(float $x, float $y, string $bayt, float $pt, bool $kalin, string $renk): void {
        if ($bayt === '') return;
        $this->sayfalar[$this->no] .= sprintf("BT /%s %.2F Tf %s rg %.2F %.2F Td (%s) Tj ET\n", $kalin ? 'F2' : 'F1', $pt, self::_renk($renk),
            $x * self::K, (self::BOY - $y - $pt / self::K * 0.8) * self::K, strtr($bayt, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '\\r']));
    }

    private function _hizala(float $x, float $en, string $bayt, float $pt, bool $kalin, string $hiza): float {
        if ($hiza === 'L') return $x;
        $w = $this->_gen($bayt, $pt, $kalin);
        return $hiza === 'R' ? $x + $en - $w : $x + ($en - $w) / 2;
    }

    private function _bol(string $b, float $en, float $pt, bool $kalin): array {
        $out = [];
        foreach (explode("\n", $b) as $paragraf) {
            $paragraf = rtrim($paragraf);
            if ($paragraf === '') { $out[] = ''; continue; }
            $satir = '';
            foreach (preg_split('/(?<= )/', $paragraf) ?: [] as $sozcuk) {
                if ($this->_gen(rtrim($satir . $sozcuk), $pt, $kalin) <= $en) { $satir .= $sozcuk; continue; }
                if (trim($satir) !== '') $out[] = rtrim($satir);
                $satir = '';
                while (strlen($sozcuk) > 1 && $this->_gen(rtrim($sozcuk), $pt, $kalin) > $en) {
                    $i = 1;
                    while ($i < strlen($sozcuk) - 1 && $this->_gen(substr($sozcuk, 0, $i + 1), $pt, $kalin) <= $en) $i++;
                    $out[] = substr($sozcuk, 0, $i);
                    $sozcuk = substr($sozcuk, $i);
                }
                $satir = $sozcuk;
            }
            if (trim($satir) !== '') $out[] = rtrim($satir);
        }
        return $out;
    }

    private function _gen(string $b, float $pt, bool $kalin): float {
        $t = self::_tablo($kalin);
        $s = 0;
        for ($i = 0, $l = strlen($b); $i < $l; $i++) $s += $t[ord($b[$i])];
        return $s * $pt / 1000 / self::K;
    }

    private static function _tablo(bool $kalin): array {
        static $t = [];
        if (isset($t[(int)$kalin])) return $t[(int)$kalin];
        $ascii = $kalin
            ? [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278, 556,556,556,556,556,556,556,556,556,556, 333,333,584,584,584,611,975,
               722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611, 333,278,333,584,556,333,
               556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500, 389,280,389,584, 350]
            : [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278, 556,556,556,556,556,556,556,556,556,556, 278,278,584,584,584,556,1015,
               667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611, 278,278,278,469,556,333,
               556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500, 334,260,334,584, 350];
        $ust = $kalin
            ? [556,350,278,556,500,1000,556,556,333,1000,667,333,1000,350,611,350, 350,278,278,500,500,350,556,1000,333,1000,556,333,944,350,500,667]
            : [556,350,222,556,333,1000,556,556,333,1000,667,333,1000,350,611,350, 350,222,222,333,333,350,556,1000,333,1000,500,333,944,350,500,667];
        $latin = $kalin
            ? [278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,333, 400,584,333,333,333,611,556,278,333,333,365,556,834,834,834,611,
               722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278, 778,722,778,778,778,778,778,584,778,722,722,722,722,278,667,611,
               556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278, 611,611,611,611,611,611,611,584,611,611,611,611,611,278,556,556]
            : [278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333, 400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,
               667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278, 778,722,778,778,778,778,778,584,778,722,722,722,722,278,667,611,
               556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278, 556,556,556,556,556,556,556,584,611,556,556,556,556,278,500,500];
        return $t[(int)$kalin] = array_merge(array_fill(0, 32, 278), $ascii, $ust, $latin);
    }

    private static function _renk(string $hex): string {
        $h = ltrim($hex, '#');
        return sprintf('%.3F %.3F %.3F', hexdec(substr($h, 0, 2)) / 255, hexdec(substr($h, 2, 2)) / 255, hexdec(substr($h, 4, 2)) / 255);
    }

    private static function _utf16(string $s): string {
        return '<FEFF' . strtoupper(bin2hex(mb_convert_encoding(mb_scrub($s, 'UTF-8'), 'UTF-16BE', 'UTF-8'))) . '>';
    }
}
