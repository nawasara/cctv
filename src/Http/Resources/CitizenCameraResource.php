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
        ];
    }
}
