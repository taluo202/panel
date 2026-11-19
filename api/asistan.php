<?php
declare(strict_types=1);
require_once __DIR__ . '/analiz_motor.php';
require_once __DIR__ . '/claude.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method Not Allowed', 405);
verifyCsrf();
@set_time_limit(300);

$db = getDb();
$action = $_GET['action'] ?? '';
$body = govdeOku();

$ayar = claudeAyar($db);
if (!$ayar['aktif']) {
    jsonError(($_SESSION['rol'] ?? '') === 'admin'
        ? 'Yapay zekâ asistanı yapılandırılmamış. Ayarlar → Yapay Zekâ Analiz Asistanı bölümüne Claude API anahtarını girin.'
        : 'Yapay zekâ asistanı henüz yapılandırılmamış. Sistem yöneticinizden etkinleştirmesini isteyin.', 400, ['yapilandirilmamis' => true]);
}
claudeLimitKontrol($db);

function _asYorumIzni(): bool {
    return hasPermission('yonetim', 'oku') && hasPermission('kiralama_takip', 'oku')
        && hasPermission('arac', 'oku') && hasPermission('manlift', 'oku');
}
function _asKarlilikTurleri(): array {
    if (!hasPermission('rapor', 'oku') || !hasPermission('kiralama_takip', 'oku')) return [];
    return array_values(array_filter(['arac', 'manlift'], fn($t) => hasPermission($t, 'oku')));
}
function _asDonem(array $g): array {
    $bas = _anTarih($g['bas'] ?? null, date('Y-01-01'));
    $bit = _anTarih($g['bit'] ?? null, date('Y-m-d'));
    if ($bas > $bit) [$bas, $bit] = [$bit, $bas];
    return [$bas, $bit];
}
function _asTl(float $v): string { return number_format($v, 2, ',', '.') . ' ₺'; }

if ($action === 'yorum') {
    if (!_asYorumIzni()) jsonError('Yönetici yorumu için Yönetim, Kiralama Takip, Araç ve Makine okuma yetkileri gerekir.', 403);
    [$bas, $bit] = _asDonem($body);
    $sube = subeKisiti() ?? (trim(metin($body['sube'] ?? '')) ?: null);
    $paket = analizKpiPaketi($db, $bas, $bit, $sube);

    $sema = [
        'type' => 'object',
        'properties' => [
            'ozet' => ['type' => 'string', 'description' => '3-5 cümlelik genel değerlendirme'],
            'olumlu' => ['type' => 'array', 'items' => ['type' => 'string']],
            'dikkat' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => [
                    'baslik' => ['type' => 'string'],
                    'aciklama' => ['type' => 'string'],
                    'oncelik' => ['type' => 'string', 'enum' => ['yuksek', 'orta', 'dusuk']],
                ],
                'required' => ['baslik', 'aciklama', 'oncelik'], 'additionalProperties' => false,
            ]],
            'oneriler' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => ['oneri' => ['type' => 'string'], 'gerekce' => ['type' => 'string']],
                'required' => ['oneri', 'gerekce'], 'additionalProperties' => false,
            ]],
        ],
        'required' => ['ozet', 'olumlu', 'dikkat', 'oneriler'],
        'additionalProperties' => false,
    ];
    $sistem = "Bir iş makinesi (manlift, araç) kiralama firmasının yönetim danışmanısın. Sana verilen JSON, "
        . "firmanın seçili dönemdeki gerçek göstergeleridir (tutarlar TL). Görevin bu verileri yöneticiye Türkçe yorumlamak.\n"
        . "- Yalnızca verideki rakamları kullan; veride olmayan bir rakam, oran veya neden uydurma. Emin olmadığın nedeni 'olası' diye belirt.\n"
        . "- Önceki dönemle kıyasla; anlamlı artış/azalışları, vadesi geçmiş alacakları, zarar eden ve boşta kalan makineleri, riskli müşterileri öne çıkar.\n"
        . "- 'faturalanmamis_is' alacak değildir, henüz faturası kesilmemiş iştir; yüksekse faturalandırma gecikmesi olarak yorumla.\n"
        . "- Dönem kısa ya da veri azsa bunu söyle, aşırı genelleme yapma.\n"
        . "- Öneriler somut ve uygulanabilir olsun (kim, ne yapmalı). En fazla 5 dikkat maddesi ve 5 öneri.\n"
        . "- Tutarları 1.234.567 ₺ biçiminde yaz.";
    try {
        $y = claudeIstek($ayar, [
            'system' => $sistem,
            'max_tokens' => 16000,
            'messages' => [['role' => 'user', 'content' => "Dönem göstergeleri:\n" . json_encode($paket, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]],
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $sema]],
        ], 'high');
    } catch (\RuntimeException $e) {
        claudeKullanimYaz($db, 'yorum', $ayar['model'], [], 'hata');
        jsonError($e->getMessage(), 502);
    }
    claudeKullanimYaz($db, 'yorum', $ayar['model'], $y['usage'] ?? []);
    if (($y['stop_reason'] ?? '') === 'max_tokens') jsonError('Yorum yarıda kesildi; dönemi daraltıp tekrar deneyin.', 502);
    $yorum = json_decode(claudeMetin($y), true);
    if (!is_array($yorum) || !isset($yorum['ozet'])) jsonError('Yapay zekâ yanıtı okunamadı; tekrar deneyin.', 502);
    jsonOk(['yorum' => $yorum, 'veri' => $paket, 'model' => $y['model'] ?? $ayar['model'], 'uretim' => date('Y-m-d H:i')]);
}

if ($action === 'risk_aciklama') {
    requirePermission('kiralama_takip', 'oku');
    $mid = (int)($body['musteri_id'] ?? 0);
    $kapsam = analizMusteriKapsami($db, subeKisiti());
    if ($mid < 1 || ($kapsam !== null && !in_array($mid, $kapsam, true))) jsonError('Müşteri bulunamadı.', 404);
    $r = analizMusteriRisk($db, [$mid])['musteriler'][0] ?? null;
    if (!$r) jsonError('Bu müşteri için ölçülebilir finansal hareket yok.');
    $cari = _asMusteriCari($db, $mid);
    try {
        $y = claudeIstek($ayar, [
            'system' => "Bir kiralama firmasının tahsilat uzmanısın. Verilen müşteri risk verisini Türkçe, en fazla 5 cümleyle açıkla "
                . "ve sonunda 'Önerilen adım:' ile başlayan tek somut öneri yaz (ör. ön ödeme iste, yeni kiralamayı teminata bağla, "
                . "temsilci arasın, normal çalışmaya devam). Yalnız verideki rakamları kullan; tahmin yürütüyorsan belirt. "
                . "Skor kural tabanlıdır, sen değiştirme.",
            'max_tokens' => 2000,
            'messages' => [['role' => 'user', 'content' => json_encode(['risk' => $r, 'cari' => $cari], JSON_UNESCAPED_UNICODE)]],
        ], 'low');
    } catch (\RuntimeException $e) {
        claudeKullanimYaz($db, 'risk_aciklama', $ayar['model'], [], 'hata');
        jsonError($e->getMessage(), 502);
    }
    claudeKullanimYaz($db, 'risk_aciklama', $ayar['model'], $y['usage'] ?? []);
    jsonOk(['aciklama' => claudeMetin($y), 'risk' => $r]);
}

function _asMusteriBul(PDO $db, string $ad): array {
    $ad = trim($ad);
    if ($ad === '') return [];
    $where = [trAramaSql('unvan') . ' LIKE ?']; $params = ['%' . trArama($ad) . '%'];
    subeKosulEkle($where, $params, 'sube');
    $st = $db->prepare('SELECT id, unvan, sube FROM musteriler WHERE ' . implode(' AND ', $where) . ' ORDER BY unvan COLLATE NOCASE LIMIT 5');
    $st->execute($params);
    return $st->fetchAll();
}

function _asMusteriCari(PDO $db, int $mid): array {
    $kal = cariBorcKalemleri($db, $mid)[$mid] ?? ['borclar' => [], 'faturalanmamis' => []];
    $alacak = alacakOzeti($db, [$mid]);
    $faturalar = array_slice(array_reverse($kal['borclar']), 0, 8);
    $st = $db->prepare("SELECT tarih, tutar, yontem FROM tahsilatlar WHERE musteri_id=? ORDER BY tarih DESC, id DESC LIMIT 8");
    $st->execute([$mid]);
    return [
        'acik_faturali_alacak' => (float)($alacak['musteri'][$mid] ?? 0),
        'faturalanmamis_is' => round(array_sum(array_column($kal['faturalanmamis'], 'tutar')), 2),
        'son_faturalar' => array_map(fn($f) => ['tarih' => $f['tarih'], 'tutar' => $f['tutar'], 'vade' => $f['vade'] ?? null,
            'kalan' => !empty($f['hakedis_id']) ? ($alacak['hakedis_kalan'][(int)$f['hakedis_id']] ?? 0.0) : null,
            'aciklama' => $f['aciklama']], $faturalar),
        'son_tahsilatlar' => $st->fetchAll(),
    ];
}

function _asAraclar(): array {
    $tarih = ['bas' => ['type' => 'string', 'description' => 'Dönem başı YYYY-AA-GG (varsayılan: bu yılın başı)'],
              'bit' => ['type' => 'string', 'description' => 'Dönem sonu YYYY-AA-GG (varsayılan: bugün)']];
    $a = [];
    if (_asYorumIzni()) $a[] = ['name' => 'kpi_ozeti',
        'description' => 'Seçili dönemin yönetim göstergeleri: ciro, tahsilat, önceki dönemle kıyas, filo doluluğu ve net kâr, açık/vadesi geçmiş alacak, riskli müşteriler, en kârlı/zayıf makineler, son 6 ay trendi, geciken iadeler. Genel "işler nasıl" sorularında önce bunu kullan.',
        'input_schema' => ['type' => 'object', 'properties' => $tarih]];
    if (_asKarlilikTurleri()) $a[] = ['name' => 'makine_karlilik',
        'description' => 'Makine bazında gelir, maliyet (gider+servis+nakliye+amortisman veya DK tedarik bedeli), net kâr, doluluk yüzdesi, boşta gün ve kural tabanlı öneriler. Sıralanabilir ve süzülebilir.',
        'input_schema' => ['type' => 'object', 'properties' => $tarih + [
            'siralama' => ['type' => 'string', 'enum' => ['net_azalan', 'net_artan', 'doluluk_azalan', 'doluluk_artan', 'bos_gun_azalan', 'gelir_azalan']],
            'tur' => ['type' => 'string', 'enum' => ['hepsi', 'arac', 'manlift']],
            'sadece_dk' => ['type' => 'boolean', 'description' => 'Yalnız dış kiralama (DK) makineleri'],
            'arama' => ['type' => 'string', 'description' => 'Makine kodu/plaka/marka içinde geçen metin'],
            'limit' => ['type' => 'integer', 'description' => '1-40, varsayılan 10'],
        ]]];
    if (hasPermission('kiralama_takip', 'oku')) {
        $a[] = ['name' => 'musteri_risk',
            'description' => 'Müşterilerin ödeme riski: skor (0-100), seviye, açık ve vadesi geçmiş alacak, ortalama ödeme gecikmesi, karşılıksız çek, risk limiti ve Türkçe gerekçeler.',
            'input_schema' => ['type' => 'object', 'properties' => [
                'seviye' => ['type' => 'string', 'enum' => ['hepsi', 'yuksek', 'orta', 'dusuk']],
                'arama' => ['type' => 'string', 'description' => 'Müşteri ünvanında geçen metin'],
                'limit' => ['type' => 'integer', 'description' => '1-30, varsayılan 10'],
            ]]];
        $a[] = ['name' => 'musteri_cari',
            'description' => 'Tek müşterinin cari durumu: açık faturalı alacak, faturalanmamış iş, son faturalar (vade ve kalan) ve son tahsilatlar.',
            'input_schema' => ['type' => 'object', 'properties' => ['musteri' => ['type' => 'string', 'description' => 'Müşteri ünvanı veya bir parçası']], 'required' => ['musteri']]];
        $a[] = ['name' => 'kiralama_ara',
            'description' => 'Kiralama kayıtlarını arar: müşteri, makine, durum ve tarih aralığına göre. Kod, müşteri, ekipman, tarihler, birim fiyat ve toplam tutar döner.',
            'input_schema' => ['type' => 'object', 'properties' => $tarih + [
                'musteri' => ['type' => 'string'], 'makine' => ['type' => 'string', 'description' => 'Ekipman adı/kod/plaka parçası'],
                'durum' => ['type' => 'string', 'enum' => ['hepsi', 'aktif', 'tamamlandi', 'geciken_iade']],
                'limit' => ['type' => 'integer', 'description' => '1-30, varsayılan 15'],
            ]]];
    }
    if (hasPermission('arac', 'oku') || hasPermission('manlift', 'oku')) $a[] = ['name' => 'filo_durumu',
        'description' => 'Filonun anlık durumu: durum dağılımı (kirada, müsait, serviste...), iade tarihi geçmiş kiralamalar ve açık servis kayıtları.',
        'input_schema' => ['type' => 'object', 'properties' => new stdClass()]];
    if (hasPermission('teknik_servis', 'oku')) $a[] = ['name' => 'servis_ozeti',
        'description' => 'Dönemdeki teknik servis kayıtları: tipe göre sayı, toplam maliyet, en çok servise giren makineler.',
        'input_schema' => ['type' => 'object', 'properties' => $tarih]];
    if (hasPermission('arac', 'oku') || hasPermission('manlift', 'oku')) $a[] = ['name' => 'makine_detay',
        'description' => 'Tek makinenin (araç/manlift) kartı: durum, şube, DK bilgisi, şu anki kiralaması, son servisleri ve bu yılki kârlılığı. Kod, plaka veya marka parçasıyla aranır.',
        'input_schema' => ['type' => 'object', 'properties' => ['makine' => ['type' => 'string', 'description' => 'Makine kodu, plaka veya marka parçası']], 'required' => ['makine']]];
    if (hasPermission('kiralama_takip', 'oku')) $a[] = ['name' => 'teklif_ozeti',
        'description' => 'Tekliflerin durumu: döneme göre durum dağılımı, kiralamaya dönüşüm oranı ve açık (henüz dönüşmemiş) teklifler tutarlarıyla.',
        'input_schema' => ['type' => 'object', 'properties' => $tarih + ['limit' => ['type' => 'integer', 'description' => '1-30, varsayılan 10']]]];
    $a[] = ['name' => 'bekleyen_isler',
        'description' => 'Kullanıcının kendisine atanmış açık görevler ve yetkili olduğu modüllerdeki açık istisnalar (geciken tahsilat, fatura bekleyen hakediş, geciken toplama vb.) sayılarıyla.',
        'input_schema' => ['type' => 'object', 'properties' => new stdClass()]];
    return $a;
}

function _asAracCalistir(PDO $db, string $ad, array $g): array {
    $sube = subeKisiti();
    $limit = fn($v, $max, $vars) => max(1, min($max, (int)($v ?: $vars)));
    switch ($ad) {
        case 'kpi_ozeti':
            if (!_asYorumIzni()) return ['hata' => 'yetki yok'];
            [$bas, $bit] = _asDonem($g);
            return analizKpiPaketi($db, $bas, $bit, $sube);

        case 'makine_karlilik':
            $turler = _asKarlilikTurleri();
            if (!$turler) return ['hata' => 'yetki yok'];
            if (in_array($g['tur'] ?? '', ['arac', 'manlift'], true)) $turler = array_values(array_intersect($turler, [$g['tur']]));
            [$bas, $bit] = _asDonem($g);
            $k = analizMakineKarlilik($db, $bas, $bit, $sube, $turler);
            $m = $k['makineler'];
            if (!empty($g['sadece_dk'])) $m = array_values(array_filter($m, fn($r) => $r['dk']));
            if (($q = trArama((string)($g['arama'] ?? ''))) !== '') $m = array_values(array_filter($m, fn($r) => str_contains(trArama($r['ad'] . ' ' . ($r['model'] ?? '')), $q)));
            $sir = ['net_azalan' => ['net', -1], 'net_artan' => ['net', 1], 'doluluk_azalan' => ['doluluk', -1],
                    'doluluk_artan' => ['doluluk', 1], 'bos_gun_azalan' => ['bos_gun', -1], 'gelir_azalan' => ['gelir', -1]][$g['siralama'] ?? 'net_azalan'] ?? ['net', -1];
            usort($m, fn($a, $b) => (($a[$sir[0]] ?? -1) <=> ($b[$sir[0]] ?? -1)) * $sir[1]);
            return ['ozet' => $k['ozet'], 'eslesen_adet' => count($m), 'makineler' => array_map(fn($r) => [
                'makine' => $r['ad'], 'tur' => $r['tur'], 'sube' => $r['sube'], 'dk' => $r['dk'], 'durum' => $r['durum'],
                'gelir' => $r['gelir'], 'maliyet' => $r['toplam_maliyet'], 'servis' => $r['servis_maliyet'], 'net' => $r['net'],
                'doluluk' => $r['doluluk'], 'dolu_gun' => $r['dolu_gun'], 'bos_gun' => $r['bos_gun'], 'kiralama_adet' => $r['kiralama_adet'],
                'oneriler' => array_column($r['oneriler'], 'metin'),
            ], array_slice($m, 0, $limit($g['limit'] ?? 0, 40, 10)))];

        case 'musteri_risk':
            if (!hasPermission('kiralama_takip', 'oku')) return ['hata' => 'yetki yok'];
            $r = analizMusteriRisk($db, analizMusteriKapsami($db, $sube));
            $m = $r['musteriler'];
            if (in_array($g['seviye'] ?? '', ['yuksek', 'orta', 'dusuk'], true)) $m = array_values(array_filter($m, fn($x) => $x['seviye'] === $g['seviye']));
            if (($q = trArama((string)($g['arama'] ?? ''))) !== '') $m = array_values(array_filter($m, fn($x) => str_contains(trArama((string)$x['unvan']), $q)));
            return ['ozet' => $r['ozet'], 'eslesen_adet' => count($m), 'musteriler' => array_slice($m, 0, $limit($g['limit'] ?? 0, 30, 10))];

        case 'musteri_cari':
            if (!hasPermission('kiralama_takip', 'oku')) return ['hata' => 'yetki yok'];
            $bul = _asMusteriBul($db, (string)($g['musteri'] ?? ''));
            if (!$bul) return ['hata' => 'Bu adla müşteri bulunamadı.'];
            if (count($bul) > 1 && mb_strtolower(trim((string)$g['musteri'])) !== mb_strtolower((string)$bul[0]['unvan']))
                return ['birden_fazla_eslesme' => array_column($bul, 'unvan'), 'not' => 'Kullanıcıya hangisini kastettiğini sor ya da tam ünvanla tekrar çağır.'];
            $mid = (int)$bul[0]['id'];
            $risk = analizMusteriRisk($db, [$mid])['musteriler'][0] ?? null;
            return ['musteri' => $bul[0]['unvan'], 'sube' => $bul[0]['sube']] + _asMusteriCari($db, $mid)
                 + ['risk' => $risk ? array_intersect_key($risk, array_flip(['skor', 'seviye', 'geciken', 'ort_gecikme_gun', 'gerekce'])) : null];

        case 'kiralama_ara':
            if (!hasPermission('kiralama_takip', 'oku')) return ['hata' => 'yetki yok'];
            $where = ["k.durum<>'iptal'"]; $params = [];
            if (($q = trim((string)($g['musteri'] ?? ''))) !== '') { $where[] = trAramaSql('m.unvan') . ' LIKE ?'; $params[] = '%' . trArama($q) . '%'; }
            if (($q = trim((string)($g['makine'] ?? ''))) !== '') { $where[] = '(' . trAramaSql('k.ekipman_adi') . ' LIKE ? OR ' . trAramaSql('COALESCE(k.makine_kodu,\'\')') . ' LIKE ?)'; $params[] = '%' . trArama($q) . '%'; $params[] = '%' . trArama($q) . '%'; }
            $durum = $g['durum'] ?? 'hepsi';
            if ($durum === 'aktif') $where[] = "k.durum='aktif' AND k.gercek_bitis IS NULL";
            if ($durum === 'tamamlandi') $where[] = "(k.gercek_bitis IS NOT NULL OR k.durum='tamamlandi')";
            if ($durum === 'geciken_iade') $where[] = "k.durum='aktif' AND k.gercek_bitis IS NULL AND k.bitis IS NOT NULL AND k.bitis < date('now','localtime')";
            if (!empty($g['bas']) || !empty($g['bit'])) {
                [$bas, $bit] = _asDonem($g);
                $where[] = "k.baslangic <= ? AND COALESCE(k.gercek_bitis, k.bitis, '9999-12-31') >= ?"; $params[] = $bit; $params[] = $bas;
            }
            if ($sube !== null) { $where[] = "COALESCE(NULLIF(TRIM(k.sube),''), m.sube) = ?"; $params[] = $sube; }
            $st = $db->prepare("SELECT k.*, m.unvan AS musteri FROM kiralamalar k JOIN musteriler m ON m.id=k.musteri_id WHERE "
                . implode(' AND ', $where) . " ORDER BY k.baslangic DESC, k.id DESC LIMIT " . $limit($g['limit'] ?? 0, 30, 15));
            $st->execute($params);
            $out = [];
            foreach ($st->fetchAll() as $k) $out[] = [
                'kod' => $k['kod'], 'musteri' => $k['musteri'], 'ekipman' => $k['ekipman_adi'], 'sube' => $k['sube'],
                'baslangic' => $k['baslangic'], 'bitis' => $k['bitis'], 'iade' => $k['gercek_bitis'], 'durum' => $k['durum'],
                'kira_tipi' => kiraTipAdi($k['kira_tipi'] ?? null), 'birim_ucret' => (float)$k['birim_ucret'], 'doviz' => $k['doviz'] ?: 'TL',
                'toplam_kdv_dahil' => kiralamaTutar($k)['genel_toplam'],
            ];
            return ['adet' => count($out), 'kiralamalar' => $out];

        case 'filo_durumu':
            $turler = array_values(array_filter(['arac', 'manlift'], fn($t) => hasPermission($t, 'oku')));
            if (!$turler) return ['hata' => 'yetki yok'];
            $dag = []; $servis = [];
            foreach (makineListesi($db) as $m) {
                if (!in_array($m['tur'], $turler, true) || ($m['durum_ham'] ?? '') === 'satildi') continue;
                if ($sube !== null && trim((string)$m['sube']) !== $sube) continue;
                $dag[$m['durum']] = ($dag[$m['durum']] ?? 0) + 1;
            }
            $gec = _asAracCalistir($db, 'kiralama_ara', ['durum' => 'geciken_iade', 'limit' => 15]);
            if (hasPermission('teknik_servis', 'oku')) {
                $st = $db->query("SELECT makine_ad, tip, durum, tarih, ariza FROM teknik_servis WHERE durum <> 'tamamlandi' ORDER BY tarih LIMIT 15");
                $servis = $st->fetchAll();
            }
            return ['durum_dagilimi' => $dag, 'iadesi_geciken' => $gec['kiralamalar'] ?? [], 'acik_servis' => $servis];

        case 'servis_ozeti':
            if (!hasPermission('teknik_servis', 'oku')) return ['hata' => 'yetki yok'];
            [$bas, $bit] = _asDonem($g);
            require_once __DIR__ . '/servis-maliyet-lib.php';
            $_fy = smFiyatYetkisi($db) && subeKisitiHam() === null;
            $st = $db->prepare("SELECT makine_ad, tip, durum, COALESCE(maliyet,0) maliyet FROM teknik_servis WHERE tarih >= ? AND tarih <= ?");
            $st->execute([$bas, $bit]);
            $tip = []; $mak = []; $top = 0.0;
            foreach ($st->fetchAll() as $s) {
                $tip[$s['tip'] ?: 'diger'] = ($tip[$s['tip'] ?: 'diger'] ?? 0) + 1;
                $k = $s['makine_ad'] ?: '?';
                $mak[$k]['adet'] = ($mak[$k]['adet'] ?? 0) + 1;
                $mak[$k]['maliyet'] = ($mak[$k]['maliyet'] ?? 0) + (float)$s['maliyet'];
                $top += (float)$s['maliyet'];
            }
            uasort($mak, fn($a, $b) => $b['adet'] <=> $a['adet'] ?: $b['maliyet'] <=> $a['maliyet']);
            $enCok = []; foreach (array_slice($mak, 0, 10, true) as $k => $v) $enCok[] = $_fy ? ['makine' => $k, 'adet' => $v['adet'], 'maliyet' => round($v['maliyet'], 2)] : ['makine' => $k, 'adet' => $v['adet']];
            return $_fy ? ['bas' => $bas, 'bit' => $bit, 'tipe_gore' => $tip, 'toplam_maliyet' => round($top, 2), 'en_cok_servis' => $enCok]
                        : ['bas' => $bas, 'bit' => $bit, 'tipe_gore' => $tip, 'en_cok_servis' => $enCok, 'not' => 'Maliyet bilgisi yalnız fiyatlandırma yetkilisine gösterilir.'];

        case 'makine_detay':
            $turler = array_values(array_filter(['arac', 'manlift'], fn($t) => hasPermission($t, 'oku')));
            if (!$turler) return ['hata' => 'yetki yok'];
            $q = trArama((string)($g['makine'] ?? ''));
            if ($q === '') return ['hata' => 'Makine adı/kodu verilmedi.'];
            $bulunan = [];
            foreach (makineListesi($db) as $m) {
                if (!in_array($m['tur'], $turler, true)) continue;
                if ($sube !== null && trim((string)$m['sube']) !== $sube) continue;
                if (str_contains(trArama($m['ad'] . ' ' . ($m['model'] ?? '') . ' ' . ($m['makine_kodu'] ?? '')), $q)) $bulunan[] = $m;
            }
            if (!$bulunan) return ['hata' => 'Bu adla makine bulunamadı.'];
            if (count($bulunan) > 1) {
                $tam = array_values(array_filter($bulunan, fn($m) => trArama((string)$m['kod']) === $q));
                if (count($tam) !== 1) return ['birden_fazla_eslesme' => array_slice(array_column($bulunan, 'ad'), 0, 15), 'not' => 'Kullanıcıya hangisini kastettiğini sor.'];
                $bulunan = $tam;
            }
            $m = $bulunan[0];
            $out = ['makine' => $m['ad'], 'tur' => $m['tur'], 'marka' => $m['marka'], 'model' => $m['model'] ?? null,
                    'sube' => $m['sube'], 'durum' => $m['durum'], 'aktif' => (bool)$m['aktif'], 'metraj' => $m['metraj'],
                    'dis_kiralama' => isset($m['dk']) ? $m['dk'] : null];
            if (hasPermission('kiralama_takip', 'oku')) {
                $st = $db->prepare("SELECT k.kod, m.unvan musteri, k.baslangic, k.bitis, k.gercek_bitis FROM kiralamalar k JOIN musteriler m ON m.id=k.musteri_id
                                    WHERE k.makine_tur=? AND k.makine_id=? AND k.durum<>'iptal'" . ($sube !== null ? ' AND k.sube = ?' : '') . " ORDER BY k.baslangic DESC LIMIT 5");
                $st->execute($sube !== null ? [$m['tur'], $m['id'], $sube] : [$m['tur'], $m['id']]);
                $out['son_kiralamalar'] = $st->fetchAll();
            }
            if (hasPermission('teknik_servis', 'oku')) {
                require_once __DIR__ . '/servis-maliyet-lib.php';
                $st = $db->prepare("SELECT tarih, tip, durum, ariza, yapilan" . (smFiyatYetkisi($db) && subeKisitiHam() === null ? ', maliyet' : '') . " FROM teknik_servis WHERE makine_tur=? AND makine_id=? ORDER BY tarih DESC, id DESC LIMIT 5");
                $st->execute([$m['tur'], $m['id']]);
                $out['son_servisler'] = $st->fetchAll();
            }
            if (in_array($m['tur'], _asKarlilikTurleri(), true)) {
                foreach (analizMakineKarlilik($db, date('Y-01-01'), date('Y-m-d'), $sube, [$m['tur']])['makineler'] as $r) {
                    if ($r['id'] !== (int)$m['id']) continue;
                    $out['bu_yil_karlilik'] = ['gelir' => $r['gelir'], 'maliyet' => $r['toplam_maliyet'], 'net' => $r['net'],
                        'doluluk' => $r['doluluk'], 'bos_gun' => $r['bos_gun'], 'oneriler' => array_column($r['oneriler'], 'metin')];
                }
            }
            return $out;

        case 'teklif_ozeti':
            if (!hasPermission('kiralama_takip', 'oku')) return ['hata' => 'yetki yok'];
            [$bas, $bit] = _asDonem($g);
            $where = ['t.tarih >= ?', 't.tarih <= ?']; $params = [$bas, $bit];
            subeKosulEkle($where, $params, 't.sube');
            $st = $db->prepare('SELECT t.*, m.unvan musteri FROM teklifler t LEFT JOIN musteriler m ON m.id=t.musteri_id WHERE ' . implode(' AND ', $where) . ' ORDER BY t.tarih DESC');
            $st->execute($params);
            $teklifler = $st->fetchAll();
            $kal = $db->prepare('SELECT miktar, sure, birim_ucret FROM teklif_kalemleri WHERE teklif_id=?');
            $dag = []; $acik = [];
            foreach ($teklifler as $t) {
                $dag[$t['durum'] ?: 'taslak'] = ($dag[$t['durum'] ?: 'taslak'] ?? 0) + 1;
                if (in_array($t['durum'], ['donustu', 'iptal', 'reddedildi', 'kaybedildi'], true)) continue;
                $kal->execute([$t['id']]);
                $acik[] = ['kod' => $t['kod'], 'musteri' => $t['musteri'], 'tarih' => $t['tarih'], 'gecerlilik' => $t['gecerlilik'], 'durum' => $t['durum'],
                           'toplam_kdv_dahil' => teklifToplam($kal->fetchAll(), (float)$t['kdv_oran'], (float)$t['iskonto_oran'])['genel_toplam'],
                           'suresi_gecti' => !empty($t['gecerlilik']) && $t['gecerlilik'] < date('Y-m-d')];
            }
            $n = count($teklifler);
            return ['bas' => $bas, 'bit' => $bit, 'toplam_teklif' => $n, 'durum_dagilimi' => $dag,
                    'donusum_orani_yuzde' => $n ? round(($dag['donustu'] ?? 0) / $n * 100, 1) : null,
                    'acik_teklifler' => array_slice($acik, 0, $limit($g['limit'] ?? 0, 30, 10)), 'acik_teklif_adet' => count($acik)];

        case 'bekleyen_isler':
            $harita = ['tutanak_kabul' => 'kiralama_takip', 'tutanak_duzeltme' => 'kiralama_takip', 'teknik_servis' => 'teknik_servis',
                'satinalma' => 'satinalma', 'kiralama' => 'kiralama_takip', 'operator_bekliyor' => 'kiralama_takip', 'toplama_gecikti' => 'kiralama_takip',
                'tahsilat_gecikti' => 'kiralama_takip', 'fatura_bekliyor' => 'kiralama_takip', 'bakim_cakismasi' => 'kiralama_takip', 'musteri_talep' => 'kiralama_takip',
                'portal_uzatma' => 'kiralama_takip', 'portal_iade' => 'kiralama_takip', 'portal_toplama' => 'kiralama_takip', 'teklif_karar' => 'kiralama_takip'];
            $istisna = [];
            foreach ($db->query("SELECT kaynak_modul, COUNT(*) n FROM gorevler WHERE durum='acik' AND kaynak_modul IS NOT NULL AND kaynak_modul<>'' GROUP BY kaynak_modul")->fetchAll() as $r) {
                $mod = $harita[$r['kaynak_modul']] ?? null;
                if ($mod && hasPermission($mod, 'oku')) $istisna[$r['kaynak_modul']] = (int)$r['n'];
            }
            $kendi = [];
            $pid = 0;
            try { $st = $db->prepare('SELECT personel_id FROM users WHERE id=?'); $st->execute([(int)($_SESSION['user_id'] ?? 0)]); $pid = (int)$st->fetchColumn(); } catch (\Throwable $e) {}
            if ($pid > 0) {
                $st = $db->prepare("SELECT baslik, son_tarih, oncelik FROM gorevler WHERE durum='acik' AND atanan_id=? ORDER BY (son_tarih IS NULL), son_tarih LIMIT 15");
                $st->execute([$pid]);
                $kendi = $st->fetchAll();
            }
            return ['bana_atanan_acik_gorevler' => $kendi, 'acik_istisnalar_modul_bazinda' => $istisna,
                    'not' => 'İstisna ayrıntıları Dikkat Gerekenler ekranında; görevler Görevler ekranında.'];
    }
    return ['hata' => 'Bilinmeyen araç: ' . $ad];
}

function _asSayfaBaglami(PDO $db, array $b): string {
    $SAYFALAR = ['index.html' => 'Dashboard', 'kiralama-takip.html' => 'Kiralama Takip', 'musteriler.html' => 'Müşteriler',
        'musteri-detay.html' => 'Müşteri detayı', 'arac.html' => 'Araç listesi', 'arac-detay.html' => 'Araç detayı',
        'manlift.html' => 'Makine listesi', 'manlift-detay.html' => 'Makine detayı', 'hakedisler.html' => 'Hakedişler',
        'teklifler.html' => 'Teklifler', 'teknik-servis.html' => 'Teknik Servis', 'nakliye.html' => 'Nakliye',
        'dis-kiralama.html' => 'Dış Kiralama', 'musaitlik.html' => 'Müsaitlik Takvimi', 'rapor.html' => 'Raporlar',
        'yonetim.html' => 'Yönetim Paneli', 'finans.html' => 'Finans', 'cari-ekstre.html' => 'Cari ekstre',
        'iade-tutanaklari.html' => 'İade Tutanakları', 'hasar-degerlendirme.html' => 'Hasar değerlendirme arşivi', 'istisnalar.html' => 'Dikkat Gerekenler', 'depo-isleri.html' => 'Yükleme / Toplama'];
    $sayfa = basename((string)($b['sayfa'] ?? ''));
    if (!isset($SAYFALAR[$sayfa])) return '';
    $metin = 'kullanıcı "' . $SAYFALAR[$sayfa] . '" ekranında';
    $id = (int)($b['id'] ?? 0);
    if ($id > 0 && in_array($sayfa, ['musteri-detay.html', 'cari-ekstre.html'], true) && hasPermission('kiralama_takip', 'oku')) {
        $kapsam = analizMusteriKapsami($db, subeKisiti());
        if ($kapsam === null || in_array($id, $kapsam, true)) {
            $st = $db->prepare('SELECT unvan FROM musteriler WHERE id=?'); $st->execute([$id]);
            if ($u = $st->fetchColumn()) $metin .= '; açık kayıt müşteri: "' . $u . '" ("bu müşteri" bunu kasteder)';
        }
    }
    if ($id > 0 && in_array($sayfa, ['arac-detay.html', 'manlift-detay.html'], true)) {
        $tur = $sayfa === 'arac-detay.html' ? 'arac' : 'manlift';
        if (hasPermission($tur, 'oku')) {
            $st = $db->prepare($tur === 'arac' ? 'SELECT plaka, sube FROM araclar WHERE id=?' : 'SELECT kod, sube FROM manliftler WHERE id=?');
            $st->execute([$id]);
            $r = $st->fetch(PDO::FETCH_NUM);
            if ($r && (subeKisiti() === null || trim((string)$r[1]) === subeKisiti())) $metin .= '; açık kayıt makine: "' . $r[0] . '" ("bu makine" bunu kasteder)';
        }
    }
    return $metin;
}

if ($action === 'sor') {
    $soru = trim((string)($body['soru'] ?? ''));
    if ($soru === '') jsonError('Bir soru yazın.');
    if (mb_strlen($soru) > 2000) jsonError('Soru çok uzun (en fazla 2000 karakter).');
    $araclar = _asAraclar();
    if (!$araclar) jsonError('Asistanın kullanabileceği bir veri yetkiniz yok (Kiralama Takip, Raporlar, Araç/Makine veya Teknik Servis).', 403);

    $mesajlar = [];
    foreach (array_slice(is_array($body['gecmis'] ?? null) ? $body['gecmis'] : [], -10) as $m) {
        $rol = ($m['rol'] ?? '') === 'asistan' ? 'assistant' : (($m['rol'] ?? '') === 'kullanici' ? 'user' : null);
        $metin = mb_substr(trim((string)($m['metin'] ?? '')), 0, 4000);
        if (!$rol || $metin === '') continue;
        if ($mesajlar && end($mesajlar)['role'] === $rol) continue;
        if (!$mesajlar && $rol !== 'user') continue;
        $mesajlar[] = ['role' => $rol, 'content' => $metin];
    }
    if ($mesajlar && end($mesajlar)['role'] === 'user') array_pop($mesajlar);
    $baglam = _asSayfaBaglami($db, is_array($body['baglam'] ?? null) ? $body['baglam'] : []);
    $mesajlar[] = ['role' => 'user', 'content' => ($baglam !== '' ? "[Sayfa bağlamı: $baglam]\n" : '') . $soru];

    $kullanici = trim((string)($_SESSION['ad_soyad'] ?? $_SESSION['username'] ?? ''));
    $sistem = "Sen Taloo kiralama yönetim panelinin analiz asistanısın. Firma iş makinesi (manlift, araç) kiralıyor.\n"
        . "Bugün: " . date('Y-m-d') . ". Kullanıcı: " . ($kullanici ?: 'panel kullanıcısı')
        . (subeKisiti() ? ' (yalnız ' . subeKisiti() . ' şubesini görebilir)' : '') . ".\n"
        . "Kurallar:\n"
        . "- Sayısal her bilgiyi araçlardan al; rakam, müşteri veya makine uydurma. Araç veri döndürmediyse bunu açıkça söyle.\n"
        . "- Tarih belirtilmemişse makul bir dönem seç (ör. bu ay, bu yıl) ve cevapta hangi dönemi kullandığını yaz.\n"
        . "- 'Faturalanmamış iş' alacak değildir; açık alacak yalnız faturalı ve ödenmemiş tutardır.\n"
        . "- Cevaplar Türkçe, kısa ve net olsun: önce doğrudan cevap, sonra gerekirse madde işaretli ayrıntı veya küçük bir tablo (Markdown). Tutarları 1.234.567 ₺ biçiminde yaz.\n"
        . "- Kullanıcının yetkisi olmayan konuda (araç listende yoksa) veri olmadığını söyle, tahmin etme.\n"
        . "- Yalnız okuma yapabilirsin; kayıt değiştirme isteklerinde ilgili ekranı öner.\n"
        . "- Mesajın başındaki [Sayfa bağlamı: …] kullanıcının hangi ekranda olduğunu söyler; 'bu müşteri/bu makine' gibi ifadeleri buna göre çöz.";

    $kullanilan = []; $usage = ['input_tokens' => 0, 'output_tokens' => 0, 'cache_read_input_tokens' => 0];
    $cevap = '';
    try {
        for ($tur = 0; $tur < 8; $tur++) {
            $y = claudeIstek($ayar, [
                'system' => $sistem,
                'tools' => $araclar,
                'messages' => $mesajlar,
                'max_tokens' => 16000,
                'cache_control' => ['type' => 'ephemeral'],
            ], 'medium');
            foreach (['input_tokens', 'output_tokens', 'cache_read_input_tokens'] as $u) $usage[$u] += (int)($y['usage'][$u] ?? 0);
            $mesajlar[] = ['role' => 'assistant', 'content' => $y['content']];
            if (($y['stop_reason'] ?? '') !== 'tool_use') { $cevap = claudeMetin($y); break; }
            $sonuclar = [];
            foreach ($y['content'] as $b) {
                if (($b['type'] ?? '') !== 'tool_use') continue;
                $kullanilan[] = $b['name'];
                $girdi = is_array($b['input'] ?? null) ? $b['input'] : [];
                try { $cikti = _asAracCalistir($db, (string)$b['name'], $girdi); $hata = isset($cikti['hata']); }
                catch (\Throwable $e) { $cikti = ['hata' => 'Araç çalıştırılamadı.']; $hata = true; }
                $metin = json_encode($cikti, JSON_UNESCAPED_UNICODE);
                if (strlen($metin) > 60000) $metin = mb_strcut($metin, 0, 60000) . '… (kısaltıldı; daha dar filtre/limit kullan)';
                $sonuclar[] = ['type' => 'tool_result', 'tool_use_id' => $b['id'], 'content' => $metin] + ($hata ? ['is_error' => true] : []);
            }
            $mesajlar[] = ['role' => 'user', 'content' => $sonuclar];
        }
    } catch (\RuntimeException $e) {
        claudeKullanimYaz($db, 'sor', $ayar['model'], $usage, 'hata');
        jsonError($e->getMessage(), 502);
    }
    claudeKullanimYaz($db, 'sor', $ayar['model'], $usage);
    if ($cevap === '') $cevap = 'Soruyu yanıtlamak için çok fazla adım gerekti. Soruyu daraltıp (dönem, müşteri veya makine belirterek) tekrar deneyin.';
    jsonOk(['cevap' => $cevap, 'araclar' => array_values(array_unique($kullanilan))]);
}

jsonError('Geçersiz işlem.');
