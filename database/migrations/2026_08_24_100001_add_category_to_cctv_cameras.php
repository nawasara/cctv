<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kategori kamera — dipakai saringan di layar CCTV aplikasi warga.
 *
 * Kategorinya sudah ditetapkan mockup `Cctv.jsx`: Pusat Kota, Lalu Lintas,
 * Wisata, Pasar, Siaga Bencana.
 *
 * Nullable dengan sengaja: kamera yang sudah ada tidak berkategori sampai
 * seseorang mengisinya, dan memaksa nilai bawaan berarti seluruh kamera
 * lama tampil di kategori yang belum tentu benar. Aplikasi menampilkan yang
 * tak berkategori pada saringan "Semua".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nawasara_cctv_cameras', function (Blueprint $table) {
            $table->string('category', 50)->nullable()->after('location');

            // Aplikasi menyaring kategori pada kamera publik yang aktif.
            $table->index(['category', 'is_public']);
        });
    }

    public function down(): void
    {
        Schema::table('nawasara_cctv_cameras', function (Blueprint $table) {
            $table->dropIndex(['category', 'is_public']);
            $table->dropColumn('category');
        });
    }
};
