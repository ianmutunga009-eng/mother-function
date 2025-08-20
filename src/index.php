<?php
require_once(__DIR__ . '/../vendor/autoload.php');
use Appwrite\Client;
use Appwrite\Services\Users;
use Appwrite\Services\Databases;

// ================================
// ENVIRONMENT CONFIGURATION
// ================================
if (!defined('APP_ENVIRONMENT')) {
    define('APP_ENVIRONMENT', getenv('APP_ENVIRONMENT') ?: 'sandbox'); // sandbox or live
}

// ================================
// ACCESS TOKEN MANAGEMENT
// ================================
class PesapalToken {
    private $context;
    private $apiUrl;
    private $consumerKey;
    private $consumerSecret;
    
    public function __construct($context) {
        $this->context = $context;
        
        if (APP_ENVIRONMENT == 'sandbox') {
            $this->apiUrl = "https://cybqa.pesapal.com/pesapalv3/api/Auth/RequestToken";
            $this->consumerKey = "qkio1BGGYAXTu2JOfm7XSXNruoZsrqEW";
            $this->consumerSecret = "osGQ364R49cXKeOYSpaOnT++rHs=";
        } elseif (APP_ENVIRONMENT == 'live') {
            $this->apiUrl = "https://pay.pesapal.com/v3/api/Auth/RequestToken";
            $this->consumerKey = "Xoo2yQc5VCg++LH5uOhlvrvmv4CsfYs1";
            $this->consumerSecret = "puDJIjs7Uo1BZQ3o6gGuHNmhRUk=";
        } else {
            throw new Exception("Invalid APP_ENVIRONMENT");
        }
    }
    
    public function getAccessToken() {
        // Try to get cached token first
        $cachedToken = $this->getCachedToken();
        
        if ($cachedToken && !$this->isTokenExpired($cachedToken)) {
            return $cachedToken['token'];
        }
        
        // Generate new token
        return $this->generateNewToken();
    }
    
    private function generateNewToken() {
        $headers = [
            "Accept: application/json",
            "Content-Type: application/json"
        ];
        
        $data = [
            "consumer_key" => $this->consumerKey,
            "consumer_secret" => $this->consumerSecret
        ];

        $ch = curl_init($this->apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("cURL Error: " . $error);
        }

        if ($httpCode !== 200) {
            throw new Exception("Failed to get access token. HTTP Code: " . $httpCode);
        }

        $data = json_decode($response);
        if (!$data || !isset($data->token)) {
            throw new Exception("Invalid response from Pesapal: " . $response);
        }

        // Cache the token
        $this->cacheToken($data->token);
        
        $this->context->log('New Pesapal token generated successfully');
        return $data->token;
    }
    
    private function cacheToken($token) {
        $tokenData = [
            'token' => $token,
            'cached_at' => time(),
            'expires_in' => 3600 // 1 hour default
        ];
        
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
}

// ================================
// IPN REGISTRATION
// ================================
class PesapalIPNRegistration {
    private $context;
    private $tokenManager;
    private $ipnUrl;
    private $ipnRegistrationUrl;
    
    public function __construct($context) {
        $this->context = $context;
        $this->tokenManager = new PesapalToken($context);
        
        // Use the Appwrite function URL for IPN
        $this->ipnUrl = getenv('APPWRITE_FUNCTION_ENDPOINT') . "/ipn";
        
        if (APP_ENVIRONMENT == 'sandbox') {
            $this->ipnRegistrationUrl = "https://cybqa.pesapal.com/pesapalv3/api/URLSetup/RegisterIPN";
        } elseif (APP_ENVIRONMENT == 'live') {
            $this->ipnRegistrationUrl = "https://pay.pesapal.com/v3/api/URLSetup/RegisterIPN";
        } else {
            throw new Exception("Invalid APP_ENVIRONMENT");
        }
    }
    
    public function registerIPN($token) {
        $headers = [
            "Accept: application/json",
            "Content-Type: application/json",
            "Authorization: Bearer $token"
        ];

        $data = [
            "url" => $this->ipnUrl,
            "ipn_notification_type" => "POST"
        ];

        $ch = curl_init($this->ipnRegistrationUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("cURL Error: " . $error);
        }

        if ($responseCode !== 200) {
            throw new Exception("Failed to register IPN. HTTP Code: " . $responseCode);
        }

        $data = json_decode($response);
        if (!$data || !isset($data->ipn_id) || !isset($data->url)) {
            throw new Exception("Invalid IPN registration response: " . $response);
        }

        $this->context->log('IPN registered successfully: ' . $data->ipn_id);

        return [
            'ipn_id' => $data->ipn_id,
            'url' => $data->url
        ];
    }
}

// ================================
// ORDER SUBMISSION
// ================================
class PesapalOrder {
    private $context;
    private $tokenManager;
    private $ipnManager;
    private $submitOrderUrl;
    
    public function __construct($context) {
        $this->context = $context;
        $this->tokenManager = new PesapalToken($context);
        $this->ipnManager = new PesapalIPNRegistration($context);
        
        if (APP_ENVIRONMENT == 'sandbox') {
            $this->submitOrderUrl = "https://cybqa.pesapal.com/pesapalv3/api/Transactions/SubmitOrderRequest";
        } elseif (APP_ENVIRONMENT == 'live') {
            $this->submitOrderUrl = "https://pay.pesapal.com/v3/api/Transactions/SubmitOrderRequest";
        } else {
            throw new Exception("Invalid APP_ENVIRONMENT");
        }
    }
    
    public function submitOrder($orderData) {
        // Get access token
        $token = $this->tokenManager->getAccessToken();
        if (!$token) {
            throw new Exception("Failed to get access token");
        }

        // Register IPN and get IPN ID
        $ipnResult = $this->ipnManager->registerIPN($token);
        if (!isset($ipnResult['ipn_id'])) {
            throw new Exception("Failed to get IPN ID");
        }

        // Generate merchant reference
        $merchantreference = $orderData['merchant_reference'] ?? rand(1, 1000000000000000000);
        
        // Set default values or use provided ones
        $phone = $orderData['phone'] ?? "0795065125";
        $amount = $orderData['amount'] ?? 1.00;
        $callbackurl = $orderData['callback_url'] ?? getenv('APPWRITE_FUNCTION_ENDPOINT');
        $branch = $orderData['branch'] ?? "main";
        $first_name = $orderData['first_name'] ?? "Ian";
        $middle_name = $orderData['middle_name'] ?? "Munguti";
        $last_name = $orderData['last_name'] ?? "Mutunga";
        $email_address = $orderData['email'] ?? "mungutiian98@gmail.com";
        $description = $orderData['description'] ?? "Payment description goes here";
        
        $headers = [
            "Accept: application/json",
            "Content-Type: application/json",
            "Authorization: Bearer $token"
        ];

        // Request payload - matching your exact structure
        $data = [
            "id" => $merchantreference,
            "currency" => $orderData['currency'] ?? "KES",
            "amount" => floatval($amount),
            "description" => $description,
            "callback_url" => $callbackurl,
            "notification_id" => $ipnResult['ipn_id'],
            "branch" => $branch,
            "billing_address" => [
                "email_address" => $email_address,
                "phone_number" => $phone,
                "country_code" => $orderData['country_code'] ?? "KE",
                "first_name" => $first_name,
                "middle_name" => $middle_name,
                "last_name" => $last_name,
                "line_1" => $orderData['address_line_1'] ?? "Pesapal Limited",
                "line_2" => $orderData['address_line_2'] ?? "",
                "city" => $orderData['city'] ?? "",
                "state" => $orderData['state'] ?? "",
                "postal_code" => $orderData['postal_code'] ?? "",
                "zip_code" => $orderData['zip_code'] ?? ""
            ]
        ];

        $ch = curl_init($this->submitOrderUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("cURL Error: " . $error);
        }

        if ($httpCode !== 200) {
            throw new Exception("Failed to submit order. HTTP Code: " . $httpCode);
        }

        $result = json_decode($response);
        if (!$result) {
            throw new Exception("Invalid response from order submission: " . $response);
        }

        $this->context->log('Order submitted successfully: ' . $merchantreference);
        $this->context->log('Pesapal response: ' . json_encode($result));

        return [
            'success' => true,
            'order_id' => $merchantreference,
            'redirect_url' => $result->redirect_url ?? null,
            'order_tracking_id' => $result->order_tracking_id ?? null,
            'merchant_reference' => $result->merchant_reference ?? null,
            'status' => 'created',
            'ipn_id' => $ipnResult['ipn_id']
        ];
    }
}

// ================================
// TRANSACTION STATUS CHECK
// ================================
class PesapalTransactionStatus {
    private $context;
    private $tokenManager;
    private $statusUrl;
    
    public function __construct($context) {
        $this->context = $context;
        $this->tokenManager = new PesapalToken($context);
        
        if (APP_ENVIRONMENT == 'sandbox') {
            $this->statusUrl = "https://cybqa.pesapal.com/pesapalv3/api/Transactions/GetTransactionStatus";
        } elseif (APP_ENVIRONMENT == 'live') {
            $this->statusUrl = "https://pay.pesapal.com/v3/api/Transactions/GetTransactionStatus";
        } else {
            throw new Exception("Invalid APP_ENVIRONMENT");
        }
    }
    
    public function getTransactionStatus($orderTrackingId) {
        $token = $this->tokenManager->getAccessToken();
        
        $url = $this->statusUrl . "?orderTrackingId=" . urlencode($orderTrackingId);
        
        $headers = [
            "Accept: application/json",
            "Authorization: Bearer $token"
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("cURL Error: " . $error);
        }

        if ($httpCode !== 200) {
            throw new Exception("Failed to get transaction status: HTTP $httpCode - $response");
        }

        return json_decode($response, true);
    }
}

// ================================
// IPN HANDLER
// ================================
class PesapalIPNHandler {
    private $context;
    private $statusChecker;
    
    public function __construct($context) {
        $this->context = $context;
        $this->statusChecker = new PesapalTransactionStatus($context);
    }
    
    public function processIPN($ipnData) {
        $this->context->log('Received IPN: ' . json_encode($ipnData));
        
        // Validate IPN data
        if (!isset($ipnData['OrderTrackingId'])) {
            throw new Exception('Invalid IPN data received - missing OrderTrackingId');
        }
        
        $trackingId = $ipnData['OrderTrackingId'];
        
        // Get transaction status from Pesapal
        $transactionStatus = $this->statusChecker->getTransactionStatus($trackingId);
        
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
    
    private function handleSuccessfulPayment($transactionData) {
        $this->context->log('✅ Payment successful: ' . json_encode($transactionData));
        // Add your subscription/database update logic here
    }
    
    private function handleFailedPayment($transactionData) {
        $this->context->log('❌ Payment failed: ' . json_encode($transactionData));
        // Add your failed payment logic here
    }
    
    private function handleReversedPayment($transactionData) {
        $this->context->log('🔄 Payment reversed: ' . json_encode($transactionData));
        // Add your reversal logic here
    }
}

// ================================
// MAIN APPWRITE FUNCTION
// ================================
return function ($context) {
    // Initialize Appwrite client
    $client = new Client();
    $client
        ->setEndpoint(getenv('APPWRITE_FUNCTION_API_ENDPOINT'))
        ->setProject(getenv('APPWRITE_FUNCTION_PROJECT_ID'))
        ->setKey($context->req->headers['x-appwrite-key'] ?? getenv('APPWRITE_FUNCTION_API_KEY'));

    // Get request details
    $method = $context->req->method;
    $path = $context->req->path ?? '/';
    $body = json_decode($context->req->body ?? '{}', true);
    
    $context->log("Request: $method $path - Environment: " . APP_ENVIRONMENT);
    
    try {
        // Route the request
        switch ($path) {
            case '/':
            case '/ping':
                return $context->res->json([
                    'status' => 'alive',
                    'service' => 'Pesapal Payment System',
                    'environment' => APP_ENVIRONMENT,
                    'timestamp' => time(),
                    'endpoints' => [
                        'GET /ping' => 'Health check',
                        'GET /test-token' => 'Test token generation',
                        'POST /register-ipn' => 'Register IPN URL',
                        'POST /create-order' => 'Create payment order',
                        'POST /ipn' => 'Handle payment notifications',
                        'GET /transaction-status?id=XXX' => 'Check transaction status'
                    ]
                ]);
                
            case '/test-token':
                $tokenManager = new PesapalToken($context);
                $token = $tokenManager->getAccessToken();
                return $context->res->json([
                    'success' => true,
                    'message' => 'Token generated successfully',
                    'environment' => APP_ENVIRONMENT,
                    'token_length' => strlen($token)
                ]);
                
            case '/register-ipn':
                if ($method !== 'POST') {
                    return $context->res->json(['error' => 'Method not allowed'], 405);
                }
                
                $tokenManager = new PesapalToken($context);
                $ipnManager = new PesapalIPNRegistration($context);
                $token = $tokenManager->getAccessToken();
                $result = $ipnManager->registerIPN($token);
                return $context->res->json($result);
                
            case '/create-order':
                if ($method !== 'POST') {
                    return $context->res->json(['error' => 'Method not allowed'], 405);
                }
                
                $orderHandler = new PesapalOrder($context);
                $result = $orderHandler->submitOrder($body);
                return $context->res->json($result);
                
            case '/transaction-status':
                $orderTrackingId = $_GET['id'] ?? $body['orderTrackingId'] ?? null;
                if (!$orderTrackingId) {
                    return $context->res->json(['error' => 'Missing orderTrackingId parameter'], 400);
                }
                
                $statusChecker = new PesapalTransactionStatus($context);
                $result = $statusChecker->getTransactionStatus($orderTrackingId);
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