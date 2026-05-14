<?php

namespace Nawasara\Cctv\Console\Commands;

use Illuminate\Console\Command;
use Nawasara\Cctv\Services\DahuaClient;

class SyncTitlesCommand extends Command
{
    protected $signature = 'cctv:sync-titles';

    protected $description = 'Sinkronkan nama kamera dari ChannelTitle device Dahua (hanya kamera dengan sync_title aktif)';

    public function handle(DahuaClient $dahua): int
    {
        $result = $dahua->syncAllTitles();

        $this->info(sprintf(
            'Sync nama selesai: %d ter-update, %d di-skip (channel tanpa title), %d device tidak reachable.',
            $result['updated'],
            $result['skipped'],
            $result['unreachable'],
        ));

        // Device tidak reachable bukan kegagalan fatal — kamera lain tetap
        // ter-sync. Tapi return FAILURE supaya scheduler/CI bisa lihat ada
        // device bermasalah.
        return $result['unreachable'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
