#!/bin/bash
# ============================================================
# MigraShop — Script de instalação no VPS
# Testado em Ubuntu 20.04 / 22.04 / Debian 11 / 12
# Corre como root: bash install.sh
# ============================================================

set -e
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'
ok()   { echo -e "${GREEN}✓${NC} $1"; }
warn() { echo -e "${YELLOW}⚠${NC} $1"; }
info() { echo -e "${CYAN}→${NC} $1"; }
err()  { echo -e "${RED}✗${NC} $1"; exit 1; }

echo ""
echo "  ███╗   ███╗██╗ ██████╗ ██████╗  █████╗ ███████╗██╗  ██╗ ██████╗ ██████╗ "
echo "  ████╗ ████║██║██╔════╝ ██╔══██╗██╔══██╗██╔════╝██║  ██║██╔═══██╗██╔══██╗"
echo "  ██╔████╔██║██║██║  ███╗██████╔╝███████║███████╗███████║██║   ██║██████╔╝"
echo "  ██║╚██╔╝██║██║██║   ██║██╔══██╗██╔══██║╚════██║██╔══██║██║   ██║██╔═══╝ "
echo "  ██║ ╚═╝ ██║██║╚██████╔╝██║  ██║██║  ██║███████║██║  ██║╚██████╔╝██║     "
echo "  ╚═╝     ╚═╝╚═╝ ╚═════╝ ╚═╝  ╚═╝╚═╝  ╚═╝╚══════╝╚═╝  ╚═╝ ╚═════╝ ╚═╝     "
echo ""
echo "  Instalação no VPS — $(date '+%Y-%m-%d %H:%M')"
echo "  ============================================================"
echo ""

# ── Verificar root ─────────────────────────────────────────────────────────────
[ "$EUID" -ne 0 ] && err "Corre como root: sudo bash install.sh"

# ── Detectar distro ────────────────────────────────────────────────────────────
if [ -f /etc/debian_version ]; then
    DISTRO="debian"
    PKG="apt-get"
elif [ -f /etc/redhat-release ]; then
    DISTRO="redhat"
    PKG="yum"
else
    warn "Distro não detectada. A assumir Debian/Ubuntu."
    DISTRO="debian"
    PKG="apt-get"
fi

# ── Config ────────────────────────────────────────────────────────────────────
INSTALL_DIR="/var/www/migrashop"
LOG_DIR="/var/log/migrashop"
DB_NAME="wc2ps_saas"
DB_USER="wc2ps_user"
DB_PASS=$(openssl rand -base64 20 | tr -d '=/+' | head -c 24)
WORKER_LOG="${LOG_DIR}/worker.log"

echo ""
info "Directório de instalação: ${INSTALL_DIR}"
info "Log do worker: ${WORKER_LOG}"
echo ""

# ── 1. Actualizar sistema ──────────────────────────────────────────────────────
info "[1/7] A actualizar sistema..."
$PKG update -qq
ok "Sistema actualizado"

# ── 2. Instalar PHP ────────────────────────────────────────────────────────────
info "[2/7] A instalar PHP..."

PHP_VERSION=""
for v in 8.3 8.2 8.1 8.0; do
    if $PKG install -y -qq "php${v}-cli" "php${v}-mysql" "php${v}-curl" "php${v}-mbstring" 2>/dev/null; then
        PHP_VERSION=$v
        PHP_BIN="php${v}"
        ok "PHP ${v} instalado"
        break
    fi
done

if [ -z "$PHP_VERSION" ]; then
    # Tentar PHP genérico
    $PKG install -y -qq php-cli php-mysql php-curl php-mbstring
    PHP_BIN="php"
    ok "PHP instalado (versão genérica)"
fi

# Verificar allow_url_fopen
PHP_INI=$(${PHP_BIN} --ini | grep "Loaded Configuration" | awk '{print $NF}')
if [ -f "$PHP_INI" ]; then
    sed -i 's/allow_url_fopen = Off/allow_url_fopen = On/' "$PHP_INI"
    ok "allow_url_fopen activado"
fi

# ── 3. Instalar MariaDB ────────────────────────────────────────────────────────
info "[3/7] A instalar MariaDB..."
$PKG install -y -qq mariadb-server
systemctl enable mariadb --quiet
systemctl start mariadb
ok "MariaDB instalado e a correr"

# ── 4. Criar base de dados ────────────────────────────────────────────────────
info "[4/7] A criar base de dados..."
mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null
mysql -e "DROP USER IF EXISTS '${DB_USER}'@'localhost';" 2>/dev/null
mysql -e "CREATE USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';" 2>/dev/null
mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';" 2>/dev/null
mysql -e "FLUSH PRIVILEGES;" 2>/dev/null
ok "Base de dados '${DB_NAME}' criada"
ok "Utilizador '${DB_USER}' criado"

# ── 5. Criar estrutura de directorios ─────────────────────────────────────────
info "[5/7] A criar directorios..."
mkdir -p "${INSTALL_DIR}"
mkdir -p "${LOG_DIR}"

# Copiar ficheiros (assume que o script está na raiz do zip extraído)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cp -r "${SCRIPT_DIR}/." "${INSTALL_DIR}/"
chmod -R 750 "${INSTALL_DIR}"
chmod 640 "${INSTALL_DIR}/worker/config.php"
ok "Ficheiros copiados para ${INSTALL_DIR}"

# ── 6. Gerar config.php ───────────────────────────────────────────────────────
info "[6/7] A gerar configuração..."

# Gerar API key para o dashboard
API_KEY=$(openssl rand -hex 32)

cat > "${INSTALL_DIR}/worker/config.php" << CONFIG
<?php
// BD central (gerado pelo install.sh)
define('DB_DSN',  'mysql:host=127.0.0.1;dbname=${DB_NAME};charset=utf8mb4');
define('DB_USER', '${DB_USER}');
define('DB_PASS', '${DB_PASS}');

// Ficheiro de stop do worker
define('STOP_FILE', '/tmp/wc2ps_worker_stop');

// Batch size por defeito
define('DEFAULT_BATCH', 50);

// Timeout para requests ao agent (segundos)
define('AGENT_TIMEOUT', 60);

// Dashboard API key
define('DASHBOARD_API_KEY', '${API_KEY}');

// PayPal — preenche antes de ir a produção
// define('PAYPAL_CLIENT_ID', 'SEU_CLIENT_ID');
// define('PAYPAL_SECRET',    'SEU_SECRET');
// define('PAYPAL_SANDBOX',   false);
// define('APP_URL',          'https://migrashop.pt');
CONFIG

ok "config.php gerado"

# ── 7. Instalar schema e arrancar worker ──────────────────────────────────────
info "[7/7] A instalar schema e arrancar worker..."

${PHP_BIN} "${INSTALL_DIR}/worker/setup.php" > /tmp/setup_output.txt 2>&1
ok "Schema da BD instalado"

# Criar serviço systemd para o worker
cat > /etc/systemd/system/migrashop-worker.service << SERVICE
[Unit]
Description=MigraShop Migration Worker
After=network.target mariadb.service

[Service]
Type=simple
User=www-data
ExecStart=${PHP_BIN} ${INSTALL_DIR}/worker/worker.php
Restart=always
RestartSec=10
StandardOutput=append:${WORKER_LOG}
StandardError=append:${WORKER_LOG}

[Install]
WantedBy=multi-user.target
SERVICE

systemctl daemon-reload
systemctl enable migrashop-worker --quiet
systemctl start migrashop-worker
ok "Worker registado como serviço systemd e a correr"

# ── Resumo ────────────────────────────────────────────────────────────────────
echo ""
echo "  ============================================================"
echo -e "  ${GREEN}INSTALAÇÃO CONCLUÍDA${NC}"
echo "  ============================================================"
echo ""
echo "  Directório:    ${INSTALL_DIR}"
echo "  Log worker:    ${WORKER_LOG}"
echo "  DB:            ${DB_NAME}"
echo "  DB user:       ${DB_USER}"
echo -e "  DB password:   ${YELLOW}${DB_PASS}${NC}"
echo ""
echo "  DASHBOARD API KEY:"
echo -e "  ${CYAN}${API_KEY}${NC}"
echo ""
echo "  PRÓXIMOS PASSOS:"
echo "  1. Guarda a API key e a password da BD acima"
echo "  2. Preenche PAYPAL_CLIENT_ID e PAYPAL_SECRET em:"
echo "     ${INSTALL_DIR}/worker/config.php"
echo "  3. Verifica o worker: systemctl status migrashop-worker"
echo "  4. Ver logs:          tail -f ${WORKER_LOG}"
echo ""
