<?php
declare(strict_types=1);
require_once __DIR__ . '/belge-zinciri-lib.php';
const HB_TURLER = ['DHT' => 'Dönüş Hasar Tespit Tutanağı', 'DDK' => 'Dönüş Kontrol Belgesi',
                   'TSR' => 'Teknik Servis Raporu', 'HBB' => 'Hasar Bedeli Bildirimi'];

function hbSema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS hasar_belgeleri (
      id INTEGER PRIMARY KEY AUTOINCREMENT, tur TEXT NOT NULL, no TEXT NOT NULL, kaynak_id INTEGER NOT NULL,
      kiralama_id INTEGER NOT NULL, degerlendirme_id INTEGER, surum INTEGER NOT NULL, icerik TEXT NOT NULL, hash TEXT NOT NULL,
      olusturan TEXT, created_at TEXT NOT NULL, UNIQUE(tur, kaynak_id, surum));
    CREATE INDEX IF NOT EXISTS idx_hb_kiralama ON hasar_belgeleri(kiralama_id);
    CREATE INDEX IF NOT EXISTS idx_hb_deger ON hasar_belgeleri(degerlendirme_id);
    CREATE UNIQUE INDEX IF NOT EXISTS ux_hb_no_surum ON hasar_belgeleri(no, surum);
    CREATE TABLE IF NOT EXISTS belge_sayaclari (tur TEXT NOT NULL, yil INTEGER NOT NULL, son INTEGER NOT NULL, PRIMARY KEY(tur, yil));
    CREATE TABLE IF NOT EXISTS hasar_dosya_gonderimleri (
      id INTEGER PRIMARY KEY AUTOINCREMENT, degerlendirme_id INTEGER NOT NULL, kiralama_id INTEGER NOT NULL, kanal TEXT NOT NULL,
      alici TEXT, belgeler TEXT NOT NULL, token_hash TEXT UNIQUE, son_gecerlilik TEXT, iptal INTEGER NOT NULL DEFAULT 0,
      goruntulenme INTEGER NOT NULL DEFAULT 0, son_goruntulenme TEXT, gonderen TEXT, created_at TEXT NOT NULL);
    CREATE INDEX IF NOT EXISTS idx_hdg_deger ON hasar_dosya_gonderimleri(degerlendirme_id);
    CREATE TRIGGER IF NOT EXISTS trg_hb_degismez BEFORE UPDATE OF icerik, hash, no, surum ON hasar_belgeleri
      BEGIN SELECT RAISE(ABORT, 'Belge sürümü değiştirilemez; yeni sürüm yazın.'); END;
    CREATE TRIGGER IF NOT EXISTS trg_hb_silinmez BEFORE DELETE ON hasar_belgeleri
      BEGIN SELECT RAISE(ABORT, 'Belge sürümü silinemez.'); END;");
    if (!in_array('bicim', array_column($db->query('PRAGMA table_info(hasar_dosya_gonderimleri)')->fetchAll(PDO::FETCH_ASSOC), 'name'), true))
        $db->exec("ALTER TABLE hasar_dosya_gonderimleri ADD COLUMN bicim TEXT NOT NULL DEFAULT 'baglanti'");
}

function hbNo(PDO $db, string $tur, string $tarih): string {
    $yil = (int)substr($tarih, 0, 4);
    $db->prepare('INSERT INTO belge_sayaclari(tur,yil,son) VALUES(?,?,1) ON CONFLICT(tur,yil) DO UPDATE SET son=son+1')->execute([$tur, $yil]);
    $q = $db->prepare('SELECT son FROM belge_sayaclari WHERE tur=? AND yil=?'); $q->execute([$tur, $yil]);
    return $tur . '-' . $yil . '-' . str_pad((string)$q->fetchColumn(), 4, '0', STR_PAD_LEFT);
}

function hbSon(PDO $db, string $tur, int $kaynakId): ?array {
    $q = $db->prepare('SELECT * FROM hasar_belgeleri WHERE tur=? AND kaynak_id=? ORDER BY surum DESC LIMIT 1');
    $q->execute([$tur, $kaynakId]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $r['icerik'] = json_decode($r['icerik'], true) ?: [];
    return $r;
}

function hbSurumler(PDO $db, string $tur, int $kaynakId): array {
    $q = $db->prepare('SELECT id, no, surum, olusturan, created_at FROM hasar_belgeleri WHERE tur=? AND kaynak_id=? ORDER BY surum DESC');
    $q->execute([$tur, $kaynakId]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function hbYaz(PDO $db, string $tur, int $kaynakId, int $kiralamaId, ?int $degerlendirmeId, array $icerik): array {
    if (!isset(HB_TURLER[$tur])) throw new DomainException('Geçersiz belge türü.');
    if (!$db->inTransaction()) {
        $db->beginTransaction();
        try {
            islemKilitAl($db);
            $r = hbYaz($db, $tur, $kaynakId, $kiralamaId, $degerlendirmeId, $icerik);
            $db->commit();
            return $r;
        } catch (\Throwable $e) { $db->rollBack(); throw $e; }
    }
    $json = json_encode($icerik, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    $hash = hash('sha256', $json);
    $son = hbSon($db, $tur, $kaynakId);
    if ($son && $son['hash'] === $hash) return ['id' => (int)$son['id'], 'no' => $son['no'], 'surum' => (int)$son['surum'], 'yeni' => false];
    $simdi = date('Y-m-d H:i:s');
    $no = $son ? $son['no'] : hbNo($db, $tur, $simdi);
    $surum = $son ? (int)$son['surum'] + 1 : 1;
    $db->prepare('INSERT INTO hasar_belgeleri(tur,no,kaynak_id,kiralama_id,degerlendirme_id,surum,icerik,hash,olusturan,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)')
       ->execute([$tur, $no, $kaynakId, $kiralamaId, $degerlendirmeId, $surum, $json, $hash, (string)($_SESSION['username'] ?? 'Sistem'), $simdi]);
    $id = (int)$db->lastInsertId();
    logla($db, 'hasar_belgeleri', $id, 'ekle', null, ['tur' => $tur, 'no' => $no, 'surum' => $surum], HB_TURLER[$tur] . ' ' . $no . ' · Sürüm ' . $surum);
    return ['id' => $id, 'no' => $no, 'surum' => $surum, 'yeni' => true];
}

function hbItBelge(PDO $db, int $tutanakId, ?int $surum = null): ?array {
    $q = $db->prepare('SELECT t.belge_no, s.surum, s.veri, s.created_at FROM iade_tutanaklari t JOIN tutanak_surumleri s ON s.tutanak_id=t.id
                       WHERE t.id=? AND t.inceleme_sonucu IS NOT NULL' . ($surum === null ? ' ORDER BY s.surum DESC LIMIT 1' : ' AND s.surum=?'));
    $q->execute($surum === null ? [$tutanakId] : [$tutanakId, $surum]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    return ['tur' => 'IT', 'no' => (string)$r['belge_no'], 'surum' => (int)$r['surum'], 'created_at' => $r['created_at'], 'tutanak_id' => $tutanakId,
            'icerik' => tsMusteriGorunumu(json_decode((string)$r['veri'], true) ?: [])];
}

function hbItSurumler(PDO $db, int $tutanakId): array {
    $q = $db->prepare('SELECT t.belge_no AS no, s.surum, s.kaydeden AS olusturan, s.created_at FROM tutanak_surumleri s
                       JOIN iade_tutanaklari t ON t.id=s.tutanak_id WHERE s.tutanak_id=? ORDER BY s.surum DESC');
    $q->execute([$tutanakId]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function hbIadeNo(PDO $db, int $kontrolId): ?string {
    if ($it = bzIadeTutanagiKontrol($db, $kontrolId)) return (string)$it['belge_no'];
    $dht = hbSon($db, 'DHT', $kontrolId);
    return $dht['no'] ?? null;
}


function _hbAyar(PDO $db, array $anahtarlar): array {
    $ph = implode(',', array_fill(0, count($anahtarlar), '?'));
    $q = $db->prepare("SELECT anahtar, deger FROM ayarlar WHERE anahtar IN ($ph)");
    $q->execute($anahtarlar);
    $out = array_fill_keys($anahtarlar, '');
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['anahtar']] = (string)$r['deger'];
    return $out;
}

function _hbKunye(PDO $db, int $kid): array {
    $q = $db->prepare('SELECT k.*, m.unvan AS musteri FROM kiralamalar k LEFT JOIN musteriler m ON m.id=k.musteri_id WHERE k.id=?');
    $q->execute([$kid]);
    $k = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    $tur = (string)($k['makine_tur'] ?? ''); $mid = (int)($k['makine_id'] ?? 0);
    $b = makineBilgiToplu($db, [['tur' => $tur, 'id' => $mid]])[$tur . ':' . $mid] ?? [];
    return [$k, [
        'kiralama' => ['kod' => $k['kod'] ?? null, 'sozlesme_no' => $k['sozlesme_no'] ?? null, 'musteri' => $k['musteri'] ?? null, 'sube' => $k['sube'] ?? null,
                       'baslangic' => $k['baslangic'] ?? null, 'gercek_bitis' => $k['gercek_bitis'] ?? null],
        'makine' => ['kod' => $b['kod'] ?? null, 'marka' => $b['marka'] ?? null, 'model' => $b['model'] ?? null, 'seri_no' => $b['seri_no'] ?? null, 'tur' => $tur ?: null,
                     'ad' => $k['ekipman_adi'] ?? null, 'kategori' => $b['kategori'] ?? null, 'metraj' => $b['metraj'] ?? null,
                     'olcu_birim' => isset($b['metraj']) ? ($b['olcu_birim'] ?? null) : null, 'makine_kodu' => $b['makine_kodu'] ?? null],
    ]];
}

function _hbFotoYollari(PDO $db, array $ids): array {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $s = $db->prepare("SELECT dosya_yolu FROM kiralama_fotograflari WHERE id IN ($ph) ORDER BY id");
    $s->execute($ids);
    return $s->fetchAll(PDO::FETCH_COLUMN);
}

function hbFotoCiftleri(PDO $db, int $kid): array {
    $s = $db->prepare("SELECT aci_tipi, dosya_yolu, COALESCE(yon,'gidis') AS yon, created_at FROM kiralama_fotograflari
                       WHERE kiralama_id=? AND COALESCE(yon,'gidis') IN ('gidis','donus') ORDER BY id");
    $s->execute([$kid]);
    $c = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $a = trim((string)$f['aci_tipi']) ?: 'Diğer';
        $c[$a] ??= ['aci' => $a, 'gidis_yol' => null, 'donus_yol' => null, 'tarih' => null];
        if ($f['yon'] === 'gidis' && !$c[$a]['gidis_yol']) $c[$a]['gidis_yol'] = $f['dosya_yolu'];
        if ($f['yon'] === 'donus' && !$c[$a]['donus_yol']) { $c[$a]['donus_yol'] = $f['dosya_yolu']; $c[$a]['tarih'] = $f['created_at']; }
    }
    return array_values($c);
}

function hbTsr(PDO $db, int $servisId): ?array {
    $q = $db->prepare("SELECT id, kiralama_id, depo_kontrol_id FROM hasar_degerlendirmeleri WHERE servis_id=? AND (depo_kontrol_id IS NOT NULL OR (tutanak_id IS NULL AND bulgu_turu='ariza')) ORDER BY id LIMIT 1");
    $q->execute([$servisId]);
    $h = $q->fetch(PDO::FETCH_ASSOC);
    if (!$h) {
        $q = $db->prepare("SELECT id, kiralama_id, donus_kontrol_id AS depo_kontrol_id FROM teknik_servis WHERE id=? AND kiralama_id IS NOT NULL AND (donus_kontrol_id IS NOT NULL OR kaynak IN ('depo_inceleme','donus_kontrolu'))");
        $q->execute([$servisId]);
        $h = $q->fetch(PDO::FETCH_ASSOC);
        if (!$h) return null;
        $h['id'] = null;
    }
    $s = $db->prepare('SELECT t.tarih, t.tamamlanma, t.teknisyen_id, p.ad_soyad AS teknisyen FROM teknik_servis t LEFT JOIN personel p ON p.id=t.teknisyen_id WHERE t.id=?');
    $s->execute([$servisId]);
    $sv = $s->fetch(PDO::FETCH_ASSOC) ?: [];
    require_once __DIR__ . '/tutanak-yaz.php';
    $tImza = personelImzasi($db, (int)($sv['teknisyen_id'] ?? 0))['imza'];
    [, $kunye] = _hbKunye($db, (int)$h['kiralama_id']);
    $kal = array_map(fn($x) => ['tur' => $x['tur'], 'aciklama' => $x['aciklama'], 'miktar' => $x['miktar'], 'birim' => $x['birim'],
                                'saat' => $x['saat'], 'kisi' => $x['kisi']], array_values(array_filter(tsKalemListe($db, $servisId), fn($x) => in_array($x['tur'], ['malzeme', 'iscilik'], true))));
    $f = $db->prepare("SELECT dosya_yolu FROM teknik_servis_fotograflari WHERE servis_id=? AND kaynak='tamir_sonrasi' ORDER BY id");
    $f->execute([$servisId]);
    $ic = $kunye + ['belge_tur' => 'TSR', 'iade_no' => !empty($h['depo_kontrol_id']) ? hbIadeNo($db, (int)$h['depo_kontrol_id']) : null,
                    'servis' => ['id' => $servisId, 'tarih' => $sv['tarih'] ?? null, 'tamamlanma' => $sv['tamamlanma'] ?? null, 'teknisyen' => $sv['teknisyen'] ?? null]
                                 + ($tImza !== null ? ['teknisyen_imza' => $tImza] : []),
                    'kalemler' => $kal, 'tamir_fotolari' => $f->fetchAll(PDO::FETCH_COLUMN)];
    return hbYaz($db, 'TSR', $servisId, (int)$h['kiralama_id'], $h['id'] !== null ? (int)$h['id'] : null, $ic);
}

function hbHbb(PDO $db, int $hid): ?array {
    require_once __DIR__ . '/ariza-karar-lib.php';
    $r = hdGet($db, $hid);
    if (!$r || (empty($r['depo_kontrol_id']) && !hfArizaKaydiMi($r)) || !in_array($r['fiyat_durum'], ['fiyatlandi', 'ucretsiz', 'faturalandi', 'iptal'], true)) return null;
    if ($r['fiyat_durum'] === 'iptal' && !hbSon($db, 'HBB', $hid)) return null;
    [$k, $kunye] = _hbKunye($db, (int)$r['kiralama_id']);
    $s = $db->prepare('SELECT aciklama, tutar, kdv_oran FROM hasar_kalemleri WHERE degerlendirme_id=? ORDER BY id');
    $s->execute([$hid]);
    $kal = array_map(fn($x) => ['aciklama' => $x['aciklama'], 'tutar' => (float)$x['tutar'], 'kdv_oran' => (float)$x['kdv_oran']], $s->fetchAll(PDO::FETCH_ASSOC));
    $h = hfHesap($kal);
    $hk = null;
    if (!empty($r['hakedis_id'])) {
        $s = $db->prepare('SELECT kod FROM hakedisler WHERE id=?');
        $s->execute([(int)$r['hakedis_id']]);
        $hk = $s->fetchColumn() ?: '#' . (int)$r['hakedis_id'];
    }
    $ay = _hbAyar($db, ['firma_banka', 'firma_iban', 'firma_telefon', 'firma_eposta']);
    $tsr = !empty($r['servis_id']) ? hbSon($db, 'TSR', (int)$r['servis_id']) : null;
    $karar = ($r['ariza_karar'] ?? null) || ($r['kusur_sahibi'] ?? null)
        ? ['karar' => AK_KARARLAR[$r['ariza_karar'] ?? ''] ?? null, 'sebep' => $r['ariza_karar_sebep'] ?? null,
           'kusur' => AK_KUSUR[$r['kusur_sahibi'] ?? ''] ?? null, 'kusur_sebep' => $r['kusur_sebep'] ?? null] : null;
    $ic = $kunye + ['belge_tur' => 'HBB', 'iade_no' => !empty($r['depo_kontrol_id']) ? hbIadeNo($db, (int)$r['depo_kontrol_id']) : null, 'tsr_no' => $tsr['no'] ?? null,
                    'para_birimi' => hfPara($k), 'yontem' => (string)($r['fatura_yontemi'] ?? ''), 'hakedis_kod' => $hk,
                    'kalemler' => $h['satirlar'], 'ara' => $h['ara'], 'kdv' => $h['kdv'], 'genel' => $h['genel'],
                    'iptal' => $r['fiyat_durum'] === 'iptal', 'ariza_karar' => $karar,
                    'odeme' => ['banka' => $ay['firma_banka'], 'iban' => $ay['firma_iban']],
                    'itiraz' => ['telefon' => $ay['firma_telefon'], 'eposta' => $ay['firma_eposta']]];
    return hbYaz($db, 'HBB', $hid, (int)$r['kiralama_id'], $hid, $ic);
}

const HB_LINK_GUN = 30;
const HB_FIYATLI = ['fiyatlandi', 'ucretsiz', 'faturalandi', 'iptal'];

function hbDosya(PDO $db, int $hid): array {
    require_once __DIR__ . '/hasar-fiyat-lib.php';
    $r = hdGet($db, $hid);
    if (!$r || (empty($r['depo_kontrol_id']) && !hfArizaKaydiMi($r))) throw new DomainException('Hasar dosyası bulunamadı.', 404);
    $k = $db->prepare('SELECT musteri_id, sube FROM kiralamalar WHERE id=?');
    $k->execute([(int)$r['kiralama_id']]);
    $kr = $k->fetch(PDO::FETCH_ASSOC) ?: [];
    $mq = $db->prepare('SELECT telefon, email FROM musteriler WHERE id=?');
    $mq->execute([(int)($kr['musteri_id'] ?? 0)]);
    $mu = $mq->fetch(PDO::FETCH_ASSOC) ?: [];
    $belgeler = []; $surumler = [];
    $it = !empty($r['depo_kontrol_id']) ? bzIadeTutanagiKontrol($db, (int)$r['depo_kontrol_id']) : null;
    if ($it && ($b = hbItBelge($db, (int)$it['id']))) { $belgeler[] = $b; $surumler['IT'] = hbItSurumler($db, (int)$it['id']); }
    $kaynaklar = [['TSR', (int)($r['servis_id'] ?? 0)], ['HBB', $hid]];
    if (!$it && !empty($r['depo_kontrol_id'])) array_unshift($kaynaklar, ['DHT', (int)$r['depo_kontrol_id']]);
    foreach ($kaynaklar as [$t, $kaynak]) {
        if (!$kaynak || !($b = hbSon($db, $t, $kaynak))) continue;
        $belgeler[] = ['tur' => $t, 'no' => $b['no'], 'surum' => (int)$b['surum'], 'created_at' => $b['created_at'], 'icerik' => $b['icerik']];
        $surumler[$t] = hbSurumler($db, $t, $kaynak);
    }
    $g = $db->prepare('SELECT id, kanal, alici, belgeler, son_gecerlilik, iptal, goruntulenme, son_goruntulenme, gonderen, created_at, bicim
                       FROM hasar_dosya_gonderimleri WHERE degerlendirme_id=? ORDER BY id DESC');
    $g->execute([$hid]);
    $ok = in_array('HBB', array_column($belgeler, 'tur'), true) && in_array($r['fiyat_durum'], HB_FIYATLI, true);
    require_once __DIR__ . '/musteri-erisim-lib.php';
    return ['degerlendirme_id' => $hid, 'kiralama_id' => (int)$r['kiralama_id'], 'sube' => $kr['sube'] ?? null, 'musteri_id' => (int)($kr['musteri_id'] ?? 0),
            'bicim' => meTeslimBicimi($db, (int)($kr['musteri_id'] ?? 0)),
            'belgeler' => $belgeler, 'surumler' => $surumler, 'gonderimler' => $g->fetchAll(PDO::FETCH_ASSOC),
            'gonderilebilir' => $ok, 'engel' => $ok ? null : 'Hasar henüz fiyatlandırılmadı; dosya müşteriye gönderilemez.',
            'alici_oneri' => ['telefon' => ($mu['telefon'] ?? null) ?: null, 'eposta' => ($mu['email'] ?? null) ?: null]];
}

function hbGonder(PDO $db, int $hid, string $kanal, string $alici): array {
    islemKilitAl($db);
    if (!in_array($kanal, ['whatsapp', 'eposta'], true)) throw new DomainException('Geçersiz kanal.', 400);
    $d = hbDosya($db, $hid);
    if (!$d['gonderilebilir']) throw new DomainException($d['engel'], 409);
    $alici = mb_substr(trim($alici), 0, 200);
    $tel = null;
    if ($kanal === 'whatsapp') {
        require_once __DIR__ . '/whatsapp.php';
        $tel = waTelefon($alici);
        if (!$tel) throw new DomainException('Geçerli bir telefon numarası girin.', 400);
    }
    if ($kanal === 'eposta' && !filter_var($alici, FILTER_VALIDATE_EMAIL)) throw new DomainException('Geçerli bir e-posta adresi girin.', 400);
    $pdf = $d['bicim'] === 'pdf';
    $token = $pdf ? null : bin2hex(random_bytes(32));
    $simdi = date('Y-m-d H:i:s');
    $bel = array_map(fn($b) => ['tur' => $b['tur'], 'no' => $b['no'], 'surum' => $b['surum']] + ($b['tur'] === 'IT' ? ['tutanak_id' => (int)$b['tutanak_id']] : []), $d['belgeler']);
    $db->prepare('INSERT INTO hasar_dosya_gonderimleri(degerlendirme_id,kiralama_id,kanal,alici,belgeler,token_hash,son_gecerlilik,gonderen,created_at,bicim) VALUES(?,?,?,?,?,?,?,?,?,?)')
       ->execute([$hid, $d['kiralama_id'], $kanal, $alici, json_encode($bel, JSON_UNESCAPED_UNICODE), $token !== null ? tokenOzet($token) : null,
                  $token !== null ? date('Y-m-d H:i:s', time() + HB_LINK_GUN * 86400) : null, (string)($_SESSION['username'] ?? 'Sistem'), $simdi, $pdf ? 'pdf' : 'baglanti']);
    $gid = (int)$db->lastInsertId();
    $nolar = implode(', ', array_map(fn($b) => $b['no'], $bel));
    require_once __DIR__ . '/musteri-erisim-lib.php';
    require_once __DIR__ . '/eposta.php';
    if ($pdf) {
        $erisim = meOtoDavet($db, $d['musteri_id'], null);
        $metin = 'Hasar dosyanız (' . $nolar . ') ekte PDF olarak gönderilmiştir.' . mePdfNotu($erisim);
        $out = ['gonderim_id' => $gid, 'bicim' => 'pdf', 'portal' => $erisim['durum']];
        if ($kanal === 'whatsapp') {
            require_once __DIR__ . '/belge-pdf-lib.php';
            $out += ['pdf_url' => 'api/hasar_dosya.php?pdf=' . $gid, 'dosya_adi' => hbPdfAdi($bel, $hid), 'metin' => $metin,
                     'wa_url' => 'https://wa.me/' . $tel . '?text=' . rawurlencode($metin)];
        } else {
            $e = epostaEkliGonder($db, $alici, 'Hasar dosyası — ' . $nolar, $metin, [hbPdf($db, $gid)], 'hasar_dosya:' . $hid);
            if (empty($e['basarili'])) throw new DomainException('E-posta gönderilemedi: ' . ($e['hata'] ?? 'bilinmeyen hata'), 400);
        }
    } else {
        $sema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || TRUSTED_HOST !== '' ? 'https' : 'http';
        $link = $sema . '://' . guvenliHost() . siteKokYolu() . '/hasar-dosyasi-goster.html?t=' . $token;
        $metin = 'Hasar dosyanız (' . $nolar . ') — ' . HB_LINK_GUN . ' gün geçerli bağlantı: ' . $link . '.' . meMesajNotu(['durum' => 'aktif']);
        $out = ['gonderim_id' => $gid, 'bicim' => 'baglanti', 'link' => $link, 'portal' => 'aktif'];
        if ($kanal === 'whatsapp') $out['wa_url'] = 'https://wa.me/' . $tel . '?text=' . rawurlencode($metin);
        else {
            $e = epostaGonder($db, $alici, 'Hasar dosyası — ' . $nolar, $metin, 'hasar_dosya:' . $hid);
            if (empty($e['basarili'])) throw new DomainException('E-posta gönderilemedi: ' . ($e['hata'] ?? 'bilinmeyen hata'), 400);
        }
    }
    hdGecmis($db, $hid, 'gonderim', 'Hasar dosyası gönderildi (' . ($kanal === 'whatsapp' ? 'WhatsApp' : 'e-posta') . ' → ' . $alici . ($pdf ? ', PDF' : '') . '): ' . $nolar . '.', ['gonderim_id' => $gid]);
    logla($db, 'hasar_dosya_gonderimleri', $gid, 'ekle', null, ['kanal' => $kanal, 'bicim' => $pdf ? 'pdf' : 'baglanti', 'alici' => $alici, 'belgeler' => $bel], 'Hasar dosyası gönderildi — Hasar #' . $hid);
    return $out;
}

function hbPdf(PDO $db, int $gid): array {
    require_once __DIR__ . '/belge-pdf-lib.php';
    $q = $db->prepare('SELECT * FROM hasar_dosya_gonderimleri WHERE id=?');
    $q->execute([$gid]);
    $g = $q->fetch(PDO::FETCH_ASSOC);
    if (!$g) throw new DomainException('Gönderim bulunamadı.', 404);
    $belgeler = _hbGonderimBelgeleri($db, $g);
    if (!$belgeler) throw new DomainException('Belge bulunamadı.', 404);
    return ['ad' => hbPdfAdi($belgeler, (int)$g['degerlendirme_id']), 'tur' => 'application/pdf', 'veri' => bpHasarDosyasiPdf($db, $belgeler)];
}

function hbPdfAdi(array $belgeler, int $hid): string {
    $hbb = array_values(array_filter($belgeler, fn($b) => ($b['tur'] ?? '') === 'HBB'))[0]['no'] ?? ('hasar-' . $hid);
    return bpDosyaAdi('Hasar-dosyasi-' . $hbb);
}

function _hbGonderimBelgeleri(PDO $db, array $g): array {
    $s = $db->prepare('SELECT tur, no, surum, icerik, created_at FROM hasar_belgeleri WHERE no=? AND surum=?');
    $belgeler = [];
    foreach (json_decode((string)$g['belgeler'], true) ?: [] as $b) {
        if (($b['tur'] ?? '') === 'IT') { if ($r = hbItBelge($db, (int)($b['tutanak_id'] ?? 0), (int)$b['surum'])) $belgeler[] = $r; continue; }
        $s->execute([$b['no'], (int)$b['surum']]);
        if ($r = $s->fetch(PDO::FETCH_ASSOC)) { $r['icerik'] = json_decode($r['icerik'], true) ?: []; $r['surum'] = (int)$r['surum']; $belgeler[] = $r; }
    }
    return $belgeler;
}

function hbBaglantiIptal(PDO $db, int $gid): void {
    $q = $db->prepare('SELECT * FROM hasar_dosya_gonderimleri WHERE id=?');
    $q->execute([$gid]);
    $g = $q->fetch(PDO::FETCH_ASSOC);
    if (!$g) throw new DomainException('Gönderim bulunamadı.', 404);
    if ((int)$g['iptal'] === 1) return;
    $db->prepare('UPDATE hasar_dosya_gonderimleri SET iptal=1 WHERE id=?')->execute([$gid]);
    hdGecmis($db, (int)$g['degerlendirme_id'], 'gonderim', 'Hasar dosyası bağlantısı iptal edildi (' . $g['kanal'] . ' → ' . $g['alici'] . ').', ['gonderim_id' => $gid]);
    logla($db, 'hasar_dosya_gonderimleri', $gid, 'guncelle', ['iptal' => 0], ['iptal' => 1], 'Hasar dosyası bağlantısı iptal edildi — Hasar #' . (int)$g['degerlendirme_id']);
}

function hbTokenCoz(PDO $db, string $token, bool $say = true): array {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) throw new DomainException('Bağlantı bulunamadı.', 404);
    $q = $db->prepare('SELECT * FROM hasar_dosya_gonderimleri WHERE token_hash=?');
    $q->execute([tokenOzet($token)]);
    $g = $q->fetch(PDO::FETCH_ASSOC);
    if (!$g || (int)$g['iptal'] === 1) throw new DomainException('Bağlantı bulunamadı.', 404);
    if ((string)$g['son_gecerlilik'] < date('Y-m-d H:i:s')) throw new DomainException('Bağlantının süresi doldu. Firmamızdan yeni bağlantı isteyin.', 410);
    if ($say) $db->prepare('UPDATE hasar_dosya_gonderimleri SET goruntulenme=goruntulenme+1, son_goruntulenme=? WHERE id=?')->execute([date('Y-m-d H:i:s'), (int)$g['id']]);
    return ['belgeler' => _hbGonderimBelgeleri($db, $g), 'son_gecerlilik' => $g['son_gecerlilik']];
}

function hbKontrolBelgesi(PDO $db, int $kontrolId): array {
    $q = $db->prepare("SELECT k.id AS kiralama_id, k.sube FROM depo_kontrolleri d JOIN kiralamalar k ON k.id=d.kiralama_id WHERE d.id=? AND d.yon='donus'");
    $q->execute([$kontrolId]);
    $k = $q->fetch(PDO::FETCH_ASSOC);
    $it = $k ? bzIadeTutanagiKontrol($db, $kontrolId) : null;
    $b = $it ? hbItBelge($db, (int)$it['id']) : null;
    if (!$b && $k && ($e = hbSon($db, 'DHT', $kontrolId) ?: hbSon($db, 'DDK', $kontrolId)))
        $b = ['tur' => $e['tur'], 'no' => $e['no'], 'surum' => (int)$e['surum'], 'created_at' => $e['created_at'], 'icerik' => $e['icerik']];
    if (!$b) throw new DomainException('Belge bulunamadı.', 404);
    return ['kiralama_id' => (int)$k['kiralama_id'], 'sube' => $k['sube'], 'belgeler' => [$b]];
}


function hbPortalDosya(PDO $db, int $hid, int $musteriId): array {
    try { $d = hbDosya($db, $hid); } catch (DomainException $e) { throw new DomainException('Belge bulunamadı.', 404); }
    if ($d['musteri_id'] !== $musteriId || !$d['gonderilebilir']) throw new DomainException('Belge bulunamadı.', 404);
    return ['belgeler' => $d['belgeler']];
}

function hbPortalListe(PDO $db, array $kiralamaIds): array {
    $ids = array_values(array_filter(array_map('intval', $kiralamaIds)));
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $dr = implode(',', array_fill(0, count(HB_FIYATLI), '?'));
    $s = $db->prepare("SELECT d.kiralama_id, d.id, MAX(b.created_at) AS tarih FROM hasar_degerlendirmeleri d
                       JOIN hasar_belgeleri b ON b.tur='HBB' AND b.kaynak_id=d.id
                       WHERE d.kiralama_id IN ($ph) AND d.depo_kontrol_id IS NOT NULL AND d.fiyat_durum IN ($dr)
                       GROUP BY d.kiralama_id, d.id ORDER BY d.id");
    $s->execute([...$ids, ...HB_FIYATLI]);
    $out = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['kiralama_id']][] = ['id' => (int)$r['id'], 'tarih' => $r['tarih']];
    return $out;
}

function hbFotoListesi(array $belgeler): array {
    $y = [];
    foreach ($belgeler as $b) {
        $i = $b['icerik'] ?? [];
        foreach ($i['fotolar'] ?? [] as $f) { $y[] = $f['gidis_yol'] ?? null; $y[] = $f['donus_yol'] ?? null; }
        foreach ($i['bulgular'] ?? [] as $x) foreach ($x['foto_yollar'] ?? [] as $p) $y[] = $p;
        foreach ($i['tamir_fotolari'] ?? [] as $p) $y[] = $p;
        foreach ($i['fotograflar'] ?? [] as $f) $y[] = $f['dosya_yolu'] ?? null;
        foreach ($i['karsi']['fotograflar'] ?? [] as $f) $y[] = $f['dosya_yolu'] ?? null;
        foreach ($i['saha_alim']['fotograflar'] ?? [] as $f) $y[] = $f['dosya_yolu'] ?? null;
        foreach ($i['depo']['fotolar'] ?? [] as $f) { $y[] = $f['gidis_yol'] ?? null; $y[] = $f['donus_yol'] ?? null; }
        foreach ($i['depo']['bulgular'] ?? [] as $x) foreach ($x['foto_yollar'] ?? [] as $p) $y[] = $p;
    }
    return array_values(array_unique(array_filter($y, fn($p) => is_string($p) && $p !== '')));
}

function hbFotoGonder(string $rel, array $izinli): void {
    $yok = function (): void { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('Bulunamadı.'); };
    if (!in_array($rel, $izinli, true) || str_contains($rel, '..') || str_contains($rel, '\\')) $yok();
    $gercek = realpath(UPLOAD_PATH . $rel);
    $kok = realpath(rtrim(UPLOAD_PATH, '/\\'));
    if ($gercek === false || $kok === false || !str_starts_with($gercek, $kok . DIRECTORY_SEPARATOR)) $yok();
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($gercek) ?: '';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) $yok();
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($gercek));
    header('Cache-Control: private, max-age=300');
    readfile($gercek);
    exit;
}
