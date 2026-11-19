<?php
declare(strict_types=1);
require_once __DIR__.'/analiz-maliyet.php';
require_once __DIR__.'/analiz-kaynak-kurallari.php';

function ciroFaaliyetleri():array{return [
 'arac_ustu_platform'=>'Araç üstü platform','arac_ustu_vinc'=>'Araç üstü vinç','makasli_platform'=>'Makaslı platform',
 'eklemli_platform'=>'Eklemli platform','arazi_makasli'=>'Arazi tipi makaslı platform','akulu_forklift'=>'Akülü forklift',
 'dizel_forklift'=>'Dizel forklift','paletli_makasli'=>'Paletli makaslı platform','dis_vinc'=>'Dışarıdan kiralanan vinç',
 'dis_sepet'=>'Dışarıdan kiralanan sepet','dis_makas'=>'Dışarıdan kiralanan makas','dis_kayarkasa'=>'Dışarıdan kiralanan kayarkasa',
 'avk_kayarkasa'=>'AVK kayarkasa','makas_satis'=>'Makas satışı','vinc_satis'=>'Vinç satışı','makas_servis'=>'Makas servis',
 'servis_diger'=>'Diğer servis gelirleri','nakliye'=>'Nakliye','dis_diger'=>'Diğer dış kiralamalar','ekipman_satis'=>'Diğer ekipman satışı','belirsiz'=>'Sınıflandırılmamış'];}

function ciroKategori(string $text,bool $dis):string{
 $s=mb_strtolower($text,'UTF-8');$vinc=str_contains($s,'vinç')||str_contains($s,'vinc');
 if($dis){if($vinc)return 'dis_vinc';if(str_contains($s,'sepet'))return 'dis_sepet';if(str_contains($s,'makas'))return 'dis_makas';if(str_contains($s,'kayarkasa'))return 'dis_kayarkasa';return 'dis_diger';}
 if(str_contains($s,'kayarkasa'))return 'avk_kayarkasa';
 if(str_contains($s,'araç üstü')||str_contains($s,'arac ustu'))return $vinc?'arac_ustu_vinc':'arac_ustu_platform';
 if(str_contains($s,'eklemli'))return 'eklemli_platform';
 if(str_contains($s,'arazi')&&str_contains($s,'makas'))return 'arazi_makasli';
 if(str_contains($s,'paletli')&&str_contains($s,'makas'))return 'paletli_makasli';
 if(str_contains($s,'forklift')&&(str_contains($s,'akülü')||str_contains($s,'akulu')))return 'akulu_forklift';
 if(str_contains($s,'dizel')&&str_contains($s,'forklift'))return 'dizel_forklift';
 if(str_contains($s,'makas'))return 'makasli_platform';return 'belirsiz';
}

function ciroRapor(PDO $db,string $ay,?string $sube=null):array{
 if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$ay))throw new DomainException('Geçerli ay seçin.');
 $bas=$ay.'-01';$bit=date('Y-m-t',strtotime($bas));$out=[];$warnings=[];
 foreach(ciroFaaliyetleri() as $key=>$label){$katki=str_starts_with($key,'dis_')||in_array($key,['nakliye','avk_kayarkasa'],true)?'kar':'gelir';$out[$key]=['kod'=>$key,'faaliyet'=>$label,'ciro'=>0.0,'dogrudan_maliyet'=>0.0,'kar'=>null,'katki'=>null,'katki_tipi'=>$katki,'kayit_sayisi'=>0,'eksikler'=>[],'kaynaklar'=>[]];}
 $add=function(string $key,array $source,float $income,?float $cost=0,string $issue='')use(&$out){$row=&$out[$key];$row['ciro']+=$income;$row['dogrudan_maliyet']+=$cost??0;$row['kayit_sayisi']++;$source['gelir']=$income;$source['maliyet']=$cost;$source['eksik']=$issue;$row['kaynaklar'][]=$source;if($issue!=='')$row['eksikler'][]=$issue;};
 $st=$db->prepare("SELECT k.*,COALESCE(ml.model,ar.model,'') AS model,CASE WHEN EXISTS(SELECT 1 FROM kiralama_dis_maliyetleri z WHERE z.kiralama_id=k.id) THEN 'dis' ELSE COALESCE(ml.temin_sekli,ar.temin_sekli,'stok') END AS temin FROM kiralamalar k LEFT JOIN manliftler ml ON k.makine_tur='manlift' AND ml.id=k.makine_id LEFT JOIN araclar ar ON k.makine_tur='arac' AND ar.id=k.makine_id WHERE k.durum<>'iptal' AND k.baslangic<=?");$st->execute([$bit]);
 foreach($st->fetchAll() as $r){
  if($sube!==null&&trim((string)$r['sube'])!==$sube)continue;
  $income=kiralamaTutarPencereli($r,$bas,$bit);if($income===null)continue;
  $key=ciroKategori($r['model'].' '.$r['ekipman_adi'],$r['temin']==='dis');
  if(($r['doviz']??'TL')!=='TL'){$out[$key]['eksikler'][]='Kiralama #'.$r['id'].': gelir dönüşüm kuru eksik; TL toplamına katılmadı.';continue;}
  $cost=0;$issue='';if($r['temin']==='dis'){$dm=analizDkMaliyet($db,$r,$bas,$bit);$cost=$dm['eksik']?null:$dm['tutar'];$issue=$dm['eksik']??'';}
  if($key==='avk_kayarkasa'){$v=akTutar($db,'kayarkasa',(int)$r['id'],$ay);$cost=$v?(float)$v['maliyet']:null;$issue=$v?'':'Kayarkasa #'.$r['id'].': işe ait doğrudan gider eşleşmesi eksik.';}
  if($key==='belirsiz')$issue='Kiralama #'.$r['id'].': faaliyet türü sınıflandırılamadı.';
  $add($key,['tur'=>'kiralama','id'=>(int)$r['id'],'kod'=>$r['kod']?:'#'.$r['id'],'aciklama'=>$r['ekipman_adi'],'sube'=>$r['sube'],'tarih'=>$r['baslangic']],$income,$cost,$issue);
 }
 $st=$db->prepare("SELECT s.*,COALESCE(m.sube,a.sube) AS sube,COALESCE(m.model,a.model,'') AS model FROM ekipman_satis s LEFT JOIN manliftler m ON s.makine_tur='manlift' AND m.id=s.makine_id LEFT JOIN araclar a ON s.makine_tur='arac' AND a.id=s.makine_id WHERE s.tarih>=? AND s.tarih<=?");$st->execute([$bas,$bit]);
 foreach($st->fetchAll() as $r){$v=akTutar($db,'satis',(int)$r['id'],$ay);if($v)$r['sube']=$v['sube'];if($sube!==null&&$r['sube']!==$sube)continue;$model=mb_strtolower($r['model'],'UTF-8');$key=str_contains($model,'makas')?'makas_satis':((str_contains($model,'vinç')||str_contains($model,'vinc'))?'vinc_satis':'ekipman_satis');$add($key,['tur'=>'satis','id'=>(int)$r['id'],'kod'=>'Satış #'.$r['id'],'aciklama'=>$r['model'],'sube'=>$r['sube'],'tarih'=>$r['tarih']],(float)$r['satis_tutari'],$v?(float)$v['maliyet']:null,$v?'':'Satış #'.$r['id'].': edinim/defter maliyeti eşleşmedi; satış kârı hesaplanmadı.');}
 if($db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='teknik_servis'")->fetchColumn()){
  $sql="SELECT s.*,COALESCE(k.sube,m.sube,a.sube,mu.sube,'') AS sube,COALESCE(m.model,a.model,s.makine_ad,'') AS model FROM teknik_servis s LEFT JOIN kiralamalar k ON k.id=s.kiralama_id LEFT JOIN manliftler m ON s.makine_tur='manlift' AND m.id=s.makine_id LEFT JOIN araclar a ON s.makine_tur='arac' AND a.id=s.makine_id LEFT JOIN musteriler mu ON mu.id=COALESCE(s.musteri_id,k.musteri_id) WHERE s.durum='tamamlandi' AND (s.musteriye_yansit=1 OR s.fiyat_durum IN ('fiyatlandi','ucretsiz'))";
  foreach($db->query($sql)->fetchAll() as $r){
   $v=akTutar($db,'servis',(int)$r['id'],$ay);$date=$v?$v['tarih']:($r['tamamlanma']?:$r['tarih']);$branch=$v?$v['sube']:$r['sube'];
   if(substr((string)$date,0,7)!==$ay||($sube!==null&&$branch!==$sube))continue;
   $key=str_contains(mb_strtolower($r['model'],'UTF-8'),'makas')?'makas_servis':'servis_diger';
   $fiyatli=!$v&&in_array($r['fiyat_durum']??'',['fiyatlandi','ucretsiz'],true);
   $add($key,['tur'=>'servis','id'=>(int)$r['id'],'kod'=>'Servis #'.$r['id'],'aciklama'=>$r['makine_ad'],'sube'=>$branch,'tarih'=>$date],$v?(float)$v['gelir']:($fiyatli&&$r['fiyat_durum']==='fiyatlandi'?(float)$r['musteri_tutar']:0),$v?(float)$v['maliyet']:($fiyatli?(float)$r['maliyet']:null),$v||$fiyatli?'':'Servis #'.$r['id'].': gerçek satış bedeli eksik; maliyet gelir kabul edilmedi.');
   if(!$v&&!$fiyatli)$out[$key]['kaynaklar'][array_key_last($out[$key]['kaynaklar'])]['gelir']=null;
  }
 }else{$out['makas_servis']['eksikler'][]='Servis satış gelirini doğrulayan kaynak henüz bağlı değil.';$out['makas_servis']['ciro']=null;$out['makas_servis']['dogrudan_maliyet']=null;}
 $st=$db->prepare('SELECT id,sube,tarih,aciklama,yansitilan,odenen FROM nakliyeler WHERE tarih>=? AND tarih<=?');$st->execute([$bas,$bit]);
 foreach($st->fetchAll() as $r){if($sube!==null&&trim((string)$r['sube'])!==$sube)continue;$add('nakliye',['tur'=>'nakliye','id'=>(int)$r['id'],'kod'=>'Nakliye #'.$r['id'],'aciklama'=>$r['aciklama'],'sube'=>$r['sube'],'tarih'=>$r['tarih']],(float)$r['yansitilan'],(float)$r['odenen']);}
 foreach($out as &$row){
  $row['eksikler']=array_values(array_unique($row['eksikler']));
  foreach(['ciro','dogrudan_maliyet'] as $k)if($row[$k]!==null)$row[$k]=round($row[$k],2);
  $row['bilinen_ciro']=$row['ciro'];$row['bilinen_maliyet']=$row['dogrudan_maliyet'];
  if(array_filter($row['eksikler'],fn($x)=>str_contains($x,'dönüşüm kuru')||str_contains($x,'gerçek satış bedeli eksik'))) $row['ciro']=null;
  if(array_filter($row['kaynaklar'],fn($x)=>$x['maliyet']===null))$row['dogrudan_maliyet']=null;
  $row['kar']=$row['eksikler']?null:round($row['ciro']-$row['dogrudan_maliyet'],2);
  $row['katki']=$row['katki_tipi']==='kar'?$row['kar']:$row['ciro'];
  if(($row['kod']==='belirsiz'&&$row['kayit_sayisi']>0)||array_filter($row['eksikler'],fn($x)=>str_contains($x,'dönüşüm kuru'))) $row['katki']=null;
  $warnings=array_merge($warnings,$row['eksikler']);
 }unset($row);
 return ['ay'=>$ay,'sube'=>$sube,'list'=>array_values($out),'eksikler'=>array_values(array_unique($warnings)),'kesin'=>!$warnings,'toplam'=>['ciro'=>round(array_sum(array_column($out,'bilinen_ciro')),2),'maliyet'=>round(array_sum(array_column($out,'bilinen_maliyet')),2),'kar'=>null,'katki'=>in_array(null,array_column($out,'katki'),true)?null:round(array_sum(array_column($out,'katki')),2),'bilinen_katki'=>round(array_sum(array_column($out,'katki')),2)]];
}

function ciroRaporYil(PDO $db,string $yil,?string $sube=null):array{
 if(!preg_match('/^\d{4}$/',$yil))throw new DomainException('Geçerli yıl seçin.');
 $aylar=[];for($i=1;$i<=12;$i++)$aylar[]=ciroRapor($db,$yil.'-'.sprintf('%02d',$i),$sube);
 return ['yil'=>$yil,'sube'=>$sube,'aylar'=>$aylar];
}
