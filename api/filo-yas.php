<?php
declare(strict_types=1);
function filoYasDetayi(array $makineler,int $yil):array{
 $groups=[];$sum=0;$known=0;$missing=0;
 foreach($makineler as $m){
  $dims=['tur'=>$m['tur'],'marka'=>trim((string)($m['marka']??'')),'model'=>trim((string)($m['model']??'')),'metraj'=>$m['metraj']??null,'sube'=>trim((string)($m['sube']??''))];
  $key=json_encode($dims,JSON_UNESCAPED_UNICODE);if(!isset($groups[$key]))$groups[$key]=$dims+['adet'=>0,'bilinen'=>0,'eksik'=>0,'yas_toplam'=>0];
  $g=&$groups[$key];$g['adet']++;$year=(int)($m['imal_yili']??0);
  if($year>1900&&$year<=$yil){$age=$yil-$year;$g['bilinen']++;$g['yas_toplam']+=$age;$sum+=$age;$known++;}else{$g['eksik']++;$missing++;}unset($g);
 }
 foreach($groups as &$g)$g['ortalama']=$g['bilinen']?round($g['yas_toplam']/$g['bilinen'],2):null;unset($g);
 return ['referans_yil'=>$yil,'ortalama'=>$known?round($sum/$known,2):null,'bilinen'=>$known,'eksik'=>$missing,'gruplar'=>array_values($groups)];
}
