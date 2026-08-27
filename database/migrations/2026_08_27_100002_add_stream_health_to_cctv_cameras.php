<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kesehatan SIARAN — terpisah dari kesehatan KAMERA.
 *
 * ⚠️ Keduanya berbeda, dan perbedaannya yang menipu warga.
 *
 * `health_status` diisi TCP connect ke port RTSP kamera. Ia menjawab "kamera
 * dan jaringannya hidup" — dan itu saja. Kamera dapat menjawab TCP dengan
 * sempurna sementara siarannya tidak dapat ditonton: kredensial berubah,
 * go2rtc belum mendaftarkan stream-nya, codec-nya HEVC yang tidak dapat
 * diputar, atau kamera membalas tetapi tidak mengirim bingkai.
 *
 * Warga tidak berurusan dengan port RTSP. Yang mereka lakukan adalah menekan
 * tombol tonton — dan yang menentukan berhasil-tidaknya adalah **go2rtc**,
 * bukan kamera. Menampilkan "Aktif" berdasarkan TCP saja berarti menjanjikan
 * sesuatu yang belum tentu ada.
 *
 * Kolom ini menyimpan hasil probe yang menempuh jalur yang SAMA dengan warga:
 * meminta satu bingkai dari go2rtc. Bila bingkainya datang, siarannya
 * sungguh dapat ditonton.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nawasara_cctv_cameras', function (Blueprint $table) {
            /**
             * online   — bingkai berhasil diambil dari go2rtc
             * offline  — go2rtc tidak dapat memberi bingkai
             * unknown  — belum pernah diprobe
             *
             * ⚠️ `unknown` BUKAN berarti mati. Aplikasi menggambarnya netral;
             * memperlakukannya sebagai offline akan menyembunyikan kamera yang
             * baru ditambahkan sebelum probe pertama berjalan.
             */
            $table->string('stream_status', 20)->default('unknown')->after('health_status');

            $table->unsignedSmallInteger('stream_failure_count')->default(0)->after('stream_status');
            $table->timestamp('stream_probed_at')->nullable()->after('stream_failure_count');

            /**
             * Sebab kegagalan terakhir, untuk petugas — BUKAN untuk warga.
             *
             * Warga membaca `offline_note` yang ditulis manusia. Yang ini
             * pesan teknis ("go2rtc menjawab 200 dengan badan kosong"), dan
             * menampilkannya ke warga hanya membingungkan.
             */
            $table->string('stream_error', 255)->nullable()->after('stream_probed_at');

            $table->index('stream_status');
        });
    }

    public function down(): void
    {
        Schema::table('nawasara_cctv_cameras', function (Blueprint $table) {
            $table->dropIndex(['stream_status']);
            $table->dropColumn([
                'stream_status',
                'stream_failure_count',
                'stream_probed_at',
                'stream_error',
            ]);
        });
    }
};
