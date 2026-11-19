<?php
declare(strict_types=1); require_once __DIR__.'/config.php'; if(session_status()===PHP_SESSION_NONE)session_start(); requireAuth(); yazmaIstegiCsrf(); requirePermission('dis_kiralama'); $db=getDb();
$id=(int)($_GET['dis_kiralama_id']??0); if($id<1)jsonError('Dış kiralama seçilmedi.'); $q=$db->prepare('SELECT id,makine_tur,makine_id FROM dis_kiralama_kayitlari WHERE id=?');$q->execute([$id]);$dk=$q->fetch();if(!$dk)jsonError('Kayıt bulunamadı.',404);
$mk=$db->prepare('SELECT sube FROM '.($dk['makine_tur']==='arac'?'araclar':'manliftler').' WHERE id=?');$mk->execute([(int)$dk['makine_id']]);$mkSube=$mk->fetchColumn();
if($mkSube===false)jsonError('Makine bulunamadı.',404); subeErisimDogrula($mkSube);
const DKB_TURLER = ['tedarik_sozlesmesi','teslim','iade','fatura','sigorta','ruhsat','muayene','diger','gelis_foto','iade_foto'];
if($_SERVER['REQUEST_METHOD']==='GET'){ $s=$db->prepare('SELECT id,belge_turu,belge_adi,belge_no,belge_tarihi,dosya_yolu,notlar,yukleyen,created_at FROM dis_kiralama_belgeler WHERE dis_kiralama_id=? ORDER BY id DESC');$s->execute([$id]);jsonOk($s->fetchAll()); }
if($_SERVER['REQUEST_METHOD']==='POST'){ verifyCsrf();$b=govdeOku();$data=(string)($b['veri']??'');if(!preg_match('/^data:(image\/(?:jpeg|jpg|png|webp)|application\/pdf);base64,([A-Za-z0-9+\/=]+)$/',$data,$m))jsonError('Yalnız JPG, PNG, WEBP veya PDF kabul edilir.');$bin=base64_decode($m[2],true);if($bin===false||strlen($bin)<100||strlen($bin)>MAX_FILE_SIZE)jsonError('Dosya boyutu geçersiz.');$pdf=str_starts_with($m[1],'application/');if($pdf?substr($bin,0,4)!=='%PDF':!getimagesizefromstring($bin))jsonError('Dosya içeriği doğrulanamadı.');$ext=$pdf?'pdf':($m[1]==='image/jpeg'||$m[1]==='image/jpg'?'jpeg':substr($m[1],6));$dir=UPLOAD_PATH.'dis_kiralama/'.$id.'/';if(!is_dir($dir))mkdir($dir,0755,true);$name=bin2hex(random_bytes(16)).'.'.$ext;if(file_put_contents($dir.$name,$bin)===false)jsonError('Dosya kaydedilemedi.',500);$rel='dis_kiralama/'.$id.'/'.$name;$s=$db->prepare('INSERT INTO dis_kiralama_belgeler(dis_kiralama_id,belge_turu,belge_adi,belge_no,belge_tarihi,dosya_yolu,notlar,yukleyen) VALUES(?,?,?,?,?,?,?,?)');$s->execute([$id,(in_array($b['belge_turu']??'',DKB_TURLER,true)?$b['belge_turu']:'diger'),trim((string)($b['belge_adi']??$name)),belgeNoAl($b),dateStr('belge_tarihi',$b),$rel,trim((string)($b['notlar']??''))?:null,(int)($_SESSION['user_id']??0)]);$bid=(int)$db->lastInsertId();
  logla($db,'dis_kiralama_belgeler',$bid,'ekle',null,logKayitAl($db,'dis_kiralama_belgeler',$bid),'DK #'.$id.' belgesi eklendi');
  jsonOk(['id'=>$bid,'dosya_yolu'=>$rel],'DK belgesi eklendi.');}
if($_SERVER['REQUEST_METHOD']==='PUT'){
  verifyCsrf();
  $bid=(int)($_GET['id']??0);
  $q=$db->prepare('SELECT * FROM dis_kiralama_belgeler WHERE id=? AND dis_kiralama_id=?');$q->execute([$bid,$id]);$eski=$q->fetch();
  if(!$eski)jsonError('Belge bulunamadı.',404);
  $b=json_decode(file_get_contents('php://input'),true);
  if(!is_array($b))jsonError('Geçersiz istek gövdesi.');
  alanlarZorunlu($b,['belge_adi','belge_turu','belge_no','belge_tarihi']);
  $ad=belgeAdiAl($b);
  if($ad==='')jsonError('Belge adı boş bırakılamaz.');
  $tur=in_array($b['belge_turu'],DKB_TURLER,true)?$b['belge_turu']:'diger';
  $db->prepare('UPDATE dis_kiralama_belgeler SET belge_adi=?,belge_turu=?,belge_no=?,belge_tarihi=? WHERE id=?')->execute([$ad,$tur,belgeNoAl($b),dateStr('belge_tarihi',$b),$bid]);
  logla($db,'dis_kiralama_belgeler',$bid,'guncelle',$eski,logKayitAl($db,'dis_kiralama_belgeler',$bid),'DK #'.$id.' belgesi düzenlendi: '.$ad);
  jsonOk(['id'=>$bid],'DK belgesi güncellendi.');
}
if($_SERVER['REQUEST_METHOD']==='DELETE'){verifyCsrf();$bid=(int)($_GET['id']??0);$q=$db->prepare('SELECT * FROM dis_kiralama_belgeler WHERE id=? AND dis_kiralama_id=?');$q->execute([$bid,$id]);$row=$q->fetch();if(!$row)jsonError('Belge bulunamadı.',404);$full=UPLOAD_PATH.$row['dosya_yolu'];if(is_file($full))@unlink($full);$db->prepare('DELETE FROM dis_kiralama_belgeler WHERE id=?')->execute([$bid]);logla($db,'dis_kiralama_belgeler',$bid,'sil',$row,null,'DK #'.$id.' belgesi silindi');jsonOk(null,'DK belgesi silindi.');} jsonError('Method Not Allowed',405);
