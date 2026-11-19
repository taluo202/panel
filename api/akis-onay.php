<?php
declare(strict_types=1);

function akisOnayIste(array $body,array $baglam,string $mesaj):void {
    if(empty($_SESSION['akis_onay_anahtar']))$_SESSION['akis_onay_anahtar']=bin2hex(random_bytes(32));
    $token=hash_hmac('sha256',json_encode([$baglam,date('Y-m-d')],JSON_UNESCAPED_UNICODE),$_SESSION['akis_onay_anahtar']);
    if(!is_string($body['akis_onay_token']??null)||!hash_equals($token,$body['akis_onay_token']))
        jsonError($mesaj.' Onayınız kullanıcı ve tarih-saat bilgisiyle kaydedilecek.',409,['onay_kodu'=>'AKIS_GECIS_ONAYI','onay_token'=>$token]);
}

function akisDenetimYaz(PDO $db,array $k,array $eski,array $yeni,string $mesaj):void {
    $degisen=[];
    foreach(array_unique(array_merge(array_keys($eski),array_keys($yeni))) as $alan){
        $once=$eski[$alan]??null;$sonra=$yeni[$alan]??null;
        if($once!==$sonra)$degisen[$alan]=[$once,$sonra];
    }
    $degisen['makine_tur']=[null,$k['makine_tur']??null];
    $degisen['makine_id']=[null,$k['makine_id']??null];
    $db->prepare('INSERT INTO islem_log(tarih,kullanici_id,kullanici_ad,tablo,kayit_id,eylem,ozet,degisenler,ip) VALUES(?,?,?,?,?,?,?,?,?)')->execute([
        date('Y-m-d H:i:s'),$_SESSION['user_id']??null,$_SESSION['ad_soyad']??$_SESSION['username']??null,
        'kiralamalar',(int)$k['id'],'guncelle',$mesaj,
        json_encode($degisen,JSON_UNESCAPED_UNICODE),
        substr((string)($_SERVER['REMOTE_ADDR']??''),0,45)
    ]);
}

function akisHareketUyarisi(string $eski,string $olay):?string {
    $normal=['siparis'=>['musait'],'nakliye'=>['kiraya_gidecek'],'teslim'=>['nakliyede'],
        'toplaniyor'=>['musait','kirada'],'iade'=>['kiradan_gelecek'],'bakima'=>['bakim_bekliyor'],'bakim_bitti'=>['bakimda']];
    $hedef=['siparis'=>'kiraya_gidecek','nakliye'=>'nakliyede','teslim'=>'musait','toplaniyor'=>'kiradan_gelecek','iade'=>'bakim_bekliyor','bakima'=>'bakimda','bakim_bitti'=>'musait'];
    if(!isset($normal[$olay])||$eski===($hedef[$olay]??'')||in_array($eski,$normal[$olay],true))return null;
    $ad=['musait'=>'Müsait / sahaya teslim edilmiş','kirada'=>'Kirada','kiraya_gidecek'=>'Gönderim bekliyor','nakliyede'=>'Nakliyede','kiradan_gelecek'=>'Toplanıyor','bakim_bekliyor'=>'Bakım bekliyor','bakimda'=>'Bakımda'];
    return 'Mevcut aşama: '.($ad[$eski]??$eski).'. Seçilen aşama: '.($ad[$hedef[$olay]]??$olay).'. Normal işlem sırası atlanacak veya geri alınacak. Makinenin fiili durumunu kontrol ederek bu geçişi onaylıyor musunuz?';
}

function akisDurumOnayi(PDO $db,array $k,array &$body):?string {
    $yeni=$body['durum']??$k['durum'];
    if($yeni==='aktif'&&($k['durum']!=='aktif'||!empty($k['gercek_bitis']))){
        if(opYeniZincir($db,$k)&&in_array(opAdimDurumu($db,(int)$k['id'])['saha_teslim_alma']['sonuc']??null,['tamam','atlandi'],true)&&!opOturumYetkisi($db,'atlama'))
            jsonError('Kapanmış kiralamayı yeniden açmak için "Adım atlama" yetkisi gerekir. Yöneticinize başvurun.',403);
        akisOnayIste($body,[$k,$body['baslangic']??null,$body['bitis']??null,'yeniden_ac'],
            'Kiralama yeniden açılacak. Eski gerçek iade tarihi kaldırılacak; eski fatura, ödeme ve tutanaklar korunacak. Fiziki teslim durumu ayrıca kontrol edilmelidir. Onaylıyor musunuz?');
        $tur=$body['makine_tur']??$k['makine_tur'];$mid=(int)($body['makine_id']??$k['makine_id']);
        $bas=$body['baslangic']??$k['baslangic'];$bit=array_key_exists('bitis',$body)?$body['bitis']:$k['bitis'];
        $c=kiralamaKaydiKontrol($db,$tur,$mid,$bas,$bit,(int)$k['id']);
        if($c['engel'])jsonError($c['engel'],409);
        if($c['cakisma'])jsonError('Yeniden açılan kiralama başka bir rezervasyonla çakışıyor. Tarihleri düzenleyin.',409);
        $body['gercek_bitis']=null;return 'Uyarı onaylandı: kiralama yeniden açıldı; önceki iade tarihi kaldırıldı.';
    }
    if($yeni==='tamamlandi'&&$k['durum']!=='tamamlandi'){
        $tarih=($body['gercek_bitis']??null)?:($k['gercek_bitis']??null);
        $q=$db->prepare("SELECT COUNT(*) FROM iade_tutanaklari WHERE kiralama_id=? AND islem='iade' AND COALESCE(fiili_teslim,1)=1");$q->execute([$k['id']]);$tutanak=(int)$q->fetchColumn()>0;
        if(!$tarih||!$tutanak){
            $tarih=$tarih?:date('Y-m-d');
            if($tarih<($body['baslangic']??$k['baslangic']))jsonError('Henüz başlamamış kiralamayı iade edilmiş olarak kapatamazsınız. İptal işlemini kullanın.',409);
            require_once __DIR__.'/zincir-lib.php';$hkEksik=kiralamaHakedisEksik($db,array_merge($k,['durum'=>'tamamlandi','gercek_bitis'=>$tarih]));
            akisOnayIste($body,[$k,'kapat',$tarih,$tutanak],(!$tutanak?'İade tutanağı bulunmuyor. ':'').($hkEksik?'Bu kiralamanın şu günleri için hakediş yok: '.$hkEksik['metin'].'; kapandıktan sonra Hakedişler › Toplu Üret ile üretilmeli. ':'').'Gerçek iade tarihi '.$tarih.' olarak kaydedilecek. Makinenin fiilen geri alındığını ve kiralamanın bu tarihle kapanmasını onaylıyor musunuz?');
            $body['gercek_bitis']=$tarih;return 'Uyarı onaylandı: '.(!$tutanak?'iade tutanağı olmadan ':'').'kiralama '.$tarih.' iade tarihiyle tamamlandı.';
        }
    }
    return null;
}

function akisYenidenAcAdimlari(PDO $db,array $k,string $onayMetni):void {
    if(!opYeniZincir($db,$k))return;
    if(!in_array(opAdimDurumu($db,(int)$k['id'])['saha_teslim_alma']['sonuc']??null,['tamam','atlandi'],true))return;
    opAdimYaz($db,(int)$k['id'],'saha_teslim_alma','geri_alindi',opOturumYapan($db),'yonetici',$onayMetni);
}
