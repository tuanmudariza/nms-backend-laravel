<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MikrotikController; // <--- WAJIB panggil nama controllernya di sini!

// Jalur lama kita
Route::get('/ping-mikrotik', function() {
    return response()->json([
        'status' => 'success',
        'message' => 'Koneksi ke backend Laravel aman!',
        'uptime' => '2 days, 5 hours'
    ]);
});

// JALUR BARU: Untuk nembak status MikroTik asli
Route::get('/mikrotik/status', [MikrotikController::class, 'getStatus']);

// Jalur untuk cek traffic bandwidth (Contoh akses: api/mikrotik/traffic?interface=ether1)
Route::get('/mikrotik/traffic', [MikrotikController::class, 'getTraffic']);

// Jalur untuk melihat semua daftar pelanggan PPPoE ISP
Route::get('/mikrotik/pppoe', [MikrotikController::class, 'getPppoeSecrets']);