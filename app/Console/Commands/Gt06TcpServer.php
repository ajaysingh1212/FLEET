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
    protected $description = 'GT06 / PT06 TCP GPS Server (Production Safe)';

    public function handle()
    {
        set_time_limit(0);
        error_reporting(E_ALL);

        $host = '0.0.0.0';
        $port = (int)$this->option('port');

        $master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        socket_set_option($master, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_set_option($master, SOL_SOCKET, SO_KEEPALIVE, 1);

        socket_bind($master, $host, $port);
        socket_listen($master, 100);

        $this->info("🚀 GT06 TCP Server listening on {$host}:{$port}");

        while (true) {
            $client = @socket_accept($master);
            if (!$client) {
                usleep(100000);
                continue;
            }

            socket_set_option($client, SOL_SOCKET, SO_KEEPALIVE, 1);
            socket_set_option($client, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 30, 'usec' => 0]);

            socket_getpeername($client, $ip);
            $this->line("🔌 Connected: {$ip}");

            while (true) {
                $bin = @socket_read($client, 2048, PHP_BINARY_READ);

                if ($bin === false || $bin === '') {
                    $this->line("❌ Disconnected: {$ip}");
                    break;
                }

                $hex = strtoupper(bin2hex($bin));
                if (substr($hex, 0, 4) !== '7878') continue;

                $protocol = substr($hex, 6, 2);

                /* ================= LOGIN ================= */
                if ($protocol === '01') {
                    $imei = $this->decodeImeiBCD(substr($hex, 8, 16));
                    if ($imei) {
                        Device::firstOrCreate(['imei' => $imei]);
                        $this->info("📱 LOGIN | {$imei}");
                        socket_write($client, hex2bin('787805010001D9DC0D0A'));
                    }
                }

                /* ================= HEARTBEAT ================= */
                elseif ($protocol === '13') {
                    $imei = $this->extractImeiFromPacket($hex);
                    $this->line("❤️ HEARTBEAT | {$imei}");
                    socket_write($client, hex2bin('787805130001D9DC0D0A'));
                }

                /* ================= LOCATION ================= */
                elseif ($protocol === '22') {
                    $imei = $this->extractImeiFromPacket($hex);
                    if (!$imei) continue;

                    $trackedAt = Carbon::parse($this->decodeDateTime(substr($hex, 8, 12)));
                    $lat   = $this->decodeCoord(substr($hex, 20, 8));
                    $lon   = $this->decodeCoord(substr($hex, 28, 8));
                    $speed = hexdec(substr($hex, 36, 2));

                    $courseStatus = hexdec(substr($hex, 38, 4));
                    $course   = $courseStatus & 0x03FF;
                    $ignition = ($courseStatus & 0x0400) !== 0;
                    $gpsValid = ($courseStatus & 0x8000) !== 0;

                    if ($gpsValid) {
                        DeviceLocation::create([
                            'imei' => $imei,
                            'tracked_at' => $trackedAt,
                            'latitude' => $lat,
                            'longitude' => $lon,
                            'speed' => $speed,
                            'course' => $course,
                            'ignition' => $ignition,
                            'gps_valid' => true,
                        ]);

                        LiveLocation::updateOrCreate(
                            ['imei' => $imei],
                            [
                                'latitude' => $lat,
                                'longitude' => $lon,
                                'speed' => $speed,
                                'course' => $course,
                                'ignition' => $ignition,
                                'gps_valid' => true,
                                'tracked_at' => $trackedAt,
                            ]
                        );

                        $this->line("📍 {$imei} | {$lat},{$lon} | {$speed} km/h");
                        socket_write($client, hex2bin('787805220001D9DC0D0A'));
                    }
                }
            }

            socket_close($client);
        }
    }

    /* ================= HELPERS ================= */

    private function decodeImeiBCD(string $hex): ?string
    {
        $imei = '';
        for ($i = 0; $i < strlen($hex); $i += 2) {
            $imei .= str_pad(hexdec(substr($hex, $i, 2)), 2, '0', STR_PAD_LEFT);
        }
        return strlen($imei) >= 15 ? substr($imei, 0, 15) : null;
    }

    private function decodeCoord(string $hex): float
    {
        return round((hexdec($hex) / 30000) / 60, 6);
    }

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

    private function extractImeiFromPacket(string $hex): ?string
    {
        $pos = strpos($hex, '0D0A');
        if ($pos === false) return null;
        return $this->decodeImeiBCD(substr($hex, $pos - 18, 16));
    }
}
