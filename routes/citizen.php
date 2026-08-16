<?php

use Illuminate\Support\Facades\Route;
use Nawasara\Cctv\Http\Api\CitizenCameraController;

/*
|--------------------------------------------------------------------------
| CCTV — rute aplikasi warga
|--------------------------------------------------------------------------
| Di-mount CctvServiceProvider di prefix /api/v1/citizen/cctv dengan
| middleware: api + api.citizen (JWT realm warga) + throttle.
|
| BUKAN `api.auth`. Yang itu untuk token sistem `nws_` milik integrasi
| antar-sistem, dan pemegangnya boleh melihat seluruh kamera aktif.
|
| Tidak ada pemisahan scope di sini — warga yang sudah masuk boleh melihat
| daftar sekaligus menontonnya. Yang membatasi adalah `is_public` pada
| kamera, bukan hak pada tokennya.
*/

Route::get('/cameras', [CitizenCameraController::class, 'index'])
    ->name('cameras.index');

Route::get('/cameras/{slug}', [CitizenCameraController::class, 'show'])
    ->name('cameras.show');

Route::get('/cameras/{slug}/stream', [CitizenCameraController::class, 'stream'])
    ->name('cameras.stream');
