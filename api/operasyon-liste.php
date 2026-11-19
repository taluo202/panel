<?php
declare(strict_types=1);

function operasyonListe(PDO $db, string $today, callable $izin, ?string $sube = null): array {
    $son = (new DateTimeImmutable($today))->modify('+7 days')->format('Y-m-d');
    $items = [];
    $ekle = function (string $tip, int $id, string $baslik, string $alt, ?string $tarih, string $url, string $aksiyon, bool $kritik = false) use (&$items, $today) {
        $items[] = compact('tip', 'id', 'baslik', 'alt', 'tarih', 'url', 'aksiyon') + ['oncelik' => $kritik || ($tarih && $tarih < $today) ? 'geciken' : ($tarih === $today ? 'bugun' : 'yaklasan')];
    };
    if ($izin('kiralama_takip')) {
        $st = $db->prepare("SELECT k.*, m.unvan AS musteri FROM kiralamalar k LEFT JOIN musteriler m ON m.id=k.musteri_id WHERE k.durum='aktif' AND k.gercek_bitis IS NULL AND COALESCE(k.onay_durumu,'gerekmiyor') NOT IN ('bekliyor','reddedildi') AND (k.baslangic BETWEEN ? AND ? OR (k.bitis IS NOT NULL AND k.bitis<>'' AND k.bitis<=?))");
        $st->execute([$today, $son, $son]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($sube !== null && ($r['sube'] ?? '') !== $sube) continue;
            $url = 'kiralama-takip.html?detay=' . (int)$r['id'];
            $alt = ($r['musteri'] ?: 'Müşteri belirtilmemiş') . ' · ' . ($r['kod'] ?: '#' . $r['id']);
            if ($r['baslangic'] >= $today && $r['baslangic'] <= $son) {
                $ekle('baslangic', (int)$r['id'], $r['ekipman_adi'], $alt, $r['baslangic'], $url, 'Kiralamayı aç');
            }
            if (!empty($r['bitis']) && $r['bitis'] <= $son) {
                $ekle('iade', (int)$r['id'], $r['ekipman_adi'], $alt, $r['bitis'], $url, 'İadeyi incele');
            }
        }
        $st = $db->prepare("SELECT h.*, m.unvan AS musteri FROM hakedisler h LEFT JOIN musteriler m ON m.id=h.musteri_id WHERE (h.durum IN ('faturalandi','tahsil_edildi') OR TRIM(COALESCE(h.fatura_no,''))<>'') AND h.vade_tarihi IS NOT NULL AND h.vade_tarihi<>'' AND h.vade_tarihi<=?");
        $st->execute([$son]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $fifoKalan = alacakOzeti($db)['hakedis_kalan'];
        foreach ($rows as $r) {
            if ($sube !== null && ($r['sube'] ?? '') !== $sube) continue;
            $kalan = $fifoKalan[(int)$r['id']] ?? 0.0;
            if ($kalan <= 0.01) continue;
            $ekle('tahsilat', (int)$r['id'], $r['musteri'] ?: 'Müşteri', ($r['kod'] ?: 'Hakediş #' . $r['id']) . ' · Kalan: ' . number_format($kalan, 2, ',', '.') . ' ₺', $r['vade_tarihi'], 'musteri-detay.html?id=' . (int)$r['musteri_id'], 'Cari hesabı aç');
        }
    }
    foreach (['arac' => ['araclar', 'plaka', 'periyodik_kontrol_bitis', 'arac-detay.html'], 'manlift' => ['manliftler', 'kod', 'periyodik_bitis', 'manlift-detay.html']] as $modul => [$tablo, $ad, $tarihAlan, $sayfa]) {
        if (!$izin($modul)) continue;
        foreach ($db->query("SELECT * FROM $tablo WHERE aktif IS NULL OR aktif=1")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($sube !== null && ($r['sube'] ?? '') !== $sube) continue;
            $url = $sayfa . '?id=' . (int)$r['id'];
            $tarih = $r[$tarihAlan] ?? null;
            if ($tarih && $tarih <= $son) $ekle('bakim', (int)$r['id'], $r[$ad], 'Periyodik kontrol · ' . ($modul === 'arac' ? 'Araç' : 'Makine'), $tarih, $url, 'Makineyi aç');
            $saat = saatBakimDurumu($r);
            if ($saat && $saat['durum'] !== 'normal') $ekle('bakim', (int)$r['id'], $r[$ad], 'Saat bakımı · Kalan ' . $saat['kalan_saat'] . ' saat', null, $url, 'Makineyi aç', $saat['durum'] === 'gecti');
        }
    }
    $sira = ['geciken' => 0, 'bugun' => 1, 'yaklasan' => 2];
    usort($items, fn($a, $b) => [$sira[$a['oncelik']], $a['tarih'] ?? '9999', $a['tip'], $a['id']] <=> [$sira[$b['oncelik']], $b['tarih'] ?? '9999', $b['tip'], $b['id']]);
    return ['tarih' => $today, 'son_tarih' => $son, 'sube' => $sube, 'items' => $items];
}
