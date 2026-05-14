<div>
    <x-nawasara-ui::page.container>
        {{-- Page header — judul + jumlah kamera + tombol Tambah --}}
        <x-nawasara-ui::page-header
            title="Camera"
            description="Registry kamera CCTV Dahua. Stream didaftarkan ke go2rtc untuk live view."
            :count="$this->cameras->total().' kamera'">
            <x-nawasara-ui::button color="primary" wire:click="openCreate" permission="cctv.camera.create">
                <x-slot:icon><x-lucide-plus class="size-4" /></x-slot:icon>
                Tambah Kamera
            </x-nawasara-ui::button>
        </x-nawasara-ui::page-header>

        {{-- Toolbar — search via filter-bar (konsisten dengan package lain) --}}
        <x-nawasara-ui::filter-bar
            search-model="search"
            search-placeholder="Cari nama, lokasi, atau IP..." />

        @if ($this->cameras->isEmpty())
            <x-nawasara-ui::empty-state icon="lucide-video-off" title="Belum ada kamera"
                description="Tambahkan kamera Dahua untuk mulai monitoring.">
                <x-nawasara-ui::button color="primary" wire:click="openCreate" permission="cctv.camera.create">
                    <x-slot:icon><x-lucide-plus class="size-4" /></x-slot:icon>
                    Tambah Kamera
                </x-nawasara-ui::button>
            </x-nawasara-ui::empty-state>
        @else
            {{-- Tabel — pakai komponen x-table (sticky kolom Aksi saat scroll) --}}
            <x-nawasara-ui::table
                :headers="['Nama', 'Lokasi', 'Alamat', 'Channel', 'Codec', 'Status', 'Aksi']"
                stickyLast>
                <x-slot:table>
                    @foreach ($this->cameras as $camera)
                        <tr wire:key="camera-{{ $camera->id }}">
                            <td class="px-6 py-4 text-sm font-medium text-gray-900 dark:text-neutral-100">
                                <div class="flex items-center gap-2">
                                    {{ $camera->name }}
                                    @unless ($camera->is_active)
                                        <x-nawasara-ui::badge color="neutral">nonaktif</x-nawasara-ui::badge>
                                    @endunless
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600 dark:text-neutral-400">
                                {{ $camera->location ?: '—' }}
                            </td>
                            <td class="px-6 py-4 font-mono text-sm text-gray-600 dark:text-neutral-400">
                                {{ $camera->ip_address }}:{{ $camera->rtsp_port }}
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600 dark:text-neutral-400">
                                ch {{ $camera->channel }} / {{ $camera->subtype === 0 ? 'main' : 'sub' }}
                            </td>
                            <td class="px-6 py-4 text-sm">
                                @if ($camera->video_codec === 'h265')
                                    <x-nawasara-ui::badge color="warning">H.265 · transcode</x-nawasara-ui::badge>
                                @else
                                    <x-nawasara-ui::badge color="neutral">{{ $camera->video_codec === 'h264' ? 'H.264' : 'auto' }}</x-nawasara-ui::badge>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm">
                                @if ($camera->health_status === 'online')
                                    <x-nawasara-ui::badge color="success" icon="lucide-circle-check">online</x-nawasara-ui::badge>
                                @elseif ($camera->health_status === 'offline')
                                    <x-nawasara-ui::badge color="danger" icon="lucide-circle-x">offline</x-nawasara-ui::badge>
                                @else
                                    <x-nawasara-ui::badge color="neutral">belum diprobe</x-nawasara-ui::badge>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex justify-end gap-1">
                                    @can('cctv.camera.update')
                                        <x-nawasara-ui::icon-button icon="pencil" tooltip="Edit kamera"
                                            wire:click="openEdit({{ $camera->id }})" />
                                    @endcan
                                    @can('cctv.camera.delete')
                                        <x-nawasara-ui::icon-button icon="trash-2" tooltip="Hapus kamera"
                                            wire:click="delete({{ $camera->id }})"
                                            wire:confirm="Hapus kamera {{ $camera->name }}?" />
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </x-slot:table>

                <x-slot:footer>
                    <div class="px-2">
                        {{ $this->cameras->links('nawasara-ui::components.pagination') }}
                    </div>
                </x-slot:footer>
            </x-nawasara-ui::table>
        @endif
    </x-nawasara-ui::page.container>

    {{-- Create / Edit modal --}}
    <x-nawasara-ui::modal wire:model="showForm" maxWidth="2xl"
        :title="$editingId ? 'Edit Kamera' : 'Tambah Kamera'"
        subtitle="Konfigurasi koneksi RTSP kamera Dahua.">
        <form wire:submit="save" class="space-y-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-nawasara-ui::form.input label="Nama Kamera" wire:model="name"
                        placeholder="Gerbang Utama" />
                    @error('name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-nawasara-ui::form.input label="Lokasi" wire:model="location"
                        placeholder="Lobi lantai 1" />
                    @error('location') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <x-nawasara-ui::form.input label="Alamat IP" wire:model="ip_address"
                        placeholder="103.109.206.38" />
                    @error('ip_address') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-nawasara-ui::form.input label="Port RTSP" type="number" wire:model="rtsp_port" />
                    @error('rtsp_port') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <x-nawasara-ui::form.input label="Port HTTP" type="number" wire:model="http_port" />
                    @error('http_port') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-nawasara-ui::form.input label="Channel (1-16)" type="number" wire:model="channel" />
                    @error('channel') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-nawasara-ui::form.select label="Stream" wire:model="subtype"
                        :options="[0 => 'Main (HD)', 1 => 'Sub (SD)']" :placeholder="null" />
                    @error('subtype') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <x-nawasara-ui::form.select label="Codec Video" wire:model="video_codec"
                    :options="[
                        'auto' => 'Auto / H.264 (tanpa transcode)',
                        'h264' => 'H.264 (eksplisit)',
                        'h265' => 'H.265 / HEVC (transcode ke H.264)',
                    ]" :placeholder="null"
                    hint="Kamera Dahua sering H.265 — browser tidak bisa putar H.265 via WebRTC. Pilih H.265 supaya go2rtc transcode (lebih berat CPU)." />
                @error('video_codec') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-nawasara-ui::form.input label="Username Kamera" wire:model="username"
                        placeholder="admin" autocomplete="off" />
                    @error('username') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-nawasara-ui::form.input label="Password Kamera" type="password" wire:model="password"
                        autocomplete="new-password"
                        :placeholder="$editingId ? 'Biarkan kosong jika tidak diubah' : ''" />
                    @error('password') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="flex flex-col gap-2">
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="checkbox" wire:model="is_active"
                        class="rounded border-gray-300 text-emerald-700 focus:ring-emerald-700">
                    Aktif (stream didaftarkan ke go2rtc)
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="checkbox" wire:model="sync_title"
                        class="rounded border-gray-300 text-emerald-700 focus:ring-emerald-700">
                    Sinkronkan nama dari device Dahua
                    <span class="text-xs text-gray-400">(matikan untuk nama custom)</span>
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="checkbox" wire:model="recording_enabled"
                        class="rounded border-gray-300 text-emerald-700 focus:ring-emerald-700">
                    Aktifkan perekaman
                    <span class="text-xs text-gray-400">(engine perekaman menyusul)</span>
                </label>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <x-nawasara-ui::button type="button" variant="ghost" color="secondary"
                    wire:click="$set('showForm', false)">
                    Batal
                </x-nawasara-ui::button>
                <x-nawasara-ui::button type="submit" color="primary">
                    {{ $editingId ? 'Simpan Perubahan' : 'Tambah Kamera' }}
                </x-nawasara-ui::button>
            </div>
        </form>
    </x-nawasara-ui::modal>
</div>
