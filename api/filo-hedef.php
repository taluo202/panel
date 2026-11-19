<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';require_once __DIR__.'/filo-hedef-kurallari.php';requireAuth();requirePermission('rapor','oku');$db=getDb();fhSema($db);
header('Cache-Control: no-store');$ay=(string)($_GET['ay']??date('Y-m'));$branch=subeKisiti()??(trim((string)($_GET['sube']??''))?:null);
try{
 $data=fhRapor($db,$ay,$branch);
 if($_SERVER['REQUEST_METHOD']==='GET'){$data['yazabilir']=hasPermission('yonetim','yaz');$q=$db->prepare('SELECT grup,onceki,yeni,gerekce,kullanici,zaman FROM filo_hedef_gecmis WHERE ay=? ORDER BY id DESC');$q->execute([$ay]);$allowed=array_flip(array_column($data['gruplar'],'grup'));$data['gecmis']=array_values(array_filter($q->fetchAll(),fn($r)=>isset($allowed[$r['grup']])));jsonOk($data);}
 if($_SERVER['REQUEST_METHOD']!=='POST')jsonError('Method Not Allowed',405);requirePermission('yonetim','yaz');verifyCsrf();
 $b=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);if(!is_array($b))throw new DomainException('İstek geçersiz.');
 $key=(string)($b['grup']??'');$group=null;foreach($data['gruplar'] as $r)if($r['grup']===$key)$group=$r;if(!$group)throw new DomainException('Grup bulunamadı veya erişim kapsamınızda değil.');
 $target=$b['hedef']??null;if(!is_scalar($target)||!preg_match('/^\d{1,10}(\.\d{1,2})?$/',(string)$target)||(float)$target<=0)throw new DomainException('Pozitif aylık hedef fiyatı girin (KDV hariç TL).');
 $why=trim((string)($b['gerekce']??''));if(!$why||mb_strlen($why)>1000)throw new DomainException('Hedef kayıt / düzeltme gerekçesi gerekli.');
 $db->beginTransaction();$q=$db->prepare('SELECT * FROM filo_donem_hedefleri WHERE ay=? AND grup=?');$q->execute([$ay,$key]);$old=$q->fetch();
 if(!is_int($b['surum']??null)||$b['surum']!==(int)($old['surum']??0))throw new DomainException('Hedef değişti; raporu yenileyin.');
 $dims=array_intersect_key($group,array_flip(['tur','marka','model','metraj','sube','temin']));
 $db->prepare('INSERT INTO filo_donem_hedefleri VALUES(?,?,?,?,?) ON CONFLICT(ay,grup) DO UPDATE SET hedef=excluded.hedef,surum=excluded.surum')->execute([$ay,$key,json_encode($dims,JSON_UNESCAPED_UNICODE),round((float)$target,2),(int)($old['surum']??0)+1]);
 $db->prepare('INSERT INTO filo_hedef_gecmis(ay,grup,onceki,yeni,gerekce,kullanici,zaman) VALUES(?,?,?,?,?,?,?)')->execute([$ay,$key,$old['hedef']??null,(float)$target,$why,$_SESSION['username']??'',date('Y-m-d H:i:s')]);
 logla($db,'filo_donem_hedefleri',null,$old?'guncelle':'ekle',['hedef'=>$old['hedef']??null],['hedef'=>round((float)$target,2)],'Filo hedefi '.$ay.' · '.$key.' · '.$why);
 $db->commit();jsonOk(null,'Yalnız seçilen ayın hedefi kaydedildi.');
}catch(DomainException $e){if($db->inTransaction())$db->rollBack();jsonError($e->getMessage(),422);}catch(JsonException $e){if($db->inTransaction())$db->rollBack();jsonError('Geçersiz istek gövdesi.',400);}catch(Throwable $e){if($db->inTransaction())$db->rollBack();error_log($e->getMessage());jsonError('Hedef kaydedilemedi.',500);}
