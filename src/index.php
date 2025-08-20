<?php
namespace Pesapal;

class Token {
    private $context;
    private $consumerKey;
    private $consumerSecret;
    private $baseUrl;
    
    public function __construct($context) {
        $this->context = $context;
        $this->consumerKey = getenv('PESAPAL_CONSUMER_KEY');
        $this->consumerSecret = getenv('PESAPAL_CONSUMER_SECRET');
        $this->baseUrl = getenv('PESAPAL_BASE_URL') ?: 'https://pay.pesapal.com/v3/api';
        
        if (!$this->consumerKey || !$this->consumerSecret) {
            throw new Exception('Pesapal credentials not configured');
        }
    }
    
    /**
     * Get valid access token (from cache or generate new)
     */
    public function getAccessToken() {
        // Try to get cached token first
        $cachedToken = $this->getCachedToken();
        
        if ($cachedToken && !$this->isTokenExpired($cachedToken)) {
            return $cachedToken['access_token'];
        }
        
        // Generate new token
        return $this->generateNewToken();
    }
    
    /**
     * Generate new access token from Pesapal
     */
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
    
    /**
     * Cache token with expiry
     */
    private function cacheToken($tokenData) {
        $tokenData['cached_at'] = time();
        // Store in a simple file cache or Appwrite KV if available
        file_put_contents('/tmp/pesapal_token.json', json_encode($tokenData));
    }
    
    /**
     * Get cached token
     */
    private function getCachedToken() {
        if (file_exists('/tmp/pesapal_token.json')) {
            return json_decode(file_get_contents('/tmp/pesapal_token.json'), true);
        }
        return null;
    }
    
    /**
     * Check if token is expired
     */
    private function isTokenExpired($tokenData) {
        $expiresIn = $tokenData['expires_in'] ?? 3600; // Default 1 hour
        $cachedAt = $tokenData['cached_at'] ?? 0;
        
        return (time() - $cachedAt) >= ($expiresIn - 300); // Refresh 5 mins early
    }
    
    /**
     * Make HTTP request helper
     */
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