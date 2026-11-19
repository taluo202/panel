<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
$_oturumPersonelId = (int)($_SESSION['personel_id'] ?? 0);

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDb();

switch ($method) {
    case 'GET':
        if (($_GET['action'] ?? '') === 'tatiller') {
            $yil = (int)($_GET['yil'] ?? date('Y'));
            if ($yil < 2000 || $yil > 2100) $yil = (int)date('Y');
            jsonOk(resmiTatiller($yil));
        }

        if (isset($_GET['personel_id'])) {
            $pid = (int)$_GET['personel_id'];
            if ($pid < 1) jsonError('Geçersiz personel ID.');
            if ($pid !== $_oturumPersonelId) requirePermission('personel');
            $kStmt = $db->prepare("
                SELECT id, baslangic, bitis, gun_sayisi, izin_turu, durum, notlar
                FROM izin_talepleri
                WHERE personel_id = ? AND izin_turu NOT IN ('ekstra_hak', 'izin_azaltma')
                ORDER BY baslangic DESC
            ");
            $kStmt->execute([$pid]);
            jsonOk($kStmt->fetchAll());
        }

        requirePermission('personel');
        $ay = trim(metin($_GET['ay'] ?? date('Y-m')));
        if (!preg_match('/^\d{4}-\d{2}$/', $ay)) $ay = date('Y-m');
        $ayBas = $ay . '-01';
        $ayBit = date('Y-m-t', strtotime($ayBas));
        $stmt = $db->prepare("
            SELECT it.id, it.baslangic, it.bitis, it.gun_sayisi, it.izin_turu, it.durum, it.notlar,
                   p.id as personel_id, p.ad_soyad, p.pozisyon
            FROM izin_talepleri it
            JOIN personel p ON p.id = it.personel_id
            WHERE it.durum = 'onaylandi'
              AND it.izin_turu NOT IN ('ekstra_hak', 'izin_azaltma')
              AND it.baslangic <= ? AND it.bitis >= ?
            ORDER BY it.baslangic, p.ad_soyad
        ");
        $stmt->execute([$ayBit, $ayBas]);
        jsonOk($stmt->fetchAll());

    case 'POST':
        verifyCsrf();
        $body        = govdeOku();
        $personel_id = (int)($body['personel_id'] ?? 0);
        $baslangic   = dateStr('baslangic', $body);
        $bitis       = dateStr('bitis', $body);

        $izin_turu = in_array($body['izin_turu'] ?? '', ['yillik','ekstra_hak','izin_azaltma','ekstra','mazeretli','ucretsiz','diger']) ? $body['izin_turu'] : 'yillik';

        $_kendiAdina = ($personel_id > 0 && $personel_id === $_oturumPersonelId);
        if (!$_kendiAdina || in_array($izin_turu, ['ekstra_hak', 'izin_azaltma'], true)) {
            requirePermission('personel');
        }

        if ($izin_turu === 'ekstra_hak') {
            if ($personel_id < 1) jsonError('Personel ID zorunludur.');
            $bas = $baslangic ?: date('Y-m-d');
            $bit = $bitis ?: $bas;
            if ($bit < $bas) jsonError('Bitiş tarihi başlangıçtan önce olamaz.');
            $gun = max(1, (int)($body['gun_sayisi'] ?? 0));
            subeKayitDogrula($db, 'personel', $personel_id);
            $yeniId = islemYanit($db, function (PDO $db) use ($personel_id, $bas, $bit, $gun, $body): int {
                $db->prepare("
                    INSERT INTO izin_talepleri (personel_id, baslangic, bitis, gun_sayisi, izin_turu, notlar, durum)
                    VALUES (?, ?, ?, ?, 'ekstra_hak', ?, 'onaylandi')
                ")->execute([$personel_id, $bas, $bit, $gun, trim(metin($body['notlar'] ?? '')) ?: null]);
                $yid = (int)$db->lastInsertId();
                izinHakSayaci($db, $personel_id, $gun);
                logla($db, 'izin_talepleri', $yid, 'ekle', null, null, 'Ekstra izin hakkı: +' . $gun . ' gün · personel #' . $personel_id);
                return $yid;
            });
            jsonOk(['id' => $yeniId, 'gun_sayisi' => $gun], 'Ekstra izin hakkı kaydedildi.');
        }

        if ($izin_turu === 'izin_azaltma') {
            if ($personel_id < 1) jsonError('Personel ID zorunludur.');
            $gun   = max(1, (int)($body['gun_sayisi'] ?? 0));
            $today = date('Y-m-d');
            subeKayitDogrula($db, 'personel', $personel_id);
            $yeniId = islemYanit($db, function (PDO $db) use ($personel_id, $today, $gun, $body): int {
                $db->prepare("
                    INSERT INTO izin_talepleri (personel_id, baslangic, bitis, gun_sayisi, izin_turu, notlar, durum)
                    VALUES (?, ?, ?, ?, 'izin_azaltma', ?, 'onaylandi')
                ")->execute([$personel_id, $today, $today, $gun, trim(metin($body['notlar'] ?? '')) ?: null]);
                $yid = (int)$db->lastInsertId();
                izinHakSayaci($db, $personel_id, -$gun);
                logla($db, 'izin_talepleri', $yid, 'ekle', null, null, 'İzin azaltma: -' . $gun . ' gün · personel #' . $personel_id);
                return $yid;
            });
            jsonOk(['id' => $yeniId, 'gun_sayisi' => $gun], 'İzin azaltma kaydedildi.');
        }

        if ($personel_id < 1 || !$baslangic || !$bitis) {
            jsonError('Personel ID, başlangıç ve bitiş tarihi zorunludur.');
        }
        if ($bitis < $baslangic) jsonError('Bitiş tarihi başlangıçtan önce olamaz.');

        $gun = isGunSayisi($baslangic, $bitis);

        $durumGelen = trim(metin($body['durum'] ?? ''));
        $durum = in_array($durumGelen, ['onaylandi','bekliyor']) ? $durumGelen : 'bekliyor';
        if (!hasPermission('personel')) $durum = 'bekliyor';

        $dupChk = $db->prepare("SELECT id FROM izin_talepleri WHERE personel_id=? AND baslangic=? AND bitis=? AND izin_turu=?");
        $dupChk->execute([$personel_id, $baslangic, $bitis, $izin_turu]);
        $dupId = $dupChk->fetchColumn();
        if ($dupId) jsonOk(['id' => (int)$dupId], 'Bu izin talebi zaten kaydedilmiş.');

        [$izin_id, $kesinti_tutar, $finans_uyari] = islemIcinde($db, function (PDO $db) use ($personel_id, $baslangic, $bitis, $gun, $izin_turu, $body, $durum): array {
            $stmt = $db->prepare("
                INSERT INTO izin_talepleri (personel_id, baslangic, bitis, gun_sayisi, izin_turu, notlar, durum)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $personel_id, $baslangic, $bitis, $gun,
                $izin_turu,
                trim(metin($body['notlar'] ?? '')) ?: null,
                $durum,
            ]);
            $izin_id = (int)$db->lastInsertId();

            $kesinti_tutar = 0.0;
            $finans_uyari  = null;
            if ($izin_turu === 'ucretsiz') {
                $ay = substr($baslangic, 0, 7);

                $fStmt = $db->prepare("SELECT id, toplam_maas FROM finans WHERE personel_id=? AND ay=?");
                $fStmt->execute([$personel_id, $ay]);
                $fRow = $fStmt->fetch();

                if ($fRow) {
                    $kesinti_tutar = round($gun / 30 * (float)$fRow['toplam_maas'], 2);
                    $db->prepare("UPDATE finans SET haciz_kesinti = haciz_kesinti + ? WHERE id=?")
                       ->execute([$kesinti_tutar, $fRow['id']]);
                } else {
                    $lStmt = $db->prepare("SELECT toplam_maas FROM finans WHERE personel_id=? ORDER BY ay DESC LIMIT 1");
                    $lStmt->execute([$personel_id]);
                    $lRow = $lStmt->fetch();

                    if ($lRow && (float)$lRow['toplam_maas'] > 0) {
                        $kesinti_tutar = round($gun / 30 * (float)$lRow['toplam_maas'], 2);
                        $db->prepare("INSERT INTO finans (personel_id, ay, toplam_maas, mesai, prim, haciz_kesinti, bankaya_yatan, elden_kalan, bes)
                                      VALUES (?, ?, ?, 0, 0, ?, 0, 0, 0)")
                           ->execute([$personel_id, $ay, (float)$lRow['toplam_maas'], $kesinti_tutar]);
                    } else {
                        $finans_uyari = 'Bu personel için finans kaydı bulunamadı. Lütfen finans sayfasından maaş bilgisini girin.';
                    }
                }
            }
            return [$izin_id, $kesinti_tutar, $finans_uyari];
        });

        jsonOk(['id' => $izin_id, 'gun_sayisi' => $gun, 'kesinti_tutar' => $kesinti_tutar, 'finans_uyari' => $finans_uyari], 'İzin kaydedildi.');

    case 'PUT':
        verifyCsrf();
        requirePermission('personel');
        $id   = (int)($_GET['id'] ?? 0);
        $body = govdeOku();
        if ($id < 1) jsonError('Geçersiz ID.');

        if (($_GET['action'] ?? '') === 'evrak') {
            if (!is_array($body)) jsonError('Geçersiz istek gövdesi.');
            alanlarZorunlu($body, ['belge_adi', 'belge_no', 'belge_tarihi']);
            $_izEski = logKayitAl($db, 'izin_talepleri', $id);
            if (!$_izEski) jsonError('Kayıt bulunamadı.', 404);
            if (empty($_izEski['belge_yolu'])) jsonError('Bu izin kaydında evrak yok; önce evrak yükleyin.');
            $evrakAdi = belgeAdiAl($body);
            if ($evrakAdi === '') jsonError('Belge adı boş bırakılamaz.');
            $db->prepare("UPDATE izin_talepleri SET belge_adi=?, belge_no=?, belge_tarihi=? WHERE id=?")
               ->execute([$evrakAdi, belgeNoAl($body), dateStr('belge_tarihi', $body), $id]);
            logla($db, 'izin_talepleri', $id, 'guncelle', $_izEski, logKayitAl($db, 'izin_talepleri', $id),
                'İzin evrakı bilgileri düzenlendi: ' . $evrakAdi . ' · personel #' . (int)$_izEski['personel_id']);
            jsonOk(['id' => $id], 'İzin evrakı güncellendi.');
        }

        $curStmt = $db->prepare("SELECT personel_id, izin_turu, gun_sayisi, baslangic, bitis, notlar FROM izin_talepleri WHERE id=?");
        $curStmt->execute([$id]);
        $mevcut = $curStmt->fetch();
        if (!$mevcut) jsonError('Kayıt bulunamadı.', 404);

        if (in_array($mevcut['izin_turu'], ['ekstra_hak', 'izin_azaltma'], true)) {
            $yeniGun = max(1, (int)($body['gun_sayisi'] ?? $mevcut['gun_sayisi']));

            $bas = dateStr('baslangic', $body) ?: $mevcut['baslangic'];
            $bit = dateStr('bitis', $body) ?: $mevcut['bitis'];
            if ($bas && $bit && $bit < $bas) jsonError('Bitiş tarihi başlangıçtan önce olamaz.');

            $notlar = array_key_exists('notlar', $body)
                ? (trim((string)$body['notlar']) ?: null)
                : $mevcut['notlar'];

            islemYanit($db, function (PDO $db) use ($id, $mevcut, $yeniGun, $bas, $bit, $notlar): void {
                $u = $db->prepare("UPDATE izin_talepleri SET gun_sayisi=?, baslangic=?, bitis=?, notlar=? WHERE id=? AND gun_sayisi=?");
                $u->execute([$yeniGun, $bas, $bit, $notlar, $id, (int)$mevcut['gun_sayisi']]);
                if ($u->rowCount() !== 1) throw new DomainException('Kayıt bu sırada değişti; sayfayı yenileyip tekrar deneyin.', 409);
                $sign = $mevcut['izin_turu'] === 'ekstra_hak' ? 1 : -1;
                izinHakSayaci($db, (int)$mevcut['personel_id'], $sign * ($yeniGun - (int)$mevcut['gun_sayisi']));
            });
            jsonOk(['gun_sayisi' => $yeniGun], 'Kayıt güncellendi.');
        }

        if (isset($body['baslangic'])) {
            $baslangic = dateStr('baslangic', $body);
            $bitis     = dateStr('bitis', $body);
            if (!$baslangic || !$bitis) jsonError('Tarihler zorunludur.');
            if ($bitis < $baslangic) jsonError('Bitiş tarihi başlangıçtan önce olamaz.');

            $gun = isGunSayisi($baslangic, $bitis);

            $izin_turu = in_array($body['izin_turu'] ?? '', ['yillik','ekstra','mazeretli','ucretsiz','diger']) ? $body['izin_turu'] : 'yillik';
            $notlar    = trim(metin($body['notlar'] ?? '')) ?: null;

            [$kesinti_tutar, $finans_uyari] = islemIcinde($db, function (PDO $db) use ($id, $mevcut, $baslangic, $bitis, $gun, $izin_turu, $notlar): array {
                if ($mevcut['izin_turu'] === 'ucretsiz' && $mevcut['baslangic']) {
                    $eskiAy = substr($mevcut['baslangic'], 0, 7);
                    $ef = $db->prepare("SELECT id, toplam_maas FROM finans WHERE personel_id=? AND ay=?");
                    $ef->execute([$mevcut['personel_id'], $eskiAy]);
                    $er = $ef->fetch();
                    if ($er) {
                        $iade = round((int)$mevcut['gun_sayisi'] / 30 * (float)$er['toplam_maas'], 2);
                        $db->prepare("UPDATE finans SET haciz_kesinti = MAX(0, haciz_kesinti - ?) WHERE id=?")
                           ->execute([$iade, $er['id']]);
                    }
                }

                $db->prepare("UPDATE izin_talepleri SET baslangic=?, bitis=?, gun_sayisi=?, izin_turu=?, notlar=? WHERE id=?")
                   ->execute([$baslangic, $bitis, $gun, $izin_turu, $notlar, $id]);

                $kesinti_tutar = 0.0; $finans_uyari = null;
                if ($izin_turu === 'ucretsiz') {
                    $ay = substr($baslangic, 0, 7);
                    $fStmt = $db->prepare("SELECT id, toplam_maas FROM finans WHERE personel_id=? AND ay=?");
                    $fStmt->execute([$mevcut['personel_id'], $ay]);
                    $fRow = $fStmt->fetch();
                    if ($fRow) {
                        $kesinti_tutar = round($gun / 30 * (float)$fRow['toplam_maas'], 2);
                        $db->prepare("UPDATE finans SET haciz_kesinti = haciz_kesinti + ? WHERE id=?")
                           ->execute([$kesinti_tutar, $fRow['id']]);
                    } else {
                        $lStmt = $db->prepare("SELECT toplam_maas FROM finans WHERE personel_id=? ORDER BY ay DESC LIMIT 1");
                        $lStmt->execute([$mevcut['personel_id']]);
                        $lRow = $lStmt->fetch();
                        if ($lRow && (float)$lRow['toplam_maas'] > 0) {
                            $kesinti_tutar = round($gun / 30 * (float)$lRow['toplam_maas'], 2);
                            $db->prepare("INSERT INTO finans (personel_id, ay, toplam_maas, mesai, prim, haciz_kesinti, bankaya_yatan, elden_kalan, bes)
                                          VALUES (?, ?, ?, 0, 0, ?, 0, 0, 0)")
                               ->execute([$mevcut['personel_id'], $ay, (float)$lRow['toplam_maas'], $kesinti_tutar]);
                        } else {
                            $finans_uyari = 'Bu personel için finans kaydı bulunamadı. Lütfen finans sayfasından maaş bilgisini girin.';
                        }
                    }
                }
                return [$kesinti_tutar, $finans_uyari];
            });

            jsonOk(['gun_sayisi' => $gun, 'kesinti_tutar' => $kesinti_tutar, 'finans_uyari' => $finans_uyari], 'İzin güncellendi.');
        }

        $durum = $body['durum'] ?? '';
        if (!in_array($durum, ['onaylandi','reddedildi','bekliyor'])) jsonError('Geçersiz istek.');
        $_izEski = logKayitAl($db, 'izin_talepleri', $id);
        $gerekce = retGerekcesi($body, $durum);
        $db->prepare("UPDATE izin_talepleri SET durum=?, ret_gerekcesi=? WHERE id=?")->execute([$durum, $gerekce, $id]);
        logla($db, 'izin_talepleri', $id, 'guncelle', $_izEski, logKayitAl($db, 'izin_talepleri', $id),
            'İzin durumu: ' . $durum . ($gerekce !== null ? ' · gerekçe: ' . $gerekce : ''));
        jsonOk(null, 'İzin durumu güncellendi.');

    case 'DELETE':
        verifyCsrf();
        requirePermission('personel');
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        try { izinKaydiSil($db, $id); }
        catch (DomainException $e) { jsonError($e->getMessage(), (int)$e->getCode() === 404 ? 404 : 409); }
        jsonOk(null, 'İzin silindi.');

    default:
        jsonError('Method Not Allowed', 405);
}
