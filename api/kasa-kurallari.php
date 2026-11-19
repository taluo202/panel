<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
function kasaSema(PDO $db):void{
 $db->exec("CREATE TABLE IF NOT EXISTS kasa_hareketleri(id INTEGER PRIMARY KEY AUTOINCREMENT,tarih TEXT NOT NULL,hesap TEXT NOT NULL,yon TEXT NOT NULL,kategori TEXT NOT NULL,kurus INTEGER NOT NULL,belge TEXT NOT NULL,aciklama TEXT NOT NULL,surum INTEGER NOT NULL DEFAULT 1,iptal INTEGER NOT NULL DEFAULT 0);
 CREATE TABLE IF NOT EXISTS kasa_gecmis(id INTEGER PRIMARY KEY AUTOINCREMENT,kayit_id INTEGER NOT NULL,eylem TEXT NOT NULL,gerekce TEXT NOT NULL,eski TEXT,yeni TEXT,kullanici TEXT NOT NULL,zaman TEXT NOT NULL);
 CREATE TABLE IF NOT EXISTS kasa_mutabakat(ay TEXT PRIMARY KEY,ozet_hash TEXT NOT NULL,kullanici TEXT NOT NULL,zaman TEXT NOT NULL);");
 kasaBelgeUnique($db);
 $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_kasa_belge_aktif ON kasa_hareketleri(belge) WHERE iptal=0");
}
function kasaBelgeUnique(PDO $db):void{
 $eskiMi=function() use($db):bool{$q=$db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='kasa_hareketleri'");$sql=(string)$q->fetchColumn();return (bool)preg_match('/belge\s+TEXT\s+NOT\s+NULL\s+UNIQUE/i',$sql);};
 if(!$eskiMi()||$db->inTransaction())return;
 $fk=(int)$db->query('PRAGMA foreign_keys')->fetchColumn();
 $db->exec('PRAGMA foreign_keys = OFF');
 try{
  islemIcinde($db,function(PDO $db)use($eskiMi):void{
  if($eskiMi()){
   $seq=$db->query("SELECT seq FROM sqlite_sequence WHERE name='kasa_hareketleri'")->fetchColumn();
   $db->exec('DROP TABLE IF EXISTS kasa_hareketleri_yeni');
   $db->exec("CREATE TABLE kasa_hareketleri_yeni(id INTEGER PRIMARY KEY AUTOINCREMENT,tarih TEXT NOT NULL,hesap TEXT NOT NULL,yon TEXT NOT NULL,kategori TEXT NOT NULL,kurus INTEGER NOT NULL,belge TEXT NOT NULL,aciklama TEXT NOT NULL,surum INTEGER NOT NULL DEFAULT 1,iptal INTEGER NOT NULL DEFAULT 0)");
   $db->exec('INSERT INTO kasa_hareketleri_yeni(id,tarih,hesap,yon,kategori,kurus,belge,aciklama,surum,iptal) SELECT id,tarih,hesap,yon,kategori,kurus,belge,aciklama,surum,iptal FROM kasa_hareketleri');
   $db->exec('DROP TABLE kasa_hareketleri');
   $db->exec('ALTER TABLE kasa_hareketleri_yeni RENAME TO kasa_hareketleri');
   if($seq!==false)$db->prepare("UPDATE sqlite_sequence SET seq=MAX(seq,?) WHERE name='kasa_hareketleri'")->execute([(int)$seq]);
  }
  });
 }
 finally{$db->exec('PRAGMA foreign_keys = '.($fk?'ON':'OFF'));}
}
function kasaBelgeNo(PDO $db,string $tarih):string{
 $on='KD-'.substr($tarih,0,7).'-';
 $q=$db->prepare("SELECT belge FROM kasa_hareketleri WHERE belge LIKE ?");$q->execute([$on.'%']);$max=0;
 foreach($q->fetchAll(PDO::FETCH_COLUMN) as $b){if(preg_match('/^'.preg_quote($on,'/').'(\d{4,})$/',$b,$m))$max=max($max,(int)$m[1]);}
 return $on.str_pad((string)($max+1),4,'0',STR_PAD_LEFT);
}
function kasaTarih($v):string{
 if(!is_string($v)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$v))throw new DomainException('Geçerli tarih girin.');
 $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);
 if(!$d||$d->format('Y-m-d')!==$v||$v>date('Y-m-d'))throw new DomainException('Geçerli, ileri tarihli olmayan işlem günü girin.');return $v;
}
function kasaVeri(array $b):array{
 $r=['tarih'=>kasaTarih($b['tarih']??null)];
 foreach(['hesap'=>['kasa','banka'],'yon'=>['gelir','gider'],'kategori'=>['normal','cek_tahsil','demirbas','transfer']] as $k=>$options){if(!in_array($b[$k]??null,$options,true))throw new DomainException('Geçersiz '.$k.'.');$r[$k]=$b[$k];}
 if(($r['kategori']==='cek_tahsil'&&$r['yon']!=='gelir')||($r['kategori']==='demirbas'&&$r['yon']!=='gider'))throw new DomainException('Çek tahsili gelir, demirbaş ödemesi gider olmalı.');
 $s=(string)($b['tutar']??'');if(!preg_match('/^\d{1,10}(\.\d{1,2})?$/',$s)||(float)$s<=0)throw new DomainException('Pozitif TL tutarı girin; en fazla iki ondalık basamak.');
 $r['kurus']=(int)round((float)$s*100);
 foreach(['belge'=>150,'aciklama'=>1000] as $k=>$max){$v=trim((string)($b[$k]??''));if(mb_strlen($v)>$max||($v===''&&$k!=='belge'))throw new DomainException($k.' gerekli; en fazla '.$max.' karakter.');$r[$k]=$v;}
 return $r;
}
function kasaHash(array $rows):string{return hash('sha256',json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));}
function kasaOzet(array $rows):array{
 $sum=['gelir'=>0,'gider'=>0,'nakde_cek'=>0,'mal_odeme'=>0];
 foreach($rows as $r){if($r['iptal']||$r['kategori']==='transfer')continue;$sum[$r['yon']]+=(int)$r['kurus'];if($r['kategori']==='cek_tahsil')$sum['nakde_cek']+=(int)$r['kurus'];if($r['kategori']==='demirbas')$sum['mal_odeme']+=(int)$r['kurus'];}
 $sum['kar_zarar']=$sum['gelir']-$sum['gider'];$sum['mal_haric_kar']=$sum['kar_zarar']+$sum['mal_odeme'];return array_map(fn($v)=>$v/100,$sum);
}
function kasaAy(PDO $db,string $ay):array{
 if(!preg_match('/^(19|20|21)\d{2}-(0[1-9]|1[0-2])$/',$ay))throw new DomainException('Geçerli ay seçin.');
 $q=$db->prepare('SELECT * FROM kasa_hareketleri WHERE tarih LIKE ? ORDER BY tarih,id');$q->execute([$ay.'-%']);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$hash=kasaHash($rows);
 $q=$db->prepare('SELECT * FROM kasa_mutabakat WHERE ay=?');$q->execute([$ay]);$check=$q->fetch(PDO::FETCH_ASSOC);
 return ['ay'=>$ay,'rows'=>$rows,'ozet'=>kasaOzet($rows),'hash'=>$hash,'dogrulandi'=>$check&&hash_equals($check['ozet_hash'],$hash),'mutabakat'=>$check?:null];
}
function kasaGecmis(PDO $db,int $id,string $action,string $why,?array $old,?array $new,string $actor):void{
 $db->prepare('INSERT INTO kasa_gecmis(kayit_id,eylem,gerekce,eski,yeni,kullanici,zaman) VALUES(?,?,?,?,?,?,?)')->execute([$id,$action,$why,$old===null?null:json_encode($old,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$new===null?null:json_encode($new,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$actor,date('Y-m-d H:i:s')]);
}
