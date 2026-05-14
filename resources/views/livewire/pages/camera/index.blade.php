<div>
    <x-nawasara-ui::page.container>
        <x-nawasara-ui::page.title>Cameras</x-nawasara-ui::page.title>

        <x-slot name="actions">
            <x-nawasara-ui::button color="primary" wire:click="openCreate" permission="cctv.camera.create">
                <x-slot:icon><x-lucide-plus class="size-4" /></x-slot:icon>
                Tambah Kamera
            </x-nawasara-ui::button>
        </x-slot>

        {{-- Search --}}
        <div class="mb-4 max-w-sm">
            <x-nawasara-ui::search-input wire:model.live.debounce.300ms="search"
                placeholder="Cari nama, lokasi, atau IP..." />
        </div>

        @if ($this->cameras->isEmpty())
            <x-nawasara-ui::empty-state icon="lucide-video-off" title="Belum ada kamera"
                description="Tambahkan kamera Dahua untuk mulai monitoring.">
                <x-nawasara-ui::button color="primary" wire:click="openCreate" permission="cctv.camera.create">
                    <x-slot:icon><x-lucide-plus class="size-4" /></x-slot:icon>
                    Tambah Kamera
                </x-nawasara-ui::button>
            </x-nawasara-ui::empty-state>
        @else
            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-800">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                    <thead class="bg-gray-50 dark:bg-gray-900">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Nama</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Lokasi</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Alamat</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Channel</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Status</th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase text-gray-500">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                        @foreach ($this->cameras as $camera)
                            <tr wire:key="camera-{{ $camera->id }}">
                                <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">
                                    {{ $camera->name }}
                                    @unless ($camera->is_active)
                                        <x-nawasara-ui::badge color="neutral">nonaktif</x-nawasara-ui::badge>
                                    @endunless
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">
                                    {{ $camera->location ?: '—' }}
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 font-mono">
                                    {{ $camera->ip_address }}:{{ $camera->rtsp_port }}
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">
                                    ch {{ $camera->channel }} /
                                    {{ $camera->subtype === 0 ? 'main' : 'sub' }}
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    @if ($camera->health_status === 'online')
                                        <x-nawasara-ui::badge color="success">online</x-nawasara-ui::badge>
                                    @elseif ($camera->health_status === 'offline')
                                        <x-nawasara-ui::badge color="danger">offline</x-nawasara-ui::badge>
                                    @else
                                        <x-nawasara-ui::badge color="neutral">belum diprobe</x-nawasara-ui::badge>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex justify-end gap-2">
                                        <x-nawasara-ui::button variant="ghost" size="sm" color="secondary"
                                            wire:click="openEdit({{ $camera->id }})" permission="cctv.camera.update">
                                            <x-slot:icon><x-lucide-pencil class="size-4" /></x-slot:icon>
                                            Edit
                                        </x-nawasara-ui::button>
                                        <x-nawasara-ui::button variant="ghost" size="sm" color="danger"
                                            wire:click="delete({{ $camera->id }})"
                                            wire:confirm="Hapus kamera {{ $camera->name }}?"
                                            permission="cctv.camera.delete">
                                            <x-slot:icon><x-lucide-trash-2 class="size-4" /></x-slot:icon>
                                            Hapus
                                        </x-nawasara-ui::button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $this->cameras->links('nawasara-ui::components.pagination') }}
            </div>
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
