<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';require_once __DIR__.'/analiz-kaynak-kurallari.php';require_once __DIR__.'/kasa-kurallari.php';
requireAuth(); yazmaIstegiCsrf();requirePermission('yonetim');if(subeKisiti()!==null)jsonError('Kaynak mutabakatı için şirket geneli yetki gerekir.',403);
$db=getDb();akKaynakSema($db);header('Cache-Control: no-store');
try{
 $tur=(string)($_GET['tur']??'');$id=(int)($_GET['id']??0);$ay=(string)($_GET['ay']??'');
 if(!preg_match('/^(19|20|21)\d{2}-(0[1-9]|1[0-2])$/',$ay))throw new DomainException('Geçerli ay seçin.');
 $source=akKaynak($db,$tur,$id);if(!$source)throw new DomainException('Kaynak kayıt bulunamadı.');
 $key=$tur==='kayarkasa'?$ay:'';$q=$db->prepare('SELECT * FROM analiz_kaynak_tutarlari WHERE tur=? AND kaynak_id=? AND ay=?');$q->execute([$tur,$id,$key]);$old=$q->fetch(PDO::FETCH_ASSOC)?:null;
 if($_SERVER['REQUEST_METHOD']==='GET'){
  $q=$db->prepare('SELECT gerekce,kullanici,zaman,eski,yeni FROM analiz_kaynak_gecmis WHERE tur=? AND kaynak_id=? AND ay=? ORDER BY id DESC');$q->execute([$tur,$id,$key]);
  jsonOk(['kaynak'=>$source,'hash'=>akHash($source),'kayit'=>$old,'gecmis'=>$q->fetchAll(),'subeler'=>$db->query('SELECT ad FROM subeler WHERE aktif=1 ORDER BY ad')->fetchAll(PDO::FETCH_COLUMN),'yazabilir'=>hasPermission('yonetim','yaz')]);
 }
 if($_SERVER['REQUEST_METHOD']!=='POST')jsonError('Method Not Allowed',405);verifyCsrf();
 $b=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);if(!is_array($b))throw new DomainException('İstek geçersiz.');
 $date=kasaTarih($b['tarih']??null);if(substr($date,0,7)!==$ay)throw new DomainException('Gelir / maliyet tarihi seçilen ayda olmalı.');
 $why=trim((string)($b['gerekce']??''));$belge=trim((string)($b['belge']??''));if(!$why||!$belge||mb_strlen($why)>1000||mb_strlen($belge)>300)throw new DomainException('Belge ve gerekçe gerekli; en fazla 300 / 1000 karakter.');
 $sube=trim((string)($b['sube']??''));$q=$db->prepare('SELECT 1 FROM subeler WHERE ad=? AND aktif=1');$q->execute([$sube]);if(!$q->fetchColumn())throw new DomainException('Geçerli şube seçin.');
 $money=function($v):float{if(!is_scalar($v)||!preg_match('/^\d{1,10}(\.\d{1,2})?$/',(string)$v))throw new DomainException('KDV hariç TL tutarı girin; en fazla iki ondalık basamak.');return round((float)$v,2);};
 $cost=$money($b['maliyet']??null);$income=$tur==='servis'?$money($b['gelir']??null):null;
 $db->beginTransaction();$source=akKaynak($db,$tur,$id);if(!$source||!hash_equals(akHash($source),(string)($b['hash']??'')))throw new DomainException('Kaynak değişti; yeniden açıp kontrol edin.');
 $q=$db->prepare('SELECT * FROM analiz_kaynak_tutarlari WHERE tur=? AND kaynak_id=? AND ay=?');$q->execute([$tur,$id,$key]);$old=$q->fetch(PDO::FETCH_ASSOC)?:null;
 if(!is_int($b['surum']??null)||$b['surum']!==(int)($old['surum']??0))throw new DomainException('Başka kullanıcı değiştirdi; kaydı yeniden açın.');
 if($tur==='servis'&&($source['durum']!=='tamamlandi'||!$source['musteriye_yansit']))throw new DomainException('Yalnız tamamlanmış ve müşteriye yansıtılan servise satış tutarı girilir.');
 if($tur==='satis'&&$date!==$source['tarih'])throw new DomainException('Satış maliyeti satış kaydının tarihiyle aynı olmalı.');
 if($tur==='kayarkasa'&&!akKayarkasaMi($db,$source))throw new DomainException('Bu kayıt aktif kayarkasa kiralaması değil.');
 $new=['tur'=>$tur,'kaynak_id'=>$id,'ay'=>$key,'tarih'=>$date,'sube'=>$sube,'gelir'=>$income,'maliyet'=>$cost,'belge'=>$belge,'kaynak_hash'=>akHash($source),'surum'=>(int)($old['surum']??0)+1];
 $db->prepare('INSERT INTO analiz_kaynak_tutarlari VALUES(?,?,?,?,?,?,?,?,?,?) ON CONFLICT(tur,kaynak_id,ay) DO UPDATE SET tarih=excluded.tarih,sube=excluded.sube,gelir=excluded.gelir,maliyet=excluded.maliyet,belge=excluded.belge,kaynak_hash=excluded.kaynak_hash,surum=excluded.surum')->execute(array_values($new));
 $db->prepare('INSERT INTO analiz_kaynak_gecmis(tur,kaynak_id,ay,eski,yeni,gerekce,kullanici,zaman) VALUES(?,?,?,?,?,?,?,?)')->execute([$tur,$id,$key,json_encode($old,JSON_UNESCAPED_UNICODE),json_encode($new,JSON_UNESCAPED_UNICODE),$why,$_SESSION['username']??'',date('Y-m-d H:i:s')]);
 logla($db,'analiz_kaynak_tutarlari',$id,$old?'guncelle':'ekle',$old?:null,$new,'Kaynak tutarı ('.$tur.' #'.$id.', '.$key.') · '.$why);
 $db->commit();jsonOk(null,'Kaynak tutarı kaydedildi; raporu yeniden getirin.');
}catch(DomainException $e){if($db->inTransaction())$db->rollBack();jsonError($e->getMessage(),422);}catch(JsonException $e){if($db->inTransaction())$db->rollBack();jsonError('Geçersiz istek gövdesi.',400);}catch(Throwable $e){if($db->inTransaction())$db->rollBack();error_log($e->getMessage());jsonError('Kaynak kaydedilemedi; değişiklikler geri alındı.',500);}

