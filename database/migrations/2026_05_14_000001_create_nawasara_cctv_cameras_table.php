<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Camera registry — satu baris per kamera Dahua.
 *
 * Kredensial (username/password) DISIMPAN TERENKRIPSI lewat cast 'encrypted'
 * di model Camera. Kolom-nya `text` karena ciphertext Laravel jauh lebih
 * panjang dari plaintext aslinya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nawasara_cctv_cameras', function (Blueprint $table) {
            $table->id();

            // Identitas
            $table->string('name');                 // "Gerbang Utama", dll
            $table->string('location')->nullable(); // deskripsi lokasi fisik
            $table->string('slug')->unique();        // dipakai sbg stream id di go2rtc

            // Koneksi RTSP
            $table->string('ip_address');
            $table->unsignedSmallInteger('rtsp_port')->default(554);
            $table->unsignedSmallInteger('http_port')->default(80);
            $table->unsignedTinyInteger('channel')->default(1); // Dahua channel 1-16
            $table->unsignedTinyInteger('subtype')->default(0); // 0=main, 1=sub

            // Kredensial — TERENKRIPSI at-rest (cast 'encrypted' di model).
            // JANGAN query langsung; selalu lewat model accessor.
            $table->text('username');
            $table->text('password');

            // Status & monitoring
            $table->boolean('is_active')->default(true);  // admin enable/disable
            $table->string('health_status')->default('unknown'); // online|offline|unknown
            $table->unsignedSmallInteger('failure_count')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_probed_at')->nullable();

            // Recording opt-in per kamera (engine diaktifkan tahap berikutnya)
            $table->boolean('recording_enabled')->default(false);

            $table->timestamps();

            $table->index('is_active');
            $table->index('health_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_cctv_cameras');
    }
};
