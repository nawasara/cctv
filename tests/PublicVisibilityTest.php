<?php

namespace Nawasara\Cctv\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Kamera mana yang boleh keluar lewat API publik.
 *
 * `is_active` dan `is_public` menjawab pertanyaan BERBEDA, dan menyamakannya
 * adalah kebocoran: yang pertama berarti "kamera ini dipakai sistem", yang
 * kedua "boleh dilihat warga". Kamera dalam ruangan aktif setiap hari dan
 * tidak pernah boleh keluar.
 *
 * Terbukti 10 September 2026: kamera RESEPSIONIS, ditandai non-publik sejak
 * dibuat, tetap muncul di respons yang dibaca Gasta — peta yang dapat dibuka
 * siapa pun. CameraController menyaring `is_active` saja, sementara
 * CitizenCameraController sudah benar sejak awal.
 */
class PublicVisibilityTest extends TestCase
{
    /**
     * Salinan saringan yang dipakai CameraController pada ketiga endpoint
     * publiknya (index, show, stream).
     *
     * @param  array<int, array{name: string, is_active: bool, is_public: bool}>  $kamera
     * @return array<int, string>  nama yang lolos
     */
    private function terlihatPublik(array $kamera): array
    {
        return array_values(array_map(
            fn ($c) => $c['name'],
            array_filter($kamera, fn ($c) => $c['is_active'] && $c['is_public'])
        ));
    }

    /** Keadaan produksi saat kebocoran ditemukan. */
    private function kameraProduksi(): array
    {
        return [
            ['name' => 'D1 SIBERUT', 'is_active' => true, 'is_public' => true],
            ['name' => 'D10 MLILIR UTARA', 'is_active' => true, 'is_public' => false],
            ['name' => 'D12 (slot kosong)', 'is_active' => false, 'is_public' => false],
            ['name' => 'D13 RESEPSIONIS', 'is_active' => true, 'is_public' => false],
        ];
    }

    /**
     * Inti perkaranya: kamera dalam ruangan yang aktif tidak boleh keluar.
     */
    public function test_kamera_non_publik_tidak_pernah_keluar(): void
    {
        $terlihat = $this->terlihatPublik($this->kameraProduksi());

        $this->assertNotContains(
            'D13 RESEPSIONIS',
            $terlihat,
            'kamera non-publik bocor ke API yang dibaca peta warga',
        );
    }

    /**
     * `is_active` SAJA tidak cukup — inilah saringan yang dulu dipakai.
     */
    public function test_menyaring_is_active_saja_membocorkan(): void
    {
        $hanyaAktif = array_values(array_map(
            fn ($c) => $c['name'],
            array_filter($this->kameraProduksi(), fn ($c) => $c['is_active'])
        ));

        // Membuktikan bahwa saringan lama memang meloloskannya.
        $this->assertContains('D13 RESEPSIONIS', $hanyaAktif);

        // Dan saringan yang benar menahannya.
        $this->assertNotContains('D13 RESEPSIONIS', $this->terlihatPublik($this->kameraProduksi()));
    }

    /**
     * Kamera nonaktif tetap tertahan meski `is_public` sempat true.
     *
     * Slot kosong yang dulunya publik adalah kasus nyata: kameranya dilepas,
     * barisnya dinonaktifkan, tetapi penandanya belum dibersihkan.
     */
    public function test_kamera_nonaktif_tertahan_meski_pernah_publik(): void
    {
        $terlihat = $this->terlihatPublik([
            ['name' => 'slot kosong', 'is_active' => false, 'is_public' => true],
        ]);

        $this->assertSame([], $terlihat);
    }

    /** Yang memang untuk warga tetap lolos. */
    public function test_kamera_publik_yang_aktif_tetap_lolos(): void
    {
        $this->assertSame(
            ['D1 SIBERUT'],
            $this->terlihatPublik($this->kameraProduksi()),
        );
    }
}
