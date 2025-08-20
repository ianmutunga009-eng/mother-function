<?php
function getPesapalToken($consumerKey, $consumerSecret) {
    $url = 'https://pay.pesapal.com/v3/api/Auth/RequestToken';
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    $body = json_encode([
        'consumer_key' => $consumerKey,
        'consumer_secret' => $consumerSecret
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return $data['token'] ?? null;
}

// Usage
$token = getPesapalToken($_ENV['PESAPAL_KEY'], $_ENV['PESAPAL_SECRET']);
echo json_encode(['token' => $token]);
