<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/musteri-erisim-lib.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAuth();

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDb();

if ($method !== 'POST') jsonError('Method Not Allowed', 405);

function _formHtmlTemizle(string $html): string {
    if (trim($html) === '') return '';
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?><div id="__kok__">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    $kok = $dom->getElementById('__kok__');
    if (!$kok) return '';

    $icerikliAt = ['script','style','iframe','frame','frameset','object','embed','applet','link','meta',
                   'base','form','input','button','select','option','textarea','svg','math','template',
                   'noscript','noembed','noframes','canvas','video','audio','source','track','title','head',
                   'portal','xmp','plaintext','dialog'];
    $izinliEtiket = ['div','span','p','br','hr','b','strong','i','em','u','s','small','sub','sup','mark',
                     'h1','h2','h3','h4','h5','h6','table','thead','tbody','tfoot','tr','th','td','caption',
                     'colgroup','col','ul','ol','li','dl','dt','dd','img','a','label','section','header',
                     'footer','article','main','aside','nav','blockquote','pre','code','center','font','figure',
                     'figcaption'];
    $izinliOznitelik = ['class','id','style','title','alt','colspan','rowspan','width','height','align',
                        'valign','dir','lang','src','href','target','for','color','size','border',
                        'cellpadding','cellspacing','scope'];

    $xpath = new DOMXPath($dom);
    foreach (iterator_to_array($xpath->query('.//*', $kok)) as $el) {
        if (!($el instanceof DOMElement) || !$el->parentNode) continue;
        $etiket = strtolower($el->localName ?? $el->nodeName);
        if (in_array($etiket, $icerikliAt, true)) { $el->parentNode->removeChild($el); continue; }
        if (!in_array($etiket, $izinliEtiket, true)) {
            while ($el->firstChild) $el->parentNode->insertBefore($el->firstChild, $el);
            $el->parentNode->removeChild($el);
            continue;
        }
        foreach (iterator_to_array($el->attributes) as $attr) {
            $ad = strtolower($attr->nodeName);
            $deger = (string)$attr->nodeValue;
            $sil = !in_array($ad, $izinliOznitelik, true);
            if (!$sil && ($ad === 'href' || $ad === 'src')) $sil = !_formUrlGuvenli($deger, $ad === 'src');
            if (!$sil && $ad === 'target') $sil = $deger !== '_blank';
            if (!$sil && $ad === 'style') {
                $css = _formStilTemizle($deger);
                if ($css === '') { $sil = true; } else { $el->setAttribute('style', $css); }
            }
            if ($sil) $el->removeAttribute($attr->nodeName);
        }
        if ($el->getAttribute('target') === '_blank') $el->setAttribute('rel', 'noopener noreferrer');
    }

    $sonuc = '';
    foreach (iterator_to_array($kok->childNodes) as $child) {
        $sonuc .= $dom->saveHTML($child);
    }
    return $sonuc;
}

function _formUrlGuvenli(string $url, bool $gorsel): bool {
    $t = strtolower((string)preg_replace('/[\x00-\x20]+/', '', $url));
    if (!preg_match('/^([a-z][a-z0-9+.-]*):/', $t, $m)) return true;
    if (in_array($m[1], ['http', 'https'], true)) return true;
    if (!$gorsel) return in_array($m[1], ['mailto', 'tel'], true);
    return (bool)preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#', $t);
}

function _formStilTemizle(string $css): string {
    $kalan = [];
    foreach (explode(';', $css) as $bildirim) {
        $bildirim = trim($bildirim);
        if ($bildirim === '' || !str_contains($bildirim, ':')) continue;
        [$ozellik, $deger] = array_map('trim', explode(':', $bildirim, 2));
        $ozellik = strtolower($ozellik);
        $d = strtolower($deger);
        if (in_array($ozellik, ['position', 'behavior', '-moz-binding', 'z-index'], true)) continue;
        if (str_contains($d, 'url(') || str_contains($d, 'expression') || str_contains($d, 'javascript:')
            || str_contains($d, '@import') || str_contains($bildirim, '\\')) continue;
        if (!preg_match('/^[a-z-]+$/', $ozellik)) continue;
        $kalan[] = $ozellik . ':' . $deger;
    }
    return implode(';', $kalan);
}

verifyCsrf();
$body    = govdeOku();
$formId  = (int)($body['form_id'] ?? 0);
$baslik  = trim(metin($body['baslik'] ?? ''));
$html    = _formHtmlTemizle((string)($body['html'] ?? ''));

if ($html === '' || mb_strlen($html) < 30) jsonError('Belge içeriği boş.');
if (mb_strlen($html) > 600000) jsonError('Belge çok büyük.');

if ($formId > 0) {
    requirePermission('formlar', 'yaz');
}

meSema($db);
$musteriId = (int)($body['musteri_id'] ?? 0);
if ($musteriId > 0) {
    $mv = $db->prepare('SELECT id FROM musteriler WHERE id=?');
    $mv->execute([$musteriId]);
    if (!$mv->fetchColumn()) jsonError('Müşteri bulunamadı.', 404);
} elseif ($formId > 0) {
    $fm = $db->prepare("SELECT m.id FROM form_kayitlari f JOIN musteriler m ON TRIM(LOWER(m.unvan)) = TRIM(LOWER(f.musteri))
                        WHERE f.id=? AND TRIM(COALESCE(f.musteri,'')) <> '' LIMIT 2");
    $fm->execute([$formId]);
    $es = $fm->fetchAll(PDO::FETCH_COLUMN);
    if (count($es) === 1) $musteriId = (int)$es[0];
}
$davetYetkili = false;
if ($musteriId > 0 && hasPermission('kiralama_takip', 'yaz')) {
    $kisit = subeKisiti();
    if ($kisit === null) $davetYetkili = true;
    else {
        $ms = $db->prepare('SELECT sube FROM musteriler WHERE id=?');
        $ms->execute([$musteriId]);
        $davetYetkili = trim((string)$ms->fetchColumn()) === $kisit;
    }
}

if ($formId > 0) {
    $db->prepare("DELETE FROM form_paylasim WHERE form_id = ?")->execute([$formId]);
}

do {
    $token = bin2hex(random_bytes(24));
    $chk   = $db->prepare("SELECT 1 FROM form_paylasim WHERE token = ?");
    $chk->execute([$token]);
} while ($chk->fetchColumn());

$gun = (int)($body['gun'] ?? 0);
if ($gun <= 0 || $gun > PAYLASIM_MAX_GUN) $gun = PAYLASIM_VARSAYILAN_GUN;
$bitis = date('Y-m-d H:i:s', strtotime('+' . $gun . ' days'));

$stmt = $db->prepare("INSERT INTO form_paylasim (token, form_id, baslik, html, expires_at, olusturan, musteri_id) VALUES (?,?,?,?,?,?,?)");
$stmt->execute([$token, $formId ?: null, $baslik ?: null, $html, $bitis,
                (string)($_SESSION['username'] ?? ''), $musteriId ?: null]);

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = guvenliHost();
$dir    = siteKokYolu();
$url    = $scheme . '://' . $host . $dir . '/belge.php?t=' . $token;

$erisim = null;
if ($musteriId > 0) {
    $erisim = $davetYetkili ? meOtoDavet($db, $musteriId, 'belge.php?t=' . $token)
        : ['durum' => mePortalDurum($db, $musteriId) === 'aktif' ? 'aktif' : 'bekliyor', 'link' => null];
}
jsonOk([
    'token'      => $token,
    'url'        => $url,
    'expires_at' => $bitis,
    'gun'        => $gun,
    'musteri_id' => $musteriId ?: null,
    'portal'     => $erisim['durum'] ?? null,
    'not'        => $erisim ? trim(meMesajNotu($erisim)) : '',
    'uyari'      => $musteriId > 0 ? '' : 'Bu belge kayıtlı bir müşteriye bağlanamadı; bağlantıyı yalnız Formlar yetkisi olan personelimiz panelden giriş yaparak açabilir.',
], "Paylaşım bağlantısı oluşturuldu. $gun gün geçerli."); 
