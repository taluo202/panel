<?php
declare(strict_types=1);

function csSurum(array $record): string {
    unset($record['musteri_unvan'], $record['vade_durum'], $record['surum'], $record['gecmis']);
    ksort($record);
    return hash('sha256', json_encode($record, JSON_UNESCAPED_UNICODE));
}

function csIslemTarihi(array $body, ?array $old): ?string {
    $status = (string)($body['durum'] ?? $old['durum'] ?? 'portfoyde');
    if (!in_array($status, ['portfoyde','tahsil','odendi','karsiliksiz','iade','ciro'], true)) throw new InvalidArgumentException('Geçerli çek / senet durumu seçin.');
    if (mb_strlen((string)($body['islem_gerekcesi'] ?? '')) > 1000) throw new InvalidArgumentException('İşlem açıklaması en fazla 1000 karakter olabilir.');
    if ($status==='ciro') {
        if (($body['yon'] ?? $old['yon'] ?? 'alinan')!=='alinan') throw new InvalidArgumentException('Yalnız müşteriden alınan çek / senet başka firmaya ciro edilebilir.');
        $recipient=trim((string)($body['ciro_alici'] ?? $old['ciro_alici'] ?? ''));
        if ($recipient==='' || mb_strlen($recipient)>200) throw new InvalidArgumentException('Çekin verildiği firma / kişi adını girin (en fazla 200 karakter).');
    }
    $changed = $old && $status !== $old['durum'];
    $value = trim((string)($body['durum_tarihi'] ?? ($changed ? '' : ($old['durum_tarihi'] ?? ''))));
    if ($value !== '') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('Gerçek işlem tarihi geçersiz.');
        if ($value > date('Y-m-d')) throw new InvalidArgumentException('Gerçekleşmiş işlem için gelecek tarih seçilemez.');
        if ($changed && !empty($old['durum_tarihi']) && $value < $old['durum_tarihi']) throw new InvalidArgumentException('Yeni hareket tarihi önceki durum tarihinden önce olamaz. Önce önceki tarihteki hatayı düzeltin.');
        $received = $body['teslim_tarihi'] ?? $old['teslim_tarihi'] ?? null;
        if ($received && $value < $received) throw new InvalidArgumentException('İşlem tarihi alınma / verilme tarihinden önce olamaz.');
    }
    if (($changed || (!$old && $status !== 'portfoyde')) && $value === '') {
        throw new InvalidArgumentException('Durum değişikliği için işlemin gerçekleştiği tarihi girin. Vade tarihi otomatik kullanılmaz.');
    }
    if ($old && $status === 'tahsil' && $value === '') {
        foreach (['tutar','musteri_id','yon'] as $key) {
            if (array_key_exists($key,$body) && (string)$body[$key] !== (string)($old[$key] ?? '')) throw new InvalidArgumentException('Tahsil edilmiş kaydın tutarını veya müşterisini değiştirmeden önce gerçek tahsilat tarihini tamamlayın.');
        }
    }
    if ($old && ($changed || $value !== (string)($old['durum_tarihi'] ?? '')) && trim((string)($body['islem_gerekcesi'] ?? '')) === '') {
        throw new InvalidArgumentException('Durum veya işlem tarihi değişikliği için kısa bir açıklama girin.');
    }
    return $value ?: null;
}

function csDuzeltmeSirasi(PDO $db, array $body, array $current): void {
    if (empty($body['durum_tarihi']) || ($body['durum'] ?? $current['durum']) !== $current['durum']) return;
    $q=$db->prepare('SELECT eski,yeni FROM cek_senet_gecmis WHERE cek_id=? ORDER BY id DESC');$q->execute([$current['id']]);
    foreach($q->fetchAll() as $event){
        $old=json_decode($event['eski'] ?? 'null',true);$new=json_decode($event['yeni'] ?? 'null',true);
        if(!$old || !$new)continue;
        if(($new['durum'] ?? '')===$current['durum'] && ($old['durum'] ?? '')!==$current['durum']){
            if(!empty($old['durum_tarihi']) && $body['durum_tarihi']<$old['durum_tarihi'])throw new InvalidArgumentException('Düzeltilen tarih önceki hareketten önce olamaz. Önce önceki tarihteki hatayı düzeltin.');
            break;
        }
    }
}

function csIslemKaydet(PDO $db, int $id, ?array $old, ?array $new, string $reason): void {
    $action = !$old ? 'ekle' : (!$new ? 'sil' : 'guncelle');
    $name = $_SESSION['ad_soyad'] ?? $_SESSION['username'] ?? '';
    $uid = $_SESSION['user_id'] ?? null;
    $db->prepare('INSERT INTO cek_senet_gecmis(cek_id,eylem,islem_tarihi,gerekce,eski,yeni,kullanici_id,kullanici_ad) VALUES(?,?,?,?,?,?,?,?)')
       ->execute([$id,$action,$new['durum_tarihi'] ?? null,$reason,json_encode($old,JSON_UNESCAPED_UNICODE),json_encode($new,JSON_UNESCAPED_UNICODE),$uid,$name]);
    logla($db, 'cek_senet', $id, $action, $old, $new, 'Çek/senet · ' . $reason);
}
