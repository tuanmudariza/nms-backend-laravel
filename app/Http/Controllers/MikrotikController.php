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

    public function getRouterInfo()
{
    try {
        // 1. Manfaatkan fungsi private koneksi yang udah lo punya di baris 12
        $client = $this->connectMikrotik();

        // 2. Bikin query buat narik resource sistem MikroTik
        $query = new \RouterOS\Query('/system/resource/print');
        $response = $client->query($query)->read();

        // Ambil data array pertama dari respon MikroTik
        $systemResource = $response[0] ?? [];

        // 3. Setor data asli ke Flutter kalau sukses konek ke alat kantor
        return response()->json([
            'status' => 'success',
            'data' => [
                'board_name'  => $systemResource['board-name'] ?? 'Unknown',
                'version'     => $systemResource['version'] ?? 'Unknown',
                'uptime'      => $systemResource['uptime'] ?? '00:00:00',
                'cpu_load'    => ($systemResource['cpu-load'] ?? 0) . '%',
                'free_memory' => isset($systemResource['free-memory']) ? round($systemResource['free-memory'] / 1024 / 1024, 2) . ' MB' : '0 MB',
            ]
        ], 200);

    } catch (\Exception $e) {
        // 4. MODE DUMMY: Kalau gagal konek (pas lo lagi di rumah/kosan), sistem gak bakal crash!
        return response()->json([
            'status' => 'error',
            'message' => 'Gagal terhubung ke router fisik. Mengaktifkan Mode Simulator.',
            'data' => [
                'board_name'  => 'MikroTik RB951Ui (Dummy)',
                'version'     => '7.12 (Stable) - Dummy',
                'uptime'      => '1d 04:23:11',
                'cpu_load'    => rand(5, 25) . '%',
                'free_memory' => '64.5 MB',
            ]
        ], 200);
    }
}

public function getTraffic()
{
    try {
        // 1. Konek ke MikroTik menggunakan fungsi andalan lo
        $client = $this->connectMikrotik();

        // 2. Query untuk mengambil monitor-interface (ganti 'ether1' sesuai interface internet kantor lo)
        $query = new \RouterOS\Query('/interface/monitor-interface');
        $query->equal('interface', 'ether1');
        $query->equal('once', ''); // Mengambil data sekali tembak saja

        $response = $client->query($query)->read();
        $traffic = $response[0] ?? [];

        // 3. Setor data traffic asli ke Flutter
        return response()->json([
            'status' => 'success',
            'data' => [
                'interface' => 'ether1',
                // Mengonversi data bps mentah menjadi format yang ramah dibaca (Kbps / Mbps)
                'rx_bits_per_second' => isset($traffic['rx-bits-per-second']) ? round($traffic['rx-bits-per-second'] / 1000, 1) . ' Kbps' : '0 Kbps',
                'tx_bits_per_second' => isset($traffic['tx-bits-per-second']) ? round($traffic['tx-bits-per-second'] / 1000, 1) . ' Kbps' : '0 Kbps',
                'rx_packets_per_second' => $traffic['rx-packets-per-second'] ?? 0,
                'tx_packets_per_second' => $traffic['tx-packets-per-second'] ?? 0,
            ]
        ], 200);

    } catch (\Exception $e) {
        // 4. MODE SIMULATOR (DUMMY): Biar lo berdua tetep bisa ngoding grafik di rumah/kosan
        return response()->json([
            'status' => 'success',
            'message' => 'Menggunakan Mode Simulator Traffic.',
            'data' => [
                'interface' => 'ether1 (Simulator)',
                // Mengenerate angka acak biar grafiknya di Flutter nanti kelihatan naik-turun bergerak hidup!
                'rx_bits_per_second' => rand(500, 4500) . ' Kbps',
                'tx_bits_per_second' => rand(300, 2500) . ' Kbps',
                'rx_packets_per_second' => rand(50, 400),
                'tx_packets_per_second' => rand(30, 250),
            ]
        ], 200);
    }
}

public function getPppoeSecrets(Request $request)
{
    try {
        $client = $this->connectMikrotik();

        // 1. Ambil data secret & active secara real-time
        $querySecret = new \RouterOS\Query('/ppp/secret/print');
        $secrets = $client->query($querySecret)->read();

        $queryActive = new \RouterOS\Query('/ppp/active/print');
        $actives = $client->query($queryActive)->read();

        $activeUsers = array_column($actives, 'name');

        // Ambil parameter pencarian dari Flutter nanti (kalau ada)
        $search = $request->query('search');
        $limit = $request->query('limit', 50); // Default batasi 50 data dulu biar Flutter gak berat

        $userList = [];
        foreach ($secrets as $secret) {
            $username = $secret['name'] ?? 'Unknown';

            // Filter Pencarian: Kalau Flutter kirim query search, kita saring di sini
            if ($search && stripos($username, $search) === false) {
                continue;
            }

            $isOnline = in_array($username, $activeUsers);
            $rawUptime = $isOnline ? ($actives[array_search($username, $activeUsers)]['uptime'] ?? '0s') : '-';
            
            // Jinakkan dulu uptime aneh mikrotik lewat fungsi helper kita
            $parsedUptime = $this->parseMikrotikUptime($rawUptime);

            $userList[] = [
                'username' => $username,
                'profile'  => $secret['profile'] ?? 'default',
                'service'  => $secret['service'] ?? 'pppoe',
                'status'   => $isOnline ? 'Online' : 'Offline',
                'uptime_raw' => $rawUptime, // Tetap tampilin yang asli buat jaga-jaga
                'uptime'     => $parsedUptime['readable'], // Format rapi: "2 hari 19 jam 57 menit"
                'uptime_seconds' => $parsedUptime['seconds'], // Angka murni detik: 244640
            ];
        }

        // Hitung total sebelum di-limit
        $totalUsers = count($userList);
        $totalOnline = count(array_filter($userList, fn($u) => $u['status'] === 'Online'));

        // Potong data sesuai limit biar beban kerja Flutter enteng
        if ($limit && $limit > 0) {
            $userList = array_slice($userList, 0, $limit);
        }

        return response()->json([
            'status' => 'success',
            'search_keyword' => $search ?? 'none',
            'total_filtered' => count($userList),
            'total_users_all' => $totalUsers,
            'total_online_all' => $totalOnline,
            'data' => $userList
        ], 200);

    } catch (\Exception $e) {
        // MODE SIMULATOR (Tetap aman kalau lo lagi offline dari router kantor)
        return response()->json([
            'status' => 'success',
            'message' => 'Menggunakan Mode Simulator PPPoE.',
            'total_filtered' => 5,
            'total_users_all' => 5,
            'total_online_all' => 3,
            'data' => [
                [
                    'username' => 'budi_net', 
                    'profile' => '10Mbps_Unlim', 
                    'service' => 'pppoe', 
                    'status' => 'Online', 
                    'uptime_raw' => '05:23:12',
                    'uptime' => '5 jam 23 menit', 
                    'uptime_seconds' => 19392
                ],
                [
                    'username' => 'ani_speedy', 
                    'profile' => '20Mbps_Unlim', 
                    'service' => 'pppoe', 
                    'status' => 'Online', 
                    'uptime_raw' => '12:01:45',
                    'uptime' => '12 jam 1 menit', 
                    'uptime_seconds' => 43305
                ],
                [
                    'username' => 'kos_mahandraga', 
                    'profile' => '50Mbps_VVIP', 
                    'service' => 'pppoe', 
                    'status' => 'Online', 
                    'uptime_raw' => '2d02:45:00',
                    'uptime' => '2 hari 2 jam 45 menit', 
                    'uptime_seconds' => 182700
                ],
                [
                    'username' => 'joko_susanto', 
                    'profile' => '10Mbps_Unlim', 
                    'service' => 'pppoe', 
                    'status' => 'Offline', 
                    'uptime_raw' => '-',
                    'uptime' => '-', 
                    'uptime_seconds' => 0
                ],
                [
                    'username' => 'reza_gaming', 
                    'profile' => '30Mbps_Home', 
                    'service' => 'pppoe', 
                    'status' => 'Offline', 
                    'uptime_raw' => '-',
                    'uptime' => '-', 
                    'uptime_seconds' => 0
                ],
            ]
        ], 200);
    }
}

public function getSystemLogs()
{
    try {
        // 1. Konek ke MikroTik pake fungsi andalan lo
        $client = $this->connectMikrotik();

        // 2. Query untuk mengambil 10 baris log sistem terbaru
        $query = new \RouterOS\Query('/log/print');
        // Kita batasi ambil data dari belakang biar dapet yang paling baru
        $response = $client->query($query)->read();
        
        // Ambil 10 log terakhir dan balik urutannya biar yang terbaru di atas
        $latestLogs = array_slice(array_reverse($response), 0, 10);

        $logList = [];
        foreach ($latestLogs as $log) {
            $logList[] = [
                'time'    => $log['time'] ?? '-',
                'topics'  => $log['topics'] ?? 'info',
                'message' => $log['message'] ?? '-',
            ];
        }

        return response()->json([
            'status' => 'success',
            'total_logs' => count($logList),
            'data' => $logList
        ], 200);

    } catch (\Exception $e) {
        // 3. MODE SIMULATOR (DUMMY): Biar tetep bisa ngoding tampilan log malam ini
        return response()->json([
            'status' => 'success',
            'message' => 'Menggunakan Mode Simulator Log Sistem.',
            'total_logs' => 4,
            'data' => [
                ['time' => '18:30:02', 'topics' => 'ppp,info', 'message' => 'PPPoE user <budi_net> logged in'],
                ['time' => '18:25:14', 'topics' => 'system,info', 'message' => 'device changed by admin via winbox'],
                ['time' => '18:12:40', 'topics' => 'ppp,warning', 'message' => 'PPPoE user <reza_gaming> authentication failed: password wrong'],
                ['time' => '18:00:01', 'topics' => 'script,info', 'message' => 'Backup database automated success'],
            ]
        ], 200);
    }
}

private function parseMikrotikUptime($uptime)
{
    if (!$uptime || $uptime === '-') {
        return ['readable' => '-', 'seconds' => 0];
    }

    // Inisialisasi angka awal
    $days = 0; $hours = 0; $minutes = 0; $seconds = 0;

    // Regex sakti buat nangkep format: w(week), d(day), h(hour), m(minute), s(second)
    if (preg_match('/(?:(\d+)w)?(?:(\d+)d)?(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?/', $uptime, $matches)) {
        // Cari posisi kecocokan string MikroTik
        // urutan regex: 1=w, 2=d, 3=h, 4=m, 5=s
        $weeks   = !empty($matches[1]) ? (int)$matches[1] : 0;
        $days    = !empty($matches[2]) ? (int)$matches[2] : 0;
        $hours   = !empty($matches[3]) ? (int)$matches[3] : 0;
        $minutes = !empty($matches[4]) ? (int)$matches[4] : 0;
        $seconds = !empty($matches[5]) ? (int)$matches[5] : 0;

        // Gabungkan minggu ke hari kalau ada
        $days += $weeks * 7;
    }

    // Hitung total detik murni (sangat berguna buat anak frontend)
    $totalSeconds = ($days * 86400) + ($hours * 3600) + ($minutes * 60) + $seconds;

    // Bikin format teks Indonesia yang rapi dan enak dibaca di UI Mobile
    $readableParts = [];
    if ($days > 0) $readableParts[] = "{$days} hari";
    if ($hours > 0) $readableParts[] = "{$hours} jam";
    if ($minutes > 0) $readableParts[] = "{$minutes} menit";
    if ($seconds > 0 && $days == 0) $readableParts[] = "{$seconds} detik"; // detik muncul kalau belum hitungan hari

    $readableText = count($readableParts) > 0 ? implode(' ', $readableParts) : '0 detik';

    return [
        'readable' => $readableText,
        'seconds'  => $totalSeconds
    ];
}

}