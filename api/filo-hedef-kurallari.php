<?php
declare(strict_types=1);
require_once __DIR__.'/filo-hedef-tarihce.php';
function fhSema(PDO $db):void{$db->exec("CREATE TABLE IF NOT EXISTS filo_donem_hedefleri(ay TEXT NOT NULL,grup TEXT NOT NULL,boyutlar TEXT NOT NULL,hedef REAL NOT NULL,surum INTEGER NOT NULL DEFAULT 1,PRIMARY KEY(ay,grup));CREATE TABLE IF NOT EXISTS filo_hedef_gecmis(id INTEGER PRIMARY KEY AUTOINCREMENT,ay TEXT,grup TEXT,onceki REAL,yeni REAL,gerekce TEXT,kullanici TEXT,zaman TEXT);");}
function fhBoyut(array $m):array{return ['tur'=>$m['tur'],'marka'=>trim((string)$m['marka']),'model'=>trim((string)$m['model']),'metraj'=>is_numeric($m['metraj'])?(string)(float)$m['metraj']:'','sube'=>trim((string)$m['sube']),'temin'=>($m['temin_sekli']??'stok')?:'stok'];}
function fhOranlar(array $g):array{
 $g['ortalama_fiyat']=$g['kirali_gun']>0&&!$g['eksik_kur']&&!($g['cakisma']??0)?round($g['gelir']/$g['kirali_gun']*30,2):null;
 $g['hedef_ciro']=$g['hedef']!==null?round(($g['kapasite_gun']??($g['adet']*$g['gun']))*$g['hedef']/30,2):null;
 $g['fiyat_gerceklesme']=$g['hedef']>0&&$g['ortalama_fiyat']!==null?round($g['ortalama_fiyat']/$g['hedef']*100,2):null;
 $g['ciro_gerceklesme']=$g['hedef_ciro']>0&&!$g['eksik_kur']&&!($g['cakisma']??0)?round($g['gelir']/$g['hedef_ciro']*100,2):null;
 return $g;
}
function fhRapor(PDO $db,string $ay,?string $sube=null):array{
 if(!preg_match('/^(19|20|21)\d{2}-(0[1-9]|1[0-2])$/',$ay))throw new DomainException('Geçerli ay seçin.');
 $bas=$ay.'-01';$son=date('Y-m-t',strtotime($bas));$bit=min($son,date('Y-m-d'));$gun=$bas>$bit?0:(int)(new DateTimeImmutable($bas))->diff(new DateTimeImmutable($bit))->days+1;
 if($gun&&($history=fhTarihselRapor($db,$ay,$bas,$bit,$gun,$sube))!==null)return $history;
 $rows=$db->query("SELECT id,'arac' AS tur,marka,model,NULL AS metraj,sube,temin_sekli,aktif,durum FROM araclar UNION ALL SELECT id,'manlift',marka,model,metraj,sube,temin_sekli,aktif,durum FROM manliftler")->fetchAll();
 $q=$db->prepare('SELECT * FROM filo_donem_hedefleri WHERE ay=?');$q->execute([$ay]);$targets=array_column($q->fetchAll(),null,'grup');$groups=[];$machines=[];
 foreach($rows as $m){if(!(int)$m['aktif']||in_array($m['durum'],['satildi','pasif'],true)||($sube!==null&&trim((string)$m['sube'])!==$sube))continue;$dims=fhBoyut($m);$key=hash('sha256',json_encode($dims,JSON_UNESCAPED_UNICODE));$machines[$m['tur'].':'.$m['id']]=$key;
  if(!isset($groups[$key]))$groups[$key]=$dims+['grup'=>$key,'adet'=>0,'hedef'=>isset($targets[$key])?(float)$targets[$key]['hedef']:null,'surum'=>(int)($targets[$key]['surum']??0),'gelir'=>0.0,'kirali_gun'=>0,'gun'=>$gun,'eksik_kur'=>0,'cakisma'=>0];$groups[$key]['adet']++;
 }
 $occupied=[];
 if($gun){$q=$db->prepare("SELECT * FROM kiralamalar WHERE durum<>'iptal' AND baslangic<=?");$q->execute([$bit]);foreach($q->fetchAll() as $r){$mk=$r['makine_tur'].':'.$r['makine_id'];if(!isset($machines[$mk]))continue;$ix=kiralamaDonemKesisimi($r,$bas,$bit);if(!$ix)continue;$key=$machines[$mk];
  if(($r['doviz']??'TL')!=='TL'){$groups[$key]['eksik_kur']++;continue;}
  $amount=kiralamaTutarPencereli($r,$bas,$bit);if($amount===null)continue;$groups[$key]['gelir']+=$amount;
  $groups[$key]['kirali_gun']+=(int)$ix['gun'];
  for($d=new DateTimeImmutable($ix['dBas']);$d<=new DateTimeImmutable($ix['dBit']);$d=$d->modify('+1 day')){$day=$d->format('Y-m-d');if(isset($occupied[$mk][$day]))$groups[$key]['cakisma']++;$occupied[$mk][$day]=true;}
 }}
 return ['ay'=>$ay,'bas'=>$bas,'bit'=>$gun?$bit:null,'gun'=>$gun,'gruplar'=>array_values(array_map('fhOranlar',$groups)),'kapsam'=>'Güncel aktif filo ve bugünkü makine şubesine göre; dönem hedefleri ayrı saklanır. Geçmiş envanter değişimleri bilinmediğinden kapasite hedefi tahminidir. Gelecek günler gerçekleşmeye katılmaz.'];
}
