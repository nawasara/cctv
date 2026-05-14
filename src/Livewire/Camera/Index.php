<?php

namespace Nawasara\Cctv\Livewire\Camera;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Nawasara\Cctv\Models\Camera;
use Nawasara\Cctv\Services\Go2rtcClient;

/**
 * Camera registry CRUD.
 *
 * Create/update kamera + sinkronkan stream-nya ke go2rtc sidecar. Kredensial
 * di-handle model (cast 'encrypted') — komponen ini tidak pernah meng-echo
 * password balik ke view; field password di form selalu mulai kosong saat
 * edit (user isi ulang hanya kalau mau ganti).
 */
class Index extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    // -- Form modal state ------------------------------------------------------
    public bool $showForm = false;
    public ?int $editingId = null;

    public string $name = '';
    public string $location = '';
    public string $ip_address = '';
    public int $rtsp_port = 554;
    public int $http_port = 80;
    public int $channel = 1;
    public int $subtype = 0;
    public string $username = '';
    public string $password = '';
    public bool $is_active = true;
    public bool $recording_enabled = false;

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'ip_address' => ['required', 'ip'],
            'rtsp_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'http_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'channel' => ['required', 'integer', 'min:1', 'max:16'],
            'subtype' => ['required', 'integer', 'in:0,1'],
            'username' => ['required', 'string', 'max:255'],
            // Password wajib saat create; saat edit boleh kosong (= tidak diubah).
            'password' => [$this->editingId ? 'nullable' : 'required', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'recording_enabled' => ['boolean'],
        ];
    }

    #[Computed]
    public function cameras()
    {
        return Camera::query()
            ->when($this->search, function ($q) {
                $q->where(function ($q) {
                    $q->where('name', 'like', "%{$this->search}%")
                        ->orWhere('location', 'like', "%{$this->search}%")
                        ->orWhere('ip_address', 'like', "%{$this->search}%");
                });
            })
            ->orderBy('name')
            ->paginate((int) config('nawasara-cctv.per_page', 24));
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function openCreate(): void
    {
        Gate::authorize('cctv.camera.create');
        $this->resetForm();
        $this->showForm = true;
    }

    public function openEdit(int $id): void
    {
        Gate::authorize('cctv.camera.update');

        $camera = Camera::findOrFail($id);

        $this->editingId = $camera->id;
        $this->name = $camera->name;
        $this->location = (string) $camera->location;
        $this->ip_address = $camera->ip_address;
        $this->rtsp_port = $camera->rtsp_port;
        $this->http_port = $camera->http_port;
        $this->channel = $camera->channel;
        $this->subtype = $camera->subtype;
        $this->username = $camera->username;
        $this->password = ''; // jangan echo password balik — kosongkan
        $this->is_active = $camera->is_active;
        $this->recording_enabled = $camera->recording_enabled;

        $this->showForm = true;
    }

    public function save(Go2rtcClient $go2rtc): void
    {
        Gate::authorize($this->editingId ? 'cctv.camera.update' : 'cctv.camera.create');

        $validated = $this->validate();

        if ($this->editingId) {
            $camera = Camera::findOrFail($this->editingId);
            $camera->fill([
                'name' => $validated['name'],
                'location' => $validated['location'] ?: null,
                'ip_address' => $validated['ip_address'],
                'rtsp_port' => $validated['rtsp_port'],
                'http_port' => $validated['http_port'],
                'channel' => $validated['channel'],
                'subtype' => $validated['subtype'],
                'username' => $validated['username'],
                'is_active' => $validated['is_active'],
                'recording_enabled' => $validated['recording_enabled'],
            ]);
            // Password hanya di-set kalau user mengisi ulang.
            if (! empty($validated['password'])) {
                $camera->password = $validated['password'];
            }
            $camera->save();
        } else {
            $camera = Camera::create([
                'name' => $validated['name'],
                'location' => $validated['location'] ?: null,
                'slug' => $this->uniqueSlug($validated['name']),
                'ip_address' => $validated['ip_address'],
                'rtsp_port' => $validated['rtsp_port'],
                'http_port' => $validated['http_port'],
                'channel' => $validated['channel'],
                'subtype' => $validated['subtype'],
                'username' => $validated['username'],
                'password' => $validated['password'],
                'is_active' => $validated['is_active'],
                'recording_enabled' => $validated['recording_enabled'],
            ]);
        }

        // Sinkronkan ke go2rtc — kalau kamera aktif, daftarkan/update stream;
        // kalau dinonaktifkan, cabut. Gagal sync TIDAK membatalkan save:
        // sidecar bisa offline, scheduler cctv:sync-go2rtc akan retry.
        if ($camera->is_active) {
            $go2rtc->registerCamera($camera);
        } else {
            $go2rtc->removeCamera($camera->slug);
        }

        $this->showForm = false;
        $this->resetForm();
        unset($this->cameras);

        $this->dispatch('toast', type: 'success', message: 'Kamera tersimpan.');
    }

    public function delete(int $id, Go2rtcClient $go2rtc): void
    {
        Gate::authorize('cctv.camera.delete');

        $camera = Camera::findOrFail($id);
        $slug = $camera->slug;
        $camera->delete();

        $go2rtc->removeCamera($slug);

        unset($this->cameras);
        $this->dispatch('toast', type: 'success', message: 'Kamera dihapus.');
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'name', 'location', 'ip_address',
            'username', 'password',
        ]);
        $this->rtsp_port = 554;
        $this->http_port = 80;
        $this->channel = 1;
        $this->subtype = 0;
        $this->is_active = true;
        $this->recording_enabled = false;
        $this->resetValidation();
    }

    /**
     * Slug unik untuk dipakai sebagai stream id di go2rtc. go2rtc meng-key
     * stream by name, jadi tabrakan slug = stream saling timpa.
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'camera';
        $slug = $base;
        $i = 2;

        while (Camera::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    public function render()
    {
        return view('nawasara-cctv::livewire.pages.camera.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}
