<?php

namespace Nawasara\Cctv\Console\Commands;

use Illuminate\Console\Command;
use Nawasara\Cctv\Services\CameraHealthProbe;

class ProbeCamerasCommand extends Command
{
    protected $signature = 'cctv:probe';

    protected $description = 'TCP-probe semua kamera CCTV aktif, update status online/offline';

    public function handle(CameraHealthProbe $probe): int
    {
        $result = $probe->probeAll();

        $this->info(sprintf(
            'Probe selesai: %d online, %d offline.',
            $result['online'],
            $result['offline'],
        ));

        return self::SUCCESS;
    }
}
