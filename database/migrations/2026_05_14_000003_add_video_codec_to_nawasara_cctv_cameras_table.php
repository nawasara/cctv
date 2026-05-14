<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah kolom video_codec ke tabel cameras.
 *
 * Kenapa: browser TIDAK bisa memutar H.265/HEVC lewat WebRTC (cuma
 * H.264/VP8/VP9/AV1). Banyak kamera Dahua default streaming H.265 — tanpa
 * transcode, video stuck 0:00 di browser.
 *
 *   'auto'  — kirim RTSP apa adanya ke go2rtc (passthrough). Cocok untuk
 *             kamera yang sudah H.264. Default — tidak membebani CPU.
 *   'h264'  — kamera sudah H.264, sama seperti auto (eksplisit).
 *   'h265'  — kamera H.265: go2rtc transcode ke H.264 via ffmpeg. Makan
 *             CPU, tapi browser-compatible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nawasara_cctv_cameras', function (Blueprint $table) {
            $table->string('video_codec')->default('auto')->after('subtype');
        });
    }

    public function down(): void
    {
        Schema::table('nawasara_cctv_cameras', function (Blueprint $table) {
            $table->dropColumn('video_codec');
        });
    }
};
