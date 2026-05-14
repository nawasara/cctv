<div>
    <x-nawasara-ui::page.container>
        <x-nawasara-ui::page-header
            title="Recording"
            description="Rekaman tersegmen per kamera. Engine perekaman belum aktif di versi ini."
            :count="$this->recordings->total().' rekaman'" />

        {{-- Toolbar — filter kamera (dropdown) + filter tanggal.
             Pakai komponen filter-bar + filter-dropdown supaya konsisten
             dengan package lain. --}}
        <x-nawasara-ui::filter-bar>
            <x-nawasara-ui::filter-dropdown
                label="Kamera"
                model="cameraFilter"
                :items="$this->cameras->pluck('name', 'id')->toArray()" />

            {{-- Filter tanggal — native date input, dibungkus styling
                 senada tombol filter-dropdown. --}}
            <div class="relative inline-flex">
                <input type="date" wire:model.live="date"
                    class="py-2.5 px-4 inline-flex items-center gap-x-2 text-sm font-medium rounded-lg
                           border border-gray-200 bg-white text-gray-800 shadow-sm
                           focus:border-emerald-600 focus:ring-emerald-600 focus:outline-none
                           dark:bg-neutral-800 dark:border-neutral-700 dark:text-white" />
            </div>

            @if ($cameraFilter || $date)
                <button type="button" wire:click="$set('cameraFilter', ''); $set('date', '')"
                    class="py-2.5 px-3 inline-flex items-center gap-x-1.5 text-sm text-gray-500
                           hover:text-gray-700 dark:text-neutral-400 dark:hover:text-neutral-200">
                    <x-lucide-x class="size-4" />
                    Reset
                </button>
            @endif
        </x-nawasara-ui::filter-bar>

        @if ($this->recordings->isEmpty())
            <x-nawasara-ui::empty-state icon="lucide-film" title="Belum ada rekaman"
                description="Engine perekaman belum aktif di versi ini. Rekaman akan muncul di sini setelah perekaman diaktifkan dan kamera memiliki recording_enabled." />
        @else
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->recordings as $rec)
                    <div wire:key="rec-{{ $rec->id }}"
                        class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm
                               dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="relative aspect-video w-full bg-black">
                            @if ($playingId === $rec->id)
                                <video controls autoplay class="absolute inset-0 h-full w-full" wire:ignore>
                                    <source src="{{ $rec->playbackUrl() }}" type="video/mp4">
                                </video>
                            @else
                                <button type="button" wire:click="play({{ $rec->id }})"
                                    class="absolute inset-0 flex h-full w-full items-center justify-center
                                           text-white/60 transition hover:text-white">
                                    <x-lucide-circle-play class="size-14" />
                                </button>
                            @endif
                        </div>
                        <div class="flex items-center justify-between gap-2 px-4 py-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-gray-900 dark:text-neutral-100">
                                    {{ $rec->camera?->name ?? 'Kamera dihapus' }}
                                </p>
                                <p class="truncate text-xs text-gray-500 dark:text-neutral-400">
                                    {{ $rec->started_at?->format('d M Y H:i') }}
                                    @if ($rec->duration_seconds)
                                        · {{ gmdate('i:s', $rec->duration_seconds) }}
                                    @endif
                                </p>
                            </div>
                            @can('cctv.recording.delete')
                                <x-nawasara-ui::icon-button icon="trash-2" tooltip="Hapus rekaman"
                                    wire:click="delete({{ $rec->id }})"
                                    wire:confirm="Hapus rekaman ini?" />
                            @endcan
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-4">
                {{ $this->recordings->links('nawasara-ui::components.pagination') }}
            </div>
        @endif
    </x-nawasara-ui::page.container>
</div>
