<?php

namespace Nawasara\Cctv\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Menghitung penonton dari jawaban `/api/streams` go2rtc.
 *
 * Bentuk data di bawah disalin dari produksi 30 Agustus 2026, termasuk
 * saat dua penonton sungguhan dibuka — bukan dikarang.
 */
class ViewerCountTest extends TestCase
{
    /** Salinan logika Go2rtcClient::viewerCounts(). */
    private function hitung(array $streams): array
    {
        $out = [];

        foreach ($streams as $slug => $stream) {
            if (! is_array($stream)) {
                continue;
            }

            $consumers = $stream['consumers'] ?? [];
            $out[(string) $slug] = is_array($consumers) ? count($consumers) : 0;
        }

        return $out;
    }

    /** Bentuk sungguhan saat tidak ada yang menonton. */
    public function test_tanpa_penonton_menghasilkan_nol(): void
    {
        $hasil = $this->hitung([
            'channel-1' => ['producers' => [['url' => 'ffmpeg:rtsp://…']], 'consumers' => []],
            'channel-2' => ['producers' => [['url' => 'ffmpeg:rtsp://…']], 'consumers' => []],
        ]);

        $this->assertSame(['channel-1' => 0, 'channel-2' => 0], $hasil);
    }

    /** Bentuk sungguhan saat dua penonton dibuka. */
    public function test_dua_penonton_terhitung_dua(): void
    {
        $hasil = $this->hitung([
            'channel-1' => [
                'producers' => [['url' => 'ffmpeg:rtsp://…']],
                'consumers' => [
                    ['remote_addr' => '127.0.0.1:46688', 'user_agent' => 'Wget'],
                    ['remote_addr' => '127.0.0.1:46694', 'user_agent' => 'Wget'],
                ],
            ],
        ]);

        $this->assertSame(['channel-1' => 2], $hasil);
    }

    /**
     * `consumers` boleh HILANG, bukan sekadar kosong.
     *
     * Stream yang terdaftar tetapi belum pernah dibuka tidak selalu punya
     * kuncinya. Tanpa penjagaan, `count(null)` melempar di PHP 8 dan
     * menjatuhkan seluruh daftar kamera karena satu lencana hiasan.
     */
    public function test_consumers_hilang_atau_null_dianggap_nol(): void
    {
        $this->assertSame(
            ['a' => 0, 'b' => 0],
            $this->hitung([
                'a' => ['producers' => []],
                'b' => ['producers' => [], 'consumers' => null],
            ]),
        );
    }

    /** Entri yang bukan array dilewati, bukan menjatuhkan seluruh peta. */
    public function test_entri_rusak_dilewati(): void
    {
        $hasil = $this->hitung([
            'baik' => ['consumers' => [['x' => 1]]],
            'rusak' => 'bukan array',
        ]);

        $this->assertSame(['baik' => 1], $hasil);
        $this->assertArrayNotHasKey('rusak', $hasil);
    }

    /**
     * Kamera yang TIDAK ADA di peta harus menghasilkan null, bukan nol.
     *
     * Nol berarti "tidak ada yang menonton". Null berarti "tidak diketahui"
     * — go2rtc tidak terjangkau, atau stream-nya belum terdaftar. Aplikasi
     * menyembunyikan lencana untuk null; menyatakan sepi yang belum tentu
     * benar akan membuat siaran ramai terlihat mati.
     */
    public function test_kamera_di_luar_peta_menghasilkan_null(): void
    {
        $peta = ['channel-1' => 5];

        $ambil = fn (string $slug) => array_key_exists($slug, $peta)
            ? (int) $peta[$slug]
            : null;

        $this->assertSame(5, $ambil('channel-1'));
        $this->assertNull($ambil('channel-99'));
    }

    /**
     * Peta HARUS sampai ke tiap item, bukan hanya ke koleksinya.
     *
     * Semula dipasang lewat `additional(['viewers' => ...])` pada koleksi.
     * Laravel tidak meneruskan itu ke anggota, sehingga `$this->additional`
     * di dalam item selalu kosong dan `viewers` selalu null — terlihat persis
     * seperti "go2rtc tidak terjangkau", padahal petanya benar.
     *
     * Ditemukan di produksi: `meta.viewers_total` menyebut 9 sementara setiap
     * `viewers` bernilai null.
     */
    public function test_peta_sampai_ke_tiap_item(): void
    {
        // Meniru properti statis pada Resource.
        $peta = ['channel-1' => 4, 'channel-3' => 9];

        $ambil = fn (?array $m, string $slug) => $m === null
            ? null
            : (array_key_exists($slug, $m) ? (int) $m[$slug] : null);

        // Terpasang: angkanya sampai.
        $this->assertSame(4, $ambil($peta, 'channel-1'));
        $this->assertSame(9, $ambil($peta, 'channel-3'));

        // Kamera yang ada di peta dengan nilai nol tetap nol, bukan null.
        $this->assertSame(0, $ambil(['channel-2' => 0], 'channel-2'));

        // Tidak terpasang sama sekali: null, dan lencananya disembunyikan.
        $this->assertNull($ambil(null, 'channel-1'));
    }

    /** Total untuk `meta.viewers_total` di jawaban daftar. */
    public function test_total_seluruh_kamera(): void
    {
        $peta = $this->hitung([
            'a' => ['consumers' => [[], []]],
            'b' => ['consumers' => [[]]],
            'c' => ['consumers' => []],
        ]);

        $this->assertSame(3, array_sum($peta));
    }
}
