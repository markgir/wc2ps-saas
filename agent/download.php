<?php
/**
 * agent/download.php — Gera e serve o ficheiro agent com o token embutido
 * O cliente descarrega este ficheiro e faz upload para o seu servidor.
 *
 * GET /agent/download.php?job=JOB_ID&type=wc&token=TOKEN
 */
declare(strict_types=1);

require_once __DIR__ . '/../worker/config.php';
require_once __DIR__ . '/../worker/Database.php';

$jobId = (int)($_GET['job']   ?? 0);
$type  = $_GET['type']  ?? 'wc';   // 'wc' ou 'ps'
$token = $_GET['token'] ?? '';

if (!$jobId || !$token || !in_array($type, ['wc', 'ps'])) {
    http_response_code(400);
    echo 'Invalid parameters'; exit;
}

// Validar token
$db   = new Database(DB_DSN, DB_USER, DB_PASS);
$stmt = $db->getPdo()->prepare(
    "SELECT t.id FROM tokens t
     WHERE t.job_id=? AND t.token=? AND t.type=?
     AND (t.expires_at IS NULL OR t.expires_at > NOW())
     LIMIT 1"
);
$stmt->execute([$jobId, $token, "agent_{$type}"]);
if (!$stmt->fetch()) {
    http_response_code(403);
    echo 'Invalid or expired token'; exit;
}

// Ler template do agent e substituir o token
$agentTemplate = file_get_contents(__DIR__ . '/wc2ps-agent.php');
$agentWithToken = str_replace("'{{AGENT_TOKEN}}'", "'" . addslashes($token) . "'", $agentTemplate);

// Registar download
$db->getPdo()->prepare(
    "UPDATE tokens SET used_at=NOW() WHERE job_id=? AND token=?"
)->execute([$jobId, $token]);

// Servir ficheiro
$filename = "wc2ps-agent-{$type}.php";
header('Content-Type: application/octet-stream');
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header('Content-Length: ' . strlen($agentWithToken));
header('Cache-Control: no-store');
echo $agentWithToken;
