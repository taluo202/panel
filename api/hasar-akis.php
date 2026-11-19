<?php
declare(strict_types=1);

function hdSema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS hasar_degerlendirmeleri (
        id INTEGER PRIMARY KEY AUTOINCREMENT, tutanak_id INTEGER UNIQUE REFERENCES iade_tutanaklari(id) ON DELETE RESTRICT, depo_kontrol_id INTEGER,
        kiralama_id INTEGER NOT NULL, makine_tur TEXT, makine_id INTEGER, sube TEXT,
        bulgu_turu TEXT NOT NULL, ozet TEXT NOT NULL, kaynak_veri TEXT NOT NULL, kaynak_hash TEXT NOT NULL,
        kaynak_surumu INTEGER NOT NULL DEFAULT 1, durum TEXT NOT NULL DEFAULT 'acik',
        ekip TEXT NOT NULL DEFAULT 'teknik_servis', atanan_id INTEGER, hedef_zaman TEXT NOT NULL,
        oncelik TEXT NOT NULL DEFAULT 'normal', kritik INTEGER NOT NULL DEFAULT 0,
        karar TEXT, gerekce TEXT, ic_not TEXT, musteri_aciklama TEXT,
        onceki_tutanak_id INTEGER, servis_id INTEGER, aksesuar_ad TEXT, aksesuar_miktar REAL,
        gorev_id INTEGER, aksesuar_gorev_id INTEGER, surum INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')), sonuclanma TEXT
    );
    CREATE INDEX IF NOT EXISTS idx_hd_isler ON hasar_degerlendirmeleri(durum,sube,atanan_id,hedef_zaman);
    CREATE TABLE IF NOT EXISTS hasar_degerlendirme_gecmisi (
        id INTEGER PRIMARY KEY AUTOINCREMENT, degerlendirme_id INTEGER NOT NULL REFERENCES hasar_degerlendirmeleri(id) ON DELETE RESTRICT,
        olay TEXT NOT NULL, aciklama TEXT NOT NULL, kullanici_id INTEGER, kullanici TEXT, veri TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );");
    hdSemaA4($db);
    $kol = array_column($db->query('PRAGMA table_info(hasar_degerlendirmeleri)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('depo_kontrol_id', $kol, true)) $db->exec('ALTER TABLE hasar_degerlendirmeleri ADD COLUMN depo_kontrol_id INTEGER');
    $db->exec("CREATE TABLE IF NOT EXISTS hasar_kalemleri (
        id INTEGER PRIMARY KEY AUTOINCREMENT, degerlendirme_id INTEGER NOT NULL REFERENCES hasar_degerlendirmeleri(id) ON DELETE RESTRICT,
        tur TEXT NOT NULL, aciklama TEXT NOT NULL, foto_ids TEXT NOT NULL DEFAULT '[]',
        tutar REAL, kdv_oran REAL, para_birimi TEXT,              -- A4b fiyatlandırma doldurur
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')));
      CREATE INDEX IF NOT EXISTS idx_hk_degerlendirme ON hasar_kalemleri(degerlendirme_id);");
    try { $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_hd_depo_kontrol ON hasar_degerlendirmeleri(depo_kontrol_id) WHERE depo_kontrol_id IS NOT NULL'); }
    catch (\Throwable $e) { error_log('idx_hd_depo_kontrol: ' . $e->getMessage()); }
    hdSemaA4b($db);
}

function hdKalemler(PDO $db, int $id): array {
    $s = $db->prepare('SELECT tur, aciklama, foto_ids FROM hasar_kalemleri WHERE degerlendirme_id=? ORDER BY id'); $s->execute([$id]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

function hdSemaEski(PDO $db): bool {
    $sema = (string)($db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='hasar_degerlendirmeleri'")->fetchColumn() ?: '');
    return (bool)preg_match('/tutanak_id\s+INTEGER\s+NOT\s+NULL/i', $sema);
}

function hdFotoSilmeEngeli(PDO $db, array $foto): ?string {
    $msj = 'Bu fotoğraf tamamlanmış kontrolün/hasar kaydının kanıtıdır; silinemez.';
    $kid = (int)$foto['kiralama_id']; $fid = (int)$foto['id'];
    $s = $db->prepare("SELECT 1 FROM hasar_kalemleri h, json_each(h.foto_ids) j WHERE CAST(j.value AS INTEGER)=? LIMIT 1");
    $s->execute([$fid]);
    if ($s->fetchColumn()) return $msj;
    $s = $db->prepare("SELECT 1 FROM servis_bulgulari b, json_each(b.foto_ids) j WHERE CAST(j.value AS INTEGER)=? LIMIT 1");
    $s->execute([$fid]);
    if ($s->fetchColumn()) return $msj;
    if (($foto['yon'] ?? 'gidis') === 'donus') {
        $s = $db->prepare("SELECT durum FROM depo_kontrolleri WHERE kiralama_id=? AND yon='donus'"); $s->execute([$kid]);
        return in_array($s->fetchColumn(), ['sorunsuz', 'sorunlu'], true) ? $msj : null;
    }
    $s = $db->prepare("SELECT 1 FROM operasyon_adimlari a WHERE a.kiralama_id=? AND a.adim IN ('depo_hazirlik','yukleme') AND a.sonuc='tamam'
                       AND a.id=(SELECT MAX(b.id) FROM operasyon_adimlari b WHERE b.kiralama_id=a.kiralama_id AND b.adim=a.adim) LIMIT 1");
    $s->execute([$kid]);
    return $s->fetchColumn() ? $msj : null;
}

function hdSemaA4(PDO $db): void {
    $desen = '/tutanak_id\s+INTEGER\s+NOT\s+NULL\s+UNIQUE/i';
    $semaOku = fn() => (string)($db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='hasar_degerlendirmeleri'")->fetchColumn() ?: '');
    $sema = $semaOku();
    if (!preg_match($desen, $sema) || $db->inTransaction()) return;
    $fk = (int)$db->query('PRAGMA foreign_keys')->fetchColumn();
    $db->exec('PRAGMA foreign_keys = OFF');
    try {
        $db->beginTransaction();
        islemKilitAl($db);
        if ($semaOku() !== $sema) throw new \RuntimeException('__zaten__');
        $yeni = preg_replace('/^CREATE TABLE\s+("?)hasar_degerlendirmeleri\1/i', 'CREATE TABLE hasar_degerlendirmeleri_a4',
                             preg_replace($desen, 'tutanak_id INTEGER UNIQUE', $sema, 1));
        $kol = implode(',', array_map(fn($c) => '"' . $c['name'] . '"', $db->query('PRAGMA table_info(hasar_degerlendirmeleri)')->fetchAll(PDO::FETCH_ASSOC)));
        $ix = $db->query("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='hasar_degerlendirmeleri' AND sql IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
        $db->exec('DROP TABLE IF EXISTS hasar_degerlendirmeleri_a4');
        $db->exec($yeni);
        $db->exec("INSERT INTO hasar_degerlendirmeleri_a4 ($kol) SELECT $kol FROM hasar_degerlendirmeleri");
        $db->exec('DROP TABLE hasar_degerlendirmeleri');
        $db->exec('ALTER TABLE hasar_degerlendirmeleri_a4 RENAME TO hasar_degerlendirmeleri');
        foreach ($ix as $s) $db->exec($s);
        $db->commit();
    } catch (\Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($e->getMessage() !== '__zaten__') error_log('hasar_degerlendirmeleri A4 yeniden kurulumu: ' . $e->getMessage());
    } finally {
        $db->exec('PRAGMA foreign_keys = ' . ($fk ? 'ON' : 'OFF'));
    }
}

function hdSemaA4b(PDO $db): void {
    $kol = array_column($db->query('PRAGMA table_info(hasar_degerlendirmeleri)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['fiyat_durum TEXT', 'fatura_yontemi TEXT', 'hakedis_id INTEGER', 'hakedis_kalem_id INTEGER', 'fatura_kalem_id INTEGER',
              'fiyat_zaman TEXT', 'fiyatlayan TEXT', 'fiyat_gorev_id INTEGER'] as $tanim) {
        if (!in_array(strtok($tanim, ' '), $kol, true)) $db->exec('ALTER TABLE hasar_degerlendirmeleri ADD COLUMN ' . $tanim);
    }
    $db->exec("UPDATE hasar_degerlendirmeleri SET fiyat_durum='bekliyor' WHERE depo_kontrol_id IS NOT NULL AND fiyat_durum IS NULL");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_hd_fiyat ON hasar_degerlendirmeleri(fiyat_durum);
      CREATE TABLE IF NOT EXISTS hasar_faturalari (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        degerlendirme_id INTEGER NOT NULL UNIQUE REFERENCES hasar_degerlendirmeleri(id) ON DELETE RESTRICT,
        kiralama_id INTEGER NOT NULL, musteri_id INTEGER NOT NULL, sube TEXT, ref TEXT NOT NULL,
        para_birimi TEXT NOT NULL,
        kalemler TEXT NOT NULL,                       -- JSON anlık görüntü [{kalem_id,aciklama,tutar,kdv_oran,net,kdv}] (4c HBB)
        ara_toplam REAL NOT NULL, kdv_tutar REAL NOT NULL, genel_toplam REAL NOT NULL,   -- para biriminde
        kur REAL, kur_tarihi TEXT, tl_ara REAL, tl_kdv REAL, tl_genel REAL,               -- faturalandı işaretlenince
        faturalandi INTEGER NOT NULL DEFAULT 0, fatura_no TEXT, fatura_tarihi TEXT, fatura_tipi TEXT,
        fatura_durum TEXT, fatura_hata_notu TEXT, vade_tarihi TEXT,
        created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
      CREATE INDEX IF NOT EXISTS idx_hf_musteri ON hasar_faturalari(musteri_id, faturalandi);");
}
function hdGecmis(PDO $db, int $id, string $olay, string $aciklama, array $veri=[]): void {
    $db->prepare('INSERT INTO hasar_degerlendirme_gecmisi(degerlendirme_id,olay,aciklama,kullanici_id,kullanici,veri) VALUES(?,?,?,?,?,?)')
        ->execute([$id,$olay,$aciklama,$_SESSION['user_id']??null,$_SESSION['username']??'Saha kaydı',json_encode($veri,JSON_UNESCAPED_UNICODE)]);
}
function hdKaynak(PDO $db,int $tid): ?array {
    $st=$db->prepare('SELECT t.*,k.makine_tur,k.makine_id,k.sube,k.ekipman_adi,k.kod AS kiralama_kod FROM iade_tutanaklari t JOIN kiralamalar k ON k.id=t.kiralama_id WHERE t.id=?');
    $st->execute([$tid]);return $st->fetch()?:null;
}
function hdJson($v): array {return is_array($v)?$v:(json_decode((string)$v,true)?:[]);}
function hdGorevSenkron(PDO $db,array $r): void {
    $kapali=in_array($r['durum'],['sonuclandi','iptal','aksesuar_bekliyor','tasindi'],true);
    $db->prepare("UPDATE gorevler SET atanan_id=?,son_tarih=?,oncelik=?,durum=?,tamamlanma=?,aciklama=?,updated_at=datetime('now','localtime') WHERE id=?")
        ->execute([$r['atanan_id'],substr($r['hedef_zaman'],0,10),$r['oncelik'],$kapali?'tamamlandi':'acik',$kapali?date('Y-m-d'):null,($r['atanan_id']?'Sorumlu atandı. ':'Atama bekliyor. ').$r['ozet'],$r['gorev_id']]);
    if (!empty($r['aksesuar_gorev_id'])) {
        $bekliyor=$r['durum']==='aksesuar_bekliyor';
        $db->prepare("UPDATE gorevler SET atanan_id=?,son_tarih=?,durum=?,tamamlanma=?,updated_at=datetime('now','localtime') WHERE id=?")
            ->execute([$r['atanan_id'],substr($r['hedef_zaman'],0,10),$bekliyor?'acik':'tamamlandi',$bekliyor?null:date('Y-m-d'),$r['aksesuar_gorev_id']]);
    }
}
function hdGet(PDO $db,int $id): ?array {
    $s=$db->prepare('SELECT * FROM hasar_degerlendirmeleri WHERE id=?');$s->execute([$id]);return $s->fetch()?:null;
}
function hdTutanakServisSenkron(PDO $db,int $tid): ?int {
    require_once __DIR__.'/servis-kalem-lib.php';
    require_once __DIR__.'/belge-zinciri-lib.php';
    $t=hdKaynak($db,$tid);if(!$t||$t['islem']!=='iade')return null;
    if(opYeniZincir($db,['id'=>(int)$t['kiralama_id']]))return null;
    $b=hdJson($t['bilesen_hasar']);$kod=hdJson($t['ariza_kodlari']);
    $sayac=[];
    $s=$db->prepare("SELECT id,km,calisma_saati FROM iade_tutanaklari WHERE kiralama_id=? AND islem='teslim' AND COALESCE(fiili_teslim,1)=1 AND tarih<=? AND id<>? ORDER BY tarih DESC,id DESC LIMIT 1");$s->execute([$t['kiralama_id'],$t['tarih'],$tid]);$eskiT=$s->fetch();
    if($eskiT)foreach(['km'=>'Kilometre','calisma_saati'=>'Çalışma saati'] as $alan=>$ad){
        if($t[$alan]!==null&&$eskiT[$alan]!==null&&(float)$t[$alan]<(float)$eskiT[$alan])$sayac[]=$ad.': teslim '.$eskiT[$alan].', iade '.$t[$alan].' (teslim tutanağı #'.$eskiT['id'].')';
    }
    $aksesuarFark=hzTutanakFark($db,$t);
    $eksikAks=array_values(array_filter(hdJson($t['aksesuarlar']),fn($a)=>is_array($a)&&($a['durum']??'')==='eksik'&&trim((string)($a['ad']??''))!==''));
    $etiket=['hasarsiz'=>'Hasarsız','az_hasarli'=>'Az hasarlı','cok_hasarli'=>'Çok hasarlı'];
    $bulgular=[];$eksikler=[];
    if(($t['genel_durum']??'saglam')==='hasarli'||trim((string)($t['hasar_notu']??''))!=='')$bulgular[]=['tur'=>'hasar','aciklama'=>trim((string)($t['hasar_notu']??''))?:'Tutanakta hasarlı işaretlendi'];
    foreach($b as $ad=>$durum)if($durum!=='hasarsiz')$bulgular[]=['tur'=>'hasar','aciklama'=>(BZ_BILESEN_AD[$ad]??str_replace('_',' ',(string)$ad)).': '.($etiket[$durum]??$durum)];
    foreach($kod as $k)$bulgular[]=['tur'=>'ariza_kodu','aciklama'=>'Arıza kodu: '.(is_array($k)?(string)($k['kod']??'Belirtilmedi'):(string)$k)];
    foreach($sayac as $u)$bulgular[]=['tur'=>'sayac','aciklama'=>$u];
    foreach($aksesuarFark as $f){
        $bulgular[]=['tur'=>'aksesuar','aciklama'=>$f['ad'].': verilen '.($f['verilen']??'—').', dönen '.($f['alinan']??'—').(trim((string)($f['not']??''))!==''?' — '.$f['not']:''),'adet'=>is_int($f['fark'])&&$f['fark']>0?(float)$f['fark']:null];
        if(is_int($f['fark'])&&$f['fark']>0)$eksikler[]=['ad'=>(string)$f['ad'],'adet'=>(float)$f['fark']];
    }
    foreach($eksikAks as $a){$bulgular[]=['tur'=>'aksesuar','aciklama'=>$a['ad'].': eksik','adet'=>1.0];$eksikler[]=['ad'=>(string)$a['ad'],'adet'=>1.0];}
    $st=$db->prepare('SELECT * FROM teknik_servis WHERE tutanak_id=? ORDER BY id DESC LIMIT 1');$st->execute([$tid]);$sv=$st->fetch();
    if(!$bulgular)return $sv?(int)$sv['id']:null;
    if(!$sv){$eh=$db->prepare("SELECT id FROM hasar_degerlendirmeleri WHERE tutanak_id=? AND COALESCE(durum,'')<>'tasindi' LIMIT 1");$eh->execute([$tid]);if($eh->fetchColumn())return null;}
    $yalnizEksik=!array_filter($bulgular,fn($x)=>$x['tur']!=='aksesuar');
    $tur=(string)$t['makine_tur'];$mid=(int)$t['makine_id'];$kod_=$t['kiralama_kod']?:'#'.$t['kiralama_id'];
    $metin='İade tutanağı '.($t['belge_no']?:'#'.$tid).' ('.$kod_.') bulguları:'."\n• ".implode("\n• ",array_column($bulgular,'aciklama'));
    $simdi=date('Y-m-d H:i:s');
    if(!$sv){
        if(!in_array($tur,['arac','manlift'],true)||$mid<1)return null;
        $kritik=in_array('cok_hasarli',$b,true);
        $baskaIste=!$yalnizEksik&&makineKiradaMi($db,$tur,$mid);
        $db->prepare("INSERT INTO teknik_servis(makine_tur,makine_id,makine_ad,tip,tarih,durum,oncelik,ariza,tutanak_id,kiralama_id,kaynak,musteriye_yansit,maliyet_kaynak,makine_baska_iste,tamamlanma,created_at,updated_at)
                      VALUES(?,?,?,?,?,?,?,?,?,?,'donus_kontrolu',1,'kalem',?,?,?,?)")
           ->execute([$tur,$mid,$t['ekipman_adi'],$yalnizEksik?'eksik_parca':'ariza',date('Y-m-d'),$yalnizEksik?'tamamlandi':'acik',$kritik?'yuksek':'normal',$metin,$tid,(int)$t['kiralama_id'],(int)$baskaIste,$yalnizEksik?date('Y-m-d'):null,$simdi,$simdi]);
        $sid=(int)$db->lastInsertId();
        if(!$yalnizEksik&&!$baskaIste)teknikServisMakineDurumSenkron($db,$tur,$mid,'acik','ariza');
        if(!$yalnizEksik)gorevOlustur($db,($baskaIste?'Makine '.($t['ekipman_adi']?:'#'.$mid).' geri dönünce onarılmalı — servis #'.$sid:'Servis: '.($t['ekipman_adi']?:'Makine').' (iade tutanağı)'),$metin,'teknik_servis',$sid,$kritik?'yuksek':'normal');
        logla($db,'teknik_servis',$sid,'ekle',null,['kaynak'=>'donus_kontrolu','tutanak_id'=>$tid,'bulgu'=>count($bulgular),'eksik'=>count($eksikler)],'İade tutanağından servis açıldı — '.$kod_);
    }else{
        $sid=(int)$sv['id'];
        if($sv['durum']==='tamamlandi'){
            $db->prepare("UPDATE teknik_servis SET notlar=TRIM(COALESCE(notlar,'') || char(10) || ?), updated_at=? WHERE id=?")->execute(['['.date('d.m.Y H:i').'] Tutanak düzeltildi; bulgular değişti — kontrol edin.',$simdi,$sid]);
            gorevOlustur($db,'Tutanak düzeltildi: servis #'.$sid.' bulgularını kontrol edin',$metin,'teknik_servis',$sid);
            logla($db,'teknik_servis',$sid,'guncelle',null,['tutanak_id'=>$tid],'Tutanak düzeltildi; tamamlanmış servise not — '.$kod_);
            return $sid;
        }
        $db->prepare('DELETE FROM servis_bulgulari WHERE servis_id=? AND tutanak_id=?')->execute([$sid,$tid]);
        if(!in_array($sv['fiyat_durum']??'',['fiyatlandi','ucretsiz'],true))$db->prepare("DELETE FROM teknik_servis_kalemleri WHERE servis_id=? AND kaynak='eksik'")->execute([$sid]);
        $db->prepare("UPDATE teknik_servis SET ariza=?, updated_at=? WHERE id=?")->execute([$metin,$simdi,$sid]);
        logla($db,'teknik_servis',$sid,'guncelle',null,['tutanak_id'=>$tid,'bulgu'=>count($bulgular)],'Tutanak düzeltildi; servis bulguları yenilendi — '.$kod_);
    }
    foreach($bulgular as $x)tsBulguYaz($db,$sid,'tutanak',['tur'=>$x['tur'],'aciklama'=>$x['aciklama'],'adet'=>$x['adet']??null,'tutanak_id'=>$tid]);
    foreach($eksikler as $e)tsEksikKalemYaz($db,$sid,$e['ad'],$e['adet'],null);
    $f=$db->prepare('SELECT dosya_yolu FROM iade_tutanak_fotograflari WHERE tutanak_id=?');$f->execute([$tid]);
    $var=$db->prepare('SELECT 1 FROM teknik_servis_fotograflari WHERE servis_id=? AND dosya_yolu=? LIMIT 1');
    $ins=$db->prepare("INSERT INTO teknik_servis_fotograflari(servis_id,dosya_yolu,kaynak,created_at) VALUES(?,?,'donus_kontrolu',?)");
    foreach($f->fetchAll(PDO::FETCH_COLUMN) as $yol){$var->execute([$sid,$yol]);if(!$var->fetchColumn())$ins->execute([$sid,$yol,$simdi]);}
    if(!$sv&&$yalnizEksik)tsTamamlandi($db,$sid);
    return $sid;
}
function hdTutanakSenkron(PDO $db,int $tid): ?int {
    $t=hdKaynak($db,$tid);if(!$t)return null;
    $b=hdJson($t['bilesen_hasar']);$kod=hdJson($t['ariza_kodlari']);
    $sayac=[];
    if($t['islem']==='iade'){
        $s=$db->prepare("SELECT id,km,calisma_saati FROM iade_tutanaklari WHERE kiralama_id=? AND islem='teslim' AND COALESCE(fiili_teslim,1)=1 AND tarih<=? AND id<>? ORDER BY tarih DESC,id DESC LIMIT 1");$s->execute([$t['kiralama_id'],$t['tarih'],$tid]);$eski=$s->fetch();
        if($eski)foreach(['km'=>'Kilometre','calisma_saati'=>'Çalışma saati'] as $alan=>$ad){
            if($t[$alan]!==null&&$eski[$alan]!==null&&(float)$t[$alan]<(float)$eski[$alan])$sayac[]=$ad.': teslim '.$eski[$alan].', iade '.$t[$alan].' (teslim tutanağı #'.$eski['id'].')';
        }
    }
    $aksesuarFark=hzTutanakFark($db,$t);
    $hasar=count($aksesuarFark)>0||($t['genel_durum']??'saglam')!=='saglam'||count(array_filter($b,fn($d)=>$d!=='hasarsiz'))>0||count($kod)>0||count($sayac)>0;
    $st=$db->prepare('SELECT * FROM hasar_degerlendirmeleri WHERE tutanak_id=?');$st->execute([$tid]);$r=$st->fetch();
    if(!$hasar&&!$r)return null;
    if(!$r&&$t['islem']==='iade'&&opYeniZincir($db,['id'=>(int)$t['kiralama_id']]))return null;
    $snap=array_intersect_key($t,array_flip(['belge_surumu','tarih','islem','genel_durum','hasar_notu','bilesen_hasar','ariza_kodlari','aksesuarlar','km','calisma_saati','makine_tur','makine_id']));
    $snap['sayac_uyari']=$sayac;
    $snap['aksesuar_fark']=$aksesuarFark;
    $json=json_encode($snap,JSON_UNESCAPED_UNICODE);$hash=hash('sha256',$json);
    if($r&&$r['kaynak_hash']===$hash)return (int)$r['id'];
    $kritik=in_array('cok_hasarli',$b,true);$oncelik=$kritik?'yuksek':'normal';
    $tur=$t['genel_durum']==='eksik_aksesuar'?'aksesuar':($sayac&&$t['genel_durum']==='saglam'?'sayac':($kod&&$t['genel_durum']==='saglam'?'ariza_kodu':'hasar'));
    $ozet=($t['belge_no']?:'Tutanak #'.$tid).' · '.($t['ekipman_adi']?:'Makine').' · '.($t['hasar_notu']?:'Kontrol bulguları incelenecek');
    if($aksesuarFark)$ozet.=' · Aksesuar sayım farkı: '.count($aksesuarFark).' kalem';
    if($sayac)$ozet.=' · Sayaç farkı: '.implode('; ',$sayac);
    $hedef=date('Y-m-d H:i:s',time()+($kritik?4:24)*3600);
    if(!$r){
        $db->prepare('INSERT INTO hasar_degerlendirmeleri(tutanak_id,kiralama_id,makine_tur,makine_id,sube,bulgu_turu,ozet,kaynak_veri,kaynak_hash,hedef_zaman,oncelik,kritik) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$tid,$t['kiralama_id'],$t['makine_tur'],$t['makine_id'],$t['sube'],$tur,$ozet,$json,$hash,$hedef,$oncelik,(int)$kritik]);
        $id=(int)$db->lastInsertId();
        $gid=gorevOlustur($db,'Hasar/eksik değerlendirmesi: '.($t['ekipman_adi']?:'Makine'),'Atama bekliyor. '.$ozet,'hasar_degerlendirme',$id,$oncelik);
        $db->prepare('UPDATE hasar_degerlendirmeleri SET gorev_id=? WHERE id=?')->execute([$gid,$id]);
        hdGecmis($db,$id,'olustu','Tutanak bulgularından otomatik değerlendirme açıldı.',$snap);
    }else{
        $id=(int)$r['id'];
        $db->prepare("UPDATE hasar_degerlendirmeleri SET kaynak_veri=?,kaynak_hash=?,kaynak_surumu=kaynak_surumu+1,surum=surum+1,
            ozet=?,bulgu_turu=?,kritik=?,oncelik=?,durum='acik',karar=NULL,gerekce=NULL,sonuclanma=NULL,hedef_zaman=?,updated_at=datetime('now','localtime') WHERE id=?")
            ->execute([$json,$hash,$ozet,$tur,(int)$kritik,$oncelik,$hedef,$id]);
        hdGecmis($db,$id,'kaynak_degisti','Tutanak bulguları değişti; yeniden değerlendirme gerekiyor.',$snap);
    }
    hdGorevSenkron($db,hdGet($db,$id));return $id;
}
function hdArsivIslemleri(array $r, bool $ticari = true): array {
    require_once __DIR__.'/servis-kalem-lib.php';
    if (tsHasarGocKapsami($r) !== 'kapanis') return [];
    $fy = (string)($r['fatura_yontemi'] ?? '');
    $bedelBagli = $fy !== '' || !empty($r['hakedis_id']) || !empty($r['fatura_kalem_id']) || in_array((string)($r['fiyat_durum'] ?? ''), ['fiyatlandi', 'faturalandi'], true);
    if ($bedelBagli && !$ticari) return [];
    $out = ['kapat'];
    if ($fy === 'hakedis') $out[] = 'hakediste_kaldi';
    if ($fy === 'ayri' && ($r['fiyat_durum'] ?? '') !== 'faturalandi') $out[] = 'faturala';
    if (($r['durum'] ?? '') === 'ertelendi' && !$bedelBagli) $out[] = 'servise_tasi';
    return $out;
}
function hdKapanisUygula(PDO $db, array $r, string $islem, string $gerekce, bool $ticari = true): array {
    $id = (int)$r['id'];
    if (!in_array($islem, hdArsivIslemleri($r), true) || $islem === 'faturala') throw new DomainException('Bu kayıt arşivde; bu işlem yapılamaz.');
    $bedelBagli = !empty($r['fatura_yontemi']) || !empty($r['hakedis_id']) || !empty($r['fatura_kalem_id']) || in_array((string)($r['fiyat_durum'] ?? ''), ['fiyatlandi', 'faturalandi'], true);
    if ($islem !== 'servise_tasi' && $bedelBagli && !$ticari) throw new DomainException('Bedel bağlı kaydın kapanışı için kiralama yazma yetkisi gerekir.');
    $kilit = $db->prepare('UPDATE hasar_degerlendirmeleri SET surum=surum+1 WHERE id=? AND surum=?'); $kilit->execute([$id, (int)($r['surum'] ?? 0)]);
    if ($kilit->rowCount() !== 1) throw new DomainException('Bu kayıt başka bir işlemle değişti. Yenileyip tekrar deneyin.');
    if ($islem === 'servise_tasi') {
        $sid = tsHasarTasi($db, $r);
        if ($gerekce !== '') hdGecmis($db, $id, 'arsiv_tasi', $gerekce, ['servis_id' => $sid]);
        return ['id' => $id, 'durum' => 'tasindi', 'servis_id' => $sid, 'mesaj' => 'Kayıt servis kaydı #' . $sid . '\'e taşındı; Teknik Servis\'ten ilerleyin.'];
    }
    if ($islem === 'kapat' && mb_strlen($gerekce) < 5) throw new DomainException('Kapatma gerekçesini yazın (en az 5 karakter).');
    if ($islem === 'hakediste_kaldi') {
        $q = $db->prepare('SELECT kod FROM hakedisler WHERE id=?'); $q->execute([(int)($r['hakedis_id'] ?? 0)]);
        $kod = (string)($q->fetchColumn() ?: ('#' . (int)($r['hakedis_id'] ?? 0)));
        $gerekce = 'Bedel hakedişte kaldı (' . $kod . ').';
    }
    $simdi = date('Y-m-d H:i:s');
    $db->prepare("UPDATE hasar_degerlendirmeleri SET durum='sonuclandi', sonuclanma=?, updated_at=? WHERE id=?")->execute([$simdi, $simdi, $id]);
    $db->prepare("UPDATE gorevler SET durum='tamamlandi', tamamlanma=?, updated_at=? WHERE kaynak_modul IN ('hasar_degerlendirme','hasar_aksesuar','hasar_fiyat','hasar_indirim') AND kaynak_id=? AND durum='acik'")->execute([date('Y-m-d'), $simdi, $id]);
    hdGecmis($db, $id, 'arsiv_kapat', $gerekce, ['islem' => $islem]);
    logla($db, 'hasar_degerlendirmeleri', $id, 'guncelle', ['durum' => $r['durum']], ['durum' => 'sonuclandi'], 'Arşiv kapanışı — ' . $gerekce);
    return ['id' => $id, 'durum' => 'sonuclandi', 'servis_id' => !empty($r['servis_id']) ? (int)$r['servis_id'] : null, 'mesaj' => 'Kayıt kapatıldı.'];
}
function hdGorunur(array $r): bool {
    $yetki=hasPermission('teknik_servis','oku')||hasPermission('kiralama_takip','oku');
    $kendi=!empty($_SESSION['personel_id'])&&(int)$r['atanan_id']===(int)$_SESSION['personel_id'];
    $sube=subeKisitiHam();return ($yetki||$kendi)&&($sube===null||$sube===($r['sube']??''));
}
function hdServisTeknisyeni(PDO $db,int $sid): int {
    $s=$db->prepare('SELECT teknisyen_id FROM teknik_servis WHERE id=?');$s->execute([$sid]);return (int)$s->fetchColumn();
}
function hdServisSubeUygun(PDO $db,int $sid): bool {
    $k=subeKisitiHam();if($k===null)return true;
    require_once __DIR__.'/saha-servis-lib.php';$sb=_ssServisSubesi($db,$sid);return $sb===''||$sb===$k;
}
function hdGorevMaskele(PDO $db,array $g): array {
    if(!in_array($g['kaynak_modul']??'',['teknik_servis','servis_getir'],true))return $g;
    if(subeKisitiHam()===null)return $g;
    $pid=(int)($_SESSION['personel_id']??0);
    if($pid>0&&hdServisTeknisyeni($db,(int)$g['kaynak_id'])===$pid)return $g;
    if(!hdServisSubeUygun($db,(int)$g['kaynak_id']))$g['aciklama']=null;
    return $g;
}
function hdGorevGorunur(PDO $db,array $g): bool {
    $km=(string)($g['kaynak_modul']??'');
    if($km==='')return true;
    if(($_SESSION['rol']??'')==='admin')return true;
    $pid=(int)($_SESSION['personel_id']??0);
    if($pid>0&&(int)($g['atanan_id']??0)===$pid)return true;
    if(in_array($km,['teknik_servis','servis_getir'],true)&&$pid>0&&hdServisTeknisyeni($db,(int)$g['kaynak_id'])===$pid)return true;
    if(in_array($km,['servis_getir','servis_rapor'],true))return hasPermission('teknik_servis','oku')||hasPermission('kiralama_takip','oku');
    if(($g['kaynak_modul']??'')==='teklif_karar'){
        $s=$db->prepare('SELECT sube FROM teklifler WHERE id=?');$s->execute([$g['kaynak_id']]);$r=$s->fetch();$sube=subeKisitiHam();
        return $r&&hasPermission('kiralama_takip','oku')&&($sube===null||$sube===trim((string)$r['sube']));
    }
    if(in_array($g['kaynak_modul']??'', ['portal_uzatma','portal_iade','portal_toplama'],true)){
        $s=$db->prepare('SELECT k.sube FROM portal_kiralama_talepleri t JOIN kiralamalar k ON k.id=t.kiralama_id WHERE t.id=?');$s->execute([$g['kaynak_id']]);$r=$s->fetch();$sube=subeKisitiHam();
        return $r&&hasPermission('kiralama_takip','oku')&&($sube===null||$sube===trim((string)$r['sube']));
    }
    if(in_array($g['kaynak_modul']??'', ['tutanak_kabul','tutanak_duzeltme','tutanak_gonderim'],true)){
        $s=$db->prepare('SELECT k.sube FROM iade_tutanaklari t JOIN kiralamalar k ON k.id=t.kiralama_id WHERE t.id=?');$s->execute([$g['kaynak_id']]);$r=$s->fetch();$sube=subeKisitiHam();
        return $r&&hasPermission('kiralama_takip','oku')&&($sube===null||$sube===$r['sube']);
    }
    if(in_array($g['kaynak_modul']??'',['hasar_fiyat','hasar_indirim'],true)){
        $r=hdGet($db,(int)$g['kaynak_id']);$sube=subeKisitiHam();
        return $r&&hasPermission('kiralama_takip','yaz')&&($sube===null||$sube===($r['sube']??''));
    }
    if(($g['kaynak_modul']??'')==='servis_talep'){if(!hasPermission('teknik_servis','oku'))return false;require_once __DIR__.'/servis-talep-lib.php';$t=stTalep($db,(int)$g['kaynak_id']);return $t&&stSubeIzinli($t);}
    if($km==='servis_fiyat'){
        require_once __DIR__.'/servis-maliyet-lib.php';
        if(!smFiyatYetkisi($db)&&!hasPermission('kiralama_takip','yaz'))return false;
        return hdServisVar($db,(int)$g['kaynak_id'])&&hdServisSubeUygun($db,(int)$g['kaynak_id']);
    }
    if($km==='servis_indirim'){
        return hasPermission('kiralama_takip','yaz')&&hdServisVar($db,(int)$g['kaynak_id'])&&hdServisSubeUygun($db,(int)$g['kaynak_id']);
    }
    if(($g['kaynak_modul']??'')==='teknik_servis'){
        $s=$db->prepare('SELECT * FROM hasar_degerlendirmeleri WHERE servis_id=?');$s->execute([$g['kaynak_id']]);$r=$s->fetch();
        if($r)return hdGorunur($r);
        return hasPermission('teknik_servis','oku');
    }
    if(in_array($km,['kiralama','operator_bekliyor','toplama_gecikti','bakim_cakismasi','toplama_bildirim','yola_cikti_bildirim','nakliye_plan_disi','hakedis_kontrol','kiralama_protokol'],true)||str_starts_with($km,'depo_')){
        $s=$db->prepare('SELECT sube FROM kiralamalar WHERE id=?');$s->execute([$g['kaynak_id']]);$r=$s->fetch();$sube=subeKisitiHam();
        return $r&&hasPermission('kiralama_takip','oku')&&($sube===null||$sube===trim((string)($r['sube']??'')));
    }
    if(in_array($km,['hakedis','tahsilat_gecikti','fatura_bekliyor'],true)){
        $s=$db->prepare('SELECT '.hakedisSubeSql('h','m').' AS sube FROM hakedisler h LEFT JOIN musteriler m ON m.id=h.musteri_id WHERE h.id=?');$s->execute([$g['kaynak_id']]);$r=$s->fetch();$sube=subeKisitiHam();
        return $r&&hasPermission('kiralama_takip','oku')&&($sube===null||$sube===trim((string)($r['sube']??'')));
    }
    if(($g['kaynak_modul']??'')==='musteri_talep'){
        $s=$db->prepare('SELECT m.sube FROM musteri_talepleri t JOIN musteriler m ON m.id=t.musteri_id WHERE t.id=?');$s->execute([$g['kaynak_id']]);$r=$s->fetch();$sube=subeKisitiHam();
        return $r&&hasPermission('kiralama_takip','oku')&&($sube===null||$sube===trim((string)($r['sube']??'')));
    }
    if(in_array($km,['hakedis_eksik','fiyat_eksik','nakliye_faturasiz','servis_faturasiz','servis_ucretsiz','hasar_fatura_bekliyor','hasar_tahsilat_gecikti','aktarim_kira','aktarim_servis','aktarim_hasar','dk_iade_bekliyor','doviz_kur_eksik'],true)){require_once __DIR__.'/zincir-lib.php';return zincirGorevGorunur($db,$g);}
    if($km==='satinalma')return hasPermission('satinalma','oku');
    if(!in_array($km, ['hasar_degerlendirme','hasar_aksesuar'],true))return false;
    $r=hdGet($db,(int)$g['kaynak_id']);return $r&&hdGorunur($r);
}
function hdAksesuarYetki(array $r): bool {
    return $r['durum']==='aksesuar_bekliyor'&&!empty($_SESSION['personel_id'])
        &&(int)$r['atanan_id']===(int)$_SESSION['personel_id']&&hasPermission('satinalma','yaz');
}
function hdServisVar(PDO $db,int $sid): bool {
    $s=$db->prepare('SELECT 1 FROM teknik_servis WHERE id=?');$s->execute([$sid]);return (bool)$s->fetchColumn();
}
function hdKilitliGorev(PDO $db,array $g): bool {
    if(in_array($g['kaynak_modul']??'', ['portal_uzatma','portal_iade'],true))return true;
    if(in_array($g['kaynak_modul']??'', ['tutanak_kabul','tutanak_duzeltme'],true))return true;
    if(in_array($g['kaynak_modul']??'', ['hasar_degerlendirme','hasar_aksesuar','hasar_fiyat','hasar_indirim','servis_indirim','servis_talep'],true))return true;
    if(($g['kaynak_modul']??'')!=='teknik_servis')return false;
    $s=$db->prepare('SELECT 1 FROM hasar_degerlendirmeleri WHERE servis_id=?');$s->execute([$g['kaynak_id']]);return (bool)$s->fetchColumn();
}
function hdServisAc(PDO $db,array $r,string $gerekce): int {
    if(empty($r['tutanak_id'])){
        if(!empty($r['servis_id']))return (int)$r['servis_id'];
        throw new DomainException('Bu kayıt dönüş kontrolünden açıldı; servis kaydı incelemeyle açılmıştır.');
    }
    $s=$db->prepare('SELECT id,durum FROM teknik_servis WHERE tutanak_id=? ORDER BY id DESC LIMIT 1');$s->execute([$r['tutanak_id']]);
    if($eski=$s->fetch()){
        if($eski['durum']==='tamamlandi')throw new DomainException('Önceki servis kapanmış. Yeni hasarı yeni tutanakla kaydedin; kapanmış servis kendiliğinden yeniden açılmaz.');
        return (int)$eski['id'];
    }
    if(!in_array($r['makine_tur'],['arac','manlift'],true)||!(int)$r['makine_id'])throw new DomainException('Kiralama bir makineye bağlı değil. Önce makine bağlantısını düzeltin.');
    $t=hdKaynak($db,(int)$r['tutanak_id']);if(!$t)throw new DomainException('Kaynak tutanak bulunamadı.');
    $bilesen=[];$etiket=['hasarsiz'=>'Hasarsız','az_hasarli'=>'Az hasarlı','cok_hasarli'=>'Çok hasarlı'];
    foreach(hdJson($t['bilesen_hasar']) as $ad=>$durum)$bilesen[]=str_replace('_',' ',$ad).': '.($etiket[$durum]??$durum);
    $kodlar=array_map(fn($k)=>is_array($k)?($k['kod']??'Belirtilmedi'):(string)$k,hdJson($t['ariza_kodlari']));
    $parts=[$r['ozet'],'Değerlendirme: '.$gerekce,'Bileşenler: '.($bilesen?implode(', ',$bilesen):'Belirtilmedi'),'Arıza kodları: '.($kodlar?implode(', ',$kodlar):'Belirtilmedi')];
    $db->prepare("INSERT INTO teknik_servis(makine_tur,makine_id,makine_ad,tip,tarih,durum,oncelik,ariza,tutanak_id,kiralama_id,teknisyen_id,kaynak) VALUES(?,?,?,'ariza',?,'acik',?,?,?,?,?,'donus_kontrolu')")
        ->execute([$r['makine_tur'],$r['makine_id'],$t['ekipman_adi'],date('Y-m-d'),$r['oncelik'],implode("\n",$parts),$r['tutanak_id'],$r['kiralama_id'],$r['atanan_id']]);
    $id=(int)$db->lastInsertId();
    teknikServisMakineDurumSenkron($db,$r['makine_tur'],(int)$r['makine_id'],'acik');
    $gid=gorevOlustur($db,'Servis: '.($t['ekipman_adi']?:'Makine'),implode("\n",$parts),'teknik_servis',$id,$r['oncelik']);
    $db->prepare('UPDATE gorevler SET atanan_id=?,son_tarih=? WHERE id=?')->execute([$r['atanan_id'],substr($r['hedef_zaman'],0,10),$gid]);
    logla($db,'teknik_servis',$id,'ekle',null,null,'Değerlendirme #'.$r['id'].' üzerinden servis açıldı.');return $id;
}
