<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tutanak_kurallari.php';
require_once __DIR__ . '/referans-lib.php';
require_once __DIR__ . '/tutanak-yaz.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$db     = getDb();

function _itSilHedef(PDO $db): int {
    if (($_SESSION['rol'] ?? '') !== 'admin') jsonError('Tutanakları yalnız yönetici silebilir. Hatalı tutanak için gerekçeli düzeltme talebi açın.', 403);
    require_once __DIR__ . '/kiralama-operasyon-lib.php';
    $tid = (int)($_GET['id'] ?? 0);
    $q = $db->prepare('SELECT kiralama_id FROM iade_tutanaklari WHERE id=?'); $q->execute([$tid]);
    $kid = $q->fetchColumn();
    if ($kid === false) jsonError('Tutanak bulunamadı.', 404);
    subeKayitDogrula($db, 'kiralamalar', (int)$kid);
    return $tid;
}

if ($method === 'GET' && $action === 'sil_onizle') {
    requireAuth();
    requirePermission('kiralama_takip');
    $tid = _itSilHedef($db);
    $db->beginTransaction();
    try { $r = tutanakTamSil($db, $tid); }
    finally { if ($db->inTransaction()) $db->rollBack(); }
    jsonOk(['belge_no' => $r['tutanak']['belge_no'] ?? null, 'ozet' => kiralamaSilOzet($r['sayim'])]);
}

function _aiFotoOku(string $dosyaYolu): ?array {
    $tam = UPLOAD_PATH . $dosyaYolu;
    if (!is_file($tam)) return null;
    $binary = @file_get_contents($tam);
    if ($binary === false) return null;
    $ext = strtolower(pathinfo($dosyaYolu, PATHINFO_EXTENSION));
    $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? null;
    if (!$mime) return null;
    return ['mime' => $mime, 'b64' => base64_encode($binary)];
}

const AI_BILESENLER = ['tekerlek','korkuluk','joystick','boya','aku','kaporta','destek_ayaklari'];

function aiHasarPrompt(): string {
    return "Bir iş makinesi kiralama firmasında (araç / manlift) iade-teslim muayenesi yapan deneyimli bir ekspertizsin.\n"
    . "Fotoğraflar etiketlidir: 'Ö' ile başlayanlar ÖNCEKİ tutanağın, 'Ş' ile başlayanlar ŞİMDİKİ tutanağın fotoğraflarıdır. "
    . "Aynı numara aynı açıyı gösterir (Ö2 ile Ş2 aynı açı: ör. 'Sağ Yan'). Karşılığı olmayan fotoğraflar da etiketlidir.\n\n"
    . "GÖREVLER:\n"
    . "1) HASAR TESPİTİ: Her açıyı önceki hâliyle kıyasla. Gördüğün HER hasarı ayrı madde olarak yaz: çizik, göçük, kırık, çatlak, "
    . "eksik parça, sızıntı (yağ/hidrolik), yırtık, aşınma, pas, boya hasarı. Her madde için: bölge (açı adı), parça, tür, şiddet, "
    . "'yeni' (önceki fotoğrafta YOKSA true; önceden de varsa false; önceki açı yoksa/kıyaslanamıyorsa 'belirsiz'), yaklaşık konum ve "
    . "boyut, hangi fotoğraf etiketinde görüldüğü, önerilen işlem ve bu madde için güvenin.\n"
    . "   Kir, toz, çamur, yansıma, ışık ve açı farkını HASAR SAYMA. Emin değilsen güveni 'dusuk' yaz; hasar uydurma.\n"
    . "2) GÖSTERGE: Kilometre sayacı veya çalışma saati göstergesi görünüyorsa RAKAMI oku; net değilse null bırak — TAHMİN ETME.\n"
    . "3) ARIZA KODU: Kumanda panelinde/ekranında hata/arıza kodu görünüyorsa (ör. F01.05, E-16) OLDUĞU GİBİ yaz; seri no, model "
    . "veya sayaç değerini kod sanma.\n"
    . "4) EKSİK AÇILAR ve KALİTE: Kıyas için gerekli olup çekilmemiş ya da karşılığı olmayan açıları, bulanık/karanlık/uzak "
    . "çekilmiş fotoğrafları etiketiyle belirt.\n"
    . "5) SORUMLULUK: Yeni hasarlar kiralama döneminde mi oluşmuş görünüyor? 'muhtemel' / 'belirsiz' / 'yok' ve kısa gerekçe. "
    . "Bu hukuki karar değildir; yalnız görsel kanıta dayalı ön değerlendirmedir.\n"
    . "6) KONTROL LİSTESİ: Fotoğraftan anlaşılamayan ama teknisyenin makinede elle bakması gereken 1-6 somut madde "
    . "(ör. 'Sol arka hidrolik hortum bağlantısında sızıntı kontrolü').\n"
    . "7) BİLEŞEN ÖZETİ: Forma aktarılacak özet için yalnız şu anahtarları kullan: " . implode(', ', AI_BILESENLER)
    . " (değer: hasarsiz | az_hasarli | cok_hasarli; fotoğrafta görünmeyen bileşen için gorulmedi).\n\n"
    . "Tüm metinler Türkçe olsun. 'ozet' 2-4 cümle; önce en önemli yeni hasarı söyle.";
}

function aiHasarSema(): array {
    $str = ['type' => 'string'];
    return [
        'type' => 'object',
        'properties' => [
            'yeni_hasar_var' => ['type' => 'boolean'],
            'ozet' => $str,
            'guven' => ['type' => 'string', 'enum' => ['yuksek', 'orta', 'dusuk']],
            'hasarlar' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'bolge' => $str, 'parca' => $str,
                'tur' => ['type' => 'string', 'enum' => ['cizik', 'gocuk', 'kirik', 'catlak', 'eksik_parca', 'sizinti', 'yirtik', 'asinma', 'pas', 'boya_hasari', 'diger']],
                'siddet' => ['type' => 'string', 'enum' => ['hafif', 'orta', 'agir']],
                'yeni' => ['type' => 'string', 'enum' => ['evet', 'hayir', 'belirsiz']],
                'konum' => $str, 'boyut' => $str, 'foto' => $str,
                'onerilen_islem' => ['type' => 'string', 'enum' => ['kontrol', 'temizlik', 'boya_rotus', 'onarim', 'parca_degisimi', 'servis']],
                'guven' => ['type' => 'string', 'enum' => ['yuksek', 'orta', 'dusuk']],
            ], 'required' => ['bolge', 'parca', 'tur', 'siddet', 'yeni', 'konum', 'boyut', 'foto', 'onerilen_islem', 'guven'], 'additionalProperties' => false]],
            'eksik_acilar' => ['type' => 'array', 'items' => $str],
            'foto_sorunlari' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['foto' => $str, 'sorun' => $str],
                'required' => ['foto', 'sorun'], 'additionalProperties' => false]],
            'sorumluluk' => ['type' => 'object', 'properties' => [
                'degerlendirme' => ['type' => 'string', 'enum' => ['muhtemel', 'belirsiz', 'yok']], 'gerekce' => $str],
                'required' => ['degerlendirme', 'gerekce'], 'additionalProperties' => false],
            'kontrol_listesi' => ['type' => 'array', 'items' => $str],
            'bilesenler' => ['type' => 'object', 'properties' => array_fill_keys(AI_BILESENLER,
                ['type' => 'string', 'enum' => ['hasarsiz', 'az_hasarli', 'cok_hasarli', 'gorulmedi']]),
                'required' => AI_BILESENLER, 'additionalProperties' => false],
            'ariza_kodlari' => ['type' => 'array', 'items' => $str],
            'km' => ['anyOf' => [['type' => 'number'], ['type' => 'null']]],
            'calisma_saati' => ['anyOf' => [['type' => 'number'], ['type' => 'null']]],
        ],
        'required' => ['yeni_hasar_var', 'ozet', 'guven', 'hasarlar', 'eksik_acilar', 'foto_sorunlari', 'sorumluluk',
                       'kontrol_listesi', 'bilesenler', 'ariza_kodlari', 'km', 'calisma_saati'],
        'additionalProperties' => false,
    ];
}

function aiHasarJsonTarifi(): string {
    return "\n\nYALNIZCA şu JSON'u döndür, başka metin yazma:\n"
    . "{\"yeni_hasar_var\": bool, \"ozet\": \"...\", \"guven\": \"yuksek|orta|dusuk\",\n"
    . " \"hasarlar\": [{\"bolge\":\"Sağ Yan\",\"parca\":\"arka çamurluk\",\"tur\":\"cizik|gocuk|kirik|catlak|eksik_parca|sizinti|yirtik|asinma|pas|boya_hasari|diger\","
    . "\"siddet\":\"hafif|orta|agir\",\"yeni\":\"evet|hayir|belirsiz\",\"konum\":\"...\",\"boyut\":\"~10 cm\",\"foto\":\"Ş3\","
    . "\"onerilen_islem\":\"kontrol|temizlik|boya_rotus|onarim|parca_degisimi|servis\",\"guven\":\"yuksek|orta|dusuk\"}],\n"
    . " \"eksik_acilar\": [\"...\"], \"foto_sorunlari\": [{\"foto\":\"Ş2\",\"sorun\":\"bulanık\"}],\n"
    . " \"sorumluluk\": {\"degerlendirme\":\"muhtemel|belirsiz|yok\",\"gerekce\":\"...\"}, \"kontrol_listesi\": [\"...\"],\n"
    . " \"bilesenler\": {\"tekerlek\":\"hasarsiz|az_hasarli|cok_hasarli\", ...}, \"ariza_kodlari\": [\"...\"], \"km\": sayı|null, \"calisma_saati\": sayı|null}";
}

function _aiSonucAyikla(string $ham): array {
    $temiz = trim($ham);
    if (preg_match('/```(?:json)?\s*(.+?)```/s', $temiz, $m)) $temiz = trim($m[1]);
    $b = strpos($temiz, '{'); $e = strrpos($temiz, '}');
    if ($b === false || $e === false || $e <= $b) {
        return ['ozet' => $ham, 'yapisal' => false];
    }
    $veri = json_decode(substr($temiz, $b, $e - $b + 1), true);
    if (!is_array($veri)) return ['ozet' => $ham, 'yapisal' => false];

    $enum = fn($v, array $izin, $vars) => in_array($v, $izin, true) ? $v : $vars;
    $kisa = fn($v, int $n = 200) => mb_substr(trim(is_scalar($v) ? (string)$v : ''), 0, $n);
    $cikti = [
        'yapisal'         => true,
        'yeni_hasar_var'  => !empty($veri['yeni_hasar_var']),
        'ozet'            => trim((string)($veri['ozet'] ?? '')) ?: $ham,
        'guven'           => $enum($veri['guven'] ?? '', ['yuksek','orta','dusuk'], 'orta'),
        'bilesenler'      => [],
        'ariza_kodlari'   => [],
        'km'              => null,
        'calisma_saati'   => null,
        'hasarlar'        => [],
        'eksik_acilar'    => [],
        'foto_sorunlari'  => [],
        'sorumluluk'      => null,
        'kontrol_listesi' => [],
    ];

    if (is_array($veri['bilesenler'] ?? null)) {
        $dereceler = ['hasarsiz','az_hasarli','cok_hasarli'];
        foreach ($veri['bilesenler'] as $ad => $derece) {
            if (!in_array($ad, AI_BILESENLER, true)) continue;
            if (!in_array($derece, $dereceler, true)) continue;
            $cikti['bilesenler'][$ad] = $derece;
        }
    }

    if (is_array($veri['hasarlar'] ?? null)) {
        foreach (array_slice($veri['hasarlar'], 0, 30) as $h) {
            if (!is_array($h)) continue;
            $foto = mb_strtoupper($kisa($h['foto'] ?? '', 6));
            $foto = str_replace(['O', 'S'], ['Ö', 'Ş'], $foto);
            $cikti['hasarlar'][] = [
                'bolge'  => $kisa($h['bolge'] ?? '', 60) ?: 'Belirtilmemiş',
                'parca'  => $kisa($h['parca'] ?? '', 80) ?: 'Belirtilmemiş',
                'tur'    => $enum($h['tur'] ?? '', ['cizik','gocuk','kirik','catlak','eksik_parca','sizinti','yirtik','asinma','pas','boya_hasari','diger'], 'diger'),
                'siddet' => $enum($h['siddet'] ?? '', ['hafif','orta','agir'], 'orta'),
                'yeni'   => $enum(is_bool($h['yeni'] ?? null) ? (($h['yeni']) ? 'evet' : 'hayir') : ($h['yeni'] ?? ''), ['evet','hayir','belirsiz'], 'belirsiz'),
                'konum'  => $kisa($h['konum'] ?? ''),
                'boyut'  => $kisa($h['boyut'] ?? '', 60),
                'foto'   => preg_match('/^[ÖŞ]\d{1,2}$/u', $foto) ? $foto : '',
                'onerilen_islem' => $enum($h['onerilen_islem'] ?? '', ['kontrol','temizlik','boya_rotus','onarim','parca_degisimi','servis'], 'kontrol'),
                'guven'  => $enum($h['guven'] ?? '', ['yuksek','orta','dusuk'], 'orta'),
            ];
        }
    }
    foreach (['eksik_acilar' => 12, 'kontrol_listesi' => 8] as $alan => $max) {
        if (!is_array($veri[$alan] ?? null)) continue;
        foreach (array_slice($veri[$alan], 0, $max) as $v) if (($v = $kisa($v)) !== '') $cikti[$alan][] = $v;
    }
    if (is_array($veri['foto_sorunlari'] ?? null)) {
        foreach (array_slice($veri['foto_sorunlari'], 0, 12) as $f) {
            if (is_array($f) && ($s = $kisa($f['sorun'] ?? '')) !== '') $cikti['foto_sorunlari'][] = ['foto' => $kisa($f['foto'] ?? '', 6), 'sorun' => $s];
        }
    }
    if (is_array($veri['sorumluluk'] ?? null)) {
        $cikti['sorumluluk'] = ['degerlendirme' => $enum($veri['sorumluluk']['degerlendirme'] ?? '', ['muhtemel','belirsiz','yok'], 'belirsiz'),
                                'gerekce' => $kisa($veri['sorumluluk']['gerekce'] ?? '', 400)];
    }
    foreach ($cikti['hasarlar'] as $h) if ($h['yeni'] === 'evet') { $cikti['yeni_hasar_var'] = true; break; }

    $sayi = function ($v, float $ustSinir) {
        if ($v === null || $v === '' || !is_numeric($v)) return null;
        $n = (float)$v;
        return ($n >= 0 && $n <= $ustSinir) ? round($n, 1) : null;
    };
    if (is_array($veri['ariza_kodlari'] ?? null)) {
        foreach ($veri['ariza_kodlari'] as $kod) {
            if (!is_string($kod)) continue;
            $kod = strtoupper(trim($kod));
            if ($kod === '' || !preg_match('/^[A-Z0-9][A-Z0-9.\-]{0,11}$/', $kod)) continue;
            if (!in_array($kod, $cikti['ariza_kodlari'], true)) $cikti['ariza_kodlari'][] = $kod;
            if (count($cikti['ariza_kodlari']) >= 6) break;
        }
    }

    $cikti['km']            = $sayi($veri['km'] ?? null, 3000000);
    $cikti['calisma_saati'] = $sayi($veri['calisma_saati'] ?? null, 200000);

    return $cikti;
}

function _aiArizaKoduEslestir(PDO $db, array $kodlar, ?string $marka): array {
    $eslesen = []; $eslesmeyen = [];
    foreach ($kodlar as $kod) {
        $where  = ['aktif=1', 'UPPER(kod) = ?'];
        $params = [$kod];
        if ($marka !== null && trim($marka) !== '') {
            $where[] = "(instr(LOWER(?), LOWER(marka)) > 0 OR LOWER(marka) = LOWER(?))";
            $params[] = $marka; $params[] = $marka;
        }
        try {
            $st = $db->prepare("SELECT MIN(id) AS id, marka, kategori, kod, aciklama FROM ariza_kodlari"
                . " WHERE " . implode(' AND ', $where) . " GROUP BY marka, kod, aciklama LIMIT 3");
            $st->execute($params);
            $bulunan = $st->fetchAll();
        } catch (\Exception $e) { $bulunan = []; }
        if ($bulunan) foreach ($bulunan as $b) $eslesen[] = $b;
        else $eslesmeyen[] = $kod;
    }
    return ['eslesen' => $eslesen, 'eslesmeyen' => $eslesmeyen];
}

function aiFotoEsle(array $onceki, array $yeni): array {
    $norm = fn($a) => mb_strtolower(trim((string)preg_replace('/^.*·\s*/u', '', (string)$a)));
    $oncekiAci = [];
    foreach ($onceki as $f) { $k = $norm($f['aci_tipi'] ?? ''); if ($k !== '' && !isset($oncekiAci[$k])) $oncekiAci[$k] = $f; }
    $out = []; $n = 0; $kullanilan = [];
    foreach ($yeni as $f) {
        if ($n >= 10) break;
        $k = $norm($f['aci_tipi'] ?? '');
        $aci = trim((string)($f['aci_tipi'] ?? '')) ?: 'Genel';
        $n++;
        if ($k !== '' && isset($oncekiAci[$k]) && !isset($kullanilan[$k])) {
            $kullanilan[$k] = true;
            $out[] = ['etiket' => "Ö$n", 'set' => 'onceki', 'aci' => $aci, 'dosya' => $oncekiAci[$k]['dosya_yolu']];
        }
        $out[] = ['etiket' => "Ş$n", 'set' => 'yeni', 'aci' => $aci, 'dosya' => $f['dosya_yolu']];
    }
    $ek = 0;
    foreach ($oncekiAci as $k => $f) {
        if (isset($kullanilan[$k]) || $ek >= 4) continue;
        $ek++; $n++;
        $out[] = ['etiket' => "Ö$n", 'set' => 'onceki', 'aci' => trim((string)$f['aci_tipi']) ?: 'Genel', 'dosya' => $f['dosya_yolu']];
    }
    return $out;
}

function _aiIcerik(array $etiketli, callable $gorsel): array {
    $c = [];
    foreach ($etiketli as $f) {
        $img = _aiFotoOku($f['dosya']);
        if (!$img) continue;
        $c[] = ['type' => 'text', 'text' => '[' . $f['etiket'] . '] ' . ($f['set'] === 'onceki' ? 'ÖNCEKİ' : 'ŞİMDİKİ') . ' — ' . $f['aci']];
        $c[] = $gorsel($img);
    }
    return $c;
}

function _aiAnaliziClaude(PDO $db, string $apiKey, ?string $model, array $etiketli): string {
    require_once __DIR__ . '/claude.php';
    $ayar = ['anahtar' => $apiKey, 'model' => $model ?: CLAUDE_VARSAYILAN_MODEL];
    $content = _aiIcerik($etiketli, fn($img) => ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $img['mime'], 'data' => $img['b64']]]);
    $content[] = ['type' => 'text', 'text' => aiHasarPrompt()];
    try {
        $y = claudeIstek($ayar, [
            'max_tokens' => 16000,
            'messages' => [['role' => 'user', 'content' => $content]],
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => aiHasarSema()]],
        ], 'high', 180);
    } catch (\RuntimeException $e) {
        claudeKullanimYaz($db, 'hasar_tespit', $ayar['model'], [], 'hata');
        throw new \Exception($e->getMessage());
    }
    claudeKullanimYaz($db, 'hasar_tespit', $ayar['model'], $y['usage'] ?? []);
    if (($y['stop_reason'] ?? '') === 'max_tokens') throw new \Exception('Yanıt yarıda kesildi; daha az fotoğrafla tekrar deneyin.');
    $metin = claudeMetin($y);
    if ($metin === '') throw new \Exception('Claude yanıtı beklenmedik biçimde döndü.');
    return $metin;
}

function _aiAnaliziOpenAI(string $apiKey, ?string $model, array $etiketli): string {
    $content = _aiIcerik($etiketli, fn($img) => ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $img['mime'] . ';base64,' . $img['b64']]]);
    $content[] = ['type' => 'text', 'text' => aiHasarPrompt() . aiHasarJsonTarifi()];

    $payload = [
        'model' => $model ?: 'gpt-4o-mini',
        'max_tokens' => 3000,
        'messages' => [['role' => 'user', 'content' => $content]],
    ];
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['content-type: application/json', 'authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT => 120,
    ]);
    $raw = curl_exec($ch);
    $httpKod = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlHata = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new \Exception('Bağlantı hatası: ' . $curlHata);
    $json = json_decode($raw, true);
    if ($httpKod !== 200) throw new \Exception('OpenAI API hatası (' . $httpKod . '): ' . ($json['error']['message'] ?? $raw));
    $metin = $json['choices'][0]['message']['content'] ?? null;
    if (!$metin) throw new \Exception('OpenAI yanıtı beklenmedik biçimde döndü.');
    return trim($metin);
}

if ($method === 'GET' && $action === 'makine_liste') {
    requireAuth();
    requirePermission('kiralama_takip');

    $q  = trim(metin($_GET['q'] ?? ''));
    $out = [];
    foreach ([['arac', 'araclar', 'plaka'], ['manlift', 'manliftler', 'kod']] as [$tur, $tablo, $adAlan]) {
        $sql = "SELECT id, $adAlan AS ad, marka, model, durum FROM $tablo";
        $par = [];
        $sql .= " WHERE COALESCE(durum,'') NOT IN ('satildi','hurda')";
        if ($q !== '') {
            $sql .= " AND ($adAlan LIKE ? OR " . trAramaSql('marka') . " LIKE ? OR " . trAramaSql('model') . " LIKE ?)";
            $like = '%' . trArama($q) . '%'; array_push($par, $like, $like, $like);
        }
        $sql .= " ORDER BY $adAlan COLLATE NOCASE";
        $st = $db->prepare($sql);
        $st->execute($par);
        foreach ($st->fetchAll() as $m) {
            $m['tur'] = $tur;
            $m['id']  = (int)$m['id'];
            $out[] = $m;
        }
    }

    $kir = [];
    $kSt = $db->query("
        SELECT k.makine_tur, k.makine_id, k.id, k.kod, k.ekipman_adi, k.baslangic, k.bitis,
               m.unvan AS musteri_unvan
        FROM kiralamalar k JOIN musteriler m ON m.id = k.musteri_id
        WHERE k.durum='aktif' AND k.gercek_bitis IS NULL AND k.makine_id IS NOT NULL
        ORDER BY k.baslangic DESC
    ");
    foreach ($kSt->fetchAll() as $k) {
        $anahtar = $k['makine_tur'] . ':' . (int)$k['makine_id'];
        if (!isset($kir[$anahtar])) $kir[$anahtar] = $k;
    }

    foreach ($out as &$m) {
        $m['aktif_kiralama'] = $kir[$m['tur'] . ':' . $m['id']] ?? null;
    }
    unset($m);

    usort($out, function ($a, $b) {
        $ak = $a['aktif_kiralama'] ? 0 : 1;
        $bk = $b['aktif_kiralama'] ? 0 : 1;
        if ($ak !== $bk) return $ak <=> $bk;
        return strcasecmp((string)$a['ad'], (string)$b['ad']);
    });

    jsonOk($out);
}

function _itGirisliYetki(PDO $db): array {
    requireAuth();
    $ofis = hasPermission('kiralama_takip');
    $saha = opOturumYetkisi($db, 'saha');
    if (!$ofis && !$saha) jsonError('Tutanak için kiralama ya da "Sahada teslim" yetkisi gerekir.', 403);
    $u = $db->prepare("SELECT p.id FROM users u JOIN personel p ON p.id = u.personel_id
                       WHERE u.id = ? AND COALESCE(p.durum,'aktif') = 'aktif'");
    $u->execute([(int)$_SESSION['user_id']]);
    return ['ofis' => $ofis, 'saha' => $saha, 'personel_id' => (int)($u->fetchColumn() ?: 0)];
}

if ($method === 'GET' && $action === 'makine') {
    _itGirisliYetki($db);
    $tur = trim(metin($_GET['tur'] ?? ''));
    $id  = (int)($_GET['id'] ?? 0);
    if (!in_array($tur, ['arac', 'manlift'], true) || $id < 1) jsonError('Geçersiz makine.');
    $tablo   = $tur === 'arac' ? 'araclar' : 'manliftler';
    $adAlan  = $tur === 'arac' ? 'plaka' : 'kod';
    $st = $db->prepare("SELECT id, $adAlan AS ad, marka, model FROM $tablo WHERE id=?");
    $st->execute([$id]);
    $makine = $st->fetch();
    if (!$makine) jsonError('Makine bulunamadı.', 404);
    $makine['tur'] = $tur;

    $kStmt = $db->prepare("
        SELECT k.id, k.kod, k.ekipman_adi, k.baslangic, k.bitis, k.gercek_bitis, k.lokasyon, m.unvan AS musteri_unvan
        FROM kiralamalar k JOIN musteriler m ON m.id = k.musteri_id
        WHERE k.makine_tur=? AND k.makine_id=? AND k.durum='aktif' AND k.gercek_bitis IS NULL
        ORDER BY k.baslangic DESC LIMIT 1
    ");
    $kStmt->execute([$tur, $id]);
    $aktifKiralama = $kStmt->fetch() ?: null;

    $gecmisKiralama = false;
    if (!$aktifKiralama) {
        $gStmt = $db->prepare("
            SELECT k.id, k.kod, k.ekipman_adi, k.baslangic, k.bitis, k.gercek_bitis, k.durum,
                   m.unvan AS musteri_unvan
            FROM kiralamalar k JOIN musteriler m ON m.id = k.musteri_id
            WHERE k.makine_tur=? AND k.makine_id=? AND k.durum<>'iptal'
            ORDER BY COALESCE(k.gercek_bitis, k.bitis, k.baslangic) DESC, k.id DESC LIMIT 1
        ");
        $gStmt->execute([$tur, $id]);
        $aktifKiralama = $gStmt->fetch() ?: null;
        if ($aktifKiralama) $gecmisKiralama = true;
    }

    $sonStmt = $db->prepare("
        SELECT t.id, t.tarih, t.islem, t.genel_durum
        FROM iade_tutanaklari t JOIN kiralamalar k ON k.id = t.kiralama_id
        WHERE k.makine_tur=? AND k.makine_id=?
          AND (? = 'teslim' OR (t.kiralama_id=? AND t.islem='teslim'))
        ORDER BY t.tarih DESC, t.id DESC LIMIT 1
    ");
    $referansIslem = ($_GET['islem'] ?? 'iade') === 'teslim' ? 'teslim' : 'iade';
    $sonStmt->execute([$tur, $id, $referansIslem, (int)($aktifKiralama['id'] ?? 0)]);
    $sonTutanak = $sonStmt->fetch() ?: null;
    if ($sonTutanak) {
        $fStmt = $db->prepare("SELECT id, aci_tipi, dosya_yolu FROM iade_tutanak_fotograflari WHERE tutanak_id=? ORDER BY id");
        $fStmt->execute([$sonTutanak['id']]);
        $sonTutanak['fotograflar'] = $fStmt->fetchAll();
    }

    $aksesuarRef = $aktifKiralama ? hzReferans($db, (int)$aktifKiralama['id'], ($_GET['islem'] ?? 'teslim')) : null;
    $sahaEkrani = opSahaEkrani($db, $tur, $id);
    jsonOk(['kurallar' => tutanakKurallari($tur), 'makine' => $makine,
        'gecmis_kiralama' => $gecmisKiralama, 'aktif_kiralama' => $aktifKiralama, 'son_tutanak' => $sonTutanak, 'aksesuar_referans'=> $aksesuarRef,
        'saha_ekrani' => $sahaEkrani, 'hazirlik_engeli' => $sahaEkrani ? null : opDepoEngeli($db, $tur, $id),
        'depo_isi' => !$sahaEkrani && ($dIs = opDepoIsi($db, $tur, $id)) ? ['kiralama_id' => $dIs['kiralama_id'], 'is' => $dIs['is']] : null,
        'referans' => refBilgi($db, $tur, $id)]);
}

if ($method === 'POST' && $action === 'taraf') {
    _itGirisliYetki($db);
    verifyCsrf();
    $body       = govdeOku();
    $kiralamaId = (int)($body['kiralama_id'] ?? 0);
    if ($kiralamaId < 1) jsonError('Kiralama seçilmelidir.');
    $kStmt = $db->prepare("SELECT k.lokasyon, k.sube, m.unvan AS musteri_unvan FROM kiralamalar k JOIN musteriler m ON m.id = k.musteri_id WHERE k.id=?");
    $kStmt->execute([$kiralamaId]);
    $taraf = $kStmt->fetch();
    if (!$taraf) jsonError('Kiralama bulunamadı.', 404);
    subeErisimDogrula($taraf['sube'] ?? null);
    jsonOk(['musteri_unvan' => $taraf['musteri_unvan'], 'lokasyon' => $taraf['lokasyon']]);
}

if ($method === 'GET' && $action === 'personel_liste') {
    $y = _itGirisliYetki($db);
    if (!$y['ofis']) {
        $st = $db->prepare("SELECT id, ad_soyad, pozisyon FROM personel WHERE id=?");
        $st->execute([$y['personel_id']]);
        jsonOk($st->fetchAll());
    }
    $st = $db->query("SELECT id, ad_soyad, pozisyon FROM personel WHERE durum='aktif' ORDER BY ad_soyad");
    jsonOk($st->fetchAll());
}

if ($method === 'GET' && $action === 'gecmis') {
    requireAuth();
    requirePermission('kiralama_takip');
    $kid = (int)($_GET['kiralama_id'] ?? 0);
    if ($kid < 1) jsonError('Geçersiz ID.');
    $st = $db->prepare("
        SELECT t.*, COALESCE(p.ad_soyad, t.imza_personel_ad) AS personel_ad
        FROM iade_tutanaklari t LEFT JOIN personel p ON p.id = t.personel_id
        WHERE t.kiralama_id=? ORDER BY t.tarih DESC, t.id DESC
    ");
    $st->execute([$kid]);
    $rows = $st->fetchAll();

    $ids = array_column($rows, 'id');
    $fotoMap = [];
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $fStmt = $db->prepare("SELECT id, tutanak_id, aci_tipi, dosya_yolu, aciklama FROM iade_tutanak_fotograflari WHERE tutanak_id IN ($ph) ORDER BY id");
        $fStmt->execute($ids);
        foreach ($fStmt->fetchAll() as $f) $fotoMap[(int)$f['tutanak_id']][] = ['id' => (int)$f['id'], 'aci_tipi' => $f['aci_tipi'], 'dosya_yolu' => $f['dosya_yolu'], 'aciklama' => $f['aciklama']];
    }
    $itVar = (bool)array_filter($rows, fn($x) => $x['inceleme_sonucu'] !== null);
    foreach ($rows as &$r) { $r['fotograflar'] = $fotoMap[(int)$r['id']] ?? []; $r += bzListeBayraklari($r, $itVar); }
    unset($r);
    jsonOk($rows);
}

if ($method === 'GET' && $action === 'zincir') {
    requireAuth();
    requirePermission('kiralama_takip');
    jsonOk(bzZincirListe($db, ['kiralama_id' => (int)($_GET['kiralama_id'] ?? 0), 'q' => metin($_GET['q'] ?? ''),
        'tarih_bas' => dateStr('tarih_bas', $_GET) ?? '', 'tarih_bit' => dateStr('tarih_bit', $_GET) ?? '', 'durum' => in_array($_GET['durum'] ?? '', ['iade_bekleyen', 'hasarli'], true) ? $_GET['durum'] : '']));
}
if ($method === 'GET' && $action === 'liste') {
    requireAuth();
    requirePermission('kiralama_takip');
    $where = []; $params = [];
    if (($tb = trim(metin($_GET['tarih_bas'] ?? ''))) !== '') { $where[] = "t.tarih>=?"; $params[] = $tb; }
    if (($te = trim(metin($_GET['tarih_bit'] ?? ''))) !== '') { $where[] = "t.tarih<=?"; $params[] = $te; }
    if (($pid = (int)($_GET['personel_id'] ?? 0)) > 0) { $where[] = "t.personel_id=?"; $params[] = $pid; }
    if (($ia = trim(metin($_GET['imza_ad'] ?? ''))) !== '') { $where[] = "COALESCE(t.personel_id,0)=0 AND t.imza_personel_ad=?"; $params[] = $ia; }
    if (($kidF = (int)($_GET['kiralama_id'] ?? 0)) > 0) { $where[] = "t.kiralama_id=?"; $params[] = $kidF; }
    $is = (string)($_GET['islem'] ?? '');
    if ($is === 'teslim') $where[] = "t.islem='teslim'";
    elseif ($is === 'teslim_alma') $where[] = "t.islem='iade' AND COALESCE(t.asama,'tutanak')='saha_alim'";
    elseif ($is === 'iade') $where[] = "t.islem='iade' AND COALESCE(t.asama,'tutanak')<>'saha_alim'";
    if (in_array($_GET['genel_durum'] ?? '', ['saglam','hasarli','eksik_aksesuar'], true)) { $where[] = "t.genel_durum=?"; $params[] = $_GET['genel_durum']; }
    if (($q = trim(metin($_GET['q'] ?? ''))) !== '') {
        $where[] = "(" . trAramaSql('k.kod') . " LIKE ? OR " . trAramaSql('k.ekipman_adi') . " LIKE ? OR " . trAramaSql('m.unvan') . " LIKE ? OR " . trAramaSql('COALESCE(p.ad_soyad, t.imza_personel_ad)') . " LIKE ?)";
        $like = '%' . trArama($q) . '%'; array_push($params, $like, $like, $like, $like);
    }
    $sql = "
        SELECT t.id, t.belge_no, t.kiralama_id, t.personel_id, t.islem, t.tarih, t.genel_durum, t.hasar_notu,
               t.km, t.calisma_saati, t.created_at, t.ai_analiz_durum, t.asama, t.inceleme_sonucu,
               COALESCE(p.ad_soyad, t.imza_personel_ad) AS personel_ad, k.kod AS kiralama_kod, k.ekipman_adi, k.makine_tur, k.makine_id,
               m.unvan AS musteri_unvan,
               h.ek_ucret_toplam, h.ek_ucret_adet,
               COALESCE(f.foto_adet, 0) AS foto_adet, EXISTS (SELECT 1 FROM iade_tutanaklari bz_x WHERE bz_x.kiralama_id=t.kiralama_id AND bz_x.inceleme_sonucu IS NOT NULL) AS it_var
        FROM iade_tutanaklari t
        LEFT JOIN personel p ON p.id = t.personel_id
        JOIN kiralamalar k ON k.id = t.kiralama_id
        JOIN musteriler m ON m.id = k.musteri_id
        LEFT JOIN (SELECT tutanak_id, SUM(genel_toplam) ek_ucret_toplam, COUNT(*) ek_ucret_adet FROM hakedisler WHERE tutanak_id IS NOT NULL GROUP BY tutanak_id) h ON h.tutanak_id = t.id
        LEFT JOIN (SELECT tutanak_id, COUNT(*) foto_adet FROM iade_tutanak_fotograflari GROUP BY tutanak_id) f ON f.tutanak_id = t.id
    ";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY t.tarih DESC, t.id DESC";
    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) $r += bzListeBayraklari($r, (bool)$r['it_var']);
    unset($r);
    jsonOk($rows);
}

if ($method === 'GET' && $action === 'eksik') {
    requireAuth();
    requirePermission('kiralama_takip');
    $where = [tutanaksizKiralamaKosulu('k')];
    $params = [];
    if (($tb = trim(metin($_GET['tarih_bas'] ?? ''))) !== '') { $where[] = "k.gercek_bitis>=?"; $params[] = $tb; }
    if (($te = trim(metin($_GET['tarih_bit'] ?? ''))) !== '') { $where[] = "k.gercek_bitis<=?"; $params[] = $te; }
    if (($q = trim(metin($_GET['q'] ?? ''))) !== '') {
        $where[] = "(" . trAramaSql('k.kod') . " LIKE ? OR " . trAramaSql('k.ekipman_adi') . " LIKE ? OR " . trAramaSql('m.unvan') . " LIKE ?)";
        $like = '%' . trArama($q) . '%'; array_push($params, $like, $like, $like);
    }
    if (($kidF = (int)($_GET['kiralama_id'] ?? 0)) > 0) { $where[] = "k.id=?"; $params[] = $kidF; }
    $sql = "
        SELECT k.id AS kiralama_id, k.kod AS kiralama_kod, k.ekipman_adi, k.gercek_bitis, k.updated_at,
               m.unvan AS musteri_unvan, it.id AS it_id, it.belge_no AS it_belge_no
        FROM kiralamalar k JOIN musteriler m ON m.id = k.musteri_id
        LEFT JOIN iade_tutanaklari it ON it.kiralama_id = k.id AND it.inceleme_sonucu IS NOT NULL
        WHERE " . implode(' AND ', $where) . "
        ORDER BY k.gercek_bitis DESC, k.id DESC
    ";
    $st = $db->prepare($sql);
    $st->execute($params);
    jsonOk($st->fetchAll());
}

if ($method === 'GET' && $action === 'imzalayan_liste') {
    requireAuth();
    requirePermission('kiralama_takip');
    $personel = $db->query("SELECT id, ad_soyad FROM personel WHERE durum='aktif' ORDER BY ad_soyad")->fetchAll();
    $w = ["COALESCE(t.personel_id,0)=0", "COALESCE(t.imza_personel_ad,'')<>''"]; $p = [];
    subeKosulEkle($w, $p, 'k.sube');
    $st = $db->prepare("SELECT DISTINCT t.imza_personel_ad FROM iade_tutanaklari t JOIN kiralamalar k ON k.id=t.kiralama_id
                        WHERE " . implode(' AND ', $w) . " ORDER BY 1 LIMIT 200");
    $st->execute($p);
    jsonOk(['personel' => $personel, 'ofis' => $st->fetchAll(PDO::FETCH_COLUMN)]);
}

if ($method === 'GET' && $action === 'inceleme_bekleyen') {
    requireAuth();
    requirePermission('kiralama_takip');
    $w = ["k.id > (SELECT son_eski_kiralama FROM operasyon_gecis WHERE id=1)", "COALESCE(k.makine_id,0)<>0", "k.durum<>'iptal'",
          "(SELECT oa.sonuc FROM operasyon_adimlari oa WHERE oa.kiralama_id=k.id AND oa.adim='saha_teslim_alma' ORDER BY oa.id DESC LIMIT 1) IN ('tamam','atlandi')",
          "COALESCE((SELECT ob.sonuc FROM operasyon_adimlari ob WHERE ob.kiralama_id=k.id AND ob.adim='depo_inceleme' ORDER BY ob.id DESC LIMIT 1),'geri_alindi')='geri_alindi'"];
    $p = [];
    if (($kidF = (int)($_GET['kiralama_id'] ?? 0)) > 0) { $w[] = "k.id=?"; $p[] = $kidF; }
    if (($q = trim(metin($_GET['q'] ?? ''))) !== '') {
        $w[] = "(" . trAramaSql('k.kod') . " LIKE ? OR " . trAramaSql('k.ekipman_adi') . " LIKE ? OR " . trAramaSql('m.unvan') . " LIKE ?)";
        $like = '%' . trArama($q) . '%'; array_push($p, $like, $like, $like);
    }
    subeKosulEkle($w, $p, 'k.sube');
    $st = $db->prepare("SELECT k.id, k.id AS kiralama_id, k.kod AS kiralama_kod, k.ekipman_adi, k.durum, k.gercek_bitis, k.makine_id, m.unvan AS musteri_unvan,
                               (SELECT MAX(a.zaman) FROM operasyon_adimlari a WHERE a.kiralama_id=k.id AND a.adim='saha_teslim_alma') AS gelis
                        FROM kiralamalar k JOIN musteriler m ON m.id=k.musteri_id
                        WHERE " . implode(' AND ', $w) . " ORDER BY gelis, k.id LIMIT 300");
    $st->execute($p);
    $rows = $st->fetchAll();
    $sira = opSiradakiToplu($db, $rows);
    $tb = dateStr('tarih_bas', $_GET) ?? ''; $te = dateStr('tarih_bit', $_GET) ?? '';
    $out = [];
    foreach ($rows as $r) {
        if (($sira[(int)$r['id']] ?? null) !== 'depo_inceleme') continue;
        $gun = substr((string)$r['gelis'], 0, 10);
        if (($tb !== '' && $gun < $tb) || ($te !== '' && $gun > $te)) continue;
        $out[] = ['kiralama_id' => (int)$r['kiralama_id'], 'kiralama_kod' => $r['kiralama_kod'], 'ekipman_adi' => $r['ekipman_adi'],
                  'musteri_unvan' => $r['musteri_unvan'], 'gelis' => $r['gelis'], 'durum' => $r['durum'], 'gercek_bitis' => $r['gercek_bitis'],
                  'kiralama_acik' => $r['durum'] === 'aktif' && empty($r['gercek_bitis'])];
    }
    jsonOk($out);
}

if ($method === 'GET' && $action === 'tek') {
    require __DIR__.'/tutanak_belge.php';
    exit;
}

if ($method === 'POST' && $action === 'ai_analiz') {
    requireAuth();
    require_once __DIR__ . '/claude.php';
    requirePermission('kiralama_takip');
    verifyCsrf();
    $body = govdeOku();
    $id = (int)($body['id'] ?? 0);
    if ($id < 1) jsonError('Geçersiz ID.');

    $ayarStmt = $db->query("SELECT anahtar, deger FROM ayarlar WHERE anahtar IN ('ai_saglayici','ai_api_key','ai_model')");
    $ayar = [];
    foreach ($ayarStmt->fetchAll() as $r) $ayar[$r['anahtar']] = $r['deger'];
    $saglayici = $ayar['ai_saglayici'] ?? '';
    $apiKey    = $ayar['ai_api_key'] ?? '';
    $model     = trim($ayar['ai_model'] ?? '') ?: null;
    if (!in_array($saglayici, ['anthropic', 'openai'], true) || $apiKey === '') {
        jsonError('AI hasar tespiti yapılandırılmamış. Ayarlar → AI Hasar Tespiti bölümünden bir sağlayıcı ve API anahtarı ekleyin.');
    }

    $tStmt = $db->prepare("SELECT t.*, k.makine_tur, k.makine_id FROM iade_tutanaklari t JOIN kiralamalar k ON k.id = t.kiralama_id WHERE t.id=?");
    $tStmt->execute([$id]);
    $tutanak = $tStmt->fetch();
    if (!$tutanak) jsonError('Tutanak bulunamadı.', 404);

    $fotoStmt = $db->prepare("SELECT aci_tipi, dosya_yolu FROM iade_tutanak_fotograflari WHERE tutanak_id=? ORDER BY id");
    $fotoStmt->execute([$id]);
    $yeniFotolar = $fotoStmt->fetchAll();
    if (!$yeniFotolar) jsonError('Bu tutanakta fotoğraf yok.');

    $oncekiStmt = $db->prepare("
        SELECT t2.id FROM iade_tutanaklari t2 JOIN kiralamalar k2 ON k2.id = t2.kiralama_id
        WHERE k2.makine_tur=? AND k2.makine_id=? AND t2.id<>?
          AND (t2.tarih < ? OR (t2.tarih = ? AND t2.id < ?))
        ORDER BY t2.tarih DESC, t2.id DESC LIMIT 1
    ");
    $oncekiStmt->execute([$tutanak['makine_tur'], $tutanak['makine_id'], $id, $tutanak['tarih'], $tutanak['tarih'], $id]);
    $oncekiId = $oncekiStmt->fetchColumn();
    if (!$oncekiId) jsonError('Bu makine için karşılaştırılacak önceki bir tutanak yok.');

    $fotoStmt->execute([$oncekiId]);
    $oncekiFotolar = $fotoStmt->fetchAll();
    if (!$oncekiFotolar) jsonError('Önceki tutanakta fotoğraf yok, kıyaslanamıyor.');

    $etiketli = aiFotoEsle($oncekiFotolar, $yeniFotolar);

    $db->prepare("UPDATE iade_tutanaklari SET ai_analiz_durum='calisiyor' WHERE id=?")->execute([$id]);
    try {
        $ham = $saglayici === 'anthropic'
            ? _aiAnaliziClaude($db, $apiKey, $model, $etiketli)
            : _aiAnaliziOpenAI($apiKey, $model, $etiketli);

        $c = _aiSonucAyikla($ham);
        $ozet = $c['ozet'] ?: $ham;

        $ekler = [];
        if ($c['km'] !== null)            $ekler[] = 'Okunan kilometre: ' . $c['km'];
        if ($c['calisma_saati'] !== null) $ekler[] = 'Okunan çalışma saati: ' . $c['calisma_saati'];
        if (!empty($c['bilesenler'])) {
            $bl = [];
            foreach ($c['bilesenler'] as $ad => $d) $bl[] = $ad . ': ' . str_replace('_', ' ', $d);
            $ekler[] = 'Bileşen değerlendirmesi — ' . implode(', ', $bl);
        }
        $mrkStmt = $db->prepare($tutanak['makine_tur'] === 'arac'
            ? "SELECT marka FROM araclar WHERE id=?" : "SELECT marka FROM manliftler WHERE id=?");
        $mrkStmt->execute([(int)$tutanak['makine_id']]);
        $makineMarka = (string)($mrkStmt->fetchColumn() ?: '');
        $arz = _aiArizaKoduEslestir($db, $c['ariza_kodlari'] ?? [], $makineMarka);
        if ($arz['eslesen']) {
            $al = [];
            foreach ($arz['eslesen'] as $e) $al[] = $e['kod'] . ' (' . $e['aciklama'] . ')';
            $ekler[] = 'Ekranda okunan arıza kodu — ' . implode(', ', $al);
        }
        if ($arz['eslesmeyen']) {
            $ekler[] = 'Okunan ama listede bulunamayan kod: ' . implode(', ', $arz['eslesmeyen']);
        }

        $TUR_AD = ['cizik' => 'çizik', 'gocuk' => 'göçük', 'kirik' => 'kırık', 'catlak' => 'çatlak', 'eksik_parca' => 'eksik parça',
                   'sizinti' => 'sızıntı', 'yirtik' => 'yırtık', 'asinma' => 'aşınma', 'pas' => 'pas', 'boya_hasari' => 'boya hasarı', 'diger' => 'diğer'];
        $yeniHasar = array_values(array_filter($c['hasarlar'] ?? [], fn($h) => $h['yeni'] === 'evet'));
        if ($yeniHasar) {
            $ekler[] = 'Yeni hasarlar:';
            foreach ($yeniHasar as $h) $ekler[] = '• ' . $h['bolge'] . ' / ' . $h['parca'] . ': ' . ($TUR_AD[$h['tur']] ?? $h['tur'])
                . ' (' . $h['siddet'] . ($h['boyut'] ? ', ' . $h['boyut'] : '') . ')' . ($h['foto'] ? ' [' . $h['foto'] . ']' : '');
        }
        if (!empty($c['eksik_acilar'])) $ekler[] = 'Kıyaslanamayan/eksik açılar: ' . implode(', ', $c['eksik_acilar']);
        if ($ekler) $ozet .= "\n\n" . implode("\n", $ekler);

        $rapor = $c + [
            'ariza_eslesen' => $arz['eslesen'], 'ariza_eslesmeyen' => $arz['eslesmeyen'],
            'fotograflar' => array_map(fn($f) => ['etiket' => $f['etiket'], 'set' => $f['set'], 'aci' => $f['aci'], 'dosya' => $f['dosya']], $etiketli),
            'onceki_tutanak_id' => (int)$oncekiId, 'saglayici' => $saglayici, 'model' => $model ?: ($saglayici === 'anthropic' ? CLAUDE_VARSAYILAN_MODEL : 'gpt-4o-mini'),
        ];
        $db->prepare("UPDATE iade_tutanaklari SET ai_analiz_json=? WHERE id=?")->execute([json_encode($rapor, JSON_UNESCAPED_UNICODE), $id]);

        $db->prepare("UPDATE iade_tutanaklari SET ai_analiz_sonucu=?, ai_analiz_durum='tamam', ai_analiz_tarihi=? WHERE id=?")
           ->execute([$ozet, date('Y-m-d H:i:s'), $id]);

        jsonOk([
            'sonuc'             => $ozet,
            'onceki_tutanak_id' => (int)$oncekiId,
            'oneri' => [
                'yapisal'        => $c['yapisal'],
                'yeni_hasar_var' => $c['yeni_hasar_var'] ?? false,
                'guven'          => $c['guven'] ?? 'orta',
                'bilesenler'     => $c['bilesenler'] ?? [],
                'km'             => $c['km'] ?? null,
                'calisma_saati'  => $c['calisma_saati'] ?? null,
                'ariza_kodlari'  => $arz['eslesen'],
                'ariza_eslesmeyen' => $arz['eslesmeyen'],
            ],
            'rapor' => $rapor,
        ], 'AI analizi tamamlandı.');
    } catch (\Exception $e) {
        $db->prepare("UPDATE iade_tutanaklari SET ai_analiz_durum='hata', ai_analiz_tarihi=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $id]);
        jsonError('AI analizi başarısız: ' . $e->getMessage(), 502);
    }
}

switch ($method) {

    case 'POST':
        $yetki = _itGirisliYetki($db);
        verifyCsrf();
        $body       = govdeOku();
        $kiralamaId = (int)($body['kiralama_id'] ?? 0);
        $ofisModu   = $yetki['ofis'];
        $personelId = $ofisModu ? (int)($body['personel_id'] ?? 0) : $yetki['personel_id'];
        if ($kiralamaId < 1) jsonError('Kiralama seçilmelidir.');
        if ($personelId < 1) jsonError($ofisModu ? 'Personel seçilmelidir.'
            : 'Hesabınız aktif bir personel kaydına bağlı değil. Yöneticiniz Ayarlar → Kullanıcılar ekranından bağlamalı.', $ofisModu ? 400 : 403);

        $kStmt = $db->prepare("SELECT * FROM kiralamalar WHERE id=?");
        $kStmt->execute([$kiralamaId]);
        $kiralama = $kStmt->fetch();
        if (!$kiralama) jsonError('Kiralama bulunamadı.', 404);
        subeErisimDogrula($kiralama['sube'] ?? null);

        $pStmt = $db->prepare("SELECT id, ad_soyad FROM personel WHERE id=?");
        $pStmt->execute([$personelId]);
        $personel = $pStmt->fetch();
        if (!$personel) jsonError('Personel bulunamadı.', 404);

        if (opYeniZincir($db, $kiralama) && !$yetki['saha']) {
            jsonError('Sahada teslim yetkiniz yok. Yöneticinizden "Sahada teslim" yetkisi isteyin.', 403);
        }
        $tKaynak   = ($yetki['saha'] && $personelId === $yetki['personel_id']) ? 'saha' : 'ofis';
        $tKaydeden = trim((string)($_SESSION['username'] ?? '')) ?: null;

        $_anahtar = istemciAnahtari($body);
        if (($_varolan = mukerrerKayitId($db, 'iade_tutanaklari', $_anahtar)) !== null) {
            jsonOk(['id' => $_varolan, 'mukerrer' => true], 'Bu tutanak zaten kaydedilmişti.');
        }

        $oy = opOturumYapan($db);
        $kim = $tKaynak === 'saha'
            ? ['tur' => 'personel', 'personel_id' => $personelId, 'yapan_id' => $personelId, 'ad' => (string)$personel['ad_soyad'],
               'tutanak_kaynak' => $tKaynak, 'kaydeden' => $tKaydeden, 'adim_kaynak' => 'tutanak']
            : ['tur' => 'kullanici', 'personel_id' => $personelId, 'yapan_id' => $oy['id'], 'ad' => $oy['ad'],
               'tutanak_kaynak' => $tKaynak, 'kaydeden' => $tKaydeden, 'adim_kaynak' => 'tutanak'];
        $yazilanFotolar = [];
        $db->beginTransaction();
        try {
            $r = itTutanakYaz($db, $body, $kim, ['foto_kurali' => 'tam', 'istemci_anahtari' => $_anahtar,
                 'beklenen_makine' => [(string)$kiralama['makine_tur'], (int)$kiralama['makine_id']]], $yazilanFotolar);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            foreach ($yazilanFotolar as $yol) @unlink(UPLOAD_PATH . $yol);
            if ($e instanceof InvalidArgumentException) jsonError($e->getMessage());
            if ($e instanceof DomainException) jsonError($e->getMessage(), 409);
            error_log('iade_tutanak kayit hatasi: ' . $e->getMessage());
            jsonError('Tutanak kaydedilemedi.', 500);
        }
        if (!$r['fiili_teslim']) jsonOk(['id' => $r['id'], 'servis_id' => $r['servis_id'], 'kiralama_kapatildi' => false],
            'Tutanak kaydedildi; fiili teslim/iade gerçekleşmediği için makine ve kiralama durumu ilerletilmedi.');
        jsonOk(['id' => $r['id'], 'servis_id' => $r['servis_id'], 'hakedis_id' => $r['hakedis_id'],
                'hakedis_uyarilar' => $r['hakedis_uyarilar'], 'kiralama_kapatildi' => $r['kiralama_kapatildi']],
            $r['islem'] === 'iade'
                ? ($r['kiralama_acikti']
                    ? ($r['asama'] === 'saha_alim' ? 'Teslim alma kaydedildi, kiralama tamamlandı. İade tutanağı dönüş kontrolünden sonra oluşacak.' : 'İade tutanağı kaydedildi, kiralama tamamlandı.')
                    : 'Tutanak kaydedildi (kiralama zaten kapalıydı, tutar/faturalar değiştirilmedi).')
                : 'Teslim tutanağı kaydedildi.');

    case 'PUT':
        requireAuth();
        requirePermission('kiralama_takip');
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $chk = $db->prepare("SELECT * FROM iade_tutanaklari WHERE id=?");
        $chk->execute([$id]);
        $mevcut = $chk->fetch();
        if (!$mevcut) jsonError('Tutanak bulunamadı.', 404);

        $ham  = file_get_contents('php://input');
        $body = json_decode($ham, true);
        if ($body === null) {
            if (trim((string)$ham) !== '') jsonError('İstek gövdesi okunamadı.');
            $body = [];
        }
        if (!is_array($body)) jsonError('İstek gövdesi okunamadı.');
        tsYetki($db,$id);
        $db->beginTransaction();try { tsIlk($db,$id); $db->commit(); } catch(Throwable $e){$db->rollBack();throw $e;}
        $mevcut=tsYetki($db,$id);$talep=null;
        if(!empty($body['duzeltme_onay_id'])){
            $st=$db->prepare("SELECT * FROM tutanak_duzeltmeleri WHERE id=? AND tutanak_id=? AND durum='bekliyor'");$st->execute([(int)$body['duzeltme_onay_id'],$id]);$talep=$st->fetch();
            if(!$talep)jsonError('Bekleyen düzeltme talebi bulunamadı.',409);
            $body=json_decode($talep['istek'],true);
        }
        if((int)($body['temel_surum']??0)!==(int)$mevcut['belge_surumu'])jsonError('Belge sürümü değişti. Yenileyip yeni düzeltme talebi oluşturun.',409);
        $gerekce=mb_substr(trim((string)($body['duzeltme_gerekcesi']??'')),0,3000);
        if($gerekce==='')jsonError('Düzeltme gerekçesi zorunludur.');

        if (array_intersect(['km','calisma_saati','genel_durum','hasar_notu','bilesen_hasar'], array_keys($body))) {
            if ($hata = tutanakAlanHatasi(array_replace($mevcut, $body), false)) jsonError($hata);
        }
        if(array_key_exists('tarih',$body)&&!dateStr('tarih',$body))jsonError('Geçerli tutanak tarihi girin.');
        if(array_key_exists('ariza_kodlari',$body)&&!is_array($body['ariza_kodlari']))jsonError('Arıza kodları liste olmalıdır.');
        $tarih       = array_key_exists('tarih', $body) ? (dateStr('tarih', $body) ?: date('Y-m-d')) : $mevcut['tarih'];
        $km          = array_key_exists('km', $body) ? (($body['km'] !== '' && $body['km'] !== null) ? (float)$body['km'] : null) : $mevcut['km'];
        $calismaSaati = array_key_exists('calisma_saati', $body) ? (($body['calisma_saati'] !== '' && $body['calisma_saati'] !== null) ? (float)$body['calisma_saati'] : null) : $mevcut['calisma_saati'];
        $yakit       = array_key_exists('yakit_seviyesi', $body) ? (trim((string)$body['yakit_seviyesi']) ?: null) : $mevcut['yakit_seviyesi'];
        $genelDurum  = array_key_exists('genel_durum', $body)
            ? (in_array($body['genel_durum'], ['saglam', 'hasarli', 'eksik_aksesuar'], true) ? $body['genel_durum'] : 'saglam')
            : $mevcut['genel_durum'];
        $aksesuarlar = array_key_exists('aksesuarlar', $body)
            ? (is_array($body['aksesuarlar']) ? json_encode(array_values($body['aksesuarlar']), JSON_UNESCAPED_UNICODE) : null)
            : $mevcut['aksesuarlar'];
        $hasarNotu   = array_key_exists('hasar_notu', $body) ? (trim((string)$body['hasar_notu']) ?: null) : $mevcut['hasar_notu'];
        $genelNot    = array_key_exists('genel_not', $body) ? (trim((string)$body['genel_not']) ?: null) : $mevcut['genel_not'];
        $temsilciAd  = array_key_exists('musteri_temsilci_ad', $body) ? (trim((string)$body['musteri_temsilci_ad']) ?: null) : $mevcut['musteri_temsilci_ad'];

        $bilesenHasar = $mevcut['bilesen_hasar'];
        if (array_key_exists('bilesen_hasar', $body)) {
            if (is_array($body['bilesen_hasar'])) {
                $gecerliBilesen = ['tekerlek','korkuluk','joystick','boya','aku','kaporta','destek_ayaklari'];
                $gecerliDurum   = ['hasarsiz','az_hasarli','cok_hasarli'];
                $temiz = [];
                foreach ($body['bilesen_hasar'] as $ad => $derece) {
                    if (!in_array($ad, $gecerliBilesen, true))    jsonError('Bilinmeyen bileşen: ' . $ad);
                    if (!in_array($derece, $gecerliDurum, true))  jsonError('Bilinmeyen hasar durumu: ' . $derece);
                    $temiz[$ad] = $derece;
                }
                $bilesenHasar = $temiz ? json_encode($temiz, JSON_UNESCAPED_UNICODE) : null;
            } else {
                $bilesenHasar = null;
            }
        }

        $adetli=null;
        if(array_key_exists('aksesuar_kalemleri',$body)){
            try{$adetli=hzKalemler($body['aksesuar_kalemleri']);}catch(DomainException $e){jsonError($e->getMessage());}
            $ref=json_decode($mevcut['aksesuar_referans_veri']??'null',true);$map=array_column($adetli,null,'kod');
            foreach($ref['kalemler']??[] as $x){$y=$map[$x['kod']]??null;if(!$y||$y['ad']!==$x['ad']||$y['seri']!==$x['seri'])jsonError('Referans aksesuar satırını kaldırmayın veya kimliğini değiştirmeyin.');if($y['adet']!==$x['adet']&&$y['not']==='')jsonError('Adet farkına açıklama yazın.');}
        }
        if(!$talep){
            $ekler=$body['fotograflar_yeni']??[];
            if(!is_array($ekler)||count($ekler)>20)jsonError('Bir düzeltmede en fazla 20 yeni fotoğraf ekleyin.');
            foreach($ekler as $f){
                if(!is_array($f)||!is_string($f['veri']??null)||strlen($f['veri'])>14000000||trim((string)($f['aci']??''))==='')jsonError('Yeni fotoğrafın açısı ve geçerli görüntüsü gereklidir.');
                if(!preg_match('~^data:image/(png|jpeg|webp);base64,(.+)$~s',$f['veri'],$fm)||!($raw=base64_decode($fm[2],true))||!@getimagesizefromstring($raw))jsonError('Yeni fotoğraf içeriği geçerli değil.');
            }
            $izinli=['aksesuar_kalemleri','temel_surum','duzeltme_gerekcesi','tarih','km','calisma_saati','yakit_seviyesi','genel_durum','aksesuarlar','hasar_notu','genel_not','musteri_temsilci_ad','bilesen_hasar','ariza_kodlari','fotograf_sil_ids','fotograflar_yeni'];
            if(array_diff(array_keys($body),$izinli))jsonError('Düzeltilemeyen alan gönderildi.');
            $db->beginTransaction();
            try {
            $st=$db->prepare("INSERT INTO tutanak_duzeltmeleri(tutanak_id,temel_surum,istek,gerekce,isteyen,created_at) VALUES(?,?,?,?,?,?)");
            $st->execute([$id,$body['temel_surum'],json_encode($body,JSON_UNESCAPED_UNICODE),$gerekce,$_SESSION['username'],date('Y-m-d H:i:s')]);
            $rid=(int)$db->lastInsertId();logla($db,'iade_tutanaklari',$id,'guncelle',null,null,'Düzeltme talebi #'.$rid.' açıldı: '.$gerekce);
            tsTalepGorevi($db,$id);$db->commit();
            }catch(Throwable $e){if($db->inTransaction())$db->rollBack();error_log($e->getMessage());jsonError('Düzeltme talebi kaydedilemedi.',500);}
            jsonOk(['talep_id'=>$rid],'Düzeltme talebi açıldı. Geçerli belge değişmedi; etkiyi inceleyip onaylayın.');
        }
        if($tarih!==$mevcut['tarih']){
            $etki=tsEtki($db,$mevcut);$k=$etki['kiralama'];
            if($tarih<$k['baslangic'])jsonError('Tutanak tarihi kiralama başlangıcından önce olamaz.');
            if($mevcut['islem']==='iade'&&$k['gercek_bitis']!==$tarih)jsonError('Önce kiralama bitişini ve bağlı hakedişleri inceleyip düzeltin. Geçerli belge değiştirilmedi.',409);
            foreach($etki['hakedisler'] as $h)if($mevcut['islem']==='iade'&&$h['donem_bit']>$tarih)jsonError('Hakediş #'.$h['id'].' yeni iade tarihinden sonraki dönemi kapsıyor. Önce ticari düzeltme gerekir.',409);
        }
        $yeniYollar=[];
        $db->beginTransaction();
        try {
            $fresh=tsYetki($db,$id);if((int)$fresh['belge_surumu']!==(int)$body['temel_surum'])throw new DomainException('Belge sürümü değişti.');
            $db->prepare("
                UPDATE iade_tutanaklari SET
                  tarih=?, km=?, calisma_saati=?, yakit_seviyesi=?, genel_durum=?, aksesuarlar=?,
                  hasar_notu=?, genel_not=?, musteri_temsilci_ad=?, bilesen_hasar=?,
                  ariza_kodlari=COALESCE(?, ariza_kodlari)
                WHERE id=?
            ")->execute([
                $tarih, $km, $calismaSaati, $yakit, $genelDurum, $aksesuarlar,
                $hasarNotu, $genelNot, $temsilciAd, $bilesenHasar,
                array_key_exists('ariza_kodlari', $body)
                    ? json_encode(array_values($body['ariza_kodlari']), JSON_UNESCAPED_UNICODE) : null,
                $id,
            ]);

            if($adetli!==null)$db->prepare('UPDATE iade_tutanaklari SET aksesuar_kalemleri=? WHERE id=?')->execute([json_encode($adetli,JSON_UNESCAPED_UNICODE),$id]);
            $oncekiFoto = (int)$db->query('SELECT COUNT(*) FROM iade_tutanak_fotograflari WHERE tutanak_id=' . (int)$id)->fetchColumn();
            $silIds = array_values(array_filter(array_map('intval', is_array($body['fotograf_sil_ids'] ?? null) ? $body['fotograf_sil_ids'] : [])));
            if ($silIds) {
                $ph = implode(',', array_fill(0, count($silIds), '?'));
                $fSil = $db->prepare("SELECT id, dosya_yolu FROM iade_tutanak_fotograflari WHERE tutanak_id=? AND id IN ($ph)");
                $fSil->execute(array_merge([$id], $silIds));
                $silinecekler = $fSil->fetchAll();
                $db->prepare("DELETE FROM iade_tutanak_fotograflari WHERE tutanak_id=? AND id IN ($ph)")->execute(array_merge([$id], $silIds));

            }

            $yeniFotolar = is_array($body['fotograflar_yeni'] ?? null) ? $body['fotograflar_yeni'] : [];
            if ($yeniFotolar) {
                $fotoIns = $db->prepare("INSERT INTO iade_tutanak_fotograflari (tutanak_id, aci_tipi, dosya_yolu, aciklama, created_at) VALUES (?,?,?,?,?)");
                foreach ($yeniFotolar as $f) {
                    if (!is_array($f) || empty($f['veri'])) throw new DomainException('Yeni fotoğraf verisi eksik.');
                    if ($_gh = gorselVeriHatasi($f['veri'])) throw new DomainException($_gh);
                    $yol = _itFotoKaydet((string)$f['veri'], $id);
                    if ($yol === null) throw new DomainException('Yeni fotoğraf kaydedilemedi.');
                    $yeniYollar[]=$yol;
                    $fotoIns->execute([$id, trim((string)($f['aci'] ?? '')) ?: null, $yol, mb_substr(trim((string)($f['aciklama'] ?? '')), 0, 500) ?: null, date('Y-m-d H:i:s')]);
                }
            }

            $kalanStmt = $db->prepare("SELECT COUNT(*) FROM iade_tutanak_fotograflari WHERE tutanak_id=?");
            $kalanStmt->execute([$id]);
            if ($oncekiFoto > 0 && (int)$kalanStmt->fetchColumn() < 1) throw new DomainException('En az bir fotoğraf kalmalı; son fotoğraf silinemez.');

            if (!empty($mevcut['inceleme_sonucu'])) $db->prepare('UPDATE iade_tutanaklari SET imza_personel=NULL WHERE id=?')->execute([$id]);
            else $db->prepare("UPDATE iade_tutanaklari SET imza_personel=NULL,imza_musteri=NULL,kabul_sonucu='yeniden_onay',kabul_notu=? WHERE id=?")->execute(['İçerik düzeltildi: '.$gerekce,$id]);
            itKiralayanEksikseSabitle($db,$id);
            tsKaydet($db,$id,$gerekce,'duzeltme');tsKabulGorevi($db,$id);
            $db->prepare("UPDATE tutanak_duzeltmeleri SET durum='onaylandi',onaylayan=?,sonuc_zamani=? WHERE id=? AND durum='bekliyor'")->execute([$_SESSION['username'],date('Y-m-d H:i:s'),$talep['id']]);
            tsTalepGorevi($db,$id);
            hdTutanakServisSenkron($db, $id);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            foreach($yeniYollar as $yol)@unlink(UPLOAD_PATH.$yol);
            error_log($e->getMessage());
            jsonError($e instanceof DomainException ? $e->getMessage() : 'Düzeltme uygulanamadı; önceki belge korunuyor.',409);
        }
        logla($db, 'iade_tutanaklari', $id, 'guncelle', null, null, 'Tutanak düzenlendi');
        jsonOk(null, !empty($mevcut['inceleme_sonucu'])
            ? 'Yeni belge sürümü oluşturuldu. Dönüş kontrolündeki imza yeni sürüme aktarılmadı; müşteri kabulü teslim alma kaydında kalır.'
            : 'Yeni belge sürümü oluşturuldu. Eski imzalar aktarılmadı; yeniden kabul gerekir.');

    case 'DELETE':
        requireAuth();
        requirePermission('kiralama_takip');
        verifyCsrf();
        $tid = _itSilHedef($db);
        $db->beginTransaction();
        try {
            $r = tutanakTamSil($db, $tid);
            logla($db, 'iade_tutanaklari', $tid, 'sil', $r['tutanak'] + ['bagli_kayitlar' => json_encode($r['yedek'], JSON_UNESCAPED_UNICODE)], null,
                  'Tutanak ' . ($r['tutanak']['belge_no'] ?? ('#' . $tid)) . ' BAĞLI KAYITLARIYLA SİLİNDİ' . (($o = kiralamaSilOzet($r['sayim'])) ? ' · ' . implode(', ', $o) : ''));
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            if ($e instanceof DomainException) jsonError($e->getMessage(), 409);
            error_log('tutanak sil: ' . $e->getMessage());
            jsonError('Tutanak silinemedi; hiçbir kayıt değişmedi.', 500);
        }
        jsonOk(['ozet' => kiralamaSilOzet($r['sayim'])], 'Tutanak bağlı kayıtlarıyla silindi.');

    default:
        jsonError('Method Not Allowed', 405);
}
