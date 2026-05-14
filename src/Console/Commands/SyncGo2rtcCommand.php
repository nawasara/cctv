<?php

namespace Nawasara\Cctv\Console\Commands;

use Illuminate\Console\Command;
use Nawasara\Cctv\Services\Go2rtcClient;

class SyncGo2rtcCommand extends Command
{
    protected $signature = 'cctv:sync-go2rtc';

    protected $description = 'Daftarkan ulang semua kamera aktif ke sidecar go2rtc (dipakai setelah sidecar restart)';

    public function handle(Go2rtcClient $client): int
    {
        if (! $client->isReachable()) {
            $this->error('Sidecar go2rtc tidak reachable. Cek container go2rtc + CCTV_GO2RTC_API_URL.');

            return self::FAILURE;
        }

        $result = $client->syncAllCameras();

        $this->info(sprintf(
            'Sync go2rtc selesai: %d ter-sync, %d gagal.',
            $result['synced'],
            $result['failed'],
        ));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
