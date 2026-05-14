<div>
    <x-nawasara-ui::page.container>
        <x-nawasara-ui::page.title>Live View</x-nawasara-ui::page.title>

        <x-slot name="actions">
            @if ($this->sidecarReachable)
                <x-nawasara-ui::badge color="success">go2rtc terhubung</x-nawasara-ui::badge>
            @else
                <x-nawasara-ui::badge color="danger">go2rtc tidak terhubung</x-nawasara-ui::badge>
            @endif
        </x-slot>

        @unless ($this->sidecarReachable)
            <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800
                        dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                Sidecar <span class="font-mono">go2rtc</span> tidak dapat dihubungi. Stream tidak akan tampil
                sampai container go2rtc berjalan. Cek <span class="font-mono">CCTV_GO2RTC_API_URL</span> dan
                status container.
            </div>
        @endunless

        @if ($this->cameras->isEmpty())
            <x-nawasara-ui::empty-state icon="lucide-monitor-off" title="Belum ada kamera aktif"
                description="Tambahkan kamera di menu Cameras, lalu pastikan statusnya aktif." />
        @elseif ($focused)
            {{-- Single-camera focus --}}
            @php $cam = $this->cameras->firstWhere('slug', $focused); @endphp
            @if ($cam)
                <div class="mb-3">
                    <x-nawasara-ui::button variant="ghost" color="secondary" size="sm" wire:click="unfocus">
                        <x-slot:icon><x-lucide-arrow-left class="size-4" /></x-slot:icon>
                        Kembali ke grid
                    </x-nawasara-ui::button>
                </div>
                <div class="overflow-hidden rounded-lg border border-gray-200 bg-black dark:border-gray-800">
                    {{-- aspect-video frame; iframe absolutely fills it so go2rtc's
                         player stretches edge-to-edge instead of sitting native-size
                         in the middle with letterbox + scrollbars. --}}
                    <div class="relative aspect-video w-full" wire:ignore>
                        <iframe
                            src="{{ $this->go2rtcPublicUrl }}/stream.html?src={{ urlencode($cam->slug) }}&mode={{ $this->defaultMode }}"
                            class="absolute inset-0 h-full w-full border-0"
                            scrolling="no" allow="autoplay; fullscreen"
                            referrerpolicy="no-referrer"></iframe>
                    </div>
                </div>
                <div class="mt-2 flex items-center justify-between">
                    <div>
                        <p class="font-medium text-gray-900 dark:text-gray-100">{{ $cam->name }}</p>
                        <p class="text-sm text-gray-500">{{ $cam->location ?: '—' }}</p>
                    </div>
                    @if ($cam->isOnline())
                        <x-nawasara-ui::badge color="success">online</x-nawasara-ui::badge>
                    @else
                        <x-nawasara-ui::badge color="danger">offline</x-nawasara-ui::badge>
                    @endif
                </div>
            @endif
        @else
            {{-- Grid mode — semua kamera --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->cameras as $camera)
                    <div wire:key="live-{{ $camera->id }}"
                        class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
                        {{-- relative + absolute-fill iframe: go2rtc player stretches
                             to fill the 16:9 cell, no letterbox / scrollbar. --}}
                        <div class="relative aspect-video w-full bg-black" wire:ignore>
                            <iframe
                                src="{{ $this->go2rtcPublicUrl }}/stream.html?src={{ urlencode($camera->slug) }}&mode={{ $this->defaultMode }}"
                                class="absolute inset-0 h-full w-full border-0"
                                scrolling="no" loading="lazy" allow="autoplay"
                                referrerpolicy="no-referrer"></iframe>
                        </div>
                        <div class="flex items-center justify-between px-3 py-2">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">
                                    {{ $camera->name }}
                                </p>
                                <p class="truncate text-xs text-gray-500">{{ $camera->location ?: '—' }}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                @if ($camera->isOnline())
                                    <span class="inline-block size-2 rounded-full bg-green-500"
                                        title="online"></span>
                                @else
                                    <span class="inline-block size-2 rounded-full bg-rose-500"
                                        title="offline"></span>
                                @endif
                                <x-nawasara-ui::button variant="ghost" color="secondary" size="sm"
                                    wire:click="focus('{{ $camera->slug }}')">
                                    <x-slot:icon><x-lucide-maximize-2 class="size-4" /></x-slot:icon>
                                </x-nawasara-ui::button>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-nawasara-ui::page.container>
</div>
