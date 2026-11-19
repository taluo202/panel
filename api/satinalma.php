<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('satinalma');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$db     = getDb();


const SA_EPS = 0.0001;

function _saDurumManuel($v): string { return in_array($v, ['taslak','gonderildi','iptal'], true) ? $v : 'taslak'; }

function saSayiYerel(mixed $n): string { return rtrim(rtrim(number_format((float)$n, 2, ',', '.'), '0'), ','); }

function _saYapan(): ?string { return $_SESSION['ad_soyad'] ?? ($_SESSION['username'] ?? null); }

function _saKalemler(PDO $db, int $siparisId): array {
    $st = $db->prepare("SELECT k.*, p.ad AS parca_ad FROM satinalma_kalemleri k LEFT JOIN yedek_parca p ON p.id=k.parca_id
                        WHERE k.siparis_id=? ORDER BY COALESCE(k.sira, 999999), k.id");
    $st->execute([$siparisId]);
    $out = [];
    foreach ($st->fetchAll() as $k) {
        $out[] = [
            'id' => (int)$k['id'], 'sira' => $k['sira'] !== null ? (int)$k['sira'] : null, 'stok_kodu' => $k['stok_kodu'],
            'aciklama' => $k['aciklama'], 'miktar' => (float)$k['miktar'], 'birim' => $k['birim'] ?: 'adet',
            'parca_id' => $k['parca_id'] !== null ? (int)$k['parca_id'] : null, 'parca_ad' => $k['parca_ad'],
            'notlar' => $k['notlar'], 'stok_disi' => (int)($k['stok_disi'] ?? 0),
            'teslim_alinan' => (float)$k['teslim_alinan'], 'faturalanan' => (float)($k['faturalanan'] ?? 0),
            'son_birim_fiyat' => $k['son_birim_fiyat'] !== null ? (float)$k['son_birim_fiyat'] : null,
            'son_kdv_oran' => $k['son_kdv_oran'] !== null ? (float)$k['son_kdv_oran'] : null,
            'aktarildi' => (int)($k['aktarildi'] ?? 0),
            'teknik_servis_id' => $k['teknik_servis_id'] !== null ? (int)$k['teknik_servis_id'] : null,
        ];
    }
    return $out;
}

function _saSiparis(PDO $db, int $id): ?array {
    $st = $db->prepare("SELECT s.*, t.unvan AS tedarikci_unvan, t.telefon AS tedarikci_telefon, t.email AS tedarikci_email,
                               t.yetkili AS tedarikci_yetkili, t.adres AS tedarikci_adres, t.vergi_dairesi AS tedarikci_vergi_dairesi,
                               t.vergi_no AS tedarikci_vergi_no
                        FROM satinalma_siparisleri s JOIN tedarikciler t ON t.id = s.tedarikci_id WHERE s.id=?");
    $st->execute([$id]);
    $s = $st->fetch();
    if (!$s) return null;
    $s['id'] = (int)$s['id'];
    $s['tedarikci_id'] = (int)$s['tedarikci_id'];
    $s['kalemler'] = _saKalemler($db, $id);
    return $s;
}

function _saDetay(PDO $db, int $id): ?array {
    $s = _saSiparis($db, $id);
    if (!$s) return null;
    require_once __DIR__ . '/satinalma-lib.php';
    $s['durum_etiket'] = SA_DURUM_ETIKET[$s['durum']] ?? $s['durum'];
    $f = $db->prepare("SELECT id, fatura_no, fatura_tarihi, ara_toplam, kdv_toplam, genel_toplam, notlar, created_at, yapan
                       FROM satinalma_faturalari WHERE siparis_id=? ORDER BY fatura_tarihi DESC, id DESC");
    $f->execute([$id]);
    $faturalar = $f->fetchAll();
    $fk = $db->prepare("SELECT fk.kalem_id, k.aciklama, fk.miktar, fk.birim_fiyat, fk.kdv_oran, fk.kdv_tutar, fk.mal_hizmet_tutar, fk.toplam
                        FROM satinalma_fatura_kalemleri fk LEFT JOIN satinalma_kalemleri k ON k.id = fk.kalem_id
                        WHERE fk.fatura_id=? ORDER BY COALESCE(k.sira, 999999), fk.id");
    foreach ($faturalar as &$fa) {
        $fk->execute([(int)$fa['id']]);
        $fa['id'] = (int)$fa['id'];
        foreach (['ara_toplam', 'kdv_toplam', 'genel_toplam'] as $a) $fa[$a] = (float)$fa[$a];
        $fa['kalemler'] = array_map(fn($r) => ['kalem_id' => (int)$r['kalem_id'], 'aciklama' => $r['aciklama'], 'miktar' => (float)$r['miktar'],
            'birim_fiyat' => (float)$r['birim_fiyat'], 'kdv_oran' => (float)$r['kdv_oran'], 'kdv_tutar' => (float)$r['kdv_tutar'],
            'mal_hizmet_tutar' => (float)$r['mal_hizmet_tutar'], 'toplam' => (float)$r['toplam']], $fk->fetchAll());
    }
    unset($fa);
    $s['faturalar'] = $faturalar;
    $g = $db->prepare("SELECT kanal, alici, zaman, yapan FROM satinalma_gonderimleri WHERE siparis_id=? ORDER BY id DESC");
    $g->execute([$id]);
    $s['gonderimler'] = $g->fetchAll();
    return $s;
}

function _saDurumHesapla(PDO $db, int $siparisId): string {
    $st = $db->prepare("SELECT durum FROM satinalma_siparisleri WHERE id=?");
    $st->execute([$siparisId]);
    $mevcut = (string)$st->fetchColumn();
    if ($mevcut === 'iptal' || $mevcut === '') return $mevcut;
    $k = $db->prepare("SELECT miktar, teslim_alinan, COALESCE(faturalanan,0) faturalanan FROM satinalma_kalemleri WHERE siparis_id=?");
    $k->execute([$siparisId]);
    $kalemler = $k->fetchAll();
    if (!$kalemler) return $mevcut;
    $hicTeslim = true; $hepsiTeslim = true; $hepsiFatura = true;
    foreach ($kalemler as $r) {
        if ((float)$r['teslim_alinan'] > SA_EPS) $hicTeslim = false;
        if ((float)$r['teslim_alinan'] < (float)$r['miktar'] - SA_EPS) $hepsiTeslim = false;
        if ((float)$r['faturalanan'] < (float)$r['miktar'] - SA_EPS) $hepsiFatura = false;
    }
    if ($hicTeslim) $yeni = in_array($mevcut, ['taslak', 'gonderildi'], true) ? $mevcut : 'gonderildi';
    elseif (!$hepsiTeslim) $yeni = 'kismi_teslim';
    else $yeni = $hepsiFatura ? 'tamamlandi' : 'teslim_alindi';
    if ($yeni !== $mevcut) {
        $db->prepare("UPDATE satinalma_siparisleri SET durum=?, updated_at=datetime('now','localtime') WHERE id=?")->execute([$yeni, $siparisId]);
    }
    return $yeni;
}

function _saKalemFaturaTazele(PDO $db, int $kalemId): void {
    $t = $db->prepare("SELECT COALESCE(SUM(miktar),0) FROM satinalma_fatura_kalemleri WHERE kalem_id=?");
    $t->execute([$kalemId]);
    $son = $db->prepare("SELECT fk.birim_fiyat, fk.kdv_oran FROM satinalma_fatura_kalemleri fk JOIN satinalma_faturalari f ON f.id=fk.fatura_id
                         WHERE fk.kalem_id=? ORDER BY f.fatura_tarihi DESC, f.id DESC LIMIT 1");
    $son->execute([$kalemId]);
    $r = $son->fetch() ?: ['birim_fiyat' => null, 'kdv_oran' => null];
    $db->prepare("UPDATE satinalma_kalemleri SET faturalanan=?, son_birim_fiyat=?, son_kdv_oran=? WHERE id=?")
       ->execute([round((float)$t->fetchColumn(), 4), $r['birim_fiyat'], $r['kdv_oran'], $kalemId]);
}

function _saKartFiyatTazele(PDO $db, int $parcaId): void {
    $st = $db->prepare("SELECT fk.birim_fiyat, f.tedarikci_id, f.fatura_tarihi FROM satinalma_fatura_kalemleri fk
                        JOIN satinalma_faturalari f ON f.id = fk.fatura_id JOIN satinalma_kalemleri k ON k.id = fk.kalem_id
                        WHERE k.parca_id=? ORDER BY f.fatura_tarihi DESC, f.id DESC LIMIT 1");
    $st->execute([$parcaId]);
    $r = $st->fetch();
    if (!$r) {
        $db->prepare("UPDATE yedek_parca SET alis_fiyati=NULL, son_tedarikci_id=NULL, son_alis_tarihi=NULL, updated_at=datetime('now','localtime') WHERE id=?")
           ->execute([$parcaId]);
        return;
    }
    $db->prepare("UPDATE yedek_parca SET alis_fiyati=?, son_tedarikci_id=?, son_alis_tarihi=?,
                         fiyat = CASE WHEN COALESCE(fiyat,0)=0 THEN ? ELSE fiyat END, updated_at=datetime('now','localtime') WHERE id=?")
       ->execute([(float)$r['birim_fiyat'], $r['tedarikci_id'] !== null ? (int)$r['tedarikci_id'] : null, $r['fatura_tarihi'], (float)$r['birim_fiyat'], $parcaId]);
}

function _saKalemGirdi(PDO $db, array $kalemler): array {
    $kart = $db->prepare("SELECT ad, parca_no, birim FROM yedek_parca WHERE id=?");
    $out = []; $n = 0;
    foreach ($kalemler as $k) {
        if (!is_array($k)) continue;
        $parcaId = ((int)($k['parca_id'] ?? 0)) ?: null;
        $ad = trim(metin($k['aciklama'] ?? ''));
        $stokKodu = trim(metin($k['stok_kodu'] ?? ''));
        $birim = trim(metin($k['birim'] ?? ''));
        if ($parcaId) {
            $kart->execute([$parcaId]);
            $p = $kart->fetch();
            if (!$p) $parcaId = null;
            else {
                if ($ad === '') $ad = (string)$p['ad'];
                if ($stokKodu === '') $stokKodu = (string)($p['parca_no'] ?? '');
                if ($birim === '') $birim = (string)($p['birim'] ?? '');
            }
        }
        if ($ad === '') continue;
        $n++;
        $out[] = [
            'id' => ((int)($k['id'] ?? 0)) ?: null,
            'sira' => ((int)($k['sira'] ?? 0)) ?: $n,
            'stok_kodu' => $stokKodu !== '' ? mb_substr($stokKodu, 0, 100) : null,
            'aciklama' => mb_substr($ad, 0, 500),
            'miktar' => max(0.01, round((float)($k['miktar'] ?? 1), 4)),
            'birim' => $birim !== '' ? mb_substr($birim, 0, 30) : 'adet',
            'parca_id' => $parcaId,
            'notlar' => trim(metin($k['notlar'] ?? '')) ?: null,
            'stok_disi' => !empty($k['stok_disi']) ? 1 : 0,
            'teknik_servis_id' => ((int)($k['teknik_servis_id'] ?? 0)) ?: null,
        ];
    }
    return $out;
}

function _saKalemEkle(PDO $db, int $siparisId, array $k): void {
    $db->prepare("INSERT INTO satinalma_kalemleri (siparis_id, sira, stok_kodu, aciklama, miktar, birim, parca_id, notlar, stok_disi, birim_fiyat, teknik_servis_id)
                  VALUES (?,?,?,?,?,?,?,?,?,0,?)")
       ->execute([$siparisId, $k['sira'], $k['stok_kodu'], $k['aciklama'], $k['miktar'], $k['birim'], $k['parca_id'], $k['notlar'], $k['stok_disi'], $k['teknik_servis_id']]);
}

function _saGovde(): array { return govdeOku(); }

function _saLogKalemler(PDO $db, int $siparisId): array {
    return array_map(fn($k) => ['id' => $k['id'], 'sira' => $k['sira'], 'stok_kodu' => $k['stok_kodu'], 'aciklama' => $k['aciklama'], 'miktar' => $k['miktar'],
        'birim' => $k['birim'], 'parca_id' => $k['parca_id'], 'notlar' => $k['notlar'], 'stok_disi' => $k['stok_disi'], 'teknik_servis_id' => $k['teknik_servis_id']],
        _saKalemler($db, $siparisId));
}

if ($method === 'GET' && $action === 'pdf') {
    $s = _saSiparis($db, (int)($_GET['id'] ?? 0));
    if (!$s) jsonError('Sipariş bulunamadı.', 404);
    require_once __DIR__ . '/satinalma-lib.php';
    bpIndir(saPdf($db, $s));
}

if ($method === 'GET' && $action === 'fiyat_takibi') {
    $where = []; $params = [];
    if (($tid = (int)($_GET['tedarikci_id'] ?? 0)) > 0) { $where[] = "f.tedarikci_id=?"; $params[] = $tid; }
    if (($q = trim(metin($_GET['q'] ?? ''))) !== '') {
        $where[] = "(" . trAramaSql('k.aciklama') . " LIKE ? OR " . trAramaSql('k.stok_kodu') . " LIKE ? OR " . trAramaSql('p.ad') . " LIKE ? OR " . trAramaSql('p.parca_no') . " LIKE ?)";
        $like = '%' . trArama($q) . '%'; array_push($params, $like, $like, $like, $like);
    }
    $sql = "SELECT fk.birim_fiyat, fk.kdv_oran, fk.miktar, f.fatura_tarihi, f.fatura_no, f.id fatura_id, f.siparis_id, s.kod siparis_kod,
                   t.unvan tedarikci_unvan, k.parca_id, k.stok_kodu, k.aciklama, k.birim, p.ad parca_ad, p.parca_no
            FROM satinalma_fatura_kalemleri fk
            JOIN satinalma_faturalari f ON f.id = fk.fatura_id
            JOIN satinalma_kalemleri k ON k.id = fk.kalem_id
            LEFT JOIN satinalma_siparisleri s ON s.id = f.siparis_id
            LEFT JOIN yedek_parca p ON p.id = k.parca_id
            LEFT JOIN tedarikciler t ON t.id = f.tedarikci_id";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY f.fatura_tarihi DESC, f.id DESC, fk.id DESC";
    $st = $db->prepare($sql); $st->execute($params);
    $gruplar = [];
    foreach ($st->fetchAll() as $r) {
        $stokKodu = trim((string)($r['stok_kodu'] ?: $r['parca_no']));
        $anahtar = $r['parca_id'] ? 'p:' . (int)$r['parca_id'] : ($stokKodu !== '' ? 'k:' . mb_strtolower($stokKodu) : 'a:' . mb_strtolower(trim((string)$r['aciklama'])));
        $fiyat = (float)$r['birim_fiyat'];
        if (!isset($gruplar[$anahtar])) {
            $gruplar[$anahtar] = ['anahtar' => $anahtar, 'stok_kodu' => $stokKodu !== '' ? $stokKodu : null,
                'malzeme' => (string)($r['parca_ad'] ?: $r['aciklama']), 'parca_id' => $r['parca_id'] !== null ? (int)$r['parca_id'] : null,
                'son_fiyat' => $fiyat, 'son_kdv_oran' => (float)$r['kdv_oran'], 'son_tarih' => $r['fatura_tarihi'],
                'son_tedarikci' => $r['tedarikci_unvan'], 'onceki_fiyat' => null, 'degisim_yuzde' => null,
                'en_dusuk' => $fiyat, 'en_yuksek' => $fiyat, 'adet' => 0, 'gecmis' => []];
        }
        $g = &$gruplar[$anahtar];
        $g['gecmis'][] = ['fatura_id' => (int)$r['fatura_id'], 'fatura_no' => $r['fatura_no'], 'fatura_tarihi' => $r['fatura_tarihi'],
            'siparis_id' => $r['siparis_id'] !== null ? (int)$r['siparis_id'] : null, 'siparis_kod' => $r['siparis_kod'],
            'tedarikci' => $r['tedarikci_unvan'], 'miktar' => (float)$r['miktar'], 'birim' => $r['birim'],
            'birim_fiyat' => $fiyat, 'kdv_oran' => (float)$r['kdv_oran']];
        if ($g['adet'] > 0 && $g['onceki_fiyat'] === null) $g['onceki_fiyat'] = $fiyat;
        $g['en_dusuk'] = min($g['en_dusuk'], $fiyat);
        $g['en_yuksek'] = max($g['en_yuksek'], $fiyat);
        $g['adet']++;
        unset($g);
    }
    foreach ($gruplar as &$g) {
        if ($g['onceki_fiyat'] !== null && $g['onceki_fiyat'] > 0) $g['degisim_yuzde'] = round(($g['son_fiyat'] - $g['onceki_fiyat']) / $g['onceki_fiyat'] * 100, 1);
    }
    unset($g);
    $rows = array_values($gruplar);
    usort($rows, fn($a, $b) => strcmp(mb_strtolower($a['malzeme']), mb_strtolower($b['malzeme'])));
    jsonOk($rows);
}

if ($method === 'POST' && $action === 'gonder') {
    verifyCsrf();
    $id = (int)($_GET['id'] ?? 0);
    $body = _saGovde();
    $s = _saSiparis($db, $id);
    if (!$s) jsonError('Sipariş bulunamadı.', 404);
    if ($s['durum'] === 'iptal') jsonError('İptal edilmiş sipariş gönderilemez.');
    if (!$s['kalemler']) jsonError('Siparişte kalem yok; önce malzeme ekleyin.');
    $kanal = (string)($body['kanal'] ?? '');
    if (!in_array($kanal, ['eposta', 'whatsapp'], true)) jsonError('Gönderim kanalı seçin (e-posta ya da WhatsApp).');
    require_once __DIR__ . '/satinalma-lib.php';
    $out = [];
    if ($kanal === 'eposta') {
        $alici = mb_substr(trim(metin($body['alici'] ?? '')) ?: trim((string)$s['tedarikci_email']), 0, 200);
        if ($alici === '') jsonError('Tedarikçinin e-posta adresi kayıtlı değil. Adresi yazın ya da tedarikçi kartına ekleyin.');
        if (!filter_var($alici, FILTER_VALIDATE_EMAIL)) jsonError('Geçerli bir e-posta adresi girin.');
        require_once __DIR__ . '/eposta.php';
        $e = epostaGonder($db, $alici, 'Satınalma siparişi ' . $s['kod'], saEpostaMetin($db, $s), 'satinalma', false, [saPdf($db, $s)]);
        if (empty($e['basarili'])) jsonError('E-posta gönderilemedi: ' . ($e['hata'] ?? 'bilinmeyen hata') . ' (Ayarlar > E-posta bölümünü kontrol edin.)');
        $out['mesaj'] = 'Sipariş PDF olarak ' . $alici . ' adresine gönderildi.';
    } else {
        require_once __DIR__ . '/whatsapp.php';
        $ham = trim(metin($body['alici'] ?? '')) ?: trim((string)$s['tedarikci_telefon']);
        if ($ham === '') jsonError('Tedarikçinin telefon numarası kayıtlı değil. Numarayı yazın ya da tedarikçi kartına ekleyin.');
        $tel = waTelefon($ham);
        if (!$tel) jsonError('Geçerli bir telefon numarası girin (ör. 0532 123 45 67).');
        $alici = $tel;
        $metin = saWaMetin($db, $s);
        $out += ['wa_url' => 'https://wa.me/' . $tel . '?text=' . rawurlencode($metin), 'metin' => $metin,
                 'pdf_url' => 'api/satinalma.php?action=pdf&id=' . $id, 'dosya_adi' => bpDosyaAdi((string)$s['kod']),
                 'mesaj' => "WhatsApp mesajı hazır — açılan pencereden gönderin. PDF'i isterseniz indirip ayrıca paylaşın."];
    }
    $db->prepare("INSERT INTO satinalma_gonderimleri (siparis_id, kanal, alici, yapan) VALUES (?,?,?,?)")->execute([$id, $kanal, $alici, _saYapan()]);
    if ($s['durum'] === 'taslak') {
        $db->prepare("UPDATE satinalma_siparisleri SET durum='gonderildi', updated_at=datetime('now','localtime') WHERE id=?")->execute([$id]);
    }
    logla($db, 'satinalma_siparisleri', $id, 'guncelle', null, ['gonderim' => $kanal, 'alici' => $alici],
          'Satınalma siparişi tedarikçiye gönderildi (' . ($kanal === 'eposta' ? 'e-posta' : 'WhatsApp') . ' → ' . $alici . '): ' . $s['kod']);
    $out['durum'] = $s['durum'] === 'taslak' ? 'gonderildi' : $s['durum'];
    jsonOk($out, $out['mesaj']);
}

if ($method === 'POST' && $action === 'teslim_al') {
    verifyCsrf();
    $body = _saGovde();
    $kalemId = (int)($body['kalem_id'] ?? 0);
    $miktar  = (float)($body['miktar'] ?? 0);
    if ($kalemId < 1 || $miktar <= 0) jsonError('Kalem ve miktar zorunludur.');

    $kSt = $db->prepare("SELECT k.*, s.kod, s.durum FROM satinalma_kalemleri k JOIN satinalma_siparisleri s ON s.id=k.siparis_id WHERE k.id=?");
    $kSt->execute([$kalemId]);
    $kalem = $kSt->fetch();
    if (!$kalem) jsonError('Kalem bulunamadı.', 404);
    if ($kalem['durum'] === 'iptal') jsonError('İptal edilmiş siparişte teslim alınamaz.');

    $kalanMiktar = (float)$kalem['miktar'] - (float)$kalem['teslim_alinan'];
    if ($miktar > $kalanMiktar + SA_EPS) jsonError('Teslim alınan miktar kalan miktardan fazla olamaz (kalan: ' . $kalanMiktar . ').');

    $siparisId = (int)$kalem['siparis_id'];
    try {
        $yeniDurum = islemIcinde($db, function (PDO $db) use ($kalemId, $miktar, $kalem, $siparisId): string {
        $tq = $db->prepare("SELECT s.durum FROM satinalma_kalemleri k JOIN satinalma_siparisleri s ON s.id=k.siparis_id WHERE k.id=?");
        $tq->execute([$kalemId]);
        if ($tq->fetchColumn() === 'iptal') throw new DomainException('İptal edilmiş siparişte teslim alınamaz.', 409);
        $tu = $db->prepare("UPDATE satinalma_kalemleri SET teslim_alinan = teslim_alinan + ? WHERE id=? AND COALESCE(teslim_alinan,0) + ? <= COALESCE(miktar,0) + ?");
        $tu->execute([$miktar, $kalemId, $miktar, SA_EPS]);
        if ($tu->rowCount() !== 1) throw new DomainException('Bu kalem bu arada teslim alınmış; kalan miktar yetmiyor. Sayfayı yenileyin.', 409);

        if ($kalem['parca_id'] && !(int)($kalem['stok_disi'] ?? 0)) {
            $db->prepare("UPDATE yedek_parca SET stok = stok + ?, updated_at=datetime('now','localtime') WHERE id=?")
               ->execute([(int)round($miktar), (int)$kalem['parca_id']]);
            $db->prepare("INSERT INTO stok_hareket (parca_id, tarih, tip, miktar, kaynak, birim_fiyat, aciklama) VALUES (?,?,?,?,?,?,?)")
               ->execute([(int)$kalem['parca_id'], date('Y-m-d'), 'giris', (int)round($miktar), 'satinalma', (float)($kalem['son_birim_fiyat'] ?? 0), ($kalem['kod'] ?: 'Satınalma') . ' teslim alındı']);
        }
        $durum = _saDurumHesapla($db, $siparisId);
        logla($db, 'satinalma_siparisleri', $siparisId, 'guncelle', null, null, 'Satınalma teslim alındı (' . ($kalem['kod'] ?: '#' . $siparisId) . ': ' . $kalem['aciklama'] . ' × ' . $miktar . ')');
        return (string)$durum;
        });
    } catch (DomainException $e) {
        jsonError($e->getMessage(), 409);
    } catch (\Throwable $e) {
        error_log('satinalma teslim alinamadi: ' . $e->getMessage());
        jsonError('Teslim alma işlemi başarısız.', 500);
    }
    jsonOk(['durum' => $yeniDurum], 'Teslim alındı.');
}

if ($method === 'POST' && $action === 'fatura') {
    verifyCsrf();
    $id = (int)($_GET['id'] ?? 0);
    $body = _saGovde();
    $s = _saSiparis($db, $id);
    if (!$s) jsonError('Sipariş bulunamadı.', 404);
    if ($s['durum'] === 'iptal') jsonError('İptal edilmiş siparişe fatura girilemez.');
    $faturaNo = mb_substr(trim(metin($body['fatura_no'] ?? '')), 0, 60);
    if ($faturaNo === '') jsonError('Fatura numarası zorunludur.');
    $faturaTarihi = dateStr('fatura_tarihi', $body) ?: date('Y-m-d');
    $girdi = is_array($body['kalemler'] ?? null) ? $body['kalemler'] : [];
    $kalemMap = [];
    foreach ($s['kalemler'] as $k) $kalemMap[$k['id']] = $k;

    $satirlar = []; $ara = 0.0; $kdvT = 0.0; $buFatura = [];
    foreach ($girdi as $g) {
        $kid = (int)($g['kalem_id'] ?? 0);
        $mk = round((float)($g['miktar'] ?? 0), 4);
        if ($kid < 1 || $mk <= 0) continue;
        if (!isset($kalemMap[$kid])) jsonError('Faturadaki bir kalem bu siparişe ait değil.');
        $k = $kalemMap[$kid];
        $bf = round((float)($g['birim_fiyat'] ?? 0), 4);
        $ko = (float)($g['kdv_oran'] ?? 0);
        if ($bf < 0) jsonError('"' . $k['aciklama'] . '" için birim fiyat negatif olamaz.');
        if ($ko < 0 || $ko > 100) jsonError('"' . $k['aciklama'] . '" için KDV oranı 0 ile 100 arasında olmalı.');
        $buFatura[$kid] = ($buFatura[$kid] ?? 0) + $mk;
        $kalan = $k['miktar'] - $k['faturalanan'];
        if ($buFatura[$kid] > $kalan + SA_EPS) {
            jsonError('Sıra ' . ($k['sira'] ?? '') . ' — "' . $k['aciklama'] . '": faturalanan miktar sipariş miktarını aşıyor (sipariş ' . saSayiYerel($k['miktar'])
                . ', daha önce faturalanan ' . saSayiYerel($k['faturalanan']) . ', kalan ' . saSayiYerel(max(0, $kalan)) . ').');
        }
        $mal = round($mk * $bf, 2);
        $kdv = round($mal * $ko / 100, 2);
        $satirlar[] = [$kid, $mk, $bf, $ko, $kdv, $mal, round($mal + $kdv, 2)];
        $ara += $mal; $kdvT += $kdv;
    }
    if (!$satirlar) jsonError('Faturada en az bir kalem ve miktar olmalı.');
    $ara = round($ara, 2); $kdvT = round($kdvT, 2);

    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO satinalma_faturalari (siparis_id, tedarikci_id, fatura_no, fatura_tarihi, ara_toplam, kdv_toplam, genel_toplam, notlar, yapan)
                      VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([$id, $s['tedarikci_id'], $faturaNo, $faturaTarihi, $ara, $kdvT, round($ara + $kdvT, 2), trim(metin($body['notlar'] ?? '')) ?: null, _saYapan()]);
        $fid = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT INTO satinalma_fatura_kalemleri (fatura_id, kalem_id, miktar, birim_fiyat, kdv_oran, kdv_tutar, mal_hizmet_tutar, toplam) VALUES (?,?,?,?,?,?,?,?)");
        foreach ($satirlar as $r) $ins->execute(array_merge([$fid], $r));
        $kartlar = [];
        foreach (array_keys($buFatura) as $kid) {
            _saKalemFaturaTazele($db, $kid);
            if ($kalemMap[$kid]['parca_id']) $kartlar[$kalemMap[$kid]['parca_id']] = true;
        }
        foreach (array_keys($kartlar) as $pid) _saKartFiyatTazele($db, (int)$pid);
        $yeniDurum = _saDurumHesapla($db, $id);
        $db->commit();
    } catch (\Exception $e) {
        $db->rollBack();
        jsonError('Fatura kaydedilemedi.', 500);
    }
    logla($db, 'satinalma_faturalari', $fid, 'ekle', null, logKayitAl($db, 'satinalma_faturalari', $fid),
          'Tedarikçi faturası girildi: ' . $faturaNo . ' (' . $s['kod'] . ', ' . number_format($ara + $kdvT, 2, ',', '.') . ' TL)');
    jsonOk(['id' => $fid, 'durum' => $yeniDurum, 'ara_toplam' => $ara, 'kdv_toplam' => $kdvT, 'genel_toplam' => round($ara + $kdvT, 2)], 'Fatura kaydedildi.');
}

if ($method === 'DELETE' && $action === 'fatura') {
    verifyCsrf();
    $fid = (int)($_GET['fatura_id'] ?? 0);
    $f = $db->prepare("SELECT * FROM satinalma_faturalari WHERE id=?");
    $f->execute([$fid]);
    $fatura = $f->fetch();
    if (!$fatura) jsonError('Fatura bulunamadı.', 404);
    $eski = logKayitAl($db, 'satinalma_faturalari', $fid);
    $kq = $db->prepare("SELECT DISTINCT fk.kalem_id, k.parca_id FROM satinalma_fatura_kalemleri fk LEFT JOIN satinalma_kalemleri k ON k.id=fk.kalem_id WHERE fk.fatura_id=?");
    $kq->execute([$fid]);
    $etkilenen = $kq->fetchAll();
    $siparisId = (int)$fatura['siparis_id'];
    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM satinalma_fatura_kalemleri WHERE fatura_id=?")->execute([$fid]);
        $db->prepare("DELETE FROM satinalma_faturalari WHERE id=?")->execute([$fid]);
        $kartlar = [];
        foreach ($etkilenen as $r) {
            _saKalemFaturaTazele($db, (int)$r['kalem_id']);
            if ($r['parca_id']) $kartlar[(int)$r['parca_id']] = true;
        }
        foreach (array_keys($kartlar) as $pid) _saKartFiyatTazele($db, $pid);
        $yeniDurum = _saDurumHesapla($db, $siparisId);
        $db->commit();
    } catch (\Exception $e) {
        $db->rollBack();
        jsonError('Fatura geri alınamadı.', 500);
    }
    logla($db, 'satinalma_faturalari', $fid, 'sil', $eski, null, 'Tedarikçi faturası geri alındı: ' . ($fatura['fatura_no'] ?? '#' . $fid));
    jsonOk(['durum' => $yeniDurum], 'Fatura geri alındı.');
}

if ($method === 'POST' && $action === 'yedek_parcaya_aktar') {
    verifyCsrf();
    $id = (int)($_GET['id'] ?? 0);
    $body = _saGovde();
    $s = _saSiparis($db, $id);
    if (!$s) jsonError('Sipariş bulunamadı.', 404);
    $secilen = array_map('intval', is_array($body['kalem_idler'] ?? null) ? $body['kalem_idler'] : []);
    $kalemler = array_values(array_filter($s['kalemler'], fn($k) => in_array($k['id'], $secilen, true)));
    if (!$kalemler) jsonError('Aktarılacak kalemleri seçin.');

    $olusturulan = 0; $guncellenen = 0; $atlanan = 0; $mesajlar = [];
    $kodBul = $db->prepare("SELECT id FROM yedek_parca WHERE parca_no IS NOT NULL AND TRIM(parca_no)<>'' AND parca_no=? COLLATE NOCASE
                            AND COALESCE(aktif,1)=1 ORDER BY id LIMIT 1");
    $kartGuncelle = $db->prepare("UPDATE yedek_parca SET alis_fiyati=COALESCE(?, alis_fiyati), son_tedarikci_id=CASE WHEN ? IS NULL THEN son_tedarikci_id ELSE ? END, son_alis_tarihi=COALESCE(?, son_alis_tarihi),
                                  fiyat = CASE WHEN COALESCE(fiyat,0)=0 AND ? IS NOT NULL THEN ? ELSE fiyat END,
                                  birim = COALESCE(NULLIF(birim,''), ?), updated_at=datetime('now','localtime') WHERE id=?");
    $sonFatura = $db->prepare("SELECT MAX(f.fatura_tarihi) FROM satinalma_fatura_kalemleri fk JOIN satinalma_faturalari f ON f.id=fk.fatura_id WHERE fk.kalem_id=?");
    $stokEkle = function (int $pid, array $k) use ($db, $s): void {
        $adet = (int)round($k['teslim_alinan']);
        if ($adet < 1) return;
        $db->prepare("UPDATE yedek_parca SET stok = stok + ?, updated_at=datetime('now','localtime') WHERE id=?")->execute([$adet, $pid]);
        $db->prepare("INSERT INTO stok_hareket (parca_id, tarih, tip, miktar, kaynak, birim_fiyat, aciklama) VALUES (?,?,?,?,?,?,?)")
           ->execute([$pid, date('Y-m-d'), 'giris', $adet, 'satinalma', (float)($k['son_birim_fiyat'] ?? 0), ($s['kod'] ?: 'Satınalma') . ' — yedek parçaya aktarıldı']);
    };

    $db->beginTransaction();
    try {
        foreach ($kalemler as $k) {
            $ad = $k['aciklama'];
            if ($k['stok_disi']) { $atlanan++; $mesajlar[] = '"' . $ad . '" stok dışı işaretli; yedek parçaya aktarılmadı.'; continue; }
            $sonFatura->execute([$k['id']]);
            $tarih = $sonFatura->fetchColumn() ?: null;
            $fiyat = $k['son_birim_fiyat'];
            if ($k['parca_id']) {
                $kartGuncelle->execute([$fiyat, $fiyat, $s['tedarikci_id'], $tarih, $fiyat, $fiyat, $k['birim'], $k['parca_id']]);
                if ($fiyat !== null) _saKartFiyatTazele($db, $k['parca_id']);
                $guncellenen++;
                $mesajlar[] = '"' . $ad . '" kartı güncellendi' . ($fiyat === null ? ' (faturası henüz girilmediği için fiyat yok)' : '') . '.';
                $pid = $k['parca_id'];
            } else {
                $pid = null;
                if ($k['stok_kodu'] !== null && trim($k['stok_kodu']) !== '') {
                    $kodBul->execute([trim($k['stok_kodu'])]);
                    $pid = ((int)$kodBul->fetchColumn()) ?: null;
                }
                if ($pid) {
                    $kartGuncelle->execute([$fiyat, $fiyat, $s['tedarikci_id'], $tarih, $fiyat, $fiyat, $k['birim'], $pid]);
                    $guncellenen++;
                    $mesajlar[] = '"' . $ad . '" aynı stok kodlu karta (' . $k['stok_kodu'] . ') bağlandı' . ($k['teslim_alinan'] > 0 ? ', gelen ' . saSayiYerel($k['teslim_alinan']) . ' ' . $k['birim'] . ' stoğa eklendi' : '') . '.';
                } else {
                    $db->prepare("INSERT INTO yedek_parca (ad, parca_no, stok, fiyat, alis_fiyati, son_tedarikci_id, son_alis_tarihi, birim, aciklama)
                                  VALUES (?,?,0,?,?,?,?,?,?)")
                       ->execute([$ad, $k['stok_kodu'], (float)($fiyat ?? 0), $fiyat, $s['tedarikci_id'], $tarih, $k['birim'], 'Satınalma ' . $s['kod'] . ' siparişinden aktarıldı']);
                    $pid = (int)$db->lastInsertId();
                    $olusturulan++;
                    $mesajlar[] = '"' . $ad . '" için yeni yedek parça kartı açıldı' . ($k['teslim_alinan'] > 0 ? ', gelen ' . saSayiYerel($k['teslim_alinan']) . ' ' . $k['birim'] . ' stoğa eklendi' : '') . '.';
                }
                $stokEkle($pid, $k);
                $db->prepare("UPDATE satinalma_kalemleri SET parca_id=? WHERE id=?")->execute([$pid, $k['id']]);
                if ($fiyat !== null) _saKartFiyatTazele($db, $pid);
            }
            $db->prepare("UPDATE satinalma_kalemleri SET aktarildi=1 WHERE id=?")->execute([$k['id']]);
        }
        $db->commit();
    } catch (\Exception $e) {
        $db->rollBack();
        jsonError('Yedek parçaya aktarılamadı.', 500);
    }
    logla($db, 'satinalma_siparisleri', $id, 'guncelle', null, ['olusturulan' => $olusturulan, 'guncellenen' => $guncellenen, 'atlanan' => $atlanan],
          'Satınalma kalemleri yedek parçaya aktarıldı (' . $s['kod'] . '): ' . $olusturulan . ' yeni kart, ' . $guncellenen . ' güncellenen, ' . $atlanan . ' atlanan');
    jsonOk(['olusturulan' => $olusturulan, 'guncellenen' => $guncellenen, 'atlanan' => $atlanan, 'mesajlar' => $mesajlar],
           $olusturulan + $guncellenen > 0 ? 'Yedek parçaya aktarıldı.' : 'Aktarılan kalem olmadı.');
}

switch ($method) {

    case 'GET':
        if (isset($_GET['id'])) {
            $s = _saDetay($db, (int)$_GET['id']);
            if (!$s) jsonError('Sipariş bulunamadı.', 404);
            jsonOk($s);
        }

        require_once __DIR__ . '/satinalma-lib.php';
        $where = []; $params = [];
        if (($tid = (int)($_GET['tedarikci_id'] ?? 0)) > 0) { $where[] = "s.tedarikci_id=?"; $params[] = $tid; }
        if (($d = trim(metin($_GET['durum'] ?? ''))) !== '' && $d !== 'hepsi') { $where[] = "s.durum=?"; $params[] = $d; }
        if (($q = trim(metin($_GET['q'] ?? ''))) !== '') { $where[] = "(" . trAramaSql('s.kod') . " LIKE ? OR " . trAramaSql('t.unvan') . " LIKE ?)"; $like = '%' . trArama($q) . '%'; array_push($params, $like, $like); }
        $sql = "SELECT s.*, t.unvan AS tedarikci_unvan FROM satinalma_siparisleri s JOIN tedarikciler t ON t.id = s.tedarikci_id";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY s.tarih DESC, s.id DESC";
        $st = $db->prepare($sql); $st->execute($params);
        $rows = $st->fetchAll();
        $kalemOzet = []; $faturaOzet = [];
        if ($rows) {
            $ids = array_map('intval', array_column($rows, 'id'));
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $kSt = $db->prepare("SELECT siparis_id, COUNT(*) adet, SUM(miktar) miktar, SUM(MIN(teslim_alinan, miktar)) teslim, SUM(MIN(COALESCE(faturalanan,0), miktar)) fatura
                                 FROM satinalma_kalemleri WHERE siparis_id IN ($ph) GROUP BY siparis_id");
            $kSt->execute($ids);
            foreach ($kSt->fetchAll() as $k) $kalemOzet[(int)$k['siparis_id']] = $k;
            $fSt = $db->prepare("SELECT siparis_id, COUNT(*) adet, SUM(genel_toplam) toplam FROM satinalma_faturalari WHERE siparis_id IN ($ph) GROUP BY siparis_id");
            $fSt->execute($ids);
            foreach ($fSt->fetchAll() as $f) $faturaOzet[(int)$f['siparis_id']] = $f;
        }
        foreach ($rows as &$r) {
            $oz = $kalemOzet[(int)$r['id']] ?? ['adet' => 0, 'miktar' => 0, 'teslim' => 0, 'fatura' => 0];
            $fo = $faturaOzet[(int)$r['id']] ?? ['adet' => 0, 'toplam' => 0];
            $r['kalem_adet'] = (int)$oz['adet'];
            $r['toplam_tutar'] = round((float)$fo['toplam'], 2);
            $r['fatura_adet'] = (int)$fo['adet'];
            $r['teslim_yuzde'] = (float)$oz['miktar'] > 0 ? (int)round((float)$oz['teslim'] / (float)$oz['miktar'] * 100) : 0;
            $r['fatura_yuzde'] = (float)$oz['miktar'] > 0 ? (int)round((float)$oz['fatura'] / (float)$oz['miktar'] * 100) : 0;
            $r['durum_etiket'] = SA_DURUM_ETIKET[$r['durum']] ?? $r['durum'];
        }
        unset($r);
        jsonOk($rows);
        break;

    case 'POST':
        verifyCsrf();
        $body = _saGovde();
        $tedarikciId = (int)($body['tedarikci_id'] ?? 0);
        if ($tedarikciId < 1) jsonError('Tedarikçi seçilmelidir.');
        $kalemler = _saKalemGirdi($db, is_array($body['kalemler'] ?? null) ? $body['kalemler'] : []);
        if (!$kalemler) jsonError('En az bir sipariş kalemi ekleyin (malzeme adı boş olamaz).');

        $tChk = $db->prepare("SELECT COUNT(*) FROM tedarikciler WHERE id=?");
        $tChk->execute([$tedarikciId]);
        if ((int)$tChk->fetchColumn() === 0) jsonError('Tedarikçi bulunamadı.');

        $tarih = dateStr('tarih', $body) ?: date('Y-m-d');
        $db->beginTransaction();
        try {
            $db->prepare("INSERT INTO satinalma_siparisleri (tedarikci_id, tarih, durum, notlar) VALUES (?,?,?,?)")
               ->execute([$tedarikciId, $tarih, 'taslak', trim(metin($body['notlar'] ?? '')) ?: null]);
            $id  = (int)$db->lastInsertId();
            $kod = 'SA-' . substr($tarih,0,4) . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
            $db->prepare("UPDATE satinalma_siparisleri SET kod=? WHERE id=?")->execute([$kod, $id]);
            foreach ($kalemler as $k) _saKalemEkle($db, $id, $k);
            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            jsonError('Sipariş kaydedilemedi.', 500);
        }
        logla($db, 'satinalma_siparisleri', $id, 'ekle', null, null, 'Satınalma siparişi: ' . $kod);
        if (array_filter(array_column($kalemler, 'teknik_servis_id'))) {
            gorevOlustur($db, 'Parça talebi: sipariş ' . $kod, null, 'satinalma', $id);
        }
        jsonOk(['id' => $id, 'kod' => $kod], 'Satınalma siparişi kaydedildi.');

    case 'PUT':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $body = _saGovde();

        $cur = $db->prepare("SELECT * FROM satinalma_siparisleri WHERE id=?");
        $cur->execute([$id]);
        $mevcutSiparis = $cur->fetch();
        if (!$mevcutSiparis) jsonError('Sipariş bulunamadı.', 404);

        if (count($body) === 1 && isset($body['durum'])) {
            $istenen = _saDurumManuel($body['durum']);
            $t = $db->prepare("SELECT COALESCE(SUM(teslim_alinan),0) + COALESCE(SUM(faturalanan),0) FROM satinalma_kalemleri WHERE siparis_id=?");
            $t->execute([$id]);
            $hareketli = (float)$t->fetchColumn() > 0;
            if ($hareketli && $istenen !== 'iptal' && $mevcutSiparis['durum'] !== 'iptal') jsonError('Teslimatı ya da faturası olan siparişin durumu teslim ve faturadan hesaplanır; elle değiştirilemez.');
            if ($hareketli && $istenen === 'iptal') jsonError('Teslimatı ya da faturası olan sipariş iptal edilemez.');
            $db->prepare("UPDATE satinalma_siparisleri SET durum=?, updated_at=datetime('now','localtime') WHERE id=?")->execute([$istenen, $id]);
            if ($mevcutSiparis['durum'] === 'iptal' && $istenen !== 'iptal') _saDurumHesapla($db, $id);
            logla($db, 'satinalma_siparisleri', $id, 'guncelle', ['durum' => $mevcutSiparis['durum']], ['durum' => $istenen], 'Satınalma siparişi durumu: ' . $mevcutSiparis['kod']);
            jsonOk(null, 'Durum güncellendi.');
        }

        if (!in_array($mevcutSiparis['durum'], ['taslak', 'gonderildi'], true)) jsonError('Yalnız taslak ya da gönderilmiş (henüz teslim alınmamış) sipariş düzenlenebilir.');

        $tedarikciId = (int)($body['tedarikci_id'] ?? 0);
        if ($tedarikciId < 1) jsonError('Tedarikçi seçilmelidir.');
        $kalemler = _saKalemGirdi($db, is_array($body['kalemler'] ?? null) ? $body['kalemler'] : []);
        if (!$kalemler) jsonError('En az bir sipariş kalemi ekleyin (malzeme adı boş olamaz).');

        $eskiler = [];
        foreach (_saKalemler($db, $id) as $k) $eskiler[$k['id']] = $k;
        $kalanIdler = array_filter(array_column($kalemler, 'id'), fn($kid) => isset($eskiler[$kid]));
        $faturaliVar = false;
        foreach ($eskiler as $kid => $k) {
            if (!in_array($kid, $kalanIdler, true) && ($k['teslim_alinan'] > 0 || $k['faturalanan'] > 0)) {
                jsonError('"' . $k['aciklama'] . '" kaleminin teslimatı ya da faturası var; siparişten çıkarılamaz.');
            }
            if ($k['faturalanan'] > SA_EPS) $faturaliVar = true;
        }
        if ($faturaliVar && $tedarikciId !== (int)$mevcutSiparis['tedarikci_id']) {
            jsonError('Faturası girilmiş siparişin tedarikçisi değiştirilemez; önce faturayı geri alın.');
        }

        $tarih = dateStr('tarih', $body) ?: date('Y-m-d');
        $eskiLog = logKayitAl($db, 'satinalma_siparisleri', $id) ?? [];
        $eskiLog['kalemler'] = _saLogKalemler($db, $id);
        $db->beginTransaction();
        try {
            $db->prepare("UPDATE satinalma_siparisleri SET tedarikci_id=?, tarih=?, notlar=?, updated_at=datetime('now','localtime') WHERE id=?")
               ->execute([$tedarikciId, $tarih, trim(metin($body['notlar'] ?? '')) ?: null, $id]);
            foreach ($eskiler as $kid => $k) {
                if (!in_array($kid, $kalanIdler, true)) $db->prepare("DELETE FROM satinalma_kalemleri WHERE id=?")->execute([$kid]);
            }
            $up = $db->prepare("UPDATE satinalma_kalemleri SET sira=?, stok_kodu=?, aciklama=?, miktar=?, birim=?, parca_id=?, notlar=?, stok_disi=?, teknik_servis_id=? WHERE id=?");
            foreach ($kalemler as $k) {
                if ($k['id'] && isset($eskiler[$k['id']])) {
                    $e = $eskiler[$k['id']];
                    if ($k['miktar'] < max($e['teslim_alinan'], $e['faturalanan']) - SA_EPS) throw new DomainException('"' . $k['aciklama'] . '" miktarı teslim alınan / faturalanan miktarın altına inemez.');
                    if ($e['faturalanan'] > SA_EPS && (int)($k['parca_id'] ?? 0) !== (int)($e['parca_id'] ?? 0)) {
                        throw new DomainException('"' . $k['aciklama'] . '" kaleminin faturası var; yedek parça bağlantısı değiştirilemez (önce faturayı geri alın).');
                    }
                    $up->execute([$k['sira'], $k['stok_kodu'], $k['aciklama'], $k['miktar'], $k['birim'], $k['parca_id'], $k['notlar'], $k['stok_disi'],
                                  $k['teknik_servis_id'] ?? $e['teknik_servis_id'], $k['id']]);
                } else {
                    _saKalemEkle($db, $id, $k);
                }
            }
            $db->commit();
        } catch (\DomainException $e) {
            $db->rollBack();
            jsonError($e->getMessage());
        } catch (\Exception $e) {
            $db->rollBack();
            jsonError('Sipariş güncellenemedi.', 500);
        }
        $yeniLog = logKayitAl($db, 'satinalma_siparisleri', $id) ?? [];
        $yeniLog['kalemler'] = _saLogKalemler($db, $id);
        logla($db, 'satinalma_siparisleri', $id, 'guncelle', $eskiLog, $yeniLog, 'Satınalma siparişi güncellendi: ' . $mevcutSiparis['kod']);
        if (array_filter(array_column($kalemler, 'teknik_servis_id'))) {
            gorevOlustur($db, 'Parça talebi: sipariş ' . $mevcutSiparis['kod'], null, 'satinalma', $id);
        }
        jsonOk(null, 'Sipariş güncellendi.');

    case 'DELETE':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $t = $db->prepare("SELECT COALESCE(SUM(teslim_alinan),0) FROM satinalma_kalemleri WHERE siparis_id=?");
        $t->execute([$id]);
        if ((float)$t->fetchColumn() > 0) jsonError('Teslimat başlamış sipariş silinemez, iptal edin.');
        $fq = $db->prepare("SELECT COUNT(*) FROM satinalma_faturalari WHERE siparis_id=?");
        $fq->execute([$id]);
        if ((int)$fq->fetchColumn() > 0) jsonError('Faturası girilmiş sipariş silinemez; önce faturayı geri alın.');
        $_sipKod = $db->prepare("SELECT kod FROM satinalma_siparisleri WHERE id=?");
        $_sipKod->execute([$id]);
        $_kod = (string)($_sipKod->fetchColumn() ?: $id);
        $db->prepare("DELETE FROM satinalma_siparisleri WHERE id=?")->execute([$id]);
        logla($db, 'satinalma_siparisleri', $id, 'sil', null, null, 'Satınalma siparişi silindi: ' . $_kod);
        jsonOk(null, 'Sipariş silindi.');

    default:
        jsonError('Method Not Allowed', 405);
}
