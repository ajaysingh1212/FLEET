<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Device;
use App\Models\DeviceLocation;
use App\Models\LiveLocation;
use Carbon\Carbon;

class Gt06TcpServer extends Command
{
    protected $signature = 'gt06:listen {--port=5023}';
    protected $description = 'GT06 / PT06 TCP GPS Server (Android Compatible)';

    /**
     * Android app har packet pe naya socket banata hai
     * isliye IP => IMEI map rakhna zaroori hai
     */
    protected array $ipImei = [];

    public function handle()
    {
        set_time_limit(0);
        error_reporting(E_ALL);

        $host = '0.0.0.0';
        $port = (int) $this->option('port');

        /* ---------- CREATE MASTER SOCKET ---------- */
        $master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($master, SOL_SOCKET, SO_REUSEADDR, 1);

        if (!socket_bind($master, $host, $port)) {
            $this->error("❌ Cannot bind on port $port");
            return;
        }

        socket_listen($master);
        $this->info("🚀 GT06 TCP Server started on port $port");

        /* ---------- MAIN LOOP ---------- */
        while (true) {

            $client = socket_accept($master);
            if ($client === false) {
                continue;
            }

            socket_getpeername($client, $ip);
            $bin = socket_read($client, 2048, PHP_BINARY_READ);
            socket_close($client);

            if (!$bin) {
                continue;
            }

            $hex = strtoupper(bin2hex($bin));

            // GT06 header check
            if (substr($hex, 0, 4) !== '7878') {
                continue;
            }

            $protocol = substr($hex, 6, 2);

            /* =====================================================
             * LOGIN PACKET (0x01)
             * ===================================================== */
            if ($protocol === '01') {

                $imeiHex = substr($hex, 8, 16);
                $imei = $this->decodeImeiBCD($imeiHex);

                if (!$imei) {
                    $this->error("❌ INVALID IMEI FROM IP: $ip");
                    continue;
                }

                $this->ipImei[$ip] = $imei;

                Device::firstOrCreate(['imei' => $imei]);

                $this->info("📱 LOGIN PACKET");
                $this->line("IMEI : $imei");

                continue;
            }

            /* ---------- IMEI REQUIRED AFTER LOGIN ---------- */
            $imei = $this->ipImei[$ip] ?? null;
            if (!$imei) {
                $this->warn("⚠ PACKET BEFORE LOGIN FROM IP: $ip");
                continue;
            }

            /* =====================================================
             * HEARTBEAT (0x13)
             * ===================================================== */
            if ($protocol === '13') {
                $this->line("❤️ HEARTBEAT | IMEI: $imei");
                continue;
            }

            /* =====================================================
             * LOCATION PACKET (0x22)
             * ===================================================== */
            if ($protocol === '22') {

                $timeUtc = $this->decodeDateTime(substr($hex, 8, 12));
                $lat     = $this->decodeCoord(substr($hex, 20, 8));
                $lon     = $this->decodeCoord(substr($hex, 28, 8));
                $speed   = hexdec(substr($hex, 36, 2));

                $cs = hexdec(substr($hex, 38, 4));
                $course   = $cs & 0x03FF;
                $ignition = ($cs & 0x0400) ? true : false;
                $gpsValid = ($cs & 0x8000) ? true : false;

                /* ---------- CONSOLE OUTPUT ---------- */
                $this->line("📍 LOCATION DATA");
                $this->line("IMEI      : $imei");
                $this->line("TIME      : $timeUtc");
                $this->line("LAT       : $lat");
                $this->line("LON       : $lon");
                $this->line("SPEED     : $speed km/h");
                $this->line("COURSE    : {$course}°");
                $this->line("IGNITION  : " . ($ignition ? 'ON' : 'OFF'));
                $this->line("GPS       : " . ($gpsValid ? 'VALID' : 'INVALID'));
                $this->line("MAP       : https://maps.google.com/?q=$lat,$lon");

                /* ---------- SAVE HISTORY (MySQL) ---------- */
                DeviceLocation::create([
                    'imei'       => $imei,
                    'tracked_at' => Carbon::parse($timeUtc),
                    'latitude'   => $lat,
                    'longitude'  => $lon,
                    'speed'      => $speed,
                    'course'     => $course,
                    'ignition'   => $ignition,
                    'gps_valid'  => $gpsValid,
                ]);

                /* ---------- SAVE LIVE LOCATION (SQLite) ---------- */
                LiveLocation::updateOrCreate(
                    ['imei' => $imei],
                    [
                        'latitude'   => $lat,
                        'longitude'  => $lon,
                        'speed'      => $speed,
                        'course'     => $course,
                        'ignition'   => $ignition,
                        'gps_valid'  => $gpsValid,
                        'tracked_at' => Carbon::parse($timeUtc),
                    ]
                );

                continue;
            }

            /* =====================================================
             * ALARM (0x26)
             * ===================================================== */
            if ($protocol === '26') {
                $this->error("🚨 ALARM RECEIVED | IMEI: $imei");
            }
        }
    }

    /* =====================================================
     * HELPERS
     * ===================================================== */

    /**
     * Decode BCD IMEI
     * Example: 0868720064174687 → 868720064174687
     */
    private function decodeImeiBCD(string $hex): ?string
    {
        $imei = '';
        for ($i = 0; $i < strlen($hex); $i += 2) {
            $imei .= str_pad(hexdec(substr($hex, $i, 2)), 2, '0', STR_PAD_LEFT);
        }

        $imei = substr($imei, 0, 15);
        return strlen($imei) === 15 ? $imei : null;
    }

    /**
     * GT06 coordinate decode
     */
    private function decodeCoord(string $hex): float
    {
        return round((hexdec($hex) / 30000) / 60, 6);
    }

    /**
     * Decode UTC datetime
     */
    private function decodeDateTime(string $hex): string
    {
        return sprintf(
            "20%02d-%02d-%02d %02d:%02d:%02d UTC",
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
            hexdec(substr($hex, 6, 2)),
            hexdec(substr($hex, 8, 2)),
            hexdec(substr($hex, 10, 2))
        );
    }
}
