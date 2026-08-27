<?php

namespace Nawasara\Cctv\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Dahua CCTV camera.
 *
 * Kredensial (username/password) di-cast 'encrypted' — disimpan sebagai
 * ciphertext di DB, otomatis dekripsi saat diakses lewat model. JANGAN
 * pernah expose nilai-nya ke log/response; activity log di bawah sengaja
 * TIDAK mencatat username/password.
 */
class Camera extends Model
{
    use LogsActivity;

    protected $table = 'nawasara_cctv_cameras';

    protected $fillable = [
        'name',
        'sync_title',
        'location',
        'latitude',
        'longitude',
        'slug',
        'ip_address',
        'rtsp_port',
        'http_port',
        'channel',
        'subtype',
        'video_codec',
        'stream_status',
        'stream_failure_count',
        'stream_probed_at',
        'stream_error',
        'username',
        'password',
        'is_active',
        'is_public',
        'health_status',
        'offline_note',
        'failure_count',
        'last_seen_at',
        'last_probed_at',
        'recording_enabled',
    ];

    protected $casts = [
        'username' => 'encrypted',
        'password' => 'encrypted',
        'is_active' => 'boolean',
        'is_public' => 'boolean',
        'sync_title' => 'boolean',
        'stream_probed_at' => 'datetime',
        'recording_enabled' => 'boolean',
        'channel' => 'integer',
        'subtype' => 'integer',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'rtsp_port' => 'integer',
        'http_port' => 'integer',
        'failure_count' => 'integer',
        'last_seen_at' => 'datetime',
        'last_probed_at' => 'datetime',
    ];

    /**
     * Status yang DITAMPILKAN KE WARGA.
     *
     * ⚠️ Memakai `stream_status`, BUKAN `health_status`.
     *
     * `health_status` menjawab "kamera hidup menurut TCP". `stream_status`
     * menjawab "kalau warga menekan tonton, apakah muncul gambar" — dan itu
     * pertanyaan yang sesungguhnya mereka ajukan.
     *
     * Keduanya dapat berbeda: kamera menjawab TCP dengan sempurna sementara
     * siarannya tidak dapat ditonton (kredensial berubah, stream belum
     * terdaftar di go2rtc, codec HEVC). Menampilkan `health_status` berarti
     * menjanjikan sesuatu yang belum tentu ada — warga menekan tonton dan
     * mendapat layar hitam.
     *
     * Selama `stream_status` masih `unknown` — probe pertama belum berjalan —
     * status kamera dipakai sebagai cadangan. Itu lebih baik daripada
     * menampilkan `unknown` untuk seluruh kamera setelah rilis, sampai
     * penjadwal berjalan.
     */
    public function getPublicStatusAttribute(): string
    {
        if (in_array($this->stream_status, ['online', 'offline'], true)) {
            return $this->stream_status;
        }

        return $this->health_status ?: 'unknown';
    }


    // Kredensial sengaja disembunyikan dari array/JSON serialization supaya
    // tidak bocor lewat ->toArray() di response Livewire atau API.
    protected $hidden = [
        'username',
        'password',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        // Catat perubahan konfigurasi kamera TAPI bukan kredensial dan bukan
        // kolom health (health berubah tiap probe — akan membanjiri log).
        return LogOptions::defaults()
            ->logOnly([
                'name',
                'sync_title',
                'location',
                'latitude',
                'longitude',
                'slug',
                'ip_address',
                'rtsp_port',
                'http_port',
                'channel',
                'subtype',
                'video_codec',
                'is_active',
                // Siapa membuka / menutup kamera ke publik wajib tercatat —
                // ini keputusan yang menyangkut apa yang dapat dilihat siapa
                // saja, bukan sekadar setelan operasional.
                'is_public',
                'recording_enabled',
                // Teks yang DIBACA WARGA. Perubahannya dicatat karena itu
                // pernyataan resmi kepada publik tentang keadaan kamera,
                // bukan catatan internal.
                'offline_note',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function recordings(): HasMany
    {
        return $this->hasMany(Recording::class);
    }

    /**
     * Bangun URL RTSP penuh dengan kredensial — dipakai HANYA untuk dikirim
     * ke go2rtc sidecar saat generate config. Tidak pernah ditampilkan ke
     * user dan tidak boleh masuk log.
     *
     * @param  int|null  $subtype  override subtype (mis. main stream untuk
     *                             single view); default pakai kolom kamera.
     */
    public function buildRtspUrl(?int $subtype = null): string
    {
        $subtype ??= $this->subtype;

        $path = str_replace(
            ['{channel}', '{subtype}'],
            [$this->channel, $subtype],
            (string) config('nawasara-cctv.dahua.rtsp_path'),
        );

        // rawurlencode kredensial — password Dahua sering punya '@' atau ':'
        // yang akan merusak parsing URL kalau tidak di-encode.
        $user = rawurlencode((string) $this->username);
        $pass = rawurlencode((string) $this->password);

        return sprintf(
            'rtsp://%s:%s@%s:%d/%s',
            $user,
            $pass,
            $this->ip_address,
            $this->rtsp_port,
            $path,
        );
    }

    /**
     * Bangun string `src` yang dikirim ke go2rtc (PUT /api/streams).
     *
     * Ini BUKAN sekadar URL RTSP — go2rtc menerima "source string" yang bisa
     * berupa RTSP mentah ATAU dibungkus prefix `ffmpeg:` untuk transcode.
     *
     * Kenapa perlu dibungkus: browser tidak bisa memutar H.265/HEVC lewat
     * WebRTC. Kamera Dahua yang streaming H.265 harus di-transcode ke H.264
     * dulu, kalau tidak video stuck 0:00 di browser.
     *
     *   video_codec = 'auto' | 'h264'  -> RTSP passthrough (no transcode)
     *   video_codec = 'h265'           -> ffmpeg: wrapper, transcode ke H.264
     *
     * @param  int|null  $subtype  override subtype, diteruskan ke buildRtspUrl()
     */
    public function buildGo2rtcSource(?int $subtype = null): string
    {
        $rtsp = $this->buildRtspUrl($subtype);

        // H.265 -> bungkus dengan ffmpeg transcode. #video=h264 minta go2rtc
        // transcode video ke H.264; #rtsp_transport=tcp lebih tahan terhadap
        // jaringan flaky daripada UDP default ffmpeg.
        if ($this->video_codec === 'h265') {
            return 'ffmpeg:'.$rtsp.'#video=h264#rtsp_transport=tcp';
        }

        // 'auto' / 'h264' -> passthrough. go2rtc connect RTSP langsung,
        // browser putar H.264 native tanpa beban CPU transcode.
        return $rtsp;
    }

    public function isOnline(): bool
    {
        return $this->health_status === 'online';
    }

    /**
     * Punya koordinat lengkap? Dipakai untuk filter kamera yang bisa
     * di-plot di peta — yang null di-skip dari map view.
     */
    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }
}
