<?php

namespace Nawasara\Cctv\Http\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Nawasara\Api\Models\ApiAccessLog;
use Nawasara\Api\Services\StreamUrlSigner;
use Nawasara\Cctv\Models\Camera;

/**
 * Verify-only endpoint untuk Nginx `auth_request`. Tidak mengirim body
 * stream — Nginx yang proxy traffic video setelah verify lulus.
 *
 * Alur:
 *   1. Client connect ke /api/v1/cctv/stream/{slug}?sig=&exp=
 *   2. Nginx panggil endpoint ini lewat auth_request
 *   3. Endpoint ini validate sig + exp, log akses, return 200 / 403
 *   4. Kalau 200 → Nginx `proxy_pass` ke http://go2rtc:1984/api/...
 *      (kalau 403 → Nginx tutup connection)
 *
 * TIDAK butuh API token — auth via signed URL. Token sudah di-verify saat
 * generate URL di CameraController::stream.
 */
class StreamProxyController extends Controller
{
    public function verify(Request $request, string $slug, StreamUrlSigner $signer): Response
    {
        $sig = (string) $request->query('sig', '');
        $exp = (int) $request->query('exp', 0);

        $valid = $sig !== ''
            && $exp > 0
            && $signer->verify(['slug' => $slug], $sig, $exp);

        // Validasi: kamera memang exist + aktif. Sig valid tapi kamera
        // sudah dihapus → tetap reject, jangan biarkan Nginx proxy.
        if ($valid) {
            $valid = Camera::where('slug', $slug)
                ->where('is_active', true)
                ->exists();
        }

        $status = $valid ? 200 : 403;

        // Log ke api_access_logs supaya audit log "siapa nonton stream
        // apa, kapan" tersedia. Best-effort — jangan crash kalau log gagal.
        try {
            ApiAccessLog::create([
                'api_token_id' => null, // signed URL — token id tidak ter-attach di hop ini
                'method' => 'GET',
                'path' => substr("/api/v1/cctv/stream/{$slug}", 0, 512),
                'status' => $status,
                'kind' => ApiAccessLog::KIND_STREAM_VERIFY,
                'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 512),
            ]);
        } catch (\Throwable) {
            // ignore
        }

        return response('', $status);
    }
}
