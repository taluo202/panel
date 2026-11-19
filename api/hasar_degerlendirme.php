<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_once __DIR__.'/hasar-fiyat-lib.php';
require_once __DIR__.'/hasar-akis.php';
require_once __DIR__.'/ariza-karar-lib.php';
requireAuth();$db=getDb();$method=$_SERVER['REQUEST_METHOD'];
header('Cache-Control: no-store');
$yonetir=hasPermission('teknik_servis','yaz')||hasPermission('kiralama_takip','yaz');
$teknik=hasPermission('teknik_servis','yaz');
$ticari=hasPermission('kiralama_takip','yaz');
$id=(int)($_GET['id']??0);
function hdKunyeEkle(PDO $db, array $rows): array {
    if (!$rows) return $rows;
    $bilgi = makineBilgiToplu($db, array_map(fn($r) => ['tur' => $r['makine_tur'], 'id' => (int)$r['makine_id']], $rows));
    $kids = array_values(array_unique(array_map(fn($r) => (int)$r['kiralama_id'], $rows)));
    $kir = [];
    if ($kids) {
        $s = $db->prepare('SELECT k.id,k.kod,k.lokasyon,k.il,k.ekipman_adi,k.sube,k.durum,k.musteri_id,m.unvan AS musteri FROM kiralamalar k LEFT JOIN musteriler m ON m.id=k.musteri_id WHERE k.id IN (' . implode(',', array_fill(0, count($kids), '?')) . ')');
        $s->execute($kids);
        foreach ($s->fetchAll() as $k) $kir[(int)$k['id']] = $k;
    }
    $ben = (int)($_SESSION['personel_id'] ?? 0);
    foreach ($rows as &$r) {
        $b = $bilgi[$r['makine_tur'] . ':' . (int)$r['makine_id']] ?? [];
        $k = $kir[(int)$r['kiralama_id']] ?? [];
        $r['makine'] = ['kod' => $b['kod'] ?? null, 'marka' => $b['marka'] ?? null, 'model' => $b['model'] ?? null,
                        'marka_model' => trim(($b['marka'] ?? '') . ' ' . ($b['model'] ?? '')) ?: null,
                        'kategori' => $b['kategori'] ?? null, 'metraj' => $b['metraj'] ?? null, 'olcu_birim' => $b['olcu_birim'] ?? null,
                        'seri_no' => $b['seri_no'] ?? null, 'stok_kodu' => $b['makine_kodu'] ?? null,
                        'sube' => ($b['sube'] ?? null) ?: ($k['sube'] ?? null) ?: ($r['sube'] ?? null), 'durum' => $b['durum'] ?? null];
        $r['kiralama_kod'] = $k['kod'] ?? null; $r['musteri'] = $k['musteri'] ?? null; $r['santiye'] = $k['lokasyon'] ?? null;
        $r['il'] = $k['il'] ?? null; $r['ekipman_adi'] = $k['ekipman_adi'] ?? null; $r['kiralama_durum'] = $k['durum'] ?? null;
        $r['benim'] = $ben > 0 && (int)$r['atanan_id'] === $ben;
    }
    unset($r);
    return $rows;
}
if($method==='GET'){
    if(($_GET['action']??'')==='sayi'){
        $rows=array_filter($db->query('SELECT * FROM hasar_degerlendirmeleri')->fetchAll(),'hdGorunur');
        jsonOk(['toplam'=>count($rows),'kapanis'=>count(array_filter($rows,fn($x)=>hdArsivIslemleri($x)!==[]))]);
    }
    if($id){
        $r=hdGet($db,$id);if(!$r||!hdGorunur($r))jsonError('Değerlendirme bulunamadı.',404);
        $t=hdKaynak($db,(int)$r['tutanak_id']);
        $r['tutanak']=$t?array_intersect_key($t,array_flip(['id','belge_no','islem','tarih','genel_durum','hasar_notu','bilesen_hasar','ariza_kodlari','ekipman_adi','kiralama_kod'])):null;
        $r['ai_rapor']=($t&&!empty($t['ai_analiz_json']))?json_decode((string)$t['ai_analiz_json'],true):null;
        if($t){
            $s=$db->prepare('SELECT id,aci_tipi,aciklama,dosya_yolu FROM iade_tutanak_fotograflari WHERE tutanak_id=? ORDER BY id');$s->execute([$r['tutanak_id']]);$r['fotograflar']=$s->fetchAll();
            $oSube=subeKisitiHam();$oPar=[$r['makine_tur'],$r['makine_id'],$r['tutanak_id'],$t['tarih'],$t['tarih'],$r['tutanak_id']];if($oSube!==null)$oPar[]=$oSube;
            $s=$db->prepare('SELECT t.id,t.belge_no,t.tarih,t.islem FROM iade_tutanaklari t JOIN kiralamalar k ON k.id=t.kiralama_id WHERE k.makine_tur=? AND k.makine_id=? AND t.id<>? AND (t.tarih<? OR (t.tarih=? AND t.id<?))'.($oSube!==null?' AND k.sube=?':'').' ORDER BY t.tarih DESC,t.id DESC LIMIT 50');
            $s->execute($oPar);$r['onceki_kayitlar']=$s->fetchAll();
        }else{
            $s=$db->prepare("SELECT id,aci_tipi,aciklama,dosya_yolu FROM kiralama_fotograflari WHERE kiralama_id=? AND yon='donus' ORDER BY id");$s->execute([$r['kiralama_id']]);$r['fotograflar']=$s->fetchAll();
            if(hfArizaKaydiMi($r)&&$r['servis_id']){$s=$db->prepare("SELECT id,'Arıza fotoğrafı (müşteri)' AS aci_tipi,kaynak AS aciklama,dosya_yolu FROM teknik_servis_fotograflari WHERE servis_id=? ORDER BY id");$s->execute([$r['servis_id']]);$r['fotograflar']=array_merge($r['fotograflar'],$s->fetchAll());}
            $r['kalemler']=hdKalemler($db,$id);
            $r['onceki_kayitlar']=[];
        }
        $r['ariza']=akArizaVeri($db,$r,$ticari);$r['ticari_karar']=$ticari;
        $s=$db->prepare('SELECT olay,aciklama,kullanici,veri,created_at FROM hasar_degerlendirme_gecmisi WHERE degerlendirme_id=? ORDER BY id DESC');$s->execute([$id]);$r['gecmis']=$s->fetchAll();
        if($r['servis_id']){$s=$db->prepare('SELECT id,durum FROM teknik_servis WHERE id=?');$s->execute([$r['servis_id']]);$r['servis']=$s->fetch()?:null;}
        $r['yonetebilir']=$yonetir||hdAksesuarYetki($r);$r['teknik_karar']=$teknik;$r['yalniz_aksesuar']=!$yonetir&&hdAksesuarYetki($r);
        $s=$db->prepare('SELECT ad_soyad FROM personel WHERE id=?');$s->execute([$r['atanan_id']]);$r['atanan_ad']=$s->fetchColumn()?:null;
        $r['onceki_fotograflar']=[];
        if($r['onceki_kayitlar']){
            $ids=array_column($r['onceki_kayitlar'],'id');$s=$db->prepare('SELECT tutanak_id,aci_tipi,aciklama,dosya_yolu FROM iade_tutanak_fotograflari WHERE tutanak_id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id');$s->execute($ids);
            foreach($s->fetchAll() as $f)$r['onceki_fotograflar'][$f['tutanak_id']][]=$f;
        }
        $r=hdKunyeEkle($db,[$r])[0];
        $r['karsi']=null;
        if($t){
            if($t['islem']==='iade'){
                $s=$db->prepare("SELECT id,belge_no,tarih FROM iade_tutanaklari WHERE kiralama_id=? AND islem='teslim' AND COALESCE(fiili_teslim,1)=1 AND id<>? AND tarih<=? ORDER BY tarih DESC,id DESC LIMIT 1");$s->execute([$t['kiralama_id'],$r['tutanak_id'],$t['tarih']]);
                if($kt=$s->fetch()){$s=$db->prepare('SELECT id,aci_tipi,aciklama,dosya_yolu FROM iade_tutanak_fotograflari WHERE tutanak_id=? ORDER BY id');$s->execute([$kt['id']]);
                    $r['karsi']=['kaynak'=>'teslim','etiket'=>'Teslim '.($kt['belge_no']?:'#'.$kt['id']).' · '.$kt['tarih'],'fotograflar'=>$s->fetchAll()];}
            }
        }else{
            $s=$db->prepare("SELECT id,aci_tipi,aciklama,dosya_yolu FROM kiralama_fotograflari WHERE kiralama_id=? AND yon='gidis' ORDER BY id");$s->execute([$r['kiralama_id']]);
            $gidis=$s->fetchAll();if($gidis)$r['karsi']=['kaynak'=>'gidis','etiket'=>'Gidiş (çıkış kontrolü) fotoğrafları','fotograflar'=>$gidis];
        }
        $gids=array_values(array_filter([(int)$r['gorev_id'],(int)$r['aksesuar_gorev_id'],(int)($r['fiyat_gorev_id']??0)]));
        $gq='(kaynak_modul IN (\'hasar_degerlendirme\',\'hasar_aksesuar\',\'hasar_fiyat\',\'hasar_indirim\') AND kaynak_id=?)';$gp=[$id];
        if((int)$r['servis_id']){$gq.=' OR (kaynak_modul IN (\'teknik_servis\',\'servis_getir\') AND kaynak_id=?)';$gp[]=(int)$r['servis_id'];}
        if($gids)$gq.=' OR id IN ('.implode(',',$gids).')';
        $s=$db->prepare('SELECT id,baslik,durum,kaynak_modul,atanan_id,son_tarih FROM gorevler WHERE '.$gq.' ORDER BY id');
        $s->execute($gp);$r['gorevler']=array_values(array_filter($s->fetchAll(),fn($g)=>!in_array($g['kaynak_modul'],['hasar_fiyat','hasar_indirim'],true)||$ticari));
        if($ticari){$s=$db->prepare('SELECT id,ref,faturalandi,fatura_no,fatura_tarihi FROM hasar_faturalari WHERE degerlendirme_id=?');$s->execute([$id]);$r['fatura']=$s->fetch()?:null;}
        $r['kilitli']=true;$r['arsiv']=true;$r['kapanis']=$yonetir?hdArsivIslemleri($r,$ticari):[];
        jsonOk($r);
    }
    if(($_GET['action']??'')==='personel'){
        if(!$yonetir)jsonError('Atama yetkiniz yok.',403);
        $where=["durum='aktif'"];$params=[];if(($sube=subeKisitiHam())!==null){$where[]='sube=?';$params[]=$sube;}
        $s=$db->prepare('SELECT id,ad_soyad,pozisyon,sube FROM personel WHERE '.implode(' AND ',$where).' ORDER BY ad_soyad');$s->execute($params);jsonOk($s->fetchAll());
    }
    $rows=$db->query('SELECT h.*,p.ad_soyad AS atanan_ad,s.durum AS servis_durum FROM hasar_degerlendirmeleri h LEFT JOIN personel p ON p.id=h.atanan_id LEFT JOIN teknik_servis s ON s.id=h.servis_id ORDER BY h.hedef_zaman,h.id')->fetchAll();
    $rows=array_values(array_filter($rows,'hdGorunur'));
    if(($_GET['benim']??'')==='1')$rows=array_values(array_filter($rows,fn($r)=>!empty($_SESSION['personel_id'])&&(int)$r['atanan_id']===(int)$_SESSION['personel_id']));
    $rows=hdKunyeEkle($db,$rows);
    $ozet=['acik'=>0,'atanmamis'=>0,'geciken'=>0,'sonuclanan'=>0];$now=date('Y-m-d H:i:s');
    foreach($rows as &$r){$r['gecikti']=!in_array($r['durum'],['sonuclandi','iptal','tasindi'],true)&&$r['hedef_zaman']<$now;
        if(in_array($r['durum'],['sonuclandi','iptal','tasindi'],true))$ozet['sonuclanan']++;else{$ozet['acik']++;if(!$r['atanan_id'])$ozet['atanmamis']++;if($r['gecikti'])$ozet['geciken']++;}}
    unset($r);jsonOk(['list'=>$rows,'ozet'=>$ozet,'yonetebilir'=>$yonetir,'teknik_karar'=>$teknik,'ticari_karar'=>$ticari]);
}
if($method!=='PUT')jsonError('Method Not Allowed',405);
verifyCsrf();
$body=json_decode(file_get_contents('php://input'),true);if(!is_array($body))jsonError('İstek okunamadı.');
$r=hdGet($db,$id);if(!$r||!hdGorunur($r))jsonError('Değerlendirme bulunamadı.',404);
if(($_GET['action']??'')==='indirim')jsonError('Kira indirimi önerileri servis kaydında: Teknik Servis › kayıt › Masraf ve fiyat.',409);
if(($_GET['action']??'')!=='kapanis')jsonError('Hasar değerlendirme arşivde; yeni karar verilemez. Bağlı servis kaydından ilerleyin (Teknik Servis).',409);
if(!$yonetir)jsonError('Değerlendirme yazma yetkiniz yok.',403);
if((int)($body['surum']??0)!==(int)$r['surum'])jsonError('Bu kayıt başka bir işlemle değişti. Yenileyip tekrar değerlendirin.',409);
$db->beginTransaction();
try{$out=hdKapanisUygula($db,$r,(string)($body['islem']??''),mb_substr(trim((string)($body['gerekce']??'')),0,3000),$ticari);$db->commit();jsonOk($out,$out['mesaj']);}
catch(Throwable $e){if($db->inTransaction())$db->rollBack();if($e instanceof DomainException)jsonError($e->getMessage(),409);error_log('Hasar arşiv kapanışı: '.$e->getMessage());jsonError('İşlem tamamlanamadı; kayıt korunuyor. Tekrar deneyin.',500);}
