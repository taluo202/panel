<?php
declare(strict_types=1);

function tutanakKurallari(string $tur): array {
    static $kurallar = null;
    if ($kurallar === null) {
        $yol = __DIR__ . '/../data/tutanak-kurallari.json';
        $ham = is_file($yol) ? @file_get_contents($yol) : false;
        if ($ham === false || trim((string)$ham) === '') {
            error_log('Taloo: tutanak kural dosyasi okunamadi: ' . $yol);
            jsonError('Tutanak kuralları dosyası sunucuda bulunamadı (site/data/tutanak-kurallari.json). '
                . 'Bu dosya yükleme sırasında atlanmış olabilir — site/data/ klasörünü sunucuya yükleyin.', 500);
        }
        try {
            $kurallar = json_decode($ham, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            error_log('Taloo: tutanak kural dosyasi bozuk: ' . $e->getMessage());
            jsonError('Tutanak kuralları dosyası okunamadı (bozuk içerik). '
                . 'site/data/tutanak-kurallari.json dosyasını yeniden yükleyin.', 500);
        }
    }
    return ['surum' => $kurallar['surum'] ?? 1, 'alanlar' => $kurallar['alanlar'] ?? [],
        'fotograflar' => $kurallar['fotograflar'][$tur] ?? $kurallar['fotograflar']['arac'] ?? []];
}

function tutanakAlanHatasi(array $body, bool $tamKayit = true): ?string {
    foreach (['km', 'calisma_saati'] as $alan) {
        $v = $body[$alan] ?? null;
        if ($v !== null && $v !== '' && (!is_numeric($v) || !is_finite((float)$v) || (float)$v < 0))
            return 'Sayaç değeri sıfır veya pozitif bir sayı olmalıdır.';
    }
    $durum = $body['genel_durum'] ?? '';
    if (!in_array($durum, ['saglam', 'hasarli', 'eksik_aksesuar'], true)) return 'Makinenin genel durumunu seçin.';
    if ($durum !== 'saglam' && trim((string)($body['hasar_notu'] ?? '')) === '') return 'Hasar veya eksik aksesuarın ayrıntısını yazın.';
    $bilesen = $body['bilesen_hasar'] ?? [];
    if (is_string($bilesen)) $bilesen = json_decode($bilesen, true) ?: [];
    if (!is_array($bilesen)) return 'Bileşen kontrolü okunamadı.';
    foreach ($bilesen as $v) {
        if (!in_array($v, ['hasarsiz', 'az_hasarli', 'cok_hasarli'], true)) return 'Geçersiz bileşen kontrolü.';
        if ($durum === 'saglam' && $v !== 'hasarsiz') return 'Hasarlı bileşen seçiliyken genel durum sağlam olamaz.';
    }
    if (!$tamKayit) return null;
    if (!in_array($body['islem'] ?? '', ['teslim', 'iade'], true)) return 'Teslim veya iade işlemini seçin.';
    $tarih = (string)($body['tarih'] ?? '');
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $tarih, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return 'Geçerli işlem tarihi giriniz.';
    foreach (tutanakKurallari('arac')['alanlar'] as $k) {
        if (!is_string($body[$k['alan']] ?? null) || trim($body[$k['alan']]) === '') return $k['ad'] . ' giriniz.';
    }
    return null;
}

function tutanakFotoBinary(string $dataUrl): ?string {
    if (!preg_match('/^data:image\/(jpe?g|png|webp);base64,([a-zA-Z0-9+\/=]+)$/', $dataUrl, $m)) return null;
    $binary = base64_decode($m[2], true);
    if ($binary === false || strlen($binary) < 100 || strlen($binary) > MAX_FILE_SIZE) return null;
    return gorselPikselUygun($binary) ? $binary : null;
}

function tutanakFotoHatasi(array $fotolar, string $tur): ?string {
    if (count($fotolar) > 20) return 'Bir tutanakta en fazla 20 güncel fotoğraf eklenebilir.';
    $acilar = [];
    foreach ($fotolar as $i => $f) {
        if (is_array($f) && ($_gh = gorselVeriHatasi($f['veri'] ?? null))) return ((int)$i + 1) . '. fotoğraf: ' . $_gh;
        if (!is_array($f) || !is_string($f['veri'] ?? null) || tutanakFotoBinary($f['veri']) === null)
            return ((int)$i + 1) . '. fotoğraf okunamadı. Yeniden seçip gönderin.';
        $aci = trim((string)($f['aci'] ?? ''));
        if ($aci === '') return ((int)$i + 1) . '. fotoğrafın açısını seçin.';
        $acilar[$aci] = true;
    }
    $eksik = [];
    foreach (tutanakKurallari($tur)['fotograflar'] as $f) {
        if ($f['zorunlu'] && !isset($acilar[$f['ad']])) $eksik[] = $f['ad'];
    }
    return $eksik ? 'Zorunlu güncel fotoğraflar eksik: ' . implode(', ', $eksik) : null;
}

function operasyonKiralamaGuncelMi(PDO $db, array $k, bool $kapaliIzinli = false): bool {
    if (!$kapaliIzinli && (($k['durum'] ?? '') !== 'aktif' || !empty($k['gercek_bitis']))) return false;
    if (($k['durum'] ?? '') === 'iptal' || !in_array($k['makine_tur'] ?? '', ['arac','manlift'], true) || empty($k['makine_id'])) return false;
    $st = $db->prepare("SELECT 1 FROM kiralamalar WHERE makine_tur=? AND makine_id=? AND id<>?
        AND durum<>'iptal' AND baslangic<=?
        AND (baslangic>? OR (baslangic=? AND id>?)) LIMIT 1");
    $st->execute([$k['makine_tur'], $k['makine_id'], $k['id'], date('Y-m-d'), $k['baslangic'], $k['baslangic'], $k['id']]);
    return !$st->fetchColumn();
}

function operasyonTutanakHareketi(PDO $db, array $k, string $islem): bool {
    if (!operasyonKiralamaGuncelMi($db, $k)) return false;
    if ($islem === 'teslim') {
        $tablo = $k['makine_tur'] === 'arac' ? 'araclar' : 'manliftler';
        $st = $db->prepare("SELECT durum FROM $tablo WHERE id=?");
        $st->execute([$k['makine_id']]);
        if (in_array($st->fetchColumn(), ['kiradan_gelecek','bakim_bekliyor','inceleme_bekliyor','bakimda','serviste','satildi','pasif'], true)) return false;
    }
    kiralamaMakineDurumu($db, $k['makine_tur'], (int)$k['makine_id'], $islem === 'iade' ? opIadeOlayi($db, $k) : $islem);
    return true;
}
