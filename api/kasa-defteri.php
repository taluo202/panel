<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';require_once __DIR__.'/kasa-kurallari.php';
requireAuth();requirePermission('yonetim');
if(subeKisiti()!==null)jsonError('Şirket geneli kasa defteri için tüm şubelere erişim gerekir.',403);
header('Cache-Control: no-store');$db=getDb();kasaSema($db);$method=$_SERVER['REQUEST_METHOD'];
try{
 $ay=(string)($_GET['ay']??date('Y-m'));$data=kasaAy($db,$ay);
 if($method==='GET'){
  if(isset($_GET['id'])){$q=$db->prepare('SELECT * FROM kasa_gecmis WHERE kayit_id=? ORDER BY id DESC');$q->execute([(int)$_GET['id']]);jsonOk($q->fetchAll());}
  $data['yazabilir']=hasPermission('yonetim','yaz');jsonOk($data);
 }
 if(!in_array($method,['POST','PUT','DELETE'],true))jsonError('Method Not Allowed',405);
 verifyCsrf();$b=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);if(!is_array($b))throw new DomainException('İstek geçersiz.');
 $why=trim((string)($b['gerekce']??''));if(mb_strlen($why)>1000||($why===''&&$method!=='POST'))throw new DomainException('Gerekçe gerekli (en fazla 1000 karakter).');
 if($why===''&&($b['action']??'')==='mutabakat')throw new DomainException('Gerekçe gerekli (en fazla 1000 karakter).');
 $actor=(string)($_SESSION['username']??$_SESSION['user_id']);$db->beginTransaction();
 islemKilitAl($db);
 if(($b['action']??'')==='mutabakat'){
  if($method!=='POST')throw new DomainException('Geçersiz yöntem.');
  if(date('Y-m-t',strtotime($ay.'-01'))>=date('Y-m-d'))throw new DomainException('Ay sona erdikten sonra mutabakat yapılabilir.');
  $current=kasaAy($db,$ay);if(!is_string($b['hash']??null)||!hash_equals($current['hash'],$b['hash']))throw new DomainException('Kayıtlar değişti; listeyi yenileyin.');
  $db->prepare('INSERT INTO kasa_mutabakat VALUES(?,?,?,?) ON CONFLICT(ay) DO UPDATE SET ozet_hash=excluded.ozet_hash,kullanici=excluded.kullanici,zaman=excluded.zaman')->execute([$ay,$current['hash'],$actor,date('Y-m-d H:i:s')]);
  kasaGecmis($db,0,'mutabakat',$why,null,['ay'=>$ay,'hash'=>$current['hash'],'ozet'=>$current['ozet']],$actor);
  logla($db,'kasa_mutabakat',null,'guncelle',null,['ay'=>$ay,'hash'=>$current['hash']],'Kasa ay kontrolü '.$ay.' · '.$why);
  $db->commit();jsonOk(null,'Ay kontrol edildi. Sonraki değişiklikte yeniden kontrol gerekir.');
 }
 $old=null;$id=(int)($b['id']??0);
 if($method!=='POST'){
  $q=$db->prepare('SELECT * FROM kasa_hareketleri WHERE id=?');$q->execute([$id]);$old=$q->fetch(PDO::FETCH_ASSOC);
  if(!$old||substr($old['tarih'],0,7)!==$ay)throw new DomainException('Bu ayda kayıt bulunamadı.');
  if($old['iptal'])throw new DomainException('İptal edilmiş kayıt değiştirilemez.');
  if(!is_int($b['surum']??null)||$b['surum']!==(int)$old['surum'])throw new DomainException('Kayıt başka bir oturumda değişti; listeyi yenileyin.');
 }
 if($method==='DELETE'){$new=$old;$new['iptal']=1;$new['surum']++;$db->prepare('UPDATE kasa_hareketleri SET iptal=1,surum=surum+1 WHERE id=?')->execute([$id]);}
 else{
  $new=kasaVeri($b);if(substr($new['tarih'],0,7)!==$ay)throw new DomainException('İşlem tarihi seçilen ay içinde olmalı.');
  if($new['belge']==='')$new['belge']=$old?$old['belge']:kasaBelgeNo($db,$new['tarih']);
  if($why==='')$why='İlk kayıt';
  $args=array_values($new);
  if($old){$args[]=$id;$db->prepare('UPDATE kasa_hareketleri SET tarih=?,hesap=?,yon=?,kategori=?,kurus=?,belge=?,aciklama=?,surum=surum+1 WHERE id=?')->execute($args);}
  else{$db->prepare('INSERT INTO kasa_hareketleri(tarih,hesap,yon,kategori,kurus,belge,aciklama) VALUES(?,?,?,?,?,?,?)')->execute($args);$id=(int)$db->lastInsertId();}
 }
 kasaGecmis($db,$id,$method==='DELETE'?'iptal':($old?'duzeltme':'ekle'),$why,$old,$new,$actor);
 logla($db,'kasa_hareketleri',$id,$old?'guncelle':'ekle',$old?:null,$new,'Kasa '.($method==='DELETE'?'iptal':($old?'düzeltme':'hareket')).' · '.$why);
 $db->commit();jsonOk(['id'=>$id],'Kayıt ve geçmişi saklandı.');
}catch(DomainException $e){if($db->inTransaction())$db->rollBack();jsonError($e->getMessage(),422);}catch(JsonException $e){if($db->inTransaction())$db->rollBack();jsonError('Geçersiz istek gövdesi.',400);}catch(PDOException $e){if($db->inTransaction())$db->rollBack();if(str_contains($e->getMessage(),'UNIQUE'))jsonError('Bu belge satır numarası aktif bir kayıtta zaten kullanılıyor. Mevcut kaydı kontrol edin; iptal edilmiş kayıtların numarası yeniden kullanılabilir.',409);error_log($e->getMessage());jsonError('Kayıt tamamlanamadı; değişiklikler geri alındı.',500);}catch(Throwable $e){if($db->inTransaction())$db->rollBack();error_log('kasa-defteri: '.$e->getMessage().' @'.$e->getFile().':'.$e->getLine());jsonError('İşlem tamamlanamadı.',500);}
