<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__.'/prim-paylasim.php';
if (session_status() === PHP_SESSION_NONE) session_start(); requireAuth(); requirePermission('yonetim');
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET','POST'], true)) jsonError('Method Not Allowed',405);
$db=getDb(); require_once __DIR__.'/analiz-maliyet.php'; $ay=preg_match('/^\d{4}-\d{2}$/',(string)($_GET['ay']??''))?$_GET['ay']:date('Y-m'); if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$ay))jsonError('Geçerli ay seçin.'); $bas=$ay.'-01'; $bit=date('Y-m-t',strtotime($bas));
$oran=primOraniAyar(ayarOku('sube_prim_orani'));
$_h=primAyHesapla($db,$ay,$oran,subeKisiti()); $out=$_h['list']; $eksikler=$_h['eksikler'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') { verifyCsrf(); if(subeKisiti()!==null)jsonError('Genel kapanış merkez yetkisi gerektirir.',403); if($eksikler)jsonError('Eksik kaynaklar nedeniyle kapanış yapılamaz.',409); $body=govdeOku(); if(empty($body['gider_mutabakati']))jsonError('Şube genel giderleri ve maliyet mutabakatı onayı gerekli.',409); $gerekce=trim((string)($body['gerekce']??'')); if($gerekce==='')jsonError('Kapanış gerekçesi zorunludur.'); [$surum,$toplam]=islemYanit($db,function(PDO $db)use($ay,$oran,$gerekce):array{$h=primAyHesapla($db,$ay,$oran,null);if($h['eksikler'])throw new DomainException('Eksik kaynaklar nedeniyle kapanış yapılamaz.');$out=$h['list'];$st=$db->prepare("SELECT COALESCE(MAX(surum),0)+1 FROM sube_prim_kapanislari WHERE ay=?");$st->execute([$ay]);$surum=(int)$st->fetchColumn();$toplam=array_sum(array_column($out,'prim'));$db->prepare("INSERT INTO sube_prim_kapanislari(ay,surum,oran,veri_json,toplam_prim,gerekce,created_by) VALUES(?,?,?,?,?,?,?)")->execute([$ay,$surum,$oran,json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$toplam,$gerekce,(int)($_SESSION['user_id']??0)]);
    logla($db,'sube_prim_kapanislari',(int)$db->lastInsertId(),'ekle',null,null,'Şube primi kapanışı '.$ay.' sürüm '.$surum.' · toplam '.round($toplam,2).' · '.$gerekce);
   return [$surum,$toplam];});jsonOk(['surum'=>$surum,'toplam_prim'=>round($toplam,2)],'Prim kapanışı kaydedildi.'); }
if(subeKisiti()!==null){$eksikler=$eksikler?['Eksik kaynaklar var; merkezden mutabakat isteyin.']:[];jsonOk(['ay'=>$ay,'oran'=>$oran,'list'=>$out,'eksikler'=>$eksikler,'onizleme'=>true,'kapanislar'=>[]]);}
if(isset($_GET['kapanis_id'])){$q=$db->prepare('SELECT * FROM sube_prim_kapanislari WHERE id=? AND ay=?');$q->execute([(int)$_GET['kapanis_id'],$ay]);$v=$q->fetch();if(!$v)jsonError('Kapanış bulunamadı.',404);$v['list']=json_decode($v['veri_json'],true);unset($v['veri_json']);jsonOk($v);}
$hs=$db->prepare('SELECT id,surum,oran,toplam_prim,gerekce,created_at,created_by FROM sube_prim_kapanislari WHERE ay=? ORDER BY surum DESC');$hs->execute([$ay]);
jsonOk(['ay'=>$ay,'oran'=>$oran,'list'=>$out,'eksikler'=>$eksikler,'onizleme'=>true,'kapanislar'=>$hs->fetchAll()]);
