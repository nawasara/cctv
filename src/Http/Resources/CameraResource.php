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

            // Status health: badge online/offline. 'unknown' fallback kalau
            // probe belum pernah jalan.
            'status' => $this->health_status ?: 'unknown',
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),

            // Info teknis non-sensitif. Channel + codec dipakai client
            // (mis. drasta) untuk display badge / decide player config.
            // IP, port, RTSP path, kredensial: DIBLOK.
            'channel' => $this->channel,
            'codec' => $this->video_codec,
        ];
    }
}
