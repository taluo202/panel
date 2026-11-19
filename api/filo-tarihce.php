<?php
declare(strict_types=1);

function ftSema(PDO $db):void {
 $db->exec("CREATE TABLE IF NOT EXISTS filo_tarihce_meta(id INTEGER PRIMARY KEY CHECK(id=1),baslangic TEXT NOT NULL);
 CREATE TABLE IF NOT EXISTS filo_tarihce(id INTEGER PRIMARY KEY AUTOINCREMENT,tur TEXT NOT NULL,makine_id INTEGER NOT NULL,gun TEXT NOT NULL,zaman TEXT NOT NULL,olay TEXT NOT NULL,marka TEXT,model TEXT,metraj REAL,sube TEXT,temin_sekli TEXT,aktif INTEGER,durum TEXT);
 CREATE INDEX IF NOT EXISTS filo_tarihce_makine ON filo_tarihce(tur,makine_id,gun,id);
 CREATE INDEX IF NOT EXISTS filo_tarihce_gun ON filo_tarihce(gun,id);");
 if($db->query('SELECT baslangic FROM filo_tarihce_meta WHERE id=1')->fetchColumn())return;
 $db->exec('SAVEPOINT filo_tarihce_kur');
 try{
  $q=$db->prepare('INSERT OR IGNORE INTO filo_tarihce_meta VALUES(1,?)');$q->execute([date('Y-m-d')]);
  if($q->rowCount()){
   foreach(['arac'=>'araclar','manlift'=>'manliftler'] as $tur=>$table){
    $met=$tur==='manlift'?'metraj':'NULL';
    $db->exec("INSERT INTO filo_tarihce(tur,makine_id,gun,zaman,olay,marka,model,metraj,sube,temin_sekli,aktif,durum) SELECT '$tur',id,date('now','+3 hours'),datetime('now','+3 hours'),'baslangic',marka,model,$met,sube,temin_sekli,aktif,durum FROM $table");
    foreach(['INSERT'=>'NEW','UPDATE'=>'NEW','DELETE'=>'OLD'] as $event=>$row){
     $met=$tur==='manlift'?"$row.metraj":'NULL';$active=$event==='DELETE'?'0':"$row.aktif";
     $state=$event==='DELETE'?"'silindi'":"$row.durum";
     $when=$event==='UPDATE'?' WHEN '.implode(' OR ',array_map(fn($c)=>"OLD.$c IS NOT NEW.$c",array_merge(['marka','model','sube','temin_sekli','aktif','durum'],$tur==='manlift'?['metraj']:[]))):'';
     $name='ft_'.$tur.'_'.strtolower($event);
     $db->exec("CREATE TRIGGER IF NOT EXISTS $name AFTER $event ON $table $when BEGIN INSERT INTO filo_tarihce(tur,makine_id,gun,zaman,olay,marka,model,metraj,sube,temin_sekli,aktif,durum) VALUES('$tur',$row.id,date('now','+3 hours'),datetime('now','+3 hours'),'".strtolower($event)."',$row.marka,$row.model,$met,$row.sube,$row.temin_sekli,$active,$state); END");
    }
   }
  }
  $db->exec('RELEASE SAVEPOINT filo_tarihce_kur');
 }catch(Throwable $e){$db->exec('ROLLBACK TO SAVEPOINT filo_tarihce_kur');$db->exec('RELEASE SAVEPOINT filo_tarihce_kur');throw $e;}
}

function ftBaslangic(PDO $db):?string {
 if(!$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='filo_tarihce_meta'")->fetchColumn())return null;
 return $db->query('SELECT baslangic FROM filo_tarihce_meta WHERE id=1')->fetchColumn()?:null;
}

function ftGunler(PDO $db,string $bas,string $bit):?array {
 $known=ftBaslangic($db);if(!$known||$bas<$known||$bas>$bit)return null;
 $q=$db->prepare('SELECT * FROM filo_tarihce WHERE gun<=? ORDER BY gun,zaman,id');$q->execute([$bit]);
 $events=$q->fetchAll(PDO::FETCH_ASSOC);$i=0;$state=[];$days=[];
 for($date=new DateTimeImmutable($bas);$date<=new DateTimeImmutable($bit);$date=$date->modify('+1 day')){
  $day=$date->format('Y-m-d');
  while(isset($events[$i])&&$events[$i]['gun']<=$day){$m=$events[$i++];$state[$m['tur'].':'.$m['makine_id']]=$m;}
  $days[$day]=$state;
 }
 return $days;
}

function ftDahil(array $m,?string $sube=null,string $tur='',string $temin=''):bool {
 return (int)$m['aktif']===1&&!in_array($m['durum'],['pasif','satildi','silindi'],true)
  &&($sube===null||$sube===''||trim((string)$m['sube'])===$sube)
  &&($tur===''||$m['tur']===$tur)&&($temin===''||(($m['temin_sekli']?:'stok')===$temin));
}

function ftDoluluk(PDO $db,string $bas,string $bit,?string $sube=null,string $tur='',string $temin=''):?array {
 $days=ftGunler($db,$bas,$bit);if($days===null)return null;
 $q=$db->prepare("SELECT * FROM kiralamalar WHERE durum<>'iptal' AND baslangic<=?");$q->execute([$bit]);$occupied=[];$overlaps=[];
 foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$ix=kiralamaDonemKesisimi($r,$bas,$bit);if(!$ix)continue;$mk=$r['makine_tur'].':'.$r['makine_id'];
  for($d=new DateTimeImmutable($ix['dBas']);$d<=new DateTimeImmutable($ix['dBit']);$d=$d->modify('+1 day')){$day=$d->format('Y-m-d');if(isset($occupied[$day][$mk]))$overlaps[$day][$mk]=true;$occupied[$day][$mk]=true;}
 }
 $groups=[];$capacity=0;$rented=0;$clash=0;
 foreach($days as $day=>$machines)foreach($machines as $mk=>$m){if(!ftDahil($m,$sube,$tur,$temin))continue;
  $dims=['tur'=>$m['tur'],'sube'=>trim((string)$m['sube'])?:'Belirtilmemiş','marka'=>$m['marka']??'','model'=>$m['model']??'','metraj'=>$m['metraj']];$key=json_encode($dims,JSON_UNESCAPED_UNICODE);
  if(!isset($groups[$key]))$groups[$key]=$dims+['uygun_gun'=>0,'kirali_gun'=>0,'kapasite_gunler'=>[],'gunler'=>[],'adet'=>0];
  $g=&$groups[$key];$g['uygun_gun']++;$capacity++;$g['kapasite_gunler'][$day]=($g['kapasite_gunler'][$day]??0)+1;$g['adet']=max($g['adet'],$g['kapasite_gunler'][$day]);
  if(isset($occupied[$day][$mk])){$g['kirali_gun']++;$rented++;$g['gunler'][$day]=($g['gunler'][$day]??0)+1;}
  if(isset($overlaps[$day][$mk]))$clash++;unset($g);
 }
 $detail=[];foreach($groups as $g){$g['doluluk']=$g['uygun_gun']?round($g['kirali_gun']/$g['uygun_gun']*100,2):null;$detail[]=$g;}
 return ['uygun'=>$capacity,'kirali'=>$rented,'cakisma'=>$clash,'detay'=>$detail,'seri'=>array_values($groups)];
}
