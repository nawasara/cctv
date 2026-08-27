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
    public function frame(Camera $camera, int $timeoutSeconds = 10): ?string
    {
        try {
            $response = Http::baseUrl(rtrim($this->apiUrl, '/'))
                ->timeout($timeoutSeconds)
                ->withQueryParameters(['src' => $camera->slug])
                ->get('/api/frame.jpeg');

            if (! $response->successful()) {
                return null;
            }

            $body = $response->body();

            // go2rtc dapat menjawab 200 dengan badan kosong bila stream-nya
            // terdaftar tetapi kameranya tidak menjawab. Menyimpan itu ke
            // cache berarti warga melihat gambar rusak selama 30 detik
            // berikutnya.
            return $body === '' ? null : $body;
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
}
