<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/eposta.php';


if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonError('Method Not Allowed', 405);

$db = getDb();

$kayitli = (string)($db->query("SELECT deger FROM ayarlar WHERE anahtar='cron_anahtar'")->fetchColumn() ?: '');
$gelen   = (string)($_GET['anahtar'] ?? '');
if ($kayitli === '' || strlen($kayitli) < 20 || !hash_equals($kayitli, $gelen)) {
    jsonError('Geçersiz anahtar.', 403);
}

$bugun = date('Y-m-d');
$w7    = date('Y-m-d', strtotime('+7 days'));
$w15   = date('Y-m-d', strtotime('+15 days'));
$w30   = date('Y-m-d', strtotime('+30 days'));

$bolumler = [];
$ekle = function (string $baslik, array $satirlar) use (&$bolumler): void {
    if ($satirlar) $bolumler[] = ['baslik' => $baslik, 'satirlar' => $satirlar];
};
$g = fn(?string $d) => $d ? date('d.m.Y', strtotime($d)) : '';
$tl = fn($n) => number_format((float)$n, 2, ',', '.') . ' TL';

$st = $db->prepare("SELECT k.kod, k.ekipman_adi, k.bitis, m.unvan
                    FROM kiralamalar k LEFT JOIN musteriler m ON m.id=k.musteri_id
                    WHERE k.durum='aktif' AND k.gercek_bitis IS NULL AND k.bitis IS NOT NULL AND k.bitis<=?
                    ORDER BY k.bitis LIMIT 50");
$st->execute([$w7]);
$ekle('İADE ZAMANI GELEN KİRALAMALAR', array_map(
    fn($r) => ($r['bitis'] < $bugun ? '[GECİKTİ] ' : '') . ($r['unvan'] ?: '—') . ' · ' . ($r['ekipman_adi'] ?: $r['kod']) . ' · ' . $g($r['bitis']),
    $st->fetchAll()));

$st = $db->prepare("SELECT c.tip, c.tutar, c.vade_tarihi, c.kisi, m.unvan
                    FROM cek_senet c LEFT JOIN musteriler m ON m.id=c.musteri_id
                    WHERE c.durum='portfoyde' AND c.vade_tarihi<=? ORDER BY c.vade_tarihi LIMIT 50");
$st->execute([$w15]);
$ekle('VADESİ YAKLAŞAN ÇEK / SENET', array_map(
    fn($r) => ($r['vade_tarihi'] < $bugun ? '[GEÇTİ] ' : '') . ($r['kisi'] ?: $r['unvan'] ?: ucfirst((string)$r['tip'])) . ' · ' . $tl($r['tutar']) . ' · ' . $g($r['vade_tarihi']),
    $st->fetchAll()));

$st = $db->prepare("SELECT plaka, trafik_bitis, kasko_bitis, tuv_muayene_bitis, periyodik_kontrol_bitis
                    FROM araclar WHERE (aktif IS NULL OR aktif=1)
                      AND (trafik_bitis<=? OR kasko_bitis<=? OR tuv_muayene_bitis<=? OR periyodik_kontrol_bitis<=?)
                    ORDER BY plaka LIMIT 50");
$st->execute([$w30, $w30, $w30, $w30]);
$aracSatir = [];
foreach ($st->fetchAll() as $r) {
    foreach (['Trafik' => $r['trafik_bitis'], 'Kasko' => $r['kasko_bitis'],
              'TÜV' => $r['tuv_muayene_bitis'], 'Periyodik' => $r['periyodik_kontrol_bitis']] as $ad => $tar) {
        if ($tar && $tar <= $w30) $aracSatir[] = ($tar < $bugun ? '[GEÇTİ] ' : '') . $r['plaka'] . ' · ' . $ad . ' · ' . $g($tar);
    }
}
$ekle('ARAÇ EVRAK / MUAYENE', $aracSatir);

$bakim = [];
foreach ([['araclar', 'plaka'], ['manliftler', 'kod']] as [$tablo, $adKol]) {
    $q = $db->query("SELECT $adKol AS ad, calisma_saati, son_bakim_saat, bakim_araligi_saat
                     FROM $tablo WHERE (aktif IS NULL OR aktif=1) AND " . saatBakimSqlKosul() . " LIMIT 50");
    foreach ($q->fetchAll() as $r) {
        $d = saatBakimDurumu($r);
        if ($d === null || $d['durum'] === 'normal') continue;
        $bakim[] = ($d['durum'] === 'gecti' ? '[AŞILDI] ' : '') . $r['ad'] . ' · '
                 . number_format(abs($d['kalan_saat']), 0, ',', '.') . ' saat '
                 . ($d['kalan_saat'] < 0 ? 'fazla çalıştı' : 'kaldı');
    }
}
$ekle('SAAT BAZLI BAKIM', $bakim);

$st = $db->query("
    SELECT ad, parca_no, stok, kritik_stok FROM yedek_parca
    WHERE COALESCE(kritik_stok,0) > 0 AND COALESCE(stok,0) <= COALESCE(kritik_stok,0)
    ORDER BY (COALESCE(stok,0) - COALESCE(kritik_stok,0)) ASC LIMIT 50");
$ekle('KRİTİK STOK', array_map(
    fn($r) => ((int)$r['stok'] < 0 ? '[EKSİDE] ' : '') . $r['ad']
            . ($r['parca_no'] ? ' (' . $r['parca_no'] . ')' : '')
            . ' · stok ' . (int)$r['stok'] . ' / eşik ' . (int)$r['kritik_stok'],
    $st->fetchAll()));

$kat = ['Ehliyet', 'SRC', 'Psikoteknik', 'İSG Eğitimi', 'Sağlık Raporu', 'Yüksekte Çalışma'];
$ph  = implode(',', array_fill(0, count($kat), '?'));
$st  = $db->prepare("SELECT pb.kategori, pb.bitis_tarihi, p.ad_soyad
                     FROM personel_belgeler pb JOIN personel p ON p.id=pb.personel_id
                     WHERE COALESCE(p.durum,'aktif') <> 'pasif' AND pb.kategori IN ($ph) AND pb.bitis_tarihi IS NOT NULL
                       AND (pb.arsiv IS NULL OR pb.arsiv=0) AND pb.bitis_tarihi<=?
                     ORDER BY pb.bitis_tarihi LIMIT 50");
$st->execute(array_merge($kat, [$w30]));
$ekle('PERSONEL EVRAK SÜRESİ', array_map(
    fn($r) => ($r['bitis_tarihi'] < $bugun ? '[GEÇTİ] ' : '') . $r['ad_soyad'] . ' · ' . $r['kategori'] . ' · ' . $g($r['bitis_tarihi']),
    $st->fetchAll()));

require_once __DIR__ . '/zincir-lib.php';
try { istisnaTaraKilitli($db, true); } catch (\Throwable $e) { error_log('cron istisnaTara: ' . $e->getMessage()); }
$_zKod = array_keys(zincirKurallari($db, $bugun, []));
$st = $db->prepare("SELECT baslik FROM gorevler WHERE durum='acik' AND kaynak='otomatik' AND kaynak_modul IN (" . implode(',', array_fill(0, count($_zKod), '?')) . ")
                    ORDER BY (oncelik='yuksek') DESC, id LIMIT 50");
$st->execute($_zKod);
$ekle('FATURA / TAHSİLAT ZİNCİRİ (Dikkat Gerekenler)', array_column($st->fetchAll(), 'baslik'));

require_once __DIR__ . '/veri-sagligi-lib.php';
@set_time_limit(300);
try {
    $_vs = veriSagligiGecelik($db);
    $ekle('VERİ SAĞLIĞI (Ayarlar > Sistem Ön Kontrolü)', veriSagligiSatirlari($_vs['tarama'], $_vs['prova']));
} catch (\Throwable $e) { hataGunlugu('cron.veri_sagligi', $e); }

$toplamSatir = array_sum(array_map(fn($b) => count($b['satirlar']), $bolumler));

if ($toplamSatir === 0) {
    jsonOk(['gonderildi' => false, 'sebep' => 'uyari_yok', 'tarih' => $bugun], 'Bugün uyarı yok; e-posta gönderilmedi.');
}

$alici = (string)($db->query("SELECT deger FROM ayarlar WHERE anahtar='bildirim_email'")->fetchColumn() ?: '');
if ($alici === '') {
    jsonOk(['gonderildi' => false, 'sebep' => 'alici_yok', 'uyari_sayisi' => $toplamSatir],
           'Uyarı var ama Ayarlar\'da bildirim e-posta adresi tanımlı değil.');
}

$firma = (string)($db->query("SELECT deger FROM ayarlar WHERE anahtar='firma_unvan'")->fetchColumn() ?: 'Taloo');
$govde = $firma . " — Günlük Özet (" . date('d.m.Y') . ")\n"
       . str_repeat('=', 48) . "\n\n";
foreach ($bolumler as $b) {
    $govde .= $b['baslik'] . ' (' . count($b['satirlar']) . ")\n" . str_repeat('-', 40) . "\n";
    foreach ($b['satirlar'] as $s) $govde .= '  • ' . $s . "\n";
    $govde .= "\n";
}
$govde .= "Ayrıntı için panele girin: https://" . guvenliHost() . "/index.html\n";

$snc = epostaGonder($db, $alici, '[' . $firma . '] Günlük özet · ' . $toplamSatir . ' uyarı', $govde, 'gunluk_ozet');

jsonOk([
    'gonderildi'   => $snc['basarili'],
    'yontem'       => $snc['yontem'],
    'hata'         => $snc['hata'],
    'uyari_sayisi' => $toplamSatir,
    'bolumler'     => array_map(fn($b) => ['baslik' => $b['baslik'], 'adet' => count($b['satirlar'])], $bolumler),
], $snc['basarili'] ? 'Günlük özet gönderildi.' : 'Özet hazırlandı ama e-posta gönderilemedi.');
