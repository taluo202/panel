<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/filo-yas.php';
require_once __DIR__.'/filo-inceleme.php';
require_once __DIR__.'/filo-gorsel.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('rapor');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonError('Method Not Allowed', 405);

$db = getDb();

$fSube  = trim(metin($_GET['sube'] ?? ''));
$fSube = subeKisiti() ?? $fSube;
$fTemin = trim(metin($_GET['temin_sekli'] ?? ''));
$fTur   = trim(metin($_GET['makine_tur'] ?? ''));
$fYakit = trim(metin($_GET['yakit_tipi'] ?? ''));
$fAna   = trim(metin($_GET['ana_grup'] ?? ''));
$fAlt   = trim(metin($_GET['alt_grup'] ?? ''));
$raporBas = preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($_GET['bas']??'')) ? $_GET['bas'] : date('Y-01-01');
$raporBit = preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($_GET['bit']??'')) ? $_GET['bit'] : date('Y-m-d');
foreach([$raporBas,$raporBit] as $tarih){$d=DateTimeImmutable::createFromFormat('!Y-m-d',$tarih);if(!$d||$d->format('Y-m-d')!==$tarih)jsonError('Geçerli rapor tarihi seçin.');}
if ($raporBas > $raporBit) { $tmp=$raporBas; $raporBas=$raporBit; $raporBit=$tmp; }

if((new DateTimeImmutable($raporBas))->diff(new DateTimeImmutable($raporBit))->days>365)jsonError('Tek raporda en fazla 366 günlük dönem seçin.');
if($raporBas>date('Y-m-d'))jsonError('Gerçekleşen doluluk için gelecek başlangıç tarihi seçilemez.');
$raporBit=min($raporBit,date('Y-m-d'));
$makineler = [];
$sorgular = [
    'arac'    => "SELECT id, plaka AS ad, marka, model, sube, metraj, aktif, durum, temin_sekli, dis_kira_bedeli,
                         makine_kodu, liste_fiyat_aylik, liste_fiyat_gunluk, liste_fiyat_haftalik, yakit_tipi, kategori,
                         periyodik_kontrol_bitis AS periyodik, 'arac' AS tur FROM araclar",
    'manlift' => "SELECT id, kod AS ad, marka, model, sube, imal_yili, metraj, aktif, durum, temin_sekli, dis_kira_bedeli,
                         makine_kodu, liste_fiyat_aylik, liste_fiyat_gunluk, liste_fiyat_haftalik, yakit_tipi, kategori,
                         periyodik_bitis AS periyodik, 'manlift' AS tur FROM manliftler",
];
foreach ($sorgular as $tur => $sql) {
    if ($fTur !== '' && $fTur !== $tur) continue;
    foreach ($db->query($sql)->fetchAll() as $m) {
        if ($fSube !== '' && trim((string)($m['sube'] ?? '')) !== $fSube) continue;
        $temin = ($m['temin_sekli'] ?? 'stok') ?: 'stok';
        if ($fTemin !== '' && $temin !== $fTemin) continue;
        $m['temin_sekli'] = $temin;
        $makineler[] = $m;
    }
}
$makineler = filoSinifUygula($db, $makineler);
$sinifSecenek = [];
foreach ($makineler as $m) { $ag = $m['ana_grup'] ?: 'Ana grup belirtilmemiş'; $sinifSecenek[$ag][$m['alt_grup'] ?: 'Kategori seçilmemiş'] = true; }
ksort($sinifSecenek);
$makineler = array_values(array_filter($makineler, function ($m) use ($fYakit, $fAna, $fAlt) {
    if ($fYakit !== '' && (($m['yakit'] ?? null) ?: 'belirtilmemis') !== $fYakit) return false;
    if ($fAna !== '' && ($m['ana_grup'] ?: 'Ana grup belirtilmemiş') !== $fAna) return false;
    if ($fAlt !== '' && ($m['alt_grup'] ?: 'Kategori seçilmemiş') !== $fAlt) return false;
    return true;
}));
$filtreVar = $fSube !== '' || $fTemin !== '' || $fTur !== '' || $fYakit !== '' || $fAna !== '' || $fAlt !== '';

$kiradaSet = ['arac' => makineKiradaSeti($db, 'arac'), 'manlift' => makineKiradaSeti($db, 'manlift')];
$makineAnahtar = [];
$makineSubeleri = [];
foreach ($makineler as $m) { $makineAnahtar[$m['tur'] . ':' . $m['id']] = true; $makineSubeleri[$m['tur'] . ':' . $m['id']] = trim((string)($m['sube'] ?? '')); }

$durumSay = [];
$subeSay = []; $markaSay = []; $teminSay = []; $turSay = []; $yakitSay = [];
$kiradaAdet = 0; $musaitAdet = 0;
$yasToplam = []; $yasAdet = []; $yasSube = []; $yasModel=[];
foreach ($makineler as $m) {
    $kirada = in_array((int)$m['id'], $kiradaSet[$m['tur']], true);
    $d = $kirada ? 'kirada' : (($m['durum'] ?? 'musait') ?: 'musait');
    $durumSay[$d] = ($durumSay[$d] ?? 0) + 1;
    if ($kirada) $kiradaAdet++;
    if ($d === 'musait') $musaitAdet++;
    $sb = trim((string)($m['sube'] ?? '')) ?: 'Belirtilmemiş';
    $subeSay[$sb] = ($subeSay[$sb] ?? 0) + 1;
    $mk = trim((string)($m['marka'] ?? '')) ?: 'Belirtilmemiş';
    $markaSay[$mk] = ($markaSay[$mk] ?? 0) + 1;
    $teminSay[$m['temin_sekli']] = ($teminSay[$m['temin_sekli']] ?? 0) + 1;
    $turSay[$m['tur']] = ($turSay[$m['tur']] ?? 0) + 1;
    $yk = ($m['yakit'] ?? '') ?: 'belirtilmemis';
    $yakitSay[$yk] = ($yakitSay[$yk] ?? 0) + 1;
    $yil = (int)($m['imal_yili'] ?? 0); if ($yil > 1900 && $yil <= (int)date('Y')) { $yas = (int)date('Y') - $yil;$ym=implode(' | ',[$m['tur'],$m['marka']??'', $m['model']??'', $m['metraj']??'', $m['sube']??'']);if(!isset($yasModel[$ym]))$yasModel[$ym]=['grup'=>$ym,'adet'=>0,'toplam'=>0];$yasModel[$ym]['adet']++;$yasModel[$ym]['toplam']+=$yas; $yasToplam[$m['tur']] = ($yasToplam[$m['tur']]??0)+$yas; $yasAdet[$m['tur']] = ($yasAdet[$m['tur']]??0)+1; $sbKey=$sb; $yasSube[$sbKey]['toplam']=($yasSube[$sbKey]['toplam']??0)+$yas; $yasSube[$sbKey]['adet']=($yasSube[$sbKey]['adet']??0)+1; }
}
$yasOrt = []; foreach ($yasToplam as $tur=>$top) $yasOrt[$tur] = round($top / max(1,$yasAdet[$tur]), 2); foreach ($yasSube as $sb=>&$v) $v['ortalama'] = round($v['toplam']/max(1,$v['adet']),2); unset($v);
$fiyatGruplari=[];
$fs=$db->query("SELECT k.makine_tur,k.makine_id,k.kira_tipi,k.birim_ucret,k.miktar,k.iskonto_oran,k.sube,ml.model AS ml_model,ml.marka AS ml_marka,ml.metraj,ml.liste_fiyat_aylik AS ml_liste_fiyat_aylik,ml.liste_fiyat_gunluk AS ml_lg,ml.liste_fiyat_haftalik AS ml_lh,ar.liste_fiyat_gunluk AS ar_lg,ar.liste_fiyat_haftalik AS ar_lh,k.baslangic,k.bitis,k.gercek_bitis,k.onay_durumu,k.doviz,ar.model AS ar_model,ar.marka AS ar_marka,ar.liste_fiyat_aylik AS ar_liste FROM kiralamalar k LEFT JOIN manliftler ml ON k.makine_tur='manlift' AND ml.id=k.makine_id LEFT JOIN araclar ar ON k.makine_tur='arac' AND ar.id=k.makine_id WHERE k.durum<>'iptal'");
foreach($fs->fetchAll() as $r){if(!isset($makineAnahtar[$r['makine_tur'].':'.$r['makine_id']]))continue;if(kiralamaDonemKesisimi($r,$raporBas,$raporBit)===null)continue;if(($r['doviz']??'TL')!=='TL')continue;$marka=trim((string)($r['ml_marka']??$r['ar_marka']??''))?:'Belirtilmemiş';$model=trim((string)($r['ml_model']??$r['ar_model']??''))?:'Belirtilmemiş';$met=trim((string)($r['metraj']??''))?:'—';if($fSube!==''&&trim((string)($r['sube']??''))!==$fSube)continue;$key=$marka.'|'.$model.'|'.$met;if(!isset($fiyatGruplari[$key]))$fiyatGruplari[$key]=['marka'=>$marka,'model'=>$model,'metraj'=>$met,'agirlik'=>0,'hedef_toplam'=>0.0,'hedef_altinda'=>0,'toplam'=>0.0,'adet'=>0,'hedef'=>(float)($r['ml_liste_fiyat_aylik']??$r['ar_liste']??0)];$tip=$r['kira_tipi']?:'gunluk';$aylik=(float)$r['birim_ucret']*max(1,(int)$r['miktar'])*(30/kiraBirimGun($tip));$aylik*=1-kiralamaGecerliIskonto($r)/100;$agirlik=(int)kiralamaDonemKesisimi($r,$raporBas,$raporBit)['gun'];$lm=['liste_fiyat_aylik'=>$r['ml_liste_fiyat_aylik']??$r['ar_liste']??0,'liste_fiyat_gunluk'=>$r['ml_lg']??$r['ar_lg']??0,'liste_fiyat_haftalik'=>$r['ml_lh']??$r['ar_lh']??0];$hedef=kiraListeFiyatAylik($lm,$tip)['aylik']*max(1,(int)$r['miktar']);$fiyatGruplari[$key]['liste_gunluk']=round((float)$lm['liste_fiyat_gunluk'],2);$fiyatGruplari[$key]['liste_haftalik']=round((float)$lm['liste_fiyat_haftalik'],2);$fiyatGruplari[$key]['liste_aylik']=round((float)$lm['liste_fiyat_aylik'],2);$fiyatGruplari[$key]['toplam']+=$aylik*$agirlik;$fiyatGruplari[$key]['agirlik']+=$agirlik;$fiyatGruplari[$key]['hedef_toplam']+=$hedef*$agirlik;$fiyatGruplari[$key]['hedef_altinda']+=($hedef>0&&$aylik<$hedef)?1:0;$fiyatGruplari[$key]['adet']++;}
$fiyatAnalizi=[];foreach($fiyatGruplari as $v){$v['hedef']=round($v['hedef_toplam']/max(1,$v['agirlik']),2);$v['gerceklesen_ortalama']=round($v['toplam']/max(1,$v['agirlik']),2);$v['fark']=round($v['gerceklesen_ortalama']-$v['hedef'],2);$v['fark_yuzde']=$v['hedef']>0?round($v['fark']/$v['hedef']*100,2):null;unset($v['toplam']);$fiyatAnalizi[]=$v;}usort($fiyatAnalizi,fn($a,$b)=>$a['fark']<=>$b['fark']);
$toplamMakine = count($makineler);
$dogrulukOrani = $toplamMakine > 0 ? round($kiradaAdet / $toplamMakine * 100, 2) : 0.0;

$donemGun = (int)((new DateTimeImmutable($raporBas))->diff(new DateTimeImmutable($raporBit))->days)+1;
$uygunGun=0; $kiralikGun=0; $dolulukGruplari=[]; $eligible=[]; $occupied=[]; $cakismaGun=0;
foreach($makineler as $m){
    if((int)($m['aktif']??1)!==1||in_array($m['durum']??'', ['pasif','satildi'],true))continue;
    $key=$m['tur'].':'.$m['id'];
    $group=implode('|',[$m['tur'],$m['sube']??'', $m['marka']??'', $m['model']??'', $m['metraj']??'']);
    $eligible[$key]=$group;
    if(!isset($dolulukGruplari[$group]))$dolulukGruplari[$group]=['marka'=>$m['marka']??'','model'=>$m['model']??'','metraj'=>$m['metraj']??null,'tur'=>$m['tur'],'sube'=>trim((string)$m['sube'])?:'Belirtilmemiş','uygun_gun'=>0,'kirali_gun'=>0];
    $uygunGun+=$donemGun; $dolulukGruplari[$group]['uygun_gun']+=$donemGun;
}
$ds=$db->prepare("SELECT makine_tur,makine_id,baslangic,bitis,gercek_bitis FROM kiralamalar WHERE durum<>'iptal' AND baslangic<=?");
$ds->execute([$raporBit]);
foreach($ds->fetchAll() as $r){
    $key=$r['makine_tur'].':'.$r['makine_id'];if(!isset($eligible[$key]))continue;
    $intersection=kiralamaDonemKesisimi($r,$raporBas,$raporBit);if($intersection===null)continue;
    $date=new DateTimeImmutable($intersection['dBas']);$end=new DateTimeImmutable($intersection['dBit']);
    while($date<=$end){$day=$date->format('Y-m-d');if(isset($occupied[$key][$day]))$cakismaGun++;$occupied[$key][$day]=true;$date=$date->modify('+1 day');}
}
foreach($occupied as $key=>$days){$n=count($days);$kiralikGun+=$n;$dolulukGruplari[$eligible[$key]]['kirali_gun']+=$n;}
$dolulukSeri=[];
foreach($eligible as $key=>$group){
 if(!isset($dolulukSeri[$group]))$dolulukSeri[$group]=array_intersect_key($dolulukGruplari[$group],array_flip(['marka','model','metraj','tur','sube']))+['adet'=>0,'gunler'=>[]];
 $dolulukSeri[$group]['adet']++;
 foreach($occupied[$key]??[] as $day=>$yes)$dolulukSeri[$group]['gunler'][$day]=($dolulukSeri[$group]['gunler'][$day]??0)+1;
}
$dolulukDetay=[];foreach($dolulukGruplari as $v){$v['doluluk']=$v['uygun_gun']>0?round($v['kirali_gun']/$v['uygun_gun']*100,2):null;$dolulukDetay[]=$v;}
$tarihsel=ftDoluluk($db,$raporBas,$raporBit,$fSube,$fTur,$fTemin);
if($tarihsel!==null){$uygunGun=$tarihsel['uygun'];$kiralikGun=$tarihsel['kirali'];$cakismaGun=$tarihsel['cakisma'];$dolulukDetay=$tarihsel['detay'];$dolulukSeri=$tarihsel['seri'];}
$tarihceBas=ftBaslangic($db);
$oBit=(new DateTimeImmutable($raporBas))->modify('-1 day')->format('Y-m-d');
$oBas=(new DateTimeImmutable($oBit))->modify('-'.($donemGun-1).' days')->format('Y-m-d');
$oTarihsel=ftDoluluk($db,$oBas,$oBit,$fSube,$fTur,$fTemin);
$oUygun=0;$oKirali=0;
if($oTarihsel!==null){$oUygun=(int)$oTarihsel['uygun'];$oKirali=(int)$oTarihsel['kirali'];}
else{
    $oUygun=count($eligible)*$donemGun;
    $oq=$db->prepare("SELECT makine_tur,makine_id,baslangic,bitis,gercek_bitis FROM kiralamalar WHERE durum<>'iptal' AND baslangic<=?");
    $oq->execute([$oBit]);$oOcc=[];
    foreach($oq->fetchAll() as $r){
        $key=$r['makine_tur'].':'.$r['makine_id'];if(!isset($eligible[$key]))continue;
        $ix=kiralamaDonemKesisimi($r,$oBas,$oBit);if($ix===null)continue;
        $d=new DateTimeImmutable($ix['dBas']);$e=new DateTimeImmutable($ix['dBit']);
        while($d<=$e){$oOcc[$key][$d->format('Y-m-d')]=true;$d=$d->modify('+1 day');}
    }
    foreach($oOcc as $days)$oKirali+=count($days);
}
$onceki=['bas'=>$oBas,'bit'=>$oBit,'gun'=>$donemGun,'uygun_makine_gun'=>$oUygun,'kirali_makine_gun'=>$oKirali,
    'doluluk'=>$oUygun>0?round($oKirali/$oUygun*100,2):null,'tarihsel_dogrulandi'=>$oTarihsel!==null];
$kapasiteMetni=$tarihsel!==null?'Kaydedilmiş gün sonu envanteri ve o günkü şubeye göre. Bakımdaki aktif makineler filo kapasitesine dahildir. Bugün gün içinde değişebilir.':'Güncel envantere göre tahmin. Envanter tarihçesi '.($tarihceBas?:'henüz bilinmeyen tarih').' itibarıyla tutulur; daha önceki dönemler doğrulanamaz. Bakımdaki aktif makineler kapasiteye dahildir.';
$sirala = function (array $arr) {
    arsort($arr);
    $t = array_sum($arr) ?: 1;
    $out = [];
    foreach ($arr as $k => $v) $out[] = ['ad' => (string)$k, 'sayi' => $v, 'yuzde' => round($v / $t * 100, 2)];
    return $out;
};

$aktif = $db->query("
    SELECT k.*, m.unvan AS musteri_unvan
    FROM kiralamalar k LEFT JOIN musteriler m ON m.id = k.musteri_id
    WHERE k.durum='aktif' AND k.gercek_bitis IS NULL AND k.baslangic<=date('now','localtime')
")->fetchAll();

$bugun = date('Y-m-d');
$ak = filoAktifKiralamaOzeti($aktif, $makineAnahtar, $filtreVar, $bugun);
$ilSay = $ak['il_say']; $ilDetay = $ak['il_detay']; $ilBos = $ak['il_bos']; $dovizSay = $ak['doviz_say']; $gecikenler = $ak['gecikenler'];
$gercekAylik = $ak['gercek_aylik']; $kurEksikAdet = $ak['kur_eksik']; $aktifFiltreli = $ak['aktif_filtreli']; $netAylik = $ak['net_aylik'];

$kapasiteSube = filoKapasiteSube($makineler, $aktifFiltreli);
$kapasite = array_sum(array_column($kapasiteSube, 'kapasite'));
$listeFiyatiGirilmemis = 0;
foreach ($makineler as $m) {
    if (in_array($m['durum'] ?? '', ['satildi', 'pasif'], true)) continue;
    if ((float)($m['liste_fiyat_aylik'] ?? 0) <= 0 && (float)($m['liste_fiyat_gunluk'] ?? 0) <= 0 && (float)($m['liste_fiyat_haftalik'] ?? 0) <= 0) $listeFiyatiGirilmemis++;
}
$gercekAylik = round($gercekAylik, 2);
$kapasite = round($kapasite, 2);

$disMaliyet = 0.0;
foreach ($makineler as $m) if ($m['temin_sekli'] === 'dis') $disMaliyet += (float)($m['dis_kira_bedeli'] ?? 0);

$bakim = [];
try {
    $bs = $db->query("
        SELECT s.makine_tur, s.makine_id, s.tip, s.tarih, s.durum, s.ariza,
               p.ad_soyad AS teknisyen
        FROM teknik_servis s LEFT JOIN personel p ON p.id = s.teknisyen_id
        WHERE s.durum <> 'tamamlandi'
        ORDER BY s.tarih
    ")->fetchAll();
    $adMap = [];
    foreach ($makineler as $m) $adMap[$m['tur'] . ':' . $m['id']] = $m;
    foreach ($bs as $s) {
        $anahtar = $s['makine_tur'] . ':' . (int)$s['makine_id'];
        if (!isset($adMap[$anahtar])) continue;
        $bakim[] = [
            'makine' => $adMap[$anahtar]['ad'], 'sube' => $adMap[$anahtar]['sube'],
            'makine_tur' => $s['makine_tur'], 'makine_id' => (int)$s['makine_id'],
            'tip' => $s['tip'], 'durum' => $s['durum'], 'ariza' => $s['ariza'],
            'tarih' => $s['tarih'], 'teknisyen' => $s['teknisyen'],
            'sure_gun' => $s['tarih'] ? (int)((strtotime($bugun) - strtotime($s['tarih'])) / 86400) : 0,
        ];
    }
    usort($bakim, fn($a, $b) => $b['sure_gun'] <=> $a['sure_gun']);
} catch (\Exception $e) { hataGunlugu('filo.bakim', $e, true); }

$matris = []; $matrisSubeler = [];
foreach ($makineler as $m) {
    $kirada = in_array((int)$m['id'], $kiradaSet[$m['tur']], true);
    $d = $kirada ? 'kirada' : (($m['durum'] ?? 'musait') ?: 'musait');
    if (!in_array($d, ['kirada', 'musait'], true)) continue;
    $sb = trim((string)($m['sube'] ?? '')) ?: 'Belirtilmemiş';
    $ad = trim((string)($m['model'] ?? '')) ?: (trim((string)($m['marka'] ?? '')) ?: 'Diğer');
    $matrisSubeler[$sb] = true;
    if (!isset($matris[$ad])) $matris[$ad] = [];
    if (!isset($matris[$ad][$sb])) $matris[$ad][$sb] = ['kirada' => 0, 'musait' => 0];
    $matris[$ad][$sb][$d]++;
}
ksort($matris); $matrisSubeAd = array_keys($matrisSubeler); sort($matrisSubeAd);
$matrisSatir = [];
foreach ($matris as $ad => $subeler) $matrisSatir[] = ['makine' => $ad, 'subeler' => $subeler];

$tumSubeler = [];
foreach ($db->query("SELECT DISTINCT sube FROM araclar WHERE sube IS NOT NULL AND sube<>''")->fetchAll(PDO::FETCH_COLUMN) as $s) $tumSubeler[$s] = true;
foreach ($db->query("SELECT DISTINCT sube FROM manliftler WHERE sube IS NOT NULL AND sube<>''")->fetchAll(PDO::FETCH_COLUMN) as $s) $tumSubeler[$s] = true;
$tumSubeAd = array_keys($tumSubeler); sort($tumSubeAd);

jsonOk([
    'inceleme' => filoInceleme($db,$makineler,$raporBas,$raporBit),
    'ozet' => [
        'toplam_makine' => $toplamMakine,
        'kirada' => $kiradaAdet,
        'musait' => $musaitAdet,
        'doluluk_orani' => $dogrulukOrani,
        'geciken_adet' => count($gecikenler),
        'bakim_adet' => count($bakim),
        'kapasite_aylik' => $kapasite,
        'gerceklesen_aylik' => $gercekAylik,
        'gerceklesen_kur_eksik' => $kurEksikAdet,
        'fark' => $kurEksikAdet?null:round($kapasite - $gercekAylik, 2),
        'liste_fiyati_girilmemis' => $listeFiyatiGirilmemis,
        'kapasite_bosta' => round(array_sum(array_column($kapasiteSube, 'bosta')), 2),
        'kapasite_fiyat_farki' => round(array_sum(array_column($kapasiteSube, 'fiyat_farki')), 2),
        'kapasite_gerceklesen' => round(array_sum(array_column($kapasiteSube, 'gerceklesen')), 2),
        'dis_kiralama_maliyet_aylik' => round($disMaliyet, 2),
        'rapor_bas' => $raporBas, 'rapor_bit' => $raporBit,
        'uygun_makine_gun' => $uygunGun, 'kirali_makine_gun' => $kiralikGun,
        'cakisan_makine_gun' => $cakismaGun, 'kapasite_yontemi' => $kapasiteMetni, 'tarihce_baslangic'=>$tarihceBas,
        'donem_doluluk' => $tarihsel!==null&&$uygunGun>0?round($kiralikGun/$uygunGun*100,2):null, 'tahmini_donem_doluluk'=>round($kiralikGun/max(1,$uygunGun)*100,2), 'tarihsel_kapasite_dogrulandi'=>$tarihsel!==null,
    ],
    'onceki'      => $onceki,
    'durum'       => $sirala($durumSay),
    'sube'        => $sirala($subeSay),
    'marka'       => $sirala($markaSay),
    'temin_sekli' => $sirala($teminSay),
    'makine_turu' => $sirala($turSay),
    'yakit_tipi'  => $sirala($yakitSay),
    'ana_grup'    => filoAnaGrupKirilim(array_map(fn($m) => $m + ['kirada' => in_array((int)$m['id'], $kiradaSet[$m['tur']], true)], $makineler)),
    'sube_ana_grup_doluluk' => filoSubeAnaGrupDoluluk($makineler, $occupied, $donemGun),
    'kapasite_sube' => $kapasiteSube,
    'bakim_analizi' => filoBakimAnalizi($makineler, $bugun),
    'makine_nokta' => filoNoktaVerisi($makineler, $netAylik, array_map('count', $occupied), (int)date('Y')),
    'geciken_ay_ozeti' => filoGecikenAyOzeti($gecikenler),
    'yas_ortalamasi' => ['genel' => $yasOrt, 'sube' => $yasSube, 'model'=>array_values(array_map(fn($x)=>['grup'=>$x['grup'],'adet'=>$x['adet'],'ortalama'=>round($x['toplam']/$x['adet'],2)],$yasModel))],
    'yas_detay' => filoYasDetayi($makineler,(int)date('Y')),
    'fiyat_analizi' => $fiyatAnalizi,
    'doluluk_detay' => $dolulukDetay,
    'doluluk_seri' => array_values($dolulukSeri),
    'il'          => $sirala($ilSay),
    'il_detay'    => $ilDetay,
    'il_bos'      => $ilBos,
    'doviz'       => $sirala($dovizSay),
    'gecikenler'  => array_slice($gecikenler, 0, 100),
    'bakim'       => array_slice($bakim, 0, 100),
    'matris'      => ['subeler' => $matrisSubeAd, 'satirlar' => array_slice($matrisSatir, 0, 60)],
    'filtreler'   => ['subeler' => $tumSubeAd, 'yakit_tipleri' => MAKINE_YAKIT_TIPLERI, 'ana_gruplar' => array_map(fn($a, $alt) => ['ana_grup' => (string)$a, 'alt' => array_map('strval', array_keys($alt))], array_keys($sinifSecenek), $sinifSecenek)],
]);
