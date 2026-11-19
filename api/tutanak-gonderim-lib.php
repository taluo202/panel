<?php
declare(strict_types=1);
require_once __DIR__ . '/hasar-belge-lib.php';
require_once __DIR__ . '/tutanak-surum.php';
require_once __DIR__ . '/musteri-erisim-lib.php';
const TG_LINK_GUN = 30;

function tgSema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS tutanak_gonderimleri (
      id INTEGER PRIMARY KEY AUTOINCREMENT, tutanak_id INTEGER NOT NULL, kiralama_id INTEGER NOT NULL, surum INTEGER NOT NULL,
      olay TEXT NOT NULL, kanal TEXT NOT NULL, alici TEXT, token_hash TEXT NOT NULL UNIQUE, son_gecerlilik TEXT NOT NULL,
      iptal INTEGER NOT NULL DEFAULT 0, goruntulenme INTEGER NOT NULL DEFAULT 0, son_goruntulenme TEXT, hata TEXT,
      gonderen TEXT, created_at TEXT NOT NULL);
    CREATE INDEX IF NOT EXISTS idx_tg_tutanak ON tutanak_gonderimleri(tutanak_id);");
    if (!in_array('bicim', array_column($db->query('PRAGMA table_info(tutanak_gonderimleri)')->fetchAll(PDO::FETCH_ASSOC), 'name'), true))
        $db->exec("ALTER TABLE tutanak_gonderimleri ADD COLUMN bicim TEXT NOT NULL DEFAULT 'baglanti'");
}

function tgTur(string $islem, bool $buyuk = true, ?string $inceleme = null): string {
    if ($islem !== 'teslim' && $inceleme === 'hasarli') return $buyuk ? 'Hasar tespit bildirimi' : 'hasar tespit bildirimi';
    return $islem === 'teslim' ? ($buyuk ? 'Teslim tutanağı' : 'teslim tutanağı') : ($buyuk ? 'İade tutanağı' : 'iade tutanağı');
}

function tgUygun(PDO $db, int $tid): array {
    $q = $db->prepare('SELECT t.id, t.kiralama_id, t.islem, t.asama, t.belge_no, t.inceleme_sonucu, k.kod, k.sube, k.musteri_id, m.unvan, m.email, m.telefon,
                              (SELECT MAX(s.surum) FROM tutanak_surumleri s WHERE s.tutanak_id=t.id) AS surum, (' . bzPortalGorunurSql('t') . ') AS gorunur
                       FROM iade_tutanaklari t JOIN kiralamalar k ON k.id=t.kiralama_id LEFT JOIN musteriler m ON m.id=k.musteri_id WHERE t.id=?');
    $q->execute([$tid]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r || $r['asama'] !== 'tutanak') throw new DomainException('Tutanak bulunamadı.', 404);
    if (!$r['surum']) throw new DomainException('Tutanağın imzalı sürümü yok; gönderilemez.', 409);
    if (!(int)$r['gorunur']) throw new DomainException('Bu hasarlı iade tutanağı eski hasar kaydına bağlı; müşteriye hasar dosyasıyla gider (Hasar değerlendirme arşivi).', 409);
    return $r;
}

function tgLink(string $token): string {
    $sema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || TRUSTED_HOST !== '' ? 'https' : 'http';
    return $sema . '://' . guvenliHost() . siteKokYolu() . '/tutanak-goster.html?t=' . $token;
}

function _tgGoreli(string $link): string {
    $p = strrpos($link, '/');
    return $p === false ? $link : substr($link, $p + 1);
}

function tgBaglantiYaz(PDO $db, array $u, string $olay, string $kanal, ?string $alici, string $gonderen, string $bicim = 'baglanti'): array {
    tgSema($db);
    $token = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO tutanak_gonderimleri(tutanak_id,kiralama_id,surum,olay,kanal,alici,token_hash,son_gecerlilik,gonderen,created_at,bicim) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
       ->execute([(int)$u['id'], (int)$u['kiralama_id'], (int)$u['surum'], $olay, $kanal, $alici, tokenOzet($token),
                  date('Y-m-d H:i:s', time() + TG_LINK_GUN * 86400), $gonderen, date('Y-m-d H:i:s'), $bicim === 'pdf' ? 'pdf' : 'baglanti']);
    $pdf = $bicim === 'pdf';
    return ['id' => (int)$db->lastInsertId(), 'link' => $pdf ? null : tgLink($token), 'token' => $pdf ? null : $token];
}

function tgPdfMesaj(string $unvan, array $satirlar): string {
    $s = $satirlar[0];
    $govde = count($satirlar) === 1
        ? $s['kod'] . ' kodlu makinenin ' . tgTur($s['islem'], false, $s['inceleme'] ?? null) . ' (' . $s['no'] . ') ekte PDF olarak gönderilmiştir.'
        : 'tutanaklarınız ekte PDF olarak gönderilmiştir: ' . implode(' ; ', array_map(fn($x) => $x['kod'] . ' — ' . tgTur($x['islem'], true, $x['inceleme'] ?? null) . ' ' . $x['no'], $satirlar)) . '.';
    return 'Sayın ' . $unvan . ', ' . $govde;
}

function tgPdf(PDO $db, int $tutanakId, int $surum): array {
    require_once __DIR__ . '/belge-pdf-lib.php';
    $s = $db->prepare('SELECT t.belge_no, s.veri FROM tutanak_surumleri s JOIN iade_tutanaklari t ON t.id=s.tutanak_id WHERE s.tutanak_id=? AND s.surum=?');
    $s->execute([$tutanakId, $surum]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new DomainException('Tutanak sürümü bulunamadı.', 404);
    $no = (string)$r['belge_no'];
    return ['ad' => bpDosyaAdi($no !== '' ? $no : 'tutanak-' . $tutanakId), 'tur' => 'application/pdf',
            'veri' => bpTutanakPdf($db, ['no' => $no, 'surum' => $surum, 'icerik' => tsMusteriGorunumu(json_decode((string)$r['veri'], true) ?: [])])];
}

function tgMesaj(string $unvan, array $satirlar): string {
    $s = $satirlar[0];
    $govde = count($satirlar) === 1
        ? $s['kod'] . ' kodlu makinenin ' . tgTur($s['islem'], false, $s['inceleme'] ?? null) . ' (' . $s['no'] . '): ' . $s['link']
        : 'tutanaklarınız: ' . implode(' ; ', array_map(fn($x) => $x['kod'] . ' — ' . tgTur($x['islem'], true, $x['inceleme'] ?? null) . ' ' . $x['no'] . ': ' . $x['link'], $satirlar));
    return 'Sayın ' . $unvan . ', ' . $govde . ' — bağlantı ' . TG_LINK_GUN . ' gün geçerlidir.';
}

function _tgSatir(array $u, string $link): array {
    return ['kod' => (string)($u['kod'] ?: '#' . $u['kiralama_id']), 'islem' => (string)$u['islem'], 'inceleme' => $u['inceleme_sonucu'] ?? null, 'no' => (string)$u['belge_no'], 'link' => $link];
}

function tgOtomatikGonder(PDO $db, array $tutanakIdler, string $olay = 'otomatik', string $gonderen = 'Sistem'): array {
    $gruplar = [];
    foreach (array_values(array_unique(array_map('intval', $tutanakIdler))) as $tid) {
        try { $u = tgUygun($db, $tid); } catch (Throwable $e) { continue; }
        $gruplar[(int)$u['musteri_id']][] = $u;
    }
    $out = [];
    foreach ($gruplar as $mid => $us) {
        $gid = [];
        $yer = fn(array $x): string => implode(',', array_fill(0, count($x), '?'));
        try {
            $bicim = meTeslimBicimi($db, (int)$mid);
            $satir = [];
            foreach ($us as $u) { $b = tgBaglantiYaz($db, $u, $olay, 'gorev', null, $gonderen, $bicim); $gid[] = $b['id']; $satir[] = _tgSatir($u, (string)$b['link']); }
            [$kanal, $alici] = ['gorev', null];
            $hatalar = [];
            $mail = trim((string)($us[0]['email'] ?? ''));
            $mailVar = $mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL);
            $konu = (count($satir) > 1 ? 'Tutanaklarınız' : tgTur($satir[0]['islem'], true, $satir[0]['inceleme'] ?? null)) . ' — ' . implode(', ', array_column($satir, 'no'));
            if ($bicim === 'baglanti') {
                $mesaj = tgMesaj((string)$us[0]['unvan'], $satir) . meMesajNotu(['durum' => 'aktif']);
                $wa = tgWhatsapp($db, $mid, $mesaj);
                if ($wa['hata'] !== null) $hatalar[] = $wa['hata'];
                if ($wa['giden']) [$kanal, $alici] = ['whatsapp', implode(', ', $wa['giden'])];
                elseif ($mailVar) {
                    require_once __DIR__ . '/eposta.php';
                    $e = epostaGonder($db, $mail, $konu, $mesaj, 'tutanak_gonderim');
                    if (!empty($e['basarili'])) [$kanal, $alici] = ['eposta', $mail];
                    else $hatalar[] = 'E-posta gönderilemedi: ' . ($e['hata'] ?? 'bilinmeyen hata');
                } else $hatalar[] = $hatalar ? 'Müşteri kartında e-posta yok.' : 'Müşteri kartında WhatsApp izni olan portal kişisi ya da e-posta yok.';
            } else {
                $erisim = meOtoDavet($db, (int)$mid, null);
                $mesaj = tgPdfMesaj((string)$us[0]['unvan'], $satir) . mePdfNotu($erisim);
                if ($mailVar) {
                    require_once __DIR__ . '/eposta.php';
                    $e = epostaEkliGonder($db, $mail, $konu, $mesaj, array_map(fn($u) => tgPdf($db, (int)$u['id'], (int)$u['surum']), $us), 'tutanak_gonderim');
                    if (!empty($e['basarili'])) [$kanal, $alici] = ['eposta', $mail];
                    else $hatalar[] = 'E-posta gönderilemedi: ' . ($e['hata'] ?? 'bilinmeyen hata');
                } else $hatalar[] = 'Müşterinin portal hesabı ve müşteri kartında e-postası yok.';
            }
            $hata = $hatalar ? implode(' · ', $hatalar) : null;
            if ($kanal === 'gorev') gorevOlustur($db, 'Müşteriye tutanak iletilemedi — ' . $us[0]['unvan'], $mesaj . "\n\nSebep: " . $hata
                . ($bicim === 'pdf' ? "\nTutanak sayfasındaki \"Müşteriye gönderim\" bölümünden WhatsApp ile PDF olarak gönderin ya da müşteri kartına e-posta ekleyip yeniden gönderin." : ''),
                'tutanak_gonderim', (int)$us[0]['id'], 'yuksek');
            $db->prepare('UPDATE tutanak_gonderimleri SET kanal=?, alici=?, hata=? WHERE id IN (' . $yer($gid) . ')')->execute([$kanal, $alici, $hata, ...$gid]);
            logla($db, 'iade_tutanaklari', (int)$us[0]['id'], 'guncelle', null, ['gonderim' => $kanal, 'bicim' => $bicim, 'tutanaklar' => array_column($us, 'id')],
                  'Tutanak müşteriye ' . ['whatsapp' => 'WhatsApp ile gönderildi', 'eposta' => 'e-postayla gönderildi', 'gorev' => 'iletilemedi, ofise görev'][$kanal]
                  . ($bicim === 'pdf' && $kanal !== 'gorev' ? ' (PDF eki)' : ''));
            $out[] = ['musteri_id' => $mid, 'kanal' => $kanal, 'bicim' => $bicim, 'tutanaklar' => array_map(fn($u) => (int)$u['id'], $us)];
        } catch (Throwable $e) {
            error_log('tgOtomatikGonder: ' . $e->getMessage());
            try {
                if ($gid) $db->prepare("UPDATE tutanak_gonderimleri SET kanal='hata', hata=? WHERE id IN (" . $yer($gid) . ')')->execute([mb_substr($e->getMessage(), 0, 500), ...$gid]);
                gorevOlustur($db, 'Müşteriye tutanak iletilemedi — ' . $us[0]['unvan'], 'Gönderim hatası: ' . $e->getMessage(), 'tutanak_gonderim', (int)$us[0]['id'], 'yuksek');
            } catch (Throwable $e2) { error_log('tgOtomatikGonder (hata kaydı): ' . $e2->getMessage()); }
            $out[] = ['musteri_id' => $mid, 'kanal' => 'hata', 'tutanaklar' => array_map(fn($u) => (int)$u['id'], $us)];
        }
    }
    return $out;
}

function tgWhatsapp(PDO $db, int $mid, string $mesaj): array {
    require_once __DIR__ . '/whatsapp.php';
    $giden = []; $hatalar = [];
    try {
        $ay = waAyar($db);
        if (!$ay['aktif']) return ['giden' => [], 'hata' => null];
        $st = $db->prepare("SELECT id, ad_soyad, telefon FROM musteri_kontaklari WHERE musteri_id=? AND durum='aktif' AND wa_izin=1");
        $st->execute([$mid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $k) {
            $tel = waTelefon((string)$k['telefon']);
            $kayit = ['olay' => 'tutanak', 'alici' => 'musteri', 'kanal' => 'cloud', 'musteri_id' => $mid, 'kontak_id' => (int)$k['id'],
                      'telefon' => $tel ?? (string)$k['telefon'], 'mesaj' => $mesaj];
            if ($tel === null) { _waLogla($db, $kayit + ['durum' => 'atlandi', 'hata' => 'Telefon numarası geçersiz']); $hatalar[] = (string)$k['telefon'] . ': numara geçersiz'; continue; }
            $r = waBulutGonder($ay, $tel, (string)$k['ad_soyad'], $mesaj);
            $logId = _waLogla($db, $kayit + ['durum' => $r['ok'] ? 'gonderildi' : 'hata', 'hata' => $r['ok'] ? null : $r['hata']]);
            $db->prepare("UPDATE whatsapp_log SET mesaj_id=?, gonderim_tarihi=datetime('now','localtime') WHERE id=?")->execute([$r['mesaj_id'], $logId]);
            if ($r['ok']) $giden[] = $tel; else $hatalar[] = $tel . ': ' . $r['hata'];
        }
    } catch (Throwable $e) { $hatalar[] = $e->getMessage(); }
    return ['giden' => $giden, 'hata' => $hatalar ? 'WhatsApp gönderilemedi (' . mb_substr(implode('; ', $hatalar), 0, 400) . ')' : null];
}

function tgElleGonder(PDO $db, int $tid, string $kanal, string $alici, string $gonderen): array {
    $u = tgUygun($db, $tid);
    if ($kanal === 'otomatik') {
        $o = tgOtomatikGonder($db, [$tid], 'yeniden', $gonderen)[0] ?? [];
        $k = $o['kanal'] ?? 'hata';
        return ['kanal' => $k, 'bicim' => $o['bicim'] ?? null, 'mesaj' => ['whatsapp' => 'WhatsApp ile gönderildi.',
                'eposta' => ($o['bicim'] ?? '') === 'pdf' ? 'E-postayla PDF olarak gönderildi.' : 'E-postayla gönderildi.',
                'gorev' => 'Müşteriye ulaşılamadı; ofise görev açıldı.'][$k] ?? 'Gönderilemedi; ofise görev açıldı.'];
    }
    if (!in_array($kanal, ['whatsapp', 'eposta'], true)) throw new DomainException('Geçersiz kanal.', 400);
    $alici = mb_substr(trim($alici), 0, 200);
    $tel = null;
    if ($kanal === 'whatsapp') {
        require_once __DIR__ . '/whatsapp.php';
        if (!($tel = waTelefon($alici))) throw new DomainException('Geçerli bir telefon numarası girin.', 400);
    } elseif (!filter_var($alici, FILTER_VALIDATE_EMAIL)) throw new DomainException('Geçerli bir e-posta adresi girin.', 400);
    if (meTeslimBicimi($db, (int)$u['musteri_id']) === 'pdf') return _tgEllePdf($db, $u, $kanal, $alici, $tel, $gonderen);
    $b = tgBaglantiYaz($db, $u, 'elle', $kanal, $alici, $gonderen);
    $erisim = ['durum' => 'aktif', 'link' => null];
    $mesaj = tgMesaj((string)$u['unvan'], [_tgSatir($u, $b['link'])]) . meMesajNotu($erisim);
    $out = ['kanal' => $kanal, 'gonderim_id' => $b['id'], 'link' => $b['link'], 'portal' => $erisim['durum'],
            'mesaj' => 'WhatsApp mesajı hazır — açılan pencereden gönderin.'];
    if ($tel) $out['wa_url'] = 'https://wa.me/' . $tel . '?text=' . rawurlencode($mesaj);
    else {
        require_once __DIR__ . '/eposta.php';
        $e = epostaGonder($db, $alici, tgTur((string)$u['islem'], true, $u['inceleme_sonucu'] ?? null) . ' — ' . $u['belge_no'], $mesaj, 'tutanak_gonderim');
        if (empty($e['basarili'])) {
            $db->prepare("UPDATE tutanak_gonderimleri SET kanal='hata', iptal=1, hata=? WHERE id=?")->execute(['E-posta gönderilemedi: ' . ($e['hata'] ?? ''), $b['id']]);
            throw new DomainException('E-posta gönderilemedi: ' . ($e['hata'] ?? 'bilinmeyen hata'), 400);
        }
        $out['mesaj'] = 'E-postayla gönderildi.';
    }
    logla($db, 'iade_tutanaklari', $tid, 'guncelle', null, ['gonderim' => $kanal, 'alici' => $alici],
          'Tutanak müşteriye gönderildi (' . ($kanal === 'whatsapp' ? 'WhatsApp' : 'e-posta') . ' → ' . $alici . ')');
    return $out;
}

function _tgEllePdf(PDO $db, array $u, string $kanal, string $alici, ?string $tel, string $gonderen): array {
    $b = tgBaglantiYaz($db, $u, 'elle', $kanal, $alici, $gonderen, 'pdf');
    $erisim = meOtoDavet($db, (int)$u['musteri_id'], null);
    $mesaj = tgPdfMesaj((string)$u['unvan'], [_tgSatir($u, '')]) . mePdfNotu($erisim);
    $out = ['kanal' => $kanal, 'bicim' => 'pdf', 'gonderim_id' => $b['id'], 'portal' => $erisim['durum']];
    if ($tel) {
        require_once __DIR__ . '/belge-pdf-lib.php';
        $out += ['pdf_url' => 'api/tutanak_gonderim.php?pdf=' . $b['id'], 'dosya_adi' => bpDosyaAdi((string)$u['belge_no'] ?: 'tutanak-' . $u['id']),
                 'metin' => $mesaj, 'wa_url' => 'https://wa.me/' . $tel . '?text=' . rawurlencode($mesaj),
                 'mesaj' => "PDF hazır — WhatsApp'ta dosya olarak paylaşın."];
    } else {
        require_once __DIR__ . '/eposta.php';
        $e = epostaEkliGonder($db, $alici, tgTur((string)$u['islem'], true, $u['inceleme_sonucu'] ?? null) . ' — ' . $u['belge_no'], $mesaj, [tgPdf($db, (int)$u['id'], (int)$u['surum'])], 'tutanak_gonderim');
        if (empty($e['basarili'])) {
            $db->prepare("UPDATE tutanak_gonderimleri SET kanal='hata', iptal=1, hata=? WHERE id=?")->execute(['E-posta gönderilemedi: ' . ($e['hata'] ?? ''), $b['id']]);
            throw new DomainException('E-posta gönderilemedi: ' . ($e['hata'] ?? 'bilinmeyen hata'), 400);
        }
        $out['mesaj'] = 'E-postayla PDF olarak gönderildi.';
    }
    logla($db, 'iade_tutanaklari', (int)$u['id'], 'guncelle', null, ['gonderim' => $kanal, 'bicim' => 'pdf', 'alici' => $alici],
          'Tutanak müşteriye PDF olarak gönderildi (' . ($kanal === 'whatsapp' ? 'WhatsApp' : 'e-posta') . ' → ' . $alici . ')');
    return $out;
}

function tgGecmis(PDO $db, int $tid): array {
    tgSema($db);
    $q = $db->prepare('SELECT id, surum, olay, kanal, alici, son_gecerlilik, iptal, goruntulenme, son_goruntulenme, hata, gonderen, created_at, bicim
                       FROM tutanak_gonderimleri WHERE tutanak_id=? ORDER BY id DESC');
    $q->execute([$tid]);
    $simdi = date('Y-m-d H:i:s');
    return array_map(fn($g) => $g + ['durum' => $g['bicim'] === 'pdf' ? ((int)$g['iptal'] ? 'iptal' : 'pdf')
        : ((int)$g['iptal'] ? 'iptal' : ((string)$g['son_gecerlilik'] < $simdi ? 'suresi_doldu' : 'acik'))], $q->fetchAll(PDO::FETCH_ASSOC));
}

function tgIptal(PDO $db, int $gid): array {
    tgSema($db);
    $q = $db->prepare('SELECT * FROM tutanak_gonderimleri WHERE id=?');
    $q->execute([$gid]);
    $g = $q->fetch(PDO::FETCH_ASSOC);
    if (!$g) throw new DomainException('Gönderim bulunamadı.', 404);
    if (!(int)$g['iptal']) {
        $db->prepare('UPDATE tutanak_gonderimleri SET iptal=1 WHERE id=?')->execute([$gid]);
        logla($db, 'iade_tutanaklari', (int)$g['tutanak_id'], 'guncelle', ['gonderim_iptal' => null], ['gonderim_iptal' => $gid],
              'Tutanak bağlantısı iptal edildi (' . $g['kanal'] . ($g['alici'] ? ' → ' . $g['alici'] : '') . ')');
    }
    return $g;
}

function tgTokenCoz(PDO $db, string $token, bool $say = true): array {
    tgSema($db);
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) throw new DomainException('Bağlantı bulunamadı.', 404);
    $q = $db->prepare('SELECT * FROM tutanak_gonderimleri WHERE token_hash=?');
    $q->execute([tokenOzet($token)]);
    $g = $q->fetch(PDO::FETCH_ASSOC);
    if (!$g || (int)$g['iptal'] === 1 || $g['kanal'] === 'hata' || ($g['bicim'] ?? 'baglanti') === 'pdf') throw new DomainException('Bağlantı bulunamadı.', 404);
    if ((string)$g['son_gecerlilik'] < date('Y-m-d H:i:s')) throw new DomainException('Bağlantının süresi doldu. Firmamızdan yeni bağlantı isteyin.', 410);
    $s = $db->prepare('SELECT t.belge_no, s.veri, s.created_at FROM tutanak_surumleri s JOIN iade_tutanaklari t ON t.id=s.tutanak_id WHERE s.tutanak_id=? AND s.surum=?');
    $s->execute([(int)$g['tutanak_id'], (int)$g['surum']]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new DomainException('Bağlantı bulunamadı.', 404);
    if ($say) $db->prepare('UPDATE tutanak_gonderimleri SET goruntulenme=goruntulenme+1, son_goruntulenme=? WHERE id=?')->execute([date('Y-m-d H:i:s'), (int)$g['id']]);
    return ['belge' => ['no' => (string)$r['belge_no'], 'surum' => (int)$g['surum'], 'created_at' => $r['created_at'],
                        'icerik' => tsMusteriGorunumu(json_decode((string)$r['veri'], true) ?: [])],
            'son_gecerlilik' => $g['son_gecerlilik']];
}


function tgSirala(PDO $db, int $tutanakId): void { _tgKuyruk(['db' => $db, 'id' => $tutanakId]); }

function _tgKuyruk(?array $is = null, bool $al = false): array {
    static $kuyruk = [], $kayitli = false;
    if ($is !== null) {
        $kuyruk[] = $is;
        if (!$kayitli && PHP_SAPI !== 'cli') { $kayitli = true; register_shutdown_function('tgKuyrukIsle'); }
    }
    if ($al) { $d = $kuyruk; $kuyruk = []; return $d; }
    return $kuyruk;
}

function tgKuyrukIsle(): void {
    $isler = _tgKuyruk(null, true);
    if (!$isler) return;
    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
    $dbler = [];
    foreach ($isler as $is) { $k = spl_object_id($is['db']); $dbler[$k]['db'] = $is['db']; $dbler[$k]['ids'][] = (int)$is['id']; }
    foreach ($dbler as $d) {
        try {
            $db = $d['db'];
            if ($db->inTransaction()) continue;
            $ids = array_values(array_unique($d['ids']));
            $q = $db->prepare('SELECT id FROM iade_tutanaklari WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $q->execute($ids);
            $var = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
            if ($var) tgOtomatikGonder($db, $var);
        } catch (Throwable $e) { error_log('tgKuyrukIsle: ' . $e->getMessage()); }
    }
    if (function_exists('_waKuyrukIsle')) _waKuyrukIsle();
}
