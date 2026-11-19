<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/il-veri.php';


if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();
requirePermission('kiralama_takip');

$db = getDb();

switch ($_SERVER['REQUEST_METHOD']) {

case 'GET':
    $kapsam = ($_GET['kapsam'] ?? 'eksik') === 'hepsi' ? 'hepsi' : 'eksik';

    $sql = "SELECT k.id, k.kod, k.ekipman_adi, k.baslangic, k.bitis, k.durum,
                   k.lokasyon, k.sube, k.il,
                   m.unvan AS musteri, m.adres AS musteri_adres
            FROM kiralamalar k
            LEFT JOIN musteriler m ON m.id = k.musteri_id";
    $where = []; $params = [];
    if ($kapsam === 'eksik') $where[] = "(k.il IS NULL OR trim(k.il) = '')";
    subeKosulEkle($where, $params, 'k.sube');
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= " ORDER BY k.baslangic DESC, k.id DESC";
    $liste = $db->prepare($sql);
    $liste->execute($params);

    $satirlar = [];
    $sayac = ['yuksek'=>0, 'orta'=>0, 'dusuk'=>0, 'yok'=>0];
    foreach ($liste->fetchAll() as $k) {
        $t = ilTahminEt([
            ['tip' => 'lokasyon', 'metin' => $k['lokasyon']],
            ['tip' => 'adres',    'metin' => $k['musteri_adres']],
            ['tip' => 'sube',     'metin' => $k['sube']],
        ]);
        $sayac[$t['guven']]++;
        $satirlar[] = [
            'id'          => (int)$k['id'],
            'kod'         => $k['kod'],
            'ekipman_adi' => $k['ekipman_adi'],
            'musteri'     => $k['musteri'],
            'baslangic'   => $k['baslangic'],
            'durum'       => $k['durum'],
            'lokasyon'    => $k['lokasyon'],
            'sube'        => $k['sube'],
            'mevcut_il'   => $k['il'],
            'tahmin'      => $t,
        ];
    }

    jsonOk([
        'kapsam'  => $kapsam,
        'iller'   => trIlListesi(),
        'sayac'   => $sayac,
        'toplam'  => count($satirlar),
        'satirlar'=> $satirlar,
    ]);
    break;

case 'POST':
    verifyCsrf();
    $body = govdeOku();
    $atamalar = $body['atamalar'] ?? null;
    if (!is_array($atamalar) || !$atamalar) jsonError('Kaydedilecek atama yok.');
    if (count($atamalar) > 5000) jsonError('Tek seferde en fazla 5000 kayıt güncellenebilir.');

    $gecerli = array_flip(trIlListesi());

    $temiz = [];
    foreach ($atamalar as $a) {
        $id = (int)($a['id'] ?? 0);
        $il = ilYazim((string)($a['il'] ?? ''));
        if ($id < 1) jsonError('Geçersiz kiralama kimliği.');
        if ($il !== '' && !isset($gecerli[$il])) jsonError('Tanınmayan il: ' . $il);
        $temiz[] = [$id, $il === '' ? null : $il];
    }

    $kisit = subeKisiti();
    $st = $db->prepare("UPDATE kiralamalar SET il=?, updated_at=datetime('now','localtime') WHERE id=?"
                     . ($kisit !== null ? ' AND sube=?' : ''));
    $guncellenen = 0;
    $db->beginTransaction();
    try {
        foreach ($temiz as [$id, $il]) {
            $st->execute($kisit !== null ? [$il, $id, $kisit] : [$il, $id]);
            $guncellenen += $st->rowCount();
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        jsonError('Kayıt sırasında hata oluştu, hiçbir değişiklik uygulanmadı.', 500);
    }

    jsonOk(['guncellenen' => $guncellenen], $guncellenen . ' kiralamanın il bilgisi güncellendi.');
    break;

default:
    jsonError('Method Not Allowed', 405);
}
