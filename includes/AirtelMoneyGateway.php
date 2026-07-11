<?php
/**
 * Airtel Money Payment Gateway Integration
 * 
 * Handles payment processing for Airtel Money mobile money service
 * Supports direct merchant payment API and USSD-based transactions
 */

class AirtelMoneyGateway {
    
    // API Configuration
    private $clientId;
    private $clientSecret;
    private $baseUrl;
    private $merchantCode;
    private $currencyCode;
    private $environment; // 'sandbox' or 'production'
    
    // Request tracking
    private $requestId;
    private $timestamp;
    
    public function __construct() {
        // Load configuration from environment or config file
        $this->clientId = getenv('AIRTEL_CLIENT_ID') ?: (defined('AIRTEL_CLIENT_ID') ? AIRTEL_CLIENT_ID : '');
        $this->clientSecret = getenv('AIRTEL_CLIENT_SECRET') ?: (defined('AIRTEL_CLIENT_SECRET') ? AIRTEL_CLIENT_SECRET : '');
        $this->merchantCode = getenv('AIRTEL_MERCHANT_CODE') ?: (defined('AIRTEL_MERCHANT_CODE') ? AIRTEL_MERCHANT_CODE : '');
        $this->currencyCode = 'ZMW'; // Zambian Kwacha
        $this->environment = getenv('AIRTEL_ENV') ?: 'sandbox';
        
        // Set base URL based on environment
        if ($this->environment === 'production') {
            $this->baseUrl = 'https://api.airtel.africa/merchant/v1';
        } else {
            $this->baseUrl = 'https://sandbox-api.airtel.africa/merchant/v1';
        }
        
        $this->requestId = $this->generateRequestId();
        $this->timestamp = date('Y-m-d H:i:s');
    }
    
    /**
     * Generate authentication token from Airtel API
     */
    public function authenticate() {
        try {
            $url = $this->baseUrl . '/authentication/token';
            
            $payload = json_encode([
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'client_credentials'
            ]);
            
            $response = $this->sendRequest('POST', $url, $payload);
            
            if ($response['success']) {
                return $response['data']['access_token'] ?? null;
            }
            
            error_log('Airtel authentication failed: ' . json_encode($response));
            return null;
            
        } catch (Exception $e) {
            error_log('Airtel auth exception: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Initiate payment transaction
     * 
     * @param string $phoneNumber Customer's Airtel Money phone number
     * @param float $amount Payment amount in ZMW
     * @param string $studentId Student ID for tracking
     * @param string $reference Unique reference for transaction
     * @param string $narration Transaction description
     * 
     * @return array Response with transaction details
     */
    public function initiatePayment($phoneNumber, $amount, $studentId, $reference, $narration) {
        try {
            $token = $this->authenticate();
            if (!$token) {
                return [
                    'success' => false,
                    'error' => 'Failed to authenticate with Airtel Money',
                    'code' => 'AUTH_FAILED'
                ];
            }
            
            $url = $this->baseUrl . '/payments/mobile/checkout';
            
            $payload = json_encode([
                'reference' => $reference,
                'subscriber' => [
                    'country' => 'ZM',
                    'currency' => $this->currencyCode,
                    'msisdn' => $this->formatPhoneNumber($phoneNumber)
                ],
                'transaction' => [
                    'amount' => (string)$amount,
                    'country' => 'ZM',
                    'currency' => $this->currencyCode,
                    'id' => $this->requestId,
                    'type' => 'MerchantPayment'
                ],
                'merchant' => [
                    'category' => 'Education',
                    'name' => 'Industrial training college',
                    'street' => 'University Campus'
                ]
            ]);
            
            $headers = [
                'Authorization: Bearer ' . $token,
                'X-Country: ZM',
                'X-Currency: ' . $this->currencyCode
            ];
            
            $response = $this->sendRequest('POST', $url, $payload, $headers);
            
            if ($response['success']) {
                return [
                    'success' => true,
                    'transaction_id' => $response['data']['id'] ?? null,
                    'reference' => $reference,
                    'amount' => $amount,
                    'student_id' => $studentId,
                    'phone_number' => $this->formatPhoneNumber($phoneNumber),
                    'status' => $response['data']['status'] ?? 'pending',
                    'timestamp' => $this->timestamp
                ];
            }
            
            return [
                'success' => false,
                'error' => $response['data']['message'] ?? 'Payment initiation failed',
                'code' => $response['data']['code'] ?? 'UNKNOWN_ERROR'
            ];
            
        } catch (Exception $e) {
            error_log('Airtel payment initiation exception: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Exception: ' . $e->getMessage(),
                'code' => 'EXCEPTION'
            ];
        }
    }
    
    /**
     * Query transaction status
     * 
     * @param string $reference Unique reference from payment initiation
     * 
     * @return array Transaction status
     */
    public function queryTransaction($reference) {
        try {
            $token = $this->authenticate();
            if (!$token) {
                return [
                    'success' => false,
                    'status' => 'unknown',
                    'error' => 'Authentication failed'
                ];
            }
            
            $url = $this->baseUrl . '/payments/mobile/query';
            
            $payload = json_encode([
                'reference' => $reference
            ]);
            
            $headers = [
                'Authorization: Bearer ' . $token
            ];
            
            $response = $this->sendRequest('POST', $url, $payload, $headers);
            
            if ($response['success']) {
                return [
                    'success' => true,
                    'status' => $response['data']['status'] ?? 'unknown',
                    'amount' => $response['data']['amount'] ?? null,
                    'id' => $response['data']['id'] ?? null
                ];
            }
            
            return [
                'success' => false,
                'status' => 'unknown',
                'error' => $response['data']['message'] ?? 'Query failed'
            ];
            
        } catch (Exception $e) {
            error_log('Airtel query exception: ' . $e->getMessage());
            return [
                'success' => false,
                'status' => 'unknown',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Handle payment callback/webhook from Airtel
     * 
     * @param array $callbackData Data from Airtel webhook
     * 
     * @return array Processing result
     */
    public function handleCallback($callbackData) {
        try {
            // Verify callback signature if provided
            if (isset($callbackData['signature'])) {
                if (!$this->verifySignature($callbackData)) {
                    return [
                        'success' => false,
                        'error' => 'Invalid signature',
                        'code' => 'INVALID_SIGNATURE'
                    ];
                }
            }
            
            $transactionStatus = $callbackData['status'] ?? 'unknown';
            $reference = $callbackData['reference'] ?? null;
            
            if (!$reference) {
                return [
                    'success' => false,
                    'error' => 'Missing reference in callback',
                    'code' => 'MISSING_REFERENCE'
                ];
            }
            
            return [
                'success' => true,
                'reference' => $reference,
                'status' => $transactionStatus,
                'id' => $callbackData['id'] ?? null,
                'amount' => $callbackData['amount'] ?? null
            ];
            
        } catch (Exception $e) {
            error_log('Airtel callback exception: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'code' => 'EXCEPTION'
            ];
        }
    }
    
    /**
     * Format phone number to international format (256703123456)
     */
    private function formatPhoneNumber($phoneNumber) {
        // Remove any non-numeric characters
        $phoneNumber = preg_replace('/\D/', '', $phoneNumber);
        
        // If already has country code (starts with 260)
        if (strpos($phoneNumber, '260') === 0) {
            return $phoneNumber;
        }
        
        // If starts with 0, replace with country code
        if (strpos($phoneNumber, '0') === 0) {
            $phoneNumber = '260' . substr($phoneNumber, 1);
        }
        
        // If less than 10 digits, assume it's missing leading digit
        if (strlen($phoneNumber) === 9) {
            $phoneNumber = '260' . $phoneNumber;
        }
        
        return $phoneNumber;
    }
    
    /**
     * Generate unique request ID
     */
    private function generateRequestId() {
        return 'ITC-' . date('YmdHis') . '-' . substr(md5(uniqid()), 0, 8);
    }
    
    /**
     * Send HTTP request to Airtel API
     */
    private function sendRequest($method, $url, $payload = null, $headers = []) {
        try {
            $ch = curl_init();
            
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            
            // Default headers
            $allHeaders = array_merge([
                'Content-Type: application/json',
                'Accept: application/json'
            ], $headers);
            
            curl_setopt($ch, CURLOPT_HTTPHEADER, $allHeaders);
            
            if ($payload) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }
            
            // SSL verification (disable for sandbox, enable for production)
            if ($this->environment === 'production') {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            } else {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            }
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                error_log('Airtel CURL error: ' . $curlError);
                return [
                    'success' => false,
                    'data' => ['message' => 'Network error: ' . $curlError]
                ];
            }
            
            $data = json_decode($response, true);
            
            $isSuccess = ($httpCode >= 200 && $httpCode < 300);
            
            return [
                'success' => $isSuccess,
                'data' => $data,
                'http_code' => $httpCode
            ];
            
        } catch (Exception $e) {
            error_log('Airtel sendRequest exception: ' . $e->getMessage());
            return [
                'success' => false,
                'data' => ['message' => $e->getMessage()]
            ];
        }
    }
    
    /**
     * Verify callback signature (if Airtel provides signature)
     */
    private function verifySignature($data) {
        // Implement signature verification based on Airtel's documentation
        // For now, just log that verification was attempted
        error_log('Airtel callback signature verification attempted');
        return true;
    }
}
?>
