<?php
if (!defined('APP_ENVIROMENT')) {
    define('APP_ENVIROMENT', 'live'); // sandbox or live
}

function getAccessToken() {
    if(APP_ENVIROMENT == 'sandbox'){
        $apiUrl = "https://cybqa.pesapal.com/pesapalv3/api/Auth/RequestToken"; // Sandbox URL
        $consumerKey = "qkio1BGGYAXTu2JOfm7XSXNruoZsrqEW";
        $consumerSecret = "osGQ364R49cXKeOYSpaOnT++rHs=";
    }elseif(APP_ENVIROMENT == 'live'){
        $apiUrl = "https://pay.pesapal.com/v3/api/Auth/RequestToken"; // Live URL
        $consumerKey = "Xoo2yQc5VCg++LH5uOhlvrvmv4CsfYs1";
        $consumerSecret = "puDJIjs7Uo1BZQ3o6gGuHNmhRUk=";
    }else{
        throw new Exception("Invalid APP_ENVIROMENT");
    }

    $headers = [
        "Accept: application/json",
        "Content-Type: application/json"
    ];
    $data = [
        "consumer_key" => $consumerKey,
        "consumer_secret" => $consumerSecret
    ];

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception("Failed to get access token. HTTP Code: " . $httpCode);
    }

    $data = json_decode($response);
    if (!$data || !isset($data->token)) {
        throw new Exception("Invalid response from Pesapal: " . $response);
    }

    return $data->token;
}

// Only return JSON response if this file is called directly
if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) {
    try {
        $token = getAccessToken();
        header('Content-Type: application/json');
        echo json_encode(['token' => $token]);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
} 

