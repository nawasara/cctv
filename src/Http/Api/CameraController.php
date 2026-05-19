<?php

namespace Nawasara\Cctv\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nawasara\Cctv\Http\Resources\CameraResource;
use Nawasara\Cctv\Models\Camera;
use Nawasara\Api\Services\StreamUrlSigner;

/**
 * Public API untuk CCTV.
 *
 * Auth + scope sudah di-cek di middleware sebelum controller jalan:
 *   - api.auth → token valid + aktif
 *   - scope:cctv.camera.read → list + show
 *   - scope:cctv.camera.stream → stream
 */
class CameraController extends Controller
{
    /**
     * GET /api/v1/cctv/cameras
     * Scope: cctv.camera.read
     *
     * List kamera publik yang aktif. Filter `mappable` opsional via query —
     * default include semua aktif (drasta filter di sisi client kalau perlu).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Camera::query()
            ->where('is_active', true)
            ->orderBy('name');

        // Opsi: ?mappable=1 → hanya yang punya koordinat. Default tidak
        // filter (client decide), tapi kalau diisi 1, hemat payload.
        if ($request->boolean('mappable')) {
            $query->whereNotNull('latitude')->whereNotNull('longitude');
        }

        $cameras = $query->get();

        return response()->json([
            'data' => CameraResource::collection($cameras)->resolve(),
            'meta' => ['total' => $cameras->count()],
        ]);
    }

    /**
     * GET /api/v1/cctv/cameras/{slug}
     * Scope: cctv.camera.read
     */
    public function show(string $slug): JsonResponse
    {
        $camera = Camera::where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        return response()->json([
            'data' => (new CameraResource($camera))->resolve(request()),
        ]);
    }

    /**
     * GET /api/v1/cctv/cameras/{slug}/stream
     * Scope: cctv.camera.stream
     *
     * Generate signed proxy URL ke stream go2rtc (TTL 5 menit default).
     * URL pointing ke endpoint internal `/api/v1/cctv/stream/{slug}` yang
     * Nginx auth_request validate sebelum proxy ke go2rtc internal.
     *
     * Client tidak pernah lihat URL go2rtc atau kredensial Dahua.
     */
    public function stream(string $slug, StreamUrlSigner $signer): JsonResponse
    {
        $camera = Camera::where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $signed = $signer->sign(['slug' => $camera->slug]);
        $exp = $signed['exp'];

        // Pakai subdomain stream khusus kalau di-set, fallback ke APP_URL.
        // Subdomain terpisah di-route via Cloudflare Tunnel (lebih reliable
        // untuk WebSocket cross-origin daripada Cloudflare HTTP proxy).
        $base = rtrim((string) config('nawasara-cctv.go2rtc.stream_url_base') ?: url(''), '/');

        $streamUrl = $base."/api/v1/cctv/stream/{$camera->slug}"
            .'?sig='.$signed['sig']
            .'&exp='.$exp;

        return response()->json([
            'data' => [
                'stream_url' => $streamUrl,
                'mode' => (string) config('nawasara-cctv.go2rtc.default_mode', 'mse'),
                'expires_at' => \Carbon\Carbon::createFromTimestamp($exp)->toIso8601String(),
            ],
        ]);
    }
}
