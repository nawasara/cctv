<?php

namespace Nawasara\Cctv\Livewire\Live;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Nawasara\Cctv\Models\Camera;
use Nawasara\Cctv\Services\Go2rtcClient;

/**
 * Live view — sidebar daftar kamera + grid video yang user kurasi sendiri.
 *
 * User pilih kamera mana yang mau ditonton dari sidebar (maksimal
 * MAX_SELECTED sekaligus). Video di-render client-side pakai web component
 * <video-stream> dari go2rtc (lihat resources/js/cctv-go2rtc.js) — BUKAN
 * iframe stream.html, supaya tidak kena CORS dari <link rel=manifest>
 * eksternal go2rtc dan layout-nya bisa dikontrol penuh.
 *
 * Livewire di sini cuma kelola STATE (kamera mana yang dipilih) + metadata.
 * Streaming WebRTC murni di browser, tidak lewat server roundtrip.
 */
class Index extends Component
{
    /**
     * Batas kamera yang bisa ditonton bersamaan. 4 = grid 2x2 — cukup untuk
     * monitoring tanpa membanjiri CPU/bandwidth klien (tiap stream WebRTC
     * = 1 koneksi + decode).
     */
    public const MAX_SELECTED = 4;

    /**
     * Slug kamera yang sedang dipilih untuk ditonton. Disimpan di query
     * string supaya refresh / share-link mempertahankan pilihan.
     *
     * @var array<int, string>
     */
    #[Url(as: 'cam')]
    public array $selected = [];

    public function mount(): void
    {
        // Default: tampilkan kamera pertama (kalau ada) supaya halaman tidak
        // kosong saat pertama dibuka. Kalau user datang via share-link
        // dengan ?cam=..., $selected sudah terisi dari Url — jangan ditimpa.
        if (empty($this->selected)) {
            $first = Camera::query()->where('is_active', true)->orderBy('name')->first();
            if ($first) {
                $this->selected = [$first->slug];
            }
        } else {
            // Bersihkan slug yang tidak valid / kamera non-aktif dari URL.
            $this->selected = $this->validSelectedSlugs();
        }
    }

    /**
     * Semua kamera aktif — untuk sidebar.
     */
    #[Computed]
    public function cameras()
    {
        return Camera::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * Kamera yang sedang dipilih, dalam urutan $selected — untuk grid video.
     */
    #[Computed]
    public function selectedCameras()
    {
        if (empty($this->selected)) {
            return collect();
        }

        $byslug = $this->cameras->keyBy('slug');

        return collect($this->selected)
            ->map(fn ($slug) => $byslug->get($slug))
            ->filter()
            ->values();
    }

    #[Computed]
    public function sidecarReachable(): bool
    {
        return app(Go2rtcClient::class)->isReachable();
    }

    /**
     * Base URL go2rtc yang dipakai BROWSER untuk WebSocket signaling.
     * Lokal: http://localhost:1984. Server: /go2rtc (via reverse proxy).
     */
    #[Computed]
    public function go2rtcPublicUrl(): string
    {
        return rtrim((string) config('nawasara-cctv.go2rtc.public_url'), '/');
    }

    #[Computed]
    public function defaultMode(): string
    {
        return (string) config('nawasara-cctv.go2rtc.default_mode', 'webrtc');
    }

    #[Computed]
    public function atLimit(): bool
    {
        return count($this->selected) >= self::MAX_SELECTED;
    }

    /**
     * Toggle satu kamera di/dari pilihan. Klik kamera yang sudah dipilih =
     * lepas; klik yang belum = tambah (kalau belum mentok limit).
     */
    public function toggle(string $slug): void
    {
        if (in_array($slug, $this->selected, true)) {
            $this->selected = array_values(array_diff($this->selected, [$slug]));

            return;
        }

        if ($this->atLimit) {
            $this->dispatch('toast', type: 'warning',
                message: 'Maksimal '.self::MAX_SELECTED.' kamera ditonton bersamaan.');

            return;
        }

        // Pastikan slug valid sebelum masuk pilihan.
        if ($this->cameras->firstWhere('slug', $slug)) {
            $this->selected[] = $slug;
        }
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    /**
     * Slug di $selected yang benar-benar masih ada + aktif. Dipakai untuk
     * sanitasi input dari query string (?cam=...).
     *
     * @return array<int, string>
     */
    private function validSelectedSlugs(): array
    {
        $valid = Camera::query()
            ->where('is_active', true)
            ->whereIn('slug', $this->selected)
            ->pluck('slug')
            ->all();

        // Pertahankan urutan asli dari $selected, batasi ke MAX_SELECTED.
        return array_slice(
            array_values(array_filter($this->selected, fn ($s) => in_array($s, $valid, true))),
            0,
            self::MAX_SELECTED,
        );
    }

    public function render()
    {
        return view('nawasara-cctv::livewire.pages.live.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}
