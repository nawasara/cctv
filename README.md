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

## Status v0.1.0

| Fitur | Status |
|---|---|
| Registry kamera + CRUD (kredensial terenkripsi) | ✅ siap |
| Live view (grid + single focus) via go2rtc | ✅ siap |
| Health monitoring (TCP probe, badge online/offline) | ✅ siap |
| Sinkronisasi stream ke go2rtc | ✅ siap |
| Tabel + UI playback rekaman | ✅ siap (UI) |
| **Engine perekaman** (record RTSP ke disk) | ⏳ menyusul — butuh keputusan retention/storage |

UI Recordings sudah lengkap; begitu engine perekaman diaktifkan di versi
berikutnya, halaman itu langsung berfungsi tanpa perubahan.

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
| `cctv:probe` | TCP-probe semua kamera aktif, update status online/offline | tiap 5 menit |
| `cctv:sync-go2rtc` | Daftarkan ulang semua kamera ke go2rtc (jaring pengaman bila sidecar restart) | tiap jam |

## Permissions

| Permission | Untuk |
|---|---|
| `cctv.camera.view` | Lihat live view + daftar kamera |
| `cctv.camera.create` | Tambah kamera |
| `cctv.camera.update` | Edit kamera |
| `cctv.camera.delete` | Hapus kamera |
| `cctv.recording.view` | Lihat + putar rekaman |
| `cctv.recording.delete` | Hapus rekaman |
