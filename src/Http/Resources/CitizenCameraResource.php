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
    /**
     * Angka penonton dari `additional(['viewers' => ...])`.
     *
     * Null bila tidak tersedia — pada `show()` yang hanya satu kamera, atau
     * saat go2rtc tidak terjangkau. Aplikasi menuliskannya nullable.
     */
    protected function viewerCount(Request $request): ?int
    {
        $peta = $this->additional['viewers'] ?? null;

        if (! is_array($peta)) {
            return null;
        }

        return array_key_exists($this->slug, $peta)
            ? (int) $peta[$this->slug]
            : null;
    }

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

            // ⚠️ `publicStatus`, BUKAN `health_status`.
            //
            // `health_status` hanya menjawab "kamera menjawab TCP". Yang
            // ditanyakan warga adalah "kalau saya tekan tonton, muncul
            // gambar?" — dan itu dijawab probe siaran lewat go2rtc.
            // Lihat Camera::getPublicStatusAttribute().
            //
            // 'unknown' bila belum pernah diprobe sama sekali. Aplikasi
            // menggambarnya netral, bukan sebagai mati.
            'status' => $this->publicStatus,

            // Sebab kamera tidak aktif, ditulis petugas untuk dibaca warga.
            //
            // Hanya dikirim saat kameranya memang tidak hidup: catatan lama
            // yang tertinggal pada kamera yang sudah pulih akan memberi tahu
            // warga tentang kerusakan yang sudah tidak ada.
            'offline_note' => $this->publicStatus === 'online'
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
            // Jumlah penonton yang sedang menyaksikan siaran ini.
            //
            // Diambil dari `additional(['viewers' => ...])` yang dipasang
            // controller — satu panggilan go2rtc untuk seluruh daftar, bukan
            // satu per kamera.
            //
            // ⚠️ Null bila go2rtc tidak dapat dihubungi, BUKAN nol. Nol
            // berarti "tidak ada yang menonton"; null berarti "tidak
            // diketahui", dan aplikasi menyembunyikan lencananya alih-alih
            // menyatakan sepi yang belum tentu benar.
            //
            // Yang dihitung adalah SAMBUNGAN, bukan orang — satu warga dengan
            // dua tab terhitung dua. Untuk lencana "sedang ramai" itu cukup.
            'viewers' => $this->viewerCount($request),

            'thumbnail_url' => $this->publicStatus === 'online'
                ? url("/api/v1/citizen/cctv/cameras/{$this->slug}/thumbnail")
                : null,
        ];
    }
}
