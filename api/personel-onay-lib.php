<?php
declare(strict_types=1);

function poYetki(): array {
    return [
        'izin'   => ['oku' => hasPermission('personel', 'oku'), 'yaz' => hasPermission('personel', 'yaz')],
        'mesai'  => ['oku' => hasPermission('mesai', 'oku'),    'yaz' => hasPermission('mesai', 'yaz')],
        'masraf' => ['oku' => hasPermission('personel', 'oku'), 'yaz' => hasPermission('personel', 'yaz')],
        'belge'  => ['oku' => hasPermission('personel', 'oku'), 'yaz' => hasPermission('personel', 'yaz')],
    ];
}

function poListe(PDO $db, array $yetki, int $pid = 0): array {
    $pKos = $pid > 0 ? ' AND x.personel_id = ?' : '';
    $pPar = $pid > 0 ? [$pid] : [];
    $sorgu = function (string $sql) use ($db, $pPar): array {
        $st = $db->prepare($sql);
        $st->execute($pPar);
        return $st->fetchAll();
    };
    $kendiHesap = "SELECT u.username FROM users u WHERE u.personel_id = x.personel_id";
    $liste = [];

    $ekle = function (array $r, string $tur, array $belgeler) use (&$liste, $yetki): void {
        $liste[] = [
            'tur'         => $tur,
            'id'          => (int)$r['id'],
            'personel_id' => (int)$r['personel_id'],
            'ad_soyad'    => $r['ad_soyad'],
            'pozisyon'    => $r['pozisyon'],
            'alt_tur'     => $r['alt_tur'],
            'tarih'       => $r['tarih'],
            'bitis'       => $r['bitis'] ?? null,
            'gun'         => isset($r['gun']) ? (float)$r['gun'] : null,
            'saat'        => isset($r['saat']) ? (float)$r['saat'] : null,
            'saat_araligi'=> $r['saat_araligi'] ?? null,
            'tutar'       => isset($r['tutar']) ? (float)$r['tutar'] : null,
            'yer'         => $r['yer'] ?? null,
            'not'         => $r['notu'] ?? null,
            'kaynak'      => $r['kaynak'] ?? null,
            'gonderim'    => $r['created_at'] ?? null,
            'belgeler'    => $belgeler,
            'yazabilir'   => $yetki[$tur]['yaz'],
        ];
    };

    if ($yetki['izin']['oku']) {
        $satirlar = $sorgu("SELECT x.id, x.personel_id, p.ad_soyad, p.pozisyon, x.izin_turu AS alt_tur, x.baslangic AS tarih,
                                 x.bitis, x.gun_sayisi AS gun, x.notlar AS notu, x.kaynak, x.belge_adi, x.belge_yolu, x.belge_no, x.created_at
                          FROM izin_talepleri x JOIN personel p ON p.id = x.personel_id
                          WHERE x.durum = 'bekliyor' AND x.izin_turu NOT IN ('ekstra_hak', 'izin_azaltma')
                            AND (COALESCE(x.kaynak, '') = '' OR EXISTS (SELECT 1 FROM mesailer m WHERE m.id = x.kaynak_mesai_id
                                 AND m.olusturan IN (SELECT u.username FROM users u WHERE u.personel_id = x.personel_id)))" . $pKos . "
                          ORDER BY x.baslangic, x.id LIMIT 500");
        foreach ($satirlar as $r) {
            $ekle($r, 'izin', $r['belge_yolu'] ? [['ad' => $r['belge_adi'] ?: 'İzin belgesi', 'yol' => $r['belge_yolu'], 'no' => $r['belge_no']]] : []);
        }
    }

    if ($yetki['mesai']['oku']) {
        $satirlar = $sorgu("SELECT x.id, x.personel_id, p.ad_soyad, p.pozisyon, x.tip AS alt_tur, x.tarih, x.bitis_tarih AS bitis,
                                 CASE WHEN x.tip = 'konaklama' THEN x.saat END AS gun,
                                 CASE WHEN x.tip = 'normal' THEN x.saat END AS saat,
                                 CASE WHEN x.baslangic_saat IS NOT NULL AND x.bitis_saat IS NOT NULL
                                      THEN x.baslangic_saat || ' – ' || x.bitis_saat END AS saat_araligi,
                                 x.calisma_yeri AS yer, x.aciklama AS notu, x.created_at
                          FROM mesailer x JOIN personel p ON p.id = x.personel_id
                          WHERE x.durum = 'taslak' AND x.olusturan IN ($kendiHesap)" . $pKos . "
                          ORDER BY x.tarih, x.id LIMIT 500");
        $mb = [];
        if ($satirlar) {
            $idler = implode(',', array_map(fn($r) => (int)$r['id'], $satirlar));
            foreach ($db->query("SELECT mesai_id, belge_adi, belge_no, dosya_yolu FROM mesai_belgeler WHERE mesai_id IN ($idler) ORDER BY id") as $b) {
                $mb[(int)$b['mesai_id']][] = ['ad' => $b['belge_adi'], 'yol' => $b['dosya_yolu'], 'no' => $b['belge_no']];
            }
        }
        foreach ($satirlar as $r) $ekle($r, 'mesai', $mb[(int)$r['id']] ?? []);
    }

    if ($yetki['masraf']['oku']) {
        $satirlar = $sorgu("SELECT x.id, x.personel_id, p.ad_soyad, p.pozisyon, x.kategori AS alt_tur, x.tarih, x.tutar,
                                 x.aciklama AS notu, x.fis_dosya, x.belge_no, x.created_at
                          FROM personel_masraf x JOIN personel p ON p.id = x.personel_id
                          WHERE x.durum = 'bekliyor'" . $pKos . "
                          ORDER BY x.tarih, x.id LIMIT 500");
        foreach ($satirlar as $r) {
            $ekle($r, 'masraf', $r['fis_dosya'] ? [['ad' => 'Fiş', 'yol' => $r['fis_dosya'], 'no' => $r['belge_no']]] : []);
        }
    }

    if ($yetki['belge']['oku']) {
        $satirlar = $sorgu("SELECT x.id, x.personel_id, p.ad_soyad, p.pozisyon, x.kategori AS alt_tur, substr(x.created_at, 1, 10) AS tarih,
                                 x.bitis_tarihi AS bitis, x.belge_adi AS notu, x.dosya_yolu, x.belge_no, x.created_at
                          FROM personel_belgeler x JOIN personel p ON p.id = x.personel_id
                          WHERE x.onay_durumu = 'bekliyor'" . $pKos . "
                          ORDER BY x.created_at, x.id LIMIT 500");
        foreach ($satirlar as $r) {
            $ekle($r, 'belge', $r['dosya_yolu'] ? [['ad' => $r['notu'] ?: 'Belge', 'yol' => $r['dosya_yolu'], 'no' => $r['belge_no']]] : []);
        }
    }
    return $liste;
}
