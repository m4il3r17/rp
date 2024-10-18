<?php
set_time_limit(0);
error_reporting(E_ALL);

$address = '0.0.0.0';
$port = 1200;

// Create a TCP Stream socket
$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if ($sock === false) {
    $errorcode = socket_last_error();
    $errormsg = socket_strerror($errorcode);
    die("Could not create socket: [$errorcode] $errormsg \n");
}

// Bind the socket to an address/port
if (socket_bind($sock, $address, $port) === false) {
    $errorcode = socket_last_error($sock);
    $errormsg = socket_strerror($errorcode);
    die("Could not bind socket: [$errorcode] $errormsg \n");
}

// Start listening for connections
if (socket_listen($sock, 5) === false) {
    $errorcode = socket_last_error($sock);
    $errormsg = socket_strerror($errorcode);
    die("Could not listen on socket: [$errorcode] $errormsg \n");
}

echo "SOCKS5 server listening on $address:$port\n";

while (true) {
    $client = socket_accept($sock);
    if ($client === false) {
        $errorcode = socket_last_error($sock);
        $errormsg = socket_strerror($errorcode);
        echo "socket_accept() failed: [$errorcode] $errormsg \n";
        continue;
    }

    // Handle each client in a separate function
    handle_client($client);
}

socket_close($sock);

// Function to handle client connection
function handle_client($client)
{
    // Set a timeout for reading from the client
    socket_set_option($client, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 60, 'usec' => 0]);

    // SOCKS5 Handshake
    $data = socket_read($client, 2);
    if (!$data || strlen($data) != 2) {
        socket_close($client);
        return;
    }
    $version = ord($data[0]);
    $nmethods = ord($data[1]);
    $methods = socket_read($client, $nmethods);
    // Choose method 0x00: No authentication required
    socket_write($client, "\x05\x00");

    // SOCKS5 Request
    $header = socket_read($client, 4);
    if (!$header || strlen($header) != 4) {
        socket_close($client);
        return;
    }
    $version = ord($header[0]);
    $cmd = ord($header[1]);
    $atype = ord($header[3]);

    // Read destination address and port
    if ($atype == 1) { // IPv4
        $addr_data = socket_read($client, 4);
        if (strlen($addr_data) != 4) {
            socket_close($client);
            return;
        }
        $address = inet_ntop($addr_data);
    } elseif ($atype == 3) { // Domain name
        $len = ord(socket_read($client, 1));
        $addr_data = socket_read($client, $len);
        if (strlen($addr_data) != $len) {
            socket_close($client);
            return;
        }
        $address = $addr_data;
    } else {
        // Send 'Address Type Not Supported' reply
        socket_write($client, "\x05\x08\x00\x01\x00\x00\x00\x00\x00\x00");
        socket_close($client);
        return;
    }
    $port_data = socket_read($client, 2);
    if (strlen($port_data) != 2) {
        socket_close($client);
        return;
    }
    $dest_port = unpack('n', $port_data)[1];

    // Connect to the destination server
    $remote = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if (!@socket_connect($remote, $address, $dest_port)) {
        // Send 'Connection Refused' reply
        socket_write($client, "\x05\x05\x00\x01\x00\x00\x00\x00\x00\x00");
        socket_close($client);
        socket_close($remote);
        return;
    }

    // Send 'Succeeded' reply
    socket_write($client, "\x05\x00\x00\x01\x00\x00\x00\x00\x00\x00");

    // Relay data between client and remote server
    socket_set_nonblock($client);
    socket_set_nonblock($remote);

    $sockets = [$client, $remote];
    while (true) {
        $read = $sockets;
        $write = null;
        $except = null;
        $num_changed = socket_select($read, $write, $except, null);
        if ($num_changed === false) {
            echo "socket_select() failed: " . socket_strerror(socket_last_error()) . "\n";
            break;
        }
        foreach ($read as $sock) {
            $data = @socket_read($sock, 8192, PHP_BINARY_READ);
            if ($data === false || strlen($data) === 0) {
                // Connection closed or error
                break 2;
            }
            $other_sock = ($sock === $client) ? $remote : $client;
            socket_write($other_sock, $data);
        }
    }
    socket_close($client);
    socket_close($remote);
}
?>
