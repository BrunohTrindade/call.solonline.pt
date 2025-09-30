#!/usr/bin/env bash
set -euo pipefail

# Setup MySQL server and run Laravel migrations+seed on a Linux VPS
# Usage:
#   sudo DB_NAME=solonline DB_USER=soluser DB_PASS='strongpass' bash scripts/setup_mysql_and_migrate.sh
# Notes:
# - Requires root privileges (sudo) to install packages and manage MySQL service
# - Expects PHP (>=8.2) and Composer installed system-wide
# - The Laravel .env must be configured for MySQL (or will be updated by this script if ENV vars are provided)

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
APP_ROOT=$(cd "$SCRIPT_DIR/.." && pwd)

DB_NAME=${DB_NAME:-solonline}
DB_USER=${DB_USER:-}
DB_PASS=${DB_PASS:-}

echo "[1/6] Detectando distribuição e instalando MySQL..."
if [ -f /etc/os-release ]; then
  . /etc/os-release
  DIST_ID=${ID:-}
else
  echo "Não foi possível detectar a distro Linux. Instale o MySQL manualmente e reinicie este script."
  exit 1
fi

case "$DIST_ID" in
  ubuntu|debian)
    apt-get update -y
    DEBIAN_FRONTEND=noninteractive apt-get install -y mysql-server
    systemctl enable --now mysql
    ;;
  rocky|almalinux|centos|rhel)
    yum install -y @mysql
    systemctl enable --now mysqld || systemctl enable --now mysql
    ;;
  *)
    echo "Distribuição $DIST_ID não tratada. Instale o MySQL manualmente e continue."
    exit 1
    ;;
esac

echo "[2/6] Verificando PHP e Composer..."
PHPV=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;') || { echo "PHP não encontrado"; exit 1; }
REQ=$(php -r 'echo version_compare(PHP_VERSION, "8.2.0", ">=") ? "ok" : "fail";')
if [ "$REQ" != "ok" ]; then
  echo "PHP $PHPV detectado. É necessário PHP >= 8.2."; exit 1
fi
if ! command -v composer >/dev/null 2>&1; then
  echo "Composer não encontrado. Instale o Composer e reexecute."; exit 1
fi

echo "[3/6] Criando banco de dados e usuário (se definidos)..."
if command -v mysql >/dev/null 2>&1; then
  # Tenta conectar como root via socket (método padrão em Ubuntu/Debian)
  if [ -n "$DB_USER" ] && [ -n "$DB_PASS" ]; then
    mysql -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'%' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'%';
FLUSH PRIVILEGES;
SQL
  else
    mysql -uroot -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  fi
else
  echo "Cliente mysql não encontrado no PATH; verifique a instalação."
  exit 1
fi

echo "[4/6] Ajustando .env (se DB_USER/PASS foram informados)..."
cd "$APP_ROOT"
if [ ! -f .env ]; then
  cp .env.example .env
fi
if [ -n "${DB_NAME:-}" ]; then sed -i "s/^DB_DATABASE=.*/DB_DATABASE=$DB_NAME/" .env; fi
if [ -n "${DB_USER:-}" ]; then sed -i "s/^DB_USERNAME=.*/DB_USERNAME=$DB_USER/" .env; fi
if [ -n "${DB_PASS:-}" ]; then sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=$DB_PASS/" .env; fi
sed -i "s/^DB_CONNECTION=.*/DB_CONNECTION=mysql/" .env

echo "[5/6] Instalando dependências PHP e rodando migrações+seed..."
composer install --no-interaction --prefer-dist --no-dev
php artisan key:generate --force || true
php artisan config:clear
php artisan migrate --seed --force

echo "[6/6] Concluído. Usuário admin será criado pelo seeder, se ainda não existir:"
echo "  admin@example.com / admin123"
echo "Revise .env para ajustar o MAIL_ e outras configs de produção."
