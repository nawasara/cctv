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
        @else
            {{--
                Layout: sidebar daftar kamera (kiri) + grid video terpilih (kanan).
                User kurasi sendiri kamera mana yang ditonton, maksimal 4.

                Web component <video-stream> di-load dari go2rtc via dynamic
                import() — BUKAN iframe stream.html — supaya:
                  1. Tidak kena CORS dari <link rel=manifest> eksternal go2rtc.
                  2. Layout dikontrol penuh (no iframe scrollbar / letterbox).
                  3. Semua video satu DOM — sidebar + grid gampang.
            --}}
            <div x-data="cctvLive('{{ $this->go2rtcPublicUrl }}')"
                class="flex flex-col gap-4 lg:flex-row">

                {{-- ============ SIDEBAR — daftar kamera ============ --}}
                <aside class="w-full shrink-0 lg:w-72">
                    <div class="rounded-lg border border-gray-200 dark:border-gray-800">
                        <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3
                                    dark:border-gray-800">
                            <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">
                                Daftar CCTV
                            </h2>
                            <span class="text-xs text-gray-500">
                                {{ count($selected) }}/{{ \Nawasara\Cctv\Livewire\Live\Index::MAX_SELECTED }}
                            </span>
                        </div>

                        <ul class="max-h-[28rem] overflow-y-auto p-2">
                            @foreach ($this->cameras as $camera)
                                @php $isSelected = in_array($camera->slug, $selected, true); @endphp
                                <li wire:key="cam-li-{{ $camera->id }}">
                                    <button type="button"
                                        wire:click="toggle('{{ $camera->slug }}')"
                                        @class([
                                            'flex w-full items-center gap-3 rounded-md px-3 py-2 text-left text-sm transition',
                                            'bg-emerald-50 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' => $isSelected,
                                            'text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-900' => ! $isSelected,
                                        ])>
                                        {{-- indikator terpilih --}}
                                        <span @class([
                                            'flex size-4 shrink-0 items-center justify-center rounded border',
                                            'border-emerald-600 bg-emerald-600 text-white' => $isSelected,
                                            'border-gray-300 dark:border-gray-700' => ! $isSelected,
                                        ])>
                                            @if ($isSelected)
                                                <x-lucide-check class="size-3" />
                                            @endif
                                        </span>

                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate font-medium">{{ $camera->name }}</span>
                                            <span class="block truncate text-xs text-gray-500">
                                                {{ $camera->location ?: '—' }}
                                            </span>
                                        </span>

                                        {{-- status online/offline --}}
                                        @if ($camera->isOnline())
                                            <span class="size-2 shrink-0 rounded-full bg-green-500"
                                                title="online"></span>
                                        @else
                                            <span class="size-2 shrink-0 rounded-full bg-rose-500"
                                                title="offline"></span>
                                        @endif
                                    </button>
                                </li>
                            @endforeach
                        </ul>

                        @if (count($selected) > 0)
                            <div class="border-t border-gray-200 p-2 dark:border-gray-800">
                                <button type="button" wire:click="clearSelection"
                                    class="w-full rounded-md px-3 py-1.5 text-xs text-gray-500
                                           hover:bg-gray-50 hover:text-gray-700
                                           dark:hover:bg-gray-900 dark:hover:text-gray-300">
                                    Kosongkan pilihan
                                </button>
                            </div>
                        @endif
                    </div>

                    <p class="mt-2 px-1 text-xs text-gray-400">
                        Klik kamera untuk menonton. Maksimal
                        {{ \Nawasara\Cctv\Livewire\Live\Index::MAX_SELECTED }} kamera bersamaan.
                    </p>
                </aside>

                {{-- ============ GRID VIDEO — kamera terpilih ============ --}}
                <div class="min-w-0 flex-1">
                    @if ($this->selectedCameras->isEmpty())
                        <div class="flex h-64 items-center justify-center rounded-lg border border-dashed
                                    border-gray-300 text-sm text-gray-400 dark:border-gray-700">
                            Pilih kamera dari daftar untuk mulai menonton.
                        </div>
                    @else
                        {{-- 1 kamera = full width; 2+ = grid 2 kolom (maks 2x2) --}}
                        <div @class([
                            'grid gap-3',
                            'grid-cols-1' => $this->selectedCameras->count() === 1,
                            'grid-cols-1 sm:grid-cols-2' => $this->selectedCameras->count() > 1,
                        ])>
                            @foreach ($this->selectedCameras as $camera)
                                <div wire:key="live-{{ $camera->id }}"
                                    class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
                                    {{-- video-stream web component di-mount oleh Alpine.
                                         wire:ignore — jangan biarkan Livewire patch ulang
                                         DOM video (akan putus koneksi WebRTC tiap render). --}}
                                    <div class="relative aspect-video w-full bg-black"
                                        wire:ignore
                                        x-data="{ slug: '{{ $camera->slug }}' }"
                                        x-init="mountStream($el, slug)">
                                        {{-- <video-stream> disuntik di sini oleh mountStream() --}}
                                    </div>
                                    <div class="flex items-center justify-between px-3 py-2">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">
                                                {{ $camera->name }}
                                            </p>
                                            <p class="truncate text-xs text-gray-500">
                                                {{ $camera->location ?: '—' }}
                                            </p>
                                        </div>
                                        <button type="button" wire:click="toggle('{{ $camera->slug }}')"
                                            class="shrink-0 rounded-md p-1.5 text-gray-400 transition
                                                   hover:bg-rose-50 hover:text-rose-600
                                                   dark:hover:bg-rose-950"
                                            title="Tutup kamera ini">
                                            <x-lucide-x class="size-4" />
                                        </button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            {{--
                cctvLive() — Alpine component. mountStream() dynamic-import
                video-rtc.js + video-stream.js dari go2rtc, lalu buat elemen
                <video-stream> dan arahkan ke WebSocket signaling go2rtc.
                Dynamic import dibungkus once-guard supaya tidak re-fetch
                modul tiap kamera.
            --}}
            @script
            <script>
                Alpine.data('cctvLive', (go2rtcBase) => ({
                    // base URL go2rtc untuk browser; pastikan tanpa trailing slash
                    base: go2rtcBase.replace(/\/$/, ''),

                    // Promise modul go2rtc — di-load sekali, di-share semua stream.
                    _modulePromise: null,

                    loadModule() {
                        if (!this._modulePromise) {
                            // video-stream.js sendiri yang import video-rtc.js
                            // (relatif). Sekali load, custom element <video-stream>
                            // ter-register global.
                            this._modulePromise = import(`${this.base}/video-stream.js`)
                                .catch(err => {
                                    console.error('[cctv] gagal load go2rtc video-stream.js', err);
                                    this._modulePromise = null; // boleh retry
                                    throw err;
                                });
                        }
                        return this._modulePromise;
                    },

                    async mountStream(container, slug) {
                        try {
                            await this.loadModule();
                        } catch {
                            container.innerHTML =
                                '<div class="flex h-full w-full items-center justify-center ' +
                                'text-xs text-rose-400">Gagal memuat player go2rtc</div>';
                            return;
                        }

                        // Hindari double-mount kalau Alpine re-init elemen.
                        if (container.querySelector('video-stream')) return;

                        const el = document.createElement('video-stream');
                        // background=true: tampilkan frame terakhir saat buffering.
                        el.background = true;
                        el.mode = '{{ $this->defaultMode }}';
                        // WebSocket signaling endpoint go2rtc untuk stream ini.
                        el.src = new URL(
                            `api/ws?src=${encodeURIComponent(slug)}`,
                            this.base + '/'
                        );
                        // isi penuh container aspect-video
                        el.style.position = 'absolute';
                        el.style.inset = '0';
                        el.style.width = '100%';
                        el.style.height = '100%';
                        container.appendChild(el);
                    },
                }));
            </script>
            @endscript
        @endif
    </x-nawasara-ui::page.container>
</div>
