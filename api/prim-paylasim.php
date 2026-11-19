<?php
declare(strict_types=1);
function primGiderPaylas(array $expenses,array $sourceIncome,array $crossIncome):array {
 $out=['giden'=>[],'gelen'=>[],'detay'=>[]];
 foreach($expenses as $source=>$expense){
  $den=(float)($sourceIncome[$source]??0);if($den<=0)continue;
  $shares=[];foreach($crossIncome as $target=>$sources){$income=(float)($sources[$source]??0);if($income>0&&$target!==$source)$shares[$target]=$income;}
  ksort($shares);$sum=array_sum($shares);if($sum>$den+0.01)throw new InvalidArgumentException('Şubeler arası gelir kaynak toplamını aşıyor.');
  $cents=(int)round($expense*100);$transfer=(int)round($cents*min(1,$sum/$den));$allocated=0;$parts=[];
  foreach($shares as $target=>$income){$raw=$cents*$income/$den;$base=(int)floor($raw);$parts[$target]=['cents'=>$base,'fraction'=>$raw-$base,'income'=>$income];$allocated+=$base;}
  uasort($parts,fn($a,$b)=>$b['fraction']<=>$a['fraction']);$remaining=$transfer-$allocated;
  foreach($parts as $target=>$part){$amount=($part['cents']+($remaining-->0?1:0))/100;$out['giden'][$source]=($out['giden'][$source]??0)+$amount;$out['gelen'][$target]=($out['gelen'][$target]??0)+$amount;
   $out['detay'][$target][]=['kaynak_sube'=>$source,'kullanilan_gelir'=>round($part['income'],2),'kaynak_gelir'=>round($den,2),'kaynak_gider'=>round($expense,2),'oran'=>round($part['income']/$den*100,4),'paylasilan_gider'=>$amount];
  }
 }
 return $out;
}
function primHaricKategori(string $category):bool{return in_array($category,['makine_alim','makine_taksit','demirbas','merkez_gideri'],true);}
function primAyHesapla(PDO $db,string $ay,float $oran,?string $kisit):array {
require_once __DIR__.'/analiz-maliyet.php'; $bas=$ay.'-01'; $bit=date('Y-m-t',strtotime($bas));
$subeler=array_column($db->query("SELECT ad FROM subeler WHERE aktif=1 ORDER BY ad")->fetchAll(),'ad');
$mSube=[]; foreach(['manliftler'=>'manlift','araclar'=>'arac'] as $tb=>$tur){$st=$db->query("SELECT id,sube FROM $tb"); foreach($st->fetchAll() as $m)$mSube[$tur.':'.$m['id']]=trim((string)$m['sube']);}
$k=$db->prepare("SELECT * FROM kiralamalar WHERE durum<>'iptal' AND baslangic<=?");
$k->execute([$bit]); $refs=null; $gelir=[]; $kaynakPay=[]; $kaynakGelir=[]; $eksikler=[]; $dkGider=[]; $gelirKaynak=[]; $giderKaynak=[];$haricGider=[];$dkVar=false;
foreach($k->fetchAll() as $r){
    $tutar=kiralamaTutarPencereli($r,$bas,$bit);
    if($tutar===null)continue;
    $hedef=trim((string)$r['sube']);
    $kaynak=trim((string)($mSube[($r['makine_tur']??'').':'.(int)$r['makine_id']]??''));
    if($hedef===''||$kaynak===''){ $eksikler[]='Kiralama #'.$r['id'].': şube bağlantısı eksik'; continue; }
    $dvz=null;
    if(!in_array(($r['doviz']??'TL')?:'TL',['TL','TRY'],true)){
        $refs??=(function()use($db){require_once __DIR__.'/filo-referans-lib.php';return frVeri($db);})();
        $dvz=primDovizCevir($db,$r,$bas,$bit,$refs);
        if($dvz['eksik']!==null){$eksikler[]=$dvz['eksik'];continue;}
        $tutar=$dvz['tl'];
    }
    if($kisit===null||$hedef===$kisit){
        $ds=$db->prepare('SELECT 1 FROM kiralama_dis_maliyetleri WHERE kiralama_id=?');$ds->execute([$r['id']]);
        $mt=$r['makine_tur']==='arac'?'araclar':'manliftler';$dmq=$db->prepare("SELECT temin_sekli FROM $mt WHERE id=?");$dmq->execute([$r['makine_id']]);
        $dis=(bool)$ds->fetchColumn()||$dmq->fetchColumn()==='dis';$maliyet=0;
        if($dis){$dkVar=true;$dm=analizDkMaliyet($db,$r,$bas,$bit);$maliyet=$dm['tutar'];if($dm['eksik'])$eksikler[]=$dm['eksik'];else $dkGider[$hedef]=($dkGider[$hedef]??0)+$dm['tutar'];}
        $gelirKaynak[$hedef][]=['tur'=>$dis?'dis_kiralama':'kiralama','id'=>(int)$r['id'],'kod'=>$r['kod']?:'#'.$r['id'],'kaynak_sube'=>$kaynak,'aciklama'=>$r['ekipman_adi'],'doviz'=>$dvz['para']??null,'doviz_tutar'=>$dvz['doviz_tutar']??null,'kur_parcalari'=>$dvz['parcalar']??null,'gelir'=>round($tutar,2),'maliyet'=>$maliyet===null?null:round($maliyet,2)];
    }
    $kaynakGelir[$kaynak]=($kaynakGelir[$kaynak]??0)+$tutar;
    if($kaynak===$hedef)$gelir[$hedef]=($gelir[$hedef]??0)+$tutar;
    else $kaynakPay[$hedef][$kaynak]=($kaynakPay[$hedef][$kaynak]??0)+$tutar;
}
$g=$db->prepare("SELECT id,tarih,kategori,aciklama,tutar,makine_tur,makine_id FROM giderler WHERE tarih>=? AND tarih<=?");$g->execute([$bas,$bit]);$gider=[];foreach($g->fetchAll() as $r){if(primHaricKategori((string)$r['kategori']))continue;$sb=trim((string)($mSube[($r['makine_tur']??'').':'.(int)$r['makine_id']]??''));if($sb!==''){$gider[$sb]=($gider[$sb]??0)+(float)$r['tutar'];$giderKaynak[$sb][]=['tur'=>'makine','id'=>(int)$r['id'],'kategori'=>$r['kategori'],'aciklama'=>$r['makine_tur'].' #'.$r['makine_id'].' · '.$r['aciklama'],'tutar'=>(float)$r['tutar']];}else $eksikler[]='Şubesi belirlenemeyen makine gideri var';}
$sg=$db->prepare('SELECT * FROM analiz_sube_giderleri WHERE ay=? AND iptal_at IS NULL');$sg->execute([$ay]);foreach($sg->fetchAll() as $x){if(primHaricKategori($x['kategori'])){$haricGider[$x['sube']]=($haricGider[$x['sube']]??0)+(float)$x['tutar'];continue;}$gider[$x['sube']]=($gider[$x['sube']]??0)+(float)$x['tutar'];$giderKaynak[$x['sube']][]=['tur'=>'genel','id'=>(int)$x['id'],'kategori'=>$x['kategori'],'aciklama'=>$x['aciklama'],'tutar'=>(float)$x['tutar']];}
$n=$db->prepare("SELECT id,aciklama,sube,yansitilan,odenen FROM nakliyeler WHERE tarih>=? AND tarih<=?");$n->execute([$bas,$bit]);foreach($n->fetchAll() as $r){$sb=trim((string)$r['sube']);if($sb==='')continue;$gelir[$sb]=($gelir[$sb]??0)+(float)$r['yansitilan'];$gider[$sb]=($gider[$sb]??0)+(float)$r['odenen'];$gelirKaynak[$sb][]=['tur'=>'nakliye','id'=>(int)$r['id'],'kod'=>'Nakliye #'.$r['id'],'kaynak_sube'=>$sb,'aciklama'=>$r['aciklama'],'gelir'=>(float)$r['yansitilan'],'maliyet'=>(float)$r['odenen']];$giderKaynak[$sb][]=['tur'=>'nakliye','id'=>(int)$r['id'],'kategori'=>'nakliye','aciklama'=>$r['aciklama'],'tutar'=>(float)$r['odenen']];}
$allocation=primGiderPaylas($gider,$kaynakGelir,$kaynakPay);
if($dkVar)$eksikler[]='DK prim matrahı (ciro / tedarikçi maliyeti sonrası katkı) site sahibinin kararını bekliyor; mevcut yöntemle ön hesap gösterilir.';
$out=[];foreach($subeler as $sb){
 if($kisit!==null&&$kisit!==$sb)continue;
 $kendi=$gelir[$sb]??0;$payDet=$allocation['detay'][$sb]??[];$payG=$allocation['gelen'][$sb]??0;$giden=$allocation['giden'][$sb]??0;
 $brutGider=$gider[$sb]??0;$kalanGider=$brutGider-$giden;
 $topGelir=$kendi+array_sum($kaynakPay[$sb]??[]);$topGider=$kalanGider+$payG+($dkGider[$sb]??0);$net=$topGelir-$topGider;
 $out[]=['sube'=>$sb,'kendi_gelir'=>round($kendi,2),'diger_sube_makine_geliri'=>round($topGelir-$kendi,2),'toplam_gelir'=>round($topGelir,2),
 'makine_sahibi_cirosu'=>round($kaynakGelir[$sb]??0,2),'kayitli_sube_gideri'=>round($brutGider,2),'diger_subelere_aktarilan_gider'=>round($giden,2),'prim_haric_gider'=>round($haricGider[$sb]??0,2),
 'kendi_gider'=>round($kalanGider,2),'diger_sube_gider_payi'=>round($payG,2),'dk_maliyeti'=>round($dkGider[$sb]??0,2),'toplam_gider'=>round($topGider,2),'net'=>round($net,2),'prim_orani'=>$oran,'prim'=>round(max(0,$net)*$oran,2),'pay_detay'=>$payDet,'gelir_kaynaklari'=>$gelirKaynak[$sb]??[],'gider_kaynaklari'=>$giderKaynak[$sb]??[]];
}
return ['list'=>$out,'eksikler'=>$eksikler];
}

function primDovizCevir(PDO $db,array $k,string $bas,string $bit,array $refs):array {
 $para=strtoupper(trim((string)$k['doviz']));$ad='Kiralama '.(($k['kod']??'')?:'#'.$k['id']).' (#'.$k['id'].', '.$para.')';
 $ayAd=substr($bas,0,7);
 if(!in_array($para,['EUR','USD'],true))return ['eksik'=>$ad.': '.$para.' için kur kaynağı yok.'];
 $kes=kiralamaDonemKesisimi($k,$bas,$bit);if(!$kes)return ['eksik'=>null,'para'=>$para,'parcalar'=>[],'doviz_tutar'=>0.0,'tl'=>0.0];
 $s=$db->prepare("SELECT id,donem_bas,donem_bit,fatura_tarihi,durum,fatura_no,COALESCE(NULLIF(kod,''),'#'||id) kod FROM hakedisler WHERE kiralama_id=? AND COALESCE(durum,'')<>'iptal' AND COALESCE(donem_bas,'')<>'' AND donem_bas<=? AND COALESCE(NULLIF(donem_bit,''),donem_bas)>=? ORDER BY donem_bas,id");
 $s->execute([$k['id'],$kes['dBit'],$kes['dBas']]);$hk=$s->fetchAll(PDO::FETCH_ASSOC);
 $dilimler=[];$imlec=$kes['dBas'];$sonraki=fn($d)=>date('Y-m-d',strtotime($d.' +1 day'));
 foreach($hk as $h){
  $pb=max($imlec,substr($h['donem_bas'],0,10));$pe=min($kes['dBit'],substr((string)($h['donem_bit']?:$h['donem_bas']),0,10));
  if($pb>$pe)continue;
  if($pb>$imlec)return ['eksik'=>$ad.': '.$imlec.'–'.date('Y-m-d',strtotime($pb.' -1 day')).' günleri hiçbir hakedişte yok; bu günlerin fatura günü (kuru) belirlenemiyor.'];
  $faturali=in_array($h['durum']??'',['faturalandi','tahsil_edildi'],true)||trim((string)($h['fatura_no']??''))!=='';
  $ft=$faturali?substr(trim((string)$h['fatura_tarihi']),0,10):'';
  if($ft==='')return ['eksik'=>$ad.': hakediş '.$h['kod'].' ('.$pb.'–'.$pe.') henüz faturalanmadı; TL karşılığı fatura günü TCMB satış kuruyla hesaplanır, önce fatura tarihini girin.'];
  $dilimler[]=[$pb,$pe,$ft,'Hakediş '.$h['kod'].' fatura günü'];$imlec=$sonraki($pe);
 }
 if(!$dilimler){
  $ft=substr(trim((string)($k['fatura_tarihi']??'')),0,10);
  if($ft===''||$ft<$bas||$ft>$bit)return ['eksik'=>$ad.': '.$ayAd.' için hakediş/fatura günü yok; TL karşılığı fatura günü TCMB satış kuruyla hesaplanır, önce bu ayın faturasını (hakediş fatura tarihini) girin.'];
  $dilimler[]=[$kes['dBas'],$kes['dBit'],$ft,'Kiralama fatura günü'];$imlec=$sonraki($kes['dBit']);
 }
 if($imlec<=$kes['dBit'])return ['eksik'=>$ad.': '.$imlec.'–'.$kes['dBit'].' günleri hiçbir hakedişte yok; bu günlerin fatura günü (kuru) belirlenemiyor.'];
 $parcalar=[];$dvzTop=0.0;$tlTop=0.0;
 foreach($dilimler as [$pb,$pe,$ft,$kaynak]){
  $kur=frKur($refs,$ft,$para);
  if($kur===null||$kur<=0)return ['eksik'=>$ad.': fatura günü '.$ft.' için '.$para.' TCMB satış kuru kayıtlı değil (Filo Referans → Tarihli döviz kurları).'];
  $dv=(float)kiralamaTutarPencereli($k,$pb,$pe);$tl=round($dv*$kur,2);
  $parcalar[]=['bas'=>$pb,'bit'=>$pe,'doviz_tutar'=>round($dv,2),'kur'=>$kur,'kur_tarihi'=>$ft,'kaynak'=>$kaynak,'tl'=>$tl];$dvzTop+=$dv;$tlTop+=$tl;
 }
 return ['eksik'=>null,'para'=>$para,'parcalar'=>$parcalar,'doviz_tutar'=>round($dvzTop,2),'tl'=>round($tlTop,2)];
}
function primOraniAyar(string $deger):float {
 $v=str_replace(',','.',trim($deger));
 return (is_numeric($v)&&(float)$v>=0&&(float)$v<=100)?round((float)$v/100,6):0.025;
}
