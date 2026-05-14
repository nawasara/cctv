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
        'location',
        'slug',
        'ip_address',
        'rtsp_port',
        'http_port',
        'channel',
        'subtype',
        'username',
        'password',
        'is_active',
        'health_status',
        'failure_count',
        'last_seen_at',
        'last_probed_at',
        'recording_enabled',
    ];

    protected $casts = [
        'username' => 'encrypted',
        'password' => 'encrypted',
        'is_active' => 'boolean',
        'recording_enabled' => 'boolean',
        'channel' => 'integer',
        'subtype' => 'integer',
        'rtsp_port' => 'integer',
        'http_port' => 'integer',
        'failure_count' => 'integer',
        'last_seen_at' => 'datetime',
        'last_probed_at' => 'datetime',
    ];

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
                'location',
                'slug',
                'ip_address',
                'rtsp_port',
                'http_port',
                'channel',
                'subtype',
                'is_active',
                'recording_enabled',
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

    public function isOnline(): bool
    {
        return $this->health_status === 'online';
    }
}
