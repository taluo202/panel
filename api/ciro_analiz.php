<?php
 declare(strict_types=1);
 require_once __DIR__.'/config.php';
 require_once __DIR__.'/ciro-rapor.php';
 if(session_status()===PHP_SESSION_NONE)session_start();
 requireAuth();requirePermission('yonetim');requirePermission('kiralama_takip','oku');requirePermission('arac','oku');requirePermission('manlift','oku');
 if($_SERVER['REQUEST_METHOD']!=='GET')jsonError('Method Not Allowed',405);
 $db=getDb();$ay=(string)($_GET['ay']??date('Y-m'));$sube=subeKisiti()??(trim((string)($_GET['sube']??''))?:null);
 try{
  if(isset($_GET['yil'])){$r=ciroRaporYil($db,(string)$_GET['yil'],$sube);jsonOk($r);}
  $r=ciroRapor($db,$ay,$sube);$r['subeler']=subeKisiti()!==null?[subeKisiti()]:$db->query('SELECT ad FROM subeler ORDER BY ad')->fetchAll(PDO::FETCH_COLUMN);jsonOk($r);
 }catch(DomainException $e){jsonError($e->getMessage(),422);}
