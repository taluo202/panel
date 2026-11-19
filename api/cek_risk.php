<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';require_once __DIR__.'/cek-cari-kurallari.php';require_once __DIR__.'/cek-islem.php';
requireAuth();requirePermission('cek_senet');$db=getDb();crSema($db);
if($_SERVER['REQUEST_METHOD']==='GET'){
 $data=crVeri($db);$list=[];
 foreach($data['kayitlar'] as $row){$q=$db->prepare('SELECT * FROM cek_senet WHERE id=?');$q->execute([$row['cek_id']]);$c=$q->fetch();if(subeKisiti()!==null&&cekSenetSubeEfektif($db,$c)!==subeKisiti())continue;
  $q=$db->prepare('SELECT unvan FROM musteriler WHERE id=?');$q->execute([$row['musteri_id']]);$row['musteri']=$q->fetchColumn()?:'Müşteri eksik';$row['surum']=csSurum($c);$list[]=$row;
 }
 $totals=['bekliyor'=>0.0,'avukatlik'=>0.0,'batik'=>0.0];foreach($list as $r)$totals[$r['sinif']]+=$r['tutar'];
 jsonOk(['list'=>$list,'toplam'=>$totals,'yazabilir'=>hasPermission('cek_senet','yaz'),'uyarilar'=>subeKisiti()===null?$data['uyarilar']:($data['uyarilar']?['Tarih veya geçmişi eksik çekler var; merkezden kontrol isteyin.']:[])]);
}
if($_SERVER['REQUEST_METHOD']!=='POST')jsonError('Method Not Allowed',405);
verifyCsrf();requirePermission('cek_senet','yaz');$b=govdeOku();
$kind=$b['sinif']??'';if(!in_array($kind,['bekliyor','avukatlik','batik'],true))jsonError('Geçerli sınıf seçin.');
$reason=trim((string)($b['gerekce']??''));if($reason===''||mb_strlen($reason)>1000)jsonError('Sınıflandırma gerekçesi gerekli (en fazla 1000 karakter).');
$date=csStokTarih($b['tarih']??null);if(!$date||$date>date('Y-m-d'))jsonError('Geçerli gerçekleşmiş karar tarihi girin.');
$db->beginTransaction();
$q=$db->prepare('SELECT * FROM cek_senet WHERE id=?');$q->execute([(int)($b['cek_id']??0)]);$c=$q->fetch();if(!$c)jsonError('Çek bulunamadı.',404);
subeErisimDogrula(cekSenetSubeEfektif($db,$c));
if(!is_string($b['surum']??null)||!hash_equals(csSurum($c),$b['surum']))jsonError('Çek kaydı değişti; yenileyin.',409);
$risk=null;foreach(crVeri($db)['kayitlar'] as $r)if($r['cek_id']===(int)$c['id'])$risk=$r;
if(!$risk)jsonError('Yalnız müşteriden alınmış karşılıksız çek sınıflandırılabilir.');
if($date<($c['durum_tarihi']??'')||$date<($c['teslim_tarihi']??''))jsonError('Karar tarihi çekin karşılıksız çıkmasından önce olamaz.');
if((int)($b['sinif_id']??-1)!==$risk['sinif_id'])jsonError('Sınıflandırma değişti; yenileyin.',409);
$last=$db->prepare('SELECT MAX(tarih) FROM cek_risk_siniflari WHERE cek_id=? AND olay_id=?');$last->execute([$c['id'],$risk['olay_id']]);if($date<(string)$last->fetchColumn())jsonError('Düzeltme tarihi önceki sınıflandırmadan önce olamaz.');
$db->prepare('INSERT INTO cek_risk_siniflari(cek_id,olay_id,sinif,tarih,gerekce,kullanici) VALUES(?,?,?,?,?,?)')->execute([$c['id'],$risk['olay_id'],$kind,$date,$reason,$_SESSION['ad_soyad']??$_SESSION['username']]);
logla($db,'cek_risk_siniflari',(int)$db->lastInsertId(),'ekle',null,$b,'Karşılıksız çek sınıflandırması');$db->commit();jsonOk(null,'Sınıflandırma kaydedildi; normal alacak değişmedi.');
