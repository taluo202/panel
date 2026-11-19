<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

const CLAUDE_VARSAYILAN_MODEL = 'claude-opus-5';
const CLAUDE_SAATLIK_LIMIT = 40;

function claudeAyar(PDO $db): array {
    $ayar = [];
    foreach ($db->query("SELECT anahtar, deger FROM ayarlar WHERE anahtar IN ('claude_api_key','claude_model','ai_saglayici','ai_api_key','claude_asistan_aktif')")->fetchAll() as $r)
        $ayar[$r['anahtar']] = (string)$r['deger'];
    $anahtar = trim((string)getenv('ANTHROPIC_API_KEY'));
    if ($anahtar === '') $anahtar = trim($ayar['claude_api_key'] ?? '');
    if ($anahtar === '' && ($ayar['ai_saglayici'] ?? '') === 'anthropic') $anahtar = trim($ayar['ai_api_key'] ?? '');
    $model = trim($ayar['claude_model'] ?? '') ?: CLAUDE_VARSAYILAN_MODEL;
    return [
        'anahtar' => $anahtar,
        'model' => $model,
        'aktif' => $anahtar !== '' && ($ayar['claude_asistan_aktif'] ?? '1') !== '0',
    ];
}

function _claudeModelOzellik(string $model): array {
    $effort   = (bool)preg_match('/^claude-(opus-(5|4-[5-8])|fable|mythos|sonnet-(5|4-6))/', $model);
    $fallback = (bool)preg_match('/^claude-(opus-5|fable-5)/', $model);
    return ['effort' => $effort, 'fallback' => $fallback];
}

function claudeLimitKontrol(PDO $db): void {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $st = $db->prepare("SELECT COUNT(*) FROM ai_kullanim WHERE user_id=? AND tarih > datetime('now','-1 hour','localtime')");
    $st->execute([$uid]);
    if ((int)$st->fetchColumn() >= CLAUDE_SAATLIK_LIMIT)
        jsonError('Saatlik yapay zekâ kullanım sınırına ulaştınız (' . CLAUDE_SAATLIK_LIMIT . ' istek). Biraz sonra tekrar deneyin.', 429);
}

function claudeKullanimYaz(PDO $db, string $islem, string $model, array $usage, string $durum = 'tamam'): void {
    try {
        $db->prepare("INSERT INTO ai_kullanim (tarih, user_id, islem, model, girdi_token, cikti_token, onbellek_token, durum)
                      VALUES (datetime('now','localtime'), ?, ?, ?, ?, ?, ?, ?)")
           ->execute([(int)($_SESSION['user_id'] ?? 0), $islem, $model,
                      (int)($usage['input_tokens'] ?? 0), (int)($usage['output_tokens'] ?? 0),
                      (int)($usage['cache_read_input_tokens'] ?? 0), $durum]);
    } catch (\Throwable $e) { hataGunlugu('claude.kullanim_yaz', $e); }
}

function claudeIstek(array $ayar, array $govde, string $effort = 'medium', int $zamanAsimi = 120): array {
    $model = $ayar['model'];
    $oz = _claudeModelOzellik($model);
    $govde = ['model' => $model] + $govde;
    $govde['max_tokens'] = $govde['max_tokens'] ?? 16000;
    if ($oz['effort']) $govde['output_config'] = ($govde['output_config'] ?? []) + ['effort' => $effort];
    $basliklar = ['content-type: application/json', 'x-api-key: ' . $ayar['anahtar'], 'anthropic-version: 2023-06-01'];
    if ($oz['fallback']) {
        $govde['fallbacks'] = 'default';
        $basliklar[] = 'anthropic-beta: server-side-fallback-2026-07-01';
    }
    $ch = curl_init(getenv('TALOO_CLAUDE_URL') ?: 'https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($govde, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => $basliklar,
        CURLOPT_TIMEOUT => $zamanAsimi,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $ham = curl_exec($ch);
    $kod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hata = curl_error($ch);
    curl_close($ch);
    if ($ham === false) throw new \RuntimeException('Yapay zekâ servisine bağlanılamadı: ' . $hata);
    $json = json_decode((string)$ham, true);
    if ($kod !== 200 || !is_array($json)) {
        $mesaj = is_array($json) ? (string)($json['error']['message'] ?? '') : '';
        $turkce = match (true) {
            $kod === 401 => 'API anahtarı geçersiz. Ayarlar → Yapay Zekâ Analiz Asistanı bölümünü kontrol edin.',
            $kod === 403 => 'API anahtarının bu modele erişim izni yok.',
            $kod === 404 => 'Model bulunamadı: ' . $model . '. Ayarlardaki model adını kontrol edin.',
            $kod === 429 => 'Yapay zekâ servisinin hız sınırına ulaşıldı. Bir dakika sonra tekrar deneyin.',
            $kod === 529, $kod >= 500 => 'Yapay zekâ servisi şu an yoğun/erişilemiyor. Biraz sonra tekrar deneyin.',
            default => 'Yapay zekâ isteği başarısız (' . $kod . ')' . ($mesaj !== '' ? ': ' . $mesaj : '.'),
        };
        throw new \RuntimeException($turkce);
    }
    if (($json['stop_reason'] ?? '') === 'refusal') {
        throw new \RuntimeException('Model bu isteği yanıtlamayı reddetti. Soruyu farklı ifade etmeyi deneyin.');
    }
    return $json;
}

function claudeMetin(array $yanit): string {
    $parca = [];
    foreach ($yanit['content'] ?? [] as $b) if (($b['type'] ?? '') === 'text') $parca[] = (string)$b['text'];
    return trim(implode("\n", $parca));
}
