<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sebab kamera sedang tidak aktif — ditulis petugas, dibaca warga.
 *
 * Sebelum ini aplikasi hanya dapat menyebut "Sedang tidak aktif" tanpa sebab,
 * dan warga yang melihatnya berhari-hari akan melaporkan kamera yang SUDAH
 * diketahui rusak — berulang kali, lewat Lapor Bunda, ke petugas yang sama
 * yang sedang memperbaikinya.
 *
 * Satu kalimat ("Perbaikan jaringan, perkiraan selesai Jumat") menghentikan
 * itu. Mockup `Cctv.jsx` sudah menyediakan tempatnya sejak awal.
 *
 * Nullable, dan memang seharusnya kosong hampir sepanjang waktu: kamera yang
 * sehat tidak punya sebab untuk dijelaskan. Aplikasi hanya menampilkannya
 * ketika `status` bukan `online`.
 *
 * ⚠️ **Isinya dibaca warga.** Ini bukan kolom catatan operasional — jangan
 * menuliskan alamat IP, nama vendor, nomor tiket, atau apa pun yang tidak
 * ditujukan untuk umum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nawasara_cctv_cameras', function (Blueprint $table) {
            // Panjangnya dibatasi dengan sengaja. Kolom ini digambar sebagai
            // satu baris kecil di bawah nama kamera; paragraf tidak akan muat,
            // dan yang tidak muat akan terpotong di tengah kalimat.
            $table->string('offline_note', 160)
                ->nullable()
                ->after('health_status');
        });
    }

    public function down(): void
    {
        Schema::table('nawasara_cctv_cameras', function (Blueprint $table) {
            $table->dropColumn('offline_note');
        });
    }
};
