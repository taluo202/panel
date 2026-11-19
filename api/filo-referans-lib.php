<?php
declare(strict_types=1);
function frSema(PDO $db):void {
 $db->exec("CREATE TABLE IF NOT EXISTS filo_siniflar(tur TEXT NOT NULL,makine_id INTEGER NOT NULL,ana_grup TEXT NOT NULL DEFAULT '',alt_grup TEXT NOT NULL DEFAULT '',enerji TEXT NOT NULL DEFAULT '',surum INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(tur,makine_id));
 CREATE TABLE IF NOT EXISTS filo_fiyat_gecmisi(id INTEGER PRIMARY KEY AUTOINCREMENT,tur TEXT NOT NULL,makine_id INTEGER NOT NULL,tarih TEXT NOT NULL,para TEXT NOT NULL,gunluk REAL,haftalik REAL,aylik REAL,kaynak TEXT NOT NULL,kullanici TEXT NOT NULL,kayit_zamani TEXT NOT NULL DEFAULT (datetime('now','localtime')));
 CREATE INDEX IF NOT EXISTS filo_fiyat_arama ON filo_fiyat_gecmisi(tur,makine_id,tarih,id);
 CREATE TABLE IF NOT EXISTS filo_kur_gecmisi(id INTEGER PRIMARY KEY AUTOINCREMENT,tarih TEXT NOT NULL,para TEXT NOT NULL,kur REAL NOT NULL,kaynak TEXT NOT NULL,kullanici TEXT NOT NULL,kayit_zamani TEXT NOT NULL DEFAULT (datetime('now','localtime')));
 CREATE INDEX IF NOT EXISTS filo_kur_arama ON filo_kur_gecmisi(tarih,para,id);");
}
function frTarih($value):string {
 if(!is_string($value))throw new InvalidArgumentException('Geçerli tarih girin.');
 $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
 if(!$d||$d->format('Y-m-d')!==$value)throw new InvalidArgumentException('Geçerli tarih girin.');
 return $value;
}
function frSayi($value,bool $nullable=false):?float {
 if($nullable&&($value===null||$value===''))return null;
 if(!is_numeric($value)||!is_finite((float)$value)||(float)$value<0||(float)$value>1e12)throw new InvalidArgumentException('Tutar sıfır veya pozitif bir sayı olmalı.');
 return round((float)$value,6);
}
function frMetin($value,int $max=120):string {
 if(!is_string($value)||mb_strlen(trim($value))>$max)throw new InvalidArgumentException('Metin alanı geçersiz veya çok uzun.');
 return trim($value);
}
function frVeri(PDO $db):array {
 frSema($db);$out=['sinif'=>[],'fiyat'=>[],'kur'=>[]];
 foreach($db->query('SELECT * FROM filo_siniflar') as $r)$out['sinif'][$r['tur'].':'.$r['makine_id']]=$r;
 foreach($db->query('SELECT * FROM filo_fiyat_gecmisi ORDER BY tarih DESC,id DESC') as $r)$out['fiyat'][$r['tur'].':'.$r['makine_id']][]=$r;
 foreach($db->query('SELECT * FROM filo_kur_gecmisi ORDER BY id DESC') as $r){$k=$r['tarih'].':'.$r['para'];if(!isset($out['kur'][$k]))$out['kur'][$k]=$r;}
 return $out;
}
function frFiyat(array $data,string $key,string $date,string $currency):?array {
 foreach($data['fiyat'][$key]??[] as $r)if($r['tarih']<=$date&&$r['para']===$currency)return $r;
 return null;
}
function frKur(array $data,string $date,string $currency):?float {
 if(in_array($currency,['TL','TRY'],true))return 1.0;
 return isset($data['kur'][$date.':'.$currency])?(float)$data['kur'][$date.':'.$currency]['kur']:null;
}
