<?php
declare(strict_types=1);
function akKaynakSema(PDO $db):void{
 $db->exec("CREATE TABLE IF NOT EXISTS analiz_kaynak_tutarlari(tur TEXT NOT NULL,kaynak_id INTEGER NOT NULL,ay TEXT NOT NULL,tarih TEXT NOT NULL,sube TEXT NOT NULL,gelir REAL,maliyet REAL NOT NULL,belge TEXT NOT NULL,kaynak_hash TEXT NOT NULL,surum INTEGER NOT NULL DEFAULT 1,PRIMARY KEY(tur,kaynak_id,ay));
 CREATE TABLE IF NOT EXISTS analiz_kaynak_gecmis(id INTEGER PRIMARY KEY AUTOINCREMENT,tur TEXT,kaynak_id INTEGER,ay TEXT,eski TEXT,yeni TEXT,gerekce TEXT,kullanici TEXT,zaman TEXT);");
}
function akKaynak(PDO $db,string $tur,int $id):?array{
 $sql=match($tur){
  'servis'=>"SELECT id,makine_ad AS ad,makine_tur,makine_id,durum,maliyet,musteriye_yansit,tamamlanma,tarih FROM teknik_servis WHERE id=?",
  'satis'=>"SELECT id,makine_tur,makine_id,satis_tutari,tarih FROM ekipman_satis WHERE id=?",
  'kayarkasa'=>"SELECT id,ekipman_adi AS ad,makine_tur,makine_id,sube,baslangic,bitis,gercek_bitis,birim_ucret,kira_tipi,miktar,iskonto_oran,durum FROM kiralamalar WHERE id=?",
  default=>throw new DomainException('Geçersiz kaynak türü.')};
 $q=$db->prepare($sql);$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function akHash(array $r):string{return hash('sha256',json_encode($r,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));}
function akTutar(PDO $db,string $tur,int $id,string $ay):?array{
 if(!$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='analiz_kaynak_tutarlari'")->fetchColumn())return null;
 $q=$db->prepare('SELECT * FROM analiz_kaynak_tutarlari WHERE tur=? AND kaynak_id=? AND ay=?');$q->execute([$tur,$id,$tur==='kayarkasa'?$ay:'']);$v=$q->fetch(PDO::FETCH_ASSOC);if(!$v)return null;
 $source=akKaynak($db,$tur,$id);if(!$source||!hash_equals($v['kaynak_hash'],akHash($source)))return null;return $v;
}

function akKayarkasaMi(PDO $db,array $source):bool{
 if(($source['durum']??'')==='iptal')return false;
 $model='';
 $table=match($source['makine_tur']??''){'arac'=>'araclar','manlift'=>'manliftler',default=>null};
 if($table!==null){
  $q=$db->prepare("SELECT model FROM ".$table." WHERE id=?");
  $q->execute([(int)($source['makine_id']??0)]);$model=(string)($q->fetchColumn()?:'');
 }
 return str_contains(mb_strtolower($model.' '.($source['ad']??''),'UTF-8'),'kayarkasa');
}
