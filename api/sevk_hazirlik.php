<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_once __DIR__.'/referans-lib.php';
requireAuth(); yazmaIstegiCsrf();
$db=getDb();
$y=['kiralama_oku'=>hasPermission('kiralama_takip','oku'),'kiralama_yaz'=>hasPermission('kiralama_takip','yaz'),'depo'=>opOturumYetkisi($db,'depo'),'yukleme'=>opOturumYetkisi($db,'yukleme')];
if(!array_filter($y))jsonError('Bu işlem için yetkiniz yok.',403);
header('Cache-Control: no-store');$id=(int)($_GET['id']??0);
if($_SERVER['REQUEST_METHOD']==='GET'&&!$id){
    if($e=hzErisimHatasi(false,'liste',$y))jsonError($e,403);
    $w=[];$p=[];subeKosulEkle($w,$p,'k.sube');
    $s=$db->prepare("SELECT k.id,k.kod,k.ekipman_adi,k.durum,k.baslangic,m.unvan FROM kiralamalar k JOIN musteriler m ON m.id=k.musteri_id".($w?' WHERE '.implode(' AND ',$w):'').' ORDER BY k.id DESC LIMIT 300');$s->execute($p);jsonOk($s->fetchAll());
}
try{
    $k=hzKiralama($db,$id);
    if($_SERVER['REQUEST_METHOD']==='GET'){
        if($e=hzErisimHatasi(opYeniZincir($db,$k),'oku',$y))jsonError($e,403);
        $s=$db->prepare("SELECT surum,olay,gerekce,kullanici,zaman FROM depo_kontrol_gecmisi WHERE kiralama_id=? AND yon='gidis' ORDER BY id DESC");$s->execute([$id]);$gecmis=$s->fetchAll();
        $s=$db->prepare('SELECT id,islem,tarih,belge_surumu,fiili_teslim,km,calisma_saati,yakit_seviyesi,bilesen_hasar,aksesuar_kalemleri,aksesuar_referans_veri FROM iade_tutanaklari WHERE kiralama_id=? ORDER BY tarih DESC,id DESC');$s->execute([$id]);$t=$s->fetchAll();
        $s=$db->prepare("SELECT id,aci_tipi,aciklama,dosya_yolu FROM kiralama_fotograflari WHERE kiralama_id=? AND COALESCE(yon,'gidis')='gidis' ORDER BY id");$s->execute([$id]);
        jsonOk(['kiralama'=>array_intersect_key($k,array_flip(['id','kod','ekipman_adi','makine_tur','makine_id','sube','lokasyon','musteri_unvan','durum'])),'hazirlik'=>hzGet($db,$id),'sablonlar'=>hzSablonlar(),'sablon_oneri'=>hzSablonTahmin(makineBilgiToplu($db,[['tur'=>(string)$k['makine_tur'],'id'=>(int)$k['makine_id']]])[$k['makine_tur'].':'.(int)$k['makine_id']]['kategori']??null,$k['ekipman_adi']??null,(string)$k['makine_tur']),'gecmis'=>$gecmis,'engel'=>hzEngel($db,$k),'eski'=>!hzGerekli($db,$k),'tutanaklar'=>$t,'fotograflar'=>$s->fetchAll(),'zorunlu_acilar'=>($hzAci=hzHazirlikZorunlu($db,$k)),'eksik_acilar'=>hzEksikAcilar($db,$k,$hzAci),'sayac_alani'=>hzSayacAlani((string)$k['makine_tur']),'yeni_zincir'=>opYeniZincir($db,$k),'yazabilir'=>hzErisimHatasi(opYeniZincir($db,$k),'kaydet',$y)===null,'referans'=>refHazirlikBilgi($db,$k),'bilesen_tanim'=>bzBilesenTanim()]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')jsonError('Method Not Allowed',405);
    verifyCsrf();$b=json_decode(file_get_contents('php://input'),true);if(!is_array($b))throw new DomainException('İstek okunamadı.');
    $db->beginTransaction();$k=hzKiralama($db,$id);
    $islem=(string)($b['islem']??'');
    if(!in_array($islem,['kaydet','tamamla','onayla','yola_cik'],true)){$db->rollBack();jsonError('Geçersiz hazırlık işlemi.',400);}
    if($e=hzErisimHatasi(opYeniZincir($db,$k),$islem,$y)){$db->rollBack();jsonError($e,403);}
    if($islem==='yola_cik'&&opYeniZincir($db,$k)){$db->rollBack();jsonError('Yeni düzende yükleme Yükleme / Toplama ekranından yapılır.',409);}
    hzKaydet($db,$k,$b);
    if(opYeniZincir($db,$k)&&in_array($b['islem'],['tamamla','yola_cik'],true))
        opAdimYaz($db,$id,$b['islem']==='tamamla'?'depo_hazirlik':'yukleme','tamam',opOturumYapan($db),'hazirlik',mb_substr(trim((string)($b['gerekce']??'')),0,500)?:null);
    logla($db,'kiralamalar',$id,'guncelle',null,null,'Sevk hazırlığı: '.$b['islem']);$db->commit();jsonOk(null,'Hazırlık işlemi kaydedildi.');
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();if($e instanceof DomainException)jsonError($e->getMessage(),409);error_log($e->getMessage());jsonError('Hazırlık işlemi tamamlanamadı.',500);}
