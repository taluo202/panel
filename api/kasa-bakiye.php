<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';require_once __DIR__.'/kasa-bakiye-kurallari.php';
requireAuth();requirePermission('yonetim');if(subeKisiti()!==null)jsonError('Şirket geneli kasa bakiyesi için tüm şubelere erişim gerekir.',403);
$db=getDb();kbSema($db);header('Cache-Control: no-store');$method=$_SERVER['REQUEST_METHOD'];
try{
 if($method==='GET'){
  if(($_GET['action']??'')==='acilis'){
   $rows=$db->query('SELECT * FROM kasa_acilis ORDER BY tarih DESC')->fetchAll();$history=$db->query("SELECT gerekce,kullanici,zaman,eski,yeni FROM kasa_gecmis WHERE eylem='acilis' ORDER BY id DESC")->fetchAll();jsonOk(['rows'=>$rows,'gecmis'=>$history,'yazabilir'=>hasPermission('yonetim','yaz')]);
  }
  $tarih=$_GET['tarih']??date('Y-m-d');if(!is_string($tarih)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$tarih))throw new DomainException('Tarih YYYY-AA-GG biçiminde olmalı.');
  jsonOk(kbRapor($db,$tarih));
 }
 if($method!=='POST')jsonError('Method Not Allowed',405);verifyCsrf();$b=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);if(!is_array($b))throw new DomainException('İstek geçersiz.');
 $date=kasaTarih($b['tarih']??null);$cash=kbPara($b['kasa']??null);$bank=kbPara($b['banka']??null,true);$doc=trim((string)($b['belge']??''));$why=trim((string)($b['gerekce']??''));
 if(!$doc||mb_strlen($doc)>300||!$why||mb_strlen($why)>1000)throw new DomainException('Kaynak belge (en fazla 300) ve gerekçe (en fazla 1000 karakter) gerekli.');
 if(($b['teyit']??false)!==true)throw new DomainException('Tutarların belirtilen günün başlangıç bakiyesi olduğunu doğrulayın.');
 $db->beginTransaction();$q=$db->prepare('SELECT * FROM kasa_acilis WHERE tarih=?');$q->execute([$date]);$old=$q->fetch(PDO::FETCH_ASSOC)?:null;
 if(!is_int($b['surum']??null)||$b['surum']!==(int)($old['surum']??0))throw new DomainException('Açılış başka bir oturumda değişti; kayıtları yeniden yükleyin.');
 $new=['tarih'=>$date,'kasa_kurus'=>$cash,'banka_kurus'=>$bank,'belge'=>$doc,'surum'=>(int)($old['surum']??0)+1];
 $db->prepare('INSERT INTO kasa_acilis VALUES(?,?,?,?,?) ON CONFLICT(tarih) DO UPDATE SET kasa_kurus=excluded.kasa_kurus,banka_kurus=excluded.banka_kurus,belge=excluded.belge,surum=excluded.surum')->execute(array_values($new));
 kasaGecmis($db,0,'acilis',$why,$old,$new,(string)($_SESSION['username']??$_SESSION['user_id']));
 logla($db,'kasa_acilis',null,$old?'guncelle':'ekle',$old?:null,$new,'Kasa/banka açılış bakiyesi · '.$why);
 $db->commit();jsonOk(null,'Açılış kaydedildi. Gelir veya gider hareketi oluşturulmadı.');
}catch(DomainException $e){if($db->inTransaction())$db->rollBack();jsonError($e->getMessage(),422);}catch(JsonException $e){if($db->inTransaction())$db->rollBack();jsonError('Geçersiz istek gövdesi.',400);}catch(Throwable $e){if($db->inTransaction())$db->rollBack();error_log($e->getMessage());jsonError('Açılış kaydedilemedi; değişiklikler geri alındı.',500);}
