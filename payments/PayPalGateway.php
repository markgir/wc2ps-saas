<?php
/**
 * PayPalGateway.php — Integração PayPal Checkout
 * ================================================
 * Usa PayPal REST API v2 (Orders API).
 * Suporta sandbox e produção via config.
 *
 * Fluxo:
 *  1. createOrder()    → devolve order_id + approval_url
 *  2. Cliente aprova   → PayPal redireciona para return_url?token=ORDER_ID
 *  3. captureOrder()   → confirma pagamento e devolve detalhes
 *  4. onSuccess()      → cria job e devolve instruções de instalação
 */
declare(strict_types=1);

class PayPalGateway
{
    private string $clientId;
    private string $clientSecret;
    private string $baseUrl;
    private string $returnUrl;
    private string $cancelUrl;

    // Planos disponíveis
    public const PLANS = [
        'starter'    => ['price' => 29.00, 'label' => 'Starter',    'max_products' => 500],
        'pro'        => ['price' => 49.00, 'label' => 'Pro',         'max_products' => 5000],
        'enterprise' => ['price' => 99.00, 'label' => 'Enterprise',  'max_products' => PHP_INT_MAX],
    ];

    public function __construct(array $cfg)
    {
        $sandbox          = $cfg['sandbox'] ?? true;
        $this->clientId   = $cfg['client_id'];
        $this->clientSecret = $cfg['client_secret'];
        $this->baseUrl    = $sandbox
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
        $this->returnUrl  = $cfg['return_url'];
        $this->cancelUrl  = $cfg['cancel_url'];
    }

    /**
     * Passo 1: Criar order no PayPal
     * Devolve: ['order_id' => ..., 'approval_url' => ...]
     */
    public function createOrder(string $plan, string $clientEmail, string $clientName): array
    {
        if (!isset(self::PLANS[$plan])) {
            throw new InvalidArgumentException("Unknown plan: {$plan}");
        }

        $planData = self::PLANS[$plan];
        $token    = $this->getAccessToken();

        $body = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'amount' => [
                    'currency_code' => 'EUR',
                    'value'         => number_format($planData['price'], 2, '.', ''),
                ],
                'description' => "MigraShop - Plano {$planData['label']} (até {$planData['max_products']} produtos)",
                'custom_id'   => $plan . '|' . urlencode($clientEmail),
            ]],
            'payment_source' => [
                'paypal' => [
                    'experience_context' => [
                        'payment_method_preference' => 'IMMEDIATE_PAYMENT_REQUIRED',
                        'brand_name'   => 'MigraShop',
                        'locale'       => 'pt-PT',
                        'landing_page' => 'LOGIN',
                        'return_url'   => $this->returnUrl,
                        'cancel_url'   => $this->cancelUrl,
                    ],
                ],
            ],
        ];

        $response = $this->request('POST', '/v2/checkout/orders', $body, $token);

        // Encontrar o link de aprovação
        $approvalUrl = '';
        foreach ($response['links'] ?? [] as $link) {
            if ($link['rel'] === 'payer-action') {
                $approvalUrl = $link['href'];
                break;
            }
        }

        if (!$approvalUrl) {
            throw new RuntimeException('PayPal did not return an approval URL');
        }

        return [
            'order_id'     => $response['id'],
            'approval_url' => $approvalUrl,
            'plan'         => $plan,
            'price'        => $planData['price'],
            'status'       => $response['status'],
        ];
    }

    /**
     * Passo 3: Capturar pagamento após aprovação
     * Devolve detalhes completos do pagamento
     */
    public function captureOrder(string $orderId): array
    {
        $token    = $this->getAccessToken();
        $response = $this->request('POST', "/v2/checkout/orders/{$orderId}/capture", [], $token);

        if ($response['status'] !== 'COMPLETED') {
            throw new RuntimeException("Payment not completed. Status: {$response['status']}");
        }

        $unit = $response['purchase_units'][0] ?? [];
        $capture = $unit['payments']['captures'][0] ?? [];

        // Extrair plan e email do custom_id
        $customId = $unit['custom_id'] ?? '';
        [$plan, $emailEncoded] = array_pad(explode('|', $customId, 2), 2, '');
        $email = urldecode($emailEncoded);

        return [
            'order_id'      => $orderId,
            'capture_id'    => $capture['id'] ?? '',
            'status'        => 'paid',
            'amount'        => (float)($capture['amount']['value'] ?? 0),
            'currency'      => $capture['amount']['currency_code'] ?? 'EUR',
            'plan'          => $plan,
            'client_email'  => $email,
            'payer_email'   => $response['payer']['email_address'] ?? $email,
            'payer_name'    => trim(
                ($response['payer']['name']['given_name'] ?? '') . ' ' .
                ($response['payer']['name']['surname'] ?? '')
            ),
            'paid_at'       => $capture['create_time'] ?? date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Verificar estado de um order (para webhook ou polling)
     */
    public function getOrderStatus(string $orderId): array
    {
        $token    = $this->getAccessToken();
        $response = $this->request('GET', "/v2/checkout/orders/{$orderId}", [], $token);
        return [
            'order_id' => $orderId,
            'status'   => $response['status'],
        ];
    }

    // ── Privado ───────────────────────────────────────────────────────────────

    private function getAccessToken(): string
    {
        $credentials = base64_encode("{$this->clientId}:{$this->clientSecret}");

        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => implode("\r\n", [
                'Authorization: Basic ' . $credentials,
                'Content-Type: application/x-www-form-urlencoded',
            ]),
            'content' => 'grant_type=client_credentials',
            'timeout' => 15,
        ]]);

        $raw = @file_get_contents($this->baseUrl . '/v1/oauth2/token', false, $ctx);
        if ($raw === false) throw new RuntimeException('Cannot reach PayPal API');

        $data = json_decode($raw, true);
        if (empty($data['access_token'])) {
            throw new RuntimeException('PayPal auth failed: ' . ($data['error_description'] ?? 'unknown'));
        }

        return $data['access_token'];
    }

    private function request(string $method, string $path, array $body, string $token): array
    {
        $ctx = stream_context_create(['http' => [
            'method'        => $method,
            'header'        => implode("\r\n", [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'PayPal-Request-Id: ' . bin2hex(random_bytes(16)),
                'Prefer: return=representation',
            ]),
            'content'       => $method !== 'GET' ? json_encode($body) : null,
            'timeout'       => 20,
            'ignore_errors' => true,
        ]]);

        $raw      = @file_get_contents($this->baseUrl . $path, false, $ctx);
        $response = json_decode($raw ?: '{}', true) ?? [];

        $httpCode = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('/HTTP\/\d\.\d (\d+)/', $h, $m)) $httpCode = (int)$m[1];
        }

        if ($httpCode >= 400) {
            $msg = $response['message'] ?? $response['error_description'] ?? 'PayPal error';
            throw new RuntimeException("PayPal {$httpCode}: {$msg}");
        }

        return $response;
    }
}
