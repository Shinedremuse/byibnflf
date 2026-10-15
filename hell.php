<?php
// Конфиг
$server_url = 'http://93.152.212.127:8080/receive';
$message = 'Hello';

// Вариант 1: через CURL (рекомендуется)
function sendViaCurl($url, $msg) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['message' => $msg]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        echo "CURL Error: " . curl_error($ch) . "n";
    } else {
        echo "Status: $http_code | Response: $responsen";
    }
    curl_close($ch);
}

// Вариант 2: через file_get_contents
function sendViaStream($url, $msg) {
    $data = json_encode(['message' => $msg]);
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/jsonrn" .
                       "Content-Length: " . strlen($data) . "rn",
            'content' => $data,
            'timeout' => 5
        ]
    ];
    
    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        echo "Error: Could not connect to servern";
    } else {
        echo "Response: $responsen";
    }
}

// Запуск (выбрать один способ)
echo "[PHP] Sending to $server_urln";
sendViaCurl($server_url, $message);
// sendViaStream($server_url, $message);

?>
