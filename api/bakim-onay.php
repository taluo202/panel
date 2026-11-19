<?php
declare(strict_types=1);

function bakimDogrudanBitir(PDO $db, array $k, bool $onay): array {
    $tur=$k['makine_tur']??'';$mid=(int)($k['makine_id']??0);
    if(!in_array($tur,['arac','manlift'],true)||!$mid)return ['handled'=>false];
    $table=$tur==='arac'?'araclar':'manliftler';
    return islemIcinde($db, function (PDO $db) use ($k, $onay, $tur, $mid, $table): array {
        $q=$db->prepare("SELECT durum FROM $table WHERE id=?");$q->execute([$mid]);$old=$q->fetchColumn();
        if($old!=='bakim_bekliyor')return ['handled'=>false];
        if(!$onay)return ['handled'=>true,'onay_gerekli'=>true];
        if(makineAcikServisVarMi($db,$tur,$mid))return ['handled'=>true,'engel'=>'Makinenin açık servis kaydı var. Önce servis kaydını tamamlayın.'];
        $db->prepare("UPDATE $table SET durum='musait' WHERE id=?")->execute([$mid]);
        $now=date('Y-m-d H:i:s');
        $changes=['makine_durum'=>['bakim_bekliyor','musait'],'atlanan_asama'=>[null,'bakima'],'acik_onay'=>[false,true],'makine'=>[null,$tur.':'.$mid],'onay_zamani'=>[null,$now]];
        $db->prepare('INSERT INTO islem_log(tarih,kullanici_id,kullanici_ad,tablo,kayit_id,eylem,ozet,degisenler,ip) VALUES(?,?,?,?,?,?,?,?,?)')->execute([
            $now,$_SESSION['user_id']??null,$_SESSION['ad_soyad']??$_SESSION['username']??null,'kiralamalar',(int)$k['id'],'guncelle',
            'Bakım bekliyor → Bakım bitti (müsait): Bakıma alındı aşaması kullanıcı tarafından uyarı onaylanarak atlandı. Makine '.$tur.' #'.$mid,
            json_encode($changes,JSON_UNESCAPED_UNICODE),substr((string)($_SERVER['REMOTE_ADDR']??''),0,45)
        ]);
        return ['handled'=>true,'onay_gerekli'=>false];
    });
}
