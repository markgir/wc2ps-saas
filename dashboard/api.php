<?php
/**
 * dashboard/api.php — API REST do dashboard
 * Corre no servidor dedicado. A interface web consome este endpoint.
 *
 * Endpoints:
 *   POST /api.php  {action: 'create_job', ...}
 *   POST /api.php  {action: 'get_jobs'}
 *   POST /api.php  {action: 'get_job', job_id: N}
 *   POST /api.php  {action: 'get_logs', job_id: N, offset: N}
 *   POST /api.php  {action: 'cancel_job', job_id: N}
 *   POST /api.php  {action: 'generate_token', client_id: N}
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

require_once __DIR__ . '/../worker/config.php';
require_once __DIR__ . '/../worker/Database.php';
require_once __DIR__ . '/../worker/AgentClient.php';

// Auth por API key (dashboard → servidor dedicado)
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (!defined('DASHBOARD_API_KEY') || !hash_equals(DASHBOARD_API_KEY, $apiKey)) {
    respond(401, 'Unauthorized');
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true) ?? [];
$db   = new Database(DB_DSN, DB_USER, DB_PASS);

try {
    switch ($data['action'] ?? '') {

        // Criar job (após pagamento confirmado)
        case 'create_job': {
            $clientId = (int)($data['client_id'] ?? 0);
            if (!$clientId) respond(400, 'client_id required');

            // Gerar tokens para os dois agents
            $wcToken = $db->generateToken($clientId, 'agent_wc');
            $psToken = $db->generateToken($clientId, 'agent_ps');

            $fields = [
                'client_id'      => $clientId,
                'client_name'    => $data['client_name'] ?? '',
                'status'         => 'pending',
                'wc_agent_url'   => $data['wc_agent_url'],
                'wc_agent_token' => $wcToken,
                'wc_db_host'     => $data['wc_db_host'] ?? '127.0.0.1',
                'wc_db_port'     => $data['wc_db_port'] ?? '3306',
                'wc_db_name'     => $data['wc_db_name'],
                'wc_db_user'     => $data['wc_db_user'],
                'wc_db_pass'     => $data['wc_db_pass'],
                'wc_db_prefix'   => $data['wc_db_prefix'] ?? 'wp_',
                'ps_agent_url'   => $data['ps_agent_url'],
                'ps_agent_token' => $psToken,
                'ps_db_host'     => $data['ps_db_host'] ?? '127.0.0.1',
                'ps_db_port'     => $data['ps_db_port'] ?? '3306',
                'ps_db_name'     => $data['ps_db_name'],
                'ps_db_user'     => $data['ps_db_user'],
                'ps_db_pass'     => $data['ps_db_pass'],
                'ps_db_prefix'   => $data['ps_db_prefix'] ?? 'ps_',
                'ps_id_lang'     => $data['ps_id_lang'] ?? 1,
                'ps_id_shop'     => $data['ps_id_shop'] ?? 1,
            ];

            $cols = implode(', ', array_map(fn($k) => "`{$k}`", array_keys($fields)));
            $vals = implode(', ', array_fill(0, count($fields), '?'));
            $stmt = $db->getPdo()->prepare("INSERT INTO jobs ({$cols}) VALUES ({$vals})");
            $stmt->execute(array_values($fields));
            $jobId = (int)$db->getPdo()->lastInsertId();

            // Tokens são devolvidos UMA vez para instalação no cliente
            respond(200, 'ok', [
                'job_id'         => $jobId,
                'wc_agent_token' => $wcToken,
                'ps_agent_token' => $psToken,
                'install_instructions' => [
                    'step1' => 'Faz download do ficheiro wc2ps-agent.php',
                    'step2' => "Substitui {{AGENT_TOKEN}} por: {$wcToken}",
                    'step3' => 'Faz upload para a raiz do WooCommerce via File Manager',
                    'step4' => 'Repete para o PrestaShop com o token: ' . $psToken,
                    'step5' => 'Clica em Verificar Instalação na dashboard',
                ],
            ]);
        }

        // Listar jobs
        case 'get_jobs': {
            $clientId = (int)($data['client_id'] ?? 0);
            $where = $clientId ? 'WHERE client_id = ?' : '';
            $params = $clientId ? [$clientId] : [];
            $stmt = $db->getPdo()->prepare(
                "SELECT id, client_name, status, total_products, done_products,
                        created_at, started_at, completed_at
                 FROM jobs {$where} ORDER BY created_at DESC LIMIT 50"
            );
            $stmt->execute($params);
            respond(200, 'ok', ['jobs' => $stmt->fetchAll()]);
        }

        // Detalhes de um job
        case 'get_job': {
            $jobId = (int)($data['job_id'] ?? 0);
            $stmt  = $db->getPdo()->prepare("SELECT * FROM jobs WHERE id = ? LIMIT 1");
            $stmt->execute([$jobId]);
            $job = $stmt->fetch();
            if (!$job) respond(404, 'Job not found');
            // Não devolver passwords
            foreach (['wc_db_pass','ps_db_pass','wc_agent_token','ps_agent_token'] as $k) {
                unset($job[$k]);
            }
            $pct = $job['total_products'] > 0
                ? round(($job['done_products'] / $job['total_products']) * 100, 1)
                : 0;
            $job['progress_pct'] = $pct;
            respond(200, 'ok', $job);
        }

        // Logs de um job
        case 'get_logs': {
            $jobId  = (int)($data['job_id'] ?? 0);
            $offset = (int)($data['offset'] ?? 0);
            $logs   = $db->getLogs($jobId, $offset, 200);
            respond(200, 'ok', ['logs' => $logs, 'next_offset' => $offset + count($logs)]);
        }

        // Cancelar job
        case 'cancel_job': {
            $jobId = (int)($data['job_id'] ?? 0);
            $db->updateJob($jobId, ['status' => 'cancelled']);
            // Cria ficheiro de stop para o worker
            file_put_contents(STOP_FILE . '_' . $jobId, '1');
            respond(200, 'ok', ['cancelled' => true]);
        }

        // Verificar instalação do agent no cliente
        case 'verify_agent': {
            $jobId = (int)($data['job_id'] ?? 0);
            $type  = $data['type'] ?? 'wc'; // 'wc' ou 'ps'
            $stmt  = $db->getPdo()->prepare("SELECT * FROM jobs WHERE id = ? LIMIT 1");
            $stmt->execute([$jobId]);
            $job   = $stmt->fetch();
            if (!$job) respond(404, 'Job not found');

            $agentUrl   = $type === 'wc' ? $job['wc_agent_url']   : $job['ps_agent_url'];
            $agentToken = $type === 'wc' ? $job['wc_agent_token'] : $job['ps_agent_token'];
            $dbCfg      = [
                'agent_url'  => $agentUrl,
                'token'      => $agentToken,
                'db_host'    => $job["{$type}_db_host"],
                'db_port'    => $job["{$type}_db_port"],
                'db_name'    => $job["{$type}_db_name"],
                'db_user'    => $job["{$type}_db_user"],
                'db_pass'    => $job["{$type}_db_pass"],
                'db_prefix'  => $job["{$type}_db_prefix"],
            ];

            $agent = new AgentClient($dbCfg);
            $ping  = $agent->ping();
            $info  = $agent->testConnection($type);

            respond(200, 'ok', [
                'agent_ok' => true,
                'ping'     => $ping,
                'db_info'  => $info,
            ]);
        }

        // Actualizar credenciais de um job após pagamento
        case 'update_job_creds': {
            $jobId = (int)($data['job_id'] ?? 0);
            $fields = [
                'wc_agent_url'   => $data['wc_agent_url']   ?? '',
                'wc_db_host'     => $data['wc_db_host']     ?? '127.0.0.1',
                'wc_db_port'     => $data['wc_db_port']     ?? '3306',
                'wc_db_name'     => $data['wc_db_name']     ?? '',
                'wc_db_user'     => $data['wc_db_user']     ?? '',
                'wc_db_pass'     => $data['wc_db_pass']     ?? '',
                'wc_db_prefix'   => $data['wc_db_prefix']   ?? 'wp_',
                'ps_agent_url'   => $data['ps_agent_url']   ?? '',
                'ps_db_host'     => $data['ps_db_host']     ?? '127.0.0.1',
                'ps_db_port'     => $data['ps_db_port']     ?? '3306',
                'ps_db_name'     => $data['ps_db_name']     ?? '',
                'ps_db_user'     => $data['ps_db_user']     ?? '',
                'ps_db_pass'     => $data['ps_db_pass']     ?? '',
                'ps_db_prefix'   => $data['ps_db_prefix']   ?? 'ps_',
                'ps_id_lang'     => (int)($data['ps_id_lang'] ?? 1),
                'ps_id_shop'     => (int)($data['ps_id_shop'] ?? 1),
            ];
            $db->updateJob($jobId, $fields);
            respond(200, 'ok', ['updated' => true]);
        }

        // Activar job (worker vai pegar-o)
        case 'activate_job': {
            $jobId = (int)($data['job_id'] ?? 0);
            $db->updateJob($jobId, ['status' => 'pending']);
            respond(200, 'ok', ['activated' => true]);
        }

                default:
            respond(400, 'Unknown action');
    }
} catch (Throwable $e) {
    respond(500, 'Error: ' . $e->getMessage());
}

function respond(int $status, string $msg, array $data = []): never
{
    http_response_code($status);
    echo json_encode(['ok' => $status < 300, 'message' => $msg, 'data' => $data],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
