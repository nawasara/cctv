<?php

use Illuminate\Support\Facades\Route;
use Nawasara\Cctv\Http\Controllers\Go2rtcAuthController;
use Nawasara\Cctv\Livewire\Camera\Index as CameraIndex;
use Nawasara\Cctv\Livewire\Live\Index as LiveIndex;
use Nawasara\Cctv\Livewire\Recording\Index as RecordingIndex;
use Spatie\Permission\Middleware\PermissionMiddleware;

Route::middleware(['web', 'auth'])->prefix('nawasara-cctv')->group(function () {
    Route::get('live', LiveIndex::class)
        ->middleware(PermissionMiddleware::using('cctv.camera.view'))
        ->name('nawasara-cctv.live.index');

    Route::get('cameras', CameraIndex::class)
        ->middleware(PermissionMiddleware::using('cctv.camera.view'))
        ->name('nawasara-cctv.camera.index');

    Route::get('recordings', RecordingIndex::class)
        ->middleware(PermissionMiddleware::using('cctv.recording.view'))
        ->name('nawasara-cctv.recording.index');
});

// Gerbang auth_request nginx untuk /go2rtc/api/ws; lihat Go2rtcAuthController.
// Sengaja TANPA middleware `auth`: yang itu mengalihkan ke halaman login (302),
// dan auth_request menganggap 3xx sebagai galat, bukan penolakan.
Route::middleware(['web'])
    ->get('nawasara-cctv/go2rtc-auth', Go2rtcAuthController::class)
    ->name('nawasara-cctv.go2rtc-auth');
