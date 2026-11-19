<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_once __DIR__.'/filo-referans-lib.php';
if(session_status()===PHP_SESSION_NONE)session_start();
$user=requireAuth();requirePermission('rapor','oku');$db=getDb();frSema($db);
$method=$_SERVER['REQUEST_METHOD'];
if(!in_array($method,['GET','POST'],true))jsonError('Method Not Allowed',405);
if($method==='GET'){
 $rows=[];
 foreach(['arac'=>'araclar','manlift'=>'manliftler'] as $tur=>$table){
  $where=[];$params=[];subeKosulEkle($where,$params,'sube');
  $q=$db->prepare('SELECT id,'.($tur==='arac'?'plaka':'kod').' AS ad,marka,model,sube,temin_sekli FROM '.$table.($where?' WHERE '.implode(' AND ',$where):''));$q->execute($params);
  foreach($q->fetchAll() as $m){$m['tur']=$tur;$m['key']=$tur.':'.$m['id'];$m['yazabilir']=hasPermission($tur,'yaz');$rows[]=$m;}
 }
 $data=frVeri($db);$allowed=array_flip(array_column($rows,'key'));
 $data['sinif']=array_intersect_key($data['sinif'],$allowed);$data['fiyat']=array_intersect_key($data['fiyat'],$allowed);
 jsonOk(['makineler'=>$rows,'veri'=>$data,'kur_gecmisi'=>$db->query('SELECT * FROM filo_kur_gecmisi ORDER BY tarih DESC,id DESC')->fetchAll(),'kur_yazabilir'=>hasPermission('finans','yaz')&&subeKisiti()===null]);
}
verifyCsrf();$body=json_decode(file_get_contents('php://input'),true);if(!is_array($body))jsonError('Geçersiz istek.');
try{
 $action=$body['action']??'';
 if(!in_array($action,['sinif','fiyat','kur'],true))throw new InvalidArgumentException('Geçersiz işlem.');
 if($action==='kur'){
  requirePermission('finans','yaz');if(subeKisiti()!==null)jsonError('Ortak kur tablosunu merkez finans yetkilisi düzenleyebilir.',403);
 }else{
  $tur=$body['tur']??'';if(!in_array($tur,['arac','manlift'],true))throw new InvalidArgumentException('Makine türü geçersiz.');
  requirePermission($tur,'yaz');$id=filter_var($body['makine_id']??0,FILTER_VALIDATE_INT);if(!$id||$id<1)throw new InvalidArgumentException('Makine seçin.');
  $table=$tur==='arac'?'araclar':'manliftler';subeKayitDogrula($db,$table,$id);$q=$db->prepare("SELECT id FROM $table WHERE id=?");$q->execute([$id]);if(!$q->fetch())jsonError('Makine bulunamadı.',404);
 }
 $who=(string)($user['ad_soyad']??$user['username']??'Yetkili');
 if($action==='sinif'){
  $ana=frMetin($body['ana_grup']??'');$alt=frMetin($body['alt_grup']??'');$enerji=frMetin($body['enerji']??'');
  if($alt!==''&&$ana==='')throw new InvalidArgumentException('Alt grup için önce ana grup seçin.');
  if(!in_array($enerji,['','elektrik','dizel','benzin','lpg','hibrit','diger'],true))throw new InvalidArgumentException('Enerji türü geçersiz.');
  $rev=filter_var($body['surum']??null,FILTER_VALIDATE_INT);if($rev===false||$rev<0)throw new InvalidArgumentException('Kaydı yenileyin.');
  $db->beginTransaction();$q=$db->prepare('SELECT * FROM filo_siniflar WHERE tur=? AND makine_id=?');$q->execute([$tur,$id]);$old=$q->fetch()?:null;
  if((int)($old['surum']??0)!==$rev){$db->rollBack();jsonError('Kayıt başka kullanıcı tarafından değişti; yenileyin.',409);}
  $db->prepare('INSERT INTO filo_siniflar(tur,makine_id,ana_grup,alt_grup,enerji,surum) VALUES(?,?,?,?,?,?) ON CONFLICT(tur,makine_id) DO UPDATE SET ana_grup=excluded.ana_grup,alt_grup=excluded.alt_grup,enerji=excluded.enerji,surum=excluded.surum')->execute([$tur,$id,$ana,$alt,$enerji,$rev+1]);
  logla($db,'filo_siniflar',$id,'guncelle',$old,['ana_grup'=>$ana,'alt_grup'=>$alt,'enerji'=>$enerji],$tur.' analiz sınıflandırması');$db->commit();jsonOk(null,'Sınıflandırma kaydedildi.');
 }
 $date=frTarih($body['tarih']??null);$currency=$body['para']??'';
 if(!in_array($currency,$action==='kur'?['USD','EUR']:['TL','USD','EUR'],true))throw new InvalidArgumentException('Para birimi geçersiz.');
 $source=frMetin($body['kaynak']??'',500);if($source==='')throw new InvalidArgumentException('Belge veya veri kaynağı açıklaması zorunludur.');
 $revision=filter_var($body['onceki_id']??null,FILTER_VALIDATE_INT);if($revision===false||$revision<0)throw new InvalidArgumentException('Önceki kayıt bilgisi eksik; yenileyin.');
 if($action==='kur'){
  $rate=frSayi($body['kur']??null);if($rate<=0)throw new InvalidArgumentException('Kur sıfırdan büyük olmalı.');if($date>date('Y-m-d'))throw new InvalidArgumentException('Gelecek gün için gerçekleşmiş kur girilemez.');
 }else{
  $daily=frSayi($body['gunluk']??null,true);$weekly=frSayi($body['haftalik']??null,true);$monthly=frSayi($body['aylik']??null,true);
  if($daily===null&&$weekly===null&&$monthly===null)throw new InvalidArgumentException('En az bir liste fiyatı girin.');
 }
 $db->beginTransaction();
 $q=$db->prepare($action==='kur'?'SELECT MAX(id) FROM filo_kur_gecmisi WHERE tarih=? AND para=?':'SELECT MAX(id) FROM filo_fiyat_gecmisi WHERE tarih=? AND para=? AND tur=? AND makine_id=?');
 $q->execute($action==='kur'?[$date,$currency]:[$date,$currency,$tur,$id]);
 if((int)$q->fetchColumn()!==$revision){$db->rollBack();jsonError('Bu tarih ve para birimi için kayıt değişti; yenileyin.',409);}
 if($action==='kur')$db->prepare('INSERT INTO filo_kur_gecmisi(tarih,para,kur,kaynak,kullanici) VALUES(?,?,?,?,?)')->execute([$date,$currency,$rate,$source,$who]);
 else $db->prepare('INSERT INTO filo_fiyat_gecmisi(tur,makine_id,tarih,para,gunluk,haftalik,aylik,kaynak,kullanici) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$tur,$id,$date,$currency,$daily,$weekly,$monthly,$source,$who]);
 $newId=(int)$db->lastInsertId();logla($db,$action==='kur'?'filo_kur_gecmisi':'filo_fiyat_gecmisi',$newId,'ekle',null,$body,'Önceki sürüm korunarak referans kaydı eklendi.');$db->commit();jsonOk(['id'=>$newId],'Kaydedildi; önceki sürümler korundu.');
}catch(InvalidArgumentException $e){if($db->inTransaction())$db->rollBack();jsonError($e->getMessage());}
