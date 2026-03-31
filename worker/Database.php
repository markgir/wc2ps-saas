<?php
/**
 * Database.php — BD central do servidor dedicado
 * Gere clientes, jobs, logs e tokens.
 */
declare(strict_types=1);

class Database
{
    private PDO $pdo;

    public function __construct(string $dsn, string $user, string $pass)
    {
        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /** Criar schema inicial */
    public function createSchema(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS clients (
                id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email        VARCHAR(255) NOT NULL UNIQUE,
                name         VARCHAR(255) NOT NULL,
                created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB;

            CREATE TABLE IF NOT EXISTS jobs (
                id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                client_id       INT UNSIGNED NOT NULL,
                client_name     VARCHAR(255) NOT NULL,
                status          ENUM('pending','running','paused','completed','error','cancelled') DEFAULT 'pending',
                -- Agent WC
                wc_agent_url    VARCHAR(512) NOT NULL,
                wc_agent_token  VARCHAR(128) NOT NULL,
                wc_db_host      VARCHAR(255) DEFAULT '127.0.0.1',
                wc_db_port      VARCHAR(10)  DEFAULT '3306',
                wc_db_name      VARCHAR(255) NOT NULL,
                wc_db_user      VARCHAR(255) NOT NULL,
                wc_db_pass      VARCHAR(255) NOT NULL,
                wc_db_prefix    VARCHAR(32)  DEFAULT 'wp_',
                -- Agent PS
                ps_agent_url    VARCHAR(512) NOT NULL,
                ps_agent_token  VARCHAR(128) NOT NULL,
                ps_db_host      VARCHAR(255) DEFAULT '127.0.0.1',
                ps_db_port      VARCHAR(10)  DEFAULT '3306',
                ps_db_name      VARCHAR(255) NOT NULL,
                ps_db_user      VARCHAR(255) NOT NULL,
                ps_db_pass      VARCHAR(255) NOT NULL,
                ps_db_prefix    VARCHAR(32)  DEFAULT 'ps_',
                ps_id_lang      TINYINT      DEFAULT 1,
                ps_id_shop      TINYINT      DEFAULT 1,
                -- Progresso
                total_products  INT DEFAULT 0,
                done_products   INT DEFAULT 0,
                total_categories INT DEFAULT 0,
                done_categories INT DEFAULT 0,
                total_attrs     INT DEFAULT 0,
                done_attrs      INT DEFAULT 0,
                errors          JSON,
                state           LONGTEXT NULL COMMENT 'JSON com cursor e mapas de migração',
                -- Timestamps
                created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
                started_at      DATETIME NULL,
                completed_at    DATETIME NULL,
                updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (client_id) REFERENCES clients(id)
            ) ENGINE=InnoDB;

            CREATE TABLE IF NOT EXISTS job_logs (
                id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                job_id     INT UNSIGNED NOT NULL,
                ts         DATETIME DEFAULT CURRENT_TIMESTAMP,
                level      ENUM('debug','info','success','warning','error') DEFAULT 'info',
                step       VARCHAR(64),
                message    TEXT,
                INDEX idx_job (job_id, ts),
                FOREIGN KEY (job_id) REFERENCES jobs(id)
            ) ENGINE=InnoDB;

            CREATE TABLE IF NOT EXISTS tokens (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                client_id   INT UNSIGNED NOT NULL,
                job_id      INT UNSIGNED NULL,
                token       VARCHAR(64) NOT NULL UNIQUE,
                type        ENUM('agent_wc','agent_ps','api') DEFAULT 'api',
                used_at     DATETIME NULL,
                expires_at  DATETIME NULL,
                created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (client_id) REFERENCES clients(id)
            ) ENGINE=InnoDB;

            CREATE TABLE IF NOT EXISTS payments (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                client_id   INT UNSIGNED NOT NULL,
                job_id      INT UNSIGNED NULL,
                amount      DECIMAL(8,2) NOT NULL,
                currency    VARCHAR(3) DEFAULT 'EUR',
                status      ENUM('pending','paid','refunded') DEFAULT 'pending',
                ref         VARCHAR(255),
                paid_at     DATETIME NULL,
                created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (client_id) REFERENCES clients(id)
            ) ENGINE=InnoDB;
        ");
    }

    /** Buscar próximo job pendente */
    public function fetchNextJob(): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM jobs WHERE status = 'pending' ORDER BY created_at ASC LIMIT 1"
        );
        $stmt->execute();
        return $stmt->fetch() ?: null;
    }

    /** Actualizar estado de um job */
    public function updateJob(int $jobId, array $fields): void
    {
        $sets   = implode(', ', array_map(fn($k) => "`{$k}` = ?", array_keys($fields)));
        $values = array_values($fields);
        $values[] = $jobId;
        $this->pdo->prepare("UPDATE jobs SET {$sets} WHERE id = ?")->execute($values);
    }

    /** Escrever linha de log */
    public function log(int $jobId, string $level, string $step, string $message): void
    {
        $this->pdo->prepare(
            "INSERT INTO job_logs (job_id, level, step, message) VALUES (?, ?, ?, ?)"
        )->execute([$jobId, $level, $step, $message]);
    }

    /** Buscar logs de um job (para o dashboard/API) */
    public function getLogs(int $jobId, int $offset = 0, int $limit = 200): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, ts, level, step, message FROM job_logs
             WHERE job_id = ? ORDER BY id ASC LIMIT ? OFFSET ?"
        );
        $stmt->execute([$jobId, $limit, $offset]);
        return $stmt->fetchAll();
    }

    /** Gerar token seguro */
    public function generateToken(int $clientId, string $type, ?int $jobId = null): string
    {
        $token = bin2hex(random_bytes(32)); // 64 chars hex
        $this->pdo->prepare(
            "INSERT INTO tokens (client_id, job_id, token, type, expires_at)
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))"
        )->execute([$clientId, $jobId, $token, $type]);
        return $token;
    }

    public function getPdo(): PDO { return $this->pdo; }
}
