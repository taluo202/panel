<?php
declare(strict_types=1);

function dkDonemKontrol(PDO $db, string $tur, int $id, ?string $bas, ?string $bit): array {
    if (!in_array($tur, ['arac','manlift'], true)) return ['engel'=>null,'kayit'=>null];
    $table=$tur==='arac'?'araclar':'manliftler';
    $s=$db->prepare("SELECT temin_sekli FROM $table WHERE id=?");$s->execute([$id]);
    if ($s->fetchColumn()!=='dis') return ['engel'=>null,'kayit'=>null];
    if (!$bas) return ['engel'=>'DK makinesi için başlangıç tarihi zorunludur.','kayit'=>null];
    $s=$db->prepare("SELECT * FROM dis_kiralama_kayitlari WHERE makine_tur=? AND makine_id=? AND durum IN ('musait','rezerve','musteride') AND temin_baslangic<=? AND (temin_bitis IS NULL OR (? IS NOT NULL AND temin_bitis>=?)) ORDER BY temin_baslangic DESC,id DESC");
    $s->execute([$tur,$id,$bas,$bit,$bit]);
    $rows=$s->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows && !$bit){$q=$db->prepare("SELECT temin_bitis FROM dis_kiralama_kayitlari WHERE makine_tur=? AND makine_id=? AND durum IN ('musait','rezerve','musteride') AND temin_baslangic<=? AND temin_bitis>=? ORDER BY temin_baslangic DESC LIMIT 1");$q->execute([$tur,$id,$bas,$bas]);
      if($tb=$q->fetchColumn())return ['engel'=>'DK makinesi tedarikçiden '.tarihGoster((string)$tb).' tarihine kadar temin edildi; kiralamaya bu tarihe kadar bir bitiş (iade) tarihi girin.','kayit'=>null];}
    if(count($rows)!==1) return ['engel'=>count($rows)>1?'Birden fazla DK temin dönemi çakışıyor; tedarik kayıtlarını düzeltin.':'Kiralama süresini kapsayan aktif DK temin kaydı yok.','kayit'=>null];
    $r=$rows[0];
    if ($bit && (int)(new DateTimeImmutable($bas))->diff(new DateTimeImmutable($bit))->days+1 < (float)$r['min_kiralama_suresi']) {
        return ['engel'=>'Kiralama, tedarikçinin minimum gün süresinden kısa.','kayit'=>null];
    }
    return ['engel'=>null,'kayit'=>$r];
}

function dkMaliyetKaydet(PDO $db, int $kiralamaId): void {
    $st=$db->prepare('SELECT * FROM kiralamalar WHERE id=?');$st->execute([$kiralamaId]);
    $k=$st->fetch(PDO::FETCH_ASSOC);
    if(!$k||!$k['makine_tur']||!$k['makine_id'])return;
    $st=$db->prepare('SELECT id FROM kiralama_dis_maliyetleri WHERE kiralama_id=?');$st->execute([$kiralamaId]);
    if($st->fetchColumn())return;
    $check=dkDonemKontrol($db,$k['makine_tur'],(int)$k['makine_id'],$k['baslangic'],$k['bitis']);
    if($check['engel']!==null)throw new DomainException($check['engel']);
    $d=$check['kayit'];if(!$d)return;
    $kur=$d['maliyet_para_birimi']==='TL'?1:0;
    $db->prepare('INSERT INTO kiralama_dis_maliyetleri(kiralama_id,dis_kiralama_id,maliyet_tipi,maliyet_tutari,para_birimi,kur,nakliye_maliyeti) VALUES(?,?,?,?,?,?,?)')
       ->execute([$kiralamaId,$d['id'],$d['maliyet_tipi'],$d['maliyet_birim_tutari'],$d['maliyet_para_birimi'],$kur,(float)$d['nakliye_gelis']+(float)$d['nakliye_iade']]);
}
