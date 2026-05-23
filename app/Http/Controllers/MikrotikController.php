<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use RouterOS\Client;
use RouterOS\Query;

class MikrotikController extends Controller
{
    // Fungsi privat untuk otomatis konek ke MikroTik pakai data dari file .env
    private function connectMikrotik()
    {
        return new Client([
            'host' => env('MIKROTIK_HOST'),
            'user' => env('MIKROTIK_USER'),
            'pass' => env('MIKROTIK_PASS'),
            'port' => (int) env('MIKROTIK_PORT'),
        ]);
    }

    // 1. API untuk mengambil informasi dasar Router (Uptime, Board Name, CPU Load)
    public function getStatus()
    {
        try {
            $client = $this->connectMikrotik();

            $query = new Query('/system/resource/print');
            $response = $client->query($query)->read();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'uptime'     => $response[0]['uptime'],
                    'board_name' => $response[0]['board-name'],
                    'cpu_load'   => $response[0]['cpu-load'] . '%',
                    'free_memory'=> round($response[0]['free-memory'] / 1024 / 1024, 2) . ' MB',
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal terhubung ke MikroTik: ' . $e->getMessage()
            ], 500);
        }
    }

    // 2. API untuk memantau Traffic (Bandwidth) Real-time pada Interface tertentu
    public function getTraffic(Request $request)
    {
        try {
            $client = $this->connectMikrotik();
            
            $interface = $request->query('interface', 'ether1');

            $query = new Query('/interface/monitor-traffic');
            $query->equal('interface', $interface);
            $query->equal('once', '');

            $response = $client->query($query)->read();

            return response()->json([
                'status' => 'success',
                'interface' => $interface,
                'data' => [
                    'download_speed' => round($response[0]['rx-bits-per-second'] / 1000000, 2) . ' Mbps',
                    'upload_speed'   => round($response[0]['tx-bits-per-second'] / 1000000, 2) . ' Mbps',
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengambil data traffic: ' . $e->getMessage()
            ], 500);
        }
    }

    // 3. API untuk mengambil daftar semua pelanggan PPPoE (Secret) yang terdaftar
    public function getPppoeSecrets()
    {
        try {
            $client = $this->connectMikrotik();

            $query = new Query('/ppp/secret/print');
            $response = $client->query($query)->read();

            $cleanData = [];
            foreach ($response as $user) {
                $cleanData[] = [
                    'name'     => $user['name'],
                    'service'  => $user['service'] ?? 'any',
                    'profile'  => $user['profile'],
                    'disabled' => $user['disabled'] === 'true' ? 'Non-Aktif' : 'Aktif',
                ];
            }

            return response()->json([
                'status' => 'success',
                'total_pelanggan' => count($cleanData),
                'data' => $cleanData
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengambil data pelanggan: ' . $e->getMessage()
            ], 500);
        }
    }
} // <--- Kurung kurawal penutup akhir Class utama wajib di paling bawah file!