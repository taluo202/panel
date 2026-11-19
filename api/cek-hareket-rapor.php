<?php
declare(strict_types=1);

function csRaporHareketleri(array $history, array $current): array {
    $events=[];
    foreach ($history as $h) {
        $old=json_decode($h['eski'] ?? 'null',true);
        $new=json_decode($h['yeni'] ?? 'null',true);
        if (!$new || ($new['yon'] ?? '') !== 'alinan' || ($new['tip'] ?? '') !== 'cek') continue;
        $status=$new['durum'] ?? '';
        $type=match($status) {'ciro'=>'ciro_verilen','tahsil'=>'tahsil_edilen',default=>null};
        if ($status==='portfoyde' && ($old['durum'] ?? '')==='ciro') $type='ciro_iade';
        if ($status==='portfoyde' && ($old['durum'] ?? '')==='portfoyde' && ($events[count($events)-1]['tur'] ?? '')==='ciro_iade') $type='ciro_iade';
        if (!$type) continue;
        $event=['tur'=>$type,'tarih'=>$new['durum_tarihi'] ?? null,'tutar'=>(float)$new['tutar'],'alici'=>$new['ciro_alici'] ?? null];
        if ($old && ($old['durum'] ?? '')===$status) {
            $index=null;
            for($i=count($events)-1;$i>=0;$i--) if($events[$i]['tur']===$type){$index=$i;break;}
            if($index!==null){$events[$index]=$event;continue;}
        }
        $events[]=$event;
    }
    if (!$events && ($current['tip'] ?? '')==='cek' && ($current['yon'] ?? '')==='alinan' && in_array($current['durum'] ?? '',['ciro','tahsil'],true)) {
        $events[]=['tur'=>$current['durum']==='ciro'?'ciro_verilen':'tahsil_edilen','tarih'=>$current['durum_tarihi'] ?? null,'tutar'=>(float)$current['tutar'],'alici'=>$current['ciro_alici'] ?? null];
    }
    return $events;
}
