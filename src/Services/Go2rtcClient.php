<?php

namespace Nawasara\Cctv\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Nawasara\Cctv\Models\Camera;

/**
 * Thin client untuk go2rtc HTTP API.
 *
 * go2rtc adalah sidecar yang menjembatani RTSP (dari kamera Dahua) ke
 * WebRTC/HLS/MSE (yang browser bisa konsumsi). Laravel TIDAK menyentuh RTSP
 * langsung — semua lewat sidecar ini.
 *
 * Tanggung jawab:
 *   - Register / sinkronkan daftar stream kamera ke go2rtc (PUT /api/streams).
 *   - Query status stream (online producer/consumer count).
 *   - Sediakan URL embed WebRTC/HLS untuk dipakai frontend.
 *
 * Catatan keamanan: payload PUT stream berisi URL RTSP LENGKAP dengan
 * kredensial. Itu wajar — go2rtc memang butuh kredensial untuk connect ke
 * kamera. Yang TIDAK boleh: nge-log payload itu. Semua log di sini sengaja
 * cuma mencatat slug kamera, bukan URL.
 */
class Go2rtcClient
{
    public function __construct(
        private readonly string $apiUrl,
        private readonly int $timeout,
    ) {}

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->apiUrl, '/'))
            ->timeout($this->timeout)
            ->acceptJson();
    }

    /**
     * Daftarkan / update satu stream kamera di go2rtc.
     *
     * go2rtc meng-key stream by name; memanggil ini lagi dengan slug yang
     * sama akan meng-overwrite (idempotent). Dipakai saat kamera dibuat /
     * diedit dan saat sinkronisasi penuh.
     */
    public function registerCamera(Camera $camera): bool
    {
        try {
            // go2rtc /api/streams membaca parameter dari QUERY STRING, bukan
            // request body. `Http::put($url, [...])` default kirim JSON body
            // yang go2rtc abaikan diam-diam (balas 200 tapi stream tidak
            // terdaftar). Karena itu params dikirim via ->withQueryParameters().
            //
            // src = buildGo2rtcSource() (bukan buildRtspUrl) — kamera H.265
            // perlu dibungkus prefix ffmpeg: untuk transcode ke H.264.
            $response = $this->http()
                ->withQueryParameters([
                    'name' => $camera->slug,
                    'src' => $camera->buildGo2rtcSource(),
                ])
                ->put('/api/streams');

            if ($response->failed()) {
                Log::warning('go2rtc registerCamera failed', [
                    'camera' => $camera->slug,
                    'status' => $response->status(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            // Sidecar mungkin belum up — jangan throw, biar CRUD kamera tetap
            // jalan; sinkronisasi bisa di-retry lewat command/scheduler.
            Log::warning('go2rtc registerCamera error', [
                'camera' => $camera->slug,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Hapus stream kamera dari go2rtc (mis. saat kamera dihapus / dinonaktifkan).
     */
    public function removeCamera(string $slug): bool
    {
        try {
            // Sama seperti registerCamera: go2rtc baca param dari query string.
            // DELETE /api/streams?src=<name> menghapus stream by name.
            $response = $this->http()
                ->withQueryParameters(['src' => $slug])
                ->delete('/api/streams');

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('go2rtc removeCamera error', [
                'camera' => $slug,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Sinkronisasi penuh: pastikan SEMUA kamera aktif terdaftar di go2rtc.
     * Dipakai saat sidecar baru restart (state go2rtc in-memory, hilang saat
     * restart kecuali pakai config file persisted).
     *
     * @return array{synced:int, failed:int}
     */
    public function syncAllCameras(): array
    {
        $synced = 0;
        $failed = 0;

        Camera::query()->where('is_active', true)->each(function (Camera $camera) use (&$synced, &$failed) {
            if ($this->registerCamera($camera)) {
                $synced++;
            } else {
                $failed++;
            }
        });

        return ['synced' => $synced, 'failed' => $failed];
    }

    /**
     * Ambil daftar stream beserta status dari go2rtc.
     *
     * @return array<string,mixed> keyed by stream name; kosong kalau sidecar
     *                             tidak reachable.
     */
    public function streams(): array
    {
        try {
            $response = $this->http()->get('/api/streams');

            return $response->successful() ? (array) $response->json() : [];
        } catch (\Throwable $e) {
            Log::warning('go2rtc streams query error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Apakah sidecar go2rtc reachable? Dipakai untuk badge status di UI.
     */
    public function isReachable(): bool
    {
        try {
            return $this->http()->get('/api/streams')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Tangkap satu bingkai sebagai JPEG. Null bila gagal.
     *
     * Dipakai thumbnail layar CCTV aplikasi warga. Tangkapan ini MAHAL bagi
     * kamera: go2rtc harus membuka koneksi RTSP ke perangkat Dahua, menunggu
     * keyframe, lalu men-decode-nya. Karena itu pemanggil WAJIB men-cache
     * hasilnya — lihat `CitizenCameraController::thumbnail()`.
     *
     * ⚠️ **Batas waktunya sendiri, lebih longgar dari panggilan API biasa.**
     * `/api/streams` menjawab dari memori dalam milidetik; menangkap bingkai
     * menunggu keyframe dari kamera, yang pada Dahua bergantung pada interval
     * GOP-nya — dua sampai empat detik bukan hal aneh. Memakai batas waktu
     * yang sama membuat tangkapan gagal justru pada kamera yang sehat.
     */
    /**
     * Ukuran minimal bingkai yang dianggap BERISI GAMBAR.
     *
     * ⚠️ Bingkai abu-abu tetap JPEG yang sah dan tetap dijawab 200 — ia hanya
     * kecil, karena bidang polos hampir tidak menghasilkan data terkompresi.
     * Tanpa ambang ini, gambar abu tersimpan ke cache dan warga melihatnya
     * selama tiga puluh detik berikutnya.
     *
     * Diukur di produksi 27 Agustus 2026:
     *
     *   dingin  6,5 – 10 KB    abu, keyframe belum tiba
     *   hangat   42 – 70 KB    gambar sungguhan
     *
     * 20 KB dipilih di tengah jurang itu — cukup tinggi untuk menolak yang
     * abu, cukup rendah untuk menerima pemandangan malam yang gelap dan
     * memang berukuran kecil.
     */
    private const MIN_FRAME_BYTES = 20000;

    /**
     * Menangkap satu bingkai JPEG dari go2rtc.
     *
     * ⚠️ **Bingkai berisi hanya keluar selama ADA KONSUMEN yang menempel.**
     *
     * go2rtc menyambung ke kamera saat diminta, tetapi bingkai yang dilayani
     * `frame.jpeg` baru terisi ketika aliran sedang benar-benar mengalir ke
     * seseorang. Tanpa konsumen, yang keluar adalah bidang abu — 200, JPEG
     * sah, dan salah. Itulah gambar abu yang terlihat di aplikasi warga.
     *
     * Korelasinya diukur di produksi 27 Agustus 2026, sempurna pada sembilan
     * kamera:
     *
     *   consumers=0   5 – 9 KB    abu
     *   consumers=1  47 – 51 KB   gambar sungguhan
     *
     * Karena itu penangkapan dilakukan **sambil koneksi aliran masih dibuka**,
     * bukan sesudah ditutup.
     *
     * Yang sudah dicoba dan TIDAK menolong — jangan diulang:
     *
     *   `?duration=3`            tetap ~10 KB
     *   pemanasan lalu tutup     6, 8, 12, 16, dan 20 detik, semuanya abu
     *   membaca aliran Guzzle    271 KB terbaca, bingkai tetap ~7 KB
     *   soket mentah lalu tutup  854 KB terbaca, bingkai tetap ~9 KB
     *   menangkap berulang       enam kali berturut, semuanya abu
     *
     * Semuanya gagal karena alasan yang sama: koneksinya sudah tertutup saat
     * bingkai diambil.
     */
    public function frame(Camera $camera, int $timeoutSeconds = 10, bool $warmUp = false): ?string
    {
        try {
            $body = $this->grabFrame($camera, $timeoutSeconds);

            // Cukup berisi — kamera ini sudah punya penonton lain, atau
            // memang sedang mengalir.
            if ($body !== null && strlen($body) >= self::MIN_FRAME_BYTES) {
                return $body;
            }

            // ⚠️ Hanya probe terjadwal yang boleh menunggu. Permintaan warga
            // tidak: menahan aliran beberapa detik membuat mereka menunggu
            // gambar yang tak kunjung datang, dan probe sudah mengisikan
            // cache-nya di latar.
            if (! $warmUp) {
                return $body;
            }

            $panas = $this->frameWhileStreaming($camera, $timeoutSeconds);

            // Yang panas menang; kalau gagal, yang dingin tetap lebih baik
            // daripada tidak ada gambar sama sekali.
            return $panas ?? $body;
        } catch (\Throwable $e) {
            // Hanya slug yang dicatat — jangan pernah menuliskan URL sumber,
            // ia memuat kredensial RTSP (lihat catatan kelas).
            Log::warning('go2rtc frame capture error', [
                'slug' => $camera->slug,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Membuka aliran, MENAHANNYA tetap terbuka, lalu menangkap bingkai.
     *
     * Soket mentah dipakai karena aliran ini tidak pernah berakhir sendiri —
     * klien HTTP biasa akan menunggu badan yang tak kunjung selesai. Yang
     * dibutuhkan hanyalah koneksi yang tetap hidup selama penangkapan.
     */
    private function frameWhileStreaming(Camera $camera, int $timeoutSeconds): ?string
    {
        $url = parse_url(rtrim($this->apiUrl, '/'));
        $host = $url['host'] ?? '127.0.0.1';
        $port = (int) ($url['port'] ?? 80);

        $sock = @fsockopen($host, $port, $errno, $errstr, 3);

        if ($sock === false) {
            return null;
        }

        try {
            $path = '/api/stream.mp4?src='.rawurlencode($camera->slug);

            fwrite($sock, "GET {$path} HTTP/1.0

Host: {$host}

Connection: close



");
            stream_set_timeout($sock, self::WARMUP_SECONDS);

            // Dibaca sebentar supaya go2rtc benar-benar mengalirkan data —
            // koneksi yang dibuka tanpa dibaca akan tersumbat penyangganya.
            $batas = microtime(true) + self::WARMUP_SECONDS;

            while (! feof($sock) && microtime(true) < $batas) {
                if (fread($sock, 16384) === false) {
                    break;
                }
            }

            // Bingkai diambil SEBELUM soket ditutup — inilah keseluruhan
            // maksudnya.
            return $this->grabFrame($camera, $timeoutSeconds);
        } finally {
            fclose($sock);
        }
    }

    /** Satu kali tangkap, tanpa pemanasan. */
    private function grabFrame(Camera $camera, int $timeoutSeconds): ?string
    {
        $response = Http::baseUrl(rtrim($this->apiUrl, '/'))
            ->timeout($timeoutSeconds)
            ->withQueryParameters(['src' => $camera->slug])
            ->get('/api/frame.jpeg');

        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();

        // go2rtc dapat menjawab 200 dengan badan kosong bila stream-nya
        // terdaftar tetapi kameranya tidak menjawab. Menyimpan itu ke cache
        // berarti warga melihat gambar rusak selama 30 detik berikutnya.
        return $body === '' ? null : $body;
    }

    /**
     * Berapa lama aliran DITAHAN TERBUKA sebelum bingkai ditangkap.
     *
     * Diukur: enam detik cukup bagi kamera Dahua di jaringan ini untuk
     * benar-benar mengalir. Yang menentukan bukan lamanya menunggu melainkan
     * koneksinya masih hidup saat penangkapan — pemanasan 20 detik yang
     * ditutup lebih dulu tetap menghasilkan bidang abu.
     */
    private const WARMUP_SECONDS = 6;
}
