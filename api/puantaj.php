<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();

$db     = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$_oturumPersonelId = (int)($_SESSION['personel_id'] ?? 0);

function _puantajKendiKiralamasiMi(PDO $db, int $kiralamaId, int $oturumPersonelId): bool {
    if ($oturumPersonelId < 1 || $kiralamaId < 1) return false;
    $st = $db->prepare("SELECT 1 FROM kiralamalar WHERE id=? AND operatorlu=1 AND operator_id=?");
    $st->execute([$kiralamaId, $oturumPersonelId]);
    return (bool)$st->fetchColumn();
}

function _puantajSatiriGetir(PDO $db, int $id, int $oturumPersonelId): array {
    $st = $db->prepare("SELECT * FROM kiralama_puantaj WHERE id=?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) return [false, null];
    return [_puantajKendiKiralamasiMi($db, (int)$row['kiralama_id'], $oturumPersonelId), $row];
}

function _puantajSubeDogrula(PDO $db, int $kiralamaId): void {
    subeKayitDogrula($db, 'kiralamalar', $kiralamaId);
}

function _pBeklemeNedeni($v): ?string {
    return in_array($v, ['musteri_hazirlik', 'saha_erisim', 'hava_kosulu', 'teknik_ariza', 'diger'], true) ? $v : null;
}

function _pFotoKaydet(string $dataUrl, int $puantajId): ?string {
    if (!preg_match('/^data:image\/(jpe?g|png|webp);base64,([a-zA-Z0-9+\/=]+)$/', $dataUrl, $m)) return null;
    $binary = base64_decode($m[2], true);
    if ($binary === false || strlen($binary) < 100 || strlen($binary) > MAX_FILE_SIZE) return null;
    if (!gorselPikselUygun($binary)) return null;
    $ext = $m[1] === 'jpg' ? 'jpeg' : $m[1];
    $dir = UPLOAD_PATH . 'kiralama_puantaj/' . $puantajId . '/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return null;
    $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
    if (file_put_contents($dir . $safeName, $binary) === false) return null;
    @chmod($dir . $safeName, 0644);
    return 'kiralama_puantaj/' . $puantajId . '/' . $safeName;
}

const AKIS_GECIS = [
    'bekliyor' => ['gorundu'],
    'gorundu'  => ['yolda', 'ertelendi', 'saha_girilemedi'],
    'yolda'    => ['sahada', 'ertelendi', 'saha_girilemedi'],
    'sahada'   => ['basladi', 'ertelendi', 'saha_girilemedi'],
    'basladi'  => ['tamamlandi', 'kismen'],
];
const AKIS_TUM_DEGERLER = ['bekliyor', 'gorundu', 'yolda', 'sahada', 'basladi', 'tamamlandi', 'ertelendi', 'kismen', 'saha_girilemedi'];
const AKIS_TERMINAL = ['tamamlandi', 'ertelendi', 'kismen', 'saha_girilemedi'];
const AKIS_ZAMAN_ALANI = [
    'gorundu' => 'gorulme_zamani', 'yolda' => 'yola_cikis_zamani', 'sahada' => 'sahaya_varis_zamani',
];

if ($method === 'GET' && !empty($_GET['bugun'])) {
    if ($_oturumPersonelId < 1) jsonError('Personel kaydınıza bağlı değilsiniz. Yöneticinizden bağlanmanızı isteyin.');
    $bugun = date('Y-m-d');
    $kStmt = $db->prepare("
        SELECT k.id, k.kod, k.ekipman_adi, k.lokasyon, k.il, k.baslangic, k.bitis,
               m.unvan AS musteri_unvan, m.yetkili AS musteri_yetkili, m.telefon AS musteri_telefon
        FROM kiralamalar k JOIN musteriler m ON m.id = k.musteri_id
        WHERE k.operatorlu=1 AND k.operator_id=? AND k.durum='aktif'
          AND k.baslangic<=? AND k.gercek_bitis IS NULL
        ORDER BY k.baslangic
    ");
    $kStmt->execute([$_oturumPersonelId, $bugun]);
    $isler = $kStmt->fetchAll();

    $upsert = $db->prepare("INSERT INTO kiralama_puantaj (kiralama_id, tarih, akis_durumu) VALUES (?,?,'bekliyor') ON CONFLICT(kiralama_id, tarih) DO NOTHING");
    $sonuc = [];
    foreach ($isler as $is) {
        $upsert->execute([$is['id'], $bugun]);
        $pStmt = $db->prepare("SELECT * FROM kiralama_puantaj WHERE kiralama_id=? AND tarih=?");
        $pStmt->execute([$is['id'], $bugun]);
        $puantaj = $pStmt->fetch();

        $foto = [];
        $servisDurum = null;
        if ($puantaj) {
            $fStmt = $db->prepare("SELECT id, etiket, dosya_yolu FROM kiralama_puantaj_foto WHERE puantaj_id=? ORDER BY id");
            $fStmt->execute([(int)$puantaj['id']]);
            $foto = $fStmt->fetchAll();

            if (!empty($puantaj['ariza_servis_id'])) {
                $sStmt = $db->prepare("SELECT durum FROM teknik_servis WHERE id=?");
                $sStmt->execute([(int)$puantaj['ariza_servis_id']]);
                $servisDurum = $sStmt->fetchColumn() ?: null;
            }
        }

        $oncekiStmt = $db->prepare("SELECT devir_notu, tarih FROM kiralama_puantaj
                                    WHERE kiralama_id=? AND tarih<? AND devir_notu IS NOT NULL AND devir_notu != ''
                                    ORDER BY tarih DESC LIMIT 1");
        $oncekiStmt->execute([$is['id'], $bugun]);
        $devir = $oncekiStmt->fetch();

        $sonuc[] = [
            'kiralama'     => $is,
            'puantaj'      => $puantaj,
            'foto'         => $foto,
            'onceki_devir' => $devir ?: null,
            'servis_durum' => $servisDurum,
        ];
    }
    jsonOk($sonuc);
}

if ($method === 'GET' && ($_GET['action'] ?? '') === 'foto') {
    $fid = (int)($_GET['id'] ?? 0);
    if ($fid < 1) jsonError('Geçersiz ID.', 400);
    $st = $db->prepare("SELECT f.dosya_yolu, p.kiralama_id FROM kiralama_puantaj_foto f
                         JOIN kiralama_puantaj p ON p.id = f.puantaj_id WHERE f.id=?");
    $st->execute([$fid]);
    $row = $st->fetch();
    if (!$row) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('Bulunamadı.'); }
    if (!_puantajKendiKiralamasiMi($db, (int)$row['kiralama_id'], $_oturumPersonelId)) {
        if (!hasPermission('kiralama_takip', 'oku')) { http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); exit('Bu belgeyi görüntüleme yetkiniz yok.'); }
        _puantajSubeDogrula($db, (int)$row['kiralama_id']);
    }
    $tam = realpath(UPLOAD_PATH . $row['dosya_yolu']);
    $kok = realpath(rtrim(UPLOAD_PATH, '/\\'));
    if (!$tam || !$kok || !str_starts_with($tam, $kok) || !is_file($tam)) {
        http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('Bulunamadı.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tam) ?: 'application/octet-stream';
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($tam));
    header('Cache-Control: private, max-age=300');
    while (ob_get_level() > 0) ob_end_clean();
    readfile($tam);
    exit;
}

if ($method === 'GET') {
    $kid = (int)($_GET['kiralama_id'] ?? 0);
    if ($kid < 1) jsonError('Kiralama ID gerekli.');
    if (!_puantajKendiKiralamasiMi($db, $kid, $_oturumPersonelId)) { requirePermission('kiralama_takip'); _puantajSubeDogrula($db, $kid); }

    $k = $db->prepare("SELECT k.*, p.ad_soyad AS operator_ad FROM kiralamalar k LEFT JOIN personel p ON p.id=k.operator_id WHERE k.id=?");
    $k->execute([$kid]);
    $kira = $k->fetch();
    if (!$kira) jsonError('Kiralama bulunamadı.', 404);

    $ps = $db->prepare("SELECT tarih, durum, saat, mesai, aciklama FROM kiralama_puantaj WHERE kiralama_id=? ORDER BY tarih");
    $ps->execute([$kid]);
    $kayitlar = [];
    foreach ($ps->fetchAll() as $r) $kayitlar[$r['tarih']] = $r;

    $bas = $kira['baslangic'];
    $bit = $kira['gercek_bitis'] ?: ($kira['bitis'] ?: date('Y-m-d'));
    $gunler = [];
    $tamGun = 0; $yarimGun = 0; $toplamSaat = 0.0; $toplamMesai = 0.0;
    if ($bas) {
        try {
            $d = new DateTime($bas);
            $end = new DateTime($bit);
            if ($end < $d) $end = clone $d;
            $i = 0;
            while ($d <= $end && $i < 400) {
                $ds = $d->format('Y-m-d');
                $rec = $kayitlar[$ds] ?? null;
                $durum = $rec['durum'] ?? '';
                if ($durum === 'tam')   $tamGun++;
                if ($durum === 'yarim') $yarimGun++;
                $toplamSaat  += (float)($rec['saat'] ?? 0);
                $toplamMesai += (float)($rec['mesai'] ?? 0);
                $gunler[] = [
                    'tarih'    => $ds,
                    'durum'    => $durum,
                    'saat'     => $rec['saat'] ?? '',
                    'mesai'    => $rec['mesai'] ?? '',
                    'aciklama' => $rec['aciklama'] ?? '',
                ];
                $d->modify('+1 day'); $i++;
            }
        } catch (\Exception $e) { hataGunlugu('puantaj.takvim', $e, true); }
    }
    $calismaGun = $tamGun + 0.5 * $yarimGun;
    $hesap = kiralamaTutar($kira);

    jsonOk([
        'kiralama' => [
            'id' => (int)$kira['id'], 'kod' => $kira['kod'], 'ekipman_adi' => $kira['ekipman_adi'],
            'operatorlu' => (int)$kira['operatorlu'], 'operator_ad' => $kira['operator_ad'],
            'operator_ucret' => (float)$kira['operator_ucret'], 'operator_ucret_tipi' => $kira['operator_ucret_tipi'],
            'baslangic' => $bas, 'bitis' => $bit,
        ],
        'gunler' => $gunler,
        'ozet'   => [
            'tam' => $tamGun, 'yarim' => $yarimGun, 'calisma_gun' => $calismaGun,
            'toplam_saat' => round($toplamSaat, 1), 'toplam_mesai' => round($toplamMesai, 1),
            'operator_bedeli' => $hesap['operator_bedeli'] ?? 0,
        ],
    ]);
}

elseif ($method === 'POST') {
    verifyCsrf();
    requirePermission('kiralama_takip');
    $body = govdeOku();
    $kid  = (int)($body['kiralama_id'] ?? 0);
    if ($kid < 1) jsonError('Kiralama ID gerekli.');
    _puantajSubeDogrula($db, $kid);
    $satirlar = $body['gunler'] ?? null;
    if (!is_array($satirlar)) jsonError('Puantaj verisi geçersiz.');

    $up = $db->prepare("
        INSERT INTO kiralama_puantaj (kiralama_id, tarih, durum, saat, mesai, aciklama)
        VALUES (?,?,?,?,?,?)
        ON CONFLICT(kiralama_id, tarih) DO UPDATE SET durum=excluded.durum, saat=excluded.saat, mesai=excluded.mesai, aciklama=excluded.aciklama
    ");
    $del = $db->prepare("DELETE FROM kiralama_puantaj WHERE kiralama_id=? AND tarih=?");
    $db->beginTransaction();
    foreach ($satirlar as $s) {
        $tarih = dateStr('tarih', $s);
        if (!$tarih) continue;
        $durum = in_array($s['durum'] ?? '', ['tam','yarim','yok'], true) ? $s['durum'] : '';
        if ($durum === '') { $del->execute([$kid, $tarih]); continue; }
        $up->execute([$kid, $tarih, $durum, max(0,(float)($s['saat'] ?? 0)), max(0,(float)($s['mesai'] ?? 0)), trim($s['aciklama'] ?? '') ?: null]);
    }
    $db->commit();
    logla($db, 'kiralama_puantaj', $kid, 'guncelle', null, null,
          'Puantaj kaydedildi: ' . count($satirlar) . ' gün (kiralama #' . $kid . ')');

    jsonOk(null, 'Puantaj kaydedildi.');
}

elseif ($method === 'PUT') {
    verifyCsrf();
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) jsonError('Geçersiz ID.');
    [$sahibiMi, $row] = _puantajSatiriGetir($db, $id, $_oturumPersonelId);
    if (!$row) jsonError('Kayıt bulunamadı.', 404);
    if (!$sahibiMi) { requirePermission('kiralama_takip'); _puantajSubeDogrula($db, (int)$row['kiralama_id']); }

    $body = govdeOku();
    if ($_gh = gorselListeHatasi($body['fotograflar_yeni'] ?? null)) jsonError($_gh);

    $gelenAnahtar = istemciAnahtari($body);
    if ($gelenAnahtar !== null && (string)($row['istemci_anahtari'] ?? '') === $gelenAnahtar) {
        jsonOk(null, 'Bu işlem zaten kaydedilmişti.');
    }

    $mevcutDurum = $row['akis_durumu'] ?: 'bekliyor';
    $hedefDurum  = null;
    if (isset($body['akis_durumu'])) {
        $hedefDurum = (string)$body['akis_durumu'];
        if (!in_array($hedefDurum, AKIS_TUM_DEGERLER, true)) jsonError('Geçersiz durum.');
        if ($sahibiMi && !in_array($hedefDurum, AKIS_GECIS[$mevcutDurum] ?? [], true)) {
            jsonError('Bu durumdan doğrudan "' . $hedefDurum . '" durumuna geçilemez.');
        }
        if (in_array($hedefDurum, ['ertelendi', 'kismen', 'saha_girilemedi'], true)) {
            $not = trim((string)($body['sonuc_notu'] ?? $row['sonuc_notu'] ?? ''));
            if ($not === '') jsonError('Bu sonuç için bir açıklama girmelisiniz.');
        }
    }

    $db->beginTransaction();
    try {
        $silIds = array_values(array_filter(array_map('intval', is_array($body['fotograf_sil_ids'] ?? null) ? $body['fotograf_sil_ids'] : [])));
        if ($silIds) {
            $ph = implode(',', array_fill(0, count($silIds), '?'));
            $fSil = $db->prepare("SELECT id, dosya_yolu FROM kiralama_puantaj_foto WHERE puantaj_id=? AND id IN ($ph)");
            $fSil->execute(array_merge([$id], $silIds));
            $silinecekler = $fSil->fetchAll();
            $db->prepare("DELETE FROM kiralama_puantaj_foto WHERE puantaj_id=? AND id IN ($ph)")->execute(array_merge([$id], $silIds));
            foreach ($silinecekler as $f) {
                $tam = UPLOAD_PATH . $f['dosya_yolu'];
                if (is_file($tam)) @unlink($tam);
            }
        }

        $fotoKaydedilemeyen = 0;
        $yeniFotolar = is_array($body['fotograflar_yeni'] ?? null) ? $body['fotograflar_yeni'] : [];
        if ($yeniFotolar) {
            $fotoIns = $db->prepare("INSERT INTO kiralama_puantaj_foto (puantaj_id, etiket, dosya_yolu) VALUES (?,?,?)");
            foreach ($yeniFotolar as $f) {
                if (!is_array($f) || empty($f['veri'])) continue;
                $yol = _pFotoKaydet((string)$f['veri'], $id);
                if ($yol === null) { $fotoKaydedilemeyen++; continue; }
                $fotoIns->execute([$id, trim((string)($f['etiket'] ?? '')) ?: null, $yol]);
            }
        }

        if ($hedefDurum !== null && in_array($hedefDurum, ['tamamlandi', 'kismen'], true)) {
            $kalan = $db->prepare("SELECT COUNT(*) FROM kiralama_puantaj_foto WHERE puantaj_id=?");
            $kalan->execute([$id]);
            if ((int)$kalan->fetchColumn() < 1) throw new \Exception('Bu sonuç için en az bir fotoğraf eklemelisiniz.');
        }

        $alanlar = []; $degerler = [];
        if (array_key_exists('bekleme_saat', $body))     { $alanlar[] = 'bekleme_saat=?';     $degerler[] = max(0, (float)$body['bekleme_saat']); }
        if (array_key_exists('bekleme_nedeni', $body))   { $alanlar[] = 'bekleme_nedeni=?';   $degerler[] = _pBeklemeNedeni($body['bekleme_nedeni'] ?? null); }
        if (array_key_exists('bekleme_aciklama', $body)) { $alanlar[] = 'bekleme_aciklama=?'; $degerler[] = trim((string)$body['bekleme_aciklama']) ?: null; }
        if (array_key_exists('is_aciklamasi', $body))    { $alanlar[] = 'is_aciklamasi=?';    $degerler[] = trim((string)$body['is_aciklamasi']) ?: null; }
        if (array_key_exists('devir_notu', $body))       { $alanlar[] = 'devir_notu=?';       $degerler[] = trim((string)$body['devir_notu']) ?: null; }
        if (array_key_exists('sonuc_notu', $body))       { $alanlar[] = 'sonuc_notu=?';       $degerler[] = trim((string)$body['sonuc_notu']) ?: null; }
        if (array_key_exists('ariza_bildirimi', $body))  { $alanlar[] = 'ariza_bildirimi=?';  $degerler[] = !empty($body['ariza_bildirimi']) ? 1 : 0; }
        if (array_key_exists('ariza_aciklama', $body))   { $alanlar[] = 'ariza_aciklama=?';   $degerler[] = trim((string)$body['ariza_aciklama']) ?: null; }

        $arizaSonuc = null;
        if (!empty($body['ariza_bildirimi']) && empty($row['ariza_servis_id'])) {
            $arizaAciklamaMetni = trim((string)($body['ariza_aciklama'] ?? $row['ariza_aciklama'] ?? ''));
            $arizaSonuc = teknikServisOperatorBildirimIsle($db, (int)$row['kiralama_id'], $arizaAciklamaMetni, $_oturumPersonelId > 0 ? $_oturumPersonelId : null);
            if (!empty($arizaSonuc['id'])) {
                $alanlar[] = 'ariza_servis_id=?'; $degerler[] = (int)$arizaSonuc['id'];
            }
        }
        if (array_key_exists('on_kontrol_json', $body)) {
            $onKontrol = $body['on_kontrol_json'];
            $alanlar[] = 'on_kontrol_json=?';
            $degerler[] = is_array($onKontrol) ? json_encode($onKontrol, JSON_UNESCAPED_UNICODE) : null;
            $alanlar[] = 'on_kontrol_tamam=?';
            $degerler[] = !empty($body['on_kontrol_tamam']) ? 1 : 0;
        }

        if ($hedefDurum !== null) {
            $alanlar[] = 'akis_durumu=?'; $degerler[] = $hedefDurum;
            if (isset(AKIS_ZAMAN_ALANI[$hedefDurum]) && empty($row[AKIS_ZAMAN_ALANI[$hedefDurum]])) {
                $alanlar[] = AKIS_ZAMAN_ALANI[$hedefDurum] . '=?';
                $degerler[] = date('Y-m-d H:i:s');
            }
            if ($hedefDurum === 'basladi' && empty($row['calisma_baslangic'])) {
                $alanlar[] = 'calisma_baslangic=?'; $degerler[] = date('Y-m-d H:i:s');
            }
            if (in_array($hedefDurum, AKIS_TERMINAL, true) && empty($row['calisma_bitis']) && $mevcutDurum === 'basladi') {
                $alanlar[] = 'calisma_bitis=?'; $degerler[] = date('Y-m-d H:i:s');
            }
            if (in_array($hedefDurum, AKIS_TERMINAL, true) && $gelenAnahtar !== null) {
                $alanlar[] = 'istemci_anahtari=?'; $degerler[] = $gelenAnahtar;
            }
        }

        if ($alanlar) {
            $degerler[] = $id;
            $db->prepare("UPDATE kiralama_puantaj SET " . implode(', ', $alanlar) . " WHERE id=?")->execute($degerler);
        }

        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        istisnaYanit($e, 'puantaj guncelleme');
    }

    if ($hedefDurum !== null && in_array($hedefDurum, AKIS_TERMINAL, true)) {
        logla($db, 'kiralama_puantaj', $id, 'guncelle', null, null,
              'İş kartı ' . $hedefDurum . ' olarak kapatıldı (kiralama #' . (int)$row['kiralama_id'] . ')');
        if ($hedefDurum !== 'tamamlandi') {
            $kSt = $db->prepare("SELECT kod, ekipman_adi FROM kiralamalar WHERE id=?");
            $kSt->execute([(int)$row['kiralama_id']]);
            $kira = $kSt->fetch();
            $kod = $kira['kod'] ?? ('#' . (int)$row['kiralama_id']);
            gorevOlustur($db, 'Puantaj incelenmeli: ' . $hedefDurum . ' — ' . $kod,
                $body['sonuc_notu'] ?? ($row['sonuc_notu'] ?? null), 'kiralama', (int)$row['kiralama_id'], 'yuksek');
        }
    }

    $mesaj = 'Güncellendi.';
    if ($arizaSonuc !== null) {
        $mesaj = match ($arizaSonuc['durum']) {
            'acildi'   => 'Güncellendi. Bu makine için yeni bir servis kaydı açıldı.',
            'eklendi'  => 'Güncellendi. Bu makine için AÇIK bir servis kaydı vardı, bildiriminiz oraya not olarak eklendi.',
            default    => 'Güncellendi. (Bu kiralamanın bağlı bir makinesi olmadığı için servis kaydı açılamadı.)',
        };
    }
    $fotoKaydedilemeyen = $fotoKaydedilemeyen ?? 0;
    if ($fotoKaydedilemeyen > 0) $mesaj .= ' Ancak ' . $fotoKaydedilemeyen . ' fotoğraf kaydedilemedi (bozuk ya da çok büyük olabilir); lütfen tekrar ekleyin.';
    jsonOk(['ariza_servis' => $arizaSonuc, 'foto_kaydedilemeyen' => $fotoKaydedilemeyen], $mesaj);
}

else {
    jsonError('Method Not Allowed', 405);
}
