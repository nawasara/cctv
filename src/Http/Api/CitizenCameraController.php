<?php

namespace Nawasara\Cctv\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
     * ⚠️ **Diselaraskan dengan selang probe (10 menit), bukan dibuat pendek.**
     *
     * Semula tiga puluh detik, dengan alasan "gambar CCTV tidak boleh basi".
     * Alasan itu benar, tetapi akibatnya justru lebih buruk: aliran go2rtc
     * baru mengalir saat ada yang meminta, dan permintaan pertama setelah
     * cache habis datang ketika aliran sudah dingin — yang dikembalikan
     * adalah bidang ABU. Warga melihat gambar abu selama sembilan setengah
     * menit dari setiap sepuluh.
     *
     * Yang mengisi cache sekarang adalah probe terjadwal, yang mampu
     * menunggu pemanasan enam detik di latar. Umur cache dibuat sedikit lebih
     * panjang daripada selang probe supaya tidak ada celah di antaranya.
     *
     * Gambar berumur sepuluh menit yang JELAS lebih berguna daripada gambar
     * berumur tiga puluh detik yang abu.
     */
    private const THUMBNAIL_TTL = 660;

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

        // Status yang SAMA dengan yang dilihat warga — bukan health_status.
        // Kamera yang TCP-nya hidup tetapi siarannya mati tidak punya bingkai
        // untuk diberikan, dan menunggu timeout-nya hanya menahan permintaan.
        if ($camera->publicStatus !== 'online') {
            return response('', 404);
        }

        // ⚠️ **Di-encode base64 sebelum masuk cache, dan itu WAJIB.**
        //
        // `CACHE_STORE=database` menyimpan nilai cache sebagai teks di MySQL.
        // JPEG adalah data biner yang memuat byte di luar UTF-8 yang sah;
        // menyimpannya apa adanya membuat MySQL menolak dengan
        // "Incorrect string value", dan permintaannya berakhir 500 — bukan
        // gambar rusak, melainkan galat server.
        //
        // Ditemukan 27 Agustus 2026: seluruh kamera `online` menjawab 500,
        // sementara yang bukan online menjawab 404 dengan benar (jalur itu
        // tidak pernah menyentuh cache).
        //
        // Base64 membesarkan ukurannya sekitar sepertiga. Itu harga yang
        // pantas untuk cache yang bekerja pada driver mana pun — dan bila
        // kelak driver-nya pindah ke Redis atau berkas, kode ini tetap benar.
        // ⚠️ **Seluruh jalur pengambilan dibungkus, dan itu disengaja.**
        //
        // Endpoint ini menyajikan GAMBAR HIASAN. Apa pun yang gagal di
        // dalamnya — cache menolak nilainya, go2rtc tidak menjawab, kameranya
        // membisu — tidak boleh berakhir sebagai 500. Aplikasi sudah tahu
        // menggambar ikon cadangan untuk 404; yang tidak dapat ditanganinya
        // dengan anggun adalah galat server.
        //
        // Sebelum ini, kegagalan apa pun jatuh sebagai 500 dan penyebabnya
        // tidak terlihat sama sekali dari luar. Sekarang dicatat ke log dengan
        // sebabnya, dan warga tetap mendapat 404 yang bersih.
        try {
            $encoded = Cache::remember(
                "cctv:thumb:{$camera->slug}",
                self::THUMBNAIL_TTL,
                function () use ($go2rtc, $camera) {
                    $raw = $go2rtc->frame($camera);

                    return $raw === null ? null : base64_encode($raw);
                },
            );

            $jpeg = $encoded === null ? null : base64_decode($encoded, true);

            // `base64_decode` mode ketat mengembalikan false bila isinya rusak
            // — nilai cache lama dari sebelum base64 dipakai, misalnya.
            if ($jpeg === false) {
                Cache::forget("cctv:thumb:{$camera->slug}");
                $jpeg = null;
            }
        } catch (\Throwable $e) {
            Log::warning('cctv thumbnail gagal', [
                'slug' => $camera->slug,
                'error' => $e->getMessage(),
                'kelas' => $e::class,
            ]);

            $jpeg = null;
        }

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
            // Cache-Control SENGAJA lebih pendek daripada TTL server: aplikasi
            // boleh meminta ulang tiap menit, dan permintaan itu murah karena
            // dijawab dari cache server. Menyamakannya dengan TTL server
            // membuat gambar tertahan di perangkat meski server sudah punya
            // yang baru.
            'Cache-Control' => 'public, max-age=60',
        ]);
    }
}
