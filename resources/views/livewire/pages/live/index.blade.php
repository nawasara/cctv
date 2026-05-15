<div>
    <x-nawasara-ui::page.container>
        <x-nawasara-ui::page-header
            title="Live View"
            description="Pantau kamera CCTV secara real-time. Pilih kamera dari daftar.">
            @if ($this->sidecarReachable)
                <x-nawasara-ui::badge color="success" icon="lucide-circle-check">go2rtc terhubung</x-nawasara-ui::badge>
            @else
                <x-nawasara-ui::badge color="danger" icon="lucide-circle-x">go2rtc tidak terhubung</x-nawasara-ui::badge>
            @endif
        </x-nawasara-ui::page-header>

        @unless ($this->sidecarReachable)
            <div class="mb-4 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3
                        text-sm text-amber-800 dark:border-amber-800/60 dark:bg-amber-950/40 dark:text-amber-200">
                <x-lucide-triangle-alert class="mt-0.5 size-4 shrink-0" />
                <span>
                    Sidecar <span class="font-mono">go2rtc</span> tidak dapat dihubungi. Stream tidak akan
                    tampil sampai container go2rtc berjalan. Cek <span class="font-mono">CCTV_GO2RTC_API_URL</span>
                    dan status container.
                </span>
            </div>
        @endunless

        @if ($this->cameras->isEmpty())
            <x-nawasara-ui::empty-state icon="lucide-monitor-off" title="Belum ada kamera aktif"
                description="Tambahkan kamera di menu Camera, lalu pastikan statusnya aktif." />
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
                class="flex flex-col gap-5 lg:flex-row">

                {{-- ============ SIDEBAR — daftar kamera ============ --}}
                <aside class="w-full shrink-0 lg:w-80">
                    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm
                                dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3
                                    dark:border-neutral-700">
                            <div class="flex items-center gap-2">
                                <x-lucide-cctv class="size-4 text-gray-400 dark:text-neutral-500" />
                                <h2 class="text-sm font-semibold text-gray-800 dark:text-neutral-100">
                                    Daftar CCTV
                                </h2>
                            </div>
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600
                                         dark:bg-neutral-700 dark:text-neutral-300">
                                {{ count($selected) }}/{{ \Nawasara\Cctv\Livewire\Live\Index::MAX_SELECTED }}
                            </span>
                        </div>

                        <ul class="max-h-[30rem] space-y-1 overflow-y-auto p-2
                                   [&::-webkit-scrollbar]:w-1.5
                                   [&::-webkit-scrollbar-thumb]:rounded-full
                                   [&::-webkit-scrollbar-thumb]:bg-gray-200
                                   dark:[&::-webkit-scrollbar-thumb]:bg-neutral-600">
                            @foreach ($this->cameras as $camera)
                                @php $isSelected = in_array($camera->slug, $selected, true); @endphp
                                <li wire:key="cam-li-{{ $camera->id }}">
                                    <button type="button"
                                        wire:click="toggle('{{ $camera->slug }}')"
                                        @class([
                                            'flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm transition-colors',
                                            'bg-emerald-50 text-emerald-900 ring-1 ring-inset ring-emerald-200 dark:bg-emerald-950/50 dark:text-emerald-200 dark:ring-emerald-800/60' => $isSelected,
                                            'text-gray-700 hover:bg-gray-50 dark:text-neutral-300 dark:hover:bg-neutral-700/50' => ! $isSelected,
                                        ])>
                                        {{-- indikator terpilih --}}
                                        <span @class([
                                            'flex size-5 shrink-0 items-center justify-center rounded-md border transition-colors',
                                            'border-emerald-600 bg-emerald-600 text-white' => $isSelected,
                                            'border-gray-300 dark:border-neutral-600' => ! $isSelected,
                                        ])>
                                            @if ($isSelected)
                                                <x-lucide-check class="size-3.5" />
                                            @endif
                                        </span>

                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate font-medium">{{ $camera->name }}</span>
                                            <span class="block truncate text-xs text-gray-500 dark:text-neutral-400">
                                                {{ $camera->location ?: '—' }}
                                            </span>
                                        </span>

                                        {{-- status online/offline.
                                             relative WAJIB di span luar — ping span pakai
                                             absolute, tanpa relative parent ia ambil posisi
                                             dari ancestor lain dan melayang saat scroll. --}}
                                        @if ($camera->isOnline())
                                            <span class="relative flex size-2.5 shrink-0">
                                                <span class="absolute inline-flex size-full animate-ping rounded-full
                                                             bg-green-400 opacity-60"></span>
                                                <span class="relative inline-flex size-2.5 rounded-full bg-green-500"></span>
                                            </span>
                                        @else
                                            <span class="size-2.5 shrink-0 rounded-full bg-rose-500"
                                                title="offline"></span>
                                        @endif
                                    </button>
                                </li>
                            @endforeach
                        </ul>

                        @if (count($selected) > 0)
                            <div class="border-t border-gray-100 p-2 dark:border-neutral-700">
                                <button type="button" wire:click="clearSelection"
                                    class="flex w-full items-center justify-center gap-1.5 rounded-lg px-3 py-2
                                           text-xs font-medium text-gray-500 transition-colors
                                           hover:bg-gray-50 hover:text-gray-700
                                           dark:hover:bg-neutral-700/50 dark:hover:text-neutral-200">
                                    <x-lucide-x class="size-3.5" />
                                    Kosongkan pilihan
                                </button>
                            </div>
                        @endif
                    </div>

                    <p class="mt-2.5 px-1 text-xs text-gray-400 dark:text-neutral-500">
                        Klik kamera untuk menonton. Maksimal
                        {{ \Nawasara\Cctv\Livewire\Live\Index::MAX_SELECTED }} kamera bersamaan.
                    </p>
                </aside>

                {{-- ============ GRID VIDEO — kamera terpilih ============ --}}
                <div class="min-w-0 flex-1">
                    @if ($this->selectedCameras->isEmpty())
                        <div class="flex h-72 flex-col items-center justify-center gap-2 rounded-xl
                                    border border-dashed border-gray-300 text-gray-400
                                    dark:border-neutral-700 dark:text-neutral-500">
                            <x-lucide-monitor-play class="size-10" />
                            <p class="text-sm">Pilih kamera dari daftar untuk mulai menonton.</p>
                        </div>
                    @else
                        {{-- 1 kamera = full width; 2+ = grid 2 kolom (maks 2x2) --}}
                        <div @class([
                            'grid gap-4',
                            'grid-cols-1' => $this->selectedCameras->count() === 1,
                            'grid-cols-1 sm:grid-cols-2' => $this->selectedCameras->count() > 1,
                        ])>
                            @foreach ($this->selectedCameras as $camera)
                                <div wire:key="live-{{ $camera->id }}"
                                    class="group overflow-hidden rounded-xl border border-gray-200 bg-white
                                           shadow-sm transition-shadow hover:shadow-md
                                           dark:border-neutral-700 dark:bg-neutral-800">
                                    {{-- video-stream web component di-mount oleh Alpine.
                                         wire:ignore — jangan biarkan Livewire patch ulang
                                         DOM video (akan putus koneksi WebRTC tiap render). --}}
                                    <div class="relative aspect-video w-full bg-black"
                                        wire:ignore
                                        x-data="{ slug: '{{ $camera->slug }}' }"
                                        x-init="mountStream($el, slug)">
                                        {{-- <video-stream> disuntik di sini oleh mountStream() --}}

                                        {{-- LIVE badge overlay --}}
                                        <span class="absolute left-2 top-2 z-10 inline-flex items-center gap-1
                                                     rounded-md bg-black/60 px-1.5 py-0.5 text-[10px]
                                                     font-semibold uppercase tracking-wide text-white
                                                     backdrop-blur-sm">
                                            <span class="size-1.5 rounded-full bg-red-500"></span>
                                            Live
                                        </span>
                                    </div>
                                    <div class="flex items-center justify-between gap-2 px-4 py-3">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-semibold text-gray-900 dark:text-neutral-100">
                                                {{ $camera->name }}
                                            </p>
                                            <p class="truncate text-xs text-gray-500 dark:text-neutral-400">
                                                {{ $camera->location ?: '—' }}
                                            </p>
                                        </div>
                                        <button type="button" wire:click="toggle('{{ $camera->slug }}')"
                                            class="shrink-0 rounded-lg p-2 text-gray-400 transition-colors
                                                   hover:bg-rose-50 hover:text-rose-600
                                                   dark:hover:bg-rose-950/50"
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
                    // base URL go2rtc untuk browser. Bisa absolut
                    // ('http://localhost:1984', dev lokal) ATAU path relatif
                    // ('/go2rtc', server lewat reverse-proxy nginx). Resolve
                    // ke absolut via location.origin supaya `new URL(...)`
                    // tidak error 'Invalid base URL' untuk path relatif.
                    // Tanpa trailing slash di akhir, supaya '/api/...' konsisten.
                    base: (() => {
                        const cleaned = go2rtcBase.replace(/\/$/, '');
                        // Sudah absolut (http:// / https:// / //) -> apa adanya.
                        if (/^(https?:)?\/\//i.test(cleaned)) return cleaned;
                        // Path relatif -> prefix dengan origin halaman saat ini.
                        return window.location.origin + (cleaned.startsWith('/') ? cleaned : '/' + cleaned);
                    })(),

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
                        // this.base sekarang dijamin absolut (lihat getter di atas).
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
