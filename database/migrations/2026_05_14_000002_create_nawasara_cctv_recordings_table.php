<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recording segments — satu baris per file rekaman tersegmen.
 *
 * Struktur tabel disiapkan sekarang supaya model/relasi konsisten, TAPI
 * engine perekaman (go2rtc record API / ffmpeg) belum diaktifkan di v0.1.0 —
 * itu nunggu keputusan retention + storage. Lihat config 'recording'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nawasara_cctv_recordings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('camera_id')
                ->constrained('nawasara_cctv_cameras')
                ->cascadeOnDelete();

            // Lokasi file di Laravel filesystem (disk diatur di config).
            $table->string('disk')->default('local');
            $table->string('path');                       // path relatif di disk
            $table->unsignedBigInteger('size_bytes')->nullable();

            // Rentang waktu yang dicakup segmen ini — dipakai timeline playback.
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();

            // Status lifecycle: recording → completed → (purge) failed
            $table->string('status')->default('recording');

            $table->timestamps();

            $table->index(['camera_id', 'started_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_cctv_recordings');
    }
};
