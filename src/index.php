<?php
function createPesapalOrder($token, $orderData) {
    $url = 'https://pay.pesapal.com/v3/api/Transactions/SubmitOrderRequest';
    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json'
    ];

    $body = json_encode($orderData);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

// Example usage
$token = $_ENV['PESAPAL_TOKEN']; // Or call getPesapalToken()
$order = [
    'id' => uniqid(),
    'currency' => 'KES',
    'amount' => 1000,
    'description' => 'Student Premium Plan',
    'callback_url' => 'https://yourdomain.com/ipn',
    'notification_id' => $_ENV['PESAPAL_NOTIFICATION_ID'],
    'billing_address' => [
        'email_address' => 'student@example.com',
        'phone_number' => '0712345678',
        'first_name' => 'Ian',
        'last_name' => 'Mwangi'
    ]
];

$response = createPesapalOrder($token, $order);
echo json_encode($response);
