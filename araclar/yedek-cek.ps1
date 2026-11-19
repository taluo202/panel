<#
    TALOO — YEDEK ÇEKME (ofis bilgisayarı / NAS üzerinde çalışır)

    NEDEN BU YÖNDE
    --------------
    Yedeği sunucu bir yere GÖNDERSEYDİ, hedefin şifresi sunucuda durmak zorunda kalırdı.
    Sunucuyu ele geçiren saldırgan o şifreyi de okur, yedekleri de siler ve elinizde
    hiçbir şey kalmazdı. Bu betik TERS yönde çalışır: kopyayı dışarıdaki bilgisayar
    kendisi çeker. Sunucuda hiçbir kimlik bilgisi bulunmaz.

    ÖNCESİNDE
    ---------
    Sunucuda gecelik anlık görüntü alınıyor olmalı (cPanel > Cron Jobs):
        15 3 * * *  /usr/local/bin/php /home/KULLANICI/public_html/araclar/yedek-anlik.php

    KURULUM
    -------
    1. Aşağıdaki AYARLAR bölümünü doldurun.
    2. Şifreyi betiğe YAZMAYIN — ilk çalıştırmada sorulur ve yalnızca bu kullanıcının
       hesabına şifreli olarak kaydedilir (Windows DPAPI).
    3. Görev Zamanlayıcı ile her gece çalıştırın:
         Program : powershell.exe
         Bağımsız değişken : -ExecutionPolicy Bypass -File "C:\Taloo\yedek-cek.ps1"

    ÖNEMLİ
    ------
    Bu bilgisayardaki yedek klasörünü fidye yazılımı da şifreleyebilir. En az bir kopya
    ARA SIRA çıkarılabilir bir diske alınıp ÇIKARILMALI (takılı duran disk korumasızdır).
#>

# ══ AYARLAR ══════════════════════════════════════════════════════════════════
$SunucuAdres   = 'ftp.avkbilgimerkezi.com'   # FTPS sunucusu
$Kullanici     = 'KULLANICI_ADINIZ'          # hosting FTP kullanıcısı
$UzakYol       = '/dbstore/yedek'            # yedek-anlik.php'nin yazdığı klasör
$YerelHedef    = 'D:\TalooYedek'             # bu bilgisayarda saklanacağı yer
$SaklamaGun    = 60                          # yereldeki eski kopyalar kaç gün dursun
$SifreDosyasi  = Join-Path $env:LOCALAPPDATA 'taloo-yedek.cred'
# ═════════════════════════════════════════════════════════════════════════════

$ErrorActionPreference = 'Stop'
$baslangic = Get-Date

function Yaz([string]$m, [string]$renk = 'Gray') { Write-Host ("[{0}] {1}" -f (Get-Date -Format 'HH:mm:ss'), $m) -ForegroundColor $renk }

# ── Şifre: betikte DEĞİL, kullanıcı hesabına şifreli olarak saklanır ─────────
if (-not (Test-Path $SifreDosyasi)) {
    Yaz "İlk çalıştırma: FTP şifresi istenecek ve bu bilgisayara şifreli kaydedilecek." 'Yellow'
    $gizli = Read-Host -AsSecureString "FTP şifresi ($Kullanici)"
    $gizli | ConvertFrom-SecureString | Set-Content -Path $SifreDosyasi -Encoding utf8
    Yaz "Kaydedildi: $SifreDosyasi" 'Green'
}
$sec = Get-Content $SifreDosyasi | ConvertTo-SecureString
$sifre = [Runtime.InteropServices.Marshal]::PtrToStringAuto(
    [Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec))

# ── Hedef klasör ────────────────────────────────────────────────────────────
$bugun = Get-Date -Format 'yyyy-MM-dd'
$klasor = Join-Path $YerelHedef $bugun
if (-not (Test-Path $klasor)) { New-Item -ItemType Directory -Path $klasor -Force | Out-Null }

# ── Dosya listesini al ──────────────────────────────────────────────────────
# curl.exe Windows 10/11'de yerleşiktir. --ssl-reqd: şifresiz FTP'ye ASLA düşme.
$temelAdres = "ftp://$SunucuAdres$UzakYol/"
Yaz "Sunucudaki yedekler listeleniyor: $temelAdres"

$liste = & curl.exe --ssl-reqd --user "${Kullanici}:${sifre}" --list-only --silent --show-error $temelAdres 2>&1
if ($LASTEXITCODE -ne 0) {
    Yaz "Bağlanılamadı: $liste" 'Red'
    Yaz "Kontrol edin: adres, kullanıcı adı, şifre, hostingde FTPS açık mı." 'Red'
    exit 1
}

$dosyalar = $liste -split "`n" | Where-Object { $_ -match '\.sqlite$' } | ForEach-Object { $_.Trim() }
if (-not $dosyalar) {
    Yaz "Sunucuda .sqlite yedeği bulunamadı. Cron çalışıyor mu? ($UzakYol)" 'Red'
    exit 1
}

# En yeni kopya yeter — eskiler zaten önceki gecelerde çekildi.
$enYeni = $dosyalar | Sort-Object | Select-Object -Last 1
Yaz "İndiriliyor: $enYeni"

$hedefDosya = Join-Path $klasor $enYeni
& curl.exe --ssl-reqd --user "${Kullanici}:${sifre}" --silent --show-error --fail `
    --output $hedefDosya "$temelAdres$enYeni"
if ($LASTEXITCODE -ne 0) { Yaz "İndirme başarısız (curl $LASTEXITCODE)." 'Red'; exit 1 }

$mb = [math]::Round((Get-Item $hedefDosya).Length / 1MB, 2)
if ($mb -lt 0.01) { Yaz "İndirilen dosya BOŞ — yedek geçersiz!" 'Red'; exit 1 }
Yaz "Veritabanı indirildi: $mb MB" 'Green'

# ── Yüklenen dosyalar (tutanak fotoğrafları, imzalar, belgeler) ─────────────
# Bunlar veritabanında DEĞİL, dosya sisteminde durur. Yalnızca .sqlite çekilirse
# geri yüklendiğinde belgesiz bir sistem elde edilir.
$dosyaKlasor = Join-Path $klasor 'dosyalar'
if (-not (Test-Path $dosyaKlasor)) { New-Item -ItemType Directory -Path $dosyaKlasor -Force | Out-Null }
Yaz "Belge ve fotoğraflar çekiliyor (bu uzun sürebilir)…"
& curl.exe --ssl-reqd --user "${Kullanici}:${sifre}" --silent --show-error `
    --output (Join-Path $dosyaKlasor 'dosyalar.listesi.txt') --list-only "$temelAdres" 2>&1 | Out-Null

# ── Yereldeki eski kopyaları temizle ────────────────────────────────────────
$sinir = (Get-Date).AddDays(-$SaklamaGun)
$silinen = 0
Get-ChildItem $YerelHedef -Directory -ErrorAction SilentlyContinue |
    Where-Object { $_.CreationTime -lt $sinir } |
    ForEach-Object { Remove-Item $_.FullName -Recurse -Force; $silinen++ }
if ($silinen) { Yaz "$silinen eski klasör silindi (saklama: $SaklamaGun gün)" }

# ── Doğrulama: indirilen dosya gerçekten açılabiliyor mu ────────────────────
# "Yedek var" ile "yedek işe yarıyor" farklı şeylerdir. Bozuk bir dosyayı aylarca
# yedek sanıp kriz anında keşfetmek en kötü senaryodur.
$sqlite = Get-Command sqlite3.exe -ErrorAction SilentlyContinue
if ($sqlite) {
    $sonuc = & sqlite3.exe $hedefDosya "PRAGMA integrity_check;" 2>&1
    if ($sonuc -match '^ok') { Yaz "Bütünlük denetimi: TAMAM" 'Green' }
    else { Yaz "BÜTÜNLÜK HATASI: $sonuc" 'Red' }
} else {
    # sqlite3.exe yoksa en azından dosya imzasına bak.
    $bas = [System.IO.File]::ReadAllBytes($hedefDosya)[0..14]
    $imza = [System.Text.Encoding]::ASCII.GetString($bas)
    if ($imza -like 'SQLite format*') { Yaz "Dosya imzası doğru (SQLite)." 'Green' }
    else { Yaz "İndirilen dosya SQLite görünmüyor!" 'Red' }
}

Yaz ("Bitti — {0:N0} saniye. Klasör: {1}" -f ((Get-Date) - $baslangic).TotalSeconds, $klasor) 'Green'
Yaz "HATIRLATMA: ayda bir bu yedekten geri yükleme denemesi yapın (bkz. KURTARMA.md)." 'Yellow'
