<?php

namespace Nawasara\Cctv\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Satu segmen rekaman dari sebuah kamera.
 *
 * Engine perekaman belum aktif di v0.1.0 (lihat config 'recording'); model
 * ini sudah ada supaya relasi + UI playback bisa dibangun di atasnya.
 */
class Recording extends Model
{
    protected $table = 'nawasara_cctv_recordings';

    protected $fillable = [
        'camera_id',
        'disk',
        'path',
        'size_bytes',
        'started_at',
        'ended_at',
        'duration_seconds',
        'status',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'size_bytes' => 'integer',
        'duration_seconds' => 'integer',
    ];

    public function camera(): BelongsTo
    {
        return $this->belongsTo(Camera::class);
    }

    /**
     * URL temporer untuk streaming file rekaman ke browser. Pakai temporaryUrl
     * kalau disk support (S3); fallback ke url() untuk disk lokal.
     */
    public function playbackUrl(int $ttlMinutes = 30): string
    {
        $storage = Storage::disk($this->disk);

        try {
            return $storage->temporaryUrl($this->path, now()->addMinutes($ttlMinutes));
        } catch (\RuntimeException) {
            // Disk lokal tidak support temporaryUrl — fallback ke url biasa.
            return $storage->url($this->path);
        }
    }

    public function exists(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }
}
