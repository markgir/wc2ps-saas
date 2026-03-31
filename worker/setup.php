<?php
/**
 * setup.php — Instala o schema da BD central
 * Corre uma vez no servidor dedicado:
 *   php setup.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') die("CLI only\n");

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';

echo "Creating schema...\n";
$db = new Database(DB_DSN, DB_USER, DB_PASS);
$db->createSchema();
echo "Schema created.\n";

// Criar cliente de teste
$pdo = $db->getPdo();
$pdo->exec("INSERT IGNORE INTO clients (email, name) VALUES ('admin@iddigital.pt', 'IDDigital')");
$clientId = $pdo->lastInsertId() ?: 1;
echo "Client created (id={$clientId})\n";

// Gerar API key para o dashboard
$apiKey = bin2hex(random_bytes(32));
echo "\n=== DASHBOARD API KEY ===\n";
echo $apiKey . "\n";
echo "Adiciona ao config.php: define('DASHBOARD_API_KEY', '{$apiKey}');\n\n";
echo "Setup complete.\n";
