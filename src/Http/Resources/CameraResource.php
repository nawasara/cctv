<?php

namespace Nawasara\Cctv\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Nawasara\Cctv\Models\Camera;

/**
 * Transformer kamera untuk public API. **Eksplisit listkan field** yang
 * di-expose — kredensial (username, password), IP internal device, port,
 * dan info ops lainnya **tidak pernah** masuk response.
 *
 * @mixin Camera
 */
class CameraResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // Identifier publik (bukan ID database — slug aman di URL).
            'slug' => $this->slug,
            'name' => $this->name,
            'location' => $this->location,

            // Koordinat untuk plot di peta. Cast decimal:7 di model
            // returnnya string dari Laravel — cast ke float di sini supaya
            // client tidak perlu parse.
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,

            // ⚠️ `publicStatus`, BUKAN `health_status` — sama seperti
            // CitizenCameraResource.
            //
            // `health_status` hanya menjawab "port kamera menjawab TCP", dan
            // seluruh kamera berada di NVR yang sama: begitu NVR-nya hidup,
            // SEMUANYA dilaporkan online. Klien yang mempercayainya (peta
            // Gasta, aplikasi mobile) menggambar lencana hijau pada kamera
            // yang siarannya mati, lalu penonton menekan tonton dan mendapat
            // layar hitam — aplikasi terlihat berbohong.
            //
            // `publicStatus` mengutamakan hasil probe SIARAN lewat go2rtc,
            // yang menjawab pertanyaan sesungguhnya: "kalau ditekan, muncul
            // gambar?" Lihat Camera::getPublicStatusAttribute().
            'status' => $this->publicStatus,

            // Keadaan perangkat, DIPISAH dari `status`.
            //
            // Petugas tetap perlu membedakan "kamera mati" dari "kamera hidup
            // tetapi siarannya rusak" — keduanya perbaikan yang berbeda.
            // Klien yang hanya menggambar lencana cukup membaca `status`.
            'device_status' => $this->health_status ?: 'unknown',

            'last_seen_at' => $this->last_seen_at?->toIso8601String(),

            // Info teknis non-sensitif. Channel + codec dipakai client
            // (mis. drasta) untuk display badge / decide player config.
            // IP, port, RTSP path, kredensial: DIBLOK.
            'channel' => $this->channel,
            'codec' => $this->video_codec,
        ];
    }
}
