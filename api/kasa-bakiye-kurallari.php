<?php
declare(strict_types=1);
require_once __DIR__.'/kasa-kurallari.php';
function kbSema(PDO $db):void{
 kasaSema($db);$db->exec('CREATE TABLE IF NOT EXISTS kasa_acilis(tarih TEXT PRIMARY KEY,kasa_kurus INTEGER NOT NULL,banka_kurus INTEGER NOT NULL,belge TEXT NOT NULL,surum INTEGER NOT NULL DEFAULT 1)');
}
function kbPara($value,bool $negative=false):int{
 if(!is_scalar($value)||!preg_match($negative?'/^-?\d{1,10}(\.\d{1,2})?$/':'/^\d{1,10}(\.\d{1,2})?$/',(string)$value))throw new DomainException('TL tutarını en fazla iki ondalık basamakla girin. Kasa negatif olamaz.');
 return (int)round((float)$value*100);
}
function kbHesap(array $opening,array $rows):array{
 $cash=(int)$opening['kasa_kurus'];$bank=(int)$opening['banka_kurus'];$transfer=[];$used=0;
 foreach($rows as $r){if($r['iptal'])continue;$amount=(int)$r['kurus']*($r['yon']==='gelir'?1:-1);if($r['hesap']==='kasa')$cash+=$amount;else $bank+=$amount;$used++;
  if($r['kategori']==='transfer')$transfer[$r['tarih']]=($transfer[$r['tarih']]??0)+$amount;
 }
 $issues=[];foreach($transfer as $day=>$net)if($net!==0)$issues[]=$day.': iç transferin giriş/çıkış toplamı eşleşmiyor. İki hesabın hareketlerini kontrol edin.';
 if($cash<0)$issues[]='Hesaplanan kasa bakiyesi negatif; açılış ve hareketleri kontrol edin.';
 return ['kasa'=>$cash/100,'banka'=>$bank/100,'toplam'=>($cash+$bank)/100,'hareket_adedi'=>$used,'eksikler'=>$issues];
}
function kbRapor(PDO $db,string $date):array{
 kasaTarih($date);$q=$db->prepare('SELECT * FROM kasa_acilis WHERE tarih<=? ORDER BY tarih DESC LIMIT 1');$q->execute([$date]);$opening=$q->fetch(PDO::FETCH_ASSOC);
 if(!$opening)return ['tarih'=>$date,'acilis'=>null,'bakiye'=>null,'dogrulandi'=>false,'eksikler'=>['Bu tarihe kadar kaydedilmiş kasa/banka açılışı yok.'],'hash'=>null];
 $q=$db->prepare('SELECT * FROM kasa_hareketleri WHERE tarih>=? AND tarih<=? ORDER BY tarih,id');$q->execute([$opening['tarih'],$date]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$result=kbHesap($opening,$rows);$issues=$result['eksikler'];$checks=[];
 $cursor=new DateTimeImmutable(substr($opening['tarih'],0,7).'-01');$end=new DateTimeImmutable(substr($date,0,7).'-01');
 if($cursor->diff($end)->y>10)$issues[]='Açılış on yıldan eski; daha yakın tarihli doğrulanmış açılış girin.';
 else for(;$cursor<=$end;$cursor=$cursor->modify('+1 month')){$month=$cursor->format('Y-m');$r=kasaAy($db,$month);$checks[]=['ay'=>$month,'hash'=>$r['hash'],'dogrulandi'=>$r['dogrulandi']];if(!$r['dogrulandi'])$issues[]=$month.': kasa hareketleri ay sonu kontrolünden geçmemiş veya sonradan değişmiş.';}
 return ['tarih'=>$date,'acilis'=>$opening,'bakiye'=>$result,'dogrulandi'=>!$issues,'eksikler'=>$issues,'hash'=>kasaHash([$opening,$rows,$checks]),'kontroller'=>$checks];
}
