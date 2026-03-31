<?php
/**
 * AgentClient.php
 * Comunica com o agent instalado no hosting do cliente.
 * Todas as queries à BD do cliente passam por aqui.
 */
declare(strict_types=1);

class AgentClient
{
    private string $agentUrl;
    private string $token;
    private string $dbHost;
    private string $dbPort;
    private string $dbUser;
    private string $dbPass;
    private string $dbName;
    private string $dbPrefix;
    private int    $timeout;

    public function __construct(array $cfg)
    {
        $this->agentUrl  = rtrim($cfg['agent_url'], '/') . '/wc2ps-agent.php';
        $this->token     = $cfg['token'];
        $this->dbHost    = $cfg['db_host']   ?? '127.0.0.1';
        $this->dbPort    = $cfg['db_port']   ?? '3306';
        $this->dbUser    = $cfg['db_user'];
        $this->dbPass    = $cfg['db_pass'];
        $this->dbName    = $cfg['db_name'];
        $this->dbPrefix  = $cfg['db_prefix'] ?? 'wp_';
        $this->timeout   = $cfg['timeout']   ?? 30;
    }

    /** Ping — verifica se o agent está acessível */
    public function ping(): array
    {
        return $this->call('ping', []);
    }

    /** Testar ligação à BD */
    public function testConnection(string $type = 'wc'): array
    {
        return $this->call('test_connection', ['type' => $type]);
    }

    /** Executar SELECT e devolver rows */
    public function query(string $sql, array $params = [], int $limit = 1000): array
    {
        $result = $this->call('query', ['sql' => $sql, 'params' => $params, 'limit' => $limit]);
        return $result['rows'] ?? [];
    }

    /** Contar rows */
    public function count(string $table, string $where = '', array $params = []): int
    {
        $result = $this->call('count', [
            'table'  => $table,
            'where'  => $where,
            'params' => $params,
        ]);
        return (int)($result['count'] ?? 0);
    }

    /** Schema de uma tabela */
    public function describe(string $table): array
    {
        $result = $this->call('describe', ['table' => $table]);
        return $result['columns'] ?? [];
    }

    /** Listar tabelas */
    public function tables(): array
    {
        $result = $this->call('tables', []);
        return $result['tables'] ?? [];
    }

    /** Chamada HTTP ao agent */
    private function call(string $action, array $data): array
    {
        $body = json_encode(array_merge($data, [
            'action'     => $action,
            'db_host'    => $this->dbHost,
            'db_port'    => $this->dbPort,
            'db_user'    => $this->dbUser,
            'db_pass'    => $this->dbPass,
            'db_name'    => $this->dbName,
            'db_prefix'  => $this->dbPrefix,
        ]));

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", [
                'Content-Type: application/json',
                'X-Agent-Token: ' . $this->token,
                'User-Agent: WC2PS-Worker/1.0',
            ]),
            'content'       => $body,
            'timeout'       => $this->timeout,
            'ignore_errors' => true,
        ], 'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ]]);

        $raw = @file_get_contents($this->agentUrl, false, $ctx);

        if ($raw === false) {
            throw new RuntimeException("Agent unreachable: {$this->agentUrl}");
        }

        $response = json_decode($raw, true);
        if (!is_array($response)) {
            throw new RuntimeException("Agent returned invalid JSON: " . substr($raw, 0, 200));
        }
        if (!($response['ok'] ?? false)) {
            throw new RuntimeException("Agent error: " . ($response['message'] ?? 'Unknown'));
        }

        return $response['data'] ?? [];
    }
}
