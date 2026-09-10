<?php

namespace Nawasara\Cctv\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Pembacaan daftar channel dari perangkat Dahua.
 *
 * Sebelum 10 September 2026 daftar ini ditulis tangan sebagai konstanta di
 * DahuaSampleSeeder, dan sudah basi tanpa ada yang menyadarinya: nama channel
 * 3 berbeda dari perangkat, dan pemetaan MLILIR bergeser satu channel.
 *
 * Kesalahan seperti itu tidak berisik — seeder tetap berjalan, kameranya tetap
 * dibuat, dan yang terjadi hanyalah nama yang salah menempel pada kamera yang
 * salah. Baru ketahuan saat seseorang membandingkan dengan perangkatnya.
 */
class DahuaChannelParseTest extends TestCase
{
    /** Salinan logika DahuaSampleSeeder::bacaDariPerangkat(). */
    private function parse(string $judul, string $encode): array
    {
        $polaBawaan = '/^(Channel|Saluran|CAM|IPC)\s*\d+$/i';
        $out = [];

        preg_match_all('/ChannelTitle\[(\d+)\]\.Name=(.*)/', $judul, $m, PREG_SET_ORDER);

        foreach ($m as $baris) {
            $index = (int) $baris[1];
            $nama = trim($baris[2]);

            if ($nama === '' || preg_match($polaBawaan, $nama)) {
                continue;
            }

            $out[$index + 1] = [
                'title' => $nama,
                'codec' => $this->codec($encode, $index),
            ];
        }

        ksort($out);

        return $out;
    }

    private function codec(string $encode, int $index): string
    {
        $pola = '/Encode\['.$index.'\]\.ExtraFormat\[0\]\.Video\.Compression=(\S+)/';

        if (! preg_match($pola, $encode, $m)) {
            return 'auto';
        }

        return match (strtoupper(str_replace('.', '', $m[1]))) {
            'H265', 'HEVC' => 'h265',
            'H264', 'AVC' => 'h264',
            default => 'auto',
        };
    }

    /**
     * Index perangkat mulai 0; channel RTSP mulai 1.
     *
     * ⚠️ Selisih satu inilah yang membuat daftar lama meleset: MLILIR UTARA
     * ada di index 9, yang berarti channel **10** — sementara daftar lama
     * menuliskannya di channel 11. Akibatnya URL RTSP menunjuk kamera yang
     * berbeda dari namanya.
     */
    public function test_index_perangkat_digeser_satu_menjadi_channel_rtsp(): void
    {
        $hasil = $this->parse(
            "table.ChannelTitle[0].Name=SIBERUT\n"
            ."table.ChannelTitle[9].Name=MLILIR UTARA\n",
            ''
        );

        $this->assertSame('SIBERUT', $hasil[1]['title'], 'index 0 harus menjadi channel 1');
        $this->assertSame('MLILIR UTARA', $hasil[10]['title'], 'index 9 harus menjadi channel 10');
        $this->assertArrayNotHasKey(0, $hasil, 'tidak boleh ada channel 0');
    }

    /**
     * Channel tanpa kamera terpasang tetap dikembalikan NVR dengan nama
     * bawaan. Menyeed semuanya menghasilkan kamera hantu yang tidak pernah
     * punya gambar — dan warga melihatnya sebagai kamera rusak.
     */
    public function test_nama_bawaan_perangkat_tidak_ikut_diseed(): void
    {
        $hasil = $this->parse(
            "table.ChannelTitle[0].Name=SIBERUT\n"
            ."table.ChannelTitle[12].Name=Saluran13\n"
            ."table.ChannelTitle[13].Name=Channel14\n"
            ."table.ChannelTitle[14].Name=CAM 15\n"
            ."table.ChannelTitle[15].Name=IPC16\n",
            ''
        );

        $this->assertCount(1, $hasil);
        $this->assertSame('SIBERUT', $hasil[1]['title']);
    }

    /**
     * Codec dibaca dari SUB-stream, bukan main stream.
     *
     * Grid memakai `subtype=1` (ExtraFormat[0]), dan pada perangkat yang sama
     * main stream bisa H.265 sementara sub-stream H.264. Membaca yang keliru
     * berarti menyalakan transcode yang tidak perlu — atau lebih buruk, tidak
     * menyalakannya padahal perlu, sehingga siarannya tidak dapat diputar.
     */
    public function test_codec_dibaca_dari_sub_stream(): void
    {
        $encode = "table.Encode[0].MainFormat[0].Video.Compression=H.265\n"
            ."table.Encode[0].ExtraFormat[0].Video.Compression=H.264\n";

        $hasil = $this->parse("table.ChannelTitle[0].Name=MLILIR UTARA\n", $encode);

        $this->assertSame('h264', $hasil[1]['codec']);
    }

    /** Penamaan codec perangkat beragam; semuanya harus dikenali. */
    public function test_ragam_penulisan_codec_dikenali(): void
    {
        foreach ([
            'H.265' => 'h265',
            'H265' => 'h265',
            'HEVC' => 'h265',
            'H.264' => 'h264',
            'AVC' => 'h264',
        ] as $dariPerangkat => $diharapkan) {
            $hasil = $this->parse(
                "table.ChannelTitle[0].Name=UJI\n",
                "table.Encode[0].ExtraFormat[0].Video.Compression={$dariPerangkat}\n"
            );

            $this->assertSame($diharapkan, $hasil[1]['codec'], "codec {$dariPerangkat}");
        }
    }

    /**
     * Codec yang tidak terbaca menjadi `auto`, bukan tebakan.
     *
     * Menebak salah lebih mahal daripada menyerahkannya ke go2rtc: tebakan
     * yang keliru menghasilkan transcode sia-sia atau siaran yang tak dapat
     * diputar sama sekali.
     */
    public function test_codec_tak_terbaca_menjadi_auto(): void
    {
        $hasil = $this->parse("table.ChannelTitle[0].Name=UJI\n", '');

        $this->assertSame('auto', $hasil[1]['codec']);
    }

    /**
     * Keluaran NVR produksi yang sebenarnya (dibaca 10 September 2026).
     *
     * Menjaga dua kesalahan daftar lama tetap terkunci: nama channel 3, dan
     * pemetaan MLILIR.
     */
    public function test_terhadap_keluaran_nvr_produksi(): void
    {
        $judul = '';
        foreach ([
            0 => 'SIBERUT', 1 => 'SEGITIGA NGEPOS', 2 => 'MH.THAMRIN',
            3 => 'MASJID DUWUR', 4 => 'BRI', 5 => 'DR SOETOMO',
            6 => 'TL NGEPOS UTARA', 7 => 'TL NGEPOS TIMUR',
            8 => 'PASAR LEGI TIMUR', 9 => 'MLILIR UTARA',
            10 => 'MLILIR SELATAN', 11 => 'MLILIR SELATAN',
            12 => 'Saluran13', 13 => 'Channel14', 14 => 'Channel15', 15 => 'Channel6',
        ] as $i => $nama) {
            $judul .= "table.ChannelTitle[{$i}].Name={$nama}\n";
        }

        $encode = '';
        foreach (range(0, 11) as $i) {
            $encode .= "table.Encode[{$i}].ExtraFormat[0].Video.Compression="
                .($i <= 8 ? 'H.265' : 'H.264')."\n";
        }

        $hasil = $this->parse($judul, $encode);

        $this->assertCount(12, $hasil, 'empat nama bawaan harus tersaring');

        // Yang SALAH di daftar lama.
        $this->assertSame('MH.THAMRIN', $hasil[3]['title'], 'daftar lama menulis TAMRIN');
        $this->assertSame('MLILIR UTARA', $hasil[10]['title'], 'daftar lama menaruhnya di ch11');
        $this->assertSame('MLILIR SELATAN', $hasil[11]['title'], 'daftar lama menaruhnya di ch12');

        // Kamera H.264 tidak boleh ditandai perlu transcode.
        $this->assertSame('h265', $hasil[1]['codec']);
        $this->assertSame('h264', $hasil[10]['codec']);
        $this->assertSame('h264', $hasil[11]['codec']);
    }
}
