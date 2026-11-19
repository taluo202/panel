<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('dis_kiralama');
$db = getDb(); $method = $_SERVER['REQUEST_METHOD']; $action = $_GET['action'] ?? 'list';
function dkBody(): array { return govdeOku(); }
function dkTur(string $t): string { return in_array($t, ['arac','manlift'], true) ? $t : ''; }
function dkTable(string $t): string { return $t === 'arac' ? 'araclar' : 'manliftler'; }
function dkDate($v): ?string {
  $v=trim((string)$v); if($v==='')return null;
  $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);
  if(!$d||$d->format('Y-m-d')!==$v)jsonError('Geçerli tarih girin (YYYY-AA-GG).');
  return $v;
}
function dkDonemDurumu(array $r): string {
  if(in_array($r['durum']??'',['iptal','iade_edildi','suresi_doldu'],true))return 'kapali';
  $bugun=date('Y-m-d'); if(($r['temin_baslangic']??'')>$bugun)return 'gelecek';
  if(!empty($r['temin_bitis'])&&$r['temin_bitis']<$bugun)return 'bitti';
  return 'aktif';
}
function dkMachine(PDO $db, string $tur, int $id): array {
  if (!$tur || $id < 1) jsonError('Makine seçilmelidir.');
  $st=$db->prepare('SELECT * FROM '.dkTable($tur).' WHERE id=?'); $st->execute([$id]); $m=$st->fetch();
  if (!$m) jsonError('Makine bulunamadı.',404); subeErisimDogrula($m['sube']??null); return $m;
}
if ($method === 'GET' && $action === 'list') {
  $where=[]; $p=[]; if (($q=trim((string)($_GET['q']??'')))!==''){ $where[]='(m.marka LIKE ? OR m.model LIKE ? OR m.kod LIKE ? OR m.plaka LIKE ? OR t.unvan LIKE ?)'; $p=array_fill(0,5,'%'.$q.'%'); }
  if (($d=$_GET['durum']??'')!==''){ $where[]='d.durum=?'; $p[]=$d; }
  $sql="SELECT d.*,t.unvan AS tedarikci_unvan,m.marka,m.model,m.kod,m.plaka,m.seri_no,m.metraj FROM dis_kiralama_kayitlari d JOIN tedarikciler t ON t.id=d.tedarikci_id JOIN (SELECT id,sube,marka,model,kod,NULL AS plaka,seri_no,metraj,'manlift' AS makine_tur FROM manliftler UNION ALL SELECT id,sube,marka,model,NULL AS kod,plaka,NULL AS seri_no,NULL AS metraj,'arac' AS makine_tur FROM araclar) m ON m.id=d.makine_id AND m.makine_tur=d.makine_tur";
  if(subeKisiti()!==null){$where[]='m.sube=?';$p[]=subeKisiti();} if ($where) $sql.=' WHERE '.implode(' AND ',$where); $sql.=' ORDER BY d.id DESC'; $st=$db->prepare($sql); $st->execute($p); $rows=$st->fetchAll();
  $say=function(string $sql,int $id) use($db):int{$q=$db->prepare($sql);$q->execute([$id]);return (int)$q->fetchColumn();};
  foreach($rows as &$r){ $r['etiket']='DK'; $r['donem_durumu']=dkDonemDurumu($r);
    $r['belge_sayisi']=$say("SELECT COUNT(*) FROM dis_kiralama_belgeler WHERE dis_kiralama_id=? AND belge_turu NOT IN ('gelis_foto','iade_foto')",(int)$r['id']);
    $r['foto_sayisi']=$say("SELECT COUNT(*) FROM dis_kiralama_belgeler WHERE dis_kiralama_id=? AND belge_turu IN ('gelis_foto','iade_foto')",(int)$r['id']);
    $q=$db->prepare("SELECT COUNT(*) FROM kiralamalar WHERE makine_tur=? AND makine_id=? AND durum<>'iptal' AND baslangic>=? AND (? IS NULL OR baslangic<=?)");$q->execute([$r['makine_tur'],$r['makine_id'],$r['temin_baslangic'],$r['temin_bitis'],$r['temin_bitis']]);$r['kiralama_sayisi']=(int)$q->fetchColumn(); }
  unset($r); jsonOk(['list'=>$rows]);
}
if ($method === 'GET' && $action === 'tedarikciler') { $st=$db->query("SELECT id,unvan,yetkili,telefon,email FROM tedarikciler WHERE aktif=1 ORDER BY unvan"); jsonOk(['list'=>$st->fetchAll()]); }
if ($method === 'GET' && $action === 'makineler') {
  $rows=[]; foreach(['manlift','arac'] as $tur){$tb=dkTable($tur); $kodCol=$tur==='manlift'?'kod':'plaka'; $extra=$tur==='manlift'?',seri_no,metraj':',NULL AS seri_no,NULL AS metraj'; $st=$db->query("SELECT id,sube,marka,model,$kodCol AS kod$extra,temin_sekli FROM $tb WHERE COALESCE(aktif,1)=1 ORDER BY marka,model"); foreach($st->fetchAll() as $r){if(subeKisiti()!==null&&trim((string)$r['sube'])!==subeKisiti())continue;$r['makine_tur']=$tur;$rows[]=$r;}} jsonOk(['list'=>$rows]);
}
if ($method === 'GET' && $action === 'detay') {
  $id=(int)($_GET['id']??0); $q=$db->prepare('SELECT d.*,t.unvan AS tedarikci_unvan FROM dis_kiralama_kayitlari d LEFT JOIN tedarikciler t ON t.id=d.tedarikci_id WHERE d.id=?'); $q->execute([$id]); $k=$q->fetch();
  if(!$k)jsonError('Kayıt bulunamadı.',404); $makine=dkMachine($db,$k['makine_tur'],(int)$k['makine_id']); $k['donem_durumu']=dkDonemDurumu($k); $k['etiket']='DK';
  $q=$db->prepare('SELECT id,unvan,yetkili,telefon,email,vergi_dairesi,vergi_no,adres,notlar,aktif FROM tedarikciler WHERE id=?');$q->execute([$k['tedarikci_id']]);$ted=$q->fetch()?:null;
  $q=$db->prepare('SELECT id,belge_turu,belge_adi,belge_no,belge_tarihi,dosya_yolu,notlar,created_at FROM dis_kiralama_belgeler WHERE dis_kiralama_id=? ORDER BY id DESC');$q->execute([$id]);$belgeler=$q->fetchAll();
  $q=$db->prepare('SELECT id,temin_baslangic,temin_bitis,durum,maliyet_tipi,maliyet_birim_tutari,maliyet_para_birimi FROM dis_kiralama_kayitlari WHERE makine_tur=? AND makine_id=? ORDER BY temin_baslangic DESC,id DESC');$q->execute([$k['makine_tur'],$k['makine_id']]);$donemler=$q->fetchAll();
  $kiralamalar=[];
  if(hasPermission('kiralama_takip','oku')){
    $q=$db->prepare("SELECT k.id,k.kod,k.ekipman_adi,k.baslangic,k.bitis,k.gercek_bitis,k.durum,k.kira_tipi,k.birim_ucret,k.doviz,k.sube,m.unvan AS musteri FROM kiralamalar k LEFT JOIN musteriler m ON m.id=k.musteri_id WHERE k.makine_tur=? AND k.makine_id=? AND k.durum<>'iptal' ORDER BY k.baslangic DESC,k.id DESC LIMIT 100");
    $q->execute([$k['makine_tur'],$k['makine_id']]);
    foreach($q->fetchAll() as $r){ $kis=subeKisiti(); if($kis!==null&&trim((string)$r['sube'])!==$kis)continue; $kiralamalar[]=$r; }
  }
  jsonOk(['kayit'=>$k,'makine'=>$makine,'tedarikci'=>$ted,'belgeler'=>$belgeler,'donemler'=>$donemler,'kiralamalar'=>$kiralamalar]);
}
if ($method === 'POST' && $action === 'tedarikci') {
  verifyCsrf(); $b=dkBody(); $unvan=trim(metin($b['unvan']??'')); if($unvan==='')jsonError('Tedarikçi ünvanı zorunludur.');
  $email=trim((string)($b['email']??'')); if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))jsonError('Geçerli bir e-posta girin.');
  $q=$db->prepare('SELECT id FROM tedarikciler WHERE unvan=? COLLATE NOCASE');$q->execute([$unvan]);if($q->fetchColumn())jsonError('Bu ünvanla bir tedarikçi zaten var; listeden seçin.',409);
  $v=[$unvan]; foreach(['yetkili','telefon','email','vergi_dairesi','vergi_no','adres','notlar'] as $f)$v[]=mb_substr(trim(metin($b[$f]??'')),0,1000)?:null;
  $db->prepare('INSERT INTO tedarikciler (unvan,yetkili,telefon,email,vergi_dairesi,vergi_no,adres,notlar,aktif) VALUES (?,?,?,?,?,?,?,?,1)')->execute($v);
  $tid=(int)$db->lastInsertId(); logla($db,'tedarikciler',$tid,'ekle',null,logKayitAl($db,'tedarikciler',$tid),'DK ekranından tedarikçi eklendi');
  jsonOk(['id'=>$tid,'unvan'=>$unvan],'Tedarikçi eklendi.');
}
if ($method === 'POST') {
  verifyCsrf(); $b=dkBody(); $tur=dkTur((string)($b['makine_tur']??'')); $mid=(int)($b['makine_id']??0); $newMachine=$b['yeni_makine']??null; if($newMachine===null)dkMachine($db,$tur,$mid); else { if(!is_array($newMachine)||!$tur)jsonError('Makine türü ve bilgileri gerekli.'); requirePermission($tur,'yaz'); $code=trim((string)($newMachine[$tur==='arac'?'plaka':'kod']??''));if($code==='')jsonError('Makine kodu / plaka gerekli.');subeErisimDogrula($newMachine['sube']??null); } $tid=(int)($b['tedarikci_id']??0); if($tid<1)jsonError('Tedarikçi seçilmelidir.');
  $bas=dkDate($b['temin_baslangic']??''); if(!$bas)jsonError('Temin başlangıcı zorunludur.'); $bit=dkDate($b['temin_bitis']??''); if($bit&&$bit<$bas)jsonError('Temin bitişi başlangıçtan önce olamaz.');
  $supplier=$db->prepare('SELECT id FROM tedarikciler WHERE id=? AND aktif=1');$supplier->execute([$tid]);
  if(!$supplier->fetchColumn())jsonError('Aktif tedarikçi seçin.');
  $overlap=$db->prepare("SELECT id FROM dis_kiralama_kayitlari WHERE makine_tur=? AND makine_id=? AND durum NOT IN ('iptal','iade_edildi','suresi_doldu') AND temin_baslangic<=COALESCE(?,'9999-12-31') AND COALESCE(temin_bitis,'9999-12-31')>=?");
  $overlap->execute([$tur,$mid,$bit,$bas]);if($overlap->fetchColumn())jsonError('Bu makinenin temin dönemleri çakışıyor.',409);
  $cols=['makine_tur','makine_id','tedarikci_id','tedarikci_makine_kodu','tedarik_sozlesme_no','temin_baslangic','temin_bitis','maliyet_tipi','maliyet_birim_tutari','maliyet_para_birimi','depozito','kdv_orani','nakliye_gelis','nakliye_iade','min_kiralama_suresi','avk_liste_fiyati','avk_min_fiyati','durum','sorumluluk_notu','notlar','created_by','updated_by'];
  $vals=[$tur,$mid,$tid,trim((string)($b['tedarikci_makine_kodu']??''))?:null,trim((string)($b['tedarik_sozlesme_no']??''))?:null,$bas,$bit,in_array($b['maliyet_tipi']??'', ['gunluk','haftalik','aylik','sabit'],true)?$b['maliyet_tipi']:'aylik',max(0,(float)($b['maliyet_birim_tutari']??0)),in_array($b['maliyet_para_birimi']??'', ['TL','USD','EUR'],true)?$b['maliyet_para_birimi']:'TL',max(0,(float)($b['depozito']??0)),max(0,(float)($b['kdv_orani']??20)),max(0,(float)($b['nakliye_gelis']??0)),max(0,(float)($b['nakliye_iade']??0)),max(0,(float)($b['min_kiralama_suresi']??0)),max(0,(float)($b['avk_liste_fiyati']??0)),max(0,(float)($b['avk_min_fiyati']??0)),in_array($b['durum']??'', ['taslak','musait','rezerve','musteride','iade_bekliyor','iade_edildi','suresi_doldu','iptal'],true)?$b['durum']:'musait',trim((string)($b['sorumluluk_notu']??''))?:null,trim((string)($b['notlar']??''))?:null,(int)($_SESSION['user_id']??0),(int)($_SESSION['user_id']??0)];
  $db->beginTransaction();
  try {
    if($newMachine!==null){
      $fields=['marka','model','sube','ruhsat_no','makine_kodu','notlar'];
      $fields[]=$tur==='arac'?'plaka':'kod';
      if($tur==='manlift')$fields=array_merge($fields,['seri_no','metraj','imal_yili','periyodik_bitis']);
      $machine=[];foreach($fields as $field){$value=trim((string)($newMachine[$field]??''));$machine[$field]=$value===''?null:mb_substr($value,0,4000);}
      if($tur==='manlift'){
        $machine['metraj']=metrajNormal($newMachine['metraj']??null);
        $machine['periyodik_bitis']=dkDate($newMachine['periyodik_bitis']??'');
        $year=(int)($newMachine['imal_yili']??0);if($year&&($year<1900||$year>(int)date('Y')+1))throw new DomainException('Geçerli imal yılı girin.');$machine['imal_yili']=$year?:null;
      }
      $tarihAlanlari=['tescil_tarihi','garanti_bitis'];
      if($tur==='arac')$tarihAlanlari=array_merge($tarihAlanlari,['trafik_baslangic','trafik_bitis','kasko_baslangic','kasko_bitis','makine_kirilma_baslangic','makine_kirilma_bitis','tuv_muayene_bitis','periyodik_kontrol_bitis']);
      foreach($tarihAlanlari as $f){$d=dkDate($newMachine[$f]??'');if($d!==null)$machine[$f]=$d;}
      $sayiAlanlari=['calisma_saati','son_bakim_saat','bakim_araligi_saat','liste_fiyat_aylik'];
      if($tur==='arac')$sayiAlanlari=array_merge($sayiAlanlari,['trafik_sigorta_tutar','kasko_tutar']);
      foreach($sayiAlanlari as $f){$v=trim((string)($newMachine[$f]??''));if($v==='')continue;if(!is_numeric($v)||!is_finite((float)$v)||(float)$v<0)jsonError('Geçersiz sayı: '.$f);$machine[$f]=(float)$v;}
      $mkG=mkGovde($db,$newMachine);if($mkG){$machine['kategori']=$mkG['kategori'];$machine['ozellikler']=$mkG['ozellikler'];if(array_key_exists('metraj',$mkG))$machine['metraj']=$mkG['metraj'];}
      $machine['temin_sekli']='dis';$machine['tedarikci_id']=$tid;$machine['durum']='musait';$machine['aktif']=1;
      $columns=array_keys($machine);
      $db->prepare('INSERT INTO '.dkTable($tur).' ('.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')')->execute(array_values($machine));
      $mid=(int)$db->lastInsertId();$vals[1]=$mid;
      logla($db,dkTable($tur),$mid,'ekle',null,logKayitAl($db,dkTable($tur),$mid),'DK yeni makine kartı');
    }
  $db->prepare('INSERT INTO dis_kiralama_kayitlari ('.implode(',',$cols).') VALUES ('.implode(',',array_fill(0,count($cols),'?')).')')->execute($vals); $id=(int)$db->lastInsertId(); $db->prepare('UPDATE '.dkTable($tur).' SET temin_sekli=\'dis\', tedarikci_id=? WHERE id=?')->execute([$tid,$mid]); logla($db,'dis_kiralama_kayitlari',$id,'ekle',null,logKayitAl($db,'dis_kiralama_kayitlari',$id),'Dış makine temin kaydı'); $db->commit();
  } catch (Throwable $e) { if($db->inTransaction())$db->rollBack(); if($e instanceof DomainException)jsonError($e->getMessage()); throw $e; }
  jsonOk(['id'=>$id,'makine_id'=>$mid,'makine_tur'=>$tur],'Dış kiralama kaydı oluşturuldu.');
}
if (($method==='PUT'||$method==='DELETE') && ($id=(int)($_GET['id']??0))>0) { verifyCsrf(); $b=$method==='PUT'?dkBody():[]; $check=$db->prepare('SELECT makine_tur,makine_id FROM dis_kiralama_kayitlari WHERE id=?');$check->execute([$id]);$existing=$check->fetch();if(!$existing)jsonError('Kayıt bulunamadı.',404);dkMachine($db,$existing['makine_tur'],(int)$existing['makine_id']);
 $requested=$method==='DELETE'?'iptal':($b['durum']??null);
 if(in_array($requested,['iptal','iade_edildi','suresi_doldu'],true)){
   $active=$db->prepare("SELECT id FROM kiralamalar WHERE makine_tur=? AND makine_id=? AND durum='aktif' AND gercek_bitis IS NULL LIMIT 1");
   $active->execute([$existing['makine_tur'],$existing['makine_id']]);if($active->fetchColumn())jsonError('Açık müşteri kiralaması varken tedarik kaydı kapatılamaz.',409);
 } if($method==='PUT'){
   if(count($b)>1){
     if(($b['makine_tur']??$existing['makine_tur'])!==$existing['makine_tur']||(int)($b['makine_id']??$existing['makine_id'])!==(int)$existing['makine_id'])jsonError('Temin kaydının makine bağlantısı değiştirilemez.');
     $old=logKayitAl($db,'dis_kiralama_kayitlari',$id);
     $dates=['temin_baslangic'=>dkDate($b['temin_baslangic']??$old['temin_baslangic']),'temin_bitis'=>dkDate($b['temin_bitis']??$old['temin_bitis'])];
     if(!$dates['temin_baslangic']||($dates['temin_bitis']&&$dates['temin_bitis']<$dates['temin_baslangic']))jsonError('Geçerli temin dönemi girin.');
     $used=$db->prepare('SELECT COUNT(*) FROM kiralama_dis_maliyetleri WHERE dis_kiralama_id=?');$used->execute([$id]);
     if((int)$used->fetchColumn()>0 && ($dates['temin_baslangic']!==$old['temin_baslangic']||$dates['temin_bitis']!==$old['temin_bitis']||(int)($b['tedarikci_id']??$old['tedarikci_id'])!==(int)$old['tedarikci_id']))jsonError('Kiralamaya bağlı temin kaydının tarih ve tedarikçisi değiştirilemez; yeni dönem kaydı açın.',409);
     $tid=(int)($b['tedarikci_id']??$old['tedarikci_id']);$q=$db->prepare('SELECT id FROM tedarikciler WHERE id=? AND aktif=1');$q->execute([$tid]);if(!$q->fetchColumn())jsonError('Aktif tedarikçi gerekli.');
     $values=$dates+['tedarikci_id'=>$tid];
     foreach(['tedarikci_makine_kodu','tedarik_sozlesme_no','sorumluluk_notu','notlar'] as $field)$values[$field]=mb_substr(trim((string)($b[$field]??$old[$field])),0,4000);
     foreach(['maliyet_birim_tutari','depozito','kdv_orani','nakliye_gelis','nakliye_iade','min_kiralama_suresi','avk_liste_fiyati','avk_min_fiyati'] as $field){$v=$b[$field]??$old[$field];if($v==='')$v=0;if(!is_numeric($v)||!is_finite((float)$v)||(float)$v<0)jsonError('Geçersiz tutar: '.$field);$values[$field]=(float)$v;}
     $values['maliyet_tipi']=$b['maliyet_tipi']??$old['maliyet_tipi'];$values['maliyet_para_birimi']=$b['maliyet_para_birimi']??$old['maliyet_para_birimi'];
     if(!in_array($values['maliyet_tipi'],['gunluk','haftalik','aylik','sabit'],true)||!in_array($values['maliyet_para_birimi'],['TL','USD','EUR'],true))jsonError('Geçersiz maliyet tipi veya para birimi.');
     if(isset($b['durum'])){if(!in_array($b['durum'],['taslak','musait','rezerve','musteride','iade_bekliyor','iade_edildi','suresi_doldu','iptal'],true))jsonError('Geçersiz durum.');$values['durum']=$b['durum'];}
     $sets=array_map(fn($f)=>$f.'=?',array_keys($values));$args=array_values($values);$args[]=$id;
     $db->beginTransaction();try{
       $db->prepare("UPDATE dis_kiralama_kayitlari SET ".implode(',',$sets).",updated_at=datetime('now','localtime') WHERE id=?")->execute($args);
       $db->prepare('UPDATE '.dkTable($existing['makine_tur']).' SET tedarikci_id=? WHERE id=?')->execute([$tid,$existing['makine_id']]);
       logla($db,'dis_kiralama_kayitlari',$id,'guncelle',$old,logKayitAl($db,'dis_kiralama_kayitlari',$id),'DK temin bilgileri güncellendi');$db->commit();
     }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
     jsonOk(['id'=>$id],'Temin bilgileri güncellendi.');
   }
 } if($method==='DELETE'){ $db->prepare("UPDATE dis_kiralama_kayitlari SET durum='iptal',updated_at=datetime('now','localtime') WHERE id=?")->execute([$id]); jsonOk([], 'Dış kiralama iptal edildi.'); } $dur=in_array($b['durum']??'', ['taslak','musait','rezerve','musteride','iade_bekliyor','iade_edildi','suresi_doldu','iptal'],true)?$b['durum']:null; if(!$dur)jsonError('Geçersiz durum.'); $db->prepare("UPDATE dis_kiralama_kayitlari SET durum=?,updated_by=?,updated_at=datetime('now','localtime') WHERE id=?")->execute([$dur,(int)($_SESSION['user_id']??0),$id]); jsonOk([], 'Durum güncellendi.'); }
jsonError('Geçersiz istek.',400);

