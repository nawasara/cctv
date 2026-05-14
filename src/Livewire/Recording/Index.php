<?php

namespace Nawasara\Cctv\Livewire\Recording;

use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Nawasara\Cctv\Models\Camera;
use Nawasara\Cctv\Models\Recording;

/**
 * Recording playback browser.
 *
 * CATATAN v0.1.0: engine perekaman belum aktif (lihat config 'recording' +
 * README). UI ini sudah lengkap — filter per kamera, timeline, playback —
 * sehingga begitu engine recording diaktifkan di versi berikutnya, halaman
 * ini langsung berfungsi tanpa perubahan. Untuk sekarang daftar rekaman
 * biasanya kosong.
 */
class Index extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $cameraFilter = '';

    #[Url(except: '')]
    public string $date = '';

    public ?int $playingId = null;

    #[Computed]
    public function cameras()
    {
        return Camera::query()->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function recordings()
    {
        return Recording::query()
            ->with('camera:id,name')
            ->when($this->cameraFilter, fn ($q) => $q->where('camera_id', $this->cameraFilter))
            ->when($this->date, fn ($q) => $q->whereDate('started_at', $this->date))
            ->where('status', 'completed')
            ->orderByDesc('started_at')
            ->paginate((int) config('nawasara-cctv.per_page', 24));
    }

    public function updatedCameraFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDate(): void
    {
        $this->resetPage();
    }

    public function play(int $id): void
    {
        $this->playingId = $id;
    }

    public function delete(int $id): void
    {
        Gate::authorize('cctv.recording.delete');

        $recording = Recording::findOrFail($id);

        // Hapus file fisik dulu, baru row — kalau file gagal dihapus, row
        // tetap ada supaya tidak jadi orphan record yang tak terlacak.
        if ($recording->exists()) {
            \Illuminate\Support\Facades\Storage::disk($recording->disk)->delete($recording->path);
        }
        $recording->delete();

        if ($this->playingId === $id) {
            $this->playingId = null;
        }

        unset($this->recordings);
        $this->dispatch('toast', type: 'success', message: 'Rekaman dihapus.');
    }

    public function render()
    {
        return view('nawasara-cctv::livewire.pages.recording.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}
