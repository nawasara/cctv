<?php

namespace Nawasara\Cctv\Tests;

use Nawasara\Cctv\Http\Api\StreamProxyController as C;
use PHPUnit\Framework\TestCase;

/**
 * Siapa yang boleh memutar URL stream bertanda tangan.
 *
 * URL untuk Gasta (aud=web) bisa diminta siapa pun lewat Gasta, jadi yang
 * membuatnya tak berguna di luar Gasta adalah Origin browser penonton.
 */
class StreamAudienceTest extends TestCase
{
    private const WEB = ['https://gasta.ponorogo.go.id'];

    public function test_url_gasta_dari_halaman_gasta_diizinkan(): void
    {
        $this->assertTrue(C::audienceAllows('web', '', 'https://gasta.ponorogo.go.id', self::WEB));
        $this->assertTrue(C::audienceAllows('web', '', 'https://GASTA.ponorogo.go.id/', self::WEB));
    }

    public function test_url_gasta_dari_situs_lain_ditolak(): void
    {
        $this->assertFalse(C::audienceAllows('web', '', 'https://sipedasview.vercel.app', self::WEB));
    }

    /** Inti langkah ini: skrip/relai tanpa Origin tidak lagi lolos untuk URL Gasta. */
    public function test_url_gasta_tanpa_origin_ditolak(): void
    {
        $this->assertFalse(C::audienceAllows('web', '', null, self::WEB));
        $this->assertFalse(C::audienceAllows('web', '', '', self::WEB));
    }

    public function test_url_aplikasi_warga_tanpa_origin_diizinkan(): void
    {
        $this->assertTrue(C::audienceAllows('', 'mp4', null, self::WEB));
        $this->assertTrue(C::audienceAllows('', 'mse', null, self::WEB));
    }

    public function test_format_lama_tanpa_audiens_ditolak(): void
    {
        $this->assertFalse(C::audienceAllows('', '', null, self::WEB));
        $this->assertFalse(C::audienceAllows('', '', 'https://gasta.ponorogo.go.id', self::WEB));
    }

    public function test_audiens_tak_dikenal_ditolak(): void
    {
        $this->assertFalse(C::audienceAllows('app', 'mp4', null, self::WEB));
    }
}
