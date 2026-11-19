<?php
declare(strict_types=1);

function csStokTarih($value): ?string {
    if(!is_string($value))return null;
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    return $date && $date->format('Y-m-d')===$value ? $value : null;
}

function csStokZamanCizelgesi(array $history): array {
    $segments=[];$initialPortfolio=false;
    foreach($history as $event){
        $old=json_decode($event['eski']??'null',true);
        $new=json_decode($event['yeni']??'null',true);
        if(!$new)continue;
        $date=csStokTarih($new['durum_tarihi']??null);
        $same=$old && ($old['durum']??'')===($new['durum']??'');
        if(!$old)$initialPortfolio=($new['durum']??'')==='portfoyde';
        elseif(!$same)$initialPortfolio=false;
        if($initialPortfolio)$date=csStokTarih($new['teslim_tarihi']??null);
        if($same && $segments){
            $last=count($segments)-1;
            if($segments[$last]['ilk_portfoy'])$date=csStokTarih($new['teslim_tarihi']??null);
            else $date=$date ?? $segments[$last]['tarih'];
            if(!$date){$segments=[];continue;}
            if($last>0 && $date<$segments[$last-1]['tarih']){$segments=[];continue;}
            $segments[$last]=['tarih'=>$date,'kayit'=>$new,'ilk_portfoy'=>$segments[$last]['ilk_portfoy']];
            continue;
        }
        if(!$date){$segments=[];continue;}
        if($segments && $date<$segments[count($segments)-1]['tarih'])$segments=[];
        $segments[]=['tarih'=>$date,'kayit'=>$new,'ilk_portfoy'=>$initialPortfolio];
    }
    return $segments;
}

function csTarihteStok(array $current, array $segments, string $day): array {
    $received=csStokTarih($current['teslim_tarihi']??null);
    if($received && $received>$day)return ['biliniyor'=>true,'alinan'=>0.0,'verilen'=>0.0];
    $state=null;
    foreach($segments as $segment){if($segment['tarih']<=$day)$state=$segment['kayit'];else break;}
    if(!$state)return ['biliniyor'=>false,'neden'=>'Bu tarihteki durumu doğrulayan işlem geçmişi yok.'];
    if(($state['tip']??'')!=='cek')return ['biliniyor'=>false,'neden'=>'Kayıt türü sonradan değişmiş; tarihsel çek kapsamı doğrulanamıyor.'];
    if(!in_array($state['durum']??'',['portfoyde','tahsil','odendi','karsiliksiz','iade','ciro'],true))return ['biliniyor'=>false,'neden'=>'Geçmiş kaydın durumu tanınmıyor.'];
    return ['biliniyor'=>true,'alinan'=>($state['durum']==='portfoyde'&&$state['yon']==='alinan')?(float)$state['tutar']:0.0,'verilen'=>($state['durum']==='portfoyde'&&$state['yon']==='verilen')?(float)$state['tutar']:0.0];
}

function csAySonuStok(array $records, array $histories, int $year, string $today): array {
    $timelines=[];foreach($records as $record)$timelines[$record['id']]=csStokZamanCizelgesi($histories[$record['id']]??[]);
    $rows=[];
    for($month=1;$month<=12;$month++){
        $ym=sprintf('%04d-%02d',$year,$month);$day=(new DateTimeImmutable($ym.'-01'))->format('Y-m-t');
        $row=['ay'=>$ym,'tarih'=>$day,'kapandi'=>$day<$today,'bilinen_alinan'=>0.0,'bilinen_verilen'=>0.0,'alinan'=>null,'verilen'=>null,'eksik_adet'=>0,'eksikler'=>[]];
        if(!$row['kapandi']){$row['bilinen_alinan']=null;$row['bilinen_verilen']=null;$rows[]=$row;continue;}
        foreach($records as $record){
            $state=csTarihteStok($record,$timelines[$record['id']],$day);
            if(!$state['biliniyor']){$row['eksik_adet']++;$row['eksikler'][]=['id'=>(int)$record['id'],'belge_no'=>$record['belge_no']??null,'neden'=>$state['neden']];continue;}
            $row['bilinen_alinan']+=$state['alinan'];$row['bilinen_verilen']+=$state['verilen'];
        }
        foreach(['bilinen_alinan','bilinen_verilen'] as $key)$row[$key]=round($row[$key],2);
        if(!$row['eksik_adet']){$row['alinan']=$row['bilinen_alinan'];$row['verilen']=$row['bilinen_verilen'];}
        $rows[]=$row;
    }
    return $rows;
}
