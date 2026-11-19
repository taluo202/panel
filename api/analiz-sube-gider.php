<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
requireAuth(); yazmaIstegiCsrf();requirePermission('yonetim');$db=getDb();
function sgSurum(array $r):string{return hash('sha256',json_encode(array_intersect_key($r,array_flip(['id','ay','sube','kategori','tutar','aciklama','iptal_at'])),JSON_UNESCAPED_UNICODE));}
$ay=(string)($_GET['ay']??'');
if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$ay))jsonError('Geçerli dönem seçin.');
if($_SERVER['REQUEST_METHOD']==='DELETE'){
 verifyCsrf();$b=govdeOku();
 $why=trim((string)($b['gerekce']??''));if($why===''||mb_strlen($why)>1000)jsonError('İptal nedenini yazın (en fazla 1000 karakter).');
 $db->beginTransaction();try{
  $q=$db->prepare('SELECT * FROM analiz_sube_giderleri WHERE id=? AND ay=?');$q->execute([(int)($b['id']??0),$ay]);$old=$q->fetch();
  if(!$old){$db->rollBack();jsonError('Gider kaydı bulunamadı.',404);}
  subeErisimDogrula($old['sube']);
  if(!empty($old['iptal_at'])){$db->rollBack();jsonError('Bu gider zaten iptal edilmiş.');}
  if(!is_string($b['surum']??null)||!hash_equals(sgSurum($old),$b['surum'])){$db->rollBack();jsonError('Gider değişmiş. Listeyi yenileyip tekrar açın.',409);}
  $at=date('Y-m-d H:i:s');$db->prepare('UPDATE analiz_sube_giderleri SET iptal_at=? WHERE id=?')->execute([$at,$old['id']]);
  logla($db,'analiz_sube_giderleri',(int)$old['id'],'guncelle',['iptal_at'=>null],['iptal_at'=>$at],'Gider iptali · '.$why);
  $db->commit();jsonOk(['id'=>$old['id']],'Gider iptal edildi; kayıt ve işlem geçmişi korundu.');
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
if(in_array($_SERVER['REQUEST_METHOD'],['POST','PUT'],true)){
 verifyCsrf();$b=govdeOku();
 $sube=trim((string)($b['sube']??''));subeErisimDogrula($sube);
 $q=$db->prepare('SELECT 1 FROM subeler WHERE ad=? AND aktif=1');$q->execute([$sube]);if(!$q->fetchColumn())jsonError('Geçerli şube seçin.');
 $t=$b['tutar']??null;if(!is_numeric($t)||!is_finite((float)$t)||(float)$t<=0||(float)$t>1e12)jsonError('Geçerli pozitif gider tutarı gerekli.');$t=round((float)$t,2);if($t<=0)jsonError('Gider en az 0,01 TL olmalı.');
 $kat=(string)($b['kategori']??'');if(!in_array($kat,['kira','maas','yemek','elektrik','su','telefon','internet','aidat','ssk','mazot','arac_tamir','aku','diger','makine_alim','makine_taksit','demirbas','merkez_gideri'],true))jsonError('Geçerli gider kategorisi seçin.');
 $not=trim((string)($b['aciklama']??''));if($not===''||mb_strlen($not)>1000)jsonError('Kaynak belge veya açıklama gerekli (en fazla 1000 karakter).');
 $why=trim((string)($b['gerekce']??''));
 if($_SERVER['REQUEST_METHOD']==='PUT'&&$why==='')jsonError('Düzeltme gerekçesi gerekli.');
 $db->beginTransaction();
 try{
  $old=null;
  if($_SERVER['REQUEST_METHOD']==='PUT'){
   $id=(int)($b['id']??0);$q=$db->prepare('SELECT * FROM analiz_sube_giderleri WHERE id=? AND ay=?');$q->execute([$id,$ay]);$old=$q->fetch();
   if(!$old){$db->rollBack();jsonError('Gider kaydı bulunamadı.',404);}
   subeErisimDogrula($old['sube']);
   if(!empty($old['iptal_at'])){$db->rollBack();jsonError('İptal edilmiş gider düzenlenemez.');}
   if($old['sube']!==$sube){$db->rollBack();jsonError('Düzeltmede şube değiştirilemez.');}
   if(!is_string($b['surum']??null)||!hash_equals(sgSurum($old),$b['surum'])){$db->rollBack();jsonError('Bu gider başka bir oturumda değişti. Listeyi yenileyerek tekrar açın.',409);}
   $db->prepare('UPDATE analiz_sube_giderleri SET kategori=?,tutar=?,aciklama=? WHERE id=?')->execute([$kat,(float)$t,$not,$id]);
  }else{
   $db->prepare('INSERT INTO analiz_sube_giderleri(ay,sube,kategori,tutar,aciklama,created_by) VALUES(?,?,?,?,?,?)')->execute([$ay,$sube,$kat,(float)$t,$not,$_SESSION['user_id']]);$id=(int)$db->lastInsertId();
  }
  $alanlar=['kategori'=>$kat,'tutar'=>(float)$t,'aciklama'=>$not];
  logla($db,'analiz_sube_giderleri',$id,$old?'guncelle':'ekle',$old?array_intersect_key($old,$alanlar):null,$alanlar,$ay.' '.$sube.' şube gideri'.($why?' · '.$why:''));
  $db->commit();jsonOk(['id'=>$id],$old?'Gider düzeltildi; önceki bilgiler denetim kaydında saklandı.':'Gider kaydedildi.');
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
if($_SERVER['REQUEST_METHOD']!=='GET')jsonError('Method Not Allowed',405);
$w=['ay=?'];$a=[$ay];subeKosulEkle($w,$a,'sube');$q=$db->prepare('SELECT * FROM analiz_sube_giderleri WHERE '.implode(' AND ',$w).' ORDER BY sube,id');$q->execute($a);$rows=$q->fetchAll();foreach($rows as &$r)$r['surum']=sgSurum($r);unset($r);jsonOk($rows);
