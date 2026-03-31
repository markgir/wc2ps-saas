<?php
/**
 * JobRunner.php — Executa um job de migração via MigrationEngine
 */
declare(strict_types=1);

require_once __DIR__ . '/AgentClient.php';
require_once __DIR__ . '/MigrationEngine.php';

class JobRunner
{
    private Database $db;
    private bool     $debug;

    public function __construct(Database $db, bool $debug = false)
    {
        $this->db    = $db;
        $this->debug = $debug;
    }

    public function runJob(int $jobId): void
    {
        $stmt = $this->db->getPdo()->prepare("SELECT * FROM jobs WHERE id=? LIMIT 1");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();
        if (!$job) {
            echo "[ERRO] Job #{$jobId} não encontrado.\n";
            return;
        }

        // Marcar como running
        $this->db->updateJob($jobId, [
            'status'     => 'running',
            'started_at' => date('Y-m-d H:i:s'),
        ]);
        $this->db->log($jobId, 'info', 'worker', "Job #{$jobId} iniciado (PID=" . getmypid() . ")");

        try {
            // Construir agent WooCommerce (lê)
            $wcAgent = new AgentClient([
                'agent_url'  => $job['wc_agent_url'],
                'token'      => $job['wc_agent_token'],
                'db_host'    => $job['wc_db_host'],
                'db_port'    => $job['wc_db_port'],
                'db_name'    => $job['wc_db_name'],
                'db_user'    => $job['wc_db_user'],
                'db_pass'    => $job['wc_db_pass'],
                'db_prefix'  => $job['wc_db_prefix'],
                'timeout'    => AGENT_TIMEOUT,
            ]);

            // Construir agent PrestaShop (escreve)
            $psAgent = new AgentClient([
                'agent_url'  => $job['ps_agent_url'],
                'token'      => $job['ps_agent_token'],
                'db_host'    => $job['ps_db_host'],
                'db_port'    => $job['ps_db_port'],
                'db_name'    => $job['ps_db_name'],
                'db_user'    => $job['ps_db_user'],
                'db_pass'    => $job['ps_db_pass'],
                'db_prefix'  => $job['ps_db_prefix'],
                'timeout'    => AGENT_TIMEOUT,
            ]);

            // Instanciar motor
            $engine = new MigrationEngine(
                wc:        $wcAgent,
                ps:        $psAgent,
                db:        $this->db,
                jobId:     $jobId,
                batchSize: DEFAULT_BATCH,
                idLang:    (int)($job['ps_id_lang'] ?? 1),
                idShop:    (int)($job['ps_id_shop'] ?? 1),
                wcPrefix:  $job['wc_db_prefix'],
                psPrefix:  $job['ps_db_prefix'],
            );

            // Loop de migração — runStep() retorna true quando tudo estiver concluído
            $maxSteps = 10000; // segurança anti-loop infinito
            $steps    = 0;

            while (!$engine->runStep()) {
                $progress = $engine->getProgress();

                if ($this->debug) {
                    echo sprintf(
                        "[%s] %s | cats %d/%d | attrs %d/%d | prods %d/%d (%.1f%%)\n",
                        date('H:i:s'),
                        $progress['step'],
                        $progress['done_categories'], $progress['total_categories'],
                        $progress['done_attrs'],       $progress['total_attrs'],
                        $progress['done_products'],    $progress['total_products'],
                        $progress['pct_products']
                    );
                }

                // Verificar sinal de paragem por job
                if (file_exists(STOP_FILE . '_' . $jobId)) {
                    unlink(STOP_FILE . '_' . $jobId);
                    $this->db->updateJob($jobId, ['status' => 'paused']);
                    $this->db->log($jobId, 'warning', 'worker', 'Job pausado por sinal externo.');
                    return;
                }

                if (++$steps >= $maxSteps) {
                    throw new RuntimeException("Excedido limite de {$maxSteps} steps — possível loop.");
                }
            }

            // Concluído
            $progress = $engine->getProgress();
            $this->db->updateJob($jobId, [
                'status'       => 'completed',
                'completed_at' => date('Y-m-d H:i:s'),
                'done_products'=> $progress['done_products'],
            ]);
            $this->db->log($jobId, 'success', 'worker',
                "Migração concluída: {$progress['done_products']}/{$progress['total_products']} produtos, " .
                "{$progress['errors']} erros."
            );

            if ($this->debug) {
                echo "[" . date('H:i:s') . "] Job #{$jobId} concluído.\n";
            }

        } catch (Throwable $e) {
            $this->db->updateJob($jobId, ['status' => 'error']);
            $this->db->log($jobId, 'error', 'worker', 'Job falhou: ' . $e->getMessage());
            if ($this->debug) {
                echo "[ERRO] {$e->getMessage()}\n";
                echo $e->getTraceAsString() . "\n";
            }
        }
    }
}
