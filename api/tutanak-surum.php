<?php
declare(strict_types=1);
require_once __DIR__.'/belge-zinciri-lib.php';

function tsSema(PDO $db): void {
    $cols=array_column($db->query('PRAGMA table_info(iade_tutanaklari)')->fetchAll(),'name');
    foreach(['belge_surumu'=>'INTEGER NOT NULL DEFAULT 0','kabul_sonucu'=>'TEXT','kabul_notu'=>'TEXT','fiili_teslim'=>'INTEGER','bulundugu_yer'=>'TEXT','sonraki_plan'=>'TEXT','cekince_parca'=>'TEXT','imza_personel_ad'=>'TEXT','toplu_teslim_id'=>'TEXT','toplu_adet'=>'INTEGER','teslim_eden_tur'=>'TEXT','birlikte_bulunanlar'=>'TEXT','kiralayan_ad'=>'TEXT','kiralayan_imza_ad'=>'TEXT','kiralayan_imza'=>'TEXT','kiralayan_imza_metni'=>'TEXT','kiralayan_imza_unvan'=>'TEXT','kiralayan_imza_kaynak'=>'TEXT'] as $k=>$tip)
        if(!in_array($k,$cols,true))$db->exec("ALTER TABLE iade_tutanaklari ADD COLUMN $k $tip");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_tutanak_toplu ON iade_tutanaklari(toplu_teslim_id) WHERE toplu_teslim_id IS NOT NULL');
    foreach(['asama'=>"TEXT NOT NULL DEFAULT 'tutanak'",'saha_alim_id'=>'INTEGER','depo_kontrol_id'=>'INTEGER','inceleme_sonucu'=>'TEXT','inceleme_gerekce'=>'TEXT','nakliye_sofor'=>'TEXT'] as $k=>$tip)
        if(!in_array($k,$cols,true))$db->exec("ALTER TABLE iade_tutanaklari ADD COLUMN $k $tip");
    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS ux_it_iade_tutanagi ON iade_tutanaklari(kiralama_id) WHERE inceleme_sonucu IS NOT NULL');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_it_depo_kontrol ON iade_tutanaklari(depo_kontrol_id) WHERE depo_kontrol_id IS NOT NULL');
    $db->exec("CREATE TABLE IF NOT EXISTS tutanak_surumleri (
        id INTEGER PRIMARY KEY AUTOINCREMENT,tutanak_id INTEGER NOT NULL REFERENCES iade_tutanaklari(id) ON DELETE RESTRICT,
        surum INTEGER NOT NULL,veri TEXT NOT NULL,ozet_hash TEXT NOT NULL,gerekce TEXT NOT NULL,kaydeden TEXT,kayit_turu TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),UNIQUE(tutanak_id,surum));
        CREATE TABLE IF NOT EXISTS tutanak_duzeltmeleri (
        id INTEGER PRIMARY KEY AUTOINCREMENT,tutanak_id INTEGER NOT NULL REFERENCES iade_tutanaklari(id) ON DELETE RESTRICT,
        temel_surum INTEGER NOT NULL,istek TEXT NOT NULL,gerekce TEXT NOT NULL,durum TEXT NOT NULL DEFAULT 'bekliyor',
        isteyen TEXT,onaylayan TEXT,sonuc_notu TEXT,created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),sonuc_zamani TEXT);
        CREATE INDEX IF NOT EXISTS idx_td_bekleyen ON tutanak_duzeltmeleri(tutanak_id,durum);
        CREATE TRIGGER IF NOT EXISTS ts_degisemez BEFORE UPDATE ON tutanak_surumleri BEGIN SELECT RAISE(ABORT,'Belge surumu degistirilemez'); END;
        CREATE TRIGGER IF NOT EXISTS ts_silinemez BEFORE DELETE ON tutanak_surumleri BEGIN SELECT RAISE(ABORT,'Belge surumu silinemez'); END;");
}
function tsYetki(PDO $db,int $id): array {
    $s=$db->prepare('SELECT t.*,k.sube FROM iade_tutanaklari t JOIN kiralamalar k ON k.id=t.kiralama_id WHERE t.id=?');$s->execute([$id]);$r=$s->fetch();
    if(!$r||(($sube=subeKisitiHam())!==null&&$sube!==$r['sube']))jsonError('Tutanak bulunamadı.',404);
    return $r;
}
function tsVeri(PDO $db,int $id,bool $kiyas=true): array {
    $s=$db->prepare('SELECT t.*,COALESCE(p.ad_soyad,t.imza_personel_ad) AS personel_ad,p.pozisyon AS personel_pozisyon,k.kod AS kiralama_kod,k.ekipman_adi,k.baslangic,k.bitis,k.makine_tur,k.makine_id,m.unvan AS musteri_unvan,m.telefon AS musteri_telefon,m.adres AS musteri_adres FROM iade_tutanaklari t LEFT JOIN personel p ON p.id=t.personel_id JOIN kiralamalar k ON k.id=t.kiralama_id JOIN musteriler m ON m.id=k.musteri_id WHERE t.id=?');$s->execute([$id]);$r=$s->fetch();
    if(!$r)throw new DomainException('Tutanak bulunamadı.');
    $s=$db->prepare('SELECT id,aci_tipi,dosya_yolu,aciklama FROM iade_tutanak_fotograflari WHERE tutanak_id=? ORDER BY id');$s->execute([$id]);$r['fotograflar']=$s->fetchAll();
    if(!empty($r['inceleme_sonucu'])){$r['saha_alim']=bzSahaBolumu($db,$r);$r['depo']=bzDepoBolumu($db,$r);}$r['makine']=bzMakineKunye($db,(string)$r['makine_tur'],(int)$r['makine_id']);if($r['islem']==='teslim'&&opYeniZincir($db,['id'=>(int)$r['kiralama_id']]))$r['hazirlik']=bzHazirlikBolumu($db,(int)$r['kiralama_id']);
    $r['karsi']=null;
    if($kiyas&&$r['islem']==='iade'){
        $s=$db->prepare("SELECT id FROM iade_tutanaklari WHERE kiralama_id=? AND islem='teslim' AND COALESCE(fiili_teslim,1)=1 AND id<>? AND tarih<=? ORDER BY tarih DESC,id DESC LIMIT 1");$s->execute([$r['kiralama_id'],$id,$r['tarih']]);$kid=$s->fetchColumn();
        if($kid){
            $s=$db->prepare('SELECT veri FROM tutanak_surumleri WHERE tutanak_id=? ORDER BY surum DESC LIMIT 1');$s->execute([$kid]);$v=$s->fetchColumn();
            $k=$v?json_decode($v,true):tsVeri($db,(int)$kid,false);unset($k['karsi']);$k['kaynak']='kiralama';$k['kaynak_kod']=$r['kiralama_kod'];$r['karsi']=$k;
        }
    }
    $r['firma_arsiv']=$db->query("SELECT anahtar,deger FROM ayarlar WHERE anahtar IN ('firma_unvan','firma_adres','firma_telefon')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $_bmYol=__DIR__.'/../data/tutanak-belge-metni.json';
    $_bmHam=is_file($_bmYol)?@file_get_contents($_bmYol):false;
    if($_bmHam===false||trim((string)$_bmHam)===''){
        error_log('Taloo: tutanak belge metni okunamadi: '.$_bmYol);
        jsonError('Tutanak belge metni dosyası sunucuda bulunamadı (site/data/tutanak-belge-metni.json). '
            .'Bu dosya yükleme sırasında atlanmış olabilir — site/data/ klasörünü sunucuya yükleyin.',500);
    }
    try{$metin=json_decode($_bmHam,true,512,JSON_THROW_ON_ERROR);}
    catch(\JsonException $e){
        error_log('Taloo: tutanak belge metni bozuk: '.$e->getMessage());
        jsonError('Tutanak belge metni dosyası okunamadı (bozuk içerik). '
            .'site/data/tutanak-belge-metni.json dosyasını yeniden yükleyin.',500);
    }
    $beyan=($r['asama']??'')==='saha_alim'?'teslim_alma':($r['kabul_sonucu']==='teslim_aldi'&&$r['genel_durum']==='saglam'?'saglam_imzali':'diger');
    $r['belge_beyani']=str_replace('{firma}',$r['firma_arsiv']['firma_unvan']??'Kiraya veren firma',$metin[$beyan]??$metin['diger']);
    $r['belge_metni_surumu']=$metin['surum'];
    return $r;
}
function tsKaydet(PDO $db,int $id,string $gerekce,string $tur): int {
    $r=tsVeri($db,$id);$s=$db->prepare('SELECT COALESCE(MAX(surum),0)+1 FROM tutanak_surumleri WHERE tutanak_id=?');$s->execute([$id]);$n=(int)$s->fetchColumn();
    $r['belge_surumu']=$n;$r['arsiv_turu']=$tur;
    $veri=json_encode($r,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $db->prepare('INSERT INTO tutanak_surumleri(tutanak_id,surum,veri,ozet_hash,gerekce,kaydeden,kayit_turu,created_at) VALUES(?,?,?,?,?,?,?,?)')->execute([$id,$n,$veri,hash('sha256',$veri),$gerekce,$_SESSION['username']??$r['kaydeden']??'Saha',$tur,date('Y-m-d H:i:s')]);
    $db->prepare('UPDATE iade_tutanaklari SET belge_surumu=? WHERE id=?')->execute([$n,$id]);return $n;
}
function tsIlk(PDO $db,int $id): void {
    $s=$db->prepare('SELECT belge_surumu FROM iade_tutanaklari WHERE id=?');$s->execute([$id]);
    if((int)$s->fetchColumn()===0)tsKaydet($db,$id,'Eski kaydın geçiş anındaki görüntüsü; geçmişte hiç değişmediği iddia edilmez.','gecis');
}
function tsMusteriGorunumu(array $v): array {
    $sil=fn(array $x)=>array_filter($x,fn($a)=>!str_starts_with((string)$a,'ai_')&&!in_array($a,['istemci_anahtari','kaydeden','personel_id'],true),ARRAY_FILTER_USE_KEY);
    $v=$sil($v);if(is_array($v['karsi']??null))$v['karsi']=$sil($v['karsi']);return $v;
}
function tsKabul(array $b,bool $kagit=false): array {
    $sonuc=(string)($b['kabul_sonucu']??'');
    if($sonuc==='')return ['kabul_sonucu'=>'eski_istemci','kabul_notu'=>'Kabul sonucu eski istemcide ayrı kaydedilmemiş.','fiili_teslim'=>1,'bulundugu_yer'=>null,'sonraki_plan'=>null,'cekince_parca'=>null];
    if(!in_array($sonuc,['teslim_aldi','cekinceli','yetkili_yok','imza_vermedi','teslim_edilemedi'],true))throw new DomainException('Geçerli kabul sonucu seçin.');
    $not=trim((string)($b['kabul_notu']??''));$yer=trim((string)($b['bulundugu_yer']??''));$plan=trim((string)($b['sonraki_plan']??''));$parca=trim((string)($b['cekince_parca']??''));
    $fiili=$b['fiili_teslim']??null;if(!in_array($fiili,[0,1,'0','1'],true))throw new DomainException('Teslimin fiilen gerçekleşip gerçekleşmediğini seçin.');
    $imza=trim((string)($b['imza_musteri']??''));
    if(in_array($sonuc,['teslim_aldi','cekinceli'],true)){
        if(!(int)$fiili||trim((string)($b['musteri_temsilci_ad']??''))==='')throw new DomainException('Kabul için fiili teslim ve teslim alan kişinin adı gerekir.');
        if($kagit){if($imza!=='')throw new DomainException('Kâğıt tutanakta dijital imza gönderilmez; imza kâğıt fotoğrafındadır.');}
        elseif(!preg_match('~^data:image/(png|jpeg);base64,(.+)$~s',$imza,$m)||!($raw=base64_decode($m[2],true))||!@getimagesizefromstring($raw))throw new DomainException('Kabul için geçerli müşteri imzası gerekir.');
    }elseif($imza!=='')throw new DomainException('Bu sonuçta müşteri kabul imzası kaydedilemez. İmza alanını temizleyin.');
    if($sonuc!=='teslim_aldi'&&$not==='')throw new DomainException('Kabul/teslim sonucu açıklaması zorunludur.');
    if($sonuc==='cekinceli'&&$parca==='')throw new DomainException('Çekincenin ilgili olduğu parça veya fotoğraf açısını belirtin.');
    if($sonuc==='teslim_edilemedi'&&((int)$fiili!==0||$yer===''||$plan===''))throw new DomainException('Teslim edilemediyse fiili teslim hayır olmalı; makinenin yeri ve sonraki plan yazılmalıdır.');
    if($kagit)$not='İmza kâğıt tutanak üzerinde (fotoğraf ekte).'.($not!==''?' '.$not:'');
    return ['kabul_sonucu'=>$sonuc,'kabul_notu'=>mb_substr($not,0,3000),'fiili_teslim'=>(int)$fiili,'bulundugu_yer'=>mb_substr($yer,0,500),'sonraki_plan'=>mb_substr($plan,0,1000),'cekince_parca'=>mb_substr($parca,0,500)];
}
function tsKabulYaz(PDO $db,int $id,array $v): void {
    $db->prepare('UPDATE iade_tutanaklari SET kabul_sonucu=?,kabul_notu=?,fiili_teslim=?,bulundugu_yer=?,sonraki_plan=?,cekince_parca=? WHERE id=?')->execute([...array_values($v),$id]);
}
function tsKabulGorevi(PDO $db,int $id): void {
    $r=tsVeri($db,$id);
    if(in_array($r['kabul_sonucu'],['teslim_aldi'],true)){
        $db->prepare("UPDATE gorevler SET durum='tamamlandi',tamamlanma=date('now','localtime') WHERE kaynak_modul='tutanak_kabul' AND kaynak_id=?")->execute([$id]);return;
    }
    $gid=gorevOlustur($db,'Tutanak kabul incelemesi: '.$r['ekipman_adi'],($r['kabul_notu']?:'Kabul yeniden alınmalı.').' · Belge '.($r['belge_no']?:'Teslim alma kaydı #'.$id).' / sürüm '.$r['belge_surumu'],'tutanak_kabul',$id,'normal');
    $db->prepare("UPDATE gorevler SET durum='acik',tamamlanma=NULL,aciklama=?,son_tarih=? WHERE id=?")->execute([($r['kabul_notu']?:'Kabul yeniden alınmalı.').' · '.($r['belge_no']?:'Teslim alma kaydı #'.$id).' / sürüm '.$r['belge_surumu'],date('Y-m-d',time()+86400),$gid]);
}
function tsEtki(PDO $db,array $r): array {
    $s=$db->prepare('SELECT id,kod,baslangic,bitis,gercek_bitis,durum FROM kiralamalar WHERE id=?');$s->execute([$r['kiralama_id']]);$k=$s->fetch();
    $s=$db->prepare('SELECT id,donem_bas,donem_bit,durum FROM hakedisler WHERE kiralama_id=? ORDER BY id');$s->execute([$r['kiralama_id']]);
    return ['kiralama'=>$k,'hakedisler'=>$s->fetchAll(),'kural'=>'Tarih değişikliği otomatik finans güncellemesi yapmaz. İade tarihini onaylamadan önce kiralama bitişi ve hakediş dönemleri aynı tarihle uyumlu olmalıdır.'];
}
function tsTalepGorevi(PDO $db,int $id): void {
    $db->prepare("UPDATE tutanak_duzeltmeleri SET durum='surum_degisti',sonuc_notu='Temel belge sürümü değişti; yeni talep gerekir.',sonuc_zamani=? WHERE tutanak_id=? AND durum='bekliyor' AND temel_surum<(SELECT belge_surumu FROM iade_tutanaklari WHERE id=?)")->execute([date('Y-m-d H:i:s'),$id,$id]);
    $s=$db->prepare("SELECT COUNT(*) FROM tutanak_duzeltmeleri WHERE tutanak_id=? AND durum='bekliyor'");$s->execute([$id]);
    if(!(int)$s->fetchColumn()){
        $db->prepare("UPDATE gorevler SET durum='tamamlandi',tamamlanma=date('now','localtime') WHERE kaynak_modul='tutanak_duzeltme' AND kaynak_id=? AND durum='acik'")->execute([$id]);return;
    }
    $r=tsVeri($db,$id);$gid=gorevOlustur($db,'Belge düzeltme incelemesi: '.$r['belge_no'],'Bekleyen talebin eski/yeni değerlerini ve tarih etkilerini inceleyin.','tutanak_duzeltme',$id);
    $db->prepare('UPDATE gorevler SET son_tarih=COALESCE(son_tarih,?) WHERE id=?')->execute([date('Y-m-d',time()+86400),$gid]);
}
