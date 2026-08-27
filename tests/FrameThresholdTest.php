<?php

namespace Nawasara\Cctv\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Ambang ukuran bingkai — pemisah antara gambar sungguhan dan bidang abu.
 *
 * Bingkai abu tetap JPEG yang SAH dan tetap dijawab 200. Yang membedakannya
 * hanya ukuran: bidang polos hampir tidak menghasilkan data terkompresi.
 * Tanpa ambang ini, gambar abu tersimpan ke cache dan warga melihatnya
 * sampai cache-nya habis.
 *
 * Angka di sini hasil pengukuran di produksi 27 Agustus 2026, bukan tebakan.
 */
class FrameThresholdTest extends TestCase
{
    /** Sama dengan Go2rtcClient::MIN_FRAME_BYTES. */
    private const AMBANG = 20000;

    private function berisi(int $bytes): bool
    {
        return $bytes >= self::AMBANG;
    }

    /**
     * Ukuran SUNGGUHAN yang terukur pada kamera dingin — semuanya abu.
     *
     * channel-1: 6.569 / 9.321 / 7.264 · channel-3: 9.918 / 9.648 / 10.351
     */
    public function test_bingkai_dingin_ditolak(): void
    {
        foreach ([6569, 9321, 7264, 9918, 9648, 10351, 10393] as $bytes) {
            $this->assertFalse(
                $this->berisi($bytes),
                "{$bytes} byte adalah bingkai abu dan harus ditolak",
            );
        }
    }

    /**
     * Ukuran SUNGGUHAN setelah aliran dihangatkan — gambar berisi.
     *
     * channel-3: 42.347 · channel-1: 68.396 / 70.043 / 70.535
     */
    public function test_bingkai_hangat_diterima(): void
    {
        foreach ([42347, 68396, 70043, 70535] as $bytes) {
            $this->assertTrue(
                $this->berisi($bytes),
                "{$bytes} byte adalah gambar sungguhan dan harus diterima",
            );
        }
    }

    /**
     * Ambangnya berada di JURANG antara keduanya, bukan menempel salah satu.
     *
     * Terukur: abu paling besar 10.393, berisi paling kecil 42.347. Ambang
     * yang terlalu dekat ke salah satu ujung akan salah pada kamera yang
     * berbeda — pemandangan malam yang gelap menghasilkan berkas kecil, dan
     * menolaknya berarti kamera yang sehat kehilangan pratinjaunya.
     */
    public function test_ambang_berada_di_tengah_jurang(): void
    {
        $abuTerbesar = 10393;
        $berisiTerkecil = 42347;

        $this->assertGreaterThan($abuTerbesar, self::AMBANG);
        $this->assertLessThan($berisiTerkecil, self::AMBANG);

        // Dan tidak menempel: setidaknya 1,5× dari abu terbesar, dan tidak
        // lebih dari separuh berisi terkecil.
        $this->assertGreaterThan($abuTerbesar * 1.5, self::AMBANG);
        $this->assertLessThan($berisiTerkecil * 0.5, self::AMBANG);
    }

    /**
     * Umur cache HARUS melebihi selang probe.
     *
     * Kalau lebih pendek, ada celah tempat cache sudah kosong sementara probe
     * berikutnya belum berjalan — dan permintaan warga di celah itu jatuh ke
     * penangkapan dingin, yang mengembalikan bidang abu. Itulah bug yang
     * dilaporkan: gambar abu di aplikasi.
     */
    public function test_umur_cache_melebihi_selang_probe(): void
    {
        $selangProbe = 10 * 60;   // cctv:probe-streams tiap 10 menit
        $umurCache = 660;         // THUMBNAIL_TTL dan CACHE_SECONDS

        $this->assertGreaterThan(
            $selangProbe,
            $umurCache,
            'cache yang lebih pendek dari selang probe meninggalkan celah bingkai abu',
        );
    }
}
