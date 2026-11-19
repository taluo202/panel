<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
requireAuth(); yazmaIstegiCsrf();requirePermission('kiralama_takip',$_SERVER['REQUEST_METHOD']==='GET'?'oku':'yaz');
$db=getDb();$id=(int)($_GET['id']??0);$r=tsYetki($db,$id);header('Cache-Control: no-store');
if($_SERVER['REQUEST_METHOD']==='GET'){
    $db->beginTransaction();try{tsIlk($db,$id);$db->commit();}catch(Throwable $e){$db->rollBack();throw $e;}
    $s=$db->prepare('SELECT surum,ozet_hash,gerekce,kaydeden,kayit_turu,created_at FROM tutanak_surumleri WHERE tutanak_id=? ORDER BY surum DESC');$s->execute([$id]);$surumler=$s->fetchAll();
    $s=$db->prepare('SELECT * FROM tutanak_duzeltmeleri WHERE tutanak_id=? ORDER BY id DESC');$s->execute([$id]);$talepler=$s->fetchAll();
    foreach($talepler as &$t){$v=json_decode($t['istek'],true);$t['degisiklik']=$v;unset($t['istek']);$t['degisiklik']['fotograflar_yeni']=array_map(fn($f)=>['aci'=>$f['aci']??'','aciklama'=>$f['aciklama']??'','veri'=>$t['durum']==='bekliyor'?($f['veri']??''):null], $v['fotograflar_yeni']??[]);}unset($t);
    foreach($talepler as &$t){
        $s=$db->prepare('SELECT veri FROM tutanak_surumleri WHERE tutanak_id=? AND surum=?');$s->execute([$id,$t['temel_surum']]);$temel=json_decode($s->fetchColumn()?:'{}',true);
        $t['onceki_degerler']=array_intersect_key($temel,$t['degisiklik']);
    }unset($t);
    $n=(int)($_GET['surum']??$surumler[0]['surum']);$s=$db->prepare('SELECT veri FROM tutanak_surumleri WHERE tutanak_id=? AND surum=?');$s->execute([$id,$n]);$veri=$s->fetchColumn();if(!$veri)jsonError('Belge sürümü bulunamadı.',404);
    if(($_GET['action']??'')==='tek'){
        $gorunum=json_decode($veri,true);
        if($n===(int)$surumler[0]['surum']){foreach(['ai_analiz_sonucu','ai_analiz_durum','ai_analiz_tarihi'] as $key)$gorunum[$key]=$r[$key]??null;$gorunum['ai_rapor']=!empty($r['ai_analiz_json'])?json_decode((string)$r['ai_analiz_json'],true):null;}
        jsonOk($gorunum);
    }
    $s=$db->prepare("SELECT * FROM gorevler WHERE kaynak_modul IN ('tutanak_kabul','tutanak_duzeltme') AND kaynak_id=? AND durum='acik' ORDER BY id DESC");$s->execute([$id]);$gorevler=$s->fetchAll();$gorev=$gorevler[0]??null;
    $s=$db->prepare("SELECT id,ad_soyad FROM personel WHERE durum='aktif' AND COALESCE(sube,'')=? ORDER BY ad_soyad");$s->execute([$r['sube']??'']);
    jsonOk(['belge'=>json_decode($veri,true),'surumler'=>$surumler,'talepler'=>$talepler,'etki'=>tsEtki($db,$r),'yazabilir'=>hasPermission('kiralama_takip','yaz'),'gorev'=>$gorev,'gorevler'=>$gorevler,'personel'=>$s->fetchAll()]);
}
if($_SERVER['REQUEST_METHOD']!=='POST')jsonError('Method Not Allowed',405);
verifyCsrf();$b=json_decode(file_get_contents('php://input'),true);if(!is_array($b))jsonError('İstek okunamadı.');
$db->beginTransaction();try{
    tsIlk($db,$id);$r=tsYetki($db,$id);
    if((int)($b['temel_surum']??0)!==(int)$r['belge_surumu'])throw new DomainException('Belge sürümü değişti. Yenileyin.');
    if(($b['islem']??'')==='ret'){
        $not=trim((string)($b['gerekce']??''));if($not==='')throw new DomainException('Ret gerekçesi zorunludur.');
        $s=$db->prepare("UPDATE tutanak_duzeltmeleri SET durum='reddedildi',sonuc_notu=?,onaylayan=?,sonuc_zamani=datetime('now','localtime') WHERE id=? AND tutanak_id=? AND durum='bekliyor'");$s->execute([mb_substr($not,0,3000),$_SESSION['username'],$b['talep_id']??0,$id]);if(!$s->rowCount())throw new DomainException('Bekleyen talep bulunamadı.');
    }elseif(($b['islem']??'')==='takip'){
        $kaynak=$b['kaynak_modul']??'tutanak_kabul';if(!in_array($kaynak,['tutanak_kabul','tutanak_duzeltme'],true))throw new DomainException('Geçersiz takip türü.');
        $atanan=(int)($b['atanan_id']??0);$not=trim((string)($b['gerekce']??''));$hedef=(string)($b['hedef_tarih']??'');
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$hedef);
        if(!$d||$d->format('Y-m-d')!==$hedef||$hedef<date('Y-m-d')||$not==='')throw new DomainException('Bugün veya sonrası için hedef tarih ve atama gerekçesi girin.');
        $s=$db->prepare("SELECT id FROM personel WHERE id=? AND durum='aktif' AND COALESCE(sube,'')=?");$s->execute([$atanan,$r['sube']??'']);if(!$s->fetchColumn())throw new DomainException('Aynı şubeden aktif sorumlu seçin.');
        $s=$db->prepare("UPDATE gorevler SET atanan_id=?,son_tarih=?,aciklama=?,updated_at=datetime('now','localtime') WHERE kaynak_modul=? AND kaynak_id=? AND durum='acik'");
        $s->execute([$atanan,$hedef,mb_substr($not,0,2000).' · '.($r['kabul_notu']??''),$kaynak,$id]);if(!$s->rowCount())throw new DomainException('Açık kabul/çekince takip işi bulunamadı.');
    }elseif(($b['islem']??'')==='kabul'){
        if(!empty($r['inceleme_sonucu']))throw new DomainException('İade Tutanağında yeniden kabul alınmaz: müşteri imzası şantiyedeki teslim alma kaydındadır; kabulü oradan yenileyin.');
        if(!(int)$r['fiili_teslim'])throw new DomainException('Fiili teslim gerçekleşmemiş. Yeni teslim/iade işlemini saha tutanağından kaydedin.');
        $b['fiili_teslim']=1;$v=tsKabul($b);
        if(!in_array($v['kabul_sonucu'],['teslim_aldi','cekinceli'],true))throw new DomainException('Bu ekranda imzalı kabul veya çekinceli kabul alınabilir.');
        $imza=(string)($b['imza_personel']??'');
        require_once __DIR__.'/tutanak-yaz.php';if($imza!==''&&!itImzaVerisiGecerli($imza))throw new InvalidArgumentException('Personel imzası okunamadı. İmzayı temizleyip yeniden atın ya da boş bırakın.');$imza=$imza!==''?$imza:null;
        tsKabulYaz($db,$id,$v);
        $imzalayan=function_exists('stOturumImzaAd')?stOturumImzaAd($db,(int)($_SESSION['user_id']??0),(string)$_SESSION['username']):(string)$_SESSION['username'];
        itYenidenKabulImzaYaz($db,$id,(string)$b['imza_musteri'],(string)$b['musteri_temsilci_ad'],$imza,$imzalayan);itKiralayanEksikseSabitle($db,$id);
        tsKaydet($db,$id,'Güncel içeriğe yeniden imza/kabul alındı.','kabul');tsKabulGorevi($db,$id);
    }else throw new DomainException('Geçersiz işlem.');
    tsTalepGorevi($db,$id);
    logla($db,'iade_tutanaklari',$id,'guncelle',null,null,'Belge işlemi: '.$b['islem']);$db->commit();jsonOk(null,'Belge işlemi kaydedildi.');
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();if($e instanceof DomainException)jsonError($e->getMessage(),409);if($e instanceof InvalidArgumentException)jsonError($e->getMessage(),400);error_log($e->getMessage());jsonError('Belge işlemi tamamlanamadı.',500);}
