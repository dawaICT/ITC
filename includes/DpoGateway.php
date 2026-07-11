<?php

class DpoGateway
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function missingFields(): array
    {
        $missing = [];
        foreach (['company_token', 'service_type', 'api_url', 'payment_url'] as $field) {
            if (trim((string)($this->config[$field] ?? '')) === '') {
                $missing[] = $field;
            }
        }
        return $missing;
    }

    public function isConfigured(): bool
    {
        return empty($this->missingFields());
    }

    public function paymentUrl(string $token): string
    {
        return rtrim((string)$this->config['payment_url'], '?') . '?ID=' . urlencode($token);
    }

    public function createToken(array $payload): array
    {
        $xml = $this->buildCreateTokenXml($payload);
        return $this->request($xml);
    }

    public function verifyToken(string $transactionToken, bool $verifyTransaction = true, ?string $companyRef = null): array
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            . '<API3G>'
            . '<CompanyToken>' . $this->escape((string)$this->config['company_token']) . '</CompanyToken>'
            . '<Request>verifyToken</Request>'
            . '<TransactionToken>' . $this->escape($transactionToken) . '</TransactionToken>';

        if ($companyRef !== null && trim($companyRef) !== '') {
            $xml .= '<CompanyRef>' . $this->escape($companyRef) . '</CompanyRef>';
        }

        $xml .= '<VerifyTransaction>' . ($verifyTransaction ? '1' : '0') . '</VerifyTransaction>'
            . '</API3G>';

        return $this->request($xml);
    }

    private function buildCreateTokenXml(array $payload): string
    {
        $currency = strtoupper(trim((string)($payload['currency'] ?? $this->config['currency'] ?? 'ZMW')));
        $serviceDate = trim((string)($payload['service_date'] ?? date('Y/m/d H:i')));
        $description = trim((string)($payload['description'] ?? 'Student fee payment'));
        $companyRef = trim((string)($payload['company_ref'] ?? ''));
        $companyAccRef = trim((string)($payload['company_acc_ref'] ?? ''));
        $redirectUrl = trim((string)($payload['redirect_url'] ?? $this->config['redirect_url'] ?? ''));
        $backUrl = trim((string)($payload['back_url'] ?? $this->config['back_url'] ?? ''));
        $customerEmail = trim((string)($payload['customer_email'] ?? ''));
        $customerFirstName = trim((string)($payload['customer_first_name'] ?? 'Student'));
        $customerLastName = trim((string)($payload['customer_last_name'] ?? ''));
        $customerCountry = strtoupper(trim((string)($payload['customer_country'] ?? 'ZM')));
        $customerPhone = preg_replace('/\D+/', '', (string)($payload['customer_phone'] ?? ''));

        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            . '<API3G>'
            . '<CompanyToken>' . $this->escape((string)$this->config['company_token']) . '</CompanyToken>'
            . '<Request>createToken</Request>'
            . '<Transaction>'
            . '<PaymentAmount>' . number_format((float)$payload['amount'], 2, '.', '') . '</PaymentAmount>'
            . '<PaymentCurrency>' . $this->escape($currency) . '</PaymentCurrency>';

        if ($companyRef !== '') {
            $xml .= '<CompanyRef>' . $this->escape($companyRef) . '</CompanyRef>';
        }

        if ($redirectUrl !== '') {
            $xml .= '<RedirectURL>' . $this->escape($redirectUrl) . '</RedirectURL>';
        }

        if ($backUrl !== '') {
            $xml .= '<BackURL>' . $this->escape($backUrl) . '</BackURL>';
        }

        $xml .= '<CompanyRefUnique>1</CompanyRefUnique>'
            . '<PTL>' . (int)($payload['ptl_hours'] ?? $this->config['ptl_hours'] ?? 24) . '</PTL>'
            . '<PTLtype>hours</PTLtype>';

        if ($companyAccRef !== '') {
            $xml .= '<CompanyAccRef>' . $this->escape($companyAccRef) . '</CompanyAccRef>';
        }

        if (!empty($this->config['default_payment'])) {
            $xml .= '<DefaultPayment>' . $this->escape((string)$this->config['default_payment']) . '</DefaultPayment>';
        }
        if (!empty($this->config['default_payment_country'])) {
            $xml .= '<DefaultPaymentCountry>' . $this->escape((string)$this->config['default_payment_country']) . '</DefaultPaymentCountry>';
        }
        if (!empty($this->config['default_payment_mno'])) {
            $xml .= '<DefaultPaymentMNO>' . $this->escape((string)$this->config['default_payment_mno']) . '</DefaultPaymentMNO>';
        }

        if ($customerEmail !== '') {
            $xml .= '<customerEmail>' . $this->escape($customerEmail) . '</customerEmail>';
        }
        if ($customerFirstName !== '') {
            $xml .= '<customerFirstName>' . $this->escape($customerFirstName) . '</customerFirstName>';
        }
        if ($customerLastName !== '') {
            $xml .= '<customerLastName>' . $this->escape($customerLastName) . '</customerLastName>';
        }
        if ($customerCountry !== '') {
            $xml .= '<customerCountry>' . $this->escape($customerCountry) . '</customerCountry>';
        }
        if ($customerPhone !== '') {
            $xml .= '<customerPhone>' . $this->escape($customerPhone) . '</customerPhone>';
        }

        $xml .= '<TransactionSource>Website</TransactionSource>'
            . '</Transaction>'
            . '<Services>'
            . '<Service>'
            . '<ServiceType>' . $this->escape((string)$this->config['service_type']) . '</ServiceType>'
            . '<ServiceDescription>' . $this->escape($description) . '</ServiceDescription>'
            . '<ServiceDate>' . $this->escape($serviceDate) . '</ServiceDate>'
            . '</Service>'
            . '</Services>'
            . '</API3G>';

        return $xml;
    }

    private function request(string $xml): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'DPO configuration is incomplete.',
                'data' => [],
                'raw' => '',
            ];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => (string)$this->config['api_url'],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $xml,
            CURLOPT_HTTPHEADER => ['Content-Type: application/xml'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $curlError !== '') {
            return [
                'success' => false,
                'message' => $curlError !== '' ? $curlError : 'Unable to reach DPO API.',
                'data' => [],
                'raw' => '',
                'http_code' => $httpCode,
            ];
        }

        $data = $this->parseXml($raw);
        if (!$data['success']) {
            return [
                'success' => false,
                'message' => $data['message'],
                'data' => [],
                'raw' => $raw,
                'http_code' => $httpCode,
            ];
        }

        $payload = $data['data'];
        $resultCode = trim((string)($payload['Result'] ?? ''));
        $message = trim((string)($payload['ResultExplanation'] ?? ''));

        return [
            'success' => $resultCode === '000',
            'message' => $message !== '' ? $message : ($resultCode === '000' ? 'Request successful.' : 'DPO request failed.'),
            'data' => $payload,
            'raw' => $raw,
            'http_code' => $httpCode,
        ];
    }

    private function parseXml(string $raw): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($xml === false) {
            $errors = [];
            foreach (libxml_get_errors() as $error) {
                $errors[] = trim($error->message);
            }
            libxml_clear_errors();
            return [
                'success' => false,
                'message' => implode('; ', $errors) ?: 'Invalid XML response from DPO.',
                'data' => [],
            ];
        }

        $json = json_encode($xml, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $data = $json !== false ? json_decode($json, true) : null;

        return [
            'success' => is_array($data),
            'message' => is_array($data) ? '' : 'Unable to parse DPO response.',
            'data' => is_array($data) ? $data : [],
        ];
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}

