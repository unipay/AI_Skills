<?php
/**
 * Minimal UniPAY API v3 client: PHP 8.1+ with ext-curl, no dependencies.
 * Server-side only. The secret key must never reach a browser or a mobile app.
 *
 *   $unipay = UnipayClient::fromEnv();
 *   $order  = $unipay->createOrder('ORD-1001-a1', '25.50', 'Order #1001',
 *                 'https://shop.example/checkout/done?order=ORD-1001',
 *                 'https://shop.example/checkout/failed?order=ORD-1001',
 *                 'https://shop.example/webhooks/unipay');
 *   // store $order['UnipayOrderHashID'] with your order, then:
 *   header('Location: ' . $order['Checkout']);
 */

final class UnipayError extends RuntimeException
{
    public function __construct(public readonly int|string $errorCode, string $message, public readonly ?array $body = null)
    {
        parent::__construct("UniPAY error {$errorCode}: {$message}");
    }
}

final class UnipayClient
{
    private ?string $token = null;
    private int $tokenExpiresAt = 0;

    public function __construct(
        private string $merchantId,
        private string $secretKey,
        private string $baseUrl = 'https://apiv2.unipay.com',
        private string $alias = 'custom',
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            getenv('UNIPAY_MERCHANT_ID') ?: throw new RuntimeException('UNIPAY_MERCHANT_ID is not set'),
            getenv('UNIPAY_SECRET_KEY') ?: throw new RuntimeException('UNIPAY_SECRET_KEY is not set'),
            getenv('UNIPAY_API_URL') ?: 'https://apiv2.unipay.com',
        );
    }

    /** Creates an order; returns data: Checkout (redirect the payer here), UnipayOrderHashID (store it), UnipayOrderID. */
    public function createOrder(
        string $merchantOrderId,
        string $amount,
        string $title,
        string $successUrl,
        string $cancelUrl,
        string $callbackUrl,
        array $extra = [],
    ): array {
        return $this->call('POST', "/v3/{$this->alias}/order/create", $extra + [
            'MerchantOrderID' => $merchantOrderId,
            'OrderPrice' => $amount,                       // major units, 2 decimals: "25.50"
            'OrderCurrency' => 'GEL',
            'OrderName' => base64_encode($title),          // always base64, see SKILL.md rule 4
            'SuccessRedirectUrl' => base64_encode($successUrl),
            'CancelRedirectUrl' => base64_encode($cancelUrl),
            'CallBackUrl' => base64_encode($callbackUrl),
            'Language' => 'en',
        ]);
    }

    /** data: order, status (3 = paid), status_label, amount (net), commission, completed_at, created_at. */
    public function getOrder(string $orderHash): array
    {
        return $this->call('GET', "/v3/{$this->alias}/order/get-details/" . rawurlencode($orderHash));
    }

    public function refund(string $orderHash, string $amount, string $reason, string $note = ''): array
    {
        return $this->call('POST', "/v3/{$this->alias}/order/refund", [
            'OrderHashID' => $orderHash,
            'Amount' => $amount,
            'Reason' => $reason,
            'Note' => $note,
        ]);
    }

    /**
     * Checks a callback's Hash. Pass the query parameters ($_GET), not the body: the body is "[]".
     * The secret is the PRODUCTION secret key.
     */
    public static function verifyCallback(array $query, string $productionSecretKey): bool
    {
        foreach (['UnipayOrderID', 'MerchantOrderID', 'Status', 'Hash'] as $key) {
            if (!isset($query[$key]) || !is_string($query[$key])) {
                return false;
            }
        }

        $expected = hash('sha256', $query['UnipayOrderID'] . '|' . $query['MerchantOrderID'] . '|' . $query['Status'] . '|' . $productionSecretKey);

        return hash_equals($expected, strtolower($query['Hash']));
    }

    private function call(string $method, string $path, ?array $body = null, bool $retried = false): array
    {
        [$status, $json] = $this->send($method, $path, $body, $this->token());

        if ($status === 401 && !$retried) {
            $this->token = null;

            return $this->call($method, $path, $body, true);
        }

        // Order endpoints answer HTTP 200 for business errors too: errorcode decides.
        if (($json['errorcode'] ?? null) !== 0) {
            throw new UnipayError($json['errorcode'] ?? $status, (string)($json['message'] ?? 'unexpected response'), $json);
        }

        return $json['data'] ?? [];
    }

    private function token(): string
    {
        if ($this->token !== null && time() < $this->tokenExpiresAt) {
            return $this->token;
        }

        [$status, $json] = $this->send('POST', '/v3/auth', [
            'merchant_id' => $this->merchantId,
            'api_key' => $this->secretKey,
        ]);

        if ($status !== 200 || empty($json['auth_token'])) {
            throw new UnipayError($status, (string)($json['message'] ?? 'authentication failed'), $json);
        }

        $this->token = $json['auth_token'];
        $this->tokenExpiresAt = time() + (int)($json['expires_in'] ?? 1800) - 60;

        return $this->token;
    }

    /** @return array{0: int, 1: array} HTTP status and decoded JSON body. */
    private function send(string $method, string $path, ?array $body, ?string $token = null): array
    {
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("UniPAY request failed: {$error}");
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        // Responses are JSON even when the Content-Type says text/html.
        $json = json_decode($raw, true);

        return [$status, is_array($json) ? $json : ['message' => substr($raw, 0, 200)]];
    }
}

/*
 * Callback endpoint (e.g. webhooks/unipay.php). Sketch: replace findOrder/markPaid with your own storage.
 *
 * $q = $_GET;                                   // all fields are in the query string
 * if (!UnipayClient::verifyCallback($q, getenv('UNIPAY_SECRET_KEY'))) {
 *     http_response_code(400);
 *     exit;
 * }
 * $order = findOrderByUnipayHash($q['UnipayOrderID']);   // the hash you stored at creation
 * if ($order && $q['Status'] === '3' && $q['Amount'] === $order->amount) {
 *     markPaidOnce($order);                     // idempotent: callbacks can repeat
 * } elseif ($order && in_array($q['Status'], ['13', '22', '-1'], true)) {
 *     markFailedIfStillPending($order);
 * }
 * http_response_code(200);                      // answer fast; any 2xx stops retries
 */
