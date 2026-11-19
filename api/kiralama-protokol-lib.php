<?php
declare(strict_types=1);
require_once __DIR__ . '/musteri-erisim-lib.php';

const KP_LINK_GUN = 30;
const KP_BAGLANTI_IP_SINIRI = 20;
const KP_VARSAYILAN_SARTLAR = "Kiralanan ekipman teslim alındığı durumda iade edilir. Hasar, kayıp ve gecikmeden müşteri sorumludur.\nÖdeme, fatura/hakediş tarihinden itibaren mutabık kalınan vadede yapılır.";
const KP_KIRA_TIPI = ['gunluk' => 'gün', 'haftalik' => 'hafta', 'aylik' => 'ay', 'yillik' => 'yıl'];
const KP_NAK_YON = ['gidis' => 'Gidiş', 'donus' => 'Dönüş', 'gidis_donus' => 'Gidiş + dönüş'];
const KP_ODEME = ['pesin' => 'Peşin', 'havale' => 'Havale / EFT', 'fatura' => 'Fatura', 'cek' => 'Çek', 'senet' => 'Senet', 'kredi_karti' => 'Kredi kartı'];
const KP_KANAL = ['saha' => 'sahada teslimde', 'baglanti' => 'bağlantıyla', 'portal' => 'müşteri portalında'];
const KP_GONDERIM_KANAL = ['whatsapp' => 'WhatsApp', 'eposta' => 'E-posta', 'kopya' => 'Bağlantı kopyalandı'];

function kpSema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS kiralama_protokolleri (
      id INTEGER PRIMARY KEY AUTOINCREMENT, kapsam TEXT NOT NULL, siparis_no TEXT, kiralama_idler TEXT NOT NULL, musteri_id INTEGER NOT NULL,
      sube TEXT, surum INTEGER NOT NULL, belge_no TEXT, icerik TEXT NOT NULL, icerik_ozet TEXT NOT NULL, veri_ozet TEXT NOT NULL,
      durum TEXT NOT NULL DEFAULT 'bekliyor', yeniden_imza INTEGER NOT NULL DEFAULT 0, onceki_id INTEGER,
      imza TEXT, imzalayan_ad TEXT, imzalayan_unvan TEXT, imza_zamani TEXT, imza_kanali TEXT, imza_kaynak TEXT, imza_ip TEXT, imza_ua TEXT,
      olusturan TEXT, created_at TEXT NOT NULL, iptal_zamani TEXT);
    CREATE INDEX IF NOT EXISTS idx_kp_kapsam ON kiralama_protokolleri(kapsam, id);
    CREATE INDEX IF NOT EXISTS idx_kp_musteri ON kiralama_protokolleri(musteri_id);
    CREATE TABLE IF NOT EXISTS kiralama_protokol_gonderimleri (
      id INTEGER PRIMARY KEY AUTOINCREMENT, protokol_id INTEGER NOT NULL, kapsam TEXT NOT NULL, kanal TEXT NOT NULL, alici TEXT,
      token_hash TEXT NOT NULL UNIQUE, son_gecerlilik TEXT NOT NULL, iptal INTEGER NOT NULL DEFAULT 0, goruntulenme INTEGER NOT NULL DEFAULT 0,
      son_goruntulenme TEXT, imzalandi INTEGER NOT NULL DEFAULT 0, hata TEXT, gonderen TEXT, created_at TEXT NOT NULL);
    CREATE INDEX IF NOT EXISTS idx_kpg_kapsam ON kiralama_protokol_gonderimleri(kapsam, id);");
    $kolon = array_column($db->query('PRAGMA table_info(kiralama_protokolleri)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['kv_imza', 'kv_imza_ad', 'kv_imza_unvan', 'kv_imza_kaynak'] as $k)
        if (!in_array($k, $kolon, true)) $db->exec("ALTER TABLE kiralama_protokolleri ADD COLUMN $k TEXT");
    if (!in_array('imza_hesapsiz', $kolon, true)) $db->exec("ALTER TABLE kiralama_protokolleri ADD COLUMN imza_hesapsiz INTEGER");
}

function kpBaglantiSinirda(PDO $db, string $ip): bool {
    $s = $db->prepare("SELECT COUNT(*) FROM checkin_attempts WHERE ip=? AND kaynak='protokol_baglanti' AND success=0
                       AND attempted_at >= datetime('now','-10 minutes','localtime')");
    $s->execute([$ip]);
    return (int)$s->fetchColumn() >= KP_BAGLANTI_IP_SINIRI;
}

function kpBaglantiDenemesi(PDO $db, string $ip, bool $basarili): void {
    $db->prepare("INSERT INTO checkin_attempts(personel_id, ip, success, kaynak) VALUES(0,?,?,'protokol_baglanti')")->execute([$ip, $basarili ? 1 : 0]);
}

function kpKirayaVeren(PDO $db, array $p): array {
    if (($p['durum'] ?? '') === 'imzalandi' && (string)($p['kv_imza_kaynak'] ?? '') !== '') {
        $imza = (string)($p['kv_imza'] ?? '');
        return ['imza' => $imza !== '' ? $imza : null, 'ad' => $p['kv_imza_ad'] ?? null, 'unvan' => $p['kv_imza_unvan'] ?? null, 'kaynak' => (string)$p['kv_imza_kaynak']];
    }
    require_once __DIR__ . '/tutanak-yaz.php';
    $ids = json_decode((string)($p['kiralama_idler'] ?? ''), true);
    return firmaImzasi($db, is_array($ids) ? (int)($ids[0] ?? 0) : 0);
}

function kpKapsam(PDO $db, int $kiralamaId): string {
    $s = $db->prepare('SELECT id, siparis_no FROM kiralamalar WHERE id=?');
    $s->execute([$kiralamaId]);
    $k = $s->fetch(PDO::FETCH_ASSOC);
    if (!$k) throw new DomainException('Kiralama bulunamadı.', 404);
    $sp = trim((string)($k['siparis_no'] ?? ''));
    return $sp !== '' ? 'S:' . $sp : 'K:' . (int)$k['id'];
}

function kpKiralamalar(PDO $db, string $kapsam): array {
    $sql = "SELECT k.*, m.unvan AS m_unvan, m.yetkili AS m_yetkili, m.telefon AS m_telefon, m.email AS m_email, m.adres AS m_adres,
                   m.vergi_dairesi AS m_vergi_dairesi, m.vergi_no AS m_vergi_no, m.il AS m_il, p.ad_soyad AS operator_ad
            FROM kiralamalar k JOIN musteriler m ON m.id=k.musteri_id LEFT JOIN personel p ON p.id=k.operator_id WHERE k.durum<>'iptal' AND ";
    if (str_starts_with($kapsam, 'S:')) { $s = $db->prepare($sql . 'k.siparis_no=? ORDER BY k.id'); $s->execute([substr($kapsam, 2)]); }
    else { $s = $db->prepare($sql . 'k.id=?'); $s->execute([(int)substr($kapsam, 2)]); }
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

function kpAyarlar(PDO $db): array {
    $k = ['firma_unvan', 'firma_adres', 'firma_vergi_dairesi', 'firma_vergi_no', 'firma_mersis', 'firma_telefon', 'firma_eposta', 'firma_web', 'kiralama_protokol_sartlari'];
    $out = array_fill_keys($k, '');
    $q = $db->prepare('SELECT anahtar, deger FROM ayarlar WHERE anahtar IN (' . implode(',', array_fill(0, count($k), '?')) . ')');
    $q->execute($k);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['anahtar']] = trim((string)$r['deger']);
    return $out;
}

function _kpYuvarla(float $n): float { return round($n, 2); }

function kpIcerikKur(PDO $db, string $kapsam): array {
    $rows = kpKiralamalar($db, $kapsam);
    if (!$rows) throw new DomainException('Protokole girecek kiralama yok (bütün makineler iptal edilmiş olabilir).', 404);
    if (count(array_unique(array_map(fn($r) => (int)$r['musteri_id'], $rows))) > 1) throw new DomainException('Siparişteki makineler farklı müşterilere ait; tek protokol üretilemez.', 409);
    $bilgi = makineBilgiToplu($db, array_map(fn($r) => ['tur' => $r['makine_tur'] ?? null, 'id' => (int)($r['makine_id'] ?? 0)], $rows));
    $kalemler = []; $nakliye = []; $toplam = []; $bas = []; $bit = []; $acikVar = false;
    foreach ($rows as $r) {
        $mb = $bilgi[($r['makine_tur'] ?? '') . ':' . (int)($r['makine_id'] ?? 0)] ?? [];
        $doviz = trim((string)($r['doviz'] ?? '')) ?: 'TL';
        $plan = $r; $plan['gercek_bitis'] = null;
        $acik = trim((string)($r['bitis'] ?? '')) === '';
        if ($acik) { $plan['birim_ucret'] = 0; $plan['operator_ucret'] = 0; $plan['bitis'] = $r['baslangic']; }
        $h = kiralamaTutar($plan);
        $acikVar = $acikVar || $acik;
        $tip = (string)($r['kira_tipi'] ?? 'gunluk');
        $kalemler[] = [
            'kiralama_id' => (int)$r['id'], 'kod' => (string)($r['kod'] ?? ''), 'ekipman_adi' => (string)($r['ekipman_adi'] ?? ''),
            'makine' => ['tur' => (string)($r['makine_tur'] ?? ''), 'id' => (int)($r['makine_id'] ?? 0), 'kod' => (string)($mb['kod'] ?? ''), 'marka' => (string)($mb['marka'] ?? ''),
                         'model' => (string)($mb['model'] ?? ''), 'kategori' => (string)($mb['kategori'] ?? ''),
                         'metraj' => metrajSayi($r['metraj'] ?? null) ?? ($mb['metraj'] ?? null), 'olcu_birim' => (string)($mb['olcu_birim'] ?? 'm'),
                         'seri_no' => trim((string)($r['seri_no'] ?? '')) ?: (string)($mb['seri_no'] ?? ''),
                         'makine_kodu' => trim((string)($r['makine_kodu'] ?? '')) ?: (string)($mb['makine_kodu'] ?? '')],
            'kira_tipi' => $tip, 'birim' => KP_KIRA_TIPI[$tip] ?? 'gün', 'birim_ucret' => _kpYuvarla((float)($r['birim_ucret'] ?? 0)),
            'miktar' => max(1, (int)($r['miktar'] ?? 1)), 'baslangic' => (string)$r['baslangic'], 'bitis' => $acik ? null : (string)$r['bitis'],
            'acik' => $acik, 'gun' => $acik ? null : (int)$h['gun'], 'sure' => $acik ? null : (float)$h['sure'],
            'kira_bedeli' => $acik ? null : (float)$h['kira_bedeli'], 'iskonto_oran' => (float)$h['iskonto_oran'], 'iskonto_tutar' => $acik ? null : (float)$h['iskonto_tutar'],
            'operatorlu' => !empty($r['operatorlu']), 'operator_ad' => (string)($r['operator_ad'] ?? ''), 'operator_bedeli' => $acik ? null : (float)$h['operator_bedeli'],
            'lokasyon' => trim((string)($r['lokasyon'] ?? '')), 'il' => trim((string)($r['il'] ?? '')), 'doviz' => $doviz, 'kdv_oran' => (float)($r['kdv_oran'] ?? 20),
        ];
        if ((float)$h['nakliye'] > 0) $nakliye[] = ['kiralama_id' => (int)$r['id'], 'kod' => (string)($r['kod'] ?? ''),
            'yon' => KP_NAK_YON[(string)($r['nakliye_yon'] ?? '')] ?? 'Gidiş + dönüş', 'tutar' => (float)$h['nakliye'], 'doviz' => $doviz];
        $t = $toplam[$doviz] ?? ['ara_toplam' => 0.0, 'kdv' => 0.0, 'genel_toplam' => 0.0];
        $toplam[$doviz] = ['ara_toplam' => _kpYuvarla($t['ara_toplam'] + $h['ara_toplam']), 'kdv' => _kpYuvarla($t['kdv'] + $h['kdv']),
                           'genel_toplam' => _kpYuvarla($t['genel_toplam'] + $h['genel_toplam'])];
        $bas[] = (string)$r['baslangic'];
        if (!$acik) $bit[] = (string)$r['bitis'];
    }
    $ortak = function (string $alan) use ($rows): string {
        $v = array_values(array_unique(array_map(fn($r) => trim((string)($r[$alan] ?? '')), $rows)));
        return count($v) === 1 ? $v[0] : '';
    };
    $odeme = $ortak('odeme_turu'); $vade = $ortak('vade_gun');
    $ilk = $rows[0];
    $a = kpAyarlar($db);
    return [
        'bicim' => 1, 'kapsam' => $kapsam, 'baslik' => 'KİRALAMA PROTOKOLÜ',
        'firma' => ['unvan' => $a['firma_unvan'], 'adres' => $a['firma_adres'], 'vergi_dairesi' => $a['firma_vergi_dairesi'], 'vergi_no' => $a['firma_vergi_no'],
                    'mersis' => $a['firma_mersis'], 'telefon' => $a['firma_telefon'], 'eposta' => $a['firma_eposta'], 'web' => $a['firma_web']],
        'musteri' => ['unvan' => (string)$ilk['m_unvan'], 'yetkili' => (string)($ilk['m_yetkili'] ?? ''), 'telefon' => (string)($ilk['m_telefon'] ?? ''),
                      'eposta' => (string)($ilk['m_email'] ?? ''), 'adres' => (string)($ilk['m_adres'] ?? ''), 'vergi_dairesi' => (string)($ilk['m_vergi_dairesi'] ?? ''),
                      'vergi_no' => (string)($ilk['m_vergi_no'] ?? ''), 'il' => (string)($ilk['m_il'] ?? '')],
        'veri' => [
            'siparis_no' => str_starts_with($kapsam, 'S:') ? substr($kapsam, 2) : '',
            'santiye' => $ortak('lokasyon'), 'baslangic' => min($bas), 'bitis' => $acikVar || !$bit ? null : max($bit), 'acik_var' => $acikVar,
            'odeme' => $odeme !== '' ? (KP_ODEME[$odeme] ?? $odeme) : '', 'vade_gun' => (int)$vade, 'sube' => (string)($ilk['sube'] ?? ''),
            'kalemler' => $kalemler, 'nakliye' => $nakliye, 'toplamlar' => $toplam,
        ],
        'sartlar' => $a['kiralama_protokol_sartlari'] !== '' ? $a['kiralama_protokol_sartlari'] : KP_VARSAYILAN_SARTLAR,
    ];
}

function kpJson(array $v): string { return (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION); }

function kpSon(PDO $db, string $kapsam): ?array {
    $s = $db->prepare("SELECT * FROM kiralama_protokolleri WHERE kapsam=? AND durum<>'iptal' ORDER BY id DESC LIMIT 1");
    $s->execute([$kapsam]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function kpGetir(PDO $db, int $id): array {
    kpSema($db);
    $s = $db->prepare('SELECT * FROM kiralama_protokolleri WHERE id=?');
    $s->execute([$id]);
    $p = $s->fetch(PDO::FETCH_ASSOC);
    if (!$p) throw new DomainException('Protokol bulunamadı.', 404);
    return $p;
}

function _kpImzaOzu(array $veri): array {
    foreach ($veri['kalemler'] ?? [] as $i => $k) {
        unset($veri['kalemler'][$i]['operator_ad']);
        foreach (['kod', 'marka', 'model', 'kategori', 'olcu_birim', 'makine_kodu'] as $alan) unset($veri['kalemler'][$i]['makine'][$alan]);
    }
    return $veri;
}

function kpHesapla(PDO $db, string $kapsam): array {
    $icerik = kpIcerikKur($db, $kapsam);
    return [$icerik, hash('sha256', kpJson($icerik)), hash('sha256', kpJson(_kpImzaOzu($icerik['veri'])))];
}

function _kpEslesir(?array $son, string $oz, string $voz): bool {
    return $son !== null && (($son['durum'] === 'bekliyor' && $son['icerik_ozet'] === $oz) || ($son['durum'] === 'imzalandi' && $son['veri_ozet'] === $voz));
}

function kpCoz(PDO $db, string $kapsam): array {
    kpSema($db);
    [$icerik, $oz, $voz] = kpHesapla($db, $kapsam);
    $son = kpSon($db, $kapsam);
    if (_kpEslesir($son, $oz, $voz)) return $son;
    $q = $db->prepare("SELECT MAX(surum) AS s, MIN(belge_no) AS no, SUM(durum='imzalandi') AS imzali FROM kiralama_protokolleri WHERE kapsam=?");
    $q->execute([$kapsam]);
    $o = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    $ids = array_map(fn($k) => (int)$k['kiralama_id'], $icerik['veri']['kalemler']);
    $mid = (int)$db->query('SELECT musteri_id FROM kiralamalar WHERE id=' . (int)$ids[0])->fetchColumn();
    return ['id' => null, 'kapsam' => $kapsam, 'siparis_no' => $icerik['veri']['siparis_no'] ?: null, 'kiralama_idler' => kpJson($ids), 'musteri_id' => $mid,
            'sube' => $icerik['veri']['sube'] ?: null, 'surum' => (int)($o['s'] ?? 0) + 1, 'belge_no' => (string)($o['no'] ?? '') ?: 'Yeni protokol',
            'icerik' => kpJson($icerik), 'icerik_ozet' => $oz, 'veri_ozet' => $voz, 'durum' => 'bekliyor', 'yeniden_imza' => (int)($o['imzali'] ?? 0) > 0 ? 1 : 0,
            'onceki_id' => $son ? (int)$son['id'] : null, 'imza' => null, 'imzalayan_ad' => null, 'imzalayan_unvan' => null, 'imza_zamani' => null,
            'imza_kanali' => null, 'imza_kaynak' => null, 'imza_ip' => null, 'imza_ua' => null, 'olusturan' => null, 'created_at' => '', 'iptal_zamani' => null];
}

function kpGuncel(PDO $db, string $kapsam, string $yapan = 'Sistem'): array {
    kpSema($db);
    [$icerik, $oz, $voz] = kpHesapla($db, $kapsam);
    $son = kpSon($db, $kapsam);
    if (_kpEslesir($son, $oz, $voz)) return $son;
    $tx = !$db->inTransaction();
    if ($tx) $db->beginTransaction();
    try {
        $son = kpSon($db, $kapsam);
        if (_kpEslesir($son, $oz, $voz)) {
            if ($tx) $db->commit();
            return $son;
        }
        if ($son && $son['durum'] === 'bekliyor')
            $db->prepare("UPDATE kiralama_protokolleri SET durum='iptal', iptal_zamani=? WHERE id=?")->execute([date('Y-m-d H:i:s'), (int)$son['id']]);
        $q = $db->prepare("SELECT MAX(surum) AS s, MIN(belge_no) AS no, SUM(durum='imzalandi') AS imzali FROM kiralama_protokolleri WHERE kapsam=?");
        $q->execute([$kapsam]);
        $o = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        $yeniden = (int)($o['imzali'] ?? 0) > 0 ? 1 : 0;
        $ids = array_map(fn($k) => (int)$k['kiralama_id'], $icerik['veri']['kalemler']);
        $mid = (int)$db->query('SELECT musteri_id FROM kiralamalar WHERE id=' . (int)$ids[0])->fetchColumn();
        $db->prepare('INSERT INTO kiralama_protokolleri(kapsam,siparis_no,kiralama_idler,musteri_id,sube,surum,icerik,icerik_ozet,veri_ozet,durum,yeniden_imza,onceki_id,olusturan,created_at)
                      VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$kapsam, $icerik['veri']['siparis_no'] ?: null, kpJson($ids), $mid, $icerik['veri']['sube'] ?: null, (int)($o['s'] ?? 0) + 1,
                      kpJson($icerik), $oz, $voz, 'bekliyor', $yeniden, $son ? (int)$son['id'] : null, mb_substr($yapan, 0, 120), date('Y-m-d H:i:s')]);
        $id = (int)$db->lastInsertId();
        $no = (string)($o['no'] ?? '') ?: 'KP-' . date('Y') . '-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
        $db->prepare('UPDATE kiralama_protokolleri SET belge_no=? WHERE id=?')->execute([$no, $id]);
        logla($db, 'kiralama_protokolleri', $id, 'ekle', null, ['kapsam' => $kapsam, 'surum' => (int)($o['s'] ?? 0) + 1, 'kiralamalar' => $ids, 'yeniden_imza' => $yeniden],
              'Kiralama protokolü ' . $no . ' sürüm ' . ((int)($o['s'] ?? 0) + 1) . ' üretildi'
              . ($yeniden ? ' — sipariş imzadan sonra değişti, yeniden imza gerekir' : ($son ? ' — sipariş değişti, önceki imzasız sürüm geçersiz' : '')));
        if ($tx) $db->commit();
    } catch (Throwable $e) {
        if ($tx && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return kpGetir($db, $id);
}

function kpImzaGecerli(string $v): bool {
    if (strlen($v) > 400000 || !preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $v, $m)) return false;
    $ham = base64_decode($m[1], true);
    return $ham !== false && strlen($ham) > 100 && str_starts_with($ham, "\x89PNG\r\n\x1a\n");
}

function kpImzala(PDO $db, int $pid, string $ozet, string $imza, string $ad, string $unvan, string $kanal, string $kaynak, string $ip, string $ua): array {
    kpSema($db);
    $ad = trim(preg_replace('/\s+/u', ' ', $ad) ?? '');
    $unvan = trim(preg_replace('/\s+/u', ' ', $unvan) ?? '');
    if (!isset(KP_KANAL[$kanal])) throw new InvalidArgumentException('Geçersiz imza kanalı.');
    if (mb_strlen($ad) < 3 || mb_strlen($ad) > 120) throw new InvalidArgumentException('İmzalayanın adını ve soyadını yazın.');
    if (mb_strlen($unvan) > 120) throw new InvalidArgumentException('Unvan en fazla 120 karakter olabilir.');
    if ($kanal === 'baglanti' && mb_strlen($unvan) < 2) throw new InvalidArgumentException('Unvanınızı ya da görevinizi yazın.');
    if (!kpImzaGecerli($imza)) throw new InvalidArgumentException('İmza okunamadı. İmza alanına yeniden imzalayın.');
    $p = kpGetir($db, $pid);
    if ($p['durum'] === 'imzalandi') throw new DomainException('Bu protokol zaten imzalandı.', 409);
    $kv = kpKirayaVeren($db, $p);
    $u = $db->prepare("UPDATE kiralama_protokolleri SET durum='imzalandi', imza=?, imzalayan_ad=?, imzalayan_unvan=?, imza_zamani=?, imza_kanali=?, imza_kaynak=?, imza_ip=?, imza_ua=?,
                       kv_imza=?, kv_imza_ad=?, kv_imza_unvan=?, kv_imza_kaynak=?, imza_hesapsiz=? WHERE id=? AND durum='bekliyor' AND icerik_ozet=?");
    $u->execute([$imza, $ad, $unvan !== '' ? $unvan : null, date('Y-m-d H:i:s'), $kanal, mb_substr($kaynak, 0, 200), mb_substr($ip, 0, 45), mb_substr($ua, 0, 300),
                 $kv['imza'], $kv['ad'], $kv['unvan'], $kv['imza'] !== null ? (string)$kv['kaynak'] : 'yok', $kanal === 'baglanti' ? 1 : null, $pid, $ozet]);
    if ($u->rowCount() !== 1) throw new DomainException('Protokol bu arada güncellendi ya da imzalandı; sayfayı yenileyip güncel hâlini okuyun.', 409);
    $kullanilanGonderim = preg_match('/gonderim:(\d+)/', $kaynak, $gm) ? (int)$gm[1] : 0;
    $db->prepare("UPDATE kiralama_protokol_gonderimleri SET iptal=1 WHERE kapsam=? AND imzalandi=0 AND iptal=0 AND id<>?")->execute([(string)$p['kapsam'], $kullanilanGonderim]);
    $ilkKid = (int)((json_decode((string)$p['kiralama_idler'], true) ?: [0])[0] ?? 0);
    $db->prepare("UPDATE gorevler SET durum='tamamlandi', tamamlanma=?, updated_at=datetime('now','localtime') WHERE kaynak_modul='kiralama_protokol' AND kaynak_id=? AND durum='acik'")
       ->execute([date('Y-m-d'), $ilkKid]);
    logla($db, 'kiralama_protokolleri', $pid, 'guncelle', ['durum' => 'bekliyor'], ['durum' => 'imzalandi', 'imzalayan' => $ad, 'unvan' => $unvan, 'kanal' => $kanal, 'ozet' => $ozet,
          'kiraya_veren_imza' => $kv['imza'] !== null ? trim(($kv['ad'] ?? '') . ' (' . $kv['kaynak'] . ')') : 'yok'],
          'Kiralama protokolü ' . $p['belge_no'] . ' imzalandı (' . KP_KANAL[$kanal] . ') — ' . $ad . ($unvan !== '' ? ', ' . $unvan : ''));
    return kpGetir($db, $pid);
}

function kpImzalaKapsam(PDO $db, string $kapsam, string $ozet, string $imza, string $ad, string $unvan, string $kanal, string $kaynak, string $yapan): array {
    if (!preg_match('/^[a-f0-9]{64}$/', $ozet)) throw new InvalidArgumentException('Protokol özeti eksik; sayfayı yenileyin.');
    $p = kpGuncel($db, $kapsam, $yapan);
    if ($p['durum'] === 'imzalandi') throw new DomainException('Bu protokol zaten imzalandı.', 409);
    if ($p['icerik_ozet'] !== $ozet) throw new DomainException('Protokol ekranda gösterildikten sonra değişti; güncel hâli okunmadan imzalanamaz.', 409);
    return kpImzala($db, (int)$p['id'], $ozet, $imza, $ad, $unvan, $kanal, $kaynak, istekIp(), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

function kpTeslimKapsamlari(PDO $db, array $kiralamaIdler): array {
    $out = [];
    foreach (array_slice(array_values(array_unique(array_map('intval', $kiralamaIdler))), 0, 20) as $kid) {
        if ($kid > 0) { try { $out[kpKapsam($db, $kid)] = $kid; } catch (DomainException $e) {} }
    }
    return $out;
}

function kpSahaOnizleme(PDO $db, array $kiralamaIdler): array {
    $out = [];
    foreach (array_keys(kpTeslimKapsamlari($db, $kiralamaIdler)) as $kapsam) {
        try {
            $p = kpCoz($db, $kapsam);
            $out[] = kpOzetSatir($p) + ['html' => kpHtml($p, kpLogo($db), kpKirayaVeren($db, $p))];
        } catch (DomainException $e) { $out[] = ['kapsam' => $kapsam, 'hata' => $e->getMessage()]; }
    }
    return $out;
}

function kpSahadaImzala(PDO $db, array $kiralamaIdler, $onaylar, string $imza, string $ad, string $unvan, string $kaynak, string $yapan): array {
    $sonuc = ['imzalanan' => [], 'uyarilar' => []];
    $izinli = kpTeslimKapsamlari($db, $kiralamaIdler);
    foreach (array_slice(is_array($onaylar) ? $onaylar : [], 0, 10) as $o) {
        $kapsam = is_array($o) && is_string($o['kapsam'] ?? null) ? $o['kapsam'] : '';
        $ozet = is_array($o) && is_string($o['ozet'] ?? null) ? $o['ozet'] : '';
        try {
            if (!isset($izinli[$kapsam])) throw new DomainException('Protokol bu teslimde teslim edilen makinelerin siparişine ait değil.', 403);
            $r = kpImzalaKapsam($db, $kapsam, $ozet, $imza, $ad, $unvan, 'saha', $kaynak, $yapan);
            $sonuc['imzalanan'][] = (string)$r['belge_no'];
        } catch (Throwable $e) {
            $sonuc['uyarilar'][] = 'Kiralama protokolü imzalanamadı: ' . ($e instanceof DomainException || $e instanceof InvalidArgumentException ? $e->getMessage() : 'beklenmeyen hata')
                . ' Ofis protokolü müşteriye bağlantıyla imzalatabilir.';
            if (!($e instanceof DomainException || $e instanceof InvalidArgumentException)) error_log('kpSahadaImzala: ' . $e->getMessage());
        }
    }
    return $sonuc;
}

function kpImzasizGorev(PDO $db, array $kiralamaIdler, string $neden): array {
    $acilan = [];
    foreach (kpTeslimKapsamlari($db, $kiralamaIdler) as $kapsam => $kid) {
        try { $p = kpCoz($db, $kapsam); } catch (DomainException $e) { continue; }
        if ($p['durum'] === 'imzalandi') continue;
        $ic = json_decode((string)$p['icerik'], true) ?: [];
        $ilk = (int)($ic['veri']['kalemler'][0]['kiralama_id'] ?? $kid);
        $ad = ($ic['veri']['siparis_no'] ?? '') !== '' ? $ic['veri']['siparis_no'] . ' siparişi' : ((($ic['veri']['kalemler'][0]['kod'] ?? '') ?: '#' . $ilk));
        $acilan[] = gorevOlustur($db, 'Protokol imzalanmadı — ' . ($ic['musteri']['unvan'] ?? '') . ' · ' . $ad,
            $neden . ' Müşteriye portal bağlantısı gönderin: Kiralama Takip → kiralama ayrıntısı → Kiralama Protokolü → Gönder.', 'kiralama_protokol', $ilk, 'normal');
    }
    return $acilan;
}

function kpDurumEtiket(array $p): string {
    if ($p['durum'] === 'imzalandi') return 'İmzalandı';
    if ($p['durum'] === 'iptal') return 'Geçersiz (yerine yeni sürüm)';
    return (int)$p['yeniden_imza'] ? 'Yeniden imza gerekir' : 'İmza bekliyor';
}

function kpOzetSatir(array $p): array {
    $ic = json_decode((string)$p['icerik'], true) ?: [];
    return ['id' => $p['id'] !== null ? (int)$p['id'] : null, 'kapsam' => (string)$p['kapsam'], 'siparis_no' => (string)($p['siparis_no'] ?? ''), 'belge_no' => (string)$p['belge_no'],
            'surum' => (int)$p['surum'], 'durum' => (string)$p['durum'], 'yeniden_imza' => (int)$p['yeniden_imza'], 'durum_etiket' => kpDurumEtiket($p),
            'ozet' => (string)$p['icerik_ozet'], 'musteri_id' => (int)$p['musteri_id'], 'musteri_unvan' => (string)($ic['musteri']['unvan'] ?? ''),
            'makine_adet' => count($ic['veri']['kalemler'] ?? []), 'kiralama_idler' => json_decode((string)$p['kiralama_idler'], true) ?: [],
            'imzalayan_ad' => $p['imzalayan_ad'], 'imzalayan_unvan' => $p['imzalayan_unvan'], 'imza_zamani' => $p['imza_zamani'],
            'imza_kanali' => $p['imza_kanali'], 'imza_kanal_ad' => KP_KANAL[(string)$p['imza_kanali']] ?? null, 'created_at' => (string)$p['created_at']];
}

function _kpE(mixed $v): string { return htmlspecialchars(is_scalar($v) ? (string)$v : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function kpTarih(?string $d): string { return $d && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m) ? $m[3] . '.' . $m[2] . '.' . $m[1] : '—'; }
function kpTarihSaat(?string $d): string { return $d ? kpTarih($d) . (strlen($d) >= 16 ? ' ' . substr($d, 11, 5) : '') : '—'; }
function kpPara(mixed $n, string $pb): string { return number_format((float)$n, 2, ',', '.') . ' ' . ($pb ?: 'TL'); }
function kpSayi(mixed $n): string { return rtrim(rtrim(number_format((float)$n, 2, ',', '.'), '0'), ','); }

function kpMakineKimlik(array $k): array {
    $m = is_array($k['makine'] ?? null) ? $k['makine'] : [];
    $t = fn(string $a): string => trim(is_scalar($m[$a] ?? null) ? (string)$m[$a] : '');
    $ust = implode(' · ', array_filter([$t('kod'), trim($t('marka') . ' ' . $t('model'))], fn($x) => $x !== ''));
    $ad = trim((string)($k['ekipman_adi'] ?? ''));
    $sn = $t('seri_no');
    $alt = array_values(array_filter([
        $ust !== '' && $ad !== $ust ? $ad : '', $t('kategori'),
        $sn !== '' ? (preg_match('/^sn/i', $sn) ? $sn : 'SN ' . $sn) : '',
        ($m['metraj'] ?? null) !== null && $m['metraj'] !== '' ? kpSayi($m['metraj']) . ' ' . ($t('olcu_birim') ?: 'm') : '',
        $t('makine_kodu') !== '' ? 'Stok kodu ' . $t('makine_kodu') : ''], fn($x) => $x !== ''));
    return ['ust' => $ust !== '' ? $ust : ($ad !== '' ? $ad : 'Makine'), 'alt' => $alt];
}

function kpKunye(array $k): string {
    $kim = kpMakineKimlik($k);
    return implode(' · ', array_merge([$kim['ust']], $kim['alt']));
}

function kpSatirlar(array $ic): array {
    $v = $ic['veri'] ?? [];
    $out = [];
    foreach ($v['kalemler'] ?? [] as $k) {
        $kim = kpMakineKimlik($k);
        $ek = array_filter(['Kiralama ' . ($k['kod'] ?: '#' . $k['kiralama_id']), $k['lokasyon'] !== '' ? 'Şantiye: ' . $k['lokasyon'] : '',
              $k['operatorlu'] ? 'Operatörlü' . ($k['operator_ad'] !== '' ? ' (' . $k['operator_ad'] . ')' : '') . ($k['operator_bedeli'] ? ': ' . kpPara($k['operator_bedeli'], $k['doviz']) : '') : 'Operatörsüz']);
        $out[] = ['tur' => 'makine', 'ust' => $kim['ust'], 'alt' => implode(' · ', $kim['alt']), 'ek' => implode(' · ', $ek),
                  'birim' => kpPara($k['birim_ucret'], $k['doviz']) . ' / ' . $k['birim'],
                  'birim_alt' => implode(' · ', array_filter([$k['miktar'] > 1 ? '× ' . $k['miktar'] . ' adet' : '', $k['iskonto_oran'] > 0 ? '%' . kpSayi($k['iskonto_oran']) . ' indirim' : ''])),
                  'sure' => kpTarih($k['baslangic']) . ' → ' . ($k['acik'] ? 'açık uçlu' : kpTarih($k['bitis'])),
                  'sure_alt' => $k['acik'] ? '' : ($k['birim'] !== 'gün' ? kpSayi($k['sure']) . ' ' . $k['birim'] . ', ' : '') . $k['gun'] . ' gün',
                  'tutar' => $k['acik'] ? 'Birim fiyat üzerinden' : kpPara((float)$k['kira_bedeli'] + (float)$k['operator_bedeli'], $k['doviz'])];
    }
    foreach ($v['nakliye'] ?? [] as $n) $out[] = ['tur' => 'nakliye', 'ust' => 'Nakliye — ' . $n['yon'], 'alt' => 'Kiralama ' . ($n['kod'] ?: '#' . $n['kiralama_id']), 'ek' => '',
                                                   'birim' => '', 'birim_alt' => '', 'sure' => '', 'sure_alt' => '', 'tutar' => kpPara($n['tutar'], $n['doviz'])];
    return $out;
}

function kpBilgiler(array $ic): array {
    $f = $ic['firma'] ?? []; $m = $ic['musteri'] ?? []; $v = $ic['veri'] ?? [];
    $vd = fn($d, $n) => trim(implode(' / ', array_filter([(string)$d, (string)$n])));
    return [
        'Kiraya Veren Bilgileri' => [['Ünvan / Firma', $f['unvan'] ?? ''], ['Adres', $f['adres'] ?? ''], ['Vergi Dairesi / No', $vd($f['vergi_dairesi'] ?? '', $f['vergi_no'] ?? '')],
                           ['Mersis No', $f['mersis'] ?? ''], ['Telefon', $f['telefon'] ?? ''], ['E-posta', $f['eposta'] ?? ''], ['Web', $f['web'] ?? '']],
        'Kiracı Bilgileri' => [['Müşteri', $m['unvan'] ?? ''], ['Yetkili', $m['yetkili'] ?? ''], ['Telefon', $m['telefon'] ?? ''], ['E-posta', $m['eposta'] ?? ''],
                               ['Vergi Dairesi / No', $vd($m['vergi_dairesi'] ?? '', $m['vergi_no'] ?? '')], ['Adres', trim(implode(' / ', array_filter([$m['adres'] ?? '', $m['il'] ?? ''])))]],
        'Kiralama Bilgileri' => [['Sipariş No', $v['siparis_no'] ?? ''], ['Makine Sayısı', (string)count($v['kalemler'] ?? [])],
                       ['Başlangıç', kpTarih($v['baslangic'] ?? null)], ['Bitiş', ($v['bitis'] ?? null) ? kpTarih($v['bitis']) : 'Açık uçlu / satırlarda'],
                       ['Ödeme', ($v['odeme'] ?? '') !== '' ? $v['odeme'] : 'Satırlara göre'], ['Vade', ($v['vade_gun'] ?? 0) > 0 ? $v['vade_gun'] . ' gün' : ''],
                       ['Şantiye / Lokasyon', ($v['santiye'] ?? '') !== '' ? $v['santiye'] : 'Her makinenin satırında']],
    ];
}

function kpSartParcalari(string $metin): array {
    $out = [];
    foreach (preg_split('/\r?\n/', trim($metin)) ?: [] as $s) {
        $s = trim($s);
        if ($s === '') continue;
        $out[] = [$s, mb_strlen($s) <= 70 && !preg_match('/[.;:,]$/u', $s) && (preg_match('/^\d+(\.\d+)*[.)]?\s+\S/u', $s) || preg_match('/^[A-ZÇĞİÖŞÜ0-9 ()\/&-]{4,}$/u', $s))];
    }
    return $out;
}

function kpLogo(PDO $db): string {
    $q = $db->prepare("SELECT deger FROM ayarlar WHERE anahtar='firma_logo'");
    $q->execute();
    $l = trim((string)$q->fetchColumn());
    return preg_match('~^firma/logo\.(png|jpe?g|webp|svg)$~i', $l) && is_file(UPLOAD_PATH . $l) ? 'uploads/' . TENANT_SEG . $l : '';
}

function kpImzaKanit(array $p, ?PDO $db = null): ?string {
    if (($p['durum'] ?? '') !== 'imzalandi') return null;
    $kanal = KP_KANAL[(string)($p['imza_kanali'] ?? '')] ?? '';
    $bas = (int)($p['imza_hesapsiz'] ?? 0) === 1 ? 'Bağlantıyla imzalandı (hesapsız)'
         : ($kanal !== '' ? mb_strtoupper(mb_substr($kanal, 0, 1)) . mb_substr($kanal, 1) . ' imzalandı' : 'İmzalandı');
    $ek = [];
    if ($db && preg_match('/gonderim:(\d+)/', (string)($p['imza_kaynak'] ?? ''), $gm)) {
        $q = $db->prepare('SELECT kanal, gonderen FROM kiralama_protokol_gonderimleri WHERE id=?');
        $q->execute([(int)$gm[1]]);
        if ($g = $q->fetch(PDO::FETCH_ASSOC)) {
            $ek[] = 'Gönderim: ' . (['whatsapp' => 'WhatsApp', 'eposta' => 'e-posta', 'kopya' => 'kopyalanan bağlantı'][$g['kanal']] ?? $g['kanal'])
                  . (trim((string)$g['gonderen']) !== '' ? ', gönderen: ' . trim((string)$g['gonderen']) : '');
        }
    }
    $ip = trim((string)($p['imza_ip'] ?? ''));
    if ($db && $ip !== '' && (int)($p['imza_hesapsiz'] ?? 0) === 1) {
        $q = $db->prepare("SELECT 1 FROM login_attempts WHERE ip=? AND success=1 AND attempted_at >= datetime('now','localtime','-30 days') LIMIT 1");
        $q->execute([$ip]);
        if ($q->fetchColumn()) $ek[] = 'Dikkat: personel ağından imzalandı';
    }
    return implode(' · ', array_filter(array_merge([$bas, $ip, kpTarihSaat($p['imza_zamani'] ?? null)], $ek), fn($x) => $x !== '' && $x !== '—'));
}

function kpSozlesmeImzasi(PDO $db, int $kiralamaId): ?array {
    if ($kiralamaId < 1) return null;
    kpSema($db);
    $q = $db->prepare("SELECT p.* FROM kiralama_protokolleri p WHERE p.durum='imzalandi' AND p.musteri_id=(SELECT musteri_id FROM kiralamalar WHERE id=?)
                       AND EXISTS (SELECT 1 FROM json_each(CASE WHEN json_valid(p.kiralama_idler) THEN p.kiralama_idler ELSE '[]' END) j WHERE CAST(j.value AS INTEGER)=?)
                       ORDER BY p.id DESC LIMIT 1");
    $q->execute([$kiralamaId, $kiralamaId]);
    $p = $q->fetch(PDO::FETCH_ASSOC);
    if (!$p || !kpImzaGecerli((string)$p['imza'])) return null;
    $yeni = $db->prepare("SELECT 1 FROM kiralama_protokolleri WHERE kapsam=? AND id>? AND durum='bekliyor' LIMIT 1");
    $yeni->execute([(string)$p['kapsam'], (int)$p['id']]);
    if ($yeni->fetchColumn()) return null;
    return ['protokol_id' => (int)$p['id'], 'belge_no' => (string)$p['belge_no'], 'surum' => (int)$p['surum'], 'imza' => (string)$p['imza'],
            'ad' => (string)$p['imzalayan_ad'], 'unvan' => (string)($p['imzalayan_unvan'] ?? ''), 'tarih' => (string)$p['imza_zamani'],
            'hesapsiz' => (int)($p['imza_hesapsiz'] ?? 0) === 1];
}

function kpDurumMetni(array $p): string {
    return $p['durum'] === 'imzalandi'
        ? 'İmzalandı — ' . kpTarihSaat($p['imza_zamani']) . ' · ' . $p['imzalayan_ad'] . ($p['imzalayan_unvan'] ? ' (' . $p['imzalayan_unvan'] . ')' : '') . ' · ' . (KP_KANAL[(string)$p['imza_kanali']] ?? '')
        : kpDurumEtiket($p) . ((int)$p['yeniden_imza'] && $p['durum'] === 'bekliyor' ? ' — sipariş önceki imzadan sonra değişti; güncel hâli aşağıdadır.' : '');
}

function kpHtml(array $p, string $logo = '', ?array $kv = null): string {
    $ic = json_decode((string)$p['icerik'], true) ?: [];
    $v = $ic['veri'] ?? [];
    $f = $ic['firma'] ?? [];
    $durum = (string)$p['durum'];
    $imzali = $durum === 'imzalandi';
    $sinif = $imzali ? 'kp-ok' : ($durum === 'iptal' ? 'kp-gec' : 'kp-bek');
    $alt = 'Sürüm ' . (int)$p['surum'] . (($v['siparis_no'] ?? '') !== '' ? ' · Sipariş ' . $v['siparis_no'] : '');
    $h = '<div class="bt-belge kp-belge"><div class="pa-head bt-head" data-bt-firma="' . _kpE($f['unvan'] ?? '') . '" data-bt-baslik="' . _kpE($ic['baslik'] ?? 'KİRALAMA PROTOKOLÜ') . '" data-bt-no="' . _kpE($p['belge_no']) . '">'
        . '<div class="bt-kimlik">' . ($logo !== '' ? '<img src="' . _kpE($logo) . '" alt=""/>' : '') . '<div class="pa-firma">' . (($f['unvan'] ?? '') !== '' ? '<b>' . _kpE($f['unvan']) . '</b>' : '') . '</div></div>'
        . '<div class="pa-title"><h2>' . _kpE($ic['baslik'] ?? 'KİRALAMA PROTOKOLÜ') . '</h2><p class="bt-no">' . _kpE($p['belge_no']) . '</p><p>' . _kpE($alt) . '</p></div></div>';
    $h .= '<div class="pa-uyari kp-durum ' . $sinif . '">' . _kpE(kpDurumMetni($p)) . '</div>';
    foreach (kpBilgiler($ic) as $baslik => $ciftler) {
        $c = array_filter($ciftler, fn($x) => trim((string)$x[1]) !== '');
        if (!$c) continue;
        $h .= '<div class="pa-sart"><h4>' . _kpE($baslik) . '</h4><div class="pa-info">';
        foreach ($c as [$e, $d]) $h .= '<div><label>' . _kpE($e) . '</label><span>' . _kpE($d) . '</span></div>';
        $h .= '</div></div>';
    }
    $hucre = fn(string $ust, string ...$altlar): string => '<div>' . _kpE($ust) . implode('', array_map(fn($a) => $a !== '' ? '<span class="bt-alt">' . _kpE($a) . '</span>' : '', $altlar)) . '</div>';
    $h .= '<div class="pa-sart"><h4>Makineler ve Ücretler</h4><table class="pa-table kp-kalem"><thead><tr><th>Makine</th><th>Birim fiyat</th><th>Süre</th><th class="r">Tutar</th></tr></thead><tbody>';
    foreach (kpSatirlar($ic) as $s) {
        $h .= '<tr><td data-et="Makine"><div class="bt-makine"><b>' . _kpE($s['ust']) . '</b>' . ($s['alt'] !== '' ? '<span class="bt-alt">' . _kpE($s['alt']) . '</span>' : '')
            . ($s['ek'] !== '' ? '<span class="bt-alt">' . _kpE($s['ek']) . '</span>' : '') . '</div></td>'
            . '<td data-et="Birim fiyat">' . ($s['birim'] !== '' ? $hucre($s['birim'], $s['birim_alt']) : '') . '</td>'
            . '<td data-et="Süre">' . ($s['sure'] !== '' ? $hucre($s['sure'], $s['sure_alt']) : '') . '</td>'
            . '<td class="r" data-et="Tutar">' . _kpE($s['tutar']) . '</td></tr>';
    }
    $h .= '</tbody></table>';
    $tek = count($v['toplamlar'] ?? []) <= 1;
    foreach ($v['toplamlar'] ?? [] as $pb => $t) {
        $ek = $tek ? '' : ' (' . $pb . ')';
        $h .= '<table class="pa-tot"><tr><td>Ara Toplam' . _kpE($ek) . '</td><td>' . _kpE(kpPara($t['ara_toplam'], (string)$pb)) . '</td></tr>'
            . '<tr><td>KDV' . _kpE($ek) . '</td><td>' . _kpE(kpPara($t['kdv'], (string)$pb)) . '</td></tr>'
            . '<tr class="grand"><td>GENEL TOPLAM' . _kpE($ek) . '</td><td>' . _kpE(kpPara($t['genel_toplam'], (string)$pb)) . '</td></tr></table>';
    }
    $h .= '</div>';
    if (!empty($v['acik_var'])) $h .= '<div class="pa-note">Bitiş tarihi belli olmayan (açık uçlu) makinelerin kira bedeli dönem sonunda birim fiyat üzerinden hesaplanır; yukarıdaki toplamlara yalnız nakliyesi dahildir.</div>';
    $sart = (string)($ic['sartlar'] ?? '');
    $parcalar = kpSartParcalari($sart);
    $imza = $imzali && kpImzaGecerli((string)$p['imza']) ? '<img src="' . _kpE($p['imza']) . '" alt="Müşteri imzası"/>' : '';
    $kvImza = is_array($kv) && preg_match('~^data:image/(png|jpeg);base64,[A-Za-z0-9+/=]+$~', (string)($kv['imza'] ?? '')) ? (string)$kv['imza'] : '';
    $kvAd = $kvImza !== '' ? implode(' · ', array_filter([trim((string)($kv['ad'] ?? '')), trim((string)($kv['unvan'] ?? ''))], fn($x) => $x !== '')) : '';
    $kim = $imzali ? '<b>' . _kpE($p['imzalayan_ad']) . '</b>' . ($p['imzalayan_unvan'] ? ' · ' . _kpE($p['imzalayan_unvan']) : '')
        . (($ic['musteri']['unvan'] ?? '') !== '' ? '<br/><small>' . _kpE($ic['musteri']['unvan']) . '</small>' : '')
        . '<br/><small>Kiracı adına imzalayan · ' . _kpE(kpTarihSaat($p['imza_zamani'])) . ' · ' . _kpE(KP_KANAL[(string)$p['imza_kanali']] ?? '') . ' elektronik imza</small>'
        : '<b>' . _kpE($ic['musteri']['unvan'] ?? '') . '</b><br/><small>' . _kpE($durum === 'iptal' ? kpDurumEtiket($p) : 'İmza bekliyor') . '</small>';
    if ($parcalar) $h .= '<p class="bt-kabul">' . _kpE(BELGE_KABUL_CUMLESI) . '</p>';
    $h .= '<div class="pa-sig"><div><div class="bt-imza-baslik">Kiraya Veren</div><div class="bt-imza-alan">' . ($kvImza !== '' ? '<img src="' . _kpE($kvImza) . '" alt="Kiraya veren imzası"/>' : '') . '</div>'
        . '<div class="line"><b>' . _kpE($f['unvan'] ?? '') . '</b>' . ($kvAd !== '' ? '<br/><small>' . _kpE($kvAd) . '</small>' : '') . '</div></div>'
        . '<div><div class="bt-imza-baslik">Kiracı (Müşteri)</div><div class="bt-imza-alan">' . $imza . '</div><div class="line">' . $kim . '</div></div></div>';
    if ($parcalar) $h .= '<div class="pa-sart bt-sartlar' . (mb_strlen($sart) > 1800 ? ' bt-yeni-sayfa' : '') . '"><h4>Kiralama Şartları</h4><div class="bt-sutun">'
        . implode('', array_map(fn($x) => $x[1] ? '<h5>' . _kpE($x[0]) . '</h5>' : '<p>' . _kpE($x[0]) . '</p>', $parcalar)) . '</div></div>';
    $h .= '<p class="bt-dip">Belge özeti (SHA-256): ' . _kpE($p['icerik_ozet']) . '</p></div>';
    return $h;
}

function kpLink(string $token): string {
    $sema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || TRUSTED_HOST !== '' ? 'https' : 'http';
    return $sema . '://' . guvenliHost() . siteKokYolu() . '/protokol-imza.html?t=' . $token;
}

function kpBaglantiYaz(PDO $db, array $p, string $kanal, ?string $alici, string $gonderen): array {
    kpSema($db);
    $token = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO kiralama_protokol_gonderimleri(protokol_id,kapsam,kanal,alici,token_hash,son_gecerlilik,gonderen,created_at) VALUES(?,?,?,?,?,?,?,?)')
       ->execute([(int)$p['id'], (string)$p['kapsam'], $kanal, $alici, tokenOzet($token), date('Y-m-d H:i:s', time() + KP_LINK_GUN * 86400), mb_substr($gonderen, 0, 120), date('Y-m-d H:i:s')]);
    return ['id' => (int)$db->lastInsertId(), 'token' => $token, 'link' => kpLink($token)];
}

function kpGoruntulenmeSayilir(string $yontem, bool $pdf): bool {
    return $yontem === 'GET' && !$pdf && empty($_SESSION['user_id']);
}

function kpTokenCoz(PDO $db, string $token, bool $say = true): array {
    kpSema($db);
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) throw new DomainException('Bağlantı bulunamadı.', 404);
    $q = $db->prepare('SELECT * FROM kiralama_protokol_gonderimleri WHERE token_hash=?');
    $q->execute([tokenOzet($token)]);
    $g = $q->fetch(PDO::FETCH_ASSOC);
    if (!$g || (int)$g['iptal'] === 1 || $g['kanal'] === 'hata') throw new DomainException('Bağlantı bulunamadı ya da iptal edildi. Firmamızdan yeni bağlantı isteyin.', 404);
    if ((string)$g['son_gecerlilik'] < date('Y-m-d H:i:s')) throw new DomainException('Bağlantının süresi doldu. Firmamızdan yeni bağlantı isteyin.', 410);
    if ($say) $db->prepare('UPDATE kiralama_protokol_gonderimleri SET goruntulenme=goruntulenme+1, son_goruntulenme=? WHERE id=?')->execute([date('Y-m-d H:i:s'), (int)$g['id']]);
    return $g;
}

function kpGosterilecek(PDO $db, int $pid, bool $guncel = false): array {
    $p = kpGetir($db, $pid);
    if ($p['durum'] === 'imzalandi' && !$guncel) {
        $g = null;
        try { $g = kpCoz($db, (string)$p['kapsam']); } catch (DomainException $e) {}
        return ['protokol' => $p, 'guncel_farkli' => $g !== null && $g['id'] !== (int)$p['id']];
    }
    return ['protokol' => kpCoz($db, (string)$p['kapsam']), 'guncel_farkli' => false];
}

function kpGonder(PDO $db, string $kapsam, string $kanal, string $alici, string $gonderen): array {
    if (!isset(KP_GONDERIM_KANAL[$kanal])) throw new DomainException('Geçersiz kanal.', 400);
    $p = kpGuncel($db, $kapsam, $gonderen);
    if ($p['durum'] === 'imzalandi') throw new DomainException('Protokol zaten imzalı. İmzalı PDF\'i indirip paylaşabilirsiniz; müşteri portalında da görünür.', 409);
    $alici = mb_substr(trim($alici), 0, 200);
    $tel = null;
    if ($kanal === 'whatsapp') {
        require_once __DIR__ . '/whatsapp.php';
        if (!($tel = waTelefon($alici))) throw new DomainException('Geçerli bir telefon numarası girin.', 400);
    } elseif ($kanal === 'eposta' && !filter_var($alici, FILTER_VALIDATE_EMAIL)) throw new DomainException('Geçerli bir e-posta adresi girin.', 400);
    $mid = (int)$p['musteri_id'];
    $b = kpBaglantiYaz($db, $p, $kanal, $kanal === 'kopya' ? null : $alici, $gonderen);
    $portal = mePortalDurum($db, $mid);
    $ic = json_decode((string)$p['icerik'], true) ?: [];
    $konu = ($ic['veri']['siparis_no'] ?? '') !== '' ? $ic['veri']['siparis_no'] . ' siparişinizin' : (($ic['veri']['kalemler'][0]['kod'] ?? '') . ' kiralamanızın');
    $mesaj = 'Sayın ' . ($ic['musteri']['unvan'] ?? '') . ', ' . $konu . ' kiralama protokolü (' . $p['belge_no'] . ') onayınızı bekliyor. '
           . 'Bu bağlantıdan hesap açmadan inceleyip imzalayabilirsiniz (' . KP_LINK_GUN . ' gün geçerli): ' . $b['link'];
    $out = ['kanal' => $kanal, 'gonderim_id' => $b['id'], 'link' => $b['link'], 'metin' => $mesaj, 'portal' => $portal];
    if ($kanal === 'whatsapp') { $out['wa_url'] = 'https://wa.me/' . $tel . '?text=' . rawurlencode($mesaj); $out['mesaj'] = 'WhatsApp mesajı hazır — açılan pencereden gönderin.'; }
    elseif ($kanal === 'eposta') {
        require_once __DIR__ . '/eposta.php';
        $e = epostaGonder($db, $alici, 'Kiralama protokolü — ' . $p['belge_no'], $mesaj, 'kiralama_protokol');
        if (empty($e['basarili'])) {
            $db->prepare("UPDATE kiralama_protokol_gonderimleri SET kanal='hata', iptal=1, hata=? WHERE id=?")->execute(['E-posta gönderilemedi: ' . ($e['hata'] ?? ''), $b['id']]);
            throw new DomainException('E-posta gönderilemedi: ' . ($e['hata'] ?? 'bilinmeyen hata'), 400);
        }
        $out['mesaj'] = 'E-postayla gönderildi.';
    } else $out['mesaj'] = 'Bağlantı hazır — kopyalayıp müşteriye iletin.';
    logla($db, 'kiralama_protokolleri', (int)$p['id'], 'guncelle', null, ['gonderim' => $kanal, 'alici' => $kanal === 'kopya' ? null : $alici, 'portal' => $portal],
          'Kiralama protokolü ' . $p['belge_no'] . ' imzaya gönderildi (' . KP_GONDERIM_KANAL[$kanal] . ($kanal !== 'kopya' ? ' → ' . $alici : '') . ')');
    return $out;
}

function kpGecmis(PDO $db, string $kapsam): array {
    kpSema($db);
    $q = $db->prepare('SELECT g.id, g.protokol_id, p.surum, g.kanal, g.alici, g.son_gecerlilik, g.iptal, g.goruntulenme, g.son_goruntulenme, g.imzalandi, g.hata, g.gonderen, g.created_at
                       FROM kiralama_protokol_gonderimleri g JOIN kiralama_protokolleri p ON p.id=g.protokol_id WHERE g.kapsam=? ORDER BY g.id DESC LIMIT 50');
    $q->execute([$kapsam]);
    $simdi = date('Y-m-d H:i:s');
    return array_map(fn($g) => $g + ['kanal_ad' => KP_GONDERIM_KANAL[$g['kanal']] ?? ($g['kanal'] === 'hata' ? 'Gönderilemedi' : $g['kanal']),
        'durum' => (int)$g['imzalandi'] ? 'imzalandi' : ((int)$g['iptal'] ? 'iptal' : ((string)$g['son_gecerlilik'] < $simdi ? 'suresi_doldu' : 'acik'))], $q->fetchAll(PDO::FETCH_ASSOC));
}

function kpGonderimIptal(PDO $db, int $gid): void {
    kpSema($db);
    $q = $db->prepare('SELECT * FROM kiralama_protokol_gonderimleri WHERE id=?');
    $q->execute([$gid]);
    $g = $q->fetch(PDO::FETCH_ASSOC);
    if (!$g) throw new DomainException('Gönderim bulunamadı.', 404);
    if ((int)$g['iptal']) return;
    $db->prepare('UPDATE kiralama_protokol_gonderimleri SET iptal=1 WHERE id=?')->execute([$gid]);
    logla($db, 'kiralama_protokolleri', (int)$g['protokol_id'], 'guncelle', ['gonderim_iptal' => null], ['gonderim_iptal' => $gid],
          'Kiralama protokolü bağlantısı iptal edildi (' . (KP_GONDERIM_KANAL[$g['kanal']] ?? $g['kanal']) . ($g['alici'] ? ' → ' . $g['alici'] : '') . ')');
}

function kpArsiv(PDO $db, string $kapsam): array {
    $q = $db->prepare("SELECT * FROM kiralama_protokolleri WHERE kapsam=? AND durum='imzalandi' ORDER BY id DESC");
    $q->execute([$kapsam]);
    return array_map('kpOzetSatir', $q->fetchAll(PDO::FETCH_ASSOC));
}

function kpPdf(PDO $db, array $p): array {
    require_once __DIR__ . '/belge-pdf-lib.php';
    $c = new BelgePdf($db, []);
    $c->protokol(['belge_no' => (string)$p['belge_no'], 'surum' => (int)$p['surum'], 'durum' => (string)$p['durum'], 'durum_etiket' => kpDurumEtiket($p),
                  'icerik' => json_decode((string)$p['icerik'], true) ?: [], 'ozet' => (string)$p['icerik_ozet'],
                  'satirlar' => kpSatirlar(json_decode((string)$p['icerik'], true) ?: []), 'bilgiler' => kpBilgiler(json_decode((string)$p['icerik'], true) ?: []),
                  'sartlar' => kpSartParcalari((string)((json_decode((string)$p['icerik'], true) ?: [])['sartlar'] ?? '')),
                  'imza' => (string)($p['imza'] ?? ''), 'imzalayan' => trim((string)$p['imzalayan_ad'] . ($p['imzalayan_unvan'] ? ' · ' . $p['imzalayan_unvan'] : '')),
                  'imza_bilgi' => $p['durum'] === 'imzalandi' ? kpTarihSaat($p['imza_zamani']) . ' · ' . (KP_KANAL[(string)$p['imza_kanali']] ?? '') . ' elektronik imza' : '',
                  'kv' => kpKirayaVeren($db, $p)]);
    return ['ad' => bpDosyaAdi($p['belge_no'] . '-s' . (int)$p['surum']), 'tur' => 'application/pdf', 'veri' => $c->cikti()];
}

function kpPortalListe(PDO $db, int $musteriId): array {
    kpSema($db);
    $q = $db->prepare("SELECT * FROM kiralama_protokolleri WHERE musteri_id=? AND durum<>'iptal' ORDER BY id DESC");
    $q->execute([$musteriId]);
    $out = []; $gorulen = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $ilk = !isset($gorulen[$p['kapsam']]);
        $gorulen[$p['kapsam']] = true;
        if (!$ilk && $p['durum'] !== 'imzalandi') continue;
        $o = kpOzetSatir($p);
        foreach ($o['kiralama_idler'] as $kid) $out[(int)$kid][] = ['id' => $o['id'], 'belge_no' => $o['belge_no'], 'surum' => $o['surum'], 'durum' => $o['durum'],
            'durum_etiket' => $ilk ? $o['durum_etiket'] : 'Önceki imzalı sürüm', 'imza_zamani' => $o['imza_zamani'], 'guncel' => $ilk];
    }
    return $out;
}
