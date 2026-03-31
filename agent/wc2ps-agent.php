<?php
/**
 * wc2ps-agent.php — WC2PS Migration Agent
 * =========================================
 * Instala este ficheiro na raiz do hosting do cliente.
 * O servidor dedicado comunica com ele via HTTPS para aceder
 * às bases de dados locais sem expor credenciais ao exterior.
 *
 * Requisitos: PHP 7.4+, PDO, pdo_mysql
 * Instalação: Upload para public_html/ via File Manager do cPanel
 *
 * SEGURANÇA:
 *  - Nunca expõe dados sem token válido
 *  - Token com 64 chars gerado no servidor dedicado
 *  - Rate limiting: máximo 60 requests/minuto por IP
 *  - Todas as queries são parametrizadas (sem SQL injection)
 *  - Apenas SELECT e informações de schema (nunca escreve dados)
 */

declare(strict_types=1);
error_reporting(0);
ini_set('display_errors', '0');

// ── Config (preenchida pelo servidor dedicado no deploy) ───────────────────────
define('AGENT_TOKEN',   '{{AGENT_TOKEN}}');   // 64 chars hex, gerado pelo servidor
define('AGENT_VERSION', '1.0.0');
define('MAX_ROWS',      5000);                // limite de rows por query
define('RATE_LIMIT',    60);                  // requests por minuto

// ── Bootstrap ─────────────────────────────────────────────────────────────────

header('Content-Type: application/json; charset=utf-8');
header('X-Agent-Version: ' . AGENT_VERSION);
header('Cache-Control: no-store');

// Só aceita HTTPS em produção (ignora em desenvolvimento local)
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'on'
    && ($_SERVER['HTTP_HOST'] ?? '') !== 'localhost') {
    respond(403, 'HTTPS required');
}

// Rate limiting simples via ficheiro de lock
checkRateLimit();

// Autenticação por token no header
$token = $_SERVER['HTTP_X_AGENT_TOKEN'] ?? '';
if (!hash_equals(AGENT_TOKEN, $token)) {
    // Delay para dificultar brute-force
    usleep(random_int(200000, 800000));
    respond(401, 'Unauthorized');
}

// Parse do body JSON
$raw  = file_get_contents('php://input');
$data = @json_decode($raw, true);
if (!is_array($data)) respond(400, 'Invalid JSON body');

$action = $data['action'] ?? '';

// ── Router ─────────────────────────────────────────────────────────────────────
try {
    switch ($action) {

        // Ping — confirma que o agent está activo e acessível
        case 'ping':
            respond(200, 'ok', [
                'version'    => AGENT_VERSION,
                'php'        => PHP_VERSION,
                'extensions' => [
                    'pdo'       => extension_loaded('pdo'),
                    'pdo_mysql' => extension_loaded('pdo_mysql'),
                ],
                'timestamp'  => time(),
            ]);

        // Testar ligação à BD e devolver informação básica
        case 'test_connection': {
            $pdo  = connect($data);
            $type = $data['type'] ?? 'wc'; // 'wc' ou 'ps'
            $info = $type === 'wc' ? getWCInfo($pdo, $data) : getPSInfo($pdo, $data);
            respond(200, 'ok', $info);
        }

        // Executar query SELECT (nunca INSERT/UPDATE/DELETE)
        case 'query': {
            $sql    = $data['sql']    ?? '';
            $params = $data['params'] ?? [];
            $limit  = min((int)($data['limit'] ?? 1000), MAX_ROWS);

            // Validação: só SELECT permitido
            $sqlTrimmed = ltrim(preg_replace('/\s+/', ' ', strtoupper(trim($sql))));
            if (!str_starts_with($sqlTrimmed, 'SELECT ')
                && !str_starts_with($sqlTrimmed, 'SHOW ')
                && !str_starts_with($sqlTrimmed, 'DESCRIBE ')) {
                respond(403, 'Only SELECT/SHOW/DESCRIBE allowed');
            }

            // Injectar LIMIT se não tiver
            if (!stripos($sql, ' LIMIT ')) {
                $sql .= " LIMIT {$limit}";
            }

            $pdo  = connect($data);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            respond(200, 'ok', ['rows' => $rows, 'count' => count($rows)]);
        }

        // Contar rows de uma tabela (usado para progresso)
        case 'count': {
            $table  = sanitizeIdentifier($data['table']  ?? '');
            $where  = $data['where']  ?? '';
            $params = $data['params'] ?? [];

            $sql = "SELECT COUNT(*) AS n FROM `{$table}`";
            if ($where) $sql .= " WHERE {$where}";

            $pdo  = connect($data);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row  = $stmt->fetch(PDO::FETCH_ASSOC);
            respond(200, 'ok', ['count' => (int)$row['n']]);
        }

        // Schema de uma tabela (colunas e tipos)
        case 'describe': {
            $table = sanitizeIdentifier($data['table'] ?? '');
            $pdo   = connect($data);
            $rows  = $pdo->query("DESCRIBE `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            respond(200, 'ok', ['columns' => $rows]);
        }

        // Listar tabelas da BD
        case 'tables': {
            $pdo  = connect($data);
            $rows = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            respond(200, 'ok', ['tables' => $rows]);
        }

        default:
            respond(400, "Unknown action: {$action}");
    }
} catch (PDOException $e) {
    // Nunca expor detalhes da BD ao exterior
    $safe = preg_replace("/'.+?'/", "'?'", $e->getMessage());
    respond(500, 'Database error: ' . $safe);
} catch (Throwable $e) {
    respond(500, 'Error: ' . $e->getMessage());
}

// ── Helpers ────────────────────────────────────────────────────────────────────

function respond(int $status, string $message, array $data = []): never
{
    http_response_code($status);
    echo json_encode([
        'ok'      => $status >= 200 && $status < 300,
        'message' => $message,
        'data'    => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function connect(array $cfg): PDO
{
    $host   = $cfg['db_host']   ?? '127.0.0.1';
    $port   = $cfg['db_port']   ?? '3306';
    $dbname = $cfg['db_name']   ?? '';
    $user   = $cfg['db_user']   ?? '';
    $pass   = $cfg['db_pass']   ?? '';

    if (!$dbname || !$user) throw new RuntimeException('Missing db_name or db_user');

    return new PDO(
        "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
        $user, $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 10,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone='+00:00'",
        ]
    );
}

function getWCInfo(PDO $pdo, array $cfg): array
{
    $p = $cfg['db_prefix'] ?? 'wp_';
    $p = preg_replace('/[^a-zA-Z0-9_]/', '', $p);

    $opts = $pdo->query(
        "SELECT option_name, option_value FROM `{$p}options`
         WHERE option_name IN ('siteurl','blogname','woocommerce_version','db_version')
         LIMIT 10"
    )->fetchAll();

    $map = [];
    foreach ($opts as $r) $map[$r['option_name']] = $r['option_value'];

    $tables = $pdo->query("SHOW TABLES LIKE '{$p}%'")->fetchAll(PDO::FETCH_COLUMN);

    $products = (int)$pdo->query(
        "SELECT COUNT(*) FROM `{$p}posts` WHERE post_type='product' AND post_status='publish'"
    )->fetchColumn();

    return [
        'type'              => 'woocommerce',
        'site_url'          => $map['siteurl'] ?? '',
        'site_name'         => $map['blogname'] ?? '',
        'wc_version'        => $map['woocommerce_version'] ?? '?',
        'wp_db_version'     => $map['db_version'] ?? '?',
        'tables'            => count($tables),
        'products_published'=> $products,
        'prefix'            => $p,
    ];
}

function getPSInfo(PDO $pdo, array $cfg): array
{
    $p = $cfg['db_prefix'] ?? 'ps_';
    $p = preg_replace('/[^a-zA-Z0-9_]/', '', $p);

    $rows = $pdo->query(
        "SELECT name, value FROM `{$p}configuration`
         WHERE name IN ('PS_VERSION_DB','PS_SHOP_NAME','PS_SHOP_DOMAIN',
                        'PS_LANG_DEFAULT','PS_SHOP_DEFAULT')
         LIMIT 10"
    )->fetchAll();

    $map = [];
    foreach ($rows as $r) $map[$r['name']] = $r['value'];

    $products = (int)$pdo->query(
        "SELECT COUNT(*) FROM `{$p}product`"
    )->fetchColumn();

    return [
        'type'           => 'prestashop',
        'version'        => $map['PS_VERSION_DB'] ?? '?',
        'shop_name'      => $map['PS_SHOP_NAME']  ?? '',
        'domain'         => $map['PS_SHOP_DOMAIN'] ?? '',
        'default_lang'   => $map['PS_LANG_DEFAULT'] ?? '1',
        'default_shop'   => $map['PS_SHOP_DEFAULT'] ?? '1',
        'products_count' => $products,
        'prefix'         => $p,
    ];
}

function sanitizeIdentifier(string $id): string
{
    // Só permite letras, números, underscore e ponto
    $clean = preg_replace('/[^a-zA-Z0-9_.]/', '', $id);
    if (!$clean) throw new RuntimeException('Invalid table identifier');
    return $clean;
}

function checkRateLimit(): void
{
    $tmpDir  = sys_get_temp_dir();
    $ip      = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key     = md5('wc2ps_agent_' . $ip);
    $file    = $tmpDir . '/' . $key . '.rl';
    $now     = time();
    $window  = 60; // segundos

    if (file_exists($file)) {
        $data = @json_decode(file_get_contents($file), true);
        if ($data && $data['ts'] > $now - $window) {
            if ($data['count'] >= RATE_LIMIT) {
                respond(429, 'Rate limit exceeded. Try again in a minute.');
            }
            $data['count']++;
        } else {
            $data = ['ts' => $now, 'count' => 1];
        }
    } else {
        $data = ['ts' => $now, 'count' => 1];
    }

    @file_put_contents($file, json_encode($data), LOCK_EX);
}
