# nawasara/cctv

Monitoring CCTV publik untuk framework superapp Nawasara. Mendukung kamera
**Dahua** via RTSP, ditampilkan ke browser lewat sidecar **go2rtc**
(RTSP → WebRTC/HLS), dengan health monitoring dan kerangka playback rekaman.

## Kenapa butuh sidecar

Browser tidak bisa memutar RTSP secara langsung. `go2rtc` adalah service
terpisah (container) yang menerima RTSP dari kamera dan mem-publish ulang
sebagai WebRTC/HLS/MSE yang browser bisa konsumsi. Laravel **tidak pernah**
menyentuh RTSP — ia hanya bicara ke HTTP API go2rtc.

```
Kamera Dahua  --RTSP-->  go2rtc (sidecar)  --WebRTC/HLS-->  Browser
                              ▲
                              │ HTTP API (register stream, query status)
                         Laravel (paket ini)
```

## Status v0.2.6

| Fitur | Status |
|---|---|
| Registry kamera + CRUD (kredensial terenkripsi) | ✅ siap |
| Live view (grid + single focus) via go2rtc | ✅ siap |
| Sinkronisasi stream ke go2rtc | ✅ siap |
| Health monitoring kamera (TCP probe) | ✅ siap |
| **Health monitoring siaran** (ambil bingkai lewat go2rtc) | ✅ siap — v0.2.1 |
| Pratinjau / thumbnail per kamera | ✅ siap — v0.2.2–v0.2.4 |
| Jumlah penonton langsung | ✅ siap — v0.2.5–v0.2.6 |
| API warga (`/citizen/cctv/*`) | ✅ siap |
| Tabel + UI playback rekaman | ✅ siap (UI) |
| **Engine perekaman** (record RTSP ke disk) | ⏳ menyusul — butuh keputusan retention/storage |

UI Recordings sudah lengkap; begitu engine perekaman diaktifkan di versi
berikutnya, halaman itu langsung berfungsi tanpa perubahan.

## Keputusan yang mudah dibatalkan tanpa tahu alasannya

### 1. Ada DUA probe kesehatan, dan yang ditampilkan ke warga bukan yang TCP

`CameraHealthProbe` melakukan TCP connect ke port RTSP; `StreamHealthProbe`
menempuh jalur warga — meminta satu bingkai dari go2rtc. Menyatukannya
terlihat seperti pembersihan yang wajar, dan justru itu yang mengembalikan
bug aslinya.

Keduanya sungguh berbeda hasilnya. Kamera dapat menjawab TCP dengan sempurna
sementara siarannya tidak dapat ditonton — kredensial berubah, stream belum
terdaftar di go2rtc, atau codec HEVC yang tak dapat ditranskode. Sebelum
v0.2.1, kamera semacam itu tampil **"Aktif"** kepada warga, yang menekannya
lalu mendapat layar hitam. Probe siaran menemukan dua kamera yang persis
begitu (channel-11 dan channel-12) pada hari ia dinyalakan.

`Camera::getPublicStatusAttribute()` karenanya mendahulukan status siaran,
dan jatuh ke status kamera hanya bila siaran belum pernah diprobe.

### 2. Bingkai di bawah 20 KB dibuang — bukan batas yang dikarang

Aliran go2rtc baru mengalir saat ada yang meminta, dan bingkai pertama
sebelum keyframe tiba berupa **bidang abu** — JPEG yang sah, dijawab 200,
tidak dapat dibedakan kecuali dari ukurannya.

Terukur di produksi: bingkai abu 6,5–10,4 KB, bingkai berisi 42–70 KB.
Ambang 20 KB berada di jurang antara keduanya, bukan menempel salah satu
ujung — pemandangan malam yang gelap menghasilkan berkas kecil, dan ambang
yang terlalu tinggi akan mencabut pratinjau kamera yang sehat.

Sebabnya juga kenapa **umur cache (660 detik) harus melebihi selang probe
(600 detik)**. Kalau lebih pendek, ada celah tempat cache sudah kosong
sementara probe berikutnya belum berjalan — dan permintaan warga yang jatuh
di celah itu memicu penangkapan dingin, yang mengembalikan bidang abu.
Memendekkan cache "supaya lebih segar" mengembalikan gambar abu.

### 3. Pemanasan hanya untuk probe, tidak pernah untuk warga

`frame(..., warmUp: true)` menunggu beberapa detik supaya aliran mengalir.
Itu benar untuk probe yang berjalan di latar tiap sepuluh menit, dan salah
untuk permintaan warga — yang akan menunggu enam detik untuk sebuah gambar
pratinjau. Warga membaca cache; probe yang membayar pemanasannya.

### Jumlah penonton menghitung SAMBUNGAN, bukan orang

Diambil dari `consumers[]` di `/api/streams` go2rtc, disegarkan tiap sepuluh
detik. Satu warga dengan dua tab terhitung dua, dan penonton dari panel
Nawasara serta Gasta ikut masuk.

Untuk lencana "sedang ramai" itu memang angka yang ditanyakan. Yang **tidak**
boleh dilakukan adalah memakainya sebagai statistik pemakaian aplikasi.

Di API, `viewers` bernilai `null` bila go2rtc tak terjangkau — dan `null`
berbeda dari `0`. Peta penontonnya dipasang lewat properti statis pada
Resource, bukan `additional()`: Laravel tidak meneruskan `additional()` dari
koleksi ke anggotanya, sehingga `viewers` selalu null sementara
`meta.viewers_total` menyebut angka yang benar. Properti itu **wajib
dikosongkan di blok `finally`** — pada worker antrean yang hidup lama, peta
yang tertinggal akan menisbatkan jumlah penonton satu permintaan ke
permintaan berikutnya.

## Setup

### 1. Sidecar go2rtc (docker-compose)

Sudah ditambahkan di `docker-compose.dev.yml` sebagai service `go2rtc`
(image `alexxit/go2rtc`), berada di network `nawasara-dev` yang sama
dengan app. Container menjangkau kamera di LAN lewat routing Docker host —
tidak perlu `network_mode` khusus selama host bisa me-route ke subnet
kamera.

Reverse-proxy `/go2rtc/` → `go2rtc:1984` sudah disiapkan di
`docker/nginx.conf` (pakai `resolver` + variabel `proxy_pass` supaya nginx
tidak gagal boot kalau sidecar belum up).

### 2. Environment

```dotenv
CCTV_GO2RTC_API_URL=http://go2rtc:1984      # internal, dipakai Laravel
CCTV_GO2RTC_PUBLIC_URL=/go2rtc              # dipakai browser (via proxy)
CCTV_GO2RTC_MODE=webrtc
```

### 3. Migrasi + permission

```bash
php artisan migrate
php artisan db:seed --class="Nawasara\\Cctv\\Database\\Seeders\\PermissionSeeder"
```

## Keamanan kredensial kamera

Username/password kamera disimpan **terenkripsi at-rest** (cast `encrypted`
di model `Camera`), disembunyikan dari serialisasi (`$hidden`), dan tidak
pernah ditulis ke log. URL RTSP lengkap (dengan kredensial) hanya dibangun
sesaat untuk dikirim ke go2rtc, tidak pernah ditampilkan ke user.

> ⚠️ Saat menambah kamera, masukkan kredensial lewat form CRUD — **jangan**
> hardcode di config/repo.

## Console commands

| Command | Fungsi | Jadwal |
|---|---|---|
| `cctv:probe` | TCP-probe kamera aktif — bahan **diagnosis petugas** | tiap 5 menit |
| `cctv:probe-streams` | Tarik satu bingkai lewat go2rtc — **inilah yang dilihat warga**, sekaligus mengisi cache pratinjau | tiap 10 menit |
| `cctv:sync-go2rtc` | Daftarkan ulang semua kamera ke go2rtc (jaring pengaman bila sidecar restart) | tiap jam |
| `cctv:sync-titles` | Ambil nama kamera dari ChannelTitle device Dahua (hanya yang `sync_title` aktif) | 03:00 WIB |

Selang `cctv:probe-streams` **terikat** ke umur cache pratinjau — lihat
keputusan 2 di atas sebelum mengubahnya.

## Permissions

| Permission | Untuk |
|---|---|
| `cctv.camera.view` | Lihat live view + daftar kamera |
| `cctv.camera.create` | Tambah kamera |
| `cctv.camera.update` | Edit kamera |
| `cctv.camera.delete` | Hapus kamera |
| `cctv.recording.view` | Lihat + putar rekaman |
| `cctv.recording.delete` | Hapus rekaman |
