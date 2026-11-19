<?php
declare(strict_types=1);
require_once __DIR__.'/tutanak_kurallari.php';
require_once __DIR__.'/belge-zinciri-lib.php';

function hzSema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS sevk_hazirlik (
      kiralama_id INTEGER PRIMARY KEY REFERENCES kiralamalar(id) ON DELETE RESTRICT,
      surum INTEGER NOT NULL, durum TEXT NOT NULL, makine_bag TEXT NOT NULL, veri TEXT NOT NULL,
      onaylayan TEXT,onay_zamani TEXT,updated_at TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS sevk_hazirlik_gecmisi (
      id INTEGER PRIMARY KEY AUTOINCREMENT,kiralama_id INTEGER NOT NULL REFERENCES kiralamalar(id) ON DELETE RESTRICT,
      surum INTEGER NOT NULL,olay TEXT NOT NULL,gerekce TEXT NOT NULL,veri TEXT NOT NULL,kullanici TEXT,zaman TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS sevk_gecis (id INTEGER PRIMARY KEY CHECK(id=1),son_eski_kiralama INTEGER NOT NULL);
      INSERT OR IGNORE INTO sevk_gecis SELECT 1,COALESCE(MAX(id),0) FROM kiralamalar;");
    $cols=array_column($db->query('PRAGMA table_info(iade_tutanaklari)')->fetchAll(),'name');
    if(!in_array('aksesuar_kalemleri',$cols,true))$db->exec('ALTER TABLE iade_tutanaklari ADD COLUMN aksesuar_kalemleri TEXT');
    if(!in_array('aksesuar_referans_veri',$cols,true))$db->exec('ALTER TABLE iade_tutanaklari ADD COLUMN aksesuar_referans_veri TEXT');
    $db->exec("CREATE TABLE IF NOT EXISTS depo_kontrolleri (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      kiralama_id INTEGER NOT NULL REFERENCES kiralamalar(id) ON DELETE RESTRICT,
      yon TEXT NOT NULL CHECK(yon IN ('gidis','donus')),
      surum INTEGER NOT NULL, durum TEXT NOT NULL, makine_bag TEXT NOT NULL, veri TEXT NOT NULL,
      makine_tur TEXT, makine_id INTEGER, onaylayan TEXT, onay_zamani TEXT, yapan TEXT,
      created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')), updated_at TEXT NOT NULL,
      UNIQUE(kiralama_id, yon));
      CREATE TABLE IF NOT EXISTS depo_kontrol_gecmisi (
      id INTEGER PRIMARY KEY AUTOINCREMENT, kiralama_id INTEGER NOT NULL REFERENCES kiralamalar(id) ON DELETE RESTRICT,
      yon TEXT NOT NULL, surum INTEGER NOT NULL, olay TEXT NOT NULL, gerekce TEXT NOT NULL, veri TEXT NOT NULL,
      kullanici TEXT, zaman TEXT NOT NULL, kaynak_gecmis_id INTEGER UNIQUE);
      CREATE TABLE IF NOT EXISTS depo_kontrol_gocu (id INTEGER PRIMARY KEY CHECK(id=1), zaman TEXT NOT NULL);");
    try { hzGoc($db); } catch (\Throwable $e) { error_log('depo_kontrolleri göçü: ' . $e->getMessage()); }
}

function hzGoc(PDO $db): void {
    if ($db->query('SELECT 1 FROM depo_kontrol_gocu WHERE id=1')->fetchColumn()) return;
    $acik = !$db->inTransaction();
    if ($acik) $db->beginTransaction();
    try {
        islemKilitAl($db);
        if (!$db->query('SELECT 1 FROM depo_kontrol_gocu WHERE id=1')->fetchColumn()) {
            $simdi = date('Y-m-d H:i:s');
            $db->prepare("INSERT OR IGNORE INTO depo_kontrolleri(kiralama_id,yon,surum,durum,makine_bag,veri,makine_tur,makine_id,onaylayan,onay_zamani,created_at,updated_at)
                       SELECT s.kiralama_id,'gidis',s.surum,s.durum,s.makine_bag,s.veri,k.makine_tur,k.makine_id,s.onaylayan,s.onay_zamani,?,s.updated_at
                       FROM sevk_hazirlik s LEFT JOIN kiralamalar k ON k.id=s.kiralama_id")->execute([$simdi]);
            $db->exec("INSERT OR IGNORE INTO depo_kontrol_gecmisi(kiralama_id,yon,surum,olay,gerekce,veri,kullanici,zaman,kaynak_gecmis_id)
                       SELECT kiralama_id,'gidis',surum,olay,gerekce,veri,kullanici,zaman,id FROM sevk_hazirlik_gecmisi");
            $db->prepare('INSERT INTO depo_kontrol_gocu(id,zaman) VALUES(1,?)')->execute([$simdi]);
        }
        if ($acik) $db->commit();
    } catch (\Throwable $e) {
        if ($acik && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function dkGecmisYaz(PDO $db, int $kiralamaId, string $yon, int $surum, string $olay, string $gerekce, array $veri): void {
    $db->prepare('INSERT INTO depo_kontrol_gecmisi(kiralama_id,yon,surum,olay,gerekce,veri,kullanici,zaman) VALUES(?,?,?,?,?,?,?,?)')
       ->execute([$kiralamaId, $yon, $surum, $olay, mb_substr($gerekce, 0, 2000), json_encode($veri, JSON_UNESCAPED_UNICODE),
                  (string)($_SESSION['username'] ?? ''), date('Y-m-d H:i:s')]);
}
function hzKiralama(PDO $db,int $id): array {
    $s=$db->prepare('SELECT k.*,m.unvan AS musteri_unvan FROM kiralamalar k JOIN musteriler m ON m.id=k.musteri_id WHERE k.id=?');$s->execute([$id]);$k=$s->fetch();
    if(!$k||(($sube=subeKisitiHam())!==null&&$sube!==$k['sube']))throw new DomainException('Kiralama bulunamadı.');
    return $k;
}
function hzGocHazir(PDO $db): void {
    if ($db->query('SELECT 1 FROM depo_kontrol_gocu WHERE id=1')->fetchColumn()) return;
    try { hzGoc($db); } catch (\Throwable $e) { error_log('depo_kontrolleri göçü: ' . $e->getMessage()); }
    if (!$db->query('SELECT 1 FROM depo_kontrol_gocu WHERE id=1')->fetchColumn())
        throw new DomainException('Kontrol kayıtları taşınıyor; birkaç saniye sonra yeniden deneyin.');
}
function hzGet(PDO $db,int $id,string $yon='gidis'): ?array {
    hzGocHazir($db);
    $s=$db->prepare('SELECT * FROM depo_kontrolleri WHERE kiralama_id=? AND yon=?');$s->execute([$id,$yon]);$r=$s->fetch();
    if(!$r)return null;$r['veri']=json_decode($r['veri'],true,512,JSON_THROW_ON_ERROR);return $r;
}
function hzBag(array $k): string {return hash('sha256',json_encode([$k['makine_tur'],$k['makine_id'],$k['sube'],$k['lokasyon']??null,$k['musteri_id']]));}
function hzGerekli(PDO $db,array $k): bool {
    return hzGet($db,(int)$k['id'])!==null||(int)$k['id']>(int)$db->query('SELECT son_eski_kiralama FROM sevk_gecis WHERE id=1')->fetchColumn();
}
function hzEngel(PDO $db,array $k): ?string {
    if(!hzGerekli($db,$k))return null;
    if((opAdimDurumu($db,(int)$k['id'])['depo_hazirlik']['sonuc']??null)==='atlandi')return null;
    $r=hzGet($db,(int)$k['id']);
    if(!$r||!in_array($r['durum'],['hazir','onaylandi','yola_cikti'],true))return 'Makine yola çıkmadan önce sevk hazırlığı (kontrol listesi ve çıkış fotoğrafları) tamamlanmalı: Teknik Servis → Makine Kontrolleri → Çıkış kontrolü.';
    return hash_equals($r['makine_bag'],hzBag($k))?null:'Sevk hazırlığından sonra makine, şube veya teslim adresi değişti; Teknik Servis → Makine Kontrolleri → Çıkış kontrolü ekranında yeniden kontrol edip tamamlayın.';
}
function hzMakineEngel(PDO $db,string $tur,int $id): ?string {
    $s=$db->prepare("SELECT * FROM kiralamalar WHERE makine_tur=? AND makine_id=? AND durum='aktif' AND gercek_bitis IS NULL ORDER BY baslangic DESC,id DESC LIMIT 1");$s->execute([$tur,$id]);$k=$s->fetch();
    if(!$k)return null;
    $s=$db->prepare("SELECT 1 FROM iade_tutanaklari WHERE kiralama_id=? AND islem='teslim' AND COALESCE(fiili_teslim,1)=1 LIMIT 1");$s->execute([$k['id']]);
    return $s->fetchColumn()?null:hzEngel($db,$k);
}
function hzSablonTahmin(?string $kategori, ?string $ekipman, string $tur): string {
    $t = mb_strtolower(trim(($kategori ?? '') . ' ' . ($ekipman ?? '')), 'UTF-8');
    $t = strtr(str_replace("\u{0307}", '', $t), ['ı' => 'i', 'ş' => 's', 'ğ' => 'g', 'ü' => 'u', 'ö' => 'o', 'ç' => 'c']);
    if (preg_match('/vinc|crane/', $t)) return 'vinc';
    if ($tur === 'arac') return 'diger';
    if (preg_match('/direk|mast/', $t)) return 'direk';
    if (str_contains($t, 'makas')) return 'makasli';
    if (preg_match('/eklem|teleskop|orumcek|boom|sepet/', $t)) return 'eklemli';
    return 'diger';
}
function hzSablonlar(): array {
    $ortak=[['kimlik','Makine kimliği ve iş eşleşmesi',true,false],['belge','Gerekli ekipman belgeleri ve kontrol geçerliliği',true,false],['sizinti','Görünür sızıntı / gövde / bağlantı kontrolü',true,false],['acil','Üretici prosedürüne göre acil durdurma ve kumandalar',true,false]];
    return [
      'vinc'=>['ad'=>'Vinç','kontroller'=>[...$ortak,['ayak','Destek ayakları ve zemin ekipmanı',true,true],['kaldirma','Kaldırma donanımı / kanca / bağlantılar',true,false]],'aksesuarlar'=>['Destek takozu','Sapan','Mapa']],
      'makasli'=>['ad'=>'Makaslı platform','kontroller'=>[...$ortak,['korkuluk','Platform korkulukları ve giriş kapısı',true,false],['enerji','Şarj / enerji ve şarj bağlantıları',true,true]],'aksesuarlar'=>['Şarj kablosu','Anahtar']],
      'eklemli'=>['ad'=>'Eklemli / teleskopik platform','kontroller'=>[...$ortak,['sepet','Sepet ve kumanda kontrolü',true,false],['bom','Bom / bağlantılar ve üretici kontrol adımları',true,false]],'aksesuarlar'=>['Anahtar','Şarj kablosu']],
      'direk'=>['ad'=>'Dikey direk platform','kontroller'=>[...$ortak,['korkuluk','Platform korkulukları ve giriş kapısı',true,false],['direk','Direk / zincir ve kaldırma mekanizması (yağlama, hasar, boşluk)',true,false],['enerji','Şarj / enerji ve şarj bağlantıları',true,true]],'aksesuarlar'=>['Şarj kablosu','Anahtar']],
      'diger'=>['ad'=>'Diğer ekipman','kontroller'=>[...$ortak,['prosedur','Ekipmana özgü kontrol prosedürü tamamlandı',true,false]],'aksesuarlar'=>[]]
    ];
}
function hzKalemler($rows): array {
    if(!is_array($rows)||count($rows)>50)throw new DomainException('En fazla 50 aksesuar kalemi girin.');
    $out=[];$seen=[];
    foreach($rows as $r){
        if(!is_array($r))throw new DomainException('Aksesuar satırı okunamadı.');
        $ad=trim((string)($r['ad']??''));$seri=trim((string)($r['seri']??''));$kod=trim((string)($r['kod']??''));$adet=$r['adet']??null;
        if($ad===''||mb_strlen($ad)>120||mb_strlen($seri)>120||!preg_match('/^[a-zA-Z0-9_-]{1,50}$/',$kod))throw new DomainException('Aksesuar adı, seri veya satır kodu geçersiz.');
        if(!is_numeric($adet)||!is_finite((float)$adet)||(float)$adet<0||(float)$adet>10000||floor((float)$adet)!=(float)$adet)throw new DomainException('Aksesuar adedi 0–10000 arasında tam sayı olmalıdır.');
        if(isset($seen[$kod]))throw new DomainException('Aynı aksesuar satırı iki kez kullanılamaz.');$seen[$kod]=true;
        $durum=$r['durum']??'saglam';if(!in_array($durum,['saglam','hasarli','eksik','fazla'],true))throw new DomainException('Aksesuar durumu geçersiz.');
        $not=trim((string)($r['not']??''));if($durum!=='saglam'&&$not==='')throw new DomainException('Aksesuar farkı/hasarı için açıklama girin.');
        $out[]=['kod'=>$kod,'ad'=>$ad,'seri'=>$seri,'adet'=>(int)$adet,'durum'=>$durum,'not'=>mb_substr($not,0,500)];
    }return $out;
}
function hzVeri(array $b, ?array $tabanBilesen = null): array {
    $sablon=(string)($b['sablon']??'');$s=hzSablonlar()[$sablon]??null;if(!$s)throw new DomainException('Ekipman kontrol şablonunu seçin.');
    $v=['sablon'=>$sablon,'sablon_ad'=>$s['ad'],'sablon_surumu'=>1,'kontroller'=>[]];
    foreach(['cikis_sube','teslim_adres','cikis','varis_bas','varis_bit','yontem','sofor','plaka','romork','irtibat','telefon','erisim','prosedur','not'] as $f)$v[$f]=mb_substr(trim((string)($b[$f]??'')),0,1000);
    foreach($s['kontroller'] as [$kod,$ad,$kritik,$na]){
        $g=$b['kontroller'][$kod]??[];$sonuc=$g['sonuc']??'bekliyor';$not=trim((string)($g['not']??''));
        if(!in_array($sonuc,['bekliyor','uygun','uygun_degil','uygulanamaz'],true))throw new DomainException('Geçersiz kontrol sonucu.');
        if(in_array($sonuc,['uygun_degil','uygulanamaz'],true)&&$not==='')throw new DomainException($ad.': gerekçe gerekli.');
        if($sonuc==='uygulanamaz'&&!$na)throw new DomainException($ad.': uygulanamaz seçilemez.');
        $v['kontroller'][$kod]=['ad'=>$ad,'kritik'=>$kritik,'na'=>$na,'sonuc'=>$sonuc,'not'=>mb_substr($not,0,1000),'kontrol_eden'=>$_SESSION['username']??'','zaman'=>date('Y-m-d H:i:s')];
    }
    $say=trim((string)($b['sayac']??''));
    if($say!==''&&(!is_numeric($say)||(float)$say<0))throw new DomainException('Sayaç sıfır veya pozitif bir sayı olmalıdır.');
    $v['sayac']=$say;
    $v['aksesuarlar']=hzKalemler($b['aksesuarlar']??[]);
    $v['bilesenler']=bzBilesenler($b['bilesenler']??null,$tabanBilesen);return $v;
}
function hzOnayHatasi(array $v): ?string {
    foreach(['cikis_sube','teslim_adres','cikis','varis_bas','varis_bit','yontem','irtibat','telefon','prosedur'] as $f)if($v[$f]==='')return 'Hareket planı, saha irtibatı ve doğrulanan teknik prosedür bilgilerini tamamlayın.';
    foreach(['cikis','varis_bas','varis_bit'] as $f){$d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$v[$f]);if(!$d||$d->format('Y-m-d\TH:i')!==$v[$f])return 'Geçerli plan tarih/saatleri girin.';}
    if($v['cikis']>$v['varis_bas']||$v['varis_bas']>$v['varis_bit'])return 'Çıkış ve varış aralığının sırası geçersiz.';
    if(!in_array($v['yontem'],['firma','dis','kendi'],true))return 'Nakliye yöntemi seçin.';
    if($v['yontem']!=='kendi'&&($v['sofor']===''||$v['plaka']===''))return 'Nakliye için şoför ve taşıyıcı plakası gerekli.';
    foreach($v['kontroller'] as $r)if(!in_array($r['sonuc'],['uygun','uygulanamaz'],true))return $r['ad'].': kontrol tamamlanmamış veya uygun değil.';
    foreach($v['aksesuarlar'] as $r)if($r['durum']!=='saglam')return 'Hazırlıktaki aksesuar eksik/hasarını giderin.';
    return null;
}
function hzAciAnahtar(string $s): string { return preg_replace('/[^a-z0-9]+/', '', trArama($s)); }

function hzZorunluAcilar(string $tur): array {
    $l = tutanakKurallari($tur === 'arac' ? 'arac' : 'manlift')['fotograflar'];
    return array_values(array_map(fn($f) => (string)$f['ad'], array_filter($l, fn($f) => !empty($f['zorunlu']))));
}

const HZ_YURUYEN_ACILAR = [
    'teker' => [
        ['Sol ön teker', 'Sol ön tekerleğe yakından; lastik yüzeyi, diş derinliği ve jant görünsün.'],
        ['Sağ ön teker', 'Sağ ön tekerleğe yakından; lastik yüzeyi, diş derinliği ve jant görünsün.'],
        ['Sol arka teker', 'Sol arka tekerleğe yakından; lastik yüzeyi, diş derinliği ve jant görünsün.'],
        ['Sağ arka teker', 'Sağ arka tekerleğe yakından; lastik yüzeyi, diş derinliği ve jant görünsün.'],
    ],
    'palet' => [
        ['Sol palet', 'Sol paleti boydan boya; pabuçlar, makaralar ve palet gerginliği görünsün.'],
        ['Sağ palet', 'Sağ paleti boydan boya; pabuçlar, makaralar ve palet gerginliği görünsün.'],
    ],
];

function hzYuruyenTip(string $tur, ?string $kategori, ?string $ekipman = null): string {
    if ($tur === 'arac') return 'teker';
    $ad = trim((string)$kategori) !== '' ? (string)$kategori : (string)$ekipman;
    $t = trArama($ad);
    return (str_contains($t, 'paletli') || str_contains($t, 'orumcek')) ? 'palet' : 'teker';
}

function hzYuruyenAcilar(string $tur, ?string $kategori, ?string $ekipman = null): array {
    return array_map(fn($a) => ['ad' => $a[0], 'zorunlu' => true, 'sema' => 'lastik', 'tarif' => $a[1]],
                     HZ_YURUYEN_ACILAR[hzYuruyenTip($tur, $kategori, $ekipman)]);
}

function hzHazirlikAcilar(string $tur, ?string $kategori, ?string $ekipman = null): array {
    $tur = $tur === 'arac' ? 'arac' : 'manlift';
    return [...hzZorunluAcilar($tur), ...array_column(hzYuruyenAcilar($tur, $kategori, $ekipman), 'ad')];
}

function hzMakineKategori(PDO $db, array $k): ?string {
    $arac = ($k['makine_tur'] ?? '') === 'arac';
    $s = $db->prepare('SELECT marka, model, kategori FROM ' . ($arac ? 'araclar' : 'manliftler') . ' WHERE id=?');
    $s->execute([(int)($k['makine_id'] ?? 0)]);
    $m = $s->fetch(PDO::FETCH_ASSOC);
    return $m ? makineKategoriAdi($db, $m) : null;
}

function hzHazirlikZorunlu(PDO $db, array $k): array {
    return hzHazirlikAcilar((string)($k['makine_tur'] ?? 'manlift'), hzMakineKategori($db, $k), $k['ekipman_adi'] ?? null);
}

function hzEksikAcilar(PDO $db, array $k, ?array $acilar = null): array {
    $s = $db->prepare("SELECT aci_tipi FROM kiralama_fotograflari WHERE kiralama_id=? AND COALESCE(yon,'gidis')='gidis'");
    $s->execute([(int)$k['id']]);
    $var = array_flip(array_map(fn($a) => hzAciAnahtar((string)$a), $s->fetchAll(PDO::FETCH_COLUMN)));
    $acilar ??= hzZorunluAcilar((string)($k['makine_tur'] ?? 'manlift'));
    return array_values(array_filter($acilar, fn($a) => !isset($var[hzAciAnahtar((string)$a)])));
}

function hzSayacAlani(string $tur): string { return $tur === 'arac' ? 'km' : 'calisma_saati'; }
function hzSayacZorunlu(string $tur): bool { return $tur === 'arac'; }

function hzDepoHatasi(PDO $db, array $k, array $v): ?string {
    foreach ($v['kontroller'] ?? [] as $r) if (!in_array($r['sonuc'] ?? '', ['uygun', 'uygulanamaz'], true)) return ($r['ad'] ?? 'Kontrol') . ': kontrol tamamlanmamış veya uygun değil.';
    foreach ($v['aksesuarlar'] ?? [] as $r) if (($r['durum'] ?? 'saglam') !== 'saglam') return 'Hazırlıktaki aksesuar eksik/hasarını giderin.';
    $s = $v['sayac'] ?? '';
    if ($s === '') { if (hzSayacZorunlu((string)($k['makine_tur'] ?? ''))) return 'Kilometre sayacını girin.'; }
    elseif (!is_numeric($s) || (float)$s < 0) return 'Sayaç değeri sıfır veya pozitif bir sayı olmalıdır.';
    if ($e = hzEksikAcilar($db, $k, hzHazirlikZorunlu($db, $k))) return 'Eksik zorunlu fotoğraf: ' . implode(', ', $e) . '.';
    return null;
}

function hzErisimHatasi(bool $yeniZincir, string $islem, array $y): ?string {
    $ret = 'Bu işlem için yetkiniz yok.';
    if ($islem === 'liste')  return !empty($y['kiralama_oku']) ? null : $ret;
    if ($islem === 'oku')    return (!empty($y['kiralama_oku']) || ($yeniZincir && (!empty($y['depo']) || !empty($y['yukleme'])))) ? null : $ret;
    if ($islem === 'onayla') return !empty($y['kiralama_yaz']) ? null : $ret;
    if (!$yeniZincir)        return !empty($y['kiralama_yaz']) ? null : $ret;
    $g = $islem === 'yola_cik' ? 'yukleme' : 'depo';
    return !empty($y[$g]) ? null : 'Bu işlem için "' . OP_YETKILER[$g] . '" yetkiniz yok.';
}

function hzKaydet(PDO $db,array $k,array $b): void {
    if($k['durum']!=='aktif'||!empty($k['gercek_bitis'])||!in_array($k['makine_tur'],['arac','manlift'],true)||!$k['makine_id'])throw new DomainException('Hazırlık için aktif ve makine atanmış kiralama gerekir.');
    $r=hzGet($db,(int)$k['id']);$n=(int)($r['surum']??0);
    if((int)($b['temel_surum']??-1)!==$n)throw new DomainException('Hazırlık değişti. Sayfayı yenileyin.');
    $gerekce=trim((string)($b['gerekce']??''));
    $islem=$b['islem']??'';if(!in_array($islem,['kaydet','tamamla','onayla','yola_cik'],true))throw new DomainException('Geçersiz hazırlık işlemi.');
    if($r&&$r['durum']==='yola_cikti')throw new DomainException('Yola çıkış kaydı korunur. Yeni hazırlık için ayrı kiralama/operasyon gerekir.');
    $v=$islem==='kaydet'?hzVeri($b['veri']??[],$r['veri']['bilesenler']??null):($r['veri']??null);
    if(!$v)throw new DomainException('Önce hazırlık taslağını kaydedin.');
    if($islem!=='kaydet'&&!hash_equals($r['makine_bag'],hzBag($k)))throw new DomainException('Makine, şube veya teslim adresi değişti. Taslağı yeniden kontrol edip kaydedin.');
    if($islem!=='kaydet'&&($e=(opYeniZincir($db,$k)?hzDepoHatasi($db,$k,$v):hzOnayHatasi($v))))throw new DomainException($e);
    if($islem!=='kaydet'){
        $ps=$db->prepare("SELECT COUNT(*) FROM kiralama_fotograflari WHERE kiralama_id=? AND COALESCE(yon,'gidis')='gidis'");$ps->execute([$k['id']]);if(!(int)$ps->fetchColumn())throw new DomainException('Hazırlığı tamamlamak için güncel çıkış fotoğrafı ekleyin.');
    }
    if($islem==='tamamla'&&$r['durum']!=='taslak')throw new DomainException('Yalnız taslak hazırlık tamamlanabilir.');
    if($islem==='onayla'&&$r['durum']!=='hazir')throw new DomainException('Önce hazırlığı tamamlayın.');
    if($islem==='yola_cik'){
        if($e=hzEngel($db,$k))throw new DomainException($e);
        $s=$db->prepare("SELECT 1 FROM iade_tutanaklari WHERE kiralama_id=? AND islem='teslim' AND COALESCE(fiili_teslim,1)=1 LIMIT 1");$s->execute([$k['id']]);
        if($s->fetchColumn())throw new DomainException('Bu kiralamada fiili teslim tutanağı zaten var; makine sahada. Yola çıkış kaydedilemez.');
        if(!operasyonKiralamaGuncelMi($db,$k)||makineAcikServisVarMi($db,$k['makine_tur'],(int)$k['makine_id']))throw new DomainException('Güncel kiralama veya açık servis kontrolü sevkiyatı engelliyor.');
        $tablo=$k['makine_tur']==='arac'?'araclar':'manliftler';$s=$db->prepare("SELECT durum FROM $tablo WHERE id=?");$s->execute([$k['makine_id']]);
        if(in_array($s->fetchColumn(),['satildi','pasif','bakimda','bakim_bekliyor','inceleme_bekliyor','serviste','kiradan_gelecek'],true))throw new DomainException('Makinenin mevcut durumu sevkiyata uygun değil.');
        kiralamaMakineDurumu($db,$k['makine_tur'],(int)$k['makine_id'],'nakliye');
    }
    $durum=['kaydet'=>'taslak','tamamla'=>'hazir','onayla'=>'onaylandi','yola_cik'=>'yola_cikti'][$islem];$now=date('Y-m-d H:i:s');$actor=$_SESSION['username']??'';$json=json_encode($v,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $onaylayan=in_array($durum,['onaylandi','yola_cikti'])?($islem==='onayla'?$actor:$r['onaylayan']):null;
    $onayZamani=in_array($durum,['onaylandi','yola_cikti'])?($islem==='onayla'?$now:$r['onay_zamani']):null;
    $db->prepare("INSERT INTO depo_kontrolleri(kiralama_id,yon,surum,durum,makine_bag,veri,makine_tur,makine_id,onaylayan,onay_zamani,yapan,created_at,updated_at)
        VALUES(?,'gidis',?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(kiralama_id,yon) DO UPDATE SET surum=excluded.surum,durum=excluded.durum,
        makine_bag=excluded.makine_bag,veri=excluded.veri,makine_tur=excluded.makine_tur,makine_id=excluded.makine_id,
        onaylayan=excluded.onaylayan,onay_zamani=excluded.onay_zamani,yapan=excluded.yapan,updated_at=excluded.updated_at")
       ->execute([$k['id'],$n+1,$durum,hzBag($k),$json,$k['makine_tur'],$k['makine_id'],$onaylayan,$onayZamani,$actor,$now,$now]);
    dkGecmisYaz($db,(int)$k['id'],'gidis',$n+1,$islem,$gerekce,['makine_bag'=>hzBag($k),'veri'=>$v]);
}

function hzReferans(PDO $db,int $kid,string $islem,string $tarih='9999-12-31'): array {
    if($islem==='iade'){
        $s=$db->prepare("SELECT id,belge_surumu,aksesuar_kalemleri FROM iade_tutanaklari WHERE kiralama_id=? AND islem='teslim' AND COALESCE(fiili_teslim,1)=1 AND tarih<=? ORDER BY tarih DESC,id DESC LIMIT 1");$s->execute([$kid,$tarih]);$r=$s->fetch();
        if($r&&$r['aksesuar_kalemleri']!==null)return ['anahtar'=>'t:'.$r['id'].':'.$r['belge_surumu'],'kalemler'=>json_decode($r['aksesuar_kalemleri'],true),'kaynak'=>'Verilen: teslim tutanağındaki sayım'];
        return ['anahtar'=>'yok','kalemler'=>[],'kaynak'=>'Teslimde adetli aksesuar sayılmamış'];
    }
    $r=hzGet($db,$kid);return $r?['anahtar'=>'h:'.$r['surum'],'kalemler'=>$r['veri']['aksesuarlar'],'kaynak'=>'Verilen: çıkış kontrolündeki sayım']:['anahtar'=>'yok','kalemler'=>[],'kaynak'=>'Çıkış kontrolünde adetli aksesuar sayılmamış'];
}
function hzSayim(PDO $db,array $k,array $b): ?array {
    $ref=hzReferans($db,(int)$k['id'],$b['islem'],$b['tarih']);
    if(!array_key_exists('aksesuar_kalemleri',$b)){
        if($ref['kalemler'])throw new DomainException('Adetli aksesuar sayımı gerekli. Güncel formu açın.');
        return null;
    }
    if(($b['aksesuar_referans']??'')!==$ref['anahtar'])throw new DomainException('Aksesuar referansı değişti. Formu yenileyip sayımı kontrol edin.');
    $rows=hzKalemler($b['aksesuar_kalemleri']);$map=array_column($rows,null,'kod');
    foreach($ref['kalemler'] as $r){
        if(!isset($map[$r['kod']]))throw new DomainException('Beklenen her aksesuarı sayın; yoksa sıfır yazın.');
        $n=$map[$r['kod']];if($n['ad']!==$r['ad']||$n['seri']!==$r['seri'])throw new DomainException('Referans aksesuar kimliği değiştirilemez. Farklı parçayı ayrı satıra ekleyin.');
        if($n['adet']!==$r['adet']&&trim($n['not'])==='')throw new DomainException($r['ad'].': adet farkının açıklaması gerekli.');
    }
    return $rows;
}
function hzFarklar(array $eski,array $yeni): array {
    $a=array_column($eski,null,'kod');$b=array_column($yeni,null,'kod');$out=[];
    foreach(array_unique([...array_keys($a),...array_keys($b)]) as $key){$x=$a[$key]??null;$y=$b[$key]??null;
        $uyum=$x&&$y&&$x['ad']===$y['ad']&&$x['seri']===$y['seri'];
        $out[]=['kod'=>$key,'ad'=>($y??$x)['ad'],'seri'=>($y??$x)['seri'],'verilen'=>$x['adet']??null,'alinan'=>$y['adet']??null,'fark'=>$uyum?$x['adet']-$y['adet']:null,'durum'=>$y['durum']??'sayilmadi','not'=>$y['not']??'','kimlik_uyum'=>$uyum];
    }return $out;
}
function hzTutanakFark(PDO $db,array $t): array {
    if($t['aksesuar_kalemleri']===null||$t['islem']!=='iade'||!(int)($t['fiili_teslim']??1))return [];
    $ref=json_decode($t['aksesuar_referans_veri']??'null',true)??hzReferans($db,(int)$t['kiralama_id'],'iade',$t['tarih']);if($ref['anahtar']==='yok')return [];
    return array_values(array_filter(hzFarklar($ref['kalemler'],json_decode($t['aksesuar_kalemleri'],true)),fn($r)=>$r['fark']!==0||$r['durum']!=='saglam'||!$r['kimlik_uyum']));
}
