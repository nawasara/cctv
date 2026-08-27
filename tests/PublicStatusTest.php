<?php

namespace Nawasara\Cctv\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Status yang ditampilkan ke warga.
 *
 * Salah di sini berarti **menipu warga**: lencana "Aktif" pada kamera yang
 * siarannya mati, lalu layar hitam saat ditekan. Itu keluhan yang paling
 * merusak kepercayaan, karena aplikasi terlihat berbohong.
 */
class PublicStatusTest extends TestCase
{
    /** Salinan logika Camera::getPublicStatusAttribute(). */
    private function publicStatus(?string $stream, ?string $health): string
    {
        if (in_array($stream, ['online', 'offline'], true)) {
            return $stream;
        }

        return $health ?: 'unknown';
    }

    /**
     * Inti perkaranya: kamera menjawab TCP, siarannya mati.
     *
     * Inilah keadaan yang selama ini ditampilkan "Aktif" — kredensial
     * berubah, stream belum terdaftar di go2rtc, atau codec HEVC.
     */
    public function test_kamera_hidup_tapi_siaran_mati_ditampilkan_MATI(): void
    {
        $this->assertSame(
            'offline',
            $this->publicStatus('offline', 'online'),
            'siaran yang mati tidak boleh tampil sebagai Aktif',
        );
    }

    /**
     * Kebalikannya juga: siaran hidup meski TCP kamera gagal.
     *
     * go2rtc dapat menyimpan sambungan yang sudah terbangun, atau kamera
     * menolak koneksi baru sementara aliran lama masih jalan. Menandainya
     * mati menyembunyikan siaran yang sebenarnya dapat ditonton.
     */
    public function test_siaran_hidup_menang_atas_tcp_yang_gagal(): void
    {
        $this->assertSame('online', $this->publicStatus('online', 'offline'));
    }

    /**
     * Sebelum probe siaran pertama berjalan, status kamera dipakai.
     *
     * Tanpa cadangan ini, SELURUH kamera akan tampil `unknown` sejak rilis
     * sampai penjadwal berjalan — dan layar penuh lencana abu terbaca sebagai
     * sistem yang rusak.
     */
    public function test_sebelum_diprobe_jatuh_ke_status_kamera(): void
    {
        $this->assertSame('online', $this->publicStatus('unknown', 'online'));
        $this->assertSame('offline', $this->publicStatus('unknown', 'offline'));
        $this->assertSame('unknown', $this->publicStatus(null, null));
    }

    /** Keduanya sepakat — kasus yang paling sering. */
    public function test_keduanya_sepakat(): void
    {
        $this->assertSame('online', $this->publicStatus('online', 'online'));
        $this->assertSame('offline', $this->publicStatus('offline', 'offline'));
    }

    /**
     * Nilai tak dikenal tidak boleh lolos sebagai status.
     *
     * Bila kelak ada yang menulis nilai lain ke kolomnya, ia jatuh ke
     * cadangan, bukan diteruskan mentah ke aplikasi yang tidak mengenalinya.
     */
    public function test_nilai_tak_dikenal_jatuh_ke_cadangan(): void
    {
        $this->assertSame('online', $this->publicStatus('degraded', 'online'));
        $this->assertSame('unknown', $this->publicStatus('degraded', null));
    }
}
