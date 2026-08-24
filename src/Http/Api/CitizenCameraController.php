<?php

namespace Nawasara\Cctv\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nawasara\Api\Services\StreamUrlSigner;
use Nawasara\Cctv\Http\Resources\CitizenCameraResource;
use Nawasara\Cctv\Models\Camera;

/**
 * CCTV untuk aplikasi warga.
 *
 * Terpisah dari {@see CameraController} dengan sengaja. Yang itu melayani
 * integrasi antar-sistem lewat token `nws_` dan boleh melihat seluruh kamera
 * aktif; yang ini melayani warga dan **hanya** boleh melihat kamera yang
 * ditandai publik.
 *
 * Menggabungkan keduanya berarti satu berkas mengurus dua khalayak dengan
 * hak berbeda, dan penyaringan `is_public` menjadi cabang `if` yang mudah
 * terlewat saat kode berubah. Dipisah, penyaringan itu tidak punya jalan
 * untuk dilewati.
 */
class CitizenCameraController extends Controller
{
    /**
     * Batas kueri kamera publik.
     *
     * Ditulis satu kali dan dipakai ketiga aksi. Setiap aksi yang mengambil
     * kamera WAJIB lewat sini — termasuk `stream`, karena tanpa itu warga
     * dapat menebak slug kamera internal dan tetap memperoleh URL tontonnya
     * meski kamera tersebut tidak pernah muncul di daftar.
     */
    private function publicCameras()
    {
        return Camera::query()
            ->where('is_public', true)
            ->where('is_active', true);
    }

    /**
     * GET /api/v1/citizen/cctv/cameras
     */
    public function index(Request $request): JsonResponse
    {
        $query = $this->publicCameras()->orderBy('name');

        // Saringan kategori — layar CCTV menawarkan Pusat Kota, Lalu Lintas,
        // Wisata, Pasar, Siaga Bencana.
        if (($category = $request->query('category')) !== null && $category !== '') {
            $query->where('category', (string) $category);
        }

        if ($request->boolean('mappable')) {
            $query->whereNotNull('latitude')->whereNotNull('longitude');
        }

        $cameras = $query->get();

        return response()->json([
            'data' => CitizenCameraResource::collection($cameras)->resolve(),
            'meta' => ['total' => $cameras->count()],
        ]);
    }

    /**
     * GET /api/v1/citizen/cctv/cameras/{slug}
     */
    public function show(string $slug): JsonResponse
    {
        $camera = $this->publicCameras()->where('slug', $slug)->firstOrFail();

        return response()->json([
            'data' => (new CitizenCameraResource($camera))->resolve(request()),
        ]);
    }

    /**
     * GET /api/v1/citizen/cctv/cameras/{slug}/stream
     *
     * Mengembalikan URL bertanda tangan berumur pendek. Warga tidak pernah
     * melihat alamat go2rtc maupun kredensial perangkat Dahua.
     */
    public function stream(string $slug, StreamUrlSigner $signer): JsonResponse
    {
        $camera = $this->publicCameras()->where('slug', $slug)->firstOrFail();

        $signed = $signer->sign(['slug' => $camera->slug]);
        $exp = $signed['exp'];

        $base = rtrim((string) config('nawasara-cctv.go2rtc.stream_url_base') ?: url(''), '/');

        return response()->json([
            'data' => [
                'stream_url' => $base."/api/v1/cctv/stream/{$camera->slug}"
                    .'?sig='.$signed['sig']
                    .'&exp='.$exp,
                'mode' => (string) config('nawasara-cctv.go2rtc.default_mode', 'mse'),
                'expires_at' => \Carbon\Carbon::createFromTimestamp($exp)->toIso8601String(),
            ],
        ]);
    }
}
