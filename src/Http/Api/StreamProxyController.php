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
    /**
     * URL yang diterbitkan untuk halaman web (Gasta, lewat token `nws_`).
     *
     * ⚠️ Wajib diputar dengan header Origin yang terdaftar
     * (`nawasara-cctv.stream_web_origins`). Halaman Gasta publik tanpa login,
     * jadi siapa pun bisa meminta URL ini lewat Gasta; yang membuatnya tidak
     * berguna di luar Gasta adalah Origin yang dikirim browser penonton dan
     * tidak bisa diubah skrip halaman lain (22 Oktober 2026, setelah platform
     * tandingan "sipedas-view" memakai token Gasta).
     *
     * URL aplikasi warga tidak membawa `aud` tetapi membawa `mode`, dan boleh
     * tanpa Origin: pemutar video ponsel tidak mengirimnya, dan URL itu hanya
     * terbit untuk warga yang sudah login.
     */
    public const AUD_WEB = 'web';

    public function verify(Request $request, string $slug, StreamUrlSigner $signer): Response
    {
        $sig = (string) $request->query('sig', '');
        $exp = (int) $request->query('exp', 0);
        $aud = (string) $request->query('aud', '');

        // ⚠️ `mode` IKUT DIPERIKSA, dan harus sama persis dengan yang
        // ditandatangani `CitizenCameraController::stream()`.
        //
        // Nilai inilah yang dipakai nginx memilih endpoint go2rtc lewat
        // `$arg_mode`. Bila tidak ikut dalam HMAC, URL sah untuk mp4 dapat
        // diubah tangannya menjadi mode apa pun dan tetap lolos — lalu nginx
        // meneruskannya ke jalur go2rtc yang tidak pernah dimaksudkan,
        // termasuk `/api/streams` yang memuat kredensial RTSP kamera.
        //
        // URL lama (sebelum 27 Agustus 2026) tidak membawa `mode` dan
        // ditandatangani tanpa itu. Keduanya diterima: string kosong
        // menghasilkan payload yang sama dengan tanda tangan lama, sehingga
        // tontonan Gasta yang sedang berjalan tidak terputus saat dirilis.
        $mode = (string) $request->query('mode', '');

        $payload = ['slug' => $slug];
        if ($mode !== '') {
            $payload['mode'] = $mode;
        }
        if ($aud !== '') {
            $payload['aud'] = $aud;
        }

        $valid = $sig !== ''
            && $exp > 0
            && self::audienceAllows($aud, $mode, $request->header('Origin'))
            && $signer->verify($payload, $sig, $exp);

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

    /**
     * Apakah URL dengan audiens ini boleh diputar dari Origin ini?
     *
     *   aud=web            Origin wajib ada dan terdaftar
     *   tanpa aud, ada mode   URL aplikasi warga, Origin tidak diperiksa
     *   tanpa aud, tanpa mode format lama Gasta: DITOLAK, karena tidak
     *                         menyatakan audiens dan akan lolos tanpa Origin
     *   aud lain           ditolak
     */
    public static function audienceAllows(string $aud, string $mode, ?string $origin, ?array $webOrigins = null): bool
    {
        if ($aud === self::AUD_WEB) {
            $webOrigins ??= (array) config('nawasara-cctv.stream_web_origins', []);
            $origin = rtrim(strtolower(trim((string) $origin)), '/');

            return $origin !== '' && in_array($origin, array_map(fn ($o) => rtrim(strtolower((string) $o), '/'), $webOrigins), true);
        }

        if ($aud === '') {
            return $mode !== '';
        }

        return false;
    }
}
