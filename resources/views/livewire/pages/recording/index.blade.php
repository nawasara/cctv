<div>
    <x-nawasara-ui::page.container>
        <x-nawasara-ui::page.title>Recordings</x-nawasara-ui::page.title>

        {{-- Filters --}}
        <div class="mb-4 flex flex-wrap gap-3">
            <x-nawasara-ui::form.select label="Kamera" name="cameraFilter" wire:model.live="cameraFilter"
                placeholder="Semua kamera"
                :options="$this->cameras->pluck('name', 'id')->toArray()" />

            <div class="flex flex-col gap-1">
                <x-nawasara-ui::form.label value="Tanggal" />
                <input type="date" wire:model.live="date"
                    class="py-3 px-4 block border border-gray-300 rounded-md text-sm outline-none
                           focus:ring-2 focus:ring-emerald-700/80 dark:bg-neutral-900 dark:border-gray-800
                           text-gray-900 dark:text-neutral-100">
            </div>
        </div>

        @if ($this->recordings->isEmpty())
            <x-nawasara-ui::empty-state icon="lucide-film" title="Belum ada rekaman"
                description="Engine perekaman belum aktif di versi ini. Rekaman akan muncul di sini setelah perekaman diaktifkan dan kamera memiliki recording_enabled." />
        @else
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->recordings as $rec)
                    <div wire:key="rec-{{ $rec->id }}"
                        class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
                        <div class="aspect-video w-full bg-black">
                            @if ($playingId === $rec->id)
                                <video controls autoplay class="h-full w-full" wire:ignore>
                                    <source src="{{ $rec->playbackUrl() }}" type="video/mp4">
                                </video>
                            @else
                                <button type="button" wire:click="play({{ $rec->id }})"
                                    class="flex h-full w-full items-center justify-center text-white/70
                                           transition hover:text-white">
                                    <x-lucide-play-circle class="size-12" />
                                </button>
                            @endif
                        </div>
                        <div class="flex items-center justify-between px-3 py-2">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">
                                    {{ $rec->camera?->name ?? 'Kamera dihapus' }}
                                </p>
                                <p class="truncate text-xs text-gray-500">
                                    {{ $rec->started_at?->format('d M Y H:i') }}
                                    @if ($rec->duration_seconds)
                                        · {{ gmdate('i:s', $rec->duration_seconds) }}
                                    @endif
                                </p>
                            </div>
                            <x-nawasara-ui::button variant="ghost" color="danger" size="sm"
                                wire:click="delete({{ $rec->id }})"
                                wire:confirm="Hapus rekaman ini?" permission="cctv.recording.delete">
                                <x-slot:icon><x-lucide-trash-2 class="size-4" /></x-slot:icon>
                            </x-nawasara-ui::button>
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
