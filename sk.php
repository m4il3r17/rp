<?php
set_time_limit(0);
error_reporting(E_ALL);

$address = '0.0.0.0';
$port = 1200;

function log_message($message) {
    echo date('[Y-m-d H:i:s] ') . $message . PHP_EOL;
}

// Check if pcntl extension is loaded
if (!extension_loaded('pcntl')) {
    die("pcntl extension is not loaded. Please enable it in your php.ini.\n");
}

// Create a TCP Stream socket
$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if ($sock === false) {
    die("Could not create socket: " . socket_strerror(socket_last_error()) . "\n");
}

if (socket_bind($sock, $address, $port) === false) {
    die("Could not bind socket: " . socket_strerror(socket_last_error($sock)) . "\n");
}

if (socket_listen($sock, 5) === false) {
    die("Could not listen on socket: " . socket_strerror(socket_last_error($sock)) . "\n");
}

log_message("SOCKS5 server listening on $address:$port");

// Handle SIGCHLD to prevent zombie processes
pcntl_signal(SIGCHLD, function($signo) {
    while (pcntl_waitpid(-1, $status, WNOHANG) > 0);
});

while (true) {
    $client = @socket_accept($sock);
    if ($client === false) {
        usleep(100);
        continue;
    }

    $pid = pcntl_fork();
    if ($pid == -1) {
        log_message("Could not fork process");
        socket_close($client);
        continue;
    } elseif ($pid === 0) {
        // Child process
        socket_close($sock); // Close the server socket in the child
        handle_client($client);
        socket_close($client);
        exit(0);
    } else {
        // Parent process
        socket_close($client); // Close the client socket in the parent
    }
}

socket_close($sock);

function handle_client($client) {
    log_message("Client connected");

    // SOCKS5 Handshake
    $data = socket_read($client, 2);
    if (!$data || strlen($data) != 2) {
        log_message("Invalid handshake from client");
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
        log_message("Failed to read request header");
        return;
    }

    $version = ord($header[0]);
    $cmd = ord($header[1]);
    $rsv = ord($header[2]);
    $atype = ord($header[3]);

    if ($cmd != 1) { // Only support CONNECT
        socket_write($client, "\x05\x07\x00\x01\x00\x00\x00\x00\x00\x00"); // Command not supported
        log_message("Unsupported command: $cmd");
        return;
    }

    // Address parsing
    if ($atype == 1) { // IPv4
        $addr_data = socket_read($client, 4);
        $address = inet_ntop($addr_data);
    } elseif ($atype == 3) { // Domain name
        $len = ord(socket_read($client, 1));
        $addr_data = socket_read($client, $len);
        $address = $addr_data;
    } elseif ($atype == 4) { // IPv6
        $addr_data = socket_read($client, 16);
        $address = inet_ntop($addr_data);
    } else {
        socket_write($client, "\x05\x08\x00\x01\x00\x00\x00\x00\x00\x00"); // Address type not supported
        log_message("Address type not supported: $atype");
        return;
    }

    $port_data = socket_read($client, 2);
    $dest_port = unpack('n', $port_data)[1];
    log_message("Request to connect to $address:$dest_port");

    // Resolve domain names if necessary
    if ($atype == 3) {
        $ip = gethostbyname($address);
        if ($ip == $address) {
            log_message("DNS resolution failed for $address");
            socket_write($client, "\x05\x04\x00\x01\x00\x00\x00\x00\x00\x00"); // Host unreachable
            return;
        }
        $address = $ip;
    }

    // Connect to the destination server
    $remote = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if ($remote === false) {
        log_message("Failed to create remote socket: " . socket_strerror(socket_last_error()));
        return;
    }

    if (@socket_connect($remote, $address, $dest_port) === false) {
        $err = socket_last_error($remote);
        log_message("Failed to connect to $address:$dest_port - " . socket_strerror($err));
        socket_write($client, "\x05\x05\x00\x01\x00\x00\x00\x00\x00\x00"); // Connection refused
        socket_close($remote);
        return;
    }

    socket_write($client, "\x05\x00\x00\x01\x00\x00\x00\x00\x00\x00"); // Connection succeeded
    log_message("Connected to $address:$dest_port");

    // Relay data
    socket_set_nonblock($client);
    socket_set_nonblock($remote);

    $sockets = [$client, $remote];
    while (true) {
        $read = $sockets;
        $write = null;
        $except = null;

        if (socket_select($read, $write, $except, null) < 1) {
            usleep(100);
            continue;
        }

        foreach ($read as $sock) {
            $data = @socket_read($sock, 8192, PHP_BINARY_READ);
            if ($data === false) {
                $err = socket_last_error($sock);
                if ($err != SOCKET_EWOULDBLOCK && $err != SOCKET_EAGAIN) {
                    log_message("socket_read() failed: " . socket_strerror($err));
                    break 2;
                }
                continue;
            } elseif ($data === '') {
                log_message("Connection closed by peer");
                break 2;
            }

            $other_sock = ($sock === $client) ? $remote : $client;
            $write_result = @socket_write($other_sock, $data);
            if ($write_result === false) {
                $err = socket_last_error($other_sock);
                if ($err != SOCKET_EWOULDBLOCK && $err != SOCKET_EAGAIN) {
                    log_message("socket_write() failed: " . socket_strerror($err));
                    break 2;
                }
            }
        }
    }

    socket_close($remote);
    log_message("Closed connection to $address:$dest_port");
}
?>
