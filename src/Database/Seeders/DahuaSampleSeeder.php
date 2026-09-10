<?php

namespace Nawasara\Cctv\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use Nawasara\Cctv\Models\Camera;
use Nawasara\Cctv\Services\Go2rtcClient;

/**
 * Seed kamera Dahua — DIBACA DARI PERANGKAT, bukan dari daftar di berkas ini.
 *
 * Bukan auto-seed (tidak dipanggil DatabaseSeeder). Jalankan eksplisit:
 *
 *   php artisan db:seed --class="Nawasara\\Cctv\\Database\\Seeders\\DahuaSampleSeeder"
 *
 * Kredensial WAJIB dari env supaya tidak ikut ter-commit:
 *
 *   CCTV_DAHUA_SEED_HOST=103.109.206.38
 *   CCTV_DAHUA_SEED_USERNAME=...
 *   CCTV_DAHUA_SEED_PASSWORD=...
 *
 * ⚠️ Kamera yang SUDAH ADA dilewati, tidak diperbarui. Lihat catatan pada
 * run() — ini perubahan perilaku yang disengaja.
 */
class DahuaSampleSeeder extends Seeder
{
    /**
     * Nama channel bawaan perangkat, yang berarti "belum diberi nama".
     *
     * NVR mengembalikan 16 entri meski hanya sebagian terpasang kamera;
     * sisanya bernama `Saluran13`, `Channel14`, dan sejenisnya. Menyeed
     * semuanya menghasilkan kamera hantu yang tidak pernah punya gambar.
     */
    private const POLA_NAMA_BAWAAN = '/^(Channel|Saluran|CAM|IPC)\s*\d+$/i';

    public function run(): void
    {
        $host = env('CCTV_DAHUA_SEED_HOST');
        $username = env('CCTV_DAHUA_SEED_USERNAME');
        $password = env('CCTV_DAHUA_SEED_PASSWORD');

        if (! $host || ! $username || ! $password) {
            $this->command?->error(
                'Seeder dilewati: CCTV_DAHUA_SEED_HOST/USERNAME/PASSWORD tidak diset di .env. '.
                'Set ketiganya dulu untuk seed kamera.'
            );

            return;
        }

        $rtspPort = (int) env('CCTV_DAHUA_SEED_RTSP_PORT', 554);
        $httpPort = (int) env('CCTV_DAHUA_SEED_HTTP_PORT', 80);

        $channels = $this->bacaDariPerangkat($host, $httpPort, $username, $password);

        if ($channels === []) {
            $this->command?->error(
                "Tidak ada channel terbaca dari {$host}. Periksa kredensial dan "
                .'apakah perangkat dapat dijangkau dari mesin ini.'
            );

            return;
        }

        $go2rtc = app(Go2rtcClient::class);
        $sidecarUp = $go2rtc->isReachable();

        $ditambah = 0;
        $dilewati = 0;
        $terdaftar = 0;

        foreach ($channels as $channel => $info) {
            $slug = 'channel-'.$channel;

            // ⚠️ DILEWATI, bukan di-updateOrCreate.
            //
            // Versi sebelumnya menimpa seluruh kolom setiap kali dijalankan.
            // Nama dan `is_public` disunting staf lewat panel — menjalankan
            // ulang seeder mengembalikan suntingan itu diam-diam, termasuk
            // membuka kembali kamera yang sengaja disembunyikan.
            //
            // Untuk menyelaraskan nama dengan perangkat, ada jalur tersendiri
            // yang memang untuk itu: `php artisan cctv:sync-titles`.
            if (Camera::where('slug', $slug)->orWhere('channel', $channel)->exists()) {
                $dilewati++;

                continue;
            }

            $camera = Camera::create([
                'slug' => $slug,
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

                // TIDAK dipublikasikan otomatis. Yang boleh ditonton warga
                // adalah keputusan manusia setelah gambarnya diperiksa.
                'is_public' => false,

                'recording_enabled' => false,
            ]);

            $ditambah++;

            // Daftarkan ke go2rtc bila sidecar hidup — gagal tidak fatal,
            // karena `cctv:sync-go2rtc` per jam akan mencobanya lagi.
            if ($sidecarUp && $go2rtc->registerCamera($camera)) {
                $terdaftar++;
            }
        }

        $this->command?->info(
            "{$ditambah} kamera ditambahkan, {$dilewati} dilewati (sudah ada)."
        );

        if ($ditambah > 0) {
            $this->command?->info(
                $sidecarUp
                    ? "{$terdaftar}/{$ditambah} ter-register ke go2rtc."
                    : 'Sidecar go2rtc tidak dapat dijangkau — pendaftaran dilewati. '
                      .'Jalankan `cctv:sync-go2rtc` setelah sidecar hidup.'
            );

            $this->command?->warn(
                'Kamera baru dibuat TIDAK publik. Periksa gambarnya dulu, '
                .'lalu tandai "Tampilkan di aplikasi warga" lewat panel.'
            );
        }
    }

    /**
     * Baca daftar channel + codec langsung dari perangkat.
     *
     * ⚠️ Sebelumnya daftar ini ditulis tangan sebagai konstanta di berkas ini.
     * Daftar seperti itu pasti basi: saat diperiksa 10 September 2026, nama
     * channel 3 sudah berbeda (`MH.THAMRIN`, bukan `TAMRIN`) dan pemetaan
     * MLILIR bergeser satu channel — sehingga seeder akan memberi nama yang
     * keliru pada kamera yang salah.
     *
     * Perangkat adalah sumber yang benar; membacanya menghapus seluruh kelas
     * kesalahan itu.
     *
     * @return array<int, array{title: string, codec: string}>  channel => info
     */
    private function bacaDariPerangkat(
        string $host,
        int $httpPort,
        string $username,
        string $password
    ): array {
        $judul = $this->ambilConfig($host, $httpPort, $username, $password, 'ChannelTitle');
        $encode = $this->ambilConfig($host, $httpPort, $username, $password, 'Encode');

        if ($judul === null) {
            return [];
        }

        $out = [];

        // table.ChannelTitle[0].Name=SIBERUT
        //
        // ⚠️ Index perangkat mulai dari 0, channel RTSP mulai dari 1 —
        // channel = index + 1. Selisih satu inilah yang membuat pemetaan
        // MLILIR di daftar lama meleset.
        preg_match_all('/ChannelTitle\[(\d+)\]\.Name=(.*)/', $judul, $m, PREG_SET_ORDER);

        foreach ($m as $baris) {
            $index = (int) $baris[1];
            $nama = trim($baris[2]);

            if ($nama === '' || preg_match(self::POLA_NAMA_BAWAAN, $nama)) {
                continue;
            }

            $out[$index + 1] = [
                'title' => $nama,
                'codec' => $this->codecSubStream($encode, $index),
            ];
        }

        ksort($out);

        return $out;
    }

    /**
     * Codec sub-stream untuk satu index channel.
     *
     * Sub-stream (`ExtraFormat[0]`) yang dipakai grid, bukan main stream —
     * dan keduanya dapat berbeda codec pada perangkat yang sama.
     *
     * `auto` bila tidak terbaca: membiarkan go2rtc yang memutuskan lebih aman
     * daripada menebak salah, karena tebakan yang keliru berarti transcode
     * yang tidak perlu atau stream yang tidak dapat diputar.
     */
    private function codecSubStream(?string $encode, int $index): string
    {
        if ($encode === null) {
            return 'auto';
        }

        $pola = '/Encode\['.$index.'\]\.ExtraFormat\[0\]\.Video\.Compression=(\S+)/';

        if (! preg_match($pola, $encode, $m)) {
            return 'auto';
        }

        return match (strtoupper(str_replace('.', '', $m[1]))) {
            'H265', 'HEVC' => 'h265',
            'H264', 'AVC' => 'h264',
            default => 'auto',
        };
    }

    /** Satu panggilan configManager; null bila gagal. */
    private function ambilConfig(
        string $host,
        int $httpPort,
        string $username,
        string $password,
        string $nama
    ): ?string {
        try {
            $r = Http::withDigestAuth($username, $password)
                ->timeout(20)
                ->get("http://{$host}:{$httpPort}/cgi-bin/configManager.cgi", [
                    'action' => 'getConfig',
                    'name' => $nama,
                ]);

            return $r->successful() ? $r->body() : null;
        } catch (\Throwable $e) {
            $this->command?->warn("Gagal membaca {$nama} dari {$host}: {$e->getMessage()}");

            return null;
        }
    }
}
