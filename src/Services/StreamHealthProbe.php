<?php

namespace Nawasara\Cctv\Services;

use Nawasara\Cctv\Models\Camera;

/**
 * Memeriksa apakah siaran BENAR-BENAR dapat ditonton — bukan sekadar
 * kameranya hidup.
 *
 * ⚠️ **Ini berbeda dari [CameraHealthProbe], dan perbedaannya yang menipu.**
 *
 * `CameraHealthProbe` melakukan TCP connect ke port RTSP kamera. Ia menjawab
 * "kamera dan jaringannya hidup". Probe ini menempuh **jalur yang sama dengan
 * warga**: meminta satu bingkai dari go2rtc.
 *
 * Keduanya dapat berbeda, dan setiap perbedaan itu adalah janji palsu di
 * layar warga:
 *
 *   TCP hidup, bingkai gagal  Kredensial kamera berubah; go2rtc belum
 *                             mendaftarkan stream-nya; codec HEVC yang tidak
 *                             dapat ditranskode; kamera membalas tetapi tidak
 *                             mengirim bingkai. Warga melihat "Aktif", menekan
 *                             tonton, dan mendapat layar hitam.
 *   TCP gagal, bingkai hidup  go2rtc menyimpan sambungan yang sudah terbangun,
 *                             atau kamera menolak koneksi baru sementara yang
 *                             lama masih mengalir. Menandainya offline
 *                             menyembunyikan siaran yang sebenarnya jalan.
 *
 * Yang ditampilkan ke warga adalah HASIL PROBE INI, karena inilah yang
 * menjawab pertanyaan yang sesungguhnya mereka ajukan: "kalau saya tekan,
 * apakah muncul gambar?"
 */
class StreamHealthProbe
{
    public function __construct(
        private readonly Go2rtcClient $go2rtc,
        private readonly int $failureThreshold,
        private readonly int $timeoutSeconds,
    ) {}

    /**
     * Probe satu kamera. Mengembalikan status akhirnya.
     */
    public function probe(Camera $camera): string
    {
        $camera->stream_probed_at = now();

        $frame = $this->go2rtc->frame($camera, $this->timeoutSeconds);

        // `frame()` sudah mengembalikan null untuk badan kosong — go2rtc dapat
        // menjawab 200 dengan nol byte saat stream terdaftar tetapi kameranya
        // bisu. Itu justru kasus yang paling menipu, dan sudah tertangkap.
        if ($frame !== null && $frame !== '') {
            $camera->stream_status = 'online';
            $camera->stream_failure_count = 0;
            $camera->stream_error = null;
            $camera->save();

            return 'online';
        }

        $camera->stream_failure_count++;

        // Ambang berturut, sama alasannya dengan CameraHealthProbe: satu
        // kegagalan dapat berarti go2rtc sedang sibuk, bukan siarannya mati.
        // Menandai offline pada percobaan pertama membuat lencana berkedip
        // dan warga berhenti mempercayainya.
        if ($camera->stream_failure_count >= $this->failureThreshold) {
            $camera->stream_status = 'offline';
            $camera->stream_error = $this->diagnose($camera);
        }

        $camera->save();

        return $camera->stream_status;
    }

    /**
     * Probe seluruh kamera aktif.
     *
     * @return array{online:int, offline:int, menipu:int}
     */
    public function probeAll(): array
    {
        $online = 0;
        $offline = 0;
        $menipu = 0;

        Camera::query()
            ->where('is_active', true)
            ->each(function (Camera $camera) use (&$online, &$offline, &$menipu) {
                $sebelum = $camera->health_status;
                $status = $this->probe($camera);

                $status === 'online' ? $online++ : $offline++;

                // Yang paling perlu diketahui petugas: kamera yang tampak
                // hidup menurut TCP tetapi siarannya tidak dapat ditonton.
                // Inilah baris yang selama ini dilihat warga sebagai "Aktif".
                if ($sebelum === 'online' && $status === 'offline') {
                    $menipu++;
                }
            });

        return ['online' => $online, 'offline' => $offline, 'menipu' => $menipu];
    }

    /**
     * Keterangan teknis untuk petugas — bukan untuk warga.
     *
     * Warga membaca `offline_note` yang ditulis manusia ("Perbaikan
     * jaringan"). Yang ini menjelaskan DI MANA rantainya putus, supaya
     * petugas tidak memeriksa kamera padahal go2rtc yang bermasalah.
     */
    private function diagnose(Camera $camera): string
    {
        if (! $this->go2rtc->isReachable()) {
            return 'go2rtc tidak dapat dihubungi — periksa sidecar, bukan kameranya.';
        }

        $terdaftar = array_key_exists($camera->slug, $this->go2rtc->streams());

        if (! $terdaftar) {
            return 'Stream belum terdaftar di go2rtc. Jalankan cctv:sync-go2rtc.';
        }

        if ($camera->health_status === 'offline') {
            return 'Kamera tidak menjawab di port RTSP.';
        }

        // Sampai di sini: go2rtc hidup, stream terdaftar, kamera menjawab TCP —
        // tetapi bingkainya tidak datang. Biasanya kredensial atau codec.
        return 'Kamera menjawab tetapi tidak mengirim bingkai — periksa kredensial atau codec.';
    }
}
