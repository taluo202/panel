<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/cek-islem.php';
require_once __DIR__ . '/cek-hareket-rapor.php';
require_once __DIR__ . '/cek-gecmis-stok.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('cek_senet');

$db     = getDb();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && ($_GET['action'] ?? '') === 'aylik_stok') {
    $year=(string)($_GET['yil']??date('Y'));
    if(!preg_match('/^20[0-9]{2}$|^2100$/',$year))jsonError('2000 ile 2100 arasında bir yıl seçin.');
    $months=[];for($i=1;$i<=12;$i++){$month=sprintf('%s-%02d',$year,$i);$months[$month]=['ay'=>$month,'alinan'=>0.0,'verilen'=>0.0,'ciro_verilen'=>0.0,'disari_verilen'=>0.0,'ciro_iade'=>0.0,'tahsil_edilen'=>0.0,'portfoy_alinan'=>0.0,'portfoy_verilen'=>0.0,'vade_toplam'=>0.0];}
    $where="c.tip='cek'";$args=[];
    if(subeKisiti()!==null){$where.=' AND '.cekSenetSubeIfade().'=?';$args[]=subeKisiti();}
    $q=$db->prepare('SELECT c.* FROM cek_senet c LEFT JOIN musteriler m ON m.id=c.musteri_id WHERE '.$where);$q->execute($args);$records=$q->fetchAll();
    $q=$db->prepare('SELECT h.* FROM cek_senet_gecmis h JOIN cek_senet c ON c.id=h.cek_id LEFT JOIN musteriler m ON m.id=c.musteri_id WHERE '.$where.' ORDER BY h.id');$q->execute($args);
    $history=[];foreach($q->fetchAll() as $event)$history[$event['cek_id']][]=$event;
    $missing=['adet'=>0,'tutar'=>0.0];$missingEvents=['adet'=>0,'tutar'=>0.0];$sources=[];
    foreach($records as $r){
        $received=substr((string)($r['teslim_tarihi']??''),0,7);$due=substr((string)$r['vade_tarihi'],0,7);$amount=(float)$r['tutar'];
        if(!$received){$missing['adet']++;$missing['tutar']+=$amount;}
        if(isset($months[$received]))$months[$received][$r['yon']]+=$amount;
        if(isset($months[$due])){$months[$due]['vade_toplam']+=$amount;if($r['durum']==='portfoyde')$months[$due]['portfoy_'.$r['yon']]+=$amount;}
        foreach(csRaporHareketleri($history[$r['id']]??[],$r) as $event){
            if(empty($event['tarih'])){$missingEvents['adet']++;$missingEvents['tutar']+=$event['tutar'];continue;}
            $month=substr($event['tarih'],0,7);if(!isset($months[$month]))continue;
            $months[$month][$event['tur']]+=$event['tutar'];$sources[]=array_merge($event,['id'=>(int)$r['id'],'belge_no'=>$r['belge_no']]);
        }
    }
    foreach($months as &$row){$row['disari_verilen']=$row['verilen']+$row['ciro_verilen'];foreach($row as $key=>$value)if($key!=='ay')$row[$key]=round($value,2);}unset($row);
    jsonOk(['yil'=>(int)$year,'list'=>array_values($months),'teslim_tarihi_eksik'=>$missing,'islem_tarihi_eksik'=>$missingEvents,'hareketler'=>$sources,'ay_sonu_stok'=>csAySonuStok($records,$history,(int)$year,date('Y-m-d')),'stok_kapsami'=>'Mevcut çek kayıtları ve kayıtlı geçmişleri; silinen kayıtlar dahil değildir. Şube kapsamı mevcut erişim yetkisine göredir.','portfoy_tarihi'=>date('Y-m-d')]);
}

function _csTip($v): string   { return in_array($v, ['cek','senet'], true) ? $v : 'cek'; }
function _csYon($v): string   { return in_array($v, ['alinan','verilen'], true) ? $v : 'alinan'; }
function _csDurum($v): string { return in_array($v, ['portfoyde','tahsil','odendi','karsiliksiz','iade','ciro'], true) ? $v : 'portfoyde'; }

function _csKayitDogrula(PDO $db, int $id): array {
    $st = $db->prepare('SELECT * FROM cek_senet WHERE id=?');
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) jsonError('Kayıt bulunamadı.', 404);
    subeErisimDogrula(cekSenetSubeEfektif($db, $c));
    return $c;
}

function csTahsilatSenkron(PDO $db, int $cekId): void {
    $st = $db->prepare("SELECT * FROM cek_senet WHERE id=?");
    $st->execute([$cekId]);
    $c = $st->fetch();
    if (!$c) return;
    $olmasiGereken = ($c['yon'] === 'alinan' && $c['durum'] === 'tahsil' && !empty($c['musteri_id']));
    $mevcut = !empty($c['tahsilat_id']);

    if ($olmasiGereken && empty($c['durum_tarihi'])) return;
    if ($olmasiGereken && !$mevcut) {
        $tip = $c['tip'] === 'senet' ? 'Senet' : 'Çek';
        $acik = $tip . ' tahsilatı' . ($c['belge_no'] ? ' (No: ' . $c['belge_no'] . ')' : '') . ($c['banka'] ? ' - ' . $c['banka'] : '');
        $ins = $db->prepare("INSERT INTO tahsilatlar (musteri_id, tarih, tutar, yontem, aciklama) VALUES (?,?,?,?,?)");
        $ins->execute([(int)$c['musteri_id'], $c['durum_tarihi'], (float)$c['tutar'], 'cek', $acik]);
        $tid = (int)$db->lastInsertId();
        $db->prepare("UPDATE cek_senet SET tahsilat_id=? WHERE id=?")->execute([$tid, $cekId]);
    } elseif (!$olmasiGereken && $mevcut) {
        $db->prepare("DELETE FROM tahsilatlar WHERE id=?")->execute([(int)$c['tahsilat_id']]);
        $db->prepare("UPDATE cek_senet SET tahsilat_id=NULL WHERE id=?")->execute([$cekId]);
    } elseif ($olmasiGereken && $mevcut) {
        $tip = $c['tip'] === 'senet' ? 'Senet' : 'Çek';
        $acik = $tip . ' tahsilatı' . ($c['belge_no'] ? ' (No: ' . $c['belge_no'] . ')' : '') . ($c['banka'] ? ' - ' . $c['banka'] : '');
        $db->prepare("UPDATE tahsilatlar SET musteri_id=?, tarih=?, tutar=?, aciklama=? WHERE id=?")
           ->execute([(int)$c['musteri_id'], $c['durum_tarihi'], (float)$c['tutar'], $acik, (int)$c['tahsilat_id']]);
    }
}

try {
switch ($method) {

    case 'GET':
        if (isset($_GET['id'])) {
            _csKayitDogrula($db, (int)$_GET['id']);
            $st = $db->prepare("SELECT c.*, m.unvan AS musteri_unvan FROM cek_senet c LEFT JOIN musteriler m ON m.id=c.musteri_id WHERE c.id=?");
            $st->execute([(int)$_GET['id']]);
            $r = $st->fetch();
            if (!$r) jsonError('Kayıt bulunamadı.', 404);
            $r['surum']=csSurum($r);
            $history=$db->prepare('SELECT id,eylem,islem_tarihi,gerekce,kullanici_ad,kayit_tarihi,eski,yeni FROM cek_senet_gecmis WHERE cek_id=? ORDER BY id DESC');
            $history->execute([(int)$r['id']]);$r['gecmis']=$history->fetchAll();
            jsonOk($r);
        }

        $where = []; $params = [];
        if (($t = trim(metin($_GET['tip'] ?? ''))) !== '')   { $where[] = "c.tip=?"; $params[] = $t; }
        if (($y = trim(metin($_GET['yon'] ?? ''))) !== '')   { $where[] = "c.yon=?"; $params[] = $y; }
        if (($d = trim(metin($_GET['durum'] ?? ''))) !== '' && $d !== 'hepsi') { $where[] = "c.durum=?"; $params[] = $d; }
        if (($yil = trim(metin($_GET['yil'] ?? ''))) !== '' && preg_match('/^\d{4}$/', $yil)) {
            $where[] = "substr(c.vade_tarihi,1,4)=?"; $params[] = $yil;
        }
        if (($ay = trim(metin($_GET['ay'] ?? ''))) !== '' && preg_match('/^\d{2}$/', $ay)) {
            $where[] = "substr(c.vade_tarihi,6,2)=?"; $params[] = $ay;
        }
        if (($q = trim(metin($_GET['q'] ?? ''))) !== '') {
            $where[] = "(" . trAramaSql('c.kisi') . " LIKE ? OR " . trAramaSql('c.banka') . " LIKE ? OR " . trAramaSql('c.belge_no') . " LIKE ? OR " . trAramaSql('c.hesap_no') . " LIKE ? OR " . trAramaSql('c.iban') . " LIKE ? OR " . trAramaSql('c.sube') . " LIKE ? OR " . trAramaSql('m.unvan') . " LIKE ?)";
            $like = '%' . trArama($q) . '%'; array_push($params, $like, $like, $like, $like, $like, $like, $like);
        }
        $_csKisit = subeKisiti();
        if ($_csKisit !== null) { $where[] = cekSenetSubeIfade() . " = ?"; $params[] = $_csKisit; }
        $sql = "SELECT c.*, m.unvan AS musteri_unvan FROM cek_senet c LEFT JOIN musteriler m ON m.id=c.musteri_id";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY (c.durum='portfoyde') DESC, c.vade_tarihi DESC, c.id DESC";
        $st = $db->prepare($sql); $st->execute($params);
        $rows = $st->fetchAll();

        $today = date('Y-m-d');
        $warn = date('Y-m-d', strtotime('+15 days'));
        $ozet = ['portfoy_alinan'=>0.0,'portfoy_verilen'=>0.0,'vadesi_gecen'=>0.0,'yaklasan'=>0.0,'adet'=>count($rows)];
        foreach ($rows as &$r) {
            $r['surum']=csSurum($r);
            if ($r['durum'] === 'portfoyde') {
                if ($r['yon'] === 'alinan') $ozet['portfoy_alinan'] += (float)$r['tutar'];
                else                        $ozet['portfoy_verilen'] += (float)$r['tutar'];
                if ($r['vade_tarihi'] < $today)      { $ozet['vadesi_gecen'] += (float)$r['tutar']; $r['vade_durum'] = 'gecti'; }
                elseif ($r['vade_tarihi'] <= $warn)  { $ozet['yaklasan'] += (float)$r['tutar']; $r['vade_durum'] = 'yaklasan'; }
                else                                 $r['vade_durum'] = 'normal';
            } else {
                $r['vade_durum'] = '';
            }
        }
        unset($r);
        foreach ($ozet as $k=>$v) if ($k!=='adet') $ozet[$k] = round((float)$v, 2);
        jsonOk(['list' => $rows, 'ozet' => $ozet]);
        break;

    case 'POST':
        verifyCsrf();
        $db->beginTransaction();
        $body = govdeOku();
        $islemTarihi=csIslemTarihi($body,$curRow ?? null);
        $teslim = trim((string)($body['teslim_tarihi'] ?? ($curRow['teslim_tarihi'] ?? '')));
        if ($teslim !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $teslim) || !checkdate((int)substr($teslim,5,2),(int)substr($teslim,8,2),(int)substr($teslim,0,4)))) jsonError('Teslim tarihi geçersiz.');
        if(($body['tip']??$curRow['tip']??'cek')==='cek'&&($body['yon']??$curRow['yon']??'alinan')==='alinan'&&(!$teslim||$teslim>date('Y-m-d')))jsonError('Müşteriden alınan çek için gerçekleşmiş teslim tarihi zorunludur.');
        $vade = dateStr('vade_tarihi', $body);
        if (!$vade) jsonError('Vade tarihi zorunludur.');
        $tutar = max(0, (float)($body['tutar'] ?? 0));
        if (!is_finite($tutar) || $tutar <= 0 || $tutar > 1e12) jsonError('Geçerli pozitif tutar girin.');

        $st = $db->prepare("
            INSERT INTO cek_senet (tip, yon, musteri_id, kisi, banka, sube, sube_no, hesap_no, iban, belge_no, tutar, vade_tarihi, durum, aciklama, teslim_tarihi)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $st->execute([
            _csTip($body['tip'] ?? 'cek'), _csYon($body['yon'] ?? 'alinan'),
            ((int)($body['musteri_id'] ?? 0)) ?: null,
            trim(metin($body['kisi'] ?? '')) ?: null, trim(metin($body['banka'] ?? '')) ?: null,
            trim(metin($body['sube'] ?? '')) ?: null, trim(metin($body['sube_no'] ?? '')) ?: null,
            trim(metin($body['hesap_no'] ?? '')) ?: null, trim(metin($body['iban'] ?? '')) ?: null,
            trim(metin($body['belge_no'] ?? '')) ?: null, $tutar, $vade,
            _csDurum($body['durum'] ?? 'portfoyde'), trim(metin($body['aciklama'] ?? '')) ?: null, $teslim ?: null,
        ]);
        $yeniId = (int)$db->lastInsertId();
        $db->prepare('UPDATE cek_senet SET durum_tarihi=?,ciro_alici=? WHERE id=?')->execute([$islemTarihi,trim((string)($body['ciro_alici']??''))?:null,$yeniId]);
        subeErisimDogrula(cekSenetSubeEfektif($db,logKayitAl($db,'cek_senet',$yeniId)));
        csTahsilatSenkron($db, $yeniId);
        csIslemKaydet($db,$yeniId,null,logKayitAl($db,'cek_senet',$yeniId),trim((string)($body['islem_gerekcesi']??'')) ?: 'Yeni kayıt');
        $db->commit();
        jsonOk(['id' => $yeniId], 'Kayıt eklendi.');

    case 'PUT':
        verifyCsrf();
        $db->beginTransaction();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $cur = $db->prepare("SELECT * FROM cek_senet WHERE id=?");
        $cur->execute([$id]);
        if (!($curRow = $cur->fetch())) jsonError('Kayıt bulunamadı.', 404);
        subeErisimDogrula(cekSenetSubeEfektif($db, $curRow));
        $_logEski = logKayitAl($db, 'cek_senet', $id);
        $body = govdeOku();

        if(isset($body['surum']) && !hash_equals(csSurum($curRow),(string)$body['surum'])) { $db->rollBack();jsonError('Bu kayıt değişmiş. Listeyi yenileyip tekrar açın.',409); }
        csDuzeltmeSirasi($db,$body,$curRow);
        if (isset($body['durum']) && !isset($body['vade_tarihi'])) {
            $islemTarihi=csIslemTarihi($body,$curRow);
            $db->prepare("UPDATE cek_senet SET durum=?, updated_at=datetime('now','localtime') WHERE id=?")
               ->execute([_csDurum($body['durum']), $id]);
            $db->prepare('UPDATE cek_senet SET durum_tarihi=?,ciro_alici=? WHERE id=?')->execute([$islemTarihi,trim((string)($body['ciro_alici']??$curRow['ciro_alici']??''))?:null,$id]);
            csTahsilatSenkron($db, $id);
            csIslemKaydet($db,$id,$curRow,logKayitAl($db,'cek_senet',$id),trim((string)($body['islem_gerekcesi']??'')) ?: 'Durum güncelleme');
            $db->commit();jsonOk(null, 'Durum ve gerçek işlem tarihi kaydedildi.');
        }

        $islemTarihi=csIslemTarihi($body,$curRow ?? null);
        $teslim = trim((string)($body['teslim_tarihi'] ?? ($curRow['teslim_tarihi'] ?? '')));
        if ($teslim !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $teslim) || !checkdate((int)substr($teslim,5,2),(int)substr($teslim,8,2),(int)substr($teslim,0,4)))) jsonError('Teslim tarihi geçersiz.');
        if(($body['tip']??$curRow['tip']??'cek')==='cek'&&($body['yon']??$curRow['yon']??'alinan')==='alinan'&&(!$teslim||$teslim>date('Y-m-d')))jsonError('Müşteriden alınan çek için gerçekleşmiş teslim tarihi zorunludur.');
        $vade = dateStr('vade_tarihi', $body);
        if (!$vade) jsonError('Vade tarihi zorunludur.');
        $tutar = max(0, (float)($body['tutar'] ?? 0));
        if (!is_finite($tutar) || $tutar <= 0 || $tutar > 1e12) jsonError('Geçerli pozitif tutar girin.');

        $st = $db->prepare("
            UPDATE cek_senet SET tip=?, yon=?, musteri_id=?, kisi=?, banka=?, sube=?, sube_no=?, hesap_no=?, iban=?, belge_no=?, tutar=?, vade_tarihi=?, durum=?, aciklama=?, teslim_tarihi=?,
                updated_at=datetime('now','localtime')
            WHERE id=?
        ");
        $st->execute([
            _csTip($body['tip'] ?? 'cek'), _csYon($body['yon'] ?? 'alinan'),
            ((int)($body['musteri_id'] ?? 0)) ?: null,
            trim(metin($body['kisi'] ?? '')) ?: null, trim(metin($body['banka'] ?? '')) ?: null,
            trim(metin($body['sube'] ?? '')) ?: null, trim(metin($body['sube_no'] ?? '')) ?: null,
            trim(metin($body['hesap_no'] ?? '')) ?: null, trim(metin($body['iban'] ?? '')) ?: null,
            trim(metin($body['belge_no'] ?? '')) ?: null, $tutar, $vade,
            _csDurum($body['durum'] ?? 'portfoyde'), trim(metin($body['aciklama'] ?? '')) ?: null, $teslim ?: null,
            $id,
        ]);
        $db->prepare('UPDATE cek_senet SET durum_tarihi=?,ciro_alici=? WHERE id=?')->execute([$islemTarihi,trim((string)($body['ciro_alici']??$curRow['ciro_alici']??''))?:null,$id]);
        subeErisimDogrula(cekSenetSubeEfektif($db,logKayitAl($db,'cek_senet',$id)));
        csTahsilatSenkron($db, $id);
        csIslemKaydet($db,$id,$curRow,logKayitAl($db,'cek_senet',$id),trim((string)($body['islem_gerekcesi']??'')) ?: 'Kayıt düzeltme');
        $db->commit();
        jsonOk(null, 'Kayıt güncellendi.');

    case 'DELETE':
        verifyCsrf();
        $db->beginTransaction();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        _csKayitDogrula($db, $id);
        $row = $db->prepare("SELECT tahsilat_id FROM cek_senet WHERE id=?");
        $row->execute([$id]);
        $tid = (int)($row->fetchColumn() ?: 0);
        if ($tid > 0) $db->prepare("DELETE FROM tahsilatlar WHERE id=?")->execute([$tid]);
        $_logEski = logKayitAl($db, 'cek_senet', $id);
        $db->prepare("DELETE FROM cek_senet WHERE id=?")->execute([$id]);
        csIslemKaydet($db,$id,$_logEski,null,'Kayıt silindi; önceki bilgiler işlem geçmişinde saklandı.');
        $db->commit();
        jsonOk(null, 'Kayıt silindi.');

    default:
        jsonError('Method Not Allowed', 405);
}

} catch (InvalidArgumentException $e) { if($db->inTransaction())$db->rollBack();jsonError($e->getMessage()); } catch (Throwable $e) { if($db->inTransaction())$db->rollBack();throw $e; }
