<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/eposta.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('ayarlar');
verifyCsrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method Not Allowed', 405);

$db = getDb();

function fmtTarih(string $d): string {
    $p = explode('-', $d);
    return count($p) === 3 ? "{$p[2]}.{$p[1]}.{$p[0]}" : $d;
}

function fmtGun(string $tarih): string {
    $diff = (int)ceil((strtotime($tarih) - time()) / 86400);
    return $diff < 0 ? 'GEÇMİŞ' : "{$diff} gün kaldı";
}

$ayarRows = $db->query("SELECT anahtar, deger FROM ayarlar")->fetchAll();
$ay = [];
foreach ($ayarRows as $r) $ay[$r['anahtar']] = $r['deger'];

$esik  = max(1, (int)($ay['bildirim_gun_esigi'] ?? 30));
$sinir = date('Y-m-d', strtotime("+{$esik} days"));

$bolumler = [];

$aTarihler = [
    'trafik_bitis'            => 'Trafik Sig.',
    'kasko_bitis'             => 'Kasko',
    'makine_kirilma_bitis'    => 'Makine Kırılma',
    'tuv_muayene_bitis'       => 'TÜV Muayene',
    'periyodik_kontrol_bitis' => 'Periyodik',
];

$aStmt = $db->prepare("
    SELECT plaka, trafik_bitis, kasko_bitis, makine_kirilma_bitis,
           tuv_muayene_bitis, periyodik_kontrol_bitis
    FROM araclar
    WHERE trafik_bitis <= ? OR kasko_bitis <= ? OR makine_kirilma_bitis <= ?
       OR tuv_muayene_bitis <= ? OR periyodik_kontrol_bitis <= ?
");
$aStmt->execute([$sinir, $sinir, $sinir, $sinir, $sinir]);

$aracSatir = [];
foreach ($aStmt->fetchAll() as $a) {
    foreach ($aTarihler as $kol => $etiket) {
        if (!empty($a[$kol]) && $a[$kol] <= $sinir) {
            $aracSatir[] = "  • {$a['plaka']} — {$etiket}: " . fmtTarih($a[$kol]) . ' (' . fmtGun($a[$kol]) . ')';
        }
    }
}
if ($aracSatir) $bolumler['*Araçlar*'] = $aracSatir;

$kritik = ['Ehliyet','SRC','Psikoteknik','İSG Eğitimi','Sağlık Raporu','Yüksekte Çalışma'];
$ph     = implode(',', array_fill(0, count($kritik), '?'));
$pStmt  = $db->prepare("
    SELECT pb.kategori, pb.bitis_tarihi, p.ad_soyad
    FROM personel_belgeler pb
    JOIN personel p ON p.id = pb.personel_id
    WHERE COALESCE(p.durum,'aktif') <> 'pasif' AND pb.kategori IN ($ph) AND COALESCE(pb.arsiv,0)=0 AND pb.bitis_tarihi IS NOT NULL AND pb.bitis_tarihi <= ?
    ORDER BY pb.bitis_tarihi
");
$pStmt->execute(array_merge($kritik, [$sinir]));

$personelSatir = [];
foreach ($pStmt->fetchAll() as $b) {
    $personelSatir[] = "  • {$b['ad_soyad']} — {$b['kategori']}: " . fmtTarih($b['bitis_tarihi']) . ' (' . fmtGun($b['bitis_tarihi']) . ')';
}
if ($personelSatir) $bolumler['*Personel Belgeleri*'] = $personelSatir;

$mStmt = $db->prepare("
    SELECT kod, periyodik_bitis FROM manliftler
    WHERE periyodik_bitis IS NOT NULL AND periyodik_bitis <= ?
    ORDER BY periyodik_bitis
");
$mStmt->execute([$sinir]);

$manliftSatir = [];
foreach ($mStmt->fetchAll() as $m) {
    $manliftSatir[] = "  • {$m['kod']} — Periyodik: " . fmtTarih($m['periyodik_bitis']) . ' (' . fmtGun($m['periyodik_bitis']) . ')';
}
if ($manliftSatir) $bolumler['*Makine*'] = $manliftSatir;

$toplamUyari = (int)array_sum(array_map('count', $bolumler));

if ($toplamUyari === 0) {
    jsonOk(['gonderilen' => 0, 'uyari_sayisi' => 0],
        "Uyarı verecek belge bulunamadı ({$esik} günlük eşik).");
}

$satirlar = ["⚠️ *Kurumsal Panel — {$toplamUyari} Uyarı*", date('d.m.Y H:i'), ""];
foreach ($bolumler as $baslik => $bSatirlar) {
    $satirlar[] = $baslik . ':';
    foreach ($bSatirlar as $s) $satirlar[] = $s;
    $satirlar[] = '';
}
$mesaj = trim(implode("\n", $satirlar));

$gonderilen = 0;
$hatalar    = [];

if (!empty($ay['bildirim_email_aktif']) && !empty($ay['bildirim_email'])) {
    $konu     = "[Kurumsal Panel] {$toplamUyari} belge uyarisi - " . date('d.m.Y');
    $duzMetin = str_replace(['*', '_'], '', $mesaj);

    $_snc = epostaGonder($db, (string)$ay['bildirim_email'], $konu, $duzMetin, 'bildirim');
    if ($_snc['basarili']) {
        $gonderilen++;
    } else {
        $hatalar[] = 'E-posta gönderilemedi: ' . ($_snc['hata'] ?? 'bilinmeyen hata');
    }
}

if (!empty($ay['bildirim_wp_aktif']) && !empty($ay['bildirim_wp_no']) && !empty($ay['bildirim_wp_apikey'])) {
    $wp = whatsappGonder($mesaj);
    if ($wp['ok']) {
        $gonderilen++;
    } else {
        $hatalar[] = $wp['hata']
            ? "WhatsApp bağlantı hatası: {$wp['hata']}"
            : "WhatsApp gönderilemedi (HTTP {$wp['kod']}).";
    }
}

$mesajSonuc = $gonderilen > 0
    ? "{$gonderilen} kanal üzerinden {$toplamUyari} uyarı gönderildi."
    : 'Etkin bildirim kanalı bulunamadı veya gönderim başarısız oldu.';

jsonOk([
    'gonderilen'   => $gonderilen,
    'uyari_sayisi' => $toplamUyari,
    'hatalar'      => $hatalar,
], $mesajSonuc);
