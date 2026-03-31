<?php
/**
 * payment.php — Endpoints de pagamento
 * ======================================
 * Chamado via AJAX pela interface do cliente.
 *
 * POST {action: 'create_order', plan: 'pro', email: '...', name: '...'}
 *   → devolve approval_url para redirecionar o cliente para o PayPal
 *
 * POST {action: 'capture', order_id: '...'}
 *   → confirma pagamento, cria job, devolve token do agent
 *
 * GET  ?action=return&token=ORDER_ID
 *   → callback do PayPal após aprovação (redireciona para a interface)
 *
 * GET  ?action=cancel
 *   → callback de cancelamento
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../worker/config.php';
require_once __DIR__ . '/../worker/Database.php';
require_once __DIR__ . '/PayPalGateway.php';

// Config PayPal — preenche no config.php
if (!defined('PAYPAL_CLIENT_ID')) define('PAYPAL_CLIENT_ID', 'SEU_CLIENT_ID_AQUI');
if (!defined('PAYPAL_SECRET'))    define('PAYPAL_SECRET',    'SEU_SECRET_AQUI');
if (!defined('PAYPAL_SANDBOX'))   define('PAYPAL_SANDBOX',   true); // false em produção
if (!defined('APP_URL'))          define('APP_URL', 'https://migrashop.pt');

$paypal = new PayPalGateway([
    'client_id'     => PAYPAL_CLIENT_ID,
    'client_secret' => PAYPAL_SECRET,
    'sandbox'       => PAYPAL_SANDBOX,
    'return_url'    => APP_URL . '/payment.php?action=return',
    'cancel_url'    => APP_URL . '/payment.php?action=cancel',
]);

// ── GET callbacks do PayPal ───────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'return') {
        // PayPal redireciona aqui com ?token=ORDER_ID
        $orderId = $_GET['token'] ?? '';
        if (!$orderId) { header('Location: ' . APP_URL . '/?payment=error'); exit; }

        // Redireciona para a interface com o order_id para o JS completar a captura
        header('Location: ' . APP_URL . '/?payment=approve&order_id=' . urlencode($orderId));
        exit;
    }

    if ($action === 'cancel') {
        header('Location: ' . APP_URL . '/?payment=cancelled');
        exit;
    }

    respond(400, 'Unknown action');
}

// ── POST AJAX ─────────────────────────────────────────────────────────────────

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true) ?? [];

try {
    switch ($data['action'] ?? '') {

        // Passo 1: Criar order PayPal e devolver approval_url
        case 'create_order': {
            $plan  = $data['plan']  ?? 'pro';
            $email = trim($data['email'] ?? '');
            $name  = trim($data['name']  ?? 'Cliente');

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                respond(400, 'Email inválido');
            }

            $result = $paypal->createOrder($plan, $email, $name);

            // Guardar order pendente para validar depois
            $db = new Database(DB_DSN, DB_USER, DB_PASS);
            $db->getPdo()->prepare(
                "INSERT INTO payments (client_id, amount, currency, status, ref)
                 VALUES (0, ?, 'EUR', 'pending', ?)"
            )->execute([$result['price'], $result['order_id']]);

            // Guardar email/plan em sessão para usar no capture
            session_start();
            $_SESSION['pending_order'] = [
                'order_id' => $result['order_id'],
                'plan'     => $plan,
                'email'    => $email,
                'name'     => $name,
            ];

            respond(200, 'ok', [
                'order_id'     => $result['order_id'],
                'approval_url' => $result['approval_url'],
            ]);
        }

        // Passo 3: Capturar pagamento após aprovação
        case 'capture': {
            $orderId = $data['order_id'] ?? '';
            if (!$orderId) respond(400, 'order_id required');

            $payment = $paypal->captureOrder($orderId);
            $db      = new Database(DB_DSN, DB_USER, DB_PASS);
            $pdo     = $db->getPdo();

            // Actualizar registo de pagamento
            $pdo->prepare(
                "UPDATE payments SET status='paid', paid_at=NOW() WHERE ref=?"
            )->execute([$orderId]);

            // Criar ou encontrar cliente
            $stmt = $pdo->prepare("SELECT id FROM clients WHERE email=? LIMIT 1");
            $stmt->execute([$payment['payer_email']]);
            $client = $stmt->fetch();

            if (!$client) {
                $pdo->prepare(
                    "INSERT INTO clients (email, name) VALUES (?, ?)"
                )->execute([$payment['payer_email'], $payment['payer_name']]);
                $clientId = (int)$pdo->lastInsertId();
            } else {
                $clientId = (int)$client['id'];
            }

            // Gerar tokens para os agents
            $wcToken = $db->generateToken($clientId, 'agent_wc');
            $psToken = $db->generateToken($clientId, 'agent_ps');

            // Criar job (sem credenciais ainda — serão preenchidas após instalação)
            $plan    = $payment['plan'] ?? 'pro';
            $maxProds = PayPalGateway::PLANS[$plan]['max_products'] ?? 5000;

            $pdo->prepare(
                "INSERT INTO jobs (client_id, client_name, status,
                    wc_agent_url, wc_agent_token, wc_db_host, wc_db_port,
                    wc_db_name, wc_db_user, wc_db_pass, wc_db_prefix,
                    ps_agent_url, ps_agent_token, ps_db_host, ps_db_port,
                    ps_db_name, ps_db_user, ps_db_pass, ps_db_prefix)
                 VALUES (?, ?, 'pending',
                    '', ?, '127.0.0.1', '3306', '', '', '', 'wp_',
                    '', ?, '127.0.0.1', '3306', '', '', '', 'ps_')"
            )->execute([
                $clientId,
                $payment['payer_name'],
                $wcToken,
                $psToken,
            ]);
            $jobId = (int)$pdo->lastInsertId();

            respond(200, 'ok', [
                'job_id'         => $jobId,
                'plan'           => $plan,
                'max_products'   => $maxProds,
                'wc_agent_token' => $wcToken,
                'ps_agent_token' => $psToken,
                'wc_agent_file'  => APP_URL . "/agent/download.php?job={$jobId}&type=wc&token={$wcToken}",
                'ps_agent_file'  => APP_URL . "/agent/download.php?job={$jobId}&type=ps&token={$psToken}",
                'instructions'   => [
                    '1' => 'Descarrega o ficheiro do agent WooCommerce e faz upload para a raiz do teu WC via File Manager',
                    '2' => 'Descarrega o ficheiro do agent PrestaShop e faz upload para a raiz do teu PS via File Manager',
                    '3' => 'Clica em Verificar Instalação para confirmar que os agents estão acessíveis',
                    '4' => 'Preenche as credenciais das bases de dados e clica em Iniciar Migração',
                ],
            ]);
        }

        // Polling do estado do order (antes de capturar)
        case 'order_status': {
            $orderId = $data['order_id'] ?? '';
            $status  = $paypal->getOrderStatus($orderId);
            respond(200, 'ok', $status);
        }

        default:
            respond(400, 'Unknown action: ' . ($data['action'] ?? ''));
    }
} catch (Throwable $e) {
    respond(500, $e->getMessage());
}

function respond(int $status, string $msg, array $data = []): never
{
    http_response_code($status);
    echo json_encode(['ok' => $status < 300, 'message' => $msg, 'data' => $data],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
