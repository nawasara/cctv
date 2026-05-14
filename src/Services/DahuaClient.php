<?php

namespace Nawasara\Cctv\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Nawasara\Cctv\Models\Camera;

/**
 * Thin client untuk Dahua HTTP CGI API.
 *
 * Dipakai untuk hal-hal yang TIDAK tersedia lewat RTSP — saat ini cuma
 * baca ChannelTitle (nama kamera yang diset di device). RTSP cuma kasih
 * video; metadata seperti nama channel ada di config CGI.
 *
 * Dahua CGI pakai HTTP Digest auth. Endpoint:
 *   /cgi-bin/configManager.cgi?action=getConfig&name=ChannelTitle
 *
 * Catatan: kredensial sama dengan RTSP (username/password kamera). Tidak
 * pernah di-log — semua log di sini cuma sebut IP / channel.
 */
class DahuaClient
{
    public function __construct(
        private readonly int $timeout = 12,
    ) {}

    /**
     * Ambil nama (title) semua channel dari satu device Dahua.
     *
     * Beberapa kamera bisa berbagi 1 device fisik (1 IP, banyak channel),
     * jadi ini di-key by IP — satu panggilan ambil semua channel sekaligus.
     *
     * @return array<int, string>  map: channel (1-based) => nama, kosong
     *                             kalau device tidak reachable / auth gagal.
     */
    public function channelTitles(string $ip, int $httpPort, string $username, string $password): array
    {
        $url = sprintf(
            'http://%s:%d/cgi-bin/configManager.cgi?action=getConfig&name=ChannelTitle',
            $ip,
            $httpPort,
        );

        try {
            // Dahua CGI butuh HTTP Digest auth, bukan Basic.
            $response = Http::withDigestAuth($username, $password)
                ->timeout($this->timeout)
                ->get($url);

            if ($response->failed()) {
                Log::warning('Dahua channelTitles failed', [
                    'ip' => $ip,
                    'status' => $response->status(),
                ]);

                return [];
            }

            return $this->parseChannelTitles($response->body());
        } catch (\Throwable $e) {
            Log::warning('Dahua channelTitles error', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Parse response CGI ChannelTitle.
     *
     * Format response (plain text, 1 baris per properti):
     *   table.ChannelTitle[0].Name=SIBERUT
     *   table.ChannelTitle[1].Name=SEGITIGA NGEPOS
     *
     * Index Dahua 0-based; kita kembalikan 1-based supaya cocok dengan
     * kolom `channel` di tabel cameras.
     *
     * @return array<int, string>
     */
    private function parseChannelTitles(string $body): array
    {
        $titles = [];

        foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
            // table.ChannelTitle[<idx>].Name=<title>
            if (preg_match('/ChannelTitle\[(\d+)\]\.Name=(.*)$/', trim($line), $m)) {
                $channel = (int) $m[1] + 1; // 0-based -> 1-based
                $title = trim($m[2]);

                if ($title !== '') {
                    $titles[$channel] = $title;
                }
            }
        }

        return $titles;
    }

    /**
     * Sinkronkan nama semua kamera dari device-nya masing-masing.
     *
     * Hanya kamera dengan sync_title = true yang di-update — operator bisa
     * opt-out per kamera kalau mau nama custom di Nawasara.
     *
     * Kamera di-group by (ip, http_port, username, password) supaya 1 device
     * dengan banyak channel cuma di-hit sekali.
     *
     * @return array{updated:int, skipped:int, unreachable:int}
     */
    public function syncAllTitles(): array
    {
        $updated = 0;
        $skipped = 0;
        $unreachable = 0;

        // Group kamera per device fisik. Kredensial encrypted, jadi
        // di-resolve setelah ambil model (tidak bisa group by SQL).
        $byDevice = Camera::query()
            ->where('sync_title', true)
            ->get()
            ->groupBy(fn (Camera $c) => $c->ip_address.':'.$c->http_port);

        foreach ($byDevice as $cameras) {
            /** @var Camera $first */
            $first = $cameras->first();

            $titles = $this->channelTitles(
                $first->ip_address,
                $first->http_port,
                (string) $first->username,
                (string) $first->password,
            );

            if (empty($titles)) {
                $unreachable += $cameras->count();

                continue;
            }

            foreach ($cameras as $camera) {
                $title = $titles[$camera->channel] ?? null;

                if ($title === null) {
                    $skipped++;

                    continue;
                }

                // Format "D{channel} {title}" mengikuti penamaan device.
                $camera->name = "D{$camera->channel} {$title}";
                $camera->location = $title;
                $camera->save();
                $updated++;
            }
        }

        return [
            'updated' => $updated,
            'skipped' => $skipped,
            'unreachable' => $unreachable,
        ];
    }
}
