<?php

namespace Nawasara\Cctv\Console\Commands;

use Illuminate\Console\Command;
use Nawasara\Cctv\Models\Camera;
use Nawasara\Cctv\Services\StreamHealthProbe;

class ProbeStreamsCommand extends Command
{
    protected $signature = 'cctv:probe-streams';

    protected $description = 'Ambil satu bingkai tiap kamera lewat go2rtc — memastikan siarannya benar-benar dapat ditonton warga';

    public function handle(StreamHealthProbe $probe): int
    {
        $hasil = $probe->probeAll();

        $this->info(sprintf(
            'Siaran: %d dapat ditonton, %d tidak.',
            $hasil['online'],
            $hasil['offline'],
        ));

        // Angka yang paling perlu dilihat petugas: kamera yang TCP-nya hidup
        // tetapi siarannya tidak dapat ditonton. Selama ini warga melihatnya
        // sebagai "Aktif", menekan tonton, dan mendapat layar hitam.
        if ($hasil['menipu'] > 0) {
            $this->warn(sprintf(
                '  %d kamera tampak hidup menurut TCP tetapi siarannya GAGAL:',
                $hasil['menipu'],
            ));

            Camera::query()
                ->where('is_active', true)
                ->where('health_status', 'online')
                ->where('stream_status', 'offline')
                ->get()
                ->each(fn (Camera $c) => $this->line(
                    sprintf('    %-16s %s', $c->slug, $c->stream_error ?? '-'),
                ));
        }

        return self::SUCCESS;
    }
}
