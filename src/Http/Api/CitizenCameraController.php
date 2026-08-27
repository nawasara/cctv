<?php

namespace Nawasara\Cctv\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Nawasara\Api\Services\StreamUrlSigner;
use Nawasara\Cctv\Http\Resources\CitizenCameraResource;
use Nawasara\Cctv\Models\Camera;
use Nawasara\Cctv\Services\Go2rtcClient;

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
     * Mode putar yang boleh diminta pemanggil lewat `?mode=`.
     *
     * ⚠️ **Daftar-izin, dan itu WAJIB.** Nilai ini berakhir sebagai nama
     * endpoint go2rtc di dalam `rewrite` nginx. Tanpa pembatasan di sini, apa
     * pun yang diketik orang di query string menjadi jalur yang diteruskan ke
     * go2rtc — termasuk `/api/streams` yang menyingkap seluruh konfigurasi
     * kamera beserta kredensial RTSP-nya.
     *
     * `mse` sengaja TIDAK ada di sini: ia bawaan, dan pemanggil yang
     * menginginkannya cukup tidak mengirim `?mode=` sama sekali.
     */
    private const REQUESTABLE_MODES = ['mp4', 'hls'];

    /**
     * GET /api/v1/citizen/cctv/cameras/{slug}/stream
     *
     * Mengembalikan URL bertanda tangan berumur pendek. Warga tidak pernah
     * melihat alamat go2rtc maupun kredensial perangkat Dahua.
     *
     * ⚠️ **`?mode=` menentukan format siaran, dan bawaannya TIDAK diubah.**
     *
     * Aplikasi Flutter memutar lewat ExoPlayer, yang menerima HLS atau fMP4
     * melalui HTTP; ia meminta `?mode=mp4`. Gasta adalah peramban, memakai
     * Media Source Extensions, dan tidak mengirim `?mode=` sama sekali —
     * sehingga tetap menerima `mse` seperti sebelumnya.
     *
     * Karena itu modenya per-permintaan, BUKAN `CCTV_GO2RTC_MODE` global:
     * keduanya berbagi jalur `/api/v1/cctv/stream/` yang sama, dan mengubah
     * bawaannya menjadi mp4 akan mematikan Gasta.
     */
    public function stream(Request $request, string $slug, StreamUrlSigner $signer): JsonResponse
    {
        $camera = $this->publicCameras()->where('slug', $slug)->firstOrFail();

        $requested = $request->query('mode');
        $mode = in_array($requested, self::REQUESTABLE_MODES, true)
            ? (string) $requested
            : (string) config('nawasara-cctv.go2rtc.default_mode', 'mse');

        // ⚠️ Mode IKUT DITANDATANGANI bersama slug.
        //
        // Tanpa ini, URL yang sah untuk `mode=mp4` dapat diubah tangannya
        // menjadi `mode=apa-pun` dan tanda tangannya tetap cocok — sebab
        // `sig` tidak pernah menyebut mode. Nginx lalu meneruskan nilai itu
        // sebagai jalur go2rtc, dan daftar-izin di atas terlewati sama sekali
        // karena pemeriksaannya terjadi di sini, bukan di nginx.
        $signed = $signer->sign(['slug' => $camera->slug, 'mode' => $mode]);
        $exp = $signed['exp'];

        $base = rtrim((string) config('nawasara-cctv.go2rtc.stream_url_base') ?: url(''), '/');

        return response()->json([
            'data' => [
                'stream_url' => $base."/api/v1/cctv/stream/{$camera->slug}"
                    .'?mode='.$mode
                    .'&sig='.$signed['sig']
                    .'&exp='.$exp,
                'mode' => $mode,
                'expires_at' => \Carbon\Carbon::createFromTimestamp($exp)->toIso8601String(),
            ],
        ]);
    }

    /**
     * Berapa lama satu tangkapan bingkai dipakai ulang.
     *
     * ⚠️ **Cache di sini melindungi KAMERA, bukan server.** Satu warga
     * membuka layar CCTV berarti selusin permintaan thumbnail serentak, dan
     * setiap tangkapan menuntut go2rtc membuka RTSP ke perangkat Dahua lalu
     * menunggu keyframe. Perangkat itu tidak dibuat untuk dipanggil selusin
     * kali per detik oleh setiap warga yang membuka aplikasi.
     *
     * Tiga puluh detik: cukup lama untuk menahan gelombang permintaan, cukup
     * pendek supaya gambarnya tidak menyesatkan. CCTV yang basi lima menit
     * memberi tahu warga tentang keadaan yang sudah lewat.
     */
    private const THUMBNAIL_TTL = 30;

    /**
     * GET /api/v1/citizen/cctv/cameras/{slug}/thumbnail
     *
     * Menjawab **JPEG**, bukan JSON — aplikasi memasangnya langsung sebagai
     * gambar.
     *
     * ⚠️ **Kamera yang tidak hidup menjawab 404 tanpa menyentuh go2rtc.**
     * Menangkap bingkai dari kamera mati hanya menghabiskan waktu tunggu, dan
     * aplikasi sudah tahu cara menggambar keadaan "tidak aktif" dari `status`
     * di daftar. 404 di sini berarti "tidak ada gambar", bukan kegagalan.
     */
    public function thumbnail(string $slug, Go2rtcClient $go2rtc): Response
    {
        $camera = $this->publicCameras()->where('slug', $slug)->firstOrFail();

        if ($camera->health_status !== 'online') {
            return response('', 404);
        }

        $jpeg = Cache::remember(
            "cctv:thumb:{$camera->slug}",
            self::THUMBNAIL_TTL,
            fn () => $go2rtc->frame($camera),
        );

        // Tangkapan gagal — kamera sedang tidak menjawab meski statusnya
        // online, atau go2rtc belum sempat menyiapkan stream-nya.
        //
        // ⚠️ Kegagalan JUGA di-cache (`Cache::remember` menyimpan null), dan
        // itu disengaja: tanpa itu, kamera yang bermasalah akan ditangkap ulang
        // pada setiap permintaan — persis kamera yang paling tidak boleh
        // dibebani.
        if ($jpeg === null) {
            return response('', 404);
        }

        return response($jpeg, 200, [
            'Content-Type' => 'image/jpeg',

            // Aplikasi pun tidak perlu meminta ulang dalam rentang ini.
            'Cache-Control' => 'public, max-age='.self::THUMBNAIL_TTL,
        ]);
    }
}
