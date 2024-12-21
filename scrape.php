<?php

define('API_BASE_URL', 'aHR0cHM6Ly9wcHZsYW5kLmhhcmR3YXJlMDg4MC53b3JrZXJzLmRldi8/dGFyZ2V0PWh0dHBzOi8vcHB2LmxhbmQvYXBpL3N0cmVhbXM=');
define('USER_AGENT', 'Mozilla/5.0 (compatible; PHP script)');

function fetchApiData($url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => USER_AGENT,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        logError("cURL Error: " . curl_error($ch));
        curl_close($ch);
        return null;
    }

    curl_close($ch);

    if ($httpCode != 200) {
        logError("HTTP Error: $httpCode - Failed to fetch data from $url");
        return null;
    }

    // Check if the response is valid JSON
    $jsonData = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logError("Invalid JSON response from $url");
        return null;
    }

    return $jsonData;
}

function logError($message) {
    error_log($message . PHP_EOL, 3, 'errors.log');
}

function createM3UEntry($data, $category, $uriNameMap) {
    $name = isset($data['name']) ? $data['name'] : 'Unknown Name';
    $poster = isset($data['poster']) ? $data['poster'] : 'default_poster.jpg';
    $m3u8Link = isset($data['m3u8']) ? $data['m3u8'] : null;
    $vipMpegtsLink = isset($data['vip_mpegts']) && !empty($data['vip_mpegts']) ? $data['vip_mpegts'] : null;
    $streamId = $data['id'];
    $uriName = $uriNameMap[$streamId] ?? null;

    if (strpos($m3u8Link, 'https://') !== 0) $m3u8Link = null;
    if ($vipMpegtsLink && strpos($vipMpegtsLink, 'https://') !== 0) $vipMpegtsLink = null;

    if (!$m3u8Link && !$vipMpegtsLink) return '';

    $m3uContent = '';

    if ($vipMpegtsLink) {
        $vipCategory = "VIP";
        $nameWithVIP = '[VIP] ' . $name;
        $referrer = $uriName ? "https://ppv.land/live/{$uriName}?vip=true" : "https://ppv.land/";
        $vipOptions = "#EXTVLCOPT:http-user-agent=Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0\n"
            . "#EXTVLCOPT:http-referrer=$referrer\n";
        $m3uContent .= "#EXTINF:-1 tvg-logo=\"$poster\" group-title=\"$vipCategory\", $nameWithVIP\n" 
            . $vipOptions 
            . $vipMpegtsLink . "\n";
    }

    if ($m3u8Link) {
        $startTime = date('h:i A', $data['start_timestamp']) . ' (' . date('d/m/y', $data['start_timestamp']) . ')';
        $infoLine = "#EXTINF:-1 tvg-logo=\"$poster\" group-title=\"$category\", $name" . ($category !== "24/7 Streams" ? " - $startTime" : '') . "\n";
        $m3uContent .= $infoLine
            . "#EXTVLCOPT:http-user-agent=Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0\n"
            . "#EXTVLCOPT:http-referrer=https://ppv.land/\n"
            . $m3u8Link . "\n";
    }

    return $m3uContent;
}

function main() {
    date_default_timezone_set('Australia/Sydney'); // Ensure timezone is set before using dates

    $apiBaseUrl = base64_decode(API_BASE_URL);
    //echo "Decoded API URL: $apiBaseUrl\n";  // Remove debug output

    $data = fetchApiData($apiBaseUrl);
    if (!$data) {
        logError("API data is empty or invalid.");
        return;
    }

    //var_dump($data);  // Remove debug output

    if (!isset($data['streams'])) {
        logError("Missing 'streams' in API response.");
        return;
    }

    $ids = [];
    $categoryMap = [];
    $uriNameMap = [];

    foreach ($data['streams'] as $categoryGroup) {
        $category = $categoryGroup['category'];
        if ($category === "Fishtank") continue;

        foreach ($categoryGroup['streams'] as $stream) {
            if (isset($stream['id']) && strlen((string)$stream['id']) === 4) {
                $ids[] = $stream['id'];
                $categoryMap[$stream['id']] = $category;
                if (isset($stream['uri_name'])) {
                    $uriNameMap[$stream['id']] = $stream['uri_name'];
                }
            }
        }
    }

    $linkInfo = [];
    foreach ($ids as $id) {
        $streamData = fetchApiData($apiBaseUrl . "/$id");
        if ($streamData) $linkInfo[] = $streamData;
    }

    //echo "Number of streams to process: " . count($linkInfo) . "\n";  // Remove debug output

    $fileName = __DIR__ . '/streams.m3u';
    $m3uFile = fopen($fileName, 'w');
    if (!$m3uFile) {
        logError("Error opening file $fileName for writing.");
        return;
    }

    fwrite($m3uFile, "#EXTM3U\n");

    foreach ($linkInfo as $entry) {
        $data = $entry['data'];
        $id = $data['id'];
        $category = $categoryMap[$id] ?? 'Uncategorized';
        if ($category === "Fishtank") continue;

        $m3uEntry = createM3UEntry($data, $category, $uriNameMap);
        
        if ($m3uEntry) {
            //echo "Writing M3U entry for ID: $id\n";  // Remove debug output
            fwrite($m3uFile, $m3uEntry);
        } else {
            //echo "No M3U entry generated for ID: $id\n";  // Remove debug output
        }
    }

    fclose($m3uFile);
    echo "M3U file created successfully as $fileName.\n";  // Only print success message
}

main();
?>
