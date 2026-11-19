<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_once __DIR__.'/aylik-durum-kurallari.php';
if(session_status()===PHP_SESSION_NONE)session_start();
requireAuth();requirePermission('yonetim');
if(subeKisiti()!==null)jsonError('Şirket geneli aylık tablo için tüm şubelere erişim gereklidir.',403);
header('Cache-Control: no-store');
$db=getDb();adSema($db);$method=$_SERVER['REQUEST_METHOD'];
if($method==='GET'){
 $y=(string)($_GET['yil']??date('Y'));if(!preg_match('/^(19|20|21)\d{2}$/',$y))jsonError('Yıl geçersiz.');
 $s=$db->prepare('SELECT * FROM aylik_durum WHERE ay LIKE ? ORDER BY ay');$s->execute([$y.'-%']);$rows=[];
 foreach($s->fetchAll() as $r){$r['veri']=json_decode($r['veri'],true);$r['hesap']=adHesap($r['veri']);$rows[]=$r;}
 $s=$db->prepare('SELECT ay,surum,gerekce,kullanici,zaman FROM aylik_durum_gecmis WHERE ay LIKE ? ORDER BY id DESC');$s->execute([$y.'-%']);
 jsonOk(['rows'=>$rows,'gecmis'=>$s->fetchAll(),'alanlar'=>adAlanlar(),'kategoriler'=>adKategoriler(),'yazabilir'=>hasPermission('yonetim','yaz')]);
}
if($method!=='POST')jsonError('Method Not Allowed',405);
verifyCsrf();
try{
 $b=json_decode(file_get_contents('php://input'),true,64,JSON_THROW_ON_ERROR);if(!is_array($b))throw new DomainException('İstek geçersiz.');
 $ay=$b['ay']??'';if(!is_string($ay)||!preg_match('/^(19|20|21)\d{2}-(0[1-9]|1[0-2])$/',$ay))throw new DomainException('Ay geçersiz.');
 $why=trim((string)($b['gerekce']??''));if($why===''||mb_strlen($why)>1000)throw new DomainException('Kayıt / düzeltme gerekçesi gerekli (en fazla 1000 karakter).');
 if(!is_array($b['veri']??[]))throw new DomainException('Aylık veri geçersiz.');$v=adVeri($b['veri']??[],$ay);$json=json_encode($v,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
 $db->beginTransaction();$s=$db->prepare('SELECT surum FROM aylik_durum WHERE ay=?');$s->execute([$ay]);$old=(int)$s->fetchColumn();
 if(!isset($b['surum'])||!is_int($b['surum'])||$b['surum']!==$old){$db->rollBack();jsonError('Ay başka bir oturumda değişti. Yenileyip tekrar kontrol edin.',409);}
 $n=$old+1;$actor=(string)($_SESSION['username']??$_SESSION['user_id']);$time=date('Y-m-d H:i:s');
 $db->prepare('INSERT INTO aylik_durum VALUES(?,?,?,?,?) ON CONFLICT(ay) DO UPDATE SET surum=excluded.surum,veri=excluded.veri,kullanici=excluded.kullanici,zaman=excluded.zaman')->execute([$ay,$n,$json,$actor,$time]);
 $db->prepare('INSERT INTO aylik_durum_gecmis(ay,surum,veri,gerekce,kullanici,zaman) VALUES(?,?,?,?,?,?)')->execute([$ay,$n,$json,$why,$actor,$time]);
 $db->commit();jsonOk(['surum'=>$n],'Aylık durum kaydedildi.');
}catch(DomainException $e){if($db->inTransaction())$db->rollBack();jsonError($e->getMessage(),422);}catch(JsonException $e){if($db->inTransaction())$db->rollBack();jsonError('Geçersiz istek gövdesi.',400);}catch(Throwable $e){if($db->inTransaction())$db->rollBack();error_log('aylik_durum: '.$e->getMessage());jsonError('Kayıt tamamlanamadı.',500);}
