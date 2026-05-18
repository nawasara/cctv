<?php

use Illuminate\Support\Facades\Route;
use Nawasara\Cctv\Http\Api\CameraController;
use Nawasara\Cctv\Http\Api\StreamProxyController;

/*
|--------------------------------------------------------------------------
| CCTV API routes
|--------------------------------------------------------------------------
| Di-mount oleh CctvServiceProvider di prefix /api/v1/cctv dengan
| middleware group:
|   - api  (Laravel default)
|   - api.auth (Bearer/X-API-Key dari nawasara/api)
|   - api.log (audit log)
|
| Per-route ditambah middleware scope:<name>.
*/

// Camera listing + detail. Scope: read.
Route::middleware('scope:cctv.camera.read')->group(function () {
    Route::get('/cameras', [CameraController::class, 'index'])->name('cctv.cameras.index');
    Route::get('/cameras/{slug}', [CameraController::class, 'show'])->name('cctv.cameras.show');
});

// Stream URL generator. Scope: stream (lebih sensitif — kasih ke trusted client).
Route::middleware('scope:cctv.camera.stream')->group(function () {
    Route::get('/cameras/{slug}/stream', [CameraController::class, 'stream'])
        ->name('cctv.cameras.stream');
});

/*
|--------------------------------------------------------------------------
| Stream proxy verify (Nginx auth_request hook)
|--------------------------------------------------------------------------
| Dipanggil oleh Nginx — TIDAK butuh API token, auth via signed URL.
| Karena itu mount di group terpisah TANPA middleware api.auth.
|
| ServiceProvider mount sub-grup ini ke prefix /api/v1/cctv juga tapi
| pakai middleware lain — lihat boot().
*/
