<?php
declare(strict_types=1);


function ilNormalize(string $s): string {
    $s = str_replace(
        ['İ','I','ı','i','Ş','ş','Ğ','ğ','Ü','ü','Ö','ö','Ç','ç','Â','â','Î','î','Û','û','Ê','ê'],
        ['I','I','I','I','S','S','G','G','U','U','O','O','C','C','A','A','I','I','U','U','E','E'],
        $s
    );
    $s = strtoupper($s);
    $s = preg_replace('/[^A-Z]+/', ' ', $s) ?? '';
    return trim(preg_replace('/\s+/', ' ', $s) ?? '');
}

function trIlListesi(): array {
    return array_keys(trIlIlce());
}

function trIlIlce(): array {
    static $m = null;
    if ($m !== null) return $m;
    $m = [
    'Adana' => ['Seyhan','Yüreğir','Çukurova','Sarıçam','Ceyhan','Kozan','İmamoğlu','Karataş','Karaisalı','Pozantı','Aladağ','Feke','Saimbeyli','Tufanbeyli','Yumurtalık'],
    'Adıyaman' => ['Besni','Çelikhan','Gerger','Gölbaşı','Kahta','Samsat','Sincik','Tut'],
    'Afyonkarahisar' => ['Başmakçı','Bayat','Bolvadin','Çay','Çobanlar','Dazkırı','Dinar','Emirdağ','Evciler','Hocalar','İhsaniye','İscehisar','Kızılören','Sandıklı','Sinanpaşa','Sultandağı','Şuhut'],
    'Ağrı' => ['Diyadin','Doğubayazıt','Eleşkirt','Hamur','Patnos','Taşlıçay','Tutak'],
    'Aksaray' => ['Ağaçören','Eskil','Gülağaç','Güzelyurt','Ortaköy','Sarıyahşi','Sultanhanı'],
    'Amasya' => ['Göynücek','Gümüşhacıköy','Hamamözü','Merzifon','Suluova','Taşova'],
    'Ankara' => ['Altındağ','Ayaş','Bala','Beypazarı','Çamlıdere','Çankaya','Çubuk','Elmadağ','Güdül','Haymana','Kalecik','Kızılcahamam','Nallıhan','Polatlı','Şereflikoçhisar','Yenimahalle','Gölbaşı','Keçiören','Mamak','Sincan','Kahramankazan','Akyurt','Etimesgut','Evren','Pursaklar'],
    'Antalya' => ['Akseki','Alanya','Elmalı','Finike','Gazipaşa','Gündoğmuş','Kaş','Korkuteli','Kumluca','Manavgat','Serik','Demre','İbradı','Kepez','Konyaaltı','Muratpaşa','Aksu','Döşemealtı','Kemer'],
    'Ardahan' => ['Çıldır','Göle','Hanak','Posof','Damal'],
    'Artvin' => ['Ardanuç','Arhavi','Borçka','Hopa','Şavşat','Yusufeli','Murgul','Kemalpaşa'],
    'Aydın' => ['Efeler','Bozdoğan','Buharkent','Çine','Didim','Germencik','İncirliova','Karacasu','Karpuzlu','Koçarlı','Köşk','Kuşadası','Kuyucak','Nazilli','Söke','Sultanhisar','Yenipazar'],
    'Balıkesir' => ['Altıeylül','Karesi','Ayvalık','Balya','Bandırma','Bigadiç','Burhaniye','Dursunbey','Edremit','Erdek','Gömeç','Gönen','Havran','İvrindi','Kepsut','Manyas','Marmara','Savaştepe','Sındırgı','Susurluk'],
    'Bartın' => ['Amasra','Kurucaşile','Ulus'],
    'Batman' => ['Beşiri','Gercüş','Kozluk','Sason','Hasankeyf'],
    'Bayburt' => ['Aydıntepe','Demirözü'],
    'Bilecik' => ['Bozüyük','Gölpazarı','Osmaneli','Pazaryeri','Söğüt','Yenipazar','İnhisar'],
    'Bingöl' => ['Genç','Karlıova','Kiğı','Solhan','Adaklı','Yayladere','Yedisu'],
    'Bitlis' => ['Adilcevaz','Ahlat','Hizan','Mutki','Tatvan','Güroymak'],
    'Bolu' => ['Gerede','Göynük','Kıbrıscık','Mengen','Mudurnu','Seben','Dörtdivan','Yeniçağa'],
    'Burdur' => ['Ağlasun','Bucak','Çavdır','Çeltikçi','Gölhisar','Tefenni','Yeşilova','Karamanlı','Altınyayla'],
    'Bursa' => ['Gemlik','İnegöl','İznik','Karacabey','Keles','Mudanya','Mustafakemalpaşa','Orhaneli','Orhangazi','Yenişehir','Büyükorhan','Harmancık','Nilüfer','Osmangazi','Yıldırım','Gürsu','Kestel'],
    'Denizli' => ['Acıpayam','Buldan','Çal','Çameli','Çardak','Çivril','Güney','Kale','Sarayköy','Tavas','Babadağ','Bekilli','Honaz','Serinhisar','Pamukkale','Baklan','Beyağaç','Bozkurt','Merkezefendi'],
    'Diyarbakır' => ['Kocaköy','Çermik','Çınar','Çüngüş','Dicle','Ergani','Hani','Hazro','Kulp','Lice','Silvan','Eğil','Bismil','Sur','Yenişehir','Bağlar','Kayapınar'],
    'Düzce' => ['Akçakoca','Yığılca','Cumayeri','Gölyaka','Çilimli','Gümüşova','Kaynaşlı'],
    'Edirne' => ['Enez','Havsa','İpsala','Keşan','Lalapaşa','Meriç','Uzunköprü','Süloğlu'],
    'Elazığ' => ['Ağın','Baskil','Karakoçan','Keban','Maden','Palu','Sivrice','Arıcak','Kovancılar','Alacakaya'],
    'Erzincan' => ['Çayırlı','İliç','Kemah','Kemaliye','Refahiye','Tercan','Üzümlü','Otlukbeli'],
    'Erzurum' => ['Aşkale','Çat','Hınıs','Horasan','İspir','Karayazı','Narman','Oltu','Olur','Pasinler','Şenkaya','Tekman','Tortum','Karaçoban','Uzundere','Pazaryolu','Palandöken','Yakutiye','Aziziye','Köprüköy'],
    'Eskişehir' => ['Çifteler','Mahmudiye','Mihalıççık','Sarıcakaya','Seyitgazi','Sivrihisar','Alpu','Beylikova','İnönü','Günyüzü','Han','Mihalgazi','Odunpazarı','Tepebaşı'],
    'Gaziantep' => ['Araban','İslahiye','Nizip','Oğuzeli','Yavuzeli','Şahinbey','Şehitkamil','Karkamış','Nurdağı'],
    'Giresun' => ['Alucra','Bulancak','Dereli','Espiye','Eynesil','Görele','Keşap','Şebinkarahisar','Tirebolu','Piraziz','Yağlıdere','Çamoluk','Çanakçı','Doğankent','Güce'],
    'Gümüşhane' => ['Kelkit','Şiran','Torul','Köse','Kürtün'],
    'Hakkari' => ['Çukurca','Şemdinli','Yüksekova','Derecik'],
    'Hatay' => ['Altınözü','Reyhanlı','Hassa','İskenderun','Kırıkhan','Samandağ','Yayladağı','Erzin','Belen','Kumlu','Antakya','Defne','Arsuz','Payas','Dörtyol'],
    'Isparta' => ['Atabey','Eğirdir','Gelendost','Keçiborlu','Senirkent','Sütçüler','Şarkikaraağaç','Uluborlu','Yalvaç','Yenişarbademli'],
    'İstanbul' => ['Adalar','Bakırköy','Beşiktaş','Beykoz','Beyoğlu','Çatalca','Eyüpsultan','Fatih','Gaziosmanpaşa','Kadıköy','Kartal','Sarıyer','Silivri','Şile','Şişli','Üsküdar','Zeytinburnu','Büyükçekmece','Kağıthane','Küçükçekmece','Pendik','Ümraniye','Bayrampaşa','Avcılar','Bağcılar','Bahçelievler','Güngören','Maltepe','Sultanbeyli','Tuzla','Esenler','Arnavutköy','Ataşehir','Başakşehir','Beylikdüzü','Çekmeköy','Esenyurt','Sancaktepe','Sultangazi'],
    'İzmir' => ['Aliağa','Bayındır','Bergama','Bornova','Çeşme','Dikili','Foça','Karaburun','Karşıyaka','Kemalpaşa','Kınık','Kiraz','Menemen','Ödemiş','Seferihisar','Selçuk','Tire','Torbalı','Urla','Beydağ','Buca','Konak','Menderes','Balçova','Çiğli','Gaziemir','Narlıdere','Güzelbahçe','Bayraklı','Karabağlar'],
    'Iğdır' => ['Aralık','Karakoyunlu','Tuzluca'],
    'Kahramanmaraş' => ['Afşin','Andırın','Elbistan','Göksun','Pazarcık','Türkoğlu','Çağlayancerit','Ekinözü','Nurhak','Dulkadiroğlu','Onikişubat'],
    'Karabük' => ['Eflani','Eskipazar','Ovacık','Safranbolu','Yenice'],
    'Karaman' => ['Ermenek','Ayrancı','Kazımkarabekir','Başyayla','Sarıveliler'],
    'Kars' => ['Arpaçay','Digor','Kağızman','Sarıkamış','Selim','Susuz','Akyaka'],
    'Kastamonu' => ['Abana','Araç','Azdavay','Bozkurt','Cide','Çatalzeytin','Daday','Devrekani','İnebolu','Küre','Taşköprü','Tosya','İhsangazi','Pınarbaşı','Şenpazar','Ağlı','Doğanyurt','Hanönü','Seydiler'],
    'Kayseri' => ['Develi','Felahiye','İncesu','Pınarbaşı','Sarıoğlan','Sarız','Tomarza','Yahyalı','Yeşilhisar','Akkışla','Talas','Bünyan','Özvatan','Hacılar','Kocasinan','Melikgazi'],
    'Kilis' => ['Elbeyli','Musabeyli','Polateli'],
    'Kırklareli' => ['Babaeski','Demirköy','Kofçaz','Lüleburgaz','Pehlivanköy','Pınarhisar','Vize'],
    'Kırşehir' => ['Çiçekdağı','Kaman','Mucur','Akpınar','Akçakent','Boztepe'],
    'Kocaeli' => ['Gebze','Gölcük','Kandıra','Karamürsel','Körfez','Derince','Başiskele','Çayırova','Darıca','Dilovası','İzmit','Kartepe'],
    'Konya' => ['Akşehir','Beyşehir','Bozkır','Cihanbeyli','Çumra','Doğanhisar','Ereğli','Hadim','Ilgın','Kadınhanı','Karapınar','Kulu','Sarayönü','Seydişehir','Yunak','Akören','Altınekin','Derebucak','Hüyük','Karatay','Meram','Selçuklu','Taşkent','Ahırlı','Çeltik','Derbent','Emirgazi','Güneysınır','Halkapınar','Tuzlukçu','Yalıhüyük'],
    'Kütahya' => ['Altıntaş','Domaniç','Emet','Gediz','Simav','Tavşanlı','Aslanapa','Dumlupınar','Hisarcık','Şaphane','Çavdarhisar','Pazarlar'],
    'Kırıkkale' => ['Delice','Keskin','Sulakyurt','Bahşılı','Balışeyh','Çelebi','Karakeçili','Yahşihan'],
    'Malatya' => ['Akçadağ','Arapgir','Arguvan','Darende','Doğanşehir','Hekimhan','Pütürge','Yeşilyurt','Battalgazi','Doğanyol','Kale','Kuluncak','Yazıhan'],
    'Manisa' => ['Akhisar','Alaşehir','Demirci','Gördes','Kırkağaç','Kula','Salihli','Sarıgöl','Saruhanlı','Selendi','Soma','Turgutlu','Ahmetli','Gölmarmara','Köprübaşı','Şehzadeler','Yunusemre'],
    'Mardin' => ['Derik','Kızıltepe','Mazıdağı','Midyat','Nusaybin','Ömerli','Savur','Dargeçit','Yeşilli','Artuklu'],
    'Mersin' => ['Anamur','Erdemli','Gülnar','Mut','Silifke','Tarsus','Aydıncık','Bozyazı','Çamlıyayla','Akdeniz','Mezitli','Toroslar','Yenişehir'],
    'Muğla' => ['Bodrum','Datça','Fethiye','Köyceğiz','Marmaris','Milas','Ula','Yatağan','Dalaman','Seydikemer','Ortaca','Kavaklıdere','Menteşe'],
    'Muş' => ['Bulanık','Malazgirt','Varto','Hasköy','Korkut'],
    'Nevşehir' => ['Avanos','Derinkuyu','Gülşehir','Hacıbektaş','Kozaklı','Ürgüp','Acıgöl'],
    'Niğde' => ['Bor','Çamardı','Ulukışla','Altunhisar','Çiftlik'],
    'Ordu' => ['Akkuş','Aybastı','Fatsa','Gölköy','Korgan','Kumru','Mesudiye','Perşembe','Ulubey','Ünye','Gülyalı','Gürgentepe','Çamaş','Çatalpınar','Çaybaşı','İkizce','Kabadüz','Kabataş','Altınordu'],
    'Osmaniye' => ['Bahçe','Kadirli','Düziçi','Hasanbeyli','Sumbas','Toprakkale'],
    'Rize' => ['Ardeşen','Çamlıhemşin','Çayeli','Fındıklı','İkizdere','Kalkandere','Pazar','Güneysu','Derepazarı','Hemşin','İyidere'],
    'Sakarya' => ['Akyazı','Geyve','Hendek','Karasu','Kaynarca','Pamukova','Taraklı','Ferizli','Karapürçek','Söğütlü','Adapazarı','Arifiye','Erenler','Kocaali','Sapanca','Serdivan'],
    'Samsun' => ['Alaçam','Bafra','Çarşamba','Havza','Kavak','Ladik','Terme','Vezirköprü','Asarcık','Ondokuzmayıs','Salıpazarı','Tekkeköy','Ayvacık','Yakakent','Atakum','Canik','İlkadım'],
    'Şanlıurfa' => ['Akçakale','Birecik','Bozova','Ceylanpınar','Halfeti','Hilvan','Siverek','Suruç','Viranşehir','Harran','Eyyübiye','Haliliye','Karaköprü'],
    'Siirt' => ['Baykan','Eruh','Kurtalan','Pervari','Şirvan','Tillo'],
    'Sinop' => ['Ayancık','Boyabat','Durağan','Erfelek','Gerze','Türkeli','Dikmen','Saraydüzü'],
    'Şırnak' => ['Beytüşşebap','Cizre','İdil','Silopi','Uludere','Güçlükonak'],
    'Sivas' => ['Divriği','Gemerek','Gürün','Hafik','İmranlı','Kangal','Koyulhisar','Suşehri','Şarkışla','Yıldızeli','Zara','Akıncılar','Altınyayla','Doğanşar','Gölova','Ulaş'],
    'Tekirdağ' => ['Çerkezköy','Çorlu','Ergene','Hayrabolu','Malkara','Muratlı','Saray','Şarköy','Marmaraereğlisi','Kapaklı','Süleymanpaşa'],
    'Tokat' => ['Almus','Artova','Erbaa','Niksar','Reşadiye','Turhal','Zile','Pazar','Yeşilyurt','Başçiftlik','Sulusaray'],
    'Trabzon' => ['Akçaabat','Araklı','Arsin','Çaykara','Maçka','Of','Ortahisar','Sürmene','Tonya','Vakfıkebir','Yomra','Beşikdüzü','Şalpazarı','Çarşıbaşı','Dernekpazarı','Düzköy','Hayrat','Köprübaşı'],
    'Tunceli' => ['Çemişgezek','Hozat','Mazgirt','Nazımiye','Ovacık','Pertek','Pülümür'],
    'Uşak' => ['Banaz','Eşme','Karahallı','Sivaslı','Ulubey'],
    'Van' => ['Bahçesaray','Başkale','Çatak','Erciş','Gevaş','Gürpınar','Muradiye','Özalp','Saray','Edremit','Çaldıran','İpekyolu','Tuşba'],
    'Yalova' => ['Altınova','Armutlu','Çınarcık','Çiftlikköy','Termal'],
    'Yozgat' => ['Akdağmadeni','Boğazlıyan','Çayıralan','Çekerek','Sarıkaya','Sorgun','Şefaatli','Yerköy','Kadışehri','Saraykent','Yenifakılı','Aydıncık','Çandır'],
    'Zonguldak' => ['Çaycuma','Devrek','Ereğli','Alaplı','Gökçebey','Kilimli','Kozlu'],
    'Çanakkale' => ['Ayvacık','Bayramiç','Biga','Bozcaada','Çan','Eceabat','Ezine','Gelibolu','Gökçeada','Lapseki','Yenice'],
    'Çankırı' => ['Çerkeş','Eldivan','Ilgaz','Kurşunlu','Orta','Şabanözü','Yapraklı','Atkaracalar','Kızılırmak','Bayramören','Korgun'],
    'Çorum' => ['Alaca','Bayat','İskilip','Kargı','Mecitözü','Ortaköy','Osmancık','Sungurlu','Boğazkale','Uğurludağ','Dodurga','Laçin','Oğuzlar'],
    ];
    return $m;
}

function trSemtler(): array {
    static $m = null;
    if ($m !== null) return $m;
    $m = [
    'Levent'=>'İstanbul','Maslak'=>'İstanbul','Etiler'=>'İstanbul','Nişantaşı'=>'İstanbul','Taksim'=>'İstanbul',
    'Mecidiyeköy'=>'İstanbul','Zincirlikuyu'=>'İstanbul','Gayrettepe'=>'İstanbul','Esentepe'=>'İstanbul','Balmumcu'=>'İstanbul',
    'Karaköy'=>'İstanbul','Galata'=>'İstanbul','Eminönü'=>'İstanbul','Sultanahmet'=>'İstanbul','Ataköy'=>'İstanbul',
    'Florya'=>'İstanbul','Yeşilköy'=>'İstanbul','Halkalı'=>'İstanbul','İkitelli'=>'İstanbul','Ayazağa'=>'İstanbul',
    'Kavacık'=>'İstanbul','Acıbadem'=>'İstanbul','Kozyatağı'=>'İstanbul','Bostancı'=>'İstanbul','Erenköy'=>'İstanbul',
    'Suadiye'=>'İstanbul','Kalamış'=>'İstanbul','Altunizade'=>'İstanbul','Çamlıca'=>'İstanbul','Dudullu'=>'İstanbul',
    'Kurtköy'=>'İstanbul','Sabiha Gökçen'=>'İstanbul','Hadımköy'=>'İstanbul','Seyrantepe'=>'İstanbul','Bomonti'=>'İstanbul',
    'Merter'=>'İstanbul','Yenibosna'=>'İstanbul','Şirinevler'=>'İstanbul','Bahçeşehir'=>'İstanbul','Kayaşehir'=>'İstanbul',
    'Ambarlı'=>'İstanbul','Haramidere'=>'İstanbul','Şerifali'=>'İstanbul','Samandıra'=>'İstanbul','Tarabya'=>'İstanbul',
    'Emirgan'=>'İstanbul','Ortaköy'=>'İstanbul','Alibeyköy'=>'İstanbul','Okmeydanı'=>'İstanbul','Topkapı'=>'İstanbul',
    'İMES'=>'İstanbul','İTOSB'=>'İstanbul','Tuzla Organize'=>'İstanbul','Atatürk Havalimanı'=>'İstanbul','İstanbul Havalimanı'=>'İstanbul',
    'Kızılay'=>'Ankara','Balgat'=>'Ankara','Söğütözü'=>'Ankara','Bilkent'=>'Ankara','Ümitköy'=>'Ankara',
    'Çayyolu'=>'Ankara','Batıkent'=>'Ankara','Ostim'=>'Ankara','İvedik'=>'Ankara','Siteler'=>'Ankara',
    'Eryaman'=>'Ankara','Elvankent'=>'Ankara','Demetevler'=>'Ankara','Etlik'=>'Ankara','Esenboğa'=>'Ankara',
    'İncek'=>'Ankara','Temelli'=>'Ankara','Beşevler'=>'Ankara','Tandoğan'=>'Ankara','Oran'=>'Ankara',
    'Alsancak'=>'İzmir','Mavişehir'=>'İzmir','Güzelyalı'=>'İzmir','Basmane'=>'İzmir','Sarnıç'=>'İzmir',
    'Işıkkent'=>'İzmir','Şirinyer'=>'İzmir','Petkim'=>'İzmir','Esbaş'=>'İzmir','Adnan Menderes'=>'İzmir',
    'Görükle'=>'Bursa','Demirtaş'=>'Bursa','Hasanağa'=>'Bursa','Uludağ'=>'Bursa','Nosab'=>'Bursa',
    'Dosab'=>'Bursa','Hosab'=>'Bursa','Buttim'=>'Bursa',
    'Gosb'=>'Kocaeli','Tosb'=>'Kocaeli','Şekerpınar'=>'Kocaeli','Muallimköy'=>'Kocaeli','Kosbaş'=>'Kocaeli',
    'Izaydaş'=>'Kocaeli',
    'Çosb'=>'Tekirdağ','Velimeşe'=>'Tekirdağ','Kapaklı'=>'Tekirdağ',
    'Mosb'=>'Manisa','Organize Manisa'=>'Manisa',
    'Lara'=>'Antalya','Belek'=>'Antalya','Side'=>'Antalya','Kundu'=>'Antalya',
    'Kapadokya'=>'Nevşehir','Pamukkale Termal'=>'Denizli','Efes'=>'İzmir','Truva'=>'Çanakkale',
    ];
    return $m;
}

function trSupheliAnahtarlar(): array {
    return array_flip([
        'CAY','PAZAR','CARSAMBA','GUNEY','ORTA','KALE','HAN','OF','BOR','ULA','TUT','KAS',
        'SARAY','ADA','BAHCE','ULUS','KORFEZ','YENICE','KAVAK','TERME','GONEN','MARMARA',
        'VAN','CAT','GENC','KULA','KULU','SUR','SELIM','DERIK','MUT','TIRE','KIRAZ','SOMA',
        'CIFTLIK','MERKEZEFENDI','ORTAKOY','BAYAT','ULUBEY','AYVACIK','YENISEHIR','YESILYURT',
        'EREGLI','GOLBASI','KEMER','KEMALPASA','EDREMIT','PINARBASI','BOZKURT','OVACIK',
        'ALTINYAYLA','KOPRUBASI','AYDINCIK','HALKAPINAR','MALTEPE','BAHCELIEVLER',
        'GAZIOSMANPASA','KAVAKLIDERE','SELCUK','CAMLICA','ORAN','SITELER','EFES',
    ]);
}

function trYoksayilanAnahtarlar(): array {
    return array_flip(['MERKEZ']);
}

function trAnahtarIndeksi(): array {
    static $ix = null;
    if ($ix !== null) return $ix;
    $ix = [];
    $ekle = function (string $ad, string $il, string $tur) use (&$ix) {
        $k = ilNormalize($ad);
        if ($k === '') return;
        if (!isset($ix[$k])) $ix[$k] = ['iller' => [], 'tur' => $tur];
        if (!in_array($il, $ix[$k]['iller'], true)) $ix[$k]['iller'][] = $il;
        $oncelik = ['il' => 3, 'ilce' => 2, 'semt' => 1];
        if ($oncelik[$tur] > $oncelik[$ix[$k]['tur']]) $ix[$k]['tur'] = $tur;
    };

    foreach (trIlIlce() as $il => $ilceler) {
        $ekle($il, $il, 'il');
        foreach ($ilceler as $ilce) $ekle($ilce, $il, 'ilce');
    }
    foreach (trSemtler() as $semt => $il) $ekle($semt, $il, 'semt');

    $alt = [
        'Adıyaman'=>'Adıyaman','Ağrı'=>'Ağrı','Aydın'=>'Aydın','Balıkesir'=>'Balıkesir',
        'Diyarbakır'=>'Diyarbakır','Elazığ'=>'Elazığ','Eskişehir'=>'Eskişehir','Gümüşhane'=>'Gümüşhane',
        'Hakkâri'=>'Hakkari','İstanbul'=>'İstanbul','İzmir'=>'İzmir','Kırklareli'=>'Kırklareli',
        'Kırşehir'=>'Kırşehir','Muğla'=>'Muğla','Muş'=>'Muş','Nevşehir'=>'Nevşehir','Niğde'=>'Niğde',
        'Şanlıurfa'=>'Şanlıurfa','Şırnak'=>'Şırnak','Tekirdağ'=>'Tekirdağ','Uşak'=>'Uşak',
        'Çankırı'=>'Çankırı','Maraş'=>'Kahramanmaraş','Urfa'=>'Şanlıurfa','Antep'=>'Gaziantep',
        'İzmit'=>'Kocaeli','Adapazarı'=>'Sakarya','Mersin'=>'Mersin','İçel'=>'Mersin',
    ];
    foreach ($alt as $yazim => $il) $ekle($yazim, $il, 'il');

    return $ix;
}

function trIlkTokenIndeksi(): array {
    static $t = null;
    if ($t !== null) return $t;
    $t = [];
    foreach (array_keys(trAnahtarIndeksi()) as $anahtar) {
        $ilk = strtok($anahtar, ' ');
        if ($ilk === false || $ilk === '') continue;
        $t[$ilk][] = $anahtar;
    }
    return $t;
}

function ilMetindenBul(?string $metin): ?array {
    $norm = ilNormalize((string)$metin);
    if ($norm === '') return null;
    $hedef = ' ' . $norm . ' ';

    $ix       = trAnahtarIndeksi();
    $supheli  = trSupheliAnahtarlar();
    $yoksay   = trYoksayilanAnahtarlar();

    $adayAnahtarlar = [];
    foreach (array_unique(explode(' ', $norm)) as $token) {
        foreach (trIlkTokenIndeksi()[$token] ?? [] as $a) $adayAnahtarlar[$a] = true;
    }

    $bulunan = [];
    foreach (array_keys($adayAnahtarlar) as $anahtar) {
        if (isset($yoksay[$anahtar])) continue;
        if (strpos($hedef, ' ' . $anahtar . ' ') === false) continue;
        $bulunan[] = ['anahtar' => $anahtar, 'uzunluk' => strlen($anahtar)] + $ix[$anahtar];
    }
    if (!$bulunan) return null;

    $turSira = ['il' => 3, 'ilce' => 2, 'semt' => 1];
    usort($bulunan, function ($a, $b) use ($turSira) {
        if ($turSira[$a['tur']] !== $turSira[$b['tur']]) return $turSira[$b['tur']] <=> $turSira[$a['tur']];
        return $b['uzunluk'] <=> $a['uzunluk'];
    });

    $en = $bulunan[0];
    return [
        'il'       => count($en['iller']) === 1 ? $en['iller'][0] : null,
        'adaylar'  => $en['iller'],
        'anahtar'  => $en['anahtar'],
        'tur'      => $en['tur'],
        'belirsiz' => count($en['iller']) > 1,
        'supheli'  => isset($supheli[$en['anahtar']]),
    ];
}

function ilTahminEt(array $kaynaklar): array {
    $sonuc = ['il'=>null,'guven'=>'yok','kaynak'=>null,'anahtar'=>null,'tur'=>null,
              'adaylar'=>[],'destek'=>[],'celiski'=>[]];

    $bulgular = [];
    foreach ($kaynaklar as $k) {
        $b = ilMetindenBul($k['metin'] ?? null);
        if ($b) $bulgular[] = $b + ['kaynak' => $k['tip'] ?? '?'];
    }
    if (!$bulgular) return $sonuc;

    $birincil = null;
    foreach ($bulgular as $b) { if (!$b['belirsiz']) { $birincil = $b; break; } }
    if (!$birincil) $birincil = $bulgular[0];

    $sonuc['il']      = $birincil['il'];
    $sonuc['kaynak']  = $birincil['kaynak'];
    $sonuc['anahtar'] = $birincil['anahtar'];
    $sonuc['tur']     = $birincil['tur'];
    $sonuc['adaylar'] = $birincil['adaylar'];

    if ($birincil['belirsiz'])      $sonuc['guven'] = 'dusuk';
    elseif ($birincil['supheli'])   $sonuc['guven'] = 'dusuk';
    elseif ($birincil['tur']==='il')   $sonuc['guven'] = 'yuksek';
    elseif ($birincil['tur']==='ilce') $sonuc['guven'] = 'yuksek';
    else                               $sonuc['guven'] = 'orta';

    foreach ($bulgular as $b) {
        if ($b === $birincil || !$b['il']) continue;
        if ($b['il'] === $sonuc['il']) {
            $sonuc['destek'][] = ['kaynak'=>$b['kaynak'], 'anahtar'=>$b['anahtar']];
            if ($sonuc['guven'] === 'orta') $sonuc['guven'] = 'yuksek';
            if ($sonuc['guven'] === 'dusuk' && !$birincil['belirsiz']) $sonuc['guven'] = 'orta';
        } else {
            $sonuc['celiski'][] = ['kaynak'=>$b['kaynak'], 'il'=>$b['il'], 'anahtar'=>$b['anahtar']];
        }
    }

    if (!$sonuc['il'] && $sonuc['adaylar']) {
        foreach ($bulgular as $b) {
            if ($b['il'] && in_array($b['il'], $sonuc['adaylar'], true)) {
                $sonuc['il'] = $b['il'];
                $sonuc['guven'] = 'orta';
                $sonuc['destek'][] = ['kaynak'=>$b['kaynak'], 'anahtar'=>$b['anahtar']];
                break;
            }
        }
    }

    return $sonuc;
}


function ilKanonik(string $il): string {
    static $ix = null;
    if ($ix === null) { $ix = []; foreach (trIlListesi() as $a) $ix[ilNormalize($a)] = $a; }
    $il = trim($il);
    return $ix[ilNormalize($il)] ?? $il;
}
