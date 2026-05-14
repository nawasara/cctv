<?php

namespace Nawasara\Cctv\Livewire\Live;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Nawasara\Cctv\Models\Camera;
use Nawasara\Cctv\Services\Go2rtcClient;

/**
 * Live view — grid semua kamera aktif, plus single-camera focus.
 *
 * Video di-render lewat go2rtc: frontend embed <iframe>/WebRTC pointing ke
 * public_url go2rtc dengan stream id = slug kamera. Komponen Livewire ini
 * cuma menyediakan daftar kamera + metadata; pemutaran video murni client-side
 * (go2rtc WebRTC), tidak lewat Livewire.
 */
class Index extends Component
{
    /** Slug kamera yang sedang difokuskan (single view); null = grid mode. */
    public ?string $focused = null;

    #[Computed]
    public function cameras()
    {
        return Camera::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function sidecarReachable(): bool
    {
        return app(Go2rtcClient::class)->isReachable();
    }

    /**
     * Base URL go2rtc untuk dipakai browser. Stream embed:
     *   {publicUrl}/webrtc.html?src={slug}  (atau stream.html untuk MSE/HLS)
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

    public function focus(string $slug): void
    {
        $this->focused = $slug;
    }

    public function unfocus(): void
    {
        $this->focused = null;
    }

    public function render()
    {
        return view('nawasara-cctv::livewire.pages.live.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}
