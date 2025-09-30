#!/usr/bin/env bash
set -euo pipefail

# ==========================
# CONFIGURAÇÃO (edite aqui)
# ==========================
APP_DOMAIN="app.exemplo.com"      # domínio onde a API irá rodar
APP_DIR="/var/www/solonline"      # diretório destino da aplicação
REPO_URL=""                        # opcional: URL do seu repositório Git (https://...)
REPO_BRANCH="main"                 # branch para clonar

DB_NAME="solonline"
DB_USER="soluser"
DB_PASS="TroqueEstaSenhaForte"

ADMIN_NAME="Admin"
ADMIN_EMAIL="admin@example.com"
ADMIN_PASSWORD="admin123"

# Se já subiu os arquivos por SSH/SCP, defina REPO_URL vazio e o script só configura o sistema

# ==========================
# 1) Pacotes básicos e PPA PHP 8.2
# ==========================
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y software-properties-common curl git unzip ca-certificates lsb-release ufw
add-apt-repository -y ppa:ondrej/php
apt-get update -y

# ==========================
# 2) Instalar Nginx, PHP 8.2 (FPM) e extensões
# ==========================
apt-get install -y nginx
apt-get install -y \
  php8.2-fpm php8.2-cli php8.2-mbstring php8.2-xml php8.2-curl php8.2-zip php8.2-gd php8.2-intl php8.2-bcmath php8.2-mysql

systemctl enable --now nginx php8.2-fpm

# ==========================
# 3) Instalar MySQL e criar DB/usuário
# ==========================
apt-get install -y mysql-server
systemctl enable --now mysql

mysql -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

# ==========================
# 4) Composer (global)
# ==========================
if ! command -v composer >/dev/null 2>&1; then
  EXPECTED_CHECKSUM="$(curl -fsSL https://composer.github.io/installer.sig)"
  php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
  ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"
  if [ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]; then
    echo 'ERRO: Assinatura do Composer inválida'; rm composer-setup.php; exit 1
  fi
  php composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm composer-setup.php
fi

# ==========================
# 5) Obter aplicação (clone opcional) e preparar diretório
# ==========================
mkdir -p "$APP_DIR"
if [ -n "$REPO_URL" ]; then
  if [ ! -d "$APP_DIR/.git" ]; then
    git clone --branch "$REPO_BRANCH" "$REPO_URL" "$APP_DIR"
  else
    cd "$APP_DIR" && git fetch && git checkout "$REPO_BRANCH" && git pull --ff-only
  fi
fi

cd "$APP_DIR"

# ==========================
# 6) Backend (Laravel)
# ==========================
cd "$APP_DIR/backend"
[ -f .env ] || cp .env.example .env

sed -i "s/^APP_ENV=.*/APP_ENV=production/" .env
sed -i "s/^APP_DEBUG=.*/APP_DEBUG=false/" .env
sed -i "s|^APP_URL=.*|APP_URL=https://$APP_DOMAIN|" .env

sed -i "s/^DB_CONNECTION=.*/DB_CONNECTION=mysql/" .env
sed -i "s/^DB_HOST=.*/DB_HOST=127.0.0.1/" .env
sed -i "s/^DB_PORT=.*/DB_PORT=3306/" .env
sed -i "s/^DB_DATABASE=.*/DB_DATABASE=$DB_NAME/" .env
sed -i "s/^DB_USERNAME=.*/DB_USERNAME=$DB_USER/" .env
sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=$DB_PASS/" .env

sed -i "s/^ADMIN_NAME=.*/ADMIN_NAME=$ADMIN_NAME/" .env || echo "ADMIN_NAME=$ADMIN_NAME" >> .env
sed -i "s/^ADMIN_EMAIL=.*/ADMIN_EMAIL=$ADMIN_EMAIL/" .env || echo "ADMIN_EMAIL=$ADMIN_EMAIL" >> .env
sed -i "s/^ADMIN_PASSWORD=.*/ADMIN_PASSWORD=$ADMIN_PASSWORD/" .env || echo "ADMIN_PASSWORD=$ADMIN_PASSWORD" >> .env

composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
php artisan key:generate --force || true
php artisan config:clear
php artisan migrate --seed --force

chown -R www-data:www-data storage bootstrap/cache
find storage -type d -exec chmod 775 {} +
find storage -type f -exec chmod 664 {} +
chmod -R ug+rwx bootstrap/cache

# ==========================
# 7) Nginx server block
# ==========================
cat >/etc/nginx/sites-available/solonline.conf <<NGINX
server {
    listen 80;
    server_name $APP_DOMAIN;

    root $APP_DIR/backend/public;
    index index.php index.html;

    client_max_body_size 20m;

    location / {
    try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

  location ~* /\.(env|git|svn|hg|htaccess) { deny all; }
}
NGINX

ln -sf /etc/nginx/sites-available/solonline.conf /etc/nginx/sites-enabled/solonline.conf
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

# ==========================
# 8) Fila (systemd) e Cron (scheduler)
# ==========================
cat >/etc/systemd/system/solonline-queue.service <<SERVICE
[Unit]
Description=Laravel Queue Worker (solonline)
After=network.target mysql.service php8.2-fpm.service

[Service]
Type=simple
WorkingDirectory=$APP_DIR/backend
ExecStart=/usr/bin/php artisan queue:work --sleep=1 --tries=1 --queue=imports,default
Restart=always
User=www-data
Group=www-data
StandardOutput=append:$APP_DIR/backend/storage/logs/queue.log
StandardError=append:$APP_DIR/backend/storage/logs/queue-error.log
Environment=APP_ENV=production

[Install]
WantedBy=multi-user.target
SERVICE

systemctl daemon-reload
systemctl enable --now solonline-queue.service

# Scheduler via cron (executa a cada minuto)
(crontab -l 2>/dev/null | grep -v "schedule:run"; echo "* * * * * cd $APP_DIR/backend && /usr/bin/php artisan schedule:run >> /dev/null 2>&1") | crontab -


# ==========================
# 9) Firewall e HTTPS opcional
# ==========================
ufw allow OpenSSH || true
ufw allow 'Nginx Full' || true
yes | ufw enable || true

# HTTPS opcional (requer DNS apontado):
# apt-get install -y python3-certbot-nginx
# certbot --nginx -d "$APP_DOMAIN" --agree-tos -m "$ADMIN_EMAIL" --redirect -n

echo -e "\nProvisionamento concluído. Acesse: http://$APP_DOMAIN (ou https se configurado)"