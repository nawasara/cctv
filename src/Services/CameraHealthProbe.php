<?php

namespace Nawasara\Cctv\Services;

use Nawasara\Cctv\Models\Camera;

/**
 * Cek kesehatan kamera lewat TCP connect ke port RTSP-nya.
 *
 * Kenapa TCP connect, bukan RTSP DESCRIBE penuh: probe ini jalan periodik
 * untuk SEMUA kamera; TCP connect murah, tidak butuh kredensial, dan cukup
 * untuk membedakan "kamera/jaringan hidup" vs "mati". Kalau nanti butuh
 * deteksi "port hidup tapi stream rusak", baru naikkan ke RTSP probe.
 */
class CameraHealthProbe
{
    public function __construct(
        private readonly int $probeTimeout,
        private readonly int $failureThreshold,
    ) {}

    /**
     * Probe satu kamera, update kolom health-nya, return status final.
     */
    public function probe(Camera $camera): string
    {
        $reachable = $this->tcpReachable($camera->ip_address, $camera->rtsp_port);

        $camera->last_probed_at = now();

        if ($reachable) {
            $camera->health_status = 'online';
            $camera->failure_count = 0;
            $camera->last_seen_at = now();
        } else {
            $camera->failure_count++;
            // Baru tandai offline setelah gagal berturut sebanyak threshold —
            // hindari flap karena hiccup jaringan sesaat.
            if ($camera->failure_count >= $this->failureThreshold) {
                $camera->health_status = 'offline';
            }
        }

        $camera->save();

        return $camera->health_status;
    }

    /**
     * Probe semua kamera aktif.
     *
     * @return array{online:int, offline:int}
     */
    public function probeAll(): array
    {
        $online = 0;
        $offline = 0;

        Camera::query()->where('is_active', true)->each(function (Camera $camera) use (&$online, &$offline) {
            $status = $this->probe($camera);
            $status === 'online' ? $online++ : $offline++;
        });

        return ['online' => $online, 'offline' => $offline];
    }

    private function tcpReachable(string $host, int $port): bool
    {
        $errno = 0;
        $errstr = '';

        $conn = @fsockopen($host, $port, $errno, $errstr, $this->probeTimeout);

        if ($conn === false) {
            return false;
        }

        fclose($conn);

        return true;
    }
}
