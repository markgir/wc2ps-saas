<?php
/**
 * config.php — Configuração do servidor dedicado
 * Preenche com os teus dados antes de correr o worker.
 */

// BD central (no servidor dedicado)
define('DB_DSN',  'mysql:host=127.0.0.1;dbname=wc2ps_saas;charset=utf8mb4');
define('DB_USER', 'wc2ps_user');
define('DB_PASS', 'CHANGE_ME_STRONG_PASSWORD');

// Ficheiro para sinalizar paragem do worker
define('STOP_FILE', '/tmp/wc2ps_worker_stop');

// Batch size por defeito (produtos por request ao agent)
define('DEFAULT_BATCH', 50);

// Timeout para requests ao agent (segundos)
define('AGENT_TIMEOUT', 60);

// Dashboard API key (gerada pelo setup.php)
// define('DASHBOARD_API_KEY', 'GERADA_PELO_SETUP_PHP');

// PayPal
// define('PAYPAL_CLIENT_ID', 'SEU_CLIENT_ID');
// define('PAYPAL_SECRET',    'SEU_SECRET');
// define('PAYPAL_SANDBOX',   true);
// define('APP_URL',          'https://migrashop.pt');
