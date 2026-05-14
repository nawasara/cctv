<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah kolom sync_title ke tabel cameras.
 *
 * Command cctv:sync-titles ambil nama channel dari Dahua HTTP API dan
 * timpa kolom name + location. Tapi operator mungkin mau nama custom di
 * Nawasara (mis. lebih deskriptif dari label device). sync_title = false
 * membuat kamera itu di-skip saat sync — namanya jadi milik Nawasara.
 *
 * Default true: kamera baru ikut sync otomatis (nama dari device biasanya
 * sudah cukup informatif).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nawasara_cctv_cameras', function (Blueprint $table) {
            $table->boolean('sync_title')->default(true)->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('nawasara_cctv_cameras', function (Blueprint $table) {
            $table->dropColumn('sync_title');
        });
    }
};
