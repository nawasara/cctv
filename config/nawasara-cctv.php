<?php

return [
    // -------------------------------------------------------------------------
    // go2rtc sidecar
    // -------------------------------------------------------------------------
    // RTSP tidak bisa diputar langsung di browser. go2rtc adalah sidecar yang
    // menerima RTSP dari kamera Dahua dan re-publish sebagai WebRTC/HLS/MSE
    // yang browser bisa konsumsi. Berjalan sebagai container terpisah di
    // docker-compose (lihat docs/runbook), Laravel cuma bicara ke HTTP API-nya.
    //
    // 'api_url'  — endpoint HTTP API go2rtc, dipakai untuk generate config
    //              stream + query status. Internal (container network).
    // 'public_url' — base URL yang dipakai BROWSER untuk WebRTC/HLS. Berbeda
    //              dari api_url karena ini harus reachable dari sisi klien
    //              (lewat reverse proxy), bukan dari container.
    'go2rtc' => [
        'api_url' => env('CCTV_GO2RTC_API_URL', 'http://go2rtc:1984'),
        'public_url' => env('CCTV_GO2RTC_PUBLIC_URL', '/go2rtc'),

        // HTTP timeout untuk panggilan API go2rtc (detik).
        'timeout' => env('CCTV_GO2RTC_TIMEOUT', 10),

        // Mode playback default di browser. 'webrtc' = latency terendah,
        // 'mse' = fallback kompatibel, 'hls' = paling kompatibel tapi delay
        // beberapa detik. Frontend bisa override per-kamera.
        'default_mode' => env('CCTV_GO2RTC_MODE', 'webrtc'),
    ],

    // -------------------------------------------------------------------------
    // Dahua RTSP defaults
    // -------------------------------------------------------------------------
    // Dari config kamera Dahua yang dishare: Port RTSP default 554, format URL
    //   rtsp://<user>:<pass>@<ip>:<port>/cam/realmonitor?channel=<n>&subtype=<s>
    // subtype: 0 = main stream (HD, berat), 1 = sub stream (SD, ringan).
    // Untuk grid monitoring banyak kamera, sub stream lebih hemat bandwidth.
    'dahua' => [
        'rtsp_port' => env('CCTV_DAHUA_RTSP_PORT', 554),
        'http_port' => env('CCTV_DAHUA_HTTP_PORT', 80),

        // Timeout (detik) untuk panggilan HTTP CGI API Dahua — dipakai
        // DahuaClient untuk baca ChannelTitle. Device kadang lambat
        // respond, kasih headroom.
        'http_timeout' => env('CCTV_DAHUA_HTTP_TIMEOUT', 12),

        // Template path RTSP. {channel} dan {subtype} di-substitusi per kamera.
        // Dipisah dari kredensial — kredensial di-inject saat build URL penuh
        // supaya tidak pernah ke-log.
        'rtsp_path' => 'cam/realmonitor?channel={channel}&subtype={subtype}',

        // subtype untuk grid (banyak kamera sekaligus) vs single view.
        'grid_subtype' => 1,   // sub stream — ringan
        'single_subtype' => 0, // main stream — HD saat fokus 1 kamera
    ],

    // -------------------------------------------------------------------------
    // Health monitoring
    // -------------------------------------------------------------------------
    // Probe periodik tiap kamera untuk badge online/offline. Default probe via
    // TCP connect ke port RTSP (murah, tidak butuh auth). Scheduler interval
    // diatur di service provider.
    'health' => [
        // Timeout TCP connect saat probe (detik).
        'probe_timeout' => env('CCTV_HEALTH_PROBE_TIMEOUT', 5),

        // Kamera dianggap 'offline' kalau gagal probe sebanyak ini berturut.
        'failure_threshold' => env('CCTV_HEALTH_FAILURE_THRESHOLD', 3),
    ],

    // -------------------------------------------------------------------------
    // Recording (tahap berikutnya — struktur DB sudah ada, engine belum)
    // -------------------------------------------------------------------------
    // Recording butuh keputusan retention + storage tersendiri. Config ini
    // placeholder supaya migration/model konsisten; engine perekaman
    // (go2rtc record API atau ffmpeg) diaktifkan di versi berikutnya.
    'recording' => [
        'enabled' => env('CCTV_RECORDING_ENABLED', false),

        // Disk Laravel filesystem tempat file rekaman disimpan.
        'disk' => env('CCTV_RECORDING_DISK', 'local'),

        // Berapa hari rekaman disimpan sebelum di-purge job retention.
        'retention_days' => env('CCTV_RECORDING_RETENTION_DAYS', 7),

        // Durasi 1 segmen rekaman (menit). Segmen pendek = playback seek lebih
        // presisi + purge lebih granular, tapi lebih banyak file.
        'segment_minutes' => env('CCTV_RECORDING_SEGMENT_MINUTES', 15),
    ],

    // Pagination
    'per_page' => 24,
];
