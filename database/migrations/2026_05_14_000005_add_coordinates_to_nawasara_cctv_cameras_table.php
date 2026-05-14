<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah koordinat (latitude/longitude) ke tabel cameras.
 *
 * Tujuan: plot kamera di peta — gabung dengan titik WiFi (nawasara/wifi)
 * di satu map view nanti.
 *
 * decimal(10,7): presisi 7 desimal ~= 1.1 cm di ekuator, jauh lebih dari
 * cukup untuk titik kamera. range:
 *   latitude  -90..90    -> decimal(10,7) muat (3 digit + 7 desimal)
 *   longitude -180..180  -> decimal(10,7) muat juga
 * nullable: kamera lama belum punya koordinat, diisi manual via form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nawasara_cctv_cameras', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('location');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('nawasara_cctv_cameras', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
