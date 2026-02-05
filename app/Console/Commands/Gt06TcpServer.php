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
    protected $description = 'GT06 TCP GPS Listener (PHP 8 Compatible)';

    /** @var array<int,array{socket:\Socket,imei:?string}> */
    private array $clients = [];

    public function handle()
    {
        set_time_limit(0);
        error_reporting(E_ALL);

        $host = '0.0.0.0';
        $port = (int) $this->option('port');

        $master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($master, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_bind($master, $host, $port);
        socket_listen($master);

        $this->info("🚀 GT06 Server listening on port {$port}");

        while (true) {

            $readSockets = [$master];
            foreach ($this->clients as $c) {
                $readSockets[] = $c['socket'];
            }

            socket_select($readSockets, $write, $except, null);

            /* ================= NEW CONNECTION ================= */
            if (in_array($master, $readSockets, true)) {

                $client = socket_accept($master);
                if ($client === false) {
                    continue;
                }

                $id = spl_object_id($client);

                $this->clients[$id] = [
                    'socket' => $client,
                    'imei'   => null,
                ];

                socket_getpeername($client, $ip);
                $this->info("🔌 Connected from {$ip}");
            }

            /* ================= READ CLIENT DATA ================= */
            foreach ($this->clients as $id => $clientData) {

                $socket = $clientData['socket'];
                if (!in_array($socket, $readSockets, true)) {
                    continue;
                }

                $bin = @socket_read($socket, 2048, PHP_BINARY_READ);
                if ($bin === false || $bin === '') {
                    socket_close($socket);
                    unset($this->clients[$id]);
                    continue;
                }

                $hex = strtoupper(bin2hex($bin));
                $this->line("RAW => $hex");

                // GT06 header
                if (substr($hex, 0, 4) !== '7878') {
                    continue;
                }

                $protocol = substr($hex, 6, 2);

                /* ================= LOGIN (0x01) ================= */
                if ($protocol === '01') {

                    $imeiHex = substr($hex, 8, 16);
                    $imei = $this->decodeImeiBCD($imeiHex);

                    if (!$imei) {
                        continue;
                    }

                    $this->clients[$id]['imei'] = $imei;
                    Device::firstOrCreate(['imei' => $imei]);

                    $this->info("📱 LOGIN | IMEI: {$imei}");

                    // ACK
                    socket_write($socket, hex2bin('787805010001D9DC0D0A'));
                    continue;
                }

                // IMEI must be known after login
                $imei = $this->clients[$id]['imei'];
                if (!$imei) {
                    continue;
                }

                /* ================= HEARTBEAT (0x13) ================= */
                if ($protocol === '13') {

                    $this->line("❤️ HEARTBEAT | {$imei}");
                    socket_write($socket, hex2bin('787805130001D9DC0D0A'));
                    continue;
                }

                /* ================= LOCATION (0x12) ================= */
                if ($protocol === '12') {

                    $timeUtc = $this->decodeDateTime(substr($hex, 8, 12));
                    $lat     = $this->decodeCoord(substr($hex, 20, 8));
                    $lon     = $this->decodeCoord(substr($hex, 28, 8));
                    $speed   = hexdec(substr($hex, 36, 2));

                    $courseStatus = hexdec(substr($hex, 38, 4));
                    $course   = $courseStatus & 0x03FF;
                    $ignition = ($courseStatus & 0x0400) !== 0;
                    $gpsValid = ($courseStatus & 0x8000) !== 0;

                    if (!$gpsValid) {
                        continue;
                    }

                    if ($speed > 180) {
                        $speed = 0;
                    }

                    $trackedAt = Carbon::parse($timeUtc);

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

                    $this->info("📍 {$imei} | {$lat},{$lon} | {$speed} km/h");

                    // ACK
                    socket_write($socket, hex2bin('787805120001D9DC0D0A'));
                }
            }
        }
    }

    /* ================= HELPER FUNCTIONS ================= */

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
}
