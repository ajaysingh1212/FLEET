<?php
set_time_limit(0);
error_reporting(E_ALL);

$host = "0.0.0.0";
$port = 5023;

echo "GT06 TCP Server started on $port\n";

$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1);
socket_bind($sock, $host, $port);
socket_listen($sock);

function hex($data) {
    return strtoupper(bin2hex($data));
}

function decodeImei($hex) {
    $imeiHex = substr($hex, 8, 14);
    return ltrim($imeiHex, '0');
}

while (true) {

    $client = socket_accept($sock);
    echo "Client connected\n";

    while (true) {

        $data = socket_read($client, 2048, PHP_BINARY_READ);
        if ($data === false || $data === '') break;

        $hex = hex($data);
        echo "RAW HEX: $hex\n";

        // Check GT06 header
        if (substr($hex, 0, 4) !== '7878') continue;

        $protocol = substr($hex, 6, 2);

        /* -------- LOGIN -------- */
        if ($protocol === '01') {

            $imei = decodeImei($hex);
            echo "IMEI: $imei\n";

            // LOGIN ACK
            $ack = hex2bin("787805010001D9DC0D0A");
            socket_write($client, $ack);
        }

        /* -------- HEARTBEAT -------- */
        if ($protocol === '13') {
            $ack = hex2bin("787805130001D9DC0D0A");
            socket_write($client, $ack);
        }

        /* -------- LOCATION -------- */
        if ($protocol === '12') {
            echo "LOCATION PACKET RECEIVED\n";
            // (next step: latitude / longitude decode)
        }
    }

    socket_close($client);
}
