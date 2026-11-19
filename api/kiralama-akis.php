<?php
declare(strict_types=1);

function kiralamaAkis(PDO $db, array $k, bool $nakliyeOku, ?string $sube, string $today): array {
    $id = (int)$k['id'];
    $oku = function (string $sql, array $args = []) use ($db): array {
        $st = $db->prepare($sql); $st->execute($args); return $st->fetchAll(PDO::FETCH_ASSOC);
    };
    $subeyeUygun = fn(array $r): bool => $sube === null || ($r['sube'] ?? '') === $sube;
    $teklifler = array_values(array_filter($oku('SELECT id,kod,tarih,sube FROM teklifler WHERE id=? OR kiralama_id=? ORDER BY id', [(int)($k['kaynak_teklif_id'] ?? 0), $id]), $subeyeUygun));
    $belgeler = $oku('SELECT id,belge_turu,form_no,form_tarihi,created_at FROM kiralama_belgeler WHERE kiralama_id=? ORDER BY id', [$id]);
    $tutanaklar = $oku('SELECT id,islem,tarih,asama,inceleme_sonucu FROM iade_tutanaklari WHERE kiralama_id=? AND COALESCE(fiili_teslim,1)=1 ORDER BY tarih,id', [$id]);
    $hak = array_values(array_filter($oku('SELECT id,kod,durum,genel_toplam,fatura_no,fatura_tarihi,donem_bas,donem_bit,sube FROM hakedisler WHERE kiralama_id=? ORDER BY donem_bas,id', [$id]), $subeyeUygun));
    $nakliye = $nakliyeOku ? array_values(array_filter($oku('SELECT id,tarih,yon,sube FROM nakliyeler WHERE kiralama_id=? OR id IN (SELECT nakliye_id FROM nakliye_makineleri WHERE kiralama_id=?) ORDER BY tarih,id', [$id, $id]), $subeyeUygun)) : [];
    $tahsilatlar = array_values(array_filter($oku('SELECT t.id,t.tarih,t.tutar,t.hakedis_id,t.kiralama_id,t.sube FROM tahsilatlar t WHERE t.kiralama_id=? OR t.hakedis_id IN (SELECT id FROM hakedisler WHERE kiralama_id=?) ORDER BY t.tarih,t.id', [$id, $id]), $subeyeUygun));
    $izinliHak = array_fill_keys(array_column($hak, 'id'), true);
    $odenen = []; $toplamTahsil = 0.0;
    foreach ($tahsilatlar as $t) {
        if ($t['hakedis_id'] && !isset($izinliHak[$t['hakedis_id']])) continue;
        $toplamTahsil += (float)$t['tutar'];
        if ($t['hakedis_id']) $odenen[(int)$t['hakedis_id']] = ($odenen[(int)$t['hakedis_id']] ?? 0) + (float)$t['tutar'];
    }
    $taslak = 0; $fatura = 0; $acik = 0.0; $ilkAcik = null;
    foreach ($hak as $h) {
        if (!in_array($h['durum'], ['faturalandi','tahsil_edildi'], true)) { $taslak++; continue; }
        $fatura++;
        $kalan = max(0, round((float)$h['genel_toplam'] - ($odenen[(int)$h['id']] ?? 0), 2));
        $acik += $kalan;
        if ($kalan > 0.01 && $ilkAcik === null) $ilkAcik = (int)$h['id'];
    }
    $teslim = array_values(array_filter($tutanaklar, fn($r) => $r['islem'] === 'teslim'));
    $iade = array_values(array_filter($tutanaklar, fn($r) => $r['islem'] === 'iade'));
    $iptal = ($k['durum'] ?? '') === 'iptal';
    $iadeKapali = !empty($k['gercek_bitis']) || ($k['durum'] ?? '') === 'tamamlandi';
    $onay = $k['onay_durumu'] ?? 'gerekmiyor';
    $engelli = in_array($onay, ['bekliyor','reddedildi'], true);
    $sozlesme = !empty($k['sozlesme_no']) && !empty($k['sozlesme_imza_tarihi']);
    $asamalar = [];
    $ekle = function (string $anahtar, string $baslik, string $durum, string $aciklama, ?string $islem) use (&$asamalar) {
        $asamalar[] = compact('anahtar','baslik','durum','aciklama','islem');
    };
    $ekle('teklif','Teklif', $teklifler ? 'tamam' : 'bilgi', $teklifler ? implode(', ', array_map(fn($t) => $t['kod'] ?: '#' . $t['id'], $teklifler)) : 'Bağlı teklif yok. Doğrudan açılmış veya eski bir kayıt olabilir.', null);
    $ekle('onay','İskonto onayı', $engelli ? 'engelli' : 'tamam', $onay === 'bekliyor' ? 'Yönetici onayı bekleniyor.' : ($onay === 'reddedildi' ? 'İskonto reddedilmiş; kiralama gözden geçirilmeli.' : ($onay === 'onaylandi' ? 'Onay kaydı var.' : 'Ek onay gerekmiyor.')), $engelli ? 'duzenle' : null);
    $sozEksik = [];
    if (empty($k['sozlesme_no'])) $sozEksik[] = 'Sözleşme no';
    if (empty($k['sozlesme_imza_tarihi'])) $sozEksik[] = 'imza tarihi';
    $sozMetin = $sozlesme ? 'Sözleşme no ve imza tarihi kayıtlı.'
        : (count($sozEksik) === 2 ? 'Sözleşme no ve imza tarihi eksik.' : ($sozEksik[0] === 'imza tarihi' ? 'İmza tarihi eksik.' : 'Sözleşme no eksik.'));
    $ekle('sozlesme','Sözleşme', $sozlesme ? 'tamam' : 'eksik', $sozMetin . ' Yüklenen evrak: ' . count($belgeler) . '.', 'sozlesme');
    $ekle('nakliye','Sevkiyat', !$nakliyeOku ? 'bilgi' : ($nakliye ? 'tamam' : 'bilgi'),
          !$nakliyeOku ? 'Araç, şoför ve yön bu kaydın Nakliye bölümünde. Nakliye tutarları ve Nakliye sayfası için nakliye modülü yetkisi gerekir.'
                       : (count($nakliye) . ' nakliye kaydı. Nakliye kaydı teslim edildiği anlamına gelmez.'), $nakliyeOku ? 'nakliye' : null);
    $ekle('teslim','Teslim', $teslim ? 'tamam' : 'eksik', $teslim ? count($teslim) . ' teslim tutanağı kayıtlı.' : 'Teslim tutanağı yok; başlangıç tarihi teslim kanıtı sayılmaz.', 'tutanak');
    $iadeDurum = $iade && !empty($k['gercek_bitis']) ? 'tamam' : (($iade || $iadeKapali || (!empty($k['bitis']) && $k['bitis'] < $today)) ? 'eksik' : 'bekliyor');
    $ekle('iade','İade', $iadeDurum, $iadeDurum === 'tamam' ? 'İade tutanağı ve gerçek iade tarihi kayıtlı.' : ($iadeKapali && !$iade ? 'Kiralama kapatılmış; iade tutanağı eksik.' : ($iade && empty($k['gercek_bitis']) ? 'İade tutanağı var; gerçek iade tarihi eksik.' : 'İade tutanağı bekleniyor.')), 'tutanak');
    $ekle('hakedis','Hakediş', $hak ? 'tamam' : ($iadeKapali ? 'eksik' : 'bekliyor'), count($hak) . ' hakediş kaydı. Dönemlerin tamamının kapsandığı anlamına gelmez.', 'hakedis');
    $ekle('fatura','Fatura', $taslak ? 'eksik' : (($fatura || !empty($k['faturalandi'])) ? 'tamam' : 'bekliyor'), $fatura . ' faturalanmış hakediş, ' . $taslak . ' taslak.' . (!empty($k['faturalandi']) ? ' Kiralama üzerinde ayrıca fatura işareti var.' : ''), 'hakedis_liste');
    $ekle('tahsilat','Tahsilat', $fatura ? ($acik > 0.01 ? 'eksik' : 'tamam') : 'bekliyor', $fatura ? 'Faturalanmış hakedişlerde kalan: ' . number_format($acik, 2, ',', '.') . ' ₺.' : 'Faturalanmış hakediş bulunmuyor; tahsilat tamamlandı denemez.', 'tahsilat');
    $siradaki = $iptal ? 'Kiralama iptal edilmiş. İlişkili mali kayıtları ayrıca kontrol edin.' : null;
    if (!$siradaki) foreach ($asamalar as $a) if (in_array($a['durum'], ['engelli','eksik'], true)) { $siradaki = $a['aciklama']; break; }
    if (!$siradaki) $siradaki = 'Kayıtlı adımlarda eksik görünmüyor. Bekleyen dönem ve işlemleri takip edin.';
    $olaylar = [];
    $olay = function (?string $tarih, string $baslik, string $detay) use (&$olaylar) { if ($tarih) $olaylar[] = compact('tarih','baslik','detay'); };
    $olay($k['created_at'] ?? null, 'Kiralama oluşturuldu', $k['kod'] ?? ('#' . $id));
    foreach ($teklifler as $r) $olay($r['tarih'], 'Kaynak teklif', $r['kod'] ?: '#' . $r['id']);
    $olay($k['sozlesme_imza_tarihi'] ?? null, 'Sözleşme imza tarihi', $k['sozlesme_no'] ?? '');
    foreach ($nakliye as $r) $olay($r['tarih'], 'Nakliye kaydı', '#' . $r['id'] . ' · ' . ($r['yon'] === 'gidis' ? 'Gidiş' : ($r['yon'] === 'donus' ? 'Dönüş' : 'Nakliye')));
    foreach ($tutanaklar as $r) $olay($r['tarih'], $r['islem'] === 'teslim' ? 'Teslim tutanağı'
        : (($r['asama'] ?? '') === 'saha_alim' ? 'Teslim alma kaydı' : (!empty($r['inceleme_sonucu']) ? 'İade tutanağı (dönüş kontrolü)' : 'İade tutanağı')), '#' . $r['id']);
    $olay($k['gercek_bitis'] ?? null, 'Gerçek iade tarihi', $k['kod'] ?? '');
    foreach ($hak as $r) $olay($r['fatura_tarihi'], 'Hakediş fatura tarihi', $r['kod'] ?: '#' . $r['id']);
    foreach ($tahsilatlar as $r) if (!$r['hakedis_id'] || isset($izinliHak[$r['hakedis_id']])) $olay($r['tarih'], 'Tahsilat kaydı', '#' . $r['id'] . ' · ' . number_format((float)$r['tutar'], 2, ',', '.') . ' ₺');
    usort($olaylar, fn($a,$b) => strcmp($b['tarih'], $a['tarih']));
    return ['asamalar'=>$asamalar, 'siradaki'=>$siradaki, 'iptal'=>$iptal, 'engelli'=>$engelli,
        'teklifler'=>$teklifler, 'ilk_acik_hakedis'=>$ilkAcik,
        'toplam_tahsilat'=>round($toplamTahsil,2), 'acik_hakedis'=>round($acik,2),
        'olaylar'=>$olaylar, 'tarih'=>$today];
}
