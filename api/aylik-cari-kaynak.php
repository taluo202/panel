<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
requireAuth();requirePermission('yonetim','oku');
if(subeKisiti()!==null)jsonError('Şirket geneli kaynak karşılaştırması için tüm şubelere erişim gerekir.',403);
if($_SERVER['REQUEST_METHOD']!=='GET')jsonError('Method Not Allowed',405);
header('Cache-Control: no-store');
$ay=(string)($_GET['ay']??'');
if(!preg_match('/^(19|20|21)\d{2}-(0[1-9]|1[0-2])$/',$ay))jsonError('Geçerli ay seçin.',422);
$bas=$ay.'-01';$bit=date('Y-m-t',strtotime($bas));
if($bit>=date('Y-m-d'))jsonError('Ay sonu karşılaştırması için sona ermiş bir ay seçin.',422);
$db=getDb();$db->beginTransaction();
try{
 $rows=[];$issues=[];$total=0.0;
 $validDate=static function($v):bool{$v=(string)$v;$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);return $d&&$d->format('Y-m-d')===$v;};
 $all=$db->query('SELECT h.*,m.unvan FROM hakedisler h LEFT JOIN musteriler m ON m.id=h.musteri_id ORDER BY h.id')->fetchAll();
 foreach($all as $h){
  if(!in_array($h['durum'],['faturalandi','tahsil_edildi'],true)&&trim((string)$h['fatura_no'])==='')continue;
  if(!$validDate($h['fatura_tarihi'])){$issues[]='Hakediş #'.$h['id'].': geçerli fatura tarihi yok; ayı belirlenemedi.';continue;}
  if($h['fatura_tarihi']<$bas||$h['fatura_tarihi']>$bit)continue;
  $amount=round((float)$h['genel_toplam'],2);if($amount<0){$issues[]='Hakediş #'.$h['id'].': negatif fatura tutarını kontrol edin.';continue;}
  $total+=$amount;$rows[]=['id'=>(int)$h['id'],'kod'=>$h['kod'],'fatura_no'=>$h['fatura_no'],'tarih'=>$h['fatura_tarihi'],'musteri'=>$h['unvan'],'tutar'=>$amount];
 }
 $dateIssues=[];
 foreach($db->query('SELECT id,acilis_bakiye,acilis_tarihi FROM musteriler WHERE COALESCE(acilis_bakiye,0)<>0')->fetchAll() as $m)
  if(!$validDate($m['acilis_tarihi']))$dateIssues[]='Müşteri #'.$m['id'].': devir tarihi eksik/geçersiz.';
 foreach($db->query('SELECT id,tarih FROM tahsilatlar')->fetchAll() as $t)
  if(!$validDate($t['tarih']))$dateIssues[]='Tahsilat #'.$t['id'].': tarih eksik/geçersiz.';
 $balance=alacakOzeti($db,null,$bit,$bit);$customers=[];
 $names=$db->query('SELECT id,unvan FROM musteriler')->fetchAll(PDO::FETCH_KEY_PAIR);
 foreach($balance['musteri'] as $id=>$amount)$customers[]=['id'=>$id,'musteri'=>$names[$id]??'#'.$id,'tutar'=>$amount];
 $data=['cek_risk'=>$balance['cek_risk']??[], 'cek_uyarilari'=>$balance['cek_uyarilari']??[], 'ay'=>$ay,'faturalar'=>$rows,'fatura'=>round($total,2),'fatura_uyarilari'=>$issues,'alacak'=>$balance['acik'],'musteriler'=>$customers,'alacak_uyarilari'=>array_merge($issues,$dateIssues),
  'kapsam'=>'Resmî muhasebe Akınsoft sistemindedir. Bu liste sitedeki faturalı hakedişler, devirler, tahsilatlar ve çek mahsuplarıyla sınırlıdır. Servis, makine satış ve nakliye faturaları Akınsoft ile ayrıca karşılaştırılmalıdır. Çek dışındaki alacakların avukatlık/batık ayrımı bu listeden doğrulanamaz.'];
 $data['hash']=hash('sha256',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));$data['yazabilir']=hasPermission('yonetim','yaz');
 $db->commit();jsonOk($data);
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();error_log($e->getMessage());jsonError('Cari kaynak karşılaştırması hazırlanamadı.',500);}
