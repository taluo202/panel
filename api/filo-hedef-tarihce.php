<?php
declare(strict_types=1);
require_once __DIR__.'/filo-tarihce.php';

function fhTarihselRapor(PDO $db,string $ay,string $bas,string $bit,int $gun,?string $sube):?array {
 $days=ftGunler($db,$bas,$bit);if($days===null)return null;
 $q=$db->prepare('SELECT * FROM filo_donem_hedefleri WHERE ay=?');$q->execute([$ay]);$targets=array_column($q->fetchAll(),null,'grup');$groups=[];$membership=[];$seen=[];
 foreach($days as $day=>$machines)foreach($machines as $mk=>$m){if(!ftDahil($m,$sube))continue;
  $dims=fhBoyut($m);$key=hash('sha256',json_encode($dims,JSON_UNESCAPED_UNICODE));$membership[$day][$mk]=$key;
  if(!isset($groups[$key]))$groups[$key]=$dims+['grup'=>$key,'adet'=>0,'kapasite_gun'=>0,'hedef'=>isset($targets[$key])?(float)$targets[$key]['hedef']:null,'surum'=>(int)($targets[$key]['surum']??0),'gelir'=>0.0,'kirali_gun'=>0,'gun'=>$gun,'eksik_kur'=>0,'cakisma'=>0];
  $groups[$key]['kapasite_gun']++;if(!isset($seen[$key][$mk])){$seen[$key][$mk]=true;$groups[$key]['adet']++;}
 }
 $q=$db->prepare("SELECT * FROM kiralamalar WHERE durum<>'iptal' AND baslangic<=?");$q->execute([$bit]);$occupied=[];$fx=[];
 foreach($q->fetchAll() as $r){$ix=kiralamaDonemKesisimi($r,$bas,$bit);if(!$ix)continue;$mk=$r['makine_tur'].':'.$r['makine_id'];$amount=kiralamaTutarPencereli($r,$bas,$bit);if($amount===null)continue;
  for($date=new DateTimeImmutable($ix['dBas']);$date<=new DateTimeImmutable($ix['dBit']);$date=$date->modify('+1 day')){$day=$date->format('Y-m-d');$key=$membership[$day][$mk]??null;if(!$key)continue;
   if(isset($occupied[$day][$mk]))$groups[$key]['cakisma']++;$occupied[$day][$mk]=true;
   if(($r['doviz']??'TL')!=='TL'){if(!isset($fx[$key][$r['id']])){$groups[$key]['eksik_kur']++;$fx[$key][$r['id']]=true;}continue;}
   $groups[$key]['gelir']+=$amount/(int)$ix['gun'];$groups[$key]['kirali_gun']++;
  }
 }
 foreach($groups as &$g){$g['gelir']=round($g['gelir'],2);$g=fhOranlar($g);}unset($g);
 return ['ay'=>$ay,'bas'=>$bas,'bit'=>$bit,'gun'=>$gun,'gruplar'=>array_values($groups),'tarihsel_kapasite_dogrulandi'=>true,'kapsam'=>'Kaydedilmiş gün sonu envanteri ve o günkü makine şubesi kullanılır. Bakımdaki aktif makineler filo kapasitesine dahildir. Adet, dönem içinde grupta bulunan farklı makine sayısıdır; kapasite hedefi günlük adetlerden hesaplanır. Bugünün kaydı gün içinde değişebilir.'];
}
