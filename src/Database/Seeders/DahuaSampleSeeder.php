<?php

namespace Nawasara\Cctv\Database\Seeders;

use Illuminate\Database\Seeder;
use Nawasara\Cctv\Models\Camera;
use Nawasara\Cctv\Services\Go2rtcClient;

/**
 * Seed 12 kamera Dahua dari ChannelTitle yang sudah diverifikasi.
 *
 * Bukan auto-seed (tidak di-call DatabaseSeeder). Pakai eksplisit:
 *
 *   php artisan db:seed --class="Nawasara\\Cctv\\Database\\Seeders\\DahuaSampleSeeder"
 *
 * Kredensial WAJIB di env supaya tidak ke-commit ke repo:
 *
 *   CCTV_DAHUA_SEED_HOST=103.109.206.38
 *   CCTV_DAHUA_SEED_USERNAME=...
 *   CCTV_DAHUA_SEED_PASSWORD=...
 *
 * Tanpa env tersebut, seeder abort dengan pesan jelas — jaga supaya
 * kredensial tidak default ke nilai contoh yang lemah.
 *
 * Idempotent: pakai updateOrCreate by slug. Aman dijalankan ulang.
 * Tiap kamera otomatis di-register ke go2rtc kalau sidecar reachable.
 */
class DahuaSampleSeeder extends Seeder
{
    /**
     * Nama channel dari device Dahua (sudah diverifikasi via API
     * /cgi-bin/configManager.cgi?action=getConfig&name=ChannelTitle).
     * Channel 1-10 streaming H.265 (butuh transcode), 11-12 H.264
     * (passthrough — hemat CPU).
     */
    private const CHANNELS = [
        1  => ['title' => 'SIBERUT',          'codec' => 'h265'],
        2  => ['title' => 'SEGITIGA NGEPOS',  'codec' => 'h265'],
        3  => ['title' => 'TAMRIN',           'codec' => 'h265'],
        4  => ['title' => 'MASJID DUWUR',     'codec' => 'h265'],
        5  => ['title' => 'BRI',              'codec' => 'h265'],
        6  => ['title' => 'DR SOETOMO',       'codec' => 'h265'],
        7  => ['title' => 'TL NGEPOS UTARA',  'codec' => 'h265'],
        8  => ['title' => 'TL NGEPOS TIMUR',  'codec' => 'h265'],
        9  => ['title' => 'PASAR LEGI TIMUR', 'codec' => 'h265'],
        10 => ['title' => 'PASAR LEGI BARAT', 'codec' => 'h265'],
        11 => ['title' => 'MLILIR UTARA',     'codec' => 'auto'],
        12 => ['title' => 'MLILIR SELATAN',   'codec' => 'auto'],
    ];

    public function run(): void
    {
        $host = env('CCTV_DAHUA_SEED_HOST');
        $username = env('CCTV_DAHUA_SEED_USERNAME');
        $password = env('CCTV_DAHUA_SEED_PASSWORD');

        if (! $host || ! $username || ! $password) {
            $this->command?->error(
                'Seeder skip: CCTV_DAHUA_SEED_HOST/USERNAME/PASSWORD tidak diset di .env. '.
                'Set ketiganya dulu untuk seed kamera.'
            );

            return;
        }

        $rtspPort = (int) env('CCTV_DAHUA_SEED_RTSP_PORT', 554);
        $httpPort = (int) env('CCTV_DAHUA_SEED_HTTP_PORT', 80);

        $go2rtc = app(Go2rtcClient::class);
        $sidecarUp = $go2rtc->isReachable();

        $created = 0;
        $registered = 0;

        foreach (self::CHANNELS as $channel => $info) {
            $camera = Camera::updateOrCreate(
                ['slug' => 'channel-'.$channel],
                [
                    'name' => "D{$channel} {$info['title']}",
                    'location' => $info['title'],
                    'sync_title' => true,
                    'ip_address' => $host,
                    'rtsp_port' => $rtspPort,
                    'http_port' => $httpPort,
                    'channel' => $channel,
                    'subtype' => 1,  // sub-stream — hemat bandwidth untuk grid
                    'video_codec' => $info['codec'],
                    'username' => $username,
                    'password' => $password,
                    'is_active' => true,
                    'recording_enabled' => false,
                ]
            );
            $created++;

            // Daftarkan ke go2rtc kalau sidecar up — gagal tidak fatal
            // (cctv:sync-go2rtc per jam akan retry).
            if ($sidecarUp && $go2rtc->registerCamera($camera)) {
                $registered++;
            }
        }

        $this->command?->info("Seeded {$created} kamera Dahua.");
        $this->command?->info(
            $sidecarUp
                ? "{$registered}/{$created} ter-register ke go2rtc."
                : 'Sidecar go2rtc tidak reachable — register di-skip. '.
                  'Jalankan `cctv:sync-go2rtc` setelah sidecar up, atau tunggu scheduler hourly.'
        );
    }
}
