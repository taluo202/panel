<?php
declare(strict_types=1);
require_once __DIR__ . '/servis-kalem-lib.php';
require_once __DIR__ . '/saha-servis-lib.php';

const SK_SABLON_TOHUM_BAYRAK = 'servis_sablon_tohum';
const SK_SERVIS_TIPLERI = ['ariza', 'bakim', 'expertiz'];
const SK_KIRA_DURUM_ET = ['aktif' => 'Aktif', 'tamamlandi' => 'Tamamlandı', 'iptal' => 'İptal'];
const SK_SAHA_EN_COK = 30;

const SK_VARSAYILAN_SABLONLAR = [
    [
        'ad' => 'Makaslı Platform — Periyodik Bakım', 'makine_tur' => 'manlift', 'servis_tipi' => 'bakim', 'kategori_esle' => 'makaslı, dikey, direk',
        'kalemler' => [
            ['Belgeler ve etiketler', 'Kullanım ve bakım kılavuzu makinede mevcut'],
            ['Belgeler ve etiketler', 'Kapasite, kişi sayısı ve rüzgâr sınırı etiketleri yerinde ve okunaklı'],
            ['Belgeler ve etiketler', 'Uyarı ve kumanda etiketleri yerinde ve okunaklı'],
            ['Belgeler ve etiketler', 'Periyodik kontrol (yıllık muayene) belgesi geçerli'],
            ['Belgeler ve etiketler', 'Seri no plakası okunaklı ve kayıtla aynı'],
            ['Hidrolik sistem', 'Hidrolik yağ seviyesi uygun (platform tam kapalıyken)'],
            ['Hidrolik sistem', 'Hortum, rakor ve borularda kaçak, çatlak veya sürtünme yok'],
            ['Hidrolik sistem', 'Kaldırma silindiri ve keçelerde kaçak yok; platform kendiliğinden inmiyor'],
            ['Hidrolik sistem', 'Hidrolik yağ filtresi kontrol edildi / bakım aralığındaysa değiştirildi'],
            ['Hidrolik sistem', 'Pompa ve motor bağlantıları sağlam, anormal ses yok'],
            ['Elektrik ve akü', 'Akü su seviyesi uygun, kutup başları temiz ve sıkı'],
            ['Elektrik ve akü', 'Akü şarj cihazı ve şarj kablosu sağlam, şarj ediyor'],
            ['Elektrik ve akü', 'Kablo demetleri ve soketlerde ezilme, aşınma veya gevşeklik yok'],
            ['Elektrik ve akü', 'Gösterge paneli, korna, flaşör ve hareket alarmı çalışıyor'],
            ['Şasi, tekerler ve lastikler', 'Lastik / tekerlekler hasarsız; bijon ve aks somunları sıkı'],
            ['Şasi, tekerler ve lastikler', 'Makas kolları, pimler ve burçlarda çatlak, eğilme veya aşırı boşluk yok'],
            ['Şasi, tekerler ve lastikler', 'Çukur koruma (pothole) sistemi açılıp kapanıyor'],
            ['Şasi, tekerler ve lastikler', 'Yürüyüş, direksiyon ve fren düzgün çalışıyor'],
            ['Şasi, tekerler ve lastikler', 'Şasi ve kaynak yerlerinde çatlak veya deformasyon yok'],
            ['Platform ve korkuluklar', 'Korkuluk, ara korkuluk ve etek levhası sağlam; pimler takılı'],
            ['Platform ve korkuluklar', 'Giriş kapısı / zinciri kendiliğinden kapanıyor'],
            ['Platform ve korkuluklar', 'Uzatma tablası rahat kayıyor ve kilitleniyor'],
            ['Platform ve korkuluklar', 'Emniyet kemeri bağlantı noktaları sağlam'],
            ['Platform ve korkuluklar', 'Platform zemini hasarsız ve kaymaz'],
            ['Kumanda ve acil stop', 'Platform kumandası tüm hareketlerde düzgün (etkinleştirme / ölü adam anahtarı dahil)'],
            ['Kumanda ve acil stop', 'Zemin kumandası çalışıyor ve platform kumandasından öncelikli'],
            ['Kumanda ve acil stop', 'Zemin ve platform acil stop butonları tüm hareketi durduruyor'],
            ['Kumanda ve acil stop', 'Acil (manuel) indirme sistemi çalışıyor'],
            ['Güvenlik donanımları', 'Eğim (tilt) alarmı devrede; eğimde kaldırma ve yürüyüş kesiliyor'],
            ['Güvenlik donanımları', 'Aşırı yük algılama sistemi çalışıyor'],
            ['Güvenlik donanımları', 'Limit anahtarları çalışıyor (yükseklikte yürüyüş hızı kesme dahil)'],
            ['Güvenlik donanımları', 'Bakım dayama kolu (güvenlik desteği) yerinde ve çalışıyor'],
            ['Test ve kapanış', 'Tam yükseklikte kaldırma-indirme testi sorunsuz; anormal ses veya titreşim yok'],
            ['Test ve kapanış', 'Yağlama noktaları gresle yağlandı'],
            ['Test ve kapanış', 'Makine temizlendi, çalışma saati kaydedildi'],
        ],
    ],
    [
        'ad' => 'Eklemli / Teleskopik Platform — Periyodik Bakım', 'makine_tur' => 'manlift', 'servis_tipi' => 'bakim', 'kategori_esle' => 'eklemli, teleskopik',
        'kalemler' => [
            ['Belgeler ve etiketler', 'Kullanım ve bakım kılavuzu makinede mevcut'],
            ['Belgeler ve etiketler', 'Kapasite (sepet yükü), kişi sayısı ve rüzgâr sınırı etiketleri okunaklı'],
            ['Belgeler ve etiketler', 'Periyodik kontrol (yıllık muayene) belgesi geçerli'],
            ['Belgeler ve etiketler', 'Seri no plakası okunaklı ve kayıtla aynı'],
            ['Motor (dizel modeller)', 'Motor yağı seviyesi uygun, kaçak yok'],
            ['Motor (dizel modeller)', 'Yakıt sistemi, yakıt ve hava filtreleri kontrol edildi'],
            ['Motor (dizel modeller)', 'Soğutma suyu seviyesi, radyatör ve kayışlar uygun'],
            ['Motor (dizel modeller)', 'Egzoz sistemi sağlam, anormal duman yok'],
            ['Hidrolik sistem', 'Hidrolik yağ seviyesi uygun (bom kapalıyken)'],
            ['Hidrolik sistem', 'Hortum, rakor ve borularda kaçak, çatlak veya sürtünme yok'],
            ['Hidrolik sistem', 'Bom, jib ve teleskop silindirlerinde kaçak yok; tutma valfleri bomu tutuyor (kayma yok)'],
            ['Hidrolik sistem', 'Hidrolik yağ filtresi kontrol edildi / bakım aralığındaysa değiştirildi'],
            ['Elektrik ve akü', 'Akü seviyesi ve kutup başları uygun; şarj sistemi çalışıyor'],
            ['Elektrik ve akü', 'Kablo demetleri, bom üstü kablo zinciri ve soketler sağlam'],
            ['Elektrik ve akü', 'Gösterge, korna, flaşör ve hareket alarmı çalışıyor'],
            ['Şasi, tekerler ve lastikler', 'Lastikler hasarsız; bijon ve aks somunları sıkı'],
            ['Şasi, tekerler ve lastikler', 'Aks salınım (osilasyon) kilidi çalışıyor'],
            ['Şasi, tekerler ve lastikler', 'Yürüyüş, direksiyon ve fren düzgün çalışıyor'],
            ['Şasi, tekerler ve lastikler', 'Şasi ve kaynak yerlerinde çatlak veya deformasyon yok'],
            ['Bom ve döner tabla', 'Bom, jib ve teleskop bölümlerinde çatlak, eğilme veya ezilme yok'],
            ['Bom ve döner tabla', 'Bom pimleri, burçlar ve pim kilitleri sağlam; aşırı boşluk yok'],
            ['Bom ve döner tabla', 'Teleskop kayar pabuçları (aşınma pedleri), zincir / halatlar ayarlı'],
            ['Bom ve döner tabla', 'Döner tabla dişlisi, rulmanı ve dönüş freni sağlam; bağlantı cıvataları sıkı'],
            ['Bom ve döner tabla', 'Sepet seviyeleme (otomatik denge) sistemi çalışıyor'],
            ['Platform ve korkuluklar', 'Sepet korkulukları ve giriş kapısı sağlam, kapı kendiliğinden kapanıyor'],
            ['Platform ve korkuluklar', 'Emniyet kemeri bağlantı noktaları sağlam'],
            ['Platform ve korkuluklar', 'Sepet döndürme (rotator) çalışıyor, boşluk yok'],
            ['Kumanda ve acil stop', 'Sepet kumandası ve ayak pedalı tüm hareketlerde düzgün'],
            ['Kumanda ve acil stop', 'Zemin kumandası çalışıyor ve sepet kumandasından öncelikli'],
            ['Kumanda ve acil stop', 'Zemin ve sepet acil stop butonları tüm hareketi durduruyor'],
            ['Kumanda ve acil stop', 'Yardımcı (acil) güç ünitesi / acil indirme çalışıyor'],
            ['Güvenlik donanımları', 'Eğim (tilt) alarmı devrede'],
            ['Güvenlik donanımları', 'Yük algılama / moment sınırlayıcı çalışıyor'],
            ['Güvenlik donanımları', 'Çalışma alanı sınırlayıcıları ve limit anahtarları çalışıyor'],
            ['Güvenlik donanımları', 'Bom yükseltilmişken yürüyüş hızı kesiliyor'],
            ['Test ve kapanış', 'Tüm hareketler tam stroklu test edildi; anormal ses veya titreşim yok'],
            ['Test ve kapanış', 'Yağlama noktaları gresle yağlandı'],
            ['Test ve kapanış', 'Makine temizlendi, çalışma saati kaydedildi'],
        ],
    ],
    [
        'ad' => 'Örümcek / Paletli Platform — Periyodik Bakım', 'makine_tur' => 'manlift', 'servis_tipi' => 'bakim', 'kategori_esle' => 'örümcek, paletli',
        'kalemler' => [
            ['Belgeler ve etiketler', 'Kullanım ve bakım kılavuzu makinede mevcut'],
            ['Belgeler ve etiketler', 'Kapasite, kişi sayısı ve rüzgâr sınırı etiketleri okunaklı'],
            ['Belgeler ve etiketler', 'Periyodik kontrol (yıllık muayene) belgesi geçerli'],
            ['Motor ve güç ünitesi', 'Motor yağı (benzinli / dizel) seviyesi uygun, kaçak yok'],
            ['Motor ve güç ünitesi', 'Yakıt ve hava filtreleri kontrol edildi'],
            ['Motor ve güç ünitesi', 'Akü / elektrik motoru ve şarj sistemi çalışıyor (akülü modeller)'],
            ['Hidrolik sistem', 'Hidrolik yağ seviyesi uygun'],
            ['Hidrolik sistem', 'Hortum, rakor ve borularda kaçak, çatlak veya sürtünme yok'],
            ['Hidrolik sistem', 'Bom, ayak ve palet silindirlerinde kaçak yok; tutma valfleri tutuyor'],
            ['Hidrolik sistem', 'Hidrolik yağ filtresi kontrol edildi / bakım aralığındaysa değiştirildi'],
            ['Paletler ve yürüyüş', 'Palet gerginliği ayarlı; paletlerde kesik, yırtık veya aşırı aşınma yok'],
            ['Paletler ve yürüyüş', 'Palet makaraları, avare ve tahrik dişlisi sağlam'],
            ['Paletler ve yürüyüş', 'Palet genişletme (varsa) açılıp kapanıyor'],
            ['Paletler ve yürüyüş', 'Yürüyüş ve park freni düzgün çalışıyor'],
            ['Ayaklar (stabilizatörler)', 'Destek ayakları açılıp kapanıyor; ayak tabanları ve pimleri sağlam'],
            ['Ayaklar (stabilizatörler)', 'Ayak emniyet sensörleri çalışıyor: ayaklar basmadan bom kalkmıyor'],
            ['Ayaklar (stabilizatörler)', 'Su terazisi / elektronik eğim göstergesi doğru gösteriyor'],
            ['Bom ve döner tabla', 'Bom, jib ve teleskop bölümlerinde çatlak veya eğilme yok'],
            ['Bom ve döner tabla', 'Bom pimleri, burçlar ve pim kilitleri sağlam'],
            ['Bom ve döner tabla', 'Döner tabla ve dönüş freni sağlam; bağlantı cıvataları sıkı'],
            ['Bom ve döner tabla', 'Sepet seviyeleme sistemi çalışıyor'],
            ['Platform ve korkuluklar', 'Sepet korkulukları ve giriş kapısı sağlam'],
            ['Platform ve korkuluklar', 'Emniyet kemeri bağlantı noktaları sağlam'],
            ['Kumanda ve acil stop', 'Uzaktan (telsiz / kablolu) kumanda tüm hareketlerde düzgün'],
            ['Kumanda ve acil stop', 'Sepet kumandası çalışıyor'],
            ['Kumanda ve acil stop', 'Tüm acil stop butonları hareketi durduruyor'],
            ['Kumanda ve acil stop', 'Acil indirme (el pompası / yardımcı güç) çalışıyor'],
            ['Güvenlik donanımları', 'Eğim (tilt) alarmı devrede'],
            ['Güvenlik donanımları', 'Yük sınırlayıcı çalışıyor'],
            ['Güvenlik donanımları', 'Çalışma alanı sınırlayıcıları ve limit anahtarları çalışıyor'],
            ['Test ve kapanış', 'Tüm hareketler tam stroklu test edildi; anormal ses veya titreşim yok'],
            ['Test ve kapanış', 'Yağlama noktaları gresle yağlandı'],
            ['Test ve kapanış', 'Makine temizlendi, çalışma saati kaydedildi'],
        ],
    ],
    [
        'ad' => 'Forklift — Periyodik Bakım', 'makine_tur' => 'manlift', 'servis_tipi' => 'bakim', 'kategori_esle' => 'forklift, istif',
        'kalemler' => [
            ['Belgeler ve etiketler', 'Kapasite (yük diyagramı) etiketi yerinde ve okunaklı'],
            ['Belgeler ve etiketler', 'Periyodik kontrol (yıllık muayene) belgesi geçerli'],
            ['Belgeler ve etiketler', 'Uyarı etiketleri ve seri no plakası okunaklı'],
            ['Motor ve güç ünitesi', 'Motor yağı ve filtresi kontrol edildi / bakım aralığındaysa değiştirildi'],
            ['Motor ve güç ünitesi', 'Soğutma suyu, radyatör ve kayışlar uygun'],
            ['Motor ve güç ünitesi', 'Hava ve yakıt filtreleri kontrol edildi'],
            ['Motor ve güç ünitesi', 'Egzoz sistemi sağlam, anormal duman yok'],
            ['Motor ve güç ünitesi', 'Akü ve şarj sistemi çalışıyor (elektrikli modeller)'],
            ['Hidrolik sistem', 'Hidrolik yağ seviyesi uygun'],
            ['Hidrolik sistem', 'Hortum ve rakorlarda kaçak yok'],
            ['Hidrolik sistem', 'Kaldırma ve devirme silindirlerinde kaçak yok; yük altında çatal kendiliğinden inmiyor'],
            ['Direk, çatal ve zincir', 'Direk (mast) rayları, makaralar ve kızaklar sağlam'],
            ['Direk, çatal ve zincir', 'Kaldırma zincirleri gergin, uzama yok, yağlandı'],
            ['Direk, çatal ve zincir', 'Çatallarda çatlak, eğilme veya aşırı topuk aşınması yok; uçlar hizalı'],
            ['Direk, çatal ve zincir', 'Çatal kilitleri, çatal taşıyıcı ve yük sırtlığı sağlam'],
            ['Direk, çatal ve zincir', 'Yan kaydırma (varsa) düzgün çalışıyor'],
            ['Şasi, tekerler ve lastikler', 'Lastikler, jantlar ve bijonlar sağlam'],
            ['Şasi, tekerler ve lastikler', 'Direksiyon boşluğu normal'],
            ['Şasi, tekerler ve lastikler', 'Servis freni ve el freni tutuyor'],
            ['Şasi, tekerler ve lastikler', 'Karşı ağırlık bağlantısı sağlam'],
            ['Kabin ve güvenlik', 'Operatör koruyucu üst tavan sağlam'],
            ['Kabin ve güvenlik', 'Emniyet kemeri ve koltuk (operatör varlık) anahtarı çalışıyor'],
            ['Kabin ve güvenlik', 'Korna, geri vites alarmı, farlar ve döner lamba çalışıyor'],
            ['Kabin ve güvenlik', 'Aynalar sağlam'],
            ['Kumanda', 'Kaldırma, indirme ve devirme kolları düzgün, bırakınca nötre dönüyor'],
            ['Kumanda', 'Yön / vites kolu düzgün çalışıyor'],
            ['Kumanda', 'Gösterge paneli uyarı lambaları çalışıyor'],
            ['Test ve kapanış', 'Yükle kaldırma ve yürüyüş testi sorunsuz'],
            ['Test ve kapanış', 'Yağlama noktaları gresle yağlandı'],
            ['Test ve kapanış', 'Makine temizlendi, çalışma saati kaydedildi'],
        ],
    ],
    [
        'ad' => 'Araç — Periyodik Bakım', 'makine_tur' => 'arac', 'servis_tipi' => 'bakim', 'kategori_esle' => '',
        'kalemler' => [
            ['Belgeler', 'Ruhsat ve araç evrakları araçta mevcut'],
            ['Belgeler', 'Muayene tarihi kontrol edildi'],
            ['Motor', 'Motor yağı ve yağ filtresi değiştirildi / seviye uygun'],
            ['Motor', 'Hava, yakıt ve polen filtreleri kontrol edildi'],
            ['Motor', 'Soğutma suyu / antifriz seviyesi uygun'],
            ['Motor', 'Kayışlar ve hortumlar sağlam'],
            ['Motor', 'Motor altında yağ veya su kaçağı yok'],
            ['Fren ve yürüyen aksam', 'Fren balata ve diskleri kontrol edildi'],
            ['Fren ve yürüyen aksam', 'Fren hidroliği seviyesi uygun'],
            ['Fren ve yürüyen aksam', 'El freni tutuyor'],
            ['Fren ve yürüyen aksam', 'Amortisörler ve süspansiyon sağlam'],
            ['Fren ve yürüyen aksam', 'Direksiyon boşluğu normal, ön düzen sorunsuz'],
            ['Lastikler', 'Lastik diş derinliği ve basınçları uygun'],
            ['Lastikler', 'Stepne mevcut ve şişik; bijonlar sıkı'],
            ['Elektrik ve aydınlatma', 'Akü ve şarj sistemi çalışıyor'],
            ['Elektrik ve aydınlatma', 'Farlar, stop, sinyal ve geri vites lambaları çalışıyor'],
            ['Elektrik ve aydınlatma', 'Korna, silecekler ve cam suyu çalışıyor'],
            ['Kabin ve güvenlik', 'Emniyet kemerleri çalışıyor'],
            ['Kabin ve güvenlik', 'Aynalar sağlam'],
            ['Kabin ve güvenlik', 'Yangın söndürücü, ilk yardım çantası ve reflektör araçta'],
            ['Üst ekipman (vinç / platform varsa)', 'Üst ekipmanın hidrolik sistemi, ayakları ve kumandaları çalışıyor'],
            ['Üst ekipman (vinç / platform varsa)', 'Üst ekipman periyodik kontrol belgesi geçerli'],
            ['Test ve kapanış', 'Yol testi sorunsuz'],
            ['Test ve kapanış', 'Kilometre / sayaç kaydedildi'],
        ],
    ],
    [
        'ad' => 'Arıza — Ön Kontrol (Genel)', 'makine_tur' => null, 'servis_tipi' => 'ariza', 'kategori_esle' => '',
        'kalemler' => [
            ['Bildirim ve tespit', 'Arıza müşteriden / operatörden dinlendi, belirti not edildi'],
            ['Bildirim ve tespit', 'Hata kodu veya gösterge uyarısı okundu ve not edildi'],
            ['Bildirim ve tespit', 'Arıza tekrarlanabildi (hangi harekette olduğu not edildi)'],
            ['Güvenlik', 'Makine güvenli alana alındı, "kullanım dışı" uyarısı asıldı'],
            ['Güvenlik', 'Acil stop çalışıyor'],
            ['Güvenlik', 'Platform / bom güvenli konuma indirildi veya desteklendi'],
            ['Görsel kontrol', 'Hidrolik kaçak kontrol edildi'],
            ['Görsel kontrol', 'Kablo, soket ve sigorta kutusu kontrol edildi'],
            ['Görsel kontrol', 'Mekanik hasar (çarpma, eğilme, kırık) kontrol edildi'],
            ['Görsel kontrol', 'Lastik / palet durumu kontrol edildi'],
            ['Sistem kontrolleri', 'Akü gerilimi ve şarj durumu ölçüldü'],
            ['Sistem kontrolleri', 'Hidrolik yağ seviyesi kontrol edildi'],
            ['Sistem kontrolleri', 'Yakıt ve motor yağı seviyesi kontrol edildi (motorlu makinede)'],
            ['Sistem kontrolleri', 'Sigortalar ve röleler kontrol edildi'],
            ['Karar', 'Arıza nedeni belirlendi'],
            ['Karar', 'Gereken parça belirlendi / parça talebi açıldı'],
            ['Karar', 'Yerinde onarım mı, atölyeye mi gelecek kararı verildi'],
            ['Karar', 'Kullanım hatası veya hasar şüphesi varsa not edildi ve fotoğraflandı'],
        ],
    ],
    [
        'ad' => 'Expertiz (Genel)', 'makine_tur' => null, 'servis_tipi' => 'expertiz', 'kategori_esle' => '',
        'kalemler' => [
            ['Kimlik ve belgeler', 'Seri no plakası okunaklı ve kayıtla aynı'],
            ['Kimlik ve belgeler', 'İmal yılı ve çalışma saati / kilometre kaydedildi'],
            ['Kimlik ve belgeler', 'Kullanım kılavuzu ve etiketler mevcut'],
            ['Kimlik ve belgeler', 'Periyodik kontrol belgesi ve geçmiş bakım kayıtları incelendi'],
            ['Gövde ve şasi', 'Şasi, kaynak yerleri ve bağlantılarda çatlak veya deformasyon yok'],
            ['Gövde ve şasi', 'Boya, korozyon ve darbe izleri not edildi ve fotoğraflandı'],
            ['Gövde ve şasi', 'Lastik / palet aşınması not edildi'],
            ['Kaldırma yapısı', 'Makas kolları / bom, pim ve burç boşlukları kontrol edildi'],
            ['Kaldırma yapısı', 'Silindirlerde kaçak veya kayma yok'],
            ['Hidrolik ve motor', 'Hidrolik yağın rengi ve kirliliği kontrol edildi'],
            ['Hidrolik ve motor', 'Hidrolik kaçak yok'],
            ['Hidrolik ve motor', 'Motor (varsa) çalışma, duman, ses ve kaçak kontrol edildi'],
            ['Elektrik ve akü', 'Akü sağlığı / kapasitesi ölçüldü'],
            ['Elektrik ve akü', 'Kablo tesisatı ve kumanda kutuları sağlam'],
            ['Elektrik ve akü', 'Hata kodu geçmişi okundu'],
            ['Fonksiyon ve güvenlik testi', 'Tüm hareketler (kaldırma, yürüyüş, direksiyon, dönüş) tam stroklu test edildi'],
            ['Fonksiyon ve güvenlik testi', 'Acil stop ve acil indirme çalışıyor'],
            ['Fonksiyon ve güvenlik testi', 'Eğim alarmı, yük algılama ve limit anahtarları çalışıyor'],
            ['Sonuç', 'Eksik veya hasarlı parçalar listelendi'],
            ['Sonuç', 'Tahmini onarım ihtiyacı not edildi'],
            ['Sonuç', 'Genel durum değerlendirmesi yazıldı (iyi / orta / kötü)'],
        ],
    ],
];

function skServisTipi($v): ?string {
    return in_array($v, SK_SERVIS_TIPLERI, true) ? $v : null;
}

function skKategoriEsle($v): string {
    $parca = [];
    foreach (explode(',', metin($v)) as $p) {
        $p = trim(preg_replace('/\s+/u', ' ', $p) ?? '');
        if ($p !== '' && !in_array(trArama($p), array_map('trArama', $parca), true)) $parca[] = $p;
    }
    return mb_substr(implode(', ', $parca), 0, 200);
}

function skSema(PDO $db): void {
    try {
        $var = array_flip(array_column($db->query('PRAGMA table_info(servis_kontrol_sablonlari)')->fetchAll(PDO::FETCH_ASSOC), 'name'));
        if (!$var) return;
        foreach (['servis_tipi' => 'TEXT', 'kategori_esle' => 'TEXT'] as $ad => $tanim) {
            if (!isset($var[$ad])) $db->exec("ALTER TABLE servis_kontrol_sablonlari ADD COLUMN $ad $tanim");
        }
    } catch (\Throwable $e) {
        error_log('skSema: ' . $e->getMessage());
        return;
    }
    skSablonTohum($db);
}

function skSablonTohum(PDO $db): int {
    try {
        $q = $db->prepare('SELECT 1 FROM ayarlar WHERE anahtar=?');
        $q->execute([SK_SABLON_TOHUM_BAYRAK]);
        if ($q->fetchColumn()) return 0;
    } catch (\Throwable $e) { return 0; }
    $kendi = !$db->inTransaction();
    $eklenen = 0;
    try {
        if ($kendi) $db->beginTransaction();
        $bayrak = $db->prepare('INSERT OR IGNORE INTO ayarlar(anahtar, deger) VALUES(?, ?)');
        $bayrak->execute([SK_SABLON_TOHUM_BAYRAK, date('Y-m-d H:i:s')]);
        if ($bayrak->rowCount() === 1 && (int)$db->query('SELECT COUNT(*) FROM servis_kontrol_sablonlari')->fetchColumn() === 0) {
            $sb = $db->prepare('INSERT INTO servis_kontrol_sablonlari (ad, makine_tur, servis_tipi, kategori_esle, aktif) VALUES (?,?,?,?,1)');
            $kl = $db->prepare('INSERT INTO servis_kontrol_sablon_kalemleri (sablon_id, sira, kategori, madde) VALUES (?,?,?,?)');
            foreach (SK_VARSAYILAN_SABLONLAR as $s) {
                $sb->execute([$s['ad'], $s['makine_tur'], $s['servis_tipi'], $s['kategori_esle'] !== '' ? $s['kategori_esle'] : null]);
                $sid = (int)$db->lastInsertId();
                foreach ($s['kalemler'] as $i => [$kategori, $madde]) $kl->execute([$sid, $i + 1, $kategori, $madde]);
                $eklenen++;
            }
            $db->prepare('UPDATE ayarlar SET deger=? WHERE anahtar=?')->execute([date('Y-m-d H:i:s') . ' · ' . $eklenen . ' varsayılan şablon', SK_SABLON_TOHUM_BAYRAK]);
        }
        if ($kendi) $db->commit();
    } catch (\Throwable $e) {
        if ($kendi && $db->inTransaction()) $db->rollBack();
        error_log('skSablonTohum: ' . $e->getMessage());
        return 0;
    }
    return $eklenen;
}

function skMakineSelect(): string {
    return " COALESCE(m.kod, a.plaka) AS makine_kod, COALESCE(m.marka, a.marka) AS makine_marka, COALESCE(m.model, a.model) AS makine_model,
             COALESCE(m.seri_no, a.ruhsat_no) AS makine_seri_no,
             CASE WHEN a.id IS NOT NULL THEN COALESCE(NULLIF(TRIM(a.kategori),''), 'Araç') ELSE m.kategori END AS makine_kategori,
             COALESCE(m.makine_kodu, a.makine_kodu) AS makine_stok_kodu, COALESCE(m.metraj, a.metraj) AS makine_metraj";
}

function skMakineJoin(string $k = 'k'): string {
    return " LEFT JOIN manliftler m ON ($k.makine_tur='manlift' AND m.id=$k.makine_id)
             LEFT JOIN araclar a ON ($k.makine_tur='arac' AND a.id=$k.makine_id)";
}

function skKiraKart(PDO $db, array $r): array {
    static $birim = [];
    $kat = trim((string)($r['makine_kategori'] ?? ''));
    $metraj = metrajSayi($r['makine_metraj'] ?? null);
    if ($metraj !== null) $birim[$kat] ??= mkBirim($db, $kat !== '' ? $kat : null);
    return [
        'kiralama_id' => (int)$r['id'], 'kod' => $r['kod'] ?? null, 'siparis_no' => trim((string)($r['siparis_no'] ?? '')) ?: null,
        'musteri_id' => isset($r['musteri_id']) ? (int)$r['musteri_id'] : null, 'musteri_unvan' => $r['musteri_unvan'] ?? null,
        'ekipman_adi' => $r['ekipman_adi'] ?? null, 'lokasyon' => trim((string)($r['lokasyon'] ?? '')) ?: null, 'il' => trim((string)($r['il'] ?? '')) ?: null,
        'baslangic' => $r['baslangic'] ?? null, 'bitis' => ($r['gercek_bitis'] ?? null) ?: ($r['bitis'] ?? null), 'gercek_bitis' => $r['gercek_bitis'] ?? null,
        'durum' => $r['durum'] ?? null, 'durum_etiket' => SK_KIRA_DURUM_ET[$r['durum'] ?? ''] ?? ($r['durum'] ?? null),
        'makine_tur' => $r['makine_tur'] ?? null, 'makine_id' => isset($r['makine_id']) ? (int)$r['makine_id'] : null,
        'makine_kod' => $r['makine_kod'] ?? null, 'makine_marka' => $r['makine_marka'] ?? null, 'makine_model' => $r['makine_model'] ?? null,
        'makine_seri_no' => $r['makine_seri_no'] ?? null, 'makine_kategori' => $kat !== '' ? $kat : null, 'makine_stok_kodu' => $r['makine_stok_kodu'] ?? null,
        'makine_metraj' => $metraj, 'makine_olcu_birim' => $metraj !== null ? $birim[$kat] : null,
    ];
}

function skTarihEt(?string $t): string {
    $d = $t ? DateTime::createFromFormat('Y-m-d', substr($t, 0, 10)) : false;
    return $d ? $d->format('d.m.Y') : (string)$t;
}

function skKiralamaUygunMu(?array $k, ?string $tur, ?int $mid, string $tarih): ?string {
    if (!$k) return 'Kiralama bulunamadı.';
    $kisit = subeKisitiHam();
    if ($kisit !== null && trim((string)($k['sube'] ?? '')) !== $kisit) return 'Bu kiralama başka bir şubeye ait.';
    if (($k['makine_tur'] ?? null) !== $tur || (int)($k['makine_id'] ?? 0) !== (int)$mid) return 'Bu kiralama servisteki makineye ait değil.';
    if (($k['durum'] ?? '') === 'iptal') return 'İptal edilmiş kiralama seçilemez.';
    if ((string)($k['baslangic'] ?? '') > $tarih) return 'Kiralama servis tarihinde (' . skTarihEt($tarih) . ') henüz başlamamıştı; başlangıç ' . skTarihEt($k['baslangic'] ?? null) . '.';
    return null;
}

function skServisSatir(PDO $db, int $sid): array {
    $q = $db->prepare('SELECT * FROM teknik_servis WHERE id=?');
    $q->execute([$sid]);
    $s = $q->fetch(PDO::FETCH_ASSOC);
    if (!$s) throw new DomainException('Servis kaydı bulunamadı.');
    return $s;
}

function skKilitSebebi(PDO $db, array $s): ?string {
    if (!in_array($s['makine_tur'] ?? '', ['manlift', 'arac'], true) || empty($s['makine_id'])) {
        return 'Bu servis filo makinesine bağlı değil (müşterinin makinesi ya da makinesiz kayıt); kiralama seçilemez.';
    }
    if ((int)($s['faturalandi'] ?? 0) === 1) return 'Faturalandırılmış servisin kaynak kiralaması değiştirilemez.';
    if (!empty($s['tutanak_id']) || tsDepoServisiMi($db, $s)) return 'Bu servis iade tutanağından / dönüş kontrolünden açıldı; kaynak kiralama belgeyle sabittir.';
    $kisit = subeKisitiHam();
    if ($kisit !== null && !empty($s['kiralama_id'])) {
        $q = $db->prepare('SELECT sube FROM kiralamalar WHERE id=?');
        $q->execute([(int)$s['kiralama_id']]);
        $sube = $q->fetchColumn();
        if ($sube !== false && trim((string)$sube) !== $kisit) return 'Bu servis başka şubenin kiralamasına bağlı; kaynağını değiştiremezsiniz.';
    }
    return null;
}

function skMakineKiralamalari(PDO $db, ?string $tur, ?int $mid, string $tarih, ?int $mevcut = null): array {
    $out = ['kiralamalar' => [], 'gizli' => 0, 'oneri' => null, 'mevcut' => $mevcut];
    if (!in_array($tur, ['manlift', 'arac'], true) || !$mid) return $out;
    $kisit = subeKisitiHam();
    $q = $db->prepare("SELECT k.*, mu.unvan AS musteri_unvan, " . skMakineSelect() . "
                       FROM kiralamalar k LEFT JOIN musteriler mu ON mu.id=k.musteri_id" . skMakineJoin('k') . "
                       WHERE k.makine_tur=? AND k.makine_id=? AND k.durum<>'iptal'
                       ORDER BY k.baslangic DESC, k.id DESC LIMIT 200");
    $q->execute([$tur, $mid]);
    $gorunen = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($kisit !== null && trim((string)($r['sube'] ?? '')) !== $kisit) { $out['gizli']++; continue; }
        $sebep = skKiralamaUygunMu($r, $tur, $mid, $tarih);
        $out['kiralamalar'][] = skKiraKart($db, $r) + ['secilebilir' => $sebep === null, 'sebep' => $sebep];
        $gorunen[(int)$r['id']] = true;
    }
    $oneri = tsKaynakKiralamaBul($db, $tur, $mid, $tarih);
    $out['oneri'] = ($oneri && isset($gorunen[$oneri])) ? $oneri : null;
    return $out;
}

function skServisKiralamalari(PDO $db, int $sid): array {
    $s = skServisSatir($db, $sid);
    $mid = !empty($s['makine_id']) ? (int)$s['makine_id'] : null;
    $out = skMakineKiralamalari($db, $s['makine_tur'] ?? null, $mid, (string)$s['tarih'], !empty($s['kiralama_id']) ? (int)$s['kiralama_id'] : null);
    $out['kilit'] = skKilitSebebi($db, $s);
    $out['servis_tarih'] = $s['tarih'];
    return $out;
}

function skBagla(PDO $db, int $sid, int $kid): array {
    $s = skServisSatir($db, $sid);
    if ($kid < 1) throw new DomainException('Bağlanacak kiralamayı seçin.');
    if ($eng = skKilitSebebi($db, $s)) throw new DomainException($eng);
    $eskiId = !empty($s['kiralama_id']) ? (int)$s['kiralama_id'] : null;
    if ($eskiId === $kid) return ['kiralama_id' => $kid, 'degisti' => false];
    $q = $db->prepare('SELECT k.*, mu.unvan AS musteri_unvan FROM kiralamalar k LEFT JOIN musteriler mu ON mu.id=k.musteri_id WHERE k.id=?');
    $q->execute([$kid]);
    $k = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($sebep = skKiralamaUygunMu($k, $s['makine_tur'], (int)$s['makine_id'], (string)$s['tarih'])) throw new DomainException($sebep);
    $eskiKod = null;
    if ($eskiId) {
        $e = $db->prepare('SELECT kod FROM kiralamalar WHERE id=?');
        $e->execute([$eskiId]);
        $eskiKod = $e->fetchColumn() ?: ('#' . $eskiId);
    }
    $db->prepare("UPDATE teknik_servis SET kiralama_id=?, updated_at=datetime('now','localtime') WHERE id=?")->execute([$kid, $sid]);
    $yeniKod = $k['kod'] ?: ('#' . $kid);
    $ozet = 'Servis kaynağı ' . ($eskiKod ? $eskiKod . ' yerine ' : '') . $yeniKod . ' kiralamasına bağlandı'
          . (trim((string)($k['siparis_no'] ?? '')) !== '' ? ' · Sipariş ' . trim((string)$k['siparis_no']) : '')
          . (!empty($k['musteri_unvan']) ? ' · ' . $k['musteri_unvan'] : '');
    logla($db, 'teknik_servis', $sid, 'guncelle', ['kiralama_id' => $eskiId], ['kiralama_id' => $kid], $ozet);
    return ['kiralama_id' => $kid, 'degisti' => true, 'ozet' => $ozet];
}

function skSiparisAnahtar(array $k): string {
    $sip = trim((string)($k['siparis_no'] ?? ''));
    return $sip !== '' ? 'S:' . $sip . ':' . (int)($k['musteri_id'] ?? 0) : 'K:' . (int)$k['id'];
}

function skSahaBilgi(array $k, array $santiyeler): array {
    $lok = trim((string)($k['lokasyon'] ?? ''));
    $santiye = null;
    if ($lok !== '') {
        foreach ($santiyeler as $s) {
            if (trArama(trim((string)($s['ad'] ?? ''))) === trArama($lok) || ($s['adres'] !== null && trArama(trim((string)$s['adres'])) === trArama($lok))) { $santiye = $s; break; }
        }
    }
    $dolu = fn($v) => trim((string)($v ?? '')) !== '' ? trim((string)$v) : null;
    $adres = $santiye ? ($dolu($santiye['adres']) ?? $lok) : $lok;
    if ($dolu($k['saha_irtibat_ad'] ?? null) || $dolu($k['saha_irtibat_tel'] ?? null)) {
        [$ad, $tel, $kaynak] = [$dolu($k['saha_irtibat_ad'] ?? null), $dolu($k['saha_irtibat_tel'] ?? null), 'kiralama'];
    } elseif ($santiye && ($dolu($santiye['yetkili']) || $dolu($santiye['telefon']))) {
        [$ad, $tel, $kaynak] = [$dolu($santiye['yetkili']), $dolu($santiye['telefon']), 'santiye'];
    } elseif ($dolu($k['musteri_yetkili'] ?? null) || $dolu($k['musteri_telefon'] ?? null)) {
        [$ad, $tel, $kaynak] = [$dolu($k['musteri_yetkili'] ?? null), $dolu($k['musteri_telefon'] ?? null), 'musteri'];
    } else {
        [$ad, $tel, $kaynak] = [null, null, null];
    }
    return [
        'adres' => $adres !== '' ? $adres : null, 'il' => $dolu($k['il'] ?? null) ?? ($santiye ? $dolu($santiye['il']) : null),
        'ilce' => $santiye ? $dolu($santiye['ilce']) : null, 'santiye_id' => $santiye ? (int)$santiye['id'] : null,
        'santiye_ad' => $santiye ? $santiye['ad'] : null, 'irtibat_ad' => $ad, 'irtibat_tel' => $tel, 'irtibat_kaynak' => $kaynak,
    ];
}

function _skAktifSql(): string {
    return "SELECT k.*, mu.unvan AS musteri_unvan, mu.yetkili AS musteri_yetkili, mu.telefon AS musteri_telefon, " . skMakineSelect() . ",
                   (SELECT s.id FROM teknik_servis s WHERE s.makine_tur=k.makine_tur AND s.makine_id=k.makine_id AND s.durum IN ('acik','devam') ORDER BY s.id DESC LIMIT 1) AS acik_servis_id
            FROM kiralamalar k JOIN musteriler mu ON mu.id=k.musteri_id" . skMakineJoin('k');
}

function skAktifSebep(array $k, string $tarih): ?string {
    $kod = $k['kod'] ?: ('#' . (int)$k['id']);
    $kisit = subeKisitiHam();
    if ($kisit !== null && trim((string)($k['sube'] ?? '')) !== $kisit) return $kod . ' başka bir şubeye ait.';
    if (($k['durum'] ?? '') !== 'aktif' || !empty($k['gercek_bitis'])) return $kod . ' aktif kiralama değil (makine sahada görünmüyor).';
    if ((string)($k['baslangic'] ?? '') > $tarih) return $kod . ' henüz başlamadı (başlangıç ' . skTarihEt($k['baslangic'] ?? null) . ').';
    if (!in_array($k['makine_tur'] ?? '', ['manlift', 'arac'], true) || empty($k['makine_id'])) return $kod . ' filo makinesine bağlı değil (dış kiralama ya da serbest kalem); saha servisi açılamaz.';
    return null;
}

function skAktifKiralamalar(PDO $db, string $tarih): array {
    $kisit = subeKisitiHam();
    $sql = _skAktifSql() . " WHERE k.durum='aktif' AND k.gercek_bitis IS NULL AND k.baslangic<=?";
    $p = [$tarih];
    if ($kisit !== null) { $sql .= " AND TRIM(COALESCE(k.sube,''))=?"; $p[] = $kisit; }
    $sql .= ' ORDER BY k.baslangic DESC, k.id DESC LIMIT 1000';
    $q = $db->prepare($sql);
    $q->execute($p);
    $satirlar = $q->fetchAll(PDO::FETCH_ASSOC);
    $santiye = [];
    $sq = $db->prepare('SELECT id, ad, adres, il, ilce, yetkili, telefon FROM santiyeler WHERE musteri_id=? AND COALESCE(aktif,1)=1 ORDER BY id');
    $gruplar = [];
    foreach ($satirlar as $r) {
        $an = skSiparisAnahtar($r);
        $mid = (int)$r['musteri_id'];
        if (!isset($santiye[$mid])) { $sq->execute([$mid]); $santiye[$mid] = $sq->fetchAll(PDO::FETCH_ASSOC); }
        if (!isset($gruplar[$an])) {
            $gruplar[$an] = ['anahtar' => $an, 'siparis_no' => trim((string)($r['siparis_no'] ?? '')) ?: null, 'musteri_id' => $mid,
                             'musteri_unvan' => $r['musteri_unvan'], 'baslangic' => $r['baslangic'], 'kalemler' => []] + skSahaBilgi($r, $santiye[$mid]);
        }
        if ((string)$r['baslangic'] < (string)$gruplar[$an]['baslangic']) $gruplar[$an]['baslangic'] = $r['baslangic'];
        $sebep = skAktifSebep($r, $tarih);
        $gruplar[$an]['kalemler'][] = skKiraKart($db, $r) + ['acik_servis_id' => $r['acik_servis_id'] !== null ? (int)$r['acik_servis_id'] : null,
                                                         'secilebilir' => $sebep === null, 'sebep' => $sebep];
    }
    foreach ($gruplar as &$g) usort($g['kalemler'], fn($a, $b) => $a['kiralama_id'] <=> $b['kiralama_id']);
    unset($g);
    return array_values($gruplar);
}

function skKiralamadanSahaAc(PDO $db, array $b): array {
    $idler = [];
    foreach ((array)($b['kiralama_idler'] ?? []) as $x) {
        $x = (int)$x;
        if ($x > 0 && !in_array($x, $idler, true)) $idler[] = $x;
    }
    if (!$idler) throw new DomainException('Aktif kiralamadan en az bir makine işaretleyin.');
    if (count($idler) > SK_SAHA_EN_COK) throw new DomainException('Tek seferde en çok ' . SK_SAHA_EN_COK . ' makine için saha servisi açılabilir.');
    $tarih = dateStr('tarih', $b) ?: date('Y-m-d');
    $q = $db->prepare(_skAktifSql() . ' WHERE k.id IN (' . implode(',', array_fill(0, count($idler), '?')) . ')');
    $q->execute($idler);
    $kiralar = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $kiralar[(int)$r['id']] = $r;
    $anahtarlar = []; $makineler = [];
    foreach ($idler as $id) {
        if (!isset($kiralar[$id])) throw new DomainException('Seçilen kiralamalardan biri bulunamadı.');
        $k = $kiralar[$id];
        if ($sebep = skAktifSebep($k, $tarih)) throw new DomainException($sebep);
        $anahtarlar[skSiparisAnahtar($k)] = true;
        $mk = $k['makine_tur'] . ':' . (int)$k['makine_id'];
        if (isset($makineler[$mk])) throw new DomainException('Aynı makine iki kez seçildi.');
        $makineler[$mk] = true;
    }
    if (count($anahtarlar) > 1) throw new DomainException('Seçilen makineler aynı siparişe ait olmalı; her sipariş için ayrı açın.');
    $ilk = $kiralar[$idler[0]];
    $ortak = [
        'ariza' => $b['ariza'] ?? '', 'saha_adres' => $b['saha_adres'] ?? '', 'saha_il' => $b['saha_il'] ?? null, 'saha_ilce' => $b['saha_ilce'] ?? null,
        'santiye_id' => $b['santiye_id'] ?? null, 'irtibat_ad' => $b['irtibat_ad'] ?? null, 'irtibat_tel' => $b['irtibat_tel'] ?? null,
        'teknisyen_id' => $b['teknisyen_id'] ?? null, 'planlanan_tarih' => $b['planlanan_tarih'] ?? null, 'oncelik' => $b['oncelik'] ?? 'normal',
        'tip' => skServisTipi($b['tip'] ?? null) ?? 'ariza', 'tarih' => $tarih, 'musteri_id' => (int)$ilk['musteri_id'],
        'gerekce' => $b['gerekce'] ?? null, 'notlar' => $b['notlar'] ?? null,
    ];
    $kayitlar = []; $uyarilar = [];
    foreach ($idler as $id) {
        $k = $kiralar[$id];
        $benzer = teknikServisAcikKaydiBul($db, $k['makine_tur'], (int)$k['makine_id']);
        $sid = ssIsAc($db, $ortak + ['makine_tur' => $k['makine_tur'], 'makine_id' => (int)$k['makine_id'], 'kiralama_id' => $id]);
        $db->prepare('UPDATE teknik_servis SET kiralama_id=? WHERE id=? AND COALESCE(kiralama_id,0)<>?')->execute([$id, $sid, $id]);
        $etiket = trim((string)($k['makine_kod'] ?? '')) ?: ($k['ekipman_adi'] ?: ('#' . $id));
        $kayitlar[] = ['id' => $sid, 'kiralama_id' => $id, 'kiralama_kod' => $k['kod'], 'makine' => $etiket, 'benzer_acik_kayit' => $benzer ? (int)$benzer['id'] : null];
        if ($benzer) $uyarilar[] = $etiket . ': #' . (int)$benzer['id'] . ' numaralı açık servis kaydı da var.';
    }
    return ['idler' => array_column($kayitlar, 'id'), 'kayitlar' => $kayitlar, 'uyarilar' => $uyarilar];
}
