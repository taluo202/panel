<?php
declare(strict_types=1);

function analizDkMaliyet(PDO $db, array $k, string $bas, string $bit): array {
    $s=$db->prepare('SELECT * FROM kiralama_dis_maliyetleri WHERE kiralama_id=?');
    $s->execute([$k['id']]); $d=$s->fetch(PDO::FETCH_ASSOC);
    if (!$d) return ['tutar'=>null,'eksik'=>'Kiralama #'.$k['id'].': dondurulmuş DK maliyeti yok'];
    $kur=($d['para_birimi']==='TL')?1:(float)$d['kur'];
    if ($kur<=0) return ['tutar'=>null,'eksik'=>'Kiralama #'.$k['id'].': DK maliyet kuru eksik'];
    $kes=kiralamaDonemKesisimi($k,$bas,$bit);
    if (!$kes) return ['tutar'=>0.0,'eksik'=>null];
    $s=$db->prepare("SELECT k.id,k.baslangic FROM kiralama_dis_maliyetleri s JOIN kiralamalar k ON k.id=s.kiralama_id WHERE s.dis_kiralama_id=? AND k.durum<>'iptal' ORDER BY k.baslangic,k.id LIMIT 1");
    $s->execute([$d['dis_kiralama_id']]); $ilk=$s->fetch(PDO::FETCH_ASSOC);
    $tekSefer=$ilk && (int)$ilk['id']===(int)$k['id'] && $k['baslangic']>=$bas && $k['baslangic']<=$bit;
    $gun=(int)$kes['gun']; $birim=(float)$d['maliyet_tutari'];
    $tutar=match($d['maliyet_tipi']) {
        'gunluk'=>$birim*$gun, 'haftalik'=>$birim*$gun/7,
        'aylik'=>$birim*$gun/30, 'sabit'=>$tekSefer?$birim:0,
        default=>null
    };
    if ($tutar===null) return ['tutar'=>null,'eksik'=>'Kiralama #'.$k['id'].': bilinmeyen DK maliyet tipi'];
    return ['tutar'=>($tutar+($tekSefer?(float)$d['nakliye_maliyeti']:0))*$kur,'eksik'=>null];
}
