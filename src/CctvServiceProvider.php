<?php

namespace Nawasara\Cctv;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Nawasara\Cctv\Console\Commands\ProbeCamerasCommand;
use Nawasara\Cctv\Console\Commands\SyncGo2rtcCommand;
use Nawasara\Cctv\Services\CameraHealthProbe;
use Nawasara\Cctv\Services\Go2rtcClient;
use Symfony\Component\Finder\Finder;

class CctvServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nawasara-cctv.php', 'nawasara-cctv');

        $this->app->singleton(Go2rtcClient::class, fn () => new Go2rtcClient(
            (string) config('nawasara-cctv.go2rtc.api_url'),
            (int) config('nawasara-cctv.go2rtc.timeout'),
        ));

        $this->app->singleton(CameraHealthProbe::class, fn () => new CameraHealthProbe(
            (int) config('nawasara-cctv.health.probe_timeout'),
            (int) config('nawasara-cctv.health.failure_threshold'),
        ));
    }

    public function boot(): void
    {
        // Commands didaftarkan duluan — sebelum operasi lain yang mungkin gagal.
        if ($this->app->runningInConsole()) {
            $this->commands([
                ProbeCamerasCommand::class,
                SyncGo2rtcCommand::class,
            ]);
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'nawasara-cctv');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Guarded — view:cache crash kalau path component tidak ada.
        if (is_dir(__DIR__.'/../resources/views/components')) {
            Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'nawasara-cctv');
        }

        $this->registerLivewire();

        $this->app->booted(function () {
            if (! $this->app->runningInConsole()) {
                return;
            }

            $schedule = $this->app->make(Schedule::class);

            // Probe kesehatan kamera tiap 5 menit — cukup sering untuk badge
            // online/offline akurat, cukup jarang untuk tidak membanjiri
            // jaringan dengan TCP connect.
            $schedule->command('cctv:probe')
                ->everyFiveMinutes()
                ->withoutOverlapping(4)
                ->runInBackground();

            // Sinkronisasi go2rtc tiap jam — jaring pengaman kalau sidecar
            // restart dan kehilangan daftar stream in-memory-nya.
            $schedule->command('cctv:sync-go2rtc')
                ->hourly()
                ->withoutOverlapping(10)
                ->runInBackground();
        });
    }

    public function registerLivewire(): void
    {
        $namespace = 'Nawasara\\Cctv\\Livewire';
        $basePath = __DIR__.'/Livewire';

        if (! is_dir($basePath)) {
            return;
        }

        $finder = new Finder();
        $finder->files()->in($basePath)->name('*.php');

        foreach ($finder as $file) {
            $relativePath = str_replace('/', '\\', $file->getRelativePathname());
            $class = $namespace.'\\'.Str::beforeLast($relativePath, '.php');

            if (class_exists($class)) {
                $alias = 'nawasara-cctv.'.
                    Str::of($relativePath)
                        ->replace('.php', '')
                        ->replace('\\', '.')
                        ->replace('/', '.')
                        ->explode('.')
                        ->map(fn ($segment) => Str::kebab($segment))
                        ->join('.');

                Livewire::component($alias, $class);
            }
        }
    }
}
