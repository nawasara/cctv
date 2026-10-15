<?php

namespace Nawasara\Cctv\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Nawasara\Cctv\Models\Camera;

/**
 * Gerbang `auth_request` nginx untuk WebSocket player go2rtc di halaman Live.
 *
 *   browser → /go2rtc/api/ws?src=<slug>
 *           → nginx auth_request → GET /nawasara-cctv/go2rtc-auth (ini)
 *           → 204 bila boleh, lalu nginx meneruskan ke go2rtc
 *
 * ⚠️ Sebelum 15 Oktober 2026 `/go2rtc/` diteruskan ke go2rtc tanpa
 * pemeriksaan apa pun. Siapa pun di internet dapat membaca
 * `/go2rtc/api/streams`, yang memuat URL RTSP kamera BESERTA username dan
 * password-nya, dan menonton kamera non-publik (audit keamanan, temuan K2).
 * Sekarang nginx hanya membuka berkas player dan `api/ws`, dan `api/ws`
 * melewati gerbang ini.
 *
 * Slug diambil dari `X-Original-URI` yang dipasang nginx, karena subrequest
 * auth_request tidak membawa query string permintaan aslinya.
 *
 * Menjawab 401/403, bukan redirect ke login: auth_request menganggap 3xx
 * sebagai galat server, bukan penolakan.
 */
class Go2rtcAuthController
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->noContent(401);
        }

        if (! $user->can('cctv.camera.view')) {
            return response()->noContent(403);
        }

        $slug = $this->slugFrom((string) $request->header('X-Original-URI', ''));

        if ($slug === null || ! Camera::query()->where('slug', $slug)->where('is_active', true)->exists()) {
            return response()->noContent(403);
        }

        return response()->noContent();
    }

    protected function slugFrom(string $originalUri): ?string
    {
        $query = parse_url($originalUri, PHP_URL_QUERY);
        if (! is_string($query)) {
            return null;
        }

        parse_str($query, $params);
        $src = $params['src'] ?? null;

        return is_string($src) && $src !== '' ? $src : null;
    }
}
