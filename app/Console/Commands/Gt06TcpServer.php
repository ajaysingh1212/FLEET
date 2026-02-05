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
    protected $description = 'GT06 / PT06 TCP GPS Server';

    public function handle()
    {
        set_time_limit(0);
        error_reporting(E_ALL);

        $host = '0.0.0.0';
        $port = (int)$this->option('port');

        $master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($master, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_bind($master, $host, $port);
        socket_listen($master);

        $this->info("🚀 GT06 TCP Server running on port {$port}");

        while (true) {

            $client = socket_accept($master);
            socket_getpeername($client, $ip);

            $bin = socket_read($client, 2048, PHP_BINARY_READ);
            if (!$bin) {
                socket_close($client);
                continue;
            }

            $hex = strtoupper(bin2hex($bin));

            // Header check
            if (substr($hex, 0, 4) !== '7878') {
                socket_close($client);
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
                    $this->error("❌ Invalid IMEI");
                    socket_close($client);
                    continue;
                }

                Device::firstOrCreate(['imei' => $imei]);

                $this->info("📱 LOGIN | IMEI: {$imei}");

                // LOGIN ACK
                socket_write($client, hex2bin('787805010001D9DC0D0A'));
                socket_close($client);
                continue;
            }

            /* =====================================================
             * HEARTBEAT (0x13)
             * ===================================================== */
            if ($protocol === '13') {

                $imei = $this->extractImeiFromPacket($hex);
                if (!$imei) {
                    socket_close($client);
                    continue;
                }

                $this->line("❤️ HEARTBEAT | {$imei}");

                socket_write($client, hex2bin('787805130001D9DC0D0A'));
                socket_close($client);
                continue;
            }

            /* =====================================================
             * LOCATION PACKET (0x22)
             * ===================================================== */
            if ($protocol === '22') {

                $imei = $this->extractImeiFromPacket($hex);
                if (!$imei) {
                    socket_close($client);
                    continue;
                }

                $timeUtc = $this->decodeDateTime(substr($hex, 8, 12));
                $lat     = $this->decodeCoord(substr($hex, 20, 8));
                $lon     = $this->decodeCoord(substr($hex, 28, 8));
                $speed   = hexdec(substr($hex, 36, 2));

                $courseStatus = hexdec(substr($hex, 38, 4));
                $course   = $courseStatus & 0x03FF;
                $ignition = ($courseStatus & 0x0400) !== 0;
                $gpsValid = ($courseStatus & 0x8000) !== 0;

                if (!$gpsValid) {
                    socket_close($client);
                    continue;
                }

                if ($speed > 180) $speed = 0;

                $trackedAt = Carbon::parse($timeUtc);

                /* -------- Save History -------- */
                DeviceLocation::create([
                    'imei'       => $imei,
                    'tracked_at' => $trackedAt,
                    'latitude'   => $lat,
                    'longitude'  => $lon,
                    'speed'      => $speed,
                    'course'     => $course,
                    'ignition'   => $ignition,
                    'gps_valid'  => true,
                ]);

                /* -------- Update Live Location -------- */
                LiveLocation::updateOrCreate(
                    ['imei' => $imei],
                    [
                        'latitude'   => $lat,
                        'longitude'  => $lon,
                        'speed'      => $speed,
                        'course'     => $course,
                        'ignition'   => $ignition,
                        'gps_valid'  => true,
                        'tracked_at' => $trackedAt,
                    ]
                );

                $this->line("📍 {$imei} | {$lat},{$lon} | {$speed} km/h");

                // LOCATION ACK
                socket_write($client, hex2bin('787805220001D9DC0D0A'));
                socket_close($client);
                continue;
            }

            /* =====================================================
             * ALARM (0x26)
             * ===================================================== */
            if ($protocol === '26') {
                $imei = $this->extractImeiFromPacket($hex);
                $this->error("🚨 ALARM | {$imei}");
            }

            socket_close($client);
        }
    }

    /* =====================================================
     * HELPERS
     * ===================================================== */

    private function decodeImeiBCD(string $hex): ?string
    {
        $imei = '';
        for ($i = 0; $i < strlen($hex); $i += 2) {
            $imei .= str_pad(hexdec(substr($hex, $i, 2)), 2, '0', STR_PAD_LEFT);
        }
        $imei = substr($imei, 0, 15);
        return strlen($imei) === 15 ? $imei : null;
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

    /**
     * Extract IMEI safely from GT06 packet
     */
    private function extractImeiFromPacket(string $hex): ?string
    {
        $pos = strpos($hex, '0D0A');
        if ($pos === false) return null;

        $imeiHex = substr($hex, $pos - 18, 16);
        return $this->decodeImeiBCD($imeiHex);
    }
}
