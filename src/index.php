<?php
require_once(__DIR__ . '/../vendor/autoload.php');
use Appwrite\Client;
use Appwrite\Services\Users;
use Appwrite\Services\Databases;

// ================================
// TOKEN MANAGEMENT CLASS
// ================================
class PesapalToken {
    private $context;
    private $consumerKey;
    private $consumerSecret;
    private $baseUrl;
    
    public function __construct($context) {
        $this->context = $context;
        $this->consumerKey = getenv('PESAPAL_CONSUMER_KEY');
        $this->consumerSecret = getenv('PESAPAL_CONSUMER_SECRET');
        $this->baseUrl = getenv('PESAPAL_BASE_URL') ?: 'https://cybqa.pesapal.com/pesapalv3/api'; // Sandbox URL
        
        if (!$this->consumerKey || !$this->consumerSecret) {
            throw new Exception('Pesapal credentials not configured');
        }
    }
    
    public function getAccessToken() {
        // Try to get cached token first
        $cachedToken = $this->getCachedToken();
        
        if ($cachedToken && !$this->isTokenExpired($cachedToken)) {
            return $cachedToken['access_token'];
        }
        
        // Generate new token
        return $this->generateNewToken();
    }
    
    private function generateNewToken() {
        $url = $this->baseUrl . '/Auth/RequestToken';
        
        $data = [
            'consumer_key' => $this->consumerKey,
            'consumer_secret' => $this->consumerSecret
        ];
        
        $response = $this->makeHttpRequest($url, 'POST', $data);
        
        if (!$response || !isset($response['access_token'])) {
            throw new Exception('Failed to generate Pesapal token');
        }
        
        // Cache the token
        $this->cacheToken($response);
        
        $this->context->log('New Pesapal token generated successfully');
        return $response['access_token'];
    }
    
    private function cacheToken($tokenData) {
        $tokenData['cached_at'] = time();
        // Store in temporary file
        $tempDir = sys_get_temp_dir();
        file_put_contents($tempDir . '/pesapal_token.json', json_encode($tokenData));
    }
    
    private function getCachedToken() {
        $tempDir = sys_get_temp_dir();
        $tokenFile = $tempDir . '/pesapal_token.json';
        
        if (file_exists($tokenFile)) {
            return json_decode(file_get_contents($tokenFile), true);
        }
        return null;
    }
    
    private function isTokenExpired($tokenData) {
        $expiresIn = $tokenData['expires_in'] ?? 3600;
        $cachedAt = $tokenData['cached_at'] ?? 0;
        
        return (time() - $cachedAt) >= ($expiresIn - 300); // Refresh 5 mins early
    }
    
    private function makeHttpRequest($url, $method = 'GET', $data = null) {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ]
        ]);
        
        if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("cURL Error: $error");
        }
        
        if ($httpCode >= 400) {
            throw new Exception("HTTP Error: $httpCode - $response");
        }
        
        return json_decode($response, true);
    }
}

// ================================
// ORDER CREATION CLASS
// ================================
class PesapalOrder {
    private $context;
    private $tokenManager;
    private $baseUrl;
    
    public function __construct($context) {
        $this->context = $context;
        $this->tokenManager = new PesapalToken($context);
        $this->baseUrl = getenv('PESAPAL_BASE_URL') ?: 'https://cybqa.pesapal.com/pesapalv3/api';
    }
    
    public function createOrder($requestData) {
        // Validate required fields
        $required = ['amount', 'currency', 'description', 'callback_url', 'notification_id'];
        foreach ($required as $field) {
            if (!isset($requestData[$field])) {
                throw new Exception("Missing required field: $field");
            }
        }
        
        // Get access token
        $accessToken = $this->tokenManager->getAccessToken();
        
        // Generate unique order ID
        $orderId = 'ORD_' . time() . '_' . substr(md5(uniqid()), 0, 8);
        
        // Prepare order data
        $orderData = [
            'id' => $orderId,
            'currency' => $requestData['currency'],
            'amount' => floatval($requestData['amount']),
            'description' => $requestData['description'],
            'callback_url' => $requestData['callback_url'],
            'notification_id' => $requestData['notification_id'],
            'billing_address' => [
                'email_address' => $requestData['email'] ?? 'test@example.com',
                'phone_number' => $requestData['phone'] ?? '',
                'country_code' => $requestData['country_code'] ?? 'KE',
                'first_name' => $requestData['first_name'] ?? 'John',
                'last_name' => $requestData['last_name'] ?? 'Doe',
                'line_1' => $requestData['address'] ?? 'Nairobi',
                'line_2' => '',
                'city' => 'Nairobi',
                'state' => 'Nairobi',
                'postal_code' => '00100',
                'zip_code' => '00100'
            ]
        ];
        
        // Submit order to Pesapal
        $url = $this->baseUrl . '/Transactions/SubmitOrderRequest';
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($orderData),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $accessToken
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("cURL Error: $error");
        }
        
        if ($httpCode !== 200) {
            throw new Exception("Order submission failed: HTTP $httpCode - $response");
        }
        
        $result = json_decode($response, true);
        
        if (!$result) {
            throw new Exception("Invalid response from Pesapal");
        }
        
        $this->context->log('Order created successfully: ' . $orderId);
        $this->context->log('Pesapal response: ' . json_encode($result));
        
        return [
            'success' => true,
            'order_id' => $orderId,
            'redirect_url' => $result['redirect_url'] ?? null,
            'order_tracking_id' => $result['order_tracking_id'] ?? null,
            'merchant_reference' => $result['merchant_reference'] ?? null,
            'status' => 'created'
        ];
    }
}

// ================================
// IPN HANDLER CLASS
// ================================
class PesapalIPNHandler {
    private $context;
    private $tokenManager;
    private $baseUrl;
    
    public function __construct($context) {
        $this->context = $context;
        $this->tokenManager = new PesapalToken($context);
        $this->baseUrl = getenv('PESAPAL_BASE_URL') ?: 'https://cybqa.pesapal.com/pesapalv3/api';
    }
    
    public function processIPN($ipnData) {
        $this->context->log('Received IPN: ' . json_encode($ipnData));
        
        // Validate IPN data
        if (!isset($ipnData['OrderTrackingId'])) {
            throw new Exception('Invalid IPN data received - missing OrderTrackingId');
        }
        
        $trackingId = $ipnData['OrderTrackingId'];
        
        // Get transaction status from Pesapal
        $transactionStatus = $this->getTransactionStatus($trackingId);
        
        $this->context->log('Transaction status: ' . json_encode($transactionStatus));
        
        // Process based on status
        $statusCode = $transactionStatus['status_code'] ?? 0;
        $statusMessage = $transactionStatus['description'] ?? 'Unknown';
        
        switch ($statusCode) {
            case 1: // Completed
                $this->handleSuccessfulPayment($transactionStatus);
                break;
            case 2: // Failed
                $this->handleFailedPayment($transactionStatus);
                break;
            case 3: // Reversed
                $this->handleReversedPayment($transactionStatus);
                break;
            default:
                $this->context->log("Unknown payment status: $statusCode - $statusMessage");
        }
        
        return [
            'success' => true,
            'status' => 'processed',
            'tracking_id' => $trackingId,
            'payment_status' => $statusMessage,
            'status_code' => $statusCode
        ];
    }
    
    private function getTransactionStatus($trackingId) {
        $accessToken = $this->tokenManager->getAccessToken();
        $url = $this->baseUrl . '/Transactions/GetTransactionStatus?orderTrackingId=' . urlencode($trackingId);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $accessToken
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("cURL Error: $error");
        }
        
        if ($httpCode !== 200) {
            throw new Exception("Failed to get transaction status: HTTP $httpCode - $response");
        }
        
        return json_decode($response, true);
    }
    
    private function handleSuccessfulPayment($transactionData) {
        $this->context->log('✅ Payment successful: ' . json_encode($transactionData));
        
        // Here you would update your database
        // For now, just log the success
        
        // Example: Update user subscription in Appwrite database
        // $this->updateSubscriptionStatus($transactionData, 'active');
    }
    
    private function handleFailedPayment($transactionData) {
        $this->context->log('❌ Payment failed: ' . json_encode($transactionData));
        
        // Handle failed payment logic
        // $this->updateSubscriptionStatus($transactionData, 'failed');
    }
    
    private function handleReversedPayment($transactionData) {
        $this->context->log('🔄 Payment reversed: ' . json_encode($transactionData));
        
        // Handle reversal logic
        // $this->updateSubscriptionStatus($transactionData, 'reversed');
    }
}

// ================================
// MAIN APPWRITE FUNCTION
// ================================
return function ($context) {
    // Initialize Appwrite client (for database operations later)
    $client = new Client();
    $client
        ->setEndpoint(getenv('APPWRITE_FUNCTION_API_ENDPOINT'))
        ->setProject(getenv('APPWRITE_FUNCTION_PROJECT_ID'))
        ->setKey($context->req->headers['x-appwrite-key'] ?? getenv('APPWRITE_FUNCTION_API_KEY'));

    // Get request details
    $method = $context->req->method;
    $path = $context->req->path ?? '/';
    $body = json_decode($context->req->body ?? '{}', true);
    
    $context->log("Request: $method $path");
    
    try {
        // Route the request
        switch ($path) {
            case '/':
            case '/ping':
                return $context->res->json([
                    'status' => 'alive',
                    'service' => 'Payment System',
                    'timestamp' => time(),
                    'endpoints' => [
                        'GET /ping' => 'Health check',
                        'POST /create-order' => 'Create payment order',
                        'POST /ipn' => 'Handle payment notifications',
                        'GET /test-token' => 'Test Pesapal token generation'
                    ]
                ]);
                
            case '/test-token':
                $tokenManager = new PesapalToken($context);
                $token = $tokenManager->getAccessToken();
                return $context->res->json([
                    'success' => true,
                    'message' => 'Token generated successfully',
                    'token_length' => strlen($token)
                ]);
                
            case '/create-order':
                if ($method !== 'POST') {
                    return $context->res->json(['error' => 'Method not allowed'], 405);
                }
                
                $orderHandler = new PesapalOrder($context);
                $result = $orderHandler->createOrder($body);
                return $context->res->json($result);
                
            case '/ipn':
                if ($method !== 'POST') {
                    return $context->res->json(['error' => 'Method not allowed'], 405);
                }
                
                $ipnHandler = new PesapalIPNHandler($context);
                $result = $ipnHandler->processIPN($body);
                return $context->res->json($result);
                
            default:
                return $context->res->json(['error' => 'Route not found'], 404);
        }
        
    } catch (Exception $e) {
        $context->error('Error: ' . $e->getMessage());
        return $context->res->json([
            'error' => $e->getMessage(),
            'success' => false
        ], 500);
    }
};