<?php
declare(strict_types=1);
function adSema(PDO $db): void {
 $db->exec("CREATE TABLE IF NOT EXISTS aylik_durum(ay TEXT PRIMARY KEY,surum INTEGER NOT NULL,veri TEXT NOT NULL,kullanici TEXT,zaman TEXT);
 CREATE TABLE IF NOT EXISTS aylik_durum_gecmis(id INTEGER PRIMARY KEY AUTOINCREMENT,ay TEXT NOT NULL,surum INTEGER NOT NULL,veri TEXT NOT NULL,gerekce TEXT NOT NULL,kullanici TEXT,zaman TEXT,UNIQUE(ay,surum));");
}
function adKategoriler(): array {return [
 'arac_vinc'=>['Araç üstü vinç','gelir'],'platform'=>['Platform','gelir'],
 'dis_vinc_sepet'=>['Dışarıdan kiralanan vinç / sepet','kar'],'dis_manlift'=>['Dışarıdan kiralanan manlift','kar'],
 'makas_servis'=>['Makas servis','gelir'],'nakliye'=>['Nakliye','kar'],
 'makine_satis'=>['Makas / platform satışı','gelir'],'forklift'=>['Forklift kiralama','gelir'],
 'kayarkasa'=>['Kayarkasa nakil','gelir'],'manlift'=>['Makine kiralama','gelir']];}
function adAlanlar(): array {return [
 'nakit_tl'=>'Bakiye günündeki nakit (TL)','cek_tl'=>'Bakiye günündeki çek toplamı (TL)',
 'usd_kur'=>'Bakiye günü USD/TL kuru','eur_kur'=>'Borç değerleme EUR/TL kuru',
 'gelir'=>'Kasa defteri gelir (KDV dahil, TL)','gider'=>'Kasa defteri ödenen gider (TL)',
 'nakde_cek'=>'Ay içinde nakde çevrilen çek (TL)','depo_cek'=>'Ay içinde alınan, ay sonunda vadesi gelmemiş depodaki çek (TL)',
 'fatura'=>'Ay içinde kesilen toplam fatura (KDV dahil, TL)',
 'alacak'=>'Ay sonu normal alacaklar (TL)','avukat'=>'Ay sonu avukatlık alacaklar (TL)','batik'=>'Ay sonu batık alacaklar (TL)',
 'mal_odeme'=>'Aylık gider içindeki demirbaş / makine ödemesi (TL)'];}
function adSayi($v,string $ad,bool $neg=false): ?float {
 if($v===null||$v==='')return null;
 if(!is_numeric($v)||!is_finite((float)$v)||abs((float)$v)>1e12||(!$neg&&(float)$v<0))throw new DomainException($ad.': geçerli tutar girin.');
 return round((float)$v,6);
}
function adTarih($v): string {if(!is_string($v)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$v))throw new DomainException('Bakiye tarihi geçersiz.');$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$d||$d->format('Y-m-d')!==$v)throw new DomainException('Bakiye tarihi geçersiz.');return $v;}
function adVeri(array $b,string $ay): array {
 $v=['tarih'=>adTarih($b['tarih']??''),'kapsam'=>$b['kapsam']??'','kaynak'=>trim((string)($b['kaynak']??''))];
 if(substr($v['tarih'],0,7)!==$ay)throw new DomainException('Bakiye tarihi seçilen ayda olmalı.');
 if(!in_array($v['kapsam'],['kasa','kasa_banka'],true))throw new DomainException('Nakit kapsamını seçin.');
 if($v['kaynak']===''||mb_strlen($v['kaynak'])>3000)throw new DomainException('Kaynak / mutabakat açıklamasını girin (en fazla 3000 karakter).');
 foreach(adAlanlar() as $k=>$ad)$v[$k]=adSayi($b[$k]??null,$ad,$k==='nakit_tl');
 foreach(['usd_kur','eur_kur'] as $k)if($v[$k]!==null&&$v[$k]<=0)throw new DomainException('Kur sıfırdan büyük olmalı.');
 if($v['gider']!==null&&$v['mal_odeme']!==null&&$v['mal_odeme']>$v['gider'])throw new DomainException('Demirbaş ödemesi toplam giderden büyük olamaz.');
 if($v['gelir']!==null&&$v['nakde_cek']!==null&&$v['nakde_cek']>$v['gelir'])throw new DomainException('Nakde çevrilen çek kasa gelirinin içinde olmalı; gelirden büyük olamaz.');
 $v['ciro']=[];
 foreach(adKategoriler() as $k=>[$ad,$tip]){
  $r=$b['ciro'][$k]??[];if(!is_array($r))throw new DomainException('Ciro satırı geçersiz.');
  $v['ciro'][$k]=['gelir'=>adSayi($r['gelir']??null,$ad), 'maliyet'=>$tip==='kar'?adSayi($r['maliyet']??null,$ad.' maliyet'):0];
 }
 $borclar=$b['borclar']??[];if(!is_array($borclar)||count($borclar)>100)throw new DomainException('En fazla 100 borç satırı girin.');
 $v['borclar']=[];$ids=[];
 foreach($borclar as $r){
  if(!is_array($r))throw new DomainException('Borç satırı geçersiz.');
  $ad=trim((string)($r['ad']??''));$pb=$r['para']??'';
  if($ad===''||mb_strlen($ad)>150||isset($ids[$ad])||!in_array($pb,['TRY','USD','EUR'],true))throw new DomainException('Borç için benzersiz sözleşme/makine adı ve para birimi girin.');$ids[$ad]=true;
  $d=['ad'=>$ad,'para'=>$pb];foreach(['devir','yeni','odeme'] as $k)$d[$k]=adSayi($r[$k]??null,$ad.' '.$k);
  if(in_array(null,[$d['devir'],$d['yeni'],$d['odeme']],true))throw new DomainException('Borç satırında devir, yeni borç ve ödemeyi doldurun.');
  if($d['odeme']>$d['devir']+$d['yeni'])throw new DomainException('Borç ödemesi mevcut borcu aşamaz.');
  $v['borclar'][]=$d;
 }
 $v['borc_teyit']=($b['borc_teyit']??false)===true;
 $v['durum']=($b['durum']??'taslak')==='kesin'?'kesin':'taslak';
 if($v['durum']==='kesin'){
  foreach(adAlanlar() as $k=>$ad)if($k!=='eur_kur'&&$v[$k]===null)throw new DomainException($ad.' eksik. İşlem yoksa 0 girin.');
  foreach($v['ciro'] as $r)if($r['gelir']===null||$r['maliyet']===null)throw new DomainException('Ciro kalemlerini tamamlayın; iş olmayan kaleme 0 girin.');
  if(!$v['borc_teyit'])throw new DomainException('Borç listesinin tam olduğunu doğrulayın.');
  foreach($v['borclar'] as $r)if($r['para']==='EUR'&&$v['eur_kur']===null)throw new DomainException('EUR borcu için EUR/TL kuru gerekli.');
 }
 return $v;
}
function adHesap(array $v): array {
 $sum=fn($a,$b)=>$a===null||$b===null?null:round($a+$b,2);
 $div=fn($a,$b)=>$a===null||!$b?null:round($a/$b,2);
 $out=['genel_tl'=>$sum($v['nakit_tl'],$v['cek_tl'])];
 $out['genel_usd']=$div($out['genel_tl'],$v['usd_kur']);$out['nakit_usd']=$div($v['nakit_tl'],$v['usd_kur']);
 $out['kar_zarar']=$v['gelir']===null||$v['gider']===null?null:round($v['gelir']-$v['gider'],2);
 $out['mal_haric_kar']=$sum($out['kar_zarar'],$v['mal_odeme']);
 $out['ciro']=0;$out['ciro_kalemleri']=[];
 foreach(adKategoriler() as $k=>[$ad,$tip]){$r=$v['ciro'][$k];$n=$r['gelir']===null||$r['maliyet']===null?null:round($r['gelir']-$r['maliyet'],2);$out['ciro_kalemleri'][$k]=$n;$out['ciro']=$sum($out['ciro'],$n);}
 $out['borc_para']=['TRY'=>0,'USD'=>0,'EUR'=>0];foreach($v['borclar'] as $r)$out['borc_para'][$r['para']]+=round($r['devir']+$r['yeni']-$r['odeme'],2);
 $out['borc_usd']=null;
 foreach($out['borc_para'] as $pb=>$amount)$out['borc_'.$pb]=$v['borc_teyit']?round($amount,2):null;
 if($v['borc_teyit']&&$v['usd_kur']&&(!$out['borc_para']['EUR']||$v['eur_kur']))$out['borc_usd']=round($out['borc_para']['USD']+($out['borc_para']['TRY']+$out['borc_para']['EUR']*($v['eur_kur']??0))/$v['usd_kur'],2);
 return $out;
}
