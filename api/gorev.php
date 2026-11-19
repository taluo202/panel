<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();

$db     = getDb();
$method = $_SERVER['REQUEST_METHOD'];

function _gOnc($v): string   { return in_array($v, ['dusuk','normal','yuksek'], true) ? $v : 'normal'; }
function _gDurum($v): string { return in_array($v, ['acik','tamamlandi'], true) ? $v : 'acik'; }

switch ($method) {

    case 'GET':
        $where = []; $params = [];
        if (($d = trim(metin($_GET['durum'] ?? ''))) !== '' && $d !== 'hepsi') { $where[] = "g.durum=?"; $params[] = $d; }
        if (($aid = (int)($_GET['atanan_id'] ?? 0)) > 0) { $where[] = "g.atanan_id=?"; $params[] = $aid; }
        $sql = "SELECT g.*, p.ad_soyad AS atanan_ad FROM gorevler g LEFT JOIN personel p ON p.id=g.atanan_id";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY (g.durum='acik') DESC, (g.son_tarih IS NULL), g.son_tarih ASC, g.id DESC";
        $st = $db->prepare($sql); $st->execute($params);
        require_once __DIR__ . '/zincir-lib.php';
        $rows = array_values(array_map(fn($g) => gorevKaynakBilgisi(hdGorevMaskele($db, $g)), array_filter($st->fetchAll(), fn($g) => hdGorevGorunur($db, $g))));
        foreach ($rows as &$r) $r['kaynak_kilitli'] = hdKilitliGorev($db, $r);
        unset($r);

        $today = date('Y-m-d');
        $ozet = ['acik'=>0,'tamamlandi'=>0,'geciken'=>0];
        foreach ($rows as $r) {
            $ozet[$r['durum']] = ($ozet[$r['durum']] ?? 0) + 1;
            if ($r['durum'] === 'acik' && $r['son_tarih'] && $r['son_tarih'] < $today) $ozet['geciken']++;
        }
        jsonOk(['list' => $rows, 'ozet' => $ozet]);
        break;

    case 'POST':
        verifyCsrf();
        $body = govdeOku();
        $baslik   = trim(metin($body['baslik'] ?? ''));
        if ($baslik === '') jsonError('Görev başlığı zorunludur.');
        $sonTarih = dateStr('son_tarih', $body);

        if ($sonTarih === null) {
            $dupChk = $db->prepare("SELECT id FROM gorevler WHERE baslik=? AND durum='acik' AND son_tarih IS NULL");
            $dupChk->execute([$baslik]);
        } else {
            $dupChk = $db->prepare("SELECT id FROM gorevler WHERE baslik=? AND durum='acik' AND son_tarih=?");
            $dupChk->execute([$baslik, $sonTarih]);
        }
        $dupId = $dupChk->fetchColumn();
        if ($dupId) jsonOk(['id' => (int)$dupId], 'Bu görev zaten eklenmiş.');

        $kaynak = ($body['kaynak'] ?? '') === 'bildirim' ? 'bildirim' : 'manuel';
        $st = $db->prepare("INSERT INTO gorevler (baslik, aciklama, atanan_id, son_tarih, oncelik, durum, kaynak) VALUES (?,?,?,?,?,?,?)");
        $st->execute([
            $baslik,
            trim(metin($body['aciklama'] ?? '')) ?: null,
            ((int)($body['atanan_id'] ?? 0)) ?: null,
            $sonTarih,
            _gOnc($body['oncelik'] ?? 'normal'),
            _gDurum($body['durum'] ?? 'acik'),
            $kaynak,
        ]);
        $_gid = (int)$db->lastInsertId();
        logla($db, 'gorevler', $_gid, 'ekle', null, logKayitAl($db, 'gorevler', $_gid), 'Görev eklendi: ' . $baslik);
        jsonOk(['id' => $_gid], 'Görev eklendi.');

    case 'PUT':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $body = govdeOku();

        $cur = $db->prepare("SELECT * FROM gorevler WHERE id=?");
        $cur->execute([$id]);
        $mevcut = $cur->fetch();
        if (!$mevcut || !hdGorevGorunur($db, $mevcut)) jsonError('Görev bulunamadı.', 404);
        if (hdKilitliGorev($db, $mevcut)) jsonError('Bu görev kaynak değerlendirme ekranından atanır ve sonuçlandırılır.', 409);

        if (!isset($body['baslik']) && !array_diff(array_keys($body), ['durum', 'son_tarih'])) {
            $sets = []; $params = [];
            if (array_key_exists('durum', $body)) {
                $durum = _gDurum($body['durum']);
                $tam = ($durum === 'tamamlandi') ? ($mevcut['tamamlanma'] ?: date('Y-m-d')) : null;
                $sets[] = 'durum=?'; $params[] = $durum;
                $sets[] = 'tamamlanma=?'; $params[] = $tam;
            }
            if (array_key_exists('son_tarih', $body)) {
                $sets[] = 'son_tarih=?'; $params[] = dateStr('son_tarih', $body);
            }
            if ($sets) {
                $sets[] = "updated_at=datetime('now','localtime')";
                $params[] = $id;
                $db->prepare("UPDATE gorevler SET " . implode(',', $sets) . " WHERE id=?")->execute($params);
                logla($db, 'gorevler', $id, 'guncelle', $mevcut, logKayitAl($db, 'gorevler', $id), 'Görev durumu/tarihi değişti');
                jsonOk(null, 'Görev güncellendi.');
            }
        }

        $baslik = trim(metin($body['baslik'] ?? ''));
        if ($baslik === '') jsonError('Görev başlığı zorunludur.');
        $durum = _gDurum($body['durum'] ?? $mevcut['durum']);
        $tam = ($durum === 'tamamlandi') ? ($mevcut['tamamlanma'] ?: date('Y-m-d')) : null;
        $gAciklama = trim(metin($body['aciklama'] ?? '')) ?: null;
        if (($mevcut['aciklama'] ?? null) !== null && hdGorevMaskele($db, $mevcut)['aciklama'] === null) $gAciklama = $mevcut['aciklama'];
        $st = $db->prepare("UPDATE gorevler SET baslik=?, aciklama=?, atanan_id=?, son_tarih=?, oncelik=?, durum=?, tamamlanma=?, updated_at=datetime('now','localtime') WHERE id=?");
        $st->execute([
            $baslik,
            $gAciklama,
            ((int)($body['atanan_id'] ?? 0)) ?: null,
            dateStr('son_tarih', $body),
            _gOnc($body['oncelik'] ?? 'normal'),
            $durum, $tam, $id,
        ]);
        logla($db, 'gorevler', $id, 'guncelle', $mevcut, logKayitAl($db, 'gorevler', $id), 'Görev güncellendi');
        jsonOk(null, 'Görev güncellendi.');

    case 'DELETE':
        verifyCsrf();
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonError('Geçersiz ID.');
        $st=$db->prepare('SELECT * FROM gorevler WHERE id=?');$st->execute([$id]);$_logEski=$st->fetch() ?: null;
        if (!$_logEski || !hdGorevGorunur($db, $_logEski)) jsonError('Görev bulunamadı.', 404);
        if (hdKilitliGorev($db, $_logEski ?: [])) jsonError('Değerlendirmeye bağlı görev silinemez; kaynağından sonuçlandırın.', 409);
        $db->prepare("DELETE FROM gorevler WHERE id=?")->execute([$id]);
        if ($_logEski) logla($db, 'gorevler', $id, 'sil', $_logEski, null, 'Görev silindi: ' . $_logEski['baslik']);
        jsonOk(null, 'Görev silindi.');

    default:
        jsonError('Method Not Allowed', 405);
}
