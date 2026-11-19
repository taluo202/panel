<?php
declare(strict_types=1);
require_once __DIR__.'/analiz-maliyet.php';
require_once __DIR__.'/filo-referans-lib.php';
require_once __DIR__.'/filo-gorsel.php';

function fiGun(?string $from,?string $to):?int {
 foreach([$from,$to] as $s){$d=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$s);if(!$d||$d->format('Y-m-d')!==$s)return null;}
 return (int)(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->format('%r%a');
}

function filoInceleme(PDO $db,array $scope,string $bas,string $bit):array {
 $refs=frVeri($db);$today=date('Y-m-d');$kisit=subeKisitiHam();$gizli=[];$allowed=[];foreach($scope as $m)$allowed[$m['tur'].':'.$m['id']]=$m;
 $machines=[];
 foreach(['arac'=>'araclar','manlift'=>'manliftler'] as $tur=>$table)foreach($db->query("SELECT * FROM $table")->fetchAll(PDO::FETCH_ASSOC) as $m){
  $key=$tur.':'.$m['id'];if(!isset($allowed[$key]))continue;
  $due=$m[$tur==='arac'?'periyodik_kontrol_bitis':'periyodik_bitis']??null;$days=fiGun($today,$due);
  $machines[$key]=['key'=>$key,'id'=>(int)$m['id'],'tur'=>$tur,'ad'=>$m[$tur==='arac'?'plaka':'kod'],'seri'=>$m['seri_no']??null,'kod'=>$m['makine_kodu']??null,'marka'=>$m['marka']??'','model'=>$m['model']??'',
   'imal_yili'=>$m['imal_yili']??null,'metraj'=>$m['metraj']??null,'sube'=>trim((string)($m['sube']??''))?:'Şube belirtilmemiş','temin'=>($m['temin_sekli']??'stok')?:'stok','aktif'=>(int)($m['aktif']??1),'durum'=>$m['durum']??'musait',
   'liste'=>(float)($m['liste_fiyat_aylik']??0),'liste_gunluk'=>(float)($m['liste_fiyat_gunluk']??0),'liste_haftalik'=>(float)($m['liste_fiyat_haftalik']??0),'kategori'=>$m['kategori']??null,'yakit_tipi'=>$m['yakit_tipi']??null,'periyodik'=>$due,'periyodik_gun'=>$days,'periyodik_durum'=>$days===null?'eksik':($days<0?'gecmis':($days<=30?'yaklasan':'gecerli')),'kirada'=>false,'aktif_kira'=>0];
 }
 foreach($machines as $key=>&$machine){$meta=$refs['sinif'][$key]??[];$sc=is_array($allowed[$key])?$allowed[$key]:[];$machine['ana_grup']=(string)($sc['ana_grup']??($meta['ana_grup']??''));$machine['alt_grup']=(string)($sc['alt_grup']??($meta['alt_grup']??''));$machine['enerji']=(string)(array_key_exists('yakit',$sc)?($sc['yakit']??''):($meta['enerji']??''));$machine['ana_grup_kaynak']=$sc['ana_grup_kaynak']??null;}unset($machine);
 $frozen=array_flip($db->query('SELECT kiralama_id FROM kiralama_dis_maliyetleri')->fetchAll(PDO::FETCH_COLUMN));$rentals=[];$current=[];$rentKeys=[];
 foreach($db->query("SELECT k.*,m.unvan AS musteri_unvan,m.odeme_tercihi AS musteri_odeme_tercihi FROM kiralamalar k LEFT JOIN musteriler m ON m.id=k.musteri_id WHERE k.durum<>'iptal' ORDER BY k.baslangic DESC,k.id DESC")->fetchAll(PDO::FETCH_ASSOC) as $r){
  $key=$r['makine_tur'].':'.$r['makine_id'];if(!isset($machines[$key]))continue;$m=$machines[$key];
  $active=$r['durum']==='aktif'&&!$r['gercek_bitis']&&$r['baslangic']<=$today;
  $ix=kiralamaDonemKesisimi($r,$bas,$bit);if(!$active&&!$ix)continue;
  $rentKeys[(int)$r['id']]=true;$dis=isset($frozen[$r['id']])||$m['temin']==='dis';$currency=trim((string)($r['doviz']??''))?:'TL';
  $discount=kiralamaGecerliIskonto($r);$unit=(float)$r['birim_ucret'];$quantity=max(1,(int)($r['miktar']??1));$monthly=$unit*$quantity*30/kiraBirimGun($r['kira_tipi']?:'gunluk');$net=round($monthly*(1-$discount/100),2);
  $income=$ix?kiralamaTutarPencereli($r,$bas,$bit):null;$cost=null;$issue=null;
  if($dis&&$ix){$d=analizDkMaliyet($db,$r,$bas,$bit);$cost=$d['tutar']===null?null:round($d['tutar'],2);$issue=$d['eksik'];}
  $item=['id'=>(int)$r['id'],'makine'=>$key,'musteri_id'=>(int)$r['musteri_id'],'musteri'=>$r['musteri_unvan']?:'Müşteri belirtilmemiş','kod'=>$r['kod']?:'#'.$r['id'],'sube'=>$r['sube']?:'Şube belirtilmemiş','para'=>$currency,'dis'=>$dis,'aktif'=>$active,
   'bas'=>$r['baslangic'],'bit'=>$r['gercek_bitis']?:$r['bitis'],'plan_bit'=>$r['bitis'],'kalan'=>$active?fiGun($today,$r['bitis']):null,'gecen'=>$active?max(0,fiGun($r['baslangic'],$today)??0):null,'il'=>$r['il']??'',
   'tip'=>$r['kira_tipi']?:'gunluk','odeme'=>$r['odeme_turu']??'','vade'=>(int)($r['vade_gun']??0),'ikame'=>!empty($r['ikame_makine_id']),'birim'=>$unit,'net_birim'=>round($unit*(1-$discount/100),2),'aylik'=>$net,'iskonto'=>$discount,
   'liste_tl'=>$m['liste']>0?$m['liste']:null,'liste_gunluk_tl'=>$m['liste_gunluk']>0?$m['liste_gunluk']:null,'liste_haftalik_tl'=>$m['liste_haftalik']>0?$m['liste_haftalik']:null,'liste_eslesen_aylik'=>null,'liste_eslesen_kaynak'=>null,'liste_fark_tl'=>null,'ilce'=>$r['ilce']??'','santiye_adres'=>$r['santiye_adres']??'','musteri_odeme_tercihi'=>$r['musteri_odeme_tercihi']??null,'son_iskonto'=>null,'gun'=>$ix?(int)$ix['gun']:0,'gelir'=>$income,'maliyet_tl'=>$cost,
   'katki_tl'=>$dis&&$currency==='TL'&&$income!==null&&$cost!==null?round($income-$cost,2):null,'maliyet_uyari'=>$issue,'aciklama'=>$r['aciklama']??''];
  $es=kiraListeFiyatAylik(['liste_fiyat_aylik'=>$m['liste'],'liste_fiyat_gunluk'=>$m['liste_gunluk'],'liste_fiyat_haftalik'=>$m['liste_haftalik']],$item['tip']);if($es['aylik']>0){$item['liste_eslesen_aylik']=round($es['aylik']*$quantity,2);$item['liste_eslesen_kaynak']=$es['kaynak'];if($currency==='TL')$item['liste_fark_tl']=round($es['aylik']*$quantity-$net,2);}
  $rate=frKur($refs,$r['baslangic'],$currency);$history=frFiyat($refs,$key,$r['baslangic'],$currency);
  $item['baslangic_kuru']=$rate;$item['donem_gelir_tl']=$income!==null&&$rate!==null?round($income*$rate,2):null;
  $item['tarihsel_liste_aylik']=$history&&$history['aylik']!==null?(float)$history['aylik']:null;
  $item['tarihsel_liste_gunluk']=$history&&$history['gunluk']!==null?(float)$history['gunluk']:null;
  $item['tarihsel_liste_haftalik']=$history&&$history['haftalik']!==null?(float)$history['haftalik']:null;
  $item['tarihsel_liste_tarih']=$history['tarih']??null;$item['tarihsel_liste_kaynak']=$history['kaynak']??null;
  $item['tarihsel_liste_fark']=$item['tarihsel_liste_aylik']!==null?round($item['tarihsel_liste_aylik']*$quantity-$net,2):null;
  $item['kur_kaynak']=$currency==='TL'?'TL':($refs['kur'][$r['baslangic'].':'.$currency]['kaynak']??null);
  if($dis&&$item['donem_gelir_tl']!==null&&$cost!==null)$item['katki_tl']=round($item['donem_gelir_tl']-$cost,2);
  if(fgDetayGizli($r,$kisit)){$gizli[(int)$r['id']]=true;$item=array_replace($item,['musteri_id'=>null,'musteri'=>null,'ilce'=>null,'santiye_adres'=>null,'musteri_odeme_tercihi'=>null,'aciklama'=>null,'detay_gizli'=>true]);}else $item['detay_gizli']=false;
  if($ix)$rentals[]=$item;
  if($active){$current[]=$item;$machines[$key]['kirada']=true;$machines[$key]['aktif_kira']++;}
 }
 $son=fgSonIskontoToplu($db,array_keys(array_diff_key($rentKeys,$gizli)),$today);foreach($rentals as &$x)$x['son_iskonto']=$son[$x['id']]??null;unset($x);foreach($current as &$x)$x['son_iskonto']=$son[$x['id']]??null;unset($x);
 $services=[];
 foreach($db->query("SELECT s.*,p.ad_soyad AS teknisyen FROM teknik_servis s LEFT JOIN personel p ON p.id=s.teknisyen_id WHERE s.durum<>'tamamlandi' ORDER BY s.tarih,s.id")->fetchAll(PDO::FETCH_ASSOC) as $s){
  $key=$s['makine_tur'].':'.$s['makine_id'];if(!isset($machines[$key]))continue;$days=fiGun($s['tarih'],$today);
  $services[]=['id'=>(int)$s['id'],'makine'=>$key,'tip'=>$s['tip'],'tarih'=>$s['tarih'],'gun'=>$days!==null&&$days>=0?$days:null,'durum'=>$s['durum'],'ariza'=>$s['ariza'],'teknisyen'=>$s['teknisyen']?:'Atanmamış'];
 }
 $changes=[];
 foreach($db->query("SELECT kayit_id,tarih,kullanici_ad,degisenler FROM islem_log WHERE tablo='kiralamalar' AND degisenler LIKE '%iskonto_oran%' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) as $l){
  if(!isset($rentKeys[(int)$l['kayit_id']])||isset($gizli[(int)$l['kayit_id']]))continue;$fields=json_decode($l['degisenler'],true);$v=$fields['iskonto_oran']??null;if(!is_array($v)||count($v)!==2)continue;
  $changes[]=['kiralama_id'=>(int)$l['kayit_id'],'tarih'=>$l['tarih'],'kullanici'=>$l['kullanici_ad'],'onceki'=>$v[0],'yeni'=>$v[1]];
 }
 return ['bugun'=>$today,'bas'=>$bas,'bit'=>$bit,'makineler'=>array_values($machines),'kiralamalar'=>$rentals,'aktif_kiralamalar'=>$current,'servisler'=>$services,'iskonto_gecmisi'=>$changes,
  'notlar'=>['Tarihsel fiyat ve TL dönüşümü sözleşmenin başlangıç tarihini esas alır. Kur tam o güne ait olmalıdır; eski günün kuru otomatik kullanılmaz. Düzeltmeler analiz sonuçlarını günceller, fatura tutarlarını değiştirmez.','Fiyatlar KDV hariçtir; aylık eşdeğer 30 gün üzerinden hesaplanır. Para birimleri birbirine eklenmez.','Ana grup önce makineye özel sınıflandırmadan, yoksa kategorinin katalogdaki ana grubundan (o da boşsa alan şablonunun adından) gelir; alt grup makinenin kategorisidir. Yakıt tipi makine kaydından, yoksa sınıflandırmadaki enerji türünden gelir. Boş alanlar Belirtilmemiş olarak gösterilir.','Liste karşılaştırması bugünkü TL liste fiyatıyla ve sözleşmenin kira tipine uyan fiyatla yapılır (günlük kira günlük liste ×30, haftalık kira haftalık liste ×30/7); o tipin fiyatı girilmemişse aylık liste kullanılır. Dönem hedefleri aşağıdaki hedef tablosunda ayrıdır.','Dağılım ve bakım bugünü, fiyat ve DK katkısı seçili tarih aralığını gösterir. Şube filtresi makinenin bugünkü kayıtlı şubesidir.']];
}
