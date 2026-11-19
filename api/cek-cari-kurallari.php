<?php
declare(strict_types=1);
require_once __DIR__.'/cek-gecmis-stok.php';
function crSema(PDO $db):void {
 $db->exec("CREATE TABLE IF NOT EXISTS cek_risk_siniflari(id INTEGER PRIMARY KEY AUTOINCREMENT,cek_id INTEGER NOT NULL,olay_id INTEGER NOT NULL DEFAULT 0,sinif TEXT NOT NULL,tarih TEXT NOT NULL,gerekce TEXT NOT NULL,kullanici TEXT NOT NULL,kayit_zamani TEXT NOT NULL DEFAULT(datetime('now','localtime')))");
}
function crKredi(array $c):float {
 return ($c['tip']??'')==='cek'&&($c['yon']??'')==='alinan'&&!empty($c['musteri_id'])&&in_array($c['durum']??'',['portfoyde','ciro','tahsil','karsiliksiz'],true)?(float)$c['tutar']:0.0;
}
function crVeri(PDO $db,?string $cutoff=null,?int $customer=null):array {
 crSema($db);$day=$cutoff??date('Y-m-d');$out=['mahsup'=>[],'risk'=>[],'risk_toplam'=>['bekliyor'=>0.0,'avukatlik'=>0.0,'batik'=>0.0],'kayitlar'=>[],'hareketler'=>[],'bagli_tahsilatlar'=>[],'uyarilar'=>[]];
 $hist=[];foreach($db->query('SELECT * FROM cek_senet_gecmis ORDER BY id') as $h)$hist[(int)$h['cek_id']][]=$h;
 $classes=[];foreach($db->query('SELECT * FROM cek_risk_siniflari ORDER BY tarih DESC,id DESC') as $r)if($r['tarih']<=$day){$key=$r['cek_id'].':'.$r['olay_id'];$classes[$key]??=$r;}
 $collections=[];foreach($db->query('SELECT id,musteri_id,tarih,tutar FROM tahsilatlar') as $t)$collections[(int)$t['id']]=$t;
 foreach($db->query('SELECT * FROM cek_senet') as $current){
  if($current['tip']!=='cek')continue;$cid=(int)$current['id'];$timeline=csStokZamanCizelgesi($hist[$cid]??[]);$receipt=csStokTarih($current['teslim_tarihi']??null);
  if(!$receipt){if($customer===null||(int)$current['musteri_id']===$customer)$out['uyarilar'][]='Çek #'.$cid.': alınma tarihi eksik; cari kapama doğrulanamıyor.';continue;}
  if(!$timeline){
   if($current['durum']==='portfoyde')$timeline=[['tarih'=>$receipt,'kayit'=>$current]];
   elseif($day>=date('Y-m-d')){$timeline=[['tarih'=>max($receipt,csStokTarih($current['durum_tarihi']??null)??$receipt),'kayit'=>$current]];if($customer===null||(int)$current['musteri_id']===$customer)$out['uyarilar'][]='Çek #'.$cid.': önceki durum geçmişi eksik; geçmiş dönem hareketi kesin değildir.';}
   else {if($customer===null||(int)$current['musteri_id']===$customer)$out['uyarilar'][]='Çek #'.$cid.': dönem sonu durumu için işlem geçmişi eksik.';continue;}
  }
  $state=null;$oldAmount=0.0;$oldCustomer=0;
  foreach($timeline as $segment){if($segment['tarih']>$day)break;$next=$segment['kayit'];$received=csStokTarih($next['teslim_tarihi']??null);if(!$received||$received>$day)continue;
   $amount=crKredi($next);$mid=(int)($next['musteri_id']??0);$date=max($received,$segment['tarih']);
   if($oldCustomer&&($customer===null||$oldCustomer===$customer)&&($mid!==$oldCustomer||$amount!==$oldAmount))$out['hareketler'][]=['tarih'=>$date,'sira'=>1,'tip'=>'cek_mahsup','aciklama'=>'Çek #'.$cid.' önceki cari kapama düzeltmesi','borc'=>$oldAmount,'odeme'=>0.0,'musteri_id'=>$oldCustomer];
   if($amount>0&&($customer===null||$mid===$customer)&&($mid!==$oldCustomer||$amount!==$oldAmount))$out['hareketler'][]=['tarih'=>$date,'sira'=>1,'tip'=>'cek_mahsup','aciklama'=>'Çek teslimi ile cari kapama #'.$cid.' (nakit tahsilat değildir)','borc'=>0.0,'odeme'=>$amount,'musteri_id'=>$mid];
   $oldAmount=$amount;$oldCustomer=$mid;$state=$next;
  }
  if(!$state)continue;
  $linked=$collections[(int)($current['tahsilat_id']??0)]??null;
  if($linked&&$linked['tarih']<=$day&&($customer===null||(int)$linked['musteri_id']===$customer)){
   $out['bagli_tahsilatlar'][]=(int)$linked['id'];$lm=(int)$linked['musteri_id'];$out['mahsup'][$lm]=($out['mahsup'][$lm]??0)-(float)$linked['tutar'];
  }
  $mid=(int)($state['musteri_id']??0);if($customer!==null&&$mid!==$customer)continue;$amount=crKredi($state);
  if($amount>0)$out['mahsup'][$mid]=($out['mahsup'][$mid]??0)+$amount;
  if($state['durum']!=='karsiliksiz'||$amount<=0)continue;
  $episode=0;foreach($hist[$cid]??[] as $h){$n=json_decode($h['yeni']??'null',true);$o=json_decode($h['eski']??'null',true);if(($n['durum']??'')==='karsiliksiz'&&($o['durum']??'')!=='karsiliksiz'&&($n['durum_tarihi']??'9999')<=$day)$episode=(int)$h['id'];}
  $classification=$classes[$cid.':'.$episode]??null;$kind=$classification['sinif']??'bekliyor';
  $out['risk'][$mid]??=['bekliyor'=>0.0,'avukatlik'=>0.0,'batik'=>0.0];$out['risk'][$mid][$kind]+=$amount;$out['risk_toplam'][$kind]+=$amount;
  $out['kayitlar'][]=['cek_id'=>$cid,'musteri_id'=>$mid,'olay_id'=>$episode,'belge_no'=>$state['belge_no']??'','tutar'=>$amount,'sinif'=>$kind,'sinif_id'=>(int)($classification['id']??0),'tarih'=>$state['durum_tarihi']??null,'gerekce'=>$classification['gerekce']??null];
 }
 return $out;
}
