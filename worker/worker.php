<?php
/**
 * worker.php — WC2PS Migration Worker
 * =====================================
 * Corre no servidor dedicado via CLI, sem depender de browser.
 * Lê jobs da BD central, executa migrações via agent, actualiza estado.
 *
 * Uso:
 *   php worker.php                  # processa jobs em fila, corre indefinidamente
 *   php worker.php --job=JOB_ID     # processa um job específico
 *   php worker.php --once           # processa 1 job e sai
 *
 * Lançar em background:
 *   nohup php worker.php >> /var/log/wc2ps-worker.log 2>&1 &
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only'; exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/AgentClient.php';
require_once __DIR__ . '/JobRunner.php';
require_once __DIR__ . '/Database.php';

// Parse args
$opts   = getopt('', ['job:', 'once', 'debug']);
$jobId  = $opts['job']  ?? null;
$once   = isset($opts['once']);
$debug  = isset($opts['debug']);

$db     = new Database(DB_DSN, DB_USER, DB_PASS);
$runner = new JobRunner($db, $debug);

echo "[" . date('Y-m-d H:i:s') . "] Worker started (PID=" . getmypid() . ")\n";

if ($jobId) {
    // Modo single job
    $runner->runJob((int)$jobId);
} else {
    // Loop de processamento
    while (true) {
        $job = $db->fetchNextJob();
        if ($job) {
            echo "[" . date('H:i:s') . "] Processing job #{$job['id']} — {$job['client_name']}\n";
            $runner->runJob((int)$job['id']);
            if ($once) break;
        } else {
            if ($once) break;
            // Sem jobs — aguarda 10 segundos antes de verificar de novo
            sleep(10);
        }
        // Verifica sinais de paragem
        if (file_exists(STOP_FILE)) {
            unlink(STOP_FILE);
            echo "[" . date('H:i:s') . "] Stop signal received. Exiting.\n";
            break;
        }
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Worker stopped.\n";
