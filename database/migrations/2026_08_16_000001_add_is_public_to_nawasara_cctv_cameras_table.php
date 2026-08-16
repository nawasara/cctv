<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda kamera yang boleh ditonton warga lewat aplikasi.
 *
 * Sebelum ini yang ada hanya `is_active` — penanda operasional (kamera hidup
 * atau dimatikan admin), BUKAN penanda kelayakan tonton publik. Selama seluruh
 * endpoint berada di balik `api.auth` perbedaan itu tidak terasa, karena
 * tokennya hanya dipegang aplikasi tepercaya.
 *
 * Begitu endpoint warga dibuka, keduanya harus dipisah: kamera yang mengarah
 * ke area internal tetap perlu aktif untuk keperluan operasional, tetapi tidak
 * untuk ditonton siapa saja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nawasara_cctv_cameras', function (Blueprint $table) {
            // Bawaan FALSE — 12 kamera yang sudah ada tidak otomatis terbuka
            // ke publik. Admin menandai satu per satu setelah memastikan apa
            // yang terlihat di gambarnya. Bawaan TRUE akan membuka semuanya
            // pada saat migrasi dijalankan, dan itu tidak dapat ditarik lagi
            // setelah sempat tayang.
            $table->boolean('is_public')
                ->default(false)
                ->after('is_active');

            // Dipakai endpoint warga yang selalu menyaring kedua kolom.
            $table->index(['is_public', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('nawasara_cctv_cameras', function (Blueprint $table) {
            $table->dropIndex(['is_public', 'is_active']);
            $table->dropColumn('is_public');
        });
    }
};
