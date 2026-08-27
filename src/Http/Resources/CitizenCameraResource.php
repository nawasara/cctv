<?php

namespace Nawasara\Cctv\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Nawasara\Cctv\Models\Camera;

/**
 * Kamera sebagaimana dilihat warga.
 *
 * DAFTAR-IZIN: hanya kolom yang disebut di sini yang keluar. Ditulis begini
 * supaya kolom baru pada tabel kamera tidak ikut terkirim secara bawaan —
 * dengan daftar-larang, setiap penambahan kolom di masa depan otomatis bocor
 * sampai ada yang ingat membuangnya.
 *
 * Lebih sempit daripada {@see CameraResource}: `channel` dan `video_codec`
 * dihilangkan karena keduanya keterangan operasional perangkat, tidak dipakai
 * pemutar di aplikasi warga, dan menyingkap sedikit hal tentang susunan
 * perangkat yang tidak perlu diketahui publik.
 *
 * @mixin Camera
 */
class CitizenCameraResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // Slug, bukan id — dipakai di URL dan aman ditebak orang.
            'slug' => $this->slug,
            'name' => $this->name,
            'location' => $this->location,

            // Null bila belum dikategorikan — aplikasi menampilkannya pada
            // saringan "Semua". Memaksa nilai bawaan akan menaruh kamera lama
            // di kategori yang belum tentu benar.
            'category' => $this->category,

            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,

            // 'unknown' bila probe belum pernah berjalan. Aplikasi menampilkan
            // lencana status, dan nilai tak dikenal harus jatuh ke tampilan
            // netral — bukan dianggap mati.
            'status' => $this->health_status ?: 'unknown',

            // Sebab kamera tidak aktif, ditulis petugas untuk dibaca warga.
            //
            // Hanya dikirim saat kameranya memang tidak hidup: catatan lama
            // yang tertinggal pada kamera yang sudah pulih akan memberi tahu
            // warga tentang kerusakan yang sudah tidak ada.
            'offline_note' => $this->health_status === 'online'
                ? null
                : ($this->offline_note ?: null),

            // Pratinjau kamera. NULL bila kameranya sedang tidak hidup —
            // menangkap bingkai dari kamera mati hanya menghabiskan waktu
            // tunggu, dan aplikasi sudah tahu menggambar keadaan itu dari
            // `status` di atas.
            //
            // Dikirim di DAFTAR, bukan hanya sebagai endpoint terpisah:
            // aplikasi menggambar kisi thumbnail dan tidak perlu menyusun
            // alamatnya sendiri — host-nya dapat berbeda dari host API.
            'thumbnail_url' => $this->health_status === 'online'
                ? url("/api/v1/citizen/cctv/cameras/{$this->slug}/thumbnail")
                : null,
        ];
    }
}
